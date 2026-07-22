<?php
/**
 * Admin onboarding + operations UI.
 *
 * @package Burrow
 */
class Burrow_Admin {
	private $plugin_name;
	private $version;
	private $options_repo;
	private $outbox_repo;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name  = $plugin_name;
		$this->version      = $version;
		$this->options_repo = new BurrowWP\Infrastructure\Persistence\WpOptionsRepository();
		$this->outbox_repo  = new BurrowWP\Infrastructure\Persistence\WpOutboxRepository();
	}

	/**
	 * Build an API client for post-onboarding admin operations.
	 * Uses the ingestion key as the auth credential (the API key is never stored).
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return BurrowWP\Infrastructure\Http\BurrowApiClient
	 */
	private function build_admin_api_client( array $settings ) {
		$ingestion_key = isset( $settings['ingestion_key'] ) && is_array( $settings['ingestion_key'] ) ? $settings['ingestion_key'] : array();
		$auth_key      = BurrowWP\Core\Auth\DispatchCredentials::resolve_dispatch_api_key( '', $ingestion_key );
		return new BurrowWP\Infrastructure\Http\BurrowApiClient(
			BurrowWP\Core\Config\BaseUrlResolver::resolve( $settings ),
			$auth_key,
			5,
			$ingestion_key,
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
	}

	/**
	 * Effective Burrow API base URL (env override → saved → default).
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string
	 */
	private function resolve_base_url( array $settings ) {
		return BurrowWP\Core\Config\BaseUrlResolver::resolve( $settings );
	}

	public function enqueue_styles() {}
	public function enqueue_scripts() {}

	public function add_settings_page() {
		$settings  = $this->options_repo->get_settings();
		$completed = $this->is_onboarding_complete( $settings );

		$parent_slug    = $completed ? 'burrow-dashboard' : 'burrow-setup';
		$parent_render  = $completed ? 'render_dashboard_page' : 'render_onboarding_page';

		add_menu_page(
			__( 'Burrow', 'burrow' ),
			__( 'Burrow', 'burrow' ),
			'manage_options',
			$parent_slug,
			array( $this, $parent_render ),
			$this->get_menu_icon_data_uri(),
			81
		);

		if ( $completed ) {
			add_submenu_page( $parent_slug, __( 'Dashboard', 'burrow' ), __( 'Dashboard', 'burrow' ), 'manage_options', 'burrow-dashboard', array( $this, 'render_dashboard_page' ) );
			add_submenu_page( $parent_slug, __( 'Settings', 'burrow' ), __( 'Settings', 'burrow' ), 'manage_options', 'burrow-setup', array( $this, 'render_setup_summary_page' ) );
		} else {
			add_submenu_page( $parent_slug, __( 'Setup', 'burrow' ), __( 'Setup', 'burrow' ), 'manage_options', 'burrow-setup', array( $this, 'render_onboarding_page' ) );
		}

		add_submenu_page( $parent_slug, __( 'Outbox', 'burrow' ), __( 'Outbox', 'burrow' ), 'manage_options', 'burrow-outbox', array( $this, 'render_outbox_page' ) );
	}

	/**
	 * Whether the onboarding wizard has been fully completed.
	 */
	public function is_onboarding_complete( array $settings ) {
		$has_key = ! empty( $settings['ingestion_key']['key'] );
		if ( ! $has_key || empty( $settings['routing']['projectId'] ) ) {
			return false;
		}
		$selected = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		if ( empty( $selected ) ) {
			return false;
		}
		if ( empty( $settings['contract_sync']['syncedAt'] ) ) {
			return false;
		}
		return true;
	}

	public function register_settings() {}

	public function maybe_redirect_after_activation() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( 'burrow_do_activation_redirect', false ) ) {
			return;
		}
		delete_option( 'burrow_do_activation_redirect' );
		$settings = $this->options_repo->get_settings();
		if ( $this->is_onboarding_complete( $settings ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=burrow-dashboard' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=burrow-setup&step=connection' ) );
		}
		exit;
	}

	public function maybe_handle_admin_actions() {
		if ( empty( $_POST['burrow_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'burrow_admin_action', 'burrow_nonce' );

		$action   = sanitize_key( wp_unslash( $_POST['burrow_action'] ) );
		$settings = $this->options_repo->get_settings();
		$message  = __( 'Action completed.', 'burrow' );
		$step     = 'connection';
		$notice_is_error = false;

		if ( 'setup_connection' === $action ) {
			$settings['base_url'] = esc_url_raw( (string) wp_unslash( $_POST['base_url'] ?? '' ) );
			$onboarding_api_key   = sanitize_text_field( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
			if ( empty( $settings['base_url'] ) ) {
				$settings['base_url'] = BurrowWP\Core\Config\BaseUrlResolver::DEFAULT_BASE_URL;
			}

			if ( '' === trim( $onboarding_api_key ) ) {
				$message         = __( 'Please enter your API key before continuing.', 'burrow' );
				$step            = 'connection';
				$notice_is_error = true;
			} else {
				$client = new BurrowWP\Infrastructure\Http\BurrowApiClient(
					$this->resolve_base_url( $settings ),
					$onboarding_api_key,
					5,
					isset( $settings['ingestion_key'] ) && is_array( $settings['ingestion_key'] ) ? $settings['ingestion_key'] : array(),
					isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
				);
				$res    = $client->discover( $this->build_discover_payload() );
				if ( empty( $res['ok'] ) ) {
					$message         = __( 'Connection failed.', 'burrow' ) . ' ' . ( $res['error'] ?? '' );
					$notice_is_error = true;
				} else {
					set_transient( 'burrow_onboarding_api_key', $onboarding_api_key, HOUR_IN_SECONDS );
					$body                                   = (array) ( $res['body'] ?? array() );
					$settings['project_candidates']         = $this->extract_project_candidates( $body );
					$settings['routing']['organizationId']  = (string) ( $body['organizationId'] ?? $settings['routing']['organizationId'] );
					$settings['onboarding']                 = $this->reset_onboarding_state( $settings['onboarding'] ?? array() );
					$message                                = __( 'Connected. Select a project to continue.', 'burrow' );
					$step                                   = 'project';
				}
			}
			$this->options_repo->save_settings( $settings );
		} elseif ( 'select_project' === $action ) {
			$index      = (int) ( $_POST['project_index'] ?? -1 );
			$candidates = isset( $settings['project_candidates'] ) && is_array( $settings['project_candidates'] ) ? $settings['project_candidates'] : array();
			$step       = 'project';
			$onboarding_api_key = (string) get_transient( 'burrow_onboarding_api_key' );
			if ( '' === $onboarding_api_key ) {
				$message         = __( 'Session expired. Please re-enter your API key.', 'burrow' );
				$step            = 'connection';
				$notice_is_error = true;
			} elseif ( ! isset( $candidates[ $index ] ) ) {
				$message         = __( 'Please select a project.', 'burrow' );
				$notice_is_error = true;
			} else {
				$selected = $candidates[ $index ];
				$client   = new BurrowWP\Infrastructure\Http\BurrowApiClient(
					$this->resolve_base_url( $settings ),
					$onboarding_api_key,
					5,
					isset( $settings['ingestion_key'] ) && is_array( $settings['ingestion_key'] ) ? $settings['ingestion_key'] : array(),
					isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
				);
				$res      = $client->link(
					array(
						'site'      => array( 'url' => site_url() ),
						'selection' => array(
							'organizationId' => (string) $selected['organizationId'],
							'clientId'       => (string) $selected['clientId'],
							'projectId'      => (string) $selected['projectId'],
						),
						'platform'  => 'wordpress',
						'capabilities' => array(
							'forms'            => array_values( $this->detect_forms_capabilities() ),
							'ecommerce'        => class_exists( 'WooCommerce' ) ? array( 'woocommerce' ) : array(),
							'ecommerce_funnel' => class_exists( 'WooCommerce' ),
							'system'           => true,
						),
					)
				);
				$message = $this->persist_link_response( $res );
				$step    = ! empty( $res['ok'] ) ? 'integrations' : 'project';
				if ( empty( $res['ok'] ) ) {
					$notice_is_error = true;
				}
				if ( ! empty( $res['ok'] ) ) {
					delete_transient( 'burrow_onboarding_api_key' );
				}
			}
		} elseif ( 'save_integrations' === $action ) {
			$selected = isset( $_POST['integrations'] ) && is_array( $_POST['integrations'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['integrations'] ) ) : array();
			if ( empty( $selected ) ) {
				$message = __( 'Select at least one integration to continue.', 'burrow' );
				$step    = 'integrations';
				$this->redirect_with_notice( $step, $message, true );
			}
			$settings_mode = ! empty( $_POST['settings_mode'] ) || $this->is_onboarding_complete( $settings );
			$previous      = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
			$selected      = array_values( array_unique( $selected ) );
			$settings['onboarding']['selected_integrations'] = $selected;
			if ( ! $settings_mode ) {
				$settings['onboarding']['gravity_configured']    = false;
				$settings['onboarding']['woocommerce_confirmed'] = false;
				$settings['onboarding']['woocommerce_mode']      = in_array( 'woocommerce', $selected, true ) ? 'track' : 'off';
				$settings['onboarding']['provider_configured']   = array();
			} else {
				$configured = isset( $settings['onboarding']['provider_configured'] ) && is_array( $settings['onboarding']['provider_configured'] )
					? $settings['onboarding']['provider_configured']
					: array();
				foreach ( array_keys( $configured ) as $provider_key ) {
					if ( ! in_array( $provider_key, $selected, true ) ) {
						unset( $configured[ $provider_key ] );
					}
				}
				$settings['onboarding']['provider_configured'] = $configured;
				if ( ! in_array( 'woocommerce', $selected, true ) ) {
					$settings['onboarding']['woocommerce_mode']      = 'off';
					$settings['onboarding']['woocommerce_confirmed'] = false;
					if ( isset( $settings['capabilities'] ) && is_array( $settings['capabilities'] ) ) {
						$settings['capabilities']['ecommerce_funnel'] = false;
					}
				} elseif ( ! in_array( 'woocommerce', $previous, true ) ) {
					$settings['onboarding']['woocommerce_mode']      = 'track';
					$settings['onboarding']['woocommerce_confirmed'] = false;
				}
				$settings['forms_contracts'] = $this->prune_contracts_for_selected_integrations( $settings, $selected );
			}
			$this->options_repo->save_settings( $settings );
			if ( $settings_mode ) {
				$sync_message = $this->autosync_forms_contract( $settings );
				$message      = __( 'Integrations updated and synced to Burrow.', 'burrow' );
				if ( '' !== $sync_message ) {
					$message .= ' ' . $sync_message;
				}
				$step = 'integrations';
			} else {
				$message = __( 'Integrations selected.', 'burrow' );
				$step    = $this->next_config_step( $settings, 'integrations' );
			}
		} elseif ( 'save_gravity_contracts' === $action ) {
			$gravity                        = isset( $_POST['gravity'] ) ? wp_unslash( $_POST['gravity'] ) : array();
			$error_message                  = '';
			$merged_contracts               = $this->merge_gravity_contracts( $settings, $gravity, $error_message );
			$settings_mode                  = ! empty( $_POST['settings_mode'] ) || $this->is_onboarding_complete( $settings );
			if ( '' !== $error_message ) {
				$message         = $error_message;
				$step            = 'gravity-forms';
				$notice_is_error = true;
			} else {
				$settings['forms_contracts']    = $merged_contracts;
				$settings['onboarding']['gravity_configured'] = true;
				$settings['onboarding']['provider_configured']['gravity-forms'] = true;
				$this->options_repo->save_settings( $settings );
				$message = __( 'Gravity Forms configuration saved.', 'burrow' );
				if ( $settings_mode ) {
					$sync_message = $this->autosync_forms_contract( $settings );
					if ( '' !== $sync_message ) {
						$message .= ' ' . $sync_message;
					} else {
						$message = __( 'Gravity Forms saved and synced to Burrow.', 'burrow' );
					}
					$step = 'gravity-forms';
				} else {
					$step = $this->next_config_step( $settings, 'gravity-forms' );
				}
			}
		} elseif ( 'save_provider_contracts' === $action ) {
			$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
			$allowed  = array( 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms' );
			$step     = $provider;
			$settings_mode = ! empty( $_POST['settings_mode'] ) || $this->is_onboarding_complete( $settings );
			if ( ! in_array( $provider, $allowed, true ) ) {
				$message         = __( 'Invalid provider selected.', 'burrow' );
				$notice_is_error = true;
			} else {
				$provider_forms = isset( $_POST['provider_forms'] ) ? wp_unslash( $_POST['provider_forms'] ) : array();
				$error_message = '';
				$merged_contracts = $this->merge_simple_provider_contracts( $settings, $provider, $provider_forms, $error_message );
				if ( '' !== $error_message ) {
					$message         = $error_message;
					$notice_is_error = true;
				} else {
					$settings['forms_contracts'] = $merged_contracts;
					$settings['onboarding']['provider_configured'][ $provider ] = true;
					$this->options_repo->save_settings( $settings );
					$labels  = $this->integration_labels();
					$message = sprintf( __( '%s configuration saved.', 'burrow' ), (string) ( $labels[ $provider ] ?? $provider ) );
					if ( $settings_mode ) {
						$sync_message = $this->autosync_forms_contract( $settings );
						if ( '' !== $sync_message ) {
							$message .= ' ' . $sync_message;
						} else {
							$message = sprintf( __( '%s saved and synced to Burrow.', 'burrow' ), (string) ( $labels[ $provider ] ?? $provider ) );
						}
						$step = $provider;
					} else {
						$step = $this->next_config_step( $settings, $provider );
					}
				}
			}
		} elseif ( 'confirm_woocommerce' === $action ) {
			$mode = isset( $_POST['woocommerce_mode'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce_mode'] ) ) : 'track';
			if ( ! in_array( $mode, array( 'track', 'off' ), true ) ) {
				$mode = 'track';
			}
			$funnel_enabled = ! empty( $_POST['ecommerce_funnel'] );
			$selected = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
			if ( 'off' === $mode ) {
				$selected       = array_values( array_diff( $selected, array( 'woocommerce' ) ) );
				$funnel_enabled = false;
			} elseif ( ! in_array( 'woocommerce', $selected, true ) ) {
				$selected[] = 'woocommerce';
			}
			$settings['onboarding']['selected_integrations'] = $selected;
			$settings['onboarding']['woocommerce_mode']      = $mode;
			$settings['onboarding']['woocommerce_confirmed'] = true;
			if ( ! isset( $settings['capabilities'] ) || ! is_array( $settings['capabilities'] ) ) {
				$settings['capabilities'] = array();
			}
			$settings['capabilities']['ecommerce_funnel'] = $funnel_enabled;
			$this->options_repo->save_settings( $settings );
			$message = 'off' === $mode
				? __( 'WooCommerce tracking disabled.', 'burrow' )
				: __( 'WooCommerce tracking enabled.', 'burrow' );
			$settings_mode = ! empty( $_POST['settings_mode'] ) || $this->is_onboarding_complete( $settings );
			if ( $settings_mode ) {
				$sync_message = $this->autosync_forms_contract( $settings );
				if ( '' !== $sync_message ) {
					$message .= ' ' . $sync_message;
				} else {
					$message .= ' ' . __( 'Synced to Burrow.', 'burrow' );
				}
				$step = 'woocommerce';
			} else {
				$step = $this->next_config_step( $settings, 'woocommerce' );
			}
		} elseif ( 'sync_forms_contract' === $action ) {
			$routing_error = $this->validate_routing_before_contract_sync( $settings );
			if ( '' !== $routing_error ) {
				$message = $routing_error;
				$step    = 'project';
				$this->redirect_with_notice( $step, $message, true );
			}
			$client = $this->build_admin_api_client( $settings );
			$res    = $client->submit_forms_contract( $this->build_forms_contract_payload( $settings ) );
			$message = $this->persist_contract_response( $res );
			if ( empty( $res['ok'] ) ) {
				$notice_is_error = true;
				$step            = 'review';
			} else {
				do_action( 'burrow_invalidate_delivery' );
				do_action( 'burrow_system_stack_snapshot' );
				do_action( 'burrow_system_heartbeat' );
				do_action( 'burrow_outbox_worker' );
				$step = 'backfill';
			}
		} elseif ( 'queue_backfill' === $action ) {
			if ( empty( $settings['contract_sync']['syncedAt'] ) ) {
				$step    = 'review';
				$message = __( 'Sync contracts to Burrow before starting backfill.', 'burrow' );
				$this->redirect_with_notice( $step, $message, true );
			}
			$preset = isset( $_POST['backfill_window_preset'] ) ? sanitize_key( wp_unslash( $_POST['backfill_window_preset'] ) ) : 'last_730_days';
			$step       = 'dashboard';
			$queue_error = '';
			$existing_job = isset( $settings['backfill'] ) && is_array( $settings['backfill'] ) ? $settings['backfill'] : array();
			$existing_status = isset( $existing_job['status'] ) ? (string) $existing_job['status'] : 'idle';
			if ( in_array( $existing_status, array( 'queued', 'running' ), true ) ) {
				$message         = __( 'A backfill is already in progress. Wait for it to finish or cancel it before starting a new one.', 'burrow' );
				$notice_is_error = true;
			} else {
				$window = $this->resolve_backfill_window_for_preset( $preset );
				if ( ! empty( $window['error'] ) ) {
					$queue_error = (string) $window['error'];
				}
				if ( '' === $queue_error ) {
					$posted_sources       = isset( $_POST['backfill_sources'] ) ? wp_unslash( $_POST['backfill_sources'] ) : array();
					$selected_sources     = $this->sanitize_selected_backfill_sources( $settings, $posted_sources );
					$active_contract_keys = $this->build_backfill_active_keys( $settings, $selected_sources );
					if ( empty( $active_contract_keys ) ) {
						$message         = __( 'No configured forms or WooCommerce tracking found for backfill.', 'burrow' );
						$notice_is_error = true;
					} else {
						$now_iso = gmdate( 'c' );
						$window_start = (string) $window['windowStart'];
						$window_end   = (string) $window['windowEnd'];
						$settings['backfill'] = array(
							'status'             => 'queued',
							'windowPreset'       => (string) $window['preset'],
							'windowStart'        => $window_start,
							'windowEnd'          => $window_end,
							'scope'              => 'selected_sources',
							'selectedSources'    => $selected_sources,
							'cursor'             => array(),
							'activeContractKeys' => $active_contract_keys,
							'totalForms'         => count( $active_contract_keys ),
							'completedForms'     => 0,
							'processedEvents'    => 0,
							'lastError'          => '',
							'queuedAt'           => $now_iso,
							'startedAt'          => '',
							'completedAt'        => '',
							'updatedAt'          => $now_iso,
							'batchSize'          => 100,
							'perKeyConcurrency'  => 4,
						);
						$this->options_repo->save_settings( $settings );
						do_action( 'burrow_invalidate_delivery' );
						do_action( 'burrow_system_stack_snapshot' );
						wp_schedule_single_event( time() + 5, 'burrow_backfill_worker' );
						$message = __( 'Backfill job queued.', 'burrow' );
					}
				} else {
					$message         = $queue_error;
					$notice_is_error = true;
				}
			}
		} elseif ( 'cancel_backfill' === $action ) {
			$step = 'dashboard';
			$job  = isset( $settings['backfill'] ) && is_array( $settings['backfill'] ) ? $settings['backfill'] : array();
			$job['status']    = 'cancelled';
			$job['lastError'] = '';
			$job['updatedAt'] = gmdate( 'c' );
			$settings['backfill'] = $job;
			$this->options_repo->save_settings( $settings );
			$message = __( 'Backfill cancelled.', 'burrow' );
		} elseif ( 'resume_backfill' === $action ) {
			$step = 'dashboard';
			$job  = isset( $settings['backfill'] ) && is_array( $settings['backfill'] ) ? $settings['backfill'] : array();
			$job['status']    = 'queued';
			$job['lastError'] = '';
			$selected_sources = isset( $job['selectedSources'] ) && is_array( $job['selectedSources'] ) ? $job['selectedSources'] : array();
			$job['selectedSources']    = $this->sanitize_selected_backfill_sources( $settings, $selected_sources );
			$job['activeContractKeys'] = $this->build_backfill_active_keys( $settings, $job['selectedSources'] );
			$job['totalForms']         = count( (array) $job['activeContractKeys'] );
			$job['updatedAt'] = gmdate( 'c' );
			$settings['backfill'] = $job;
			$this->options_repo->save_settings( $settings );
			wp_schedule_single_event( time() + 5, 'burrow_backfill_worker' );
			$message = __( 'Backfill resumed from current cursor.', 'burrow' );
		} elseif ( 'retry_backfill' === $action ) {
			$step = 'dashboard';
			$job  = isset( $settings['backfill'] ) && is_array( $settings['backfill'] ) ? $settings['backfill'] : array();
			$job['status']          = 'queued';
			$job['cursor']          = array();
			$selected_sources       = isset( $job['selectedSources'] ) && is_array( $job['selectedSources'] ) ? $job['selectedSources'] : array();
			$job['selectedSources'] = $this->sanitize_selected_backfill_sources( $settings, $selected_sources );
			$job['activeContractKeys'] = $this->build_backfill_active_keys( $settings, $job['selectedSources'] );
			$job['totalForms']         = count( (array) $job['activeContractKeys'] );
			$job['completedForms']  = 0;
			$job['processedEvents'] = 0;
			$job['startedAt']       = '';
			$job['completedAt']     = '';
			$job['lastError']       = '';
			$job['updatedAt']       = gmdate( 'c' );
			$settings['backfill']   = $job;
			$this->options_repo->save_settings( $settings );
			wp_schedule_single_event( time() + 5, 'burrow_backfill_worker' );
			$message = __( 'Backfill restarted from beginning.', 'burrow' );
		} elseif ( 'save_operations_contract' === $action ) {
			$step            = 'dashboard';
			$posted_contract = isset( $_POST['operations_contract'] ) ? wp_unslash( $_POST['operations_contract'] ) : array();
			$contract_key    = sanitize_text_field( (string) wp_unslash( $_POST['operations_contract_key'] ?? '' ) );
			$error_message   = '';
			$settings        = $this->apply_operations_contract_edit(
				$settings,
				$contract_key,
				is_array( $posted_contract ) ? $posted_contract : array(),
				$error_message
			);
			$this->options_repo->save_settings( $settings );

			if ( '' !== $error_message ) {
				$message         = $error_message;
				$notice_is_error = true;
			} else {
				$message = __( 'Contracts updated.', 'burrow' );
				$should_sync = isset( $_POST['sync_contracts'] ) && '1' === (string) $_POST['sync_contracts'];
				if ( $should_sync ) {
					$routing_error = $this->validate_routing_before_contract_sync( $settings );
					if ( '' !== $routing_error ) {
						$message         = $routing_error;
						$notice_is_error = true;
				} else {
				$client = $this->build_admin_api_client( $settings );
						$res    = $client->submit_forms_contract( $this->build_forms_contract_payload( $settings ) );
						$message = $this->persist_contract_response( $res );
					}
				}
			}
		} elseif ( 'replay_failed' === $action ) {
			$ok      = $this->outbox_repo->replay_failed( (int) ( $_POST['outbox_id'] ?? 0 ) );
			$message = $ok ? __( 'Failed event queued for replay.', 'burrow' ) : __( 'Unable to replay event.', 'burrow' );
			$step    = isset( $_POST['return_step'] ) ? sanitize_key( (string) wp_unslash( $_POST['return_step'] ) ) : 'dashboard';
			if ( ! in_array( $step, array( 'dashboard', 'outbox' ), true ) ) {
				$step = 'dashboard';
			}
		} elseif ( 'replay_all_failed' === $action ) {
			$count = $this->outbox_repo->replay_all_failed();
			if ( $count > 0 ) {
				do_action( 'burrow_outbox_worker' );
			}
			$message = sprintf(
				/* translators: %d: number of failed outbox rows queued */
				_n( '%d failed event queued for retry.', '%d failed events queued for retry.', $count, 'burrow' ),
				$count
			);
			$step = isset( $_POST['return_step'] ) ? sanitize_key( (string) wp_unslash( $_POST['return_step'] ) ) : 'outbox';
			if ( ! in_array( $step, array( 'dashboard', 'outbox' ), true ) ) {
				$step = 'outbox';
			}
		} elseif ( 'retry_now' === $action ) {
			$ok      = $this->outbox_repo->retry_now( (int) ( $_POST['outbox_id'] ?? 0 ) );
			if ( $ok ) {
				do_action( 'burrow_outbox_worker' );
			}
			$message = $ok ? __( 'Event flushed for immediate delivery.', 'burrow' ) : __( 'Unable to retry event.', 'burrow' );
			$step    = 'outbox';
		} elseif ( 'delete_outbox_record' === $action ) {
			$ok      = $this->outbox_repo->delete_record( (int) ( $_POST['outbox_id'] ?? 0 ) );
			$message = $ok ? __( 'Outbox record deleted.', 'burrow' ) : __( 'Unable to delete record.', 'burrow' );
			$step    = 'outbox';
		} elseif ( 'save_operations_settings' === $action ) {
			$step = 'dashboard';
			$retention_days = isset( $_POST['outbox_retention_days'] ) ? (int) $_POST['outbox_retention_days'] : 30;
			$retention_days = max( 1, min( 365, $retention_days ) );
			$settings['outbox_retention_days'] = $retention_days;

			$woo_selected = in_array( 'woocommerce', (array) ( $settings['onboarding']['selected_integrations'] ?? array() ), true );
			if ( $woo_selected ) {
				if ( ! isset( $settings['capabilities'] ) || ! is_array( $settings['capabilities'] ) ) {
					$settings['capabilities'] = array();
				}
				$settings['capabilities']['ecommerce_funnel'] = ! empty( $_POST['ecommerce_funnel'] );
			}

			$this->options_repo->save_settings( $settings );
			$message = __( 'Settings saved.', 'burrow' );
		}

		$this->redirect_with_notice( $step, $message, $notice_is_error );
	}

	public function render_onboarding_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->options_repo->get_settings();
		$step     = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : $this->default_wizard_step( $settings );
		$steps    = $this->build_wizard_steps( $settings );
		$notice   = isset( $_GET['burrow_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['burrow_notice'] ) ) : '';
		$is_error = isset( $_GET['burrow_error'] ) && '1' === (string) $_GET['burrow_error'];
		$reconfigure = isset( $_GET['reconfigure'] ) && '1' === (string) $_GET['reconfigure'];
		?>
		<div class="wrap">
			<p><img src="<?php echo esc_attr( $this->get_brand_logo_src() ); ?>" alt="Burrow" style="max-width:220px;height:auto;margin:10px 0;display:block;" /></p>
			<h1><?php esc_html_e( 'Onboarding Wizard', 'burrow' ); ?></h1>
			<?php if ( $reconfigure ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Reconfigure mode — existing linked settings are preserved until you complete a new project link.', 'burrow' ); ?></p></div>
			<?php endif; ?>
			<style>
				.burrow-onboarding-layout {
					display: grid;
					grid-template-columns: 260px minmax(0, 1fr);
					gap: 24px;
					align-items: start;
					margin-top: 14px;
				}
				.burrow-wizard-sidebar {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 6px;
					padding: 14px 12px;
					position: sticky;
					top: 32px;
				}
				.burrow-wizard-content {
					min-width: 0;
				}
				.burrow-step-intro {
					margin: 2px 0 10px 0;
				}
				.burrow-step-intro .description {
					margin: 8px 0 0 0;
				}
				.burrow-step-header {
					margin: 2px 0 12px 0;
				}
				.burrow-step-header .description {
					margin: 8px 0 0 0;
				}
				.burrow-step-title {
					display: inline-flex;
					align-items: center;
					gap: 8px;
				}
				.burrow-step-title .burrow-integration-icon {
					width: 20px;
					height: 20px;
					display: inline-block;
					vertical-align: middle;
				}
				.burrow-step-title .burrow-menu-glyph {
					width: 24px;
					height: 24px;
					object-fit: contain;
					flex: 0 0 24px;
				}
				.burrow-step-title .dashicons {
					width: 24px;
					height: 24px;
					font-size: 24px;
					line-height: 24px;
					color: #2271b1;
				}
				.burrow-wizard-steps {
					list-style: none;
					margin: 0;
					padding: 0;
				}
				.burrow-wizard-steps li {
					margin: 0;
					padding: 0;
					border-left: 2px solid #dcdcde;
				}
				.burrow-wizard-steps a {
					display: block;
					padding: 8px 8px 8px 12px;
					text-decoration: none;
					color: #1d2327;
				}
				.burrow-wizard-steps li.is-current {
					border-left-color: #2271b1;
				}
				.burrow-wizard-steps li.is-current a {
					font-weight: 700;
					color: #2271b1;
					background: #f0f6fc;
				}
				.burrow-wizard-step-num {
					color: #50575e;
					margin-right: 6px;
				}
				.burrow-sidebar-back {
					margin-top: 14px;
					padding-top: 10px;
					border-top: 1px solid #e2e4e7;
				}
				.burrow-sidebar-back a {
					text-decoration: none;
				}
				@media (max-width: 1024px) {
					.burrow-onboarding-layout {
						grid-template-columns: 1fr;
					}
					.burrow-wizard-sidebar {
						position: static;
					}
				}
			</style>
			<?php if ( $notice ) : ?>
				<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<div class="burrow-step-header">
				<?php if ( 'connection' === $step ) : ?>
					<?php $this->render_step_heading( 'connection', __( 'Step 1: Connect to Burrow', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Enter your Burrow base URL and API key so we can validate access and discover your available projects.', 'burrow' ); ?></p>
				<?php elseif ( 'project' === $step ) : ?>
					<?php $this->render_step_heading( 'project', __( 'Step 2: Select Project', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Choose where events and contracts from this site should be routed in Burrow.', 'burrow' ); ?></p>
				<?php elseif ( 'integrations' === $step ) : ?>
					<?php $this->render_step_heading( 'integrations', __( 'Step 3: Select Integrations', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Select the installed plugins you want to configure. You can enable one or many and continue through each setup step.', 'burrow' ); ?></p>
				<?php elseif ( 'gravity-forms' === $step ) : ?>
					<?php $this->render_step_heading( 'gravity-forms', __( 'Gravity Forms Setup', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Select the forms and fields you want to include in Burrow contracts.', 'burrow' ); ?></p>
				<?php elseif ( 'contact-form-7' === $step ) : ?>
					<?php $this->render_step_heading( 'contact-form-7', __( 'Contact Form 7 Setup', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Choose tracking mode for each Contact Form 7 form.', 'burrow' ); ?></p>
				<?php elseif ( 'ninja-forms' === $step ) : ?>
					<?php $this->render_step_heading( 'ninja-forms', __( 'Ninja Forms Setup', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Choose tracking mode for each Ninja Form.', 'burrow' ); ?></p>
				<?php elseif ( 'fluent-forms' === $step ) : ?>
					<?php $this->render_step_heading( 'fluent-forms', __( 'Fluent Forms Setup', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Choose tracking mode for each Fluent Form.', 'burrow' ); ?></p>
				<?php elseif ( 'woocommerce' === $step ) : ?>
					<?php $this->render_step_heading( 'woocommerce', __( 'WooCommerce Setup', 'burrow' ) ); ?>
				<?php elseif ( 'backfill' === $step ) : ?>
					<?php $this->render_step_heading( 'backfill', __( 'Finish', 'burrow' ) ); ?>
					<p class="description"><?php esc_html_e( 'Your setup is finalized. Optionally queue a historical backfill or head to the Dashboard.', 'burrow' ); ?></p>
				<?php else : ?>
					<?php $this->render_step_heading( 'review', __( 'Review & Finish', 'burrow' ) ); ?>
				<?php endif; ?>
			</div>
			<div class="burrow-onboarding-layout">
				<aside class="burrow-wizard-sidebar">
					<?php $this->render_wizard_steps( $step, $steps, $settings ); ?>
				</aside>
				<section class="burrow-wizard-content">
					<?php if ( 'connection' === $step ) : ?>
						<form method="post">
							<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
							<input type="hidden" name="burrow_action" value="setup_connection" />
							<table class="form-table" role="presentation">
								<tr><th><label for="base_url"><?php esc_html_e( 'Burrow Base URL', 'burrow' ); ?></label></th><td><input name="base_url" id="base_url" type="url" class="regular-text" value="<?php echo esc_attr( $this->resolve_base_url( $settings ) ); ?>" placeholder="<?php echo esc_attr( BurrowWP\Core\Config\BaseUrlResolver::DEFAULT_BASE_URL ); ?>" required /><?php if ( '' !== BurrowWP\Core\Config\BaseUrlResolver::env_base_url() ) : ?><p class="description"><?php esc_html_e( 'Overridden by the BURROW_BASE_URL environment variable.', 'burrow' ); ?></p><?php endif; ?></td></tr>
								<tr><th><label for="api_key"><?php esc_html_e( 'API Key', 'burrow' ); ?></label></th><td><input name="api_key" id="api_key" type="password" class="regular-text" value="" autocomplete="off" required /><p class="description"><?php esc_html_e( 'Used only to validate access during setup. Not stored after linking.', 'burrow' ); ?> <a href="https://app.useburrow.com/settings" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Find your API key', 'burrow' ); ?></a></p></td></tr>
							</table>
							<?php submit_button( __( 'Validate Connection & Continue', 'burrow' ) ); ?>
						</form>
					<?php elseif ( 'project' === $step ) : ?>
						<?php $this->render_project_picker( $settings ); ?>
					<?php elseif ( 'integrations' === $step ) : ?>
						<?php $this->render_integration_selection_step( $settings ); ?>
					<?php elseif ( 'gravity-forms' === $step ) : ?>
						<?php $this->render_gravity_forms_step(); ?>
					<?php elseif ( 'contact-form-7' === $step ) : ?>
						<?php $this->render_simple_forms_provider_step( 'contact-form-7', $this->list_contact_form_7_forms() ); ?>
					<?php elseif ( 'ninja-forms' === $step ) : ?>
						<?php $this->render_simple_forms_provider_step( 'ninja-forms', $this->list_ninja_forms() ); ?>
					<?php elseif ( 'fluent-forms' === $step ) : ?>
						<?php $this->render_simple_forms_provider_step( 'fluent-forms', $this->list_fluent_forms() ); ?>
					<?php elseif ( 'wpforms' === $step ) : ?>
						<?php $this->render_simple_forms_provider_step( 'wpforms', $this->list_wpforms() ); ?>
					<?php elseif ( 'formidable-forms' === $step ) : ?>
						<?php $this->render_simple_forms_provider_step( 'formidable-forms', $this->list_formidable_forms() ); ?>
					<?php elseif ( 'woocommerce' === $step ) : ?>
						<?php $this->render_woocommerce_step(); ?>
					<?php elseif ( 'backfill' === $step ) : ?>
						<?php $this->render_backfill_step( $settings ); ?>
					<?php else : ?>
						<?php $this->render_review_step( $settings ); ?>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}

	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings      = $this->options_repo->get_settings();
		$retention_days = isset( $settings['outbox_retention_days'] ) ? max( 1, (int) $settings['outbox_retention_days'] ) : 30;
		$project_url   = BurrowWP\Core\Onboarding\LinkStateManager::project_url_from_settings( $settings );
		$ingestion_key_prefix = '';
		if ( isset( $settings['ingestion_key'] ) && is_array( $settings['ingestion_key'] ) ) {
			$ingestion_key_prefix = isset( $settings['ingestion_key']['keyPrefix'] ) ? trim( (string) $settings['ingestion_key']['keyPrefix'] ) : '';
		}
		$labels        = $this->integration_labels();
		$selected      = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		$woo_active    = in_array( 'woocommerce', $selected, true );
		$funnel_enabled = ! empty( $settings['capabilities']['ecommerce_funnel'] );
		$contracts     = isset( $settings['forms_contracts'] ) && is_array( $settings['forms_contracts'] ) ? $settings['forms_contracts'] : array();

		$contract_rows = array();
		foreach ( $contracts as $key => $contract ) {
			if ( ! is_array( $contract ) || empty( $contract['enabled'] ) ) {
				continue;
			}
			$contract_key = (string) $key;
			$provider_key = (string) ( $contract['provider'] ?? '' );
			$field_mappings = isset( $contract['fieldMappings'] ) && is_array( $contract['fieldMappings'] ) ? $contract['fieldMappings'] : array();
			$contract_rows[ $contract_key ] = array(
				'contractKey'   => $contract_key,
				'providerKey'   => $provider_key,
				'provider'      => (string) ( $labels[ $provider_key ] ?? $provider_key ),
				'formName'      => (string) ( $contract['formName'] ?? '' ),
				'externalFormId'=> (string) ( $contract['externalFormId'] ?? '' ),
				'mode'          => (string) ( $contract['mode'] ?? ( ! empty( $contract['countOnly'] ) ? 'count_only' : 'custom_fields' ) ),
				'icon'          => (string) ( $contract['icon'] ?? '' ),
				'mappingCount'  => count( $field_mappings ),
				'contract'      => $contract,
			);
		}
		$edit_contract_key = isset( $_GET['edit_contract'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['edit_contract'] ) ) : '';
		if ( '' !== $edit_contract_key && ! isset( $contract_rows[ $edit_contract_key ] ) ) {
			$edit_contract_key = '';
		}
		$editing_row = '' !== $edit_contract_key ? $contract_rows[ $edit_contract_key ] : null;

		$job    = $this->refresh_backfill_job_state( $settings );
		$bf_status = isset( $job['status'] ) ? (string) $job['status'] : 'idle';

		$outbox_counts = $this->outbox_repo->get_status_counts();
		?>
		<div class="wrap">
			<?php $this->render_admin_notice_from_query(); ?>
			<?php $this->render_burrow_page_header( __( 'Burrow Dashboard', 'burrow' ) ); ?>
			<?php $this->render_status_badge_styles(); ?>
			<style>
				.burrow-dashboard-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; max-width:900px; margin:16px 0; }
				.burrow-card { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:16px 18px; }
				.burrow-card h3 { margin:0 0 8px; font-size:14px; color:#1d2327; }
				.burrow-card .burrow-card-value { font-size:22px; font-weight:600; color:#2271b1; }
				.burrow-card .description { margin-top:6px; }
				.burrow-section { margin-top:28px; }
				.burrow-section > h2 { margin:0 0 10px; }
				.burrow-integration-summary { display:flex; flex-wrap:wrap; gap:10px; margin:10px 0; }
				.burrow-integration-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; background:#f0f6fc; border:1px solid #c3d0e0; border-radius:4px; font-size:13px; }
				.burrow-integration-badge .burrow-integration-icon, .burrow-integration-badge .dashicons { width:16px; height:16px; font-size:16px; line-height:16px; }
				.burrow-integration-badge .burrow-menu-glyph { width:16px; height:16px; object-fit:contain; filter:grayscale(1) brightness(0) saturate(100%) invert(34%) sepia(89%) saturate(955%) hue-rotate(178deg) brightness(90%) contrast(91%); }
			</style>

			<!-- Connection overview -->
			<div class="burrow-dashboard-grid">
				<div class="burrow-card">
					<h3><?php esc_html_e( 'Project', 'burrow' ); ?></h3>
					<div class="burrow-card-value"><?php echo esc_html( '' !== (string) ( $settings['routing']['projectId'] ?? '' ) ? substr( (string) $settings['routing']['projectId'], 0, 8 ) . '...' : '-' ); ?></div>
					<?php if ( '' !== $project_url ) : ?>
						<p class="description"><a href="<?php echo esc_url( $project_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View in Burrow &rarr;', 'burrow' ); ?></a></p>
					<?php endif; ?>
				</div>
				<div class="burrow-card">
					<h3><?php esc_html_e( 'Ingestion Key', 'burrow' ); ?></h3>
					<div class="burrow-card-value"><?php echo esc_html( '' !== $ingestion_key_prefix ? $ingestion_key_prefix : '-' ); ?></div>
					<p class="description"><?php echo esc_html( '' !== $ingestion_key_prefix ? __( 'Project-scoped', 'burrow' ) : __( 'Not yet linked', 'burrow' ) ); ?></p>
				</div>
				<div class="burrow-card">
					<h3><?php esc_html_e( 'Outbox', 'burrow' ); ?></h3>
					<div class="burrow-card-value"><?php echo esc_html( (string) ( $outbox_counts['pending'] + $outbox_counts['retrying'] ) ); ?></div>
					<p class="description">
						<?php
						echo esc_html( sprintf(
							__( '%d pending, %d failed, %d sent', 'burrow' ),
							$outbox_counts['pending'] + $outbox_counts['retrying'],
							$outbox_counts['failed'],
							$outbox_counts['sent']
						) );
						?>
						&mdash; <a href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-outbox' ) ); ?>"><?php esc_html_e( 'View', 'burrow' ); ?></a>
					</p>
					<?php if ( (int) $outbox_counts['failed'] > 0 ) : ?>
						<form method="post" style="margin-top:8px;">
							<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
							<input type="hidden" name="burrow_action" value="replay_all_failed" />
							<input type="hidden" name="return_step" value="dashboard" />
							<button type="submit" class="button button-secondary">
								<?php echo esc_html( sprintf( __( 'Retry all failed (%d)', 'burrow' ), (int) $outbox_counts['failed'] ) ); ?>
							</button>
						</form>
					<?php endif; ?>
					<?php if ( ( $outbox_counts['pending'] + $outbox_counts['retrying'] ) > 0 ) : ?>
						<?php $this->render_cron_dispatch_notice( 'outbox' ); ?>
					<?php endif; ?>
				</div>
				<div class="burrow-card">
					<h3><?php esc_html_e( 'Backfill', 'burrow' ); ?></h3>
					<div class="burrow-card-value"><?php echo $this->render_status_badge( $bf_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php if ( ! empty( $job['processedEvents'] ) ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( '%d events processed', 'burrow' ), (int) $job['processedEvents'] ) ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Integrations overview -->
			<div class="burrow-section">
				<h2><?php esc_html_e( 'Active Integrations', 'burrow' ); ?></h2>
				<?php if ( empty( $selected ) ) : ?>
					<p class="description"><?php esc_html_e( 'No integrations configured yet.', 'burrow' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-setup&step=integrations' ) ); ?>"><?php esc_html_e( 'Run setup', 'burrow' ); ?></a>
					</p>
				<?php else : ?>
					<div class="burrow-integration-summary">
						<?php foreach ( $selected as $integration_key ) :
							$integration_key = (string) $integration_key;
							$icon = $this->get_integration_icon_markup( $integration_key );
							$label = (string) ( $labels[ $integration_key ] ?? $integration_key );
							$badge_detail = '';
							if ( 'woocommerce' === $integration_key ) {
								$woo_mode = isset( $settings['onboarding']['woocommerce_mode'] ) ? (string) $settings['onboarding']['woocommerce_mode'] : 'track';
								$funnel_on = ! empty( $settings['capabilities']['ecommerce_funnel'] );
								if ( 'track' !== $woo_mode ) {
									$badge_detail = __( 'Off', 'burrow' );
								} elseif ( $funnel_on ) {
									$badge_detail = __( 'Orders + Items + Funnel', 'burrow' );
								} else {
									$badge_detail = __( 'Orders + Items', 'burrow' );
								}
							} else {
								$count = 0;
								foreach ( $contracts as $c ) {
									if ( is_array( $c ) && ! empty( $c['enabled'] ) && (string) ( $c['provider'] ?? '' ) === $integration_key ) {
										$count++;
									}
								}
								$badge_detail = sprintf( _n( '%d form', '%d forms', $count, 'burrow' ), $count );
							}
							?>
							<span class="burrow-integration-badge">
								<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<strong><?php echo esc_html( $label ); ?></strong>
								<span class="description"><?php echo esc_html( $badge_detail ); ?></span>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Active form contracts -->
			<div class="burrow-section">
				<h2><?php esc_html_e( 'Form Contracts', 'burrow' ); ?></h2>
				<table class="widefat striped" style="max-width:1200px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Provider', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Form', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Form ID', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Icon', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Fields', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Action', 'burrow' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $contract_rows ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No active contracts configured.', 'burrow' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $contract_rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['provider'] ); ?></td>
									<td><?php echo esc_html( $row['formName'] ); ?></td>
									<td><?php echo esc_html( $row['externalFormId'] ); ?></td>
									<td><?php echo esc_html( $this->format_tracking_mode_label( (string) $row['mode'] ) ); ?></td>
									<td><?php echo '' !== (string) $row['icon'] ? '<code>' . esc_html( (string) $row['icon'] ) . '</code>' : '<span class="description">' . esc_html__( 'default', 'burrow' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><?php echo esc_html( (string) (int) $row['mappingCount'] ); ?></td>
									<td>
										<?php if ( $edit_contract_key === (string) $row['contractKey'] ) : ?>
											<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-dashboard' ) ); ?>"><?php esc_html_e( 'Cancel', 'burrow' ); ?></a>
										<?php else : ?>
											<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'burrow-dashboard', 'edit_contract' => rawurlencode( (string) $row['contractKey'] ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'burrow' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<?php if ( is_array( $editing_row ) ) : ?>
					<?php $this->render_operations_contract_editor( $editing_row ); ?>
				<?php endif; ?>
			</div>

			<!-- Backfill -->
			<div id="backfill" class="burrow-section">
				<h2><?php esc_html_e( 'Data Backfill', 'burrow' ); ?></h2>
				<?php $this->render_dashboard_backfill_section( $settings, $job, $bf_status ); ?>
			</div>

			<!-- Settings -->
			<div class="burrow-section">
				<h2><?php esc_html_e( 'Settings', 'burrow' ); ?></h2>
				<p class="description"><?php esc_html_e( 'System heartbeat pings confirm the site is connected (sent hourly). Stack snapshots include full CMS, PHP, and plugin inventory (sent weekly). Check the Outbox for system.heartbeat.ping and system.stack.snapshot events.', 'burrow' ); ?></p>
				<form method="post" style="max-width:560px;">
					<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
					<input type="hidden" name="burrow_action" value="save_operations_settings" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="outbox_retention_days"><?php esc_html_e( 'Outbox retention', 'burrow' ); ?></label></th>
							<td>
								<input id="outbox_retention_days" name="outbox_retention_days" type="number" min="1" max="365" step="1" value="<?php echo esc_attr( (string) $retention_days ); ?>" class="small-text" />
								<span><?php esc_html_e( 'days', 'burrow' ); ?></span>
								<p class="description"><?php esc_html_e( 'Sent and failed records are cleaned up daily after this retention window. Longer retention preserves duplicate protection for reruns.', 'burrow' ); ?></p>
							</td>
						</tr>
						<?php if ( $woo_active ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cart & checkout funnel', 'burrow' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="ecommerce_funnel" value="1" <?php checked( $funnel_enabled ); ?> />
									<?php esc_html_e( 'Track add-to-cart, checkout started, abandoned checkout, and cart recovery events', 'burrow' ); ?>
								</label>
							</td>
						</tr>
						<?php endif; ?>
					</table>
					<?php submit_button( __( 'Save Settings', 'burrow' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the backfill controls and status within the dashboard.
	 */
	private function render_dashboard_backfill_section( array $settings, array $job, $status ) {
		$support_notes = $this->build_backfill_support_notes( $settings );
		$preset_labels = $this->backfill_window_presets();
		$current_preset = isset( $job['windowPreset'] ) ? sanitize_key( (string) $job['windowPreset'] ) : 'last_730_days';
		$source_labels  = $this->backfill_source_labels( $settings );
		$selected_sources = isset( $job['selectedSources'] ) && is_array( $job['selectedSources'] )
			? $this->sanitize_selected_backfill_sources( $settings, $job['selectedSources'] )
			: array_keys( $source_labels );
		$backfill_active = in_array( $status, array( 'queued', 'running' ), true );
		?>
		<?php if ( ! empty( $support_notes ) ) : ?>
			<ul class="description" style="margin:0 0 10px 18px;list-style:disc;">
				<?php foreach ( $support_notes as $note ) : ?>
					<li><?php echo wp_kses( (string) $note, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p>
			<?php echo $this->render_status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</p>
		<p class="description">
			<?php echo esc_html( sprintf( __( 'Sources complete: %1$d / %2$d', 'burrow' ), (int) ( $job['completedForms'] ?? 0 ), (int) ( $job['totalForms'] ?? 0 ) ) ); ?>
		</p>
		<p class="description">
			<?php echo esc_html( sprintf( __( 'Events sent: %d (WooCommerce orders may emit multiple events per order)', 'burrow' ), (int) ( $job['processedEvents'] ?? 0 ) ) ); ?>
		</p>
		<?php if ( ! empty( $job['updatedAt'] ) ) : ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'Last update: %s', 'burrow' ), (string) $job['updatedAt'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( 'failed' === $status && ! empty( $job['lastError'] ) ) : ?>
			<p class="description"><?php echo esc_html( (string) $job['lastError'] ); ?></p>
		<?php endif; ?>

		<form method="post" style="margin-top:10px;">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="queue_backfill" />
			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
				<select name="backfill_window_preset">
					<?php foreach ( $preset_labels as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $current_preset ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span><?php esc_html_e( 'Run sources:', 'burrow' ); ?></span>
				<div role="group" aria-label="<?php esc_attr_e( 'Backfill sources', 'burrow' ); ?>">
					<?php foreach ( $source_labels as $source_key => $source_label ) : ?>
						<label style="display:inline-flex;align-items:center;gap:6px;margin-right:10px;">
							<input type="checkbox" name="backfill_sources[]" value="<?php echo esc_attr( $source_key ); ?>" <?php checked( in_array( $source_key, $selected_sources, true ) ); ?> />
							<?php echo esc_html( $source_label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<button type="submit" class="button button-primary" <?php disabled( $backfill_active ); ?>><?php esc_html_e( 'Start New Backfill', 'burrow' ); ?></button>
			</div>
		</form>
		<?php if ( $backfill_active ) : ?>
			<form method="post" style="display:inline-block;margin-top:8px;">
				<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
				<input type="hidden" name="burrow_action" value="cancel_backfill" />
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Cancel Backfill', 'burrow' ); ?></button>
			</form>
		<?php endif; ?>
		<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Reruns use deterministic event keys. Duplicate protection depends on outbox retention history.', 'burrow' ); ?></p>

		<?php if ( in_array( $status, array( 'failed', 'running' ), true ) ) : ?>
			<form method="post" style="display:inline-block;margin-top:8px;">
				<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
				<input type="hidden" name="burrow_action" value="resume_backfill" />
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Resume Previous Run', 'burrow' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Post-onboarding Settings (Overview / Integrations / providers / Connection).
	 */
	public function render_setup_summary_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['reconfigure'] ) && '1' === (string) $_GET['reconfigure'] ) {
			$this->render_onboarding_page();
			return;
		}
		$settings = $this->options_repo->get_settings();
		$labels   = $this->integration_labels();
		$selected = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		$section  = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ( isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'overview' );
		$allowed_sections = array_merge(
			array( 'overview', 'integrations', 'connection' ),
			array_values( array_intersect( $selected, array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms', 'woocommerce' ) ) )
		);
		if ( ! in_array( $section, $allowed_sections, true ) ) {
			$section = 'overview';
		}

		$contracts   = isset( $settings['forms_contracts'] ) && is_array( $settings['forms_contracts'] ) ? $settings['forms_contracts'] : array();
		$project_id  = (string) ( $settings['routing']['projectId'] ?? '' );
		$project_url = BurrowWP\Core\Onboarding\LinkStateManager::project_url_from_settings( $settings );
		$woo_mode    = isset( $settings['onboarding']['woocommerce_mode'] ) ? (string) $settings['onboarding']['woocommerce_mode'] : 'off';
		$nav          = array( 'overview' => __( 'Overview', 'burrow' ), 'integrations' => __( 'Integrations', 'burrow' ) );
		foreach ( $selected as $key ) {
			$key = (string) $key;
			if ( isset( $labels[ $key ] ) ) {
				$nav[ $key ] = (string) $labels[ $key ];
			}
		}
		$nav['connection'] = __( 'Connection', 'burrow' );
		?>
		<div class="wrap">
			<?php $this->render_admin_notice_from_query(); ?>
			<?php $this->render_burrow_page_header( __( 'Burrow Settings', 'burrow' ) ); ?>
			<?php $this->render_status_badge_styles(); ?>
			<style>
				.burrow-settings-nav { margin: 12px 0 18px; }
				.burrow-setup-icon { display:inline-flex; align-items:center; gap:6px; margin-right:14px; }
				.burrow-setup-icon .dashicons { width:18px; height:18px; font-size:18px; line-height:18px; color:#2271b1; }
			</style>
			<h2 class="nav-tab-wrapper burrow-settings-nav">
				<?php foreach ( $nav as $key => $label ) : ?>
					<a class="nav-tab <?php echo $section === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-setup&section=' . rawurlencode( $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'overview' === $section ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Connected Project', 'burrow' ); ?></th>
						<td>
							<code><?php echo esc_html( '' !== $project_id ? $project_id : '-' ); ?></code>
							<?php if ( '' !== $project_url ) : ?>
								&nbsp;<a href="<?php echo esc_url( $project_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View in Burrow &rarr;', 'burrow' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Base URL', 'burrow' ); ?></th>
						<td><code><?php echo esc_html( $this->resolve_base_url( $settings ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Contracts Synced', 'burrow' ); ?></th>
						<td><?php echo esc_html( (string) ( $settings['contract_sync']['syncedAt'] ?? '-' ) ); ?></td>
					</tr>
				</table>
				<?php
				$contract_rows = array();
				foreach ( $contracts as $c ) {
					if ( ! is_array( $c ) || empty( $c['enabled'] ) ) {
						continue;
					}
					$provider_key = (string) ( $c['provider'] ?? '' );
					$contract_rows[] = array(
						'provider' => (string) ( $labels[ $provider_key ] ?? $provider_key ),
						'formName' => (string) ( $c['formName'] ?? '' ),
						'formId'   => (string) ( $c['externalFormId'] ?? '' ),
						'mode'     => (string) ( $c['mode'] ?? ( ! empty( $c['countOnly'] ) ? 'count_only' : 'custom_fields' ) ),
						'fields'   => is_array( $c['fieldMappings'] ?? null ) ? count( $c['fieldMappings'] ) : 0,
					);
				}
				?>
				<?php if ( ! empty( $contract_rows ) ) : ?>
					<h2><?php esc_html_e( 'Active Contracts', 'burrow' ); ?></h2>
					<table class="widefat striped" style="max-width:900px;">
						<thead><tr>
							<th><?php esc_html_e( 'Provider', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Form', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Form ID', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'burrow' ); ?></th>
							<th><?php esc_html_e( 'Mapped Fields', 'burrow' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $contract_rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['provider'] ); ?></td>
								<td><?php echo esc_html( '' !== $row['formName'] ? $row['formName'] : '-' ); ?></td>
								<td><?php echo esc_html( '' !== $row['formId'] ? $row['formId'] : '-' ); ?></td>
								<td><?php echo esc_html( $this->format_tracking_mode_label( $row['mode'] ) ); ?></td>
								<td><?php echo esc_html( (string) (int) $row['fields'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<p style="margin-top:16px;">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-dashboard' ) ); ?>"><?php esc_html_e( 'Go to Dashboard', 'burrow' ); ?></a>
				</p>
			<?php elseif ( 'integrations' === $section ) : ?>
				<?php $this->render_settings_integrations_section( $settings ); ?>
			<?php elseif ( 'connection' === $section ) : ?>
				<p><?php esc_html_e( 'Relink updates the connected Burrow project without re-running the full setup wizard.', 'burrow' ); ?></p>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-setup&reconfigure=1&step=connection' ) ); ?>"><?php esc_html_e( 'Relink connection / project', 'burrow' ); ?></a>
				</p>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Base URL', 'burrow' ); ?></th><td><code><?php echo esc_html( $this->resolve_base_url( $settings ) ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Project', 'burrow' ); ?></th><td><code><?php echo esc_html( '' !== $project_id ? $project_id : '-' ); ?></code></td></tr>
				</table>
			<?php elseif ( 'woocommerce' === $section ) : ?>
				<?php $this->render_settings_woocommerce_section( $settings ); ?>
			<?php elseif ( 'gravity-forms' === $section ) : ?>
				<input type="hidden" id="burrow-settings-mode-flag" value="1" />
				<?php
				ob_start();
				$this->render_gravity_forms_step();
				$html = (string) ob_get_clean();
				$html = preg_replace( '/(<form method="post"[^>]*>)/', '$1' . "\n\t\t\t<input type=\"hidden\" name=\"settings_mode\" value=\"1\" />", $html, 1 );
				$html = str_replace( __( 'Save forms & continue', 'burrow' ), __( 'Save & Sync to Burrow', 'burrow' ), $html );
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php else : ?>
				<?php
				$list_method = array(
					'contact-form-7'   => 'list_contact_form_7_forms',
					'ninja-forms'      => 'list_ninja_forms',
					'fluent-forms'     => 'list_fluent_forms',
					'wpforms'          => 'list_wpforms',
					'formidable-forms' => 'list_formidable_forms',
				);
				if ( isset( $list_method[ $section ] ) ) {
					ob_start();
					$this->render_simple_forms_provider_step( $section, $this->{$list_method[ $section ]}() );
					$html = (string) ob_get_clean();
					$html = preg_replace( '/(<form method="post"[^>]*>)/', '$1' . "\n\t\t\t<input type=\"hidden\" name=\"settings_mode\" value=\"1\" />", $html, 1 );
					$html = str_replace( __( 'Save forms & continue', 'burrow' ), __( 'Save & Sync to Burrow', 'burrow' ), $html );
					echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_settings_integrations_section( array $settings ) {
		$detected = array_merge( $this->detect_forms_capabilities(), class_exists( 'WooCommerce' ) ? array( 'woocommerce' ) : array() );
		$selected = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		$labels   = $this->integration_labels();
		?>
		<p class="description"><?php esc_html_e( 'Choose which integrations Burrow should track. Saving syncs capabilities and contracts automatically.', 'burrow' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="save_integrations" />
			<input type="hidden" name="settings_mode" value="1" />
			<?php foreach ( $detected as $key ) :
				$key = (string) $key;
				?>
				<label style="display:block;margin:8px 0;">
					<input type="checkbox" name="integrations[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?> />
					<?php echo esc_html( (string) ( $labels[ $key ] ?? $key ) ); ?>
					<?php if ( in_array( $key, $selected, true ) && 'woocommerce' !== $key ) : ?>
						&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-setup&section=' . rawurlencode( $key ) ) ); ?>"><?php esc_html_e( 'Configure forms', 'burrow' ); ?></a>
					<?php elseif ( in_array( $key, $selected, true ) && 'woocommerce' === $key ) : ?>
						&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-setup&section=woocommerce' ) ); ?>"><?php esc_html_e( 'Configure', 'burrow' ); ?></a>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
			<?php if ( empty( $detected ) ) : ?>
				<p class="description"><?php esc_html_e( 'No supported form or commerce plugins were detected.', 'burrow' ); ?></p>
			<?php endif; ?>
			<?php submit_button( __( 'Save & Sync to Burrow', 'burrow' ) ); ?>
		</form>
		<?php
	}

	private function render_settings_woocommerce_section( array $settings ) {
		ob_start();
		$this->render_woocommerce_step();
		$html = (string) ob_get_clean();
		$html = preg_replace( '/(<form method="post"[^>]*>)/', '$1' . "\n\t\t\t<input type=\"hidden\" name=\"settings_mode\" value=\"1\" />", $html, 1 );
		$html = str_replace( __( 'Save and Continue', 'burrow' ), __( 'Save & Sync to Burrow', 'burrow' ), $html );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Sync forms contracts after a Settings save. Returns error text or empty string on success.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	private function autosync_forms_contract( array $settings ) {
		$routing_error = $this->validate_routing_before_contract_sync( $settings );
		if ( '' !== $routing_error ) {
			return $routing_error;
		}
		$client = $this->build_admin_api_client( $settings );
		$res    = $client->submit_forms_contract( $this->build_forms_contract_payload( $settings ) );
		$message = $this->persist_contract_response( $res );
		if ( empty( $res['ok'] ) ) {
			return $message;
		}
		do_action( 'burrow_invalidate_delivery' );
		return '';
	}

	/**
	 * Drop contracts for providers no longer selected.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<int,string>   $selected Selected integration keys.
	 * @return array<string,mixed>
	 */
	private function prune_contracts_for_selected_integrations( array $settings, array $selected ) {
		$contracts = isset( $settings['forms_contracts'] ) && is_array( $settings['forms_contracts'] ) ? $settings['forms_contracts'] : array();
		$kept      = array();
		foreach ( $contracts as $key => $contract ) {
			$provider = is_array( $contract ) ? sanitize_key( (string) ( $contract['provider'] ?? '' ) ) : '';
			if ( '' !== $provider && in_array( $provider, $selected, true ) ) {
				$kept[ $key ] = $contract;
			}
		}
		return $kept;
	}

	public function render_outbox_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : 'all';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['q'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 50;
		$allowed = array( 'all', 'pending', 'retrying', 'sent', 'failed' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'all';
		}
		$query_status = 'all' === $status ? '' : $status;
		$offset       = ( $paged - 1 ) * $per_page;
		$total_rows   = $this->outbox_repo->count_records( $query_status, $search );
		$total_pages  = max( 1, (int) ceil( $total_rows / $per_page ) );
		if ( $paged > $total_pages ) {
			$paged  = $total_pages;
			$offset = ( $paged - 1 ) * $per_page;
		}
		$rows = $this->outbox_repo->get_records( $query_status, $per_page, $offset, $search );
		$base_url = admin_url( 'admin.php?page=burrow-outbox' );
		$tabs = array(
			'all'      => __( 'All', 'burrow' ),
			'pending'  => __( 'Pending', 'burrow' ),
			'retrying' => __( 'Retrying', 'burrow' ),
			'failed'   => __( 'Failed', 'burrow' ),
			'sent'     => __( 'Sent', 'burrow' ),
		);
		?>
		<div class="wrap">
			<?php $this->render_admin_notice_from_query(); ?>
			<?php $this->render_burrow_page_header( __( 'Burrow Outbox', 'burrow' ) ); ?>
			<?php $this->render_status_badge_styles(); ?>
			<p class="description"><?php esc_html_e( 'Outbox records for delivery debugging with status filters, search, and pagination.', 'burrow' ); ?></p>
			<?php $this->render_cron_dispatch_notice( 'outbox' ); ?>
			<?php
			$failed_count = (int) ( $this->outbox_repo->get_status_counts()['failed'] ?? 0 );
			if ( $failed_count > 0 && in_array( $status, array( 'all', 'failed' ), true ) ) :
				?>
				<form method="post" style="margin:10px 0;">
					<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
					<input type="hidden" name="burrow_action" value="replay_all_failed" />
					<input type="hidden" name="return_step" value="outbox" />
					<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Retry all failed outbox events now?', 'burrow' ) ); ?>');">
						<?php echo esc_html( sprintf( __( 'Retry all failed (%d)', 'burrow' ), $failed_count ) ); ?>
					</button>
				</form>
			<?php endif; ?>
			<h2 class="nav-tab-wrapper" style="margin-bottom:12px;">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<?php $tab_url = add_query_arg( array( 'page' => 'burrow-outbox', 'status' => $tab_key, 'q' => $search ), $base_url ); ?>
					<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $status === $tab_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<form method="get" style="margin:12px 0; display:flex; gap:8px; align-items:center;">
				<input type="hidden" name="page" value="burrow-outbox" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<label for="burrow-outbox-search" class="screen-reader-text"><?php esc_html_e( 'Search outbox', 'burrow' ); ?></label>
				<input id="burrow-outbox-search" type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search event, key, channel, payload, or error', 'burrow' ); ?>" class="regular-text" />
				<?php submit_button( __( 'Search', 'burrow' ), 'secondary', '', false ); ?>
				<?php if ( '' !== $search ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'burrow-outbox', 'status' => $status ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'burrow' ); ?></a>
				<?php endif; ?>
			</form>
			<p><strong><?php echo esc_html( sprintf( __( 'Showing %1$d-%2$d of %3$d records', 'burrow' ), 0 === $total_rows ? 0 : $offset + 1, min( $offset + count( $rows ), $total_rows ), $total_rows ) ); ?></strong></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Status', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Event', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Event key', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Next attempt', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Last error', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'burrow' ); ?></th>
						<th><?php esc_html_e( 'Action', 'burrow' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No outbox records found for this filter.', 'burrow' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $idx => $row ) : ?>
							<?php $row_id = (int) ( $row['id'] ?? 0 ); ?>
							<tr>
								<td><?php echo esc_html( (string) $row_id ); ?></td>
								<td><?php echo $this->render_status_badge( (string) ( $row['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><code><?php echo esc_html( (string) ( $row['event_name'] ?? '' ) ); ?></code></td>
								<td><code style="font-size:11px;"><?php echo esc_html( (string) ( $row['event_key'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) ( (int) ( $row['attempt_count'] ?? 0 ) . ' / ' . (int) ( $row['max_attempts'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['next_attempt_at'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['last_error'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['updated_at'] ?? '' ) ); ?></td>
								<td style="white-space:nowrap;">
									<?php $row_status = (string) ( $row['status'] ?? '' ); ?>
									<a href="#" class="burrow-toggle-payload" data-target="burrow-payload-<?php echo esc_attr( (string) $row_id ); ?>" title="<?php esc_attr_e( 'View payload', 'burrow' ); ?>" style="text-decoration:none;margin-right:4px;">
										<span class="dashicons dashicons-visibility" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span>
									</a>
									<?php if ( 'failed' === $row_status ) : ?>
										<form method="post" style="margin:0;display:inline;">
											<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
											<input type="hidden" name="burrow_action" value="replay_failed" />
											<input type="hidden" name="return_step" value="outbox" />
											<input type="hidden" name="outbox_id" value="<?php echo esc_attr( (string) $row_id ); ?>" />
											<button type="submit" class="button button-small" title="<?php esc_attr_e( 'Re-queue for delivery', 'burrow' ); ?>">
												<span class="dashicons dashicons-controls-repeat" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
												<?php esc_html_e( 'Replay', 'burrow' ); ?>
											</button>
										</form>
										<form method="post" style="margin:0;display:inline;">
											<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
											<input type="hidden" name="burrow_action" value="delete_outbox_record" />
											<input type="hidden" name="outbox_id" value="<?php echo esc_attr( (string) $row_id ); ?>" />
											<button type="submit" class="button button-small button-link-delete" title="<?php esc_attr_e( 'Delete record', 'burrow' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete this outbox record?', 'burrow' ); ?>');">
												<span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
											</button>
										</form>
									<?php elseif ( in_array( $row_status, array( 'pending', 'retrying' ), true ) ) : ?>
										<form method="post" style="margin:0;display:inline;">
											<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
											<input type="hidden" name="burrow_action" value="retry_now" />
											<input type="hidden" name="outbox_id" value="<?php echo esc_attr( (string) $row_id ); ?>" />
											<button type="submit" class="button button-small" title="<?php esc_attr_e( 'Flush immediately', 'burrow' ); ?>">
												<span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
												<?php esc_html_e( 'Retry Now', 'burrow' ); ?>
											</button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
							<tr id="burrow-payload-<?php echo esc_attr( (string) $row_id ); ?>" style="display:none;">
								<td colspan="9" style="padding:0;">
									<div style="background:#1e1e2e;border-top:2px solid #45475a;padding:12px 16px;max-height:400px;overflow:auto;border-radius:0 0 4px 4px;">
										<pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.6;color:#cdd6f4;font-family:'SF Mono','Fira Code','JetBrains Mono',Menlo,Consolas,monospace;"><?php
											$raw = isset( $row['payload_json'] ) ? (string) $row['payload_json'] : '{}';
											$decoded = json_decode( $raw, true );
											if ( is_array( $decoded ) ) {
												echo esc_html( (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
											} else {
												echo esc_html( $raw );
											}
										?></pre>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
			$pagination = paginate_links(
				array(
					'base'      => add_query_arg(
						array(
							'page'   => 'burrow-outbox',
							'status' => $status,
							'q'      => $search,
							'paged'  => '%#%',
						),
						admin_url( 'admin.php' )
					),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo;', 'burrow' ),
					'next_text' => __( '&raquo;', 'burrow' ),
				)
			);
			if ( is_string( $pagination ) && '' !== $pagination ) :
				?>
				<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( $pagination ); ?></div></div>
			<?php endif; ?>
			<script>
			(function(){
				document.querySelectorAll('.burrow-toggle-payload').forEach(function(link){
					link.addEventListener('click',function(e){
						e.preventDefault();
						var target = document.getElementById(this.getAttribute('data-target'));
						if(target){
							target.style.display = target.style.display === 'none' ? 'table-row' : 'none';
						}
					});
				});
			})();
			</script>
		</div>
		<?php
	}

	private function render_wizard_steps( $step, array $steps, array $settings ) {
		echo '<ol class="burrow-wizard-steps">';
		$position = 0;
		foreach ( $steps as $key => $label ) {
			$position++;
			$url = add_query_arg(
				array(
					'page' => 'burrow-setup',
					'step' => $key,
				),
				admin_url( 'admin.php' )
			);
			$class = $key === $step ? 'is-current' : '';
			$integration_attr = '';
			if ( in_array( $key, array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'woocommerce' ), true ) ) {
				$integration_attr = ' data-integration="' . esc_attr( $key ) . '"';
			}
			echo '<li class="' . esc_attr( $class ) . '" data-step="' . esc_attr( $key ) . '"' . $integration_attr . '>';
			echo '<a href="' . esc_url( $url ) . '"><span class="burrow-wizard-step-num">' . esc_html( (string) $position ) . '.</span>' . esc_html( $label ) . '</a>';
			echo '</li>';
		}
		echo '</ol>';
		$prev_step = $this->previous_step( $settings, $step );
		if ( '' !== $prev_step ) {
			$prev_url = add_query_arg(
				array(
					'page' => 'burrow-setup',
					'step' => $prev_step,
				),
				admin_url( 'admin.php' )
			);
			echo '<div class="burrow-sidebar-back"><a href="' . esc_url( $prev_url ) . '">&laquo; ' . esc_html__( 'Back', 'burrow' ) . '</a></div>';
		}
	}

	private function render_project_picker( array $settings ) {
		$candidates = isset( $settings['project_candidates'] ) && is_array( $settings['project_candidates'] ) ? $settings['project_candidates'] : array();
		if ( empty( $candidates ) ) {
			echo '<p>' . esc_html__( 'No projects returned yet. Complete Step 1 again to fetch projects.', 'burrow' ) . '</p>';
			return;
		}
		?>
		<form method="post">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="select_project" />
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Select', 'burrow' ); ?></th><th><?php esc_html_e( 'Client', 'burrow' ); ?></th><th><?php esc_html_e( 'Project', 'burrow' ); ?></th><th><?php esc_html_e( 'Site', 'burrow' ); ?></th></tr></thead><tbody>
			<?php foreach ( $candidates as $index => $candidate ) : ?><tr><td><input type="radio" name="project_index" value="<?php echo esc_attr( (string) $index ); ?>" required /></td><td><?php echo esc_html( (string) $candidate['clientName'] ); ?></td><td><?php echo esc_html( (string) $candidate['projectName'] ); ?></td><td><?php echo esc_html( (string) $candidate['siteUrl'] ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
			<?php submit_button( __( 'Use Selected Project', 'burrow' ) ); ?>
		</form>
		<?php
	}

	private function render_integration_selection_step( array $settings ) {
		$detected = $this->detected_integrations();
		$selected = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		$labels   = $this->integration_labels();
		$timeline_labels = array(
			'gravity-forms'  => 'Gravity Forms',
			'contact-form-7' => 'Contact Form 7',
			'ninja-forms'    => 'Ninja Forms',
			'fluent-forms'   => 'Fluent Forms',
			'woocommerce'    => 'WooCommerce',
		);
		$provider_order = array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'woocommerce' );
		?>
		<form method="post">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="save_integrations" />
			<style>
				.burrow-integrations-table th:first-child,
				.burrow-integrations-table td:first-child {
					width: 90px;
					text-align: left;
					vertical-align: middle;
					padding-left: 16px;
				}
				.burrow-integrations-table .burrow-select-all-label {
					display: inline-flex;
					align-items: center;
					gap: 6px;
					justify-content: flex-start;
				}
				#burrow-select-all-integrations,
				.burrow-integration-checkbox {
					margin: 0;
				}
				.burrow-integration-label {
					display: inline-flex;
					align-items: center;
					gap: 8px;
				}
				.burrow-integration-label .dashicons {
					font-size: 18px;
					width: 18px;
					height: 18px;
					color: #2271b1;
				}
				.burrow-integration-label .burrow-menu-glyph {
					width: 18px;
					height: 18px;
					object-fit: contain;
					/* normalize plugin glyphs toward the same blue tone */
					filter: grayscale(1) brightness(0) saturate(100%) invert(34%) sepia(89%) saturate(955%) hue-rotate(178deg) brightness(90%) contrast(91%);
				}
				.burrow-gravity-form-block.burrow-count-only-active table {
					opacity: 0.65;
				}
			</style>
			<table class="widefat striped burrow-integrations-table">
				<thead>
					<tr>
						<th>
							<label for="burrow-select-all-integrations" class="burrow-select-all-label">
								<input type="checkbox" id="burrow-select-all-integrations" />
								<?php esc_html_e( 'Enable', 'burrow' ); ?>
							</label>
						</th>
						<th><?php esc_html_e( 'Integration', 'burrow' ); ?></th>
					</tr>
				</thead>
				<tbody>
			<?php foreach ( $detected as $key ) : ?>
				<tr>
					<td>
						<input class="burrow-integration-checkbox" type="checkbox" name="integrations[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?> />
					</td>
					<td>
						<span class="burrow-integration-label">
							<?php echo $this->get_integration_icon_markup( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php echo esc_html( $labels[ $key ] ?? $key ); ?></span>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Detected integrations are unchecked by default.', 'burrow' ); ?></p>
			<?php submit_button( __( 'Continue', 'burrow' ) ); ?>
		</form>
		<script>
			(function () {
				const master = document.getElementById('burrow-select-all-integrations');
				const boxes = Array.from(document.querySelectorAll('.burrow-integration-checkbox'));
				const submit = document.querySelector('form [type="submit"]');
				const timeline = document.querySelector('.burrow-wizard-steps');
				const adminBase = <?php echo wp_json_encode( admin_url( 'admin.php?page=burrow-setup&step=' ) ); ?>;
				const timelineLabels = <?php echo wp_json_encode( $timeline_labels ); ?>;
				const providerOrder = <?php echo wp_json_encode( $provider_order ); ?>;
				if (!master || !boxes.length) return;
				const selectedIntegrations = () => boxes.filter((box) => box.checked).map((box) => box.value);
				const syncTimeline = () => {
					if (!timeline) return;
					Array.from(timeline.querySelectorAll('li[data-integration]')).forEach((li) => li.remove());
					const selected = selectedIntegrations();
					const reviewStep = timeline.querySelector('li[data-step="review"]');
					const ordered = providerOrder.filter((key) => selected.includes(key));
					ordered.forEach((key) => {
						if (!reviewStep) return;
						const li = document.createElement('li');
						li.setAttribute('data-step', key);
						li.setAttribute('data-integration', key);
						const link = document.createElement('a');
						link.href = adminBase + encodeURIComponent(key);
						const num = document.createElement('span');
						num.className = 'burrow-wizard-step-num';
						num.textContent = '0.';
						link.appendChild(num);
						link.appendChild(document.createTextNode(timelineLabels[key] || key));
						li.appendChild(link);
						timeline.insertBefore(li, reviewStep);
					});
					const visible = Array.from(timeline.querySelectorAll('li')).filter((li) => li.style.display !== 'none');
					visible.forEach((li, index) => {
						const marker = li.querySelector('.burrow-wizard-step-num');
						if (marker) marker.textContent = `${index + 1}.`;
					});
				};
				const sync = () => {
					const checked = boxes.filter((box) => box.checked).length;
					master.checked = checked === boxes.length;
					master.indeterminate = checked > 0 && checked < boxes.length;
					if (submit) {
						submit.disabled = checked === 0;
					}
					syncTimeline();
				};
				master.addEventListener('change', () => {
					boxes.forEach((box) => { box.checked = master.checked; });
					sync();
				});
				boxes.forEach((box) => box.addEventListener('change', sync));
				sync();
			})();
		</script>
		<?php
	}

	private function render_gravity_forms_step() {
		$forms = $this->list_gravity_forms();
		$normalized = array();
		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$form_id = isset( $form['id'] ) ? (string) $form['id'] : '';
			if ( '' === $form_id ) {
				continue;
			}
			$fields = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				if ( ! is_object( $field ) ) {
					continue;
				}
				$fields[] = array(
					'id'   => (string) $field->id,
					'name' => (string) $field->label,
					'type' => (string) $field->type,
				);
			}
			$normalized[] = array(
				'id'     => $form_id,
				'title'  => (string) ( $form['title'] ?? sprintf( 'Form %s', $form_id ) ),
				'fields' => $fields,
			);
		}
		$this->render_selective_forms_config(
			'gravity-forms',
			$normalized,
			'save_gravity_contracts',
			'gravity',
			__( 'Save forms & continue', 'burrow' )
		);
	}

	private function render_simple_forms_provider_step( $provider, array $forms ) {
		$provider = sanitize_key( (string) $provider );
		$labels   = $this->integration_labels();
		$normalized = array();
		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$form_id = isset( $form['id'] ) ? (string) $form['id'] : '';
			if ( '' === $form_id ) {
				continue;
			}
			$normalized[] = array(
				'id'     => $form_id,
				'title'  => (string) ( $form['title'] ?? sprintf( 'Form %s', $form_id ) ),
				'fields' => $this->list_provider_fields_for_form( $provider, $form_id ),
			);
		}
		$this->render_selective_forms_config(
			$provider,
			$normalized,
			'save_provider_contracts',
			'provider_forms',
			sprintf( __( 'Save forms & continue', 'burrow' ) ),
			true
		);
	}

	/**
	 * Selective add/configure form picker (Craft parity).
	 *
	 * @param string               $provider Provider key.
	 * @param array<int,array>     $forms Normalized forms with id/title/fields.
	 * @param string               $action Admin POST action.
	 * @param string               $input_root Root input name (gravity|provider_forms).
	 * @param string               $submit_label Submit button label.
	 * @param bool                 $include_provider_hidden Whether to post provider key.
	 */
	private function render_selective_forms_config( $provider, array $forms, $action, $input_root, $submit_label, $include_provider_hidden = false ) {
		$provider   = sanitize_key( (string) $provider );
		$labels     = $this->integration_labels();
		$settings   = $this->options_repo->get_settings();
		$existing   = isset( $settings['forms_contracts'] ) && is_array( $settings['forms_contracts'] ) ? $settings['forms_contracts'] : array();
		$forms      = $this->enrich_forms_with_submission_stats( $provider, $forms );
		usort(
			$forms,
			static function ( $a, $b ) {
				$ac = (int) ( $a['submissionCount120d'] ?? 0 );
				$bc = (int) ( $b['submissionCount120d'] ?? 0 );
				if ( $ac === $bc ) {
					return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
				}
				return $bc <=> $ac;
			}
		);

		$selected_count = 0;
		foreach ( $forms as $form ) {
			$form_id = (string) ( $form['id'] ?? '' );
			$contract_key = $provider . ':' . $form_id;
			$current = isset( $existing[ $contract_key ] ) && is_array( $existing[ $contract_key ] ) ? $existing[ $contract_key ] : array();
			$mode = isset( $current['mode'] ) ? sanitize_key( (string) $current['mode'] ) : 'off';
			if ( ! in_array( $mode, array( 'off', 'count_only', 'custom_fields' ), true ) ) {
				$mode = ! empty( $current['countOnly'] ) ? 'count_only' : ( ! empty( $current['enabled'] ) ? 'custom_fields' : 'off' );
			}
			if ( in_array( $mode, array( 'count_only', 'custom_fields' ), true ) ) {
				$selected_count++;
			}
		}

		$supports_custom_fields = in_array(
			$provider,
			array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms' ),
			true
		);
		?>
		<style>
			.burrow-form-selected-section { margin: 16px 0 24px; }
			.burrow-form-section-title { margin: 0 0 6px; font-size: 15px; }
			.burrow-form-section-hint, .burrow-form-meta { color: #646970; }
			.burrow-form-integration-block { border: 1px solid #dcdcde; background: #fff; padding: 14px 16px; margin: 0 0 12px; border-radius: 4px; }
			.burrow-form-integration-block.is-unselected { display: none; }
			.burrow-form-block-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
			.burrow-form-block-header h3 { margin: 0 0 4px; }
			.burrow-form-picker { margin-top: 18px; padding-top: 14px; border-top: 1px solid #dcdcde; }
			.burrow-form-picker-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
			.burrow-form-picker select { min-width: 280px; max-width: 100%; }
			.burrow-form-mode-option { margin-right: 14px; display: inline-block; }
		</style>
		<p class="description">
			<?php esc_html_e( 'Add the forms you want to track. Count-only sends a minimal envelope (formId, formName, submissionId, timestamp). Custom fields only adds mapped tags/properties — never full submission data.', 'burrow' ); ?>
		</p>
		<form method="post" class="burrow-form-config-form" data-provider="<?php echo esc_attr( $provider ); ?>">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php if ( $include_provider_hidden ) : ?>
				<input type="hidden" name="provider" value="<?php echo esc_attr( $provider ); ?>" />
			<?php endif; ?>

			<div class="burrow-form-selected-section">
				<h3 class="burrow-form-section-title"><?php esc_html_e( 'Tracking these forms', 'burrow' ); ?></h3>
				<p class="description burrow-form-section-hint"><?php esc_html_e( 'Choose Count-only or Custom fields for each form you add.', 'burrow' ); ?></p>
				<p class="description burrow-form-selected-empty"<?php echo $selected_count > 0 ? ' hidden' : ''; ?>>
					<?php esc_html_e( 'No forms added yet. Use Add a form below to get started.', 'burrow' ); ?>
				</p>
				<div class="burrow-form-selected-list">
					<?php foreach ( $forms as $form ) : ?>
						<?php
						$form_id   = (string) ( $form['id'] ?? '' );
						$form_name = (string) ( $form['title'] ?? $form_id );
						$contract_key = $provider . ':' . $form_id;
						$current      = isset( $existing[ $contract_key ] ) && is_array( $existing[ $contract_key ] ) ? $existing[ $contract_key ] : array();
						$current_mode = isset( $current['mode'] ) ? sanitize_key( (string) $current['mode'] ) : 'off';
						if ( ! in_array( $current_mode, array( 'off', 'count_only', 'custom_fields' ), true ) ) {
							$current_mode = ! empty( $current['countOnly'] ) ? 'count_only' : ( ! empty( $current['enabled'] ) ? 'custom_fields' : 'off' );
						}
						$is_selected = in_array( $current_mode, array( 'count_only', 'custom_fields' ), true );
						$existing_mappings = isset( $current['fieldMappings'] ) && is_array( $current['fieldMappings'] ) ? $current['fieldMappings'] : array();
						$mapped_lookup = array();
						foreach ( $existing_mappings as $mapping ) {
							if ( ! is_array( $mapping ) || empty( $mapping['externalFieldId'] ) ) {
								continue;
							}
							$mapped_lookup[ (string) $mapping['externalFieldId'] ] = $mapping;
						}
						$provider_fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
						$count_120d = (int) ( $form['submissionCount120d'] ?? 0 );
						$last_label = ! empty( $form['lastSubmittedAt'] )
							? gmdate( 'M j, Y', strtotime( (string) $form['lastSubmittedAt'] ) )
							: __( 'No submissions', 'burrow' );
						$input_prefix = $input_root . '[forms][' . $form_id . ']';
						?>
						<div
							class="burrow-form-integration-block burrow-mapped-provider-form-block<?php echo $is_selected ? '' : ' is-unselected'; ?>"
							data-form-id="<?php echo esc_attr( $form_id ); ?>"
							data-form-name="<?php echo esc_attr( $form_name ); ?>"
							data-selected="<?php echo $is_selected ? '1' : '0'; ?>"
						>
							<div class="burrow-form-block-header">
								<div>
									<h3><?php echo esc_html( $form_name ); ?></h3>
									<p class="description burrow-form-meta">
										<span><?php echo esc_html( sprintf( __( '%d in last 120 days', 'burrow' ), $count_120d ) ); ?></span>
										<span aria-hidden="true">·</span>
										<span><?php echo esc_html( sprintf( __( 'Last: %s', 'burrow' ), $last_label ) ); ?></span>
									</p>
								</div>
								<button type="button" class="button-link-delete burrow-form-remove-btn"><?php esc_html_e( 'Remove', 'burrow' ); ?></button>
							</div>
							<div class="burrow-form-selected-body">
								<fieldset style="margin:0 0 12px 0;">
									<legend><strong><?php esc_html_e( 'Tracking mode', 'burrow' ); ?></strong></legend>
									<label class="burrow-form-mode-option"><input class="burrow-provider-mode-radio burrow-form-mode-radio" type="radio" name="<?php echo esc_attr( $input_prefix ); ?>[mode]" value="count_only" <?php checked( ! $is_selected || 'custom_fields' !== $current_mode ); ?> <?php disabled( ! $is_selected ); ?> /> <?php esc_html_e( 'Count-only', 'burrow' ); ?></label>
									<?php if ( $supports_custom_fields ) : ?>
										<label class="burrow-form-mode-option"><input class="burrow-provider-mode-radio burrow-form-mode-radio" type="radio" name="<?php echo esc_attr( $input_prefix ); ?>[mode]" value="custom_fields" <?php checked( $is_selected && 'custom_fields' === $current_mode ); ?> <?php disabled( ! $is_selected ); ?> /> <?php esc_html_e( 'Custom fields', 'burrow' ); ?></label>
									<?php endif; ?>
								</fieldset>
								<input type="hidden" class="burrow-form-external-id" name="<?php echo esc_attr( $input_prefix ); ?>[externalFormId]" value="<?php echo esc_attr( $form_id ); ?>" <?php disabled( ! $is_selected ); ?> />
								<input type="hidden" class="burrow-form-name-input" name="<?php echo esc_attr( $input_prefix ); ?>[formName]" value="<?php echo esc_attr( $form_name ); ?>" <?php disabled( ! $is_selected ); ?> />
								<?php if ( $supports_custom_fields ) : ?>
									<?php if ( empty( $provider_fields ) ) : ?>
										<p class="description"><?php esc_html_e( 'No fields were detected for this form.', 'burrow' ); ?></p>
									<?php else : ?>
										<table class="widefat striped burrow-field-mapping-table">
											<thead><tr><th><?php esc_html_e( 'Include', 'burrow' ); ?></th><th><?php esc_html_e( 'Field', 'burrow' ); ?></th><th><?php esc_html_e( 'Type', 'burrow' ); ?></th><th><?php esc_html_e( 'Canonical Key', 'burrow' ); ?></th><th><?php esc_html_e( 'Target', 'burrow' ); ?></th></tr></thead>
											<tbody>
											<?php foreach ( $provider_fields as $field ) : ?>
												<?php
												$field_external_id = (string) ( $field['id'] ?? '' );
												$field_name = (string) ( $field['name'] ?? $field_external_id );
												$field_type = (string) ( $field['type'] ?? 'text' );
												$existing_map = isset( $mapped_lookup[ $field_external_id ] ) ? $mapped_lookup[ $field_external_id ] : array();
												$checked = ! empty( $existing_map );
												if ( 'gravity-forms' === $provider && empty( $existing_map ) && $this->is_suggested_field_type( $field_type ) && $is_selected && 'custom_fields' === $current_mode ) {
													$checked = true;
												}
												$target = isset( $existing_map['target'] ) && in_array( (string) $existing_map['target'], array( 'properties', 'tags' ), true )
													? (string) $existing_map['target']
													: '';
												$canonical = isset( $existing_map['canonicalKey'] ) ? (string) $existing_map['canonicalKey'] : $this->label_to_canonical_key( $field_name );
												if ( class_exists( '\Burrow\Sdk\Contracts\FormsContractWizardHelpers' ) ) {
													$sanitized = \Burrow\Sdk\Contracts\FormsContractWizardHelpers::sanitizeCanonicalKey( $canonical );
													if ( is_array( $sanitized ) && ! empty( $sanitized['key'] ) ) {
														$canonical = (string) $sanitized['key'];
													} elseif ( is_string( $sanitized ) && '' !== $sanitized ) {
														$canonical = $sanitized;
													}
												}
												?>
												<tr>
													<td><input class="burrow-provider-field-checkbox burrow-form-field-checkbox" type="checkbox" name="<?php echo esc_attr( $input_prefix ); ?>[fields][<?php echo esc_attr( $field_external_id ); ?>][include]" value="1" <?php checked( $checked ); ?> /></td>
													<td><?php echo esc_html( $field_name ); ?></td>
													<td><?php echo esc_html( $field_type ); ?></td>
													<td><input type="text" name="<?php echo esc_attr( $input_prefix ); ?>[fields][<?php echo esc_attr( $field_external_id ); ?>][canonicalKey]" value="<?php echo esc_attr( $canonical ); ?>" /></td>
													<td>
														<select name="<?php echo esc_attr( $input_prefix ); ?>[fields][<?php echo esc_attr( $field_external_id ); ?>][target]">
															<option value="" <?php selected( '', $target ); ?>><?php esc_html_e( 'Select one', 'burrow' ); ?></option>
															<option value="properties" <?php selected( 'properties', $target ); ?>>properties</option>
															<option value="tags" <?php selected( 'tags', $target ); ?>>tags</option>
														</select>
													</td>
												</tr>
												<input type="hidden" name="<?php echo esc_attr( $input_prefix ); ?>[fields][<?php echo esc_attr( $field_external_id ); ?>][externalFieldId]" value="<?php echo esc_attr( $field_external_id ); ?>" />
												<input type="hidden" name="<?php echo esc_attr( $input_prefix ); ?>[fields][<?php echo esc_attr( $field_external_id ); ?>][sourceLabel]" value="<?php echo esc_attr( $field_name ); ?>" />
												<input type="hidden" name="<?php echo esc_attr( $input_prefix ); ?>[fields][<?php echo esc_attr( $field_external_id ); ?>][dataType]" value="<?php echo esc_attr( $field_type ); ?>" />
											<?php endforeach; ?>
											</tbody>
										</table>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( empty( $forms ) ) : ?>
				<p><?php echo esc_html( sprintf( __( 'No active forms found for %s.', 'burrow' ), (string) ( $labels[ $provider ] ?? $provider ) ) ); ?></p>
			<?php else : ?>
				<div class="burrow-form-picker">
					<h3 class="burrow-form-section-title"><?php esc_html_e( 'Add a form', 'burrow' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Sorted by submission volume in the last 120 days.', 'burrow' ); ?></p>
					<div class="burrow-form-picker-row">
						<select class="burrow-form-picker-select">
							<option value=""><?php esc_html_e( 'Select a form…', 'burrow' ); ?></option>
							<?php foreach ( $forms as $form ) : ?>
								<?php
								$form_id = (string) ( $form['id'] ?? '' );
								$contract_key = $provider . ':' . $form_id;
								$current = isset( $existing[ $contract_key ] ) && is_array( $existing[ $contract_key ] ) ? $existing[ $contract_key ] : array();
								$mode = isset( $current['mode'] ) ? sanitize_key( (string) $current['mode'] ) : 'off';
								if ( in_array( $mode, array( 'count_only', 'custom_fields' ), true ) || ( ! empty( $current['enabled'] ) && empty( $current['mode'] ) ) ) {
									continue;
								}
								$count_120d = (int) ( $form['submissionCount120d'] ?? 0 );
								$last_label = ! empty( $form['lastSubmittedAt'] )
									? gmdate( 'M j, Y', strtotime( (string) $form['lastSubmittedAt'] ) )
									: __( 'No submissions', 'burrow' );
								$option_label = sprintf(
									'%s — %d in 120d, last %s',
									(string) ( $form['title'] ?? $form_id ),
									$count_120d,
									$last_label
								);
								?>
								<option value="<?php echo esc_attr( $form_id ); ?>"><?php echo esc_html( $option_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button burrow-form-add-btn"><?php esc_html_e( 'Add form', 'burrow' ); ?></button>
					</div>
				</div>
			<?php endif; ?>

			<p class="submit">
				<button type="submit" class="button button-primary burrow-form-save-btn" <?php disabled( $selected_count < 1 ); ?>><?php echo esc_html( $submit_label ); ?></button>
			</p>
		</form>
		<script>
			(function () {
				const form = document.querySelector('.burrow-form-config-form[data-provider="<?php echo esc_js( $provider ); ?>"]');
				if (!form) return;
				const emptyHint = form.querySelector('.burrow-form-selected-empty');
				const saveBtn = form.querySelector('.burrow-form-save-btn');
				const picker = form.querySelector('.burrow-form-picker-select');
				const addBtn = form.querySelector('.burrow-form-add-btn');

				const selectedBlocks = () => Array.from(form.querySelectorAll('.burrow-form-integration-block')).filter((b) => b.dataset.selected === '1');

				const setSelected = (block, selected) => {
					block.dataset.selected = selected ? '1' : '0';
					block.classList.toggle('is-unselected', !selected);
					block.querySelectorAll('.burrow-form-mode-radio, .burrow-form-external-id, .burrow-form-name-input').forEach((el) => {
						if (selected) el.removeAttribute('disabled');
						else el.setAttribute('disabled', 'disabled');
					});
					if (selected) {
						const countOnly = block.querySelector('.burrow-form-mode-radio[value="count_only"]');
						if (countOnly && !block.querySelector('.burrow-form-mode-radio:checked')) {
							countOnly.checked = true;
						}
					} else {
						block.querySelectorAll('.burrow-form-mode-radio').forEach((el) => { el.checked = false; });
					}
					syncFieldControls(block);
				};

				const syncFieldControls = (block) => {
					const selected = block.dataset.selected === '1';
					const modeEl = block.querySelector('.burrow-form-mode-radio:checked');
					const mode = modeEl ? modeEl.value : 'off';
					const fieldsEnabled = selected && mode === 'custom_fields';
					Array.from(block.querySelectorAll('tbody tr')).forEach((row) => {
						const checkbox = row.querySelector('.burrow-form-field-checkbox');
						const mappingControls = row.querySelectorAll('input:not([type=checkbox]), select, textarea');
						if (checkbox) {
							if (fieldsEnabled) checkbox.removeAttribute('disabled');
							else {
								checkbox.setAttribute('disabled', 'disabled');
								if (!selected || mode !== 'custom_fields') checkbox.checked = false;
							}
						}
						const includeChecked = checkbox && checkbox.checked;
						mappingControls.forEach((control) => {
							if (fieldsEnabled && includeChecked) control.removeAttribute('disabled');
							else control.setAttribute('disabled', 'disabled');
						});
					});
				};

				const syncChrome = () => {
					const count = selectedBlocks().length;
					if (emptyHint) emptyHint.hidden = count > 0;
					if (saveBtn) saveBtn.disabled = count < 1;
				};

				form.querySelectorAll('.burrow-form-integration-block').forEach((block) => {
					block.querySelectorAll('.burrow-form-mode-radio, .burrow-form-field-checkbox').forEach((el) => {
						el.addEventListener('change', () => syncFieldControls(block));
					});
					const removeBtn = block.querySelector('.burrow-form-remove-btn');
					if (removeBtn) {
						removeBtn.addEventListener('click', () => {
							setSelected(block, false);
							if (picker) {
								const opt = document.createElement('option');
								opt.value = block.dataset.formId;
								const meta = block.querySelector('.burrow-form-meta');
								opt.textContent = (block.dataset.formName || block.dataset.formId) + (meta ? ' — ' + meta.textContent.replace(/\s+/g, ' ').trim() : '');
								picker.appendChild(opt);
							}
							syncChrome();
						});
					}
					syncFieldControls(block);
				});

				if (addBtn && picker) {
					addBtn.addEventListener('click', () => {
						const id = picker.value;
						if (!id) return;
						const block = form.querySelector('.burrow-form-integration-block[data-form-id="' + CSS.escape(id) + '"]');
						if (!block) return;
						setSelected(block, true);
						const opt = picker.querySelector('option[value="' + CSS.escape(id) + '"]');
						if (opt) opt.remove();
						picker.value = '';
						syncChrome();
					});
				}

				form.addEventListener('submit', (event) => {
					if (selectedBlocks().length < 1) {
						event.preventDefault();
						window.alert(<?php echo wp_json_encode( __( 'Add at least one form before continuing.', 'burrow' ) ); ?>);
						return;
					}
					for (const block of selectedBlocks()) {
						const selectedMode = block.querySelector('.burrow-form-mode-radio:checked');
						const mode = selectedMode ? selectedMode.value : 'off';
						if (mode !== 'custom_fields') continue;
						const heading = block.querySelector('h3');
						const formName = heading && heading.textContent ? heading.textContent.trim() : 'This form';
						const includedFields = Array.from(block.querySelectorAll('.burrow-form-field-checkbox:checked'));
						if (!includedFields.length) {
							event.preventDefault();
							window.alert(formName + ': please include at least one field when using Custom fields mode.');
							return;
						}
						for (const checkbox of includedFields) {
							const row = checkbox.closest('tr');
							const targetSelect = row ? row.querySelector('select[name*="[target]"]') : null;
							if (targetSelect && !targetSelect.value) {
								event.preventDefault();
								window.alert(formName + ': please choose a target for each included field.');
								targetSelect.focus();
								return;
							}
						}
					}
				});

				syncChrome();
			})();
		</script>
		<?php
	}

	/**
	 * Attach 120-day volume + last submission metadata for form picker sorting.
	 *
	 * @param string           $provider Provider key.
	 * @param array<int,array> $forms Forms.
	 * @return array<int,array>
	 */
	private function enrich_forms_with_submission_stats( $provider, array $forms ) {
		$provider = sanitize_key( (string) $provider );
		$out      = array();
		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$form_id = isset( $form['id'] ) ? (string) $form['id'] : '';
			$stats   = $this->get_form_submission_stats( $provider, $form_id );
			$form['submissionCount120d'] = (int) ( $stats['count120d'] ?? 0 );
			$form['lastSubmittedAt']     = (string) ( $stats['lastSubmittedAt'] ?? '' );
			$out[] = $form;
		}
		return $out;
	}

	/**
	 * Best-effort submission stats for a provider form.
	 *
	 * @param string $provider Provider key.
	 * @param string $form_id Form id.
	 * @return array{count120d:int,lastSubmittedAt:string}
	 */
	private function get_form_submission_stats( $provider, $form_id ) {
		$provider = sanitize_key( (string) $provider );
		$form_id  = (string) $form_id;
		$empty    = array( 'count120d' => 0, 'lastSubmittedAt' => '' );
		if ( '' === $form_id ) {
			return $empty;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * 120 ) );

		if ( 'gravity-forms' === $provider && class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'count_entries' ) ) {
			$count = (int) \GFAPI::count_entries( (int) $form_id, array( 'start_date' => $since ) );
			$last  = '';
			if ( method_exists( 'GFAPI', 'get_entries' ) ) {
				$entries = \GFAPI::get_entries( (int) $form_id, array(), null, array( 'offset' => 0, 'page_size' => 1 ) );
				if ( is_array( $entries ) && ! empty( $entries[0]['date_created'] ) ) {
					$last = (string) $entries[0]['date_created'];
				}
			}
			return array( 'count120d' => $count, 'lastSubmittedAt' => $last );
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $empty;
		}

		if ( 'fluent-forms' === $provider ) {
			$table = $wpdb->prefix . 'fluentform_submissions';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $table !== $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
				return $empty;
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND created_at >= %s", (int) $form_id, $since ) );
			$last  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT 1", (int) $form_id ) );
			return array( 'count120d' => $count, 'lastSubmittedAt' => $last );
		}

		if ( 'formidable-forms' === $provider ) {
			$table = $wpdb->prefix . 'frm_items';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $table !== $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
				return $empty;
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND created_at >= %s", (int) $form_id, $since ) );
			$last  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT 1", (int) $form_id ) );
			return array( 'count120d' => $count, 'lastSubmittedAt' => $last );
		}

		if ( 'ninja-forms' === $provider ) {
			$table = $wpdb->prefix . 'nf3_objects';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $table === $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
				$count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE type = %s AND title = %s AND created_at >= %s",
						'submission',
						$form_id,
						$since
					)
				);
				// Ninja stores form id on meta; fall back to post-based counts when available.
			}
			$posts = get_posts(
				array(
					'post_type'      => 'nf_sub',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'meta_key'       => '_form_id',
					'meta_value'     => $form_id,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);
			$last = ! empty( $posts[0] ) ? get_post_field( 'post_date_gmt', $posts[0] ) : '';
			$count_q = new \WP_Query(
				array(
					'post_type'      => 'nf_sub',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => '_form_id',
							'value' => $form_id,
						),
					),
					'date_query'     => array(
						array(
							'after'     => $since,
							'inclusive' => true,
							'column'    => 'post_date_gmt',
						),
					),
				)
			);
			return array( 'count120d' => (int) $count_q->found_posts, 'lastSubmittedAt' => (string) $last );
		}

		if ( 'wpforms' === $provider && function_exists( 'wpforms' ) ) {
			$entry_handler = wpforms()->get( 'entry' );
			if ( is_object( $entry_handler ) && method_exists( $entry_handler, 'get_entries' ) ) {
				$entries = $entry_handler->get_entries(
					array(
						'form_id' => (int) $form_id,
						'date'    => $since . ',' . gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$count = is_array( $entries ) ? count( $entries ) : 0;
				$last_entries = $entry_handler->get_entries(
					array(
						'form_id' => (int) $form_id,
						'number'  => 1,
					)
				);
				$last = ( is_array( $last_entries ) && ! empty( $last_entries[0]->date ) ) ? (string) $last_entries[0]->date : '';
				return array( 'count120d' => $count, 'lastSubmittedAt' => $last );
			}
		}

		if ( 'contact-form-7' === $provider && class_exists( '\Flamingo_Inbound_Message' ) ) {
			$q = new \WP_Query(
				array(
					'post_type'      => 'flamingo_inbound',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => '_channel',
							'value' => $form_id,
						),
					),
					'date_query'     => array(
						array(
							'after'     => $since,
							'inclusive' => true,
							'column'    => 'post_date_gmt',
						),
					),
				)
			);
			$latest = get_posts(
				array(
					'post_type'      => 'flamingo_inbound',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => '_channel',
					'meta_value'     => $form_id,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);
			$last = ! empty( $latest[0] ) ? (string) get_post_field( 'post_date_gmt', $latest[0] ) : '';
			return array( 'count120d' => (int) $q->found_posts, 'lastSubmittedAt' => $last );
		}

		return $empty;
	}


	private function render_review_step( array $settings ) {
		$selected            = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		$labels              = $this->integration_labels();
		$provider_configured = isset( $settings['onboarding']['provider_configured'] ) && is_array( $settings['onboarding']['provider_configured'] )
			? $settings['onboarding']['provider_configured']
			: array();
		$contracts           = isset( $settings['forms_contracts'] ) && is_array( $settings['forms_contracts'] ) ? $settings['forms_contracts'] : array();
		$provider_contract_counts = array();
		foreach ( $contracts as $contract ) {
			if ( ! is_array( $contract ) || empty( $contract['enabled'] ) ) {
				continue;
			}
			$provider_key = (string) ( $contract['provider'] ?? '' );
			if ( '' === $provider_key ) {
				continue;
			}
			if ( ! isset( $provider_contract_counts[ $provider_key ] ) ) {
				$provider_contract_counts[ $provider_key ] = 0;
			}
			$provider_contract_counts[ $provider_key ]++;
		}
		$integration_rows    = array();
		foreach ( $selected as $integration ) {
			$integration = (string) $integration;
			$status_label = __( 'Configured', 'burrow' );
			if ( in_array( $integration, array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms' ), true ) ) {
				$has_contracts = ! empty( $provider_contract_counts[ $integration ] );
				$is_provider_confirmed = ! empty( $provider_configured[ $integration ] );
				if ( $has_contracts ) {
					$status_label = __( 'Configured', 'burrow' );
				} elseif ( $is_provider_confirmed ) {
					$status_label = __( 'Configured (skipped)', 'burrow' );
				} else {
					$status_label = __( 'Needs setup', 'burrow' );
				}
			} elseif ( 'woocommerce' === $integration ) {
				$status_label = ! empty( $settings['onboarding']['woocommerce_confirmed'] )
					? __( 'Configured', 'burrow' )
					: __( 'Needs setup', 'burrow' );
			}
			$integration_rows[] = array(
				'name'   => (string) ( $labels[ $integration ] ?? $integration ),
				'status' => $status_label,
			);
		}

		$contract_rows = array();
		foreach ( $contracts as $contract ) {
			if ( ! is_array( $contract ) || empty( $contract['enabled'] ) ) {
				continue;
			}
			$provider_key = (string) ( $contract['provider'] ?? '' );
			$contract_rows[] = array(
				'provider'      => (string) ( $labels[ $provider_key ] ?? $provider_key ),
				'formName'      => (string) ( $contract['formName'] ?? '' ),
				'externalFormId'=> (string) ( $contract['externalFormId'] ?? '' ),
				'mode'          => (string) ( $contract['mode'] ?? ( ! empty( $contract['countOnly'] ) ? 'count_only' : 'custom_fields' ) ),
				'mappingCount'  => is_array( $contract['fieldMappings'] ?? null ) ? count( $contract['fieldMappings'] ) : 0,
			);
		}
		?>
		<p><?php esc_html_e( 'Review exactly what will be sent to Burrow when contracts are synced.', 'burrow' ); ?></p>
		<p class="description">
			<?php
			echo wp_kses(
				__( 'Icons are resolved automatically from Burrow defaults. If needed later, icon overrides use Lucide icon key names (for example <code>file-signature</code>, <code>shopping-cart</code>, <code>layers</code>). See <a href="https://lucide.dev/icons" target="_blank" rel="noopener noreferrer">lucide.dev/icons</a>.', 'burrow' ),
				array(
					'a'    => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
					'code' => array(),
				)
			);
			?>
		</p>
		<h3><?php esc_html_e( 'Integration Readiness', 'burrow' ); ?></h3>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th><?php esc_html_e( 'Integration', 'burrow' ); ?></th><th><?php esc_html_e( 'Status', 'burrow' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $integration_rows ) ) : ?>
				<tr><td colspan="2"><?php esc_html_e( 'No integrations selected.', 'burrow' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $integration_rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h3 style="margin-top:16px;"><?php esc_html_e( 'Contracts To Sync', 'burrow' ); ?></h3>
		<table class="widefat striped" style="max-width:1100px;">
			<thead><tr><th><?php esc_html_e( 'Provider', 'burrow' ); ?></th><th><?php esc_html_e( 'Form', 'burrow' ); ?></th><th><?php esc_html_e( 'Form ID', 'burrow' ); ?></th><th><?php esc_html_e( 'Mode', 'burrow' ); ?></th><th><?php esc_html_e( 'Mapped fields', 'burrow' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $contract_rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No enabled contracts yet. Return to provider setup and enable at least one form.', 'burrow' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $contract_rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['provider'] ); ?></td>
						<td><?php echo esc_html( '' !== $row['formName'] ? $row['formName'] : '-' ); ?></td>
						<td><?php echo esc_html( '' !== $row['externalFormId'] ? $row['externalFormId'] : '-' ); ?></td>
						<td><?php echo esc_html( $this->format_tracking_mode_label( $row['mode'] ) ); ?></td>
						<td><?php echo esc_html( (string) (int) $row['mappingCount'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<form method="post">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="sync_forms_contract" />
			<p class="submit">
				<?php submit_button( __( 'Sync Contracts to Burrow', 'burrow' ), 'secondary', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	private function format_tracking_mode_label( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( 'count_only' === $mode ) {
			return __( 'Count-only', 'burrow' );
		}
		if ( 'custom_fields' === $mode ) {
			return __( 'Custom fields', 'burrow' );
		}
		if ( 'off' === $mode ) {
			return __( 'Off', 'burrow' );
		}
		return ucfirst( str_replace( '_', ' ', $mode ) );
	}

	private function render_backfill_step( array $settings ) {
		$support_notes = $this->build_backfill_support_notes( $settings );
		?>
		<p><?php esc_html_e( 'Setup is complete. You can optionally queue a historical data backfill now, or do it later from the Dashboard.', 'burrow' ); ?></p>
		<?php if ( ! empty( $support_notes ) ) : ?>
			<ul class="description" style="margin:0 0 12px 18px;list-style:disc;">
				<?php foreach ( $support_notes as $note ) : ?>
					<li><?php echo wp_kses( (string) $note, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php $this->render_cron_dispatch_notice( 'backfill' ); ?>
		<form method="post">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="queue_backfill" />
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="backfill_window_preset"><?php esc_html_e( 'Window', 'burrow' ); ?></label></th>
					<td>
						<select id="backfill_window_preset" name="backfill_window_preset">
							<?php foreach ( $this->backfill_window_presets() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, 'last_730_days' ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p>
				<?php submit_button( __( 'Queue Backfill Now', 'burrow' ), 'secondary', 'submit', false ); ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=burrow-dashboard' ) ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Go to Dashboard', 'burrow' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Detect stale queued/running backfill jobs and mark as failed for clearer UX.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	private function refresh_backfill_job_state( array $settings ) {
		$job    = isset( $settings['backfill'] ) && is_array( $settings['backfill'] ) ? $settings['backfill'] : array();
		$status = isset( $job['status'] ) ? (string) $job['status'] : 'idle';
		if ( ! in_array( $status, array( 'queued', 'running' ), true ) ) {
			return $job;
		}

		$updated_at = isset( $job['updatedAt'] ) ? strtotime( (string) $job['updatedAt'] ) : false;
		$age_sec    = false === $updated_at ? 0 : ( time() - (int) $updated_at );
		$is_stale   = $age_sec > 600;
		$scheduled  = wp_next_scheduled( 'burrow_backfill_worker' );

		if ( ! $is_stale && false !== $scheduled ) {
			return $job;
		}

		$job['status']    = 'failed';
		$job['updatedAt'] = gmdate( 'c' );
		if ( empty( $job['lastError'] ) ) {
			$job['lastError'] = __( 'Backfill worker appears idle. Resume previous run or start a new backfill.', 'burrow' );
		}
		$settings['backfill'] = $job;
		$this->options_repo->save_settings( $settings );

		return $job;
	}

	/**
	 * Build dynamic backfill support notes from configured integrations.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<int,string>
	 */
	private function build_backfill_support_notes( array $settings ) {
		$notes    = array();
		$selected = isset( $settings['onboarding']['selected_integrations'] ) && is_array( $settings['onboarding']['selected_integrations'] )
			? $settings['onboarding']['selected_integrations']
			: array();
		$forms_selected = array_intersect( $selected, array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms' ) );
		if ( ! empty( $forms_selected ) ) {
			$notes[] = __( 'Backfill includes events for configured forms contracts.', 'burrow' );
		}

		if ( in_array( 'woocommerce', $selected, true ) ) {
			$woo_mode = isset( $settings['onboarding']['woocommerce_mode'] ) ? (string) $settings['onboarding']['woocommerce_mode'] : 'track';
			if ( 'track' === $woo_mode ) {
				$notes[] = __( 'WooCommerce order and line-item events are included in backfill.', 'burrow' );
			} else {
				$notes[] = __( 'WooCommerce is selected but tracking is off, so Woo events are skipped in backfill.', 'burrow' );
			}
		}

		if ( in_array( 'contact-form-7', $selected, true ) ) {
			if ( class_exists( '\Flamingo_Inbound_Message' ) ) {
				$notes[] = __( 'Contact Form 7 historical backfill is enabled via Flamingo submissions.', 'burrow' );
			} else {
				$url = admin_url( 'plugin-install.php?s=Flamingo&tab=search&type=term' );
				$notes[] = sprintf(
					/* translators: %s Flamingo plugin install URL */
					__( 'Contact Form 7 does not store submissions by default. Install <a href="%s" target="_blank" rel="noopener noreferrer">Flamingo</a> to include historical CF7 data.', 'burrow' ),
					esc_url( $url )
				);
			}
		}

		$notes[] = __( 'Backfill uses deterministic event keys. Duplicate protection depends on outbox retention history.', 'burrow' );

		return $notes;
	}

	private function backfill_window_presets() {
		return array(
			'last_7_days'   => __( 'Last 7 days', 'burrow' ),
			'last_30_days'  => __( 'Last 30 days', 'burrow' ),
			'last_90_days'  => __( 'Last 90 days', 'burrow' ),
			'last_365_days' => __( 'Past Year', 'burrow' ),
			'last_730_days' => __( 'Two Years', 'burrow' ),
			'all_time'      => __( 'All time', 'burrow' ),
		);
	}

	private function resolve_backfill_window_for_preset( $preset ) {
		$preset = sanitize_key( (string) $preset );
		$now    = time();
		$start  = null;
		switch ( $preset ) {
			case 'last_7_days':
				$start = strtotime( '-7 days', $now );
				break;
			case 'last_30_days':
				$start = strtotime( '-30 days', $now );
				break;
			case 'last_90_days':
				$start = strtotime( '-90 days', $now );
				break;
			case 'last_365_days':
				$start = strtotime( '-365 days', $now );
				break;
			case 'last_730_days':
				$start = strtotime( '-730 days', $now );
				break;
			case 'all_time':
				$start = strtotime( '1970-01-01 00:00:00' );
				break;
			default:
				return array(
					'error' => __( 'Invalid backfill window preset selected.', 'burrow' ),
				);
		}
		if ( false === $start || $start > $now ) {
			return array(
				'error' => __( 'Unable to resolve backfill window.', 'burrow' ),
			);
		}
		return array(
			'preset'      => $preset,
			'windowStart' => gmdate( 'c', (int) $start ),
			'windowEnd'   => gmdate( 'c', (int) $now ),
			'error'       => '',
		);
	}

	private function default_wizard_step( array $settings ) {
		$has_key = ! empty( $settings['ingestion_key']['key'] ) || get_transient( 'burrow_onboarding_api_key' );
		if ( ! $has_key ) {
			return 'connection';
		}
		if ( empty( $settings['routing']['projectId'] ) ) {
			return 'project';
		}
		$selected = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		if ( empty( $selected ) ) {
			return 'integrations';
		}
		$provider_configured = isset( $settings['onboarding']['provider_configured'] ) && is_array( $settings['onboarding']['provider_configured'] )
			? $settings['onboarding']['provider_configured']
			: array();
		$form_providers = array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms' );
		foreach ( $form_providers as $provider ) {
			if ( in_array( $provider, $selected, true ) && empty( $provider_configured[ $provider ] ) ) {
				return $provider;
			}
		}
		if ( in_array( 'woocommerce', $selected, true ) && empty( $settings['onboarding']['woocommerce_confirmed'] ) ) {
			return 'woocommerce';
		}
		if ( empty( $settings['contract_sync']['syncedAt'] ) ) {
			return 'review';
		}
		return 'backfill';
	}

	private function redirect_with_notice( $step, $message, $is_error = null ) {
		if ( null === $is_error ) {
			$is_error = false !== stripos( $message, 'failed' ) || false !== stripos( $message, 'unable' ) || false !== stripos( $message, 'please' ) || false !== stripos( $message, 'error' ) || false !== stripos( $message, 'select a target' ) || false !== stripos( $message, 'custom fields' );
		}
		if ( 'dashboard' === $step || 'operations' === $step ) {
			$page = 'burrow-dashboard';
		} elseif ( 'outbox' === $step ) {
			$page = 'burrow-outbox';
		} else {
			$page = 'burrow-setup';
		}
		$args = array(
			'page'          => $page,
			'step'          => $step,
			'burrow_notice' => rawurlencode( $message ),
			'burrow_error'  => $is_error ? '1' : '0',
		);
		if ( 'burrow-setup' === $page && in_array( $step, array( 'overview', 'integrations', 'connection', 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms', 'woocommerce' ), true ) ) {
			// Keep Settings section context after save.
			$args['section'] = $step;
		}
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function render_admin_notice_from_query() {
		$notice   = isset( $_GET['burrow_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['burrow_notice'] ) ) : '';
		$is_error = isset( $_GET['burrow_error'] ) && '1' === (string) $_GET['burrow_error'];
		if ( '' === $notice ) {
			return;
		}
		echo '<div class="notice ' . ( $is_error ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice ) . '</p></div>';
	}

	private function render_burrow_page_header( $title ) {
		$logo_src = $this->get_brand_logo_src();
		?>
		<div style="margin:6px 0 12px 0;">
			<img src="<?php echo esc_attr( $logo_src ); ?>" alt="Burrow" style="max-width:220px;height:auto;display:block;margin-bottom:8px;" />
			<h1 style="margin:0;"><?php echo esc_html( (string) $title ); ?></h1>
		</div>
		<?php
	}

	/**
	 * Render an inline notice about WP-Cron dispatch if relevant.
	 *
	 * @param string $context 'outbox' or 'backfill' for tailored messaging.
	 */
	private function render_cron_dispatch_notice( $context = 'outbox' ) {
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alt_cron      = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$next_outbox   = wp_next_scheduled( 'burrow_outbox_worker' );

		if ( $cron_disabled && false === $next_outbox ) {
			?>
			<div class="notice notice-warning inline" style="margin:8px 0 12px;">
				<p>
					<strong><?php esc_html_e( 'WP-Cron is disabled on this site.', 'burrow' ); ?></strong>
					<?php esc_html_e( 'Burrow relies on WP-Cron to dispatch queued events. Ensure a system cron (e.g. crontab) hits', 'burrow' ); ?>
					<code><?php echo esc_html( site_url( '/wp-cron.php' ) ); ?></code>
					<?php esc_html_e( 'every 1–2 minutes, or events will remain pending until the next page load triggers cron.', 'burrow' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		if ( 'backfill' === $context ) {
			?>
			<p class="description" style="margin:4px 0 8px;">
				<span class="dashicons dashicons-clock" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:2px;"></span>
				<?php esc_html_e( 'Events are dispatched via WP-Cron every ~2 minutes. On low-traffic sites, visit any page to trigger cron, or configure a system cron for reliable scheduling.', 'burrow' ); ?>
			</p>
			<?php
		} else {
			?>
			<p class="description" style="margin:4px 0 8px;">
				<span class="dashicons dashicons-clock" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:2px;"></span>
				<?php
				esc_html_e( 'Outbox events are flushed via WP-Cron every ~2 minutes.', 'burrow' );
				if ( false !== $next_outbox ) {
					$diff = (int) $next_outbox - time();
					if ( $diff > 0 ) {
						echo ' ';
						printf(
							esc_html__( 'Next scheduled run in %s.', 'burrow' ),
							esc_html( human_time_diff( time(), (int) $next_outbox ) )
						);
					} else {
						echo ' ';
						esc_html_e( 'Next run is due now and will fire on the next page load.', 'burrow' );
					}
				}
				?>
			</p>
			<?php
		}
	}

	private function get_brand_logo_src() {
		$paths = array(
			BURROW_PLUGIN_DIR . 'admin/images/burrow.svg',
			BURROW_PLUGIN_DIR . 'admin/images/burrow-icon.svg',
		);
		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$contents = file_get_contents( $path );
			if ( false === $contents || '' === trim( (string) $contents ) ) {
				continue;
			}
			return 'data:image/svg+xml;base64,' . base64_encode( (string) $contents );
		}
		return $this->embedded_burrow_logo_data_uri();
	}

	private function embedded_burrow_logo_data_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 60"><rect width="240" height="60" fill="none"/><text x="8" y="40" font-family="Arial, sans-serif" font-size="34" font-weight="700" fill="#0b6ea8">Burrow</text></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private function render_status_badge_styles() {
		?>
		<style>
			.burrow-status-badge {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 3px 10px;
				border-radius: 999px;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.5;
				margin-right: 6px;
				border: 1px solid transparent;
			}
			.burrow-status-pending { background: #fff7ed; color: #9a3412; border-color: #fdba74; }
			.burrow-status-queued { background: #fff7ed; color: #9a3412; border-color: #fdba74; }
			.burrow-status-retrying { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
			.burrow-status-running { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
			.burrow-status-failed { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; }
			.burrow-status-sent,
			.burrow-status-completed,
			.burrow-status-success { background: #ecfdf5; color: #047857; border-color: #86efac; }
			.burrow-status-idle,
			.burrow-status-unknown { background: #f3f4f6; color: #374151; border-color: #d1d5db; }
		</style>
		<?php
	}

	private function render_status_badge( $status, $count = null ) {
		$status = sanitize_key( (string) $status );
		$class  = in_array( $status, array( 'pending', 'queued', 'retrying', 'running', 'failed', 'sent', 'completed', 'success', 'idle' ), true ) ? $status : 'unknown';
		$label  = ucfirst( str_replace( '_', ' ', $status ) );
		if ( '' === $label ) {
			$label = __( 'Unknown', 'burrow' );
		}
		$text = null === $count ? $label : sprintf( '%s: %d', $label, (int) $count );
		return '<span class="burrow-status-badge burrow-status-' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	private function build_discover_payload() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		return array(
			'platform'      => 'wordpress',
			'pluginVersion' => BURROW_VERSION,
			'site'          => array( 'url' => site_url(), 'cmsVersion' => get_bloginfo( 'version' ) ),
			'capabilities'  => array( 'forms' => array_values( $this->detect_forms_capabilities() ), 'ecommerce' => class_exists( 'WooCommerce' ) ? array( 'woocommerce' ) : array(), 'ecommerce_funnel' => class_exists( 'WooCommerce' ), 'system' => true ),
			'plugins'       => array_keys( $plugins ),
		);
	}

	private function extract_project_candidates( array $body ) {
		$list = array();
		foreach ( array( 'projects', 'projectMatches', 'matches' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				$list = $body[ $key ];
				break;
			}
		}
		$items = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$client  = isset( $row['client'] ) && is_array( $row['client'] ) ? $row['client'] : $row;
			$project = isset( $row['project'] ) && is_array( $row['project'] ) ? $row['project'] : $row;
			$items[] = array(
				'organizationId' => (string) ( $row['organizationId'] ?? $client['organizationId'] ?? '' ),
				'clientId'       => (string) ( $row['clientId'] ?? $client['id'] ?? '' ),
				'projectId'      => (string) ( $row['projectId'] ?? $project['id'] ?? '' ),
				'clientName'     => (string) ( $client['name'] ?? $row['clientName'] ?? '' ),
				'projectName'    => (string) ( $project['name'] ?? $row['projectName'] ?? '' ),
				'siteUrl'        => (string) ( $project['url'] ?? $row['siteUrl'] ?? '' ),
			);
		}
		return $items;
	}

	private function merge_gravity_contracts( array $settings, $gravity, &$error_message = '' ) {
		$gravity   = is_array( $gravity ) ? $gravity : array();
		$forms     = isset( $gravity['forms'] ) && is_array( $gravity['forms'] ) ? $gravity['forms'] : array();
		$existing  = (array) ( $settings['forms_contracts'] ?? array() );
		$error_message = '';
		$contracts = array();
		foreach ( $existing as $k => $v ) {
			if ( 0 !== strpos( (string) $k, 'gravity-forms:' ) ) {
				$contracts[ $k ] = $v;
			}
		}
		foreach ( $forms as $id => $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$mode = isset( $form['mode'] ) ? sanitize_key( (string) $form['mode'] ) : 'off';
			if ( ! in_array( $mode, array( 'off', 'count_only', 'custom_fields' ), true ) ) {
				$mode = 'off';
			}
			if ( 'off' === $mode ) {
				continue;
			}
			$count_only = 'count_only' === $mode;
			$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
			$m      = array();
			foreach ( $fields as $f ) {
				if ( ! is_array( $f ) || empty( $f['include'] ) ) {
					continue;
				}
				$target = $this->sanitize_mapping_target( $f['target'] ?? '' );
				if ( '' === $target ) {
					$form_name   = sanitize_text_field( (string) ( $form['formName'] ?? $id ) );
					$field_label = sanitize_text_field( (string) ( $f['sourceLabel'] ?? $f['externalFieldId'] ?? __( 'field', 'burrow' ) ) );
					$error_message = sprintf(
						/* translators: 1: field label, 2: form name */
						__( 'Select a target for "%1$s" in "%2$s".', 'burrow' ),
						$field_label,
						$form_name
					);
					return $existing;
				}
				$m[] = array(
					'externalFieldId'      => sanitize_text_field( (string) ( $f['externalFieldId'] ?? '' ) ),
					'sourceLabel'          => sanitize_text_field( (string) ( $f['sourceLabel'] ?? '' ) ),
					'canonicalKey'         => sanitize_text_field( (string) ( $f['canonicalKey'] ?? '' ) ),
					'target'               => $target,
					'dataType'             => 'string',
					'reportable'           => 'tags' === $target,
					'displayLabelOverride' => sanitize_text_field( (string) ( $f['displayLabelOverride'] ?? '' ) ),
				);
			}
			if ( empty( $m ) && 'custom_fields' === $mode ) {
				$form_name = sanitize_text_field( (string) ( $form['formName'] ?? $id ) );
				$error_message = sprintf(
					/* translators: %s: form name */
					__( '"%s" is set to Custom fields. Include at least one field.', 'burrow' ),
					$form_name
				);
				return $existing;
			}
			$form_id                = sanitize_text_field( (string) $id );
			$prefixed_id            = \Burrow::prefixed_form_id( 'gravity-forms', $form_id );
			$contract_key           = 'gravity-forms:' . $form_id;
			$current_contract       = isset( $existing[ $contract_key ] ) && is_array( $existing[ $contract_key ] ) ? $existing[ $contract_key ] : array();
			$icon_override          = isset( $form['icon'] ) ? sanitize_text_field( (string) $form['icon'] ) : (string) ( $current_contract['icon'] ?? '' );
			$contracts[ 'gravity-forms:' . $form_id ] = array(
				'provider'       => 'gravity-forms',
				'externalFormId' => $prefixed_id,
				'formHandle'     => sanitize_title( (string) ( $form['formName'] ?? '' ) ),
				'formName'       => sanitize_text_field( (string) ( $form['formName'] ?? '' ) ),
				'enabled'        => true,
				'countOnly'      => $count_only,
				'mode'           => $mode,
				'fieldMappings'  => $m,
				'icon'           => '' !== trim( $icon_override ) ? $icon_override : null,
			);
		}
		return $contracts;
	}

	private function merge_simple_provider_contracts( array $settings, $provider, $provider_forms, &$error_message = '' ) {
		$provider      = sanitize_key( (string) $provider );
		$provider_forms = is_array( $provider_forms ) ? $provider_forms : array();
		$forms         = isset( $provider_forms['forms'] ) && is_array( $provider_forms['forms'] ) ? $provider_forms['forms'] : array();
		$existing      = (array) ( $settings['forms_contracts'] ?? array() );
		$error_message = '';
		$contracts     = array();
		$prefix        = $provider . ':';
		foreach ( $existing as $key => $value ) {
			if ( 0 !== strpos( (string) $key, $prefix ) ) {
				$contracts[ $key ] = $value;
			}
		}
		foreach ( $forms as $id => $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$mode = isset( $form['mode'] ) ? sanitize_key( (string) $form['mode'] ) : 'off';
			$allowed_modes = in_array( $provider, array( 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms' ), true )
				? array( 'off', 'count_only', 'custom_fields' )
				: array( 'off', 'count_only' );
			if ( ! in_array( $mode, $allowed_modes, true ) ) {
				$mode = 'off';
			}
			if ( 'off' === $mode ) {
				continue;
			}
			$mappings = array();
			if ( 'custom_fields' === $mode ) {
				$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) || empty( $field['include'] ) ) {
						continue;
					}
					$target = $this->sanitize_mapping_target( $field['target'] ?? '' );
					if ( '' === $target ) {
						$form_name   = sanitize_text_field( (string) ( $form['formName'] ?? $id ) );
						$field_label = sanitize_text_field( (string) ( $field['sourceLabel'] ?? $field['externalFieldId'] ?? __( 'field', 'burrow' ) ) );
						$error_message = sprintf(
							/* translators: 1: field label, 2: form name */
							__( 'Select a target for "%1$s" in "%2$s".', 'burrow' ),
							$field_label,
							$form_name
						);
						return $existing;
					}
					$mappings[] = array(
						'externalFieldId'      => sanitize_text_field( (string) ( $field['externalFieldId'] ?? '' ) ),
						'sourceLabel'          => sanitize_text_field( (string) ( $field['sourceLabel'] ?? '' ) ),
						'canonicalKey'         => sanitize_text_field( (string) ( $field['canonicalKey'] ?? '' ) ),
						'target'               => $target,
						'dataType'             => sanitize_text_field( (string) ( $field['dataType'] ?? 'string' ) ),
						'reportable'           => 'tags' === $target,
						'displayLabelOverride' => sanitize_text_field( (string) ( $field['sourceLabel'] ?? '' ) ),
					);
				}
				if ( empty( $mappings ) ) {
					$form_name = sanitize_text_field( (string) ( $form['formName'] ?? $id ) );
					$error_message = sprintf(
						/* translators: %s: form name */
						__( '"%s" is set to Custom fields. Include at least one field.', 'burrow' ),
						$form_name
					);
					return $existing;
				}
			}
			$form_id = sanitize_text_field( (string) $id );
			$prefixed_id = \Burrow::prefixed_form_id( $provider, $form_id );
			$contract_key = $prefix . $form_id;
			$current_contract = isset( $existing[ $contract_key ] ) && is_array( $existing[ $contract_key ] ) ? $existing[ $contract_key ] : array();
			$icon_override = isset( $form['icon'] ) ? sanitize_text_field( (string) $form['icon'] ) : (string) ( $current_contract['icon'] ?? '' );
			$contracts[ $prefix . $form_id ] = array(
				'provider'       => $provider,
				'externalFormId' => $prefixed_id,
				'formHandle'     => sanitize_title( (string) ( $form['formName'] ?? '' ) ),
				'formName'       => sanitize_text_field( (string) ( $form['formName'] ?? '' ) ),
				'enabled'        => true,
				'countOnly'      => 'custom_fields' !== $mode,
				'mode'           => $mode,
				'fieldMappings'  => $mappings,
				'icon'           => '' !== trim( $icon_override ) ? $icon_override : null,
			);
		}
		return $contracts;
	}

	private function build_forms_contract_payload( array $settings ) {
		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		return array(
			'platform'      => 'wordpress',
			'pluginVersion' => BURROW_VERSION,
			'site'          => array( 'url' => site_url(), 'cmsVersion' => get_bloginfo( 'version' ) ),
			'routing'       => array(
				'organizationId' => $settings['routing']['organizationId'] ?? '',
				'clientId'       => $sdk->clientId ?? '',
				'projectId'      => $sdk->projectId ?? '',
				'projectSourceId'=> $sdk->formsProjectSourceId ?? '',
			),
			'formsContracts' => array_values( (array) $settings['forms_contracts'] ),
		);
	}

	private function persist_link_response( array $response ) {
		if ( empty( $response['ok'] ) ) {
			return 'Link failed: ' . ( $response['error'] ?? 'Unknown error' );
		}
		$body     = (array) ( $response['body'] ?? array() );
		$settings = $this->options_repo->get_settings();
		$settings = BurrowWP\Core\Onboarding\LinkStateManager::apply_link_response( $settings, $body );
		if ( isset( $body['sdkState'] ) && is_array( $body['sdkState'] ) ) {
			$settings = $this->apply_sdk_state_to_settings( $settings, $body['sdkState'] );
		}
		$this->options_repo->save_settings( $settings );
		if ( empty( $settings['routing']['projectId'] ) ) {
			return __( 'Project linked response received, but projectId was missing. Please reselect and link the project.', 'burrow' );
		}
		return __( 'Project linked successfully.', 'burrow' );
	}

	private function validate_routing_before_contract_sync( array $settings ) {
		$routing = isset( $settings['routing'] ) && is_array( $settings['routing'] ) ? $settings['routing'] : array();
		if ( empty( $routing['projectId'] ) ) {
			return __( 'Project is not linked yet. Please complete Step 2: Select Project before syncing contracts.', 'burrow' );
		}
		return '';
	}

	private function persist_contract_response( array $response ) {
		if ( empty( $response['ok'] ) ) {
			return 'Contract sync failed: ' . ( $response['error'] ?? 'Unknown error' );
		}
		$body = (array) ( $response['body'] ?? array() );
		$s    = $this->options_repo->get_settings();

		if ( isset( $body['sdkState'] ) && is_array( $body['sdkState'] ) ) {
			$s = $this->apply_sdk_state_to_settings( $s, $body['sdkState'] );
		}

		$s['contract_sync'] = array(
			'version'  => isset( $body['contractsVersion'] ) ? (string) $body['contractsVersion'] : (string) ( $body['version'] ?? '' ),
			'hash'     => (string) ( $body['hash'] ?? '' ),
			'syncedAt' => gmdate( 'c' ),
		);

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $s['sdk_state'] ) && is_array( $s['sdk_state'] ) ? $s['sdk_state'] : array()
		);
		$project_id = $sdk->projectId ?? '';
		if ( '' !== $project_id ) {
			try {
				$sdk_response = \Burrow\Sdk\Contracts\FormsContractsResponse::fromResponseBody( $body );
				$cache = \Burrow\Sdk\Contracts\FormsContractCache::fromResponse( $project_id, $sdk_response );
				$s['forms_contract_cache'] = \Burrow\Sdk\Contracts\FormsContractCacheSerializer::toArray( $cache );
			} catch ( \Throwable $e ) {
				error_log( '[Burrow] Contract cache serialize failed: ' . $e->getMessage() );
			}
		}

		$this->options_repo->save_settings( $s );
		return __( 'Contracts synced to Burrow.', 'burrow' );
	}

	/**
	 * Mirror SDK client state into plugin settings.
	 *
	 * The SDK state blob is canonical.  We derive routing/ingestion_key
	 * from it so existing admin UI and event emission code keeps working.
	 *
	 * @param array<string,mixed> $settings  Settings.
	 * @param array<string,mixed> $sdk_state SDK state array.
	 * @return array<string,mixed>
	 */
	private function apply_sdk_state_to_settings( array $settings, array $sdk_state ) {
		$settings['sdk_state'] = $sdk_state;

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray( $sdk_state );

		if ( null !== $sdk->ingestionKey ) {
			if ( ! isset( $settings['ingestion_key'] ) || ! is_array( $settings['ingestion_key'] ) ) {
				$settings['ingestion_key'] = array();
			}
			$settings['ingestion_key']['key'] = $sdk->ingestionKey;
		}
		if ( null !== $sdk->projectId ) {
			$settings['routing']['projectId'] = $sdk->projectId;
			if ( empty( $settings['ingestion_key']['projectId'] ) ) {
				$settings['ingestion_key']['projectId'] = $sdk->projectId;
			}
		}
		if ( null !== $sdk->clientId ) {
			$settings['routing']['clientId'] = $sdk->clientId;
		}
		if ( null !== $sdk->formsProjectSourceId ) {
			$settings['routing']['projectSourceId'] = $sdk->formsProjectSourceId;
			if ( ! isset( $settings['routing']['sourceIds'] ) || ! is_array( $settings['routing']['sourceIds'] ) ) {
				$settings['routing']['sourceIds'] = array();
			}
			foreach ( array( 'forms', 'ecommerce', 'system' ) as $ch ) {
				if ( empty( $settings['routing']['sourceIds'][ $ch ] ) ) {
					$settings['routing']['sourceIds'][ $ch ] = $sdk->formsProjectSourceId;
				}
			}
		}
		if ( null !== $sdk->contractsVersion ) {
			$settings['contract_sync']['version'] = $sdk->contractsVersion;
		}

		return $settings;
	}

	private function detect_forms_capabilities() {
		$out = array();
		if ( class_exists( 'GFForms' ) ) {
			$out[] = 'gravity-forms';
		}
		if ( class_exists( 'Ninja_Forms' ) ) {
			$out[] = 'ninja-forms';
		}
		if ( class_exists( 'WPCF7' ) ) {
			$out[] = 'contact-form-7';
		}
		if ( defined( 'FLUENTFORM' ) || class_exists( '\FluentForm\App' ) ) {
			$out[] = 'fluent-forms';
		}
		if ( function_exists( 'wpforms' ) ) {
			$out[] = 'wpforms';
		}
		if ( class_exists( '\FrmAppHelper' ) ) {
			$out[] = 'formidable-forms';
		}
		return $out;
	}

	private function detected_integrations() {
		$list = $this->detect_forms_capabilities();
		if ( class_exists( 'WooCommerce' ) ) {
			$list[] = 'woocommerce';
		}
		return $list;
	}

	private function integration_labels() {
		return array(
			'gravity-forms'    => 'Gravity Forms',
			'ninja-forms'      => 'Ninja Forms',
			'contact-form-7'   => 'Contact Form 7',
			'fluent-forms'     => 'Fluent Forms',
			'wpforms'          => 'WPForms',
			'formidable-forms' => 'Formidable Forms',
			'woocommerce'      => 'WooCommerce',
		);
	}

	private function get_integration_icon_markup( $integration ) {
		$fallback_class = $this->get_integration_fallback_icon_class( $integration );
		$menu_icons = array(
			'gravity-forms'    => array( 'gf_edit_forms', 'gravityforms' ),
			'ninja-forms'      => array( 'ninja-forms', 'nf-dashboard' ),
			'contact-form-7'   => array( 'wpcf7', 'contact' ),
			'fluent-forms'     => array( 'fluent_forms', 'fluent_forms_settings' ),
			'wpforms'          => array( 'wpforms-overview', 'wpforms-entries' ),
			'formidable-forms' => array( 'formidable', 'frm_form' ),
			'woocommerce'      => array( 'woocommerce' ),
		);

		$menu_icon = $this->resolve_menu_icon_value( $menu_icons[ $integration ] ?? array() );
		if ( is_string( $menu_icon ) && 0 === strpos( $menu_icon, 'dashicons-' ) ) {
			return '<span class="dashicons ' . esc_attr( $menu_icon ) . '" aria-hidden="true"></span>';
		}
		if ( is_string( $menu_icon ) && 0 === strpos( $menu_icon, 'data:image' ) ) {
			$valid_data_uri = 1 === preg_match( '/^data:image\/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9\/+=]+$/', $menu_icon );
			if ( $valid_data_uri ) {
				return '<img class="burrow-menu-glyph" src="' . esc_attr( $menu_icon ) . '" alt="" aria-hidden="true" />';
			}
		}
		return '<span class="dashicons ' . esc_attr( $fallback_class ) . '" aria-hidden="true"></span>';
	}

	private function get_integration_fallback_icon_class( $integration ) {
		$fallback = array(
			'gravity-forms'    => 'dashicons-feedback',
			'ninja-forms'      => 'dashicons-email-alt2',
			'contact-form-7'   => 'dashicons-email',
			'fluent-forms'     => 'dashicons-editor-table',
			'wpforms'          => 'dashicons-list-view',
			'formidable-forms' => 'dashicons-forms',
			'woocommerce'      => 'dashicons-cart',
		);
		return isset( $fallback[ $integration ] ) ? $fallback[ $integration ] : 'dashicons-admin-plugins';
	}

	private function resolve_menu_icon_value( array $candidate_slugs ) {
		global $menu;
		if ( empty( $menu ) || empty( $candidate_slugs ) ) {
			return null;
		}
		foreach ( $menu as $row ) {
			if ( ! is_array( $row ) || empty( $row[2] ) || empty( $row[6] ) ) {
				continue;
			}
			$slug = (string) $row[2];
			foreach ( $candidate_slugs as $candidate ) {
				$candidate = (string) $candidate;
				if ( $slug === $candidate || 0 === strpos( $slug, $candidate ) ) {
					return (string) $row[6];
				}
			}
		}
		return null;
	}

	private function build_wizard_steps( array $settings ) {
		$steps = array( 'connection' => 'Connection', 'project' => 'Project', 'integrations' => 'Integrations' );
		$selected = (array) ( $settings['onboarding']['selected_integrations'] ?? array() );
		$form_provider_labels = array(
			'gravity-forms'     => 'Gravity Forms',
			'contact-form-7'    => 'Contact Form 7',
			'ninja-forms'       => 'Ninja Forms',
			'fluent-forms'      => 'Fluent Forms',
			'wpforms'           => 'WPForms',
			'formidable-forms'  => 'Formidable Forms',
		);
		foreach ( array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms' ) as $provider_key ) {
			if ( in_array( $provider_key, $selected, true ) ) {
				$steps[ $provider_key ] = $form_provider_labels[ $provider_key ];
			}
		}
		if ( in_array( 'woocommerce', $selected, true ) ) {
			$steps['woocommerce'] = 'WooCommerce';
		}
		$steps['review'] = 'Review';
		$steps['backfill'] = 'Finish';
		return $steps;
	}

	private function next_config_step( array $settings, $from ) {
		$keys = array_keys( $this->build_wizard_steps( $settings ) );
		$idx  = array_search( $from, $keys, true );
		if ( false === $idx ) {
			return 'review';
		}
		return isset( $keys[ $idx + 1 ] ) ? $keys[ $idx + 1 ] : 'review';
	}

	private function previous_step( array $settings, $from ) {
		$keys = array_keys( $this->build_wizard_steps( $settings ) );
		$idx  = array_search( $from, $keys, true );
		if ( false === $idx || 0 === $idx ) {
			return '';
		}
		return (string) $keys[ $idx - 1 ];
	}

	private function get_enabled_contract_keys( array $contracts ) {
		$keys = array();
		foreach ( $contracts as $key => $contract ) {
			if ( is_array( $contract ) && ! empty( $contract['enabled'] ) ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}

	private function render_operations_contract_editor( array $row ) {
		$contract        = isset( $row['contract'] ) && is_array( $row['contract'] ) ? $row['contract'] : array();
		$contract_key    = (string) ( $row['contractKey'] ?? '' );
		$provider_key    = (string) ( $row['providerKey'] ?? '' );
		$form_id         = (string) ( $row['externalFormId'] ?? '' );
		$form_name       = (string) ( $row['formName'] ?? $form_id );
		$current_mode    = (string) ( $row['mode'] ?? 'count_only' );
		$current_icon    = (string) ( $row['icon'] ?? '' );
		$supports_custom = $this->operations_provider_supports_custom_fields( $provider_key );
		$fields          = $this->operations_provider_fields( $provider_key, $form_id, $contract );
		$mapped_lookup   = $this->operations_mapped_lookup(
			isset( $contract['fieldMappings'] ) && is_array( $contract['fieldMappings'] ) ? $contract['fieldMappings'] : array()
		);
		$editor_id       = 'burrow-ops-contract-editor';
		?>
		<hr />
		<h3><?php echo esc_html( sprintf( __( 'Editing: %1$s - %2$s', 'burrow' ), (string) ( $row['provider'] ?? $provider_key ), $form_name ) ); ?></h3>
		<form id="<?php echo esc_attr( $editor_id ); ?>" method="post" style="max-width:1300px;">
			<?php wp_nonce_field( 'burrow_admin_action', 'burrow_nonce' ); ?>
			<input type="hidden" name="burrow_action" value="save_operations_contract" />
			<input type="hidden" name="operations_contract_key" value="<?php echo esc_attr( $contract_key ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Tracking mode', 'burrow' ); ?></th>
					<td>
						<label style="margin-right:14px;"><input class="burrow-ops-mode-radio" type="radio" name="operations_contract[mode]" value="off" <?php checked( 'off', $current_mode ); ?> /> <?php esc_html_e( 'Off', 'burrow' ); ?></label>
						<label style="margin-right:14px;"><input class="burrow-ops-mode-radio" type="radio" name="operations_contract[mode]" value="count_only" <?php checked( 'count_only', $current_mode ); ?> /> <?php esc_html_e( 'Count-only', 'burrow' ); ?></label>
						<?php if ( $supports_custom ) : ?>
							<label><input class="burrow-ops-mode-radio" type="radio" name="operations_contract[mode]" value="custom_fields" <?php checked( 'custom_fields', $current_mode ); ?> /> <?php esc_html_e( 'Custom fields', 'burrow' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="burrow-ops-contract-icon"><?php esc_html_e( 'Icon override', 'burrow' ); ?></label></th>
					<td>
						<input id="burrow-ops-contract-icon" type="text" class="regular-text" name="operations_contract[icon]" value="<?php echo esc_attr( $current_icon ); ?>" placeholder="file-signature" />
						<p class="description"><?php esc_html_e( 'Optional Lucide icon key. Leave blank to use SDK default.', 'burrow' ); ?></p>
					</td>
				</tr>
			</table>

			<?php if ( $supports_custom ) : ?>
				<?php if ( empty( $fields ) ) : ?>
					<p class="description"><?php esc_html_e( 'No fields were detected for this form.', 'burrow' ); ?></p>
				<?php else : ?>
					<table class="widefat striped burrow-ops-mapping-table">
						<thead>
							<tr><th><?php esc_html_e( 'Include', 'burrow' ); ?></th><th><?php esc_html_e( 'Field', 'burrow' ); ?></th><th><?php esc_html_e( 'Type', 'burrow' ); ?></th><th><?php esc_html_e( 'Canonical Key', 'burrow' ); ?></th><th><?php esc_html_e( 'Target', 'burrow' ); ?></th></tr>
						</thead>
						<tbody>
						<?php foreach ( $fields as $field ) : ?>
							<?php
							$field_id   = (string) ( $field['id'] ?? '' );
							$field_name = (string) ( $field['name'] ?? $field_id );
							$field_type = (string) ( $field['type'] ?? 'text' );
							$existing   = isset( $mapped_lookup[ $field_id ] ) ? $mapped_lookup[ $field_id ] : array();
							$checked    = ! empty( $existing );
							$target     = isset( $existing['target'] ) && in_array( (string) $existing['target'], array( 'properties', 'tags' ), true )
								? (string) $existing['target']
								: '';
							$canonical  = isset( $existing['canonicalKey'] ) ? (string) $existing['canonicalKey'] : $this->label_to_canonical_key( $field_name );
							?>
							<tr>
								<td><input class="burrow-ops-field-checkbox" type="checkbox" name="operations_contract[fields][<?php echo esc_attr( $field_id ); ?>][include]" value="1" <?php checked( $checked ); ?> /></td>
								<td><?php echo esc_html( $field_name ); ?></td>
								<td><?php echo esc_html( $field_type ); ?></td>
								<td><input type="text" name="operations_contract[fields][<?php echo esc_attr( $field_id ); ?>][canonicalKey]" value="<?php echo esc_attr( $canonical ); ?>" /></td>
								<td>
									<select name="operations_contract[fields][<?php echo esc_attr( $field_id ); ?>][target]">
										<option value="" <?php selected( '', $target ); ?>><?php esc_html_e( 'Select one', 'burrow' ); ?></option>
										<option value="properties" <?php selected( 'properties', $target ); ?>>properties</option>
										<option value="tags" <?php selected( 'tags', $target ); ?>>tags</option>
									</select>
								</td>
							</tr>
							<input type="hidden" name="operations_contract[fields][<?php echo esc_attr( $field_id ); ?>][externalFieldId]" value="<?php echo esc_attr( $field_id ); ?>" />
							<input type="hidden" name="operations_contract[fields][<?php echo esc_attr( $field_id ); ?>][sourceLabel]" value="<?php echo esc_attr( $field_name ); ?>" />
							<input type="hidden" name="operations_contract[fields][<?php echo esc_attr( $field_id ); ?>][dataType]" value="<?php echo esc_attr( $field_type ); ?>" />
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<p style="margin-top:10px;">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save Contract Edits', 'burrow' ); ?></button>
				<button type="submit" class="button button-primary" name="sync_contracts" value="1"><?php esc_html_e( 'Save + Sync to Burrow', 'burrow' ); ?></button>
			</p>
		</form>
		<?php if ( $supports_custom ) : ?>
			<script>
				(function () {
					const root = document.getElementById('<?php echo esc_js( $editor_id ); ?>');
					if (!root) return;
					const modeInputs = Array.from(root.querySelectorAll('.burrow-ops-mode-radio'));
					const mappingTable = root.querySelector('.burrow-ops-mapping-table');
					if (!mappingTable) return;
					const fields = Array.from(mappingTable.querySelectorAll('.burrow-ops-field-checkbox'));
					const controls = Array.from(mappingTable.querySelectorAll('input, select, textarea'));
					if (!modeInputs.length || !controls.length) return;
					const currentMode = () => {
						const picked = modeInputs.find((input) => input.checked);
						return picked ? picked.value : 'off';
					};
					const syncRow = (row, fieldsEnabled) => {
						const checkbox = row.querySelector('.burrow-ops-field-checkbox');
						const mappingControls = row.querySelectorAll('input:not([type=checkbox]), select, textarea');
						if (checkbox) {
							if (fieldsEnabled) {
								checkbox.removeAttribute('disabled');
							} else {
								checkbox.setAttribute('disabled', 'disabled');
								checkbox.checked = false;
							}
						}
						const includeChecked = checkbox && checkbox.checked;
						mappingControls.forEach((control) => {
							if (fieldsEnabled && includeChecked) {
								control.removeAttribute('disabled');
							} else {
								control.setAttribute('disabled', 'disabled');
							}
						});
					};
					const sync = () => {
						const mode = currentMode();
						const enabled = mode === 'custom_fields';
						Array.from(mappingTable.querySelectorAll('tbody tr')).forEach((row) => syncRow(row, enabled));
					};
					modeInputs.forEach((input) => input.addEventListener('change', sync));
					fields.forEach((field) => field.addEventListener('change', sync));
					sync();
					root.addEventListener('submit', (event) => {
						const selectedMode = root.querySelector('.burrow-ops-mode-radio:checked');
						const mode = selectedMode ? selectedMode.value : 'off';
						if (mode !== 'custom_fields') {
							return;
						}
						const includedFields = Array.from(root.querySelectorAll('.burrow-ops-field-checkbox:checked'));
						if (!includedFields.length) {
							event.preventDefault();
							window.alert('Please include at least one field when using Custom fields mode.');
							return;
						}
						for (const checkbox of includedFields) {
							const row = checkbox.closest('tr');
							const targetSelect = row ? row.querySelector('select[name*="[target]"]') : null;
							if (targetSelect && !targetSelect.value) {
								event.preventDefault();
								window.alert('Please choose a target for each included field.');
								targetSelect.focus();
								return;
							}
						}
					});
				})();
			</script>
		<?php endif; ?>
		<?php
	}

	private function operations_provider_supports_custom_fields( $provider_key ) {
		return in_array( sanitize_key( (string) $provider_key ), array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'wpforms', 'formidable-forms' ), true );
	}

	private function list_provider_fields_for_form( $provider_key, $form_id ) {
		return $this->operations_provider_fields( $provider_key, $form_id, array() );
	}

	private function operations_provider_fields( $provider_key, $form_id, array $contract ) {
		$provider_key = sanitize_key( (string) $provider_key );
		$wp_form_id   = \Burrow::raw_form_id( $provider_key, (string) $form_id );
		if ( 'gravity-forms' === $provider_key ) {
			return $this->list_gravity_form_fields( $wp_form_id );
		}
		if ( 'contact-form-7' === $provider_key ) {
			return $this->list_contact_form_7_fields( $wp_form_id );
		}
		if ( 'ninja-forms' === $provider_key ) {
			return $this->list_ninja_form_fields( $wp_form_id );
		}
		if ( 'fluent-forms' === $provider_key ) {
			return $this->list_fluent_form_fields( $wp_form_id );
		}
		if ( 'wpforms' === $provider_key ) {
			return $this->list_wpforms_fields( $wp_form_id );
		}
		if ( 'formidable-forms' === $provider_key ) {
			return $this->list_formidable_form_fields( $wp_form_id );
		}
		return isset( $contract['fieldMappings'] ) && is_array( $contract['fieldMappings'] ) ? $contract['fieldMappings'] : array();
	}

	private function list_gravity_form_fields( $form_id ) {
		$form_id = (string) $form_id;
		if ( '' === $form_id ) {
			return array();
		}
		$forms = $this->list_gravity_forms();
		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) || (string) ( $form['id'] ?? '' ) !== $form_id ) {
				continue;
			}
			$fields = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				if ( ! is_object( $field ) || empty( $field->id ) ) {
					continue;
				}
				$fields[] = array(
					'id'   => (string) $field->id,
					'name' => (string) ( $field->label ?? ( 'Field ' . $field->id ) ),
					'type' => (string) ( $field->type ?? 'text' ),
				);
			}
			return $fields;
		}
		return array();
	}

	private function operations_mapped_lookup( array $mappings ) {
		$lookup = array();
		foreach ( $mappings as $mapping ) {
			if ( ! is_array( $mapping ) || empty( $mapping['externalFieldId'] ) ) {
				continue;
			}
			$lookup[ (string) $mapping['externalFieldId'] ] = $mapping;
		}
		return $lookup;
	}

	private function apply_operations_contract_edit( array $settings, $contract_key, array $posted_contract, &$error_message ) {
		$contracts      = isset( $settings['forms_contracts'] ) && is_array( $settings['forms_contracts'] ) ? $settings['forms_contracts'] : array();
		$contract_key   = sanitize_text_field( (string) $contract_key );
		$allowed_modes  = array( 'off', 'count_only', 'custom_fields' );
		$error_message  = '';

		if ( '' === $contract_key || ! isset( $contracts[ $contract_key ] ) || ! is_array( $contracts[ $contract_key ] ) ) {
			$error_message = __( 'Selected contract could not be found.', 'burrow' );
			return $settings;
		}

		$mode = sanitize_key( (string) ( $posted_contract['mode'] ?? '' ) );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = isset( $contracts[ $contract_key ]['mode'] ) ? sanitize_key( (string) $contracts[ $contract_key ]['mode'] ) : 'count_only';
		}

		if ( 'off' === $mode ) {
			unset( $contracts[ $contract_key ] );
			$settings['forms_contracts'] = $contracts;
			return $settings;
		}

		$icon_raw = sanitize_text_field( (string) ( $posted_contract['icon'] ?? '' ) );
		$icon     = $this->sanitize_lucide_icon_key( $icon_raw );
		if ( '' !== $icon_raw && '' === $icon ) {
			$error_message = __( 'Invalid icon override. Use Lucide icon keys such as file-signature or shopping-cart.', 'burrow' );
			return $settings;
		}

		$provider = sanitize_key( (string) ( $contracts[ $contract_key ]['provider'] ?? '' ) );
		$mappings = array();
		if ( 'custom_fields' === $mode && $this->operations_provider_supports_custom_fields( $provider ) ) {
			$posted_fields = isset( $posted_contract['fields'] ) && is_array( $posted_contract['fields'] ) ? $posted_contract['fields'] : array();
			$included_count = 0;
			foreach ( $posted_fields as $posted_field ) {
				if ( is_array( $posted_field ) && ! empty( $posted_field['include'] ) ) {
					$included_count++;
				}
			}
			if ( 0 === $included_count ) {
				$contract_name = sanitize_text_field( (string) ( $contracts[ $contract_key ]['formName'] ?? $contract_key ) );
				$error_message = sprintf(
					/* translators: %s: form name */
					__( '"%s" is set to Custom fields. Include at least one field.', 'burrow' ),
					$contract_name
				);
				return $settings;
			}
			$invalid_target_field = '';
			$mappings             = $this->extract_operations_field_mappings( $posted_fields, $invalid_target_field );
			if ( '' !== $invalid_target_field ) {
				$contract_name = sanitize_text_field( (string) ( $contracts[ $contract_key ]['formName'] ?? $contract_key ) );
				$error_message = sprintf(
					/* translators: 1: field label, 2: form name */
					__( 'Select a target for "%1$s" in "%2$s".', 'burrow' ),
					$invalid_target_field,
					$contract_name
				);
				return $settings;
			}
		}

		$contracts[ $contract_key ]['enabled']       = true;
		$contracts[ $contract_key ]['countOnly']     = 'custom_fields' !== $mode;
		$contracts[ $contract_key ]['mode']          = $mode;
		$contracts[ $contract_key ]['fieldMappings'] = $mappings;
		$contracts[ $contract_key ]['icon']          = '' !== $icon ? $icon : null;

		$settings['forms_contracts'] = $contracts;
		return $settings;
	}

	private function extract_operations_field_mappings( array $posted_fields, &$invalid_target_field = '' ) {
		$mappings = array();
		$invalid_target_field = '';
		foreach ( $posted_fields as $field_id => $posted ) {
			if ( ! is_array( $posted ) || empty( $posted['include'] ) ) {
				continue;
			}
			$external_field_id = sanitize_text_field( (string) ( $posted['externalFieldId'] ?? $field_id ) );
			$canonical_key     = sanitize_text_field( (string) ( $posted['canonicalKey'] ?? '' ) );
			if ( '' === $external_field_id || '' === $canonical_key ) {
				continue;
			}
			$target = $this->sanitize_mapping_target( $posted['target'] ?? '' );
			if ( '' === $target ) {
				$invalid_target_field = sanitize_text_field( (string) ( $posted['sourceLabel'] ?? $external_field_id ) );
				return $mappings;
			}
			$mappings[] = array(
				'externalFieldId' => $external_field_id,
				'sourceLabel'     => sanitize_text_field( (string) ( $posted['sourceLabel'] ?? $external_field_id ) ),
				'canonicalKey'    => $canonical_key,
				'target'          => $target,
				'dataType'        => sanitize_text_field( (string) ( $posted['dataType'] ?? 'string' ) ),
			);
		}
		return $mappings;
	}

	private function sanitize_mapping_target( $target ) {
		$target = sanitize_key( (string) $target );
		if ( ! in_array( $target, array( 'properties', 'tags' ), true ) ) {
			return '';
		}
		return $target;
	}

	private function sanitize_lucide_icon_key( $value ) {
		$icon = strtolower( trim( (string) $value ) );
		if ( '' === $icon ) {
			return '';
		}
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $icon ) ? $icon : '';
	}

	/**
	 * Build the current list of sources included in backfill.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string[]
	 */
	private function build_backfill_active_keys( array $settings, array $selected_sources = array() ) {
		$selected_sources = $this->sanitize_selected_backfill_sources( $settings, $selected_sources );
		$keys             = array();
		if ( in_array( 'forms', $selected_sources, true ) ) {
			$keys = array_merge( $keys, $this->get_enabled_contract_keys( (array) ( $settings['forms_contracts'] ?? array() ) ) );
		}
		if ( in_array( 'ecommerce', $selected_sources, true ) ) {
			$keys[] = 'woocommerce:orders';
		}
		return array_values( array_unique( $keys ) );
	}

	private function backfill_source_labels( array $settings ) {
		$labels = array();
		$form_keys = $this->get_enabled_contract_keys( (array) ( $settings['forms_contracts'] ?? array() ) );
		if ( ! empty( $form_keys ) ) {
			$labels['forms'] = __( 'Forms', 'burrow' );
		}
		$selected_integrations = isset( $settings['onboarding']['selected_integrations'] ) && is_array( $settings['onboarding']['selected_integrations'] )
			? $settings['onboarding']['selected_integrations']
			: array();
		$woo_mode = isset( $settings['onboarding']['woocommerce_mode'] ) ? (string) $settings['onboarding']['woocommerce_mode'] : 'track';
		$woo_enabled = 'track' === $woo_mode || in_array( 'woocommerce', $selected_integrations, true );
		if ( $woo_enabled ) {
			$labels['ecommerce'] = __( 'WooCommerce', 'burrow' );
		}
		return $labels;
	}

	private function sanitize_selected_backfill_sources( array $settings, $selected_sources ) {
		$available = array_keys( $this->backfill_source_labels( $settings ) );
		$selected  = is_array( $selected_sources ) ? $selected_sources : array();
		$selected  = array_map(
			static function ( $source ) {
				return sanitize_key( (string) $source );
			},
			$selected
		);
		$selected  = array_values( array_intersect( $selected, $available ) );
		if ( empty( $selected ) ) {
			return $available;
		}
		return $selected;
	}

	private function reset_onboarding_state( $state ) {
		$state = is_array( $state ) ? $state : array();
		$state['selected_integrations'] = array();
		$state['gravity_configured']    = false;
		$state['woocommerce_confirmed'] = false;
		$state['woocommerce_mode']      = 'track';
		$state['provider_configured']   = array();
		return $state;
	}

	private function render_step_heading( $step, $title ) {
		$icon = $this->get_step_icon_markup( $step );
		echo '<h2 class="burrow-step-title">';
		echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span>' . esc_html( (string) $title ) . '</span>';
		echo '</h2>';
	}

	private function get_step_icon_markup( $step ) {
		$step = sanitize_key( (string) $step );
		$provider_steps = array( 'gravity-forms', 'contact-form-7', 'ninja-forms', 'fluent-forms', 'woocommerce' );
		if ( in_array( $step, $provider_steps, true ) ) {
			return $this->get_integration_icon_markup( $step );
		}
		$dashicons = array(
			'connection'   => 'dashicons-admin-links',
			'project'      => 'dashicons-portfolio',
			'integrations' => 'dashicons-admin-plugins',
			'review'       => 'dashicons-yes-alt',
			'backfill'     => 'dashicons-flag',
		);
		$klass = isset( $dashicons[ $step ] ) ? $dashicons[ $step ] : 'dashicons-admin-generic';
		return '<span class="dashicons burrow-integration-icon ' . esc_attr( $klass ) . '" aria-hidden="true"></span>';
	}

	private function list_gravity_forms() {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return array();
		}
		$forms = \GFAPI::get_forms();
		$out   = array();
		foreach ( (array) $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			if ( isset( $form['is_active'] ) && ! $form['is_active'] ) {
				continue;
			}
			$out[] = $form;
		}
		return $out;
	}

	private function list_contact_form_7_forms() {
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			return array();
		}
		$forms = array();
		if ( method_exists( '\WPCF7_ContactForm', 'find' ) ) {
			$forms = \WPCF7_ContactForm::find();
		} elseif ( function_exists( 'wpcf7_contact_forms' ) ) {
			$forms = wpcf7_contact_forms();
		}
		$out = array();
		foreach ( (array) $forms as $form ) {
			$id = '';
			$title = '';
			if ( is_object( $form ) ) {
				$id = method_exists( $form, 'id' ) ? (string) $form->id() : (string) ( $form->ID ?? '' );
				$title = method_exists( $form, 'title' ) ? (string) $form->title() : (string) ( $form->post_title ?? '' );
			} elseif ( is_array( $form ) ) {
				$id = (string) ( $form['id'] ?? $form['ID'] ?? '' );
				$title = (string) ( $form['title'] ?? $form['post_title'] ?? '' );
			}
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id' => $id,
				'title' => '' !== $title ? $title : sprintf( 'Form %s', $id ),
			);
		}
		return $out;
	}

	private function list_contact_form_7_fields( $form_id ) {
		$form_id = (int) $form_id;
		if ( $form_id <= 0 || ! class_exists( '\WPCF7_ContactForm' ) ) {
			return array();
		}

		$form = null;
		if ( method_exists( '\WPCF7_ContactForm', 'get_instance' ) ) {
			$form = \WPCF7_ContactForm::get_instance( $form_id );
		}
		if ( ! $form ) {
			return array();
		}

		$fields = array();
		if ( is_object( $form ) && method_exists( $form, 'scan_form_tags' ) ) {
			$tags = $form->scan_form_tags();
			foreach ( (array) $tags as $tag ) {
				if ( ! is_object( $tag ) ) {
					continue;
				}
				$name = isset( $tag->name ) ? (string) $tag->name : '';
				if ( '' === $name ) {
					continue;
				}
				$type = isset( $tag->basetype ) ? (string) $tag->basetype : ( isset( $tag->type ) ? (string) $tag->type : 'text' );
				$fields[ $name ] = array(
					'name' => $name,
					'type' => $type,
				);
			}
		}

		if ( empty( $fields ) ) {
			$template = '';
			if ( is_object( $form ) && method_exists( $form, 'prop' ) ) {
				$template = (string) $form->prop( 'form' );
			} elseif ( is_object( $form ) && isset( $form->properties['form'] ) ) {
				$template = (string) $form->properties['form'];
			}
			if ( '' !== $template ) {
				preg_match_all( '/\[([a-zA-Z0-9_-]+\*?)\s+([a-zA-Z0-9_-]+)/', $template, $matches, PREG_SET_ORDER );
				foreach ( (array) $matches as $match ) {
					$type = isset( $match[1] ) ? rtrim( (string) $match[1], '*' ) : 'text';
					$name = isset( $match[2] ) ? (string) $match[2] : '';
					if ( '' === $name ) {
						continue;
					}
					$fields[ $name ] = array(
						'name' => $name,
						'type' => $type,
					);
				}
			}
		}

		return array_values( $fields );
	}

	private function list_ninja_forms() {
		if ( ! function_exists( 'Ninja_Forms' ) ) {
			return array();
		}
		$forms = array();
		$nf = Ninja_Forms();
		if ( is_object( $nf ) && method_exists( $nf, 'form' ) ) {
			$form_repo = $nf->form();
			if ( is_object( $form_repo ) && method_exists( $form_repo, 'get_forms' ) ) {
				$forms = $form_repo->get_forms();
			}
		}
		$out = array();
		foreach ( (array) $forms as $form ) {
			$id = '';
			$title = '';
			if ( is_object( $form ) ) {
				$id = method_exists( $form, 'get_id' ) ? (string) $form->get_id() : (string) ( $form->id ?? '' );
				$title = method_exists( $form, 'get_setting' ) ? (string) $form->get_setting( 'title' ) : (string) ( $form->title ?? '' );
			} elseif ( is_array( $form ) ) {
				$id = (string) ( $form['id'] ?? '' );
				$title = (string) ( $form['title'] ?? '' );
			}
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id' => $id,
				'title' => '' !== $title ? $title : sprintf( 'Form %s', $id ),
			);
		}
		return $out;
	}

	private function list_ninja_form_fields( $form_id ) {
		$form_id = (int) $form_id;
		if ( $form_id <= 0 ) {
			return array();
		}

		$out = array();
		if ( function_exists( 'Ninja_Forms' ) ) {
			$nf = Ninja_Forms();
			if ( is_object( $nf ) && method_exists( $nf, 'form' ) ) {
				$form = $nf->form( $form_id );
				if ( is_object( $form ) && method_exists( $form, 'get_fields' ) ) {
					$fields = $form->get_fields();
					foreach ( (array) $fields as $field ) {
						if ( ! is_object( $field ) ) {
							continue;
						}
						$id = method_exists( $field, 'get_id' ) ? (string) $field->get_id() : (string) ( $field->id ?? '' );
						if ( '' === $id ) {
							continue;
						}
						$label = method_exists( $field, 'get_setting' ) ? (string) $field->get_setting( 'label' ) : '';
						$key   = method_exists( $field, 'get_setting' ) ? (string) $field->get_setting( 'key' ) : '';
						$type  = method_exists( $field, 'get_setting' ) ? (string) $field->get_setting( 'type' ) : 'text';
						$out[] = array(
							'id'   => $id,
							'name' => '' !== $label ? $label : ( '' !== $key ? $key : 'Field ' . $id ),
							'type' => '' !== $type ? $type : 'text',
						);
					}
				}
			}
		}

		if ( ! empty( $out ) ) {
			return $out;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'nf3_fields';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $table !== $exists ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, label, type, `key` FROM {$table} WHERE parent_id = %d ORDER BY id ASC", $form_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			$key   = isset( $row['key'] ) ? (string) $row['key'] : '';
			$type  = isset( $row['type'] ) ? (string) $row['type'] : 'text';
			$out[] = array(
				'id'   => $id,
				'name' => '' !== $label ? $label : ( '' !== $key ? $key : 'Field ' . $id ),
				'type' => '' !== $type ? $type : 'text',
			);
		}
		return $out;
	}

	private function list_fluent_forms() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'fluentform_forms';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $table !== $exists ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT id, title FROM {$table} WHERE status = 'published' ORDER BY id DESC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id' => $id,
				'title' => (string) ( $row['title'] ?? sprintf( 'Form %s', $id ) ),
			);
		}
		return $out;
	}

	private function list_fluent_form_fields( $form_id ) {
		$form_id = (int) $form_id;
		if ( $form_id <= 0 ) {
			return array();
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'fluentform_forms';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $table !== $exists ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT form_fields FROM {$table} WHERE id = %d LIMIT 1", $form_id ), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['form_fields'] ) ) {
			return array();
		}
		$schema = json_decode( (string) $row['form_fields'], true );
		if ( ! is_array( $schema ) ) {
			return array();
		}
		$fields = array();
		$this->collect_fluent_fields_from_schema( $schema, $fields );
		return array_values( $fields );
	}

	private function collect_fluent_fields_from_schema( $node, array &$fields ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		$name = '';
		$type = '';
		if ( isset( $node['attributes'] ) && is_array( $node['attributes'] ) ) {
			$name = isset( $node['attributes']['name'] ) ? (string) $node['attributes']['name'] : '';
			$type = isset( $node['attributes']['type'] ) ? (string) $node['attributes']['type'] : '';
		}
		if ( '' === $name && isset( $node['name'] ) && is_string( $node['name'] ) ) {
			$name = (string) $node['name'];
		}
		if ( '' === $type && isset( $node['element'] ) ) {
			$type = (string) $node['element'];
		}
		$label = isset( $node['settings']['label'] ) ? (string) $node['settings']['label'] : $name;

		if ( '' !== $name ) {
			$fields[ $name ] = array(
				'id'   => $name,
				'name' => '' !== $label ? $label : $name,
				'type' => '' !== $type ? $type : 'text',
			);
		}

		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_fluent_fields_from_schema( $child, $fields );
			}
		}
	}

	private function list_wpforms() {
		if ( ! function_exists( 'wpforms' ) ) {
			return array();
		}
		$forms = wpforms()->get( 'form' );
		if ( ! is_object( $forms ) || ! method_exists( $forms, 'get' ) ) {
			return array();
		}
		$posts = $forms->get( '', array( 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( ! is_array( $posts ) ) {
			return array();
		}
		$out = array();
		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) && ! is_a( $post, 'WP_Post' ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $post->ID,
				'title' => (string) ( $post->post_title ?? sprintf( 'Form %s', $post->ID ) ),
			);
		}
		return $out;
	}

	private function list_wpforms_fields( $form_id ) {
		$form_id = (int) $form_id;
		if ( $form_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return array();
		}
		$form_handler = wpforms()->get( 'form' );
		if ( ! is_object( $form_handler ) || ! method_exists( $form_handler, 'get' ) ) {
			return array();
		}
		$form = $form_handler->get( $form_id );
		if ( ! is_object( $form ) || empty( $form->post_content ) ) {
			return array();
		}
		$data = json_decode( $form->post_content, true );
		if ( ! is_array( $data ) || empty( $data['fields'] ) ) {
			return array();
		}
		$fields = array();
		foreach ( (array) $data['fields'] as $fid => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = isset( $field['label'] ) ? (string) $field['label'] : '';
			$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			if ( '' === $label ) {
				$label = sprintf( 'Field %s', (string) $fid );
			}
			$fields[] = array(
				'id'   => (string) $fid,
				'name' => $label,
				'type' => $type,
			);
		}
		return $fields;
	}

	private function list_formidable_forms() {
		if ( ! class_exists( '\FrmForm' ) || ! method_exists( '\FrmForm', 'getAll' ) ) {
			return array();
		}
		$forms = \FrmForm::getAll( array( 'is_template' => 0, 'status' => 'published' ), ' ORDER BY name ASC' );
		if ( ! is_array( $forms ) ) {
			return array();
		}
		$out = array();
		foreach ( $forms as $form ) {
			if ( ! is_object( $form ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $form->id,
				'title' => (string) ( $form->name ?? sprintf( 'Form %s', $form->id ) ),
			);
		}
		return $out;
	}

	private function list_formidable_form_fields( $form_id ) {
		$form_id = (int) $form_id;
		if ( $form_id <= 0 || ! class_exists( '\FrmField' ) || ! method_exists( '\FrmField', 'getAll' ) ) {
			return array();
		}
		$raw_fields = \FrmField::getAll( array( 'fi.form_id' => $form_id ), ' ORDER BY fi.field_order ASC' );
		if ( ! is_array( $raw_fields ) ) {
			return array();
		}
		$skip_types = array( 'divider', 'end_divider', 'break', 'html', 'captcha' );
		$fields = array();
		foreach ( $raw_fields as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$type = isset( $field->type ) ? (string) $field->type : 'text';
			if ( in_array( $type, $skip_types, true ) ) {
				continue;
			}
			$label = isset( $field->name ) ? (string) $field->name : '';
			if ( '' === $label ) {
				$label = sprintf( 'Field %s', (string) $field->id );
			}
			$fields[] = array(
				'id'   => (string) $field->id,
				'name' => $label,
				'type' => $type,
			);
		}
		return $fields;
	}

	private function is_suggested_field_type( $type ) {
		return in_array( $type, array( 'hidden', 'select', 'checkbox', 'radio' ), true );
	}

	private function label_to_canonical_key( $label ) {
		$label = preg_replace( '/[^a-zA-Z0-9 ]/', ' ', (string) $label );
		$parts = preg_split( '/\s+/', trim( (string) $label ) );
		if ( empty( $parts ) ) {
			$key = 'fieldValue';
		} else {
			$first = strtolower( (string) array_shift( $parts ) );
			$rest  = '';
			foreach ( $parts as $p ) {
				$rest .= ucfirst( strtolower( (string) $p ) );
			}
			$key = $first . $rest;
		}
		if ( class_exists( '\Burrow\Sdk\Contracts\FormsContractWizardHelpers' ) ) {
			$sanitized = \Burrow\Sdk\Contracts\FormsContractWizardHelpers::sanitizeCanonicalKey( $key );
			if ( is_array( $sanitized ) && isset( $sanitized['key'] ) && '' !== (string) $sanitized['key'] ) {
				return (string) $sanitized['key'];
			}
		}
		return $key;
	}

	private function get_menu_icon_data_uri() {
		$path = BURROW_PLUGIN_DIR . 'admin/images/burrow-icon.svg';
		if ( ! file_exists( $path ) ) {
			return $this->embedded_burrow_logo_data_uri();
		}
		$contents = file_get_contents( $path );
		if ( false === $contents || '' === trim( (string) $contents ) ) {
			return $this->embedded_burrow_logo_data_uri();
		}
		return 'data:image/svg+xml;base64,' . base64_encode( (string) $contents );
	}
}
