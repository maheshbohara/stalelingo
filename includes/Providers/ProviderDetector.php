<?php
/**
 * Multilingual plugin detection.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Detects which supported multilingual plugin is active.
 *
 * Detection must run on or after `plugins_loaded`, once the multilingual
 * plugins have defined their API.
 *
 * @since 1.0.0
 */
class ProviderDetector {

	/**
	 * Provider identifiers.
	 *
	 * @since 1.0.0
	 */
	public const POLYLANG = 'polylang';
	public const WPML     = 'wpml';

	/**
	 * Returns the providers whose APIs are loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function available(): array {
		$available = array();

		if ( $this->is_polylang_loaded() ) {
			$available[] = self::POLYLANG;
		}

		if ( $this->is_wpml_loaded() ) {
			$available[] = self::WPML;
		}

		return $available;
	}

	/**
	 * Whether Polylang is active.
	 *
	 * Polylang loads its `pll_*` API only once a language exists, so this checks
	 * the version constant. Callers of the API must still check function_exists().
	 *
	 * @since 1.0.0
	 */
	protected function is_polylang_loaded(): bool {
		return defined( 'POLYLANG_VERSION' );
	}

	/**
	 * Whether WPML is loaded.
	 *
	 * Polylang's WPML compatibility layer defines icl_* functions, so this
	 * checks WPML's own constant and class instead.
	 *
	 * @since 1.0.0
	 */
	protected function is_wpml_loaded(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress', false );
	}

	/**
	 * Returns the provider to use, or null when no supported plugin is active.
	 *
	 * @since 1.0.0
	 */
	public function detect(): ?string {
		$available = $this->available();
		$provider  = $available[0] ?? null;

		/**
		 * Filters the detected multilingual provider.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null  $provider  'polylang', 'wpml', or null when neither is active.
		 * @param list<string> $available Providers whose APIs are loaded.
		 */
		$provider = apply_filters( 'tdrift_provider', $provider, $available );

		return is_string( $provider ) && in_array( $provider, $available, true ) ? $provider : null;
	}
}
