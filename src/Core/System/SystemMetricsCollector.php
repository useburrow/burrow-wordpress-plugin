<?php
/**
 * Collect WordPress stack metadata for system events.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\System;

class SystemMetricsCollector {
	/**
	 * Gather stack snapshot properties.
	 *
	 * @return array<string,mixed>
	 */
	public function collect_stack_snapshot() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_update_plugins();
		$plugins  = get_plugins();
		$updates  = get_site_transient( 'update_plugins' );
		$items    = array();
		$up_count = 0;

		foreach ( $plugins as $file => $plugin ) {
			$has_update = isset( $updates->response[ $file ] );
			if ( $has_update ) {
				$up_count++;
			}
			$items[] = array(
				'handle'          => dirname( $file ),
				'name'            => $plugin['Name'],
				'version'         => $plugin['Version'],
				'latest'          => $has_update ? $updates->response[ $file ]->new_version : $plugin['Version'],
				'updateAvailable' => $has_update,
			);
		}

		return array(
			'cms' => array(
				'name'            => 'wordpress',
				'version'         => get_bloginfo( 'version' ),
				'latestVersion'   => get_bloginfo( 'version' ),
				'updateAvailable' => false,
			),
			'runtime' => array(
				'php'      => phpversion(),
				'database' => $this->database_version(),
			),
			'plugins'          => $items,
			'updatesAvailable' => $up_count,
			'totalPlugins'     => count( $items ),
		);
	}

	/**
	 * DB version string.
	 *
	 * @return string
	 */
	private function database_version() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_var( 'SELECT VERSION()' );
		return is_string( $row ) ? $row : '';
	}
}
