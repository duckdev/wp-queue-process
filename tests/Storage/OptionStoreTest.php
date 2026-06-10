<?php

declare( strict_types=1 );

namespace DuckDev\Queue\Tests\Storage;

use Brain\Monkey\Functions;
use DuckDev\Queue\Storage\OptionStore;
use DuckDev\Queue\Support\Batch;
use DuckDev\Queue\Tests\TestCase;
use FakeWpdb;

final class OptionStoreTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 42 );
		Functions\when( 'maybe_unserialize' )->returnArg();
	}

	private function store(): OptionStore {
		return new OptionStore( 'p_proc' );
	}

	public function test_save_persists_items_and_group(): void {
		Functions\expect( 'update_site_option' )->once()
			->with( \Mockery::type( 'string' ), array( 'a', 'b' ) )->andReturn( true );
		Functions\expect( 'update_site_option' )->once()
			->with( \Mockery::type( 'string' ), 'mails' )->andReturn( true );

		$key = $this->store()->save( array( 'a', 'b' ), 'mails' );

		$this->assertStringStartsWith( 'p_proc_batch_', $key );
	}

	public function test_save_skips_storage_for_empty_items(): void {
		Functions\expect( 'update_site_option' )->never();

		$key = $this->store()->save( array() );

		$this->assertStringStartsWith( 'p_proc_batch_', $key );
	}

	public function test_is_empty_true_when_count_zero(): void {
		$this->wpdb->var_result = 0;

		$this->assertTrue( $this->store()->is_empty() );
		$this->assertStringContainsString( 'wp_options', (string) $this->wpdb->last_query );
		$this->assertStringContainsString( 'COUNT(*)', (string) $this->wpdb->last_query );
	}

	public function test_is_empty_false_when_rows_exist(): void {
		$this->wpdb->var_result = 3;

		$this->assertFalse( $this->store()->is_empty() );
	}

	public function test_get_batch_returns_null_when_empty(): void {
		$this->wpdb->row_result = null;

		$this->assertNull( $this->store()->get_batch() );
	}

	public function test_get_batch_builds_value_object(): void {
		$this->wpdb->row_result = (object) array(
			'option_name'  => 'p_proc_batch_abc',
			'option_value' => array( 'x', 'y' ),
		);

		Functions\expect( 'get_site_option' )->once()
			->with( 'p_proc_batch_abc_group', 'default' )->andReturn( 'mails' );

		$batch = $this->store()->get_batch();

		$this->assertInstanceOf( Batch::class, $batch );
		$this->assertSame( 'p_proc_batch_abc', $batch->key );
		$this->assertSame( array( 'x', 'y' ), $batch->data );
		$this->assertSame( 'mails', $batch->group );
	}

	public function test_update_skips_empty_items(): void {
		Functions\expect( 'update_site_option' )->never();

		$this->assertFalse( $this->store()->update( 'p_proc_batch_abc', array() ) );
	}

	public function test_update_writes_remaining_items(): void {
		Functions\expect( 'update_site_option' )->once()
			->with( 'p_proc_batch_abc', array( 'y' ) )->andReturn( true );

		$this->assertTrue( $this->store()->update( 'p_proc_batch_abc', array( 'y' ) ) );
	}

	public function test_delete_removes_batch_and_group(): void {
		Functions\expect( 'delete_site_option' )->once()
			->with( 'p_proc_batch_abc_group' )->andReturn( true );
		Functions\expect( 'delete_site_option' )->once()
			->with( 'p_proc_batch_abc' )->andReturn( true );

		$this->assertTrue( $this->store()->delete( 'p_proc_batch_abc' ) );
	}

	public function test_get_batch_uses_sitemeta_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$this->wpdb->row_result = (object) array(
			'meta_key'   => 'p_proc_batch_abc',
			'meta_value' => array( 'x' ),
		);

		Functions\expect( 'get_site_option' )->once()
			->with( 'p_proc_batch_abc_group', 'default' )->andReturn( 'default' );

		$batch = $this->store()->get_batch();

		$this->assertSame( 'p_proc_batch_abc', $batch->key );
		$this->assertStringContainsString( 'wp_sitemeta', (string) $this->wpdb->last_query );
	}
}
