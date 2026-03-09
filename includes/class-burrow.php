<?php
/**
 * The core plugin class.
 *
 * Defines the plugin name, version, and hooks for the admin area and
 * the public-facing side of the site.
 *
 * @package    Burrow
 * @subpackage Burrow/includes
 */
class Burrow {

	/**
	 * The loader responsible for maintaining and registering all hooks.
	 *
	 * @var Burrow_Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @var string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @var string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->version     = defined( 'BURROW_VERSION' ) ? BURROW_VERSION : '1.0.0';
		$this->plugin_name = 'burrow';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		require_once BURROW_PLUGIN_DIR . 'includes/class-burrow-loader.php';
		require_once BURROW_PLUGIN_DIR . 'includes/class-burrow-activator.php';
		require_once BURROW_PLUGIN_DIR . 'includes/class-burrow-deactivator.php';
		require_once BURROW_PLUGIN_DIR . 'admin/class-burrow-admin.php';
		require_once BURROW_PLUGIN_DIR . 'public/class-burrow-public.php';

		$this->loader = new Burrow_Loader();
	}

	/**
	 * Define the locale for internationalization.
	 */
	private function set_locale() {
		$this->loader->add_action(
			'plugins_loaded',
			$this,
			'load_plugin_textdomain'
		);
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'burrow',
			false,
			dirname( plugin_basename( BURROW_PLUGIN_DIR . 'burrow.php' ) ) . '/languages/'
		);
	}

	/**
	 * Register all of the hooks related to the admin area functionality.
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Burrow_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_settings_page' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality.
	 */
	private function define_public_hooks() {
		$plugin_public = new Burrow_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within WordPress.
	 *
	 * @return string The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @return Burrow_Loader Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return string The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
