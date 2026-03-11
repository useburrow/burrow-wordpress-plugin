<?php
/**
 * Burrow event envelope factory.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Events;

class EnvelopeFactory {
	/**
	 * Build event payload.
	 *
	 * @param array<string,mixed> $routing Routing values.
	 * @param array<string,mixed> $event   Event details.
	 * @return array<string,mixed>
	 */
	public function build( array $routing, array $event ) {
		$project_source = isset( $event['projectSourceId'] ) ? $event['projectSourceId'] : ( $routing['projectSourceId'] ?? '' );
		$timestamp      = isset( $event['timestamp'] ) && is_scalar( $event['timestamp'] ) && '' !== trim( (string) $event['timestamp'] )
			? (string) $event['timestamp']
			: gmdate( 'c' );
		$payload        = array(
			'organizationId' => (string) ( $routing['organizationId'] ?? '' ),
			'clientId'       => (string) ( $routing['clientId'] ?? '' ),
			'projectId'      => (string) ( $routing['projectId'] ?? '' ),
			'integrationId'  => $this->nullable_string( $event['integrationId'] ?? null ),
			'projectSourceId'=> $this->nullable_string( $project_source ),
			'clientSourceId' => $this->nullable_string( $event['clientSourceId'] ?? null ),
			'icon'           => $this->nullable_string( $event['icon'] ?? null ),
			'isLifecycle'    => ! empty( $event['isLifecycle'] ),
			'entityType'     => $this->nullable_string( $event['entityType'] ?? null ),
			'externalEntityId' => $this->nullable_string( $event['externalEntityId'] ?? null ),
			'externalEventId'  => $this->nullable_string( $event['externalEventId'] ?? null ),
			'state'            => $this->nullable_string( $event['state'] ?? null ),
			'stateChangedAt'   => $this->nullable_string( $event['stateChangedAt'] ?? null ),
			'channel'        => (string) $event['channel'],
			'event'          => (string) $event['event'],
			'timestamp'      => $timestamp,
			'source'         => $this->nullable_string( $event['source'] ?? 'wordpress-plugin' ),
			'description'    => $this->nullable_string( $event['description'] ?? null ),
			'schemaVersion'  => '1',
			'properties'     => (array) ( $event['properties'] ?? array() ),
			'tags'           => (array) ( $event['tags'] ?? array() ),
		);

		return \Burrow\Sdk\Events\EventEnvelopeBuilder::build( $payload );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function nullable_string( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$clean = trim( (string) $value );
		return '' === $clean ? null : $clean;
	}
}
