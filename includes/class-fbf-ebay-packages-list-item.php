<?php
/**
 * Class Fbf_Ebay_Packages_List_Item
 *
 * Deals with listing one item on eBay
 */

class Fbf_Ebay_Packages_List_Item
{
    private $plugin_name;
    private $version;
    private $use_test_image = true; // switch to false to use actual thumbnails
    private $test_image = 'https://4x4tyres.co.uk/app/uploads/2019/12/Cooper_Discoverer_AT3_4S-1000x1000.png';
    private $buffer = 4;
    public $status = [];
    public $logs = [];
    private $description = 'This listing is for 4 brand new tyres in the size and style specified in the listing title\r\n
    We are one of the country’s leading suppliers of All Terrain and Mud Terrain Tyres to suit 4x4 and SUV\r\n
    Delivery is through a 3rd party carrier.  We advise not booking tyre fitting until the tyres have been delivered\r\n
    Any questions, please feel free to ask.  Thanks';

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function list_item(WC_Product $product, $result, int $qty)
    {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();
        $inv_item_created = false;

        if($token['status']==='success'){
            $payload = $this->item_payload($product, $qty);

            // Only create the item if the image exists
            if(isset($payload['product']['imageUrls'])){
                $sku = $this->generate_sku($product, $qty);
                $curr_name = $result->name;
                $curr_qty = $result->qty;

                // If there is a change of name or a change of quantity OR if the inventory item has not yet been created:
                if($curr_name!=$product->get_title() || $curr_qty!=$product->get_stock_quantity() || $result->inventory_sku===null){

                    //Create or update inventory item
                    $create_or_update_inv = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/' . $sku, 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($payload));

                    if($create_or_update_inv['status']==='success'&&$create_or_update_inv['response_code']===204){
                        $this->update_listing($product, $sku, $result->id);
                        $this->logs[] = $this->log($result->id, 'create_or_update_inv', [
                            'status' => 'success',
                            'action' => $result->inventory_sku===null?'created':'updated',
                            'response' => $create_or_update_inv,
                        ]);
                        $inv_item_created = true;
                    }else{
                        $this->logs[] = $this->log($result->id, 'create_or_update_inv', [
                            'status' => 'error',
                            'action' => 'none required',
                            'response' => $create_or_update_inv
                        ]);
                    }
                }else{
                    // Here if no update required
                    $this->logs[] = $this->log($result->id, 'create_or_update_inv', [
                        'status' => 'success',
                        'action' => 'none required',
                        'response' => json_decode('')
                    ]);
                    $inv_item_created = true;
                }
            }else{
                $this->logs[] = $this->log($result->id, 'create_or_update_inv', [
                    'status' => 'error',
                    'action' => 'none required',
                    'response' => [
                        'error_msg' => 'No image'
                    ],
                ]);
            }



            //Create or update the offer
            $publish_offer_id = null;
            $offer_status = null;
            if($inv_item_created){
                //First see if there is already an Offer ID
                if(!is_null($result->offer_id)){
                    // Exists - do we need to update it?
                    $offer = $this->api('https://api.ebay.com/sell/inventory/v1/offer/' . $result->offer_id, 'GET', ['Authorization: Bearer ' . $token['token']]);
                    if($offer['status']==='success'&&$offer['response_code']===200) {
                        $update_required = false;
                        $offer_response = json_decode($offer['response']);
                        $offer_price = floatval($offer_response->pricingSummary->price->value);
                        $publish_offer_id = $result->offer_id;
                        $offer_status = $offer_response->status;

                        if(is_a( $product, 'WC_Product_Variable' )){
                            $product_price = (float)$product->get_variation_regular_price() * $qty;
                        }else{
                            $product_price = (float)$product->get_regular_price() * $qty;
                        }

                        if ($offer_price !== $product_price) {
                            $update_required = true;
                        }
                        $offer_qty = (int)$offer_response->availableQuantity;
                        $product_qty = (int)floor(($product->get_stock_quantity() - $this->buffer) / $qty);
                        if ($offer_qty !== $product_qty) {
                            $update_required = true;
                        }
                        if ($curr_name != $product->get_title()) {
                            $update_required = true;
                        }

                        // Force an update
                        //$update_required = true;


                        if ($update_required) {
                            // Update the offer
                            $offer_payload = $this->offer_payload($product, $sku, $qty);
                            $offer_update = $this->api('https://api.ebay.com/sell/inventory/v1/offer/' . $result->offer_id, 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($offer_payload));

                            if ($offer_update['status']==='success'&&$offer_update['response_code'] === 204) {

                                /*$this->status['update_offer_status'] = 'success';
                                $this->status['update_offer_action'] = 'updated';*/

                                $this->logs[] = $this->log($result->id, 'update_offer', [
                                    'status' => 'success',
                                    'action' => 'updated',
                                    'response' => $offer_update
                                ]);

                            } else {
                                /*$this->status['update_offer_status'] = 'error';
                                $this->status['update_offer_action'] = 'none';
                                $this->status['update_offer_response'] = json_decode($offer_update['response']);*/

                                $this->logs[] = $this->log($result->id, 'update_offer', [
                                    'status' => 'error',
                                    'action' => 'none required',
                                    'response' => $offer_update
                                ]);
                            }
                        }else{
                            //$this->status['update_offer_status'] = 'success';
                            //$this->status['update_offer_action'] = 'none'; // Update wasn't required - still report as successful

                            $this->logs[] = $this->log($result->id, 'update_offer', [
                                'status' => 'success',
                                'action' => 'none required',
                                'response' => $offer
                            ]);
                        }
                    }else{
                        /*$this->status['update_offer_status'] = 'error';
                        $this->status['update_offer_action'] = 'none';
                        $this->status['update_offer_response'] = 'OfferID not found on eBay';*/

                        $this->logs[] = $this->log($result->id, 'update_offer', [
                            'status' => 'error',
                            'action' => 'none required',
                            'response' => $offer
                        ]);
                    }

                }else{
                    // Doesn't exist - create the offer
                    $offer_payload = $this->offer_payload($product, $sku, $qty);
                    $offer_create = $this->api('https://api.ebay.com/sell/inventory/v1/offer', 'POST', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($offer_payload));
                    if($offer_create['status']==='success'&&$offer_create['response_code']===201){
                        $offer_id = json_decode($offer_create['response']);
                        // Created
                        $this->update_offer_id($offer_id->offerId, $result->id);
                        /*$this->status['create_offer_status'] = 'success';
                        $this->status['create_offer_response'] = $offer_id;*/
                        $publish_offer_id = $offer_id->offerId;
                        $offer_status = 'UNPUBLISHED';

                        $this->logs[] = $this->log($result->id, 'create_offer', [
                            'status' => 'success',
                            'action' => 'created',
                            'response' => $offer_create
                        ]);
                    }else{
                        /*$this->status['create_offer_status'] = 'error';
                        $this->status['create_offer_response'] = json_decode($offer_create['response']);*/

                        $this->logs[] = $this->log($result->id, 'create_offer', [
                            'status' => 'error',
                            'action' => 'none required',
                            'response' => $offer_create
                        ]);
                    }
                }
            }
        }

        // Publish the offer
        if(!is_null($publish_offer_id) && $offer_status==='UNPUBLISHED'){
            $publish = $this->api('https://api.ebay.com/sell/inventory/v1/offer/' . $publish_offer_id . '/publish/', 'POST', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);

            if($publish['status']==='success'&&$publish['response_code']===200){
                $publish_response = json_decode($publish['response']);
                $listing_id = $publish_response->listingId;
                $this->update_listing_id($listing_id, $result->id);
                /*$this->status['publish_offer_status'] = 'success';
                $this->status['publish_offer_response'] = $publish_response;*/

                $this->logs[] = $this->log($result->id, 'publish_offer', [
                    'status' => 'success',
                    'action' => 'published',
                    'response' => $publish
                ]);
            }else{
                /*$this->status['publish_offer_status'] = 'error';
                $this->status['publish_offer_response'] = json_decode($publish['response']);*/

                $this->logs[] = $this->log($result->id, 'publish_offer', [
                    'status' => 'error',
                    'action' => 'none required',
                    'response' => $publish
                ]);
            }
        }
        return $this;
    }

    private function log($id, $ebay_action, $log)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_logs';
        $i = $wpdb->insert(
            $table,
            [
                'listing_id' => $id,
                'ebay_action' => $ebay_action,
                'log' => serialize($log)
            ]
        );
        if($i!==false){
            return $wpdb->insert_id;
        }else{
            return $i;
        }
    }

    private function update_listing_id($listing_id, $id)
    {
        global $wpdb;
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $q = $wpdb->prepare("UPDATE {$listing_table} l
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    SET l.listing_id = %s
                    WHERE l.id = %s", $listing_id, $id);
        $result = $wpdb->query($q);

        return $result;
    }

    private function update_offer_id($offer_id, $id)
    {
        global $wpdb;
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $q = $wpdb->prepare("UPDATE {$listing_table} l
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    SET l.offer_id = %s
                    WHERE l.id = %s", $offer_id, $id);
        $result = $wpdb->query($q);

        return $result;
    }

    private function update_listing(WC_Product $product, $sku, $id)
    {
        global $wpdb;
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $q = $wpdb->prepare("UPDATE {$listing_table} l
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    SET l.name = %s,
                        l.qty = %s,
                        l.inventory_sku = %s
                    WHERE l.id = %s", $product->get_title(), $product->get_stock_quantity(), $sku, $id);
        $result = $wpdb->query($q);

        return $result;
    }

    private function item_payload(WC_Product $product, $qty){
        $item = [];
        $brand_terms = get_the_terms($product->get_id(), 'pa_brand-name');

        if(!empty($brand_terms)){
            $brand_term = $brand_terms[0];
            $brand_name = $brand_term->name;
        }

        // Only add the availability node if it's available - otherwise API errors
        if($product->get_stock_quantity() >= $this->buffer){
            $item['availability'] = [
                'shipToLocationAvailability' => [
                    'quantity' => max(floor(($product->get_stock_quantity() - $this->buffer) / $qty), 0),
                ]
            ];
        }

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
            'description' => $this->description,
            'brand' => $brand_name,
            'mpn' => $product->get_sku(),
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

        return $item;
    }

    private function offer_payload(WC_Product $product, $sku, $qty)
    {
        $offer = [];
        if(is_a($product, 'WC_Product_Variable')){
            $reg_price = $product->get_variation_regular_price();
        }else{
            $reg_price = $product->get_regular_price();
        }
        $offer['sku'] = $sku;
        $offer['marketplaceId'] = 'EBAY_GB';
        $offer['format'] = 'FIXED_PRICE';
        $offer['availableQuantity'] = max(floor(($product->get_stock_quantity() - $this->buffer) / $qty), 0);
        $offer['categoryId'] = '179680';
        $offer['listingDescription'] = $this->description;
        $offer['listingPolicies'] = [
            'fulfillmentPolicyId' => '163248243010',
            'paymentPolicyId' => '191152500010',
            'returnPolicyId' => '163248142010'
        ];
        $offer['pricingSummary'] = [
            'price' => [
                'currency' => 'GBP',
                'value' => number_format((float)$reg_price * $qty, 2)
            ]
        ];
        $offer['quantityLimitPerBuyer'] = 1;
        $offer['includeCatalogProductDetails'] = true;
        $offer['merchantLocationKey'] = 'STRAT';

        return $offer;
    }

    private function get_tyre_aspects(WC_Product $product, $qty = 1)
    {
        $aspects = [];

        // MPN
        $aspects['Manufacturer Part Number'] = [
            $product->get_sku()
        ];

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
        }else{
            $aspects['Tyre fuel efficiency (A-G)'] = [ // This is a required field - G is the fallback
                'G'
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
        }else{
            $aspects['External rolling noise (dB; class)'] = [ // This is a required field - N/A is the fallback
                'N/A'
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
        }else{
            $aspects['Wet grip performance (A-G)'] = [ // This is a required field - N/A is the fallback
                'N/A'
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

    private function generate_sku(WC_Product $product, $qty)
    {
        $sku = $product->get_sku();
        $sku_prefix = '';
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
        return $sku_prefix . $sku;
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
            $resp['response_code'] = curl_getinfo($curl)['http_code'];
        }

        curl_close($curl);
        return $resp;
    }
}
