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
        $listings_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $q = $wpdb->prepare("SELECT description from {$post_ids_table} WHERE listing_id = %s", $result->listing_id);
        $r = $wpdb->get_col($q);
        $this->package_description = $r[0];
        $force_inv_update = true;

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
                if($curr_qty!=min($wheel_stock, $tyre_stock) || $result->inventory_sku===null || $force_inv_update){
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
                    // Exists - do we need to update it?
                    $new_update_required = $this->is_offer_update_required($result->offer_id, $wheel, $tyre, $qty);

                    // Force an update
                    $new_update_required = true;

                    if($new_update_required){
                        // Update the offer
                        $offer_payload = $this->offer_payload($wheel, $tyre, $sku, $qty, $result->type, $result->listing_id);
                        $offer_update = $this->api('https://api.ebay.com/sell/inventory/v1/offer/' . $result->offer_id, 'PUT', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($offer_payload));

                        if ($offer_update['status']==='success'&&($offer_update['response_code'] === 200||$offer_update['response_code'] === 204)) {
                            // Update the offer here
                            $this->insert_or_update_offer($result->offer_id, $offer_payload);

                            // Unset the listingDescription in $offer_payload so we don't include it in the log
                            unset($offer_payload['listingDescription']);

                            // Figure out if we need to publish it
                            $q = $wpdb->prepare("SELECT listing_id FROM {$listings_table} WHERE id = %s", $result->listing_id);
                            $res = $wpdb->get_col($q, 0);
                            if(is_null($res[0])){
                                // listing_id is null - need to publish
                                $publish_offer_id = $result->offer_id;
                                $offer_status = 'UNPUBLISHED';
                            }


                            $this->logs[] = $this->log($result->listing_id, 'update_offer', [
                                'status' => 'success',
                                'action' => 'updated',
                                'response' => $offer_update,
                                'payload' => $offer_payload
                            ]);
                        }else{
                            // Unset the listingDescription in $offer_payload so we don't include it in the log
                            unset($offer_payload['listingDescription']);

                            $this->logs[] = $this->log($result->listing_id, 'update_offer', [
                                'status' => 'error',
                                'action' => 'none required',
                                'response' => $offer_update,
                                'payload' => $offer_payload
                            ]);
                        }
                    }else{
                        if($this->log_everything===true){ // Don't bother to log if log_everything is false
                            $this->logs[] = $this->log($result->listing_id, 'update_offer', [
                                'status' => 'success',
                                'action' => 'none required'
                            ]);
                        }
                    }
                }else{
                    // Doesn't exist - create the offer
                    $offer_payload = $this->offer_payload($wheel, $tyre, $sku, $qty, $result->type, $result->listing_id);
                    $offer_create = $this->api('https://api.ebay.com/sell/inventory/v1/offer', 'POST', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB'], json_encode($offer_payload));

                    if($offer_create['status']==='success'&&$offer_create['response_code']===201){
                        $offer_id = json_decode($offer_create['response']);

                        // Create the offer here
                        $this->insert_or_update_offer($offer_id->offerId, $offer_payload);

                        // Created
                        $this->update_offer_id($offer_id->offerId, $result->listing_id, $offer_payload);
                        $publish_offer_id = $offer_id->offerId;
                        $offer_status = 'UNPUBLISHED';

                        $this->logs[] = $this->log($result->listing_id, 'create_offer', [
                            'status' => 'success',
                            'action' => 'created',
                            'response' => $offer_create
                        ]);
                    }else{
                        // Unset the listingDescription in $offer_payload so we don't include it in the log
                        unset($offer_payload['listingDescription']);

                        $this->logs[] = $this->log($result->listing_id, 'create_offer', [
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
        if(!is_null($publish_offer_id) && $offer_status==='UNPUBLISHED') {
            $publish = $this->api('https://api.ebay.com/sell/inventory/v1/offer/' . $publish_offer_id . '/publish/', 'POST', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);

            if($publish['status']==='success'&&$publish['response_code']===200){
                $publish_response = json_decode($publish['response']);
                $listing_id = $publish_response->listingId;
                $this->update_listing_id($listing_id, $result->listing_id);
                /*$this->status['publish_offer_status'] = 'success';
                $this->status['publish_offer_response'] = $publish_response;*/


                // On publishing we need to re-save



                $this->logs[] = $this->log($result->listing_id, 'publish_offer', [
                    'status' => 'success',
                    'action' => 'published',
                    'response' => $publish
                ]);
            }else{
                /*$this->status['publish_offer_status'] = 'error';
                $this->status['publish_offer_response'] = json_decode($publish['response']);*/

                $this->logs[] = $this->log($result->listing_id, 'publish_offer', [
                    'status' => 'error',
                    'action' => 'none required',
                    'response' => $publish
                ]);
            }
        }
        return $this;
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
            // Comment out weight and size because potentially causing an issue with publishing the listing
            /*$item['packageWeightAndSize'] = [
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
            ];*/
            $wheel_aspects = $this->get_wheel_aspects($wheel, $qty);
            $tyre_aspects = $this->get_tyre_aspects($tyre, $qty);
            $title = stripslashes(html_entity_decode($result->name));

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
                    }else if($wheel_key==='Brand'){
                        $brand = $wheel_aspect[0];
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
                'aspects' => $aspects,
                'mpn' => 'tp.q' . $qty . '.' . $result->sku,
            ];
            if($brand){
                $item['product']['brand'] = $brand;
            }
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
        $ebay_price = (float) $ebay_price_tyre + (float) $ebay_price_wheel;
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
        $offer['categoryId'] = '179679';
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

    private function is_offer_update_required($offer_id, WC_Product $wheel, WC_Product $tyre, int $qty){
        global $wpdb;
        $offer_table = $wpdb->prefix . 'fbf_ebay_packages_offers';

        $ebay_price_wheel = get_post_meta($wheel->get_id(), '_ebay_price', true);
        $ebay_price_tyre = get_post_meta($tyre->get_id(), '_ebay_price', true);
        $ebay_price = $ebay_price_tyre + $ebay_price_wheel;
        if($ebay_price > 0) {
            $reg_price = $ebay_price;
        }else{
            $reg_price = (float) $wheel->get_regular_price() + (float) $tyre->get_regular_price();
        }
        $vat = ($reg_price/100) * 20;
        $product_price = number_format(($reg_price + $vat) * $qty, 2, '.', '');

        $product_qty = min(max(floor(($wheel->get_stock_quantity() - $this->buffer) / $qty), 0), max(floor(($tyre->get_stock_quantity() - $this->buffer) / $qty), 0));

        // Look in the $offer_table for the id
        $q = $wpdb->prepare("SELECT *
            FROM {$offer_table}
            WHERE offer_id = %s", $offer_id);
        $r = $wpdb->get_row($q, ARRAY_A);
        if($r!==false&&!empty($r)) {
            // We have a match!
            if (!empty($r['payload'])) {
                $payload = unserialize($r['payload']);
                if(is_array($payload) && isset($payload['availableQuantity']) && isset($payload['pricingSummary']['price']['value'])){
                    if((int)$payload['availableQuantity']===(int)$product_qty&&(float)$payload['pricingSummary']['price']['value']===(float)$product_price){
                        return false;
                    }else{
                        return true;
                    }
                }
            }
        }
        return true;
    }
}
