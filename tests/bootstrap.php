<?php

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action() {
		// No-op for unit tests.
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require_once dirname( __DIR__ ) . '/src/Core/Events/ContractFieldMapper.php';
require_once dirname( __DIR__ ) . '/src/Core/Events/EnvelopeFactory.php';
require_once dirname( __DIR__ ) . '/src/Core/Auth/DispatchCredentials.php';
require_once dirname( __DIR__ ) . '/src/Core/Onboarding/LinkStateManager.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/WpOutboxRepository.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Http/BurrowApiClient.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Http/PortlessAwareTransport.php';
require_once dirname( __DIR__ ) . '/src/Providers/Forms/FormsProviderInterface.php';
require_once dirname( __DIR__ ) . '/src/Providers/Forms/NinjaFormsProvider.php';
require_once dirname( __DIR__ ) . '/src/Providers/Forms/FluentFormsProvider.php';
