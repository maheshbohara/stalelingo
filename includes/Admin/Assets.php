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
}
