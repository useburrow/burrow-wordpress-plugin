<?php
/**
 * Burrow
 *
 * @package           Burrow
 * @author            Burrow
 * @copyright         2024 Burrow
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Burrow
 * Plugin URI:        https://useburrow.com
 * Description:       Connects your WordPress site to Burrow with minimal setup. Auto-detects forms and commerce activity, and sends contract-based events (system, forms, ecommerce) via a resilient queued sync so reporting stays accurate even during downtime.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Burrow
 * Author URI:        https://useburrow.com
 * Text Domain:       burrow
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'BURROW_VERSION', '1.0.0' );
define( 'BURROW_PLUGIN_FILE', __FILE__ );

/**
 * Plugin base path.
 */
define( 'BURROW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin base URL.
 */
define( 'BURROW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( BURROW_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require BURROW_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * The code that runs during plugin activation.
 */
function activate_burrow() {
	require_once BURROW_PLUGIN_DIR . 'includes/class-burrow-activator.php';
	Burrow_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_burrow() {
	require_once BURROW_PLUGIN_DIR . 'includes/class-burrow-deactivator.php';
	Burrow_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_burrow' );
register_deactivation_hook( __FILE__, 'deactivate_burrow' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require BURROW_PLUGIN_DIR . 'includes/class-burrow-autoloader.php';
require BURROW_PLUGIN_DIR . 'includes/class-burrow.php';

/**
 * Begins execution of the plugin.
 */
function run_burrow() {
	$plugin = new Burrow();
	$plugin->run();
}

run_burrow();
