<?php

/**
 * Fired during plugin activation
 *
 * @link       https://4x4tyres.co.uk
 * @since      1.0.0
 *
 * @package    Fbf_Ebay_Packages
 * @subpackage Fbf_Ebay_Packages/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Fbf_Ebay_Packages
 * @subpackage Fbf_Ebay_Packages/includes
 * @author     Kevin Price-Ward <kevin.price-ward@4x4tyres.co.uk>
 */
class Fbf_Ebay_Packages_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
        //Install the logging database
        self::db_install();

        // schedule events (cron jobs)
        require_once plugin_dir_path( __FILE__ ) . 'class-fbf-ebay-packages-cron.php';
        Fbf_Ebay_Packages_Cron::schedule();
	}

    private static function db_install()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $table_name2 = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $table_name3 = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $table_name4 = $wpdb->prefix . 'fbf_ebay_packages_scheduled_event_log';
        $table_name5 = $wpdb->prefix . 'fbf_ebay_packages_offers';
        $table_name6 = $wpdb->prefix . 'fbf_ebay_packages_fittings';
        $table_name7 = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $table_name8 = $wpdb->prefix . 'fbf_ebay_packages_listing_compatibility';
        $table_name9 = $wpdb->prefix . 'fbf_ebay_packages_package_post_ids';

        $charset_collate = $wpdb->get_charset_collate();

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
          `id` mediumint(9) NOT NULL AUTO_INCREMENT,
          `post_id` mediumint(9) NOT NULL,
          `offer_id` varchar (20),
          `listing_id` varchar (20),
          `inventory_sku` varchar (80),
          `created` datetime DEFAULT CURRENT_TIMESTAMP,
          `name` varchar(120),
          `qty` smallint,
          `status` ENUM('active', 'inactive') NOT NULL default 'active',
          `type` ENUM('tyre', 'wheel', 'package'),
          PRIMARY KEY  (`id`)
        ) $charset_collate;";
        dbDelta($sql); // Note, with IF NOT EXISTS - dbdelta has to be run for each $sql - https://wordpress.stackexchange.com/questions/51646/dbdelta-only-creates-the-last-table

        $sql2 = "CREATE TABLE IF NOT EXISTS `$table_name2` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `listing_id` mediumint(9) NOT NULL,
            `sku` varchar(40) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `listing_id` (`listing_id`),
            KEY `sku` (`sku`)
        ) $charset_collate;";
        dbDelta($sql2); // Note, with IF NOT EXISTS - dbdelta has to be run for each $sql - https://wordpress.stackexchange.com/questions/51646/dbdelta-only-creates-the-last-table

        $sql3 = "CREATE TABLE IF NOT EXISTS `$table_name3` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `listing_id` mediumint(9) NOT NULL,
            `scheduled_event_id` mediumint(9),
            `ebay_action` varchar(20), 
            `created` datetime DEFAULT CURRENT_TIMESTAMP,
            `log` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            KEY `listing_id` (`listing_id`)
        ) $charset_collate;";
        dbDelta($sql3); // Note, with IF NOT EXISTS - dbdelta has to be run for each $sql - https://wordpress.stackexchange.com/questions/51646/dbdelta-only-creates-the-last-table

        $sql4 = "CREATE TABLE IF NOT EXISTS `$table_name4` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `created` datetime DEFAULT CURRENT_TIMESTAMP,
            `hook` varchar(100) NOT NULL,
            `type` varchar(20) NOT NULL,
            `log` mediumtext NOT NULL,
            PRIMARY KEY (`id`)
        ) $charset_collate;";
        dbDelta($sql4);

        $sql5 = "CREATE TABLE IF NOT EXISTS `$table_name5` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `offer_id` varchar (20),
            `payload` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `offer_id` (`offer_id`)
        ) $charset_collate;";
        dbDelta($sql5);

        $sql6 = "CREATE TABLE IF NOT EXISTS `$table_name6` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `listing_id` mediumint(9) NOT NULL,
            `chassis_id` SMALLINT UNSIGNED,
            `chassis_name` varchar (120),
            `manufacturer_id` SMALLINT UNSIGNED,
            `manufacturer_name` varchar (120),
            PRIMARY KEY (`id`),
            KEY `listing_id` (`listing_id`)
        ) $charset_collate;";
        dbDelta($sql6);

        $sql7 = "CREATE TABLE IF NOT EXISTS `$table_name7` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `chassis_id` SMALLINT UNSIGNED,
            `payload` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            KEY `chassis_id` (`chassis_id`)
        ) $charset_collate;";
        dbDelta($sql7);

        $sql8 = "CREATE TABLE IF NOT EXISTS `$table_name8` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `listing_id` mediumint(9) NOT NULL,
            `payload` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `listing_id` (`listing_id`)
        ) $charset_collate;";
        dbDelta($sql8);

        $sql9 = "CREATE TABLE IF NOT EXISTS `$table_name9` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `listing_id` mediumint(9) NOT NULL,
            `post_ids` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `listing_id` (`listing_id`)
        ) $charset_collate;";
        dbDelta($sql9);

        $wpdb->query("ALTER TABLE $table_name2 ADD FOREIGN KEY (`listing_id`) REFERENCES  $table_name(`id`) ON DELETE CASCADE"); //Add the foreign key constraint via wpdb because dbdelta does not support it!!!
        $wpdb->query("ALTER TABLE $table_name3 ADD FOREIGN KEY (`listing_id`) REFERENCES  $table_name(`id`) ON DELETE CASCADE"); //Add the foreign key constraint via wpdb because dbdelta does not support it!!!
        $wpdb->query("ALTER TABLE $table_name6 ADD FOREIGN KEY (`listing_id`) REFERENCES  $table_name(`id`) ON DELETE CASCADE"); //Add the foreign key constraint via wpdb because dbdelta does not support it!!!
        $wpdb->query("ALTER TABLE $table_name8 ADD FOREIGN KEY (`listing_id`) REFERENCES  $table_name(`id`) ON DELETE CASCADE"); //Add the foreign key constraint via wpdb because dbdelta does not support it!!!
        $wpdb->query("ALTER TABLE $table_name9 ADD FOREIGN KEY (`listing_id`) REFERENCES  $table_name(`id`) ON DELETE CASCADE"); //Add the foreign key constraint via wpdb because dbdelta does not support it!!!

        add_option('fbf_ebay_packages_db_version', FBF_EBAY_PACKAGES_DB_VERSION);

	}

}
