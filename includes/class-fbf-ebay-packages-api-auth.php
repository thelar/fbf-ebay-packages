<?php


class Fbf_Ebay_Packages_Api_Auth
{
    const AUTH_URI = 'https://auth.ebay.com/oauth2/consents';
    const CLIENT_ID = 'TopGearC-2def-4f79-8d54-3b6c42dab8f3';
    const CLIENT_SECRET = '99a7b2b8-edb5-4095-9715-bf254df7f3ce';
    const RUNAME = 'Top_Gear_Consum-TopGearC-2def-4-pfynm';
    const SCOPES = 'https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.marketing.readonly+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.marketing+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.inventory.readonly+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.inventory+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.account.readonly+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.account+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.fulfillment.readonly+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.fulfillment+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.analytics.readonly+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.finances+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fsell.payment.dispute+https%3A%2F%2Fapi.ebay.com%2Foauth%2Fapi_scope%2Fcommerce.identity.readonly';
    const SCOPES_MINT = 'https://api.ebay.com/oauth/api_scope/sell.inventory';

    static $config_file;



    public function __construct()
    {
        self::$config_file = get_template_directory() . '/../config/ebay_oauthtokens.txt';
    }

    public function get_valid_token()
    {
        //First check for config file
        if(!file_exists(self::$config_file)){
            file_put_contents(self::$config_file, '');
        }

        $resp = [];

        $fh = file_get_contents(self::$config_file, 'r');

        if($this->has_valid_code($fh)===false){
            // Check to see if the user consent code exists - if not initiate User Consent
            if(isset($_REQUEST['code'])){
                $code = filter_var($_REQUEST['code'], FILTER_SANITIZE_STRING);
            }


            if(!empty($code)){

                // Code exists so exchange code for User access token and safe to config file
                // Generated by curl-to-PHP: http://incarnate.github.io/curl-to-php/
                $ch = curl_init();
                $auth = base64_encode(self::CLIENT_ID.':'.self::CLIENT_SECRET);

                curl_setopt($ch, CURLOPT_URL, 'https://api.ebay.com/identity/v1/oauth2/token');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=authorization_code&code=' . $code . '&redirect_uri=' . self::RUNAME);

                $headers = array();
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $headers[] = 'Authorization: Basic ' . $auth;

                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $result = curl_exec($ch);
                if (curl_errno($ch)) {
                    $resp['status'] = 'error';
                    $resp['errors'] = curl_error($ch);
                }else{
                    $r = json_decode($result);
                    if(property_exists($r, 'error')){
                        $resp['status'] = 'error';
                        $resp['errors'] = $r->error_description;
                    }else{
                        // Save the Token and the Refresh Token to the config file here
                        $tokens = [
                            'access_token' => $r->access_token,
                            'access_token_expires' => time() + $r->expires_in,
                            'refresh_token' => $r->refresh_token,
                            'refresh_token_expires' => time() + $r->refresh_token_expires_in
                        ];
                        file_put_contents(self::$config_file, serialize($tokens));
                        $resp['status'] = 'success';
                        $resp['token'] = $tokens['access_token'];
                    }
                }
                curl_close($ch);


            }else{
                // Initiate user consent to obtain code
                $url = self::AUTH_URI . '?client_id=' . self::CLIENT_ID . '&response_type=code&redirect_uri=' . self::RUNAME . '&scope=' . self::SCOPES . '&state&hd&consentGiven=false&prompt=login';
                header('Location:' . $url);
            }
        }else{
            // Get the token here
            $resp['status'] = 'success';
            $resp['token'] = $this->get_token();
        }
        return $resp;
    }

    private function has_valid_code($file_contents)
    {
        // TODO: inspect file handle and see if access_token is OK - for now just return false
        $a = unserialize($file_contents);
        if($a===false){
            return false;
        }else{
            $now = time();
            if($a['access_token_expires'] > $now){
                // Access token is valid
                return true;
            }else{
                // Access token is expired so check refresh token
                if($a['refresh_token_expires'] > $now){
                    // Refresh token is valid so lets use it to get a new access token here
                    $token = $this->mint_token($a['refresh_token']);
                    if($token!==false){
                        $a['access_token'] = $token['access_token'];
                        $a['access_token_expires'] = $token['access_token_expires'];
                        file_put_contents(self::$config_file, serialize($a));
                        return true;
                    }else{
                        return false;
                    }
                }else{
                    // Refresh token is expired
                    return false;
                }
            }
        }
    }

    private function mint_token($refresh_token)
    {
        //refresh token
        // Generated by curl-to-PHP: http://incarnate.github.io/curl-to-php/
        $ch = curl_init();

        $auth = base64_encode(self::CLIENT_ID.':'.self::CLIENT_SECRET);

        curl_setopt($ch, CURLOPT_URL, 'https://api.ebay.com/identity/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=refresh_token&refresh_token=" . $refresh_token);
        //curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=refresh_token&refresh_token=" . $refresh_token . "&scope=" . self::SCOPES_MINT);

        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Authorization: Basic ' . $auth;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            return false;
        }else{
            $r = json_decode($result);
            if(property_exists($r, 'error')) {
                return false;
            }else{
                // Here if successfully minted new token
                return [
                    'access_token' => $r->access_token,
                    'access_token_expires' => time() + $r->expires_in
                ];
            }
        }
        curl_close($ch);
    }

    private function get_token()
    {
        $fh = file_get_contents(self::$config_file, 'r');
        $a = unserialize($fh);
        return $a['access_token'];
    }
}
