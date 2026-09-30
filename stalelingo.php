<?php
/**
 * Plugin Name:       Stalelingo – Outdated Translation Tracker for Multilingual Sites
 * Description:       Flags translations that went out of date when their source post changed, shows what changed, and helps editors clear the backlog.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Mahesh Bohara
 * Author URI:        http://www.maheshbohara.com.np/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stalelingo
 * Domain Path:       /languages
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'STALELINGO_VERSION', '1.0.0' );
define( 'STALELINGO_FILE', __FILE__ );
define( 'STALELINGO_DIR', plugin_dir_path( __FILE__ ) );
define( 'STALELINGO_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( STALELINGO_DIR . 'vendor/autoload.php' ) ) {
	// Only reachable from a source checkout without `composer install`; release zips ship the autoloader.
	return;
}

require_once STALELINGO_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \Stalelingo\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Stalelingo\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Stalelingo\Plugin::class, 'boot' ) );
