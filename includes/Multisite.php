<?php
/**
 * Multisite support.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Database\Schema;

/**
 * Keeps each site's tables in step with the network.
 *
 * Network activation sets up every existing site ({@see Activator::activate()}).
 * Sites created later are set up here when the plugin is network-active, and a
 * deleted site's tables are dropped with the rest of its tables.
 *
 * @since 1.0.0
 */
final class Multisite {

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function register(): void {
		// After core created the new site's own tables (priority 10) and default content (100).
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( self::class, 'drop_tables' ), 10, 2 );
	}

	/**
	 * Sets up a new site when the plugin is network-active.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function initialize_site( $site ): void {
		if ( ! $site instanceof \WP_Site || ! self::is_network_active() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		Activator::activate_site();
		restore_current_blog();
	}

	/**
	 * Adds the plugin's tables to those dropped with a deleted site.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $tables  Table names.
	 * @param int          $site_id Site being deleted.
	 * @return list<string>
	 */
	public static function drop_tables( $tables, $site_id = 0 ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( (int) $site_id );

		return array_values(
			array_unique(
				array_merge(
					array_values( (array) $tables ),
					array(
						$prefix . Schema::TABLE_SYNC,
						$prefix . Schema::TABLE_SNAPSHOTS,
						$prefix . Schema::TABLE_EVENTS,
					)
				)
			)
		);
	}

	/**
	 * Whether the plugin is active for the whole network.
	 *
	 * @since 1.0.0
	 */
	public static function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( TDRIFT_FILE ) );
	}
}
