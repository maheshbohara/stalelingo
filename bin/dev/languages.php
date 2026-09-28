<?php
/**
 * Development only: creates EN (default), FR and ES in the active multilingual plugin.
 *
 * Run with `wp eval-file bin/dev/languages.php <provider>`. This file uses
 * Polylang internals, which is acceptable for dev tooling but never in plugin code.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

$tdrift_provider = $args[0] ?? 'polylang'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

if ( 'wpml' === $tdrift_provider ) {
	global $wpdb, $sitepress;
	if ( ! class_exists( 'WPML_Installation' ) || ! $sitepress ) {
		WP_CLI::error( 'WPML is not loaded.' );
	}
	// Runs WPML's own setup wizard steps: default language, active languages, finish.
	if ( ! icl_get_setting( 'setup_complete' ) ) {
		$tdrift_install = new WPML_Installation( $wpdb, $sitepress );
		$tdrift_install->finish_step1( 'en' );
		$tdrift_install->finish_step2( array( 'en', 'fr', 'es' ) );
		$tdrift_install->finish_step3();
		$tdrift_install->finish_installation();
	}
	// A scripted install skips Translation Management's own setup; give it its settings array.
	if ( ! is_array( icl_get_setting( 'translation-management' ) ) ) {
		icl_set_setting( 'translation-management', array(), true );
	}
	// Translate the dev CPT (1 = translatable).
	$tdrift_sync                = (array) icl_get_setting( 'custom_posts_sync_option', array() );
	$tdrift_sync['tdrift_book'] = 1;
	icl_set_setting( 'custom_posts_sync_option', $tdrift_sync, true );
	WP_CLI::success( 'WPML languages: ' . implode( ', ', array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	return;
}

if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
	WP_CLI::error( 'Polylang is not loaded.' );
}

$tdrift_wanted   = array(
	'en_US' => 0,
	'fr_FR' => 1,
	'es_ES' => 2,
);
$tdrift_existing = pll_languages_list( array( 'fields' => 'locale' ) );

foreach ( $tdrift_wanted as $tdrift_locale => $tdrift_order ) {
	if ( in_array( $tdrift_locale, $tdrift_existing, true ) ) {
		continue;
	}
	$tdrift_result = PLL()->model->languages->add(
		array(
			'locale'     => $tdrift_locale,
			'term_group' => $tdrift_order,
		)
	);
	if ( is_wp_error( $tdrift_result ) ) {
		WP_CLI::error( $tdrift_result->get_error_message() );
	}
	WP_CLI::log( "Added language {$tdrift_locale}." );
}

PLL()->model->clean_languages_cache();

// Default language EN, translate the dev CPT, and skip the setup wizard.
$tdrift_options                 = get_option( 'polylang', array() );
$tdrift_options['default_lang'] = 'en';
$tdrift_options['post_types']   = array_values( array_unique( array_merge( (array) ( $tdrift_options['post_types'] ?? array() ), array( 'tdrift_book' ) ) ) );
update_option( 'polylang', $tdrift_options );
delete_transient( 'pll_activation_redirect' );
update_option( 'pll_wizard_done', 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

// Assign content created before Polylang to the default language.
PLL()->model->set_language_in_mass( 'en' );

WP_CLI::success( 'Languages: ' . implode( ', ', pll_languages_list( array( 'fields' => 'slug' ) ) ) );
