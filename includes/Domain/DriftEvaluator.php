<?php
/**
 * Drift evaluation.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the field hashes stored at a sync point with the source's current hashes.
 *
 * A field counts as changed when its hash differs, including when a value was
 * added to or removed from the source (see {@see Hasher::ABSENT}). Fields that
 * were not tracked at the sync point are reported separately and are not drift,
 * so turning on a new tracked field doesn't flag every translation.
 *
 * @since 0.1.0
 */
final class DriftEvaluator {

	/**
	 * Evaluates drift.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $baseline Field hashes at the sync point.
	 * @param array<string, string> $current  Current field hashes of the source.
	 * @param array<string, mixed>  $context  Passed to the `tdrift_is_material_change` filter
	 *                                        (translation_id, source_id, lang).
	 */
	public function evaluate( array $baseline, array $current, array $context = array() ): Evaluation {
		$changed = array();

		foreach ( $baseline as $field => $hash ) {
			if ( ! array_key_exists( $field, $current ) || $current[ $field ] === $hash ) {
				continue;
			}

			/**
			 * Filters whether a changed field counts as drift.
			 *
			 * Return false to ignore changes to a field, for example one that is
			 * copied rather than translated.
			 *
			 * @since 0.1.0
			 *
			 * @param bool                 $is_material Whether the change is material. Default true.
			 * @param string               $field       Field key, e.g. 'title' or 'meta:subtitle'.
			 * @param array<string, mixed> $context     translation_id, source_id and lang when known.
			 */
			if ( apply_filters( 'tdrift_is_material_change', true, (string) $field, $context ) ) {
				$changed[] = (string) $field;
			}
		}//end foreach

		$new_fields = array_map( 'strval', array_keys( array_diff_key( $current, $baseline ) ) );

		return new Evaluation( $changed, $new_fields );
	}
}
