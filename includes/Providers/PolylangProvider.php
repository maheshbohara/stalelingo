<?php
/**
 * Polylang adapter.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Providers;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Settings;

/**
 * Polylang implementation of {@see TranslationProvider}, built on Polylang's documented `pll_*` API.
 *
 * Polylang treats translations as peers. The source is the post in the source
 * language (the setting, defaulting to Polylang's default language). Editors
 * can make another post the source of its group, which is stored as the
 * {@see PolylangProvider::SOURCE_META} flag on that post.
 *
 * @since 0.1.0
 */
final class PolylangProvider implements TranslationProvider {

	/**
	 * Post meta flag marking a post as the source of its group.
	 *
	 * @since 0.1.0
	 */
	public const SOURCE_META = '_tdrift_is_source';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return ProviderDetector::POLYLANG;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Polylang loads its API only once a language exists.
	 *
	 * @since 0.1.0
	 */
	public function is_ready(): bool {
		return function_exists( 'pll_languages_list' ) && array() !== $this->get_languages();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @return list<string>
	 */
	public function get_languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}

		$languages = pll_languages_list( array( 'fields' => 'slug' ) );

		return array_values( array_filter( (array) $languages, 'is_string' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function get_default_language(): string {
		$default = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : '';

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
		return function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( $post_type );
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
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}

		$group = array();
		foreach ( (array) pll_get_post_translations( $post_id ) as $lang => $id ) {
			if ( is_string( $lang ) && (int) $id > 0 ) {
				$group[ $lang ] = (int) $id;
			}
		}

		$lang = $this->get_language( $post_id );
		if ( null !== $lang && ! isset( $group[ $lang ] ) ) {
			$group[ $lang ] = $post_id;
		}

		// Polylang's order depends on which member was asked; use the site's language order instead.
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

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function get_language( int $post_id ): ?string {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return null;
		}

		$lang = pll_get_post_language( $post_id, 'slug' );

		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Any post in the group.
	 */
	public function get_source( int $post_id ): ?int {
		$group = $this->get_group( $post_id );
		if ( array() === $group ) {
			return null;
		}

		foreach ( $group as $id ) {
			if ( get_post_meta( $id, self::SOURCE_META, true ) ) {
				return $id;
			}
		}

		$lang = $this->source_language( $post_id );

		return $group[ $lang ] ?? null;
	}

	/**
	 * Language whose post is the source of the group, before per-group overrides.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Any post in the group.
	 */
	public function source_language( int $post_id ): string {
		$setting = $this->settings->get( 'source_language' );
		$lang    = is_string( $setting ) && '' !== $setting ? $setting : $this->get_default_language();

		/**
		 * Filters the source language of a translation group.
		 *
		 * @since 0.1.0
		 *
		 * @param string $lang    Language code. Default the "Source language" setting,
		 *                        or the multilingual plugin's default language.
		 * @param int    $post_id A post in the group.
		 */
		$lang = apply_filters( 'tdrift_source_language', $lang, $post_id );

		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Makes a post the source of its group, or restores the default source.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $is_source Whether the post should be the source.
	 */
	public function set_source_override( int $post_id, bool $is_source ): void {
		foreach ( $this->get_group( $post_id ) as $id ) {
			delete_post_meta( $id, self::SOURCE_META );
		}
		if ( $is_source ) {
			update_post_meta( $post_id, self::SOURCE_META, '1' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * `pll_save_post` fires after Polylang saved the language and translation links.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(int): void $callback Callback.
	 */
	public function on_translation_saved( callable $callback ): void {
		add_action(
			'pll_save_post',
			static function ( $post_id ) use ( $callback ): void {
				$callback( (int) $post_id );
			}
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
		add_action( 'pll_add_language', $run );
		add_action( 'pll_update_language', $run );
		// Core's delete_{$taxonomy} hook for Polylang's `language` taxonomy; Polylang has no delete action.
		add_action( 'delete_language', $run );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Existing translations use the core edit link. For a missing translation,
	 * Polylang's own "add translation" link is used when available; its API is
	 * undocumented and changed in 3.8, so it's only called with that signature.
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

		return $this->new_translation_url( $source_id, $lang );
	}

	/**
	 * Polylang's link to create a translation, when its API offers it.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Language code.
	 */
	private function new_translation_url( int $source_id, string $lang ): ?string {
		if ( ! function_exists( 'PLL' ) || ! defined( 'POLYLANG_VERSION' ) || version_compare( POLYLANG_VERSION, '3.8', '<' ) ) {
			return null;
		}

		$polylang = PLL();
		$post     = get_post( $source_id );
		if ( ! $post instanceof \WP_Post || ! is_object( $polylang ) || ! isset( $polylang->links, $polylang->model ) ) {
			return null;
		}
		if ( ! method_exists( $polylang->links, 'get_new_post_translation_link' ) || ! method_exists( $polylang->model, 'get_language' ) ) {
			return null;
		}

		$language = $polylang->model->get_language( $lang );
		if ( ! is_object( $language ) ) {
			return null;
		}

		// @phpstan-ignore argument.type (Polylang 3.8 changed the first parameter to WP_Post; the stubs still describe 3.7.)
		$link = $polylang->links->get_new_post_translation_link( $post, $language, 'raw' );

		return is_string( $link ) && '' !== $link ? $link : null;
	}
}
