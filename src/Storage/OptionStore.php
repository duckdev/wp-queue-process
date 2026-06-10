<?php
/**
 * Option-backed queue store.
 *
 * Persists batches in the options table — or site meta on multisite —
 * using the WordPress (site) option API for writes and a direct,
 * prepared {@see \wpdb} query for the "first / any batch" lookups the
 * option API can not express. Every key is namespaced with the
 * consumer's identifier so multiple processes never collide.
 *
 * This is the default driver behind {@see \DuckDev\Queue\Task}; swap
 * it out by passing a different {@see StoreInterface} to the process
 * constructor.
 *
 * @link       https://github.com/duckdev/wp-queue-process
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 * @author     Joel James <me@joelsays.com>
 * @since      2.0.0
 * @package    Queue
 * @subpackage Storage
 */

namespace DuckDev\Queue\Storage;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use DuckDev\Queue\Contracts\StoreInterface;
use DuckDev\Queue\Support\Batch;

/**
 * Class OptionStore.
 *
 * @since 2.0.0
 */
class OptionStore implements StoreInterface {

	/**
	 * Process identifier (e.g. "prefix_action").
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private string $identifier;

	/**
	 * Key prefix every batch option shares.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private string $key_prefix;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $identifier Process identifier.
	 */
	public function __construct( string $identifier ) {
		$this->identifier = $identifier;
		$this->key_prefix = $identifier . '_batch_';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.0
	 *
	 * @param array  $items Items to enqueue.
	 * @param string $group Group name.
	 *
	 * @return string
	 */
	public function save( array $items, string $group = 'default' ): string {
		$key = $this->generate_key();

		if ( ! empty( $items ) ) {
			update_site_option( $key, $items );
			update_site_option( $key . '_group', '' === $group ? 'default' : $group );
		}

		return $key;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		global $wpdb;

		$columns = $this->table_columns();
		$key     = $wpdb->esc_like( $this->key_prefix ) . '%';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are internal, value is prepared.
				"SELECT COUNT(*) FROM {$columns['table']} WHERE {$columns['name']} LIKE %s",
				$key
			)
		);

		return ! ( $count > 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.0
	 *
	 * @return Batch|null
	 */
	public function get_batch(): ?Batch {
		global $wpdb;

		$columns = $this->table_columns();
		$key     = $wpdb->esc_like( $this->key_prefix ) . '%';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are internal, value is prepared.
				"SELECT * FROM {$columns['table']} WHERE {$columns['name']} LIKE %s ORDER BY {$columns['id']} ASC LIMIT 1",
				$key
			)
		);

		// Empty queue.
		if ( empty( $row ) ) {
			return null;
		}

		$batch_key = $row->{$columns['name']};

		return new Batch(
			$batch_key,
			(array) maybe_unserialize( $row->{$columns['value']} ),
			(string) get_site_option( $batch_key . '_group', 'default' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.0
	 *
	 * @param string $key   Batch key.
	 * @param array  $items Remaining items.
	 *
	 * @return bool
	 */
	public function update( string $key, array $items ): bool {
		if ( empty( $items ) ) {
			return false;
		}

		return (bool) update_site_option( $key, $items );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Batch key.
	 *
	 * @return bool
	 */
	public function delete( string $key ): bool {
		delete_site_option( $key . '_group' );

		return (bool) delete_site_option( $key );
	}

	/**
	 * Generate a unique key for a batch.
	 *
	 * Based on microtime so batches sort first-in-first-out and never
	 * clash when several are saved in the same request.
	 *
	 * @since 2.0.0
	 *
	 * @param int $length Maximum key length.
	 *
	 * @return string
	 */
	protected function generate_key( int $length = 64 ): string {
		$unique = md5( microtime() . wp_rand() );

		return substr( $this->key_prefix . $unique, 0, $length );
	}

	/**
	 * Resolve the table and column names for the current install.
	 *
	 * Single-site queues live in {@see \wpdb::$options}; network queues
	 * live in {@see \wpdb::$sitemeta}.
	 *
	 * @since 2.0.0
	 *
	 * @return array{table:string,name:string,id:string,value:string}
	 */
	protected function table_columns(): array {
		global $wpdb;

		if ( is_multisite() ) {
			return array(
				'table' => $wpdb->sitemeta,
				'name'  => 'meta_key',
				'id'    => 'meta_id',
				'value' => 'meta_value',
			);
		}

		return array(
			'table' => $wpdb->options,
			'name'  => 'option_name',
			'id'    => 'option_id',
			'value' => 'option_value',
		);
	}
}
