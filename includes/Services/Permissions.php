<?php
/**
 * Permission checks.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Capabilities;

/**
 * Who may do what.
 *
 * @since 0.1.0
 */
class Permissions {

	/**
	 * Whether a user may use the dashboard, settings and bulk actions.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function can_manage( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Capabilities::MANAGE );
	}

	/**
	 * Whether a user may see a translation's status and what changed in its source.
	 *
	 * Managers may see any translation; anyone else needs permission to edit it.
	 * The diff shows the source's tracked text, which a translator needs to update the translation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Translation (or source) post ID.
	 */
	public function can_view( int $user_id, int $post_id ): bool {
		return $user_id > 0 && $post_id > 0
			&& ( $this->can_manage( $user_id ) || user_can( $user_id, 'edit_post', $post_id ) );
	}

	/**
	 * Whether a user may mark a translation as up to date.
	 *
	 * Managers may mark any translation. Anyone else, such as a translator,
	 * needs permission to edit that translation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id        User ID.
	 * @param int $translation_id Translation post ID.
	 */
	public function can_mark_synced( int $user_id, int $translation_id ): bool {
		$allowed = $user_id > 0 && $translation_id > 0
			&& ( $this->can_manage( $user_id ) || user_can( $user_id, 'edit_post', $translation_id ) );

		/**
		 * Filters whether a user may mark a translation as up to date.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $allowed        Whether the user may.
		 * @param int  $user_id        User ID.
		 * @param int  $translation_id Translation post ID.
		 */
		return (bool) apply_filters( 'tdrift_can_mark_synced', $allowed, $user_id, $translation_id );
	}
}
