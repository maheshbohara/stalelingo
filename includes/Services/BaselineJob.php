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
 * @since 1.0.0
 */
class BaselineJob {

	/**
	 * Job hooks. Arguments: the last post ID processed, and the force flag (baseline only).
	 *
	 * @since 1.0.0
	 */
	public const BASELINE_HOOK = 'tdrift_baseline_batch';
	public const RECALC_HOOK   = 'tdrift_recalc_batch';

	/**
	 * Option holding the baseline progress.
	 *
	 * @since 1.0.0
	 */
	public const STATE_OPTION = 'tdrift_baseline_state';

	/**
	 * Default posts per batch.
	 *
	 * @since 1.0.0
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Post statuses the walks visit.
	 *
	 * @since 1.0.0
	 */
	public const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @return bool Whether a job was queued.
	 */
	public function start_recalculation(): bool {
		return $this->queue->enqueue( self::RECALC_HOOK, array( 0 ) );
	}

	/**
	 * Returns the baseline progress.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @param int  $after_id Last post ID processed by the previous batch.
	 * @param bool $force    Reset existing sync points too.
	 * @return int Posts examined in this batch.
	 */
	public function run_baseline_batch( int $after_id = 0, bool $force = false ): int {
		$ids = $this->next_ids( $after_id );
		$this->baseline_posts( $ids, $force );

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
	 * @since 1.0.0
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
	 * Builds the whole baseline in this request, batch by batch, without queueing jobs. For WP-CLI.
	 *
	 * @since 1.0.0
	 *
	 * @param bool          $force Reset existing sync points too.
	 * @param callable|null $tick  Called with the number of posts after each batch.
	 * @return array{posts: int, marked: int} Posts examined and translations marked up to date.
	 */
	public function run_baseline_now( bool $force = false, ?callable $tick = null ): array {
		$this->save_state( 'running', 0 );
		$posts  = 0;
		$marked = 0;
		$after  = 0;
		do {
			$ids     = $this->next_ids( $after );
			$marked += $this->baseline_posts( $ids, $force );
			$posts  += count( $ids );
			$after   = (int) end( $ids );
			if ( null !== $tick ) {
				$tick( count( $ids ) );
			}
			$more = count( $ids ) === $this->batch_size();
		} while ( $more );

		$this->save_state( 'done', $posts );

		return array(
			'posts'  => $posts,
			'marked' => $marked,
		);
	}

	/**
	 * What a baseline build would do, without changing anything. For `baseline --dry-run`.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Count translations that already have a sync point too.
	 * @return array{posts: int, sources: int, translations: int} Posts examined, sources found and
	 *                                                            translations that would be marked.
	 */
	public function plan_baseline( bool $force = false ): array {
		$plan  = array(
			'posts'        => 0,
			'sources'      => 0,
			'translations' => 0,
		);
		$after = 0;
		do {
			$ids            = $this->next_ids( $after );
			$plan['posts'] += count( $ids );
			$after          = (int) end( $ids );
			foreach ( $ids as $post_id ) {
				if ( $this->provider->get_source( $post_id ) !== $post_id ) {
					continue;
				}
				++$plan['sources'];
				foreach ( $this->provider->get_group( $post_id ) as $translation_id ) {
					if ( $translation_id !== $post_id && $this->needs_baseline( $translation_id, $force ) ) {
						++$plan['translations'];
					}
				}
			}
			$more = count( $ids ) === $this->batch_size();
		} while ( $more );

		return $plan;
	}

	/**
	 * Recalculates every source in this request, without queueing jobs. For WP-CLI.
	 *
	 * @since 1.0.0
	 *
	 * @param callable|null $tick Called with the number of posts after each batch.
	 * @return int Sources recalculated.
	 */
	public function recalculate_now( ?callable $tick = null ): int {
		$sources = 0;
		$after   = 0;
		do {
			$ids   = $this->next_ids( $after );
			$after = (int) end( $ids );
			foreach ( $ids as $post_id ) {
				if ( $this->provider->get_source( $post_id ) === $post_id ) {
					$this->drift->recalculate_source( $post_id );
					++$sources;
				}
			}
			if ( null !== $tick ) {
				$tick( count( $ids ) );
			}
			$more = count( $ids ) === $this->batch_size();
		} while ( $more );

		return $sources;
	}

	/**
	 * Tracked posts (sources and translations) that a full walk visits.
	 *
	 * @since 1.0.0
	 */
	public function count_posts(): int {
		$post_types = $this->tracked->post_types();
		if ( array() === $post_types ) {
			return 0;
		}

		global $wpdb;

		$types = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $types is one %s per post type.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$types}) AND post_status IN (%s, %s, %s, %s, %s)",
				...array_merge( $post_types, self::STATUSES )
			)
		);
		// phpcs:enable

		return (int) $count;
	}

	/**
	 * Marks the translations of the sources among these posts and recalculates them.
	 *
	 * @param list<int> $ids   Post IDs.
	 * @param bool      $force Reset existing sync points too.
	 * @return int Translations marked up to date.
	 */
	private function baseline_posts( array $ids, bool $force ): int {
		$marked = 0;
		foreach ( $ids as $post_id ) {
			if ( $this->provider->get_source( $post_id ) !== $post_id ) {
				continue;
			}

			foreach ( $this->provider->get_group( $post_id ) as $translation_id ) {
				if ( $translation_id !== $post_id && $this->needs_baseline( $translation_id, $force )
					&& $this->syncer->mark_synced( $translation_id, 0, SyncService::CONTEXT_BASELINE ) ) {
					++$marked;
				}
			}

			$this->drift->recalculate_source( $post_id );
		}

		return $marked;
	}

	/**
	 * Whether the baseline marks this translation: it has no sync point yet, or the build is forced.
	 *
	 * @param int  $translation_id Translation post ID.
	 * @param bool $force          Forced build.
	 */
	private function needs_baseline( int $translation_id, bool $force ): bool {
		if ( $force ) {
			return true;
		}
		$row = $this->sync->find_by_translation( $translation_id );

		return null === $row || null === $row->field_hashes;
	}

	/**
	 * Posts per batch.
	 *
	 * @since 1.0.0
	 */
	public function batch_size(): int {
		/**
		 * Filters how many posts a background batch processes.
		 *
		 * @since 1.0.0
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

		global $wpdb;

		$types    = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$statuses = self::STATUSES;

		// A read-only query on the core posts table: unlike WP_Query, it is unaffected by the
		// multilingual plugin's language filters, and it pages by ID cheaply on large sites.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $types is one %s per post type.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$types}) AND post_status IN (%s, %s, %s, %s, %s) ORDER BY ID ASC LIMIT %d",
				$after_id,
				...array_merge( $post_types, $statuses, array( $this->batch_size() ) )
			)
		);
		// phpcs:enable

		return array_values( array_map( 'intval', (array) $ids ) );
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
