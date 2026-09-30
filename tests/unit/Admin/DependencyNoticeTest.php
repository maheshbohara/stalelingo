<?php
/**
 * Tests for DependencyNotice.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Stalelingo\Admin\DependencyNotice;
use Stalelingo\Providers\ProviderDetector;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Admin\DependencyNotice
 * @covers \Stalelingo\Admin\AdminPage::is_own_screen
 */
final class DependencyNoticeTest extends TestCase {

	private function notice( ?string $provider ): DependencyNotice {
		$detector = \Mockery::mock( ProviderDetector::class );
		$detector->allows( 'detect' )->andReturn( $provider );

		return new DependencyNotice( $detector );
	}

	/**
	 * @dataProvider screens
	 */
	public function test_shows_only_on_plugins_and_own_screens( string $screen_id, bool $expected ): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( $expected, $this->notice( null )->should_show( $screen_id ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function screens(): array {
		return array(
			'plugins'         => array( 'plugins', true ),
			'network plugins' => array( 'plugins-network', true ),
			'dashboard'       => array( 'tools_page_stalelingo', true ),
			'settings'        => array( 'settings_page_stalelingo-settings', true ),
			'posts list'      => array( 'edit-post', false ),
			'wp dashboard'    => array( 'dashboard', false ),
		);
	}

	public function test_hidden_when_a_provider_is_active(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertFalse( $this->notice( ProviderDetector::POLYLANG )->should_show( 'plugins' ) );
	}

	public function test_hidden_from_users_who_cannot_act_on_it(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( $this->notice( null )->should_show( 'plugins' ) );
	}

	public function test_render_uses_a_single_warning_notice(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'plugins' ) );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\expect( 'wp_admin_notice' )
			->once()
			->with( \Mockery::type( 'string' ), \Mockery::on( static fn( $args ) => 'warning' === $args['type'] ) );

		$this->notice( null )->maybe_render();
	}

	public function test_render_does_nothing_without_a_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );
		Functions\expect( 'wp_admin_notice' )->never();

		$this->notice( null )->maybe_render();
	}
}
