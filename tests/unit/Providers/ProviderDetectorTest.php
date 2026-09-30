<?php
/**
 * Tests for ProviderDetector.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Providers;

use Brain\Monkey\Filters;
use Stalelingo\Providers\ProviderDetector;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Providers\ProviderDetector
 */
final class ProviderDetectorTest extends TestCase {

	/**
	 * Builds a detector with the given plugins "loaded".
	 */
	private function detector( bool $polylang, bool $wpml ): ProviderDetector {
		return new class( $polylang, $wpml ) extends ProviderDetector {
			public function __construct( private bool $polylang, private bool $wpml ) {
			}

			protected function is_polylang_loaded(): bool {
				return $this->polylang;
			}

			protected function is_wpml_loaded(): bool {
				return $this->wpml;
			}
		};
	}

	public function test_real_checks_find_nothing_without_multilingual_plugins(): void {
		$this->assertSame( array(), ( new ProviderDetector() )->available() );
	}

	public function test_detects_nothing_when_no_provider_is_loaded(): void {
		$this->assertSame( array(), $this->detector( false, false )->available() );
		$this->assertNull( $this->detector( false, false )->detect() );
	}

	public function test_detects_polylang(): void {
		$this->assertSame( ProviderDetector::POLYLANG, $this->detector( true, false )->detect() );
	}

	public function test_detects_wpml(): void {
		$this->assertSame( ProviderDetector::WPML, $this->detector( false, true )->detect() );
	}

	public function test_prefers_polylang_when_both_are_loaded(): void {
		$detector = $this->detector( true, true );

		$this->assertSame( array( ProviderDetector::POLYLANG, ProviderDetector::WPML ), $detector->available() );
		$this->assertSame( ProviderDetector::POLYLANG, $detector->detect() );
	}

	public function test_filter_can_choose_another_available_provider(): void {
		Filters\expectApplied( 'stalelingo_provider' )->once()->andReturn( ProviderDetector::WPML );

		$this->assertSame( ProviderDetector::WPML, $this->detector( true, true )->detect() );
	}

	public function test_filter_cannot_choose_an_unavailable_provider(): void {
		Filters\expectApplied( 'stalelingo_provider' )->once()->andReturn( ProviderDetector::WPML );

		$this->assertNull( $this->detector( false, false )->detect() );
	}
}
