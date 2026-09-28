<?php
/**
 * Custom table definitions.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Names and `dbDelta()` definitions of the plugin's tables.
 *
 * @since 1.0.0
 */
final class Schema {

	/**
	 * Table name suffixes, without the site prefix.
	 *
	 * @since 1.0.0
	 */
	public const TABLE_SYNC      = 'tdrift_sync';
	public const TABLE_SNAPSHOTS = 'tdrift_snapshots';
	public const TABLE_EVENTS    = 'tdrift_events';

	/**
	 * Returns the full name of a plugin table for the current site.
	 *
	 * @since 1.0.0
	 *
	 * @param string $suffix One of the TABLE_* constants.
	 */
	public static function table( string $suffix ): string {
		global $wpdb;

		return $wpdb->prefix . $suffix;
	}

	/**
	 * Returns every plugin table name for the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return array(
			self::table( self::TABLE_SYNC ),
			self::table( self::TABLE_SNAPSHOTS ),
			self::table( self::TABLE_EVENTS ),
		);
	}

	/**
	 * Returns the `CREATE TABLE` statements in the format `dbDelta()` expects.
	 *
	 * One `tdrift_sync` row exists per (source, language) pair. A row whose
	 * `translation_id` is 0 records a missing translation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Output of `$wpdb->get_charset_collate()`.
	 * @return list<string>
	 */
	public static function statements( string $charset_collate ): array {
		$sync      = self::table( self::TABLE_SYNC );
		$snapshots = self::table( self::TABLE_SNAPSHOTS );
		$events    = self::table( self::TABLE_EVENTS );

		return array(
			"CREATE TABLE {$sync} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  translation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_id bigint(20) unsigned NOT NULL,
  lang varchar(20) NOT NULL,
  post_type varchar(20) NOT NULL DEFAULT '',
  source_rev_id bigint(20) unsigned NOT NULL DEFAULT 0,
  field_hashes longtext NULL,
  changed_fields text NULL,
  status varchar(20) NOT NULL DEFAULT 'untracked',
  synced_at datetime NULL DEFAULT NULL,
  synced_by bigint(20) unsigned NOT NULL DEFAULT 0,
  source_modified_at datetime NULL DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_lang (source_id,lang),
  KEY translation_id (translation_id),
  KEY lang_status (lang,status),
  KEY status_type (status,post_type),
  KEY synced_by (synced_by),
  KEY source_rev_id (source_rev_id)
) {$charset_collate};",
			"CREATE TABLE {$snapshots} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sync_id bigint(20) unsigned NOT NULL,
  field_key varchar(191) NOT NULL,
  value longblob NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY sync_field (sync_id,field_key)
) {$charset_collate};",
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(32) NOT NULL,
  translation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  lang varchar(20) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY translation_created (translation_id,created_at),
  KEY source_id (source_id),
  KEY user_id (user_id),
  KEY created_at (created_at)
) {$charset_collate};",
		);
	}
}
