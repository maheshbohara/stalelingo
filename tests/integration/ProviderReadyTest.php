<?php
/**
 * Polylang environment sanity checks.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

/**
 * @covers \TranslationDrift\Container
 * @covers \TranslationDrift\Providers\PolylangProvider
 */
final class ProviderReadyTest extends TestCase {

	public function test_provider_is_ready_with_three_languages(): void {
		$provider = $this->container()->provider();

		$this->assertNotNull( $provider );
		$this->assertSame( self::provider_name(), $provider->id() );
		$this->assertSame( array( 'en', 'fr', 'es' ), $provider->get_languages() );
		$this->assertSame( 'en', $provider->get_default_language() );
	}

	public function test_group_and_source_resolution(): void {
		$group    = $this->create_group();
		$provider = $this->container()->provider();

		$this->assertSame( $group, $provider->get_group( $group['fr'] ) );
		$this->assertSame( $group['en'], $provider->get_source( $group['es'] ) );
		$this->assertSame( 'fr', $provider->get_language( $group['fr'] ) );
	}
}
