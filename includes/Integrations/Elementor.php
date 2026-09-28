<?php
/**
 * Elementor integration.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Integrations;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Domain\ElementorTextExtractor;
use TranslationDrift\Domain\Normalizer;
use TranslationDrift\Settings;

/**
 * Tracks the visible text of Elementor-built posts as the `elementor` field.
 *
 * The field has a value only for posts edited with Elementor
 * (`_elementor_edit_mode` = builder). Styling changes don't count unless strict mode is on.
 *
 * @since 0.1.0
 */
class Elementor {

	/**
	 * Field key.
	 *
	 * @since 0.1.0
	 */
	public const FIELD = 'elementor';

	/**
	 * Text extractor.
	 *
	 * @var ElementorTextExtractor
	 */
	private ElementorTextExtractor $extractor;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings   $settings   Settings.
	 * @param Normalizer $normalizer Normalizer.
	 */
	public function __construct( private Settings $settings, private Normalizer $normalizer ) {
		$this->extractor = new ElementorTextExtractor( $normalizer );
	}

	/**
	 * Whether Elementor is active and tracking is on.
	 *
	 * @since 0.1.0
	 */
	public function is_enabled(): bool {
		/**
		 * Filters whether Elementor content is tracked.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Default: the Elementor setting is on and Elementor is active.
		 */
		return (bool) apply_filters( 'tdrift_elementor_enabled', (bool) $this->settings->get( 'elementor' ) && defined( 'ELEMENTOR_VERSION' ) );
	}

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'tdrift_tracked_fields', array( $this, 'add_tracked_field' ) );
		add_filter( 'tdrift_field_value', array( $this, 'field_value' ), 10, 3 );
		add_filter( 'tdrift_pre_normalize_value', array( $this, 'normalize' ), 10, 4 );
	}

	/**
	 * Adds the `elementor` field.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $fields Field keys.
	 * @return list<string>
	 */
	public function add_tracked_field( $fields ): array {
		$fields   = array_values( array_filter( (array) $fields, 'is_string' ) );
		$fields[] = self::FIELD;

		return $fields;
	}

	/**
	 * Raw `_elementor_data` of a post built with Elementor, otherwise null (no value).
	 *
	 * @since 0.1.0
	 *
	 * @param mixed    $value Value from earlier filters.
	 * @param string   $field Field key.
	 * @param \WP_Post $post  Post.
	 * @return mixed
	 */
	public function field_value( $value, $field, $post ) {
		if ( self::FIELD !== $field || ! $post instanceof \WP_Post ) {
			return $value;
		}
		if ( 'builder' !== get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return null;
		}

		$data = get_post_meta( $post->ID, '_elementor_data', true );

		return is_string( $data ) || is_array( $data ) ? $data : null;
	}

	/**
	 * Normalizes the `elementor` field.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $normalized Earlier result.
	 * @param string      $field      Field key.
	 * @param mixed       $raw        Raw `_elementor_data`.
	 * @param bool        $strict     Strict mode.
	 */
	public function normalize( $normalized, $field, $raw, $strict ): ?string {
		if ( null !== $normalized || self::FIELD !== $field || ! ( is_string( $raw ) || is_array( $raw ) ) ) {
			return $normalized;
		}

		if ( $strict ) {
			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

			return $this->normalizer->normalize_value( is_array( $decoded ) ? $decoded : $raw, true );
		}

		return $this->extractor->extract( $raw );
	}
}
