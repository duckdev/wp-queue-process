<?php
/**
 * Background queue process base class.
 *
 * Builds on {@see Async} to process a queue of items across multiple
 * non-blocking requests. Items pushed onto the queue are saved, then
 * worked through in batches that bail out before exhausting the
 * server's time or memory budget; each finished batch chains the next
 * instantly, and a self-healing cron restarts a stalled queue.
 *
 * Consumers extend this class, set a unique {@see Async::$action}, and
 * implement {@see Task::task()}. The persistence, load-guarding, and
 * locking concerns are delegated to collaborators that can be injected
 * (and therefore mocked) via the constructor:
 *
 *   - {@see \DuckDev\Queue\Contracts\StoreInterface}  — where batches live.
 *   - {@see \DuckDev\Queue\Support\ServerLimits}      — when to stop a batch.
 *   - {@see \DuckDev\Queue\Support\ProcessLock}       — single-worker guard.
 *
 * @link       https://github.com/duckdev/wp-queue-process
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @author     Joel James <me@joelsays.com>
 * @since      1.0.0
 * @package    Queue
 */

namespace DuckDev\Queue;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use DuckDev\Queue\Contracts\StoreInterface;
use DuckDev\Queue\Storage\OptionStore;
use DuckDev\Queue\Support\ProcessLock;
use DuckDev\Queue\Support\ServerLimits;

/**
 * Class Task.
 *
 * @since   1.0.0
 * @package DuckDev\Queue
 */
abstract class Task extends Async {

	/**
	 * Action name for the process.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $action = 'background_process';

	/**
	 * Cron hook that runs the health check.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $cron_hook_identifier = '';

	/**
	 * Custom cron schedule name.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $cron_interval_identifier = '';

	/**
	 * Queue store.
	 *
	 * @since 2.0.0
	 *
	 * @var StoreInterface
	 */
	protected StoreInterface $store;

	/**
	 * Server-load guard.
	 *
	 * @since 2.0.0
	 *
	 * @var ServerLimits
	 */
	protected ServerLimits $limits;

	/**
	 * Single-worker lock.
	 *
	 * @since 2.0.0
	 *
	 * @var ProcessLock
	 */
	protected ProcessLock $lock;

	/**
	 * Wire up the process and its collaborators.
	 *
	 * All three collaborators are optional; when omitted the bundled
	 * WordPress-backed defaults are used. Pass your own to swap the
	 * storage layer or to inject mocks in tests.
	 *
	 * @since 1.0.0
	 * @since 2.0.0 Collaborators can be injected.
	 *
	 * @param StoreInterface|null $store  Optional. Queue store.
	 * @param ServerLimits|null   $limits Optional. Server-load guard.
	 * @param ProcessLock|null    $lock   Optional. Process lock.
	 */
	public function __construct(
		?StoreInterface $store = null,
		?ServerLimits $limits = null,
		?ProcessLock $lock = null
	) {
		// Sets $this->identifier from prefix + action.
		parent::__construct();

		$this->cron_hook_identifier     = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';

		$this->store  = $store ?? new OptionStore( $this->identifier );
		$this->limits = $limits ?? new ServerLimits( $this->identifier );
		$this->lock   = $lock ?? new ProcessLock( $this->identifier, $this->lock_duration() );

		add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_health_check' ) );
		add_filter( 'cron_schedules', array( $this, 'schedule_cron_health_check' ) );
	}

	/**
	 * Dispatch the process.
	 *
	 * Schedules the health-check cron, then fires the non-blocking
	 * request that starts working the queue.
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error
	 */
	public function dispatch() {
		$this->schedule_event();

		return parent::dispatch();
	}

	/**
	 * Push a single item onto the in-memory queue.
	 *
	 * Call {@see Task::save()} to persist what has been pushed.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Item to enqueue.
	 *
	 * @return $this
	 */
	public function push_to_queue( $data ) {
		$this->data[] = $data;

		return $this;
	}

	/**
	 * Replace the in-memory queue wholesale.
	 *
	 * Use only when the data is already in the correct shape. Follow
	 * it with {@see Task::save()} to persist.
	 *
	 * @since 1.0.2
	 *
	 * @param array $data Items to enqueue.
	 *
	 * @return $this
	 */
	public function set_queue( array $data ) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Persist the in-memory queue as a new batch.
	 *
	 * @since 1.0.0
	 * @since 1.0.1 Added the group option.
	 *
	 * @param string $group Optional. Group name. Default 'default'.
	 *
	 * @return $this
	 */
	public function save( string $group = 'default' ) {
		if ( ! empty( $this->data ) ) {
			$this->store->save( $this->data, $group );
			$this->data = array();
		}

		return $this;
	}

	/**
	 * Replace the items of an existing batch.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key  Batch key.
	 * @param array  $data Remaining items.
	 *
	 * @return $this
	 */
	public function update( string $key, array $data ) {
		$this->store->update( $key, $data );

		return $this;
	}

	/**
	 * Delete a batch entirely.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Batch key.
	 *
	 * @return $this
	 */
	public function delete( string $key ) {
		$this->store->delete( $key );

		return $this;
	}

	/**
	 * Start working the queue, if there is anything to do.
	 *
	 * Bails when a worker already holds the lock or the queue is
	 * empty; otherwise verifies the request and runs the batch loop.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		// Another worker is already running, or there's nothing to do.
		if ( $this->lock->is_locked() || $this->store->is_empty() ) {
			wp_die();
		}

		check_ajax_referer( $this->identifier, 'nonce' );

		$this->handle();

		wp_die();
	}

	/**
	 * Work through batches until time, memory, or the queue runs out.
	 *
	 * Each item is passed to {@see Task::task()}. A returned value is
	 * written back for another pass; `false` removes the item. When the
	 * batch loop ends, the next request is chained or the process is
	 * completed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function handle() {
		$this->lock->acquire();
		$this->limits->start();

		do {
			$batch = $this->store->get_batch();

			// Nothing left to process.
			if ( null === $batch ) {
				break;
			}

			foreach ( $batch->data as $key => $value ) {
				$result = $this->task( $value, $batch->group );

				if ( false !== $result ) {
					// Not done — keep it (possibly modified) for the next pass.
					$batch->data[ $key ] = $result;
				} else {
					unset( $batch->data[ $key ] );
				}

				// Stop part-way if we're running out of headroom.
				if ( $this->limits->exceeded() ) {
					break;
				}
			}

			// Persist what's left of the batch, or drop it when empty.
			if ( ! $batch->is_empty() ) {
				$this->store->update( $batch->key, $batch->data );
			} else {
				$this->store->delete( $batch->key );
			}
		} while ( ! $this->limits->exceeded() && ! $this->store->is_empty() );

		$this->lock->release();

		// More work remains — chain the next request. Otherwise finish.
		if ( ! $this->store->is_empty() ) {
			$this->dispatch();
		} else {
			$this->complete();
		}

		wp_die();
	}

	/**
	 * Wrap up once the queue is fully drained.
	 *
	 * Override to run any finishing work, but call `parent::complete()`
	 * so the health-check cron is cleared.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function complete() {
		$this->clear_scheduled_event();
	}

	/**
	 * Register the custom cron interval for the health check.
	 *
	 * @since 1.0.0
	 *
	 * @param array $schedules Existing cron schedules.
	 *
	 * @return array
	 */
	public function schedule_cron_health_check( $schedules ) {
		$interval = property_exists( $this, 'cron_interval' ) ? $this->cron_interval : 5;

		/**
		 * Filters the health-check cron interval, in minutes.
		 *
		 * @since 1.0.0
		 *
		 * @param int $interval Interval in minutes.
		 */
		$interval = apply_filters( $this->identifier . '_cron_interval', $interval );

		$schedules[ $this->cron_interval_identifier ] = array(
			'interval' => MINUTE_IN_SECONDS * $interval,
			// translators: %d: number of minutes between health checks.
			'display'  => sprintf( __( 'Every %d Minutes' ), $interval ),
		);

		return $schedules;
	}

	/**
	 * Restart a stalled queue from the health-check cron.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_cron_health_check() {
		// A worker is already running.
		if ( $this->lock->is_locked() ) {
			exit;
		}

		// Nothing left — clean up the cron and stop.
		if ( $this->store->is_empty() ) {
			$this->clear_scheduled_event();
			exit;
		}

		$this->handle();

		exit;
	}

	/**
	 * Cancel the process: drop the current batch and clear the cron.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function cancel_process() {
		if ( $this->store->is_empty() ) {
			return;
		}

		$batch = $this->store->get_batch();

		if ( null !== $batch ) {
			$this->store->delete( $batch->key );
		}

		wp_clear_scheduled_hook( $this->cron_hook_identifier );
	}

	/**
	 * Resolve the configured lock duration.
	 *
	 * Honours an optional `$queue_lock_time` property on the subclass.
	 *
	 * @since 2.0.0
	 *
	 * @return int
	 */
	protected function lock_duration(): int {
		return property_exists( $this, 'queue_lock_time' ) ? (int) $this->queue_lock_time : 60;
	}

	/**
	 * Schedule the health-check cron if it isn't already.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function schedule_event() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event( time(), $this->cron_interval_identifier, $this->cron_hook_identifier );
		}
	}

	/**
	 * Clear the health-check cron event.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function clear_scheduled_event() {
		$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
		}
	}

	/**
	 * Process a single queue item.
	 *
	 * Return the (optionally modified) item to push it back for another
	 * pass, or `false` to remove it from the queue.
	 *
	 * @since 1.0.0
	 * @since 1.0.1 Added the group argument.
	 *
	 * @param mixed  $item  Queue item to process.
	 * @param string $group Group name the item was saved under.
	 *
	 * @return mixed
	 */
	abstract protected function task( $item, $group );
}
