<?php
/**
 * Lightweight PSR-4 autoloader for BurrowWP classes.
 *
 * @package Burrow
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'BurrowWP\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = BURROW_PLUGIN_DIR . 'src/' . $relative . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
