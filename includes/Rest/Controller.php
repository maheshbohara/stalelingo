<?php
/**
 * Base REST controller.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Rest;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Services\Permissions;

/**
 * Shared namespace and permission helpers of the `tdrift/v1` routes.
 *
 * @since 1.0.0
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 */
	public const REST_NAMESPACE = 'tdrift/v1';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Permissions $permissions Permissions.
	 */
	public function __construct( protected Permissions $permissions ) {
		$this->namespace = self::REST_NAMESPACE;
	}

	/**
	 * Permission callback of the dashboard routes: the `tdrift_manage` capability.
	 *
	 * @since 1.0.0
	 *
	 * @return true|\WP_Error
	 */
	public function manage_permissions_check() {
		return $this->permissions->can_manage( get_current_user_id() ) ? true : $this->forbidden();
	}

	/**
	 * 401 when logged out, 403 when logged in.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message; defaults to a generic one.
	 */
	protected function forbidden( string $message = '' ): \WP_Error {
		return new \WP_Error(
			'rest_forbidden',
			'' === $message ? __( 'Sorry, you are not allowed to do that.', 'translation-drift' ) : $message,
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Permission check for a single post: it must exist, and the user must be allowed to view it.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	protected function post_permissions_check( int $post_id ) {
		if ( ! is_user_logged_in() ) {
			return $this->forbidden();
		}
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'translation-drift' ), array( 'status' => 404 ) );
		}

		return $this->permissions->can_view( get_current_user_id(), $post_id ) ? true : $this->forbidden();
	}

	/**
	 * Schema of a positive integer path parameter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	protected static function id_arg( string $description ): array {
		return array(
			'description' => $description,
			'type'        => 'integer',
			'minimum'     => 1,
			'required'    => true,
		);
	}
}
