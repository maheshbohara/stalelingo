<?php
/**
 * `tdrift_sync` table access.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services\Repositories;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Database\Schema;
use TranslationDrift\Domain\Status;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; no core API exists for it, and rows change on every recalculation, so object caching would only serve stale status.

/**
 * Reads and writes sync points and cached statuses.
 *
 * @since 0.1.0
 */
class SyncRepository {

	/**
	 * Table name.
	 *
	 * @since 0.1.0
	 */
	private function table(): string {
		return Schema::table( Schema::TABLE_SYNC );
	}

	/**
	 * Finds the row of a translation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $translation_id Translation post ID.
	 */
	public function find_by_translation( int $translation_id ): ?SyncRow {
		global $wpdb;

		if ( $translation_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE translation_id = %d LIMIT 1', $this->table(), $translation_id ) );

		return is_object( $row ) ? SyncRow::from_db( $row ) : null;
	}

	/**
	 * Returns the rows of a source, keyed by language.
	 *
	 * @since 0.1.0
	 *
	 * @param int $source_id Source post ID.
	 * @return array<string, SyncRow>
	 */
	public function for_source( int $source_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source_id = %d', $this->table(), $source_id ) );

		$by_lang = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$sync                   = SyncRow::from_db( $row );
				$by_lang[ $sync->lang ] = $sync;
			}
		}

		return $by_lang;
	}

	/**
	 * Rows of many posts at once, as sources or translations: one query for a whole list-table page.
	 *
	 * @since 0.1.0
	 *
	 * @param list<int> $post_ids Post IDs.
	 * @return list<SyncRow>
	 */
	public function for_posts( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( array() === $post_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is one %d per ID, used twice.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE source_id IN ({$placeholders}) OR translation_id IN ({$placeholders})", $this->table(), ...$post_ids, ...$post_ids ) );

		$result = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$result[] = SyncRow::from_db( $row );
			}
		}

		return $result;
	}

	/**
	 * IDs of the sources and translations of a post type that have a row with the given status.
	 *
	 * @since 0.1.0
	 *
	 * @param Status $status    Status.
	 * @param string $post_type Post type.
	 * @return list<int>
	 */
	public function post_ids_with_status( Status $status, string $post_type ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT source_id, translation_id FROM %i WHERE status = %s AND post_type = %s', $this->table(), $status->value, $post_type )
		);

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$row   = (array) $row;
			$ids[] = (int) ( $row['source_id'] ?? 0 );
			$ids[] = (int) ( $row['translation_id'] ?? 0 );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Inserts or replaces the row for a (source, language) pair.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $source_id Source post ID.
	 * @param string               $lang      Language code.
	 * @param array<string, mixed> $data      Row values: translation_id, post_type and status (required); field_hashes,
	 *                                        changed_fields, source_rev_id, synced_at, synced_by and source_modified_at.
	 * @phpstan-param array{
	 *     translation_id: int,
	 *     post_type: string,
	 *     status: Status,
	 *     field_hashes?: array<string, string>|null,
	 *     changed_fields?: list<string>,
	 *     source_rev_id?: int,
	 *     synced_at?: string|null,
	 *     synced_by?: int,
	 *     source_modified_at?: string|null
	 * } $data
	 * @return int Row ID.
	 */
	public function upsert( int $source_id, string $lang, array $data ): int {
		global $wpdb;

		$values  = array(
			'translation_id'     => $data['translation_id'],
			'post_type'          => $data['post_type'],
			'source_rev_id'      => $data['source_rev_id'] ?? 0,
			'field_hashes'       => isset( $data['field_hashes'] ) ? (string) wp_json_encode( $data['field_hashes'] ) : null,
			'changed_fields'     => ! empty( $data['changed_fields'] ) ? (string) wp_json_encode( array_values( $data['changed_fields'] ) ) : null,
			'status'             => $data['status']->value,
			'synced_at'          => $data['synced_at'] ?? null,
			'synced_by'          => $data['synced_by'] ?? 0,
			'source_modified_at' => $data['source_modified_at'] ?? null,
			'updated_at'         => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		$existing = $this->id_for( $source_id, $lang );
		if ( null === $existing ) {
			$inserted = $this->insert( $source_id, $lang, $values, $formats );
			if ( null !== $inserted ) {
				return $inserted;
			}
			// A concurrent job inserted the same (source, language) first: update its row instead.
			$existing = $this->id_for( $source_id, $lang ) ?? 0;
		}

		$wpdb->update( $this->table(), $values, array( 'id' => $existing ), $formats, array( '%d' ) );

		return $existing;
	}

	/**
	 * Inserts a new row.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $source_id Source post ID.
	 * @param string               $lang      Language code.
	 * @param array<string, mixed> $values    Other column values.
	 * @param list<string>         $formats   Formats of $values.
	 * @return int|null Row ID, or null when the insert failed.
	 */
	private function insert( int $source_id, string $lang, array $values, array $formats ): ?int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table(),
			array_merge(
				array(
					'source_id' => $source_id,
					'lang'      => $lang,
				),
				$values
			),
			array_merge( array( '%d', '%s' ), $formats )
		);

		return false === $inserted ? null : (int) $wpdb->insert_id;
	}

	/**
	 * ID of the row for a (source, language) pair.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Language code.
	 */
	private function id_for( int $source_id, string $lang ): ?int {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_id = %d AND lang = %s', $this->table(), $source_id, $lang ) );

		return null === $id ? null : (int) $id;
	}

	/**
	 * Updates the cached status of a row.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $id                 Row ID.
	 * @param Status       $status             New status.
	 * @param list<string> $changed_fields     Fields changed since the sync point.
	 * @param string|null  $source_modified_at Source modification time (GMT).
	 */
	public function update_status( int $id, Status $status, array $changed_fields, ?string $source_modified_at ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'             => $status->value,
				'changed_fields'     => array() === $changed_fields ? null : (string) wp_json_encode( array_values( $changed_fields ) ),
				'source_modified_at' => $source_modified_at,
				'updated_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Deletes rows and returns their IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column 'source_id' or 'translation_id'.
	 * @param int    $post_id Post ID.
	 * @return list<int> Deleted row IDs.
	 */
	public function delete_by( string $column, int $post_id ): array {
		global $wpdb;

		if ( ! in_array( $column, array( 'source_id', 'translation_id' ), true ) || $post_id <= 0 ) {
			return array();
		}

		$ids = array_values( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE %i = %d', $this->table(), $column, $post_id ) ) ) );
		$this->delete_ids( $ids );

		return $ids;
	}

	/**
	 * Deletes rows by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param list<int> $ids Row IDs.
	 */
	public function delete_ids( array $ids ): void {
		global $wpdb;

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is one %d per ID.
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", $this->table(), ...$chunk ) );
		}
	}

	/**
	 * Counts rows per status.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		global $wpdb;

		$counts = array_fill_keys( array_map( static fn( Status $s ): string => $s->value, Status::cases() ), 0 );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $this->table() ) );
		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			if ( isset( $row['status'], $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $counts;
	}
}
