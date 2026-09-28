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
			if ( ! is_readable( $wpml ) ) {
				fwrite( STDERR, "PROVIDER=wpml but WPML is not installed in the test environment (put its zips in ./private/wpml/).\n" );
				exit( 1 );
			}
			// WPML queries users while loading and setting up, before plugins_loaded; silence those
			// "called too early" notices until its setup below has run.
			add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
			require_once $wpml;
			// The suite loads WPML without activating it, so create its tables here (fresh test database each run).
			if ( function_exists( 'icl_sitepress_activate' ) ) {
				icl_sitepress_activate();
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

// WPML: run its setup wizard steps (EN default, FR, ES). $sitepress exists as soon as WPML is included.
tests_add_filter(
	'plugins_loaded',
	static function () use ( $tdrift_provider ): void {
		global $wpdb, $sitepress;
		if ( 'wpml' !== $tdrift_provider || ! class_exists( 'WPML_Installation' ) || ! $sitepress ) {
			return;
		}
		if ( ! icl_get_setting( 'setup_complete' ) ) {
			$install = new \WPML_Installation( $wpdb, $sitepress );
			$install->finish_step1( 'en' );
			$install->finish_step2( array( 'en', 'fr', 'es' ) );
			$install->finish_step3();
			$install->finish_installation();
		}
		if ( ! is_array( icl_get_setting( 'translation-management' ) ) ) {
			icl_set_setting( 'translation-management', array(), true );
		}
		// Added before WPML was loaded (see muplugins_loaded above).
		remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );

		// WPML hooks save_post only if setup was complete when it loaded (always true on a real site;
		// here setup completed just now), and on the front end it handles deletions only for REST
		// requests. Register both handlers as wp-admin does, where editors save and delete posts.
		global $wpml_post_translations;
		if ( is_object( $wpml_post_translations ) ) {
			if ( false === has_action( 'save_post', array( $wpml_post_translations, 'save_post_actions' ) ) ) {
				add_action( 'save_post', array( $wpml_post_translations, 'save_post_actions' ), 100, 2 );
			}
			if ( false === has_action( 'delete_post', array( $wpml_post_translations, 'delete_post_actions' ) ) ) {
				add_action( 'delete_post', array( $wpml_post_translations, 'delete_post_actions' ) );
			}
		}
	},
	5 // Before Translation Drift boots (10), which checks that the provider is ready.
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
