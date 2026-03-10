<?php
/**
 * Gravity Forms provider.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

class GravityFormsProvider implements FormsProviderInterface {
	public function get_provider_key() {
		return 'gravity-forms';
	}

	public function normalize_submission( $payload ) {
		$entry = isset( $payload['entry'] ) && is_array( $payload['entry'] ) ? $payload['entry'] : array();
		$form  = isset( $payload['form'] ) && is_array( $payload['form'] ) ? $payload['form'] : array();

		return array(
			'provider'      => $this->get_provider_key(),
			'formId'        => (string) ( $form['id'] ?? '' ),
			'formName'      => (string) ( $form['title'] ?? 'Gravity Form' ),
			'submissionId'  => (string) ( $entry['id'] ?? uniqid( 'gf_', false ) ),
			'rawValues'     => $entry,
		);
	}
}
