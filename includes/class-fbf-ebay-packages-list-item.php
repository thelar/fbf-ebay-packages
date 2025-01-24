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
    protected $use_test_image = false; // switch to false to use actual thumbnails
    protected $test_image = 'https://4x4tyres.co.uk/app/uploads/2019/12/Cooper_Discoverer_AT3_4S-1000x1000.png';
    protected $buffer = 4;
    public $status = [];
    public $logs = [];
    private $tyre_description = 'This listing is for 4 brand new tyres in the size and style specified in the listing title<br/>
    We are one of the country’s leading suppliers of All Terrain and Mud Terrain Tyres to suit 4x4 and SUV<br/>
    Delivery is through a 3rd party carrier.  We advise not booking tyre fitting until the tyres have been delivered<br/>
    Any questions, please feel free to ask.  Thanks';
    private $wheel_description = 'This listing is for 4 brand new wheels in the size and style specified in the listing title
    We are one of the country’s leading suppliers of Load Rated Steel and Alloy wheels to suit 4x4 and SUV.
    Delivery is through a 3rd party carrier. We advise not booking tyre fitting until the wheels have been delivered
    Any questions, please feel free to ask. Thanks';
    protected bool $log_everything = false;

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
            $payload = $this->item_payload($product, $qty, $result->type);

            // Only create the item if the image exists
            if(isset($payload['product']['imageUrls'])){
                $sku = $this->generate_sku($product, $qty);
                $curr_name = $result->name;
                $curr_qty = $result->qty;

                /*ob_start();
                print('<pre>');
                print_r($payload);
                print('</pre>');
                echo $product->get_title() . '<br/>';
                echo wp_get_attachment_image_src(get_post_thumbnail_id($product->get_id()), 'fbf-1950-1950')[0];
                $email = ob_get_clean();

                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=ISO-8859-1\r\n";
                wp_mail('kevin@code-mill.co.uk', 'Ebay test', $email, $headers);*/

                // If there is a change of name or a change of quantity OR if the inventory item has not yet been created:
                if($curr_name!=$product->get_title() || $curr_qty!=$product->get_stock_quantity() || $result->inventory_sku===null){

                    //Create or update inventory item
                    $create_or_update_inv = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/' . $sku, 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($payload));

                    if($create_or_update_inv['status']==='success'&&($create_or_update_inv['response_code']===204||$create_or_update_inv['response_code']===200)){
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
                            'response' => $create_or_update_inv,
                            'payload' => $payload
                        ]);
                    }
                }else{
                    // Here if no update required
                    if($this->log_everything===true) { // Don't bother to log if log_everything is false
                        $this->logs[] = $this->log($result->id, 'create_or_update_inv', [
                            'status' => 'success',
                            'action' => 'none required',
                            'response' => json_decode('')
                        ]);
                    }
                    $inv_item_created = true;
                }
            }else{
                $this->logs[] = $this->log($result->id, 'create_or_update_inv', [
                    'status' => 'error',
                    'action' => 'none required',
                    'response' => [
                        'error_msg' => 'No image'
                    ],
                    'payload' => $payload
                ]);
            }

            //$inv_item_created = true;

            //Handle the compatibility
            if(isset($inv_item_created) && $inv_item_created===true){
                if($compatibilty_payload = $this->compatibility_payload($result->id)){
                    if($compatibilty_payload && !$this->is_listing_compatibility_same($compatibilty_payload, $result->id)){
                        $create_or_update_compatibility = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/'.$sku.'/product_compatibility', 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], $compatibilty_payload);
                        if($create_or_update_compatibility['status']==='success' && (
                            $create_or_update_compatibility['response_code']===200 ||
                            $create_or_update_compatibility['response_code']===201 ||
                            $create_or_update_compatibility['response_code']===204)
                        ){
                            $this->save_update_listing_compatibility($compatibilty_payload, $result->id);
                            $this->logs[] = $this->log($result->id, 'product_compat', [
                                'status' => 'success',
                                'action' => 'created',
                                'response' => $create_or_update_compatibility,
                            ]);
                        }else{
                            $this->logs[] = $this->log($result->id, 'product_compat', [
                                'status' => 'error',
                                'action' => 'none required',
                                'response' => $create_or_update_compatibility,
                                'payload' => $compatibilty_payload
                            ]);
                        }
                    }else{
                        if($this->log_everything===true){ // Don't bother to log if log_everything is false
                            $this->logs[] = $this->log($result->id, 'product_compat', [
                                'status' => 'success',
                                'action' => 'none required',
                                'response' => json_decode('')
                            ]);
                        }
                    }
                }
            }

            //Create or update the offer
            $publish_offer_id = null;
            $offer_status = null;
            if(isset($inv_item_created) && $inv_item_created===true){
                //First see if there is already an Offer ID
                if(!is_null($result->offer_id)){
                    // Exists - do we need to update it?
                    $new_update_required = $this->is_offer_update_required($result->offer_id, $product, $qty);

                    // Force an update
                    //$new_update_required = true;

                    if ($new_update_required) {
                        // Update the offer
                        $offer_payload = $this->offer_payload($product, $sku, $qty, $result->type, $result->listing_id);
                        $offer_update = $this->api('https://api.ebay.com/sell/inventory/v1/offer/' . $result->offer_id, 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($offer_payload));

                        if ($offer_update['status']==='success'&&($offer_update['response_code'] === 200||$offer_update['response_code'] === 204)) {

                            // Update the offer here
                            $this->insert_or_update_offer($result->offer_id, $offer_payload);

                            // Unset the listingDescription in $offer_payload so we don't include it in the log
                            unset($offer_payload['listingDescription']);

                            $this->logs[] = $this->log($result->id, 'update_offer', [
                                'status' => 'success',
                                'action' => 'updated',
                                'response' => $offer_update,
                                'payload' => $offer_payload
                            ]);

                        } else {
                            // Unset the listingDescription in $offer_payload so we don't include it in the log
                            unset($offer_payload['listingDescription']);

                            $this->logs[] = $this->log($result->id, 'update_offer', [
                                'status' => 'error',
                                'action' => 'none required',
                                'response' => $offer_update,
                                'payload' => $offer_payload
                            ]);
                        }
                    }else{
                        if($this->log_everything===true){ // Don't bother to log if log_everything is false
                            $this->logs[] = $this->log($result->id, 'update_offer', [
                                'status' => 'success',
                                'action' => 'none required'
                            ]);
                        }
                    }
                }else{
                    // Doesn't exist - create the offer
                    $offer_payload = $this->offer_payload($product, $sku, $qty, $result->type, $result->listing_id);
                    $offer_create = $this->api('https://api.ebay.com/sell/inventory/v1/offer', 'POST', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($offer_payload));

                    if($offer_create['status']==='success'&&$offer_create['response_code']===201){
                        $offer_id = json_decode($offer_create['response']);

                        // Create the offer here
                        $this->insert_or_update_offer($offer_id, $offer_payload);

                        // Created
                        $this->update_offer_id($offer_id->offerId, $result->id, $offer_payload);
                        $publish_offer_id = $offer_id->offerId;
                        $offer_status = 'UNPUBLISHED';

                        $this->logs[] = $this->log($result->id, 'create_offer', [
                            'status' => 'success',
                            'action' => 'created',
                            'response' => $offer_create
                        ]);
                    }else{
                        // Unset the listingDescription in $offer_payload so we don't include it in the log
                        unset($offer_payload['listingDescription']);

                        $this->logs[] = $this->log($result->id, 'create_offer', [
                            'status' => 'error',
                            'action' => 'none required',
                            'response' => $offer_create,
                            'payload' => $offer_payload
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


                // On publishing we need to re-save



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

    public function clean_item($result)
    {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();

        $clean = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/' . $result->inventory_sku, 'DELETE', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);
        if($clean['status']==='success'&&$clean['response_code']===204){
            //remove the database entries
            global $wpdb;
            $table = $wpdb->prefix . 'fbf_ebay_packages_listings';
            $offers_table = $wpdb->prefix . 'fbf_ebay_packages_offers';
            $u = $wpdb->update($table,
                [
                    'inventory_sku' => null,
                    'offer_id' => null,
                    'listing_id' => null
                ],
                [
                    'id' => $result->id
                ]
            );

            if($result->offer_id){
                $d = $wpdb->delete($offers_table,
                    [
                        'offer_id' => $result->offer_id
                    ]
                );
            }

            $this->log($result->id, 'delete_inv', [
                'status' => 'success',
                'action' => 'deleted',
                'response' => $clean
            ]);

        }else{
            $this->log($result->id, 'delete_inv', [
                'status' => 'error',
                'action' => 'none required',
                'response' => $clean
            ]);
        }

        return $clean;
    }

    public function fulfill_order($fulfillment_info, $ebay_order_num)
    {
        $a = 1;
        $woo_order_num = (string) $fulfillment_info->orderNo;
        $woo_lines = get_post_meta($woo_order_num, '_ebay_order_line_items', true);
        $lines = [];
        foreach($woo_lines as $woo_line){
            $lines[] = [
                'lineItemId' => $woo_line['id'],
                'quantity' => $woo_line['qty'],
            ];
        }
        $payload = [
            'lineItems' => $lines,
            'shippedDate' => DateTime::createFromFormat('d/m/Y H:i:s', (string)$fulfillment_info->deliveries->deliveryDate)->format('c'),
        ];
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();
        $fulfillment = $this->api(sprintf('https://api.ebay.com/sell/fulfillment/v1/order/%s/shipping_fulfillment', $ebay_order_num), 'POST', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($payload));
        return $fulfillment;
    }

    protected function log($id, $ebay_action, $log)
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

    private function is_offer_update_required($offer_id, WC_Product $product, int $qty)
    {
        global $wpdb;
        $offer_table = $wpdb->prefix . 'fbf_ebay_packages_offers';

        $ebay_price = get_post_meta($product->get_id(), '_ebay_price', true);
        if($ebay_price > 0){
            $product_price = $ebay_price;
        }else{
            if(is_a( $product, 'WC_Product_Variable' )){
                $product_price = (float)$product->get_variation_regular_price() * $qty;
            }else{
                $product_price = (float)$product->get_regular_price() * $qty;
            }
        }

        $vat = ($product_price/100) * 20;
        $product_price = round($product_price + $vat, 2);

        $product_qty = (int)floor(($product->get_stock_quantity() - $this->buffer) / $qty);

        $product_title = $product->get_title();

        // Look in the $offer_table for the id
        $q = $wpdb->prepare("SELECT *
            FROM {$offer_table}
            WHERE offer_id = %s", $offer_id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            // We have a match!
            if(!empty($r['payload'])){
                $payload = unserialize($r['payload']);
                if(is_array($payload) && isset($payload['availableQuantity']) && isset($payload['pricingSummary']['price']['value'])){
                    if((int)$payload['availableQuantity']!==$product_qty || (float)$payload['pricingSummary']['price']['value']!=$product_price){
                        /*return [
                            'payload_qty' => (int)$payload['availableQuantity'],
                            'prod_qty' => $product_qty,
                            'payload_price' => (float)$payload['pricingSummary']['price']['value'],
                            'prod_price' => $product_price
                        ];*/
                        return true;
                    }else{
                        return false;
                    }
                }
            }
        }
        return true;
    }

    protected function insert_or_update_offer($offer_id, $payload)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_offers';
        $q = $wpdb->prepare("SELECT *
            FROM {$table}
            WHERE offer_id = %s", $offer_id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            // Exists so update it
            $u = $wpdb->update($table,
                [
                    'payload' => serialize($payload)
                ],
                [
                    'offer_id' => $offer_id
                ]
            );
            return $u;
        }else{
            // Doesn't exist so create it
            $i = $wpdb->insert($table,
                [
                    'offer_id' => $offer_id,
                    'payload' => serialize($payload)
                ]
            );
            return $i;
        }
    }

    protected function update_listing_id($listing_id, $id)
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

    protected function update_offer_id($offer_id, $id, $offer_payload)
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

    protected function item_payload(WC_Product $product, $qty, $type){
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
        if($type==='tyre'){
            $aspects = $this->get_tyre_aspects($product, $qty);
            $description = $this->tyre_description;
        }else if($type==='wheel'){
            $aspects = $this->get_wheel_aspects($product, $qty);
            $description = $this->wheel_description;
        }
        $title = html_entity_decode($product->get_title());
        // Add Wheel to title and Steel if necessary


        if($type==='wheel'){
            if(strpos($title, 'Steel')!==false){
                $pos = strpos($title, 'Steel');
                $title = str_replace('Steel', 'Steel Wheels', $title);
            }else if(strpos($title, 'ET')!==false){
                $pos = strpos($title, 'ET');
                if(get_term_by('id', $product->get_category_ids()[0], 'product_cat')->name=='Steel Wheel'){
                    $title = substr_replace($title, ' Steel Wheels', $pos - 1, 0);
                }else if(get_term_by('id', $product->get_category_ids()[0], 'product_cat')->name=='Alloy Wheel'){
                    $title = substr_replace($title, ' Alloy Wheels', $pos - 1, 0);
                }
            }
        }
        if($qty > 1){
            $title = sprintf('%s x %s', $qty, $title);
        }
        $item['product'] = [
            'title' => $title,
            'description' => $description,
            'brand' => $brand_name,
            'mpn' => $product->get_sku(),
            'aspects' => $aspects
        ];
        // Ean
        if($product->get_attribute('ean')){
            $item['product']['ean'] = [
                $product->get_attribute('ean')
            ];
        }
        // Image
        if($this->use_test_image){
            $item['product']['imageUrls'] = [
                $this->test_image
            ];
        }else{
            //ob_start();
            if($external_image = get_post_meta($product->get_id(), '_external_product_image', true)){
                $item['product']['imageUrls'] = [
                    $external_image['full'],
                ];
            }else{
                $gal_images = [];
                if(has_post_thumbnail($product->get_id())){
                    //echo 'Here' . '<br/>';
                    $main_image = wp_get_attachment_image_src(get_post_thumbnail_id($product->get_id()), 'fbf-1950-1950')[0];
                    /*echo '<pre>';
                    print_r(wp_get_attachment_image_src(get_post_thumbnail_id($product->get_id()), 'fbf-1950-1950')[0]);
                    echo '</pre>';*/
                    $item['product']['imageUrls'] = [
                        $main_image
                    ];
                }
                if(!empty(get_post_meta($product->get_id(), '_product_image_gallery', true))){
                    $normal_image_gal = explode(',', get_post_meta($product->get_id(), '_product_image_gallery', true));

                    if(!empty($normal_image_gal)){
                        foreach($normal_image_gal as $attach_id){
                            $gal_images[] = wp_get_attachment_image_src($attach_id, 'full');
                        }
                    }
                }
                if(!empty(get_post_meta($product->get_id(), '_product_image_gallery', true))){
                    $ebay_image_gal = get_post_meta($product->get_id(), '_fbf_ebay_images', true);

                    if(!empty($ebay_image_gal)){
                        foreach($ebay_image_gal as $k => $attach_id){
                            if($k===0){
                                $main_image = wp_get_attachment_image_src($attach_id, 'fbf-1950-1950')[0];
                            }else{
                                $gal_images[] = wp_get_attachment_image_src($attach_id, 'full');
                            }
                        }
                    }
                }

                // Set image
                $item['product']['imageUrls'] = [
                    $main_image
                ];

                /*echo 'Main image:' . $main_image;
                print('<pre>');
                print_r($item['product']['imageUrls']);
                print('</pre>');*/


                if(!empty($gal_images)){
                    $a = [];
                    foreach($gal_images as $gk => $gi){
                        $pi = pathinfo($gi[0]);
                        $index = substr($pi['filename'], strrpos($pi['filename'], '_') + 1);
                        $key = 'image_' . $index;
                        $a[$key] = $pi['dirname'] . '/' . $pi['filename'] . '-1950x1950' . '.' . $pi['extension'];
                    }
                    ksort($a);
                    foreach($a as $image){
                        $item['product']['imageUrls'][] = $image;
                    }
                }


                /*print('<pre>');
                print_r($item['product']['imageUrls']);
                print('</pre>');

                $email = ob_get_clean();*/

                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=ISO-8859-1\r\n";
                //wp_mail('kevin@code-mill.co.uk', 'Ebay test', $email, $headers);
            }

        }
        return $item;
    }

    private function offer_payload(WC_Product $product, $sku, $qty, $type, $listing_id)
    {
        $offer = [];
        $ebay_price = get_post_meta($product->get_id(), '_ebay_price', true);
        if($ebay_price > 0){
            $reg_price = $ebay_price;
        }else{
            if(is_a($product, 'WC_Product_Variable')){
                $reg_price = $product->get_variation_regular_price();
            }else{
                $reg_price = $product->get_regular_price();
            }
        }

        if($this->get_html_listing($qty, $product->get_id(), $listing_id)){
            $listing_description = $this->get_html_listing($qty, $product->get_id(), $listing_id);
        }else{
            if($type==='tyre'){
                $listing_description = $this->tyre_description;
                if(floor(($product->get_stock_quantity() - $this->buffer) / $qty) > 4){
                    $limitPerBuyer = 4;
                }else{
                    $limitPerBuyer = max(floor(($product->get_stock_quantity() - $this->buffer) / $qty), 0);
                }
            }else if($type==='wheel'){
                $listing_description = $this->wheel_description;
                $limitPerBuyer = 1;
            }
        }

        $vat = ($reg_price/100) * 20;
        //$reg_price = round(($reg_price + $vat) * $qty, 2);
        $reg_price = number_format(($reg_price + $vat) * $qty, 2, '.', '');
        $offer['sku'] = $sku;
        $offer['marketplaceId'] = 'EBAY_GB';
        $offer['format'] = 'FIXED_PRICE';
        $offer['availableQuantity'] = max(floor(($product->get_stock_quantity() - $this->buffer) / $qty), 0);
        if($type==='tyre'){
            $offer['categoryId'] = '179680';
            $description = $this->tyre_description;
            $offer['availableQuantity'] = 0;
        }else if($type==='wheel'){
            $offer['categoryId'] = '179679';
            $description = $this->wheel_description;
        }
        $offer['listingDescription'] = $listing_description;
        $offer['listingPolicies'] = [
            //'fulfillmentPolicyId' => '163248243010',
            'fulfillmentPolicyId' => '197048873010',
            'paymentPolicyId' => '191152500010',
            'returnPolicyId' => '75920337010'
        ];


        $offer['pricingSummary'] = [
            'price' => [
                'currency' => 'GBP',
                'value' => $reg_price
            ]
        ];
        $offer['quantityLimitPerBuyer'] = $limitPerBuyer;
        $offer['includeCatalogProductDetails'] = true;
        $offer['merchantLocationKey'] = 'STRAT';

        return $offer;
    }

    private function compatibility_payload($id)
    {
        global $wpdb;
        $fittings_table = $wpdb->prefix . 'fbf_ebay_packages_fittings';
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $compatibility = [];
        $q = $wpdb->prepare("SELECT *
            FROM {$fittings_table} f
            LEFT JOIN {$compatibility_table} c
            ON f.chassis_id = c.chassis_id
            WHERE f.listing_id = %s", $id);
        $r = $wpdb->get_results($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            foreach($r as $result){
                $payload = unserialize($result['payload']);
                $props = [
                    'compatibilityProperties' => $payload
                ];
                if($payload!==false){
                    $compatibility[] = $props;
                }
            }
        }
        if(empty($compatibility)){
            return false;
        }else{
            return json_encode([
                'compatibleProducts' => $compatibility
            ]);
        }
    }

    protected function is_listing_compatibility_same($payload, $id)
    {
        global $wpdb;
        $listing_compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_listing_compatibility';
        $q = $wpdb->prepare("SELECT *
            FROM {$listing_compatibility_table}
            WHERE listing_id = %s", $id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r!==false&&!empty($r)){
            $saved_payload = json_decode($r['payload']);
            $payload = json_decode($payload);
            if($saved_payload==$payload){
                return true;
            }
        }
        return false;
    }

    protected function save_update_listing_compatibility($payload, $id)
    {
        global $wpdb;
        $listing_compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_listing_compatibility';
        $i = $wpdb->replace(
            $listing_compatibility_table,
            [
                'listing_id' => $id,
                'payload' => $payload
            ]
        );
        return $i;
    }

    protected function get_tyre_aspects(WC_Product $product, $qty = 1, $prefix = '')
    {
        $aspects = [];

        // MPN
        $aspects[$prefix . 'Manufacturer Part Number'] = [
            $product->get_sku()
        ];

        // Brand
        $brand_terms = get_the_terms($product->get_id(), 'pa_brand-name');
        if(!empty($brand_terms)){
            $brand_term = $brand_terms[0];
            $brand_name = $brand_term->name;
            $aspects[$prefix . 'Brand'] = [
                $brand_name
            ];
        }

        // Aspect Ratio
        $aspect_ratios = get_the_terms($product->get_id(), 'pa_tyre-profile');
        if(!empty($aspect_ratios)){
            $aspect_ratio = $aspect_ratios[0];
            $aspect_ratio_name = $aspect_ratio->name;
            if($aspect_ratio_name=='-'){
                $aspects['Aspect Ratio'] = [
                    '0'
                ];
            }else{
                $aspects['Aspect Ratio'] = [
                    $aspect_ratio_name
                ];
            }
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
        $aspects[$prefix . 'Unit Quantity'] = [
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

    protected function get_wheel_aspects(WC_Product $product, $qty = 1, $prefix = '')
    {
        $aspects = [];

        // MPN
        $aspects[$prefix . 'Manufacturer Part Number'] = [
            $product->get_sku()
        ];

        // Brand
        $brand_terms = get_the_terms($product->get_id(), 'pa_brand-name');
        if(!empty($brand_terms)){
            $brand_term = $brand_terms[0];
            $brand_name = $brand_term->name;
            $aspects[$prefix . 'Brand'] = [
                $brand_name
            ];
        }

        //new wheel stuff here
        // Rim Diameter
        $wheel_rim_diameters = get_the_terms($product->get_id(), 'pa_wheel-size');
        if(!empty($wheel_rim_diameters)){
            $wheel_rim_diameter = $wheel_rim_diameters[0];
            $wheel_rim_diameter_name = rtrim(html_entity_decode($wheel_rim_diameter->name), '"');
            $aspects['Rim Diameter'] = [
                $wheel_rim_diameter_name
            ];
        }

        // Rim Material
        $wheel_rim_materials = get_the_terms($product->get_id(), 'product_cat');
        if(!empty($wheel_rim_materials)){
            $wheel_rim_material = $wheel_rim_materials[0];
            $wheel_rim_material_name = $wheel_rim_material->name;
            if($wheel_rim_material_name=='Alloy Wheel'){
                $aspects['Rim Material'] = [
                    'Metal Alloy'
                ];
            }else if($wheel_rim_material_name=='Steel Wheel'){
                $aspects['Rim Material'] = [
                    'Steel'
                ];
            }
        }

        // Offset
        $wheel_offsets = get_the_terms($product->get_id(), 'pa_wheel-offset');
        if(!empty($wheel_offsets)){
            $wheel_offset = $wheel_offsets[0];
            $wheel_offset_name = $wheel_offset->name;
            $aspects['Offset'] = [
                $wheel_offset_name
            ];
        }

        // Rim Width
        $wheel_rim_widths = get_the_terms($product->get_id(), 'pa_wheel-width');
        if(!empty($wheel_rim_widths)){
            $wheel_rim_width = $wheel_rim_widths[0];
            $wheel_rim_width_name = rtrim(html_entity_decode($wheel_rim_width->name), '"');
            $aspects['Rim Width'] = [
                $wheel_rim_width_name
            ];
        }

        // Number of Studs & Stud Diameter
        $wheel_pcds = get_the_terms($product->get_id(), 'pa_wheel-pcd');
        if(!empty($wheel_pcds)) {
            $wheel_pcd = $wheel_pcds[0];
            $wheel_studs_diameter = explode('/', $wheel_pcd->name);
            $wheel_studs_name = $wheel_studs_diameter[0];
            $aspects['Number of Studs'] = [
                $wheel_studs_name
            ];
            $wheel_diameter_name = $wheel_studs_diameter[1];
            $aspects['Stud Diameter'] = [
                $wheel_diameter_name
            ];
        }

        // Type
        $aspects['Type'] = [
            'Wheel Rim'
        ];

        // Rim Structure
        $aspects['Rim Structure'] = [
            'One Piece'
        ];

        // Unit Quantity
        $aspects['Unit Quantity'] = [
            $qty
        ];

        // Custom Bundle
        $aspects['Custom Bundle'] = [
            'Yes'
        ];

        // Bundle Description
        $aspects['Bundle Description'] = [
            'Bundle of ' . $qty . ' wheels'
        ];

        // Modified Item
        $aspects['Modified Item'] = [
            'No'
        ];

        // Country/Region of Manufacture
        /*$aspects['Country/Region of Manufacture'] = [
            'China'
        ];*/

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
                $sku_prefix = 'tw.q' . $qty . '.';
            }
        }
        return $sku_prefix . $sku;
    }

    protected function api($url, $method, $headers, $body=null)
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

    private function get_html_listing($qty, $product_id, $listing_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $p = $wpdb->prepare("SELECT listing_id
            FROM {$table}
            WHERE id=%s", $listing_id);
        $r = $wpdb->get_row($p);

        if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            $url = "https://";
        else
            $url = "http://";
        // Append the host(domain name, ip) to the URL.
        $url.= $_SERVER['HTTP_HOST'];
        $template = $url . '/ebay_template?product_id=' . $product_id . '&qty=' . $qty;
        if($r->listing_id){
            $template.= '&listing_id=' . $r->listing_id;
        }
        $html = file_get_contents($template);
        if(!empty($html)){
            return $html;
        }else{
            return false;
        }
    }
}
