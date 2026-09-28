<?php
/**
 * Test helper.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration\Support;

/**
 * Stops a handler at its redirect instead of exiting.
 */
final class RedirectStop extends \RuntimeException {
}
