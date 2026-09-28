<?php
/**
 * Base class for unit tests.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Sets up and tears down Brain Monkey around each test.
 */
abstract class TestCase extends PolyfillTestCase {

	use MockeryPHPUnitIntegration;

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Monkey\Functions\stubs(
			array(
				'wp_json_encode' => static fn( $value, $flags = 0 ) => json_encode( $value, $flags ),
			)
		);
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
