<?php
/**
 * Admin stylesheet.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the stylesheet of the PHP-rendered admin UI on the screens that use it.
 *
 * @since 0.1.0
 */
final class Assets {

	/**
	 * Style handle.
	 *
	 * @since 0.1.0
	 */
	public const ADMIN_STYLE = 'tdrift-admin';

	/**
	 * Enqueues the badge and metabox styles. Callers hook this only on their own screens.
	 *
	 * @since 0.1.0
	 */
	public static function enqueue_admin_style(): void {
		$asset_file = TDRIFT_DIR . 'build/admin/index.asset.php';
		if ( ! is_readable( TDRIFT_DIR . 'build/admin/index.css' ) ) {
			return;
		}

		$asset   = is_readable( $asset_file ) ? require $asset_file : array();
		$version = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : TDRIFT_VERSION;

		wp_enqueue_style( self::ADMIN_STYLE, TDRIFT_URL . 'build/admin/index.css', array(), $version );
	}

	/**
	 * Enqueues a built script (and its stylesheet, when there is one) with its generated dependencies.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $handle     Script and style handle.
	 * @param string       $entry      Build entry, e.g. 'dashboard/index'.
	 * @param list<string> $style_deps Style dependencies.
	 * @return bool Whether the script was enqueued.
	 */
	public static function enqueue_bundle( string $handle, string $entry, array $style_deps = array() ): bool {
		$asset_file = TDRIFT_DIR . 'build/' . $entry . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}

		$asset   = require $asset_file;
		$deps    = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? array_values( array_filter( $asset['dependencies'], 'is_string' ) ) : array();
		$version = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : TDRIFT_VERSION;

		wp_enqueue_script( $handle, TDRIFT_URL . 'build/' . $entry . '.js', $deps, $version, array( 'in_footer' => true ) );
		wp_set_script_translations( $handle, 'translation-drift' );

		self::enqueue_admin_style();
		$style_deps[] = self::ADMIN_STYLE;
		if ( is_readable( TDRIFT_DIR . 'build/' . $entry . '.css' ) ) {
			wp_enqueue_style( $handle, TDRIFT_URL . 'build/' . $entry . '.css', $style_deps, $version );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}

		return true;
	}
}
