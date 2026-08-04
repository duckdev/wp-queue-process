<?php
/**
 * Background process lock.
 *
 * Guarantees a single worker touches a given queue at a time. The
 * lock is a short-lived site transient: while it is held, both the
 * dispatched handler and the cron health check refuse to start a
 * second pass. The duration must outlast a single batch, so it is
 * deliberately longer than the {@see ServerLimits} time budget and is
 * filterable.
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
 * Class ProcessLock.
 *
 * @since 2.0.0
 */
class ProcessLock {

	/**
	 * Process identifier, used to namespace the transient and filter.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private string $identifier;

	/**
	 * Lock duration, in seconds.
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	private int $duration;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $identifier Process identifier.
	 * @param int    $duration   Optional. Lock duration in seconds. Default 60.
	 */
	public function __construct( string $identifier, int $duration = 60 ) {
		$this->identifier = $identifier;
		$this->duration   = $duration;
	}

	/**
	 * Transient key the lock lives under.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function key(): string {
		return $this->identifier . '_process_lock';
	}

	/**
	 * Acquire the lock.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function acquire(): void {
		/**
		 * Filters the process lock duration, in seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param int $duration Lock duration.
		 */
		$duration = (int) apply_filters( $this->identifier . '_queue_lock_time', $this->duration );

		set_site_transient( $this->key(), microtime(), $duration );
	}

	/**
	 * Release the lock.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function release(): void {
		delete_site_transient( $this->key() );
	}

	/**
	 * Whether the lock is currently held.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_locked(): bool {
		return (bool) get_site_transient( $this->key() );
	}
}
