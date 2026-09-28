<?php
/**
 * Email digests and immediate notifications.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Notifications\Notifier;

/**
 * @covers \TranslationDrift\Notifications\Notifier
 * @covers \TranslationDrift\Services\Repositories\SyncRepository
 */
final class NotificationsTest extends TestCase {

	/**
	 * Emails captured instead of sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture' ) );
		remove_all_filters( 'tdrift_digest_items' );
		wp_unschedule_hook( Notifier::DIGEST_HOOK );
		parent::tear_down();
	}

	/**
	 * Records an email and stops it from being sent.
	 *
	 * @param null|bool            $short_circuit Short-circuit value.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 */
	public function capture( $short_circuit, $atts ): bool {
		$this->mails[] = $atts;

		return true;
	}

	private function notifier(): Notifier {
		return $this->container()->notifier();
	}

	private function translator( string $email ): int {
		return self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => $email,
			)
		);
	}

	/**
	 * A synced EN/FR/ES group whose source title then changed, so FR and ES are outdated.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return array<string, int>
	 */
	private function outdated_group( array $args = array() ): array {
		$group = $this->create_synced_group( array( 'en', 'fr', 'es' ), $args );
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed title',
			)
		);
		$this->run_jobs();

		return $group;
	}

	/**
	 * The captured email sent to an address.
	 *
	 * @return array<string, mixed>
	 */
	private function mail_to( string $email ): array {
		foreach ( $this->mails as $mail ) {
			if ( $email === $mail['to'] ) {
				return $mail;
			}
		}
		$this->fail( "No email to {$email}." );
	}

	public function test_digest_lists_each_translators_languages(): void {
		$fr = $this->translator( 'fr@example.test' );
		$this->set_settings(
			array(
				'translators'       => array( 'fr' => array( $fr ) ),
				'digest_recipients' => array( 'boss@example.test' ),
			)
		);
		$group       = $this->outdated_group();
		$this->mails = array();

		$this->assertSame( 2, $this->notifier()->send_digests() );

		$translator = $this->mail_to( 'fr@example.test' );
		$this->assertStringContainsString( '1 translation needs updating', (string) $translator['subject'] );
		$this->assertStringContainsString( 'Title fr', (string) $translator['message'] );
		$this->assertStringNotContainsString( 'Title es', (string) $translator['message'] );
		$this->assertStringContainsString( 'Changed: Title', (string) $translator['message'] );
		$this->assertStringContainsString( 'post.php?post=' . $group['fr'] . '&action=edit', (string) $translator['message'] );
		$this->assertStringContainsString( 'translator for FR', (string) $translator['message'] );
		$this->assertStringNotContainsString( 'page=translation-drift', (string) $translator['message'], 'Translators without the capability get no dashboard link.' );

		$boss = $this->mail_to( 'boss@example.test' );
		$this->assertStringContainsString( '2 translations need updating', (string) $boss['subject'] );
		$this->assertStringContainsString( 'Title es', (string) $boss['message'] );
		$this->assertStringContainsString( 'page=translation-drift&status=outdated', (string) $boss['message'] );
	}

	public function test_no_digest_when_nothing_is_outdated(): void {
		$fr = $this->translator( 'fr@example.test' );
		$this->set_settings( array( 'translators' => array( 'fr' => array( $fr ) ) ) );
		$this->create_synced_group();

		$this->assertSame( 0, $this->notifier()->send_digests() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_digest_items_filter(): void {
		$this->set_settings( array( 'digest_recipients' => array( 'boss@example.test', 'other@example.test' ) ) );
		$this->outdated_group();
		$this->mails = array();
		add_filter(
			'tdrift_digest_items',
			static fn( array $items, string $email ): array => 'other@example.test' === $email ? array() : array_slice( $items, 0, 1 ),
			10,
			2
		);

		$this->assertSame( 1, $this->notifier()->send_digests() );
		$this->assertStringContainsString( '1 translation needs updating', (string) $this->mail_to( 'boss@example.test' )['subject'] );
	}

	public function test_schedule_follows_the_frequency_setting(): void {
		$this->set_settings( array( 'digest_frequency' => 'daily' ) );
		$this->notifier()->schedule();
		$event = wp_get_scheduled_event( Notifier::DIGEST_HOOK );
		$this->assertNotFalse( $event );
		$this->assertSame( 'daily', $event->schedule );
		$this->assertGreaterThan( time(), $event->timestamp );

		$this->set_settings( array( 'digest_frequency' => 'weekly' ) );
		$this->notifier()->schedule();
		$this->assertSame( 'weekly', wp_get_scheduled_event( Notifier::DIGEST_HOOK )->schedule );

		$this->set_settings( array( 'digest_frequency' => 'off' ) );
		$this->notifier()->schedule();
		$this->assertFalse( wp_get_scheduled_event( Notifier::DIGEST_HOOK ) );
	}

	public function test_saving_settings_reschedules_the_digest(): void {
		$this->set_settings( array( 'digest_frequency' => 'weekly' ) );

		$this->assertSame( 'weekly', wp_get_scheduled_event( Notifier::DIGEST_HOOK )->schedule, 'update_option_* runs schedule().' );
	}

	public function test_next_run_is_the_next_digest_hour_in_site_time(): void {
		update_option( 'timezone_string', 'America/Winnipeg' );
		$before = strtotime( '2026-09-28 12:00:00 UTC' ); // 07:00 in Winnipeg.
		$after  = strtotime( '2026-09-28 14:00:00 UTC' ); // 09:00 in Winnipeg.

		$this->assertSame( strtotime( '2026-09-28 13:00:00 UTC' ), Notifier::next_run( $before ), '08:00 the same day.' );
		$this->assertSame( strtotime( '2026-09-29 13:00:00 UTC' ), Notifier::next_run( $after ), '08:00 the next day.' );
		delete_option( 'timezone_string' );
	}

	public function test_immediate_email_for_listed_post_types_only(): void {
		$fr = $this->translator( 'fr@example.test' );
		$this->set_settings(
			array(
				'translators'          => array( 'fr' => array( $fr ) ),
				'immediate_post_types' => array( 'page' ),
			)
		);

		$this->outdated_group();
		$this->assertSame( array(), $this->mails, 'Posts are not listed.' );

		$this->outdated_group( array( 'post_type' => 'page' ) );
		$this->assertCount( 1, $this->mails, 'One email: only FR has a translator.' );
		$this->assertSame( 'fr@example.test', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Translation out of date: Title fr (FR)', (string) $this->mails[0]['subject'] );
	}

	public function test_deactivation_removes_the_digest_schedule(): void {
		$this->set_settings( array( 'digest_frequency' => 'daily' ) );
		$this->notifier()->schedule();

		\TranslationDrift\Deactivator::deactivate();

		$this->assertFalse( wp_get_scheduled_event( Notifier::DIGEST_HOOK ) );
	}
}
