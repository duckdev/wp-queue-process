<?php
/**
 * Queue store contract.
 *
 * Abstraction over where queued batches are persisted. The default
 * implementation ({@see \DuckDev\Queue\Storage\OptionStore}) keeps
 * batches in the (network) options table — the historical behaviour
 * of this library — but consumers and tests can substitute an
 * in-memory or custom store because the process loop depends only on
 * this interface.
 *
 * @link       https://github.com/duckdev/wp-queue-process
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @author     Joel James <me@joelsays.com>
 * @since      2.0.0
 * @package    Queue
 * @subpackage Contracts
 */

namespace DuckDev\Queue\Contracts;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use DuckDev\Queue\Support\Batch;

/**
 * Interface StoreInterface.
 *
 * @since 2.0.0
 */
interface StoreInterface {

	/**
	 * Persist a new batch of items.
	 *
	 * @since 2.0.0
	 *
	 * @param array  $items Items to enqueue.
	 * @param string $group Optional. Group name. Default 'default'.
	 *
	 * @return string The generated key the batch was stored under.
	 */
	public function save( array $items, string $group = 'default' ): string;

	/**
	 * Whether the queue holds no batches.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool;

	/**
	 * Get the oldest batch in the queue.
	 *
	 * Implementations MUST return batches first-in-first-out and MUST
	 * return null when the queue is empty.
	 *
	 * @since 2.0.0
	 *
	 * @return Batch|null
	 */
	public function get_batch(): ?Batch;

	/**
	 * Replace the items stored under an existing batch key.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key   Batch key.
	 * @param array  $items Remaining items.
	 *
	 * @return bool True on success.
	 */
	public function update( string $key, array $items ): bool;

	/**
	 * Delete a batch entirely.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Batch key.
	 *
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool;
}
