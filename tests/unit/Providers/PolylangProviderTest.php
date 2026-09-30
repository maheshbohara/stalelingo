<?php
/**
 * Tests for PolylangProvider (Polylang API mocked).
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Providers;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Stalelingo\Providers\PolylangProvider;
use Stalelingo\Settings;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Providers\PolylangProvider
 */
final class PolylangProviderTest extends TestCase {

	/**
	 * Group 10 (en), 11 (fr), 12 (es).
	 */
	private const GROUP = array(
		'en' => 10,
		'fr' => 11,
		'es' => 12,
	);

	/**
	 * Meta flags set per post ID.
	 *
	 * @var array<int, string>
	 */
	private array $source_flags = array();

	private function provider( string $source_language = '' ): PolylangProvider {
		$settings = \Mockery::mock( Settings::class );
		$settings->allows( 'get' )->with( 'source_language' )->andReturn( $source_language );

		return new PolylangProvider( $settings );
	}

	protected function set_up(): void {
		parent::set_up();
		$this->source_flags = array();
		$group              = self::GROUP;

		Functions\when( 'pll_languages_list' )->justReturn( array( 'en', 'fr', 'es' ) );
		Functions\when( 'pll_default_language' )->justReturn( 'en' );
		Functions\when( 'pll_get_post_translations' )->alias( static fn( int $id ): array => in_array( $id, $group, true ) ? $group : array() );
		Functions\when( 'pll_get_post_language' )->alias( static fn( int $id ) => array_search( $id, $group, true ) );
		Functions\when( 'pll_is_translated_post_type' )->alias( static fn( string $type ): bool => 'post' === $type );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ) => PolylangProvider::SOURCE_META === $key ? ( $this->source_flags[ $id ] ?? '' ) : '' );
	}

	public function test_languages_and_readiness(): void {
		$provider = $this->provider();

		$this->assertSame( 'polylang', $provider->id() );
		$this->assertTrue( $provider->is_ready() );
		$this->assertSame( array( 'en', 'fr', 'es' ), $provider->get_languages() );
		$this->assertSame( 'en', $provider->get_default_language() );
		$this->assertTrue( $provider->is_translated_post_type( 'post' ) );
		$this->assertFalse( $provider->is_translated_post_type( 'attachment' ) );
	}

	public function test_group_and_language(): void {
		$provider = $this->provider();

		$this->assertSame( self::GROUP, $provider->get_group( 11 ) );
		$this->assertSame( 'fr', $provider->get_language( 11 ) );
		$this->assertNull( $provider->get_language( 99 ) );
		$this->assertSame( array(), $provider->get_group( 99 ) );
	}

	public function test_source_defaults_to_the_default_language(): void {
		$this->assertSame( 10, $this->provider()->get_source( 12 ) );
	}

	public function test_source_language_setting(): void {
		$this->assertSame( 11, $this->provider( 'fr' )->get_source( 10 ) );
	}

	public function test_source_language_filter(): void {
		Filters\expectApplied( 'stalelingo_source_language' )->with( 'en', 11 )->andReturn( 'es' );

		$this->assertSame( 12, $this->provider()->get_source( 11 ) );
	}

	public function test_per_group_override_wins(): void {
		$this->source_flags[12] = '1';

		$this->assertSame( 12, $this->provider()->get_source( 10 ) );
	}

	public function test_setting_an_override_clears_the_others(): void {
		Functions\expect( 'delete_post_meta' )->times( 3 );
		Functions\expect( 'update_post_meta' )->once()->with( 11, PolylangProvider::SOURCE_META, '1' );

		$this->provider()->set_source_override( 11, true );
	}

	public function test_source_deleted_leaves_the_group_without_a_source(): void {
		$group = array(
			'fr' => 11,
			'es' => 12,
		);
		Functions\when( 'pll_get_post_translations' )->justReturn( $group );
		Functions\when( 'pll_get_post_language' )->alias( static fn( int $id ) => array_search( $id, $group, true ) );

		$this->assertNull( $this->provider()->get_source( 11 ) );
	}

	public function test_untranslated_post_has_no_source(): void {
		$this->assertNull( $this->provider()->get_source( 99 ) );
	}

	public function test_edit_url_for_existing_translation(): void {
		Functions\expect( 'get_edit_post_link' )->once()->with( 11, 'raw' )->andReturn( 'http://x/wp-admin/post.php?post=11&action=edit' );

		$this->assertSame( 'http://x/wp-admin/post.php?post=11&action=edit', $this->provider()->get_edit_translation_url( 10, 'fr' ) );
	}

	public function test_hooks(): void {
		$provider = $this->provider();
		$saved    = array();
		$changed  = 0;

		$provider->on_translation_saved(
			static function ( int $id ) use ( &$saved ): void {
				$saved[] = $id;
			}
		);
		$provider->on_languages_changed(
			static function () use ( &$changed ): void {
				++$changed;
			}
		);

		$this->assertTrue( has_action( 'pll_save_post' ) );
		$this->assertTrue( has_action( 'pll_add_language' ) );
		$this->assertTrue( has_action( 'pll_update_language' ) );
		$this->assertTrue( has_action( 'delete_language' ) );
	}
}
