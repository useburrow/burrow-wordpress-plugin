<?php
/**
 * Burrow API client powered by Burrow PHP SDK.
 *
 * @package Burrow
 */

namespace BurrowWP\Infrastructure\Http;

class BurrowApiClient {
	/**
	 * @var string
	 */
	private $base_url;

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var array<string,mixed>
	 */
	private $ingestion_key;

	/**
	 * @var int
	 */
	private $timeout;

	/**
	 * @var \Burrow\Sdk\Client\BurrowClient|null
	 */
	private $sdk_client_instance;

	/**
	 * @var \Burrow\Sdk\Client\BurrowClient|null
	 */
	private $dispatch_sdk_client_instance;

	/**
	 * @var \Burrow\Sdk\Transport\ConcurrentHttpTransportInterface|null
	 */
	private $sdk_transport_instance;

	public function __construct( $base_url, $api_key, $timeout = 5, array $ingestion_key = array() ) {
		$this->base_url = rtrim( (string) $base_url, '/' );
		$this->api_key  = (string) $api_key;
		$this->timeout  = max( 1, (int) $timeout );
		$this->ingestion_key = $ingestion_key;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function discover( array $payload ) {
		try {
			$request = new \Burrow\Sdk\Contracts\OnboardingDiscoveryRequest(
				isset( $payload['site'] ) && is_array( $payload['site'] ) ? $payload['site'] : array(),
				isset( $payload['capabilities'] ) && is_array( $payload['capabilities'] ) ? $payload['capabilities'] : array()
			);
			$response = $this->sdk_client()->discover( $request );
			return $this->format_sdk_response( $response );
		} catch ( \Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException $e ) {
			return $this->format_status_exception( $e );
		} catch ( \Burrow\Sdk\Transport\Exception\TransportException $e ) {
			return $this->format_transport_exception( $e );
		} catch ( \Throwable $e ) {
			return $this->format_unexpected_exception( $e );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function link( array $payload ) {
		try {
			$request = new \Burrow\Sdk\Contracts\OnboardingLinkRequest(
				isset( $payload['site'] ) && is_array( $payload['site'] ) ? $payload['site'] : array(),
				isset( $payload['selection'] ) && is_array( $payload['selection'] ) ? $payload['selection'] : array()
			);
			$response = $this->sdk_client()->link( $request );
			return $this->format_sdk_response( $response );
		} catch ( \Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException $e ) {
			return $this->format_status_exception( $e );
		} catch ( \Burrow\Sdk\Transport\Exception\TransportException $e ) {
			return $this->format_transport_exception( $e );
		} catch ( \Throwable $e ) {
			return $this->format_unexpected_exception( $e );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function submit_forms_contract( array $payload ) {
		try {
			$request  = new \Burrow\Sdk\Contracts\FormsContractSubmissionRequest( $payload );
			$response = $this->sdk_client()->submitFormsContract( $request );
			return array(
				'ok'           => true,
				'status'       => 200,
				'body'         => $this->forms_contracts_response_to_array( $response ),
				'error'        => '',
				'is_retryable' => false,
			);
		} catch ( \Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException $e ) {
			return $this->format_status_exception( $e );
		} catch ( \Burrow\Sdk\Transport\Exception\TransportException $e ) {
			return $this->format_transport_exception( $e );
		} catch ( \Throwable $e ) {
			return $this->format_unexpected_exception( $e );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function fetch_forms_contract( array $payload ) {
		try {
			$project_id = isset( $payload['routing']['projectId'] ) ? (string) $payload['routing']['projectId'] : '';
			$platform   = isset( $payload['platform'] ) ? (string) $payload['platform'] : 'wordpress';
			$response   = $this->sdk_client()->fetchFormsContracts( $project_id, $platform );
			return array(
				'ok'           => true,
				'status'       => 200,
				'body'         => $this->forms_contracts_response_to_array( $response ),
				'error'        => '',
				'is_retryable' => false,
			);
		} catch ( \Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException $e ) {
			return $this->format_status_exception( $e );
		} catch ( \Burrow\Sdk\Transport\Exception\TransportException $e ) {
			return $this->format_transport_exception( $e );
		} catch ( \Throwable $e ) {
			return $this->format_unexpected_exception( $e );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function publish_event( array $payload ) {
		try {
			$payload  = $this->ensure_event_project_scope( $payload );
			$response = $this->dispatch_sdk_client()->publishEvent( $payload );
			return $this->format_sdk_response( $response );
		} catch ( \Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException $e ) {
			return $this->format_status_exception( $e );
		} catch ( \Burrow\Sdk\Transport\Exception\TransportException $e ) {
			return $this->format_transport_exception( $e );
		} catch ( \Throwable $e ) {
			return $this->format_unexpected_exception( $e );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function backfill_events( array $payload ) {
		try {
			$payload = $this->ensure_backfill_project_scope( $payload );
			$request = new \Burrow\Sdk\Contracts\BackfillEventsRequest(
				isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array(),
				$this->backfill_window_from_payload( $payload )
			);
			$options = new \Burrow\Sdk\Client\BackfillOptions(
				max( 1, min( 100, (int) ( $payload['batchSize'] ?? 100 ) ) ),
				max( 1, (int) ( $payload['perKeyConcurrency'] ?? 4 ) )
			);

			$result = $this->dispatch_sdk_client()->backfillEvents( $request, $options );
			return array(
				'ok'           => true,
				'status'       => 200,
				'body'         => array(
					'accepted'                => $result->accepted,
					'rejected'                => $result->rejected,
					'acceptedCount'           => (int) $result->acceptedCount,
					'rejectedCount'           => (int) $result->rejectedCount,
					'requestedCount'          => (int) $result->requestedCount,
					'validationRejectedCount' => (int) $result->validationRejectedCount,
					'validationRejections'    => $result->validationRejections,
					'backfill'                => array( 'cursor' => $result->latestCursor ),
				),
				'error'        => '',
				'is_retryable' => false,
			);
		} catch ( \Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException $e ) {
			return $this->format_status_exception( $e );
		} catch ( \Burrow\Sdk\Transport\Exception\TransportException $e ) {
			return $this->format_transport_exception( $e );
		} catch ( \Throwable $e ) {
			return $this->format_unexpected_exception( $e );
		}
	}

	/**
	 * @return \Burrow\Sdk\Client\BurrowClient
	 */
	private function sdk_client() {
		if ( null !== $this->sdk_client_instance ) {
			return $this->sdk_client_instance;
		}
		if ( ! class_exists( '\Burrow\Sdk\Client\BurrowClient' ) ) {
			throw new \RuntimeException( 'Burrow SDK is not installed. Run composer install in the plugin directory.' );
		}

		$this->sdk_client_instance = new \Burrow\Sdk\Client\BurrowClient(
			$this->base_url,
			$this->api_key,
			$this->sdk_transport()
		);

		return $this->sdk_client_instance;
	}

	/**
	 * @return \Burrow\Sdk\Client\BurrowClient
	 */
	private function dispatch_sdk_client() {
		if ( null !== $this->dispatch_sdk_client_instance ) {
			return $this->dispatch_sdk_client_instance;
		}

		$dispatch_key = \BurrowWP\Core\Auth\DispatchCredentials::resolve_dispatch_api_key( $this->api_key, $this->ingestion_key );
		$this->dispatch_sdk_client_instance = new \Burrow\Sdk\Client\BurrowClient(
			$this->base_url,
			$dispatch_key,
			$this->sdk_transport()
		);

		return $this->dispatch_sdk_client_instance;
	}

	/**
	 * @return \Burrow\Sdk\Transport\ConcurrentHttpTransportInterface
	 */
	private function sdk_transport() {
		if ( null !== $this->sdk_transport_instance ) {
			return $this->sdk_transport_instance;
		}

		$delegate = new \Burrow\Sdk\Transport\CurlHttpTransport( $this->timeout );
		$this->sdk_transport_instance = new PortlessAwareTransport( $delegate );

		return $this->sdk_transport_instance;
	}

	/**
	 * @param \Burrow\Sdk\Transport\HttpResponse $response
	 * @return array<string,mixed>
	 */
	private function format_sdk_response( $response ) {
		$status = (int) $response->status;
		$body   = is_array( $response->body ) ? $response->body : array();
		return array(
			'ok'           => $status >= 200 && $status < 300,
			'status'       => $status,
			'body'         => $body,
			'error'        => $status >= 400 ? 'HTTP ' . $status : '',
			'is_retryable' => 429 === $status || $status >= 500,
		);
	}

	/**
	 * @param \Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException $e
	 * @return array<string,mixed>
	 */
	private function format_status_exception( $e ) {
		$status = (int) $e->response->status;
		$body   = is_array( $e->response->body ) ? $e->response->body : array();
		$error  = 'HTTP ' . $status;
		if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
			$error .= ': ' . $body['error'];
		}
		return array(
			'ok'           => false,
			'status'       => $status,
			'body'         => $body,
			'error'        => $error,
			'is_retryable' => $e->isRetryable(),
		);
	}

	/**
	 * @param \Throwable $e
	 * @return array<string,mixed>
	 */
	private function format_transport_exception( $e ) {
		return array(
			'ok'           => false,
			'status'       => 0,
			'body'         => array(),
			'error'        => $e->getMessage(),
			'is_retryable' => true,
		);
	}

	/**
	 * @param \Throwable $e
	 * @return array<string,mixed>
	 */
	private function format_unexpected_exception( $e ) {
		return array(
			'ok'           => false,
			'status'       => 0,
			'body'         => array(),
			'error'        => $e->getMessage(),
			'is_retryable' => false,
		);
	}

	/**
	 * @param \Burrow\Sdk\Contracts\FormsContractsResponse $response
	 * @return array<string,mixed>
	 */
	private function forms_contracts_response_to_array( $response ) {
		$contract_mappings = array();
		foreach ( (array) $response->contractMappings as $mapping ) {
			$contract_mappings[] = array(
				'contractId'     => (string) $mapping->contractId,
				'externalFormId' => null !== $mapping->externalFormId ? (string) $mapping->externalFormId : null,
				'formHandle'     => null !== $mapping->formHandle ? (string) $mapping->formHandle : null,
				'formName'       => null !== $mapping->formName ? (string) $mapping->formName : null,
				'enabled'        => (bool) $mapping->enabled,
				'updatedAt'      => null !== $mapping->updatedAt ? (string) $mapping->updatedAt : null,
				'saved'          => (bool) $mapping->saved,
			);
		}

		return array(
			'projectSourceId' => null !== $response->projectSourceId ? (string) $response->projectSourceId : null,
			'contractsVersion'=> null !== $response->contractsVersion ? (string) $response->contractsVersion : null,
			'contractMappings'=> $contract_mappings,
			'formsContracts'  => (array) $response->formsContracts,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return \Burrow\Sdk\Contracts\BackfillWindow
	 */
	private function backfill_window_from_payload( array $payload ) {
		$cursor = null;
		if ( array_key_exists( 'cursor', $payload ) ) {
			$raw_cursor = $payload['cursor'];
			if ( is_string( $raw_cursor ) ) {
				$cursor = $raw_cursor;
			} elseif ( is_array( $raw_cursor ) ) {
				$encoded = wp_json_encode( $raw_cursor );
				$cursor  = false !== $encoded ? $encoded : null;
			}
		}

		return new \Burrow\Sdk\Contracts\BackfillWindow(
			isset( $payload['windowStart'] ) ? (string) $payload['windowStart'] : gmdate( 'c' ),
			$cursor,
			isset( $payload['windowEnd'] ) ? (string) $payload['windowEnd'] : null,
			isset( $payload['source'] ) ? (string) $payload['source'] : null
		);
	}

	/**
	 * Ensure dispatch payload includes linked project id.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return array<string,mixed>
	 */
	private function ensure_event_project_scope( array $event ) {
		$current_project_id = isset( $event['projectId'] ) ? trim( (string) $event['projectId'] ) : '';
		$linked_project_id  = \BurrowWP\Core\Auth\DispatchCredentials::resolve_dispatch_project_id( $current_project_id, $this->ingestion_key );
		if ( '' !== $linked_project_id ) {
			$event['projectId'] = $linked_project_id;
		}
		return $event;
	}

	/**
	 * Ensure all backfill events include linked project id.
	 *
	 * @param array<string,mixed> $payload Backfill payload.
	 * @return array<string,mixed>
	 */
	private function ensure_backfill_project_scope( array $payload ) {
		$events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();
		$updated = array();
		foreach ( $events as $event ) {
			$updated[] = is_array( $event ) ? $this->ensure_event_project_scope( $event ) : $event;
		}
		$payload['events'] = $updated;

		$defaults = isset( $payload['routingDefaults'] ) && is_array( $payload['routingDefaults'] ) ? $payload['routingDefaults'] : array();
		$current_project_id = isset( $defaults['projectId'] ) ? trim( (string) $defaults['projectId'] ) : '';
		$linked_project_id  = \BurrowWP\Core\Auth\DispatchCredentials::resolve_dispatch_project_id( $current_project_id, $this->ingestion_key );
		if ( '' !== $linked_project_id ) {
			$defaults['projectId'] = $linked_project_id;
			$payload['routingDefaults'] = $defaults;
		}
		return $payload;
	}
}
