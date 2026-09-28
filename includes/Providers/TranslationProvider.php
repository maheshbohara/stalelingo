<?php
/**
 * Multilingual plugin adapter contract.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin needs from a multilingual plugin. The rest of the code is provider-agnostic.
 *
 * Posts only for now; the same shape works for taxonomy terms later.
 *
 * @since 0.1.0
 */
interface TranslationProvider {

	/**
	 * Provider identifier, e.g. 'polylang'.
	 *
	 * @since 0.1.0
	 */
	public function id(): string;

	/**
	 * Whether the provider's API is loaded and at least one language exists.
	 *
	 * @since 0.1.0
	 */
	public function is_ready(): bool;

	/**
	 * Language codes of the site's languages.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string>
	 */
	public function get_languages(): array;

	/**
	 * The provider's default language code.
	 *
	 * @since 0.1.0
	 */
	public function get_default_language(): string;

	/**
	 * Whether posts of this type are translated.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type.
	 */
	public function is_translated_post_type( string $post_type ): bool;

	/**
	 * The translation group of a post: post IDs keyed by language code, including the post itself.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int>
	 */
	public function get_group( int $post_id ): array;

	/**
	 * Language code of a post, or null when it has none.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function get_language( int $post_id ): ?string;

	/**
	 * ID of the source post of the post's group, or null when the group has no source.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Any post in the group.
	 */
	public function get_source( int $post_id ): ?int;

	/**
	 * Registers a callback that runs after a post and its translation links were saved.
	 *
	 * The callback receives the post ID. It fires for sources and translations alike,
	 * including a translation just created from its source.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(int): void $callback Callback.
	 */
	public function on_translation_saved( callable $callback ): void;

	/**
	 * Registers a callback that runs when the site's languages change.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): void $callback Callback.
	 */
	public function on_languages_changed( callable $callback ): void;

	/**
	 * WP_Query arguments that disable the provider's language filtering, so a query returns posts in every language.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function all_languages_query_args(): array;

	/**
	 * URL to edit the translation of a source post, or to create it when missing.
	 *
	 * Null when the current user can't, or the provider offers no such screen.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Language code.
	 */
	public function get_edit_translation_url( int $source_id, string $lang ): ?string;
}
