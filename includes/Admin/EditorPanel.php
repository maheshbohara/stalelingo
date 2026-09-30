<?php
/**
 * Block editor panel assets.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Admin;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Services\TrackedFields;

/**
 * Loads the Stalelingo panel in the block editor, for tracked post types only.
 *
 * The panel itself is a `@wordpress/editor` PluginDocumentSettingPanel (see `src/editor`).
 * The classic editor gets the {@see Metabox} instead.
 *
 * @since 1.0.0
 */
class EditorPanel {

	/**
	 * Script and style handle.
	 *
	 * @since 1.0.0
	 */
	public const HANDLE = 'stalelingo-editor';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TrackedFields $tracked Tracked fields.
	 */
	public function __construct( private TrackedFields $tracked ) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the panel when a tracked post type is open in the block editor.
	 *
	 * The site editor and widget screens fire the same hook without a post type, so they get nothing.
	 *
	 * @since 1.0.0
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base || '' === $screen->post_type
			|| ! $this->tracked->is_tracked_post_type( $screen->post_type ) ) {
			return;
		}

		Assets::enqueue_bundle( self::HANDLE, 'editor/index' );
	}
}
