<?php
/**
 * Tests for AcfTextExtractor, with fixtures shaped like a real ACF block
 * (a page title banner block registered in a custom namespace).
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Domain;

use Brain\Monkey\Filters;
use Stalelingo\Domain\AcfTextExtractor;
use Stalelingo\Domain\Normalizer;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Domain\AcfTextExtractor
 */
final class AcfTextExtractorTest extends TestCase {

	/**
	 * Field definitions keyed by field key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const FIELDS = array(
		'field_heading'     => array(
			'key'  => 'field_heading',
			'name' => 'heading',
			'type' => 'text',
		),
		'field_intro'       => array(
			'key'  => 'field_intro',
			'name' => 'intro',
			'type' => 'textarea',
		),
		'field_intro_size'  => array(
			'key'  => 'field_intro_size',
			'name' => 'intro_size',
			'type' => 'select',
		),
		'field_image'       => array(
			'key'  => 'field_image',
			'name' => 'feature_image',
			'type' => 'image',
		),
		'field_cta'         => array(
			'key'  => 'field_cta',
			'name' => 'cta',
			'type' => 'link',
		),
		'field_show_mobile' => array(
			'key'  => 'field_show_mobile',
			'name' => 'show_feature_image_mobile',
			'type' => 'true_false',
		),
		'field_margin'      => array(
			'key'  => 'field_margin',
			'name' => 'desktop_margin',
			'type' => 'group',
		),
		'field_margin_top'  => array(
			'key'  => 'field_margin_top',
			'name' => 'top',
			'type' => 'number',
		),
		'field_items'       => array(
			'key'  => 'field_items',
			'name' => 'items',
			'type' => 'repeater',
		),
		'field_item_title'  => array(
			'key'  => 'field_item_title',
			'name' => 'title',
			'type' => 'text',
		),
	);

	private function extractor(): AcfTextExtractor {
		return new AcfTextExtractor( static fn( string $key ): ?array => self::FIELDS[ $key ] ?? null, new Normalizer() );
	}

	/**
	 * Block data as ACF stores it: value plus `_name` => field key, containers flattened.
	 *
	 * @param array<string, mixed> $overrides Values to change.
	 * @return array<string, mixed>
	 */
	private function banner_data( array $overrides = array() ): array {
		$values = array_merge(
			array(
				'heading'                   => 'Grow your business',
				'intro'                     => "Advice for <strong>every</strong>\nstage.",
				'intro_size'                => 'large',
				'feature_image'             => 123,
				'cta'                       => array(
					'title'  => 'Book a call',
					'url'    => '/contact/',
					'target' => '',
				),
				'desktop_margin'            => '',
				'desktop_margin_top'        => 40,
				'show_feature_image_mobile' => '1',
				'items'                     => 1,
				'items_0_title'             => 'First item',
			),
			$overrides
		);
		$keys   = array(
			'heading'                   => 'field_heading',
			'intro'                     => 'field_intro',
			'intro_size'                => 'field_intro_size',
			'feature_image'             => 'field_image',
			'cta'                       => 'field_cta',
			'desktop_margin'            => 'field_margin',
			'desktop_margin_top'        => 'field_margin_top',
			'show_feature_image_mobile' => 'field_show_mobile',
			'items'                     => 'field_items',
			'items_0_title'             => 'field_item_title',
		);

		$data = array();
		foreach ( $values as $name => $value ) {
			$data[ $name ]       = $value;
			$data[ '_' . $name ] = $keys[ $name ];
		}

		return $data;
	}

	public function test_block_data_yields_only_text_fields(): void {
		$this->assertSame(
			array( 'Grow your business', 'Advice for every stage.', 'Book a call', 'First item' ),
			$this->extractor()->block_data_text( $this->banner_data() )
		);
	}

	public function test_layout_only_changes_do_not_change_the_text(): void {
		$layout = $this->banner_data(
			array(
				'intro_size'                => 'small',
				'desktop_margin_top'        => 80,
				'feature_image'             => 456,
				'show_feature_image_mobile' => '0',
				'cta'                       => array(
					'title'  => 'Book a call',
					'url'    => '/fr/contact/',
					'target' => '_blank',
				),
			)
		);

		$this->assertSame( $this->extractor()->block_data_text( $this->banner_data() ), $this->extractor()->block_data_text( $layout ) );
	}

	public function test_text_changes_do_change_the_text(): void {
		$this->assertNotSame(
			$this->extractor()->block_data_text( $this->banner_data() ),
			$this->extractor()->block_data_text( $this->banner_data( array( 'heading' => 'Grow faster' ) ) )
		);
	}

	public function test_block_data_keyed_by_field_key_and_unknown_keys(): void {
		$data = array(
			'field_heading' => 'Keyed by field key',
			'mystery'       => 'no field definition',
			'_orphan'       => 'field_heading',
		);

		$this->assertSame( array( 'Keyed by field key' ), $this->extractor()->block_data_text( $data ) );
	}

	public function test_nested_group_repeater_and_flexible_content_values(): void {
		$group = array(
			'type'       => 'group',
			'sub_fields' => array( self::FIELDS['field_heading'], self::FIELDS['field_margin_top'] ),
		);
		$this->assertSame(
			array( 'In group' ),
			$this->extractor()->field_text(
				$group,
				array(
					'field_heading'    => 'In group',
					'field_margin_top' => 5,
				)
			)
		);

		$repeater = array(
			'type'       => 'repeater',
			'sub_fields' => array( self::FIELDS['field_item_title'] ),
		);
		$this->assertSame(
			array( 'Row one', 'Row two' ),
			$this->extractor()->field_text( $repeater, array( array( 'field_item_title' => 'Row one' ), array( 'title' => 'Row two' ), 'junk' ) )
		);

		$flexible = array(
			'type'    => 'flexible_content',
			'layouts' => array(
				array(
					'name'       => 'hero',
					'sub_fields' => array( self::FIELDS['field_heading'] ),
				),
				array(
					'name'       => 'quote',
					'sub_fields' => array( self::FIELDS['field_intro'] ),
				),
			),
		);
		$this->assertSame(
			array( 'Hero heading', 'A quote' ),
			$this->extractor()->field_text(
				$flexible,
				array(
					array(
						'acf_fc_layout' => 'hero',
						'field_heading' => 'Hero heading',
					),
					array(
						'acf_fc_layout' => 'quote',
						'field_intro'   => 'A quote',
					),
					array(
						'acf_fc_layout' => 'gone',
						'field_intro'   => 'Ignored',
					),
				)
			)
		);
	}

	public function test_has_text(): void {
		$extractor = $this->extractor();

		$this->assertTrue( $extractor->has_text( self::FIELDS['field_heading'] ) );
		$this->assertFalse( $extractor->has_text( self::FIELDS['field_margin_top'] ) );
		$this->assertTrue(
			$extractor->has_text(
				array(
					'type'       => 'repeater',
					'sub_fields' => array( self::FIELDS['field_item_title'] ),
				)
			)
		);
		$this->assertFalse(
			$extractor->has_text(
				array(
					'type'       => 'group',
					'sub_fields' => array( self::FIELDS['field_margin_top'] ),
				)
			)
		);
		$this->assertTrue(
			$extractor->has_text(
				array(
					'type'    => 'flexible_content',
					'layouts' => array( array( 'sub_fields' => array( self::FIELDS['field_intro'] ) ) ),
				)
			)
		);
	}

	public function test_text_types_are_filterable(): void {
		Filters\expectApplied( 'stalelingo_acf_text_field_types' )->andReturn( array( 'text', 'select' ) );

		$this->assertSame( array( 'large' ), $this->extractor()->field_text( self::FIELDS['field_intro_size'], 'large' ) );
	}

	public function test_empty_and_non_scalar_values(): void {
		$extractor = $this->extractor();

		$this->assertSame( array(), $extractor->field_text( self::FIELDS['field_heading'], '' ) );
		$this->assertSame( array(), $extractor->field_text( self::FIELDS['field_heading'], array( 'x' ) ) );
		$this->assertSame( array(), $extractor->field_text( self::FIELDS['field_cta'], 'not-a-link-array' ) );
		$this->assertSame( array(), $extractor->field_text( array( 'type' => 'repeater' ), 3 ) );
	}
}
