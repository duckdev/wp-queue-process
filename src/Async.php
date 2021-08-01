<?php
/**
 * Asynchronous request class.
 *
 * This class helps to execute no blocking tasks with
 * WordPress. This is modified version from
 * https://github.com/deliciousbrains/wp-background-processing
 *
 * @since      1.0.0
 * @author     Joel James <me@joelsays.com>
 * @license    http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * @copyright  Copyright (c) 2021, Joel James
 * @link       https://github.com/duckdev/wp-queue-process
 * @package    Queue
 * @subpackage Async
 */

namespace DuckDev\Queue;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

/**
 * Class Async.
 *
 * @since   1.0.0
 * @abstract
 * @package DuckDev\Queue
 */
abstract class Async {

	/**
	 * Prefix for the actions.
	 *
	 * @var string $prefix
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $prefix = 'duckdev';

	/**
	 * Action name for the process.
	 *
	 * @var string $action
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $action = 'async_request';

	/**
	 * Identifier for process.
	 *
	 * @var mixed $identifier
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $identifier;

	/**
	 * Data to process.
	 *
	 * @var array $data
	 *
	 * @access protected
	 * @since  1.0.0
	 */
	protected $data = array();

	/**
	 * Initiate a new async request.
	 *
	 * @since  1.0.0
	 * @access public
	 */
	public function __construct() {
		// Set identifier.
		$this->identifier = $this->prefix . '_' . $this->action;

		// Register actions.
		add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
		add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
	}

	/**
	 * Set data used during the request
	 *
	 * @param array $data Data.
	 *
	 * @return $this
	 */
	public function data( $data ) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Dispatch the async request.
	 *
	 * Send a post request to self admin-ajax.php to process
	 * the request asynchronously.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return array|WP_Error
	 */
	public function dispatch() {
		// Prepare URL.
		$url = add_query_arg( $this->get_query_args(), $this->get_query_url() );
		// Get request arguments.
		$args = $this->get_post_args();

		return wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Get query args for request.
	 *
	 * These are the $_GET arguments for the request.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return array
	 */
	protected function get_query_args() {
		// Optionally extending classes can have a query_args property.
		if ( property_exists( $this, 'query_args' ) ) {
			return $this->query_args;
		}

		// Or set arguments.
		$args = array(
			'action' => $this->identifier,
			'nonce'  => wp_create_nonce( $this->identifier ),
		);

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param array $args Arguments for request.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( $this->identifier . '_query_args', $args );
	}

	/**
	 * Get query URL for the request.
	 *
	 * @return string
	 */
	protected function get_query_url() {
		// Optionally extending classes can have a query_url property.
		if ( property_exists( $this, 'query_url' ) ) {
			return $this->query_url;
		}

		// Or set URL.
		$url = admin_url( 'admin-ajax.php' );

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param string $url URL for the request.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( $this->identifier . '_query_url', $url );
	}

	/**
	 * Get post args for the request.
	 *
	 * These are the $_POST data available in request.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_post_args() {
		// Optionally extending classes can have a post_args property.
		if ( property_exists( $this, 'post_args' ) ) {
			return $this->post_args;
		}

		// Or set post data.
		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => $this->data,
			'cookies'   => $_COOKIE,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		);

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param array $args Post arguments.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( $this->identifier . '_post_args', $args );
	}

	/**
	 * Maybe handle the request.
	 *
	 * Check for correct nonce and pass to handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		// Verify ajax referrer.
		check_ajax_referer( $this->identifier, 'nonce' );

		// Handle the process.
		$this->handle();

		// Die please.
		wp_die();
	}

	/**
	 * Handle the action.
	 *
	 * Override this method to perform any actions required
	 * during the async request.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	abstract protected function handle();
}
