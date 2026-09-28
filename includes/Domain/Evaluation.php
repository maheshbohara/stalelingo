<?php
/**
 * Result of a drift evaluation.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Which tracked fields changed between a sync point and the current source.
 *
 * @since 0.1.0
 */
final class Evaluation {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 */
	public function is_drift(): bool {
		return array() !== $this->changed_fields;
	}
}
