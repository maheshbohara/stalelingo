<?php
/**
 * Plugin bootstrap.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Admin\AdminPage;
use TranslationDrift\Admin\DependencyNotice;
use TranslationDrift\Database\Migrator;
use TranslationDrift\Providers\ProviderDetector;

/**
 * Wires the plugin's services into WordPress.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Whether boot() already ran.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers hooks. Runs on `plugins_loaded`, after Polylang and WPML have loaded.
	 *
	 * @since 0.1.0
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Migrator::register();

		$detector = new ProviderDetector();

		if ( is_admin() ) {
			( new AdminPage() )->register();
			( new DependencyNotice( $detector ) )->register();
		}
	}
}
