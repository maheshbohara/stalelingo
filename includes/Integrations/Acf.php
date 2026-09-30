<?php
/**
 * Advanced Custom Fields integration.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Integrations;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Domain\AcfTextExtractor;
use Stalelingo\Domain\Normalizer;
use Stalelingo\Providers\ProviderDetector;
use Stalelingo\Providers\TranslationProvider;
use Stalelingo\Settings;

/**
 * Tracks ACF fields and ACF block text.
 *
 * Post fields: every field in the post type's field groups whose translation
 * preference is "translate" or "copy once" is tracked as `acf:<name>`. The
 * preference comes from WPML's custom field settings or Polylang Pro's ACF
 * setting. When neither sets one (for example with Polylang free), fields that
 * hold text are tracked.
 *
 * ACF blocks: the text fields of each block's data join that block's line in
 * the normalized content, through the `stalelingo_block_text` filter.
 *
 * @since 1.0.0
 */
class Acf {

	/**
	 * Field key prefix.
	 *
	 * @since 1.0.0
	 */
	public const PREFIX = 'acf:';

	/**
	 * Tracked field definitions per post type, for this request.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $fields_by_type = array();

	/**
	 * Text extractor.
	 *
	 * @var AcfTextExtractor
	 */
	private AcfTextExtractor $extractor;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings            $settings   Settings.
	 * @param TranslationProvider $provider   Provider.
	 * @param Normalizer          $normalizer Normalizer.
	 */
	public function __construct( private Settings $settings, private TranslationProvider $provider, private Normalizer $normalizer ) {
		$this->extractor = new AcfTextExtractor(
			static function ( string $key ): ?array {
				$field = function_exists( 'acf_get_field' ) ? acf_get_field( $key ) : false;

				return is_array( $field ) ? $field : null;
			},
			$normalizer
		);
	}

	/**
	 * Whether ACF is active and tracking is on.
	 *
	 * @since 1.0.0
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'acf' ) && function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'stalelingo_tracked_fields', array( $this, 'add_tracked_fields' ), 10, 2 );
		add_filter( 'stalelingo_field_value', array( $this, 'field_value' ), 10, 3 );
		add_filter( 'stalelingo_pre_normalize_value', array( $this, 'normalize' ), 10, 4 );
		add_filter( 'stalelingo_block_text', array( $this, 'block_text' ), 10, 2 );
	}

	/**
	 * Adds the post type's tracked ACF fields.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $fields    Field keys.
	 * @param string $post_type Post type.
	 * @return list<string>
	 */
	public function add_tracked_fields( $fields, $post_type ): array {
		$fields = array_values( array_filter( (array) $fields, 'is_string' ) );
		foreach ( array_keys( $this->tracked_fields( (string) $post_type ) ) as $name ) {
			$fields[] = self::PREFIX . $name;
		}

		return $fields;
	}

	/**
	 * Raw value of an `acf:*` field.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed    $value Value from earlier filters.
	 * @param string   $field Field key.
	 * @param \WP_Post $post  Post.
	 * @return mixed
	 */
	public function field_value( $value, $field, $post ) {
		if ( ! is_string( $field ) || ! str_starts_with( $field, self::PREFIX ) || ! $post instanceof \WP_Post || ! function_exists( 'get_field' ) ) {
			return $value;
		}

		return get_field( substr( $field, strlen( self::PREFIX ) ), $post->ID, false );
	}

	/**
	 * Normalizes an `acf:*` field: its text parts, one per line, or every value in strict mode.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $normalized Earlier result.
	 * @param string      $field      Field key.
	 * @param mixed       $raw        Raw value.
	 * @param bool        $strict     Strict mode.
	 */
	public function normalize( $normalized, $field, $raw, $strict ): ?string {
		if ( null !== $normalized || ! is_string( $field ) || ! str_starts_with( $field, self::PREFIX ) ) {
			return $normalized;
		}
		if ( $strict ) {
			return $this->normalizer->normalize_value( $raw, true );
		}

		$definition = $this->definition( substr( $field, strlen( self::PREFIX ) ) );
		if ( null === $definition ) {
			return $this->normalizer->normalize_value( $raw );
		}

		return implode( "\n", $this->extractor->field_text( $definition, $raw ) );
	}

	/**
	 * Adds an ACF block's text fields to its normalized line.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                $parts Text parts.
	 * @param array<string, mixed> $block Parsed block.
	 * @return list<string>
	 */
	public function block_text( $parts, $block ): array {
		$parts = array_values( array_filter( (array) $parts, 'is_string' ) );
		$name  = is_array( $block ) && is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$data  = is_array( $block ) ? ( $block['attrs']['data'] ?? null ) : null;

		if ( '' === $name || ! is_array( $data ) || ! $this->is_acf_block( $name ) ) {
			return $parts;
		}

		return array_merge( $parts, $this->extractor->block_data_text( $data ) );
	}

	/**
	 * Whether a block type is an ACF block. ACF blocks can use any namespace.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Block name.
	 */
	public function is_acf_block( string $name ): bool {
		$is_acf = function_exists( 'acf_has_block_type' ) && acf_has_block_type( $name );

		/**
		 * Filters whether a block type's `data` attribute holds ACF field values.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $is_acf Whether it is an ACF block.
		 * @param string $name   Block name.
		 */
		return (bool) apply_filters( 'stalelingo_is_acf_block', $is_acf, $name );
	}

	/**
	 * The translation preference of a field: 'translate', 'copy_once', 'copy', 'ignore', or null when unset.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public function translation_preference( array $field ): ?string {
		$preference = null;

		if ( ProviderDetector::WPML === $this->provider->id() ) {
			// WPML keeps custom field preferences by meta key: 0 ignore, 1 copy, 2 translate, 3 copy once.
			$management = apply_filters( 'wpml_setting', null, 'translation-management' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.
			$value      = is_array( $management ) ? ( $management['custom_fields_translation'][ $field['name'] ?? '' ] ?? null ) : null;
			$preference = null === $value ? null : ( array( 'ignore', 'copy', 'translate', 'copy_once' )[ (int) $value ] ?? null );
		} elseif ( isset( $field['translations'] ) && is_string( $field['translations'] ) ) {
			// Polylang Pro's ACF integration: translate, copy_once, synchronize or ignore.
			$preference = 'synchronize' === $field['translations'] ? 'copy' : $field['translations'];
		}

		/**
		 * Filters the translation preference of an ACF field.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null          $preference 'translate', 'copy_once', 'copy', 'ignore', or null when unset.
		 * @param array<string, mixed> $field      Field definition.
		 */
		$preference = apply_filters( 'stalelingo_acf_translation_preference', $preference, $field );

		return is_string( $preference ) ? $preference : null;
	}

	/**
	 * Whether a top-level field is tracked.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public function is_tracked( array $field ): bool {
		if ( '' === (string) ( $field['name'] ?? '' ) ) {
			return false;
		}

		$preference = $this->translation_preference( $field );
		if ( null !== $preference ) {
			return in_array( $preference, array( 'translate', 'copy_once' ), true );
		}

		return $this->extractor->has_text( $field );
	}

	/**
	 * Tracked top-level fields of a post type, keyed by field name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	public function tracked_fields( string $post_type ): array {
		if ( ! isset( $this->fields_by_type[ $post_type ] ) ) {
			$tracked = array();
			foreach ( acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( is_array( $field ) && $this->is_tracked( $field ) ) {
						$tracked[ (string) $field['name'] ] = $field;
					}
				}
			}
			$this->fields_by_type[ $post_type ] = $tracked;
		}

		return $this->fields_by_type[ $post_type ];
	}

	/**
	 * Forgets the field definitions read this request (after field groups change).
	 *
	 * @since 1.0.0
	 */
	public function flush_cache(): void {
		$this->fields_by_type = array();
	}

	/**
	 * Definition of a tracked field by name, from any post type seen this request.
	 *
	 * @param string $name Field name.
	 * @return array<string, mixed>|null
	 */
	private function definition( string $name ): ?array {
		foreach ( $this->fields_by_type as $fields ) {
			if ( isset( $fields[ $name ] ) ) {
				return $fields[ $name ];
			}
		}

		$field = function_exists( 'acf_get_field' ) ? acf_get_field( $name ) : false;

		return is_array( $field ) ? $field : null;
	}
}
