<?php
/**
 * WPForms provider.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

class WPFormsProvider implements FormsProviderInterface {
	public function get_provider_key() {
		return 'wpforms';
	}

	public function normalize_submission( $payload ) {
		$fields  = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$entry   = isset( $payload['entry'] ) && is_array( $payload['entry'] ) ? $payload['entry'] : array();
		$form_data = isset( $payload['form_data'] ) && is_array( $payload['form_data'] ) ? $payload['form_data'] : array();

		$form_id   = (string) ( $form_data['id'] ?? '' );
		$form_name = (string) ( $form_data['settings']['form_title'] ?? ( 'WPForm ' . $form_id ) );
		$entry_id  = (string) ( $entry['id'] ?? uniqid( 'wpf_', false ) );

		$values = array();
		foreach ( $fields as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$values[ (string) $field_id ] = isset( $field['value'] ) ? $field['value'] : '';
		}

		return array(
			'provider'      => $this->get_provider_key(),
			'formId'        => $form_id,
			'formName'      => $form_name,
			'submissionId'  => $entry_id,
			'submittedAt'   => isset( $entry['date'] ) ? (string) $entry['date'] : null,
			'rawValues'     => $values,
		);
	}
}
