<?php
/**
 * SureCart provider implementation.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Ecommerce;

class SureCartProvider implements EcommerceProviderInterface {
	/**
	 * Currencies whose smallest unit is the whole unit (no cents).
	 * Mirrors SureCart's own zero-decimal list.
	 *
	 * @var array<int,string>
	 */
	private static $zero_decimal_currencies = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
		'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	/**
	 * Normalize a SureCart Checkout model (or the raw order/checkout object from
	 * webhook event data) into the shared ecommerce order shape.
	 *
	 * SureCart amounts are integers in the currency's minor unit.
	 *
	 * @param mixed $order \SureCart\Models\Checkout, or a stdClass checkout payload.
	 * @return array<string,mixed>
	 */
	public function normalize_order( $order ) {
		if ( ! is_object( $order ) ) {
			return array();
		}

		$order_id = (string) self::read( $order, 'id' );
		if ( '' === $order_id ) {
			return array();
		}

		$currency = strtoupper( (string) self::read( $order, 'currency' ) );

		$items      = array();
		$item_count = 0;
		foreach ( self::collection_data( self::read( $order, 'line_items' ) ) as $line_item ) {
			if ( ! is_object( $line_item ) ) {
				continue;
			}
			$item_count++;
			$quantity   = max( 1, (int) self::read( $line_item, 'quantity' ) );
			$price      = self::read( $line_item, 'price' );
			$product    = is_object( $price ) ? self::read( $price, 'product' ) : null;
			$line_total = self::amount( self::read( $line_item, 'total_amount' ), $currency );
			$unit_price = self::amount( is_object( $price ) ? self::read( $price, 'amount' ) : null, $currency );
			if ( null === $unit_price && null !== $line_total ) {
				$unit_price = $line_total / $quantity;
			}

			$variant      = self::read( $line_item, 'variant' );
			$variant_name = '';
			if ( is_object( $variant ) ) {
				$options = array_filter( array(
					(string) self::read( $variant, 'option_1' ),
					(string) self::read( $variant, 'option_2' ),
					(string) self::read( $variant, 'option_3' ),
				) );
				$variant_name = implode( ' / ', $options );
			}

			$product_id = is_object( $product ) ? (string) self::read( $product, 'id' ) : (string) $product;
			if ( '' === $product_id && is_string( $price ) ) {
				// Unexpanded line item: fall back to the price id so the item event survives.
				$product_id = $price;
			}
			$product_name = is_object( $product ) ? (string) self::read( $product, 'name' ) : '';
			if ( '' === $product_name ) {
				$product_name = $product_id;
			}

			$items[] = array(
				'lineItemId'  => (string) self::read( $line_item, 'id' ),
				'productId'   => $product_id,
				'productName' => $product_name,
				'quantity'    => $quantity,
				'unitPrice'   => (float) ( $unit_price ?? 0.0 ),
				'lineTotal'   => (float) ( $line_total ?? 0.0 ),
				'variantName' => $variant_name,
				'productUrl'  => is_object( $product ) ? (string) self::read( $product, 'permalink' ) : '',
				'category'    => '',
				'vendor'      => '',
			);
		}

		$customer_identity = self::build_customer_identity( $order );
		$shipping_address  = self::read( $order, 'shipping_address' );

		$discount    = self::read( $order, 'discount' );
		$coupon_code = null;
		if ( is_object( $discount ) ) {
			$promotion = self::read( $discount, 'promotion' );
			$code      = is_object( $promotion ) ? (string) self::read( $promotion, 'code' ) : '';
			if ( '' !== $code ) {
				$coupon_code = $code;
			}
		}

		return array(
			'orderId'         => $order_id,
			'total'           => (float) ( self::amount( self::read( $order, 'total_amount' ), $currency ) ?? 0.0 ),
			'subtotal'        => self::amount( self::read( $order, 'subtotal_amount' ), $currency ),
			'shipping'        => self::amount( self::read( $order, 'shipping_amount' ), $currency ),
			'tax'             => self::amount( self::read( $order, 'tax_amount' ), $currency ),
			'discount'        => self::amount( self::read( $order, 'discount_amount' ), $currency ),
			'currency'        => $currency,
			'itemCount'       => $item_count,
			'status'          => (string) self::read( $order, 'status' ),
			'paymentMethod'   => (string) self::read( $order, 'processor_type' ),
			'items'           => $items,
			'customerToken'   => $customer_identity['customerToken'],
			'isGuest'         => $customer_identity['isGuest'],
			'orderSequence'   => $customer_identity['orderSequence'],
			'isNewCustomer'   => $customer_identity['isNewCustomer'],
			'shippingCountry' => is_object( $shipping_address ) ? (string) self::read( $shipping_address, 'country' ) : '',
			'shippingRegion'  => is_object( $shipping_address ) ? (string) self::read( $shipping_address, 'state' ) : '',
			'shippingMethod'  => null,
			'couponCode'      => $coupon_code,
		);
	}

	/**
	 * Resolve the ISO 8601 creation timestamp from a checkout/order object.
	 *
	 * @param mixed $order Checkout or order object.
	 * @return string|null
	 */
	public static function resolve_created_at( $order ) {
		if ( ! is_object( $order ) ) {
			return null;
		}
		$created = self::read( $order, 'created_at' );
		if ( is_numeric( $created ) && (int) $created > 0 ) {
			return gmdate( 'c', (int) $created );
		}
		if ( is_string( $created ) && '' !== trim( $created ) ) {
			$ts = strtotime( $created );
			return false !== $ts ? gmdate( 'c', $ts ) : null;
		}
		return null;
	}

	/**
	 * Build opaque customer identity. Never exposes names or emails.
	 *
	 * @param object $order Checkout/order object.
	 * @return array{customerToken: string, isGuest: string, orderSequence: string, isNewCustomer: string}
	 */
	private static function build_customer_identity( $order ) {
		$customer    = self::read( $order, 'customer' );
		$customer_id = is_object( $customer ) ? (string) self::read( $customer, 'id' ) : (string) $customer;

		if ( '' !== $customer_id ) {
			// Sequence defaults to first order; the order handler overrides it
			// from the locally observed per-customer order count.
			return array(
				'customerToken' => 'sc_cust_' . $customer_id,
				'isGuest'       => 'false',
				'orderSequence' => '1',
				'isNewCustomer' => 'true',
			);
		}

		$email = (string) self::read( $order, 'email' );
		$seed  = '' !== $email ? $email : (string) self::read( $order, 'id' );

		return array(
			'customerToken' => 'sc_guest_' . substr( hash( 'sha256', $seed ), 0, 12 ),
			'isGuest'       => 'true',
			'orderSequence' => '1',
			'isNewCustomer' => 'true',
		);
	}

	/**
	 * Read an attribute off a SureCart model or stdClass without tripping magic getters.
	 *
	 * @param mixed  $object Object.
	 * @param string $key    Attribute name.
	 * @return mixed
	 */
	private static function read( $object, $key ) {
		if ( ! is_object( $object ) ) {
			return null;
		}
		$value = $object->{$key} ?? null;
		return $value;
	}

	/**
	 * Unwrap a SureCart list object ({ data: [...] }) or plain array.
	 *
	 * @param mixed $collection List relation value.
	 * @return array<int,mixed>
	 */
	private static function collection_data( $collection ) {
		if ( is_object( $collection ) ) {
			$data = self::read( $collection, 'data' );
			return is_array( $data ) ? $data : array();
		}
		return is_array( $collection ) ? $collection : array();
	}

	/**
	 * Convert a SureCart minor-unit integer amount to a float in major units.
	 *
	 * @param mixed  $amount   Raw amount.
	 * @param string $currency ISO currency code.
	 * @return float
	 */
	public static function convert_amount( $amount, $currency ) {
		return (float) ( self::amount( $amount, $currency ) ?? 0.0 );
	}

	/**
	 * Convert a SureCart minor-unit integer amount to a float in major units.
	 *
	 * @param mixed  $amount   Raw amount.
	 * @param string $currency ISO currency code.
	 * @return float|null
	 */
	private static function amount( $amount, $currency ) {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return null;
		}
		$value = (float) $amount;
		if ( in_array( strtoupper( (string) $currency ), self::$zero_decimal_currencies, true ) ) {
			return $value;
		}
		return $value / 100;
	}
}
