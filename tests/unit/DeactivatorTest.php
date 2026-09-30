<?php
/**
 * Tests for Deactivator.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit;

use Brain\Monkey\Functions;
use Stalelingo\Deactivator;

/**
 * @covers \Stalelingo\Deactivator
 */
final class DeactivatorTest extends TestCase {

	public function test_deactivation_clears_every_scheduled_hook(): void {
		$hooks = Deactivator::scheduled_hooks();
		Functions\expect( 'wp_unschedule_hook' )->times( count( $hooks ) );

		Deactivator::deactivate();

		$this->assertContainsOnly( 'string', $hooks );
	}
}
