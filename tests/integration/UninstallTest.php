<?php
/**
 * Uninstall tests.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Capabilities;
use TranslationDrift\Database\Migrator;
use TranslationDrift\Database\Schema;
use TranslationDrift\Uninstaller;

/**
 * @covers \TranslationDrift\Uninstaller
 */
final class UninstallTest extends TestCase {

	public function test_keeps_data_by_default(): void {
		$this->allow_real_ddl();

		Uninstaller::uninstall();

		foreach ( Schema::tables() as $table ) {
			$this->assertTrue( $this->table_exists( $table ) );
		}
		$this->assertSame( Migrator::DB_VERSION, Migrator::installed_version() );
		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_deletes_everything_when_opted_in(): void {
		$this->allow_real_ddl();
		update_option( Uninstaller::SETTINGS_OPTION, array( 'delete_data' => true ) );

		Uninstaller::uninstall();

		foreach ( Schema::tables() as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "{$table} should be dropped" );
		}
		$this->assertFalse( get_option( Migrator::OPTION ) );
		$this->assertFalse( get_option( Uninstaller::SETTINGS_OPTION ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE ) );
	}
}
