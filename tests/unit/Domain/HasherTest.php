<?php
/**
 * Tests for Hasher.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit\Domain;

use TranslationDrift\Domain\Hasher;
use TranslationDrift\Tests\Unit\TestCase;

/**
 * @covers \TranslationDrift\Domain\Hasher
 */
final class HasherTest extends TestCase {

	/**
	 * Reference values computed independently (Python hashlib), so a change in
	 * PHP or in the hash format would fail here.
	 */
	public function test_hashes_match_reference_vectors(): void {
		$hasher = new Hasher();

		$this->assertSame( '4eb55f44151f914751ab4a35f0398e8411ffa1908ee9d6eabd75e1ca46fd60e7', $hasher->hash( 'hello' ) );
		$this->assertSame( '213e88311c15117d2043b7f9f0aacafa05969984edba0fa47230d902de0a605c', $hasher->hash( 'Café – ünïcode' ) );
	}

	public function test_hashes_are_stable_across_calls_and_instances(): void {
		$this->assertSame( ( new Hasher() )->hash( 'same' ), ( new Hasher() )->hash( 'same' ) );
	}

	public function test_absent_differs_from_empty(): void {
		$hasher = new Hasher();

		$this->assertSame( Hasher::ABSENT, $hasher->hash( null ) );
		$this->assertNotSame( $hasher->hash( null ), $hasher->hash( '' ) );
	}

	public function test_hash_all_is_sorted_by_field(): void {
		$hashes = ( new Hasher() )->hash_all(
			array(
				'title'   => 'T',
				'content' => 'C',
				'meta:x'  => null,
			)
		);

		$this->assertSame( array( 'content', 'meta:x', 'title' ), array_keys( $hashes ) );
		$this->assertSame( Hasher::ABSENT, $hashes['meta:x'] );
	}
}
