<?php
/**
 * Tests for SnapshotCodec.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit\Domain;

use TranslationDrift\Domain\SnapshotCodec;
use TranslationDrift\Tests\Unit\TestCase;

/**
 * @covers \TranslationDrift\Domain\SnapshotCodec
 */
final class SnapshotCodecTest extends TestCase {

	public function test_round_trip(): void {
		$codec = new SnapshotCodec();
		$value = str_repeat( "Ünïcode line [core/paragraph]\n", 200 );

		$encoded = $codec->encode( $value );

		$this->assertLessThan( strlen( $value ), strlen( $encoded ), 'Repetitive text compresses.' );
		$this->assertSame(
			array(
				'value'     => $value,
				'truncated' => false,
			),
			$codec->decode( $encoded )
		);
	}

	public function test_values_over_the_cap_are_truncated_on_a_character_boundary(): void {
		$codec   = new SnapshotCodec( 5 );
		$decoded = $codec->decode( $codec->encode( 'abcdé-more' ) );

		$this->assertTrue( $decoded['truncated'] );
		$this->assertSame( 'abcd', $decoded['value'], 'é is two bytes and would cross the cap.' );
		$this->assertTrue( mb_check_encoding( $decoded['value'], 'UTF-8' ) );
	}

	public function test_empty_value(): void {
		$codec = new SnapshotCodec();

		$this->assertSame(
			array(
				'value'     => '',
				'truncated' => false,
			),
			$codec->decode( $codec->encode( '' ) )
		);
	}

	public function test_unprefixed_and_corrupt_values_decode_safely(): void {
		$codec = new SnapshotCodec();

		$this->assertSame( 'plain', $codec->decode( 'plain' )['value'] );
		$this->assertSame( '', $codec->decode( 'z1:not-deflate' )['value'] );
	}
}
