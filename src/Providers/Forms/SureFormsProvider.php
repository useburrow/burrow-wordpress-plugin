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

	/**
	 * Extract the field slug from a SureForms stored field key.
	 *
	 * Stored entries keep raw keys like "srfm-input-{id}-lbl-{encoded}-{slug}";
	 * the realtime submit payload (and therefore contract field mappings) uses
	 * just the slug. Mirrors SureForms' own prepare_submission_data() split.
	 *
	 * @param string $key Raw stored field key.
	 * @return string Slug, or '' when the key has no field slug.
	 */
	public static function field_key_to_slug( $key ) {
		$parts = explode( '-lbl-', (string) $key );
		if ( count( $parts ) < 2 || '' === $parts[1] ) {
			return '';
		}
		$tokens = explode( '-', $parts[1] );
		if ( count( $tokens ) < 2 ) {
			return '';
		}
		return implode( '-', array_slice( $tokens, 1 ) );
	}

	/**
	 * Convert a stored srfm_entries form_data map (raw field keys) into the
	 * slug-keyed values shape used by realtime submissions.
	 *
	 * @param array<string,mixed> $form_data Decoded form_data from the entries table.
	 * @return array<string,string>
	 */
	public static function normalize_stored_entry_values( array $form_data ) {
		$values = array();
		foreach ( $form_data as $key => $value ) {
			$slug = self::field_key_to_slug( (string) $key );
			if ( '' === $slug ) {
				continue;
			}
			if ( is_array( $value ) ) {
				// Upload fields store arrays of URL-encoded file URLs.
				$value = implode( ', ', array_map( 'rawurldecode', array_map( 'strval', $value ) ) );
			}
			$values[ $slug ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $values;
	}
}
