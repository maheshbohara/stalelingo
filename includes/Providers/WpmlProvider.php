<?php
/**
 * WPML adapter.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * WPML implementation of {@see TranslationProvider}.
 *
 * Built on WPML's public filter API (`wpml_*` filters) and actions. WPML's
 * tables are never read or written directly. The source of a group is WPML's
 * original: the member whose `source_language_code` is null.
 *
 * @since 0.1.0
 */
final class WpmlProvider implements TranslationProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return ProviderDetector::WPML;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function is_ready(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && array() !== $this->get_languages();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @return list<string>
	 */
	public function get_languages(): array {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.
		if ( ! is_array( $languages ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', array_keys( $languages ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function get_default_language(): string {
		$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.

		return is_string( $default ) ? $default : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type.
	 */
	public function is_translated_post_type( string $post_type ): bool {
		return (bool) apply_filters( 'wpml_is_translated_post_type', false, $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int>
	 */
	public function get_group( int $post_id ): array {
		$group = array();
		foreach ( $this->translations( $post_id ) as $lang => $translation ) {
			$group[ $lang ] = (int) $translation->element_id;
		}

		return $this->in_language_order( $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function get_language( int $post_id ): ?string {
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) ) {
			return null;
		}

		$details = apply_filters(
			'wpml_element_language_details', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.
			null,
			array(
				'element_id'   => $post_id,
				'element_type' => $post_type,
			)
		);
		$code    = is_object( $details ) && isset( $details->language_code ) ? $details->language_code : null;

		return is_string( $code ) && '' !== $code ? $code : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * WPML's original is the translation without a source language.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Any post in the group.
	 */
	public function get_source( int $post_id ): ?int {
		foreach ( $this->translations( $post_id ) as $translation ) {
			$is_original = ( isset( $translation->original ) && '1' === (string) $translation->original )
				|| ( property_exists( $translation, 'source_language_code' ) && null === $translation->source_language_code );
			if ( $is_original ) {
				return (int) $translation->element_id;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * `wpml_after_save_post` fires once WPML saved the language details of a
	 * post edited in WordPress. Translations completed in WPML's Translation
	 * Editor fire `wpml_pro_translation_completed`, and duplicates fire
	 * `icl_make_duplicate`.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(int): void $callback Callback.
	 */
	public function on_translation_saved( callable $callback ): void {
		add_action(
			'wpml_after_save_post',
			static function ( $post_id ) use ( $callback ): void {
				$callback( (int) $post_id );
			}
		);
		add_action(
			'wpml_pro_translation_completed',
			static function ( $post_id ) use ( $callback ): void {
				$callback( (int) $post_id );
			}
		);
		add_action(
			'icl_make_duplicate',
			static function ( $master_id, $lang, $post_array, $duplicate_id ) use ( $callback ): void {
				$callback( (int) $duplicate_id );
			},
			10,
			4
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): void $callback Callback.
	 */
	public function on_languages_changed( callable $callback ): void {
		$run = static function () use ( $callback ): void {
			$callback();
		};
		add_action( 'wpml_update_active_languages', $run );
		add_action( 'icl_after_set_default_language', $run );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A missing translation links to WPML's own "add translation" screen:
	 * `post-new.php` with the group's `trid`, the target language and the source language.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Language code.
	 */
	public function get_edit_translation_url( int $source_id, string $lang ): ?string {
		$group = $this->get_group( $source_id );
		if ( isset( $group[ $lang ] ) ) {
			$link = get_edit_post_link( $group[ $lang ], 'raw' );

			return is_string( $link ) && '' !== $link ? $link : null;
		}

		$post_type = get_post_type( $source_id );
		$trid      = $this->trid( $source_id );
		$source    = $this->get_language( $source_id );
		if ( ! is_string( $post_type ) || null === $trid || null === $source ) {
			return null;
		}

		return add_query_arg(
			array(
				'post_type'   => $post_type,
				'trid'        => $trid,
				'lang'        => $lang,
				'source_lang' => $source,
			),
			admin_url( 'post-new.php' )
		);
	}

	/**
	 * WPML's translation-group ID of a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function trid( int $post_id ): ?int {
		$type = $this->element_type( $post_id );
		if ( null === $type ) {
			return null;
		}

		$trid = apply_filters( 'wpml_element_trid', null, $post_id, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.

		return is_numeric( $trid ) && (int) $trid > 0 ? (int) $trid : null;
	}

	/**
	 * WPML element type of a post, e.g. 'post_page'.
	 *
	 * @param int $post_id Post ID.
	 */
	private function element_type( int $post_id ): ?string {
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) ) {
			return null;
		}

		$type = apply_filters( 'wpml_element_type', $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.

		return is_string( $type ) && '' !== $type ? $type : 'post_' . $post_type;
	}

	/**
	 * The group's translations as WPML returns them, keyed by language, existing posts only.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, \stdClass>
	 */
	private function translations( int $post_id ): array {
		$trid = $this->trid( $post_id );
		$type = $this->element_type( $post_id );
		if ( null === $trid || null === $type ) {
			return array();
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter API.

		$existing = array();
		foreach ( (array) $translations as $lang => $translation ) {
			if ( is_string( $lang ) && $translation instanceof \stdClass && isset( $translation->element_id ) && (int) $translation->element_id > 0 ) {
				$existing[ $lang ] = $translation;
			}
		}

		return $existing;
	}

	/**
	 * Orders a group by the site's language order.
	 *
	 * @param array<string, int> $group Group.
	 * @return array<string, int>
	 */
	private function in_language_order( array $group ): array {
		$order = array_flip( $this->get_languages() );
		uksort(
			$group,
			static function ( string $a, string $b ) use ( $order ): int {
				$by_order = ( $order[ $a ] ?? PHP_INT_MAX ) <=> ( $order[ $b ] ?? PHP_INT_MAX );

				return 0 !== $by_order ? $by_order : strcmp( $a, $b );
			}
		);

		return $group;
	}
}
