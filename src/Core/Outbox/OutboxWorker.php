<?php
/**
 * Drain outbox and deliver events to Burrow.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Outbox;

use BurrowWP\Infrastructure\Http\BurrowApiClient;
use BurrowWP\Infrastructure\Persistence\WpOutboxRepository;

class OutboxWorker {
	/**
	 * @var WpOutboxRepository
	 */
	private $outbox;

	/**
	 * @var BurrowApiClient
	 */
	private $api_client;

	/**
	 * @var RetryPolicy
	 */
	private $retry_policy;

	public function __construct( WpOutboxRepository $outbox, BurrowApiClient $api_client, RetryPolicy $retry_policy ) {
		$this->outbox       = $outbox;
		$this->api_client   = $api_client;
		$this->retry_policy = $retry_policy;
	}

	/**
	 * Process outbox rows.
	 *
	 * @param int $limit Batch limit.
	 * @return array<string,int>
	 */
	public function run_once( $limit = 100 ) {
		$rows = $this->outbox->dequeue_ready( $limit );
		$stat = array(
			'processed' => 0,
			'sent'      => 0,
			'retrying'  => 0,
			'failed'    => 0,
		);

		foreach ( $rows as $row ) {
			$stat['processed']++;
			$payload = json_decode( (string) $row['payload_json'], true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}

			$result = $this->api_client->publish_event( $payload );
			$status = (int) $result['status'];

			if ( 200 === $status || 207 === $status ) {
				$this->outbox->mark_sent( (int) $row['id'] );
				do_action(
					'burrow_delivery_log',
					array(
						'level'      => 'info',
						'type'       => 'delivery_sent',
						'outboxId'   => (int) $row['id'],
						'eventKey'   => (string) $row['event_key'],
						'eventName'  => (string) $row['event_name'],
						'statusCode' => $status,
					)
				);
				$stat['sent']++;
				continue;
			}

			$attempt = (int) $row['attempt_count'] + 1;
			$max     = max( 1, (int) $row['max_attempts'] );
			$error   = ! empty( $result['error'] ) ? (string) $result['error'] : 'Unknown delivery failure';

			if ( $attempt >= $max || empty( $result['is_retryable'] ) ) {
				$this->outbox->mark_failed( (int) $row['id'], $attempt, $error );
				do_action(
					'burrow_delivery_log',
					array(
						'level'      => 'error',
						'type'       => 'delivery_failed',
						'outboxId'   => (int) $row['id'],
						'eventKey'   => (string) $row['event_key'],
						'eventName'  => (string) $row['event_name'],
						'attempt'    => $attempt,
						'statusCode' => $status,
						'error'      => $error,
					)
				);
				$stat['failed']++;
				continue;
			}

			$next_attempt = $this->retry_policy->next_attempt_utc( $attempt );
			$this->outbox->mark_retrying( (int) $row['id'], $attempt, $next_attempt, $error );
			do_action(
				'burrow_delivery_log',
				array(
					'level'       => 'warning',
					'type'        => 'delivery_retrying',
					'outboxId'    => (int) $row['id'],
					'eventKey'    => (string) $row['event_key'],
					'eventName'   => (string) $row['event_name'],
					'attempt'     => $attempt,
					'nextAttempt' => $next_attempt,
					'statusCode'  => $status,
					'error'       => $error,
				)
			);
			$stat['retrying']++;
		}

		return $stat;
	}
}
