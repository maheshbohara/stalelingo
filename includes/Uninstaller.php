<?php
/**
 * Data removal.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Database\Migrator;
use TranslationDrift\Database\Schema;

/**
 * Removes plugin data when the site owner opted in.
 *
 * @since 1.0.0
 */
final class Uninstaller {

	/**
	 * Option holding the plugin settings.
	 *
	 * @since 1.0.0
	 */
	public const SETTINGS_OPTION = Settings::OPTION;

	/**
	 * Uninstalls on every site of the network, or on the single site.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Whether the current site asked for its data to be deleted on uninstall.
	 *
	 * @since 1.0.0
	 */
	public static function should_delete_data(): bool {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $settings ) && ! empty( $settings['delete_data'] );
	}

	/**
	 * Uninstalls on the current site.
	 *
	 * Scheduled jobs are always removed. Tables, options and the capability are
	 * removed only when the "Delete data on uninstall" setting is on.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall_site(): void {
		global $wpdb;

		Deactivator::clear_scheduled();

		if ( ! self::should_delete_data() ) {
			return;
		}

		foreach ( Schema::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( Migrator::OPTION );
		delete_option( self::SETTINGS_OPTION );
		Capabilities::revoke();
	}
}
