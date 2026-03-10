<?php

use BurrowWP\Core\Outbox\OutboxWorker;
use BurrowWP\Core\Outbox\RetryPolicy;
use BurrowWP\Infrastructure\Http\BurrowApiClient;
use BurrowWP\Infrastructure\Persistence\WpOutboxRepository;
use PHPUnit\Framework\TestCase;

class OutboxWorkerTest extends TestCase {
	public function test_marks_sent_on_200() {
		$repo   = new TestOutboxRepository(
			array(
				array(
					'id'            => 1,
					'event_key'     => 'forms:contact:entry_1',
					'event_name'    => 'forms.submission.received',
					'payload_json'  => json_encode( array( 'event' => 'x' ) ),
					'attempt_count' => 0,
					'max_attempts'  => 6,
				),
			)
		);
		$client = new TestApiClient( array( array( 'status' => 200, 'is_retryable' => false, 'error' => '' ) ) );
		$worker = new OutboxWorker( $repo, $client, new RetryPolicy() );

		$worker->run_once( 10 );
		$this->assertSame( array( 1 ), $repo->sent_ids );
	}

	public function test_marks_retrying_on_retryable_failures() {
		$repo   = new TestOutboxRepository(
			array(
				array(
					'id'            => 2,
					'event_key'     => 'forms:contact:entry_2',
					'event_name'    => 'forms.submission.received',
					'payload_json'  => json_encode( array( 'event' => 'x' ) ),
					'attempt_count' => 1,
					'max_attempts'  => 6,
				),
			)
		);
		$client = new TestApiClient( array( array( 'status' => 503, 'is_retryable' => true, 'error' => 'HTTP 503' ) ) );
		$worker = new OutboxWorker( $repo, $client, new RetryPolicy() );

		$worker->run_once( 10 );
		$this->assertSame( array( 2 ), $repo->retry_ids );
	}

	public function test_marks_failed_on_terminal_failures() {
		$repo   = new TestOutboxRepository(
			array(
				array(
					'id'            => 3,
					'event_key'     => 'forms:contact:entry_3',
					'event_name'    => 'forms.submission.received',
					'payload_json'  => json_encode( array( 'event' => 'x' ) ),
					'attempt_count' => 5,
					'max_attempts'  => 6,
				),
			)
		);
		$client = new TestApiClient( array( array( 'status' => 400, 'is_retryable' => false, 'error' => 'HTTP 400' ) ) );
		$worker = new OutboxWorker( $repo, $client, new RetryPolicy() );

		$worker->run_once( 10 );
		$this->assertSame( array( 3 ), $repo->failed_ids );
	}
}

class TestOutboxRepository extends WpOutboxRepository {
	public $rows;
	public $sent_ids  = array();
	public $retry_ids = array();
	public $failed_ids = array();

	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function dequeue_ready( $limit = 100 ) {
		return $this->rows;
	}

	public function mark_sent( $id ) {
		$this->sent_ids[] = (int) $id;
		return true;
	}

	public function mark_retrying( $id, $attempt, $next_attempt, $last_error ) {
		$this->retry_ids[] = (int) $id;
		return true;
	}

	public function mark_failed( $id, $attempt, $last_error ) {
		$this->failed_ids[] = (int) $id;
		return true;
	}
}

class TestApiClient extends BurrowApiClient {
	private $responses;
	private $idx = 0;

	public function __construct( array $responses ) {
		$this->responses = $responses;
	}

	public function publish_event( array $payload ) {
		$response = $this->responses[ $this->idx ] ?? end( $this->responses );
		$this->idx++;
		return $response;
	}
}
