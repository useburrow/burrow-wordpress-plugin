<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for the admin settings page.
 *
 * @package    Burrow
 * @subpackage Burrow/admin
 */
class Burrow_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @var string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @var string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles() {
		// Admin styles can be enqueued here when needed.
	}

	/**
	 * Register the JavaScript for the admin area.
	 */
	public function enqueue_scripts() {
		// Admin scripts can be enqueued here when needed.
	}

	/**
	 * Add the plugin settings page to the WordPress admin menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Burrow Settings', 'burrow' ),
			__( 'Burrow', 'burrow' ),
			'manage_options',
			'burrow',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the plugin settings fields.
	 */
	public function register_settings() {
		register_setting(
			'burrow_settings_group',
			'burrow_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'burrow_general_section',
			__( 'General Settings', 'burrow' ),
			null,
			'burrow'
		);

		add_settings_field(
			'burrow_api_key',
			__( 'API Key', 'burrow' ),
			array( $this, 'render_api_key_field' ),
			'burrow',
			'burrow_general_section'
		);
	}

	/**
	 * Render the API key settings field.
	 */
	public function render_api_key_field() {
		$api_key = get_option( 'burrow_api_key', '' );
		?>
		<input
			type="text"
			id="burrow_api_key"
			name="burrow_api_key"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'Enter your Burrow API key', 'burrow' ); ?>"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Burrow website URL */
				esc_html__( 'Find your API key in the %s dashboard.', 'burrow' ),
				'<a href="' . esc_url( 'https://useburrow.com' ) . '" target="_blank" rel="noopener noreferrer">Burrow</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the plugin settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'burrow_settings_group' );
				do_settings_sections( 'burrow' );
				submit_button( __( 'Save Settings', 'burrow' ) );
				?>
			</form>
		</div>
		<?php
	}
}
