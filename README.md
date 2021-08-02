<p align="center">
<a href="http://duckdev.com" target="_blank">
    <img width="200px" src="https://duckdev.com/wp-content/uploads/2020/12/cropped-duckdev-logo-mid.png">
</a>
</p>

# WP Queue Process

WP Queue Process can be used to fire off non-blocking asynchronous requests or as a background processing tool, allowing you to queue tasks.

* Inspired by [TechCrunch WP Asynchronous Tasks](https://github.com/techcrunch/wp-async-task).
* Forked from [WP Background Processing](https://github.com/deliciousbrains/wp-background-processing) with few extra options.

__Requires PHP 5.2+__

## Install

The recommended way to install this library in your project is by loading it through Composer:

```
composer require duckdev/wp-queue-process
```

## Usage

### Async Request

Async requests are useful for pushing slow one-off tasks such as sending emails to a background process. Once the request has been dispatched it will process in the background instantly.

Extend the `\DuckDev\Queue\Async` class:

```php
class WP_Example_Request extends \DuckDev\Queue\Async {

	/**
	 * @var string
	 */
	protected $action = 'example_request';

	/**
	 * Handle
	 *
	 * Override this method to perform any actions required
	 * during the async request.
	 */
	protected function handle() {
		// Actions to perform
	}
}
```

##### `protected $action`

Should be set to a unique name.

##### `protected function handle()`

Should contain any logic to perform during the non-blocking request. The data passed to the request will be accessible via `$_POST`.

##### Dispatching Requests

Instantiate your request:

`$this->example_request = new WP_Example_Request();`

Add data to the request if required:

`$this->example_request->data( array( 'value1' => $value1, 'value2' => $value2 ) );`

Fire off the request:

`$this->example_request->dispatch();`

Chaining is also supported:

`$this->example_request->data( array( 'data' => $data ) )->dispatch();`

### Background Process

Background processes work in a similar fashion to async requests but they allow you to queue tasks. Items pushed onto the queue will be processed in the background once the queue has been dispatched. Queues will also scale based on available server resources, so higher end servers will process more items per batch. Once a batch has completed the next batch will start instantly.

Health checks run by default every 5 minutes to ensure the queue is running when queued items exist. If the queue has failed it will be restarted.

Queues work on a first in first out basis, which allows additional items to be pushed to the queue even if it’s already processing.

Extend the `\DuckDev\Queue\Task` class:

```php
class WP_Example_Process extends \DuckDev\Queue\Task {

	/**
	 * @var string
	 */
	protected $action = 'example_process';

	/**
	 * Task
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed  $item  Queue item to iterate over
	 * @param string $group Group name of the task (Useful when performing multiple tasks).
	 *                    
	 * @return mixed
	 */
	protected function task( $item, $group ) {
		// Actions to perform

		return false;
	}

	/**
	 * Complete
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		parent::complete();

		// Show notice to user or perform some other arbitrary task...
	}
}
```

##### `protected $action`

Should be set to a unique name.

##### `protected function task( $item )`

Should contain any logic to perform on the queued item. Return `false` to remove the item from the queue or return `$item` to push it back onto the queue for further processing. If the item has been modified and is pushed back onto the queue the current state will be saved before the batch is exited.

##### `protected function complete()`

Optionally contain any logic to perform once the queue has completed.

##### Dispatching Processes

Instantiate your process:

`$this->example_process = new WP_Example_Process();`

**Note:** You must instantiate your process unconditionally. All requests should do this, even if nothing is pushed to the queue.

Push items to the queue:

```php
foreach ( $items as $item ) {
    $this->example_process->push_to_queue( $item );
}
```

Save and dispatch the queue:

`$this->example_process->save( 'my-task' ')->dispatch();`

### BasicAuth

If your site is behind BasicAuth, both async requests and background processes will fail to complete. This is because WP Background Processing relies on the [WordPress HTTP API](http://codex.wordpress.org/HTTP_API), which requires you to attach your BasicAuth credentials to requests. The easiest way to do this is using the following filter:

```php
function ddqueue_http_request_args( $r, $url ) {
	$r['headers']['Authorization'] = 'Basic ' . base64_encode( USERNAME . ':' . PASSWORD );

	return $r;
}
add_filter( 'http_request_args', 'ddqueue_http_request_args', 10, 2);
```

### Credits
* This is a forked, improved library of [WP Background Processing](https://github.com/deliciousbrains/wp-background-processing).
* Maintained by [Joel James](https://github.com/joel-james/)

### License

[GPLv2+](http://www.gnu.org/licenses/gpl-2.0.html)