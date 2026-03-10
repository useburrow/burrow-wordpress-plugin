<?php
/**
 * DDEV/portless-aware SDK transport wrapper.
 *
 * @package Burrow
 */

namespace BurrowWP\Infrastructure\Http;

class PortlessAwareTransport implements \Burrow\Sdk\Transport\ConcurrentHttpTransportInterface {
	/**
	 * @var \Burrow\Sdk\Transport\ConcurrentHttpTransportInterface
	 */
	private $delegate;

	public function __construct( \Burrow\Sdk\Transport\ConcurrentHttpTransportInterface $delegate ) {
		$this->delegate = $delegate;
	}

	/**
	 * @param string               $url URL.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $payload Payload.
	 * @return \Burrow\Sdk\Transport\HttpResponse
	 */
	public function post( string $url, array $headers, array $payload ): \Burrow\Sdk\Transport\HttpResponse {
		$normalized = $this->normalize_request( $url, $headers );
		return $this->delegate->post( $normalized['url'], $normalized['headers'], $payload );
	}

	/**
	 * @param list<array{url:string,headers:array<string,string>,payload:array<string,mixed>}> $requests
	 * @return list<\Burrow\Sdk\Transport\HttpResponse>
	 */
	public function postConcurrent( array $requests ): array {
		$mapped = array();
		foreach ( $requests as $request ) {
			$normalized = $this->normalize_request(
				(string) ( $request['url'] ?? '' ),
				isset( $request['headers'] ) && is_array( $request['headers'] ) ? $request['headers'] : array()
			);
			$mapped[] = array(
				'url'     => $normalized['url'],
				'headers' => $normalized['headers'],
				'payload' => isset( $request['payload'] ) && is_array( $request['payload'] ) ? $request['payload'] : array(),
			);
		}

		return $this->delegate->postConcurrent( $mapped );
	}

	/**
	 * @param string               $url URL.
	 * @param array<string,string> $headers Headers.
	 * @return array{url:string,headers:array<string,string>}
	 */
	private function normalize_request( $url, array $headers ) {
		$url     = (string) $url;
		$is_ddev = 'true' === getenv( 'IS_DDEV_PROJECT' );
		if ( ! $is_ddev ) {
			return array(
				'url'     => $url,
				'headers' => $headers,
			);
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return array(
				'url'     => $url,
				'headers' => $headers,
			);
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( 1355 !== $port ) {
			return array(
				'url'     => $url,
				'headers' => $headers,
			);
		}

		if ( str_ends_with( $host, '.localhost' ) && ! isset( $headers['Host'] ) ) {
			$raw_host        = (string) $parts['host'];
			$headers['Host'] = $raw_host . ':' . $port;
			$url             = str_replace( $raw_host . ':' . $port, 'host.docker.internal:' . $port, $url );
		}

		if ( 'host.docker.internal' === $host && ! isset( $headers['Host'] ) ) {
			$headers['Host'] = defined( 'BURROW_PORTLESS_HOST' ) ? (string) BURROW_PORTLESS_HOST : 'burrow.localhost:1355';
		}

		return array(
			'url'     => $url,
			'headers' => $headers,
		);
	}
}

