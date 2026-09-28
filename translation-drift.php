<?php
/**
 * Plugin Name:       Translation Drift – Outdated Translation Tracker for Polylang & WPML
 * Description:       Flags translations that went out of date when their source post changed, shows what changed, and helps editors clear the backlog.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Tilt
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       translation-drift
 * Domain Path:       /languages
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'TDRIFT_VERSION', '0.1.0' );
define( 'TDRIFT_FILE', __FILE__ );
define( 'TDRIFT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TDRIFT_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( TDRIFT_DIR . 'vendor/autoload.php' ) ) {
	// Only reachable from a source checkout without `composer install`; release zips ship the autoloader.
	return;
}

require_once TDRIFT_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \TranslationDrift\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \TranslationDrift\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \TranslationDrift\Plugin::class, 'boot' ) );
