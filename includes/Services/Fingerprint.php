<?php
/**
 * Fingerprint of a source post.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized values and hashes of a source post's tracked fields.
 *
 * @since 0.1.0
 */
final class Fingerprint {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|null> $values Normalized values; null when the field has no value.
	 * @param array<string, string>      $hashes Field hashes.
	 */
	public function __construct( public readonly array $values, public readonly array $hashes ) {
	}
}
