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
		$acf = $tdrift_core_dir . '/wp-content/plugins/advanced-custom-fields/acf.php';
		if ( is_readable( $acf ) ) {
			require_once $acf;
		}
		require_once $tdrift_root . '/translation-drift.php';
	}
);

// Polylang loads its API only when languages exist, so create EN (default), FR and ES before it boots (priority 1).
tests_add_filter(
	'plugins_loaded',
	static function () use ( $tdrift_provider ): void {
		if ( 'polylang' !== $tdrift_provider || ! class_exists( 'PLL_Admin_Model' ) ) {
			return;
		}
		// Reading Polylang's model this early is deliberate; silence its "called too early" notice.
		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		add_action( 'pll_init_options_for_blog', array( \WP_Syntex\Polylang\Options\Registry::class, 'register' ) );
		$options = new \WP_Syntex\Polylang\Options\Options();
		$model   = new \PLL_Admin_Model( $options );
		$locales = array_map( static fn( $lang ) => $lang->locale, $model->get_languages_list() );
		foreach ( array( 'en_US', 'fr_FR', 'es_ES' ) as $order => $locale ) {
			if ( ! in_array( $locale, $locales, true ) ) {
				$model->languages->add(
					array(
						'locale'     => $locale,
						'term_group' => $order,
					)
				);
			}
		}
		$options['default_lang'] = 'en';
		$options->save();
		$model->clean_languages_cache();
		remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );
	},
	0
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
