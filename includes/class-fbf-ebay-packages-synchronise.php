<?php

class Fbf_Ebay_Packages_Synchronise
{
    private $plugin_name;
    private $version;
    private $products = []; // This is somewhere to store WC products that we need to retrieve quickly
    private $buffer = 4;
    private $packs = [4]; // Could be [1, 2, 4] or [1, 4] depends on what we want to list!
    private $limit = null; // limit the amount of items we are creating during testing
    private $use_test_image = false; // switch to false to use actual thumbnails
    private $test_image = 'https://4x4tyres.co.uk/app/uploads/2019/12/Cooper_Discoverer_AT3_4S-1000x1000.png';
    private $synch_items = [];
    private $log_ids = [];

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function run($type)
    {
        global $wpdb;
        $resp = [];
        $resp['start'] = microtime(true);

        // Firstly let's get the names and qty of the products re-aligned
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $q = $wpdb->prepare("SELECT *
            FROM {$listing_table} l
            INNER JOIN {$skus_table} s
                ON s.listing_id = l.id
            WHERE l.status = %s
            AND l.type = %s", 'active', $type);
        $results = $wpdb->get_results( $q );

        if($results!==false){
            $updates = [];
            $count = 0;


            foreach($results as $result){

                // These 2 calls could be expensive when we're dealing with 1000's of listings - may need to find a way of caching the product info!
                $product_id = wc_get_product_id_by_sku($result->sku);
                $product = wc_get_product($product_id);
                $this->products[$result->sku] = $product;

                /*if($curr_name!=$product->get_title() || $curr_qty!=$product->get_stock_quantity()){
                    //Update needed
                    if($this->update_listing($result->sku, $product->get_title(), $product->get_stock_quantity())){
                        $updates[] = [
                            'sku' => $result->sku,
                            'offer_id' => $result->offer_id,
                            'title' => $product->get_title(),
                            'stock' => $product->get_stock_quantity()
                        ];
                    }
                }*/ // Move this check into the list-item class


                foreach($this->packs as $qty){
                    if($this->limit && $count >= $this->limit){
                        //break out of the loops
                        break 2;
                    }

                    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-list-item.php';
                    $ebay = new Fbf_Ebay_Packages_List_Item($this->plugin_name, $this->version);
                    $item = $ebay->list_item($product, $result, $qty);
                    $this->log_ids = array_merge($this->log_ids, $item->logs);
                    $this->synch_items[] = $item;
                    $count++;
                }
            }
        }




        //Now at a point where we can think about synch'ing to eBay
        /*$count = 0;
        foreach($this->packs as $qty){
            foreach($this->products as $product){
                if($this->limit && $count >= $this->limit){
                    //break out of the loops
                    break 2;
                }

                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-list-item.php';
                $ebay = new Fbf_Ebay_Packages_List_Item($this->plugin_name, $this->version);
                $item = $ebay->list_item($product, $qty);
                $this->synch_items[] = $item;
                $count++;
            }
        }*/



        /*require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();



        if($token['status']==='success'){
            $token = $token['token'];

            //Create or Update the inventory items
            $json = json_encode($this->build_items_payload());
            $payload = '{"requests": ' . $json . '}'; // TODO: loop through batches of 25 at a time
            $create_inventory_items = $this->api('https://api.ebay.com/sell/inventory/v1/bulk_create_or_replace_inventory_item', 'POST', ['Authorization: Bearer ' . $token, 'Content-Type:application/json'], $payload);
            // Check that everything is OK with inventory creation
            if($create_inventory_items['status']==='success'){
                $inventory_response = json_decode($create_inventory_items['response']);
                $resp['inventory_updates'] = $inventory_response->responses;

                //Create or Update the listings

            }else{
                $resp['status'] = $create_inventory_items['status'];
                $resp['errors'][] = $create_inventory_items['errors'];
            }

        }else{
            $resp['status'] = $token['status'];
            $resp['errors'][] = 'eBay Token error';
        }*/

        $resp['end'] = microtime(true);
        $resp['execution_time'] = $resp['end'] - $resp['start'];
        $resp['log_ids'] = $this->log_ids;
        return $resp;

    }

    private function update_listing($sku, $name, $qty)
    {
        global $wpdb;
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $q = $wpdb->prepare("UPDATE {$listing_table} l
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    SET l.name = %s,
                        l.qty = %s
                    WHERE s.sku = %s
                    AND l.type = %s", $name, $qty, $sku, 'tyre');
        $result = $wpdb->query($q);

        return $result;
    }

    private function build_items_payload()
    {
        $count = 0;
        $items = [];

        foreach($this->packs as $qty){
            foreach($this->products as $product_key => $product){
                if($count < $this->limit){
                    $item = $this->item_payload($product_key, $product, $qty);
                    $items[] = $item;
                    $count++;
                }
            }
        }
        return $items;
     }

    private function item_payload($sku, WC_Product $product, $qty){
        $item = [];
        $sku_prefix = '';
        $brand_terms = get_the_terms($product->get_id(), 'pa_brand-name');

        $cat_terms = get_the_terms( $product->get_id(), 'product_cat' );
        foreach ($cat_terms as $term) {
            $product_cat = $term->name;
            break;
        }
        if(!is_null($product_cat)){
            if($product_cat==='Tyre'){
                $sku_prefix = 'tt.q' . $qty . '.';
            }else if($product_cat==='Steel Wheel'||$product_cat==='Alloy Wheel'){
                $sku_prefix = 'tw.1' . $qty . '.';
            }
        }

        if(!empty($brand_terms)){
            $brand_term = $brand_terms[0];
            $brand_name = $brand_term->name;
        }
        $item['availability'] = [
            'shipToLocationAvailability' => [
                'quantity' => floor(($product->get_stock_quantity() - $this->buffer) / $qty),
            ]
        ];
        $item['condition'] = 'NEW';
        $item['PackageWeightAndSize'] = [
            'dimensions' => [
                'height' => $product->get_height(),
                'length' => $product->get_length(),
                'unit' => 'CENTIMETER',
                'width' => $product->get_width()
            ],
            'packageType' => 'BULKY_GOODS',
            'weight' => [
                'unit' => 'KILOGRAM',
                'value' => $product->get_weight()
            ]
        ];
        $item['product'] = [
            'title' => sprintf('%s x %s', $qty, $product->get_title()),
            'description' => sprintf('%s x %s tyres', $qty, $product->get_title()),
            'brand' => $brand_name,
            'aspects' => $this->get_tyre_aspects($product, $qty)
        ];
        // Image
        if($this->use_test_image){
            $item['product']['imageUrls'] = [
                $this->test_image
            ];
        }else{
            if(has_post_thumbnail()){
                $image = wp_get_attachment_image_src(get_post_thumbnail_id($product->get_id()), 'full')[0];
                $item['product']['imageUrls'] = [
                    $image
                ];
            }
        }

        $item['sku'] = $sku_prefix . $sku;
        $item['locale'] = 'en_GB';
        return $item;
    }

    private function get_tyre_aspects(WC_Product $product, $qty = 1)
    {
        $aspects = [];

        // Brand
        $brand_terms = get_the_terms($product->get_id(), 'pa_brand-name');
        if(!empty($brand_terms)){
            $brand_term = $brand_terms[0];
            $brand_name = $brand_term->name;
            $aspects['Brand'] = [
                $brand_name
            ];
        }

        // Aspect Ratio
        $aspect_ratios = get_the_terms($product->get_id(), 'pa_tyre-profile');
        if(!empty($aspect_ratios)){
            $aspect_ratio = $aspect_ratios[0];
            $aspect_ratio_name = $aspect_ratio->name;
            $aspects['Aspect Ratio'] = [
                $aspect_ratio_name
            ];
        }

        // Tyre Width
        $tyre_widths = get_the_terms($product->get_id(), 'pa_tyre-width');
        if(!empty($tyre_widths)){
            $tyre_width = $tyre_widths[0];
            $tyre_width_name = $tyre_width->name;
            $aspects['Tyre Width'] = [
                $tyre_width_name
            ];
        }

        // Rim Diameter
        $rim_diameters = get_the_terms($product->get_id(), 'pa_tyre-size');
        if(!empty($rim_diameters)){
            $rim_diameter = $rim_diameters[0];
            $rim_diameter_name = $rim_diameter->name;
            $aspects['Rim Diameter'] = [
                $rim_diameter_name
            ];
        }

        // Tyre fuel efficiency (A-G)
        $fuel_efficiencies = get_the_terms($product->get_id(), 'pa_ec-label-fuel');
        if(!empty($fuel_efficiencies)){
            $fuel_efficiency = $fuel_efficiencies[0];
            $fuel_efficiency_name = $fuel_efficiency->name;
            $aspects['Tyre fuel efficiency (A-G)'] = [
                $fuel_efficiency_name
            ];
        }

        // External rolling noise (dB; class)
        $rolling_noises = get_the_terms($product->get_id(), 'pa_tyre-label-noise');
        if(!empty($rolling_noises)){
            $rolling_noise = $rolling_noises[0];
            $rolling_noise_name = $rolling_noise->name;
            $aspects['External rolling noise (dB; class)'] = [
                $rolling_noise_name
            ];
        }

        // Wet grip performance (A-G)
        $wet_grips = get_the_terms($product->get_id(), 'pa_ec-label-wet-grip');
        if(!empty($wet_grips)){
            $wet_grip = $wet_grips[0];
            $wet_grip_name = $wet_grip->name;
            $aspects['Wet grip performance (A-G)'] = [
                $wet_grip_name
            ];
        }

        // Speed Rating
        $speed_ratings = get_the_terms($product->get_id(), 'pa_load-speed-rating');
        if(!empty($speed_ratings)){
            $speed_rating = $speed_ratings[0];
            $speed_rating_name = $speed_rating->name;
            $aspects['Speed Rating'] = [
                substr($speed_rating_name, -1)
            ];
        }

        // Load Index
        $load_indexes = get_the_terms($product->get_id(), 'pa_tyre-load');
        if(!empty($load_indexes)){
            $load_index = $load_indexes[0];
            $load_index_name = $load_index->name;
            $aspects['Load Index'] = [
                $load_index_name
            ];
        }

        // Unit Quantity
        $aspects['Unit Quantity'] = [
            $qty
        ];

        // Type
        $types = get_the_terms($product->get_id(), 'pa_tyre-type');
        if(!empty($types)){
            $type = $types[0];
            $type_name = $type->name;
            $aspects['Type'] = [
                $type_name
            ];
        }

        // Vehicle Type
        $aspects['Vehicle Type'] = [
            'Offroad'
        ];

        // Fitting Included
        $aspects['Fitting Included'] = [
            'No'
        ];

        // Custom Bundle
        $aspects['Custom Bundle'] = [
            'Yes'
        ];

        // Bundle Description
        $aspects['Bundle Description'] = [
            'Bundle of ' . $qty . ' tyres'
        ];

        // Modified Item
        $aspects['Modified Item'] = [
            'No'
        ];

        // Country/Region of Manufacture
        $aspects['Country/Region of Manufacture'] = [
            'Unknown'
        ];

        // Unit Type
        $aspects['Unit Type'] = [
            'Unit'
        ];

        return $aspects;
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
            $resp['response_headers'] = $headers;
        }

        curl_close($curl);
        return $resp;
    }
}
