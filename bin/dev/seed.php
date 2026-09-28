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

/**
 * Sets a post's language and links it to its translation group.
 *
 * @param array<string, int> $group Post IDs keyed by language, source ('en') first.
 */
$link_group = static function ( array $group ) use ( $provider ): void {
	if ( 'wpml' === $provider ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API.
		$type = apply_filters( 'wpml_element_type', get_post_type( $group['en'] ) );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'    => $group['en'],
				'element_type'  => $type,
				'trid'          => false,
				'language_code' => 'en',
			)
		);
		$trid = apply_filters( 'wpml_element_trid', null, $group['en'], $type );
		foreach ( $group as $lang => $id ) {
			if ( 'en' !== $lang ) {
				do_action(
					'wpml_set_element_language_details',
					array(
						'element_id'           => $id,
						'element_type'         => $type,
						'trid'                 => $trid,
						'language_code'        => $lang,
						'source_language_code' => 'en',
					)
				);
			}
		}
		// phpcs:enable
		return;
	}
	foreach ( $group as $lang => $id ) {
		pll_set_post_language( $id, $lang );
	}
	pll_save_post_translations( $group );
};

// Seed posts in every language, whatever the admin's current language filter.
$all_languages = 'wpml' === $provider ? array( 'suppress_filters' => true ) : array( 'lang' => '' );
$en_only       = 'wpml' === $provider ? array( 'suppress_filters' => false ) : array( 'lang' => 'en' );

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
			'fields'      => 'ids',
			'numberposts' => -1,
			'post_status' => 'any',
		) + $en_only
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
			$group[ $lang ] = $post_id;
		}
		$link_group( $group );
		++$created;
	}
}

// ACF values on books.
if ( function_exists( 'update_field' ) ) {
	foreach ( get_posts(
		array(
			'post_type'   => 'tdrift_book',
			'numberposts' => -1,
			'lang'        => '',
			'meta_key'    => '_tdrift_seed',
		)
	) as $book ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
		if ( '' === (string) get_field( 'book_subtitle', $book->ID, false ) ) {
			update_field( 'book_subtitle', 'Subtitle of ' . $book->post_title, $book->ID );
			update_field( 'book_pages', 100 + $book->ID, $book->ID );
		}
	}
}

// One Elementor page group (EN/FR), for tracking Elementor text.
$elementor_data = static function ( string $title, string $text, string $button ): string {
	return (string) wp_json_encode(
		array(
			array(
				'id'       => 'tdsec01',
				'elType'   => 'container',
				'settings' => array( 'background_color' => '#F5F5F5' ),
				'elements' => array(
					array(
						'id'         => 'tdw0001',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(
							'title'       => $title,
							'title_color' => '#111111',
						),
						'elements'   => array(),
					),
					array(
						'id'         => 'tdw0002',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => "<p>{$text}</p>" ),
						'elements'   => array(),
					),
					array(
						'id'         => 'tdw0003',
						'elType'     => 'widget',
						'widgetType' => 'button',
						'settings'   => array(
							'text'             => $button,
							'background_color' => '#0073AA',
						),
						'elements'   => array(),
					),
				),
			),
		)
	);
};
if ( ! get_posts(
	array(
		'post_type'   => 'page',
		'meta_key'    => '_tdrift_seed_elementor',
		'lang'        => '',
		'fields'      => 'ids',
		'post_status' => 'any',
	)
) ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
	$pages = array();
	foreach ( array(
		'en' => array( 'Elementor landing page', 'Built with Elementor.', 'Get started' ),
		'fr' => array( 'Page Elementor', 'Construite avec Elementor.', 'Commencer' ),
	) as $lang => $copy ) {
		$id             = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $copy[0],
				'post_content' => "<p>{$copy[1]}</p>",
				'post_author'  => 'en' === $lang ? 1 : $translators['fr'],
				'meta_input'   => array(
					'_tdrift_seed'             => 1,
					'_tdrift_seed_elementor'   => 1,
					'_elementor_edit_mode'     => 'builder',
					'_elementor_template_type' => 'wp-page',
					'_elementor_data'          => wp_slash( $elementor_data( $copy[0], $copy[1], $copy[2] ) ),
				),
			)
		);
		$pages[ $lang ] = $id;
	}
	$link_group( $pages );
	++$created;
}

WP_CLI::success( "Seeded {$created} translation groups." );
