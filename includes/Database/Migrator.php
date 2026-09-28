<?php
/**
 * Versioned schema migrations.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin tables for the current site.
 *
 * `dbDelta()` brings every table to the shape in {@see Schema}. Steps listed in
 * {@see Migrator::migrations()} handle changes `dbDelta()` can't express, such
 * as data backfills or dropped columns.
 *
 * @since 0.1.0
 */
final class Migrator {

	/**
	 * Current schema version. Bump when {@see Schema} or {@see Migrator::migrations()} changes.
	 *
	 * @since 0.1.0
	 */
	public const DB_VERSION = 2;

	/**
	 * Option holding the installed schema version for the site.
	 *
	 * @since 0.1.0
	 */
	public const OPTION = 'tdrift_db_version';

	/**
	 * Hooks the upgrade check. Plugin updates do not re-run activation, so the
	 * version is compared on every load; this costs one autoloaded option read.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 1 );
	}

	/**
	 * Returns the installed schema version for the current site.
	 *
	 * @since 0.1.0
	 */
	public static function installed_version(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * Runs the migrations if the site is behind the code.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_upgrade(): void {
		if ( self::installed_version() < self::DB_VERSION ) {
			self::migrate();
		}
	}

	/**
	 * Creates or updates the tables, then runs pending migration steps.
	 *
	 * @since 0.1.0
	 */
	public static function migrate(): void {
		global $wpdb;

		$from = self::installed_version();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::statements( $wpdb->get_charset_collate() ) );

		foreach ( self::migrations() as $version => $step ) {
			if ( $version > $from && $version <= self::DB_VERSION ) {
				$step();
			}
		}

		update_option( self::OPTION, self::DB_VERSION, true );

		/**
		 * Fires after the plugin tables were created or upgraded on a site.
		 *
		 * @since 0.1.0
		 *
		 * @param int $from Schema version before the upgrade (0 on a fresh install).
		 * @param int $to   Schema version after the upgrade.
		 */
		do_action( 'tdrift_migrated', $from, self::DB_VERSION );
	}

	/**
	 * Data migration steps, keyed by the schema version that introduced them.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, callable(): void>
	 */
	private static function migrations(): array {
		return array();
	}
}
