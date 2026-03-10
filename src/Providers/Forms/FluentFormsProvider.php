<?php
/**
 * Fluent Forms provider.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

class FluentFormsProvider implements FormsProviderInterface {
	public function get_provider_key() {
		return 'fluent-forms';
	}

	public function normalize_submission( $payload ) {
		$form_data = isset( $payload['form'] ) && is_array( $payload['form'] ) ? $payload['form'] : array();
		$entry     = isset( $payload['entry'] ) && is_array( $payload['entry'] ) ? $payload['entry'] : array();
		$values    = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		return array(
			'provider'      => $this->get_provider_key(),
			'formId'        => (string) ( $form_data['id'] ?? '' ),
			'formName'      => (string) ( $form_data['title'] ?? 'Fluent Form' ),
			'submissionId'  => (string) ( $entry['id'] ?? uniqid( 'ff_', false ) ),
			'rawValues'     => $values,
		);
	}
}
