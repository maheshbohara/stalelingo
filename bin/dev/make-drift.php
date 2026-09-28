<?php
/**
 * Development only: edits some seeded English sources so their translations become outdated.
 *
 * Run with `wp eval-file bin/dev/make-drift.php` after the baseline is built.
 * Every third seeded source gets a new title; every fifth gets new content.
 * Idempotent: posts already edited (marked with `_tdrift_seed_drifted`) are skipped.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

$sources = get_posts(
	array(
		'post_type'   => array( 'post', 'page', 'tdrift_book' ),
		'meta_key'    => '_tdrift_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
		'lang'        => 'en',
		'numberposts' => -1,
		'orderby'     => 'ID',
		'order'       => 'ASC',
	)
);

$edited = 0;
foreach ( $sources as $i => $post ) {
	if ( get_post_meta( $post->ID, '_tdrift_seed_drifted', true ) ) {
		continue;
	}
	$changes = array();
	if ( 0 === $i % 3 ) {
		$changes['post_title'] = $post->post_title . ' (updated)';
	}
	if ( 0 === $i % 5 ) {
		$changes['post_content'] = $post->post_content . "\n\n<!-- wp:paragraph -->\n<p>A new paragraph added to the source.</p>\n<!-- /wp:paragraph -->";
	}
	if ( array() === $changes ) {
		continue;
	}
	wp_update_post( array_merge( array( 'ID' => $post->ID ), $changes ) );
	update_post_meta( $post->ID, '_tdrift_seed_drifted', 1 );
	++$edited;
}

WP_CLI::success( "Edited {$edited} English sources." );
