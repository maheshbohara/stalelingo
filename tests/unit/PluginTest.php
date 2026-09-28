<?php
/**
 * Tests for Plugin.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit;

use Brain\Monkey\Functions;
use TranslationDrift\Admin\AdminPage;
use TranslationDrift\Admin\DependencyNotice;
use TranslationDrift\Database\Migrator;
use TranslationDrift\Plugin;

/**
 * @covers \TranslationDrift\Plugin
 * @covers \TranslationDrift\Admin\AdminPage::register
 * @covers \TranslationDrift\Admin\DependencyNotice::__construct
 * @covers \TranslationDrift\Admin\DependencyNotice::register
 */
final class PluginTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		( new \ReflectionProperty( Plugin::class, 'booted' ) )->setValue( null, false );
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
