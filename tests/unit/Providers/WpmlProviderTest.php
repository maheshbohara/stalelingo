<?php
/**
 * Tests for WpmlProvider (WPML filter API mocked).
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Providers;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Stalelingo\Providers\WpmlProvider;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Providers\WpmlProvider
 */
final class WpmlProviderTest extends TestCase {

	/**
	 * Translations of trid 7, in WPML's return shape.
	 *
	 * @var array<string, object>
	 */
	private array $translations = array();

	protected function set_up(): void {
		parent::set_up();

		$this->translations = array(
			'fr' => (object) array(
				'translation_id'       => '31',
				'language_code'        => 'fr',
				'element_id'           => '11',
				'source_language_code' => 'en',
				'element_type'         => 'post_page',
				'original'             => '0',
			),
			'en' => (object) array(
				'translation_id'       => '30',
				'language_code'        => 'en',
				'element_id'           => '10',
				'source_language_code' => null,
				'element_type'         => 'post_page',
				'original'             => '1',
			),
		);

		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Filters\expectApplied( 'wpml_active_languages' )->andReturn(
			array(
				'en' => array( 'code' => 'en' ),
				'fr' => array( 'code' => 'fr' ),
				'de' => array( 'code' => 'de' ),
			)
		);
		Filters\expectApplied( 'wpml_default_language' )->andReturn( 'en' );
		Filters\expectApplied( 'wpml_element_type' )->andReturnUsing( static fn( string $type ): string => 'post_' . $type );
		Filters\expectApplied( 'wpml_element_trid' )->andReturnUsing( static fn( $unused, int $id ): ?int => in_array( $id, array( 10, 11 ), true ) ? 7 : null );
		Filters\expectApplied( 'wpml_get_element_translations' )->andReturnUsing( fn( $unused, int $trid ): array => 7 === $trid ? $this->translations : array() );
		Filters\expectApplied( 'wpml_element_language_details' )->andReturnUsing(
			static fn( $unused, array $args ) => array(
				10 => (object) array( 'language_code' => 'en' ),
				11 => (object) array( 'language_code' => 'fr' ),
			)[ $args['element_id'] ] ?? null
		);
		Filters\expectApplied( 'wpml_is_translated_post_type' )->andReturnUsing( static fn( $fallback, string $type ): bool => 'attachment' !== $type );
	}

	public function test_languages(): void {
		$provider = new WpmlProvider();

		$this->assertSame( 'wpml', $provider->id() );
		$this->assertSame( array( 'en', 'fr', 'de' ), $provider->get_languages() );
		$this->assertSame( 'en', $provider->get_default_language() );
		$this->assertTrue( $provider->is_translated_post_type( 'page' ) );
		$this->assertFalse( $provider->is_translated_post_type( 'attachment' ) );
	}

	public function test_group_is_in_language_order(): void {
		$this->assertSame(
			array(
				'en' => 10,
				'fr' => 11,
			),
			( new WpmlProvider() )->get_group( 11 )
		);
	}

	public function test_source_is_the_original(): void {
		$this->assertSame( 10, ( new WpmlProvider() )->get_source( 11 ) );
		$this->assertSame( 10, ( new WpmlProvider() )->get_source( 10 ) );
	}

	public function test_source_from_null_source_language_alone(): void {
		unset( $this->translations['en']->original );

		$this->assertSame( 10, ( new WpmlProvider() )->get_source( 11 ) );
	}

	public function test_group_without_its_original_has_no_source(): void {
		unset( $this->translations['en'] );

		$this->assertNull( ( new WpmlProvider() )->get_source( 11 ) );
	}

	public function test_translations_without_a_post_are_left_out(): void {
		$this->translations['de'] = (object) array(
			'element_id'           => null,
			'source_language_code' => 'en',
			'original'             => '0',
		);

		$this->assertArrayNotHasKey( 'de', ( new WpmlProvider() )->get_group( 10 ) );
	}

	public function test_unknown_post(): void {
		$provider = new WpmlProvider();

		$this->assertSame( array(), $provider->get_group( 99 ) );
		$this->assertNull( $provider->get_source( 99 ) );
		$this->assertNull( $provider->get_language( 99 ) );
		$this->assertSame( 'fr', $provider->get_language( 11 ) );
	}

	public function test_edit_url_for_missing_translation_uses_wpml_add_translation_screen(): void {
		Functions\when( 'admin_url' )->justReturn( 'http://x/wp-admin/post-new.php' );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );

		$this->assertSame(
			'http://x/wp-admin/post-new.php?post_type=page&trid=7&lang=de&source_lang=en',
			( new WpmlProvider() )->get_edit_translation_url( 10, 'de' )
		);
	}

	public function test_edit_url_for_existing_translation(): void {
		Functions\expect( 'get_edit_post_link' )->once()->with( 11, 'raw' )->andReturn( 'http://x/edit/11' );

		$this->assertSame( 'http://x/edit/11', ( new WpmlProvider() )->get_edit_translation_url( 10, 'fr' ) );
	}

	public function test_hooks(): void {
		$provider = new WpmlProvider();
		$provider->on_translation_saved( static function (): void {} );
		$provider->on_languages_changed( static function (): void {} );

		foreach ( array( 'wpml_after_save_post', 'wpml_pro_translation_completed', 'icl_make_duplicate', 'wpml_update_active_languages', 'icl_after_set_default_language' ) as $hook ) {
			$this->assertTrue( has_action( $hook ), $hook );
		}
	}
}
