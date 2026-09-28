<?php
/**
 * Tests for TrackedFields and Settings.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit\Services;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\TrackedFields;
use TranslationDrift\Settings;
use TranslationDrift\Tests\Unit\TestCase;

/**
 * @covers \TranslationDrift\Services\TrackedFields
 * @covers \TranslationDrift\Settings
 */
final class TrackedFieldsTest extends TestCase {

	/**
	 * @param array<string, mixed> $stored Stored option.
	 */
	private function tracked( array $stored = array() ): TrackedFields {
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => 'post',
				'page'       => 'page',
				'attachment' => 'attachment',
				'product'    => 'product',
			)
		);
		$provider = \Mockery::mock( TranslationProvider::class );
		$provider->allows( 'is_translated_post_type' )->andReturnUsing( static fn( string $type ): bool => 'product' !== $type );

		return new TrackedFields( new Settings(), $provider );
	}

	public function test_settings_merge_over_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array( 'strict' => true ) );
		$settings = new Settings();

		$this->assertTrue( $settings->strict() );
		$this->assertSame( array( 'title', 'content', 'excerpt' ), $settings->get( 'fields' ) );
		$this->assertNull( $settings->get( 'nope' ) );
	}

	public function test_corrupt_settings_fall_back_to_defaults(): void {
		Functions\when( 'get_option' )->justReturn( 'garbage' );

		$this->assertSame( Settings::defaults(), ( new Settings() )->all() );
	}

	public function test_default_post_types_exclude_attachments_and_untranslated_types(): void {
		$this->assertSame( array( 'post', 'page' ), $this->tracked()->post_types() );
		$this->assertTrue( $this->tracked()->is_tracked_post_type( 'page' ) );
		$this->assertFalse( $this->tracked()->is_tracked_post_type( 'attachment' ) );
	}

	public function test_chosen_post_types_must_be_translated(): void {
		$this->assertSame( array( 'page' ), $this->tracked( array( 'post_types' => array( 'page', 'product' ) ) )->post_types() );
	}

	public function test_fields_include_settings_fields_and_meta(): void {
		$tracked = $this->tracked(
			array(
				'fields'    => array( 'title', 'slug', 'bogus' ),
				'meta_keys' => array( 'subtitle', ' ', 'subtitle' ),
			)
		);

		$this->assertSame( array( 'title', 'slug', 'meta:subtitle' ), $tracked->fields( 'post' ) );
	}

	public function test_meta_and_field_filters(): void {
		Filters\expectApplied( 'tdrift_tracked_meta_keys' )->with( array(), 'page' )->andReturn( array( 'hero_text', 7 ) );
		Filters\expectApplied( 'tdrift_tracked_fields' )->andReturnUsing( static fn( array $fields ): array => array_merge( $fields, array( 'custom:x' ) ) );

		$this->assertSame( array( 'title', 'content', 'excerpt', 'meta:hero_text', 'custom:x' ), $this->tracked()->fields( 'page' ) );
	}
}
