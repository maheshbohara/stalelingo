<?php
/**
 * Tests for Deactivator.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit;

use Brain\Monkey\Functions;
use TranslationDrift\Deactivator;

/**
 * @covers \TranslationDrift\Deactivator
 */
final class DeactivatorTest extends TestCase {

	public function test_deactivation_clears_every_scheduled_hook(): void {
		$hooks = Deactivator::scheduled_hooks();
		Functions\expect( 'wp_clear_scheduled_hook' )->times( count( $hooks ) );

		Deactivator::deactivate();

		$this->assertContainsOnly( 'string', $hooks );
	}
}
