<?php
/**
 * Development only: creates EN (default), FR and ES in the active multilingual plugin.
 *
 * Run with `wp eval-file bin/dev/languages.php <provider>`. This file uses
 * Polylang internals, which is acceptable for dev tooling but never in plugin code.
 *
 * @package Stalelingo
 */

defined( 'ABSPATH' ) || exit;

$stalelingo_provider = $args[0] ?? 'polylang'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

if ( 'wpml' === $stalelingo_provider ) {
	global $wpdb, $sitepress;
	if ( ! class_exists( 'WPML_Installation' ) || ! $sitepress ) {
		WP_CLI::error( 'WPML is not loaded.' );
	}
	// Runs WPML's own setup wizard steps: default language, active languages, finish.
	if ( ! icl_get_setting( 'setup_complete' ) ) {
		$stalelingo_install = new WPML_Installation( $wpdb, $sitepress );
		$stalelingo_install->finish_step1( 'en' );
		$stalelingo_install->finish_step2( array( 'en', 'fr', 'es' ) );
		$stalelingo_install->finish_step3();
		$stalelingo_install->finish_installation();
	}
	// A scripted install skips Translation Management's own setup; give it its settings array.
	if ( ! is_array( icl_get_setting( 'translation-management' ) ) ) {
		icl_set_setting( 'translation-management', array(), true );
	}
	// Translate the dev CPT (1 = translatable).
	$stalelingo_sync                    = (array) icl_get_setting( 'custom_posts_sync_option', array() );
	$stalelingo_sync['stalelingo_book'] = 1;
	icl_set_setting( 'custom_posts_sync_option', $stalelingo_sync, true );
	WP_CLI::success( 'WPML languages: ' . implode( ', ', array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	return;
}

if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
	WP_CLI::error( 'Polylang is not loaded.' );
}

$stalelingo_wanted   = array(
	'en_US' => 0,
	'fr_FR' => 1,
	'es_ES' => 2,
);
$stalelingo_existing = pll_languages_list( array( 'fields' => 'locale' ) );

foreach ( $stalelingo_wanted as $stalelingo_locale => $stalelingo_order ) {
	if ( in_array( $stalelingo_locale, $stalelingo_existing, true ) ) {
		continue;
	}
	$stalelingo_result = PLL()->model->languages->add(
		array(
			'locale'     => $stalelingo_locale,
			'term_group' => $stalelingo_order,
		)
	);
	if ( is_wp_error( $stalelingo_result ) ) {
		WP_CLI::error( $stalelingo_result->get_error_message() );
	}
	WP_CLI::log( "Added language {$stalelingo_locale}." );
}

PLL()->model->clean_languages_cache();

// Default language EN and translate the dev CPT. Polylang 3.8 keeps its options in an object that is
// saved at the end of the request, so write through it rather than with update_option().
$stalelingo_options                 = PLL()->options;
$stalelingo_options['default_lang'] = 'en';
$stalelingo_options['post_types']   = array_values( array_unique( array_merge( (array) $stalelingo_options['post_types'], array( 'stalelingo_book' ) ) ) );
if ( method_exists( $stalelingo_options, 'save' ) ) {
	$stalelingo_options->save();
}

// Skip the setup wizard.
delete_transient( 'pll_activation_redirect' );
update_option( 'pll_wizard_done', 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

// Assign content created before Polylang to the default language.
PLL()->model->set_language_in_mass( 'en' );

WP_CLI::success( 'Languages: ' . implode( ', ', pll_languages_list( array( 'fields' => 'slug' ) ) ) );
