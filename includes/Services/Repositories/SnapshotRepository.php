<?php
/**
 * `tdrift_snapshots` table access.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services\Repositories;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Database\Schema;
use TranslationDrift\Domain\SnapshotCodec;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; snapshots are written once per sync point and read only by the diff viewer.

/**
 * Stores the values of non-revisioned fields at each sync point, for diffs.
 *
 * @since 1.0.0
 */
class SnapshotRepository {

	/**
	 * Default maximum number of fields snapshotted per sync point.
	 *
	 * @since 1.0.0
	 */
	public const DEFAULT_MAX_FIELDS = 50;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param SnapshotCodec $codec Value codec.
	 */
	public function __construct( private SnapshotCodec $codec ) {
	}

	/**
	 * Table name.
	 *
	 * @since 1.0.0
	 */
	private function table(): string {
		return Schema::table( Schema::TABLE_SNAPSHOTS );
	}

	/**
	 * Replaces the snapshots of a sync point.
	 *
	 * @since 1.0.0
	 *
	 * @param int                   $sync_id Sync row ID.
	 * @param array<string, string> $values  Normalized values keyed by field.
	 */
	public function replace( int $sync_id, array $values ): void {
		global $wpdb;

		$this->delete_for( array( $sync_id ) );

		/**
		 * Filters how many fields are snapshotted per sync point. Fields past the cap have no stored diff.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max Maximum fields. Default 50.
		 */
		$max = (int) apply_filters( 'tdrift_snapshot_max_fields', self::DEFAULT_MAX_FIELDS );
		ksort( $values, SORT_STRING );
		$now = current_time( 'mysql', true );

		foreach ( array_slice( $values, 0, max( 0, $max ), true ) as $field => $value ) {
			$wpdb->insert(
				$this->table(),
				array(
					'sync_id'    => $sync_id,
					'field_key'  => substr( (string) $field, 0, 191 ),
					'value'      => $this->codec->encode( $value ),
					'created_at' => $now,
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Adds snapshots for fields a sync point doesn't have yet, keeping the existing ones.
	 *
	 * @since 1.0.0
	 *
	 * @param int                   $sync_id Sync row ID.
	 * @param array<string, string> $values  Normalized values keyed by field.
	 */
	public function add( int $sync_id, array $values ): void {
		global $wpdb;

		$existing = $this->for_sync( $sync_id );
		$now      = current_time( 'mysql', true );
		foreach ( $values as $field => $value ) {
			$field = substr( (string) $field, 0, 191 );
			if ( isset( $existing[ $field ] ) ) {
				continue;
			}
			$wpdb->insert(
				$this->table(),
				array(
					'sync_id'    => $sync_id,
					'field_key'  => $field,
					'value'      => $this->codec->encode( $value ),
					'created_at' => $now,
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Returns the snapshots of a sync point.
	 *
	 * @since 1.0.0
	 *
	 * @param int $sync_id Sync row ID.
	 * @return array<string, array{value: string, truncated: bool}>
	 */
	public function for_sync( int $sync_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT field_key, value FROM %i WHERE sync_id = %d ORDER BY field_key', $this->table(), $sync_id ) );

		$values = array();
		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			if ( isset( $row['field_key'], $row['value'] ) ) {
				$values[ (string) $row['field_key'] ] = $this->codec->decode( (string) $row['value'] );
			}
		}

		return $values;
	}

	/**
	 * Deletes the snapshots of the given sync points.
	 *
	 * @since 1.0.0
	 *
	 * @param list<int> $sync_ids Sync row IDs.
	 */
	public function delete_for( array $sync_ids ): void {
		global $wpdb;

		foreach ( array_chunk( $sync_ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is one %d per ID.
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE sync_id IN ({$placeholders})", $this->table(), ...$chunk ) );
		}
	}

	/**
	 * Deletes snapshots whose sync row no longer exists.
	 *
	 * @since 1.0.0
	 *
	 * @return int Rows deleted.
	 */
	public function prune_orphans(): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE s FROM %i s LEFT JOIN %i y ON y.id = s.sync_id WHERE y.id IS NULL',
				$this->table(),
				Schema::table( Schema::TABLE_SYNC )
			)
		);
	}
}
