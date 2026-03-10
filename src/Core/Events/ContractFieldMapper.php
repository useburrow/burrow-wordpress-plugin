<?php
/**
 * Map raw provider values to contract-approved payload fields.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Events;

class ContractFieldMapper {
	/**
	 * Build minimized tags/properties from mappings.
	 *
	 * @param array<string,mixed> $raw_values Raw values keyed by provider field IDs.
	 * @param array<int,array<string,mixed>> $mappings Field mappings.
	 * @return array{tags: array<string,string>, properties: array<string,mixed>}
	 */
	public function map( array $raw_values, array $mappings ) {
		$tags       = array();
		$properties = array();

		foreach ( $mappings as $mapping ) {
			if ( empty( $mapping['canonicalKey'] ) || empty( $mapping['externalFieldId'] ) ) {
				continue;
			}
			$external_id = (string) $mapping['externalFieldId'];
			if ( ! array_key_exists( $external_id, $raw_values ) ) {
				continue;
			}
			$value  = $raw_values[ $external_id ];
			$target = isset( $mapping['target'] ) ? (string) $mapping['target'] : 'properties';
			$key    = (string) $mapping['canonicalKey'];

			if ( 'tags' === $target ) {
				$tags[ $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			} else {
				$properties[ $key ] = $value;
			}
		}

		return array(
			'tags'       => $tags,
			'properties' => $properties,
		);
	}
}
