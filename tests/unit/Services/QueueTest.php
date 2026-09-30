<?php
/**
 * Tests for Queue.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Services;

use Brain\Monkey\Functions;
use Stalelingo\Services\Queue;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Services\Queue
 */
final class QueueTest extends TestCase {

	public function test_uses_wp_cron_without_action_scheduler(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), 'stalelingo_x', array( 5 ), true )->andReturn( true );

		$queue = new Queue();
		$this->assertFalse( $queue->uses_action_scheduler() );
		$this->assertTrue( $queue->enqueue( 'stalelingo_x', array( 5 ) ) );
	}

	public function test_debounces_an_identical_pending_job(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertFalse( ( new Queue() )->enqueue( 'stalelingo_x', array( 5 ) ) );
	}

	public function test_uses_action_scheduler_when_initialised(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'stalelingo_x', array( 5 ), Queue::GROUP )->andReturn( 7 );
		Functions\expect( 'as_schedule_single_action' )->once()->andReturn( 8 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$queue = new Queue();
		$this->assertTrue( $queue->uses_action_scheduler() );
		$this->assertTrue( $queue->enqueue( 'stalelingo_x', array( 5 ) ) );
		$this->assertTrue( $queue->enqueue( 'stalelingo_y', array(), 30 ) );
	}
}
