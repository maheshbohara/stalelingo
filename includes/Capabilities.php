<?php
/**
 * Custom capability.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo;

defined( 'ABSPATH' ) || exit;

/**
 * Grants and revokes the `stalelingo_manage` capability.
 *
 * @since 1.0.0
 */
final class Capabilities {

	/**
	 * Capability required for the dashboard, settings and bulk actions.
	 *
	 * @since 1.0.0
	 */
	public const MANAGE = 'stalelingo_manage';

	/**
	 * Returns the roles that receive the capability on activation.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public static function roles(): array {
		/**
		 * Filters the roles granted the `stalelingo_manage` capability on activation.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $roles Role slugs. Default administrator and editor.
		 */
		$roles = apply_filters( 'stalelingo_capability_roles', array( 'administrator', 'editor' ) );

		return array_values( array_filter( (array) $roles, 'is_string' ) );
	}

	/**
	 * Adds the capability to the configured roles on the current site.
	 *
	 * @since 1.0.0
	 */
	public static function grant(): void {
		foreach ( self::roles() as $slug ) {
			$role = get_role( $slug );
			if ( $role instanceof \WP_Role ) {
				$role->add_cap( self::MANAGE );
			}
		}
	}

	/**
	 * Removes the capability from every role on the current site.
	 *
	 * @since 1.0.0
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
		}
	}
}
