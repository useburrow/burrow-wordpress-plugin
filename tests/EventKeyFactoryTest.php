<?php
/**
 * Tests for SDK EventKeyGenerator deterministic key generation.
 */

use Burrow\Sdk\Outbox\EventKeyGenerator;
use PHPUnit\Framework\TestCase;

class EventKeyGeneratorTest extends TestCase {
	public function test_forms_event_produces_deterministic_key() {
		$event = array(
			'channel'         => 'forms',
			'event'           => 'forms.submission.received',
			'timestamp'       => '2026-03-01T10:00:00Z',
			'source'          => 'gravity-forms',
			'projectId'       => 'prj_1',
			'externalEventId' => 'entry_123',
		);
		$context = array( 'provider' => 'gravity-forms', 'projectId' => 'prj_1' );

		$key1 = EventKeyGenerator::buildDeterministic( $event, $context );
		$key2 = EventKeyGenerator::buildDeterministic( $event, $context );

		$this->assertSame( $key1['eventKey'], $key2['eventKey'] );
		$this->assertSame( 64, strlen( $key1['eventKey'] ) );
		$this->assertStringContainsString( 'forms', $key1['canonical'] );
	}

	public function test_ecommerce_order_key_stable() {
		$event = array(
			'channel'         => 'ecommerce',
			'event'           => 'order.placed',
			'timestamp'       => '2026-03-01T12:00:00Z',
			'source'          => 'woocommerce',
			'projectId'       => 'prj_1',
			'orderId'         => '14577',
		);
		$context = array( 'provider' => 'woocommerce', 'projectId' => 'prj_1' );

		$key = EventKeyGenerator::buildDeterministic( $event, $context );
		$this->assertSame( 64, strlen( $key['eventKey'] ) );
		$this->assertStringContainsString( 'orderId=14577', $key['canonical'] );
	}

	public function test_ecommerce_item_key_includes_line_item() {
		$event = array(
			'channel'    => 'ecommerce',
			'event'      => 'item.purchased',
			'timestamp'  => '2026-03-01T12:00:00Z',
			'source'     => 'woocommerce',
			'projectId'  => 'prj_1',
			'orderId'    => '14577',
			'lineItemId' => '7',
		);
		$context = array( 'provider' => 'woocommerce', 'projectId' => 'prj_1' );

		$key = EventKeyGenerator::buildDeterministic( $event, $context );
		$this->assertStringContainsString( 'lineItemId=7', $key['canonical'] );
		$this->assertStringContainsString( 'orderId=14577', $key['canonical'] );
	}

	public function test_different_events_produce_different_keys() {
		$event1 = array(
			'channel'         => 'forms',
			'event'           => 'forms.submission.received',
			'timestamp'       => '2026-03-01T10:00:00Z',
			'externalEventId' => 'entry_1',
			'projectId'       => 'prj_1',
		);
		$event2 = array(
			'channel'         => 'forms',
			'event'           => 'forms.submission.received',
			'timestamp'       => '2026-03-01T10:00:00Z',
			'externalEventId' => 'entry_2',
			'projectId'       => 'prj_1',
		);

		$key1 = EventKeyGenerator::buildDeterministic( $event1 );
		$key2 = EventKeyGenerator::buildDeterministic( $event2 );

		$this->assertNotSame( $key1['eventKey'], $key2['eventKey'] );
	}
}
