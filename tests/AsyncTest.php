<?php

declare( strict_types=1 );

namespace DuckDev\Queue\Tests;

use Brain\Monkey\Functions;
use DuckDev\Queue\Async;
use DuckDev\Queue\Exceptions\QueueException;

/**
 * Concrete async request used as a test double.
 */
final class FakeAsync extends Async {

	protected string $prefix = 'p';
	protected string $action = 'proc';

	public bool $handled = false;

	protected function handle() {
		$this->handled = true;
	}
}

/**
 * Async request that leaves the action empty — should be rejected.
 */
final class BadAsync extends Async {

	protected string $action = '';

	protected function handle() {}
}

final class AsyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'add_action' )->justReturn( true );
	}

	public function test_constructor_rejects_empty_action(): void {
		$this->expectException( QueueException::class );

		new BadAsync();
	}

	public function test_data_is_chainable(): void {
		$request = new FakeAsync();

		$this->assertSame( $request, $request->data( array( 'k' => 'v' ) ) );
	}

	public function test_dispatch_posts_to_admin_ajax(): void {
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'admin_url' )->justReturn( 'https://site.test/wp-admin/admin-ajax.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://site.test/wp-admin/admin-ajax.php?action=p_proc' );
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'wp_remote_post' )->once()
			->with(
				'https://site.test/wp-admin/admin-ajax.php?action=p_proc',
				\Mockery::on(
					static function ( $args ) {
						return false === $args['blocking'] && array( 'x' => 1 ) === $args['body'];
					}
				)
			)
			->andReturn( array( 'response' => 'ok' ) );

		$result = ( new FakeAsync() )->data( array( 'x' => 1 ) )->dispatch();

		$this->assertSame( array( 'response' => 'ok' ), $result );
	}

	public function test_maybe_handle_verifies_nonce_and_runs_handler(): void {
		Functions\when( 'session_write_close' )->justReturn( true );
		Functions\expect( 'check_ajax_referer' )->once()->with( 'p_proc', 'nonce' );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new WpDieException();
			}
		);

		$request = new FakeAsync();

		try {
			$request->maybe_handle();
			$this->fail( 'maybe_handle() should terminate via wp_die().' );
		} catch ( WpDieException $e ) {
			$this->assertTrue( $request->handled );
		}
	}
}
