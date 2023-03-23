<?php

/**
 * Algolia Woo Indexer class for sending products
 * Called from main plugin file algolia-woo-indexer.php
 *
 * @package algolia-woo-indexer
 */

namespace Algowoo;

use Algolia\AlgoliaSearch\SearchClient;
use \Algowoo\Algolia_Check_Requirements as Algolia_Check_Requirements;

/**
 * Abort if this file is called directly
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Include plugin file if function is_plugin_active does not exist
 */
if (!function_exists('is_plugin_active')) {
    require_once(ABSPATH . '/wp-admin/includes/plugin.php');
}

/**
 * Define the plugin version and the database table name
 */
define('ALGOWOO_DB_OPTION', '_algolia_woo_indexer');
define('ALGOWOO_CURRENT_DB_VERSION', '0.3');

/**
 * Define application constants
 */
define('CHANGE_ME', 'change me');

/**
 * Database table names
 */
define('INDEX_NAME', '_index_name');
define('AUTOMATICALLY_SEND_NEW_PRODUCTS', '_automatically_send_new_products');
define('ALGOLIA_APP_ID', '_application_id');
define('ALGOLIA_API_KEY', '_admin_api_key');

if (!class_exists('Algolia_Send_Products')) {
    /**
     * Algolia WooIndexer main class
     */

    // TODO Rename class "Algolia_Send_Products" to match the regular expression ^[A-Z][a-zA-Z0-9]*$.
    class Algolia_Send_Products
    {
        const PLUGIN_NAME      = 'Algolia Woo Indexer';
        const PLUGIN_TRANSIENT = 'algowoo-plugin-notice';

        /**
         * The Algolia instance
         *
         * @var SearchClient
         */
        private static $algolia = null;

        private static $category_lists = [];

        private static $vendor_list = [];

        /**
         * Check if we can connect to Algolia, if not, handle the exception, display an error and then return
         */
        public static function can_connect_to_algolia()
        {
            try {
                self::$algolia->listApiKeys();
            } catch (\Algolia\AlgoliaSearch\Exceptions\UnreachableException $error) {
                add_action(
                    'admin_notices',
                    function () {
                        echo '<div class="error notice">
							  <p>' . esc_html__('An error has been encountered. Please check your application ID and API key. ', 'algolia-woo-indexer') . '</p>
							</div>';
                    }
                );
                return;
            }
        }

        /**
         * Get sale price or regular price based on product type
         *
         * @param  mixed $product Product to check
         * @return array ['sale_price' => $sale_price,'regular_price' => $regular_price] Array with regular price and sale price
         */
        public static function get_product_type_price($product)
        {
            $sale_price = 0;
            $regular_price = 0;
            if ($product->is_type('simple')) {
                $sale_price     =  $product->get_sale_price();
                $regular_price  =  $product->get_regular_price();
            } elseif ($product->is_type('variable')) {
                $sale_price     =  $product->get_variation_sale_price('min', true);
                $regular_price  =  $product->get_variation_regular_price('max', true);
            }

            return array(
                'sale_price' => $sale_price,
                'regular_price' => $regular_price,
                'sign_up_fee' => $product->get_sign_up_fee(),
                'trial_length' => \WC_Subscriptions_Product::get_trial_length($product),
                'trial_period' => \WC_Subscriptions_Product::get_trial_period($product),
                'period' => \WC_Subscriptions_Product::get_period( $source ),
            );
        }

        /**
         * Send WooCommerce products to Algolia
         *
         * @param Int $id Product to send to Algolia if we send only a single product
         * @return void
         */
        public static function send_products_to_algolia($id = '')
        {
            /**
             * Remove classes from plugin URL and autoload Algolia with Composer
             */

            $base_plugin_directory = str_replace('classes', '', dirname(__FILE__));
            require_once $base_plugin_directory . '/vendor/autoload.php';

            /**
             * Fetch the required variables from the Settings API
             */

            $algolia_application_id = get_option(ALGOWOO_DB_OPTION . ALGOLIA_APP_ID);
            $algolia_application_id = is_string($algolia_application_id) ? $algolia_application_id : CHANGE_ME;

            $algolia_api_key        = get_option(ALGOWOO_DB_OPTION . ALGOLIA_API_KEY);
            $algolia_api_key        = is_string($algolia_api_key) ? $algolia_api_key : CHANGE_ME;

            $algolia_index_name     = get_option(ALGOWOO_DB_OPTION . INDEX_NAME);
            $algolia_index_name        = is_string($algolia_index_name) ? $algolia_index_name : CHANGE_ME;

            /**
             * Display admin notice and return if not all values have been set
             */

            Algolia_Check_Requirements::check_algolia_input_values($algolia_application_id, $algolia_api_key, $algolia_index_name);

            /**
             * Initiate the Algolia client
             */
            self::$algolia = SearchClient::create($algolia_application_id, $algolia_api_key);

            /**
             * Check if we can connect, if not, handle the exception, display an error and then return
             */
            self::can_connect_to_algolia();

            /**
             * Initialize the search index and set the name to the option from the database
             */
            $index = self::$algolia->initIndex($algolia_index_name);

            /**
             * Setup arguments for sending all products to Algolia
             *
             * Limit => -1 means we send all products
             */
            $arguments = array(
                'status'   => 'publish',
                'limit'    => -1,
                'paginate' => false,
            );

            /**
             * Setup arguments for sending only a single product
             */
            if (isset($id) && '' !== $id) {
                $arguments = array(
                    'status'   => 'publish',
                    'include'  => array($id),
                    'paginate' => false,
                );
            }

            /**
             * Fetch all products from WooCommerce
             *
             * @see https://docs.woocommerce.com/wc-apidocs/function-wc_get_products.html
             */
            $products =
                /** @scrutinizer ignore-call */
                wc_get_products($arguments);

            if (empty($products)) {
                return;
            }
            $records = array();
            $record  = array();

            /** @var \WC_Product_Variable_Subscription $product */
            foreach ($products as $product) {
                /**
                 * Set sale price or regular price based on product type
                 */
                $product_type_price = self::get_product_type_price($product);
                $sale_price = $product_type_price['sale_price'];
                $regular_price = $product_type_price['regular_price'];

                /**
                 * Extract image from $product->get_image()
                 */
                preg_match('/<img(.*)src(.*)=(.*)"(.*)"/U', $product->get_image(), $result);
                $product_image = array_pop($result);
                /**
                 * Build the record array using the information from the WooCommerce product
                 */
                $record['objectID']                      = $product->get_id();
                $record['product_name']                  = $product->get_name();
                $record['product_image']                 = $product_image;
                $record['short_description']             = $product->get_short_description();
                $record['regular_price']                 = $regular_price;
                $record['sale_price']                    = $sale_price;
                $record['price']                         = $product->get_price();
                $record['price_html']                    = $product->get_price_html();
                $record['sign_up_fee']                   = $product_type_price['sign_up_fee'];
                $record['trial_length']                  = $product_type_price['trial_length'];
                $record['trial_period']                  = $product_type_price['trial_period'];
                $record['on_sale']                       = $product->is_on_sale();
                $record['categories']                    = self::get_category_names_by_ids($product->get_category_ids());
                $record['slug']                          = $product->get_slug();
                $record['variations']                    = self::get_available_variations($product);
                $record['vendor']                        = self::get_vendor_name($product->get_id());
                $record['images']                        = self::get_gallery_images_by_ids($product->get_gallery_image_ids());
                $records[] = $record;
            }
            wp_reset_postdata();

            /**
             * Send the information to Algolia and save the result
             * If result is NullResponse, print an error message
             */
            $result = $index->saveObjects($records);

            if ('Algolia\AlgoliaSearch\Response\NullResponse' === get_class($result)) {
                wp_die(esc_html__('No response from the server. Please check your settings and try again', 'algolia_woo_indexer_settings'));
            }

            /**
             * Display success message
             */
            echo '<div class="notice notice-success is-dismissible">
					 	<p>' . esc_html__('Product(s) sent to Algolia.', 'algolia-woo-indexer') . '</p>
				  		</div>';
        }

        public static function get_category_names_by_ids(array $category_ids): array
        {
            $categories = [];
            foreach ($category_ids as $category_id) {
                $categories[] = self::get_category_by_id($category_id);
            }

            return $categories;
        }

        public static function get_category_by_id( int $category_id): string
        {
            if (!array_key_exists($category_id, self::$category_lists)) {
                $category = get_term_by( 'id', $category_id, 'product_cat', 'ARRAY_A' );
                self::$category_lists[$category_id] = $category['name'];
            }

            return self::$category_lists[$category_id];
        }

        public static function get_vendor_name( int $product_id ): string
        {
            $vendor_id = get_post_field( 'post_author', $product_id );
            if (!array_key_exists($vendor_id, self::$vendor_list)) {
                $vendor_name = get_user_meta( $vendor_id, 'pv_shop_name', true );
                self::$vendor_list[$vendor_id] = $vendor_name !== '' ? $vendor_name : 'aborise';
            }

            return self::$vendor_list[$vendor_id];
        }

        public static function get_gallery_images_by_ids(array $gallery_ids): array
        {
            $imageUrls = [];
            foreach ($gallery_ids as $gallery_id) {
                $imageUrls[] = wp_get_attachment_url($gallery_id);
            }

            return $imageUrls;
        }

        public static function get_available_variations( $product ): array
        {
            if (!$product->is_type('variable')) {
                return [];
            }

            $variations = [];
            /** @var \WC_Product_Subscription_Variation $variation */
            foreach ( $product->get_available_variations( 'object' ) as $variation ){
                /**
                 * Extract image from $product->get_image()
                 */
                preg_match('/<img(.*)src(.*)=(.*)"(.*)"/U', $variation->get_image(), $result);
                $variation_image = array_pop($result);
                $variations[] = [
                    'attributes' => $variation->get_attributes(),
                    'regular_price' =>  $variation->get_regular_price(),
                    'sales_price' =>  $variation->get_sale_price(),
                    'name' => $variation->get_name(),
                    'attribute_summary' => $variation->get_attribute_summary(),
                    'image' => $variation_image,
                    'sign_up_fee' => $variation->get_sign_up_fee(),
                    'trial_length' => \WC_Subscriptions_Product::get_trial_length($variation),
                    'trial_period' => \WC_Subscriptions_Product::get_trial_period($variation),
                    'price' => $variation->get_price(),
                    'price_html' => $variation->get_price_html(),
                    'period' => \WC_Subscriptions_Product::get_period($variation),
                ];
            }

            return $variations;
        }
    }
}
