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
		self::create_event_queue_table();
		update_option( 'burrow_version', BURROW_VERSION );
	}

	/**
	 * Create the event queue database table.
	 */
	private static function create_event_queue_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'burrow_event_queue';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(100) NOT NULL,
			event_data LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			synced_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
