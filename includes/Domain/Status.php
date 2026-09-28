<?php
/**
 * Drift status.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The drift status of one translation, as stored in `tdrift_sync.status`.
 *
 * @since 0.1.0
 */
enum Status: string {

	/**
	 * The translation matches the source at its last sync point.
	 *
	 * @since 0.1.0
	 */
	case InSync = 'in_sync';

	/**
	 * A tracked source field changed since the last sync point.
	 *
	 * @since 0.1.0
	 */
	case Outdated = 'outdated';

	/**
	 * No translation exists for an enabled language.
	 *
	 * @since 0.1.0
	 */
	case Missing = 'missing';

	/**
	 * The translation exists but has no sync point yet.
	 *
	 * @since 0.1.0
	 */
	case Untracked = 'untracked';

	/**
	 * Resolves the status of a translation.
	 *
	 * @since 0.1.0
	 *
	 * @param bool            $translation_exists Whether a translation exists for the language.
	 * @param bool            $has_sync_point     Whether the translation has a sync point.
	 * @param Evaluation|null $evaluation         Comparison of the sync point with the current source.
	 */
	public static function resolve( bool $translation_exists, bool $has_sync_point, ?Evaluation $evaluation ): self {
		if ( ! $translation_exists ) {
			return self::Missing;
		}
		if ( ! $has_sync_point || null === $evaluation ) {
			return self::Untracked;
		}

		return $evaluation->is_drift() ? self::Outdated : self::InSync;
	}
}
