<?php
/**
 * Resolve dispatch credentials for project-scoped ingestion.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Auth;

class DispatchCredentials {
	/**
	 * Resolve API key for event dispatch.
	 *
	 * @param string              $default_api_key Fallback key.
	 * @param array<string,mixed> $ingestion_key Ingestion key state.
	 * @return string
	 */
	public static function resolve_dispatch_api_key( $default_api_key, array $ingestion_key ) {
		$scoped = isset( $ingestion_key['key'] ) ? trim( (string) $ingestion_key['key'] ) : '';
		if ( '' !== $scoped ) {
			return $scoped;
		}
		return trim( (string) $default_api_key );
	}

	/**
	 * Resolve linked project id for dispatch enforcement.
	 *
	 * @param string              $routing_project_id Routing project id.
	 * @param array<string,mixed> $ingestion_key Ingestion key state.
	 * @return string
	 */
	public static function resolve_dispatch_project_id( $routing_project_id, array $ingestion_key ) {
		$routing = trim( (string) $routing_project_id );
		if ( '' !== $routing ) {
			return $routing;
		}
		return isset( $ingestion_key['projectId'] ) ? trim( (string) $ingestion_key['projectId'] ) : '';
	}
}
