<?php
/**
 * Formidable Forms provider.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

class FormidableFormsProvider implements FormsProviderInterface {
	public function get_provider_key() {
		return 'formidable-forms';
	}

	public function normalize_submission( $payload ) {
		$entry = isset( $payload['entry'] ) && is_object( $payload['entry'] ) ? $payload['entry'] : null;
		$values = isset( $payload['values'] ) && is_array( $payload['values'] ) ? $payload['values'] : array();

		$form_id      = '';
		$form_name    = 'Formidable Form';
		$entry_id     = '';
		$submitted_at = null;

		if ( null !== $entry ) {
			$form_id  = (string) ( $entry->form_id ?? '' );
			$entry_id = (string) ( $entry->id ?? uniqid( 'frm_', false ) );
			$submitted_at = isset( $entry->created_at ) ? (string) $entry->created_at : null;

			if ( '' !== $form_id && class_exists( '\FrmForm' ) && method_exists( '\FrmForm', 'getOne' ) ) {
				$form = \FrmForm::getOne( (int) $form_id );
				if ( is_object( $form ) && isset( $form->name ) ) {
					$form_name = (string) $form->name;
				}
			}
		}

		return array(
			'provider'      => $this->get_provider_key(),
			'formId'        => $form_id,
			'formName'      => $form_name,
			'submissionId'  => $entry_id,
			'submittedAt'   => $submitted_at,
			'rawValues'     => $values,
		);
	}
}
