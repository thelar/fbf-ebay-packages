<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://4x4tyres.co.uk
 * @since      1.0.0
 *
 * @package    Fbf_Ebay_Packages
 * @subpackage Fbf_Ebay_Packages/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * Meta box tutorial https://shellcreeper.com/wp-settings-meta-box/
 * Select2 tutorial https://rudrastyh.com/wordpress/select2-for-metaboxes-with-ajax.html
 *
 * @package    Fbf_Ebay_Packages
 * @subpackage Fbf_Ebay_Packages/admin
 * @author     Kevin Price-Ward <kevin.price-ward@4x4tyres.co.uk>
 */
class Fbf_Ebay_Packages_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;
    private $errors = [];
    public $tyres_submenu;
    public $wheels_submenu;


	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

        $this->setup_options();


	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles($hook_suffix) {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Fbf_Ebay_Packages_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Fbf_Ebay_Packages_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

        // Bootstrap
        //wp_enqueue_style( $this->plugin_name . '-bootstrap', plugin_dir_url( __FILE__ ) . 'css/bootstrap.min.css', array(), $this->version, 'all' );

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/fbf-ebay-packages-admin.css', array(), $this->version, 'all' );

        do_action('acf/input/admin_enqueue_scripts'); // Add ACF scripts

        // Enqueue scripts for meta
        $tyre_page_hook_id = $this->page_id();
        $wheel_page_hook_id = $this->wheel_page_id();
        $compatibility_page_hook_id = $this->compatibility_page_id();
        if ( $hook_suffix == $tyre_page_hook_id || $hook_suffix == $wheel_page_hook_id || $hook_suffix == $compatibility_page_hook_id ){
            wp_enqueue_style( 'thickbox' );
            wp_enqueue_style( $this->plugin_name . '-datatables', plugin_dir_url( __FILE__ ) . 'css/datatables.min.css', array(), $this->version, 'all' );
        }

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts($hook_suffix) {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Fbf_Ebay_Packages_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Fbf_Ebay_Packages_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
        do_action('acf/input/admin_head'); // Add ACF admin head hooks



        // Enqueue scripts for meta
        $tyre_page_hook_id = $this->page_id();
        $wheel_page_hook_id = $this->wheel_page_id();
        $compatibility_page_hook_id = $this->compatibility_page_id();
        if ( $hook_suffix == $tyre_page_hook_id || $hook_suffix == $wheel_page_hook_id || $hook_suffix == $compatibility_page_hook_id ){
            wp_enqueue_script( 'common' );
            wp_enqueue_script( 'wp-lists' );
            wp_enqueue_script( 'postbox' );
            wp_enqueue_script( 'thickbox' );

            wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/fbf-ebay-packages-admin.js', array( 'jquery' ), $this->version, true );
            $ajax_params = array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'ajax_nonce' => wp_create_nonce($this->plugin_name),
                'acf_nonce' => wp_create_nonce('acf_nonce'),
            );
            wp_localize_script($this->plugin_name, 'fbf_ebay_packages_admin', $ajax_params);

            wp_enqueue_script( $this->plugin_name . '-datatables', plugin_dir_url( __FILE__ ) . 'js/datatables.min.js', array( 'jquery' ), $this->version, false );
        }
	}

    /**
     * Example daily event.
     *
     * @param null $h the handle
     * @since 1.0.0
     */
    public function run_hourly_event($h=null) {
        // Do something every hour
        // only run the sync if it's on the live site!!
        $allowed_hosts = [
            '4x4tyres.co.uk'
        ];
        if (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], $allowed_hosts)) {
            if(!is_null($h)){
                $hook = $h;
            }else{
                $hook = Fbf_Ebay_Packages_Cron::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK;
            }
            // $i = self::synchronise($hook, 'tyres and wheels');
            // TODO: handle times when maybe the logging fails
        }
    }

    public static function clean(){
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $l = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        $cleaned = [];

        if(!empty($l)){
            foreach($l as $row){
                if(!is_null($row['inventory_sku'])){
                    $cleaned[$row['inventory_sku']] = [];

                    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-clean-item.php';
                    $clean_item = new Fbf_Ebay_Packages_Clean_Item($row['id'], $row['inventory_sku'], FBF_EBAY_PACKAGES_PLUGIN_NAME, FBF_EBAY_PACKAGES_VERSION);
                    $clean_result = $clean_item->clean();

                    $cleaned[$row['inventory_sku']] = $clean_result;
                }
            }
        }
        return $cleaned;
    }

    public static function synchronise($via, $type, $items=null, $test_item_type=null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_scheduled_event_log';

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-synchronise.php';
        $sync = new Fbf_Ebay_Packages_Synchronise(FBF_EBAY_PACKAGES_PLUGIN_NAME, FBF_EBAY_PACKAGES_VERSION);
        $sync_result = $sync->run(['tyre', 'wheel'], $items);

        //$q = $wpdb->prepare("INSERT INTO {$table} (hook, type, log) VALUES (%s, %s, %s)", $via, $type, serialize($sync_result));


        $i = $wpdb->insert($table,
            [
                'hook' => $via,
                'type' => $type,
                'log' => serialize($sync_result)
            ]
        );

        if($i!==false){
            $scheduled_event_id = $wpdb->insert_id;
            $listings_table = $wpdb->prefix . 'fbf_ebay_packages_logs';
            $log_ids = $sync_result['log_ids'];
            if(!empty($log_ids)){
                foreach($log_ids as $log_id){
                    $wpdb->update($listings_table,
                        [
                            'scheduled_event_id' => $scheduled_event_id
                        ],
                        [
                            'id' => $log_id
                        ]
                    );
                }
            }
        }
        return $i;
    }

    /**
     * Register menu page
     *
     * @since 1.0.0
     */
    public function add_menu_page()
    {
        add_menu_page(
            __( 'eBay Packages', 'fbf-ebay' ),
            __( 'eBay Packages', 'fbf-ebay' ),
            'manage_woocommerce',
            $this->plugin_name,
            [$this, 'add_package'],
            'dashicons-admin-tools'
        );
        add_submenu_page(
            $this->plugin_name,
            __('Create eBay Package', 'fbf-ebay'),
            __('Create Package', 'fbf-ebay'),
            'manage_woocommerce',
            $this->plugin_name,
            [$this, 'add_package']
        );
        $this->tyres_submenu = add_submenu_page(
            $this->plugin_name,
            __('Tyres', 'fbf-ebay'),
            __('Tyres', 'fbf-ebay'),
            'manage_woocommerce',
            $this->plugin_name . '-tyres',
            [$this, 'tyres']
        );
        $this->wheels_submenu = add_submenu_page(
            $this->plugin_name,
            __('Wheels', 'fbf-ebay'),
            __('Wheels', 'fbf-ebay'),
            'manage_woocommerce',
            $this->plugin_name . '-wheels',
            [$this, 'wheels']
        );
        $this->wheels_submenu = add_submenu_page(
            $this->plugin_name,
            __('Compatibility', 'fbf-ebay'),
            __('Compatibility', 'fbf-ebay'),
            'manage_woocommerce',
            $this->plugin_name . '-compatibility',
            [$this, 'compatibility']
        );
    }

    /**
     * Render the front page for plugin
     *
     * @since  1.0.0
     */
    public function add_package() {
        $nonce = wp_create_nonce( 'acf_nonce' );
        echo '<div class="wrap">';
        echo '<h2>eBay Packages - add package</h2>';
        echo '<form method="post" id="fbf_ebay_packages_add_package" action=" ' . esc_url( admin_url( 'admin-post.php' ) ) .'">';
        echo '<input type="hidden" name="action" value="fbf_ebay_packages_add_package"/>';
        echo '<input type="hidden" id="fbf_ebay_packages_valid" name="fbf_ebay_packages_valid" value="no"/>';
        echo '<input type="hidden" id="fbf_ebay_packages_nonce" name="fbf_ebay_packages_nonce" value="' . $nonce . '"/>';
        echo '
        <script>
            acf.set("ajaxurl", "/wp/wp-admin/admin-ajax.php");
            acf.set("nonce", "' . $nonce . '");
            console.log("ajax" + acf.get("ajaxurl"));
        </script>
        ';
        $options = array(
            'id' => 'acf-form',
            'field_groups' => ['group_601413ae313d1'],
            'post_id' => 219053,
            'form' => false,
        );
        acf_form( $options );
        echo '<input type="submit" class="button button-primary" value="Add Package"/>';
        echo '</form>';
        echo '</div>';
    }

    public function fbf_ebay_packages_admin_notices()
    {
        if(isset($_REQUEST['fbf_ebay_packages_status'])) {
            if(isset($_REQUEST['fbf_ebay_packages_link'])){
                $link = '<a href="' . urldecode($_REQUEST['fbf_ebay_packages_link']) . '">click to view package</a>';
            }else{
                $link = '';
            }
            printf('<div class="notice notice-%s is-dismissible">', $_REQUEST['fbf_ebay_packages_status']);
            printf('<p>%s, %s</p>', $_REQUEST['fbf_ebay_packages_message'], $link);
            echo '</div>';
        }
    }

    /**
     * Basic Meta Box
     * @since 0.1.0
     * @link http://codex.wordpress.org/Function_Reference/add_meta_box
     */
    public function fbf_ebay_packages_admin_meta_box(){

        global $hook_suffix;
        $tyre_page_hook_id = $this->page_id();
        $wheel_page_hook_id = $this->wheel_page_id();
        $compatibility_page_hook_id = $this->compatibility_page_id();

        if($hook_suffix===$tyre_page_hook_id){
            if(!isset($_REQUEST['listing_id'])){
                $meta_brands = add_meta_box(
                    'tyre-brands',                  /* Meta Box ID */
                    'Tyre Brands',               /* Title */
                    [$this, 'tyre_brands_meta_box'],  /* Function Callback */
                    $tyre_page_hook_id,               /* Screen: Our Settings Page */
                    'normal',                 /* Context */
                    'default'                 /* Priority */
                );

                $meta_packages = add_meta_box(
                    'tyre-listings',
                    'eBay Tyre Listings',
                    [$this, 'tyre_listings_meta_box'],
                    $tyre_page_hook_id,
                    'normal',
                    'default'
                );

                $meta_schedule = add_meta_box(
                    'tyre-schedule',
                    'eBay Synchronisation',
                    [$this, 'tyre_schedule_sync_meta_box'],
                    $tyre_page_hook_id,
                    'normal',
                    'default'
                );
            }else{
                $meta_schedule = add_meta_box(
                    'tyre-listing-log-detail',
                    'All log entries for item',
                    [$this, 'tyre_listing_log_detail'],
                    $tyre_page_hook_id,
                    'normal',
                    'default'
                );
            }
        }else if($hook_suffix===$wheel_page_hook_id){
            $meta_brands = add_meta_box(
                'wheel-brands',                  /* Meta Box ID */
                'Wheel Brands',               /* Title */
                [$this, 'wheel_brands_meta_box'],  /* Function Callback */
                $wheel_page_hook_id,               /* Screen: Our Settings Page */
                'normal',                 /* Context */
                'default'                 /* Priority */
            );
            $meta_manufacturers = add_meta_box(
                'wheel-manufacturers',                  /* Meta Box ID */
                'Manufacturers',               /* Title */
                [$this, 'wheel_manufacturers_meta_box'],  /* Function Callback */
                $wheel_page_hook_id,               /* Screen: Our Settings Page */
                'normal',                 /* Context */
                'default'                 /* Priority */
            );
            $meta_chassis = add_meta_box(
                'wheel-chassis',                  /* Meta Box ID */
                'Chassis',               /* Title */
                [$this, 'wheel_chassis_meta_box'],  /* Function Callback */
                $wheel_page_hook_id,               /* Screen: Our Settings Page */
                'normal',                 /* Context */
                'default'                 /* Priority */
            );
            $meta_create = add_meta_box(
                'wheel-create-listings',                  /* Meta Box ID */
                'Create Listings',               /* Title */
                [$this, 'wheel_create_listings'],  /* Function Callback */
                $wheel_page_hook_id,               /* Screen: Our Settings Page */
                'normal',                 /* Context */
                'default'                 /* Priority */
            );
            $meta_wheels = add_meta_box(
                'wheel-listings',                  /* Meta Box ID */
                'eBay Wheel Listings',               /* Title */
                [$this, 'wheel_listings_meta_box'],  /* Function Callback */
                $wheel_page_hook_id,               /* Screen: Our Settings Page */
                'normal',                 /* Context */
                'default'                 /* Priority */
            );
            $meta_schedule = add_meta_box(
                'wheel-schedule',
                'eBay Synchronisation',
                [$this, 'wheel_schedule_sync_meta_box'],
                $wheel_page_hook_id,
                'normal',
                'default'
            );
        }else if($hook_suffix===$compatibility_page_hook_id){
            $compatibility = add_meta_box(
                'compatibility',                  /* Meta Box ID */
                'Chassis Compatibility',               /* Title */
                [$this, 'compatibility_meta_box'],  /* Function Callback */
                $compatibility_page_hook_id,               /* Screen: Our Settings Page */
                'normal',                 /* Context */
                'default'                 /* Priority */
            );
        }
    }

    private function get_errors()
    {
        return urlencode(implode('<br/>', $this->errors));
    }

    /**
     * Static function run daily from importer which updates stock and price on packages
     *
     * @param $id
     */
    public static function update_package($id)
    {
        $product = wc_get_product($id);
        $linked = $product->get_meta('_fbf_ebay_packages_linked', true);
        $tyre = wc_get_product($linked['tyre']);
        $wheel = wc_get_product($linked['wheel']);
        $percentage = $product->get_meta('_fbf_ebay_packages_percentage', true);
        $qty = $product->get_meta('_fbf_ebay_packages_qty', true);
        $stock = min($tyre->get_stock_quantity(), $wheel->get_stock_quantity());
        $product->set_stock_quantity(floor($stock/$qty)); // E.g. if the package qty is 4 - stock level for package is the minimum stock divided by 4
        $price = ($tyre->get_price() * $qty) + ($wheel->get_price() * $qty);
        $price = $price + (($price/100) * $percentage);
        $product->set_regular_price($price);
        $product->save();
    }

    public function tyres()
    {
        global $hook_suffix;

        /**
         * The class responsible for API auth.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();
        if($token['status']==='error'){
            $this->errors[] = $token['errors'];
        }

        $msg = sprintf('<p>%s</p>', $token['status']==='error'?$this->get_errors():'eBay Access Token is valid');
        printf('<div class="notice notice-%s is-dismissible">%s</div>', $token['status'], $msg);

        /* enable add_meta_boxes function in this page. */
        do_action( 'add_meta_boxes', $hook_suffix, [] ); // Not entirely sure why we need an empty array here!

        if(isset($_REQUEST['listing_id'])){
            $title = 'Log Detail';
        }else{
            $title = 'Tyre Listings';
        }
        ?>
        <div class="wrap">
            <h2><?=$title?></h2>
            <?php settings_errors(); ?>
            <div class="tyre-brand-select-meta-box-wrap">
                <form id="tyre-brand-select-form" method="post" action="options.php">
                    <div id="poststuff">
                        <div id="post-body" class="metabox-holder columns-1">
                            <div id="postbox-container-2" class="postbox-container">
                                <?php do_meta_boxes( $hook_suffix, 'normal', null ); ?>
                                <!-- #normal-sortables -->
                            </div><!-- #postbox-container-2 -->
                        </div><!-- #post-body -->
                        <br class="clear">
                    </div><!-- #poststuff -->
                </form>
            </div><!-- .fx-settings-meta-box-wrap -->
        </div><!-- .wrap -->
        <?php
    }

    public function wheels($type)
    {
        global $hook_suffix;

        /**
         * The class responsible for API auth.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();
        if($token['status']==='error'){
            $this->errors[] = $token['errors'];
        }

        $msg = sprintf('<p>%s</p>', $token['status']==='error'?$this->get_errors():'eBay Access Token is valid');
        printf('<div class="notice notice-%s is-dismissible">%s</div>', $token['status'], $msg);

        /* enable add_meta_boxes function in this page. */
        do_action( 'add_meta_boxes', $hook_suffix, [] ); // Not entirely sure why we need an empty array here!

        ?>
        <div class="wrap">
            <h2 id="fbf-ebay-packages-wheels-title">Wheels</h2>
            <?php settings_errors(); ?>
            <div class="wheel-meta-box-wrap">
                <form id="wheel-select-form" method="post" action="options.php">
                    <div id="poststuff">
                        <div id="post-body" class="metabox-holder columns-1">
                            <div id="postbox-container-2" class="postbox-container">
                                <?php do_meta_boxes( $hook_suffix, 'normal', null ); ?>
                                <!-- #normal-sortables -->
                            </div><!-- #postbox-container-2 -->
                        </div><!-- #post-body -->
                        <br class="clear">
                    </div><!-- #poststuff -->
                </form>
            </div><!-- .fx-settings-meta-box-wrap -->
        </div>
        <div id="save-listings-thickbox" style="display:none;">
            <div class="tb-modal-content"></div>
            <div class="tb-modal-footer" style="margin-bottom: 1em;">
                <button role="button" type="button" class="button button-secondary" onclick="tb_remove();">
                    Close
                </button>
                <button id="fbf-ebay-packages-wheels-confirm-listing" role="button" type="button" class="button button-primary" disabled>
                    Confirm
                </button>
            </div>
        </div>
        <?php
    }

    public function compatibility()
    {
        global $hook_suffix;

        /**
         * The class responsible for API auth.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();
        if($token['status']==='error'){
            $this->errors[] = $token['errors'];
        }

        $msg = sprintf('<p>%s</p>', $token['status']==='error'?$this->get_errors():'eBay Access Token is valid');
        printf('<div class="notice notice-%s is-dismissible">%s</div>', $token['status'], $msg);

        /* enable add_meta_boxes function in this page. */
        do_action( 'add_meta_boxes', $hook_suffix, [] ); // Not entirely sure why we need an empty array here!
        ?>
        <div class="wrap">
            <h2>Compatibility</h2>
            <?php settings_errors(); ?>
            <div class="compatibility-meta-box-wrap">
                <form id="compatibility-select-form" method="post" action="options.php">
                    <div id="poststuff">
                        <div id="post-body" class="metabox-holder columns-1">
                            <div id="postbox-container-2" class="postbox-container">
                                <?php do_meta_boxes( $hook_suffix, 'normal', null ); ?>
                                <!-- #normal-sortables -->
                            </div><!-- #postbox-container-2 -->
                        </div><!-- #post-body -->
                        <br class="clear">
                    </div><!-- #poststuff -->
                </form>
            </div><!-- .fx-settings-meta-box-wrap -->
        </div>
        <div id="compatibility-thickbox" style="display:none;">
            <div class="tb-modal-content" data-compatibility="{}"></div>
            <div class="tb-modal-footer" style="margin-bottom: 1em;">
                <button role="button" type="button" class="button button-secondary" onclick="tb_remove();">
                    Close
                </button>
                <button id="fbf-ebay-packages-compatibility-confirm-listing" role="button" type="button" class="button button-primary" disabled>
                    Confirm
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Footer Script Needed for Meta Box:
     * - Meta Box Toggle.
     * - Spinner for Saving Option.
     * - Reset Settings Confirmation
     * @since 0.1.0
     */
    public function meta_footer_scripts(){
        if(!empty($this->tyres_submenu)){
            $page_hook_id = $this->page_id();
            ?>
            <script type="text/javascript">
                //<![CDATA[
                jQuery(document).ready( function($) {
                    // toggle
                    $('.if-js-closed').removeClass('if-js-closed').addClass('closed');
                    postboxes.add_postbox_toggles( '<?php echo $page_hook_id; ?>' );
                    // display spinner
                    $('#fx-smb-form').submit( function(){
                        $('#publishing-action .spinner').css('display','inline');
                    });
                    // confirm before reset
                    $('#delete-action .submitdelete').on('click', function() {
                        return confirm('Are you sure want to do this?');
                    });
                });
                //]]>
            </script>
            <?php
        }
    }

    /**
     * Footer Script Needed for Meta Box:
     * - Meta Box Toggle.
     * - Spinner for Saving Option.
     * - Reset Settings Confirmation
     * @since 0.1.0
     */
    public function meta_footer_scripts_wheels(){
        if(!empty($this->wheels_submenu)){
            $page_hook_id = $this->wheel_page_id();
            ?>
            <script type="text/javascript">
                //<![CDATA[
                jQuery(document).ready( function($) {
                    // toggle
                    $('.if-js-closed').removeClass('if-js-closed').addClass('closed');
                    postboxes.add_postbox_toggles( '<?php echo $page_hook_id; ?>' );
                    // display spinner
                    $('#fx-smb-form').submit( function(){
                        $('#publishing-action .spinner').css('display','inline');
                    });
                    // confirm before reset
                    $('#delete-action .submitdelete').on('click', function() {
                        return confirm('Are you sure want to do this?');
                    });
                });
                //]]>
            </script>
            <?php
        }
    }

    /**
     * Footer Script Needed for Meta Box:
     * - Meta Box Toggle.
     * - Spinner for Saving Option.
     * - Reset Settings Confirmation
     * @since 0.1.0
     */
    public function meta_footer_scripts_compatibility(){
        if(!empty($this->wheels_submenu)){
            $page_hook_id = $this->wheel_page_id();
            ?>
            <script type="text/javascript">
                //<![CDATA[
                jQuery(document).ready( function($) {
                    // toggle
                    $('.if-js-closed').removeClass('if-js-closed').addClass('closed');
                    postboxes.add_postbox_toggles( '<?php echo $page_hook_id; ?>' );
                    // display spinner
                    $('#fx-smb-form').submit( function(){
                        $('#publishing-action .spinner').css('display','inline');
                    });
                    // confirm before reset
                    $('#delete-action .submitdelete').on('click', function() {
                        return confirm('Are you sure want to do this?');
                    });
                });
                //]]>
            </script>
            <?php
        }
    }

    /**
     * Submit Meta Box Callback
     * @since 0.1.0
     */
    public function tyre_brands_meta_box(){
        $selected_brands = get_option('_fbf_ebay_packages_tyre_brands');
        $options = '';
        if(!empty($selected_brands)){
            foreach($selected_brands as $brand){
                $options.= sprintf('<option value="%s" selected="selected">%s</option>', $brand['ID'], $brand['name']);
            }
        }
        ?>
        <?php /* Simple Text Input Example */ ?>
        <p>
            <label for="basic-text">Use the field below to search and select Tyre brands you want to create eBay listings for:</label>
            <select id="tyre_brands" name="tyre_brands[]" multiple="multiple" style="width: 99%; max-width: 25em;"><?=$options?></select>
        </p>
        <p class="howto">Click 'Save Listings' below to create an eBay Listing for each Tyre for the Brands listed above.</p>
        <?php submit_button( __( 'Save Listings', 'text-domain'), 'primary', 'submit', false, ['style' => 'float:left'] ); ?>
        <br class="clear"/>
        <div id="save-listings-thickbox" style="display:none;">
            <div class="tb-modal-content"></div>
            <div class="tb-modal-footer" style="margin-bottom: 1em;">
                <button role="button" type="button" class="button button-secondary" onclick="tb_remove();">
                    Close
                </button>
                <button id="fbf-ebay-packages-tyres-confirm-listing" role="button" type="button" class="button button-primary">
                    Confirm
                </button>
            </div>
        </div>
        <?php
    }

    public function wheel_brands_meta_box(){
        $selected_brands = get_option('_fbf_ebay_packages_wheel_brands');
        $options = '';
        if(!empty($selected_brands)){
            foreach($selected_brands as $brand){
                $options.= sprintf('<option value="%s" selected="selected">%s</option>', $brand['ID'], $brand['name']);
            }
        }
        ?>
        <?php /* Simple Text Input Example */ ?>
        <p>
            <label for="basic-text">Use the field below to search and select Wheel brands you want to create eBay listings for:</label>
            <select id="wheel_brands" name="wheel_brands[]" multiple="multiple" style="width: 99%; max-width: 25em;"><?=$options?></select>
        </p>
        <?php submit_button( __( 'Save Wheel Brands', 'text-domain'), 'primary', 'submit', false, ['id' => 'wheel-save-brands', 'style' => 'float:left'] ); ?>
        <br class="clear"/>
        <?php
    }

    public function wheel_manufacturers_meta_box()
    {
        $selected_manufacturers = get_option('_fbf_ebay_packages_wheel_manufacturers');
        $options = '';
        if(!empty($selected_manufacturers)){
            foreach($selected_manufacturers as $manufacturer){
                $options.= sprintf('<option value="%s" selected="selected">%s</option>', $manufacturer['ID'], $manufacturer['name']);
            }
        }
        ?>
        <?php /* Simple Text Input Example */ ?>
        <p>
            <label for="basic-text">Use the field below to search and select vehicle manufacturers you want to list Wheels for:</label>
            <select id="wheel_manufacturers" name="wheel_manufacturers[]" multiple="multiple" style="width: 99%; max-width: 25em;"><?=$options?></select>
        </p>
        <?php submit_button( __( 'Save Wheel Manufacturers', 'text-domain'), 'primary', 'submit', false, ['id' => 'wheel-save-manufacturers', 'style' => 'float:left'] ); ?>
        <br class="clear"/>
        <?php
    }

    public function wheel_chassis_meta_box()
    {
        ?>
        <?php /* Simple Text Input Example */ ?>
        <div id="wheel-chassis-wrap" style="margin-bottom: 1em;"></div>
        <?php submit_button( __( 'Save Chassis', 'text-domain'), 'primary', 'submit', false, ['id' => 'wheel-save-chassis', 'style' => 'float:left'] ); ?>
        <br class="clear"/>
        <?php
    }

    public function wheel_create_listings()
    {
        ?>
        <p>When you have selected and saved your Brand, Manufacturers and Chassis above, click Create Listings below to continue...</p>
        <?php submit_button( __( 'Create Wheel Listings', 'text-domain'), 'primary', 'submit', false, ['id' => 'wheel-create-listings', 'style' => 'float:left'] ); ?>
        <br class="clear"/>
        <?php
    }

    public function wheel_listings_meta_box()
    {
        ?>
        <table id="example" data-type="wheel" class="display" style="width:100%">
            <thead>
            <tr>
                <th>Title</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Listing ID</th>
                <th></th>
            </tr>
            </thead>
            <tbody>

            </tbody>
            <tfoot>
            <tr>
                <th>Title</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Listing ID</th>
                <th></th>
            </tr>
            </tfoot>
        </table>
        <?php
    }

    public function tyre_listings_meta_box()
    {
        ?>
        <table id="example" data-type="tyre" class="display" style="width:100%">
            <thead>
            <tr>
                <th>Title</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Listing ID</th>
                <th></th>
            </tr>
            </thead>
            <tbody>

            </tbody>
            <tfoot>
            <tr>
                <th>Title</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Listing ID</th>
                <th></th>
            </tr>
            </tfoot>
        </table>
        <?php
    }

    public function tyre_schedule_sync_meta_box()
    {
        $hourly_hook = Fbf_Ebay_Packages_Cron::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK;
        $schedule = wp_get_schedule($hourly_hook);
        $next_schedule = wp_next_scheduled($hourly_hook);
        $date = new DateTime();
        $timezone = new DateTimeZone("Europe/London");
        $date->setTimezone($timezone);
        $date->setTimestamp(absint($next_schedule));
        $next = $date->format('g:iA - jS F Y') . "\n";
        $synchronisations_to_show = 5;
        ?>
        <p>Upcoming scheduled synchronisation: <strong><?=$next?></strong> <em>&lt;<?=$schedule?>&gt;</em></p>

        <h2>Previous <?=$synchronisations_to_show?> events:</h2>
        <table id="fbf_ep_event_log_table" class="display">
            <thead>
            <tr>
                <th>Date/time</th>
                <th>Hook</th>
                <th>Execution time</th>
            </tr>
            </thead>
            <tbody>
            </tbody>
            <tfoot>
            <tr>
                <th>Date/time</th>
                <th>Hook</th>
                <th>Execution time</th>
            </tr>
            </tfoot>
        </table>
        <button role="button" class="button button-primary" id="fbf_ebay_packages_synchronise" style="margin-top: 1em;" type="button">Synchronise with eBay</button>
        <button role="button" class="button button-primary" id="fbf_ebay_packages_clean" style="margin-top: 1em;" type="button">Clean eBay</button>
        <span class="spinner" style="margin-top: 1.2em;"></span>
        <br class="clear"/>
        <?php
    }

    public function wheel_schedule_sync_meta_box()
    {
        $hourly_hook = Fbf_Ebay_Packages_Cron::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK;
        $schedule = wp_get_schedule($hourly_hook);
        $next_schedule = wp_next_scheduled($hourly_hook);
        $date = new DateTime();
        $timezone = new DateTimeZone("Europe/London");
        $date->setTimezone($timezone);
        $date->setTimestamp(absint($next_schedule));
        $next = $date->format('g:iA - jS F Y') . "\n";
        $synchronisations_to_show = 5;
        ?>
        <p>Upcoming scheduled synchronisation: <strong><?=$next?></strong> <em>&lt;<?=$schedule?>&gt;</em></p>

        <h2>Previous <?=$synchronisations_to_show?> events:</h2>
        <table id="fbf_ep_event_log_table" class="display">
            <thead>
            <tr>
                <th>Date/time</th>
                <th>Hook</th>
                <th>Execution time</th>
            </tr>
            </thead>
            <tbody>
            </tbody>
            <tfoot>
            <tr>
                <th>Date/time</th>
                <th>Hook</th>
                <th>Execution time</th>
            </tr>
            </tfoot>
        </table>
        <input type="text" placeholder="Test SKU's" id="fbf_ebay_packages_skus" name="fbf_ebay_packages_skus" style="margin-top: 1em;"/>
        <button role="button" class="button button-primary" id="fbf_ebay_packages_test_skus" style="margin-top: 1em;" type="button">Test Wheel</button>
        <button role="button" class="button button-primary" id="fbf_ebay_packages_synchronise" style="margin-top: 1em;" type="button">Synchronise with eBay</button>
        <!--<button role="button" class="button button-primary" id="fbf_ebay_packages_clean" style="margin-top: 1em;" type="button">Clean eBay</button>-->
        <span class="spinner" style="margin-top: 1.2em;"></span>
        <br class="clear"/>
        <?php
    }

    public function compatibility_meta_box()
    {
        global $wpdb;
        $fittings_table = $wpdb->prefix . 'fbf_ebay_packages_fittings';
        $q = "SELECT *
            FROM {$fittings_table}
            GROUP BY (chassis_id)
            ORDER by manufacturer_name ASC, chassis_name ASC";
        $r = $wpdb->get_results($q, ARRAY_A);

        if($r!==false&&!empty($r)){
            ?>
            <p>Please complete the Compatibility for each chassis below, this ensures that Wheels are linked to the correct Vehicles on eBay:</p>
            <?php
            foreach($r as $result){
                $this->compatibility_selector($result);
            }
        }else{
            ?>
            <p>There are no chassis to manage - nothing to see here.</p>
            <?php
        }
    }

    public function tyre_listing_log_detail()
    {
        ?>
        <table id="fbf_ep_event_log_detail" class="display" style="width:100%">
            <thead>
            <tr>
                <th>Date/Time</th>
                <th>Type</th>
                <th>Status</th>
                <th>Response Code</th>
                <th></th>
            </tr>
            </thead>
            <tbody>

            </tbody>
            <tfoot>
            <tr>
                <th>Date/Time</th>
                <th>Type</th>
                <th>Status</th>
                <th>Response Code</th>
                <th></th>
            </tr>
            </tfoot>
        </table>
        <?php
    }

    private function compatibility_selector($result)
    {
        global $wpdb;
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        echo '<hr/>';
        printf('<p><strong>%s - %s</strong> - <a href="#" data-chassis-id="%s" data-chassis-name="%1$s - %2$s" class="add-compatibility">Add</a> <a href="#" data-chassis-id="%3$s" data-chassis-name="%1$s - %2$s" class="tb_compat_delete_all">Remove all</a></p>', $result['manufacturer_name'], $result['chassis_name'], $result['chassis_id']);

        // Get existing compatibility
        $q = $wpdb->prepare("SELECT *
            FROM {$compatibility_table}
            WHERE chassis_id = %s", $result['chassis_id']);
        $r = $wpdb->get_results($q, ARRAY_A);
        printf('<ul id="tb_compat_chassis_%s" data-id="%1$s">', $result['chassis_id']);
        if($r!==false&&!empty($r)){
            foreach($r as $result){
                $payload = unserialize($result['payload']);
                $values = array_column($payload, 'value');
                printf('<li style="display: inline-block; margin-right: 0.5em;">%s<a class="tb_compat_delete dashicons dashicons-no-alt" data-name="%1$s" data-id="%s" href="#"></a></li>', implode(', ', $values), $result['id']);
            }
        }
        echo '</ul>';
    }

    public function page_id(){
        return 'ebay-packages_page_fbf-ebay-packages-tyres';
    }

    public function wheel_page_id(){
        return 'ebay-packages_page_fbf-ebay-packages-wheels';
    }

    public function compatibility_page_id(){
        return 'ebay-packages_page_fbf-ebay-packages-compatibility';
    }

    public function acf_relationship_result($text, $post, $field, $post_id)
    {
        $product = wc_get_product($post->ID);
        $str = '';
        if($field['key']==='field_601414bccb224'||$field['key']==='field_601414fccb225'||$field['key']==='field_605e087c6013a') {
            $p = $post_id;
            if(has_term('steel-wheel', 'product_cat', $post->ID)){
                $centre_bore_terms = get_the_terms($post->ID, 'pa_centre-bore');
                $centre_bore = $centre_bore_terms[0]->name;
                $pcd_terms = get_the_terms($post->ID, 'pa_wheel-pcd');
                $pcd = $pcd_terms[0]->name;
                $sku = $product->get_sku();
                $str = sprintf(' - (SKU: %s, PCD: %s, Centre Bore: %s)', $sku, $pcd, $centre_bore );
            }else if(has_term('alloy-wheel', 'product_cat', $post->ID)){
                $pcd_terms = get_the_terms($post->ID, 'pa_wheel-pcd');
                $pcd = $pcd_terms[0]->name;
                $sku = $product->get_sku();
                $str = sprintf(' - (SKU: %s, PCD: %s)', $sku, $pcd );
            }else if(has_term('tyre', 'product_cat', $post->ID)){
                $sku = $product->get_sku();
                $str = sprintf(' - (SKU: %s)', $sku );
            }
        }
        return $text . $str;
    }

    public function acf_relationship($args, $field, $post_id)
    {
        $a = $args;
        if(!empty($args['s'])){
            // strip and quotes from search
            if(strpos($args['s'], '"')!==false){
                $args['s'] = str_replace('"', '', $args['s']);
            }
            // Is the search a valid SKU?
            $p = wc_get_product_id_by_sku($args['s']);


            if($p!==0){
                $args['meta_query'][] = [
                    'key' => '_sku',
                    'value' => $args['s'],
                    'compare' => 'LIKE'
                ];
                unset($args['s']);
            }
        }
        /*if($field['key']==='field_601414bccb224'||$field['key']==='field_601414fccb225'){
            $args['meta_query'][] = [
                'key'     => '_stock',
                'type'    => 'numeric',
                'value'   => 3,
                'compare' => '>'
            ];
        }*/
        if(isset($args['meta_query'])){
            $args['meta_query']['relation'] = 'AND';
        }
        return $args;
    }

    public function update_acf_settings_path( $path ) {
        $path = plugin_dir_path( __FILE__ ) . 'vendor/advanced-custom-fields/';
        return $path;
    }

    public function update_acf_settings_dir( $dir ) {
        $dir = plugin_dir_url( __FILE__ ) . 'vendor/advanced-custom-fields/';
        return $dir;
    }

    public function save_post( $post_id )
    {
        $p = $post_id;
        $fields = [];
        $field_mapping = [
            'field_601413b7cb221' => 'name',
            'field_60143699c805a' => 'image',
            'field_60141404cb222' => 'sku',
            'field_6014144ccb223' => 'qty',
            'field_601414bccb224' => 'tyre',
            'field_601414fccb225' => 'wheel',
            'field_601418a8cb226' => 'percentage'
        ];

        if(isset($_POST['acf'])){
            foreach($_POST['acf'] as $fk => $fv){
                if(key_exists($fk, $field_mapping)){
                    if($field_mapping[$fk]==='tyre'||$field_mapping[$fk]==='wheel'){
                        $fields[$field_mapping[$fk]] = $fv[0];
                    }else{
                        $fields[$field_mapping[$fk]] = $fv;
                    }
                }
            }
        }

        // Now create the product here
        $tyre = wc_get_product($fields['tyre']);
        $wheel = wc_get_product($fields['wheel']);

        $product_cats = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        if(array_search('package', array_column($product_cats, 'slug'))!==false){
            $term_key = array_search('package', array_column($product_cats, 'slug'));
            $term = $product_cats[$term_key];
        }else{
            if($term_insert = wp_insert_term( 'Package', 'product_cat', array(
                'description' => 'Package', // optional
                'parent' => 0, // optional
                'slug' => 'package' // optional
            ))){
                $term = get_term_by('id', $term_insert['term_id'], 'product_cat');
            }

        }

        // Check if SKU exists
        if(!empty($fields['sku'])){
            if(wc_get_product_id_by_sku($fields['sku'])){
                $this->errors[] = 'SKU must be unique';
            }
        }


        // Check the stock
        $stock = min($tyre->get_stock_quantity(), $wheel->get_stock_quantity());
        // Need to be able to create stock even if on backorder - so comment out
        /*if($stock < $fields['qty']){
            $this->errors[] = 'Not enough stock';
        }*/

        if(!empty($this->errors)){
            wp_redirect(get_admin_url() . 'admin.php?page=fbf-ebay-packages-settings&fbf_ebay_packages_status=error&fbf_ebay_packages_message=' . $this->get_errors());
            exit;
        }

        // Create product
        $product = new WC_Product();
        $product->set_name($fields['name']);
        $product->set_category_ids([$term->term_id]);
        //$product->set_attributes($attrs);
        $prod_id = $product->save();

        // Copy attributes from tyre and wheel
        $tyre_attributes = $tyre->get_attributes();
        $wheel_attributes = $wheel->get_attributes();
        $att = [];
        $attrs = [];
        $attrs_a = [];
        foreach($tyre_attributes as $ak => $av){
            $attrs[] = $av;
        }
        foreach($wheel_attributes as $ak => $av){
            $attrs[] = $av;
        }
        foreach($attrs as $av){
            if($av->get_id()){
                $terms = $av->get_terms();
                foreach($terms as $term){
                    $attrs_a[$term->taxonomy][] = $term->name;
                }
            }
        }
        foreach($attrs_a as $tax => $name){
            wp_set_object_terms( $prod_id, $name, $tax, true );
            $att[$tax] = [
                'name' => $tax,
                'value' => $name,
                'is_visible' => '0',
                'is_taxonomy' => '1'
            ];
        }
        update_post_meta( $prod_id, '_product_attributes', $att);

        // Set the stock level
        $product->set_manage_stock(true);
        $product->set_stock_quantity(floor($stock/$fields['qty'])); // E.g. if the package qty is 4 - stock level for package is the minimum stock divided by 4

        // Set the price
        $price = ($tyre->get_price() * $fields['qty']) + ($wheel->get_price() * $fields['qty']);
        $price = $price + (($price/100) * $fields['percentage']);
        $product->update_meta_data('_fbf_ebay_packages_percentage', $fields['percentage']); // Need to set this because when we update prices the same calculation as above needs to be done
        $product->update_meta_data('_fbf_ebay_packages_qty', $fields['qty']); // Will also need this to work out if Package should be hidden
        $product->set_regular_price($price);

        // Set the photo
        $product->set_image_id($fields['image']);

        // Set the description
        $content = sprintf('%s x %s<br/>', $fields['qty'], $tyre->get_name());
        $content.= sprintf('%s x %s<br/>', $fields['qty'], $wheel->get_name());
        $edited_post = [
            'ID' => $prod_id,
            'post_content' => $content
        ];
        $edit = wp_update_post( $edited_post);

        // Set meta
        $linked = [
            'tyre' => $fields['tyre'],
            'wheel' => $fields['wheel']
        ];
        $product->update_meta_data('_fbf_ebay_packages_linked', $linked);

        // Set SKU
        if(!empty($fields['sku'])){
            $product->set_sku($fields['sku']);
        }else{
            $sku = sprintf($fields['qty'] . '^' . $tyre->get_sku() . '^' . $fields['qty'] . '^' . $wheel->get_sku());
            $product->set_sku($sku);
        }

        // Set yoast values
        update_post_meta($prod_id, '_yoast_wpseo_meta-robots-noindex', true);
        update_post_meta($prod_id, '_yoast_wpseo_meta-robots-nofollow', true);
        update_post_meta($prod_id, '_relevanssi_noindex_reason', 'eBay Package');

        // Backorders off
        $product->set_backorders('no');

        // Not in google product feed
        update_post_meta($prod_id, '_woocommerce_gpf_data', [
            'exclude_product' => 'on'
        ]);

        $product->save();

        $package_link = urlencode(get_admin_url() . 'post.php?post=' . $prod_id . '&action=edit');

        wp_redirect(sprintf(get_admin_url() . 'admin.php?page=fbf-ebay-packages-settings&fbf_ebay_packages_status=success&fbf_ebay_packages_message=%s&fbf_ebay_packages_link=%s', 'The%20eBay%20Package%20was%20created', $package_link));
    }

    public function setup_options()
    {
        if( function_exists('acf_add_local_field_group') ):

            acf_add_local_field_group(array(
                'key' => 'group_601413ae313d1',
                'title' => 'eBay meta',
                'fields' => array(
                    array(
                        'key' => 'field_601413b7cb221',
                        'label' => 'Package name',
                        'name' => 'package_name',
                        'type' => 'text',
                        'instructions' => 'Enter the Package title, this is how the package will be identified and ultimately it\'s name on eBay',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'default_value' => '',
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'maxlength' => '80',
                    ),
                    array(
                        'key' => 'field_60143699c805a',
                        'label' => 'Image',
                        'name' => 'image',
                        'type' => 'image',
                        'instructions' => 'Upload an image for the Package or choose an existing one from the Media Library',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'return_format' => 'array',
                        'preview_size' => 'medium',
                        'library' => 'all',
                        'min_width' => '',
                        'min_height' => '',
                        'min_size' => '',
                        'max_width' => '',
                        'max_height' => '',
                        'max_size' => '',
                        'mime_types' => '',
                    ),
                    array(
                        'key' => 'field_60141404cb222',
                        'label' => 'SKU',
                        'name' => 'sku',
                        'type' => 'text',
                        'instructions' => 'Enter a unique SKU for the Package. If this field is left empty a SKU will be created using the combined quantity and SKU of the Tyre and Wheel selected',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'default_value' => '',
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'maxlength' => '',
                    ),
                    array(
                        'key' => 'field_6014144ccb223',
                        'label' => 'Quantity',
                        'name' => 'quantity',
                        'type' => 'number',
                        'instructions' => 'Enter the number of Tyres/Wheels in the Package. E.g. for a Package with 4 Wheels and 4 Tyres enter 4',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'default_value' => 1,
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'min' => 1,
                        'max' => 4,
                        'step' => '',
                    ),
                    array(
                        'key' => 'field_601414bccb224',
                        'label' => 'Tyre',
                        'name' => 'tyre',
                        'type' => 'relationship',
                        'instructions' => 'Select the Tyre for the Package',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'post_type' => array(
                            0 => 'product',
                        ),
                        'taxonomy' => array(
                            0 => 'product_cat:tyre',
                        ),
                        'filters' => array(
                            0 => 'search',
                        ),
                        'elements' => '',
                        'min' => 1,
                        'max' => 1,
                        'return_format' => 'object',
                    ),
                    array(
                        'key' => 'field_601414fccb225',
                        'label' => 'Wheel',
                        'name' => 'wheel',
                        'type' => 'relationship',
                        'instructions' => 'Select the Tyre for the Package',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'post_type' => array(
                            0 => 'product',
                        ),
                        'taxonomy' => array(
                            0 => 'product_cat:alloy-wheel',
                            1 => 'product_cat:steel-wheel',
                        ),
                        'filters' => array(
                            0 => 'search',
                        ),
                        'elements' => '',
                        'min' => 1,
                        'max' => 1,
                        'return_format' => 'object',
                    ),
                    array(
                        'key' => 'field_601418a8cb226',
                        'label' => 'Margin',
                        'name' => 'margin',
                        'type' => 'number',
                        'instructions' => 'This is the additional % that will be added to the combined price of the Tyre and Wheel',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'default_value' => 0,
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '%',
                        'min' => 0,
                        'max' => 100,
                        'step' => '',
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'post',
                        ),
                    ),
                ),
                'menu_order' => 0,
                'position' => 'normal',
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen' => '',
                'active' => true,
                'description' => '',
            ));

            acf_add_local_field_group(array(
                'key' => 'group_605e087c46123',
                'title' => 'eBay meta 2',
                'fields' => array(
                    array(
                        'key' => 'field_6014144ccb224',
                        'label' => 'Quantity',
                        'name' => 'quantity',
                        'type' => 'number',
                        'instructions' => 'Enter the number of Tyres.',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'default_value' => 1,
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'min' => 1,
                        'max' => 4,
                        'step' => '',
                    ),
                    array(
                        'key' => 'field_605e087c6013a',
                        'label' => 'Tyre',
                        'name' => 'tyre',
                        'type' => 'relationship',
                        'instructions' => 'Select the Tyre for the listing',
                        'required' => 1,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'post_type' => array(
                            0 => 'product',
                        ),
                        'taxonomy' => array(
                            0 => 'product_cat:tyre',
                        ),
                        'filters' => array(
                            0 => 'search',
                        ),
                        'elements' => '',
                        'min' => 1,
                        'max' => 1,
                        'return_format' => 'object',
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'post',
                        ),
                    ),
                ),
                'menu_order' => 0,
                'position' => 'normal',
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen' => '',
                'active' => true,
                'description' => '',
            ));

        endif;
    }
}


