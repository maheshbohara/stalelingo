<?php
/**
 * Personal data export, erasure and privacy policy text.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Services\Repositories\EventRepository;
use TranslationDrift\Services\Repositories\SyncRepository;

/**
 * Hooks the plugin into WordPress's privacy tools.
 *
 * The only personal data the plugin stores is user IDs: who marked a translation
 * as up to date (`tdrift_sync.synced_by`) and who caused an event
 * (`tdrift_events.user_id`). The exporters list those records; the eraser
 * anonymizes them (the user ID becomes 0) so the translation history stays usable.
 *
 * @since 0.1.0
 */
final class Privacy {

	/**
	 * Records per exporter page.
	 *
	 * @since 0.1.0
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Group of the exported items.
	 *
	 * @since 0.1.0
	 */
	public const GROUP_ID = 'translation-drift';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param SyncRepository  $sync   Sync rows.
	 * @param EventRepository $events Events.
	 */
	public function __construct( private SyncRepository $sync, private EventRepository $events ) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Adds the exporters.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporters( $exporters ): array {
		$exporters = (array) $exporters;

		$exporters['translation-drift-sync']   = array(
			'exporter_friendly_name' => __( 'Translation Drift: translations marked as up to date', 'translation-drift' ),
			'callback'               => array( $this, 'export_sync' ),
		);
		$exporters['translation-drift-events'] = array(
			'exporter_friendly_name' => __( 'Translation Drift: translation history', 'translation-drift' ),
			'callback'               => array( $this, 'export_events' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_erasers( $erasers ): array {
		$erasers = (array) $erasers;

		$erasers['translation-drift'] = array(
			'eraser_friendly_name' => __( 'Translation Drift', 'translation-drift' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the translations a user marked as up to date.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export_sync( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rows = $this->sync->for_synced_by( $user->ID, self::PAGE_SIZE, ( max( 1, (int) $page ) - 1 ) * self::PAGE_SIZE );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Translation Drift', 'translation-drift' ),
				'item_id'     => 'tdrift-sync-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Translation', 'translation-drift' ),
						'value' => self::post_label( $row->translation_id, $row->lang ),
					),
					array(
						'name'  => __( 'Marked as up to date (UTC)', 'translation-drift' ),
						'value' => (string) $row->synced_at,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Exports the history events a user caused.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export_events( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rows = $this->events->for_user( $user->ID, self::PAGE_SIZE, ( max( 1, (int) $page ) - 1 ) * self::PAGE_SIZE );
		$data = array();
		foreach ( $rows as $row ) {
			$row    = (array) $row;
			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Translation Drift', 'translation-drift' ),
				'item_id'     => 'tdrift-event-' . (int) ( $row['id'] ?? 0 ),
				'data'        => array(
					array(
						'name'  => __( 'Action', 'translation-drift' ),
						'value' => self::event_label( (string) ( $row['event'] ?? '' ) ),
					),
					array(
						'name'  => __( 'Translation', 'translation-drift' ),
						'value' => self::post_label( (int) ( $row['translation_id'] ?? 0 ), (string) ( $row['lang'] ?? '' ) ),
					),
					array(
						'name'  => __( 'Date (UTC)', 'translation-drift' ),
						'value' => (string) ( $row['created_at'] ?? '' ),
					),
				),
			);
		}//end foreach

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Anonymizes a user's records: their user ID becomes 0.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused; everything is done in one pass).
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$user     = get_user_by( 'email', (string) $email );
		$count    = 0;
		$messages = array();

		if ( $user instanceof \WP_User ) {
			$count = $this->sync->anonymize_user( $user->ID ) + $this->events->anonymize_user( $user->ID );
			if ( $count > 0 ) {
				$messages[] = sprintf(
					/* translators: %d: number of records. */
					_n(
						'Translation Drift: %d record no longer names this user.',
						'Translation Drift: %d records no longer name this user.',
						$count,
						'translation-drift'
					),
					$count
				);
			}
		}

		return array(
			'items_removed'  => $count > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggests text for the site's privacy policy.
	 *
	 * @since 0.1.0
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for sites that use Translation Drift:', 'translation-drift' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When a logged-in user marks a translation as up to date, or when their action changes the status of a translation, this site stores their user ID with that record. The records are kept to show the translation history and are deleted when the related posts are deleted. Old history entries are deleted automatically after 180 days. This data is not shared with anyone outside the site.', 'translation-drift' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If translators are set up to receive email digests, their email address is used to send them the list of outdated translations in their languages.', 'translation-drift' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Translation Drift', 'translation-drift' ), wp_kses_post( $content ) );
	}

	/**
	 * A post's title and language, e.g. "Bonjour (FR, #12)".
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 */
	private static function post_label( int $post_id, string $lang ): string {
		$post  = $post_id > 0 ? get_post( $post_id ) : null;
		$title = $post instanceof \WP_Post ? html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) : __( '(deleted)', 'translation-drift' );

		/* translators: 1: post title. 2: language code, e.g. FR. 3: post ID. */
		return sprintf( __( '%1$s (%2$s, #%3$d)', 'translation-drift' ), $title, strtoupper( $lang ), $post_id );
	}

	/**
	 * Human-readable event name.
	 *
	 * @param string $event Event type.
	 */
	private static function event_label( string $event ): string {
		return match ( $event ) {
			EventRepository::MARKED_SYNCED  => __( 'Marked as up to date', 'translation-drift' ),
			EventRepository::AUTO_CLEARED   => __( 'Marked as up to date on save', 'translation-drift' ),
			EventRepository::DRIFT_DETECTED => __( 'Became outdated', 'translation-drift' ),
			EventRepository::DRIFT_RESOLVED => __( 'Became up to date again', 'translation-drift' ),
			default                         => $event,
		};
	}
}
