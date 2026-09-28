<?php
/**
 * Batched walks over every translation group.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\Repositories\SyncRepository;

/**
 * Builds the baseline and recalculates everything, in background batches.
 *
 * The baseline marks every existing translation without a sync point as up to
 * date against its source's current content. It is idempotent: translations
 * that already have a sync point are left alone unless `$force` is set.
 * Each batch queues the next one, so no request does more than one batch.
 *
 * @since 0.1.0
 */
class BaselineJob {

	/**
	 * Job hooks. Arguments: the last post ID processed, and the force flag (baseline only).
	 *
	 * @since 0.1.0
	 */
	public const BASELINE_HOOK = 'tdrift_baseline_batch';
	public const RECALC_HOOK   = 'tdrift_recalc_batch';

	/**
	 * Option holding the baseline progress.
	 *
	 * @since 0.1.0
	 */
	public const STATE_OPTION = 'tdrift_baseline_state';

	/**
	 * Default posts per batch.
	 *
	 * @since 0.1.0
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TranslationProvider $provider Provider.
	 * @param TrackedFields       $tracked  Tracked fields.
	 * @param SyncService         $syncer   Sync service.
	 * @param DriftService        $drift    Drift service.
	 * @param SyncRepository      $sync     Sync rows.
	 * @param Queue               $queue    Job queue.
	 */
	public function __construct(
		private TranslationProvider $provider,
		private TrackedFields $tracked,
		private SyncService $syncer,
		private DriftService $drift,
		private SyncRepository $sync,
		private Queue $queue
	) {
	}

	/**
	 * Queues a baseline build.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $force Also reset translations that already have a sync point.
	 * @return bool Whether a job was queued.
	 */
	public function start_baseline( bool $force = false ): bool {
		$this->save_state( 'queued', 0 );

		return $this->queue->enqueue( self::BASELINE_HOOK, array( 0, $force ) );
	}

	/**
	 * Queues a recalculation of every source.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Whether a job was queued.
	 */
	public function start_recalculation(): bool {
		return $this->queue->enqueue( self::RECALC_HOOK, array( 0 ) );
	}

	/**
	 * Returns the baseline progress.
	 *
	 * @since 0.1.0
	 *
	 * @return array{status: string, processed: int, updated_at: string}
	 */
	public function state(): array {
		$state = get_option( self::STATE_OPTION );
		$state = is_array( $state ) ? $state : array();

		return array(
			'status'     => is_string( $state['status'] ?? null ) ? $state['status'] : 'none',
			'processed'  => (int) ( $state['processed'] ?? 0 ),
			'updated_at' => is_string( $state['updated_at'] ?? null ) ? $state['updated_at'] : '',
		);
	}

	/**
	 * Runs one baseline batch and queues the next.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $after_id Last post ID processed by the previous batch.
	 * @param bool $force    Reset existing sync points too.
	 * @return int Posts examined in this batch.
	 */
	public function run_baseline_batch( int $after_id = 0, bool $force = false ): int {
		$ids = $this->next_ids( $after_id );

		foreach ( $ids as $post_id ) {
			if ( $this->provider->get_source( $post_id ) !== $post_id ) {
				continue;
			}

			foreach ( $this->provider->get_group( $post_id ) as $translation_id ) {
				if ( $translation_id === $post_id ) {
					continue;
				}
				$row = $this->sync->find_by_translation( $translation_id );
				if ( $force || null === $row || null === $row->field_hashes ) {
					$this->syncer->mark_synced( $translation_id, 0, SyncService::CONTEXT_BASELINE );
				}
			}

			$this->drift->recalculate_source( $post_id );
		}

		$processed = $this->state()['processed'] + count( $ids );
		if ( count( $ids ) < $this->batch_size() ) {
			$this->save_state( 'done', $processed );
		} else {
			$this->save_state( 'running', $processed );
			$this->queue->enqueue( self::BASELINE_HOOK, array( (int) end( $ids ), $force ) );
		}

		return count( $ids );
	}

	/**
	 * Runs one recalculation batch and queues the next.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after_id Last post ID processed by the previous batch.
	 * @return int Posts examined in this batch.
	 */
	public function run_recalc_batch( int $after_id = 0 ): int {
		$ids = $this->next_ids( $after_id );

		foreach ( $ids as $post_id ) {
			if ( $this->provider->get_source( $post_id ) === $post_id ) {
				$this->drift->recalculate_source( $post_id );
			}
		}

		if ( count( $ids ) === $this->batch_size() ) {
			$this->queue->enqueue( self::RECALC_HOOK, array( (int) end( $ids ) ) );
		}

		return count( $ids );
	}

	/**
	 * Posts per batch.
	 *
	 * @since 0.1.0
	 */
	public function batch_size(): int {
		/**
		 * Filters how many posts a background batch processes.
		 *
		 * @since 0.1.0
		 *
		 * @param int $size Posts per batch. Default 50.
		 */
		return max( 1, (int) apply_filters( 'tdrift_batch_size', self::DEFAULT_BATCH_SIZE ) );
	}

	/**
	 * IDs of the next batch of tracked posts, in every language, ordered by ID.
	 *
	 * @param int $after_id Exclusive lower bound.
	 * @return list<int>
	 */
	private function next_ids( int $after_id ): array {
		$post_types = $this->tracked->post_types();
		if ( array() === $post_types ) {
			return array();
		}

		$where = static function ( string $sql ) use ( $after_id ): string {
			global $wpdb;

			return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		};

		add_filter( 'posts_where', $where );
		$query = new \WP_Query(
			array_merge(
				array(
					'post_type'              => $post_types,
					'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'posts_per_page'         => $this->batch_size(),
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				),
				$this->provider->all_languages_query_args()
			)
		);
		remove_filter( 'posts_where', $where );

		return array_values( array_map( static fn( $id ): int => $id instanceof \WP_Post ? $id->ID : (int) $id, $query->posts ) );
	}

	/**
	 * Saves the baseline progress.
	 *
	 * @param string $status    'queued', 'running' or 'done'.
	 * @param int    $processed Posts examined so far.
	 */
	private function save_state( string $status, int $processed ): void {
		update_option(
			self::STATE_OPTION,
			array(
				'status'     => $status,
				'processed'  => $processed,
				'updated_at' => current_time( 'mysql', true ),
			),
			false
		);
	}
}
