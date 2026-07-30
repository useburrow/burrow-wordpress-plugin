<?php
/**
 * SureForms provider.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

class SureFormsProvider implements FormsProviderInterface {
	public function get_provider_key() {
		return 'sure-forms';
	}

	/**
	 * Normalize the srfm_form_submit payload.
	 *
	 * Payload keys (from SureForms inc/form-submit.php):
	 * - form_id   (int)
	 * - entry_id  (int) — absent when GDPR "do not store entries" is enabled
	 * - form_name (string)
	 * - data      (array<string,string>) label => value map
	 *
	 * @param mixed $payload Raw hook payload.
	 * @return array<string,mixed>
	 */
	public function normalize_submission( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$values  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		$entry_id = isset( $payload['entry_id'] ) && '' !== (string) $payload['entry_id']
			? (string) $payload['entry_id']
			: uniqid( 'srfm_', false );

		return array(
			'provider'     => $this->get_provider_key(),
			'formId'       => (string) ( $payload['form_id'] ?? '' ),
			'formName'     => (string) ( $payload['form_name'] ?? 'SureForm' ),
			'submissionId' => $entry_id,
			'rawValues'    => $values,
		);
	}
}
