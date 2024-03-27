<?php


class Fbf_Ebay_Packages_Admin_Ajax
{
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
    }

    public function fbf_ebay_packages_get_brands()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $query = filter_var($_REQUEST['q'], FILTER_SANITIZE_STRING);
        $data = [];

        $all_brands = get_terms([
            'taxonomy' => 'pa_brand-name',
            'hide_empty' => false,
            'name__like' => $query
        ]);

        foreach($all_brands as $brand){
            $data[] = [$brand->term_id, $brand->name];
        }

        echo json_encode($data);
        die();
    }

    public function fbf_ebay_packages_get_manufacturers()
    {
        global $wpdb;
        $data = [];
        $search = filter_var($_REQUEST['q'], FILTER_SANITIZE_STRING);
        $table = $wpdb->prefix . 'fbf_vehicle_manufacturers';

        $q = "SELECT * FROM {$table} WHERE enabled = %s";
        if(!empty($search)){
            $q.= " AND display_name LIKE %s";
            $p = $wpdb->prepare($q, true, '%' . $search . '%');
        }else{
            $p = $wpdb->prepare($q, true);
        }

        $r = $wpdb->get_results($p, ARRAY_A);
        if($r!==false&&!empty($r)){
            foreach($r as $result){
                $data[] = [
                    $result['boughto_id'],
                    $result['display_name']
                ];
            }
        }
        echo json_encode($data);
        die();
    }

    public function fbf_ebay_packages_brand_confirm()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');

        $save_brands = [];
        $search_brands = [];
        $resp = [];

        if(isset($_REQUEST['brands']) && is_array($_REQUEST['brands'])){
            foreach($_REQUEST['brands'] as $brand){
                $brand_id = filter_var($brand, FILTER_SANITIZE_STRING);
                $term = get_term_by('ID', $brand, 'pa_brand-name');

                $save_brands[] = [
                    'ID' => $brand_id,
                    'name' => $term->name,
                ];

                $search_brands[] = $brand_id;
            }
        }

        //Save the brands if set
        if(!empty($save_brands)){
            $update_option = update_option('_fbf_ebay_packages_tyre_brands', $save_brands);
        }else{
            $resp['status'] = 'error';
            $resp['errors'] = [
                'You have not selected a Tyre Brand'
            ];

            echo json_encode($resp);
            die();
        }

        //Gather information about what we are doing here and report back to get confirmation to proceed
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '=',
                ],
                [
                    'key' => '_stock_status',
                    'value' => 'onbackorder',
                    'compare' => '=',
                ]
            ],
            /*'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_stock',
                    'type' => 'numeric',
                    'value' => 4,
                    'compare' => '>'
                ],
            ],*/ // Handle the stock status for the query later
            'tax_query' => [
                'relation' => 'AND',
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => 'tyre'
                ],
                [
                    'taxonomy' => 'pa_brand-name',
                    'field' => 'id',
                    'terms' => $search_brands,
                    'operator' => 'IN'
                ],
                [
                    'taxonomy' => 'pa_list-on-ebay',
                    'field' => 'slug',
                    'terms' => 'true'
                ]
            ]
        ];

        $found = new WP_Query($args);
        if($found->post_count){
            $resp = [
                'status' => 'success',
                'message' => sprintf('You are about to create eBay Listings for %s Tyres, please confirm you wish to do this...', $found->post_count)
            ];
        }else{
            $resp = [
                'status' => 'error',
                'errors' => [
                    'No Tyres found for the selected brand'
                ]
            ];
        }

        echo json_encode($resp);
        die();
    }

    public function fbf_ebay_packages_ebay_listing()
    {
        $a = 1;
        $draw = $_REQUEST['draw'];
        $start = $_REQUEST['start'];
        $length = $_REQUEST['length'];
        $recordsFiltered = $length;
        $data = [];

        $paged = ($start/$length) + 1;

        $args = [
            'post_type' => 'product',
            'paged' => $paged,
            'posts_per_page' => $length,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_stock',
                    'type' => 'numeric',
                    'value' => 4,
                    'compare' => '>'
                ],
            ],
            'tax_query' => [
                'relation' => 'AND',
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => 'tyre'
                ]
            ]
        ];

        if(isset($_REQUEST['search']['value'])&&!empty($_REQUEST['search']['value'])){
            $meta = [
                'relation' => 'OR',
                [
                    'key' => '_sku',
                    'value' => $_REQUEST['search']['value'],
                    'compare' => 'LIKE'
                ],
                [
                    'key' => '_fbf_product_title',
                    'value' => $_REQUEST['search']['value'],
                    'compare' => 'LIKE'
                ]
            ];
            $args['meta_query'][] = $meta;
        }


        $products = new WP_Query($args);
        if($products->have_posts()){
            while($products->have_posts()){
                $products->the_post();
                $product = wc_get_product(get_the_ID());
                $record = [
                    $product->get_title(),
                    $product->get_sku(),
                    $product->get_stock_quantity(),
                    '',
                ];
                $data[] = $record;
            }
        }

        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $products->found_posts,
            'recordsFiltered' => $products->found_posts,
            'data' => $data
        ]);

        die();
    }

    public function fbf_ebay_packages_wheel_brands_confirm()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');

        $save_brands = [];
        $search_brands = [];

        if(isset($_REQUEST['brands']) && is_array($_REQUEST['brands'])){
            foreach($_REQUEST['brands'] as $brand){
                $brand_id = filter_var($brand, FILTER_SANITIZE_STRING);
                $term = get_term_by('ID', $brand, 'pa_brand-name');

                $save_brands[] = [
                    'ID' => $brand_id,
                    'name' => $term->name,
                ];

                $search_brands[] = $brand_id;
            }
        }

        //Save the brands if set
        if(!empty($save_brands)){
            $update_option = update_option('_fbf_ebay_packages_wheel_brands', $save_brands);
        }else{
            $resp['status'] = 'error';
            $resp['msg'] = 'You have not selected a Wheel Brand';

            echo json_encode($resp);
            die();
        }

        echo json_encode([
            'status' => 'success',
            'msg' => 'Wheel Brands saved successfully'
        ]);
        die();
    }

    public function fbf_ebay_packages_wheel_manufacturers_confirm()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');

        $save_manufacturers = [];
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_vehicle_manufacturers';
        $saved_chassis = get_option('_fbf_ebay_packages_chassis');
        $manufacturer_ids = [];
        if(!empty($saved_chassis)){
            foreach($saved_chassis as $ck => $cv){
                $manufacturer_ids[] = $ck;
            }
        }


        if(isset($_REQUEST['manufacturers']) && is_array($_REQUEST['manufacturers'])) {
            foreach ($_REQUEST['manufacturers'] as $manufacturer) {
                $q = $wpdb->prepare("SELECT display_name
                    FROM {$table}
                    WHERE boughto_id = %s", $manufacturer);
                $r = $wpdb->get_row($q, ARRAY_A);
                if($r!==false&&!empty($r)){
                    $save_manufacturers[] = [
                        'ID' => $manufacturer,
                        'name' => $r['display_name']
                    ];
                    if (($key = array_search($manufacturer, $manufacturer_ids)) !== false) {
                        unset($manufacturer_ids[$key]);
                    }
                }
            }
        }

        //Remove any ids that are left in $manufacturer_ids and re-save chassis option
        if(!empty($manufacturer_ids)){
            foreach($manufacturer_ids as $manu){
                unset($saved_chassis[$manu]);
            }
            update_option('_fbf_ebay_packages_chassis', $saved_chassis);
        }

        //Save the brands if set
        if(!empty($save_manufacturers)){
            $update_option = update_option('_fbf_ebay_packages_wheel_manufacturers', $save_manufacturers);
        }else{
            $resp['status'] = 'error';
            $resp['msg'] = 'You have not selected a Manufacturer';

            echo json_encode($resp);
            die();
        }

        echo json_encode([
            'status' => 'success',
            'msg' => 'Wheel Manufacturers saved successfully'
        ]);
        die();
    }

    /**
     * This function gets all the chassis for the saved manufacturers
     */
    public function fbf_ebay_packages_wheel_get_chassis()
    {
        $manufacturer_data = [];
        //$saved_chassis = get_option('_fbf_ebay_packages_chassis');
        $all_saved_chassis = get_option('_fbf_ebay_packages_all_chassis');

        if (is_plugin_active('fbf-wheel-search/fbf-wheel-search.php')) {
            require_once plugin_dir_path(WP_PLUGIN_DIR . '/fbf-wheel-search/fbf-wheel-search.php') . 'includes/class-fbf-wheel-search-boughto-api.php';
            $api = new Fbf_Wheel_Search_Boughto_Api('fbf_wheel_search', 'fbf-wheel-search');

            $manufacturers = get_option('_fbf_ebay_packages_wheel_manufacturers');
            if(!empty($manufacturers)){
                foreach($manufacturers as $manufacturer){
                    $data = $api->get_chasis($manufacturer['ID']);
                    //$manufacturer_saved_chassis = $saved_chassis[$manufacturer['ID']];
                    $all_manufacturer_saved_chassis = array_column($all_saved_chassis[$manufacturer['ID']], 'id');

                    if(!empty($data)&&!array_key_exists('error', $data)){
                        $all_chassis = [];
                        $i = 0;
                        foreach($data as $chassis){
                            if(strpos(strtolower($chassis['generation']['start_date']), 'hidden')===false){
                                $ds = DateTime::createFromFormat('Y-m-d', $chassis['generation']['start_date']);
                                $de = DateTime::createFromFormat('Y', $chassis['generation']['end_date']);
                                if($ds){
                                    $data[$i]['ds'] = $ds->format('Y');
                                }
                                if($de){
                                    $data[$i]['de'] = $de->format('Y');
                                }
                            }else{
                                unset($data[$i]);
                            }
                            $i++;
                        }

                        if(!empty($data)){
                            usort($data, function($a, $b){
                                return [$a['chassis']['display_name'], $b['ds']] <=> [$b['chassis']['display_name'], $a['ds']];
                            });
                        }

                        foreach($data as $chassis){
                            $all_chassis[] = [
                                'name' => $chassis['chassis']['display_name'],
                                'ID' => $chassis['chassis']['id'],
                                'selected' => in_array($chassis['chassis']['id'], $all_manufacturer_saved_chassis)?'selected':''
                            ];
                        }

                        $manufacturer_data[] = [
                            'name' => $manufacturer['name'],
                            'ID' => $manufacturer['ID'],
                            'chassis' => $all_chassis
                        ];
                    }
                }
            }
        }

        $a = 1;

        echo json_encode([
            'status' => 'success',
            'data' => $manufacturer_data
        ]);
        die();
    }

    public function fbf_ebay_packages_save_chassis()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $chassis = $_REQUEST['chassis'];
        $all_chassis = $_REQUEST['all_chassis_data'];
        if(!empty($chassis)){
            $update = update_option('_fbf_ebay_packages_chassis', $chassis);
            $update = update_option('_fbf_ebay_packages_all_chassis', $all_chassis);
        }
        if($update){
            echo json_encode([
                'status' => 'success',
                'msg' => 'Chassis successfully saved',
            ]);
        }else{
            echo json_encode([
                'status' => 'error',
                'msg' => 'Chassis not saved, there may be no changes',
            ]);
        }

        die();
    }

    public function fbf_ebay_packages_wheel_create_listings()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        global $wpdb;

        if (is_plugin_active('fbf-wheel-search/fbf-wheel-search.php')) {
            require_once plugin_dir_path(WP_PLUGIN_DIR . '/fbf-wheel-search/fbf-wheel-search.php') . 'includes/class-fbf-wheel-search-boughto-api.php';
            $api = new Fbf_Wheel_Search_Boughto_Api('fbf_wheel_search', 'fbf-wheel-search');
            $chassis = get_option('_fbf_ebay_packages_chassis');
            $all_chassis = get_option('_fbf_ebay_packages_all_chassis');
            $brands = get_option('_fbf_ebay_packages_wheel_brands');
            $search_brands = [];
            if(!empty($brands)){
                foreach($brands as $brand){
                    $search_brands[] = $brand['ID'];
                }
            }

            $manufacturers_table = $wpdb->prefix . 'fbf_vehicle_manufacturers';
            $wheels = [];
            $post__in = [];
            $chassis_lookup = [];

            // Just build a nicer array for manufacturer/chassis reference
            foreach($all_chassis as $mk => $chassis_array){
                // Get the manufacturer name from the id here
                $q = $wpdb->prepare("SELECT *
                    FROM {$manufacturers_table}
                    WHERE boughto_id = %s", $mk);
                $r = $wpdb->get_row($q, ARRAY_A);
                if($r!==false&&!empty($r)){
                    $manufacturer_name = $r['name'];
                    $manufacturer_id = $r['boughto_id'];
                }

                foreach($chassis_array as $manu){
                    $wheel_data = $api->get_wheels($manu['id']);
                    $skus_ids = [];
                    if(!is_wp_error($wheel_data)&&!array_key_exists('error', $wheel_data)){
                        foreach($wheel_data['results'] as $wheel){
                            $product_id = wc_get_product_id_by_sku($wheel['product_code']);
                            if($product_id){
                                $product = wc_get_product($product_id);
                                if($product->is_in_stock()){
                                    $skus_ids[] = $product_id;
                                }
                            }
                        }
                    }

                    $wheels[$manu['id']] = [
                        'manufacturer_id' => $mk,
                        'manufacturer_name' => $manufacturer_name,
                        'chassis_id' => $manu['id'],
                        'chassis_name' => $manu['name'],
                        'wheel_ids' => $skus_ids
                    ];

                    $chassis_lookup[$manu['id']] = [
                        'manufacturer_name' => $manufacturer_name,
                        'manufacturer_id' => $manufacturer_id,
                        'chassis_name' => $manu['name'],
                    ];
                }

                // Now organise into wheel_ids
                $listings = [];
                foreach($wheels as $wheel){
                    foreach($wheel['wheel_ids'] as $post_id){
                        $listings[$post_id][] = $wheel['chassis_id'];
                    }
                }
            }

            $post__in = array_keys($listings);
            // Now run WP_Query to filter against the brands
// First get all matching Tyre SKU's from the product posts
            $args = [
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post__in' => $post__in,
                'fields' => 'ids',
                /*'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => '_stock',
                        'type' => 'numeric',
                        'value' => 4,
                        'compare' => '>'
                    ],
                ],*/ // Handle the stock status for the query later
                'tax_query' => [
                    'relation' => 'AND',
                    [
                        'taxonomy' => 'pa_brand-name',
                        'field' => 'id',
                        'terms' => $search_brands,
                        'operator' => 'IN'
                    ],
                    /*[
                        'taxonomy' => 'pa_list-on-ebay',
                        'field' => 'slug',
                        'terms' => 'true'
                    ]*/
                ]
            ];
            $found = new WP_Query($args);

            // Remove posts from $listings that aren't in $found->posts
            foreach($listings as $lk => $listing){
                if(!in_array($lk, $found->posts)){
                    unset($listings[$lk]);
                }
            }

            echo json_encode([
                'status' => 'success',
                'wheel_count' => count($listings),
                'wheels_listings' => $listings,
                'post__in' => $post__in,
                'chassis_lookup' => $chassis_lookup
            ]);
        }



        die();
    }

    public function fbf_ebay_packages_wheel_confirm_listings()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        global $wpdb;
        $listings_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $fittings_table = $wpdb->prefix . 'fbf_ebay_packages_fittings';
        $listings = json_decode(stripslashes($_REQUEST['listings']));
        $chassis_lookup = json_decode(stripslashes($_REQUEST['chassis_lookup']));
        $errors = [];
        $warnings = [];
        $post_skus = [];
        $post_lookup = [];

        if(!empty($listings)){
            foreach($listings as $lk => $listing) {
                $sku = get_post_meta($lk, '_sku', true);
                $post_skus[] = $sku;
                $post_lookup[$sku] = [
                    'title' => get_the_title($lk),
                    'qty' => get_post_meta($lk, '_stock', true),
                    'id' => $lk
                ];
            }
        }else{
            $errors[] = 'listings empty';
        }

        // Get all the currently active Wheel listings
        $q = $wpdb->prepare("SELECT s.sku
            FROM {$listings_table} l
            INNER JOIN {$skus_table} s
                ON s.listing_id = l.id
            WHERE l.status = %s
            AND l.type = %s", 'active', 'wheel');
        $results = $wpdb->get_col( $q );
        if($results==false||count($results)===0){
            $warnings[] = 'No current active tyre listings';
        }

        $to_create = array_diff($post_skus, $results); // These SKU's do NOT exist in the found set so either need to be activated (if they are present but inactive) OR created if they don't exist at all
        $to_leave = array_intersect($post_skus, $results); // These SKU's exist and are active - nothing to do here
        $to_deactivate = array_diff($results, $post_skus);

        if(!empty($to_create)){
            foreach($to_create as $sku){
                $fittings = null;
                // Check to see if the SKU exists - if it does just activate it
                $q = $wpdb->prepare("SELECT l.id, s.sku
                    FROM {$listings_table} l
                    INNER JOIN {$skus_table} s
                        ON s.listing_id = l.id
                    WHERE s.sku = %s
                    AND l.type = %s", $sku, 'wheel');
                $result = $wpdb->get_row($q, ARRAY_A);
                if(null !== $result){
                    // Exists - make it active
                    $u = $wpdb->update($listings_table,
                        [
                            'status' => 'active'
                        ],
                        [
                            'id' => $result['id']
                        ]
                    );
                    if($u!==false){
                        $this->log('Listing activated', $result['id']);
                    }else{
                        $errors[] = $wpdb->last_error;
                    }

                    // Delete any fittings that exist
                    $df = $wpdb->delete(
                        $fittings_table,
                        [
                            'listing_id' => $result['id']
                        ]
                    );

                    // Insert fitting info
                    $fittings = $listings->{$post_lookup[$sku]['id']};
                    if(!is_null($fittings)){
                        foreach($fittings as $fitting){
                            $di = $wpdb->insert(
                                $fittings_table,
                                [
                                    'listing_id' => $result['id'],
                                    'chassis_id' => $fitting,
                                    'chassis_name' => $chassis_lookup->{$fitting}->chassis_name,
                                    'manufacturer_id' => $chassis_lookup->{$fitting}->manufacturer_id,
                                    'manufacturer_name' => $chassis_lookup->{$fitting}->manufacturer_name,
                                ]
                            );
                        }
                    }
                }else{
                    // Does not exist - create it
                    $i = $wpdb->insert(
                        $listings_table,
                        [
                            'name' => $post_lookup[$sku]['title'],
                            'post_id' => $post_lookup[$sku]['id'],
                            'status' => 'active',
                            'type' => 'wheel',
                            'qty' => $post_lookup[$sku]['qty']
                        ]
                    );
                    if($i!==false){
                        // Insert the SKU
                        $insert_id = $wpdb->insert_id;
                        $i = $wpdb->insert(
                            $skus_table,
                            [
                                'sku' => $sku,
                                'listing_id' => $insert_id
                            ]
                        );
                        if($i===false){
                            $errors[] = $wpdb->last_error;
                        }else{
                            // Add log
                            $this->log('Listing created', $insert_id);
                        }

                        // Insert fitting info
                        $fittings = $listings->{$post_lookup[$sku]['id']};
                        if(!is_null($fittings)){
                            foreach($fittings as $fitting){
                                $di = $wpdb->insert(
                                    $fittings_table,
                                    [
                                        'listing_id' => $insert_id,
                                        'chassis_id' => $fitting,
                                        'chassis_name' => $chassis_lookup->{$fitting}->chassis_name,
                                        'manufacturer_id' => $chassis_lookup->{$fitting}->manufacturer_id,
                                        'manufacturer_name' => $chassis_lookup->{$fitting}->manufacturer_name,
                                    ]
                                );
                            }
                        }
                    }else{
                        $errors[] = $wpdb->last_error;
                    }
                }
            }
        }

        if(!empty($to_deactivate)){
            // Set status to inactive on all
            foreach($to_deactivate as $sku_to_deactivate){
                $s = $wpdb->prepare("SELECT l.id 
                    FROM {$listings_table} l 
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    WHERE s.sku = %s
                    AND l.type = %s", $sku_to_deactivate, 'wheel');
                $id = $wpdb->get_row($s, ARRAY_A)['id'];
                if($id!==null){
                    $q = $wpdb->prepare("UPDATE {$listings_table} l
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    SET l.status = %s
                    WHERE s.sku = %s
                    AND l.type = %s", 'inactive', $sku_to_deactivate, 'wheel');
                    $result = $wpdb->query($q);

                    if($result!==false){
                        $this->log('Listing deactivated', $id);
                    }else{
                        $errors[] = 'Could not deactivate listing: ' . $id;
                    }

                    // Delete fittings
                    $df = $wpdb->delete(
                        $fittings_table,
                        [
                            'listing_id' => $id
                        ]
                    );
                }else{
                    $errors[] = 'Could not find listing for SKU: ' . $sku_to_deactivate;
                }
            }
        }

        // Even if we are leaving the listings, still update the fitting info
        if(!empty($to_leave)){
            foreach($to_leave as $sku_to_change_fitting){
                $s = $wpdb->prepare("SELECT l.id 
                    FROM {$listings_table} l 
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    WHERE s.sku = %s
                    AND l.type = %s", $sku_to_change_fitting, 'wheel');
                $id = $wpdb->get_row($s, ARRAY_A)['id'];
                if($id!==null){
                    // Delete fittings
                    $df = $wpdb->delete(
                        $fittings_table,
                        [
                            'listing_id' => $id
                        ]
                    );
                    // Now create the fittings
                    $fittings = $listings->{$post_lookup[$sku_to_change_fitting]['id']};
                    if(!is_null($fittings)){
                        foreach($fittings as $fitting){
                            $di = $wpdb->insert(
                                $fittings_table,
                                [
                                    'listing_id' => $id,
                                    'chassis_id' => $fitting,
                                    'chassis_name' => $chassis_lookup->{$fitting}->chassis_name,
                                    'manufacturer_id' => $chassis_lookup->{$fitting}->manufacturer_id,
                                    'manufacturer_name' => $chassis_lookup->{$fitting}->manufacturer_name,
                                ]
                            );
                        }
                    }
                }else{
                    $errors[] = 'Could not find listing for SKU: ' . $sku_to_change_fitting;
                }
            }
        }

        echo json_encode([
            'status' => 'success'
        ]);
        die();
    }

    public function fbf_ebay_packages_tyre_table()
    {
        global $wpdb;
        $draw = $_REQUEST['draw'];
        $type = $_REQUEST['type'];
        $start = absint($_REQUEST['start']);
        $length = absint($_REQUEST['length']);
        $paged = ($start/$length) + 1;
        $data = [];


        $s = 'FROM wp_fbf_ebay_packages_listings l
            INNER JOIN wp_fbf_ebay_packages_skus s 
                ON s.listing_id = l.id
            WHERE l.status = %s
            AND l.type = %s';
        if(isset($_REQUEST['search']['value'])&&!empty($_REQUEST['search']['value'])){
            $s.= '
            AND (l.name LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) .'%\' OR s.sku LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) .'%\' OR l.listing_id LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) . '%\')';
        }
        if(isset($_REQUEST['order'][0]['column'])){
            $dir = $_REQUEST['order'][0]['dir'];
            if($_REQUEST['order'][0]['column']==='0'){
                $s.= '
                ORDER BY l.name ' . strtoupper($dir);
            }else if($_REQUEST['order'][0]['column']==='1'){
                $s.= '
                ORDER BY s.sku ' . strtoupper($dir);
            }else if($_REQUEST['order'][0]['column']==='2'){
                $s.= '
                ORDER BY l.qty ' . strtoupper($dir);
            }else if($_REQUEST['order'][0]['column']==='3'){
                $s.= '
                ORDER BY l.listing_id ' . strtoupper($dir);
            }
        }
        $s1 = 'SELECT count(*) as count
        ' . $s;

        $main_q = $wpdb->prepare("{$s1}", 'active', $type);
        $count = $wpdb->get_row($main_q, ARRAY_A)['count'];

        $s2 = 'SELECT *, l.listing_id as l_id
        ' . $s;
        $paginated_q = $wpdb->prepare("{$s2}
            LIMIT {$length}
            OFFSET {$start}", 'active', $type);
        $results = $wpdb->get_results($paginated_q, ARRAY_A);
        if($results!==false){
            foreach($results as $result){
                $data[] = [
                    'DT_RowId' => sprintf('row_%s', $result['id']),
                    'DT_RowClass' => 'dataTable_listing',
                    'DT_RowData' => [
                        'pKey' => $result['id']
                    ],
                    'name' => $result['name'],
                    'sku' => $result['sku'],
                    'qty' => $result['qty'],
                    'l_id' => $result['l_id'],
                    'post_id' => $result['post_id'],
                    'id' => $result['id'],
                    'type' => ucwords($type)
                ];
            }
        }

        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $count,
            'recordsFiltered' => $count,
            'data' => $data
        ]);
        die();
    }

    public function fbf_ebay_packages_list_tyres()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');

        global $wpdb;
        // First get all products that match
        $brands_option = get_option('_fbf_ebay_packages_tyre_brands');
        $search_brands = [];
        $post_skus = [];
        $post_lookup = [];
        $listings_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';

        // Errors
        $status = 'success';
        $errors = [];
        $warnings = [];

        foreach($brands_option as $brand){
            $search_brands[] = $brand['ID'];
        }


        // First get all matching Tyre SKU's from the product posts
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '=',
                ],
                [
                    'key' => '_stock_status',
                    'value' => 'onbackorder',
                    'compare' => '=',
                ]
            ],
            /*'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_stock',
                    'type' => 'numeric',
                    'value' => 4,
                    'compare' => '>'
                ],
            ],*/ // Handle the stock status for the query later
            'tax_query' => [
                'relation' => 'AND',
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => 'tyre'
                ],
                [
                    'taxonomy' => 'pa_brand-name',
                    'field' => 'id',
                    'terms' => $search_brands,
                    'operator' => 'IN'
                ],
                [
                    'taxonomy' => 'pa_list-on-ebay',
                    'field' => 'slug',
                    'terms' => 'true'
                ]
            ]
        ];
        $found = new WP_Query($args);


        if($found->have_posts()){
            foreach($found->posts as $post_id){
                $sku = get_post_meta( $post_id, '_sku', true );
                $post_skus[] = $sku;
                $post_lookup[$sku] = [
                    'title' => get_the_title($post_id),
                    'qty' => get_post_meta($post_id, '_stock', true),
                    'id' => $post_id
                ];
            }
        }else{
            $errors[] = 'no found posts';
        }

        // Second get ALL the current (active) Tyre listings
        $q = $wpdb->prepare('SELECT s.sku
            FROM wp_fbf_ebay_packages_listings l
            INNER JOIN wp_fbf_ebay_packages_skus s
                ON s.listing_id = l.id
            WHERE l.status = %s
            AND l.type = %s', 'active', 'tyre');
        $results = $wpdb->get_col( $q );
        if($results==false||count($results)===0){
            $warnings[] = 'No current active tyre listings';
        }

        $to_create = array_diff($post_skus, $results); // These SKU's do NOT exist in the found set so either need to be activated (if they are present but inactive) OR created if they don't exist at all
        $to_leave = array_intersect($post_skus, $results); // These SKU's exist and are active - nothing to do here
        $to_deactivate = array_diff($results, $post_skus);

        if(!empty($to_create)){
            foreach($to_create as $sku){
                // Check to see if the SKU exists - if it does just activate it
                $q = $wpdb->prepare('SELECT l.id, s.sku
                    FROM wp_fbf_ebay_packages_listings l
                    INNER JOIN wp_fbf_ebay_packages_skus s
                        ON s.listing_id = l.id
                    WHERE s.sku = %s
                    AND l.type = %s', $sku, 'tyre');
                $result = $wpdb->get_row($q, ARRAY_A);
                if(null !== $result){
                    // Exists - make it active
                    $u = $wpdb->update($listings_table,
                        [
                            'status' => 'active'
                        ],
                        [
                            'id' => $result['id']
                        ]
                    );
                    if($u!==false){
                        $this->log('Listing activated', $result['id']);
                    }else{
                        $errors[] = $wpdb->last_error;
                    }
                }else{
                    // Does not exist - create it
                    $i = $wpdb->insert(
                        $listings_table,
                        [
                            'name' => $post_lookup[$sku]['title'],
                            'post_id' => $post_lookup[$sku]['id'],
                            'status' => 'active',
                            'type' => 'tyre',
                            'qty' => $post_lookup[$sku]['qty']
                        ]
                    );
                    if($i!==false){
                        // Insert the SKU
                        $insert_id = $wpdb->insert_id;
                        $i = $wpdb->insert(
                            $skus_table,
                            [
                                'sku' => $sku,
                                'listing_id' => $insert_id
                            ]
                        );
                        if($i===false){
                            $errors[] = $wpdb->last_error;
                        }else{
                            // Add log
                            $this->log('Listing created', $insert_id);
                        }
                    }else{
                        $errors[] = $wpdb->last_error;
                    }
                }
            }
        }

        if(!empty($to_deactivate)){
            // Set status to inactive on all
            foreach($to_deactivate as $sku_to_deactivate){
                $s = $wpdb->prepare('SELECT l.id 
                    FROM wp_fbf_ebay_packages_listings l 
                    INNER JOIN wp_fbf_ebay_packages_skus s ON s.listing_id = l.id
                    WHERE s.sku = %s
                    AND l.type = %s', $sku_to_deactivate, 'tyre');
                $id = $wpdb->get_row($s, ARRAY_A)['id'];
                if($id!==null){
                    $q = $wpdb->prepare('UPDATE wp_fbf_ebay_packages_listings l
                    INNER JOIN wp_fbf_ebay_packages_skus s ON s.listing_id = l.id
                    SET l.status = %s
                    WHERE s.sku = %s
                    AND l.type = %s', 'inactive', $sku_to_deactivate, 'tyre');
                    $result = $wpdb->query($q);

                    if($result!==false){
                        $this->log('Listing deactivated', $id);
                    }else{
                        $errors[] = 'Could not deactivate listing: ' . $id;
                    }
                }else{
                    $errors[] = 'Could not find listing for SKU: ' . $sku_to_deactivate;
                }
            }
        }

        if(!empty($errors)){
            $status = 'error';
        }

        echo json_encode([
            'status' => $status,
            'errors' => !empty($errors)?$errors:'',
            'warnings' => !empty($warnings)?$warnings:''
        ]);
        die();
    }

    public function fbf_ebay_packages_clean(){
        $product_type = filter_var($_POST['product_type'], FILTER_SANITIZE_STRING);
        if(!empty($product_type)){
            $resp = Fbf_Ebay_Packages_Admin::clean('manual', $product_type);
        }else{
            $resp=[];
        }

        echo json_encode($resp);
        die();
    }

    public function fbf_ebay_packages_synchronise()
    {
        if(Fbf_Ebay_Packages_Admin::synchronise('manual', 'tyres and wheels')){
            $status = 'success';
        }else{
            $status = 'error';
        }
        echo json_encode([
            'status' => $status,
        ]);
        die();
    }

    public function fbf_ebay_packages_schedule()
    {
        $next = wp_next_scheduled( Fbf_Ebay_Packages_Cron::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK );
        echo json_encode([
            'next' => $next,
        ]);
        die();
    }

    public function fbf_ebay_packages_test_item()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $items = explode(',', filter_var($_REQUEST['items'], FILTER_SANITIZE_STRING));
        $type = filter_var($_REQUEST['type'], FILTER_SANITIZE_STRING);
        if(!empty($items)){
            if(Fbf_Ebay_Packages_Admin::synchronise('manual', 'wheels', $items)){
                $status = 'success';
            }else{
                $status = 'error';
            }
        }else{
            $status = 'error';
        }
        echo json_encode([
            'status' => $status,
        ]);
        die();
    }

    public function fbf_ebay_packages_event_log()
    {
        global $wpdb;
        $draw = $_REQUEST['draw'];
        $data = [];
        $synchronisations_to_show = 5;
        $table = $wpdb->prefix . 'fbf_ebay_packages_scheduled_event_log';
        $timezone = new DateTimeZone("Europe/London");
        $q = "SELECT hook, log, UNIX_TIMESTAMP(created) AS d
            FROM {$table}
            ORDER BY d DESC
            LIMIT {$synchronisations_to_show}";
        $results = $wpdb->get_results($q, ARRAY_A);
        if($results!==false){
            foreach($results as $result){
                $date = new DateTime();
                $date->setTimezone($timezone);
                $date->setTimestamp($result['d']);
                $log = unserialize($result['log']);
                $time = $log['end'] - $log['start'];
                $data[] = [
                    $date->format('g:i:s A'),
                    $result['hook'],
                    $time,
                ];
            }
        }
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $synchronisations_to_show,
            'recordsFiltered' => $synchronisations_to_show,
            'data' => $data
        ]);
        die();
    }

    public function fbf_ebay_packages_log_detail()
    {
        global $wpdb;
        $draw = $_REQUEST['draw'];
        $start = absint($_REQUEST['start']);
        $length = absint($_REQUEST['length']);
        $paged = ($start/$length) + 1;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $all = [];

        if(isset($_REQUEST['listing_id'])){
            $id = $_REQUEST['listing_id'];
            $q = $wpdb->prepare("SELECT COUNT(*) AS count
                FROM {$table}
                WHERE listing_id = %s", $id);
            $r = $wpdb->get_row($q, ARRAY_A);
            if($r!==false){
                $count = $r['count'];

                $q = "SELECT *
                    FROM {$table}
                    WHERE listing_id = %s";

                $q = $wpdb->prepare("SELECT *
                    FROM {$table}
                    WHERE listing_id = %s", $id);
                $r = $wpdb->get_results($q, ARRAY_A);

                if($r!==false&&!empty($r)){
                    foreach($r as $result){
                        $created = DateTime::createFromFormat ( "Y-m-d H:i:s", $result['created'] );
                        $timestamp = $created->getTimestamp();
                        if(is_null($result['ebay_action'])){
                            switch($result['log']){
                                case 'Listing created':
                                    $action = 'listing_created';
                                    break;
                                case 'Listing activated':
                                    $action = 'listing_activated';
                                    break;
                                case 'Listing deactivated':
                                    $action = 'listing_deactivated';
                                    break;
                                default:
                                    $action = $result['log'];
                                    break;
                            }
                            $response_code = '';
                            $status = '';
                        }else{
                            $response = unserialize($result['log']);
                            $status = $response['status'];
                            $action = $result['ebay_action'];
                            $response_code = $response['response']['response_code'];
                        }
                        $all[] = [
                            'DT_RowId' => sprintf('row_%s', $result['id']),
                            'DT_RowClass' => 'dataTable_listing',
                            'DT_RowData' => [
                                'pKey' => $result['id']
                            ],
                            'created' => $result['created'],
                            'timestamp' => $timestamp,
                            'action' =>  $action,
                            'status' => $status,
                            'response_code' => $response_code,
                            'id' => $result['id']
                        ];
                    }
                    if(isset($_REQUEST['order'][0]['column'])) {
                        if ($_REQUEST['order'][0]['column'] === '0') {
                            usort($all, function($a, $b){
                                $dir = $_REQUEST['order'][0]['dir'];
                                if($dir==='desc'){
                                    return strcmp($a['timestamp'], $b['timestamp']) * -1;
                                }else{
                                    return strcmp($a['timestamp'], $b['timestamp']);
                                }
                            });
                        }
                        if ($_REQUEST['order'][0]['column'] === '1') {
                            usort($all, function($a, $b){
                                $dir = $_REQUEST['order'][0]['dir'];
                                if($dir==='desc'){
                                    return strcmp($a['action'], $b['action']) * -1;
                                }else{
                                    return strcmp($a['action'], $b['action']);
                                }
                            });
                        }
                        if ($_REQUEST['order'][0]['column'] === '2') {
                            usort($all, function($a, $b){
                                $dir = $_REQUEST['order'][0]['dir'];
                                if($dir==='desc'){
                                    return strcmp($a['status'], $b['status']) * -1;
                                }else{
                                    return strcmp($a['status'], $b['status']);
                                }
                            });
                        }
                        if ($_REQUEST['order'][0]['column'] === '3') {
                            usort($all, function($a, $b){
                                $dir = $_REQUEST['order'][0]['dir'];
                                if($dir==='desc'){
                                    return strcmp($a['response_code'], $b['response_code']) * -1;
                                }else{
                                    return strcmp($a['response_code'], $b['response_code']);
                                }
                            });
                        }
                    }

                    // Apply search
                    if($_REQUEST['search']['value']){
                        $pattern = '/' . $_REQUEST['search']['value'] . '/';
                        $all = array_filter($all, function($a) use($pattern)  {
                            return preg_grep($pattern, $a);
                        });
                        $count = count($all);
                    }

                    $data = array_slice($all, $start, $length);
                }
            }else{
                $count = 0;
            }

            echo json_encode([
                'draw' => $draw,
                'recordsTotal' => $count,
                'recordsFiltered' => $count,
                'data' => $data
            ]);
        }else{
            echo json_encode([
                'status' => 'error'
            ]);
        }
        die();
    }

    public function fbf_ebay_packages_detail_log_response()
    {
        $id = filter_var($_REQUEST['id'], FILTER_SANITIZE_STRING);
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $q = $wpdb->prepare("SELECT log
            FROM {$table}
            WHERE id = %s", $id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            $log = unserialize($r['log']);
            if(!empty($log['response']['response'])){
                $log['response']['response'] = json_decode($log['response']['response']);
            }
            ob_start();
            print_r($log);
            $log = ob_get_clean();
        }else{
            $log = '';
        }
        echo json_encode([
            'status' => 'success',
            'id' => $id,
            'log' => $log
        ]);
        die();
    }

    public function fbf_ebay_packages_listing_info()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $id = filter_var($_POST['id'], FILTER_SANITIZE_STRING);
        $q = $wpdb->prepare("SELECT *
            FROM {$table}
            WHERE id = %s", $id);
        $r = $wpdb->get_row($q, ARRAY_A);

        $result = [
            'id' => $r['id'],
            'info' => $this->get_listing_info($r['id']),
            'inv_info' => $this->get_inv_info($r['id'], $r['inventory_sku']),
            'offer_info' => $this->get_offer_info($r['id'], $r['offer_id']),
            'publish_info'=> $this->get_publish_info($r['id'], $r['listing_id']),
            'full_log_url' => get_admin_url() . 'admin.php?page=' . $this->plugin_name . '-tyres&listing_id=' . $r['id']
        ];

        if($r['type']==='wheel'){
            $result['fitting_info'] = $this->get_fitting_info($r['id']);
        }

        echo json_encode([
            'status' => 'success',
            'result' => $result
        ]);
        die();
    }

    public function fbf_ebay_packages_compatibility()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $category_tree_id = 3;
        $max_levels = 4;
        $category_id = 179679;
        $values = [];
        $level = null;
        $selectLimit = null;
        $label = null;
        $name = null;
        $selected = null;
        $data = json_decode(stripslashes($_REQUEST['data']));
        $chassis_id = filter_var($_REQUEST['chassis_id'], FILTER_SANITIZE_STRING);

        if(property_exists($data, 'next_level')){
            $level = $data->next_level;

            switch($level){
                case '2':
                    $compatibility_property = 'Model';
                    $filter = 'Car%20Make:' . urlencode($data->level_1->selections[0]);
                    $selectLimit = 1;
                    $label = 'Select ' . $selectLimit . ' model';
                    $name = 'Model';
                    break;
                case '3':
                    $compatibility_property = 'Variant';
                    $filter = 'Car%20Make:' . urlencode($data->level_1->selections[0]) . ',Model:' . urlencode($data->level_2->selections[0]);
                    $selectLimit = 1;
                    $label = 'Select ' . $selectLimit . ' variant';
                    $name = 'Variant';
                    break;
                case '4':
                    $compatibility_property = 'Cars%20Year';
                    $filter = 'Car%20Make:' . urlencode($data->level_1->selections[0]) . ',Model:' . urlencode($data->level_2->selections[0]) . ',Variant:' . urlencode($data->level_3->selections[0]);
                    $selectLimit = false;
                    $label = 'Select ' . $selectLimit . ' years';
                    $name = 'Cars Year';
                    $selected = $this->get_current_compatibility($chassis_id, $data->level_1->selections[0], $data->level_2->selections[0], $data->level_3->selections[0]);
                    break;
                default:
                    break;
            }

            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
            $auth = new Fbf_Ebay_Packages_Api_Auth();
            $token = $auth->get_valid_token();

            $resp = $this->api('https://api.ebay.com/commerce/taxonomy/v1/category_tree/' . $category_tree_id . '/get_compatibility_property_values?category_id=' . $category_id . '&compatibility_property=' . $compatibility_property . '&filter=' . $filter, 'GET', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);

            if($resp['status']==='success'&&$resp['response_code']===200){
                $r = $resp['response'];
                $values = json_decode($r)->compatibilityPropertyValues;
            }

        }else{
            // must be the first level
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
            $auth = new Fbf_Ebay_Packages_Api_Auth();
            $token = $auth->get_valid_token();

            if($token['status']==='success'){
                $makes = $this->api('https://api.ebay.com/commerce/taxonomy/v1/category_tree/' . $category_tree_id . '/get_compatibility_property_values?category_id=' . $category_id . '&compatibility_property=Car%20Make', 'GET', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);

                if($makes['status']==='success'&&$makes['response_code']===200){
                    $r = $makes['response'];
                    $values = json_decode($r)->compatibilityPropertyValues;
                    $level = 1;
                    $selectLimit = 1;
                    $label = 'Select ' . $selectLimit . ' vehicle manufacturer';
                    $name = 'Car Make';
                }
            }
        }

        echo json_encode([
            'status' => 'success',
            'values' => $values,
            'selected' => $selected,
            'level' => $level,
            'select_limit' => $selectLimit,
            'label' => $label,
            'name' => $name,
            'max_levels' => $max_levels
        ]);
        die();
    }

    public function fbf_ebay_packages_confirm_compatibility()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        global $wpdb;
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $data = json_decode(stripslashes($_REQUEST['data']));
        $chassis_id = filter_var($_REQUEST['chassis_id'], FILTER_SANITIZE_STRING);

        $payloads = [];

        $years = $data->level_4->selections;

        foreach($years as $year){
            $comp = [];
            foreach($data as $level_key => $level_value){
                if(is_object($level_value)){
                    if($level_value->name!=='Cars Year'){
                        $comp[] = [
                            'name' => $level_value->name,
                            'value' => $level_value->selections[0]
                        ];
                    }else{
                        $comp[] = [
                            'name' => $level_value->name,
                            'value' => $year
                        ];
                    }
                }
            }
            $payloads[] = $comp;
        }

        $count = 0;
        foreach($payloads as $payload){
            $payload_s = serialize($payload);
            $i = $wpdb->insert(
                $compatibility_table,
                [
                    'chassis_id' => $chassis_id,
                    'payload' => $payload_s
                ]
            );
            $count++;
        }




        if($count!==0){
            if($count===count($payloads)){
                $status = 'success';
                $error = 'none';
            }else{
                $status = 'error';
                $error = 'Compatibility inserts mismatch';
            }
        }else{
            $status = 'error';
            $error = 'No inserts';
        }

        echo json_encode([
            'status' => $status,
            'error' => $error,
            'chassis_id' => $chassis_id
        ]);
        die();
    }

    public function fbf_ebay_packages_compatibility_list()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        global $wpdb;
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $chassis_id = filter_var($_REQUEST['id'], FILTER_SANITIZE_STRING);
        $q = $wpdb->prepare("SELECT *
            FROM {$compatibility_table}
            WHERE chassis_id = %s", $chassis_id);
        $r = $wpdb->get_results($q, ARRAY_A);
        $list_items = [];

        if($r!==false&&!empty($r)){
            foreach($r as $result){
                $payload = unserialize($result['payload']);
                $name = implode(', ', array_column($payload, 'value'));
                $list_items[] = [
                    'id' => $result['id'],
                    'name' => $name
                ];
            }

            echo json_encode([
                'status' => 'success',
                'list_items' => $list_items
            ]);
        }else if(empty($r)){
            echo json_encode([
                'status' => 'success',
                'list_items' => $list_items
            ]);
        }else{
            echo json_encode([
                'status' => 'error',
                'error' => 'there was and error finding compatibility items for chassis'
            ]);
        }
        die();
    }

    public function fbf_ebay_packages_compatibility_delete()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        global $wpdb;
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $id = filter_var($_REQUEST['id'], FILTER_SANITIZE_STRING);
        $q = $wpdb->prepare("SELECT chassis_id
            FROM {$compatibility_table}
            WHERE id = %s", $id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            $chassis_id = $r['chassis_id'];

            $d = $wpdb->delete(
                $compatibility_table,
                [
                    'id' => $id
                ]
            );

            if($d!==false&&!empty($d)){
                echo json_encode([
                    'status' => 'success',
                    'chassis_id' => $chassis_id
                ]);
            }else{
                echo json_encode([
                    'status' => 'error',
                    'error' => 'nothing was deleted'
                ]);
            }
        }else{
            echo json_encode([
                'status' => 'error',
                'error' => 'could not get chassis id'
            ]);
        }

        die();
    }

    public function fbf_ebay_packages_compatibility_delete_all()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        global $wpdb;
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $id = filter_var($_REQUEST['id'], FILTER_SANITIZE_STRING);

        $d = $wpdb->delete(
            $compatibility_table,
            [
                'chassis_id' => $id
            ]
        );

        if($d!==false&&!empty($d)){
            echo json_encode([
                'status' => 'success',
                'chassis_id' => $id
            ]);
        }else{
            echo json_encode([
                'status' => 'error',
                'error' => 'delete failed'
            ]);
        }
        die();
    }

    private function get_listing_info($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $return = [];
        $q = $wpdb->prepare("SELECT log, count(*) as cnt
            FROM {$table}
            WHERE listing_id = %s
            AND ebay_action IS NULL
            GROUP BY log", $id);
        $r = $wpdb->get_results($q, ARRAY_A);

        if($r!==false&&!empty($r)){
            foreach($r as $row){
                switch($row['log']){
                    case 'Listing activated':
                        $return['activated_count'] = $row['cnt'];
                        break;
                    case 'Listing deactivated':
                        $return['deactivated_count'] = $row['cnt'];
                        break;
                    default:
                        break;
                }
            }
        }

        $q = $wpdb->prepare("SELECT created
            FROM {$table}
            WHERE listing_id = %s
            AND log = %s
            ORDER BY created DESC
            LIMIT 1", $id, 'Listing created');
        $r = $wpdb->get_row($q, ARRAY_A);

        if($r!==false&&!empty($r)){
            $return['created'] = $r['created'];
        }

        return $return;

    }

    private function get_inv_info($id, $sku)
    {
        $info = [
            'sku' => $sku
        ];
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $q = $wpdb->prepare("SELECT *
            FROM {$table}
            WHERE listing_id = %s
            AND ebay_action = %s", $id, 'create_or_update_inv');

        $r = $wpdb->get_results($q, ARRAY_A);

        if($r!==false&&!empty($r)){
            $update_count = 0;
            $error_count = 0;
            foreach($r as $row){
                $log = unserialize($row['log']);
                $id = $row['id'];
                $created = DateTime::createFromFormat ( "Y-m-d H:i:s", $row['created'] );
                $timestamp = $created->getTimestamp();
                $status = $log['status'];
                $action = $log['action'];

                if($action==='updated'&&$status==='success'){
                    $update_count++;
                    $info['last_update'] = $row['created']; // Should get the latest 'update' if there are any
                }

                if($action==='created'&&$status==='success'){
                    $info['first_created'] = $row['created']; // Should get the latest 'create' if more than 1
                }

                if($status==='error'){
                    $error_count++;
                    $info['last_error'] = $row['created'];
                }
            }
            if($update_count>0){
                $info['update_count'] = $update_count;
            }
            $info['error_count'] = $error_count;
        }

        // Get the non-ebay
        return $info;
    }

    private function get_offer_info($id, $offer_id)
    {
        $info = [
            'offer_id' => $offer_id
        ];
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $q = $wpdb->prepare("SELECT *
            FROM {$table}
            WHERE listing_id = %s
            AND ebay_action IN (%s, %s)", $id, 'create_offer', 'update_offer');
        $r = $wpdb->get_results($q, ARRAY_A);
        if($r!==false&&!empty($r)) {
            $update_count = 0;
            $error_count = 0;
            foreach ($r as $row) {
                $log = unserialize($row['log']);
                $id = $row['id'];
                $created = DateTime::createFromFormat ( "Y-m-d H:i:s", $row['created'] );
                $timestamp = $created->getTimestamp();
                $status = $log['status'];
                $action = $log['action'];

                if($row['ebay_action']==='create_offer'&&$status==='success'){
                    $info['first_created'] = $row['created']; // Should get the latest 'create' if more than 1
                }

                if($row['ebay_action']==='update_offer'&&$status==='success'){
                    $update_count++;
                    $info['last_update'] = $row['created']; // Should get the latest 'update' if there are any
                }

                if($status==='error'){
                    $error_count++;
                    $info['last_error'] = $row['created'];
                }
            }
            if($update_count>0){
                $info['update_count'] = $update_count;
            }
            $info['error_count'] = $error_count;
        }
        return $info;
    }

    private function get_publish_info($id, $listing_id)
    {
        $info = [
            'listing_id' => $listing_id
        ];
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $q = $wpdb->prepare("SELECT *
            FROM {$table}
            WHERE listing_id = %s
            AND ebay_action = %s", $id, 'publish_offer');
        $r = $wpdb->get_results($q, ARRAY_A);
        if ($r !== false && !empty($r)) {
            $error_count = 0;
            foreach ($r as $row) {
                $log = unserialize($row['log']);
                $id = $row['id'];
                $created = DateTime::createFromFormat("Y-m-d H:i:s", $row['created']);
                $timestamp = $created->getTimestamp();
                $status = $log['status'];
                $action = $log['action'];

                if($status==='success'){
                    $info['first_created'] = $row['created']; // Should get the latest 'create' if more than 1
                }

                if($status==='error'){
                    $error_count++;
                    $info['last_error'] = $row['created'];
                }
            }
            $info['error_count'] = $error_count;
        }
        return $info;
    }

    private function get_fitting_info($id)
    {
        global $wpdb;
        $fittings_table = $wpdb->prefix . 'fbf_ebay_packages_fittings';
        $fittings = [];
        $q = $wpdb->prepare("SELECT *
            FROM {$fittings_table}
            WHERE listing_id = %s", $id);
        $r = $wpdb->get_results($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            foreach($r as $result){
                $fittings[] = sprintf('%s - %s', $result['manufacturer_name'], $result['chassis_name']);
            }
        }
        return $fittings;
    }

    private function log($msg, $id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        return $wpdb->insert(
            $table,
            [
                'listing_id' => $id,
                'log' => $msg
            ]
        );
    }

    private function api($url, $method, $headers, $body=null)
    {
        $curl = curl_init();
        $resp = [];

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_ENCODING, '');
        curl_setopt($curl, CURLOPT_MAXREDIRS, 20);
        curl_setopt($curl, CURLOPT_TIMEOUT, 0);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                    return $len;
                $headers[strtolower(trim($header[0]))][] = trim($header[1]);
                return $len;
            }
        );

        if(!is_null($body)){
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $resp['status'] = 'error';
            $resp['errors'] = curl_error($curl);
        }else{
            $resp['status'] = 'success';
            $resp['response'] = $response;

            // Clean up headers (strip out token)
            foreach($headers as $i => $header){
                if(!is_array($header)){
                    if(strpos($header, 'Authorization')!==false){
                        unset($headers[$i]);
                    }
                }
            }

            $resp['response_headers'] = $headers;
            $resp['response_code'] = curl_getinfo($curl)['http_code'];
        }

        curl_close($curl);
        return $resp;
    }

    private function get_current_compatibility($chassis_id, $make, $model, $variant)
    {
        global $wpdb;
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $current_selections = [];

        $q = $wpdb->prepare("SELECT *
            FROM {$compatibility_table}
            WHERE chassis_id = %s", $chassis_id);
        $r = $wpdb->get_results($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            foreach($r as $result){
                $payload = unserialize($result['payload']);
                $make_key = array_search('Car Make', array_column($payload, 'name'));
                $model_key = array_search('Model', array_column($payload, 'name'));
                $variant_key = array_search('Variant', array_column($payload, 'name'));
                $year_key = array_search('Cars Year', array_column($payload, 'name'));
                $payload_make = $payload[$make_key]['value'];
                $payload_model = $payload[$model_key]['value'];
                $payload_variant = $payload[$variant_key]['value'];
                $payload_year = $payload[$year_key]['value'];

                if($payload_make===$make && $payload_model===$model && $payload_variant===$variant){
                    $current_selections[] = $payload_year;
                }
            }
        }



        return $current_selections;
    }
}
