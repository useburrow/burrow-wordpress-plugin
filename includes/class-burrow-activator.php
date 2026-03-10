<?php
/**
 * Fired during plugin activation.
 *
 * Defines all code necessary to run during the plugin's activation.
 *
 * @package    Burrow
 * @subpackage Burrow/includes
 */
class Burrow_Activator {

	/**
	 * Run activation tasks.
	 *
	 * Creates the database table for the event queue and stores the plugin
	 * version in the options table.
	 */
	public static function activate() {
		self::create_outbox_table();
		self::schedule_cron_jobs();
		update_option( 'burrow_version', BURROW_VERSION );
	}

	/**
	 * Create the event queue database table.
	 */
	private static function create_outbox_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'burrow_outbox';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_key VARCHAR(191) NOT NULL,
			channel VARCHAR(32) NOT NULL,
			event_name VARCHAR(128) NOT NULL,
			payload_json LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts INT UNSIGNED NOT NULL DEFAULT 6,
			last_error TEXT NULL,
			next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			sent_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_key (event_key),
			KEY status_next_attempt (status, next_attempt_at),
			KEY channel_status (channel, status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schedule plugin cron hooks.
	 *
	 * @return void
	 */
	private static function schedule_cron_jobs() {
		if ( ! wp_next_scheduled( 'burrow_outbox_worker' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'burrow_outbox_worker' );
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
}
