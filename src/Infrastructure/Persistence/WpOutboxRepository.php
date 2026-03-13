<?php
/**
 * WordPress-backed outbox store implementing SDK OutboxStoreInterface.
 *
 * @package Burrow
 */

namespace BurrowWP\Infrastructure\Persistence;

use Burrow\Sdk\Outbox\OutboxEnqueueResult;
use Burrow\Sdk\Outbox\OutboxRecord;
use Burrow\Sdk\Outbox\OutboxStats;
use Burrow\Sdk\Outbox\OutboxStatus;
use Burrow\Sdk\Outbox\OutboxStoreInterface;
use DateTimeImmutable;

class WpOutboxRepository implements OutboxStoreInterface {
	/** @var string */
	private $table_name;
	/** @var string */
	private $sent_table;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'burrow_outbox';
		$this->sent_table = $wpdb->prefix . 'burrow_outbox_sent';
	}

	/** @param array<string,mixed> $payload */
	public function enqueue( string $eventKey, array $payload ): OutboxEnqueueResult {
		global $wpdb;

		if ( $this->isEventSent( $eventKey ) ) {
			return new OutboxEnqueueResult( deduped: true, eventKey: $eventKey );
		}

		$now = current_time( 'mysql', true );
		$json = wp_json_encode( $payload );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table_name} WHERE event_key = %s LIMIT 1",
			$eventKey
		) );
		if ( null !== $existing ) {
			return new OutboxEnqueueResult( deduped: true, eventKey: $eventKey );
		}

		$channel    = isset( $payload['channel'] ) ? substr( trim( (string) $payload['channel'] ), 0, 32 ) : '';
		$event_name = isset( $payload['event'] ) ? substr( trim( (string) $payload['event'] ), 0, 128 ) : '';

		$wpdb->insert(
			$this->table_name,
			array(
				'event_key'       => $eventKey,
				'channel'         => $channel,
				'event_name'      => $event_name,
				'payload_json'    => $json,
				'status'          => OutboxStatus::PENDING,
				'attempt_count'   => 0,
				'last_error'      => null,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return new OutboxEnqueueResult( deduped: false, eventKey: $eventKey );
	}

	/** @return list<OutboxRecord> */
	public function pullPending( int $limit = 50 ): array {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table_name}
			WHERE status IN (%s, %s)
			AND next_attempt_at <= %s
			ORDER BY created_at ASC
			LIMIT %d",
			OutboxStatus::PENDING,
			OutboxStatus::RETRYING,
			$now,
			$limit
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( $this, 'hydrate_record' ), $rows );
	}

	public function markSent( string $id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$event_key = $wpdb->get_var( $wpdb->prepare(
			"SELECT event_key FROM {$this->table_name} WHERE id = %d",
			(int) $id
		) );

		$wpdb->update(
			$this->table_name,
			array(
				'status'     => OutboxStatus::SENT,
				'updated_at' => $now,
				'sent_at'    => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( null !== $event_key && '' !== $event_key ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->sent_table} WHERE event_key = %s",
				$event_key
			) );
			$wpdb->insert(
				$this->sent_table,
				array( 'event_key' => $event_key, 'sent_at' => $now ),
				array( '%s', '%s' )
			);
		}
	}

	public function markRetrying( string $id, string $error, int $delaySeconds = 0 ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$next = $delaySeconds > 0
			? gmdate( 'Y-m-d H:i:s', time() + $delaySeconds )
			: $now;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table_name}
			SET status = %s, last_error = %s, attempt_count = attempt_count + 1,
			    next_attempt_at = %s, updated_at = %s
			WHERE id = %d",
			OutboxStatus::RETRYING,
			$error,
			$next,
			$now,
			(int) $id
		) );
	}

	public function markFailed( string $id, string $error ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table_name}
			SET status = %s, last_error = %s, attempt_count = attempt_count + 1,
			    updated_at = %s
			WHERE id = %d",
			OutboxStatus::FAILED,
			$error,
			$now,
			(int) $id
		) );
	}

	public function isEventSent( string $eventKey ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$this->sent_table} WHERE event_key = %s LIMIT 1",
			$eventKey
		) );
		return null !== $found;
	}

	public function getStats(): OutboxStats {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			"SELECT status, COUNT(*) as total FROM {$this->table_name} GROUP BY status",
			ARRAY_A
		);
		$counts = array( 'pending' => 0, 'retrying' => 0, 'sent' => 0, 'failed' => 0 );
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ledger = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->sent_table}" );
		return new OutboxStats(
			pending: $counts['pending'],
			retrying: $counts['retrying'],
			sent: $counts['sent'],
			failed: $counts['failed'],
			sentLedgerCount: $ledger
		);
	}

	// ──────────────────────────────────────────────
	// Plugin-specific helpers (not part of SDK interface)
	// ──────────────────────────────────────────────

	public function replay_failed( $id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return false !== $wpdb->update(
			$this->table_name,
			array(
				'status'          => OutboxStatus::PENDING,
				'attempt_count'   => 0,
				'last_error'      => null,
				'next_attempt_at' => $now,
				'updated_at'      => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Force a pending or retrying record to be eligible for the next flush.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function retry_now( $id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return false !== $wpdb->update(
			$this->table_name,
			array(
				'next_attempt_at' => $now,
				'updated_at'      => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a single outbox record.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function delete_record( $id ) {
		global $wpdb;
		return false !== $wpdb->delete(
			$this->table_name,
			array( 'id' => (int) $id ),
			array( '%d' )
		);
	}

	public function cleanup( $days = 14 ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$ts   = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"DELETE FROM {$this->table_name}
			WHERE status IN ('sent','failed') AND updated_at < %s",
			$ts
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = (int) $wpdb->query( $sql );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->sent_table} WHERE sent_at < %s",
			$ts
		) );

		return $deleted;
	}

	/** @return array<string,int> */
	public function get_status_counts() {
		$stats = $this->getStats();
		return array(
			'pending'  => $stats->pending,
			'retrying' => $stats->retrying,
			'sent'     => $stats->sent,
			'failed'   => $stats->failed,
		);
	}

	public function get_records( $status = '', $limit = 200, $offset = 0, $search = '' ) {
		global $wpdb;
		$limit            = max( 1, min( 500, (int) $limit ) );
		$offset           = max( 0, (int) $offset );
		$allowed_statuses = array( 'pending', 'retrying', 'sent', 'failed' );
		$status           = sanitize_key( (string) $status );
		$search           = sanitize_text_field( (string) $search );

		$where = array();
		$args  = array();
		if ( in_array( $status, $allowed_statuses, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(event_name LIKE %s OR event_key LIKE %s OR channel LIKE %s OR payload_json LIKE %s OR last_error LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT id, event_key, channel, event_name, payload_json, status, attempt_count, max_attempts, last_error, next_attempt_at, created_at, updated_at, sent_at
			FROM {$this->table_name}
			{$where_sql}
			ORDER BY id DESC
			LIMIT %d OFFSET %d";
		$args[] = $limit;
		$args[] = $offset;
		$prepared_sql = empty( $args ) ? $sql : $wpdb->prepare( $sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $prepared_sql, ARRAY_A );
	}

	public function count_records( $status = '', $search = '' ) {
		global $wpdb;
		$allowed_statuses = array( 'pending', 'retrying', 'sent', 'failed' );
		$status           = sanitize_key( (string) $status );
		$search           = sanitize_text_field( (string) $search );

		$where = array();
		$args  = array();
		if ( in_array( $status, $allowed_statuses, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(event_name LIKE %s OR event_key LIKE %s OR channel LIKE %s OR payload_json LIKE %s OR last_error LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$this->table_name} {$where_sql}";
		$prepared_sql = empty( $args ) ? $sql : $wpdb->prepare( $sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $prepared_sql );
	}

	public function get_failed_records( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT id, event_key, channel, event_name, attempt_count, max_attempts, last_error, created_at, updated_at
			FROM {$this->table_name}
			WHERE status = 'failed'
			ORDER BY updated_at DESC
			LIMIT %d",
			$limit
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/** @return OutboxRecord */
	private function hydrate_record( array $row ): OutboxRecord {
		$payload = json_decode( (string) ( $row['payload_json'] ?? '{}' ), true );
		return new OutboxRecord(
			id: (string) $row['id'],
			eventKey: (string) $row['event_key'],
			status: (string) $row['status'],
			attemptCount: (int) $row['attempt_count'],
			payload: is_array( $payload ) ? $payload : array(),
			lastError: isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			createdAt: new DateTimeImmutable( (string) ( $row['created_at'] ?? 'now' ) ),
			updatedAt: new DateTimeImmutable( (string) ( $row['updated_at'] ?? 'now' ) ),
			nextAttemptAt: ! empty( $row['next_attempt_at'] ) ? new DateTimeImmutable( (string) $row['next_attempt_at'] ) : null,
			sentAt: ! empty( $row['sent_at'] ) ? new DateTimeImmutable( (string) $row['sent_at'] ) : null
		);
	}
}
