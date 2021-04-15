<?php


class Fbf_Ebay_Packages_Api_Inventory
{
    static $config_file;

    public function __construct()
    {
        self::$config_file = get_template_directory() . '/../config/ebay_oauthtokens.txt';
    }

    public function test($token)
    {
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer '.$token
        ];
        $url = 'https://api.ebay.com/sell/inventory/v1/inventory_item';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
        }else{
            $a = json_decode($response);
        }
        curl_close($ch);
    }
}
