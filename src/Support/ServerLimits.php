<?php
/**
 * Server-load guard for batch processing.
 *
 * A background batch must bail out before it trips the PHP memory
 * limit or the request time limit (30s is common on shared hosting),
 * otherwise the worker is killed mid-item and the queue stalls. This
 * helper owns both checks and the "when did this batch start" clock,
 * keeping that bookkeeping out of the process loop and making the
 * thresholds unit-testable in isolation.
 *
 * Every threshold is filterable, keyed by the process identifier so
 * two processes on the same site can be tuned independently.
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
 * Class ServerLimits.
 *
 * @since 2.0.0
 */
class ServerLimits {

	/**
	 * Fraction of the memory limit a batch is allowed to use.
	 *
	 * @since 2.0.0
	 *
	 * @var float
	 */
	private const MEMORY_FACTOR = 0.9;

	/**
	 * Process identifier, used to namespace the filters.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private string $identifier;

	/**
	 * Default per-batch time budget, in seconds.
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	private int $time_limit;

	/**
	 * Unix time the current batch started.
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	private int $start_time = 0;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $identifier Process identifier.
	 * @param int    $time_limit Optional. Per-batch time budget in seconds. Default 20.
	 */
	public function __construct( string $identifier, int $time_limit = 20 ) {
		$this->identifier = $identifier;
		$this->time_limit = $time_limit;
	}

	/**
	 * Mark the start of a batch.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function start(): void {
		$this->start_time = time();
	}

	/**
	 * Whether either the time or the memory budget is spent.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function exceeded(): bool {
		return $this->time_exceeded() || $this->memory_exceeded();
	}

	/**
	 * Whether the batch has run past its time budget.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function time_exceeded(): bool {
		/**
		 * Filters the per-batch time budget, in seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param int $limit Default time budget.
		 */
		$limit = apply_filters( $this->identifier . '_default_time_limit', $this->time_limit );

		$exceeded = time() >= ( $this->start_time + $limit );

		/**
		 * Filters whether the time budget is considered exceeded.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $exceeded Is the time budget exceeded?
		 */
		return (bool) apply_filters( $this->identifier . '_time_exceeded', $exceeded );
	}

	/**
	 * Whether the batch is close to the memory limit.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function memory_exceeded(): bool {
		$ceiling  = $this->get_memory_limit() * self::MEMORY_FACTOR;
		$exceeded = memory_get_usage( true ) >= $ceiling;

		/**
		 * Filters whether the memory budget is considered exceeded.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $exceeded Is the memory budget exceeded?
		 */
		return (bool) apply_filters( $this->identifier . '_memory_exceeded', $exceeded );
	}

	/**
	 * Resolve the PHP memory limit, in bytes.
	 *
	 * Falls back to a sane default when `ini_get` is unavailable and
	 * treats "unlimited" (-1) as 32 GB so the factor maths still works.
	 *
	 * @since 2.0.0
	 *
	 * @return int
	 */
	public function get_memory_limit(): int {
		$limit = function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : '128M';

		if ( ! $limit || -1 === intval( $limit ) ) {
			// Unlimited — treat as 32 GB.
			$limit = '32000M';
		}

		return wp_convert_hr_to_bytes( $limit );
	}
}
