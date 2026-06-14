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
		$payload = is_array( $payload ) ? $payload : array();
		$fields  = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$values  = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}
			$values[ (string) $field['id'] ] = $field['value'] ?? '';
		}

		$form_id = '';
		if ( isset( $payload['form_id'] ) ) {
			$form_id = (string) $payload['form_id'];
		} elseif ( isset( $payload['form_data']['id'] ) ) {
			$form_id = (string) $payload['form_data']['id'];
		}

		$form_name = 'Ninja Form';
		if ( isset( $payload['settings']['title'] ) && '' !== (string) $payload['settings']['title'] ) {
			$form_name = (string) $payload['settings']['title'];
		} elseif ( isset( $payload['form_data']['form_title'] ) && '' !== (string) $payload['form_data']['form_title'] ) {
			$form_name = (string) $payload['form_data']['form_title'];
		}

		$submission_id = '';
		if ( isset( $payload['actions']['save']['sub_id'] ) ) {
			$submission_id = (string) $payload['actions']['save']['sub_id'];
		}
		if ( '' === $submission_id ) {
			$submission_id = uniqid( 'nf_', false );
		}

		return array(
			'provider'      => $this->get_provider_key(),
			'formId'        => $form_id,
			'formName'      => $form_name,
			'submissionId'  => $submission_id,
			'rawValues'     => $values,
		);
	}
}
