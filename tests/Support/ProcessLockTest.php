<?php

declare( strict_types=1 );

namespace FoxeLabs\Queue\Tests\Support;

use Brain\Monkey\Functions;
use FoxeLabs\Queue\Support\ProcessLock;
use FoxeLabs\Queue\Tests\TestCase;

final class ProcessLockTest extends TestCase {

	public function test_acquire_sets_transient_with_filtered_duration(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'p_proc_queue_lock_time' === $hook ? 120 : $value;
			}
		);

		Functions\expect( 'set_site_transient' )->once()
			->with( 'p_proc_process_lock', \Mockery::type( 'string' ), 120 )
			->andReturn( true );

		( new ProcessLock( 'p_proc', 60 ) )->acquire();
	}

	public function test_release_deletes_transient(): void {
		Functions\expect( 'delete_site_transient' )->once()
			->with( 'p_proc_process_lock' )->andReturn( true );

		( new ProcessLock( 'p_proc' ) )->release();
	}

	public function test_is_locked_reflects_transient(): void {
		Functions\expect( 'get_site_transient' )->once()
			->with( 'p_proc_process_lock' )->andReturn( '0.123 456' );

		$this->assertTrue( ( new ProcessLock( 'p_proc' ) )->is_locked() );
	}

	public function test_is_locked_false_when_absent(): void {
		Functions\expect( 'get_site_transient' )->once()
			->with( 'p_proc_process_lock' )->andReturn( false );

		$this->assertFalse( ( new ProcessLock( 'p_proc' ) )->is_locked() );
	}
}
