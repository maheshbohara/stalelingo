<?php
/**
 * Tests for Uninstaller.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit;

use Brain\Monkey\Functions;
use TranslationDrift\Uninstaller;

/**
 * @covers \TranslationDrift\Uninstaller
 * @covers \TranslationDrift\Deactivator
 */
final class UninstallerTest extends TestCase {

	/**
	 * @dataProvider settings
	 *
	 * @param mixed $settings Stored option value.
	 */
	public function test_delete_data_setting( $settings, bool $expected ): void {
		Functions\when( 'get_option' )->justReturn( $settings );

		$this->assertSame( $expected, Uninstaller::should_delete_data() );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public static function settings(): array {
		return array(
			'never saved'    => array( array(), false ),
			'corrupt option' => array( 'yes', false ),
			'off'            => array( array( 'delete_data' => false ), false ),
			'on'             => array( array( 'delete_data' => true ), true ),
		);
	}

	public function test_keeps_data_when_setting_is_off(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'delete_option' )->never();
		$wpdb = \Mockery::mock();
		$wpdb->expects( 'query' )->never();
		$GLOBALS['wpdb'] = $wpdb;

		Uninstaller::uninstall_site();

		unset( $GLOBALS['wpdb'] );
	}
}
