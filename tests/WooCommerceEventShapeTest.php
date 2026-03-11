<?php

use Burrow\Sdk\Events\CanonicalEnvelopeBuilders;
use Burrow\Sdk\Events\ChannelRoutingResolver;
use Burrow\Sdk\Events\ChannelRoutingState;
use PHPUnit\Framework\TestCase;

class WooCommerceEventShapeTest extends TestCase {
	private function routing(): ChannelRoutingResolver {
		return new ChannelRoutingResolver( new ChannelRoutingState(
			projectId: 'prj_1',
			projectSourceIds: array( 'ecommerce' => 'src_ecom' ),
			clientId: 'cli_1'
		) );
	}

	private function base_order_input(): array {
		return array(
			'organizationId'   => 'org_1',
			'orderId'          => '42',
			'orderTotal'       => 99.95,
			'total'            => 99.95,
			'subtotal'         => 79.95,
			'shipping'         => 10.00,
			'tax'              => 8.00,
			'discount'         => 5.00,
			'currency'         => 'USD',
			'itemCount'        => 2,
			'submittedAt'      => '2026-03-10T12:00:00+00:00',
			'timestamp'        => '2026-03-10T12:00:00+00:00',
			'externalEntityId' => 'wc_order_42',
			'customerToken'    => 'wc_cust_7',
			'tags'             => array(
				'provider'        => 'woocommerce',
				'currency'        => 'USD',
				'customerToken'   => 'wc_cust_7',
				'isGuest'         => 'false',
				'orderSequence'   => '3',
				'isNewCustomer'   => 'false',
				'paymentMethod'   => 'stripe',
				'shippingCountry' => 'US',
				'shippingRegion'  => 'CA',
				'shippingMethod'  => 'flat-rate',
				'couponCode'      => 'SAVE10',
			),
		);
	}

	public function test_order_placed_uses_short_event_name_and_lifecycle() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent(
			$this->base_order_input(),
			$this->routing()
		);
		$this->assertSame( 'ecommerce', $envelope['channel'] );
		$this->assertSame( 'order.placed', $envelope['event'] );
		$this->assertTrue( $envelope['isLifecycle'] );
		$this->assertSame( 'order', $envelope['entityType'] );
		$this->assertSame( 'wc_order_42', $envelope['externalEntityId'] );
		$this->assertSame( 'placed', $envelope['state'] );
	}

	public function test_order_placed_has_full_property_set() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent(
			$this->base_order_input(),
			$this->routing()
		);
		$props = $envelope['properties'];
		$this->assertSame( '42', $props['orderId'] );
		$this->assertSame( 99.95, $props['orderTotal'] );
		$this->assertSame( 79.95, $props['subtotal'] );
		$this->assertSame( 8.0, $props['tax'] );
		$this->assertSame( 2, $props['itemCount'] );
	}

	public function test_order_placed_has_full_tag_set() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent(
			$this->base_order_input(),
			$this->routing()
		);
		$tags = $envelope['tags'];
		$this->assertSame( 'woocommerce', $tags['provider'] );
		$this->assertSame( 'wc_cust_7', $tags['customerToken'] );
		$this->assertSame( 'false', $tags['isGuest'] );
		$this->assertSame( '3', $tags['orderSequence'] );
		$this->assertSame( 'false', $tags['isNewCustomer'] );
		$this->assertSame( 'stripe', $tags['paymentMethod'] );
		$this->assertSame( 'US', $tags['shippingCountry'] );
		$this->assertSame( 'CA', $tags['shippingRegion'] );
		$this->assertSame( 'flat-rate', $tags['shippingMethod'] );
		$this->assertSame( 'SAVE10', $tags['couponCode'] );
	}

	public function test_item_purchased_includes_customer_token() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceItemPurchasedEvent(
			array(
				'organizationId' => 'org_1',
				'orderId'        => '42',
				'productId'      => '101',
				'productName'    => 'Widget',
				'quantity'       => 1,
				'unitPrice'      => 29.99,
				'lineTotal'      => 29.99,
				'currency'       => 'USD',
				'submittedAt'    => '2026-03-10T12:00:00+00:00',
				'timestamp'      => '2026-03-10T12:00:00+00:00',
				'customerToken'  => 'wc_cust_7',
				'tags'           => array(
					'provider' => 'woocommerce',
					'currency' => 'USD',
				),
			),
			$this->routing()
		);
		$this->assertSame( 'item.purchased', $envelope['event'] );
		$this->assertSame( 'wc_cust_7', $envelope['tags']['customerToken'] );
		$this->assertSame( 'woocommerce', $envelope['tags']['provider'] );
	}

	public function test_order_fulfilled_lifecycle_and_customer_token() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceOrderFulfilledEvent(
			array(
				'organizationId'   => 'org_1',
				'orderId'          => '42',
				'orderTotal'       => 99.95,
				'currency'         => 'USD',
				'externalEntityId' => 'wc_order_42',
				'customerToken'    => 'wc_cust_7',
				'tags'             => array( 'provider' => 'woocommerce', 'currency' => 'USD' ),
			),
			$this->routing()
		);
		$this->assertSame( 'order.fulfilled', $envelope['event'] );
		$this->assertTrue( $envelope['isLifecycle'] );
		$this->assertSame( 'order', $envelope['entityType'] );
		$this->assertSame( 'wc_order_42', $envelope['externalEntityId'] );
		$this->assertSame( 'fulfilled', $envelope['state'] );
		$this->assertSame( 'wc_cust_7', $envelope['tags']['customerToken'] );
	}

	public function test_order_refunded_lifecycle_and_customer_token() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceOrderRefundedEvent(
			array(
				'organizationId'   => 'org_1',
				'orderId'          => '42',
				'orderTotal'       => 99.95,
				'currency'         => 'USD',
				'externalEntityId' => 'wc_order_42',
				'customerToken'    => 'wc_cust_7',
				'tags'             => array( 'provider' => 'woocommerce', 'currency' => 'USD' ),
			),
			$this->routing()
		);
		$this->assertSame( 'order.refunded', $envelope['event'] );
		$this->assertTrue( $envelope['isLifecycle'] );
		$this->assertSame( 'refunded', $envelope['state'] );
		$this->assertSame( 'wc_cust_7', $envelope['tags']['customerToken'] );
	}

	public function test_order_cancelled_lifecycle_and_customer_token() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceOrderCancelledEvent(
			array(
				'organizationId'   => 'org_1',
				'orderId'          => '42',
				'orderTotal'       => 99.95,
				'currency'         => 'USD',
				'externalEntityId' => 'wc_order_42',
				'customerToken'    => 'wc_cust_7',
				'tags'             => array( 'provider' => 'woocommerce', 'currency' => 'USD' ),
			),
			$this->routing()
		);
		$this->assertSame( 'order.cancelled', $envelope['event'] );
		$this->assertTrue( $envelope['isLifecycle'] );
		$this->assertSame( 'cancelled', $envelope['state'] );
		$this->assertSame( 'wc_order_42', $envelope['externalEntityId'] );
		$this->assertSame( 'wc_cust_7', $envelope['tags']['customerToken'] );
	}

	public function test_no_pii_in_order_placed_envelope() {
		$envelope = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent(
			$this->base_order_input(),
			$this->routing()
		);
		$json = json_encode( $envelope );
		$this->assertStringNotContainsString( 'email', $json );
		$this->assertStringNotContainsString( 'firstName', $json );
		$this->assertStringNotContainsString( 'lastName', $json );
		$this->assertStringNotContainsString( 'address', $json );
	}

	public function test_guest_customer_token_is_hashed() {
		$token = 'wc_guest_' . substr( hash( 'sha256', 'guest@example.com' ), 0, 12 );
		$this->assertStringStartsWith( 'wc_guest_', $token );
		$this->assertSame( 21, strlen( $token ) );
		$this->assertStringNotContainsString( '@', $token );
	}
}
