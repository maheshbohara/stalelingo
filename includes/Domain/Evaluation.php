<?php
/**
 * Result of a drift evaluation.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Which tracked fields changed between a sync point and the current source.
 *
 * @since 1.0.0
 */
final class Evaluation {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $changed_fields Fields whose value changed materially.
	 * @param list<string> $new_fields     Fields tracked now but absent from the sync point (not drift).
	 */
	public function __construct(
		public readonly array $changed_fields,
		public readonly array $new_fields = array()
	) {
	}

	/**
	 * Whether the translation is out of date.
	 *
	 * @since 1.0.0
	 */
	public function is_drift(): bool {
		return array() !== $this->changed_fields;
	}
}
