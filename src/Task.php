<?php
/**
 * The queue task process class.
 *
 * This class helps to execute background process
 * using WordPress' cron system. This is modified version from
 * https://github.com/deliciousbrains/wp-background-processing
 *
 * @since      1.0.0
 * @author     Joel James <me@joelsays.com>
 * @license    http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * @copyright  Copyright (c) 2021, Joel James
 * @link       https://duckdev.com/products/404-to-301/
 * @package    Queue
 * @subpackage Queue
 */

namespace DuckDev\Queue;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

/**
 * Class Task.
 *
 * @since   1.0.0
 * @package DuckDev\Queue
 * @abstract
 * @extends Async
 */
abstract class Task extends Async {

	/**
	 * Action name.
	 *
	 * @var string $action
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $action = 'background_process';

	/**
	 * Start time of current process.
	 *
	 * @var int $start_time
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $start_time = 0;

	/**
	 * Cron_hook_identifier name.
	 *
	 * @var mixed $cron_hook_identifier
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $cron_hook_identifier;

	/**
	 * Cron_interval_identifier name.
	 *
	 * @var mixed $cron_interval_identifier
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $cron_interval_identifier;

	/**
	 * Initiate new background process.
	 *
	 * Extend the async class to crete a background process.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Set identifiers.
		$this->cron_hook_identifier     = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';

		// Schedule cron tasks.
		add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_health_check' ) );
		add_filter( 'cron_schedules', array( $this, 'schedule_cron_health_check' ) );
	}

	/**
	 * Dispatch the process.
	 *
	 * Start running the queued processes.
	 *
	 * @access public
	 * @since  1.0.0
	 *
	 * @return array|WP_Error
	 */
	public function dispatch() {
		// Schedule the cron health check.
		$this->schedule_event();

		// Perform remote post.
		return parent::dispatch();
	}

	/**
	 * Push an item to the queue.
	 *
	 * @param mixed $data Data to process.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return Task $this
	 */
	public function push_to_queue( $data ) {
		$this->data[] = $data;

		return $this;
	}

	/**
	 * Set the data for queue.
	 *
	 * Use this only if you have the data in correct format.
	 * CAUTION: You should call Task::save() right after this
	 * because this will replace the data in current queue.
	 *
	 * @param mixed $data Data to process.
	 *
	 * @since  1.0.2
	 * @access public
	 *
	 * @return Task $this
	 */
	public function set_queue( $data ) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Save the process queue.
	 *
	 * @param string $group Group name.
	 *
	 * @since  1.0.0
	 * @since  1.0.1 Added group option.
	 * @access public
	 *
	 * @return Task $this
	 */
	public function save( $group = 'default' ) {
		// Generate key.
		$key = $this->generate_key();

		// Save the data to database.
		if ( ! empty( $this->data ) ) {
			// Save data.
			update_site_option( $key, $this->data );
			// Save group name too.
			update_site_option( $key . '_group', $group );
		}

		return $this;
	}

	/**
	 * Update queue data.
	 *
	 * Useful after processing one item in queue.
	 *
	 * @param string $key  Key.
	 * @param array  $data Data.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return Task $this
	 */
	public function update( $key, $data ) {
		if ( ! empty( $data ) ) {
			update_site_option( $key, $data );
		}

		return $this;
	}

	/**
	 * Delete queue data completely.
	 *
	 * @param string $key Key.
	 *
	 * @since 1.0.0
	 *
	 * @return Task $this
	 */
	public function delete( $key ) {
		delete_site_option( $key );
		delete_site_option( $key . '_group' );

		return $this;
	}

	/**
	 * Generate key for the queue items.
	 *
	 * Generates a unique key based on microtime. Queue items are
	 * given a unique key so that they can be merged upon save.
	 *
	 * @param int $length Length.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return string
	 */
	protected function generate_key( $length = 64 ) {
		$unique  = md5( microtime() . wp_rand() );
		$prepend = $this->identifier . '_batch_';

		return substr( $prepend . $unique, 0, $length );
	}

	/**
	 * Maybe process queue
	 *
	 * Checks whether data exists within the queue and that
	 * the process is not already running.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		// Background process already running.
		if ( $this->is_process_running() ) {
			wp_die();
		}

		// No data to process.
		if ( $this->is_queue_empty() ) {
			wp_die();
		}

		// Check the referrer.
		check_ajax_referer( $this->identifier, 'nonce' );

		// Handle the process.
		$this->handle();

		wp_die();
	}

	/**
	 * Check if queue is empty.
	 *
	 * Check if any batch data is available in db.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return bool
	 */
	protected function is_queue_empty() {
		global $wpdb;

		$table  = $wpdb->options;
		$column = 'option_name';

		// On multisite we store in site meta.
		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		}

		// Escape for DB.
		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		// phpcs:ignore
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s",
				$key
			)
		);

		return ! ( $count > 0 );
	}

	/**
	 * Check if process running.
	 *
	 * Check whether the current process is already running
	 * in a background process.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return bool
	 */
	protected function is_process_running() {
		// Process already running.
		if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Lock the process.
	 *
	 * Lock the process so that multiple instances can't run simultaneously.
	 * Override if applicable, but the duration should be greater than that
	 * defined in the time_exceeded() method.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return void
	 */
	protected function lock_process() {
		// Set start time of current process.
		$this->start_time = time();

		// Respect time property value.
		$lock_duration = ( property_exists( $this, 'queue_lock_time' ) ) ? $this->queue_lock_time : 60; // 1 minute

		/**
		 * Filter to modify lock duration.
		 *
		 * @param int $lock_duration Lock duration.
		 *
		 * @since 1.0.0
		 */
		$lock_duration = apply_filters( $this->identifier . '_queue_lock_time', $lock_duration );

		set_site_transient( $this->identifier . '_process_lock', microtime(), $lock_duration );
	}

	/**
	 * Unlock the process.
	 *
	 * Unlock the process so that other instances can spawn.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return Task $this
	 */
	protected function unlock_process() {
		// Delete lock transient.
		delete_site_transient( $this->identifier . '_process_lock' );

		return $this;
	}

	/**
	 * Get the first batch from the queue.
	 *
	 * Serialized data will be unserialized automatically.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return object Return the first batch from the queue.
	 */
	protected function get_batch() {
		global $wpdb;

		$table        = $wpdb->options;
		$column       = 'option_name';
		$key_column   = 'option_id';
		$value_column = 'option_value';

		// Use site meta in network.
		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		}

		// Escape for db.
		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		// phpcs:ignore
		$query = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$column} LIKE %s ORDER BY {$key_column} ASC LIMIT 1",
				$key
			)
		);

		// Create new object.
		$batch        = new stdClass();
		$batch->key   = $query->$column;
		$batch->data  = maybe_unserialize( $query->$value_column );
		$batch->group = get_site_option( $batch->key . '_group', 'default' );

		return $batch;
	}

	/**
	 * Handle the async process.
	 *
	 * Pass each queue item to the task handler, while remaining
	 * within server memory and time limit constraints.
	 * After processing an item, if the task is failed, we will add
	 * it to queue again.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return void
	 */
	protected function handle() {
		// Lock first.
		$this->lock_process();

		do {
			// Get first batch.
			$batch = $this->get_batch();

			// Process each item in batch.
			foreach ( $batch->data as $key => $value ) {
				// Run task.
				$task = $this->task( $value, $batch->group );

				// If task failed add to queue again.
				if ( false !== $task ) {
					$batch->data[ $key ] = $task;
				} else {
					// Remove processed item.
					unset( $batch->data[ $key ] );
				}

				// Batch limits reached, can not process again now.
				if ( $this->time_exceeded() || $this->memory_exceeded() ) {
					break;
				}
			}

			// Update or delete current batch.
			if ( ! empty( $batch->data ) ) {
				$this->update( $batch->key, $batch->data );
			} else {
				$this->delete( $batch->key );
			}
		} while ( ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() );

		// Now unlock.
		$this->unlock_process();

		// Start next batch or complete process.
		if ( ! $this->is_queue_empty() ) {
			$this->dispatch();
		} else {
			// Complete. Yay!
			$this->complete();
		}

		// Die please.
		wp_die();
	}

	/**
	 * Check if memory limit exceeded.
	 *
	 * Ensures the batch process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return bool
	 */
	protected function memory_exceeded() {
		$return = false;
		// 90% of max memory.
		$memory_limit = $this->get_memory_limit() * 0.9;
		// Get current usage.
		$current_memory = memory_get_usage( true );

		// If reached 90%.
		if ( $current_memory >= $memory_limit ) {
			$return = true;
		}

		/**
		 * Filter to modify the memory check logic.
		 *
		 * @param bool $return Is memory exceeded?.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( $this->identifier . '_memory_exceeded', $return );
	}

	/**
	 * Get the memory limit of server.
	 *
	 * Get the maximum memory allocated for WordPress
	 * to process.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return int
	 */
	protected function get_memory_limit() {
		// Get using init_get.
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default.
			$memory_limit = '128M';
		}

		// Fallback to 32 GB for unlimited.
		if ( ! $memory_limit || - 1 === intval( $memory_limit ) ) {
			// Unlimited, set to 32 GB.
			$memory_limit = '32000M';
		}

		return wp_convert_hr_to_bytes( $memory_limit );
	}

	/**
	 * Check if time exceeded.
	 *
	 * Ensures the batch never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return bool
	 */
	protected function time_exceeded() {
		$return = false;

		/**
		 * Filter to modify default time limit.
		 *
		 * @param int $limit Default limit (20 seconds).
		 *
		 * @since 1.0.0
		 */
		$default = apply_filters( $this->identifier . '_default_time_limit', 20 );

		// Get the time.
		$finish = $this->start_time + $default; // 20 seconds

		// If time reached.
		if ( time() >= $finish ) {
			$return = true;
		}

		/**
		 * Filter to modify the time limit check logic.
		 *
		 * @param bool $return Is time exceeded?.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( $this->identifier . '_time_exceeded', $return );
	}

	/**
	 * Complete the background processing.
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return void
	 */
	protected function complete() {
		// Unschedule the cron health check.
		$this->clear_scheduled_event();
	}

	/**
	 * Add new 5 mins schedule for our health check.
	 *
	 * @param mixed $schedules Schedules.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return mixed
	 */
	public function schedule_cron_health_check( $schedules ) {
		$interval = 5;

		// Respect the interval property value.
		if ( property_exists( $this, 'cron_interval' ) ) {
			$interval = $this->cron_interval;
		}

		/**
		 * Filter to modify health check cron interval.
		 *
		 * @param int $limit Default limit (5 mins).
		 *
		 * @since 1.0.0
		 */
		$interval = apply_filters( $this->identifier . '_cron_interval', $interval );

		// Adds every 5 minutes to the existing schedules.
		$schedules[ $this->identifier . '_cron_interval' ] = array(
			'interval' => MINUTE_IN_SECONDS * $interval,
			// translators: %d cron interval.
			'display'  => sprintf( __( 'Every %d Minutes' ), $interval ),
		);

		return $schedules;
	}

	/**
	 * Handle cron health check.
	 *
	 * Restart the background process if not already running
	 * and data exists in the queue.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function handle_cron_health_check() {
		// Background process already running.
		if ( $this->is_process_running() ) {
			exit;
		}

		// No data to process.
		if ( $this->is_queue_empty() ) {
			$this->clear_scheduled_event();
			exit;
		}

		$this->handle();

		exit;
	}

	/**
	 * Schedule cron health check.
	 *
	 * Schedule a health checkup for every 5 mins until the
	 * task is completed.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return void
	 */
	protected function schedule_event() {
		// If not scheduled already.
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event( time(), $this->cron_interval_identifier, $this->cron_hook_identifier );
		}
	}

	/**
	 * Clear scheduled health check cron event.
	 *
	 * This is required to clean up the crons.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return void
	 */
	protected function clear_scheduled_event() {
		// Check if scheduled.
		$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

		if ( $timestamp ) {
			// Unschedule.
			wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
		}
	}

	/**
	 * Cancel the current background process.
	 *
	 * Stop processing queue items, clear cronjob and delete batch.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function cancel_process() {
		// If not empty.
		if ( ! $this->is_queue_empty() ) {
			$batch = $this->get_batch();

			// Delete the data.
			$this->delete( $batch->key );

			// Clear schedule.
			wp_clear_scheduled_hook( $this->cron_hook_identifier );
		}

	}

	/**
	 * Run the heavy task now.
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed  $item  Queue item to iterate over.
	 * @param string $group Group name of the task (Useful when processing multiple tasks).
	 *
	 * @since  1.0.0
	 * @since  1.0.1 Added group option.
	 * @access protected
	 *
	 * @return mixed
	 */
	abstract protected function task( $item, $group );
}
