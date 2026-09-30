<?php
/**
 * Source fingerprinting.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Services;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Domain\Hasher;
use Stalelingo\Domain\Normalizer;
use Stalelingo\Settings;

/**
 * Reads a post's tracked fields, normalizes them and hashes them.
 *
 * @since 1.0.0
 */
class Fingerprinter {

	/**
	 * Key prefix of strict-mode hashes in a sync point.
	 *
	 * @since 1.0.0
	 */
	public const STRICT_PREFIX = 'strict:';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @param \WP_Post  $post   Post.
	 * @param bool|null $strict Normalization mode; null uses the strict-mode setting.
	 */
	public function fingerprint( \WP_Post $post, ?bool $strict = null ): Fingerprint {
		$values = $this->values( $post, $this->fields->fields( $post->post_type ), $strict );
		ksort( $values, SORT_STRING );

		return new Fingerprint( $values, $this->hasher->hash_all( $values ) );
	}

	/**
	 * Normalized values of the given fields of a post, for hashing or diffing.
	 *
	 * The post may be a revision: the fields are read from it as given, whatever its post type.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post     $post   Post or revision.
	 * @param list<string> $fields Field keys.
	 * @param bool|null    $strict Normalization mode; null uses the strict-mode setting.
	 * @return array<string, string|null> Normalized values; null when a field has no value.
	 */
	public function values( \WP_Post $post, array $fields, ?bool $strict = null ): array {
		$strict = $strict ?? $this->settings->strict();
		$values = array();

		foreach ( $fields as $field ) {
			$raw        = $this->raw_value( $post, $field );
			$normalized = null === $raw ? null : $this->normalize( $field, $raw, $strict );

			/**
			 * Filters the normalized value of a tracked field before it is hashed.
			 *
			 * Return null to treat the field as having no value.
			 *
			 * @since 1.0.0
			 *
			 * @param string|null $normalized Normalized value.
			 * @param string      $field      Field key.
			 * @param mixed       $raw        Raw value.
			 * @param int         $post_id    Post ID.
			 * @param bool        $strict     Whether strict mode is on.
			 */
			$normalized = apply_filters( 'stalelingo_normalize_value', $normalized, $field, $raw, $post->ID, $strict );

			$values[ $field ] = is_scalar( $normalized ) ? (string) $normalized : null;
		}//end foreach

		return $values;
	}

	/**
	 * Field hashes in both normalization modes, for a sync point.
	 *
	 * Normal-mode hashes are keyed by field; strict-mode hashes by `strict:<field>`.
	 * Storing both lets strict mode be switched on or off without every
	 * translation suddenly comparing against hashes made in the other mode.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post.
	 * @return array{fingerprint: Fingerprint, hashes: array<string, string>} Normal-mode fingerprint and both modes' hashes.
	 */
	public function sync_point( \WP_Post $post ): array {
		$normal = $this->fingerprint( $post, false );
		$hashes = $normal->hashes;
		foreach ( $this->fingerprint( $post, true )->hashes as $field => $hash ) {
			$hashes[ self::STRICT_PREFIX . $field ] = $hash;
		}

		return array(
			'fingerprint' => $normal,
			'hashes'      => $hashes,
		);
	}

	/**
	 * The stored hashes that belong to the current normalization mode, keyed by field.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $stored Hashes stored at a sync point.
	 * @param bool|null             $strict Mode; null uses the strict-mode setting.
	 * @return array<string, string>
	 */
	public function hashes_for_mode( array $stored, ?bool $strict = null ): array {
		$strict = $strict ?? $this->settings->strict();
		$hashes = array();
		foreach ( $stored as $key => $hash ) {
			$is_strict = str_starts_with( (string) $key, self::STRICT_PREFIX );
			if ( $is_strict === $strict ) {
				$hashes[ $is_strict ? substr( (string) $key, strlen( self::STRICT_PREFIX ) ) : (string) $key ] = $hash;
			}
		}

		return $hashes;
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
		 * Filters the raw value of a custom tracked field (one added with `stalelingo_tracked_fields`).
		 *
		 * @since 1.0.0
		 *
		 * @param mixed    $value Raw value. Default null (no value).
		 * @param string   $field Field key.
		 * @param \WP_Post $post  Post.
		 */
		return apply_filters( 'stalelingo_field_value', null, $field, $post );
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
		 * @since 1.0.0
		 *
		 * @param string|null $normalized Null to use the default.
		 * @param string      $field      Field key.
		 * @param mixed       $raw        Raw value.
		 * @param bool        $strict     Whether strict mode is on.
		 */
		$pre = apply_filters( 'stalelingo_pre_normalize_value', null, $field, $raw, $strict );
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
