<?php

class Fbf_Ebay_Packages_List_Package extends Fbf_Ebay_Packages_List_Item
{
    public function list_item(WC_Product $wheel, WC_Product $tyre, WC_Product $nut_bolt, $qty, $result)
    {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();

        if($token['status']==='success'){
            $payload_wheel = $this->item_payload($wheel, $qty, 'wheel');
            $payload_tyre = $this->item_payload($tyre, $qty, 'tyre');

            $payload = $this->item_payload($wheel, $tyre, $nut_bolt, 4);
        }
    }

    protected function item_payload(WC_Product $product, $qty, $type, WC_Product $tyre = null, WC_Product $nut_bolt = null){
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
            $item['availability'] = [
                'shipToLocationAvailability' => [
                    'quantity' => max(floor(($product->get_stock_quantity() - $this->buffer) / $qty), 0),
                ]
            ];
        }
    }
}
