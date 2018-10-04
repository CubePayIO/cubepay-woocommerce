<?php
/**
 * Plugin Name: Cubepay for WooCommerce
 * Plugin URI: http://cubepay.io/
 * Description: Cubepay PaymentGateway for WooCommerce
 * Author: Cubepay
 * Author URI: http://cubepay.io/
 * Text Domain: wc-cubepay-gateway
 * Version: 1.0.1
 */

defined('ABSPATH') || exit;
const TOKEN_STRING = "transaction token:";

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}
function cubepau_init() {
    $plugin_dir = basename(dirname(__FILE__));
    load_plugin_textdomain( 'cubepay-gateway', false, $plugin_dir . "/i18n" );
}

/**
 * 把gateway 加入到 woocommerce 結帳頁面
 * @param $gateways
 * @return array
 */
function wc_cubepay_gateways($gateways)
{
    $gateways[] = 'WC_Cubepay_Gateway';
    return $gateways;
}

add_action('plugins_loaded', 'cubepau_init');
add_filter('woocommerce_payment_gateways', 'wc_cubepay_gateways');

/**
 * 連結到WooCommerce付款設定頁
 * @param $links
 * @return array
 */
function cubepay_gateway_config_links($links)
{

    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=cubepay_gateway') . '">' . __('Configure') . '</a>'
    );

    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cubepay_gateway_config_links');

add_action('plugins_loaded', 'wc_cubepay_gateway_init', 11);
function wc_cubepay_gateway_init()
{

    class WC_Cubepay_Gateway extends WC_Payment_Gateway
    {
        public $api_url;
        public $source_coin;
        public $merchant_id;
        public $merchant_secret;

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {
            $this->id = 'cubepay_gateway';
            $this->icon = apply_filters('woocommerce_offline_icon', '');
            $this->has_fields = false;
            $this->method_title = 'Cubepay Payment Gateway';
            $this->method_description = __('Provide user to checkout by cryptocurrency', 'cubepay-gateway');

            $this->configure_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_url = $this->get_option('api_url', 'http://api.cubepay.io');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->merchant_secret = $this->get_option('merchant_secret');
            $this->source_coin = $this->get_option('source_coin');
            $this->order_button_text = __('Proceed to Cubepay', 'cubepay-gateway');

            //儲存管理界面的設定，呼叫parent的process_admin_options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'callback_handler'));
        }

        /**
         * 定義欄位
         */
        public function configure_fields()
        {
            $this->form_fields = apply_filters('wc_offline_form_fields', array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'cubepay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable Cubepay Payment', 'cubepay-gateway'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Payment Title', 'cubepay-gateway'),
                    'type' => 'text',
                    'description' => __('The title of the payment method, shown at checkout page', 'cubepay-gateway'),
                    'default' => 'Payment method (Cubepay)',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description'),
                    'type' => 'textarea',
                    'description' => __('Payment Description', 'wc-cubepay-gateway'),
                    'default' => __('This will redirect to cubepay website and paid by cryptocurrency', 'cubepay-gateway'),
                    'desc_tip' => true,
                ),
                'api_url' => array(
                    'title' => __('Cubepay API Url endpoint', 'cubepay-gateway'),
                    'type' => 'text',
                    'description' => __('Cubepay API Url that provided by cubepay.io', 'cubepay-gateway'),
                    'desc_tip' => true,
                ),
                'merchant_id' => array(
                    'title' => __('Client ID', 'cubepay-gateway'),
                    'type' => 'text',
                    'description' => __('Your client ID, provided by cubepay.io', 'cubepay-gateway'),
                    'desc_tip' => true,
                ),
                'merchant_secret' => array(
                    'title' => __('Cubepay Secret', 'cubepay-gateway'),
                    'type' => 'password',
                    'description' => __('Your client secret, provided by cubepay.io', 'cubepay-gateway'),
                    'desc_tip' => true,
                ),
                'source_coin' => array(
                    'title' => __('Currency', 'cubepay-gateway'),
                    'type' => 'select',
                    'description' => __('Accepted currency', 'cubepay-gateway'),
                    'class' => 'wc-enhanced-select',
                    'options' => $this->get_cubepay_fiat(),
                    'desc_tip' => true,
                )
            ));
        }

        /**
         * Process the payment and return the result
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __('Awaiting cubepay payment', 'cubepay-gateway'));
            // 庫存減少
            wc_reduce_stock_levels($order->get_id());
            $order->update_status('pending', _x('Pending payment', 'Order status', 'woocommerce'));

            // 清空購物車
            WC()->cart->empty_cart();
            //取回cubepay payment 網址
            $result = $this->get_payment_url($order_id);
            if (empty($result)) {
                return array(
                    'result' => 'failed',
                    'redirect' => $this->get_return_url($order)
                );
            } else {
                return array(
                    'result' => 'success',
                    'redirect' => $result
                );
            }
        }

        public function get_payment_url($order_id)
        {
            $order = wc_get_order($order_id);
            $result = $this->get_cubepay_payment($order);
            if (is_wp_error($result)) {
                $order->add_order_note(sprintf(__('Payment could not captured: %s', 'woocommerce'), $result->get_error_message()));
                return "";
            }
            return $result["data"];
        }

        /**
         * 對cubepay API進行新增 payment
         * @param $order
         * @return WP_Error
         */
        public function get_cubepay_payment($order)
        {
            $api_url = $this->get_option('api_url', 'http://api.cubepay.io') . '/payment';
            $source_coin = $this->get_option('source_coin', 443);
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domainName = $protocol . $_SERVER['HTTP_HOST'];
            $cubepayRequest = [
                'client_id' => $this->merchant_id,
                'source_coin_id' => (int)$source_coin,
                'source_amount' => $order->get_total(),
                'item_name' => $this->format_buying_items($order),
                'merchant_transaction_id' => $order->get_id(),
                'return_url' => '',
                'ipn_url' => $domainName . '?wc-api=' . strtolower(get_class($this)),
                'other' => md5(time() . mt_rand(0, 1000))
            ];
            ksort($cubepayRequest);
            $order->add_order_note(TOKEN_STRING . $cubepayRequest['other']);

            $data_string = urldecode(http_build_query($cubepayRequest)) . "&client_secret=" . $this->merchant_secret;
            $sign = strtoupper(hash("sha256", $data_string));
            $cubepayRequest['sign'] = $sign;
            $http = _wp_http_get_object();
            $raw_response = $http->post($api_url, array(
                'method' => 'POST',
                'body' => $cubepayRequest
            ));
            error_log(print_r($raw_response['body'],true));

            $result = json_decode($raw_response['body'], true);
            if ($result['status'] == 200 && !empty($result["data"])) {
                return $result;
            } else {
                return new WP_Error('cubepay-api', 'Empty Response');
            }
        }

        /**
         * 取回支援的法幣
         * @return array
         */
        public function get_cubepay_fiat()
        {
            $coins = array();
            $api_url = $this->get_option('api_url', 'http://api.cubepay.io') . '/currency/fiat';
            $merchant_id = $this->get_option('merchant_id');
            $merchant_secret = $this->get_option('merchant_secret');

            $cubepayRequest = [
                'client_id' => $merchant_id
            ];
            ksort($cubepayRequest);
            $data_string = urldecode(http_build_query($cubepayRequest)) . "&client_secret=" . $merchant_secret;
            $sign = strtoupper(hash("sha256", $data_string));
            $cubepayRequest['sign'] = $sign;
            $http = _wp_http_get_object();
            $raw_response = $http->post($api_url, array(
                'method' => 'POST',
                'body' => $cubepayRequest
            ));
            try {
                if (is_wp_error($raw_response)) {
                    error_log('error');
                    return array();
                }
                $result = json_decode($raw_response['body'], true);
                if ($result['status'] == 200 && !empty($result["data"])) {
                    foreach ($result['data'] as $data) {
                        $id = $data['id'];
                        $coins[$id] = $data['name'];
                    }
                }
            } catch (Exception $exceptione) {
                error_log($exceptione->getMessage());
            }
            return $coins;
        }

        /**
         * 格式化購買清單
         * @param $order
         * @return string
         */
        function format_buying_items($order)
        {
            $items = "";
            foreach ($order->get_items() as $item_id => $item) {
                $items .= $item->get_name() . " X " . $item->get_quantity();
            }
            return $items;
        }

        function get_token($order_id)
        {
            $args = array(
                'post_id' => $order_id,
                'author' => 'WC_Comments',
                'approve' => 'approve',
                'type' => '',
            );
            $token = "";
            remove_filter('comments_clauses', array('WC_Comments', 'exclude_order_comments'));
            $comments = get_comments($args);
            foreach ($comments as $comment) {
                if (strpos($comment->comment_content, TOKEN_STRING) === 0) {
                    $token = str_replace(TOKEN_STRING, "", $comment->comment_content);
                    break;
                }
            }
            return $token;
        }

        public function callback_handler()
        {
            $raw_post = file_get_contents('php://input');
            parse_str($raw_post, $decoded);
            $client_id = $decoded['client_id'];
            $merchant_transaction_id = $decoded['merchant_transaction_id'];
            $token = $decoded['other'];
            if (isset($merchant_transaction_id) && $this->merchant_id == $client_id) {
                $order = wc_get_order($merchant_transaction_id);
                $order_token = $this->get_token($merchant_transaction_id);
                if ($order_token == $token) {
                    $order->update_status('processing', sprintf(__('Get coin from cubepay gateway', 'cubepay-gateway'), get_woocommerce_currency(), $order->get_total()));
                }
            }
            echo 'success';
            exit();
        }
    }
}