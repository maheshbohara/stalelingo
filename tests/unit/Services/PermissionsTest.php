<?php
/**
 * Tests for Permissions.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Services;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Stalelingo\Capabilities;
use Stalelingo\Services\Permissions;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Services\Permissions
 */
final class PermissionsTest extends TestCase {

	/**
	 * @param array<string, bool> $caps Capability => granted, for user 5 on post 11.
	 */
	private function grant( array $caps ): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user, string $cap, ...$args ): bool => 5 === $user && ( $caps[ $cap ] ?? false ) && ( 'edit_post' !== $cap || array( 11 ) === $args )
		);
	}

	public function test_manager_can_mark_any_translation(): void {
		$this->grant( array( Capabilities::MANAGE => true ) );

		$this->assertTrue( ( new Permissions() )->can_manage( 5 ) );
		$this->assertTrue( ( new Permissions() )->can_mark_synced( 5, 42 ) );
	}

	public function test_translator_can_mark_only_translations_they_can_edit(): void {
		$this->grant( array( 'edit_post' => true ) );
		$permissions = new Permissions();

		$this->assertFalse( $permissions->can_manage( 5 ) );
		$this->assertTrue( $permissions->can_mark_synced( 5, 11 ) );
		$this->assertFalse( $permissions->can_mark_synced( 5, 42 ) );
	}

	public function test_anonymous_and_invalid_ids_are_refused(): void {
		$this->grant(
			array(
				Capabilities::MANAGE => true,
				'edit_post'          => true,
			)
		);
		$permissions = new Permissions();

		$this->assertFalse( $permissions->can_manage( 0 ) );
		$this->assertFalse( $permissions->can_mark_synced( 0, 11 ) );
		$this->assertFalse( $permissions->can_mark_synced( 5, 0 ) );
	}

	public function test_filter_has_the_last_word(): void {
		$this->grant( array() );
		Filters\expectApplied( 'stalelingo_can_mark_synced' )->once()->with( false, 5, 11 )->andReturn( true );

		$this->assertTrue( ( new Permissions() )->can_mark_synced( 5, 11 ) );
	}
}
