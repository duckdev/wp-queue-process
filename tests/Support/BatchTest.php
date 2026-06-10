<?php

declare( strict_types=1 );

namespace DuckDev\Queue\Tests\Support;

use DuckDev\Queue\Support\Batch;
use DuckDev\Queue\Tests\TestCase;

final class BatchTest extends TestCase {

	public function test_exposes_constructor_values(): void {
		$batch = new Batch( 'duckdev_async_batch_abc', array( 'a', 'b' ), 'mails' );

		$this->assertSame( 'duckdev_async_batch_abc', $batch->key );
		$this->assertSame( array( 'a', 'b' ), $batch->data );
		$this->assertSame( 'mails', $batch->group );
	}

	public function test_empty_group_falls_back_to_default(): void {
		$batch = new Batch( 'k', array(), '' );

		$this->assertSame( 'default', $batch->group );
	}

	public function test_is_empty_reflects_data(): void {
		$this->assertTrue( ( new Batch( 'k' ) )->is_empty() );
		$this->assertFalse( ( new Batch( 'k', array( 1 ) ) )->is_empty() );
	}
}
