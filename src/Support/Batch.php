<?php
/**
 * Queue batch value object.
 *
 * A batch is a single stored row in the queue: one option (or site
 * meta) entry whose value is an array of items to process, tagged
 * with a group name. Modelling it as a small immutable-ish value
 * object — instead of the bare {@see \stdClass} the original library
 * passed around — keeps the store contract typed and the process
 * loop easy to read and test.
 *
 * Pure value object: no WordPress calls.
 *
 * @link       https://github.com/foxelabs/wp-queue-process
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @author     Joel James <me@joelsays.com>
 * @since      2.0.0
 * @package    Queue
 * @subpackage Support
 */

namespace FoxeLabs\Queue\Support;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

/**
 * Class Batch.
 *
 * @since 2.0.0
 */
class Batch {

	/**
	 * Storage key the batch lives under.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public string $key;

	/**
	 * Items still to be processed in this batch.
	 *
	 * @since 2.0.0
	 *
	 * @var array
	 */
	public array $data;

	/**
	 * Group name the batch was saved under.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public string $group;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key   Storage key the batch lives under.
	 * @param array  $data  Items to process.
	 * @param string $group Optional. Group name. Default 'default'.
	 */
	public function __construct( string $key, array $data = array(), string $group = 'default' ) {
		$this->key   = $key;
		$this->data  = $data;
		$this->group = '' === $group ? 'default' : $group;
	}

	/**
	 * Whether the batch has no items left.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return empty( $this->data );
	}
}
