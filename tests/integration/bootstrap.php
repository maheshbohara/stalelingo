<?php
/**
 * Integration test bootstrap: loads the WordPress test suite with Polylang (or WPML) and the plugin.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

$tdrift_root = dirname( __DIR__, 2 );

require_once $tdrift_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$tdrift_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/opt/wp-tests/lib';
$tdrift_core_dir  = getenv( 'WP_CORE_DIR' ) ? getenv( 'WP_CORE_DIR' ) : '/opt/wp-tests/core';
$tdrift_provider  = getenv( 'PROVIDER' ) ? getenv( 'PROVIDER' ) : 'polylang';

if ( ! is_readable( $tdrift_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in {$tdrift_tests_dir}. Run `make test-integration`.\n" );
	exit( 1 );
}

require_once $tdrift_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $tdrift_root, $tdrift_core_dir, $tdrift_provider ): void {
		if ( 'wpml' === $tdrift_provider ) {
			$wpml = $tdrift_core_dir . '/wp-content/plugins/sitepress-multilingual-cms/sitepress.php';
			if ( is_readable( $wpml ) ) {
				require_once $wpml;
			}
		} else {
			require_once $tdrift_core_dir . '/wp-content/plugins/polylang/polylang.php';
		}
		require_once $tdrift_root . '/translation-drift.php';
	}
);

// Tables are created once, outside the per-test transactions (which turn CREATE TABLE into temporary tables).
tests_add_filter(
	'init',
	static function (): void {
		\TranslationDrift\Activator::activate( is_multisite() );
	},
	0
);

require $tdrift_tests_dir . '/includes/bootstrap.php';
