<?php
/**
 * Development only: seeds translated content.
 *
 * Run with `wp eval-file bin/dev/seed.php <provider> <count>`. For every
 * post type (post, page, tdrift_book) it creates <count> English sources with
 * French translations for all of them and Spanish translations for half, so
 * the site has both translated and missing languages. FR/ES translations are
 * authored by translator-fr / translator-es.
 *
 * Seed posts are tagged with the `_tdrift_seed` meta key so re-runs are idempotent.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

$provider = $args[0] ?? 'polylang';
$count    = max( 1, (int) ( $args[1] ?? 6 ) );

if ( 'polylang' !== $provider ) {
	WP_CLI::warning( 'Seeding is implemented for Polylang only until the WPML adapter lands.' );
	return;
}

$translators = array();
foreach ( array( 'fr', 'es' ) as $lang ) {
	$user                 = get_user_by( 'login', "translator-{$lang}" );
	$translators[ $lang ] = $user ? $user->ID : 1;
}

$texts = array(
	'en' => array( 'Hello from the source', 'This is the English source paragraph.' ),
	'fr' => array( 'Bonjour de la source', 'Ceci est le paragraphe source en français.' ),
	'es' => array( 'Hola desde la fuente', 'Este es el párrafo fuente en español.' ),
);

$created = 0;
foreach ( array( 'post', 'page', 'tdrift_book' ) as $post_type ) {
	$existing = get_posts(
		array(
			'post_type'   => $post_type,
			'meta_key'    => '_tdrift_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			'lang'        => 'en',
			'fields'      => 'ids',
			'numberposts' => -1,
			'post_status' => 'any',
		)
	);

	for ( $i = count( $existing ) + 1; $i <= $count; $i++ ) {
		$group = array();
		foreach ( array( 'en', 'fr', 'es' ) as $lang ) {
			if ( 'es' === $lang && 0 === $i % 2 ) {
				continue; // Leave every other Spanish translation missing.
			}
			$post_id = wp_insert_post(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'publish',
					'post_title'   => sprintf( '%s #%d (%s)', $texts[ $lang ][0], $i, $post_type ),
					'post_content' => "<!-- wp:paragraph -->\n<p>{$texts[ $lang ][1]}</p>\n<!-- /wp:paragraph -->",
					'post_excerpt' => $texts[ $lang ][1],
					'post_author'  => 'en' === $lang ? 1 : $translators[ $lang ],
					'meta_input'   => array( '_tdrift_seed' => 1 ),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::error( $post_id->get_error_message() );
			}
			pll_set_post_language( $post_id, $lang );
			$group[ $lang ] = $post_id;
		}
		pll_save_post_translations( $group );
		++$created;
	}
}

WP_CLI::success( "Seeded {$created} translation groups." );
