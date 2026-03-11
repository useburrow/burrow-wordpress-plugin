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
	 * @param array<string,mixed> $settings Existing settings.
	 * @param array<string,mixed> $body Link response body.
	 * @return array<string,mixed>
	 */
	public static function apply_link_response( array $settings, array $body ) {
		$candidates = array( $body );
		if ( isset( $body['routing'] ) && is_array( $body['routing'] ) ) {
			$candidates[] = $body['routing'];
		}
		if ( isset( $body['selection'] ) && is_array( $body['selection'] ) ) {
			$candidates[] = $body['selection'];
		}
		if ( isset( $body['project'] ) && is_array( $body['project'] ) ) {
			$candidates[] = $body['project'];
		}

		foreach ( $candidates as $candidate ) {
			foreach ( array( 'organizationId', 'clientId', 'projectId' ) as $k ) {
				if ( isset( $candidate[ $k ] ) && '' !== trim( (string) $candidate[ $k ] ) ) {
					$settings['routing'][ $k ] = (string) $candidate[ $k ];
				}
			}
			if ( isset( $candidate['projectSourceId'] ) && '' !== trim( (string) $candidate['projectSourceId'] ) ) {
				$settings['routing']['projectSourceId'] = (string) $candidate['projectSourceId'];
			}
			if ( ! empty( $candidate['sourceIds'] ) && is_array( $candidate['sourceIds'] ) ) {
				$current = isset( $settings['routing']['sourceIds'] ) && is_array( $settings['routing']['sourceIds'] )
					? $settings['routing']['sourceIds']
					: array();
				$settings['routing']['sourceIds'] = array_merge( $current, $candidate['sourceIds'] );
			}
		}

		$ingestion = isset( $body['ingestionKey'] ) && is_array( $body['ingestionKey'] ) ? $body['ingestionKey'] : array();
		$settings['ingestion_key'] = array(
			'key'       => isset( $ingestion['key'] ) ? trim( (string) $ingestion['key'] ) : '',
			'projectId' => isset( $ingestion['projectId'] ) ? trim( (string) $ingestion['projectId'] ) : '',
			'keyPrefix' => isset( $ingestion['keyPrefix'] ) ? trim( (string) $ingestion['keyPrefix'] ) : '',
		);

		$project = isset( $body['project'] ) && is_array( $body['project'] ) ? $body['project'] : array();
		$settings['burrow_project'] = array(
			'path' => isset( $project['burrowProjectPath'] ) ? trim( (string) $project['burrowProjectPath'] ) : '',
			'url'  => isset( $project['burrowProjectUrl'] ) ? trim( (string) $project['burrowProjectUrl'] ) : '',
		);

		if ( empty( $settings['routing']['projectId'] ) && ! empty( $settings['ingestion_key']['projectId'] ) ) {
			$settings['routing']['projectId'] = (string) $settings['ingestion_key']['projectId'];
		}

		return $settings;
	}

	/**
	 * Resolve project deep-link URL from settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function project_url_from_settings( array $settings ) {
		$path = '';
		if ( isset( $settings['burrow_project']['path'] ) ) {
			$path = trim( (string) $settings['burrow_project']['path'] );
		}

		$url = '';
		if ( isset( $settings['burrow_project']['url'] ) ) {
			$url = trim( (string) $settings['burrow_project']['url'] );
		}

		if ( '' === $path && '' !== $url ) {
			$parsed_path = parse_url( $url, PHP_URL_PATH );
			if ( is_string( $parsed_path ) ) {
				$path = trim( $parsed_path );
			}
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

		if ( '' === $url ) {
			return '';
		}

		$valid = filter_var( $url, FILTER_VALIDATE_URL );
		return false === $valid ? '' : (string) $valid;
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
