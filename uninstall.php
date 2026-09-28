<?php
/**
 * Uninstall routine.
 *
 * Deletes the plugin's tables, options and capability on every site where the
 * "Delete data on uninstall" setting is on.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\TranslationDrift\Uninstaller::uninstall();
