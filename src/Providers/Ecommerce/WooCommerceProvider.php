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

		return array(
			'orderId'       => $order_id,
			'total'         => (float) $order->get_total(),
			'subtotal'      => method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : null,
			'shipping'      => method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : null,
			'discount'      => method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : null,
			'currency'      => (string) $order->get_currency(),
			'itemCount'     => $item_count,
			'status'        => (string) $order->get_status(),
			'paymentMethod' => (string) $order->get_payment_method(),
			'items'         => $items,
		);
	}
}
