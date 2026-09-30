<?php
/**
 * Development only: a custom post type used by the seed data and tests.
 *
 * This file lives in docker/ and is never shipped in the plugin zip.
 *
 * @package Stalelingo
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		register_post_type(
			'stalelingo_book',
			array(
				'label'        => 'Books',
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions', 'author' ),
			)
		);
	}
);

// ACF field group for the dev CPT: one text field (tracked) and one number field (layout, not tracked).
add_action(
	'acf/init',
	static function (): void {
		acf_add_local_field_group(
			array(
				'key'      => 'group_stalelingo_dev_book',
				'title'    => 'Book details',
				'fields'   => array(
					array(
						'key'   => 'field_stalelingo_dev_subtitle',
						'name'  => 'book_subtitle',
						'label' => 'Subtitle',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_stalelingo_dev_pages',
						'name'  => 'book_pages',
						'label' => 'Pages',
						'type'  => 'number',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'stalelingo_book',
						),
					),
				),
			)
		);
	}
);
