<?php
/**
 * Plugin configuration persistence in WordPress options.
 *
 * @package Burrow
 */

namespace BurrowWP\Infrastructure\Persistence;

class WpOptionsRepository {
	/**
	 * Option key.
	 *
	 * @var string
	 */
	private $option_name = 'burrow_settings';

	/**
	 * Fetch settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$settings = get_option( $this->option_name, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args(
			$settings,
			array(
				'api_key'             => '',
				'base_url'            => 'https://api.useburrow.com',
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
				'forms_contracts'     => array(),
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
	}

	/**
	 * Persist settings.
	 *
	 * @param array<string,mixed> $settings Settings payload.
	 * @return bool
	 */
	public function save_settings( array $settings ) {
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
}
