<?php
/**
 * Base test case wiring Brain\Monkey.
 *
 * @package FoxeLabs\Queue\Tests
 */

declare( strict_types=1 );

namespace FoxeLabs\Queue\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// apply_filters: by default, return the passed value unchanged.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
