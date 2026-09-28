<?php
/**
 * Activation handler.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Database\Migrator;

/**
 * Creates tables and grants capabilities on activation.
 *
 * Only cheap work happens here. The baseline build is queued for later and
 * never runs on the activation request.
 *
 * @since 0.1.0
 */
final class Activator {

	/**
	 * Activation callback.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields'     => 'ids',
					'number'     => 0,
					'network_id' => get_current_network_id(),
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Sets up the current site.
	 *
	 * @since 0.1.0
	 */
	public static function activate_site(): void {
		Migrator::migrate();
		Capabilities::grant();

		/**
		 * Fires after the plugin was activated on a site.
		 *
		 * @since 0.1.0
		 */
		do_action( 'tdrift_activated_site' );
	}
}
