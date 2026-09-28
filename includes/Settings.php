<?php
/**
 * Plugin settings.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `tdrift_settings` option, merged over defaults.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Option name.
	 *
	 * @since 1.0.0
	 */
	public const OPTION = 'tdrift_settings';

	/**
	 * Post fields that can be tracked.
	 *
	 * @since 1.0.0
	 */
	public const POST_FIELDS = array( 'title', 'content', 'excerpt', 'slug', 'featured_image' );

	/**
	 * Default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Empty means every public, translated post type except attachments.
			'post_types'           => array(),
			'fields'               => array( 'title', 'content', 'excerpt' ),
			'meta_keys'            => array(),
			'acf'                  => true,
			'elementor'            => true,
			// Empty means the multilingual plugin's default language.
			'source_language'      => '',
			'strict'               => false,
			'auto_clear'           => false,
			'digest_frequency'     => 'off',
			'digest_recipients'    => array(),
			// Post types whose translators are emailed as soon as a translation goes out of date.
			'immediate_post_types' => array(),
			'translators'          => array(),
			'delete_data'          => false,
		);
	}

	/**
	 * Returns all settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Returns one setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Whether strict mode is on.
	 *
	 * @since 1.0.0
	 */
	public function strict(): bool {
		return (bool) $this->get( 'strict' );
	}
}
