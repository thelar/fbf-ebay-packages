<?php

class Fbf_Ebay_Packages_Synchronise
{
    private $plugin_name;
    private $version;
    private $products = []; // This is somewhere to store WC products that we need to retrieve quickly
    private $buffer = 4;
    private $packs = [4]; // Could be [1, 2, 4] or [1, 4] depends on what we want to list!
    private $limit = null; // limit the amount of items we are creating during testing
    private $use_test_image = false; // switch to false to use actual thumbnails
    private $test_image = 'https://4x4tyres.co.uk/app/uploads/2019/12/Cooper_Discoverer_AT3_4S-1000x1000.png';
    private $synch_items = [];
    private $log_ids = [];

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function run($type, $items)
    {
        global $wpdb;
        $resp = [];
        $resp['start'] = microtime(true);
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';

        //List items that need listing (any listings that are of the correct type and are 'active'), this will also handle updating already listed items
        //TODO: refactor here to allow for different pack sizes meaning that one listing could potentially have several ebay listings!
        if(empty($items)){
            if(is_array($type)){
                $in = "(";
                foreach($type as $t){
                    $in.= "'" . $t . "',";
                }
                $in = rtrim($in, ',') . ')';
            }else{
                $in = "('" . $type . "')";
            }

            $q = sprintf("SELECT *
                FROM {$listing_table} l
                INNER JOIN {$skus_table} s
                    ON s.listing_id = l.id
                WHERE l.status = '%s'
                AND l.type IN %s", 'active', $in);
        }else{
            $q = sprintf("SELECT *
                FROM {$listing_table} l
                INNER JOIN {$skus_table} s
                ON s.listing_id = l.id
                WHERE l.status = '%s'
                AND s.sku IN (%s)", 'active', "'" . implode("','" ,$items) . "'");
        }
        $results = $wpdb->get_results( $q );


        if($results!==false){
            $updates = [];
            $count = 0;


            foreach($results as $result){
                if($this->limit && $count >= $this->limit){
                    //break out of the loops
                    break 1;
                }

                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-list-item.php';
                if($result->type==='package'){
                    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-list-package.php';
                    $ebay = new Fbf_Ebay_Packages_List_Package($this->plugin_name, $this->version);
                    $post_ids_table = $wpdb->prefix . 'fbf_ebay_packages_package_post_ids';
                    $q = $wpdb->prepare("SELECT post_ids
                        FROM {$post_ids_table}
                        WHERE listing_id = %s", $result->listing_id);
                    $r = $wpdb->get_col($q);
                    if($r){
                        $post_ids = unserialize($r[0]);
                        $chassis = $post_ids['chassis_id'];
                        $tyre = wc_get_product($post_ids['tyre_id']);
                        $wheel = wc_get_product($post_ids['wheel_id']);
                        $nut_bolt = wc_get_product($post_ids['nut_bolt_id']);
                        $qty = 4;
                        $item = $ebay->list_item($wheel, $tyre, $nut_bolt, $qty, $result);
                    }
                }else{
                    // These 2 calls could be expensive when we're dealing with 1000's of listings - may need to find a way of caching the product info!
                    $product_id = wc_get_product_id_by_sku($result->sku);
                    $product = wc_get_product($product_id);
                    $this->products[$result->sku] = $product;

                    //foreach($this->packs as $qty){
                    $ebay = new Fbf_Ebay_Packages_List_Item($this->plugin_name, $this->version);

                    //If it's a tyre just list as 1x
                    $cat = get_term_by('id', $product->get_category_ids()[0], 'product_cat')->name;

                    if($cat === 'Tyre'){
                        $lq = 1;
                    }else{
                        $lq = $this->packs[0];
                    }

                    $item = $ebay->list_item($product, $result, $lq); // TODO: refactor this block when we allow for multiple packs
                }
                $this->log_ids = array_merge($this->log_ids, $item->logs);
                $this->synch_items[] = $item;
                $count++;
            }
        }

        //De-list items that need removing from eBay (any items of correct $type, are 'inactive' AND have a value in the inventory_sku column)
        $q_d = $wpdb->prepare("SELECT *
            FROM {$listing_table} l
            INNER JOIN {$skus_table} s
                ON s.listing_id = l.id
            WHERE l.status = %s
            AND l.type = %s
            AND l.inventory_sku IS NOT NULL", 'inactive', $type);
        $results_d = $wpdb->get_results( $q_d );

        if($results_d!==false){
            foreach($results_d as $result_d){
                // TODO: allow for packs when ready
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-fbf-ebay-packages-list-item.php';
                $ebay = new Fbf_Ebay_Packages_List_Item($this->plugin_name, $this->version);
                $clean = $ebay->clean_item($result_d);
            }
        }
        $resp['end'] = microtime(true);
        $resp['execution_time'] = $resp['end'] - $resp['start'];
        $resp['log_ids'] = $this->log_ids;
        return $resp;

    }

    private function update_listing($sku, $name, $qty)
    {
        global $wpdb;
        $listing_table = $wpdb->prefix . 'fbf_ebay_packages_listings';
        $skus_table = $wpdb->prefix . 'fbf_ebay_packages_skus';
        $q = $wpdb->prepare("UPDATE {$listing_table} l
                    INNER JOIN {$skus_table} s ON s.listing_id = l.id
                    SET l.name = %s,
                        l.qty = %s
                    WHERE s.sku = %s
                    AND l.type = %s", $name, $qty, $sku, 'tyre');
        $result = $wpdb->query($q);

        return $result;
    }
}
