<?php
/**
 * Deterministic idempotency key factory.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Events;

class EventKeyFactory {
	/**
	 * Forms key.
	 *
	 * @param string $form_id       Form ID.
	 * @param string $submission_id Submission ID.
	 * @return string
	 */
	public function forms_submission_key( $form_id, $submission_id ) {
		return sprintf( 'forms:%s:%s', sanitize_key( (string) $form_id ), sanitize_key( (string) $submission_id ) );
	}

	/**
	 * Ecommerce order key.
	 *
	 * @param string $order_id Order ID.
	 * @return string
	 */
	public function ecommerce_order_key( $order_id ) {
		return sprintf( 'ecommerce:order:%s', sanitize_key( (string) $order_id ) );
	}

	/**
	 * Ecommerce item key.
	 *
	 * @param string $order_id    Order ID.
	 * @param string $line_item_id Line item ID.
	 * @return string
	 */
	public function ecommerce_item_key( $order_id, $line_item_id ) {
		return sprintf( 'ecommerce:item:%s:%s', sanitize_key( (string) $order_id ), sanitize_key( (string) $line_item_id ) );
	}
}
