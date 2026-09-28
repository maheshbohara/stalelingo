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
use TranslationDrift\Services\PostHooks;

/**
 * Creates tables and grants capabilities on activation.
 *
 * Only cheap work happens here. The baseline build is queued for later and
 * never runs on the activation request.
 *
 * @since 1.0.0
 */
final class Activator {

	/**
	 * Activation callback.
	 *
	 * @since 1.0.0
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
	 * Schedules the recurring maintenance job if it is missing.
	 *
	 * Also runs on admin requests, so sites activated before a job existed get it after an update.
	 *
	 * @since 1.0.0
	 */
	public static function ensure_schedules(): void {
		if ( ! wp_next_scheduled( PostHooks::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PostHooks::PRUNE_HOOK );
		}
	}

	/**
	 * Sets up the current site.
	 *
	 * @since 1.0.0
	 */
	public static function activate_site(): void {
		Migrator::migrate();
		Capabilities::grant();

		self::ensure_schedules();

		// Queued, never run on the activation request itself.
		$container = Plugin::container();
		if ( $container->has_provider() ) {
			$container->baseline()->start_baseline();
		}

		/**
		 * Fires after the plugin was activated on a site.
		 *
		 * @since 1.0.0
		 */
		do_action( 'tdrift_activated_site' );
	}
}
