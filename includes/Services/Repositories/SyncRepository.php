<?php
/**
 * `stalelingo_sync` table access.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Services\Repositories;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Database\Schema;
use Stalelingo\Domain\Status;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; no core API exists for it, and rows change on every recalculation, so object caching would only serve stale status.

/**
 * Reads and writes sync points and cached statuses.
 *
 * @since 1.0.0
 */
class SyncRepository {

	/**
	 * Table name.
	 *
	 * @since 1.0.0
	 */
	private function table(): string {
		return Schema::table( Schema::TABLE_SYNC );
	}

	/**
	 * Finds the row of a translation.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Rows whose sync point refers to a source revision.
	 *
	 * @since 1.0.0
	 *
	 * @param int $revision_id Revision post ID.
	 * @return list<SyncRow>
	 */
	public function find_by_revision( int $revision_id ): array {
		global $wpdb;

		if ( $revision_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source_rev_id = %d', $this->table(), $revision_id ) );

		$result = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$result[] = SyncRow::from_db( $row );
			}
		}

		return $result;
	}

	/**
	 * Forgets the source revision of a sync point, once its fields are snapshotted instead.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Row ID.
	 */
	public function clear_revision( int $id ): void {
		global $wpdb;

		$wpdb->update( $this->table(), array( 'source_rev_id' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Rows with one of the given statuses and an existing translation, newest source change first.
	 *
	 * Trashed sources are left out.
	 *
	 * @since 1.0.0
	 *
	 * @param list<Status>      $statuses Statuses.
	 * @param list<string>|null $langs    Languages, or null for all.
	 * @param int               $limit    Maximum rows; 0 for no limit.
	 * @return list<SyncRow>
	 */
	public function find_with_status( array $statuses, ?array $langs = null, int $limit = 0 ): array {
		global $wpdb;

		$statuses = array_values( array_map( static fn( Status $s ): string => $s->value, $statuses ) );
		if ( array() === $statuses || ( null !== $langs && array() === $langs ) ) {
			return array();
		}

		$lang_list = $langs ?? array( '' );
		$status_in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$lang_in   = implode( ', ', array_fill( 0, count( $lang_list ), '%s' ) );
		$values    = array_merge(
			array( $this->table(), $wpdb->posts ),
			$statuses,
			array( null === $langs ? 1 : 0 ),
			$lang_list,
			array( $limit > 0 ? $limit : PHP_INT_MAX )
		);

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_in and $lang_in are one placeholder per value.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.* FROM %i s INNER JOIN %i p ON p.ID = s.source_id
				WHERE p.post_status <> 'trash' AND s.translation_id > 0
				AND s.status IN ({$status_in})
				AND ( %d = 1 OR s.lang IN ({$lang_in}) )
				ORDER BY s.source_modified_at DESC, s.id DESC
				LIMIT %d",
				...$values
			)
		);
		// phpcs:enable
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As at the top of the file.

		$result = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$result[] = SyncRow::from_db( $row );
			}
		}

		return $result;
	}

	/**
	 * Rows marked up to date by a user, for the personal data exporter.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return list<SyncRow>
	 */
	public function for_synced_by( int $user_id, int $limit, int $offset = 0 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE synced_by = %d ORDER BY id LIMIT %d OFFSET %d', $this->table(), $user_id, $limit, $offset )
		);

		$result = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$result[] = SyncRow::from_db( $row );
			}
		}

		return $result;
	}

	/**
	 * Removes a user from the sync points they marked, for the personal data eraser.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @return int Rows anonymized.
	 */
	public function anonymize_user( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->update( $this->table(), array( 'synced_by' => 0 ), array( 'synced_by' => $user_id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Rows of many sources, grouped by source then language: one query for a dashboard page.
	 *
	 * @since 1.0.0
	 *
	 * @param list<int> $source_ids Source post IDs.
	 * @return array<int, array<string, SyncRow>>
	 */
	public function for_sources( array $source_ids ): array {
		global $wpdb;

		$source_ids = array_values( array_unique( array_filter( array_map( 'intval', $source_ids ) ) ) );
		if ( array() === $source_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $source_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is one %d per ID.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE source_id IN ({$placeholders})", $this->table(), ...$source_ids ) );

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$sync                                       = SyncRow::from_db( $row );
				$grouped[ $sync->source_id ][ $sync->lang ] = $sync;
			}
		}

		return $grouped;
	}

	/**
	 * One page of source IDs matching dashboard filters, and the total number of matching sources.
	 *
	 * A source matches when at least one of its rows matches every row filter
	 * (languages, statuses, post types, last change). Trashed sources are left out.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @phpstan-param array{
	 *     langs?: list<string>,
	 *     statuses?: list<string>,
	 *     post_types?: list<string>,
	 *     author?: int,
	 *     search?: string,
	 *     changed_after?: string,
	 *     changed_before?: string,
	 *     orderby?: string,
	 *     order?: string,
	 *     page?: int,
	 *     per_page?: int
	 * } $args
	 * @return array{ids: list<int>, total: int}
	 */
	public function query_sources( array $args ): array {
		global $wpdb;

		// Each list filter is either off (null) or a list; an empty list matches nothing,
		// e.g. a translator with no languages.
		$lists = array();
		foreach ( array( 'langs', 'statuses', 'post_types' ) as $key ) {
			$list = isset( $args[ $key ] ) ? array_values( array_filter( $args[ $key ], 'is_string' ) ) : null;
			if ( array() === $list ) {
				return array(
					'ids'   => array(),
					'total' => 0,
				);
			}
			$lists[ $key ] = $list;
		}

		$langs    = $lists['langs'] ?? array( '' );
		$statuses = $lists['statuses'] ?? array( '' );
		$types    = $lists['post_types'] ?? array( '' );

		// One placeholder per value. The query below is fixed: a filter that is off passes 1
		// to its "all" switch, so it needs no dynamic SQL.
		$lang_in   = implode( ', ', array_fill( 0, count( $langs ), '%s' ) );
		$status_in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$type_in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		$search  = isset( $args['search'] ) && '' !== $args['search'] ? '%' . $wpdb->esc_like( $args['search'] ) . '%' : '';
		$filters = array_merge(
			array( $this->table(), $wpdb->posts ),
			array( null === $lists['langs'] ? 1 : 0 ),
			$langs,
			array( null === $lists['statuses'] ? 1 : 0 ),
			$statuses,
			array( null === $lists['post_types'] ? 1 : 0 ),
			$types,
			array(
				(int) ( $args['author'] ?? 0 ),
				(int) ( $args['author'] ?? 0 ),
				$search,
				$search,
				(string) ( $args['changed_after'] ?? '' ),
				(string) ( $args['changed_after'] ?? '' ),
				(string) ( $args['changed_before'] ?? '' ),
				(string) ( $args['changed_before'] ?? '' ),
			)
		);

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$sort     = ( 'title' === ( $args['orderby'] ?? 'modified' ) ? 'title' : 'modified' ) . '_' . ( 'asc' === ( $args['order'] ?? 'desc' ) ? 'asc' : 'desc' );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $lang_in, $status_in and $type_in are one placeholder per value.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT s.source_id) FROM %i s INNER JOIN %i p ON p.ID = s.source_id
				WHERE p.post_status <> 'trash'
				AND ( %d = 1 OR s.lang IN ({$lang_in}) )
				AND ( %d = 1 OR s.status IN ({$status_in}) )
				AND ( %d = 1 OR s.post_type IN ({$type_in}) )
				AND ( %d = 0 OR p.post_author = %d )
				AND ( %s = '' OR p.post_title LIKE %s )
				AND ( %s = '' OR p.post_modified_gmt >= %s )
				AND ( %s = '' OR p.post_modified_gmt <= %s )",
				...$filters
			)
		);

		$ids = 0 === $total ? array() : $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.source_id FROM %i s INNER JOIN %i p ON p.ID = s.source_id
				WHERE p.post_status <> 'trash'
				AND ( %d = 1 OR s.lang IN ({$lang_in}) )
				AND ( %d = 1 OR s.status IN ({$status_in}) )
				AND ( %d = 1 OR s.post_type IN ({$type_in}) )
				AND ( %d = 0 OR p.post_author = %d )
				AND ( %s = '' OR p.post_title LIKE %s )
				AND ( %s = '' OR p.post_modified_gmt >= %s )
				AND ( %s = '' OR p.post_modified_gmt <= %s )
				GROUP BY s.source_id
				ORDER BY
					CASE WHEN %s = 'title_asc' THEN MAX(p.post_title) END ASC,
					CASE WHEN %s = 'title_desc' THEN MAX(p.post_title) END DESC,
					CASE WHEN %s = 'modified_asc' THEN MAX(p.post_modified_gmt) END ASC,
					CASE WHEN %s = 'modified_desc' THEN MAX(p.post_modified_gmt) END DESC,
					CASE WHEN %s IN ('title_asc', 'modified_asc') THEN s.source_id END ASC,
					s.source_id DESC
				LIMIT %d OFFSET %d",
				...array_merge( $filters, array( $sort, $sort, $sort, $sort, $sort, $per_page, $offset ) )
			)
		);
		// phpcs:enable
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As at the top of the file.

		return array(
			'ids'   => array_values( array_map( 'intval', (array) $ids ) ),
			'total' => $total,
		);
	}

	/**
	 * Row counts per status, overall and by language and post type. Trashed sources are left out.
	 *
	 * @since 1.0.0
	 *
	 * @return array{totals: array<string, int>, by_language: array<string, array<string, int>>, by_post_type: array<string, array<string, int>>}
	 */
	public function summary(): array {
		global $wpdb;

		/**
		 * Zero counts for every status.
		 *
		 * @var array<string, int> $empty
		 */
		$empty  = array_fill_keys( array_map( static fn( Status $s ): string => $s->value, Status::cases() ), 0 );
		$result = array(
			'totals'       => $empty,
			'by_language'  => array(),
			'by_post_type' => array(),
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.lang, s.post_type, s.status, COUNT(*) AS total FROM %i s INNER JOIN %i p ON p.ID = s.source_id WHERE p.post_status <> 'trash' GROUP BY s.lang, s.post_type, s.status",
				$this->table(),
				$wpdb->posts
			)
		);
		foreach ( (array) $rows as $row ) {
			$row    = (array) $row;
			$status = (string) ( $row['status'] ?? '' );
			if ( ! isset( $empty[ $status ] ) ) {
				continue;
			}
			$lang  = (string) $row['lang'];
			$type  = (string) $row['post_type'];
			$count = (int) $row['total'];

			$result['by_language'][ $lang ]            ??= $empty;
			$result['by_post_type'][ $type ]           ??= $empty;
			$result['totals'][ $status ]                += $count;
			$result['by_language'][ $lang ][ $status ]  += $count;
			$result['by_post_type'][ $type ][ $status ] += $count;
		}

		return $result;
	}

	/**
	 * IDs of the sources and translations of a post type that have a row with the given status.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
