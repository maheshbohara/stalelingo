<?php
/**
 * Activation, migrations and capability tests.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

use Stalelingo\Activator;
use Stalelingo\Capabilities;
use Stalelingo\Database\Migrator;
use Stalelingo\Database\Schema;
use Stalelingo\Providers\ProviderDetector;

/**
 * @covers \Stalelingo\Activator
 * @covers \Stalelingo\Database\Migrator
 */
final class ActivationTest extends TestCase {

	public function test_activation_creates_all_tables(): void {
		foreach ( Schema::tables() as $table ) {
			$this->assertTrue( $this->table_exists( $table ), "{$table} should exist" );
		}
		$this->assertSame( Migrator::DB_VERSION, Migrator::installed_version() );
	}

	public function test_activation_grants_capability_to_admins_and_editors(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( get_role( 'author' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_activation_is_idempotent(): void {
		global $wpdb;

		$this->allow_real_ddl();
		$columns_before = $wpdb->get_col( 'DESCRIBE ' . Schema::table( Schema::TABLE_SYNC ) );

		Activator::activate();
		Activator::activate();

		$this->assertSame( $columns_before, $wpdb->get_col( 'DESCRIBE ' . Schema::table( Schema::TABLE_SYNC ) ) );
	}

	public function test_upgrade_from_older_schema_version_runs_migrations(): void {
		$this->allow_real_ddl();
		update_option( Migrator::OPTION, 0 );
		$fired = did_action( 'stalelingo_migrated' );

		Migrator::maybe_upgrade();

		$this->assertSame( Migrator::DB_VERSION, Migrator::installed_version() );
		$this->assertSame( $fired + 1, did_action( 'stalelingo_migrated' ) );
	}

	public function test_upgrade_recreates_a_dropped_table(): void {
		global $wpdb;

		$this->allow_real_ddl();
		$table = Schema::table( Schema::TABLE_EVENTS );
		$wpdb->query( "DROP TABLE {$table}" );
		update_option( Migrator::OPTION, 0 );

		Migrator::maybe_upgrade();

		$this->assertTrue( $this->table_exists( $table ) );
	}

	public function test_polylang_is_detected(): void {
		if ( 'polylang' !== getenv( 'PROVIDER' ) && false !== getenv( 'PROVIDER' ) ) {
			$this->markTestSkipped( 'Polylang suite only.' );
		}

		$this->assertSame( ProviderDetector::POLYLANG, ( new ProviderDetector() )->detect() );
	}
}
