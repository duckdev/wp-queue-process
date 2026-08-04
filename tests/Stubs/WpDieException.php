<?php
/**
 * Thrown by the mocked wp_die() so tests can assert that execution
 * halts where the real WordPress would terminate the request.
 *
 * @package FoxeLabs\Queue\Tests
 */

declare( strict_types=1 );

namespace FoxeLabs\Queue\Tests;

class WpDieException extends \RuntimeException {
}
