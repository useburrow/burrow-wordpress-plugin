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

		$cms = $this->collect_cms_update_metadata();
		if ( ! empty( $cms['updateAvailable'] ) ) {
			$up_count++;
		}

		return array(
			'cms'               => $cms,
			'runtime'           => array(
				'php'      => phpversion(),
				'database' => $this->database_version(),
			),
			'plugins'           => $items,
			'updatesAvailable'  => $up_count,
			'totalPlugins'      => count( $items ),
		);
	}

	/**
	 * WordPress core version + update availability.
	 *
	 * @return array{name:string,version:string,latestVersion:string,updateAvailable:bool}
	 */
	private function collect_cms_update_metadata() {
		$current = (string) get_bloginfo( 'version' );
		$latest  = $current;
		$available = false;

		if ( function_exists( 'wp_version_check' ) ) {
			wp_version_check();
		}

		$core_updates = function_exists( 'get_core_updates' ) ? get_core_updates( array( 'dismissed' => false ) ) : false;
		if ( is_array( $core_updates ) ) {
			foreach ( $core_updates as $update ) {
				if ( ! is_object( $update ) ) {
					continue;
				}
				$response = isset( $update->response ) ? (string) $update->response : '';
				$version  = isset( $update->current ) ? (string) $update->current : ( isset( $update->version ) ? (string) $update->version : '' );
				if ( '' === $version ) {
					continue;
				}
				if ( in_array( $response, array( 'upgrade', 'latest' ), true ) || version_compare( $version, $current, '>' ) ) {
					if ( version_compare( $version, $latest, '>' ) ) {
						$latest = $version;
					}
				}
				if ( 'upgrade' === $response || version_compare( $version, $current, '>' ) ) {
					$available = true;
				}
			}
		}

		if ( ! $available ) {
			$transient = get_site_transient( 'update_core' );
			if ( is_object( $transient ) && ! empty( $transient->updates ) && is_array( $transient->updates ) ) {
				foreach ( $transient->updates as $update ) {
					if ( ! is_object( $update ) ) {
						continue;
					}
					$response = isset( $update->response ) ? (string) $update->response : '';
					$version  = isset( $update->current ) ? (string) $update->current : '';
					if ( 'upgrade' === $response && '' !== $version && version_compare( $version, $current, '>' ) ) {
						$available = true;
						$latest    = $version;
						break;
					}
				}
			}
		}

		return array(
			'name'            => 'wordpress',
			'version'         => $current,
			'latestVersion'   => $latest,
			'updateAvailable' => $available,
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
