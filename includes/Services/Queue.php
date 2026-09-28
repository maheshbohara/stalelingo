<?php
/**
 * Background job queue.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Queues background jobs with Action Scheduler when another plugin provides it, otherwise WP-Cron.
 *
 * A job already queued with the same hook and arguments isn't queued again,
 * which debounces repeated saves of the same post.
 *
 * @since 1.0.0
 */
class Queue {

	/**
	 * Action Scheduler group.
	 *
	 * @since 1.0.0
	 */
	public const GROUP = 'translation-drift';

	/**
	 * Whether Action Scheduler is loaded and ready.
	 *
	 * @since 1.0.0
	 */
	public function uses_action_scheduler(): bool {
		/**
		 * Filters whether jobs go through Action Scheduler when it's available.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $use Default true.
		 */
		return function_exists( 'as_enqueue_async_action' )
			&& did_action( 'action_scheduler_init' ) > 0
			&& (bool) apply_filters( 'tdrift_use_action_scheduler', true );
	}

	/**
	 * Queues a job unless an identical one is already pending.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $hook  Action hook the job runs.
	 * @param list<mixed> $args  Arguments passed to the hook.
	 * @param int         $delay Seconds to wait before running.
	 * @return bool Whether a new job was queued.
	 */
	public function enqueue( string $hook, array $args = array(), int $delay = 0 ): bool {
		if ( $this->is_queued( $hook, $args ) ) {
			return false;
		}

		if ( $this->uses_action_scheduler() ) {
			$id = $delay > 0
				? as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP )
				: as_enqueue_async_action( $hook, $args, self::GROUP );

			return $id > 0;
		}

		return true === wp_schedule_single_event( time() + $delay, $hook, $args, true );
	}

	/**
	 * Whether an identical job is pending.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $hook Hook.
	 * @param list<mixed> $args Arguments.
	 */
	public function is_queued( string $hook, array $args = array() ): bool {
		if ( $this->uses_action_scheduler() ) {
			return as_has_scheduled_action( $hook, $args, self::GROUP );
		}

		return false !== wp_next_scheduled( $hook, $args );
	}
}
