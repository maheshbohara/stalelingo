<?php
/**
 * `tdrift_events` table access.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services\Repositories;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own append-only log table.

/**
 * Append-only log of drift events.
 *
 * @since 0.1.0
 */
class EventRepository {

	/**
	 * Event types.
	 *
	 * @since 0.1.0
	 */
	public const DRIFT_DETECTED = 'drift_detected';
	public const DRIFT_RESOLVED = 'drift_resolved';
	public const MARKED_SYNCED  = 'marked_synced';
	public const AUTO_CLEARED   = 'auto_cleared';

	/**
	 * Default retention in days.
	 *
	 * @since 0.1.0
	 */
	public const DEFAULT_RETENTION_DAYS = 180;

	/**
	 * Table name.
	 *
	 * @since 0.1.0
	 */
	private function table(): string {
		return Schema::table( Schema::TABLE_EVENTS );
	}

	/**
	 * Records an event.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $event          Event type.
	 * @param int                  $translation_id Translation post ID.
	 * @param int                  $source_id      Source post ID.
	 * @param string               $lang           Language code.
	 * @param int                  $user_id        Acting user, 0 for the system.
	 * @param array<string, mixed> $context        Extra data.
	 */
	public function log( string $event, int $translation_id, int $source_id, string $lang, int $user_id = 0, array $context = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			array(
				'event'          => $event,
				'translation_id' => $translation_id,
				'source_id'      => $source_id,
				'lang'           => $lang,
				'user_id'        => $user_id,
				'context'        => array() === $context ? null : (string) wp_json_encode( $context ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Returns the events of a translation, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $translation_id Translation post ID.
	 * @param int $limit          Maximum rows.
	 * @return list<object>
	 */
	public function for_translation( int $translation_id, int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE translation_id = %d ORDER BY id DESC LIMIT %d', $this->table(), $translation_id, $limit )
		);

		return array_values( array_filter( (array) $rows, 'is_object' ) );
	}

	/**
	 * Deletes events older than the retention period.
	 *
	 * @since 0.1.0
	 *
	 * @return int Rows deleted.
	 */
	public function prune(): int {
		global $wpdb;

		/**
		 * Filters how many days drift events are kept.
		 *
		 * @since 0.1.0
		 *
		 * @param int $days Days. Default 180.
		 */
		$days   = max( 1, (int) apply_filters( 'tdrift_event_retention_days', self::DEFAULT_RETENTION_DAYS ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), $cutoff ) );
	}
}
