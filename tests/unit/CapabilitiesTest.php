<?php
/**
 * Tests for Capabilities.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TranslationDrift\Capabilities;

/**
 * @covers \TranslationDrift\Capabilities
 */
final class CapabilitiesTest extends TestCase {

	public function test_defaults_to_administrator_and_editor(): void {
		$this->assertSame( array( 'administrator', 'editor' ), Capabilities::roles() );
	}

	public function test_roles_filter_output_is_sanitized(): void {
		Filters\expectApplied( 'tdrift_capability_roles' )->andReturn( array( 'shop_manager', 42, null, 'author' ) );

		$this->assertSame( array( 'shop_manager', 'author' ), Capabilities::roles() );
	}

	public function test_grant_skips_roles_that_do_not_exist(): void {
		$editor = \Mockery::mock( \WP_Role::class );
		$editor->expects( 'add_cap' )->once()->with( Capabilities::MANAGE );

		Functions\when( 'get_role' )->alias( static fn( $slug ) => 'editor' === $slug ? $editor : null );

		Capabilities::grant();
	}

	public function test_revoke_removes_the_cap_from_every_role(): void {
		$roles = array();
		foreach ( array( 'administrator', 'editor', 'author' ) as $slug ) {
			$role = \Mockery::mock( \WP_Role::class );
			$role->expects( 'remove_cap' )->once()->with( Capabilities::MANAGE );
			$roles[ $slug ] = $role;
		}
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => $roles ) );

		Capabilities::revoke();
	}
}
