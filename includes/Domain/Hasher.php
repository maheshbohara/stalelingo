<?php
/**
 * Field hashing.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Hashes normalized field values.
 *
 * SHA-256 over a versioned prefix gives the same result on every PHP version
 * and platform. Bump {@see Hasher::VERSION} if normalization changes in a way
 * that would change hashes, so existing sync points can be migrated.
 *
 * @since 1.0.0
 */
final class Hasher {

	/**
	 * Hash format version.
	 *
	 * @since 1.0.0
	 */
	public const VERSION = 'v1';

	/**
	 * Hash stored for a field that has no value (for example, meta that doesn't exist).
	 *
	 * @since 1.0.0
	 */
	public const ABSENT = 'absent';

	/**
	 * Hashes one normalized value. Null means the field has no value.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $normalized Normalized value.
	 */
	public function hash( ?string $normalized ): string {
		if ( null === $normalized ) {
			return self::ABSENT;
		}

		return hash( 'sha256', self::VERSION . "\0" . $normalized );
	}

	/**
	 * Hashes a map of normalized values, sorted by field key.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string|null> $values Normalized values keyed by field.
	 * @return array<string, string>
	 */
	public function hash_all( array $values ): array {
		ksort( $values, SORT_STRING );

		return array_map( array( $this, 'hash' ), $values );
	}
}
