<?php
/**
 * Value normalization.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw field values into the text that is hashed and diffed.
 *
 * By default only what a reader sees counts: markup, block attributes that
 * don't hold text, whitespace runs, entity encoding and trailing newlines are
 * ignored. Strict mode keeps markup and every block attribute, so formatting
 * edits count as drift too; whitespace and entities are still normalized.
 *
 * Block content arrives already parsed (the output format of `parse_blocks()`),
 * which keeps this class free of WordPress calls other than hooks. Each block
 * with text becomes one line, `[block/name] text`, so diffs read per block.
 *
 * @since 1.0.0
 */
final class Normalizer {

	/**
	 * HTML attributes whose values are visible or announced text.
	 *
	 * @since 1.0.0
	 */
	private const TEXT_HTML_ATTRIBUTES = array( 'alt', 'title', 'aria-label', 'placeholder' );

	/**
	 * Opening or closing tags of elements that separate words.
	 *
	 * @since 1.0.0
	 */
	private const BLOCK_TAG_PATTERN = '#</?(?:address|article|aside|blockquote|br|caption|dd|details|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|summary|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*>#i';

	/**
	 * Block attributes that hold visible text for core blocks that don't render it into their saved HTML.
	 *
	 * @since 1.0.0
	 */
	private const DEFAULT_BLOCK_TEXT_ATTRIBUTES = array(
		'core/search'                    => array( 'label', 'placeholder', 'buttonText' ),
		'core/navigation-link'           => array( 'label', 'title' ),
		'core/navigation-submenu'        => array( 'label', 'title' ),
		'core/home-link'                 => array( 'label' ),
		'core/social-link'               => array( 'label' ),
		'core/post-excerpt'              => array( 'moreText' ),
		'core/read-more'                 => array( 'content' ),
		'core/query-pagination-next'     => array( 'label' ),
		'core/query-pagination-previous' => array( 'label' ),
		'core/comments-title'            => array( 'singleCommentLabel', 'multipleCommentsLabel' ),
	);

	/**
	 * Normalizes plain or HTML text.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value  Raw value.
	 * @param bool   $strict Whether to keep markup.
	 */
	public function normalize_text( string $value, bool $strict = false ): string {
		if ( ! $strict ) {
			$value = $this->visible_text( $value );
		}

		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! $strict ) {
			$value = str_replace( "\u{00A0}", ' ', $value );
		}
		$value = (string) preg_replace( '/\s+/u', ' ', $value );

		return trim( $value );
	}

	/**
	 * Normalizes parsed block content into one line per block that has text.
	 *
	 * @since 1.0.0
	 *
	 * @param array<array-key, mixed> $blocks Output of `parse_blocks()`.
	 * @param bool                    $strict Whether to keep markup and every attribute.
	 */
	public function normalize_blocks( array $blocks, bool $strict = false ): string {
		$lines = array();
		$this->walk( $blocks, $strict, $lines );

		return implode( "\n", $lines );
	}

	/**
	 * Normalizes a meta or field value of any type.
	 *
	 * Scalars are normalized as text. Arrays and objects are encoded as JSON
	 * with sorted keys, so reordering keys doesn't count as a change.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value  Raw value.
	 * @param bool  $strict Whether to keep markup in strings.
	 */
	public function normalize_value( mixed $value, bool $strict = false ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_scalar( $value ) || null === $value ) {
			return $this->normalize_text( (string) $value, $strict );
		}

		$value = $this->sort_recursive( (array) json_decode( (string) wp_json_encode( $value ), true ) );

		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Returns the text-bearing attribute names for a block type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $block_name Block name, e.g. 'core/search'.
	 * @return list<string>
	 */
	public function block_text_attributes( string $block_name ): array {
		/**
		 * Filters which block attributes hold visible text.
		 *
		 * Attributes not listed are ignored unless strict mode is on, so changing
		 * alignment, colors or spacing doesn't flag translations as outdated.
		 * Text that a block renders into its saved HTML is always included.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, list<string>> $map Attribute names keyed by block name.
		 */
		$map = apply_filters( 'tdrift_block_text_attributes', self::DEFAULT_BLOCK_TEXT_ATTRIBUTES );

		$attributes = is_array( $map ) && isset( $map[ $block_name ] ) && is_array( $map[ $block_name ] ) ? $map[ $block_name ] : array();

		return array_values( array_filter( $attributes, 'is_string' ) );
	}

	/**
	 * Appends one line per block (depth first).
	 *
	 * @param array<array-key, mixed> $blocks Parsed blocks.
	 * @param bool                    $strict Strict mode.
	 * @param list<string>            $lines  Output lines.
	 */
	private function walk( array $blocks, bool $strict, array &$lines ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : null;
			$html = $this->own_html( $block );

			if ( null === $name ) {
				// Classic content, or whitespace between blocks.
				$text = $this->normalize_text( $html, $strict );
				if ( '' !== $text ) {
					$lines[] = $text;
				}
				continue;
			}

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$text  = $strict ? $this->strict_block_text( $html, $attrs ) : $this->block_text( $name, $html, $attrs, $block );

			if ( '' !== $text ) {
				$lines[] = "[{$name}] {$text}";
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk( $block['innerBlocks'], $strict, $lines );
			}
		}//end foreach
	}

	/**
	 * Visible text of one block: its own HTML plus its text attributes.
	 *
	 * @param string               $name  Block name.
	 * @param string               $html  The block's own HTML, without inner blocks.
	 * @param array<string, mixed> $attrs Block attributes.
	 * @param array<string, mixed> $block The parsed block.
	 */
	private function block_text( string $name, string $html, array $attrs, array $block ): string {
		$parts = array( $this->normalize_text( $html ) );

		foreach ( $this->block_text_attributes( $name ) as $attribute ) {
			if ( isset( $attrs[ $attribute ] ) ) {
				foreach ( $this->strings_in( $attrs[ $attribute ] ) as $string ) {
					$parts[] = $this->normalize_text( $string );
				}
			}
		}

		/**
		 * Filters the text parts extracted from one block.
		 *
		 * Integrations use this to add text a block keeps outside its HTML,
		 * such as ACF block field values.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string>         $parts Normalized text parts.
		 * @param array<string, mixed> $block The parsed block.
		 */
		$parts = apply_filters( 'tdrift_block_text', $parts, $block );

		return trim( implode( ' ', array_filter( (array) $parts, static fn( $part ): bool => is_string( $part ) && '' !== $part ) ) );
	}

	/**
	 * Strict-mode text of one block: markup plus every attribute.
	 *
	 * @param string               $html  The block's own HTML.
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function strict_block_text( string $html, array $attrs ): string {
		$text = $this->normalize_text( $html, true );
		if ( array() === $attrs ) {
			return $text;
		}

		return trim( $this->normalize_value( $attrs, true ) . ' ' . $text );
	}

	/**
	 * The block's own HTML: its innerContent strings, with inner blocks left out.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function own_html( array $block ): string {
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			return implode( ' ', array_filter( $block['innerContent'], 'is_string' ) );
		}

		return isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
	}

	/**
	 * Text a reader sees or hears: tag contents plus text-bearing HTML attributes.
	 *
	 * @param string $html HTML.
	 */
	private function visible_text( string $html ): string {
		if ( ! str_contains( $html, '<' ) ) {
			return $html;
		}

		$extra   = array();
		$pattern = '/\s(?:' . implode( '|', self::TEXT_HTML_ATTRIBUTES ) . ')\s*=\s*(["\'])(.*?)\1/is';
		if ( preg_match_all( $pattern, $html, $matches ) ) {
			$extra = $matches[2];
		}

		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		// Block-level tags and line breaks separate words; inline tags (em, strong, a…) don't.
		$html = (string) preg_replace( self::BLOCK_TAG_PATTERN, ' ', $html );
		// wp_strip_all_tags() isn't available to this WordPress-free class; script and style are removed above.
		$text = strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

		return trim( $text . ' ' . implode( ' ', $extra ) );
	}

	/**
	 * Every string inside a scalar or nested array.
	 *
	 * @param mixed $value Attribute value.
	 * @return list<string>
	 */
	private function strings_in( mixed $value ): array {
		if ( is_string( $value ) ) {
			return array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $item ) {
			array_push( $strings, ...$this->strings_in( $item ) );
		}

		return $strings;
	}

	/**
	 * Sorts associative arrays by key, recursively. Lists keep their order.
	 *
	 * @param array<mixed> $value Value.
	 * @return array<mixed>
	 */
	private function sort_recursive( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->sort_recursive( $item );
			}
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}
}
