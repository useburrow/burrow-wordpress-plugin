<?php
/**
 * Contact Form 7 provider.
 *
 * @package Burrow
 */

namespace BurrowWP\Providers\Forms;

class ContactForm7Provider implements FormsProviderInterface {
	public function get_provider_key() {
		return 'contact-form-7';
	}

	public function normalize_submission( $payload ) {
		$form_id      = '';
		$form_name    = 'Contact Form';
		$submission_id = uniqid( 'cf7_', false );
		$values       = array();

		if ( is_object( $payload ) && method_exists( $payload, 'id' ) ) {
			$form_id = (string) $payload->id();
		}
		if ( is_object( $payload ) && method_exists( $payload, 'title' ) ) {
			$form_name = (string) $payload->title();
		}

		$submission = class_exists( '\WPCF7_Submission' ) ? \WPCF7_Submission::get_instance() : null;
		if ( $submission ) {
			$data = $submission->get_posted_data();
			if ( is_array( $data ) ) {
				$values = $data;
			}
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
