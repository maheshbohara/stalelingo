<?php
/**
 * WordPress hooks that keep statuses current.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Domain\Status;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\Repositories\EventRepository;
use TranslationDrift\Services\Repositories\SnapshotRepository;
use TranslationDrift\Services\Repositories\SyncRepository;
use TranslationDrift\Settings;

/**
 * Reacts to saves, deletions and language changes.
 *
 * Saving a source only queues a recalculation, so saving never waits for it.
 *
 * @since 0.1.0
 */
class PostHooks {

	/**
	 * Job hook that recalculates one source. Argument: source post ID.
	 *
	 * @since 0.1.0
	 */
	public const RECALC_SOURCE_HOOK = 'tdrift_recalc_source';

	/**
	 * Daily maintenance hook.
	 *
	 * @since 0.1.0
	 */
	public const PRUNE_HOOK = 'tdrift_prune';

	/**
	 * Post statuses that are tracked.
	 *
	 * @since 0.1.0
	 */
	private const TRACKED_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TranslationProvider $provider  Provider.
	 * @param TrackedFields       $tracked   Tracked fields.
	 * @param Settings            $settings  Settings.
	 * @param SyncService         $syncer    Sync service.
	 * @param DriftService        $drift     Drift service.
	 * @param BaselineJob         $baseline  Batch jobs.
	 * @param SyncRepository      $sync      Sync rows.
	 * @param SnapshotRepository  $snapshots Snapshots.
	 * @param EventRepository     $events    Events.
	 * @param Queue               $queue     Job queue.
	 */
	public function __construct(
		private TranslationProvider $provider,
		private TrackedFields $tracked,
		private Settings $settings,
		private SyncService $syncer,
		private DriftService $drift,
		private BaselineJob $baseline,
		private SyncRepository $sync,
		private SnapshotRepository $snapshots,
		private EventRepository $events,
		private Queue $queue
	) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// After the multilingual plugins (priority 10–21) saved the language.
		add_action( 'save_post', array( $this, 'on_save_post' ), 100, 2 );
		$this->provider->on_translation_saved( array( $this, 'on_translation_saved' ) );
		$this->provider->on_languages_changed(
			function (): void {
				$this->baseline->start_recalculation();
			}
		);
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );

		add_action( self::RECALC_SOURCE_HOOK, array( $this->drift, 'recalculate_source' ) );
		add_action(
			BaselineJob::BASELINE_HOOK,
			function ( $after_id = 0, $force = false ): void {
				$this->baseline->run_baseline_batch( (int) $after_id, (bool) $force );
			},
			10,
			2
		);
		add_action(
			BaselineJob::RECALC_HOOK,
			function ( $after_id = 0 ): void {
				$this->baseline->run_recalc_batch( (int) $after_id );
			}
		);
		add_action( self::PRUNE_HOOK, array( $this, 'prune' ) );
	}

	/**
	 * Queues a recalculation when a source is saved.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( ! $this->is_trackable_save( $post ) ) {
			return;
		}

		if ( $this->provider->get_source( $post_id ) === $post_id ) {
			$this->queue->enqueue( self::RECALC_SOURCE_HOOK, array( $post_id ) );
		}
	}

	/**
	 * Handles a translation save: sets its first sync point, or auto-clears it when enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_translation_saved( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $this->is_trackable_save( $post ) ) {
			return;
		}

		$source_id = $this->provider->get_source( $post_id );
		if ( null === $source_id || $source_id === $post_id ) {
			return;
		}

		$row = $this->sync->find_by_translation( $post_id );
		if ( null === $row || null === $row->field_hashes ) {
			// A new translation starts in sync with the source it was created from.
			$this->syncer->mark_synced( $post_id, get_current_user_id(), SyncService::CONTEXT_CREATED );
		} elseif ( Status::Outdated === $row->status && $this->settings->get( 'auto_clear' ) ) {
			$this->syncer->mark_synced( $post_id, get_current_user_id(), SyncService::CONTEXT_AUTO_CLEAR );
		}
	}

	/**
	 * Removes the records of a deleted source or translation.
	 *
	 * A deleted translation leaves a `missing` row once its source is recalculated.
	 * A deleted revision is snapshotted first when a sync point refers to it, so diffs keep working.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 'revision' === $post->post_type ) {
			$this->syncer->preserve_revision( $post );
			return;
		}

		$as_translation = $this->sync->find_by_translation( $post_id );

		$ids = array_merge(
			$this->sync->delete_by( 'translation_id', $post_id ),
			$this->sync->delete_by( 'source_id', $post_id )
		);
		if ( array() !== $ids ) {
			$this->snapshots->delete_for( $ids );
		}

		if ( null !== $as_translation && $as_translation->source_id !== $post_id ) {
			$this->queue->enqueue( self::RECALC_SOURCE_HOOK, array( $as_translation->source_id ) );
		}
	}

	/**
	 * Daily maintenance: removes orphaned snapshots and old events.
	 *
	 * @since 0.1.0
	 */
	public function prune(): void {
		$this->snapshots->prune_orphans();
		$this->events->prune();
	}

	/**
	 * Whether a save should be tracked: not an autosave or revision, a tracked type, a live status.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function is_trackable_save( \WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}

		return in_array( $post->post_status, self::TRACKED_STATUSES, true )
			&& $this->tracked->is_tracked_post_type( $post->post_type );
	}
}
