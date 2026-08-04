<?php
/**
 * PHPUnit bootstrap.
 *
 * Defines the WordPress constants the library guards against and loads
 * the Composer autoloader. Brain\Monkey is initialised per test in
 * {@see \FoxeLabs\Queue\Tests\TestCase}.
 *
 * @package FoxeLabs\Queue\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/WP_Error.php';
require_once __DIR__ . '/Stubs/FakeWpdb.php';
require_once __DIR__ . '/Stubs/WpDieException.php';
require_once __DIR__ . '/TestCase.php';
