<?php
/**
 * Encrypt and decrypt secrets at rest using WordPress salts.
 *
 * Prefers libsodium (bundled in PHP 7.2+), falls back to OpenSSL AES-256-CBC.
 * All stored values must carry the "enc:" prefix; unrecognised values are
 * treated as missing (returns empty string).
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Auth;

class SecretStore {
	private const PREFIX = 'enc:';
	private const CIPHER = 'aes-256-cbc';

	/**
	 * @param string $plaintext Secret value.
	 * @return string Opaque ciphertext safe for wp_options storage.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return self::sodium_encrypt( $plaintext );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			return self::openssl_encrypt_value( $plaintext );
		}

		throw new \RuntimeException( 'Neither libsodium nor OpenSSL is available for secret encryption.' );
	}

	/**
	 * @param string $stored Ciphertext from a previous encrypt() call.
	 * @return string Decrypted secret, or empty string on failure.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return '';
		}

		$payload = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $payload ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return self::sodium_decrypt( $payload );
		}

		if ( function_exists( 'openssl_decrypt' ) ) {
			return self::openssl_decrypt_value( $payload );
		}

		return '';
	}

	// ── Sodium (preferred) ───────────────────────────

	private static function sodium_encrypt( string $plaintext ): string {
		$key   = self::derive_sodium_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ct    = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		return self::PREFIX . base64_encode( $nonce . $ct );
	}

	private static function sodium_decrypt( string $payload ): string {
		$key       = self::derive_sodium_key();
		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

		if ( strlen( $payload ) < $nonce_len + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}

		$nonce = substr( $payload, 0, $nonce_len );
		$ct    = substr( $payload, $nonce_len );
		$pt    = sodium_crypto_secretbox_open( $ct, $nonce, $key );

		return false === $pt ? '' : $pt;
	}

	private static function derive_sodium_key(): string {
		$raw = self::key_material();
		if ( function_exists( 'sodium_crypto_generichash' ) ) {
			return sodium_crypto_generichash( $raw, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		return substr( hash( 'sha256', $raw, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	// ── OpenSSL fallback ─────────────────────────────

	private static function openssl_encrypt_value( string $plaintext ): string {
		$key    = self::derive_openssl_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = random_bytes( $iv_len );
		$ct     = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ct ) {
			throw new \RuntimeException( 'OpenSSL encryption failed.' );
		}

		$hmac = hash_hmac( 'sha256', $iv . $ct, $key, true );

		return self::PREFIX . base64_encode( $iv . $ct . $hmac );
	}

	private static function openssl_decrypt_value( string $payload ): string {
		$key      = self::derive_openssl_key();
		$iv_len   = openssl_cipher_iv_length( self::CIPHER );
		$hmac_len = 32;

		if ( strlen( $payload ) < $iv_len + $hmac_len + 1 ) {
			return '';
		}

		$iv   = substr( $payload, 0, $iv_len );
		$hmac = substr( $payload, -$hmac_len );
		$ct   = substr( $payload, $iv_len, -$hmac_len );

		$expected = hash_hmac( 'sha256', $iv . $ct, $key, true );
		if ( ! hash_equals( $expected, $hmac ) ) {
			return '';
		}

		$pt = openssl_decrypt( $ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false === $pt ? '' : $pt;
	}

	private static function derive_openssl_key(): string {
		return hash( 'sha256', self::key_material(), true );
	}

	// ── Shared key material ──────────────────────────

	private static function key_material(): string {
		$auth = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		$sec  = function_exists( 'wp_salt' ) ? wp_salt( 'secure_auth' ) : '';
		return $auth . '|' . $sec;
	}
}
