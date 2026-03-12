<?php
/**
 * Tests for SDK OutboxDelivery integration via InMemoryOutboxStore.
 */

use Burrow\Sdk\Outbox\EventKeyGenerator;
use Burrow\Sdk\Outbox\InMemoryOutboxStore;
use Burrow\Sdk\Outbox\OutboxDelivery;
use Burrow\Sdk\Outbox\OutboxStatus;
use Burrow\Sdk\Client\BurrowClientInterface;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\Exception\TransportFailureException;
use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use PHPUnit\Framework\TestCase;

class OutboxDeliveryIntegrationTest extends TestCase {
	private function make_event( string $channel = 'forms', string $event = 'forms.submission.received', array $extra = array() ): array {
		return array_merge( array(
			'channel'   => $channel,
			'event'     => $event,
			'timestamp' => '2026-03-01T10:00:00Z',
			'source'    => 'gravity-forms',
			'projectId' => 'prj_test',
			'externalEventId' => 'sub_' . mt_rand(),
			'properties' => array( 'submissionId' => 'sub_1' ),
			'tags' => array( 'formId' => '1' ),
		), $extra );
	}

	public function test_duplicate_enqueue_dedupes() {
		$store   = new InMemoryOutboxStore();
		$client  = $this->createMock( BurrowClientInterface::class );
		$client->method( 'publishEvent' )->willReturn( new HttpResponse( 200, array(), '' ) );
		$delivery = new OutboxDelivery( $store, $client );

		$event   = $this->make_event( 'forms', 'forms.submission.received', array( 'externalEventId' => 'fixed_id' ) );
		$context = array( 'projectId' => 'prj_1', 'provider' => 'gravity-forms' );

		$first  = $delivery->enqueueEvents( array( $event ), $context );
		$second = $delivery->enqueueEvents( array( $event ), $context );

		$this->assertSame( 1, $first['enqueued'] );
		$this->assertSame( 0, $first['deduped'] );
		$this->assertSame( 0, $second['enqueued'] );
		$this->assertSame( 1, $second['deduped'] );
	}

	public function test_transient_fail_then_success() {
		$store   = new InMemoryOutboxStore();
		$client  = $this->createMock( BurrowClientInterface::class );
		$backoff = new \Burrow\Sdk\Outbox\ExponentialBackoffStrategy( 0, 1.0, 0, 0.0 );

		$call = 0;
		$client->method( 'publishEvent' )->willReturnCallback( function () use ( &$call ) {
			$call++;
			if ( $call === 1 ) {
				throw new TransportFailureException( 'connection reset' );
			}
			return new HttpResponse( 200, array(), '' );
		} );

		$delivery = new OutboxDelivery( $store, $client, 5, $backoff );
		$event    = $this->make_event( 'system', 'system.heartbeat.ping', array( 'externalEventId' => 'hb_1' ) );
		$delivery->enqueueEvents( array( $event ), array( 'projectId' => 'prj_1' ) );

		$first_flush = $delivery->flushOutbox( 50 );
		$this->assertSame( 0, $first_flush['sentCount'] );
		$this->assertSame( 1, $first_flush['retryingCount'] );

		$second_flush = $delivery->flushOutbox( 50 );
		$this->assertSame( 1, $second_flush['sentCount'] );
		$this->assertSame( 0, $second_flush['retryingCount'] );
	}

	public function test_non_retryable_fail_stops_retrying() {
		$store  = new InMemoryOutboxStore();
		$client = $this->createMock( BurrowClientInterface::class );
		$client->method( 'publishEvent' )->willThrowException(
			new UnexpectedResponseStatusException( '/api/v1/events', new HttpResponse( 400, array( 'error' => 'bad request' ), '' ) )
		);

		$delivery = new OutboxDelivery( $store, $client, 5 );
		$event    = $this->make_event( 'forms', 'forms.submission.received', array( 'externalEventId' => 'bad_1' ) );
		$delivery->enqueueEvents( array( $event ), array( 'projectId' => 'prj_1' ) );

		$flush = $delivery->flushOutbox( 50 );
		$this->assertSame( 1, $flush['failedCount'] );
		$this->assertSame( 0, $flush['retryingCount'] );

		$stats = $delivery->getOutboxStats();
		$this->assertSame( 1, $stats->failed );
		$this->assertSame( 0, $stats->retrying );
	}

	public function test_replayed_backfill_window_dedupes() {
		$store   = new InMemoryOutboxStore();
		$client  = $this->createMock( BurrowClientInterface::class );
		$client->method( 'publishEvent' )->willReturn( new HttpResponse( 200, array(), '' ) );
		$delivery = new OutboxDelivery( $store, $client );

		$events = array(
			$this->make_event( 'forms', 'forms.submission.received', array( 'externalEventId' => 'bf_1' ) ),
			$this->make_event( 'forms', 'forms.submission.received', array( 'externalEventId' => 'bf_2' ) ),
		);
		$context = array( 'projectId' => 'prj_1', 'provider' => 'gravity-forms' );

		$batch1 = $delivery->runBackfillBatch( $events, $context );
		$this->assertSame( 2, $batch1['enqueued'] );
		$this->assertSame( 0, $batch1['deduped'] );
		$this->assertTrue( $batch1['checkpointAdvanceSafe'] );

		$batch2 = $delivery->runBackfillBatch( $events, $context );
		$this->assertSame( 0, $batch2['enqueued'] );
		$this->assertSame( 2, $batch2['deduped'] );
		$this->assertTrue( $batch2['checkpointAdvanceSafe'] );
	}

	public function test_restart_recovery_of_pending_rows() {
		$store  = new InMemoryOutboxStore();
		$client = $this->createMock( BurrowClientInterface::class );
		$client->method( 'publishEvent' )->willReturn( new HttpResponse( 200, array(), '' ) );
		$delivery = new OutboxDelivery( $store, $client );

		$event = $this->make_event( 'ecommerce', 'ecommerce.order.placed', array( 'externalEventId' => 'ord_1' ) );
		$delivery->enqueueEvents( array( $event ), array( 'projectId' => 'prj_1', 'provider' => 'woocommerce' ) );

		$stats_before = $delivery->getOutboxStats();
		$this->assertSame( 1, $stats_before->pending );

		$flush = $delivery->flushOutbox( 50 );
		$this->assertSame( 1, $flush['sentCount'] );

		$stats_after = $delivery->getOutboxStats();
		$this->assertSame( 0, $stats_after->pending );
		$this->assertSame( 1, $stats_after->sentLedgerCount );
	}

	public function test_deterministic_keys_are_stable() {
		$event   = $this->make_event( 'forms', 'forms.submission.received', array( 'externalEventId' => 'stable_1' ) );
		$context = array( 'projectId' => 'prj_1', 'provider' => 'gravity-forms' );

		$key1 = EventKeyGenerator::buildDeterministic( $event, $context );
		$key2 = EventKeyGenerator::buildDeterministic( $event, $context );

		$this->assertSame( $key1['eventKey'], $key2['eventKey'] );
		$this->assertSame( 64, strlen( $key1['eventKey'] ) );
	}
}
