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
        // Use the Woo API to create the order and add the meta
        $woo_order = wc_create_order();

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
        $woo_order->set_address( $address, 'billing' );
        $woo_order->set_address( $address, 'shipping' );

        // Taxes
        $calculate_taxes_for = array(
            'country'  => $address['country'],
            'state'    => $address['state'],
            'postcode' => $address['postcode'],
            'city'     => $address['city'],
        );

        // Add the items
        foreach($order->lineItems as $lineItem){
            $qty = $lineItem->quantity;
            $sku_a = explode('.', $lineItem->sku);

            // Handle
            if(in_array('q4', $sku_a)===true){
                $qty = 4;
            }else if(in_array('q1', $sku_a)){
                $qty = 1;
            }

            $sku = $sku_a[array_key_last($sku_a)];
            $id = wc_get_product_id_by_sku($sku);
            $item_id = $woo_order->add_product(wc_get_product($id), $qty);
            $line_item  = $woo_order->get_item( $item_id, false);
            $line_item->calculate_taxes($calculate_taxes_for);
            $line_item->save();
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
