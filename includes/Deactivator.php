<?php
/**
 * Deactivation handler.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

/**
 * Stops scheduled work on deactivation. Data is kept until uninstall.
 *
 * @since 0.1.0
 */
final class Deactivator {

	/**
	 * Returns the WP-Cron and Action Scheduler hooks the plugin schedules.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string>
	 */
	public static function scheduled_hooks(): array {
		return array();
	}

	/**
	 * Deactivation callback.
	 *
	 * @since 0.1.0
	 */
	public static function deactivate(): void {
		self::clear_scheduled();
	}

	/**
	 * Unschedules every plugin job on the current site.
	 *
	 * @since 0.1.0
	 */
	public static function clear_scheduled(): void {
		foreach ( self::scheduled_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
	}
}
