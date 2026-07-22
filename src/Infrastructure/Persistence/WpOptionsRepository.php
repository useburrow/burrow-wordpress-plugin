<?php
/**
 * Plugin configuration persistence in WordPress options.
 *
 * Secrets (ingestion key) are encrypted at rest using SecretStore and
 * transparently decrypted on read so callers never handle ciphertext.
 *
 * @package Burrow
 */

namespace BurrowWP\Infrastructure\Persistence;

use BurrowWP\Core\Auth\SecretStore;
use BurrowWP\Core\Config\BaseUrlResolver;

class WpOptionsRepository {
	/**
	 * @var string
	 */
	private $option_name = 'burrow_settings';

	/**
	 * Fetch settings with defaults, decrypting secrets.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$settings = get_option( $this->option_name, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args(
			$settings,
			array(
				'base_url'            => BaseUrlResolver::DEFAULT_BASE_URL,
				'ingestion_key'       => array(
					'key'       => '',
					'projectId' => '',
					'keyPrefix' => '',
				),
				'burrow_project'      => array(
					'path' => '',
					'url'  => '',
				),
				'sdk_state'           => array(
					'ingestionKey'         => '',
					'projectId'            => '',
					'formsProjectSourceId' => '',
					'contractsVersion'     => '',
					'contractMappings'     => array(),
					'clientId'             => '',
				),
				'routing'             => array(
					'organizationId' => '',
					'clientId'       => '',
					'projectId'      => '',
					'projectSourceId'=> '',
					'sourceIds'      => array(
						'forms'     => '',
						'ecommerce' => '',
						'system'    => '',
					),
				),
				'capabilities'        => array(
					'ecommerce_funnel' => false,
				),
				'checkout_sessions'   => array(),
				'forms_contracts'     => array(),
				'forms_contract_cache'=> array(),
				'selected_forms'      => array(),
				'contract_sync'       => array(
					'version'  => '',
					'hash'     => '',
					'syncedAt' => '',
				),
				'cleanup_on_uninstall'=> false,
				'max_attempts'        => 6,
				'queue_cap'           => 1000,
				'outbox_retention_days' => 30,
			)
		);

		self::decrypt_secrets( $settings );

		return $settings;
	}

	/**
	 * Persist settings, encrypting secrets before storage.
	 *
	 * @param array<string,mixed> $settings Settings payload.
	 * @return bool
	 */
	public function save_settings( array $settings ) {
		self::encrypt_secrets( $settings );
		return update_option( $this->option_name, $settings );
	}

	/**
	 * Save one key in settings.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function save_partial( $key, $value ) {
		$settings         = $this->get_settings();
		$settings[ $key ] = $value;
		return $this->save_settings( $settings );
	}

	/**
	 * Decrypt secret fields in-place after loading from the database.
	 *
	 * @param array<string,mixed> $settings Settings (by reference).
	 */
	private static function decrypt_secrets( array &$settings ): void {
		if ( isset( $settings['ingestion_key']['key'] ) && is_string( $settings['ingestion_key']['key'] ) ) {
			$settings['ingestion_key']['key'] = SecretStore::decrypt( $settings['ingestion_key']['key'] );
		}
		if ( isset( $settings['sdk_state']['ingestionKey'] ) && is_string( $settings['sdk_state']['ingestionKey'] ) ) {
			$settings['sdk_state']['ingestionKey'] = SecretStore::decrypt( $settings['sdk_state']['ingestionKey'] );
		}
	}

	/**
	 * Encrypt secret fields in-place before writing to the database.
	 *
	 * @param array<string,mixed> $settings Settings (by reference).
	 */
	private static function encrypt_secrets( array &$settings ): void {
		if ( isset( $settings['ingestion_key']['key'] ) && is_string( $settings['ingestion_key']['key'] ) && '' !== $settings['ingestion_key']['key'] ) {
			$settings['ingestion_key']['key'] = SecretStore::encrypt( $settings['ingestion_key']['key'] );
		}
		if ( isset( $settings['sdk_state']['ingestionKey'] ) && is_string( $settings['sdk_state']['ingestionKey'] ) && '' !== $settings['sdk_state']['ingestionKey'] ) {
			$settings['sdk_state']['ingestionKey'] = SecretStore::encrypt( $settings['sdk_state']['ingestionKey'] );
		}
	}
}
