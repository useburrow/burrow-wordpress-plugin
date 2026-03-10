<?php
/**
 * Ninja Forms provider.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

class NinjaFormsProvider implements FormsProviderInterface {
	public function get_provider_key() {
		return 'ninja-forms';
	}

	public function normalize_submission( $payload ) {
		$form_data = isset( $payload['form_data'] ) && is_array( $payload['form_data'] ) ? $payload['form_data'] : array();
		$fields    = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$values    = array();

		foreach ( $fields as $field ) {
			if ( ! isset( $field['id'] ) ) {
				continue;
			}
			$values[ (string) $field['id'] ] = $field['value'] ?? '';
		}

		return array(
			'provider'      => $this->get_provider_key(),
			'formId'        => (string) ( $form_data['id'] ?? '' ),
			'formName'      => (string) ( $form_data['form_title'] ?? 'Ninja Form' ),
			'submissionId'  => (string) ( $payload['actions']['save']['sub_id'] ?? uniqid( 'nf_', false ) ),
			'rawValues'     => $values,
		);
	}
}
