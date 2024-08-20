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

    public function fbf_ebay_packages_get_package_chassis()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $query = strtolower(filter_var($_REQUEST['q'], FILTER_SANITIZE_STRING));
        $data = [];
        global $wpdb;
        $fittings_table = $wpdb->prefix . 'fbf_ebay_packages_fittings';
        if(!empty($query)){
            $q = $wpdb->prepare("SELECT *
                    FROM {$fittings_table}
                    WHERE LOWER(chassis_name) LIKE %s
                    GROUP BY chassis_id
                    ORDER BY chassis_name", '%' . $wpdb->esc_like($query) . '%');
        }else{
            $q = $wpdb->prepare("SELECT *
                    FROM {$fittings_table}
                    GROUP BY chassis_id
                    ORDER BY chassis_name");
        }
        $r = $wpdb->get_results($q);
        if($r){
            foreach($r as $chassis){
                $data[] = [
                    $chassis->chassis_id,
                    $chassis->chassis_name,
                ];
            }
        }
        echo json_encode($data);
        die();
    }

    public function fbf_ebay_packages_get_package_wheel()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $query = htmlentities(stripslashes(htmlspecialchars_decode(filter_var($_REQUEST['q'], FILTER_SANITIZE_STRING)))); // Messing about with quotation marks
        global $wpdb;
        $selected_chassis_id = filter_var($_REQUEST['chassis_id'], FILTER_SANITIZE_STRING);
        $fittings_table = $wpdb->prefix . 'fbf_ebay_packages_fittings';
        $listings_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $data = [];

        $q = "SELECT {$listings_table}.name, {$listings_table}.post_id, {$listings_table}.inventory_sku
            FROM {$fittings_table}
            INNER JOIN {$listings_table}
            ON {$fittings_table}.listing_id = {$listings_table}.id
            WHERE {$fittings_table}.chassis_id = %s
            AND {$listings_table}.type = 'wheel'
            AND {$listings_table}.status = 'active'";
        if(!empty($query)){
            $q.= sprintf(" AND LOWER({$listings_table}.name) LIKE '%s'", '%' . $query . '%');
        }
        $q = $wpdb->prepare($q, $selected_chassis_id);
        $r = $wpdb->get_results($q);

        // remove duplicates here
        $wheel_ids = array_column($r, 'post_id');
        $wheel_ids = $this->remove_duplicates($selected_chassis_id, $wheel_ids);

        if($r){
            foreach($r as $wheel){
                if(in_array($wheel->post_id, $wheel_ids)){
                    if(!in_array($wheel->post_id, array_column($data, 0))){
                        $data[] = [
                            $wheel->post_id,
                            '(' . $wheel->inventory_sku . ') ' . html_entity_decode($wheel->name), // decode html entities before returning
                        ];
                    }
                }
            }
        }
        echo json_encode($data);
        die();
    }

    public function fbf_ebay_packages_get_package_tyre()
    {
        global $post, $wpdb;
        $data = [];
        $listings_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $chassis_id = filter_var($_REQUEST['chassis_id'], FILTER_SANITIZE_STRING);
        $wheel_id = filter_var($_REQUEST['wheel_id'], FILTER_SANITIZE_STRING);
        $wheel = wc_get_product($wheel_id);
        $sku = $wheel->get_sku();
        $query = htmlentities(stripslashes(htmlspecialchars_decode(filter_var($_REQUEST['q'], FILTER_SANITIZE_STRING)))); // Messing about with quotation marks

        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        if (is_plugin_active('fbf-wheel-search/fbf-wheel-search.php')) {
            require_once plugin_dir_path(WP_PLUGIN_DIR . '/fbf-wheel-search/fbf-wheel-search.php') . 'includes/class-fbf-wheel-search-boughto-api.php';
            $api = new \Fbf_Wheel_Search_Boughto_Api('fbf_wheel_search', 'fbf-wheel-search');
            $boughto_wheel = $api->get_wheel_by_sku($sku);

            if($boughto_wheel['status']==='success'){
                $boughto_wheel_id = $boughto_wheel['client_wheel_product_id'];
                $tyre_sizes = $api->tyre_sizes($chassis_id, $boughto_wheel_id);

                if(!empty($tyre_sizes['tyre_sizes'])) {
                    foreach ($tyre_sizes['tyre_sizes'] as $tyre) {
                        $profiles = [];
                        $sections = [];
                        if ($tyre['profile'] > 500 && preg_match('/50$/', $tyre['profile'])) {
                            $new_profile = substr_replace($tyre['profile'], '.', strlen($tyre['profile']) - 2, 0);
                            $profiles[] = $new_profile;
                            $profiles[] = substr($new_profile, 0, -1);
                        } else {
                            $profiles[] = $tyre['profile'];
                            if ($tyre['profile'] === 0) {
                                $profiles[] = '6430'; // local and staging
                                $profiles[] = '3954'; // production
                            }
                        }
                        if ($tyre['section'] > 500 && preg_match('/50$/', $tyre['section'])) {
                            $new_section = substr_replace($tyre['section'], '.', strlen($tyre['section']) - 2, 0);
                            $sections[] = $new_section;
                            $sections[] = substr($new_section, 0, -1);
                        } else {
                            $sections[] = $tyre['section'];
                        }

                        if (str_contains($tyre['comment'], 'IDEAL')) {
                            $type = 'IDEAL';
                            $modification = '';
                        } else if (str_contains($tyre['comment'], 'SPECIALIST')) {
                            $type = 'SPECIALIST';
                            $modification = trim(str_replace('SPECIALIST, ', '', $tyre['comment']));
                        } else {
                            $type = 'ALTERNATIVE';
                            $modification = '';
                        }

                        if($type!=='SPECIALIST'){ // OMIT specialist for eBay
                            $searches[$type][] = [
                                'profiles' => $profiles,
                                'rim' => $tyre['rim_size'],
                                'section' => $sections,
                                'modification' => $modification,
                            ];
                        }
                    }

                    if(!empty($searches)) {
                        foreach ($searches as $search_key => $search) {
                            $tyre_count = 0;
                            foreach ($search as $size_key => $size) {

                                $args = [
                                    'post_type' => 'product',
                                    'posts_per_page' => -1,
                                    'post_status' => 'publish',
                                    'tax_query' => [
                                        'relation' => 'AND',
                                        [
                                            'taxonomy' => 'pa_tyre-width',
                                            'field' => 'slug',
                                            'terms' => $size['section']
                                        ],
                                        [
                                            'taxonomy' => 'pa_tyre-profile',
                                            'field' => 'slug',
                                            'terms' => $size['profiles']
                                        ],
                                        [
                                            'taxonomy' => 'pa_tyre-size',
                                            'field' => 'slug',
                                            'terms' => $size['rim']
                                        ],
                                        [
                                            'taxonomy' => 'product_cat',
                                            'field' => 'slug',
                                            'terms' => 'package',
                                            'operator' => 'NOT IN'
                                        ]
                                    ],
                                    'meta_query' => [
                                        'relation' => 'OR',
                                        [
                                            'key' => '_stock_status',
                                            'value' => 'instock',
                                            'compare' => '=',
                                        ]
                                    ]
                                ];

                                $rel_tyres = new \WP_Query($args);
                                if ($rel_tyres->have_posts()) {
                                    $prices = [];
                                    while ($rel_tyres->have_posts()) {
                                        $rel_tyres->the_post();
                                        $searches[$search_key][$size_key]['tyres'][] = [
                                            'id' => $post->ID,
                                            'title' => get_the_title($post->ID),
                                        ];
                                        $tyre_count++;
                                    }
                                }
                            }
                            $searches[$search_key]['tyre_count'] = $tyre_count;
                        }

                        $ideal = [];
                        $alternative = [];
                        foreach($searches as $search_key => $search){
                            if($search['tyre_count'] > 0){
                                foreach($search as $size){
                                    if(isset($size['tyres'])){
                                        foreach($size['tyres'] as $tyre){
                                            $q = $wpdb->prepare("SELECT * FROM {$listings_table} WHERE post_id = %s", $tyre['id']);
                                            $r = $wpdb->get_row($q);
                                            if($r){
                                                if(!empty($query)){
                                                    if(str_contains(strtolower($tyre['title']), $query)){
                                                        if($search_key==='IDEAL'){
                                                            $ideal[] = [
                                                                $tyre['id'],
                                                                $tyre['title'] . ' (' . $search_key . ')'
                                                            ];
                                                        }else{
                                                            $alternative[] = [
                                                                $tyre['id'],
                                                                $tyre['title'] . ' (' . $search_key . ')'
                                                            ];
                                                        }
                                                    }
                                                }else{
                                                    if($search_key==='IDEAL'){
                                                        $ideal[] = [
                                                            $tyre['id'],
                                                            $tyre['title'] . ' (' . $search_key . ')'
                                                        ];
                                                    }else{
                                                        $alternative[] = [
                                                            $tyre['id'],
                                                            $tyre['title'] . ' (' . $search_key . ')'
                                                        ];
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        if(count($ideal)){
                            $data = $ideal;
                        }else{
                            $data = $alternative;
                        }
                    }
                }
            }
        }
        echo json_encode($data);
        die();
    }

    public function fbf_ebay_packages_get_package_nut_bolt()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $chassis_id = filter_var($_REQUEST['chassis_id'], FILTER_SANITIZE_STRING);
        $wheel_id = filter_var($_REQUEST['wheel_id'], FILTER_SANITIZE_STRING);
        $low_nut_bolt_stock = true;
        $data = [];

        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        if(is_plugin_active('fbf-wheel-search/fbf-wheel-search.php')){
            require_once plugin_dir_path(WP_PLUGIN_DIR . '/fbf-wheel-search/fbf-wheel-search.php') . 'includes/class-fbf-wheel-search-boughto-api.php';
            $api = new \Fbf_Wheel_Search_Boughto_Api('fbf_wheel_search', 'fbf-wheel-search');
            $chassis_data = $api->get_chassis_detail($chassis_id);

            //Retrieve the wheel data
            $all_wheel_data = $api->get_wheels($chassis_id)['results'];
            $wheel_product = wc_get_product($wheel_id);
            $sku = $wheel_product->get_sku();
            $index = array_search($sku, array_column($all_wheel_data, 'product_code'));
            $wheel_data = $all_wheel_data[$index];


            if (!empty($wheel_data) && !empty($chassis_data)) {
                //We can gather the bits of data for the wheel nut skus:
                $nut_or_bolt = null;
                if($chassis_data['chassis']['wheel_fasteners']=='Lug nuts'){
                    $nut_or_bolt = 'nut';
                }else if($chassis_data['chassis']['wheel_fasteners']=='Lug bolts'){
                    $nut_or_bolt = 'bolt';
                }
                $sku = sprintf($chassis_data['chassis']['thread_size'] . $nut_or_bolt . $chassis_data['chassis']['head_size'] . '%1$s', $wheel_data['seat_type'] == 'flat' ? 'FLAT' : '');
                $nuts = [
                    'title' => 'Wheel nuts for your wheel and vehicle:',
                    'text' => sprintf('Display Accessories whose SKU\'s begin with: <strong>' . $chassis_data['chassis']['thread_size'] . $nut_or_bolt . $chassis_data['chassis']['head_size'] . '%1$s' . '</strong>', $wheel_data['seat_type'] == 'flat' ? 'FLAT' : ''),
                    'item' => [
                        'nutBolt_thread_type' => $chassis_data['chassis']['thread_size'],
                        'nut_or_bolt' => $nut_or_bolt,
                        'nut_bolt_hex' => $chassis_data['chassis']['head_size'],
                        'family_tags' => isset($wheel_data['family']['tags'][0])?:'',
                        'seat_type' => $wheel_data['seat_type'],
                        'sku' => $sku
                    ],
                ];

                // If there is a low stock level of nuts and bolts
                if($low_nut_bolt_stock){
                    $base_sku = $chassis_data['chassis']['thread_size'] . $nut_or_bolt . '%s';
                    if($wheel_data['seat_type'] == 'flat'){
                        $base_sku.= 'FLAT';
                    }
                    $sku = [
                        'base' =>  $base_sku,
                        'value' => $chassis_data['chassis']['head_size'],
                        'variance' => 5,
                        'above_below' => 'both'
                    ];
                }
                $ajax = new \App\Ajax();
                if ($items = $ajax->get_upsell_items(($chassis_id !== 'undefined' ? $chassis_id : ''), $sku, 1)) {
                    $nuts['items'] = $items;
                    foreach($items as $item){
                        $data[] = [
                            $item['id'],
                            $item['title']
                        ];
                    }
                }
            }

        }

        echo json_encode($data);
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

    public function fbf_ebay_packages_package_create_listing()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');

        // Firstly store the package post ids in the package_post_ids table
        global $wpdb;
        $post_ids_table = $wpdb->prefix . 'fbf_ebay_packages_package_post_ids';
        $listings_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $chassis_id = filter_var($_REQUEST['chassis_id'], FILTER_SANITIZE_STRING);
        $wheel_id = filter_var($_REQUEST['wheel_id'], FILTER_SANITIZE_STRING);
        $tyre_id = filter_var($_REQUEST['tyre_id'], FILTER_SANITIZE_STRING);
        $nut_bolt_id = filter_var($_REQUEST['nut_bolt_id'], FILTER_SANITIZE_STRING);
        $package_name = filter_var($_REQUEST['package_name'], FILTER_SANITIZE_STRING);
        $package_description = filter_var($_REQUEST['package_desc'], FILTER_SANITIZE_STRING);
        $include_tpms = filter_var($_REQUEST['tpms'], FILTER_VALIDATE_BOOLEAN);

        if(strlen(stripslashes(htmlspecialchars_decode($package_name))) > 80){
            echo json_encode([
                'status' => 'error',
                'error' => 'The title is too long, 80 characters max',
            ]);
            die();
        }

        // Get the products
        $wheel_stock = get_post_meta($wheel_id, '_stock', true);
        $tyre_stock = get_post_meta($tyre_id, '_stock', true);

        // Make the SKU for the package - note that this is made from the chassis_id, wheel_id, tyre_id AND the last 4 digits of the timestamp to ensure uniqueness
        $sku = sprintf('%s_%s_%s_%s', $chassis_id, $wheel_id, $tyre_id, substr(time(), -4));

        $package_ids = [
            'chassis_id' => $chassis_id,
            'wheel_id' => $wheel_id,
            'tyre_id' => $tyre_id,
            'nut_bolt_id' => $nut_bolt_id,
        ];

        $i = $wpdb->insert($post_ids_table, [
            'post_ids' => serialize($package_ids),
            'description' => $package_description,
        ]);


        if($i){
            $pid = $wpdb->insert_id;
            // Now create the entry in the listings table - using $i as the value in post_id instead of an actual product, this will serve as a lookup
            $i2 = $wpdb->insert($listings_table, [
                'name' => stripslashes($package_name),
                'post_id' => $pid,
                'status' => 'active',
                'type' => 'package',
                'qty' => min($tyre_stock, $wheel_stock),
            ]);

            if($i2){
                // Insert the listing ID into the $post_ids_table
                $u = $wpdb->update($post_ids_table, [
                    'listing_id' => $wpdb->insert_id
                ], [
                    'id' => $pid
                ]);

                // Create an entry for listing in the skus table
                $i3 = $wpdb->insert($skus_table, [
                    'listing_id' => $wpdb->insert_id,
                    'sku' => $sku
                ]);
            }
        }

        if($i2 && $u && $i3){
            // Now we need to list the new package
            $sync = Fbf_Ebay_Packages_Admin::synchronise('manual', 'packages', [$sku]);

            echo json_encode([
                'status' => 'success',
            ]);
        }else{
            echo json_encode([
                'status' => 'error',
                'error' => 'Insert or Update error',
            ]);
        }

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
            LEFT JOIN wp_fbf_ebay_packages_orders o
                ON l.inventory_sku = o.sku
            WHERE l.status = %s
            AND l.type = %s';
        if(isset($_REQUEST['search']['value'])&&!empty($_REQUEST['search']['value'])){
            $s.= '
            AND (l.name LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) .'%\' OR s.sku LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) .'%\' OR l.listing_id LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) . '%\')';
        }
        // Group by order qty
        $s.= '
        GROUP BY l.id';
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
            }else if($_REQUEST['order'][0]['column']==='4'){
                $s.= '
                ORDER BY o_qty ' . strtoupper($dir);
            }
        }
        $s2 = 'SELECT *, l.listing_id as l_id, l.id as t_id, l.qty as l_qty, s.sku as s_sku, SUM(o.qty) as o_qty
        ' . $s;
        $non_paginated_q = $wpdb->prepare("{$s2}", 'active', $type);
        $count_results = $wpdb->get_results($non_paginated_q, ARRAY_A);
        $paginated_q = $wpdb->prepare("{$s2}
            LIMIT {$length}
            OFFSET {$start}", 'active', $type);
        $results = $wpdb->get_results($paginated_q, ARRAY_A);
        if($results!==false){
            foreach($results as $result){
                $data[] = [
                    'DT_RowId' => sprintf('row_%s', $result['t_id']),
                    'DT_RowClass' => 'dataTable_listing',
                    'DT_RowData' => [
                        'pKey' => $result['t_id']
                    ],
                    'name' => $result['name'],
                    'sku' => $result['s_sku'],
                    'qty' => $result['l_qty'],
                    'l_id' => $result['l_id'],
                    'post_id' => $result['post_id'],
                    'id' => $result['t_id'],
                    'type' => ucwords($type),
                    'o_qty' => $result['o_qty']?:0
                ];
            }
        }

        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => count($count_results),
            'recordsFiltered' => count($count_results),
            'data' => $data
        ]);
        die();
    }

    public function fbf_ebay_packages_packages_table()
    {
        global $wpdb;
        $draw = $_REQUEST['draw'];
        $type = 'package';
        $start = absint($_REQUEST['start']);
        $length = absint($_REQUEST['length']);
        $data = [];

        $s = 'FROM wp_fbf_ebay_packages_listings l
            LEFT JOIN wp_fbf_ebay_packages_orders o
                ON l.inventory_sku = o.sku
            WHERE l.status = %s
            AND l.type = %s';
        if(isset($_REQUEST['search']['value'])&&!empty($_REQUEST['search']['value'])){
            $s.= '
            AND l.name LIKE \'%%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) .'%%\'';
        }
        // Group by order qty
        $s.= '
        GROUP BY l.id';
        if(isset($_REQUEST['order'][0]['column'])) {
            $dir = $_REQUEST['order'][0]['dir'];
            if ($_REQUEST['order'][0]['column'] === '0') {
                $s .= '
                ORDER BY l.name ' . strtoupper($dir);
            }else if ($_REQUEST['order'][0]['column'] === '1'){
                $s .= '
                ORDER BY l.created ' . strtoupper($dir);
            }else if($_REQUEST['order'][0]['column']==='2'){
                $s.= '
                ORDER BY o_qty ' . strtoupper($dir);
            }
        }

        $s2 = 'SELECT *, l.listing_id as l_id, l.id as t_id, l.qty as l_qty, l.created as l_created, SUM(o.qty) as o_qty
        ' . $s;
        $non_paginated_q = $wpdb->prepare("{$s2}", 'active', $type);
        $count_results = $wpdb->get_results($non_paginated_q, ARRAY_A);
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
                        'pKey' => $result['t_id']
                    ],
                    'name' => $result['name'],
                    'created' => $result['l_created'],
                    'l_id' => $result['l_id'],
                    'qty' => $result['l_qty'],
                    'id' => $result['t_id'],
                    'o_qty' => $result['o_qty']?:0
                ];
            }
        }

        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => count($count_results),
            'recordsFiltered' => count($count_results),
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

    public function fbf_ebay_packages_synchronise_package()
    {
        if(Fbf_Ebay_Packages_Admin::synchronise('manual', 'packages')){
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
        $resp = [];
        $next = wp_next_scheduled( Fbf_Ebay_Packages_Cron::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK );
        if(!$next){
            $reschedule = wp_schedule_event( time(), 'hourly', Fbf_Ebay_Packages_Cron::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK );
            $resp['reschedule'] = $reschedule;
        }else{
            $resp['next'] = $next;
        }
        echo json_encode($resp);
        die();
    }

    public function fbf_ebay_packages_unschedule()
    {
        $a = 1;
        $b = 2;

        Fbf_Ebay_Packages_Cron::unschedule();
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
                    $date->format('g:i:s A - jS M Y'),
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
        if($r['type']==='package'){
            $result['is_package'] = true;
            $result['package_info'] = $this->get_package_info($r['id']);
        }

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

    public function fbf_ebay_packages_delete_package()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');
        $id = filter_var($_POST['id'], FILTER_SANITIZE_STRING);
        $resp = [];
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $q = $wpdb->prepare("SELECT * from {$table} WHERE id = %s", $id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r&&!is_null($r['inventory_sku'])) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-fbf-ebay-packages-api-auth.php';
            $auth = new Fbf_Ebay_Packages_Api_Auth();
            $token = $auth->get_valid_token();

            $clean = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/' . $r['inventory_sku'], 'DELETE', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);

            if($clean['status']==='success'&&$clean['response_code']===204){
                $offers_table = $wpdb->prefix . 'fbf_ebay_packages_offers';
                $d3 = $wpdb->delete($offers_table,
                    [
                        'offer_id' => $r['offer_id']
                    ]
                );
                $listing_compatability_table = $wpdb->prefix . 'fbf_ebay_packages_listing_compatibility';
                $d4 = $wpdb->delete($listing_compatability_table,
                    [
                        'listing_id' => $r['id']
                    ]
                );
            }else{
                echo json_encode([
                    'status' => 'error',
                    'eBay returned an error, response code: ' . $clean['response_code']
                ]);
                die();
            }
        }
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $d1 = $wpdb->delete($skus_table,
            [
                'listing_id' => $r['id']
            ]
        );
        $post_ids_table = $wpdb->prefix . 'fbf_ebay_packages_package_post_ids';
        $d2 = $wpdb->delete($post_ids_table,
            [
                'listing_id' => $r['id']
            ]
        );
        $d5 = $wpdb->delete($table,
            [
                'id' => $r['id']
            ]
        );
        if($d5&&$d2&&$d1){
            $resp['status'] = 'success';
        }else{
            $resp['status'] = 'error';
            $resp['error'] = '$d1 or $d2 or $d5 is false';
        }

        echo json_encode($resp);
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

    private function get_package_info($id)
    {
        $info = [];
        global $wpdb;
        $post_ids_table = $wpdb->prefix . 'fbf_ebay_packages_package_post_ids';
        $q = $wpdb->prepare("SELECT * FROM {$post_ids_table} WHERE listing_id = %s", $id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r){
            $data = unserialize($r['post_ids']);
            $key = "boughto_chassis_{$data['chassis_id']}";
            $transient = get_transient($key);
            $wheel = wc_get_product($data['wheel_id']);
            $wheel_name = $wheel->get_title();
            $wheel_url = $wheel->get_permalink();
            $tyre = wc_get_product($data['tyre_id']);
            $tyre_name = $tyre->get_title();
            $tyre_url = $tyre->get_permalink();
            $nut_bolt = wc_get_product($data['nut_bolt_id']);
            $nut_bolt_name = $nut_bolt->get_title();
            $nut_bolt_url = $nut_bolt->get_permalink();
            $info['chassis'] = [
                'id' => $data['chassis_id'],
                'name' => $transient['manufacturer']['name'] . ' ' . $transient['model']['name'] . ' ' . $transient['generation']['name'],
            ];
            $info['wheel'] = [
                'id' => $data['wheel_id'],
                'name' => $wheel_name,
                'permalink' => $wheel_url,
            ];
            $info['tyre'] = [
                'id' => $data['tyre_id'],
                'name' => $tyre_name,
                'permalink' => $tyre_url,
            ];
            $info['nut_bolt'] = [
                'id' => $data['nut_bolt_id'],
                'name' => $nut_bolt_name,
                'permalink' => $nut_bolt_url,
            ];
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

    private function remove_duplicates($chassis_id, $wheel_ids = [])
    {
        include_once(ABSPATH.'wp-admin/includes/plugin.php');
        if(is_plugin_active('fbf-wheel-search/fbf-wheel-search.php')) {
            require_once plugin_dir_path(WP_PLUGIN_DIR . '/fbf-wheel-search/fbf-wheel-search.php') . 'includes/class-fbf-wheel-search-boughto-api.php';
            $api = new \Fbf_Wheel_Search_Boughto_Api('fbf_wheel_search', 'fbf-wheel-search');
            $wheel_data = $api->get_wheels($chassis_id);

            $centre_bore = $wheel_data['chassis']['center_bore'];

            if(!is_null($centre_bore)){
                $centre_bore = number_format((float) $centre_bore, 2, '.', '');
            }

            $filtered = [];
            $centre_bore_values = [];
            $centre_bore_remove_ids = [];
            $name_groups = [];
            $i = 0;

            foreach ($wheel_ids as $item) {
                if(has_term('alloy-wheel', 'product_cat', $item)){
                    $item_a = [];
                    $product = wc_get_product($item);
                    $prod_title = $product->get_title();
                    $et_pos = strpos($prod_title, 'ET', 2);
                    $title = substr($prod_title, 0, $et_pos + 2);
                    $et = substr($prod_title, $et_pos + 2);
                    $et_a = explode(' ', $et);
                    $et_val = $et_a[0];
                    unset($et_a[0]);
                    $colour = implode(' ', $et_a);
                    $item_a['et'] = $et_val;
                    $item_a['index'] = $i;
                    $title = $title . ' ' . $colour;
                    $filtered[$title][] = $item_a;
                }

                if(has_term('steel-wheel', 'product_cat', $item)){
                    $centre_bore_values[$item] = [
                        'id' => $item,
                        'centre_bore' => get_the_terms($item, 'pa_centre-bore') ? number_format((float) get_the_terms($item, 'pa_centre-bore')[0]->name, 2, '.', '') : '',
                        'title' => get_the_title($item),
                        'pcd' => get_the_terms($item, 'pa_wheel-pcd')[0]->name,
                        'load-rating' => get_the_terms($item, 'pa_wheel-load-rating')[0]->name
                    ];
                }

                $i++;
            }

            foreach ($centre_bore_values as $ci => $centre_bore_value){
                $name_groups[$centre_bore_value['title']][$ci] = $centre_bore_value;
            }

            foreach($name_groups as $ni => $name_group){
                if(count($name_group) > 1){
                    $col = array_column( $name_group, 'centre_bore' );
                    array_multisort( $col, SORT_ASC, $name_group );
                    $ids_to_remove = array_column( $name_group, 'id' );

                    foreach($name_group as $gi => $gv){
                        if((float) $gv['centre_bore'] >= (float) $centre_bore){
                            $p = wc_get_product($gv['id']);
                            if($p->get_stock_quantity() > 0){
                                if (isset($wheel_load_rating)) {
                                    $wheel_load_rating = $gv['load-rating'];
                                }
                                unset($ids_to_remove[$gi]);
                                break;
                            }
                        }
                    }

                    $remove_items = [];
                    foreach($ids_to_remove as $id_to_remove){
                        $remove_item = $name_group[array_search($id_to_remove, array_column($name_group, 'id'))];
                        if(isset($wheel_load_rating) && $remove_item['load-rating'] != $wheel_load_rating){
                            $remove_items[] = $remove_item['id'];
                        }
                    }
                    foreach($remove_items as $ri){
                        if(array_search($ri, $ids_to_remove)!==false){
                            unset($ids_to_remove[array_search($ri, $ids_to_remove)]);
                        }
                    }

                    if(count($ids_to_remove)){
                        foreach($ids_to_remove as $id_to_remove){
                            array_push($centre_bore_remove_ids, $id_to_remove);
                        }
                    }
                }
            }

            if (!empty($filtered)) {
                foreach ($filtered as $group) {
                    //Order the group so that the lowest ET is first
                    array_multisort(array_column($group, 'et'), SORT_ASC, $group);

                    $i = 0;
                    foreach ($group as $group_item) {
                        //Only keep the first wheel in the group! (one with the lowest ET)
                        if ($i > 0) {
                            unset($wheel_ids[$group_item['index']]);
                        }
                        $i++;
                    }
                }
            }

            if(!empty($centre_bore_remove_ids)){
                foreach($centre_bore_remove_ids as $cb_id){
                    if(array_search($cb_id, $wheel_ids)!==false){
                        unset($wheel_ids[array_search($cb_id, $wheel_ids)]);
                    }
                }
            }
        }
        return $wheel_ids;
    }
}
