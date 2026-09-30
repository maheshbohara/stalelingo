<?php
/**
 * Development only: settings and helpers that keep e2e runs independent.
 *
 * The e2e tests open the same posts as different users in quick succession. Post
 * locks (150 seconds by default) would show "Someone else is editing this post"
 * dialogs, so the lock window is cut to a few seconds on the dev site.
 *
 * Requests from the e2e browser (marked with an X-Stalelingo-E2E header, see
 * playwright.config.ts) don't spawn WP-Cron. Otherwise every request can take the
 * cron lock, and a spawned run can miss the job a test just queued. Tests run jobs by
 * requesting wp-cron.php directly, which ignores DISABLE_WP_CRON. Ordinary browsing of
 * the dev site still spawns cron as usual.
 *
 * REST routes under `stalelingo-dev/v1` (administrators only) do what the Playwright
 * container can't do through the UI or WP-CLI: send the digest now, check whether a
 * recalculation is queued, and edit the seeded Elementor page the way Elementor's
 * editor saves it.
 *
 * This file lives in docker/ and is never shipped in the plugin zip.
 *
 * @package Stalelingo
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_check_post_lock_window', static fn(): int => 2 );

if ( isset( $_SERVER['HTTP_X_STALELINGO_E2E'] ) && ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

add_action(
	'rest_api_init',
	static function (): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );

		// Sends every digest now, as the scheduled job would.
		register_rest_route(
			'stalelingo-dev/v1',
			'/digest',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static fn() => array( 'sent' => \Stalelingo\Plugin::container()->notifier()->send_digests() ),
			)
		);

		// Whether a recalculation of a source is still queued.
		register_rest_route(
			'stalelingo-dev/v1',
			'/queued/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static fn( WP_REST_Request $request ) => array(
					'queued' => false !== wp_next_scheduled( \Stalelingo\Services\PostHooks::RECALC_SOURCE_HOOK, array( (int) $request['id'] ) ),
				),
			)
		);

		// Edits the seeded English Elementor page: 'style' changes a colour, 'text' changes the heading.
		register_rest_route(
			'stalelingo-dev/v1',
			'/elementor',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$container = \Stalelingo\Plugin::container();
					$provider  = $container->provider();
					$source    = 0;
					foreach ( get_posts(
						array(
							'post_type'   => 'page',
							'meta_key'    => '_stalelingo_seed_elementor', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of seeded dev posts.
							'lang'        => '',
							'fields'      => 'ids',
							'post_status' => 'any',
						)
					) as $id ) {
						if ( null !== $provider && $provider->get_source( (int) $id ) === (int) $id ) {
							$source = (int) $id;
						}
					}
					if ( 0 === $source ) {
						return new WP_Error( 'stalelingo_dev_no_page', 'The seeded Elementor page was not found.', array( 'status' => 404 ) );
					}

					$elements = json_decode( (string) get_post_meta( $source, '_elementor_data', true ), true );
					$elements = is_array( $elements ) ? $elements : array();
					$walk     = static function ( array &$items ) use ( &$walk, $request ): void {
						foreach ( $items as &$item ) {
							if ( 'heading' === ( $item['widgetType'] ?? '' ) ) {
								if ( 'text' === $request['mode'] ) {
									$item['settings']['title'] = 'Elementor landing page ' . wp_generate_password( 6, false );
								} else {
									$item['settings']['title_color'] = sprintf( '#%06x', wp_rand( 0, 0xffffff ) );
								}
							}
							if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
								$walk( $item['elements'] );
							}
						}
					};
					$walk( $elements );

					// Save as Elementor's editor does: element data, then the post's plain-text copy (save_post).
					$document = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance->documents->get( $source ) : null;
					if ( $document ) {
						$document->save( array( 'elements' => $elements ) );
					} else {
						update_post_meta( $source, '_elementor_data', wp_slash( (string) wp_json_encode( $elements ) ) );
						wp_update_post( array( 'ID' => $source ) );
					}

					$group = null === $provider ? array() : $provider->get_group( $source );

					return array(
						'source' => $source,
						'fr'     => (int) ( $group['fr'] ?? 0 ),
					);
				},
				'args'                => array(
					'mode' => array(
						'type'     => 'string',
						'enum'     => array( 'style', 'text' ),
						'required' => true,
					),
				),
			)
		);
	}
);
