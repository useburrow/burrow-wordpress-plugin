<?php
/**
 * WooCommerce provider implementation.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Ecommerce;

class WooCommerceProvider implements EcommerceProviderInterface {
	public function normalize_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return array();
		}

		$order_id   = (string) $order->get_id();
		$items      = array();
		$item_count = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			$item_count++;
			$product = $item->get_product();
			$items[] = array(
				'lineItemId' => (string) $item_id,
				'productId'  => $product ? (string) $product->get_id() : '',
				'productName'=> (string) $item->get_name(),
				'quantity'   => (int) $item->get_quantity(),
				'unitPrice'  => (float) $order->get_item_total( $item, false, false ),
				'lineTotal'  => (float) $item->get_total(),
				'variantName'=> ( $product && method_exists( $product, 'get_attribute_summary' ) ) ? (string) $product->get_attribute_summary() : '',
				'productUrl' => $product ? (string) get_permalink( $product->get_id() ) : '',
				'category'   => '',
				'vendor'     => '',
			);
		}

		$customer_identity = self::build_customer_identity( $order );
		$shipping_tags     = self::build_shipping_tags( $order );

		return array(
			'orderId'       => $order_id,
			'total'         => (float) $order->get_total(),
			'subtotal'      => method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : null,
			'shipping'      => method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : null,
			'tax'           => method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : null,
			'discount'      => method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : null,
			'currency'      => (string) $order->get_currency(),
			'itemCount'     => $item_count,
			'status'        => (string) $order->get_status(),
			'paymentMethod' => (string) $order->get_payment_method(),
			'items'         => $items,
			'customerToken' => $customer_identity['customerToken'],
			'isGuest'       => $customer_identity['isGuest'],
			'orderSequence' => $customer_identity['orderSequence'],
			'isNewCustomer' => $customer_identity['isNewCustomer'],
			'shippingCountry' => $shipping_tags['shippingCountry'],
			'shippingRegion'  => $shipping_tags['shippingRegion'],
			'shippingMethod'  => $shipping_tags['shippingMethod'],
			'couponCode'      => $shipping_tags['couponCode'],
		);
	}

	/**
	 * Build opaque customer identity from an order.
	 * Never exposes names, emails, or cleartext WooCommerce user IDs.
	 *
	 * @param object $order WC_Order instance.
	 * @return array{customerToken: string, isGuest: string, orderSequence: string, isNewCustomer: string}
	 */
	private static function build_customer_identity( $order ) {
		$customer_id = (int) $order->get_customer_id();
		$is_guest    = 0 === $customer_id;

		$customer_token = $is_guest
			? 'wc_guest_' . substr( hash( 'sha256', (string) $order->get_billing_email() ), 0, 12 )
			: 'wc_cust_' . $customer_id;

		if ( $is_guest ) {
			$order_sequence = '1';
		} else {
			$raw = function_exists( 'get_user_meta' ) ? get_user_meta( $customer_id, '_order_count', true ) : 0;
			$order_sequence = (string) max( 1, (int) $raw );
		}

		$is_new_customer = '1' === $order_sequence ? 'true' : 'false';

		return array(
			'customerToken' => $customer_token,
			'isGuest'       => $is_guest ? 'true' : 'false',
			'orderSequence' => $order_sequence,
			'isNewCustomer' => $is_new_customer,
		);
	}

	/**
	 * Extract shipping/payment tags from an order.
	 *
	 * @param object $order WC_Order instance.
	 * @return array{shippingCountry: string, shippingRegion: string, shippingMethod: string|null, couponCode: string|null}
	 */
	private static function build_shipping_tags( $order ) {
		$shipping_method = null;
		if ( method_exists( $order, 'get_shipping_methods' ) ) {
			$methods      = $order->get_shipping_methods();
			$first_method = reset( $methods );
			if ( $first_method && method_exists( $first_method, 'get_method_title' ) ) {
				$title = $first_method->get_method_title();
				if ( '' !== trim( (string) $title ) ) {
					$shipping_method = function_exists( 'sanitize_title' )
						? sanitize_title( $title )
						: strtolower( trim( (string) $title ) );
				}
			}
		}

		$coupon_code = null;
		if ( method_exists( $order, 'get_coupon_codes' ) ) {
			$coupons = $order->get_coupon_codes();
			if ( ! empty( $coupons ) && is_array( $coupons ) ) {
				$coupon_code = (string) $coupons[0];
			}
		}

		return array(
			'shippingCountry' => method_exists( $order, 'get_shipping_country' ) ? (string) $order->get_shipping_country() : '',
			'shippingRegion'  => method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '',
			'shippingMethod'  => $shipping_method,
			'couponCode'      => $coupon_code,
		);
	}
}
