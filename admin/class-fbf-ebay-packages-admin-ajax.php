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

    public function fbf_ebay_packages_tyre_table()
    {
        global $wpdb;
        $draw = $_REQUEST['draw'];
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
            AND l.name LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) .'%\' OR s.sku LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) .'%\' OR l.listing_id LIKE \'%' . filter_var($_REQUEST['search']['value'], FILTER_SANITIZE_STRING) . '%\'';
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

        $main_q = $wpdb->prepare("{$s1}", 'active', 'tyre');
        $count = $wpdb->get_row($main_q, ARRAY_A)['count'];

        $s2 = 'SELECT *, l.listing_id as l_id
        ' . $s;
        $paginated_q = $wpdb->prepare("{$s2}
            LIMIT {$length}
            OFFSET {$start}", 'active', 'tyre');
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
                    'id' => $result['id']
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
        if($results===false||count($results)===0){
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
        $resp = Fbf_Ebay_Packages_Admin::clean('manual', 'tyres');
        echo json_encode($resp);
        die();
    }

    public function fbf_ebay_packages_synchronise()
    {
        if(Fbf_Ebay_Packages_Admin::synchronise('manual', 'tyres')){
            $status = 'success';
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
        $q = $wpdb->prepare("SELECT hook, UNIX_TIMESTAMP(created) AS d
            FROM {$table}
            WHERE type = %s
            ORDER BY d DESC
            LIMIT {$synchronisations_to_show}", 'tyres');
        $results = $wpdb->get_results($q, ARRAY_A);
        if($results!==false){
            foreach($results as $result){
                $date = new DateTime();
                $date->setTimezone($timezone);
                $date->setTimestamp($result['d']);
                $data[] = [
                    $date->format('g:i:s A'),
                    $result['hook']
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
        ];

        echo json_encode([
            'status' => 'success',
            'result' => $result
        ]);
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
}
