<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://4x4tyres.co.uk
 * @since             1.0.0
 * @package           Fbf_Ebay_Packages
 *
 * @wordpress-plugin
 * Plugin Name:       4x4 eBay packages
 * Plugin URI:        https://github.com/thelar/fbf-ebay-packages
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress admin area.
 * Version:           1.0.1
 * Author:            Kevin Price-Ward
 * Author URI:        https://4x4tyres.co.uk
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       fbf-ebay-packages
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'FBF_EBAY_PACKAGES_VERSION', '1.0.26' );

/**
 * Current database version.
 */
define( 'FBF_EBAY_PACKAGES_DB_VERSION', '1.0.0' );

/**
 * Plugin name.
 */
define( 'FBF_EBAY_PACKAGES_PLUGIN_NAME', 'fbf-ebay-packages' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-fbf-ebay-packages-activator.php
 */
function activate_fbf_ebay_packages() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-fbf-ebay-packages-activator.php';
	Fbf_Ebay_Packages_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-fbf-ebay-packages-deactivator.php
 */
function deactivate_fbf_ebay_packages() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-fbf-ebay-packages-deactivator.php';
	Fbf_Ebay_Packages_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_fbf_ebay_packages' );
register_deactivation_hook( __FILE__, 'deactivate_fbf_ebay_packages' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-fbf-ebay-packages.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_fbf_ebay_packages() {

	$plugin = new Fbf_Ebay_Packages();
	$plugin->run();

}
run_fbf_ebay_packages();
