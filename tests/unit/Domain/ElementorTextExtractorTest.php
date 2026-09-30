<?php
/**
 * Tests for ElementorTextExtractor.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Domain;

use Brain\Monkey\Filters;
use Stalelingo\Domain\ElementorTextExtractor;
use Stalelingo\Domain\Normalizer;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Domain\ElementorTextExtractor
 */
final class ElementorTextExtractorTest extends TestCase {

	/**
	 * A section > column > widgets document.
	 *
	 * @param array<string, array<string, mixed>> $overrides Widget settings keyed by widget id.
	 * @return array<int, array<string, mixed>>
	 */
	private function document( array $overrides = array() ): array {
		$widgets = array(
			'w1' => array(
				'widgetType' => 'heading',
				'settings'   => array(
					'title'                 => 'Welcome',
					'header_size'           => 'h2',
					'typography_typography' => 'custom',
					'title_color'           => '#111111',
				),
			),
			'w2' => array(
				'widgetType' => 'text-editor',
				'settings'   => array(
					'editor'     => '<p>Intro <em>text</em>.</p>',
					'text_color' => '#333333',
				),
			),
			'w3' => array(
				'widgetType' => 'button',
				'settings'   => array(
					'text'             => 'Contact us',
					'link'             => array( 'url' => '/contact/' ),
					'background_color' => '#ff0000',
					'align'            => 'center',
				),
			),
			'w4' => array(
				'widgetType' => 'icon-list',
				'settings'   => array(
					'icon_list' => array(
						array(
							'text'          => 'Fast',
							'selected_icon' => array( 'value' => 'fas fa-check' ),
						),
						array( 'text' => 'Friendly' ),
					),
				),
			),
			'w5' => array(
				'widgetType' => 'spacer',
				'settings'   => array( 'space' => array( 'size' => 50 ) ),
			),
		);

		$elements = array();
		foreach ( $widgets as $id => $widget ) {
			$widget['settings'] = array_merge( $widget['settings'], $overrides[ $id ] ?? array() );
			$elements[]         = array(
				'id'     => $id,
				'elType' => 'widget',
			) + $widget;
		}

		return array(
			array(
				'id'       => 's1',
				'elType'   => 'section',
				'settings' => array(
					'background_color' => '#ffffff',
					'padding'          => array( 'top' => 10 ),
				),
				'elements' => array(
					array(
						'id'       => 'c1',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => $elements,
					),
				),
			),
		);
	}

	private function extract( array $document ): string {
		return ( new ElementorTextExtractor( new Normalizer() ) )->extract( (string) json_encode( $document ) );
	}

	public function test_extracts_one_line_per_widget_with_text(): void {
		$this->assertSame(
			"[elementor/heading] Welcome\n[elementor/text-editor] Intro text.\n[elementor/button] Contact us\n[elementor/icon-list] Fast Friendly",
			$this->extract( $this->document() )
		);
	}

	public function test_style_only_changes_do_not_change_the_text(): void {
		$styled = $this->document(
			array(
				'w1' => array(
					'title_color'          => '#000000',
					'header_size'          => 'h1',
					'typography_font_size' => array( 'size' => 40 ),
				),
				'w2' => array(
					'text_color' => '#999999',
					'align'      => 'right',
				),
				'w3' => array(
					'background_color' => '#00ff00',
					'border_radius'    => array( 'size' => 8 ),
				),
				'w5' => array( 'space' => array( 'size' => 120 ) ),
			)
		);

		$this->assertSame( $this->extract( $this->document() ), $this->extract( $styled ) );
	}

	public function test_text_changes_change_the_text(): void {
		$this->assertNotSame(
			$this->extract( $this->document() ),
			$this->extract( $this->document( array( 'w3' => array( 'text' => 'Write to us' ) ) ) )
		);
	}

	public function test_unknown_widgets_use_text_like_setting_names(): void {
		$document = array(
			array(
				'elType'     => 'widget',
				'widgetType' => 'acme-card',
				'settings'   => array(
					'card_title'       => 'Card title',
					'card_title_color' => '#123456',
					'items'            => array(
						array(
							'item_description' => 'Nested text',
							'item_icon'        => 'x',
						),
					),
					'_css_classes'     => 'hidden',
					'count'            => '3',
				),
			),
		);

		$this->assertSame( '[elementor/acme-card] Card title Nested text', $this->extract( $document ) );
	}

	public function test_text_settings_are_filterable(): void {
		Filters\expectApplied( 'stalelingo_elementor_text_settings' )->andReturn( array( 'heading' => array( 'header_size' ) ) );

		$this->assertStringContainsString( '[elementor/heading] h2', $this->extract( $this->document() ) );
	}

	public function test_invalid_or_empty_data(): void {
		$extractor = new ElementorTextExtractor( new Normalizer() );

		$this->assertSame( '', $extractor->extract( 'not json' ) );
		$this->assertSame( '', $extractor->extract( '[]' ) );
		$this->assertSame(
			'[elementor/heading] Hi',
			$extractor->extract(
				array(
					array(
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => array( 'text' => 'Hi' ) ),
					),
				)
			)
		);
	}
}
