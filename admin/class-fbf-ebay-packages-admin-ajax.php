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

    public function fbf_ebay_packages_list_tyres()
    {
        check_ajax_referer($this->plugin_name, 'ajax_nonce');

        global $wpdb;
        // First get all products that match
        $brands_option = get_option('_fbf_ebay_packages_tyre_brands');
        $search_brands = [];
        $post_skus = [];
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
            while($found->have_posts()){
                $found->the_post();
                $id = get_the_ID();
                $product = wc_get_product( $id );
                $post_skus[] = $product->get_sku();
            }
        }

        // Second get ALL the current Tyre listings



        echo json_encode([
            'status' => 'success',
            'message' => 'testing success',
            'skus' => $post_skus
        ]);
        die();
    }
}
