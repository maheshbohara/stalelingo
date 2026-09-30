<?php
/**
 * WPML scenarios. Run with PROVIDER=wpml when WPML is installed in the test environment.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

use Stalelingo\Domain\Status;

/**
 * @covers \Stalelingo\Providers\WpmlProvider
 */
final class WpmlTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( 'wpml' !== getenv( 'PROVIDER' ) || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$this->markTestSkipped( 'WPML is commercial and not installed here. Put its zips in ./private/wpml/ and run with PROVIDER=wpml; the WPML adapter is covered by unit tests with mocks and by bin/dev/wpml-check.php on a real site.' );
		}
	}

	public function test_provider_is_wpml(): void {
		$this->assertSame( 'wpml', $this->container()->provider()?->id() );
	}

	public function test_source_edit_flags_the_translation(): void {
		$en   = self::factory()->post->create( array( 'post_title' => 'Source' ) );
		$fr   = self::factory()->post->create( array( 'post_title' => 'Traduction' ) );
		$type = apply_filters( 'wpml_element_type', 'post' );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'    => $en,
				'element_type'  => $type,
				'trid'          => false,
				'language_code' => 'en',
			)
		);
		$trid = apply_filters( 'wpml_element_trid', null, $en, $type );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $fr,
				'element_type'         => $type,
				'trid'                 => $trid,
				'language_code'        => 'fr',
				'source_language_code' => 'en',
			)
		);

		$this->container()->sync_service()->mark_synced( $fr );
		wp_update_post(
			array(
				'ID'         => $en,
				'post_title' => 'Source changed',
			)
		);
		$this->run_jobs();

		$this->assertSame( Status::Outdated, $this->status_of( $en, 'fr' ) );
	}
}
