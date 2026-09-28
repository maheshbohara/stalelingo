<?php
/**
 * Tests for the ACF integration's tracking rules.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit\Integrations;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TranslationDrift\Domain\Normalizer;
use TranslationDrift\Integrations\Acf;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Settings;
use TranslationDrift\Tests\Unit\TestCase;

/**
 * @covers \TranslationDrift\Integrations\Acf
 */
final class AcfTest extends TestCase {

	private function acf( string $provider ): Acf {
		$settings = \Mockery::mock( Settings::class );
		$settings->allows( 'get' )->with( 'acf' )->andReturn( true );
		$mock = \Mockery::mock( TranslationProvider::class );
		$mock->allows( 'id' )->andReturn( $provider );

		return new Acf( $settings, $mock, new Normalizer() );
	}

	private function wpml_preferences( array $preferences ): void {
		Filters\expectApplied( 'wpml_setting' )->andReturn( array( 'custom_fields_translation' => $preferences ) );
	}

	public function test_wpml_translate_and_copy_once_are_tracked_copy_and_ignore_are_not(): void {
		$this->wpml_preferences(
			array(
				'journey_eyebrow' => 2,
				'journey_website' => 1,
				'internal_code'   => 0,
				'first_only'      => '3',
			)
		);
		$acf = $this->acf( 'wpml' );

		$this->assertTrue(
			$acf->is_tracked(
				array(
					'name' => 'journey_eyebrow',
					'type' => 'text',
				)
			)
		);
		$this->assertTrue(
			$acf->is_tracked(
				array(
					'name' => 'first_only',
					'type' => 'number',
				)
			),
			'The preference wins over the type.'
		);
		$this->assertFalse(
			$acf->is_tracked(
				array(
					'name' => 'journey_website',
					'type' => 'url',
				)
			)
		);
		$this->assertFalse(
			$acf->is_tracked(
				array(
					'name' => 'internal_code',
					'type' => 'text',
				)
			)
		);
	}

	public function test_fields_without_a_preference_are_tracked_when_they_hold_text(): void {
		$this->wpml_preferences( array() );
		$acf = $this->acf( 'wpml' );

		$this->assertNull( $acf->translation_preference( array( 'name' => 'episode_guest_bio' ) ) );
		$this->assertTrue(
			$acf->is_tracked(
				array(
					'name' => 'episode_guest_bio',
					'type' => 'textarea',
				)
			)
		);
		$this->assertFalse(
			$acf->is_tracked(
				array(
					'name' => 'episode_number',
					'type' => 'number',
				)
			)
		);
		$this->assertFalse(
			$acf->is_tracked(
				array(
					'name' => '',
					'type' => 'text',
				)
			)
		);
	}

	public function test_polylang_pro_field_setting(): void {
		$acf = $this->acf( 'polylang' );

		$this->assertSame(
			'translate',
			$acf->translation_preference(
				array(
					'name'         => 'a',
					'translations' => 'translate',
				)
			)
		);
		$this->assertSame(
			'copy',
			$acf->translation_preference(
				array(
					'name'         => 'b',
					'translations' => 'synchronize',
				)
			)
		);
		$this->assertFalse(
			$acf->is_tracked(
				array(
					'name'         => 'b',
					'type'         => 'text',
					'translations' => 'synchronize',
				)
			)
		);
		$this->assertTrue(
			$acf->is_tracked(
				array(
					'name' => 'c',
					'type' => 'text',
				)
			),
			'Polylang free sets no preference.'
		);
	}

	public function test_preference_filter(): void {
		Filters\expectApplied( 'tdrift_acf_translation_preference' )->andReturn( 'ignore' );

		$this->assertFalse(
			$this->acf( 'polylang' )->is_tracked(
				array(
					'name' => 'c',
					'type' => 'text',
				)
			)
		);
	}

	public function test_block_text_only_for_acf_blocks(): void {
		Functions\when( 'acf_get_field' )->alias(
			static fn( string $key ) => 'field_h' === $key ? array(
				'key'  => 'field_h',
				'name' => 'heading',
				'type' => 'text',
			) : false
		);
		Filters\expectApplied( 'tdrift_is_acf_block' )->andReturnUsing( static fn( bool $is, string $name ): bool => 'acme/banner' === $name );
		$acf  = $this->acf( 'wpml' );
		$data = array(
			'heading'  => 'Hello',
			'_heading' => 'field_h',
		);

		$this->assertSame(
			array( 'own', 'Hello' ),
			$acf->block_text(
				array( 'own' ),
				array(
					'blockName' => 'acme/banner',
					'attrs'     => array( 'data' => $data ),
				)
			)
		);
		$this->assertSame(
			array( 'own' ),
			$acf->block_text(
				array( 'own' ),
				array(
					'blockName' => 'core/paragraph',
					'attrs'     => array( 'data' => $data ),
				)
			)
		);
		$this->assertSame(
			array(),
			$acf->block_text(
				array(),
				array(
					'blockName' => 'acme/banner',
					'attrs'     => array(),
				)
			)
		);
	}

	public function test_normalize_only_handles_acf_fields(): void {
		$acf = $this->acf( 'wpml' );

		$this->assertNull( $acf->normalize( null, 'title', 'x', false ) );
		$this->assertSame( 'kept', $acf->normalize( 'kept', 'acf:x', 'y', false ) );
		$this->assertSame( '{"a":1}', $acf->normalize( null, 'acf:anything', array( 'a' => 1 ), true ) );
	}

	public function test_add_tracked_fields_and_field_value_pass_through_other_fields(): void {
		Functions\when( 'acf_get_field_groups' )->justReturn( array( array( 'key' => 'group_1' ) ) );
		Functions\when( 'acf_get_fields' )->justReturn(
			array(
				array(
					'name' => 'subtitle',
					'type' => 'text',
				),
				array(
					'name' => 'count',
					'type' => 'number',
				),
			)
		);
		$this->wpml_preferences( array() );
		$acf = $this->acf( 'wpml' );

		$this->assertSame( array( 'title', 'acf:subtitle' ), $acf->add_tracked_fields( array( 'title' ), 'page' ) );
		$this->assertSame( 'untouched', $acf->field_value( 'untouched', 'title', null ) );
	}
}
