<?php
/**
 * Drift recalculation.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Domain\DriftEvaluator;
use TranslationDrift\Domain\Status;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\Repositories\EventRepository;
use TranslationDrift\Services\Repositories\SnapshotRepository;
use TranslationDrift\Services\Repositories\SyncRepository;

/**
 * Recomputes the cached status of every language of a source post.
 *
 * Runs in the background after a source is saved, so list tables and the
 * dashboard only ever read the cached status.
 *
 * @since 0.1.0
 */
class DriftService {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TranslationProvider $provider      Provider.
	 * @param TrackedFields       $tracked       Tracked fields.
	 * @param Fingerprinter       $fingerprinter Fingerprinter.
	 * @param DriftEvaluator      $evaluator     Evaluator.
	 * @param SyncRepository      $sync          Sync rows.
	 * @param SnapshotRepository  $snapshots     Snapshots.
	 * @param EventRepository     $events        Events.
	 */
	public function __construct(
		private TranslationProvider $provider,
		private TrackedFields $tracked,
		private Fingerprinter $fingerprinter,
		private DriftEvaluator $evaluator,
		private SyncRepository $sync,
		private SnapshotRepository $snapshots,
		private EventRepository $events
	) {
	}

	/**
	 * Recalculates every language of a source post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $source_id Source post ID.
	 */
	public function recalculate_source( int $source_id ): void {
		$source = get_post( $source_id );

		if ( ! $source instanceof \WP_Post || ! $this->tracked->is_tracked_post_type( $source->post_type )
			|| $this->provider->get_source( $source_id ) !== $source_id ) {
			// No longer a tracked source: its rows describe nothing.
			$this->snapshots->delete_for( $this->sync->delete_by( 'source_id', $source_id ) );
			return;
		}

		$source_lang = $this->provider->get_language( $source_id );
		$group       = $this->provider->get_group( $source_id );
		$rows        = $this->sync->for_source( $source_id );
		$fingerprint = $this->fingerprinter->fingerprint( $source );

		foreach ( $this->provider->get_languages() as $lang ) {
			if ( $lang === $source_lang ) {
				continue;
			}

			$translation_id = $group[ $lang ] ?? 0;
			$row            = $rows[ $lang ] ?? null;
			unset( $rows[ $lang ] );

			if ( 0 === $translation_id || null === $row || $row->translation_id !== $translation_id || null === $row->field_hashes ) {
				$this->write_without_sync_point( $source, $lang, $translation_id, $row );
				continue;
			}

			$evaluation = $this->evaluator->evaluate(
				$this->fingerprinter->hashes_for_mode( $row->field_hashes ),
				$fingerprint->hashes,
				array(
					'translation_id' => $translation_id,
					'source_id'      => $source_id,
					'lang'           => $lang,
				)
			);
			$status     = Status::resolve( true, true, $evaluation );

			if ( $status === $row->status && $evaluation->changed_fields === $row->changed_fields ) {
				continue;
			}

			$this->sync->update_status( $row->id, $status, $evaluation->changed_fields, $source->post_modified_gmt );
			$this->log_transition( $row->status, $status, $translation_id, $source_id, $lang, $evaluation->changed_fields );
		}//end foreach

		// Rows for languages that no longer exist, or for the source's own language.
		$stale = array_map( static fn( $row ): int => $row->id, array_values( $rows ) );
		if ( array() !== $stale ) {
			$this->sync->delete_ids( $stale );
			$this->snapshots->delete_for( $stale );
		}
	}

	/**
	 * Writes a `missing` or `untracked` row.
	 *
	 * @param \WP_Post                                             $source         Source post.
	 * @param string                                               $lang           Language code.
	 * @param int                                                  $translation_id Translation ID, 0 when missing.
	 * @param \TranslationDrift\Services\Repositories\SyncRow|null $row       Existing row.
	 */
	private function write_without_sync_point( \WP_Post $source, string $lang, int $translation_id, $row ): void {
		$status = Status::resolve( 0 !== $translation_id, false, null );

		if ( null !== $row && $row->status === $status && $row->translation_id === $translation_id ) {
			return;
		}

		$sync_id = $this->sync->upsert(
			$source->ID,
			$lang,
			array(
				'translation_id'     => $translation_id,
				'post_type'          => $source->post_type,
				'status'             => $status,
				'field_hashes'       => null,
				'source_modified_at' => $source->post_modified_gmt,
			)
		);
		$this->snapshots->delete_for( array( $sync_id ) );
	}

	/**
	 * Logs and announces a status change.
	 *
	 * @param Status       $from           Previous status.
	 * @param Status       $to             New status.
	 * @param int          $translation_id Translation ID.
	 * @param int          $source_id      Source ID.
	 * @param string       $lang           Language code.
	 * @param list<string> $changed_fields Changed fields.
	 */
	private function log_transition( Status $from, Status $to, int $translation_id, int $source_id, string $lang, array $changed_fields ): void {
		if ( Status::Outdated === $to && Status::Outdated !== $from ) {
			$this->events->log( EventRepository::DRIFT_DETECTED, $translation_id, $source_id, $lang, 0, array( 'fields' => $changed_fields ) );

			/**
			 * Fires when a translation becomes outdated because its source changed.
			 *
			 * @since 0.1.0
			 *
			 * @param int          $translation_id Translation post ID.
			 * @param int          $source_id      Source post ID.
			 * @param string       $lang           Language code.
			 * @param list<string> $changed_fields Changed field keys.
			 */
			do_action( 'tdrift_drift_detected', $translation_id, $source_id, $lang, $changed_fields );
		} elseif ( Status::InSync === $to && Status::Outdated === $from ) {
			// The source changed back to what the translation was synced against.
			$this->events->log( EventRepository::DRIFT_RESOLVED, $translation_id, $source_id, $lang );
		}
	}
}
