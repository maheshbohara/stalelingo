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
use TranslationDrift\Admin\SettingsPage;
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
	 * Shared container.
	 *
	 * @since 0.1.0
	 * @var Container|null
	 */
	private static ?Container $container = null;

	/**
	 * Returns the shared service container.
	 *
	 * @since 0.1.0
	 */
	public static function container(): Container {
		if ( null === self::$container ) {
			self::$container = new Container( new ProviderDetector() );
		}

		return self::$container;
	}

	/**
	 * Replaces the shared container. For tests.
	 *
	 * @since 0.1.0
	 *
	 * @param Container|null $container Container, or null to rebuild on next use.
	 */
	public static function set_container( ?Container $container ): void {
		self::$container = $container;
	}

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

		$container = self::container();

		if ( $container->has_provider() ) {
			$container->acf()->register();
			$container->elementor()->register();
			$container->post_hooks()->register();
		}

		if ( $container->has_provider() ) {
			$container->admin_bar()->register();
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( Activator::class, 'ensure_schedules' ) );
			( new AdminPage() )->register();
			( new SettingsPage( $container ) )->register();
			( new DependencyNotice( $container->detector() ) )->register();

			if ( $container->has_provider() ) {
				$container->list_table()->register();
				$container->metabox()->register();
				$container->actions()->register();
			}
		}
	}
}
