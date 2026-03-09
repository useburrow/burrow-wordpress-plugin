<?php
/**
 * Fired during plugin deactivation.
 *
 * Defines all code necessary to run during the plugin's deactivation.
 *
 * @package    Burrow
 * @subpackage Burrow/includes
 */
class Burrow_Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * Clears any scheduled cron events added by the plugin.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'burrow_sync_events' );
	}
}
