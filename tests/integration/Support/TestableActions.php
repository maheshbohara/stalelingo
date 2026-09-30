<?php
/**
 * Test helper.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration\Support;

use Stalelingo\Admin\Actions;

/**
 * Actions whose redirect throws, so tests can inspect it.
 */
final class TestableActions extends Actions {

	protected function finish(): void {
		throw new RedirectStop();
	}
}
