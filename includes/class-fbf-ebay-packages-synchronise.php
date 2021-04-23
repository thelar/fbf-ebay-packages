<?php


class Fbf_Ebay_Packages_Synchronise
{
    private $plugin_name;
    private $version;
    private $products = []; // This is somewhere to store WC products that we need to retrieve quickly
    private $buffer = 4;
    private $packs = [4]; // Could be [1, 2, 4] or [1, 4] depends on what we want to list!
    private $aspect_mapping = [
        'Brand' => 'pa_brand-name',
        'Tyre Width' => 'pa_tyre-width',
        'Aspect Ratio' => 'pa_tyre-profile',
        'Rim Diameter' => 'pa_tyre-size',
        'Speed Rating' => 'pa_tyre-speed',
        'Load Index' => '',
        'Type' => '',
        'Vehicle Type' => '',
        'Fitting Included' => '',
        'Custom Bundle' => '',
        'Bundle Description' => '',
        'Modified Item' => '',
        'Country/Region of Manufacture' => '',
        'Unit Type' => ''
    ];

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

            foreach($results as $result){
                $curr_name = $result->name;
                $curr_qty = $result->qty;

                // These 2 calls could be expensive when we're dealing with 1000's of listings - may need to find a way of caching the product info!
                $product_id = wc_get_product_id_by_sku($result->sku);
                $product = wc_get_product($product_id);
                $this->products[$result->sku] = $product;

                if($curr_name!=$product->get_title() || $curr_qty!=$product->get_stock_quantity()){
                    //Update needed
                    if($this->update_listing($result->sku, $product->get_title(), $product->get_stock_quantity())){
                        $updates[] = [
                            'sku' => $result->sku,
                            'title' => $product->get_title(),
                            'stock' => $product->get_stock_quantity()
                        ];
                    }
                }
            }

            if(!empty($updates)){
                $resp['listing_updates'] = $updates;
            }
        }

        //Now at a point where we can think about synch'ing to eBay
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();

        if($token['status']==='success'){
            $token = $token['token'];

            // Just test ebay here
            $inv = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item', 'GET', ['Authorization: Bearer ' . $token]);

        }else{
            $resp['status'] = $token['status'];
            $resp['error'] = 'eBay Token error';
        }

        $resp['end'] = microtime(true);
        $resp['execution_time'] = $resp['end'] - $resp['start'];
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
        foreach($this->packs as $qty){
            foreach($this->products as $product_key => $product){
                $item = $this->item_payload($product_key, $product, $qty);
            }
        }
    }

    private function item_payload($sku, WC_Product $product, $qty){
        $item = [];
        $item['availability'] = [
            'shipToLocationAvailability' => [
                'quantity' => floor(($product->get_stock_quantity() - $this->buffer) / $qty)
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

        ];
        return $item;
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
        }

        curl_close($curl);
        return $resp;
    }
}
