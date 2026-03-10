<?php
/**
 * Form provider normalized contract.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

interface FormsProviderInterface {
	/**
	 * Provider handle.
	 *
	 * @return string
	 */
	public function get_provider_key();

	/**
	 * Normalize submission payload.
	 *
	 * @param mixed $payload Raw hook payload.
	 * @return array<string,mixed>
	 */
	public function normalize_submission( $payload );
}
