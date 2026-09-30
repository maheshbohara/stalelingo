<?php
/**
 * Elementor text extraction.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts the visible text of an Elementor document (`_elementor_data`).
 *
 * Only text settings of widgets count. Styling settings (typography, colors,
 * spacing, sizes, CSS) are ignored, so style-only changes don't drift. Known
 * widgets use an explicit list of text settings; other widgets fall back to
 * settings whose names look like text (title, text, content, description…).
 * Each widget with text becomes one line: `[elementor/<widget>] text`.
 *
 * @since 1.0.0
 */
final class ElementorTextExtractor {

	/**
	 * Text settings per widget type. Nested keys use `repeater.field`.
	 *
	 * @since 1.0.0
	 */
	private const WIDGET_TEXT = array(
		'heading'              => array( 'title' ),
		'text-editor'          => array( 'editor' ),
		'button'               => array( 'text' ),
		'image'                => array( 'caption' ),
		'image-box'            => array( 'title_text', 'description_text' ),
		'icon-box'             => array( 'title_text', 'description_text' ),
		'testimonial'          => array( 'testimonial_content', 'testimonial_name', 'testimonial_job' ),
		'tabs'                 => array( 'tabs.tab_title', 'tabs.tab_content' ),
		'accordion'            => array( 'tabs.tab_title', 'tabs.tab_content' ),
		'toggle'               => array( 'tabs.tab_title', 'tabs.tab_content' ),
		'icon-list'            => array( 'icon_list.text' ),
		'alert'                => array( 'alert_title', 'alert_description' ),
		'counter'              => array( 'title', 'prefix', 'suffix' ),
		'progress'             => array( 'title', 'inner_text' ),
		'html'                 => array( 'html' ),
		'divider'              => array( 'text' ),
		'star-rating'          => array( 'title' ),
		'blockquote'           => array( 'blockquote_content', 'tweet_button_label' ),
		'call-to-action'       => array( 'title', 'description', 'button', 'ribbon_title' ),
		'animated-headline'    => array( 'before_text', 'highlighted_text', 'rotating_text', 'after_text' ),
		'flip-box'             => array( 'title_text_a', 'description_text_a', 'title_text_b', 'description_text_b', 'button_text' ),
		'price-list'           => array( 'price_list.title', 'price_list.item_description' ),
		'price-table'          => array( 'heading', 'sub_heading', 'period', 'features_list.item_text', 'button_text', 'footer_additional_info', 'ribbon_title' ),
		'slides'               => array( 'slides.heading', 'slides.description', 'slides.button_text' ),
		'reviews'              => array( 'slides.content', 'slides.name', 'slides.title' ),
		'form'                 => array( 'form_fields.field_label', 'form_fields.placeholder', 'button_text', 'success_message', 'error_message' ),
		'testimonial-carousel' => array( 'slides.content', 'slides.name', 'slides.title' ),
	);

	/**
	 * Setting names that never hold text, even if they match the fallback pattern.
	 *
	 * @since 1.0.0
	 */
	private const STYLE_PATTERN = '/(typography|color|colour|size|align|margin|padding|border|shadow|css|_class|_id$|width|height|position|spacing|gap|radius|animation|font|weight|transform|hover|icon|link|url|image|background|opacity|z_index|layout|view|skin|html_tag|header_size)/i';

	/**
	 * Setting names that look like text, for widgets not in the list.
	 *
	 * @since 1.0.0
	 */
	private const TEXT_PATTERN = '/(^|_)(title|text|content|description|heading|label|caption|editor|placeholder|subtitle|message)(_|$)/i';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Normalizer $normalizer Normalizer.
	 */
	public function __construct( private Normalizer $normalizer ) {
	}

	/**
	 * Normalized text of an Elementor document, one line per widget.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array<mixed> $data `_elementor_data` (JSON string or decoded array).
	 */
	public function extract( string|array $data ): string {
		$elements = is_string( $data ) ? json_decode( $data, true ) : $data;
		if ( ! is_array( $elements ) ) {
			return '';
		}

		$lines = array();
		$this->walk( $elements, $lines );

		return implode( "\n", $lines );
	}

	/**
	 * Text setting names of a widget type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget Widget type, e.g. 'heading'.
	 * @return list<string>|null Null when the widget isn't known.
	 */
	public function text_settings( string $widget ): ?array {
		/**
		 * Filters the text settings of Elementor widgets.
		 *
		 * Keys are widget types; values list setting names, with `repeater.field`
		 * for repeater items. Widgets not listed use a name-based fallback.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, list<string>> $map Text settings keyed by widget type.
		 */
		$map = apply_filters( 'stalelingo_elementor_text_settings', self::WIDGET_TEXT );

		return is_array( $map ) && isset( $map[ $widget ] ) && is_array( $map[ $widget ] )
			? array_values( array_filter( $map[ $widget ], 'is_string' ) )
			: null;
	}

	/**
	 * Walks elements depth first.
	 *
	 * @param array<mixed> $elements Elements.
	 * @param list<string> $lines    Output lines.
	 */
	private function walk( array $elements, array &$lines ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			if ( 'widget' === ( $element['elType'] ?? '' ) && isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ) {
				$text = $this->widget_text( $element['widgetType'], $settings );
				if ( '' !== $text ) {
					$lines[] = "[elementor/{$element['widgetType']}] {$text}";
				}
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk( $element['elements'], $lines );
			}
		}
	}

	/**
	 * Text of one widget.
	 *
	 * @param string               $widget   Widget type.
	 * @param array<string, mixed> $settings Widget settings.
	 */
	private function widget_text( string $widget, array $settings ): string {
		$names = $this->text_settings( $widget );
		$parts = array();

		if ( null === $names ) {
			$this->fallback_text( $settings, $parts );
		} else {
			foreach ( $names as $name ) {
				$this->named_text( $settings, $name, $parts );
			}
		}

		return trim( implode( ' ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) ) );
	}

	/**
	 * Appends the text of a named setting (`name` or `repeater.field`).
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $name     Setting name.
	 * @param list<string>         $parts    Output.
	 */
	private function named_text( array $settings, string $name, array &$parts ): void {
		if ( str_contains( $name, '.' ) ) {
			list( $repeater, $field ) = explode( '.', $name, 2 );
			foreach ( (array) ( $settings[ $repeater ] ?? array() ) as $item ) {
				if ( is_array( $item ) ) {
					$this->named_text( $item, $field, $parts );
				}
			}
			return;
		}

		$value = $settings[ $name ] ?? null;
		if ( is_string( $value ) ) {
			$parts[] = $this->normalizer->normalize_text( $value );
		} elseif ( is_array( $value ) && isset( $value['text'] ) && is_string( $value['text'] ) ) {
			$parts[] = $this->normalizer->normalize_text( $value['text'] );
		}
	}

	/**
	 * Appends text-looking string settings, recursing into repeaters.
	 *
	 * @param array<array-key, mixed> $settings Settings.
	 * @param list<string>            $parts    Output.
	 */
	private function fallback_text( array $settings, array &$parts ): void {
		foreach ( $settings as $name => $value ) {
			$name = (string) $name;
			if ( str_starts_with( $name, '_' ) || preg_match( self::STYLE_PATTERN, $name ) ) {
				continue;
			}
			if ( is_array( $value ) && array_is_list( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						$this->fallback_text( $item, $parts );
					}
				}
			} elseif ( is_string( $value ) && preg_match( self::TEXT_PATTERN, $name ) ) {
				$parts[] = $this->normalizer->normalize_text( $value );
			}
		}
	}
}
