<?php


class Fbf_Ebay_Packages_Clean_Item
{
    public $plugin_name;
    public $version;
    public $item;

    public function __construct($item, $plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->item = $item;
    }

    public function clean()
    {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-api-auth.php';
        $auth = new Fbf_Ebay_Packages_Api_Auth();
        $token = $auth->get_valid_token();

        $clean = $this->api('https://api.ebay.com/sell/inventory/v1/inventory_item/' . $this->item, 'DELETE', ['Authorization: Bearer ' . $token['token'], 'Content-Type:application/json', 'Content-Language:en-GB']);
        return $clean;
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
            //$resp['response'] = $response;
            //$resp['response_headers'] = $headers;
            $resp['response_code'] = curl_getinfo($curl)['http_code'];
        }

        curl_close($curl);
        return $resp;
    }
}
