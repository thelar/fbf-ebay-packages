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
            $orders = $this->api('https://api.ebay.com/sell/fulfillment/v1/order?filter=orderfulfillmentstatus:%7BNOT_STARTED%7CIN_PROGRESS%7D', 'GET', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);
            $real = json_decode($orders['response']);
            echo 'ebay orders';
            die();
        }
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
