<?php

class Fbf_Ebay_Packages_List_Package extends Fbf_Ebay_Packages_List_Item
{
    private $package_description;

    public function list_item(WC_Product $wheel, WC_Product $tyre, WC_Product $nut_bolt, $qty, $result)
    {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        global $wpdb;
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();
        $post_ids_table = $wpdb->prefix . 'fbf_ebay_packages_package_post_ids';
        $q = $wpdb->prepare("SELECT description from {$post_ids_table} WHERE listing_id = %s", $result->listing_id);
        $r = $wpdb->get_col($q);
        $this->package_description = $r[0];

        if($token['status']==='success'){
            $payload = $this->item_payload($wheel, 4, '', $result, $tyre, $nut_bolt, $r[0]);

            // Only create the item if the image exists
            if(isset($payload['product']['imageUrls'])) {
                $sku = 'tp.q' . $qty . '.' . $result->sku;
                $curr_qty = $result->qty;

                // Stock
                $wheel_stock = get_post_meta($wheel->get_id(), '_stock', true);
                $tyre_stock = get_post_meta($tyre->get_id(), '_stock', true);

                // If there is a change of quantity OR if the inventory item has not yet been created:
                if($curr_qty!=min($wheel_stock, $tyre_stock) || $result->inventory_sku===null){
                    //Create or update inventory item
                    $create_or_update_inv = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/' . $sku, 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($payload));

                    if($create_or_update_inv['status']==='success'&&($create_or_update_inv['response_code']===204||$create_or_update_inv['response_code']===200)){
                        $this->update_listing(min($wheel_stock, $tyre_stock), $sku, $result->listing_id);
                        $this->logs[] = $this->log($result->listing_id, 'create_or_update_inv', [
                            'status' => 'success',
                            'action' => $result->inventory_sku===null?'created':'updated',
                            'response' => $create_or_update_inv,
                        ]);
                        $inv_item_created = true;
                    }else{
                        $this->logs[] = $this->log($result->listing_id, 'create_or_update_inv', [
                            'status' => 'error',
                            'action' => 'none required',
                            'response' => $create_or_update_inv,
                            'payload' => $payload
                        ]);
                    }
                }else{
                    // Here if no update required
                    if($this->log_everything===true) { // Don't bother to log if log_everything is false
                        $this->logs[] = $this->log($result->listing_id, 'create_or_update_inv', [
                            'status' => 'success',
                            'action' => 'none required',
                            'response' => json_decode('')
                        ]);
                    }
                    $inv_item_created = true;
                }
            }else{
                $this->logs[] = $this->log($result->listing_id, 'create_or_update_inv', [
                    'status' => 'error',
                    'action' => 'none required',
                    'response' => [
                        'error_msg' => 'No image'
                    ],
                    'payload' => $payload
                ]);
            }

            //Handle the compatibility
            if(isset($inv_item_created) && $inv_item_created===true){
                $q = $wpdb->prepare("SELECT post_ids from {$post_ids_table} WHERE listing_id = %s", $result->listing_id);
                $r = $wpdb->get_col($q)[0];
                if($r){
                    $chassis_id = unserialize($r)['chassis_id'];
                    if($compatibilty_payload = $this->compatibility_payload($chassis_id)){
                        if($compatibilty_payload && !$this->is_listing_compatibility_same($compatibilty_payload, $result->listing_id)){
                            $create_or_update_compatibility = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/'.$sku.'/product_compatibility', 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], $compatibilty_payload);
                            if($create_or_update_compatibility['status']==='success' && (
                                    $create_or_update_compatibility['response_code']===200 ||
                                    $create_or_update_compatibility['response_code']===201 ||
                                    $create_or_update_compatibility['response_code']===204)
                            ){
                                $this->save_update_listing_compatibility($compatibilty_payload, $result->listing_id);
                                $this->logs[] = $this->log($result->listing_id, 'product_compat', [
                                    'status' => 'success',
                                    'action' => 'created',
                                    'response' => $create_or_update_compatibility,
                                ]);
                            }else{
                                $this->logs[] = $this->log($result->listing_id, 'product_compat', [
                                    'status' => 'error',
                                    'action' => 'none required',
                                    'response' => $create_or_update_compatibility,
                                    'payload' => $compatibilty_payload
                                ]);
                            }
                        }else{
                            if($this->log_everything===true){ // Don't bother to log if log_everything is false
                                $this->logs[] = $this->log($result->listing_id, 'product_compat', [
                                    'status' => 'success',
                                    'action' => 'none required',
                                    'response' => json_decode('')
                                ]);
                            }
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

                }else{
                    // Doesn't exist - create the offer
                    $offer_payload = $this->offer_payload($wheel, $tyre, $sku, $qty, $result->type, $result->listing_id);
                }
            }
        }
    }

    protected function item_payload(WC_Product $product, $qty, $type, $result = null, WC_Product $tyre = null, WC_Product $nut_bolt = null, $description = null){
        $item = [];
        $wheel = $product;
        $wheel_brand_terms = get_the_terms($wheel->get_id(), 'pa_brand-name');
        if(!empty($wheel_brand_terms)){
            $wheel_brand_term = $wheel_brand_terms[0];
            $wheel_brand_name = $wheel_brand_term->name;
        }
        $tyre_brand_terms = get_the_terms($wheel->get_id(), 'pa_brand-name');
        if(!empty($tyre_brand_terms)){
            $tyre_brand_term = $tyre_brand_terms[0];
            $tyre_brand_name = $tyre_brand_term->name;
        }

        // Only add the availability node if it's available - otherwise API errors
        if($tyre->get_stock_quantity() >= $this->buffer && $wheel->get_stock_quantity() >= $this->buffer){
            $tyre_qty = floor(($tyre->get_stock_quantity()-$this->buffer)/$qty);
            $wheel_qty = floor(($wheel->get_stock_quantity()-$this->buffer)/$qty);
            $stock_qty = min($tyre_qty, $wheel_qty);
            $item['availability'] = [
                'shipToLocationAvailability' => [
                    'quantity' => $stock_qty,
                ]
            ];
            $item['condition'] = 'NEW';
            $item['packageWeightAndSize'] = [
                'dimensions' => [
                    'height' => $tyre->get_height(),
                    'length' => $tyre->get_length(),
                    'unit' => 'CENTIMETER',
                    'width' => $tyre->get_width()
                ],
                'packageType' => 'BULKY_GOODS',
                'weight' => [
                    'unit' => 'KILOGRAM',
                    'value' => ($tyre->get_weight() + $wheel->get_weight()) * $qty,
                ]
            ];
            $wheel_aspects = $this->get_wheel_aspects($wheel, $qty);
            $tyre_aspects = $this->get_tyre_aspects($tyre, $qty);
            $title = html_entity_decode($result->name);

            // Combine the aspects for packages
            $aspects = [];
            if($tyre_aspects['Manufacturer Part Number']){
                $aspects['Manufacturer Part Number'][] = $tyre_aspects['Manufacturer Part Number'][0];
            }
            if($wheel_aspects['Manufacturer Part Number']){
                $aspects['Manufacturer Part Number'][] = $wheel_aspects['Manufacturer Part Number'][0];
            }
            if($tyre_aspects['Brand']){
                $aspects['Tyre Brand'][] = $tyre_aspects['Brand'][0];
            }
            if($wheel_aspects['Brand']){
                $aspects['Wheel Brand'][] = $wheel_aspects['Brand'][0];
            }
            if($tyre_aspects['Type']){
                $aspects['Tyre Type'][] = $tyre_aspects['Type'][0];
            }
            if($wheel_aspects['Type']){
                $aspects['Wheel Type'][] = $wheel_aspects['Type'][0];
            }
            $aspects['Unit Quantity'][] = $qty;
            $aspects['Custom Bundle'][] = 'Yes';
            $aspects['Bundle Description'][] = 'Bundle of 4 Wheels and Tyres';
            $aspects['Modified Item'][] = 'No';
            $aspects['Unit Type'][] = 'Unit';

            foreach($wheel_aspects as $wheel_key => $wheel_aspect){
                if(!key_exists($wheel_key, $aspects)){
                    if($wheel_key!=='Brand' && $wheel_key!=='Type'){
                        $aspects[$wheel_key] = $wheel_aspect;
                    }
                }
            }
            foreach($tyre_aspects as $tyre_key => $tyre_aspect){
                if(!key_exists($tyre_key, $aspects)){
                    if($tyre_key!=='Brand' && $tyre_key!=='Type') {
                        $aspects[$tyre_key] = $tyre_aspect;
                    }
                }
            }

            $item['product'] = [
                'title' => $title,
                'description' => $description,
                'aspects' => $aspects
            ];
            // Image
            if($this->use_test_image){
                $item['product']['imageUrls'] = [
                    $this->test_image
                ];
            }else{
                if($external_wheel_image = get_post_meta($wheel->get_id(), '_external_product_image', true)){
                    $main_wheel_image = $external_wheel_image['full'];
                }else{
                    $main_wheel_image = wp_get_attachment_image_src(get_post_thumbnail_id($wheel->get_id()), 'fbf-1950-1950')[0];
                }
                if($external_tyre_image = get_post_meta($tyre->get_id(), '_external_product_image', true)){
                    $main_tyre_image = $external_tyre_image['full'];
                }else{
                    $main_tyre_image = wp_get_attachment_image_src(get_post_thumbnail_id($tyre->get_id()), 'fbf-1950-1950')[0];
                }
                $nut_bolt_image = wp_get_attachment_image_src(get_post_thumbnail_id($nut_bolt->get_id()), 'fbf-1950-1950')[0];
                $item['product']['imageUrls'] = [
                    $main_tyre_image,
                    $main_wheel_image,
                    $nut_bolt_image
                ];
            }
        }
        return $item;
    }

    private function update_listing($qty, $sku, $id)
    {
        global $wpdb;
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $u = $wpdb->update($listing_table, [
            'qty' => $qty,
            'inventory_sku' => $sku
        ], [
            'id' => $id
        ]);

        return $u;
    }

    private function compatibility_payload($chassis_id)
    {
        global $wpdb;
        $compatibility_table = $wpdb->prefix . 'fbf_ebay_packages_compatibility';
        $q = $wpdb->prepare("SELECT payload FROM {$compatibility_table} WHERE chassis_id = %s", $chassis_id);
        $r = $wpdb->get_col($q);
        $compatibility = [];
        if($r){
            foreach($r as $comp){
                $props = [
                    'compatibilityProperties' => unserialize($comp)
                ];
                $compatibility[] = $props;
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

    private function offer_payload($wheel, $tyre, $sku, $qty, $type, $id)
    {
        $offer = [];
        $ebay_price_wheel = get_post_meta($wheel->get_id(), '_ebay_price', true);
        $ebay_price_tyre = get_post_meta($tyre->get_id(), '_ebay_price', true);
        $ebay_price = $ebay_price_tyre + $ebay_price_wheel;
        if($ebay_price > 0) {
            $reg_price = $ebay_price;
        }else{
            $reg_price = (float) $wheel->get_regular_price() + (float) $tyre->get_regular_price();
        }

        $listing_description = $this->package_description;
        $limitPerBuyer = 1;

        $vat = ($reg_price/100) * 20;
        $reg_price = number_format(($reg_price + $vat) * $qty, 2, '.', '');
        $offer['sku'] = $sku;
        $offer['marketplaceId'] = 'EBAY_GB';
        $offer['format'] = 'FIXED_PRICE';
        $offer['availableQuantity'] = min(max(floor(($wheel->get_stock_quantity() - $this->buffer) / $qty), 0), max(floor(($tyre->get_stock_quantity() - $this->buffer) / $qty), 0));
    }
}
