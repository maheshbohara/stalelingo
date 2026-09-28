<?php
/**
 * Marking translations as up to date.
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

/**
 * Records sync points: the source's field hashes at the moment a translation is marked up to date.
 *
 * Permission checks belong to the caller (see {@see Permissions}).
 *
 * @since 1.0.0
 */
class SyncService {

	/**
	 * Why a translation was marked up to date.
	 *
	 * @since 1.0.0
	 */
	public const CONTEXT_MANUAL     = 'manual';
	public const CONTEXT_BASELINE   = 'baseline';
	public const CONTEXT_CREATED    = 'created';
	public const CONTEXT_AUTO_CLEAR = 'auto_clear';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TranslationProvider $provider      Provider.
	 * @param Fingerprinter       $fingerprinter Fingerprinter.
	 * @param SyncRepository      $sync          Sync rows.
	 * @param SnapshotRepository  $snapshots     Snapshots.
	 * @param EventRepository     $events        Events.
	 */
	public function __construct(
		private TranslationProvider $provider,
		private Fingerprinter $fingerprinter,
		private SyncRepository $sync,
		private SnapshotRepository $snapshots,
		private EventRepository $events
	) {
	}

	/**
	 * Marks a translation as up to date with its source's current content.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $translation_id Translation post ID.
	 * @param int    $user_id        Acting user, 0 for the system.
	 * @param string $context        One of the CONTEXT_* constants.
	 * @return bool False when the post isn't a translation of a source.
	 */
	public function mark_synced( int $translation_id, int $user_id = 0, string $context = self::CONTEXT_MANUAL ): bool {
		$source_id = $this->provider->get_source( $translation_id );
		$lang      = $this->provider->get_language( $translation_id );
		$source    = null === $source_id ? null : get_post( $source_id );

		if ( null === $lang || ! $source instanceof \WP_Post || $source_id === $translation_id ) {
			return false;
		}

		$sync_point  = $this->fingerprinter->sync_point( $source );
		$fingerprint = $sync_point['fingerprint'];
		$revision_id = $this->latest_revision_id( $source_id );
		$now         = current_time( 'mysql', true );

		$sync_id = $this->sync->upsert(
			$source_id,
			$lang,
			array(
				'translation_id'     => $translation_id,
				'post_type'          => $source->post_type,
				'status'             => Status::InSync,
				'field_hashes'       => $sync_point['hashes'],
				'changed_fields'     => array(),
				'source_rev_id'      => $revision_id,
				'synced_at'          => $now,
				'synced_by'          => $user_id,
				'source_modified_at' => $source->post_modified_gmt,
			)
		);

		$this->snapshots->replace( $sync_id, $this->snapshot_values( $fingerprint, $revision_id ) );

		$event = self::CONTEXT_AUTO_CLEAR === $context ? EventRepository::AUTO_CLEARED : EventRepository::MARKED_SYNCED;
		$this->events->log( $event, $translation_id, $source_id, $lang, $user_id, array( 'context' => $context ) );

		/**
		 * Fires after a translation was marked up to date.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $translation_id Translation post ID.
		 * @param int    $source_id      Source post ID.
		 * @param string $lang           Language code.
		 * @param int    $user_id        Acting user, 0 for the system.
		 * @param string $context        'manual', 'baseline', 'created' or 'auto_clear'.
		 */
		do_action( 'tdrift_marked_synced', $translation_id, $source_id, $lang, $user_id, $context );

		return true;
	}

	/**
	 * Keeps the diff of sync points that refer to a revision about to be deleted.
	 *
	 * Sites that limit revisions delete old ones on every save, including the one a
	 * sync point was recorded against. Before that happens, its title, content and
	 * excerpt are snapshotted and the sync point stops referring to the revision.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $revision Revision being deleted.
	 * @return int Sync points updated.
	 */
	public function preserve_revision( \WP_Post $revision ): int {
		$rows = 'revision' === $revision->post_type ? $this->sync->find_by_revision( $revision->ID ) : array();
		if ( array() === $rows ) {
			return 0;
		}

		$values = array_filter(
			$this->fingerprinter->values( $revision, DiffService::REVISIONED_FIELDS, false ),
			static fn( $value ): bool => null !== $value
		);
		foreach ( $rows as $row ) {
			$this->snapshots->add( $row->id, $values );
			$this->sync->clear_revision( $row->id );
		}

		return count( $rows );
	}

	/**
	 * Values to snapshot: fields that post revisions don't cover, or every field when revisions are off.
	 *
	 * @param Fingerprint $fingerprint Source fingerprint.
	 * @param int         $revision_id Latest source revision, 0 when none.
	 * @return array<string, string>
	 */
	private function snapshot_values( Fingerprint $fingerprint, int $revision_id ): array {
		$revisioned = DiffService::REVISIONED_FIELDS;
		$values     = array();

		foreach ( $fingerprint->values as $field => $value ) {
			if ( null !== $value && ( 0 === $revision_id || ! in_array( $field, $revisioned, true ) ) ) {
				$values[ $field ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Latest revision of a post, 0 when it has none.
	 *
	 * @param int $post_id Post ID.
	 */
	private function latest_revision_id( int $post_id ): int {
		$latest = wp_get_latest_revision_id_and_total_count( $post_id );

		return is_array( $latest ) ? (int) $latest['latest_id'] : 0;
	}
}
