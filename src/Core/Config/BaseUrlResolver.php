<?php
/**
 * Resolve Burrow API base URL with env override support.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Config;

class BaseUrlResolver {
	public const DEFAULT_BASE_URL = 'https://app.useburrow.com';

	/**
	 * Resolve the effective Burrow base URL.
	 *
	 * Order: BURROW_BASE_URL env → saved settings base_url → default app host.
	 *
	 * @param array<string,mixed>|string|null $settings_or_url Settings array or explicit URL.
	 * @return string
	 */
	public static function resolve( $settings_or_url = null ) {
		$from_env = self::env_base_url();
		if ( '' !== $from_env ) {
			return $from_env;
		}

		if ( is_string( $settings_or_url ) ) {
			$candidate = trim( $settings_or_url );
			return '' !== $candidate ? rtrim( $candidate, '/' ) : self::DEFAULT_BASE_URL;
		}

		if ( is_array( $settings_or_url ) ) {
			$candidate = isset( $settings_or_url['base_url'] ) ? trim( (string) $settings_or_url['base_url'] ) : '';
			if ( '' !== $candidate ) {
				return rtrim( $candidate, '/' );
			}
		}

		return self::DEFAULT_BASE_URL;
	}

	/**
	 * Read BURROW_BASE_URL from the environment when present.
	 *
	 * @return string
	 */
	public static function env_base_url() {
		$value = '';
		if ( function_exists( 'getenv' ) ) {
			$env = getenv( 'BURROW_BASE_URL' );
			if ( false !== $env && null !== $env ) {
				$value = trim( (string) $env );
			}
		}
		if ( '' === $value && isset( $_ENV['BURROW_BASE_URL'] ) ) {
			$value = trim( (string) $_ENV['BURROW_BASE_URL'] );
		}
		if ( '' === $value && isset( $_SERVER['BURROW_BASE_URL'] ) ) {
			$value = trim( (string) $_SERVER['BURROW_BASE_URL'] );
		}
		return '' !== $value ? rtrim( $value, '/' ) : '';
	}
}
