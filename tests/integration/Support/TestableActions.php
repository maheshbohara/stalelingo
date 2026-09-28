<?php
/**
 * Test helper.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration\Support;

use TranslationDrift\Admin\Actions;

/**
 * Actions whose redirect throws, so tests can inspect it.
 */
final class TestableActions extends Actions {

	protected function finish(): void {
		throw new RedirectStop();
	}
}
