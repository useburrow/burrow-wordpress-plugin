<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Burrow
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect uninstall cleanup preference.
$settings = get_option( 'burrow_settings', array() );
$cleanup  = isset( $settings['cleanup_on_uninstall'] ) ? (bool) $settings['cleanup_on_uninstall'] : false;

if ( $cleanup ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'burrow_outbox';
	$sent_table = $wpdb->prefix . 'burrow_outbox_sent';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$sent_table}" );
}

// Remove plugin options from the database.
delete_option( 'burrow_settings' );
delete_option( 'burrow_version' );

// Remove any scheduled events.
wp_clear_scheduled_hook( 'burrow_outbox_worker' );
wp_clear_scheduled_hook( 'burrow_system_heartbeat' );
wp_clear_scheduled_hook( 'burrow_system_stack_snapshot' );
wp_clear_scheduled_hook( 'burrow_outbox_cleanup' );
