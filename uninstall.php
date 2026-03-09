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

// Remove plugin options from the database.
delete_option( 'burrow_api_key' );
delete_option( 'burrow_settings' );
delete_option( 'burrow_version' );

// Remove any scheduled events.
wp_clear_scheduled_hook( 'burrow_sync_events' );
