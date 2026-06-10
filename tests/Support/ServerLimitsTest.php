<?php

declare( strict_types=1 );

namespace DuckDev\Queue\Tests\Support;

use Brain\Monkey\Functions;
use DuckDev\Queue\Support\ServerLimits;
use DuckDev\Queue\Tests\TestCase;

final class ServerLimitsTest extends TestCase {

	public function test_time_exceeded_tracks_the_clock(): void {
		$now = 1000;
		Functions\when( 'time' )->alias(
			static function () use ( &$now ) {
				return $now;
			}
		);

		$limits = new ServerLimits( 'p_proc', 20 );
		$limits->start();

		$this->assertFalse( $limits->time_exceeded(), 'Just started — budget intact.' );

		$now = 1025;
		$this->assertTrue( $limits->time_exceeded(), 'Past the 20s budget.' );
	}

	public function test_memory_exceeded_true_above_ninety_percent(): void {
		Functions\when( 'ini_get' )->justReturn( '1000' );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 1000 );
		Functions\when( 'memory_get_usage' )->justReturn( 950 );

		$this->assertTrue( ( new ServerLimits( 'p_proc' ) )->memory_exceeded() );
	}

	public function test_memory_exceeded_false_below_ninety_percent(): void {
		Functions\when( 'ini_get' )->justReturn( '1000' );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 1000 );
		Functions\when( 'memory_get_usage' )->justReturn( 100 );

		$this->assertFalse( ( new ServerLimits( 'p_proc' ) )->memory_exceeded() );
	}

	public function test_unlimited_memory_falls_back_to_32gb(): void {
		Functions\when( 'ini_get' )->justReturn( '-1' );
		Functions\expect( 'wp_convert_hr_to_bytes' )->once()
			->with( '32000M' )->andReturn( 34359738368 );

		$this->assertSame( 34359738368, ( new ServerLimits( 'p_proc' ) )->get_memory_limit() );
	}

	public function test_exceeded_filters_can_force_a_stop(): void {
		Functions\when( 'time' )->justReturn( 0 );
		Functions\when( 'ini_get' )->justReturn( '1000' );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 1000 );
		Functions\when( 'memory_get_usage' )->justReturn( 0 );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'p_proc_time_exceeded' === $hook ? true : $value;
			}
		);

		$limits = new ServerLimits( 'p_proc' );
		$limits->start();

		$this->assertTrue( $limits->exceeded(), 'Filter should force the loop to bail.' );
	}
}
