<?php
/**
 * Main plugin orchestrator.
 *
 * @package Burrow
 */

class Burrow {
	/**
	 * @var Burrow_Loader
	 */
	protected $loader;

	/**
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * @var string
	 */
	protected $version;

	/**
	 * @var BurrowWP\Infrastructure\Persistence\WpOptionsRepository
	 */
	private $options_repo;

	/**
	 * @var BurrowWP\Infrastructure\Persistence\WpOutboxRepository
	 */
	private $outbox_repo;

	/**
	 * @var BurrowWP\Core\Events\EventKeyFactory
	 */
	private $event_keys;

	/**
	 * @var BurrowWP\Core\Events\EnvelopeFactory
	 */
	private $envelopes;

	/**
	 * @var BurrowWP\Core\Events\ContractFieldMapper
	 */
	private $contract_mapper;

	public function __construct() {
		$this->version         = defined( 'BURROW_VERSION' ) ? BURROW_VERSION : '1.0.0';
		$this->plugin_name     = 'burrow';
		$this->options_repo    = new BurrowWP\Infrastructure\Persistence\WpOptionsRepository();
		$this->outbox_repo     = new BurrowWP\Infrastructure\Persistence\WpOutboxRepository();
		$this->event_keys      = new BurrowWP\Core\Events\EventKeyFactory();
		$this->envelopes       = new BurrowWP\Core\Events\EnvelopeFactory();
		$this->contract_mapper = new BurrowWP\Core\Events\ContractFieldMapper();

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_worker_hooks();
	}

	private function load_dependencies() {
		require_once BURROW_PLUGIN_DIR . 'includes/class-burrow-loader.php';
		require_once BURROW_PLUGIN_DIR . 'admin/class-burrow-admin.php';
		require_once BURROW_PLUGIN_DIR . 'public/class-burrow-public.php';
		$this->loader = new Burrow_Loader();
	}

	private function set_locale() {
		$this->loader->add_action( 'plugins_loaded', $this, 'load_plugin_textdomain' );
	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'burrow', false, dirname( plugin_basename( BURROW_PLUGIN_DIR . 'burrow.php' ) ) . '/languages/' );
	}

	private function define_admin_hooks() {
		$admin = new Burrow_Admin( $this->get_plugin_name(), $this->get_version() );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $admin, 'add_settings_page' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'admin_init', $admin, 'maybe_redirect_after_activation' );
		$this->loader->add_action( 'admin_init', $admin, 'maybe_handle_admin_actions' );
	}

	private function define_public_hooks() {
		$public = new Burrow_Public( $this->get_plugin_name(), $this->get_version() );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_scripts' );

		$this->loader->add_action( 'cron_schedules', $this, 'register_cron_schedules' );

		// Forms hooks.
		$this->loader->add_action( 'gform_after_submission', $this, 'handle_gravity_submission', 10, 2 );
		$this->loader->add_action( 'ninja_forms_after_submission', $this, 'handle_ninja_submission', 10, 1 );
		$this->loader->add_action( 'wpcf7_mail_sent', $this, 'handle_cf7_submission', 10, 1 );
		$this->loader->add_action( 'fluentform_submission_inserted', $this, 'handle_fluent_submission', 10, 3 );

		// WooCommerce hooks.
		$this->loader->add_action( 'woocommerce_checkout_order_processed', $this, 'handle_woocommerce_order', 10, 1 );
		$this->loader->add_action( 'woocommerce_payment_complete', $this, 'handle_woocommerce_order', 10, 1 );
	}

	private function define_worker_hooks() {
		$this->loader->add_action( 'init', $this, 'ensure_cron_jobs' );
		$this->loader->add_action( 'burrow_outbox_worker', $this, 'run_outbox_worker' );
		$this->loader->add_action( 'burrow_system_heartbeat', $this, 'emit_system_heartbeat' );
		$this->loader->add_action( 'burrow_system_stack_snapshot', $this, 'emit_system_stack_snapshot' );
		$this->loader->add_action( 'burrow_outbox_cleanup', $this, 'cleanup_outbox' );
		$this->loader->add_action( 'burrow_backfill_worker', $this, 'run_backfill_worker' );
	}

	/**
	 * Register one-minute cron interval.
	 *
	 * @param array<string,mixed> $schedules Schedules.
	 * @return array<string,mixed>
	 */
	public function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['minute'] ) ) {
			$schedules['minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every Minute', 'burrow' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure cron jobs exist after schedule registration.
	 *
	 * @return void
	 */
	public function ensure_cron_jobs() {
		$outbox_event = wp_get_scheduled_event( 'burrow_outbox_worker' );
		if ( $outbox_event && 'minute' !== $outbox_event->schedule ) {
			wp_unschedule_event( $outbox_event->timestamp, 'burrow_outbox_worker' );
			$outbox_event = null;
		}
		if ( ! $outbox_event ) {
			wp_schedule_event( time() + 60, 'minute', 'burrow_outbox_worker' );
		}
		$backfill_event = wp_get_scheduled_event( 'burrow_backfill_worker' );
		if ( $backfill_event && 'minute' !== $backfill_event->schedule ) {
			wp_unschedule_event( $backfill_event->timestamp, 'burrow_backfill_worker' );
			$backfill_event = null;
		}
		if ( ! $backfill_event ) {
			wp_schedule_event( time() + 120, 'minute', 'burrow_backfill_worker' );
		}
		if ( ! wp_next_scheduled( 'burrow_system_heartbeat' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'burrow_system_heartbeat' );
		}
		if ( ! wp_next_scheduled( 'burrow_system_stack_snapshot' ) ) {
			wp_schedule_event( time() + 900, 'daily', 'burrow_system_stack_snapshot' );
		}
		if ( ! wp_next_scheduled( 'burrow_outbox_cleanup' ) ) {
			wp_schedule_event( time() + 1800, 'daily', 'burrow_outbox_cleanup' );
		}
	}

	/**
	 * Handle Gravity Forms submission.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @param array<string,mixed> $form Form.
	 * @return void
	 */
	public function handle_gravity_submission( $entry, $form ) {
		$provider = new BurrowWP\Providers\Forms\GravityFormsProvider();
		$this->enqueue_forms_event( $provider->normalize_submission( array( 'entry' => $entry, 'form' => $form ) ) );
	}

	/**
	 * Handle Ninja Forms submission.
	 *
	 * @param mixed $form_data Form data.
	 * @return void
	 */
	public function handle_ninja_submission( $form_data ) {
		$provider = new BurrowWP\Providers\Forms\NinjaFormsProvider();
		$payload  = is_array( $form_data ) ? $form_data : array();
		$this->enqueue_forms_event( $provider->normalize_submission( $payload ) );
	}

	/**
	 * Handle Contact Form 7 submission.
	 *
	 * @param mixed $contact_form CF7 form object.
	 * @return void
	 */
	public function handle_cf7_submission( $contact_form ) {
		$provider = new BurrowWP\Providers\Forms\ContactForm7Provider();
		$this->enqueue_forms_event( $provider->normalize_submission( $contact_form ) );
	}

	/**
	 * Handle Fluent Forms submission.
	 *
	 * @param int                 $entry_id Entry id.
	 * @param array<string,mixed> $form_data Form data.
	 * @param array<string,mixed> $entry_data Entry data.
	 * @return void
	 */
	public function handle_fluent_submission( $entry_id, $form_data, $entry_data ) {
		$provider = new BurrowWP\Providers\Forms\FluentFormsProvider();
		$payload  = array(
			'entry' => array( 'id' => $entry_id ),
			'form'  => is_array( $form_data ) ? $form_data : array(),
			'data'  => is_array( $entry_data ) ? $entry_data : array(),
		);
		$this->enqueue_forms_event( $provider->normalize_submission( $payload ) );
	}

	/**
	 * Handle WooCommerce order events.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_woocommerce_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$provider = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$data     = $provider->normalize_order( $order );
		if ( empty( $data ) ) {
			return;
		}
		$submitted_at = $this->resolve_order_timestamp( $order );

		$settings = $this->options_repo->get_settings();
		$selected_integrations = isset( $settings['onboarding']['selected_integrations'] ) && is_array( $settings['onboarding']['selected_integrations'] )
			? $settings['onboarding']['selected_integrations']
			: array();
		$woocommerce_mode = isset( $settings['onboarding']['woocommerce_mode'] ) ? (string) $settings['onboarding']['woocommerce_mode'] : 'track';
		if ( ! in_array( 'woocommerce', $selected_integrations, true ) || 'track' !== $woocommerce_mode ) {
			return;
		}
		$routing  = (array) $settings['routing'];
		$source   = $routing['sourceIds']['ecommerce'] ?? ( $routing['projectSourceId'] ?? '' );

		$order_envelope = $this->envelopes->build(
			$routing,
			array(
				'projectSourceId' => $source,
				'integrationId'   => $routing['integrationId'] ?? null,
				'icon'            => $this->resolve_event_icon_override( $settings, 'ecommerce.order.placed' ),
				'entityType'      => 'order',
				'externalEntityId'=> (string) $data['orderId'],
				'externalEventId' => $this->event_keys->ecommerce_order_key( (string) $data['orderId'] ),
				'channel'         => 'ecommerce',
				'event'           => 'ecommerce.order.placed',
				'description'     => 'Order placed',
				'timestamp'       => $submitted_at,
				'properties'      => array(
					'orderId'   => $data['orderId'],
					'total'     => $data['total'],
					'currency'  => $data['currency'],
					'itemCount' => $data['itemCount'],
					'submittedAt' => $submitted_at,
				),
				'tags'            => array(
					'orderId'       => (string) $data['orderId'],
					'status'        => (string) $data['status'],
					'paymentMethod' => (string) $data['paymentMethod'],
				),
			)
		);

		$this->outbox_repo->enqueue(
			$this->event_keys->ecommerce_order_key( (string) $data['orderId'] ),
			'ecommerce',
			'ecommerce.order.placed',
			$order_envelope,
			(int) $settings['max_attempts']
		);

		foreach ( (array) $data['items'] as $item ) {
			$item_event_key = $this->event_keys->ecommerce_item_key( (string) $data['orderId'], (string) $item['lineItemId'] );
			$item_envelope = $this->envelopes->build(
				$routing,
				array(
					'projectSourceId' => $source,
					'integrationId'   => $routing['integrationId'] ?? null,
					'icon'            => $this->resolve_event_icon_override( $settings, 'ecommerce.item.purchased' ),
					'entityType'      => 'order_item',
					'externalEntityId'=> (string) ( $item['lineItemId'] ?? '' ),
					'externalEventId' => $item_event_key,
					'channel'         => 'ecommerce',
					'event'           => 'ecommerce.item.purchased',
					'description'     => 'Order item purchased',
					'timestamp'       => $submitted_at,
					'properties'      => array(
						'orderId'     => $data['orderId'],
						'productName' => $item['productName'],
						'variantName' => $item['variantName'],
						'quantity'    => $item['quantity'],
						'unitPrice'   => $item['unitPrice'],
						'lineTotal'   => $item['lineTotal'],
						'productUrl'  => $item['productUrl'],
						'submittedAt' => $submitted_at,
					),
					'tags'            => array(
						'orderId'     => (string) $data['orderId'],
						'productId'   => (string) $item['productId'],
						'productName' => (string) $item['productName'],
						'category'    => (string) $item['category'],
						'vendor'      => (string) $item['vendor'],
					),
				)
			);

			$this->outbox_repo->enqueue(
				$item_event_key,
				'ecommerce',
				'ecommerce.item.purchased',
				$item_envelope,
				(int) $settings['max_attempts']
			);
		}
	}

	/**
	 * Enqueue forms event from normalized provider payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return void
	 */
	private function enqueue_forms_event( array $payload ) {
		if ( empty( $payload['provider'] ) || empty( $payload['formId'] ) || empty( $payload['submissionId'] ) ) {
			return;
		}
		$settings  = $this->options_repo->get_settings();
		$contracts = (array) $settings['forms_contracts'];
		$key       = $payload['provider'] . ':' . $payload['formId'];
		$contract  = isset( $contracts[ $key ] ) ? (array) $contracts[ $key ] : array();

		if ( empty( $contract ) || empty( $contract['enabled'] ) ) {
			return;
		}

		$mapped = $this->contract_mapper->map(
			(array) $payload['rawValues'],
			isset( $contract['fieldMappings'] ) && is_array( $contract['fieldMappings'] ) ? $contract['fieldMappings'] : array()
		);
		$count_only = ! empty( $contract['countOnly'] );
		$has_mapped_fields = ! empty( $mapped['tags'] ) || ! empty( $mapped['properties'] );
		$has_explicit_count_only_setting = array_key_exists( 'countOnly', $contract );

		// Provider-agnostic contract rule:
		// - if a contract explicitly opts out of count-only and has no mapped fields, skip emission.
		// - legacy contracts without countOnly keep previous behavior for backward compatibility.
		if ( ! $count_only && ! $has_mapped_fields && $has_explicit_count_only_setting ) {
			return;
		}

		$routing = (array) $settings['routing'];
		$source  = $routing['sourceIds']['forms'] ?? ( $routing['projectSourceId'] ?? '' );
		$tags    = array_merge(
			array( 'formId' => (string) ( $contract['formHandle'] ?? $payload['formId'] ) ),
			(array) $mapped['tags']
		);
		$props   = array_merge(
			array(
				'formName'     => (string) $payload['formName'],
				'submissionId' => (string) $payload['submissionId'],
				'submittedAt'  => $this->resolve_iso8601( $payload['submittedAt'] ?? null ),
			),
			(array) $mapped['properties']
		);

		$envelope = $this->envelopes->build(
			$routing,
			array(
				'projectSourceId' => $source,
				'integrationId'   => $routing['integrationId'] ?? null,
				'icon'            => $this->resolve_event_icon_override( $settings, 'forms.submission.received', $contract ),
				'entityType'      => 'form_submission',
				'externalEntityId'=> (string) $payload['submissionId'],
				'externalEventId' => $this->event_keys->forms_submission_key( (string) $payload['formId'], (string) $payload['submissionId'] ),
				'channel'         => 'forms',
				'event'           => 'forms.submission.received',
				'description'     => 'Form submission received',
				'timestamp'       => $this->resolve_iso8601( $payload['submittedAt'] ?? null ),
				'properties'      => $props,
				'tags'            => $tags,
			)
		);

		$this->outbox_repo->enqueue(
			$this->event_keys->forms_submission_key( (string) $payload['formId'], (string) $payload['submissionId'] ),
			'forms',
			'forms.submission.received',
			$envelope,
			(int) $settings['max_attempts']
		);
	}

	/**
	 * Outbox worker hook.
	 *
	 * @return void
	 */
	public function run_outbox_worker() {
		$settings = $this->options_repo->get_settings();
		$client   = new BurrowWP\Infrastructure\Http\BurrowApiClient( $settings['base_url'], $settings['api_key'] );
		$worker   = new BurrowWP\Core\Outbox\OutboxWorker(
			$this->outbox_repo,
			$client,
			new BurrowWP\Core\Outbox\RetryPolicy()
		);
		$worker->run_once( 100 );
	}

	/**
	 * Emit heartbeat event.
	 *
	 * @return void
	 */
	public function emit_system_heartbeat() {
		$settings = $this->options_repo->get_settings();
		$routing  = (array) $settings['routing'];
		$source   = $routing['sourceIds']['system'] ?? ( $routing['projectSourceId'] ?? '' );
		$envelope = $this->envelopes->build(
			$routing,
			array(
				'projectSourceId' => $source,
				'icon'            => $this->resolve_event_icon_override( $settings, 'system.heartbeat.ping' ),
				'channel'         => 'system',
				'event'           => 'system.heartbeat.ping',
				'description'     => 'Plugin heartbeat',
				'properties'      => array(
					'status'    => 'ok',
					'latencyMs' => 0,
				),
				'tags'            => array( 'status' => 'ok' ),
			)
		);
		$this->outbox_repo->enqueue(
			'system:heartbeat:' . gmdate( 'YmdH' ),
			'system',
			'system.heartbeat.ping',
			$envelope,
			(int) $settings['max_attempts']
		);
	}

	/**
	 * Emit stack snapshot event.
	 *
	 * @return void
	 */
	public function emit_system_stack_snapshot() {
		$settings  = $this->options_repo->get_settings();
		$routing   = (array) $settings['routing'];
		$source    = $routing['sourceIds']['system'] ?? ( $routing['projectSourceId'] ?? '' );
		$collector = new BurrowWP\Core\System\SystemMetricsCollector();
		$snapshot  = $collector->collect_stack_snapshot();
		$envelope  = $this->envelopes->build(
			$routing,
			array(
				'projectSourceId' => $source,
				'icon'            => $this->resolve_event_icon_override( $settings, 'system.stack.snapshot' ),
				'channel'         => 'system',
				'event'           => 'system.stack.snapshot',
				'description'     => 'Daily stack snapshot',
				'properties'      => $snapshot,
				'tags'            => array(
					'cmsVersion' => (string) get_bloginfo( 'version' ),
					'phpVersion' => (string) phpversion(),
					'hasUpdates' => ! empty( $snapshot['updatesAvailable'] ) ? 'true' : 'false',
				),
			)
		);
		$this->outbox_repo->enqueue(
			'system:stack:' . gmdate( 'Ymd' ),
			'system',
			'system.stack.snapshot',
			$envelope,
			(int) $settings['max_attempts']
		);
	}

	/**
	 * Cleanup outbox.
	 *
	 * @return void
	 */
	public function cleanup_outbox() {
		$settings       = $this->options_repo->get_settings();
		$retention_days = isset( $settings['outbox_retention_days'] ) ? (int) $settings['outbox_retention_days'] : 30;
		$this->outbox_repo->cleanup( max( 1, $retention_days ) );
	}

	/**
	 * Backfill worker hook.
	 *
	 * @return void
	 */
	public function run_backfill_worker() {
		$settings = $this->options_repo->get_settings();
		$job      = isset( $settings['backfill'] ) && is_array( $settings['backfill'] ) ? $settings['backfill'] : array();
		$status   = isset( $job['status'] ) ? (string) $job['status'] : 'idle';

		if ( ! in_array( $status, array( 'queued', 'running' ), true ) ) {
			return;
		}
		if ( empty( $settings['api_key'] ) ) {
			$job['status']    = 'failed';
			$job['lastError'] = 'Missing API key for backfill.';
			$job['updatedAt'] = gmdate( 'c' );
			$settings['backfill'] = $job;
			$this->options_repo->save_settings( $settings );
			return;
		}

		$job['status']    = 'running';
		$job['startedAt'] = empty( $job['startedAt'] ) ? gmdate( 'c' ) : $job['startedAt'];
		$job['updatedAt'] = gmdate( 'c' );

		$contracts = isset( $settings['forms_contracts'] ) && is_array( $settings['forms_contracts'] ) ? $settings['forms_contracts'] : array();
		$keys      = isset( $job['activeContractKeys'] ) && is_array( $job['activeContractKeys'] ) ? $job['activeContractKeys'] : array();
		$current_keys = $this->get_backfillable_contract_keys( $contracts );
		$keys         = array_values( array_unique( array_merge( $keys, $current_keys ) ) );
		$job['activeContractKeys'] = $keys;
		$job['totalForms']         = count( $keys );

		$cursor = isset( $job['cursor'] ) && is_array( $job['cursor'] ) ? $job['cursor'] : array();
		$batch_size = max( 1, min( 100, (int) ( $job['batchSize'] ?? 100 ) ) );
		$window_start = isset( $job['windowStart'] ) ? (string) $job['windowStart'] : '';
		$window_end   = isset( $job['windowEnd'] ) && '' !== (string) $job['windowEnd'] ? (string) $job['windowEnd'] : gmdate( 'c' );
		$routed_source = isset( $settings['routing']['sourceIds']['forms'] ) ? (string) $settings['routing']['sourceIds']['forms'] : '';
		$routed_default = isset( $settings['routing']['projectSourceId'] ) ? (string) $settings['routing']['projectSourceId'] : '';

		$events = array();
		$current_contract_key = '';
		foreach ( $keys as $contract_key ) {
			$state  = isset( $cursor[ $contract_key ] ) && is_array( $cursor[ $contract_key ] ) ? $cursor[ $contract_key ] : array( 'offset' => 0, 'done' => false );
			if ( ! empty( $state['done'] ) ) {
				continue;
			}
			$current_contract_key = $contract_key;
			$offset = (int) ( $state['offset'] ?? 0 );
			$remaining = $batch_size - count( $events );
			if ( $remaining <= 0 ) {
				break;
			}
			$outcome = $this->collect_backfill_events_for_key(
				$contract_key,
				$contracts,
				$settings,
				$window_start,
				$window_end,
				$offset,
				$remaining,
				$routed_source,
				$routed_default
			);
			$events = array_merge( $events, (array) $outcome['events'] );
			$cursor[ $contract_key ] = array(
				'offset' => (int) $outcome['nextOffset'],
				'done'   => ! empty( $outcome['done'] ),
			);
			if ( ! empty( $outcome['warning'] ) ) {
				$job['lastError'] = (string) $outcome['warning'];
			}

			if ( count( $events ) >= $batch_size ) {
				break;
			}
		}

		$job['cursor'] = $cursor;
		$job['completedForms'] = $this->count_done_forms( $keys, $cursor );

		if ( empty( $events ) ) {
			$job['status']      = 'completed';
			$job['completedAt'] = gmdate( 'c' );
			$job['updatedAt']   = gmdate( 'c' );
			$job['lastError']   = '';
			$settings['backfill'] = $job;
			$this->options_repo->save_settings( $settings );
			return;
		}

		$client  = new BurrowWP\Infrastructure\Http\BurrowApiClient( $settings['base_url'], $settings['api_key'] );
		$payload = array(
			'events'            => $events,
			'cursor'            => isset( $cursor[ $current_contract_key ] ) ? $cursor[ $current_contract_key ] : array(),
			'windowStart'       => $window_start,
			'windowEnd'         => $window_end,
			'source'            => 'wordpress-plugin',
			'metadata'          => array(
				'cursor'      => isset( $cursor[ $current_contract_key ] ) ? $cursor[ $current_contract_key ] : array(),
				'windowStart' => $window_start,
				'windowEnd'   => $window_end,
				'source'      => 'wordpress-plugin',
			),
			'batchSize'         => $batch_size,
			'perKeyConcurrency' => max( 1, (int) ( $job['perKeyConcurrency'] ?? 4 ) ),
			'routingDefaults'   => array(
				'clientId'       => $settings['routing']['clientId'] ?? null,
				'projectId'      => $settings['routing']['projectId'] ?? null,
				'projectSourceId'=> $settings['routing']['projectSourceId'] ?? null,
				'integrationId'  => $settings['routing']['integrationId'] ?? null,
			),
		);
		$response = $client->backfill_events( $payload );
		if ( empty( $response['ok'] ) ) {
			$job['status']    = 'failed';
			$job['lastError'] = isset( $response['error'] ) ? (string) $response['error'] : 'Backfill request failed.';
			$job['updatedAt'] = gmdate( 'c' );
			$settings['backfill'] = $job;
			$this->options_repo->save_settings( $settings );
			return;
		}

		$response_body          = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		$accepted_count         = isset( $response_body['acceptedCount'] ) ? (int) $response_body['acceptedCount'] : count( $events );
		$rejected_rows          = isset( $response_body['rejected'] ) && is_array( $response_body['rejected'] ) ? $response_body['rejected'] : array();
		$rejected_count         = isset( $response_body['rejectedCount'] ) ? (int) $response_body['rejectedCount'] : count( $rejected_rows );
		$job['processedEvents'] = (int) ( $job['processedEvents'] ?? 0 ) + max( 0, $accepted_count );
		if ( $rejected_count > 0 ) {
			$first_rejected = reset( $rejected_rows );
			$reason         = is_array( $first_rejected ) && ! empty( $first_rejected['reason'] ) ? (string) $first_rejected['reason'] : 'One or more events were rejected.';
			$job['lastError'] = sprintf( 'Backfill accepted %d and rejected %d events. First rejection: %s', $accepted_count, $rejected_count, $reason );
		}
		$job['updatedAt']       = gmdate( 'c' );
		$settings['backfill']   = $job;
		$this->options_repo->save_settings( $settings );
	}

	/**
	 * Return contract keys eligible for backfill.
	 *
	 * @param array<string,mixed> $contracts Forms contracts.
	 * @return string[]
	 */
	private function get_backfillable_contract_keys( array $contracts ) {
		$keys = array();
		foreach ( $contracts as $key => $contract ) {
			if ( ! is_array( $contract ) || empty( $contract['enabled'] ) ) {
				continue;
			}
			$keys[] = (string) $key;
		}
		$settings = $this->options_repo->get_settings();
		$selected = isset( $settings['onboarding']['selected_integrations'] ) && is_array( $settings['onboarding']['selected_integrations'] )
			? $settings['onboarding']['selected_integrations']
			: array();
		$woo_mode = isset( $settings['onboarding']['woocommerce_mode'] ) ? (string) $settings['onboarding']['woocommerce_mode'] : 'track';
		$woo_enabled = 'track' === $woo_mode || in_array( 'woocommerce', $selected, true );
		if ( $woo_enabled ) {
			$keys[] = 'woocommerce:orders';
		}
		return $keys;
	}

	/**
	 * Collect backfill events for one key (forms contract or woo pseudo-key).
	 *
	 * @param string              $contract_key Key.
	 * @param array<string,mixed> $contracts Contracts map.
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $window_start Window start ISO.
	 * @param string              $window_end Window end ISO.
	 * @param int                 $offset Offset.
	 * @param int                 $limit Limit.
	 * @param string              $routed_source Source id for forms.
	 * @param string              $routed_default Fallback source id.
	 * @return array{events:array<int,array<string,mixed>>,nextOffset:int,done:bool,warning:string}
	 */
	private function collect_backfill_events_for_key( $contract_key, array $contracts, array $settings, $window_start, $window_end, $offset, $limit, $routed_source, $routed_default ) {
		if ( 'woocommerce:orders' === $contract_key ) {
			return $this->collect_woocommerce_backfill_events( $settings, $window_start, $window_end, $offset, $limit );
		}
		if ( ! isset( $contracts[ $contract_key ] ) || ! is_array( $contracts[ $contract_key ] ) ) {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => '' );
		}
		$contract = $contracts[ $contract_key ];
		$provider = isset( $contract['provider'] ) ? (string) $contract['provider'] : '';
		$form_id  = isset( $contract['externalFormId'] ) ? (string) $contract['externalFormId'] : '';
		$form_name = isset( $contract['formName'] ) ? (string) $contract['formName'] : $form_id;
		$entries  = array();
		$warning  = '';
		if ( 'gravity-forms' === $provider ) {
			$entries = $this->get_gravity_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit );
		} elseif ( 'ninja-forms' === $provider ) {
			$entries = $this->get_ninja_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit );
		} elseif ( 'fluent-forms' === $provider ) {
			$entries = $this->get_fluent_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit );
		} elseif ( 'contact-form-7' === $provider ) {
			$result  = $this->get_cf7_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit );
			$entries = isset( $result['entries'] ) && is_array( $result['entries'] ) ? $result['entries'] : array();
			$warning = isset( $result['warning'] ) ? (string) $result['warning'] : '';
		} else {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => '' );
		}
		if ( empty( $entries ) ) {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => $warning );
		}
		$mapped_fields = isset( $contract['fieldMappings'] ) && is_array( $contract['fieldMappings'] ) ? $contract['fieldMappings'] : array();
		$count_only    = ! empty( $contract['countOnly'] );
		$form_source   = '' !== $routed_source ? $routed_source : $routed_default;
		$events        = array();
		foreach ( $entries as $entry ) {
			$raw_values = isset( $entry['rawValues'] ) && is_array( $entry['rawValues'] ) ? $entry['rawValues'] : ( is_array( $entry ) ? $entry : array() );
			$submission_id = isset( $entry['submissionId'] ) ? (string) $entry['submissionId'] : (string) ( $entry['id'] ?? '' );
			$submitted_at  = $this->resolve_submission_timestamp( $entry );
			$mapped     = $this->contract_mapper->map( $raw_values, $mapped_fields );
			$tags       = array( 'formId' => (string) ( $contract['formHandle'] ?? $form_id ) );
			$props      = array(
				'formName'     => $form_name,
				'submissionId' => $submission_id,
				'submittedAt'  => $submitted_at,
			);
			if ( ! $count_only ) {
				$tags  = array_merge( $tags, (array) $mapped['tags'] );
				$props = array_merge( $props, (array) $mapped['properties'] );
			}
			$events[] = $this->envelopes->build(
				(array) $settings['routing'],
				array(
					'projectSourceId' => $form_source,
					'integrationId'   => $settings['routing']['integrationId'] ?? null,
					'icon'            => $this->resolve_event_icon_override( $settings, 'forms.submission.received', $contract ),
					'entityType'      => 'form_submission',
					'externalEntityId'=> $submission_id,
					'externalEventId' => $this->event_keys->forms_submission_key( (string) $form_id, $submission_id ),
					'channel'         => 'forms',
					'event'           => 'forms.submission.received',
					'description'     => 'Backfilled form submission',
					'timestamp'       => $submitted_at,
					'properties'      => $props,
					'tags'            => $tags,
				)
			);
		}
		return array(
			'events'     => $events,
			'nextOffset' => $offset + count( $entries ),
			'done'       => count( $entries ) < $limit,
			'warning'    => $warning,
		);
	}

	/**
	 * Collect WooCommerce backfill events.
	 *
	 * @return array{events:array<int,array<string,mixed>>,nextOffset:int,done:bool,warning:string}
	 */
	private function collect_woocommerce_backfill_events( array $settings, $window_start, $window_end, $offset, $limit ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => '' );
		}
		$args = array(
			'limit'   => max( 1, (int) $limit ),
			'offset'  => max( 0, (int) $offset ),
			'orderby' => 'date',
			'order'   => 'ASC',
			'return'  => 'objects',
		);
		if ( '' !== (string) $window_start ) {
			$args['date_created'] = $this->woocommerce_date_query( $window_start, $window_end );
		}
		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) || empty( $orders ) ) {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => '' );
		}
		$routing  = (array) ( $settings['routing'] ?? array() );
		$source   = $routing['sourceIds']['ecommerce'] ?? ( $routing['projectSourceId'] ?? '' );
		$provider = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$events   = array();
		foreach ( $orders as $order ) {
			$data = $provider->normalize_order( $order );
			if ( empty( $data ) ) {
				continue;
			}
			$submitted_at = $this->resolve_order_timestamp( $order );
			$events[] = $this->envelopes->build(
				$routing,
				array(
					'projectSourceId' => $source,
					'integrationId'   => $routing['integrationId'] ?? null,
					'icon'            => $this->resolve_event_icon_override( $settings, 'ecommerce.order.placed' ),
					'entityType'      => 'order',
					'externalEntityId'=> (string) $data['orderId'],
					'externalEventId' => $this->event_keys->ecommerce_order_key( (string) $data['orderId'] ),
					'channel'         => 'ecommerce',
					'event'           => 'ecommerce.order.placed',
					'description'     => 'Backfilled order placed',
					'timestamp'       => $submitted_at,
					'properties'      => array(
						'orderId'   => $data['orderId'],
						'total'     => $data['total'],
						'currency'  => $data['currency'],
						'itemCount' => $data['itemCount'],
						'submittedAt' => $submitted_at,
					),
					'tags'            => array(
						'orderId'       => (string) $data['orderId'],
						'status'        => (string) $data['status'],
						'paymentMethod' => (string) $data['paymentMethod'],
					),
				)
			);
			foreach ( (array) $data['items'] as $item ) {
				$item_event_key = $this->event_keys->ecommerce_item_key( (string) $data['orderId'], (string) $item['lineItemId'] );
				$events[] = $this->envelopes->build(
					$routing,
					array(
						'projectSourceId' => $source,
						'integrationId'   => $routing['integrationId'] ?? null,
						'icon'            => $this->resolve_event_icon_override( $settings, 'ecommerce.item.purchased' ),
						'entityType'      => 'order_item',
						'externalEntityId'=> (string) ( $item['lineItemId'] ?? '' ),
						'externalEventId' => $item_event_key,
						'channel'         => 'ecommerce',
						'event'           => 'ecommerce.item.purchased',
						'description'     => 'Backfilled order item purchased',
						'timestamp'       => $submitted_at,
						'properties'      => array(
							'orderId'     => $data['orderId'],
							'productName' => $item['productName'],
							'variantName' => $item['variantName'],
							'quantity'    => $item['quantity'],
							'unitPrice'   => $item['unitPrice'],
							'lineTotal'   => $item['lineTotal'],
							'productUrl'  => $item['productUrl'],
							'submittedAt' => $submitted_at,
						),
						'tags'            => array(
							'orderId'     => (string) $data['orderId'],
							'productId'   => (string) $item['productId'],
							'productName' => (string) $item['productName'],
							'category'    => (string) $item['category'],
							'vendor'      => (string) $item['vendor'],
						),
					)
				);
			}
		}
		return array(
			'events'     => $events,
			'nextOffset' => $offset + count( $orders ),
			'done'       => count( $orders ) < $limit,
			'warning'    => '',
		);
	}

	/**
	 * Count done forms based on cursor state.
	 *
	 * @param string[]                 $keys Active contract keys.
	 * @param array<string,array>      $cursor Cursor state.
	 * @return int
	 */
	private function count_done_forms( array $keys, array $cursor ) {
		$count = 0;
		foreach ( $keys as $key ) {
			if ( ! empty( $cursor[ $key ]['done'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Load Gravity entries for backfill window.
	 *
	 * @param string $form_id     Form ID.
	 * @param string $window_start ISO timestamp.
	 * @param string $window_end   ISO timestamp.
	 * @param int    $offset       Offset cursor.
	 * @param int    $limit        Limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_gravity_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit ) {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_entries' ) ) {
			return array();
		}

		$start = $this->iso_to_mysql_datetime( $window_start );
		$end   = $this->iso_to_mysql_datetime( $window_end );
		$search = array();
		if ( $start || $end ) {
			$search['field_filters'] = array(
				'mode' => 'all',
				array(
					'key'      => 'date_created',
					'operator' => '>=',
					'value'    => $start ? $start : '1970-01-01 00:00:00',
				),
				array(
					'key'      => 'date_created',
					'operator' => '<=',
					'value'    => $end ? $end : gmdate( 'Y-m-d H:i:s' ),
				),
			);
		}
		$sorting = array(
			'key'       => 'date_created',
			'direction' => 'ASC',
		);
		$paging = array(
			'offset'    => max( 0, (int) $offset ),
			'page_size' => max( 1, (int) $limit ),
		);
		$entries = \GFAPI::get_entries( (int) $form_id, $search, $sorting, $paging );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Load Ninja Forms submissions for a form.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_ninja_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$subs_table = $wpdb->prefix . 'nf3_subs';
		$meta_table = $wpdb->prefix . 'nf3_sub_meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$subs_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$subs_table}'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$meta_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$meta_table}'" );
		if ( $subs_table !== $subs_exists || $meta_table !== $meta_exists ) {
			return array();
		}
		$start = $this->iso_to_mysql_datetime( $window_start );
		$end   = $this->iso_to_mysql_datetime( $window_end );
		$date_col = 'date_updated';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$has_date_updated = $wpdb->get_var( "SHOW COLUMNS FROM {$subs_table} LIKE 'date_updated'" );
		if ( empty( $has_date_updated ) ) {
			$date_col = 'date_submitted';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare(
			"SELECT id, form_id, {$date_col} AS submitted_at FROM {$subs_table} WHERE form_id = %d AND {$date_col} >= %s AND {$date_col} <= %s ORDER BY id ASC LIMIT %d OFFSET %d",
			(int) $form_id,
			'' !== $start ? $start : '1970-01-01 00:00:00',
			'' !== $end ? $end : gmdate( 'Y-m-d H:i:s' ),
			(int) max( 1, $limit ),
			(int) max( 0, $offset )
		);
		$subs = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $subs ) || empty( $subs ) ) {
			return array();
		}
		$out = array();
		foreach ( $subs as $sub ) {
			$sub_id = isset( $sub['id'] ) ? (int) $sub['id'] : 0;
			if ( $sub_id <= 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$meta_table} WHERE parent_id = %d", $sub_id ), ARRAY_A );
			$raw = array();
			foreach ( (array) $meta_rows as $meta ) {
				if ( ! is_array( $meta ) || ! isset( $meta['meta_key'] ) ) {
					continue;
				}
				$raw[ (string) $meta['meta_key'] ] = $meta['meta_value'] ?? '';
			}
			$out[] = array(
				'submissionId' => (string) $sub_id,
				'rawValues'    => $raw,
				'submittedAt'  => $this->resolve_iso8601( $sub['submitted_at'] ?? null ),
			);
		}
		return $out;
	}

	/**
	 * Load Fluent Forms submissions for a form.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_fluent_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $table !== $exists ) {
			return array();
		}
		$start = $this->iso_to_mysql_datetime( $window_start );
		$end   = $this->iso_to_mysql_datetime( $window_end );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare(
			"SELECT id, response, created_at FROM {$table} WHERE form_id = %d AND created_at >= %s AND created_at <= %s ORDER BY id ASC LIMIT %d OFFSET %d",
			(int) $form_id,
			'' !== $start ? $start : '1970-01-01 00:00:00',
			'' !== $end ? $end : gmdate( 'Y-m-d H:i:s' ),
			(int) max( 1, $limit ),
			(int) max( 0, $offset )
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$submission_id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $submission_id ) {
				continue;
			}
			$raw = json_decode( (string) ( $row['response'] ?? '{}' ), true );
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}
			$out[] = array(
				'submissionId' => $submission_id,
				'rawValues'    => $raw,
				'submittedAt'  => $this->resolve_iso8601( $row['created_at'] ?? null ),
			);
		}
		return $out;
	}

	/**
	 * Load CF7 entries from Flamingo when available.
	 *
	 * @return array{entries:array<int,array<string,mixed>>,warning:string}
	 */
	private function get_cf7_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit ) {
		if ( ! class_exists( '\Flamingo_Inbound_Message' ) ) {
			return array(
				'entries' => array(),
				'warning' => 'Contact Form 7 does not store submissions by default. Install Flamingo to enable CF7 backfill.',
			);
		}
		$args = array(
			'post_type'      => 'flamingo_inbound',
			'posts_per_page' => max( 1, (int) $limit ),
			'offset'         => max( 0, (int) $offset ),
			'orderby'        => 'date',
			'order'          => 'ASC',
			'date_query'     => array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', strtotime( (string) $window_start ) ),
					'before'    => gmdate( 'Y-m-d H:i:s', strtotime( (string) $window_end ) ),
					'inclusive' => true,
				),
			),
			'meta_query'     => array(
				array(
					'key'   => '_wpcf7',
					'value' => (string) $form_id,
				),
			),
		);
		$query = new \WP_Query( $args );
		$out = array();
		foreach ( (array) $query->posts as $post ) {
			if ( ! isset( $post->ID ) ) {
				continue;
			}
			$meta = get_post_meta( (int) $post->ID );
			$raw  = array();
			foreach ( (array) $meta as $key => $value ) {
				$key = (string) $key;
				if ( 0 === strpos( $key, '_field_' ) ) {
					$field_name = substr( $key, 7 );
					$raw[ $field_name ] = is_array( $value ) ? ( $value[0] ?? '' ) : $value;
				}
			}
			$out[] = array(
				'submissionId' => (string) $post->ID,
				'rawValues'    => $raw,
				'submittedAt'  => $this->resolve_iso8601( $post->post_date_gmt ?? $post->post_date ?? null ),
			);
		}
		wp_reset_postdata();
		return array(
			'entries' => $out,
			'warning' => '',
		);
	}

	/**
	 * Build WooCommerce date filter string.
	 *
	 * @return string
	 */
	private function woocommerce_date_query( $window_start, $window_end ) {
		$start = strtotime( (string) $window_start );
		$end   = strtotime( (string) $window_end );
		if ( false === $start || false === $end ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $start ) . '...' . gmdate( 'Y-m-d H:i:s', $end );
	}

	/**
	 * Convert ISO datetime to mysql datetime.
	 *
	 * @param string $iso ISO timestamp.
	 * @return string
	 */
	private function iso_to_mysql_datetime( $iso ) {
		$iso = (string) $iso;
		if ( '' === $iso ) {
			return '';
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Resolve best-effort submission timestamp from entry payload.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @return string|null
	 */
	private function resolve_submission_timestamp( array $entry ) {
		foreach ( array( 'submittedAt', 'timestamp', 'date_created', 'created_at', 'dateCreated' ) as $key ) {
			if ( isset( $entry[ $key ] ) ) {
				$value = $this->resolve_iso8601( $entry[ $key ] );
				if ( null !== $value ) {
					return $value;
				}
			}
		}
		if ( isset( $entry['rawValues'] ) && is_array( $entry['rawValues'] ) ) {
			foreach ( array( 'submittedAt', 'timestamp', 'date_created', 'created_at', 'dateCreated' ) as $key ) {
				if ( isset( $entry['rawValues'][ $key ] ) ) {
					$value = $this->resolve_iso8601( $entry['rawValues'][ $key ] );
					if ( null !== $value ) {
						return $value;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Resolve Woo order created timestamp as ISO 8601 UTC.
	 *
	 * @param mixed $order Woo order object.
	 * @return string|null
	 */
	private function resolve_order_timestamp( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_date_created' ) ) {
			return null;
		}
		$created = $order->get_date_created();
		if ( is_object( $created ) && method_exists( $created, 'getTimestamp' ) ) {
			$ts = (int) $created->getTimestamp();
			return $ts > 0 ? gmdate( 'c', $ts ) : null;
		}
		return $this->resolve_iso8601( $created );
	}

	/**
	 * Normalize datetime to ISO 8601 UTC.
	 *
	 * @param mixed $value Datetime.
	 * @return string|null
	 */
	private function resolve_iso8601( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'c', $ts );
	}

	/**
	 * Resolve optional icon override from contract or plugin settings.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param string              $event_name Event name.
	 * @param array<string,mixed> $contract Optional contract metadata.
	 * @return string|null
	 */
	private function resolve_event_icon_override( array $settings, $event_name, array $contract = array() ) {
		if ( isset( $contract['icon'] ) ) {
			$icon = $this->valid_lucide_icon_key( $contract['icon'] );
			if ( null !== $icon ) {
				return $icon;
			}
		}
		$map = isset( $settings['event_icon_overrides'] ) && is_array( $settings['event_icon_overrides'] )
			? $settings['event_icon_overrides']
			: array();
		if ( isset( $map[ $event_name ] ) ) {
			$icon = $this->valid_lucide_icon_key( $map[ $event_name ] );
			if ( null !== $icon ) {
				return $icon;
			}
		}
		return null;
	}

	/**
	 * Validate Lucide icon key format.
	 *
	 * @param mixed $value Icon key.
	 * @return string|null
	 */
	private function valid_lucide_icon_key( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$icon = trim( strtolower( (string) $value ) );
		if ( '' === $icon ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $icon ) ) {
			return null;
		}
		return $icon;
	}

	public function run() {
		$this->loader->run();
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	public function get_loader() {
		return $this->loader;
	}

	public function get_version() {
		return $this->version;
	}
}
