<?php
/**
 * Source fingerprinting.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Domain\Hasher;
use TranslationDrift\Domain\Normalizer;
use TranslationDrift\Settings;

/**
 * Reads a post's tracked fields, normalizes them and hashes them.
 *
 * @since 0.1.0
 */
class Fingerprinter {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TrackedFields $fields     Tracked fields.
	 * @param Normalizer    $normalizer Normalizer.
	 * @param Hasher        $hasher     Hasher.
	 * @param Settings      $settings   Settings.
	 */
	public function __construct(
		private TrackedFields $fields,
		private Normalizer $normalizer,
		private Hasher $hasher,
		private Settings $settings
	) {
	}

	/**
	 * Fingerprints a post.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Post.
	 */
	public function fingerprint( \WP_Post $post ): Fingerprint {
		$strict = $this->settings->strict();
		$values = array();

		foreach ( $this->fields->fields( $post->post_type ) as $field ) {
			$raw        = $this->raw_value( $post, $field );
			$normalized = null === $raw ? null : $this->normalize( $field, $raw, $strict );

			/**
			 * Filters the normalized value of a tracked field before it is hashed.
			 *
			 * Return null to treat the field as having no value.
			 *
			 * @since 0.1.0
			 *
			 * @param string|null $normalized Normalized value.
			 * @param string      $field      Field key.
			 * @param mixed       $raw        Raw value.
			 * @param int         $post_id    Post ID.
			 * @param bool        $strict     Whether strict mode is on.
			 */
			$normalized = apply_filters( 'tdrift_normalize_value', $normalized, $field, $raw, $post->ID, $strict );

			$values[ $field ] = is_scalar( $normalized ) ? (string) $normalized : null;
		}//end foreach

		ksort( $values, SORT_STRING );

		return new Fingerprint( $values, $this->hasher->hash_all( $values ) );
	}

	/**
	 * Raw value of a field, or null when it has none.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $field Field key.
	 */
	private function raw_value( \WP_Post $post, string $field ): mixed {
		switch ( $field ) {
			case 'title':
				return $post->post_title;
			case 'content':
				return $post->post_content;
			case 'excerpt':
				return $post->post_excerpt;
			case 'slug':
				return $post->post_name;
			case 'featured_image':
				$thumbnail = (int) get_post_thumbnail_id( $post );
				return $thumbnail > 0 ? (string) $thumbnail : null;
		}

		if ( str_starts_with( $field, 'meta:' ) ) {
			$values = get_post_meta( $post->ID, substr( $field, 5 ), false );
			if ( ! is_array( $values ) || array() === $values ) {
				return null;
			}

			return 1 === count( $values ) ? reset( $values ) : $values;
		}

		/**
		 * Filters the raw value of a custom tracked field (one added with `tdrift_tracked_fields`).
		 *
		 * @since 0.1.0
		 *
		 * @param mixed    $value Raw value. Default null (no value).
		 * @param string   $field Field key.
		 * @param \WP_Post $post  Post.
		 */
		return apply_filters( 'tdrift_field_value', null, $field, $post );
	}

	/**
	 * Normalizes a raw value for its field.
	 *
	 * @param string $field  Field key.
	 * @param mixed  $raw    Raw value.
	 * @param bool   $strict Strict mode.
	 */
	private function normalize( string $field, mixed $raw, bool $strict ): string {
		/**
		 * Short-circuits normalization of a tracked field.
		 *
		 * Integrations return the normalized text of their own fields (for example
		 * 'acf:*' and 'elementor'); null falls through to the default normalization.
		 *
		 * @since 0.1.0
		 *
		 * @param string|null $normalized Null to use the default.
		 * @param string      $field      Field key.
		 * @param mixed       $raw        Raw value.
		 * @param bool        $strict     Whether strict mode is on.
		 */
		$pre = apply_filters( 'tdrift_pre_normalize_value', null, $field, $raw, $strict );
		if ( is_string( $pre ) ) {
			return $pre;
		}

		if ( 'content' === $field ) {
			return $this->normalizer->normalize_blocks( parse_blocks( (string) $raw ), $strict );
		}
		if ( 'slug' === $field || 'featured_image' === $field ) {
			return (string) $raw;
		}

		return $this->normalizer->normalize_value( $raw, $strict );
	}
}
