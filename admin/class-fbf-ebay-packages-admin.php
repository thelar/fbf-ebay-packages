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
	public function enqueue_styles() {

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

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/fbf-ebay-packages-admin.css', array(), $this->version, 'all' );

        do_action('acf/input/admin_enqueue_scripts'); // Add ACF scripts

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

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

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/fbf-ebay-packages-admin.js', array( 'jquery' ), $this->version, false );
        $ajax_params = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'ajax_nonce' => wp_create_nonce('4x4_nonce'),
            'acf_nonce' => wp_create_nonce('acf_nonce'),
        );
        wp_localize_script($this->plugin_name, 'ajax_object', $ajax_params);
        do_action('acf/input/admin_head'); // Add ACF admin head hooks
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
            'manage_options',
            $this->plugin_name . '-settings',
            [$this, 'add_package'],
            'dashicons-admin-tools'
        );
        /*add_submenu_page(
            $this->plugin_name,
            __('eBay Packages dashboard', 'fbf-ebay'),
            __('Dashboard', 'fbf-ebay'),
            'manage_options',
            $this->plugin_name,
            [$this, 'display_front_page']
        );*/
        /*add_submenu_page(
            $this->plugin_name,
            __('eBay Packages add package', 'fbf-ebay'),
            __('Add package', 'fbf-ebay'),
            'manage_options',
            $this->plugin_name . '-settings',
            [$this, 'add_package']
        );*/
    }

    /**
     * Render the front page for plugin
     *
     * @since  1.0.0
     */
    public function display_front_page() {
        echo '<div class="wrap">';
        echo '<h2>eBay Packages - dashboard</h2>';
        echo '</div>';
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
        if(wc_get_product_id_by_sku($fields['sku'])){
            $this->errors[] = 'SKU must be unique';
        }

        // Check the stock
        $stock = min($tyre->get_stock_quantity(), $wheel->get_stock_quantity());
        if($stock < $fields['qty']){
            $this->errors[] = 'Not enough stock';
        }

        if(!empty($this->errors)){
            wp_redirect(get_admin_url() . 'admin.php?page=fbf-ebay-packages-settings&fbf_ebay_packages_status=error&fbf_ebay_packages_message=' . $this->get_errors());
            exit;
        }

        // Create product
        $product = new WC_Product();
        $product->set_name($fields['name']);
        $product->set_sku($fields['sku']);
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
        $product->set_stock_quantity($stock);

        // Set the price
        $price = ($tyre->get_price() * $fields['qty']) + ($wheel->get_price() * $fields['qty']);
        $price = $price + (($price/100) * $fields['percentage']);
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

        $product->save();

        $package_link = urlencode(get_admin_url() . 'post.php?post=' . $prod_id . '&action=edit');

        wp_redirect(sprintf(get_admin_url() . 'admin.php?page=fbf-ebay-packages-settings&fbf_ebay_packages_status=success&fbf_ebay_packages_message=%s&fbf_ebay_packages_link=%s', 'Saved', $package_link));
    }

    public function update_acf_settings_path( $path ) {
        $path = plugin_dir_path( __FILE__ ) . 'vendor/advanced-custom-fields/';
        return $path;
    }

    public function update_acf_settings_dir( $dir ) {
        $dir = plugin_dir_url( __FILE__ ) . 'vendor/advanced-custom-fields/';
        return $dir;
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
                        'maxlength' => '',
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

        endif;
    }

    private function get_errors()
    {
        return urlencode(implode('<br/>', $this->errors));
    }
}
