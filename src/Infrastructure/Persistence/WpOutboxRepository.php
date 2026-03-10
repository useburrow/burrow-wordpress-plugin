<?php
/**
 * SQL-backed outbox repository.
 *
 * @package Burrow
 */

namespace BurrowWP\Infrastructure\Persistence;

class WpOutboxRepository {
	/**
	 * DB table.
	 *
	 * @var string
	 */
	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'burrow_outbox';
	}

	/**
	 * Enqueue event payload with deterministic key.
	 *
	 * @param string               $event_key Event key.
	 * @param string               $channel   Channel.
	 * @param string               $event     Event name.
	 * @param array<string, mixed> $payload   Payload.
	 * @param int                  $max       Max attempts.
	 * @return int|false
	 */
	public function enqueue( $event_key, $channel, $event, array $payload, $max = 6 ) {
		global $wpdb;

		$now     = current_time( 'mysql', true );
		$payload = wp_json_encode( $payload );

		$inserted = $wpdb->replace(
			$this->table_name,
			array(
				'event_key'       => $event_key,
				'channel'         => $channel,
				'event_name'      => $event,
				'payload_json'    => $payload,
				'status'          => 'pending',
				'attempt_count'   => 0,
				'max_attempts'    => max( 1, (int) $max ),
				'last_error'      => null,
				'next_attempt_at' => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Fetch ready records.
	 *
	 * @param int $limit Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function dequeue_ready( $limit = 100 ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table_name}
			WHERE status IN ('pending','retrying')
			AND next_attempt_at <= %s
			ORDER BY created_at ASC
			LIMIT %d",
			$now,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Mark record as sent.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function mark_sent( $id ) {
		return $this->update_status( (int) $id, 'sent', null, null );
	}

	/**
	 * Mark record for retry.
	 *
	 * @param int    $id           Record ID.
	 * @param int    $attempt      Attempt count.
	 * @param string $next_attempt Next attempt timestamp.
	 * @param string $last_error   Last error.
	 * @return bool
	 */
	public function mark_retrying( $id, $attempt, $next_attempt, $last_error ) {
		return $this->update_status( (int) $id, 'retrying', (string) $next_attempt, (string) $last_error, (int) $attempt );
	}

	/**
	 * Mark record failed.
	 *
	 * @param int    $id         Record ID.
	 * @param int    $attempt    Attempt count.
	 * @param string $last_error Last error.
	 * @return bool
	 */
	public function mark_failed( $id, $attempt, $last_error ) {
		return $this->update_status( (int) $id, 'failed', null, (string) $last_error, (int) $attempt );
	}

	/**
	 * Retry a failed record.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function replay_failed( $id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->update(
			$this->table_name,
			array(
				'status'          => 'pending',
				'attempt_count'   => 0,
				'last_error'      => null,
				'next_attempt_at' => $now,
				'updated_at'      => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $ok;
	}

	/**
	 * Cleanup sent/failed records.
	 *
	 * @param int $days Retention days.
	 * @return int
	 */
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
		return (int) $wpdb->query( $sql );
	}

	/**
	 * Queue counts for admin UI.
	 *
	 * @return array<string,int>
	 */
	public function get_status_counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT status, COUNT(*) as total FROM {$this->table_name} GROUP BY status";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows   = (array) $wpdb->get_results( $sql, ARRAY_A );
		$counts = array(
			'pending'  => 0,
			'retrying' => 0,
			'sent'     => 0,
			'failed'   => 0,
		);
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Channel + event counts.
	 *
	 * @return array<string,int>
	 */
	public function get_event_counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT event_name, COUNT(*) as total FROM {$this->table_name} WHERE status IN ('pending','retrying') GROUP BY event_name";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = (array) $wpdb->get_results( $sql, ARRAY_A );
		$items = array();
		foreach ( $rows as $row ) {
			$items[ $row['event_name'] ] = (int) $row['total'];
		}
		return $items;
	}

	/**
	 * Recent failed records for Operations UI.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
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

	/**
	 * Outbox records with optional filters.
	 *
	 * @param string $status Optional status filter.
	 * @param int    $limit  Max rows.
	 * @param int    $offset Row offset.
	 * @param string $search Search query.
	 * @return array<int,array<string,mixed>>
	 */
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
			$where[] = '(event_name LIKE %s OR event_key LIKE %s OR last_error LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT id, event_key, channel, event_name, status, attempt_count, max_attempts, last_error, next_attempt_at, created_at, updated_at, sent_at
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

	/**
	 * Count records for outbox filter.
	 *
	 * @param string $status Optional status filter.
	 * @param string $search Search query.
	 * @return int
	 */
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
			$where[] = '(event_name LIKE %s OR event_key LIKE %s OR last_error LIKE %s)';
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

	/**
	 * Update record status.
	 *
	 * @param int         $id           Record ID.
	 * @param string      $status       Status.
	 * @param string|null $next_attempt Next attempt.
	 * @param string|null $last_error   Last error.
	 * @param int|null    $attempt      Attempt count.
	 * @return bool
	 */
	private function update_status( $id, $status, $next_attempt = null, $last_error = null, $attempt = null ) {
		global $wpdb;
		$data = array(
			'status'     => $status,
			'last_error' => $last_error,
			'updated_at' => current_time( 'mysql', true ),
		);
		$fmt  = array( '%s', '%s', '%s' );

		if ( null !== $attempt ) {
			$data['attempt_count'] = (int) $attempt;
			$fmt[]                 = '%d';
		}
		if ( null !== $next_attempt ) {
			$data['next_attempt_at'] = $next_attempt;
			$fmt[]                   = '%s';
		}
		if ( 'sent' === $status ) {
			$data['sent_at'] = current_time( 'mysql', true );
			$fmt[]           = '%s';
		}

		$ok = $wpdb->update( $this->table_name, $data, array( 'id' => $id ), $fmt, array( '%d' ) );
		return false !== $ok;
	}
}
