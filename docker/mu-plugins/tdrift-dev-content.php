<?php
/**
 * Development only: a custom post type used by the seed data and tests.
 *
 * This file lives in docker/ and is never shipped in the plugin zip.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		register_post_type(
			'tdrift_book',
			array(
				'label'        => 'Books',
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions', 'author' ),
			)
		);
	}
);
