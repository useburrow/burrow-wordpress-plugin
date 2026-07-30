<?php

use BurrowWP\Providers\Ecommerce\SureCartProvider;
use PHPUnit\Framework\TestCase;

class SureCartProviderTest extends TestCase {
	private function checkout_fixture(): object {
		$product        = new stdClass();
		$product->id    = 'prod_1';
		$product->name  = 'Widget';
		$product->permalink = 'https://example.com/widget';

		$price          = new stdClass();
		$price->id      = 'price_1';
		$price->amount  = 2999;
		$price->product = $product;

		$line_item               = new stdClass();
		$line_item->id           = 'li_1';
		$line_item->quantity     = 2;
		$line_item->price        = $price;
		$line_item->total_amount = 5998;

		$line_items       = new stdClass();
		$line_items->data = array( $line_item );

		$shipping_address          = new stdClass();
		$shipping_address->country = 'US';
		$shipping_address->state   = 'CA';

		$checkout                  = new stdClass();
		$checkout->id              = 'chk_123';
		$checkout->status          = 'paid';
		$checkout->currency        = 'usd';
		$checkout->total_amount    = 6598;
		$checkout->subtotal_amount = 5998;
		$checkout->shipping_amount = 500;
		$checkout->tax_amount      = 100;
		$checkout->discount_amount = 0;
		$checkout->processor_type  = 'stripe';
		$checkout->email           = 'jane@example.com';
		$checkout->line_items      = $line_items;
		$checkout->shipping_address = $shipping_address;

		return $checkout;
	}

	public function test_normalizes_minor_unit_amounts_to_major_units() {
		$provider = new SureCartProvider();
		$data     = $provider->normalize_order( $this->checkout_fixture() );

		$this->assertSame( 'chk_123', $data['orderId'] );
		$this->assertSame( 65.98, $data['total'] );
		$this->assertSame( 59.98, $data['subtotal'] );
		$this->assertSame( 5.0, $data['shipping'] );
		$this->assertSame( 1.0, $data['tax'] );
		$this->assertSame( 'USD', $data['currency'] );
		$this->assertSame( 'stripe', $data['paymentMethod'] );
		$this->assertSame( 'paid', $data['status'] );
	}

	public function test_normalizes_line_items_with_product_details() {
		$provider = new SureCartProvider();
		$data     = $provider->normalize_order( $this->checkout_fixture() );

		$this->assertSame( 1, $data['itemCount'] );
		$item = $data['items'][0];
		$this->assertSame( 'prod_1', $item['productId'] );
		$this->assertSame( 'Widget', $item['productName'] );
		$this->assertSame( 2, $item['quantity'] );
		$this->assertSame( 29.99, $item['unitPrice'] );
		$this->assertSame( 59.98, $item['lineTotal'] );
	}

	public function test_zero_decimal_currency_amounts_are_not_divided() {
		$checkout               = $this->checkout_fixture();
		$checkout->currency     = 'jpy';
		$checkout->total_amount = 6598;

		$provider = new SureCartProvider();
		$data     = $provider->normalize_order( $checkout );

		$this->assertSame( 6598.0, $data['total'] );
	}

	public function test_guest_identity_hashes_email_and_never_exposes_it() {
		$provider = new SureCartProvider();
		$data     = $provider->normalize_order( $this->checkout_fixture() );

		$this->assertSame( 'true', $data['isGuest'] );
		$this->assertStringStartsWith( 'sc_guest_', $data['customerToken'] );
		$this->assertStringNotContainsString( 'jane', $data['customerToken'] );
		$this->assertStringNotContainsString( 'example.com', $data['customerToken'] );
	}

	public function test_identified_customer_uses_customer_id_token() {
		$checkout           = $this->checkout_fixture();
		$customer           = new stdClass();
		$customer->id       = 'cust_9';
		$checkout->customer = $customer;

		$provider = new SureCartProvider();
		$data     = $provider->normalize_order( $checkout );

		$this->assertSame( 'sc_cust_cust_9', $data['customerToken'] );
		$this->assertSame( 'false', $data['isGuest'] );
	}

	public function test_customer_as_id_string_is_supported() {
		$checkout           = $this->checkout_fixture();
		$checkout->customer = 'cust_9';

		$provider = new SureCartProvider();
		$data     = $provider->normalize_order( $checkout );

		$this->assertSame( 'sc_cust_cust_9', $data['customerToken'] );
	}

	public function test_shipping_address_and_coupon_are_extracted() {
		$checkout            = $this->checkout_fixture();
		$promotion           = new stdClass();
		$promotion->code     = 'SAVE10';
		$discount            = new stdClass();
		$discount->promotion = $promotion;
		$checkout->discount  = $discount;

		$provider = new SureCartProvider();
		$data     = $provider->normalize_order( $checkout );

		$this->assertSame( 'US', $data['shippingCountry'] );
		$this->assertSame( 'CA', $data['shippingRegion'] );
		$this->assertSame( 'SAVE10', $data['couponCode'] );
	}

	public function test_returns_empty_for_object_without_id() {
		$provider = new SureCartProvider();
		$this->assertSame( array(), $provider->normalize_order( new stdClass() ) );
		$this->assertSame( array(), $provider->normalize_order( 'not-an-object' ) );
	}

	public function test_resolve_created_at_handles_unix_timestamp() {
		$checkout             = new stdClass();
		$checkout->created_at = 1770000000;

		$this->assertSame(
			gmdate( 'c', 1770000000 ),
			SureCartProvider::resolve_created_at( $checkout )
		);
		$this->assertNull( SureCartProvider::resolve_created_at( new stdClass() ) );
	}
}
