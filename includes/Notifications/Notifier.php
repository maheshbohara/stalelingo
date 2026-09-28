<?php
/**
 * Email digests and immediate notifications.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Notifications;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Admin\AdminPage;
use TranslationDrift\Admin\StatusView;
use TranslationDrift\Capabilities;
use TranslationDrift\Domain\Status;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\Repositories\SyncRepository;
use TranslationDrift\Services\Repositories\SyncRow;
use TranslationDrift\Settings;

/**
 * Emails translators the outdated translations in their languages.
 *
 * - Digests go out daily or weekly (setting `digest_frequency`) on WP-Cron. Each
 *   translator (setting `translators`) gets their languages; extra recipients
 *   (setting `digest_recipients`) get every language.
 * - Immediate emails go out when a translation of a post type listed in
 *   `immediate_post_types` becomes outdated. They are sent from the background
 *   recalculation job, never while a post is being saved.
 *
 * Emails are plain text and sent with wp_mail().
 *
 * @since 0.1.0
 */
class Notifier {

	/**
	 * WP-Cron hook of the digest.
	 *
	 * @since 0.1.0
	 */
	public const DIGEST_HOOK = 'tdrift_send_digest';

	/**
	 * Most translations listed in one digest.
	 *
	 * @since 0.1.0
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Local hour the digest is sent at.
	 *
	 * @since 0.1.0
	 */
	public const DIGEST_HOUR = 8;

	/**
	 * Language names keyed by code, loaded once.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $language_names = null;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings            $settings Settings.
	 * @param TranslationProvider $provider Provider.
	 * @param SyncRepository      $sync     Sync rows.
	 */
	public function __construct(
		private Settings $settings,
		private TranslationProvider $provider,
		private SyncRepository $sync
	) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action(
			self::DIGEST_HOOK,
			function (): void {
				$this->send_digests();
			}
		);
		add_action(
			'tdrift_drift_detected',
			function ( $translation_id, $source_id, $lang ): void {
				$this->on_drift_detected( (int) $translation_id, (int) $source_id, (string) $lang );
			},
			10,
			3
		);
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'schedule' ), 20, 0 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'schedule' ), 20, 0 );
		add_action( 'admin_init', array( $this, 'schedule' ) );
	}

	/**
	 * Schedules, reschedules or removes the digest to match the frequency setting.
	 *
	 * @since 0.1.0
	 */
	public function schedule(): void {
		$frequency = (string) $this->settings->get( 'digest_frequency' );
		$event     = wp_get_scheduled_event( self::DIGEST_HOOK );

		if ( ! in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
			if ( false !== $event ) {
				wp_unschedule_hook( self::DIGEST_HOOK );
			}
			return;
		}

		if ( false !== $event && $event->schedule === $frequency ) {
			return;
		}

		wp_unschedule_hook( self::DIGEST_HOOK );
		wp_schedule_event( self::next_run(), $frequency, self::DIGEST_HOOK );
	}

	/**
	 * Next time the digest is due: DIGEST_HOUR today or tomorrow, in the site's timezone.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $now Current Unix time; defaults to now.
	 */
	public static function next_run( ?int $now = null ): int {
		$now  = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( wp_timezone() );
		$next = $now->setTime( self::DIGEST_HOUR, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Sends every digest.
	 *
	 * @since 0.1.0
	 *
	 * @return int Emails sent.
	 */
	public function send_digests(): int {
		$sent = 0;
		foreach ( $this->recipients() as $email => $langs ) {
			$rows  = $this->sync->find_with_status( array( Status::Outdated ), $langs, self::MAX_ITEMS + 1 );
			$more  = count( $rows ) > self::MAX_ITEMS;
			$items = array_map( array( $this, 'item' ), array_slice( $rows, 0, self::MAX_ITEMS ) );

			/**
			 * Filters the outdated translations listed in a digest email.
			 *
			 * Return an empty array to skip this recipient.
			 *
			 * @since 0.1.0
			 *
			 * @param list<array<string, mixed>> $items Items: translation_id, source_id, lang, language,
			 *                                          source_title, translation_title, changed_fields
			 *                                          (labels) and edit_url.
			 * @param string                     $email Recipient.
			 * @param list<string>|null          $langs Languages of the recipient; null for all.
			 */
			$items = apply_filters( 'tdrift_digest_items', $items, $email, $langs );
			$items = array_values( array_filter( (array) $items, 'is_array' ) );
			if ( array() === $items ) {
				continue;
			}

			$subject = sprintf(
				/* translators: 1: site name. 2: number of translations. */
				_n( '[%1$s] %2$d translation needs updating', '[%1$s] %2$d translations need updating', count( $items ), 'translation-drift' ),
				self::site_name(),
				count( $items )
			);
			$intro = __( 'These translations are out of date because their source changed:', 'translation-drift' );

			if ( $this->mail( $email, $subject, $intro, $items, $more, $langs ) ) {
				++$sent;
			}
		}//end foreach

		return $sent;
	}

	/**
	 * Emails the translators of a language as soon as a translation of an "immediate" post type goes out of date.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $translation_id Translation post ID.
	 * @param int    $source_id      Source post ID.
	 * @param string $lang           Language code.
	 * @return int Emails sent.
	 */
	public function on_drift_detected( int $translation_id, int $source_id, string $lang ): int {
		$immediate = array_filter( (array) $this->settings->get( 'immediate_post_types' ), 'is_string' );
		$source    = get_post( (int) $source_id );
		$row       = $this->sync->find_by_translation( (int) $translation_id );
		if ( ! $source instanceof \WP_Post || null === $row || ! in_array( $source->post_type, $immediate, true ) ) {
			return 0;
		}

		$item    = $this->item( $row );
		$subject = sprintf(
			/* translators: 1: site name. 2: post title. 3: language code, e.g. FR. */
			__( '[%1$s] Translation out of date: %2$s (%3$s)', 'translation-drift' ),
			self::site_name(),
			$item['translation_title'],
			strtoupper( (string) $lang )
		);
		$intro = __( 'This translation is now out of date because its source changed:', 'translation-drift' );

		$sent = 0;
		foreach ( $this->recipients() as $email => $langs ) {
			if ( ( null === $langs || in_array( (string) $lang, $langs, true ) ) && $this->mail( $email, $subject, $intro, array( $item ), false, $langs ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * Recipients and their languages: translators get the languages assigned to them,
	 * extra recipients get every language (null).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<string>|null> Languages keyed by email address.
	 */
	public function recipients(): array {
		$recipients = array();
		$languages  = $this->provider->get_languages();

		foreach ( (array) $this->settings->get( 'translators' ) as $lang => $users ) {
			if ( ! in_array( (string) $lang, $languages, true ) ) {
				continue;
			}
			foreach ( array_map( 'intval', (array) $users ) as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user instanceof \WP_User && is_email( $user->user_email ) ) {
					$recipients[ $user->user_email ][] = (string) $lang;
				}
			}
		}

		foreach ( (array) $this->settings->get( 'digest_recipients' ) as $email ) {
			if ( is_string( $email ) && is_email( $email ) ) {
				$recipients[ $email ] = null;
			}
		}

		return $recipients;
	}

	/**
	 * What an email says about one translation.
	 *
	 * @param SyncRow $row Sync row.
	 * @return array{translation_id: int, source_id: int, lang: string, language: string, source_title: string, translation_title: string, changed_fields: list<string>, edit_url: string}
	 */
	private function item( SyncRow $row ): array {
		$source      = get_post( $row->source_id );
		$translation = get_post( $row->translation_id );

		return array(
			'translation_id'    => $row->translation_id,
			'source_id'         => $row->source_id,
			'lang'              => $row->lang,
			'language'          => $this->language_name( $row->lang ),
			'source_title'      => $source instanceof \WP_Post ? self::plain( $source->post_title ) : '',
			'translation_title' => $translation instanceof \WP_Post ? self::plain( $translation->post_title ) : '',
			'changed_fields'    => array_map( array( StatusView::class, 'field_label' ), $row->changed_fields ),
			'edit_url'          => add_query_arg(
				array(
					'post'   => $row->translation_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			),
		);
	}

	/**
	 * Builds and sends one plain-text email.
	 *
	 * @param string                     $email   Recipient.
	 * @param string                     $subject Subject.
	 * @param string                     $intro   First line.
	 * @param list<array<string, mixed>> $items   Translations.
	 * @param bool                       $more    Whether more translations exist than listed.
	 * @param list<string>|null          $langs   Languages of the recipient; null for all.
	 */
	private function mail( string $email, string $subject, string $intro, array $items, bool $more, ?array $langs ): bool {
		$lines   = array( $intro, '' );
		$grouped = array();
		foreach ( $items as $item ) {
			$grouped[ (string) ( $item['language'] ?? '' ) ][] = $item;
		}

		foreach ( $grouped as $language => $group ) {
			$lines[] = $language;
			$lines[] = str_repeat( '-', max( 3, mb_strlen( $language ) ) );
			foreach ( $group as $item ) {
				$title   = '' !== (string) ( $item['translation_title'] ?? '' ) ? (string) $item['translation_title'] : __( '(no title)', 'translation-drift' );
				$lines[] = '* ' . $title;
				if ( '' !== (string) ( $item['source_title'] ?? '' ) ) {
					/* translators: %s: title of the source post. */
					$lines[] = '  ' . sprintf( __( 'Source: %s', 'translation-drift' ), (string) $item['source_title'] );
				}
				$fields = array_filter( (array) ( $item['changed_fields'] ?? array() ), 'is_string' );
				if ( array() !== $fields ) {
					/* translators: %s: comma-separated field names, e.g. "Title, Content". */
					$lines[] = '  ' . sprintf( __( 'Changed: %s', 'translation-drift' ), implode( ', ', $fields ) );
				}
				/* translators: %s: URL of the translation's edit screen. */
				$lines[] = '  ' . sprintf( __( 'Edit: %s', 'translation-drift' ), (string) ( $item['edit_url'] ?? '' ) );
			}
			$lines[] = '';
		}

		if ( $more ) {
			/* translators: %d: number of translations listed. */
			$lines[] = sprintf( __( 'Only the first %d are listed.', 'translation-drift' ), self::MAX_ITEMS );
		}

		$user = get_user_by( 'email', $email );
		if ( null === $langs || ( $user instanceof \WP_User && user_can( $user, Capabilities::MANAGE ) ) ) {
			/* translators: %s: URL of the Translation Drift dashboard. */
			$lines[] = sprintf( __( 'All outdated translations: %s', 'translation-drift' ), admin_url( 'tools.php?page=' . AdminPage::SLUG . '&status=outdated' ) );
		}

		$lines[] = '';
		$lines[] = null === $langs
			/* translators: %s: site name. */
			? sprintf( __( 'You receive this email because your address is listed as a digest recipient in the Translation Drift settings of %s.', 'translation-drift' ), self::site_name() )
			/* translators: 1: comma-separated language codes. 2: site name. */
			: sprintf( __( 'You receive this email because you are a translator for %1$s in the Translation Drift settings of %2$s.', 'translation-drift' ), strtoupper( implode( ', ', $langs ) ), self::site_name() );

		return wp_mail( $email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Display name of a language.
	 *
	 * @param string $lang Language code.
	 */
	private function language_name( string $lang ): string {
		if ( null === $this->language_names ) {
			$this->language_names = $this->provider->get_language_names();
		}

		return $this->language_names[ $lang ] ?? strtoupper( $lang );
	}

	/**
	 * The site name as plain text.
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Text as one line of plain text: tags stripped, entities decoded, line breaks collapsed
	 * (titles also go into email subjects).
	 *
	 * @param string $text Text.
	 */
	private static function plain( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}
}
