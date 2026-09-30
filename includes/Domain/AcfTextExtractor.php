<?php
/**
 * ACF text extraction.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts the translatable text from ACF field values and ACF block data.
 *
 * Only text-type fields count (text, textarea, WYSIWYG, link titles by
 * default). Layout values such as numbers, selects, toggles and image IDs are
 * ignored, so changing a block's spacing doesn't flag its translations.
 * Groups, repeaters and flexible content are walked into.
 *
 * Field definitions come from a resolver (in WordPress, `acf_get_field()`),
 * which keeps this class free of ACF calls.
 *
 * @since 1.0.0
 */
final class AcfTextExtractor {

	/**
	 * Default text field types.
	 *
	 * @since 1.0.0
	 */
	public const DEFAULT_TEXT_TYPES = array( 'text', 'textarea', 'wysiwyg', 'link' );

	/**
	 * Field types that contain other fields.
	 *
	 * @since 1.0.0
	 */
	private const CONTAINER_TYPES = array( 'group', 'repeater', 'flexible_content', 'clone' );

	/**
	 * Resolves a field key to its definition.
	 *
	 * @var callable(string): (array<string, mixed>|null)
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(string): (array<string, mixed>|null) $resolver   Field key to field definition.
	 * @param Normalizer                                    $normalizer Normalizer.
	 */
	public function __construct( callable $resolver, private Normalizer $normalizer ) {
		$this->resolver = $resolver;
	}

	/**
	 * Text field types.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function text_types(): array {
		/**
		 * Filters which ACF field types hold translatable text.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $types Field types. Default text, textarea, wysiwyg and link.
		 */
		$types = apply_filters( 'stalelingo_acf_text_field_types', self::DEFAULT_TEXT_TYPES );

		return array_values( array_filter( (array) $types, 'is_string' ) );
	}

	/**
	 * Whether a field holds text, directly or in a sub-field.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public function has_text( array $field ): bool {
		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, $this->text_types(), true ) ) {
			return true;
		}

		foreach ( $this->sub_fields( $field ) as $sub_field ) {
			if ( $this->has_text( $sub_field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalized text parts of a field value.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw (unformatted) value.
	 * @return list<string>
	 */
	public function field_text( array $field, mixed $value ): array {
		$type = (string) ( $field['type'] ?? '' );

		if ( in_array( $type, $this->text_types(), true ) ) {
			return $this->text_of( $type, $value );
		}

		if ( ! is_array( $value ) || ! in_array( $type, self::CONTAINER_TYPES, true ) ) {
			return array();
		}

		if ( 'group' === $type || 'clone' === $type ) {
			return $this->row_text( $this->sub_fields( $field ), $value );
		}

		$parts = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sub_fields = 'flexible_content' === $type
				? $this->layout_sub_fields( $field, (string) ( $row['acf_fc_layout'] ?? '' ) )
				: $this->sub_fields( $field );
			array_push( $parts, ...$this->row_text( $sub_fields, $row ) );
		}

		return $parts;
	}

	/**
	 * Normalized text parts of an ACF block's `data` attribute.
	 *
	 * Block data stores each value next to an underscore-prefixed key holding its
	 * field key (`heading` / `_heading`); group and repeater sub-fields are
	 * flattened (`items_0_title`). Keys may also be field keys themselves.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Block data.
	 * @return list<string>
	 */
	public function block_data_text( array $data ): array {
		$parts = array();

		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}

			$field_key = isset( $data[ '_' . $key ] ) && is_string( $data[ '_' . $key ] ) ? $data[ '_' . $key ] : ( str_starts_with( $key, 'field_' ) ? $key : '' );
			$field     = '' === $field_key ? null : ( $this->resolver )( $field_key );
			if ( ! is_array( $field ) ) {
				continue;
			}

			// A flattened container's own value is its row count; its sub-fields have their own keys.
			if ( in_array( (string) ( $field['type'] ?? '' ), self::CONTAINER_TYPES, true ) && ! is_array( $value ) ) {
				continue;
			}

			array_push( $parts, ...$this->field_text( $field, $value ) );
		}

		return $parts;
	}

	/**
	 * Text of one row (group value, repeater row, layout) given its sub-fields.
	 *
	 * @param list<array<string, mixed>> $sub_fields Sub-field definitions.
	 * @param array<array-key, mixed>    $row        Values keyed by field key or name.
	 * @return list<string>
	 */
	private function row_text( array $sub_fields, array $row ): array {
		$parts = array();
		foreach ( $sub_fields as $sub_field ) {
			$key  = (string) ( $sub_field['key'] ?? '' );
			$name = (string) ( $sub_field['name'] ?? '' );
			if ( '' !== $key && array_key_exists( $key, $row ) ) {
				array_push( $parts, ...$this->field_text( $sub_field, $row[ $key ] ) );
			} elseif ( '' !== $name && array_key_exists( $name, $row ) ) {
				array_push( $parts, ...$this->field_text( $sub_field, $row[ $name ] ) );
			}
		}

		return $parts;
	}

	/**
	 * Text of a text-type value.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return list<string>
	 */
	private function text_of( string $type, mixed $value ): array {
		if ( 'link' === $type ) {
			$value = is_array( $value ) ? ( $value['title'] ?? '' ) : '';
		}
		if ( ! is_scalar( $value ) ) {
			return array();
		}

		$text = $this->normalizer->normalize_text( (string) $value );

		return '' === $text ? array() : array( $text );
	}

	/**
	 * Sub-field definitions of a container field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return list<array<string, mixed>>
	 */
	private function sub_fields( array $field ): array {
		$sub_fields = $field['sub_fields'] ?? array();
		if ( 'flexible_content' === ( $field['type'] ?? '' ) ) {
			$sub_fields = array();
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				array_push( $sub_fields, ...array_values( (array) ( is_array( $layout ) ? ( $layout['sub_fields'] ?? array() ) : array() ) ) );
			}
		}

		return array_values( array_filter( (array) $sub_fields, 'is_array' ) );
	}

	/**
	 * Sub-fields of one flexible content layout.
	 *
	 * @param array<string, mixed> $field  Flexible content field.
	 * @param string               $layout Layout name.
	 * @return list<array<string, mixed>>
	 */
	private function layout_sub_fields( array $field, string $layout ): array {
		foreach ( (array) ( $field['layouts'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) && ( $candidate['name'] ?? '' ) === $layout ) {
				return array_values( array_filter( (array) ( $candidate['sub_fields'] ?? array() ), 'is_array' ) );
			}
		}

		return array();
	}
}
