<?php
/**
 * Tests for Queue.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit\Services;

use Brain\Monkey\Functions;
use TranslationDrift\Services\Queue;
use TranslationDrift\Tests\Unit\TestCase;

/**
 * @covers \TranslationDrift\Services\Queue
 */
final class QueueTest extends TestCase {

	public function test_uses_wp_cron_without_action_scheduler(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), 'tdrift_x', array( 5 ), true )->andReturn( true );

		$queue = new Queue();
		$this->assertFalse( $queue->uses_action_scheduler() );
		$this->assertTrue( $queue->enqueue( 'tdrift_x', array( 5 ) ) );
	}

	public function test_debounces_an_identical_pending_job(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertFalse( ( new Queue() )->enqueue( 'tdrift_x', array( 5 ) ) );
	}

	public function test_uses_action_scheduler_when_initialised(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'tdrift_x', array( 5 ), Queue::GROUP )->andReturn( 7 );
		Functions\expect( 'as_schedule_single_action' )->once()->andReturn( 8 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$queue = new Queue();
		$this->assertTrue( $queue->uses_action_scheduler() );
		$this->assertTrue( $queue->enqueue( 'tdrift_x', array( 5 ) ) );
		$this->assertTrue( $queue->enqueue( 'tdrift_y', array(), 30 ) );
	}
}
