<?php
/**
 * Tests for Plugin.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit;

use Brain\Monkey\Functions;
use Stalelingo\Admin\AdminPage;
use Stalelingo\Admin\DependencyNotice;
use Stalelingo\Database\Migrator;
use Stalelingo\Plugin;

/**
 * @covers \Stalelingo\Plugin
 * @covers \Stalelingo\Admin\AdminPage::register
 * @covers \Stalelingo\Admin\DependencyNotice::__construct
 * @covers \Stalelingo\Admin\DependencyNotice::register
 */
final class PluginTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		( new \ReflectionProperty( Plugin::class, 'booted' ) )->setValue( null, false );
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	public function test_boot_registers_privacy_tools_without_a_provider(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		Plugin::boot();

		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_exporters', \Stalelingo\Privacy::class . '->register_exporters()' ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', \Stalelingo\Privacy::class . '->register_erasers()' ) );
	}

	public function test_boot_registers_admin_services_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		Plugin::boot();

		$this->assertSame( 1, has_action( 'init', array( Migrator::class, 'maybe_upgrade' ) ) );
		$this->assertNotFalse( has_action( 'admin_menu', AdminPage::class . '->add_page()' ) );
		$this->assertNotFalse( has_action( 'admin_notices', DependencyNotice::class . '->maybe_render()' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices', DependencyNotice::class . '->maybe_render()' ) );
	}

	public function test_boot_skips_admin_services_on_the_front_end(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		Plugin::boot();

		$this->assertFalse( has_action( 'admin_menu' ) );
		$this->assertFalse( has_action( 'admin_notices' ) );
	}

	public function test_boot_runs_once(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );

		Plugin::boot();
		Plugin::boot();
	}
}
