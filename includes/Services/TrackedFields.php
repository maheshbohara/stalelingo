<?php
/**
 * Tracked post types and fields.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Services;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Providers\TranslationProvider;
use Stalelingo\Settings;

/**
 * Decides which post types and fields are tracked.
 *
 * Field keys: 'title', 'content', 'excerpt', 'slug', 'featured_image', and
 * 'meta:<key>' for post meta.
 *
 * @since 1.0.0
 */
class TrackedFields {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings            $settings Settings.
	 * @param TranslationProvider $provider Multilingual provider.
	 */
	public function __construct( private Settings $settings, private TranslationProvider $provider ) {
	}

	/**
	 * Tracked post types: translated by the provider and chosen in settings.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function post_types(): array {
		$chosen = array_filter( (array) $this->settings->get( 'post_types' ), 'is_string' );
		if ( array() === $chosen ) {
			$chosen = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		}

		$types = array_values( array_filter( $chosen, array( $this->provider, 'is_translated_post_type' ) ) );

		/**
		 * Filters the post types whose translations are tracked.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $types Post types.
		 */
		$types = apply_filters( 'stalelingo_tracked_post_types', $types );

		return array_values( array_unique( array_filter( (array) $types, 'is_string' ) ) );
	}

	/**
	 * Whether a post type is tracked.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type.
	 */
	public function is_tracked_post_type( string $post_type ): bool {
		return in_array( $post_type, $this->post_types(), true );
	}

	/**
	 * Tracked meta keys for a post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type.
	 * @return list<string>
	 */
	public function meta_keys( string $post_type ): array {
		$keys = array_filter( array_map( 'trim', array_filter( (array) $this->settings->get( 'meta_keys' ), 'is_string' ) ) );

		/**
		 * Filters the meta keys tracked for a post type.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $keys      Meta keys.
		 * @param string       $post_type Post type.
		 */
		$keys = apply_filters( 'stalelingo_tracked_meta_keys', array_values( $keys ), $post_type );

		return array_values( array_unique( array_filter( (array) $keys, static fn( $key ): bool => is_string( $key ) && '' !== $key ) ) );
	}

	/**
	 * Tracked field keys for a post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type.
	 * @return list<string>
	 */
	public function fields( string $post_type ): array {
		$fields = array_values( array_intersect( Settings::POST_FIELDS, (array) $this->settings->get( 'fields' ) ) );
		foreach ( $this->meta_keys( $post_type ) as $key ) {
			$fields[] = 'meta:' . $key;
		}

		/**
		 * Filters the tracked field keys for a post type.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $fields    Field keys, e.g. 'title', 'content', 'meta:subtitle'.
		 * @param string       $post_type Post type.
		 */
		$fields = apply_filters( 'stalelingo_tracked_fields', $fields, $post_type );

		return array_values( array_unique( array_filter( (array) $fields, 'is_string' ) ) );
	}
}
