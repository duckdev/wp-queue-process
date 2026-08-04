<?php

declare( strict_types=1 );

namespace FoxeLabs\Queue\Tests;

use Brain\Monkey\Functions;
use FoxeLabs\Queue\Contracts\StoreInterface;
use FoxeLabs\Queue\Support\Batch;
use FoxeLabs\Queue\Support\ProcessLock;
use FoxeLabs\Queue\Support\ServerLimits;
use FoxeLabs\Queue\Task;
use Mockery;

/**
 * Concrete background process used as a test double.
 *
 * Records the items handed to task() and lets each test decide what
 * task() returns (false to drop, anything else to re-queue).
 */
final class FakeTask extends Task {

	protected string $prefix = 'p';
	protected string $action = 'proc';

	/** @var array Items passed to task(). */
	public array $processed = array();

	/** @var mixed Value task() should return. */
	public $task_return = false;

	protected function task( $item, $group ) {
		$this->processed[] = $item;

		return $this->task_return;
	}

	/** Expose the protected batch loop for testing. */
	public function handle_public(): void {
		$this->handle();
	}
}

final class TaskTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'session_write_close' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new WpDieException();
			}
		);
	}

	/**
	 * @return array{0:FakeTask,1:Mockery\MockInterface,2:Mockery\MockInterface,3:Mockery\MockInterface}
	 */
	private function make( Batch $batch = null ): array {
		$store  = Mockery::mock( StoreInterface::class );
		$limits = Mockery::mock( ServerLimits::class );
		$lock   = Mockery::mock( ProcessLock::class );

		$task = new FakeTask( $store, $limits, $lock );

		return array( $task, $store, $limits, $lock );
	}

	/**
	 * Run the batch loop, swallowing the wp_die() that ends it.
	 */
	private function runHandle( FakeTask $task ): void {
		try {
			$task->handle_public();
			$this->fail( 'handle() should terminate via wp_die().' );
		} catch ( WpDieException $e ) {
			// Expected — the request would die here in WordPress.
		}
	}

	public function test_handle_drops_processed_items_and_completes(): void {
		[ $task, $store, $limits, $lock ] = $this->make();

		$batch = new Batch( 'p_proc_batch_1', array( 'a', 'b' ), 'g' );

		$lock->shouldReceive( 'acquire' )->once();
		$lock->shouldReceive( 'release' )->once();
		$limits->shouldReceive( 'start' )->once();
		$limits->shouldReceive( 'exceeded' )->andReturn( false );

		$store->shouldReceive( 'get_batch' )->once()->andReturn( $batch );
		// task() returns false -> every item removed -> batch dropped.
		$store->shouldReceive( 'delete' )->once()->with( 'p_proc_batch_1' );
		$store->shouldReceive( 'update' )->never();
		// Empty after the first pass, so the loop ends and complete() runs.
		$store->shouldReceive( 'is_empty' )->andReturn( true );

		// complete() clears the cron.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$task->task_return = false;
		$this->runHandle( $task );

		$this->assertSame( array( 'a', 'b' ), $task->processed );
	}

	public function test_handle_requeues_when_task_returns_value(): void {
		[ $task, $store, $limits, $lock ] = $this->make();

		$batch = new Batch( 'p_proc_batch_1', array( 'a' ), 'g' );

		$lock->shouldReceive( 'acquire' )->once();
		$lock->shouldReceive( 'release' )->once();
		$limits->shouldReceive( 'start' )->once();
		$limits->shouldReceive( 'exceeded' )->andReturn( false );

		$store->shouldReceive( 'get_batch' )->once()->andReturn( $batch );
		// task() returns a value -> item kept -> batch updated, not deleted.
		$store->shouldReceive( 'update' )->once()->with( 'p_proc_batch_1', array( 'modified' ) );
		$store->shouldReceive( 'delete' )->never();
		$store->shouldReceive( 'is_empty' )->andReturn( true );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$task->task_return = 'modified';
		$this->runHandle( $task );

		$this->assertSame( array( 'a' ), $task->processed );
	}

	public function test_maybe_handle_bails_when_locked(): void {
		[ $task, $store, $limits, $lock ] = $this->make();

		$lock->shouldReceive( 'is_locked' )->once()->andReturn( true );
		$lock->shouldReceive( 'acquire' )->never();
		Functions\expect( 'check_ajax_referer' )->never();

		try {
			$task->maybe_handle();
			$this->fail( 'maybe_handle() should terminate via wp_die().' );
		} catch ( WpDieException $e ) {
			// task() never ran.
			$this->assertSame( array(), $task->processed );
		}
	}

	public function test_save_delegates_to_store(): void {
		[ $task, $store ] = $this->make();

		$store->shouldReceive( 'save' )->once()->with( array( 'a', 'b' ), 'g' )->andReturn( 'k' );

		$task->push_to_queue( 'a' )->push_to_queue( 'b' )->save( 'g' );

		$this->assertTrue( true );
	}

	public function test_set_queue_replaces_data(): void {
		[ $task, $store ] = $this->make();

		$store->shouldReceive( 'save' )->once()->with( array( 'x', 'y' ), 'default' )->andReturn( 'k' );

		$task->push_to_queue( 'old' )->set_queue( array( 'x', 'y' ) )->save();

		$this->assertTrue( true );
	}
}
