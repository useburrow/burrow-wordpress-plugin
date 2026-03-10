<?php
/**
 * Ecommerce provider normalized contract.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Ecommerce;

interface EcommerceProviderInterface {
	/**
	 * Normalize order payload.
	 *
	 * @param mixed $order Woo order.
	 * @return array<string,mixed>
	 */
	public function normalize_order( $order );
}
