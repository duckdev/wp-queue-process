<p align="center">
<a href="http://duckdev.com" target="_blank">
    <img width="200px" src="https://duckdev.com/wp-content/uploads/2020/12/cropped-duckdev-logo-mid.png">
</a>
</p>

# WP Queue Process

WP Queue Process is a WordPress library for firing off non-blocking asynchronous requests and for running long jobs as a
background queue. Items pushed onto the queue are worked through in batches that bail out before exhausting the server's
time or memory budget, each finished batch chains the next instantly, and a self-healing cron restarts a stalled queue.

* Inspired by [TechCrunch WP Asynchronous Tasks](https://github.com/techcrunch/wp-async-task).
* Forked from [WP Background Processing](https://github.com/deliciousbrains/wp-background-processing), modernised with a
  swappable storage driver, a server-load guard, and a full test suite.

📖 **Full documentation:** [docs.duckdev.com/wp-libraries/wp-queue-process](https://docs.duckdev.com/wp-libraries/wp-queue-process)

## Requirements

* PHP 7.4 or higher
* WordPress 6.0+
* Composer

## Installation

```console
composer require duckdev/wp-queue-process
```

The library autoloads under the `DuckDev\Queue\` namespace via PSR-4.

## Architecture

Consumers extend one of two abstract classes — `Async` for a one-off request, `Task` for a queue. Everything else is a
collaborator that `Task` wires up for you and that you can swap or mock through the constructor. The folder layout
mirrors the namespace:

```
src/
├── Async.php                     # Abstract non-blocking request
├── Task.php                      # Abstract background queue (extends Async)
├── Contracts/
│   └── StoreInterface.php        # Where batches are persisted
├── Storage/
│   └── OptionStore.php           # Default driver: (network) options table
├── Support/
│   ├── Batch.php                 # Value object: one stored batch
│   ├── ServerLimits.php          # Time/memory budget guard
│   └── ProcessLock.php           # Single-worker lock (site transient)
└── Exceptions/
    └── QueueException.php
```

`Task` delegates persistence to a `StoreInterface`, load-guarding to `ServerLimits`, and single-worker locking to
`ProcessLock`. All three are injected through the constructor (with WordPress-backed defaults), so the batch loop can be
unit-tested without a database.

## Usage

### Async Request

Async requests are useful for pushing slow one-off tasks — sending an email, warming a cache — to a background process.
Once dispatched, the request processes immediately and out of band.

Extend `\DuckDev\Queue\Async`:

```php
class WP_Example_Request extends \DuckDev\Queue\Async {

	/**
	 * @var string Unique action name.
	 */
	protected $action = 'example_request';

	/**
	 * Perform the work. The dispatched data is available in $_POST.
	 */
	protected function handle() {
		// Actions to perform.
	}
}
```

Dispatch it (chaining is supported):

```php
$this->example_request = new WP_Example_Request();
$this->example_request->data( array( 'value1' => $value1, 'value2' => $value2 ) )->dispatch();
```

### Background Process

Background processes queue tasks and work through them in batches. Higher-end servers process more items per batch.
A health check runs by default every 5 minutes to restart the queue if it ever fails; queues are processed
first-in-first-out, so items can be pushed even while one is already running.

Extend `\DuckDev\Queue\Task`:

```php
class WP_Example_Process extends \DuckDev\Queue\Task {

	/**
	 * @var string Unique action name.
	 */
	protected $action = 'example_process';

	/**
	 * Process a single queue item.
	 *
	 * Return the (optionally modified) item to push it back for another
	 * pass, or false to remove it from the queue.
	 *
	 * @param mixed  $item  Queue item to process.
	 * @param string $group Group name the item was saved under.
	 *
	 * @return mixed
	 */
	protected function task( $item, $group ) {
		// Actions to perform.

		return false;
	}

	/**
	 * Optional. Runs once the queue is fully drained.
	 */
	protected function complete() {
		parent::complete();

		// Show a notice, log, etc.
	}
}
```

Instantiate the process **unconditionally** (every request, even when nothing is queued), push items, then save and
dispatch:

```php
$this->example_process = new WP_Example_Process();

foreach ( $items as $item ) {
	$this->example_process->push_to_queue( $item );
}

// Or set the whole queue at once when the data is already shaped correctly:
// $this->example_process->set_queue( $items );

$this->example_process->save( 'my-group' )->dispatch();
```

#### Public methods

| Method | Description |
| --- | --- |
| `push_to_queue( $item )` | Append a single item to the in-memory queue. |
| `set_queue( array $items )` | Replace the in-memory queue wholesale. |
| `save( string $group = 'default' )` | Persist the in-memory queue as a new batch. |
| `dispatch()` | Schedule the health-check cron and start processing. |
| `update( string $key, array $data )` | Replace the items of an existing batch. |
| `delete( string $key )` | Delete a batch entirely. |
| `cancel_process()` | Drop the current batch and clear the cron. |

### Swapping the storage driver

The default `OptionStore` keeps batches in the (network) options table. Pass your own `StoreInterface` — or a custom
`ServerLimits` / `ProcessLock` — to the constructor to change where batches live or how aggressively a batch runs:

```php
$process = new WP_Example_Process( new MyCustomStore( 'my_plugin_example_process' ) );
```

## Filters

Every filter is namespaced with the process identifier (`{prefix}_{action}`, e.g. `duckdev_example_process`):

| Filter | Default | Purpose |
| --- | --- | --- |
| `{id}_query_args` | action + nonce | Query args added to the dispatch URL. |
| `{id}_query_url` | `admin-ajax.php` | URL the request is dispatched to. |
| `{id}_post_args` | non-blocking POST | Arguments passed to `wp_remote_post()`. |
| `{id}_default_time_limit` | `20` | Per-batch time budget, in seconds. |
| `{id}_time_exceeded` | computed | Override whether the time budget is spent. |
| `{id}_memory_exceeded` | computed | Override whether the memory budget is spent. |
| `{id}_queue_lock_time` | `60` | Process lock duration, in seconds. |
| `{id}_cron_interval` | `5` | Health-check interval, in minutes. |

### BasicAuth

If your site is behind BasicAuth, requests rely on the [WordPress HTTP API](https://developer.wordpress.org/plugins/http-api/)
and need credentials attached:

```php
add_filter( 'http_request_args', function ( $r ) {
	$r['headers']['Authorization'] = 'Basic ' . base64_encode( USERNAME . ':' . PASSWORD );

	return $r;
} );
```

## Upgrading from 1.x

The 2.0 release is a structural rewrite. The classes you extend (`Async`, `Task`) and their public methods
(`push_to_queue()`, `set_queue()`, `save()`, `dispatch()`, `update()`, `delete()`, `cancel_process()`) and every filter
are unchanged, so most consumers only need to bump the requirement.

What changed:

* **PHP 7.4+** is now required (was 5.6); properties and signatures are typed.
* Persistence, load-guarding, and locking moved into injectable collaborators (`StoreInterface`, `ServerLimits`,
  `ProcessLock`). The internal protected helpers from 1.x (`is_queue_empty()`, `get_batch()`, `memory_exceeded()`, …)
  were removed — override `task()` / `complete()` or inject a collaborator instead.
* `set_queue()` and `save()` now type-hint their arguments.

## Development

```console
composer install
composer test     # PHPUnit (Brain\Monkey, no WordPress install needed)
composer phpcs    # WordPress Coding Standards
composer phpcbf   # Auto-fix coding standard violations
```

### Credits
* A forked, modernised library of [WP Background Processing](https://github.com/deliciousbrains/wp-background-processing).
* Maintained by [Joel James](https://github.com/joel-james/)

### License

[GPLv2+](http://www.gnu.org/licenses/gpl-2.0.html)
