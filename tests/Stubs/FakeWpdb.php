<?php
/**
 * Minimal $wpdb stub for OptionStore tests.
 *
 * Records the queries it is asked to run and returns canned results,
 * so the store's SQL-shaped lookups can be exercised without a
 * database.
 *
 * @package DuckDev\Queue\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'FakeWpdb' ) ) {
	class FakeWpdb { // phpcs:ignore

		public $options  = 'wp_options';
		public $sitemeta = 'wp_sitemeta';

		/** @var mixed Value returned by get_var(). */
		public $var_result = 0;

		/** @var mixed Value returned by get_row(). */
		public $row_result = null;

		/** @var string|null Last query passed to a getter. */
		public $last_query = null;

		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		public function prepare( $query, ...$args ) {
			// Good enough for assertions: inline the args.
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%s/', (string) $arg, $query, 1 );
			}

			return $query;
		}

		public function get_var( $query ) {
			$this->last_query = $query;

			return $this->var_result;
		}

		public function get_row( $query ) {
			$this->last_query = $query;

			return $this->row_result;
		}
	}
}
