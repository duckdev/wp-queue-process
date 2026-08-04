<?php
/**
 * Asynchronous request base class.
 *
 * Fires a non-blocking request back to `admin-ajax.php` so a slow,
 * one-off task (sending an email, warming a cache) runs out of band
 * without holding up the response the visitor is waiting on. Consumers
 * extend this class, set a unique {@see Async::$action}, and implement
 * {@see Async::handle()}.
 *
 * Originally adapted from deliciousbrains/wp-background-processing;
 * modernised for PSR-4, typed properties, and a swappable storage
 * layer in 2.0.
 *
 * @link       https://github.com/foxelabs/wp-queue-process
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @author     Joel James <me@joelsays.com>
 * @since      1.0.0
 * @package    Queue
 */

namespace FoxeLabs\Queue;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use FoxeLabs\Queue\Exceptions\QueueException;

/**
 * Class Async.
 *
 * @since   1.0.0
 * @package FoxeLabs\Queue
 */
abstract class Async {

	/**
	 * Prefix shared by every action and option this process registers.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $prefix = 'foxelabs';

	/**
	 * Unique action name for this process.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $action = 'async_request';

	/**
	 * Fully-qualified identifier ("{prefix}_{action}").
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $identifier = '';

	/**
	 * Data dispatched with the request.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected array $data = array();

	/**
	 * Register the ajax listeners for this request.
	 *
	 * @since 1.0.0
	 *
	 * @throws QueueException When the subclass leaves the prefix or action empty.
	 */
	public function __construct() {
		if ( '' === trim( $this->prefix ) || '' === trim( $this->action ) ) {
			throw new QueueException( 'A queue process needs a non-empty $prefix and $action.' );
		}

		$this->identifier = $this->prefix . '_' . $this->action;

		add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
		add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
	}

	/**
	 * Set the data sent with the request.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Data to send.
	 *
	 * @return $this
	 */
	public function data( array $data ) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Dispatch the async request.
	 *
	 * Posts to `admin-ajax.php` with a short timeout and no blocking,
	 * so the current request returns immediately.
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error
	 */
	public function dispatch() {
		$url = add_query_arg( $this->get_query_args(), $this->get_query_url() );

		return wp_remote_post( esc_url_raw( $url ), $this->get_post_args() );
	}

	/**
	 * Query (`$_GET`) arguments for the request URL.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_query_args(): array {
		if ( property_exists( $this, 'query_args' ) ) {
			return $this->query_args;
		}

		$args = array(
			'action' => $this->identifier,
			'nonce'  => wp_create_nonce( $this->identifier ),
		);

		/**
		 * Filters the query arguments used during an async request.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args Query arguments.
		 */
		return apply_filters( $this->identifier . '_query_args', $args );
	}

	/**
	 * URL the request is dispatched to.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function get_query_url(): string {
		if ( property_exists( $this, 'query_url' ) ) {
			return $this->query_url;
		}

		$url = admin_url( 'admin-ajax.php' );

		/**
		 * Filters the URL used during an async request.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url Request URL.
		 */
		return apply_filters( $this->identifier . '_query_url', $url );
	}

	/**
	 * Post (`$_POST`) arguments for {@see wp_remote_post()}.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_post_args(): array {
		if ( property_exists( $this, 'post_args' ) ) {
			return $this->post_args;
		}

		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => $this->data,
			'cookies'   => $_COOKIE, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- forwarding the current session, not reading input.
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		);

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args Post arguments.
		 */
		return apply_filters( $this->identifier . '_post_args', $args );
	}

	/**
	 * Verify the request and hand off to the handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		check_ajax_referer( $this->identifier, 'nonce' );

		$this->handle();

		wp_die();
	}

	/**
	 * Perform the work for this request.
	 *
	 * Override to do whatever the async request is for. The dispatched
	 * data is available in `$_POST`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract protected function handle();
}
