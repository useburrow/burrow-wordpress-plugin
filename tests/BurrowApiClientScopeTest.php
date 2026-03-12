<?php

use BurrowWP\Infrastructure\Http\BurrowApiClient;
use PHPUnit\Framework\TestCase;

class BurrowApiClientScopeTest extends TestCase {
	public function test_backfill_payload_enforces_project_scope_for_woo_order_and_item_events() {
		$client = new BurrowApiClient(
			'https://api.useburrow.com',
			'brw_live_org_key',
			5,
			array(
				'key'       => 'brw_ingestion_project_key',
				'projectId' => 'prj_scoped_123',
				'keyPrefix' => 'ing_prj',
			)
		);

		$payload = array(
			'events' => array(
				array(
					'channel' => 'ecommerce',
					'event'   => 'ecommerce.order.placed',
					'source'  => 'woocommerce',
				),
				array(
					'channel' => 'ecommerce',
					'event'   => 'ecommerce.item.purchased',
					'source'  => 'woocommerce',
				),
			),
			'routingDefaults' => array(),
		);

		$method = new ReflectionMethod( BurrowApiClient::class, 'ensure_backfill_project_scope' );
		$method->setAccessible( true );
		$scoped = $method->invoke( $client, $payload );

		$this->assertSame( 'prj_scoped_123', $scoped['routingDefaults']['projectId'] );
		$this->assertCount( 2, $scoped['events'] );
		$this->assertSame( 'ecommerce.order.placed', $scoped['events'][0]['event'] );
		$this->assertSame( 'ecommerce.item.purchased', $scoped['events'][1]['event'] );
		$this->assertSame( 'woocommerce', $scoped['events'][0]['source'] );
		$this->assertSame( 'woocommerce', $scoped['events'][1]['source'] );
		$this->assertSame( 'prj_scoped_123', $scoped['events'][0]['projectId'] );
		$this->assertSame( 'prj_scoped_123', $scoped['events'][1]['projectId'] );
	}

	public function test_unprefixed_event_names_are_rejected() {
		$client = new BurrowApiClient(
			'https://api.useburrow.com',
			'brw_live_org_key',
			5,
			array(
				'key'       => 'brw_ingestion_project_key',
				'projectId' => 'prj_scoped_123',
				'keyPrefix' => 'ing_prj',
			)
		);
		$this->expectException( \Burrow\Sdk\Events\Exception\EventContractException::class );
		$this->expectExceptionMessage( 'must be prefixed' );
		$method = new ReflectionMethod( BurrowApiClient::class, 'ensure_backfill_project_scope' );
		$method->setAccessible( true );
		$method->invoke(
			$client,
			array(
				'events' => array(
					array(
						'channel' => 'system',
						'event'   => 'stack.snapshot',
					),
				),
			)
		);
	}
}
