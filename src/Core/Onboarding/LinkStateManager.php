<?php
/**
 * Link response persistence helpers.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Onboarding;

class LinkStateManager {
	/**
	 * Apply Burrow link response payload into plugin settings.
	 *
	 * The SDK client state blob is the canonical source of truth for
	 * ingestionKey, projectId, clientId, formsProjectSourceId, and
	 * contractMappings.  Plugin settings derive routing from it.
	 *
	 * @param array<string,mixed> $settings Existing settings.
	 * @param array<string,mixed> $body     Link response body.
	 * @return array<string,mixed>
	 */
	public static function apply_link_response( array $settings, array $body ) {
		if ( isset( $body['sdkState'] ) && is_array( $body['sdkState'] ) ) {
			$settings['sdk_state'] = $body['sdkState'];
		}

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);

		if ( null !== $sdk->projectId ) {
			$settings['routing']['projectId'] = $sdk->projectId;
		}
		if ( null !== $sdk->clientId ) {
			$settings['routing']['clientId'] = $sdk->clientId;
		}
		if ( null !== $sdk->formsProjectSourceId ) {
			$settings['routing']['projectSourceId'] = $sdk->formsProjectSourceId;
		}
		if ( null !== $sdk->ingestionKey ) {
			$body_ik    = isset( $body['ingestionKey'] ) && is_array( $body['ingestionKey'] ) ? $body['ingestionKey'] : array();
			$key_prefix = isset( $body_ik['keyPrefix'] ) ? trim( (string) $body_ik['keyPrefix'] ) : '';

			$settings['ingestion_key'] = array(
				'key'       => $sdk->ingestionKey,
				'projectId' => $sdk->projectId ?? '',
				'keyPrefix' => $key_prefix,
			);
		}

		$candidates = array( $body );
		if ( isset( $body['routing'] ) && is_array( $body['routing'] ) ) {
			$candidates[] = $body['routing'];
		}
		foreach ( $candidates as $candidate ) {
			if ( isset( $candidate['organizationId'] ) && '' !== trim( (string) $candidate['organizationId'] ) ) {
				$settings['routing']['organizationId'] = (string) $candidate['organizationId'];
			}
			if ( ! empty( $candidate['sourceIds'] ) && is_array( $candidate['sourceIds'] ) ) {
				$current = isset( $settings['routing']['sourceIds'] ) && is_array( $settings['routing']['sourceIds'] )
					? $settings['routing']['sourceIds']
					: array();
				$settings['routing']['sourceIds'] = array_merge( $current, $candidate['sourceIds'] );
			}
		}

		$project = isset( $body['project'] ) && is_array( $body['project'] ) ? $body['project'] : array();
		$settings['burrow_project'] = array(
			'path' => isset( $project['burrowProjectPath'] ) ? trim( (string) $project['burrowProjectPath'] ) : '',
			'url'  => isset( $project['burrowProjectUrl'] ) ? trim( (string) $project['burrowProjectUrl'] ) : '',
		);

		self::provision_missing_source_ids( $settings );

		return $settings;
	}

	/**
	 * Resolve project deep-link URL from settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function project_url_from_settings( array $settings ) {
		$url = '';
		if ( isset( $settings['burrow_project']['url'] ) ) {
			$url = trim( (string) $settings['burrow_project']['url'] );
		}
		if ( '' !== $url ) {
			$valid = filter_var( $url, FILTER_VALIDATE_URL );
			if ( false !== $valid ) {
				return (string) $valid;
			}
		}

		$path = '';
		if ( isset( $settings['burrow_project']['path'] ) ) {
			$path = trim( (string) $settings['burrow_project']['path'] );
		}
		$base_url = isset( $settings['base_url'] ) ? trim( (string) $settings['base_url'] ) : '';
		if ( '' !== $path && '' !== $base_url ) {
			$origin = self::app_origin_from_base_url( $base_url );
			if ( '' !== $origin ) {
				$candidate = rtrim( $origin, '/' ) . '/' . ltrim( $path, '/' );
				$valid_candidate = filter_var( $candidate, FILTER_VALIDATE_URL );
				if ( false !== $valid_candidate ) {
					return (string) $valid_candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Auto-fill sourceIds channels that are still empty using projectSourceId as fallback.
	 *
	 * @param array<string,mixed> $settings Settings (passed by reference).
	 */
	private static function provision_missing_source_ids( array &$settings ) {
		$fallback = isset( $settings['routing']['projectSourceId'] ) ? trim( (string) $settings['routing']['projectSourceId'] ) : '';
		if ( '' === $fallback ) {
			return;
		}
		if ( ! isset( $settings['routing']['sourceIds'] ) || ! is_array( $settings['routing']['sourceIds'] ) ) {
			$settings['routing']['sourceIds'] = array();
		}
		foreach ( array( 'forms', 'ecommerce', 'system' ) as $channel ) {
			if ( empty( $settings['routing']['sourceIds'][ $channel ] ) ) {
				$settings['routing']['sourceIds'][ $channel ] = $fallback;
			}
		}
	}

	/**
	 * Build app origin from configured API base URL.
	 *
	 * @param string $base_url API base URL.
	 * @return string
	 */
	private static function app_origin_from_base_url( $base_url ) {
		$parts = parse_url( $base_url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : '';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( '' === $scheme || '' === $host ) {
			return '';
		}
		if ( 0 === strpos( $host, 'api.' ) ) {
			$host = 'app.' . substr( $host, 4 );
		}
		$origin = $scheme . '://' . $host;
		if ( $port > 0 ) {
			$origin .= ':' . $port;
		}
		return $origin;
	}
}
