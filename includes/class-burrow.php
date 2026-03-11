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
	 * @var \Burrow\Sdk\Outbox\OutboxDelivery|null
	 */
	private $delivery;

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
		$this->loader->add_action( 'wpforms_process_complete', $this, 'handle_wpforms_submission', 10, 4 );
		$this->loader->add_action( 'frm_after_create_entry', $this, 'handle_formidable_submission', 10, 2 );

		// WooCommerce hooks.
		$this->loader->add_action( 'woocommerce_checkout_order_processed', $this, 'handle_woocommerce_order', 10, 1 );
		$this->loader->add_action( 'woocommerce_payment_complete', $this, 'handle_woocommerce_order', 10, 1 );
		$this->loader->add_action( 'woocommerce_order_status_changed', $this, 'handle_woocommerce_status_change', 10, 3 );

		// Ecommerce funnel hooks (gated by capabilities.ecommerce_funnel at runtime).
		$this->loader->add_action( 'woocommerce_add_to_cart', $this, 'handle_woocommerce_cart_item_added', 10, 6 );
		$this->loader->add_action( 'woocommerce_cart_item_removed', $this, 'handle_woocommerce_cart_item_removed', 10, 2 );
		$this->loader->add_action( 'woocommerce_checkout_init', $this, 'handle_woocommerce_checkout_started', 10, 1 );
	}

	private function define_worker_hooks() {
		$this->loader->add_action( 'init', $this, 'ensure_cron_jobs' );
		$this->loader->add_action( 'burrow_outbox_worker', $this, 'run_outbox_worker' );
		$this->loader->add_action( 'burrow_system_heartbeat', $this, 'emit_system_heartbeat' );
		$this->loader->add_action( 'burrow_system_stack_snapshot', $this, 'emit_system_stack_snapshot' );
		$this->loader->add_action( 'burrow_outbox_cleanup', $this, 'cleanup_outbox' );
		$this->loader->add_action( 'burrow_backfill_worker', $this, 'run_backfill_worker' );
		$this->loader->add_action( 'burrow_invalidate_delivery', $this, 'invalidate_delivery_cache' );
		$this->loader->add_action( 'burrow_checkout_abandonment_scan', $this, 'run_checkout_abandonment_scan' );
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

		$settings = $this->options_repo->get_settings();
		if ( $this->is_ecommerce_funnel_enabled( $settings ) ) {
			if ( ! wp_next_scheduled( 'burrow_checkout_abandonment_scan' ) ) {
				wp_schedule_event( time() + 180, 'hourly', 'burrow_checkout_abandonment_scan' );
			}
		} else {
			wp_clear_scheduled_hook( 'burrow_checkout_abandonment_scan' );
		}
	}

	/**
	 * Lazy-build the SDK OutboxDelivery with structured logging.
	 *
	 * @return \Burrow\Sdk\Outbox\OutboxDelivery|null Null if plugin is not configured.
	 */
	private function get_delivery() {
		if ( null !== $this->delivery ) {
			return $this->delivery;
		}
		$settings      = $this->options_repo->get_settings();
		$ingestion_key = isset( $settings['ingestion_key'] ) && is_array( $settings['ingestion_key'] ) ? $settings['ingestion_key'] : array();
		$auth_key      = BurrowWP\Core\Auth\DispatchCredentials::resolve_dispatch_api_key( '', $ingestion_key );
		if ( '' === $auth_key || empty( $settings['base_url'] ) ) {
			return null;
		}
		$api_client = new BurrowWP\Infrastructure\Http\BurrowApiClient(
			$settings['base_url'],
			$auth_key,
			5,
			$ingestion_key,
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		$sdk_client = $api_client->get_dispatch_client();
		$max_attempts = isset( $settings['max_attempts'] ) ? (int) $settings['max_attempts'] : 5;

		$this->delivery = new \Burrow\Sdk\Outbox\OutboxDelivery(
			$this->outbox_repo,
			$sdk_client,
			$max_attempts
		);
		return $this->delivery;
	}

	/**
	 * Force the delivery instance to rebuild on next use.
	 * Call after settings mutations (contract sync, link) that change
	 * credentials or routing so the next enqueue/flush uses fresh state.
	 */
	public function invalidate_delivery_cache() {
		$this->delivery = null;
	}

	/**
	 * Structured logger closure for SDK outbox transitions.
	 *
	 * @return \Closure
	 */
	private function outbox_logger() {
		return static function ( array $entry ) {
			$short = isset( $entry['eventKeyShort'] ) ? (string) $entry['eventKeyShort'] : '???';
			$from  = isset( $entry['fromStatus'] ) ? (string) $entry['fromStatus'] : '?';
			$to    = isset( $entry['toStatus'] ) ? (string) $entry['toStatus'] : '?';
			$http  = isset( $entry['httpStatus'] ) ? (string) $entry['httpStatus'] : '-';
			$msg   = isset( $entry['message'] ) ? (string) $entry['message'] : '';
			$retry = ! empty( $entry['retryable'] ) ? 'retryable' : 'terminal';
			error_log( sprintf(
				'[Burrow outbox] %s %s->%s http=%s %s %s',
				$short,
				$from,
				$to,
				$http,
				$retry,
				$msg
			) );
		};
	}

	/**
	 * Enqueue events through the SDK outbox delivery (background dispatch via cron).
	 *
	 * @param list<array<string,mixed>> $events   Envelopes.
	 * @param array<string,mixed>       $context  Key generation context.
	 * @return array{enqueued:int,deduped:int}
	 */
	private function sdk_enqueue_events( array $events, array $context = array() ) {
		$delivery = $this->get_delivery();
		if ( null === $delivery ) {
			return array( 'enqueued' => 0, 'deduped' => 0 );
		}
		return $delivery->enqueueEvents( $events, $context );
	}

	/**
	 * Dispatch events immediately, falling back to the outbox on failure.
	 * Use for realtime hooks (form submissions, ecommerce orders) where
	 * instant delivery is preferred over waiting for cron.
	 *
	 * @param list<array<string,mixed>> $events   Envelopes.
	 * @param array<string,mixed>       $context  Key generation context.
	 * @return array{enqueued:int,deduped:int,sent:int,retrying:int,failed:int}
	 */
	private function sdk_dispatch_events( array $events, array $context = array() ) {
		$delivery = $this->get_delivery();
		if ( null === $delivery ) {
			return array( 'enqueued' => 0, 'deduped' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0 );
		}
		return $delivery->dispatchImmediate( $events, $context );
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
	 * Handle WPForms submission.
	 *
	 * @param array<string,mixed> $fields    Sanitized entry field values/properties.
	 * @param array<string,mixed> $entry     Entry data.
	 * @param array<string,mixed> $form_data Form data and settings.
	 * @param int                 $entry_id  Entry ID.
	 * @return void
	 */
	public function handle_wpforms_submission( $fields, $entry, $form_data, $entry_id ) {
		$provider = new BurrowWP\Providers\Forms\WPFormsProvider();
		$payload  = array(
			'fields'    => is_array( $fields ) ? $fields : array(),
			'entry'     => is_array( $entry ) ? $entry : array(),
			'form_data' => is_array( $form_data ) ? $form_data : array(),
		);
		if ( ! empty( $entry_id ) ) {
			$payload['entry']['id'] = $entry_id;
		}
		$this->enqueue_forms_event( $provider->normalize_submission( $payload ) );
	}

	/**
	 * Handle Formidable Forms submission.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param int   $form_id  Form ID.
	 * @return void
	 */
	public function handle_formidable_submission( $entry_id, $form_id ) {
		$provider = new BurrowWP\Providers\Forms\FormidableFormsProvider();
		$entry    = null;
		$values   = array();

		if ( class_exists( '\FrmEntry' ) && method_exists( '\FrmEntry', 'getOne' ) ) {
			$entry = \FrmEntry::getOne( (int) $entry_id, true );
		}
		if ( is_object( $entry ) && isset( $entry->metas ) && is_array( $entry->metas ) ) {
			$values = $entry->metas;
		}

		$this->enqueue_forms_event( $provider->normalize_submission( array(
			'entry'  => $entry,
			'values' => $values,
		) ) );
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

		$settings = $this->options_repo->get_settings();
		if ( ! $this->is_woo_tracking_enabled( $settings ) ) {
			return;
		}

		$provider = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$data     = $provider->normalize_order( $order );
		if ( empty( $data ) ) {
			return;
		}

		$submitted_at = $this->resolve_order_timestamp( $order );

		try {
			$routing_resolver = $this->build_channel_routing_resolver( $settings );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow ecommerce] Skipped: ' . $e->getMessage() );
			return;
		}

		$input = $this->build_order_placed_input( $data, $submitted_at, $settings );
		try {
			$order_envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent(
				$input,
				$routing_resolver
			);
		} catch ( \Throwable $e ) {
			error_log( '[Burrow ecommerce] order.placed build failed: ' . $e->getMessage() );
			return;
		}

		$envelopes = array( $order_envelope );

		$customer_token = (string) $data['customerToken'];
		foreach ( (array) $data['items'] as $item ) {
			$item_input = $this->build_item_purchased_input( $item, $data, $submitted_at, $customer_token, $settings );
			try {
				$envelopes[] = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceItemPurchasedEvent(
					$item_input,
					$routing_resolver
				);
			} catch ( \Throwable $e ) {
				error_log( '[Burrow ecommerce] item.purchased build failed: ' . $e->getMessage() );
				continue;
			}
		}

		if ( $this->is_ecommerce_funnel_enabled( $settings ) ) {
			$recovery_envelope = $this->maybe_build_cart_recovered_envelope( $order, $data, $customer_token, $settings, $routing_resolver );
			if ( null !== $recovery_envelope ) {
				$envelopes[] = $recovery_envelope;
			}
		}

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		$this->sdk_dispatch_events( $envelopes, array(
			'provider'  => 'woocommerce',
			'projectId' => $sdk->projectId ?? '',
		) );
	}

	/**
	 * Check if a completing order was previously abandoned and build a cart.recovered envelope.
	 *
	 * @param object                                $order            WC_Order.
	 * @param array<string,mixed>                   $data             Normalized order data.
	 * @param string                                $customer_token   Customer token.
	 * @param array<string,mixed>                   $settings         Plugin settings.
	 * @param \Burrow\Sdk\Events\ChannelRoutingResolver $routing_resolver Routing.
	 * @return array<string,mixed>|null
	 */
	private function maybe_build_cart_recovered_envelope( $order, array $data, $customer_token, array $settings, $routing_resolver ) {
		$emitted = get_option( 'burrow_abandoned_sessions', array() );
		if ( ! is_array( $emitted ) || empty( $emitted ) ) {
			return null;
		}

		$customer_id = (int) $order->get_customer_id();
		$session_key = $customer_id > 0 ? (string) $customer_id : null;

		if ( null === $session_key ) {
			return null;
		}

		if ( ! isset( $emitted[ $session_key ] ) ) {
			return null;
		}

		$abandoned_at = strtotime( (string) $emitted[ $session_key ] );
		$now          = time();
		$minutes      = $abandoned_at ? (int) floor( ( $now - $abandoned_at ) / 60 ) : 0;

		$checkout_sessions = get_option( 'burrow_checkout_sessions', array() );
		$original_cart_total = isset( $checkout_sessions[ $session_key ]['cartTotal'] )
			? (float) $checkout_sessions[ $session_key ]['cartTotal']
			: (float) $data['total'];

		$input = array(
			'organizationId'          => (string) ( $settings['routing']['organizationId'] ?? '' ),
			'orderId'                 => (string) $data['orderId'],
			'orderTotal'              => (float) $data['total'],
			'originalCartTotal'       => $original_cart_total,
			'minutesSinceAbandonment' => max( 0, $minutes ),
			'currency'                => (string) $data['currency'],
			'timestamp'               => gmdate( 'c' ),
			'customerToken'           => $customer_token,
			'tags'                    => array(
				'provider' => 'woocommerce',
				'currency' => (string) $data['currency'],
			),
		);

		try {
			$envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceCartRecoveredEvent( $input, $routing_resolver );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] cart.recovered build failed: ' . $e->getMessage() );
			return null;
		}

		unset( $emitted[ $session_key ] );
		update_option( 'burrow_abandoned_sessions', $emitted, false );

		return $envelope;
	}

	/**
	 * Handle WooCommerce order status transitions for lifecycle events.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 */
	public function handle_woocommerce_status_change( $order_id, $old_status, $new_status ) {
		$builder_map = array(
			'completed' => 'buildEcommerceOrderFulfilledEvent',
			'refunded'  => 'buildEcommerceOrderRefundedEvent',
			'cancelled' => 'buildEcommerceOrderCancelledEvent',
		);
		if ( ! isset( $builder_map[ $new_status ] ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$settings = $this->options_repo->get_settings();
		if ( ! $this->is_woo_tracking_enabled( $settings ) ) {
			return;
		}

		$provider = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$data     = $provider->normalize_order( $order );
		if ( empty( $data ) ) {
			return;
		}

		try {
			$routing_resolver = $this->build_channel_routing_resolver( $settings );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow ecommerce] Skipped lifecycle: ' . $e->getMessage() );
			return;
		}

		$input = array(
			'organizationId'   => (string) ( $settings['routing']['organizationId'] ?? '' ),
			'orderId'          => (string) $data['orderId'],
			'orderTotal'       => $data['total'],
			'total'            => $data['total'],
			'currency'         => (string) $data['currency'],
			'timestamp'        => $this->resolve_order_timestamp( $order ) ?? gmdate( 'c' ),
			'externalEntityId' => 'wc_order_' . $data['orderId'],
			'customerToken'    => (string) $data['customerToken'],
			'tags'             => array(
				'provider' => 'woocommerce',
				'currency' => (string) $data['currency'],
			),
		);

		$builder_method = $builder_map[ $new_status ];
		try {
			$envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::$builder_method( $input, $routing_resolver );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow ecommerce] order.' . $new_status . ' build failed: ' . $e->getMessage() );
			return;
		}

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		$this->sdk_dispatch_events( array( $envelope ), array(
			'provider'  => 'woocommerce',
			'projectId' => $sdk->projectId ?? '',
		) );
	}

	/**
	 * @return bool
	 */
	private function is_woo_tracking_enabled( array $settings ) {
		$selected = isset( $settings['onboarding']['selected_integrations'] ) && is_array( $settings['onboarding']['selected_integrations'] )
			? $settings['onboarding']['selected_integrations']
			: array();
		$mode = isset( $settings['onboarding']['woocommerce_mode'] ) ? (string) $settings['onboarding']['woocommerce_mode'] : 'track';
		return in_array( 'woocommerce', $selected, true ) && 'track' === $mode;
	}

	/**
	 * @return bool
	 */
	private function is_ecommerce_funnel_enabled( array $settings ) {
		if ( ! $this->is_woo_tracking_enabled( $settings ) ) {
			return false;
		}
		$caps = isset( $settings['capabilities'] ) && is_array( $settings['capabilities'] ) ? $settings['capabilities'] : array();
		return ! empty( $caps['ecommerce_funnel'] );
	}

	// ────────────────────────────────────────────────────
	// Ecommerce funnel event handlers
	// ────────────────────────────────────────────────────

	/**
	 * Handle cart.item.added via woocommerce_add_to_cart hook.
	 *
	 * @param string $cart_item_key  Cart item key.
	 * @param int    $product_id     Product ID.
	 * @param int    $quantity       Quantity added.
	 * @param int    $variation_id   Variation ID.
	 * @param mixed  $variation      Variation data.
	 * @param mixed  $cart_item_data Cart item data.
	 */
	public function handle_woocommerce_cart_item_added( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		$settings = $this->options_repo->get_settings();
		if ( ! $this->is_ecommerce_funnel_enabled( $settings ) ) {
			return;
		}

		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		if ( ! $cart ) {
			return;
		}

		$cart_item = $cart->get_cart_item( $cart_item_key );
		if ( empty( $cart_item ) ) {
			return;
		}

		$provider   = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$item_data  = $provider->normalize_cart_item( $cart_item_key, $cart_item );
		$cart_state = $provider->get_cart_state();
		$identity   = BurrowWP\Providers\Ecommerce\WooCommerceProvider::build_session_customer_identity();

		try {
			$routing_resolver = $this->build_channel_routing_resolver( $settings );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] cart.item.added skipped: ' . $e->getMessage() );
			return;
		}

		$input = array(
			'organizationId' => (string) ( $settings['routing']['organizationId'] ?? '' ),
			'productId'      => (string) $item_data['productId'],
			'productName'    => (string) $item_data['productName'],
			'variantName'    => (string) $item_data['variantName'],
			'quantity'       => (int) $item_data['quantity'],
			'unitPrice'      => (float) $item_data['unitPrice'],
			'lineTotal'      => (float) $item_data['lineTotal'],
			'cartTotal'      => (float) $cart_state['cartTotal'],
			'cartItemCount'  => (int) $cart_state['cartItemCount'],
			'currency'       => (string) $cart_state['currency'],
			'timestamp'      => gmdate( 'c' ),
			'customerToken'  => $identity['customerToken'],
			'tags'           => array(
				'provider'    => 'woocommerce',
				'currency'    => (string) $cart_state['currency'],
				'productId'   => (string) $item_data['productId'],
				'productName' => (string) $item_data['productName'],
				'category'    => (string) $item_data['category'],
			),
		);

		try {
			$envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceCartItemAddedEvent( $input, $routing_resolver );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] cart.item.added build failed: ' . $e->getMessage() );
			return;
		}

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		$this->sdk_dispatch_events( array( $envelope ), array(
			'provider'  => 'woocommerce',
			'projectId' => $sdk->projectId ?? '',
		) );
	}

	/**
	 * Handle cart.item.removed via woocommerce_cart_item_removed hook.
	 *
	 * @param string $cart_item_key Cart item key that was removed.
	 * @param object $cart          WC_Cart instance.
	 */
	public function handle_woocommerce_cart_item_removed( $cart_item_key, $cart ) {
		$settings = $this->options_repo->get_settings();
		if ( ! $this->is_ecommerce_funnel_enabled( $settings ) ) {
			return;
		}

		$removed_item = isset( $cart->removed_cart_contents[ $cart_item_key ] ) ? $cart->removed_cart_contents[ $cart_item_key ] : null;
		if ( empty( $removed_item ) ) {
			return;
		}

		$product   = isset( $removed_item['data'] ) && is_object( $removed_item['data'] ) ? $removed_item['data'] : null;
		$provider  = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$cart_state = $provider->get_cart_state();
		$identity  = BurrowWP\Providers\Ecommerce\WooCommerceProvider::build_session_customer_identity();

		$category = '';
		if ( $product ) {
			$terms = get_the_terms( $product->get_id(), 'product_cat' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$category = (string) $terms[0]->name;
			}
		}

		try {
			$routing_resolver = $this->build_channel_routing_resolver( $settings );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] cart.item.removed skipped: ' . $e->getMessage() );
			return;
		}

		$input = array(
			'organizationId' => (string) ( $settings['routing']['organizationId'] ?? '' ),
			'productId'      => $product ? (string) $product->get_id() : '',
			'productName'    => $product ? (string) $product->get_name() : '',
			'quantity'       => isset( $removed_item['quantity'] ) ? (int) $removed_item['quantity'] : 1,
			'cartTotal'      => (float) $cart_state['cartTotal'],
			'cartItemCount'  => (int) $cart_state['cartItemCount'],
			'currency'       => (string) $cart_state['currency'],
			'timestamp'      => gmdate( 'c' ),
			'customerToken'  => $identity['customerToken'],
			'tags'           => array(
				'provider'    => 'woocommerce',
				'currency'    => (string) $cart_state['currency'],
				'productId'   => $product ? (string) $product->get_id() : '',
				'productName' => $product ? (string) $product->get_name() : '',
				'category'    => $category,
			),
		);

		try {
			$envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceCartItemRemovedEvent( $input, $routing_resolver );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] cart.item.removed build failed: ' . $e->getMessage() );
			return;
		}

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		$this->sdk_dispatch_events( array( $envelope ), array(
			'provider'  => 'woocommerce',
			'projectId' => $sdk->projectId ?? '',
		) );
	}

	/**
	 * Handle checkout.started via woocommerce_checkout_init hook.
	 * Emits once per WC session to avoid duplicate events on page reloads.
	 *
	 * @param mixed $checkout WC_Checkout instance.
	 */
	public function handle_woocommerce_checkout_started( $checkout ) {
		$settings = $this->options_repo->get_settings();
		if ( ! $this->is_ecommerce_funnel_enabled( $settings ) ) {
			return;
		}

		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( ! $session ) {
			return;
		}

		$session_key = $session->get_customer_id();
		if ( $session->get( 'burrow_checkout_started' ) ) {
			return;
		}
		$session->set( 'burrow_checkout_started', gmdate( 'c' ) );

		$provider   = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$cart_state = $provider->get_cart_state();
		$identity   = BurrowWP\Providers\Ecommerce\WooCommerceProvider::build_session_customer_identity();

		if ( $cart_state['cartItemCount'] <= 0 ) {
			return;
		}

		try {
			$routing_resolver = $this->build_channel_routing_resolver( $settings );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] checkout.started skipped: ' . $e->getMessage() );
			return;
		}

		$input = array(
			'organizationId' => (string) ( $settings['routing']['organizationId'] ?? '' ),
			'cartTotal'      => (float) $cart_state['cartTotal'],
			'cartItemCount'  => (int) $cart_state['cartItemCount'],
			'currency'       => (string) $cart_state['currency'],
			'timestamp'      => gmdate( 'c' ),
			'customerToken'  => $identity['customerToken'],
			'isGuest'        => $identity['isGuest'],
			'tags'           => array(
				'provider' => 'woocommerce',
				'currency' => (string) $cart_state['currency'],
			),
		);

		try {
			$envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceCheckoutStartedEvent( $input, $routing_resolver );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] checkout.started build failed: ' . $e->getMessage() );
			return;
		}

		$this->record_checkout_session( $session_key, $cart_state, $identity );

		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		$this->sdk_dispatch_events( array( $envelope ), array(
			'provider'  => 'woocommerce',
			'projectId' => $sdk->projectId ?? '',
		) );
	}

	/**
	 * Record a checkout session for later abandonment scanning.
	 *
	 * @param string              $session_key Session key.
	 * @param array<string,mixed> $cart_state  Cart state snapshot.
	 * @param array<string,mixed> $identity    Customer identity.
	 */
	private function record_checkout_session( $session_key, array $cart_state, array $identity ) {
		$sessions = get_option( 'burrow_checkout_sessions', array() );
		if ( ! is_array( $sessions ) ) {
			$sessions = array();
		}
		$sessions[ (string) $session_key ] = array(
			'startedAt'     => gmdate( 'c' ),
			'cartTotal'     => $cart_state['cartTotal'],
			'cartItemCount' => $cart_state['cartItemCount'],
			'currency'      => $cart_state['currency'],
			'customerToken' => $identity['customerToken'],
		);

		$max_tracked = 500;
		if ( count( $sessions ) > $max_tracked ) {
			$sessions = array_slice( $sessions, -$max_tracked, null, true );
		}
		update_option( 'burrow_checkout_sessions', $sessions, false );
	}

	/**
	 * WP-Cron: scan for abandoned checkouts and emit checkout.abandoned events.
	 * Always queued (inherently async).
	 */
	public function run_checkout_abandonment_scan() {
		$settings = $this->options_repo->get_settings();
		if ( ! $this->is_ecommerce_funnel_enabled( $settings ) ) {
			return;
		}

		$sessions = get_option( 'burrow_checkout_sessions', array() );
		if ( ! is_array( $sessions ) || empty( $sessions ) ) {
			return;
		}

		$emitted = get_option( 'burrow_abandoned_sessions', array() );
		if ( ! is_array( $emitted ) ) {
			$emitted = array();
		}

		$threshold_minutes = 60;
		$now               = time();
		$envelopes         = array();
		$newly_emitted     = array();
		$surviving         = array();

		try {
			$routing_resolver = $this->build_channel_routing_resolver( $settings );
		} catch ( \Throwable $e ) {
			error_log( '[Burrow funnel] checkout.abandoned scan skipped: ' . $e->getMessage() );
			return;
		}

		foreach ( $sessions as $session_key => $session_data ) {
			if ( ! is_array( $session_data ) || empty( $session_data['startedAt'] ) ) {
				continue;
			}

			if ( isset( $emitted[ $session_key ] ) ) {
				continue;
			}

			if ( $this->session_completed_order( $session_key ) ) {
				continue;
			}

			$started_ts = strtotime( (string) $session_data['startedAt'] );
			if ( false === $started_ts ) {
				continue;
			}

			$minutes_since = (int) floor( ( $now - $started_ts ) / 60 );
			if ( $minutes_since < $threshold_minutes ) {
				$surviving[ $session_key ] = $session_data;
				continue;
			}

			$external_entity_id = 'wc_session_' . $session_key;
			$input = array(
				'organizationId'      => (string) ( $settings['routing']['organizationId'] ?? '' ),
				'cartTotal'           => (float) ( $session_data['cartTotal'] ?? 0 ),
				'cartItemCount'       => (int) ( $session_data['cartItemCount'] ?? 0 ),
				'currency'            => (string) ( $session_data['currency'] ?? '' ),
				'minutesSinceCheckout'=> $minutes_since,
				'externalEntityId'    => $external_entity_id,
				'timestamp'           => gmdate( 'c' ),
				'customerToken'       => (string) ( $session_data['customerToken'] ?? '' ),
				'tags'                => array(
					'provider' => 'woocommerce',
					'currency' => (string) ( $session_data['currency'] ?? '' ),
				),
			);

			try {
				$envelopes[] = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceCheckoutAbandonedEvent( $input, $routing_resolver );
				$newly_emitted[ $session_key ] = gmdate( 'c' );
			} catch ( \Throwable $e ) {
				error_log( '[Burrow funnel] checkout.abandoned build failed for ' . $session_key . ': ' . $e->getMessage() );
			}
		}

		if ( ! empty( $envelopes ) ) {
			$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
				isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
			);
			$this->sdk_enqueue_events( $envelopes, array(
				'provider'  => 'woocommerce',
				'projectId' => $sdk->projectId ?? '',
			) );
		}

		if ( ! empty( $newly_emitted ) ) {
			$emitted = array_merge( $emitted, $newly_emitted );
			$max_ledger = 2000;
			if ( count( $emitted ) > $max_ledger ) {
				$emitted = array_slice( $emitted, -$max_ledger, null, true );
			}
			update_option( 'burrow_abandoned_sessions', $emitted, false );
		}

		update_option( 'burrow_checkout_sessions', $surviving, false );
	}

	/**
	 * Check if a WC session resulted in a completed order.
	 *
	 * @param string $session_key WC session customer ID.
	 * @return bool
	 */
	private function session_completed_order( $session_key ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$is_numeric_user = is_numeric( $session_key ) && (int) $session_key > 0;
		if ( ! $is_numeric_user ) {
			return false;
		}

		$orders = wc_get_orders( array(
			'customer_id' => (int) $session_key,
			'limit'       => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
		) );

		return ! empty( $orders );
	}

	/**
	 * Build input array for order.placed SDK envelope builder.
	 *
	 * @param array<string,mixed> $data         Normalized order data.
	 * @param string|null         $submitted_at ISO timestamp.
	 * @param array<string,mixed> $settings     Plugin settings.
	 * @return array<string,mixed>
	 */
	private function build_order_placed_input( array $data, $submitted_at, array $settings ) {
		$tags = array(
			'provider'        => 'woocommerce',
			'currency'        => (string) $data['currency'],
			'customerToken'   => (string) $data['customerToken'],
			'isGuest'         => (string) $data['isGuest'],
			'orderSequence'   => (string) $data['orderSequence'],
			'isNewCustomer'   => (string) $data['isNewCustomer'],
			'paymentMethod'   => (string) $data['paymentMethod'],
			'shippingCountry' => (string) $data['shippingCountry'],
			'shippingRegion'  => (string) $data['shippingRegion'],
		);
		if ( ! empty( $data['shippingMethod'] ) ) {
			$tags['shippingMethod'] = (string) $data['shippingMethod'];
		}
		if ( ! empty( $data['couponCode'] ) ) {
			$tags['couponCode'] = (string) $data['couponCode'];
		}

		$input = array(
			'organizationId'   => (string) ( $settings['routing']['organizationId'] ?? '' ),
			'orderId'          => (string) $data['orderId'],
			'orderTotal'       => $data['total'],
			'total'            => $data['total'],
			'subtotal'         => $data['subtotal'],
			'shipping'         => $data['shipping'],
			'tax'              => $data['tax'],
			'discount'         => $data['discount'],
			'currency'         => (string) $data['currency'],
			'itemCount'        => (int) $data['itemCount'],
			'submittedAt'      => $submitted_at,
			'timestamp'        => $submitted_at,
			'externalEntityId' => 'wc_order_' . $data['orderId'],
			'customerToken'    => (string) $data['customerToken'],
			'tags'             => $tags,
		);

		return $input;
	}

	/**
	 * Build input array for item.purchased SDK envelope builder.
	 *
	 * @param array<string,mixed> $item           Normalized item data.
	 * @param array<string,mixed> $data           Normalized order data.
	 * @param string|null         $submitted_at    ISO timestamp.
	 * @param string              $customer_token  Opaque customer token.
	 * @param array<string,mixed> $settings        Plugin settings.
	 * @return array<string,mixed>
	 */
	private function build_item_purchased_input( array $item, array $data, $submitted_at, $customer_token, array $settings ) {
		return array(
			'organizationId' => (string) ( $settings['routing']['organizationId'] ?? '' ),
			'orderId'        => (string) $data['orderId'],
			'productId'      => (string) $item['productId'],
			'productName'    => (string) $item['productName'],
			'quantity'       => (int) $item['quantity'],
			'unitPrice'      => (float) $item['unitPrice'],
			'lineTotal'      => (float) $item['lineTotal'],
			'currency'       => (string) $data['currency'],
			'submittedAt'    => $submitted_at,
			'timestamp'      => $submitted_at,
			'customerToken'  => $customer_token,
			'tags'           => array(
				'provider'    => 'woocommerce',
				'currency'    => (string) $data['currency'],
				'orderId'     => (string) $data['orderId'],
				'productId'   => (string) $item['productId'],
				'productName' => (string) $item['productName'],
			),
		);
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

		$sdk     = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);
		$routing = (array) $settings['routing'];
		$source  = $sdk->formsProjectSourceId ?? ( $routing['projectSourceId'] ?? '' );
		$provider_key   = (string) $payload['provider'];
		$raw_form_id    = (string) ( $contract['externalFormId'] ?? $payload['formId'] );
		$scoped_form_id = $this->prefixed_form_id( $provider_key, $raw_form_id );
		$tags    = array_merge(
			array( 'formId' => $scoped_form_id ),
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
				'icon'            => $this->resolve_event_icon( 'forms', 'forms.submission.received', $contract ),
				'entityType'      => 'form_submission',
				'externalEntityId'=> (string) $payload['submissionId'],
				'externalEventId' => sprintf( 'forms:%s:%s', $scoped_form_id, (string) $payload['submissionId'] ),
				'submissionId'    => (string) $payload['submissionId'],
				'channel'         => 'forms',
				'event'           => 'forms.submission.received',
				'source'          => $this->event_source_for_provider( (string) $payload['provider'] ),
				'description'     => 'Form submission received',
				'timestamp'       => $this->resolve_iso8601( $payload['submittedAt'] ?? null ),
				'properties'      => $props,
				'tags'            => $tags,
			)
		);

		$this->sdk_dispatch_events( array( $envelope ), array(
			'provider'  => (string) $payload['provider'],
			'projectId' => $sdk->projectId ?? '',
			'entityIds' => array(
				'submissionId' => (string) $payload['submissionId'],
			),
		) );
	}

	/**
	 * Outbox worker hook.
	 *
	 * @return void
	 */
	public function run_outbox_worker() {
		$delivery = $this->get_delivery();
		if ( null === $delivery ) {
			return;
		}
		$delivery->flushOutbox( 100 );
	}

	/**
	 * Emit heartbeat event.
	 *
	 * @return void
	 */
	public function emit_system_heartbeat() {
		$settings = $this->options_repo->get_settings();
		try {
			$routing  = $this->build_channel_routing_resolver( $settings );
			$envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildSystemHeartbeatEvent(
				array(
					'organizationId' => (string) ( $settings['routing']['organizationId'] ?? '' ),
					'responseMs'     => 0,
					'tags'           => array( 'provider' => 'snapshot' ),
				),
				$routing
			);
		} catch ( \Throwable $e ) {
			error_log( '[Burrow system] Heartbeat skipped: ' . $e->getMessage() );
			return;
		}
		$this->sdk_enqueue_events( array( $envelope ), array(
			'provider'  => 'snapshot',
			'projectId' => (string) ( $settings['routing']['projectId'] ?? '' ),
		) );
	}

	/**
	 * Emit stack snapshot event.
	 *
	 * @return void
	 */
	public function emit_system_stack_snapshot() {
		$settings  = $this->options_repo->get_settings();
		$collector = new BurrowWP\Core\System\SystemMetricsCollector();
		$snapshot  = $collector->collect_stack_snapshot();
		try {
			$routing  = $this->build_channel_routing_resolver( $settings );
			$envelope = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildSystemStackSnapshotEvent(
				array(
					'organizationId'  => (string) ( $settings['routing']['organizationId'] ?? '' ),
					'cms'             => $snapshot['cms'] ?? array(),
					'runtime'         => $snapshot['runtime'] ?? array(),
					'plugins'         => $snapshot['plugins'] ?? array(),
					'updatesAvailable'=> $snapshot['updatesAvailable'] ?? 0,
					'totalPlugins'    => $snapshot['totalPlugins'] ?? 0,
					'tags'            => array(
						'cmsVersion'   => (string) get_bloginfo( 'version' ),
						'phpVersion'   => (string) phpversion(),
						'hasUpdates'   => ! empty( $snapshot['updatesAvailable'] ) ? 'true' : 'false',
						'updatesCount' => (string) (int) ( $snapshot['updatesAvailable'] ?? 0 ),
						'provider'     => 'snapshot',
					),
				),
				$routing
			);
		} catch ( \Throwable $e ) {
			error_log( '[Burrow system] Stack snapshot skipped: ' . $e->getMessage() );
			return;
		}
		$this->sdk_enqueue_events( array( $envelope ), array(
			'provider'  => 'snapshot',
			'projectId' => (string) ( $settings['routing']['projectId'] ?? '' ),
		) );
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
		$ingestion_key = isset( $settings['ingestion_key']['key'] ) ? trim( (string) $settings['ingestion_key']['key'] ) : '';
		if ( '' === $ingestion_key ) {
			$job['status']    = 'failed';
			$job['lastError'] = 'Missing ingestion key for backfill. Re-link the project.';
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
		$has_woo_key = in_array( 'woocommerce:orders', $keys, true );
		$ecommerce_source = isset( $settings['routing']['sourceIds']['ecommerce'] ) ? trim( (string) $settings['routing']['sourceIds']['ecommerce'] ) : '';
		if ( $has_woo_key && '' === $ecommerce_source ) {
			$job['status']    = 'failed';
			$job['lastError'] = 'Missing ecommerce project source id. Re-link project and confirm ecommerce source provisioning before Woo backfill.';
			$job['updatedAt'] = gmdate( 'c' );
			$settings['backfill'] = $job;
			$this->options_repo->save_settings( $settings );
			return;
		}

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

		$delivery = $this->get_delivery();
		if ( null === $delivery ) {
			$job['status']    = 'failed';
			$job['lastError'] = 'Plugin not configured for delivery.';
			$job['updatedAt'] = gmdate( 'c' );
			$settings['backfill'] = $job;
			$this->options_repo->save_settings( $settings );
			return;
		}

		$context = array(
			'projectId' => (string) ( $settings['routing']['projectId'] ?? '' ),
			'provider'  => 'wordpress-plugin',
		);

		$batch_result = $delivery->runBackfillBatch( $events, $context, $batch_size );

		$job['processedEvents'] = (int) ( $job['processedEvents'] ?? 0 ) + $batch_result['sent'];
		$job['backfillMetrics'] = array(
			'enqueued' => $batch_result['enqueued'],
			'deduped'  => $batch_result['deduped'],
			'sent'     => $batch_result['sent'],
			'retried'  => $batch_result['retried'],
			'failed'   => $batch_result['failed'],
		);

		if ( ! $batch_result['checkpointAdvanceSafe'] ) {
			$job['lastError'] = sprintf(
				'Batch has %d retrying records; checkpoint held. Will retry on next tick.',
				$batch_result['retried']
			);
		}

		if ( $batch_result['failed'] > 0 ) {
			$job['lastError'] = sprintf(
				'%d events failed (non-retryable) in this batch.',
				$batch_result['failed']
			);
		}

		$job['updatedAt']     = gmdate( 'c' );
		$settings['backfill'] = $job;
		$this->options_repo->save_settings( $settings );
	}

	/**
	 * Group events by their channel field.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function group_events_by_channel( array $events ) {
		$grouped = array();
		foreach ( $events as $event ) {
			$channel = isset( $event['channel'] ) ? trim( (string) $event['channel'] ) : 'forms';
			$grouped[ $channel ][] = $event;
		}
		return $grouped;
	}

	/**
	 * Resolve the correct projectSourceId for a given backfill channel.
	 *
	 * @param string              $channel Channel name.
	 * @param array<string,mixed> $settings Settings.
	 * @return string|null
	 */
	/**
	 * Build an SDK ChannelRoutingResolver from plugin settings.
	 *
	 * Provides the SDK with projectId + per-channel projectSourceIds so
	 * it can resolve routing for any channel (forms, ecommerce, system).
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return \Burrow\Sdk\Events\ChannelRoutingResolver
	 */
	private function build_channel_routing_resolver( array $settings ) {
		$sdk = \Burrow\Sdk\Client\BurrowClientState::fromArray(
			isset( $settings['sdk_state'] ) && is_array( $settings['sdk_state'] ) ? $settings['sdk_state'] : array()
		);

		$routing    = isset( $settings['routing'] ) && is_array( $settings['routing'] ) ? $settings['routing'] : array();
		$source_ids = isset( $routing['sourceIds'] ) && is_array( $routing['sourceIds'] ) ? $routing['sourceIds'] : array();
		$fallback   = $sdk->formsProjectSourceId ?? '';

		$channel_sources = array();
		foreach ( array( 'forms', 'ecommerce', 'system' ) as $ch ) {
			$val = isset( $source_ids[ $ch ] ) ? trim( (string) $source_ids[ $ch ] ) : '';
			$channel_sources[ $ch ] = '' !== $val ? $val : $fallback;
		}

		$state = new \Burrow\Sdk\Events\ChannelRoutingState(
			projectId: $sdk->projectId,
			projectSourceIds: $channel_sources,
			clientId: $sdk->clientId
		);

		return new \Burrow\Sdk\Events\ChannelRoutingResolver( $state );
	}

	/**
	 * Resolve source for a backfill channel using the SDK routing resolver.
	 */
	private function resolve_backfill_source_for_channel( $channel, array $settings ) {
		try {
			$resolver = $this->build_channel_routing_resolver( $settings );
			$resolved = $resolver->getRoutingForChannel( (string) $channel );
			return $resolved['projectSourceId'];
		} catch ( \Throwable $e ) {
			$routing = isset( $settings['routing'] ) && is_array( $settings['routing'] ) ? $settings['routing'] : array();
			return isset( $routing['projectSourceId'] ) ? (string) $routing['projectSourceId'] : null;
		}
	}

	/**
	 * @param array<string,mixed> $response
	 * @return string
	 */
	private function format_backfill_error( array $response ) {
		$error = isset( $response['error'] ) ? trim( (string) $response['error'] ) : '';
		$body  = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		$code  = '';
		$message = '';
		if ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$code    = isset( $body['error']['code'] ) ? trim( (string) $body['error']['code'] ) : '';
			$message = isset( $body['error']['message'] ) ? trim( (string) $body['error']['message'] ) : '';
		}
		if ( '' === $error ) {
			$status = isset( $response['status'] ) ? (int) $response['status'] : 0;
			$error  = $status > 0 ? 'HTTP ' . $status : 'Backfill request failed.';
		}
		if ( '' !== $code ) {
			$error .= ' [' . $code . ']';
		}
		if ( '' !== $message ) {
			$error .= ': ' . $message;
		}
		return $error;
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
		$scoped_form_id = isset( $contract['externalFormId'] ) ? (string) $contract['externalFormId'] : '';
		$wp_form_id     = self::raw_form_id( $provider, $scoped_form_id );
		$form_name = isset( $contract['formName'] ) ? (string) $contract['formName'] : $scoped_form_id;
		$entries  = array();
		$warning  = '';
		if ( 'gravity-forms' === $provider ) {
			$entries = $this->get_gravity_entries_for_backfill( $wp_form_id, $window_start, $window_end, $offset, $limit );
		} elseif ( 'ninja-forms' === $provider ) {
			$entries = $this->get_ninja_entries_for_backfill( $wp_form_id, $window_start, $window_end, $offset, $limit );
		} elseif ( 'fluent-forms' === $provider ) {
			$entries = $this->get_fluent_entries_for_backfill( $wp_form_id, $window_start, $window_end, $offset, $limit );
		} elseif ( 'contact-form-7' === $provider ) {
			$result  = $this->get_cf7_entries_for_backfill( $wp_form_id, $window_start, $window_end, $offset, $limit );
			$entries = isset( $result['entries'] ) && is_array( $result['entries'] ) ? $result['entries'] : array();
			$warning = isset( $result['warning'] ) ? (string) $result['warning'] : '';
		} elseif ( 'wpforms' === $provider ) {
			$entries = $this->get_wpforms_entries_for_backfill( $wp_form_id, $window_start, $window_end, $offset, $limit );
		} elseif ( 'formidable-forms' === $provider ) {
			$entries = $this->get_formidable_entries_for_backfill( $wp_form_id, $window_start, $window_end, $offset, $limit );
		} else {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => '' );
		}
		if ( empty( $entries ) ) {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => $warning );
		}
		$mapped_fields = isset( $contract['fieldMappings'] ) && is_array( $contract['fieldMappings'] ) ? $contract['fieldMappings'] : array();
		$count_only    = ! empty( $contract['countOnly'] );
		$has_explicit_count_only_setting = array_key_exists( 'countOnly', $contract );
		$form_source   = '' !== $routed_source ? $routed_source : $routed_default;
		$events        = array();
		foreach ( $entries as $entry ) {
			$raw_values = isset( $entry['rawValues'] ) && is_array( $entry['rawValues'] ) ? $entry['rawValues'] : ( is_array( $entry ) ? $entry : array() );
			$submission_id = isset( $entry['submissionId'] ) ? (string) $entry['submissionId'] : (string) ( $entry['id'] ?? '' );
			$submitted_at  = $this->resolve_submission_timestamp( $entry );
			$mapped     = $this->contract_mapper->map( $raw_values, $mapped_fields );
			$has_mapped_fields = ! empty( $mapped['tags'] ) || ! empty( $mapped['properties'] );
			if ( ! $count_only && ! $has_mapped_fields && $has_explicit_count_only_setting ) {
				// Mirror realtime behavior: explicit non-count contracts require selected mapped fields.
				continue;
			}
			$tags       = array( 'formId' => $scoped_form_id );
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
					'icon'            => $this->resolve_event_icon( 'forms', 'forms.submission.received', $contract ),
					'entityType'      => 'form_submission',
					'externalEntityId'=> $submission_id,
					'externalEventId' => sprintf( 'forms:%s:%s', $scoped_form_id, $submission_id ),
					'submissionId'    => $submission_id,
					'channel'         => 'forms',
					'event'           => 'forms.submission.received',
					'source'          => $this->event_source_for_provider( $provider ),
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
		try {
			$routing_resolver = $this->build_channel_routing_resolver( $settings );
		} catch ( \Throwable $e ) {
			return array( 'events' => array(), 'nextOffset' => $offset, 'done' => true, 'warning' => 'Missing ecommerce routing: ' . $e->getMessage() );
		}
		$woo_provider = new BurrowWP\Providers\Ecommerce\WooCommerceProvider();
		$events       = array();
		foreach ( $orders as $order ) {
			$data = $woo_provider->normalize_order( $order );
			if ( empty( $data ) ) {
				continue;
			}
			$submitted_at   = $this->resolve_order_timestamp( $order );
			$customer_token = (string) $data['customerToken'];

			$order_input = $this->build_order_placed_input( $data, $submitted_at, $settings );
			try {
				$events[] = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent(
					$order_input,
					$routing_resolver
				);
			} catch ( \Throwable $e ) {
				error_log( '[Burrow backfill] order.placed build failed for order ' . $data['orderId'] . ': ' . $e->getMessage() );
				continue;
			}
			foreach ( (array) $data['items'] as $item ) {
				$item_input = $this->build_item_purchased_input( $item, $data, $submitted_at, $customer_token, $settings );
				try {
					$events[] = \Burrow\Sdk\Events\CanonicalEnvelopeBuilders::buildEcommerceItemPurchasedEvent(
						$item_input,
						$routing_resolver
					);
				} catch ( \Throwable $e ) {
					error_log( '[Burrow backfill] item.purchased build failed: ' . $e->getMessage() );
					continue;
				}
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
	 * Load WPForms entries for backfill window.
	 *
	 * @param string $form_id     Form ID.
	 * @param string $window_start ISO timestamp.
	 * @param string $window_end   ISO timestamp.
	 * @param int    $offset       Offset cursor.
	 * @param int    $limit        Limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_wpforms_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit ) {
		if ( ! function_exists( 'wpforms' ) ) {
			return array();
		}
		$entry_handler = wpforms()->get( 'entry' );
		if ( ! is_object( $entry_handler ) || ! method_exists( $entry_handler, 'get_entries' ) ) {
			return array();
		}
		$start = $this->iso_to_mysql_datetime( $window_start );
		$end   = $this->iso_to_mysql_datetime( $window_end );
		$args = array(
			'form_id' => (int) $form_id,
			'number'  => max( 1, (int) $limit ),
			'offset'  => max( 0, (int) $offset ),
			'orderby' => 'entry_id',
			'order'   => 'ASC',
		);
		if ( '' !== $start ) {
			$args['date_query'] = array(
				array(
					'after'     => $start,
					'before'    => '' !== $end ? $end : gmdate( 'Y-m-d H:i:s' ),
					'inclusive' => true,
				),
			);
		}
		$entries = $entry_handler->get_entries( $args );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! is_object( $entry ) ) {
				continue;
			}
			$fields_raw = isset( $entry->fields ) ? json_decode( $entry->fields, true ) : array();
			$values = array();
			if ( is_array( $fields_raw ) ) {
				foreach ( $fields_raw as $fid => $field ) {
					$values[ (string) $fid ] = is_array( $field ) ? ( $field['value'] ?? '' ) : (string) $field;
				}
			}
			$out[] = array(
				'submissionId' => (string) $entry->entry_id,
				'rawValues'    => $values,
				'submittedAt'  => $this->resolve_iso8601( $entry->date ?? null ),
			);
		}
		return $out;
	}

	/**
	 * Load Formidable Forms entries for backfill window.
	 *
	 * @param string $form_id     Form ID.
	 * @param string $window_start ISO timestamp.
	 * @param string $window_end   ISO timestamp.
	 * @param int    $offset       Offset cursor.
	 * @param int    $limit        Limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_formidable_entries_for_backfill( $form_id, $window_start, $window_end, $offset, $limit ) {
		if ( ! class_exists( '\FrmEntry' ) || ! method_exists( '\FrmEntry', 'getAll' ) ) {
			return array();
		}
		$start = $this->iso_to_mysql_datetime( $window_start );
		$end   = $this->iso_to_mysql_datetime( $window_end );
		$where = array( 'form_id' => (int) $form_id );
		if ( '' !== $start ) {
			$where['created_at >'] = '' !== $start ? $start : '1970-01-01 00:00:00';
		}
		if ( '' !== $end ) {
			$where['created_at <'] = $end;
		}
		$entries = \FrmEntry::getAll(
			$where,
			' ORDER BY id ASC LIMIT ' . max( 1, (int) $limit ) . ' OFFSET ' . max( 0, (int) $offset ),
			'',
			true
		);
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! is_object( $entry ) ) {
				continue;
			}
			$values = isset( $entry->metas ) && is_array( $entry->metas ) ? $entry->metas : array();
			$out[] = array(
				'submissionId' => (string) $entry->id,
				'rawValues'    => $values,
				'submittedAt'  => $this->resolve_iso8601( $entry->created_at ?? null ),
			);
		}
		return $out;
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
	 * Resolve canonical source slug by provider/integration.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	/**
	 * Resolve event source via SDK.
	 *
	 * @param string $provider Provider slug.
	 * @param string $channel  Channel.
	 * @return string
	 */
	private function event_source_for_provider( $provider, $channel = 'forms' ) {
		return \Burrow\Sdk\Events\EventSourceResolver::resolveSourceForEvent( array(
			'provider' => (string) $provider,
			'channel'  => (string) $channel,
		) );
	}

	private static $form_id_prefixes = array(
		'gravity-forms'    => 'gf_',
		'fluent-forms'     => 'flf_',
		'contact-form-7'   => 'cf7_',
		'ninja-forms'      => 'nf_',
		'wpforms'          => 'wpf_',
		'formidable-forms' => 'frm_',
	);

	/**
	 * Build a provider-prefixed form ID for use in event tags, contracts, and idempotency keys.
	 * Ensures uniqueness across form plugins that may share numeric IDs.
	 *
	 * @param string $provider   Provider key (e.g. 'gravity-forms').
	 * @param string $raw_id     Raw numeric form ID.
	 * @return string            Prefixed form ID (e.g. 'gf_42').
	 */
	public static function prefixed_form_id( $provider, $raw_id ) {
		$prefix = isset( self::$form_id_prefixes[ $provider ] ) ? self::$form_id_prefixes[ $provider ] : '';
		return $prefix . (string) $raw_id;
	}

	/**
	 * Strip the provider prefix from a scoped form ID to recover the raw numeric ID
	 * needed for WordPress form-plugin API calls.
	 *
	 * @param string $provider     Provider key (e.g. 'gravity-forms').
	 * @param string $scoped_id    Prefixed form ID (e.g. 'gf_42').
	 * @return string              Raw numeric form ID (e.g. '42').
	 */
	public static function raw_form_id( $provider, $scoped_id ) {
		$prefix = isset( self::$form_id_prefixes[ $provider ] ) ? self::$form_id_prefixes[ $provider ] : '';
		if ( '' !== $prefix && 0 === strpos( (string) $scoped_id, $prefix ) ) {
			return substr( (string) $scoped_id, strlen( $prefix ) );
		}
		return (string) $scoped_id;
	}

	/**
	 * Resolve icon for event via SDK, with optional contract override.
	 *
	 * @param string              $channel    Channel.
	 * @param string              $event_name Event name.
	 * @param array<string,mixed> $contract   Optional contract metadata.
	 * @return string|null
	 */
	private function resolve_event_icon( $channel, $event_name, array $contract = array() ) {
		if ( isset( $contract['icon'] ) && is_string( $contract['icon'] ) && '' !== trim( $contract['icon'] ) ) {
			return trim( $contract['icon'] );
		}
		return \Burrow\Sdk\Events\EventIconResolver::resolveIconForEvent( (string) $channel, (string) $event_name );
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
