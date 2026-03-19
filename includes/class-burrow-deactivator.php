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
		wp_clear_scheduled_hook( 'burrow_outbox_worker' );
		wp_clear_scheduled_hook( 'burrow_system_heartbeat' );
		wp_clear_scheduled_hook( 'burrow_system_stack_snapshot' );
		wp_clear_scheduled_hook( 'burrow_outbox_cleanup' );
		wp_clear_scheduled_hook( 'burrow_checkout_abandonment_scan' );
		wp_clear_scheduled_hook( 'burrow_cart_abandonment_scan' );
	}
}
