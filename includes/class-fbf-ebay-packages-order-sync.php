<?php
/**
 * Defines the Endpoint for syncing Orders and related functionality
 *
 *
 * @package    Fbf_Ebay_Packages
 * @subpackage Fbf_Ebay_Packages/admin
 * @author     Kevin Price-Ward <kevin.price-ward@4x4tyres.co.uk>
 */

class Fbf_Ebay_Packages_Order_Sync extends Fbf_Ebay_Packages_Admin
{
    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    protected $plugin_name;

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

        add_action('parse_request', array($this, 'endpoint'), 0);
    }

    public function endpoint()
    {
        global $wp;

        $endpoint_vars = $wp->query_vars;

        // if endpoint
        if ($wp->request == 'ebay_orders') {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
            $auth = new Fbf_Ebay_Packages_Api_Auth();
            $token = $auth->get_valid_token();
            if($token['status']==='success') {
                $orders = $this->api('https://api.ebay.com/sell/fulfillment/v1/order?filter=orderfulfillmentstatus:%7BNOT_STARTED%7CIN_PROGRESS%7D', 'GET', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);
                if($orders['status']==='success'&&($orders['response_code']===200)){
                    $this->sync_orders(json_decode($orders['response']));
                }
            }
            echo 'ebay orders';
            die();
        }
    }

    private function sync_orders($orders)
    {
        if($orders->total){
            $orders = $orders->orders;
            foreach($orders as $order){
                // 1. Is there an order in Woo for this eBay Order
                $ebay_order_id = $order->orderId;
                $args = [
                    'post_type' => 'shop_order',
                    'posts_per_page' => -1,
                    'post_status' => 'any',
                    'meta_key' => '_ebay_order_number',
                    'meta_value' => $ebay_order_id,
                    'meta_compare' => '=',
                    'fields' => 'ids',
                ];
                $woo_order_id = get_posts($args);

                if(empty($woo_order_id)){
                    // Woo Order does not exist for eBay order so need to create it here
                    $order = $this->create_order($order);
                }else{
                    // Woo Order does exist, check status here
                    $woo_order = wc_get_order($woo_order_id[0]);

                    // If the Woo Order is Complete - update the eBay Order setting the status to completed and updating the order with the Tracking Information
                }
            }
        }
    }

    private function create_order($order)
    {
        global $wpdb;
        // Use the Woo API to create the order and add the meta
        $woo_order = wc_create_order();
        $discount = 0;

        // Add the eBay order number as meta
        $ebay_id = $order->orderId;
        update_post_meta($woo_order->get_id(), '_ebay_order_number', $ebay_id);

        $line_items = [];
        foreach($order->lineItems as $lineItem){
            $line_items[] = [
                'id' => $lineItem->lineItemId,
                'qty' => $lineItem->quantity,
            ];
        }
        update_post_meta($woo_order->get_id(), '_ebay_order_line_items', $line_items);

        // Contact details
        $names = explode(' ', $order->buyer->buyerRegistrationAddress->fullName);
        $lastname = array_pop($names);
        $firstname = implode(' ', $names);
        $billing_names = explode(' ', $order->fulfillmentStartInstructions[0]->shippingStep->shipTo->fullName);
        $billing_lastname = $lastname = array_pop($billing_names);
        $billing_firstname = implode(' ', $billing_names);

        $address = [
            'first_name' => $firstname,
            'last_name'  => $lastname,
            'company'    => $order->buyer->buyerRegistrationAddress->companyName,
            'email'      => $order->buyer->buyerRegistrationAddress->email,
            'phone'      => substr($order->buyer->buyerRegistrationAddress->primaryPhone->phoneNumber,0,1)==='0' ? $order->buyer->buyerRegistrationAddress->primaryPhone->phoneNumber : '0' . $order->buyer->buyerRegistrationAddress->primaryPhone->phoneNumber,
            'address_1'  => $order->buyer->buyerRegistrationAddress->contactAddress->addressLine1,
            'address_2'  => $order->buyer->buyerRegistrationAddress->contactAddress->addressLine2,
            'city'       => $order->buyer->buyerRegistrationAddress->contactAddress->city,
            'state'      => $order->buyer->buyerRegistrationAddress->contactAddress->stateOrProvince,
            'postcode'   => $order->buyer->buyerRegistrationAddress->contactAddress->postalCode,
            'country'    => $order->buyer->buyerRegistrationAddress->contactAddress->countryCode,
        ];
        $shipping_address = [
            'first_name' => $billing_firstname,
            'last_name' => $billing_lastname,
            'address_1' => $order->fulfillmentStartInstructions[0]->shippingStep->shipTo->contactAddress->addressLine1,
            'address_2' => $order->fulfillmentStartInstructions[0]->shippingStep->shipTo->contactAddress->addressLine2,
            'city' => $order->fulfillmentStartInstructions[0]->shippingStep->shipTo->contactAddress->city,
            'state' => $order->fulfillmentStartInstructions[0]->shippingStep->shipTo->contactAddress->stateOrProvince,
            'postcode' => $order->fulfillmentStartInstructions[0]->shippingStep->shipTo->contactAddress->postalCode,
            'country' => $order->fulfillmentStartInstructions[0]->shippingStep->shipTo->contactAddress->countryCode,
        ];
        $woo_order->set_address( $address, 'billing' );
        $woo_order->set_address( $shipping_address, 'shipping' );

        // Taxes
        $calculate_taxes_for = array(
            'country'  => $address['country'],
            'state'    => $address['state'],
            'postcode' => $address['postcode'],
            'city'     => $address['city'],
        );

        // Add the items
        foreach($order->lineItems as $lineItem){
            // Insert the line item into the orders table for reporting statistics
            $ebay_orders_table = $wpdb->prefix . 'fbf_ebay_packages_orders';
            $i = $wpdb->insert($ebay_orders_table, [
                'qty' => $lineItem->quantity,
                'sku' => $lineItem->sku,
                'ebay_order_id' => $ebay_id
            ]);

            $qty = $lineItem->quantity;
            $sku_a = explode('.', $lineItem->sku);

            // Handle
            if(in_array('q4', $sku_a)===true){
                $qty = 4 * $lineItem->quantity;;
            }else if(in_array('q1', $sku_a)){
                $qty = 1 * $lineItem->quantity;
            }

            // Firstly is it a package?
            if(str_starts_with($lineItem->sku, 'tp')){
                $listings_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
                $post_ids_table = $wpdb->prefix . 'fbf_ebay_packages_package_post_ids';
                $q = $wpdb->prepare("SELECT *
                    FROM {$listings_table} l
                    LEFT JOIN {$post_ids_table} p
                    ON l.id = p.listing_id
                    WHERE l.inventory_sku = %s", $lineItem->sku);
                $r = $wpdb->get_row($q, ARRAY_A);
                if(!is_null($r)){
                    $post_ids = unserialize($r['post_ids']);
                    $wheel_item_id = $woo_order->add_product(wc_get_product($post_ids['wheel_id']), $qty);
                    $wheel_line_item = $woo_order->get_item($wheel_item_id, false);
                    $wheel_line_item->save();
                    $tyre_item_id = $woo_order->add_product(wc_get_product($post_ids['tyre_id']), $qty);
                    $tyre_line_item = $woo_order->get_item($tyre_item_id, false);
                    $tyre_line_item->save();

                    // Calculate how many nuts or bolts are required
                    $wheel_product = wc_get_product($post_ids['wheel_id']);
                    $pcd = $wheel_product->get_attribute('pa_wheel-pcd');
                    $per_wheel = substr($pcd, 0, 1);
                    $nut_bolt_qty = $per_wheel * $qty;
                    $nut_bolt_item_id = $woo_order->add_product(wc_get_product($post_ids['nut_bolt_id']), $nut_bolt_qty);
                    $nut_bolt_line_item = $woo_order->get_item($nut_bolt_item_id, false);
                    $nut_bolt_line_item->save();

                    // Handle TPMS
                    if($post_ids['has_tpms']){
                        $tpms_item = get_field('tpms_sensor', 'options');
                        $tpms_product = wc_get_product($tpms_item[0]);
                        $tpms_item_id = $woo_order->add_product($tpms_product, $qty);
                        $tpms_line_item = $woo_order->get_item($tpms_item_id, false);
                        $tpms_line_item->save();
                    }

                    // Now apply a discount for the same value as the nuts/bolts
                    $discount+= wc_get_price_excluding_tax(wc_get_product($post_ids['nut_bolt_id'])) * $nut_bolt_qty;
                }
            }else{
                $sku = $sku_a[array_key_last($sku_a)];
                $id = wc_get_product_id_by_sku($sku);
                $item_id = $woo_order->add_product(wc_get_product($id), $qty);
                $line_item  = $woo_order->get_item( $item_id, false);
                $line_item->calculate_taxes($calculate_taxes_for);
                $line_item->save();
            }
        }

        $woo_order->calculate_totals();

        // Set the status
        $woo_order->set_status('processing');

        // Shipping
        if($shipping_zones = WC_Shipping_Zones::get_zones()){
            if($zone = array_values($shipping_zones)[array_search('UK', array_column($shipping_zones, 'zone_name'))]){
                foreach($zone['shipping_methods'] as $mk => $method){
                    if($method->id==='free_shipping'){
                        $shipping = new WC_Order_Item_Shipping();
                        $shipping->set_method_title( 'Free shipping' );
                        $shipping->set_method_id( 'free_shipping:' . $method->get_instance_id() ); // set an existing Shipping method ID
                        $shipping->set_total( 0 ); // optional
                        $woo_order->add_item($shipping);
                        break;
                    }
                }
            }
        }

        // Payment
        $woo_order->set_payment_method('cod');
        $woo_order->set_payment_method_title( 'eBay order' );

        // Add Note
        $woo_order->add_order_note('eBay order number: ' . $ebay_id, false);

        // Fees
        // Get the customer country code
        $country_code = $woo_order->get_shipping_country();

        // Set the array for tax calculations
        $calculate_tax_for = array(
            'country' => $country_code,
            'state' => '',
            'postcode' => '',
            'city' => ''
        );

        // Get a new instance of the WC_Order_Item_Fee Object
        $item_fee = new WC_Order_Item_Fee();

        $item_fee->set_name( "Discount" ); // Generic fee name
        $item_fee->set_amount( -$discount ); // Fee amount
        $item_fee->set_tax_class( '' ); // default for ''
        $item_fee->set_tax_status( 'taxable' ); // or 'none'
        $item_fee->set_total( -$discount ); // Fee amount

        // Calculating Fee taxes
        $item_fee->calculate_taxes( $calculate_tax_for );
        $woo_order->add_item($item_fee);
        $woo_order->calculate_totals();

        // Save
        $woo_order->save();
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
}
