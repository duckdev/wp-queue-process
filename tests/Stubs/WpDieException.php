<?php
/**
 * Thrown by the mocked wp_die() so tests can assert that execution
 * halts where the real WordPress would terminate the request.
 *
 * @package DuckDev\Queue\Tests
 */

declare( strict_types=1 );

namespace DuckDev\Queue\Tests;

class WpDieException extends \RuntimeException {
}
