<?php
/**
 * Test helper.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration\Support;

/**
 * Stops a handler at its redirect instead of exiting.
 */
final class RedirectStop extends \RuntimeException {
}
