<?php
/**
 * Tests for Migrator.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit\Database;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use TranslationDrift\Database\Migrator;
use TranslationDrift\Tests\Unit\TestCase;

/**
 * @covers \TranslationDrift\Database\Migrator
 */
final class MigratorTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$wpdb         = \Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->allows( 'get_charset_collate' )->andReturn( '' );
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	public function test_fresh_install_creates_tables_and_records_version(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'dbDelta' )->once()->with( \Mockery::on( static fn( $sql ) => 3 === count( $sql ) ) );
		Functions\expect( 'update_option' )->once()->with( Migrator::OPTION, Migrator::DB_VERSION, true );
		Actions\expectDone( 'tdrift_migrated' )->once()->with( 0, Migrator::DB_VERSION );

		Migrator::maybe_upgrade();
	}

	public function test_up_to_date_site_is_left_alone(): void {
		Functions\when( 'get_option' )->justReturn( (string) Migrator::DB_VERSION );
		Functions\expect( 'dbDelta' )->never();
		Functions\expect( 'update_option' )->never();

		Migrator::maybe_upgrade();
	}

	public function test_register_hooks_early_on_init(): void {
		Migrator::register();

		$this->assertSame( 1, has_action( 'init', array( Migrator::class, 'maybe_upgrade' ) ) );
	}
}
