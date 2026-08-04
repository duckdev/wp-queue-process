<?php
/**
 * Base library exception.
 *
 * Thrown for programmer-error cases such as constructing a queue
 * process without an action name. Runtime conditions (an empty queue,
 * a locked process) are signalled with return values, not exceptions.
 *
 * @link       https://github.com/foxelabs/wp-queue-process
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @author     Joel James <me@joelsays.com>
 * @since      2.0.0
 * @package    Queue
 * @subpackage Exceptions
 */

namespace FoxeLabs\Queue\Exceptions;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use Exception;

/**
 * Class QueueException.
 *
 * @since 2.0.0
 */
class QueueException extends Exception {
}
