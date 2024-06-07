<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://4x4tyres.co.uk
 * @since      1.0.0
 *
 * @package    Fbf_Ebay_Packages
 * @subpackage Fbf_Ebay_Packages/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Fbf_Ebay_Packages
 * @subpackage Fbf_Ebay_Packages/includes
 * @author     Kevin Price-Ward <kevin.price-ward@4x4tyres.co.uk>
 */
class Fbf_Ebay_Packages {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Fbf_Ebay_Packages_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'FBF_EBAY_PACKAGES_VERSION' ) ) {
			$this->version = FBF_EBAY_PACKAGES_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'fbf-ebay-packages';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Fbf_Ebay_Packages_Loader. Orchestrates the hooks of the plugin.
	 * - Fbf_Ebay_Packages_i18n. Defines internationalization functionality.
	 * - Fbf_Ebay_Packages_Admin. Defines all hooks for the admin area.
	 * - Fbf_Ebay_Packages_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-fbf-ebay-packages-admin.php';

		/**
		 * The class responsible for admin ajax functions.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-fbf-ebay-packages-admin-ajax.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-fbf-ebay-packages-public.php';

		$this->loader = new Fbf_Ebay_Packages_Loader();

        /**
         * The class responsible for scheduling and un-scheduling events (cron jobs).
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-cron.php';

        /**
         * The class responsible for syncing orders
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-order-sync.php';
    }

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Fbf_Ebay_Packages_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Fbf_Ebay_Packages_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Fbf_Ebay_Packages_Admin( $this->get_plugin_name(), $this->get_version() );
		$plugin_admin_ajax = new Fbf_Ebay_Packages_Admin_Ajax($this->get_plugin_name(), $this->get_version());

        $plugin_api = new Fbf_Ebay_Packages_Order_Sync($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action( 'admin_menu', $plugin_admin, 'add_menu_page' );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		// CRON Hook
        $this->loader->add_action( Fbf_Ebay_Packages_Cron::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK, $plugin_admin, 'run_hourly_event' );

        $this->loader->add_action( 'add_meta_boxes', $plugin_admin, 'fbf_ebay_packages_admin_meta_box');

        $this->loader->add_action( 'admin_post_fbf_ebay_packages_add_package', $plugin_admin, 'save_post');
        $this->loader->add_action( 'admin_notices', $plugin_admin, 'fbf_ebay_packages_admin_notices');

        /* Load the JavaScript needed for the settings screen. */
        $this->loader->add_action("admin_footer-{$plugin_admin->page_id()}", $plugin_admin, 'meta_footer_scripts'); // Tyres
        $this->loader->add_action("admin_footer-{$plugin_admin->wheel_page_id()}", $plugin_admin, 'meta_footer_scripts_wheels'); // Wheels
        $this->loader->add_action("admin_footer-{$plugin_admin->compatibility_page_id()}", $plugin_admin, 'meta_footer_scripts_compatibility'); // Compatibility
        $this->loader->add_action("admin_footer-{$plugin_admin->packages_page_id()}", $plugin_admin, 'meta_footer_scripts_packages'); // Packages

        $this->loader->add_filter('acf/fields/relationship/query', $plugin_admin, 'acf_relationship', 10, 3);
        $this->loader->add_filter('acf/fields/relationship/result', $plugin_admin, 'acf_relationship_result', 10, 4);

        //Ajax
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_get_brands', $plugin_admin_ajax, 'fbf_ebay_packages_get_brands' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_get_brands', $plugin_admin_ajax, 'fbf_ebay_packages_get_brands' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_get_manufacturers', $plugin_admin_ajax, 'fbf_ebay_packages_get_manufacturers' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_get_manufacturers', $plugin_admin_ajax, 'fbf_ebay_packages_get_manufacturers' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_ebay_listing', $plugin_admin_ajax, 'fbf_ebay_packages_ebay_listing' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_ebay_listing', $plugin_admin_ajax, 'fbf_ebay_packages_ebay_listing' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_brand_confirm', $plugin_admin_ajax, 'fbf_ebay_packages_brand_confirm' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_brand_confirm', $plugin_admin_ajax, 'fbf_ebay_packages_brand_confirm' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_wheel_brands_confirm', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_brands_confirm' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_wheel_brands_confirm', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_brands_confirm' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_save_chassis', $plugin_admin_ajax, 'fbf_ebay_packages_save_chassis' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_save_chassis', $plugin_admin_ajax, 'fbf_ebay_packages_save_chassis' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_wheel_create_listings', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_create_listings' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_wheel_create_listings', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_create_listings' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_wheel_confirm_listings', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_confirm_listings' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_wheel_confirm_listings', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_confirm_listings' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_wheel_manufacturers_confirm', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_manufacturers_confirm' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_wheel_manufacturers_confirm', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_manufacturers_confirm' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_wheel_get_chassis', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_get_chassis' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_wheel_get_chassis', $plugin_admin_ajax, 'fbf_ebay_packages_wheel_get_chassis' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_list_tyres', $plugin_admin_ajax, 'fbf_ebay_packages_list_tyres' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_list_tyres', $plugin_admin_ajax, 'fbf_ebay_packages_list_tyres' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_tyre_table', $plugin_admin_ajax, 'fbf_ebay_packages_tyre_table' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_tyre_table', $plugin_admin_ajax, 'fbf_ebay_packages_tyre_table' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_event_log', $plugin_admin_ajax, 'fbf_ebay_packages_event_log' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_event_log', $plugin_admin_ajax, 'fbf_ebay_packages_event_log' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_synchronise', $plugin_admin_ajax, 'fbf_ebay_packages_synchronise' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_synchronise', $plugin_admin_ajax, 'fbf_ebay_packages_synchronise' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_schedule', $plugin_admin_ajax, 'fbf_ebay_packages_schedule' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_schedule', $plugin_admin_ajax, 'fbf_ebay_packages_schedule' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_unschedule', $plugin_admin_ajax, 'fbf_ebay_packages_unschedule' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_unschedule', $plugin_admin_ajax, 'fbf_ebay_packages_unschedule' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_clean', $plugin_admin_ajax, 'fbf_ebay_packages_clean' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_clean', $plugin_admin_ajax, 'fbf_ebay_packages_clean' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_listing_info', $plugin_admin_ajax, 'fbf_ebay_packages_listing_info' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_listing_info', $plugin_admin_ajax, 'fbf_ebay_packages_listing_info' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_log_detail', $plugin_admin_ajax, 'fbf_ebay_packages_log_detail' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_log_detail', $plugin_admin_ajax, 'fbf_ebay_packages_log_detail' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_detail_log_response', $plugin_admin_ajax, 'fbf_ebay_packages_detail_log_response' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_detail_log_response', $plugin_admin_ajax, 'fbf_ebay_packages_detail_log_response' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_test_item', $plugin_admin_ajax, 'fbf_ebay_packages_test_item' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_test_item', $plugin_admin_ajax, 'fbf_ebay_packages_test_item' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_get_package_chassis', $plugin_admin_ajax, 'fbf_ebay_packages_get_package_chassis' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_get_package_chassis', $plugin_admin_ajax, 'fbf_ebay_packages_get_package_chassis' );

        // Compatibility
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_compatibility', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_compatibility', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_confirm_compatibility', $plugin_admin_ajax, 'fbf_ebay_packages_confirm_compatibility' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_confirm_compatibility', $plugin_admin_ajax, 'fbf_ebay_packages_confirm_compatibility' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_compatibility_list', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility_list' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_compatibility_list', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility_list' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_compatibility_delete', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility_delete' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_compatibility_delete', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility_delete' );
        $this->loader->add_action( 'wp_ajax_fbf_ebay_packages_compatibility_delete_all', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility_delete_all' );
        $this->loader->add_action( 'wp_ajax_nopriv_fbf_ebay_packages_compatibility_delete_all', $plugin_admin_ajax, 'fbf_ebay_packages_compatibility_delete_all' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Fbf_Ebay_Packages_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Fbf_Ebay_Packages_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
