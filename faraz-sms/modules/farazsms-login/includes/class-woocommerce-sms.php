<?php
namespace FarazSMS;
use WC_Order;

class WooCommerce_SMS {
    public function __construct() {
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_order_status_changed',[$this, 'handle_order_status_changed'],10,4);
        }
    }

    public function handle_order_status_changed($order_id, $old_status, $new_status, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return;
        }

        $settings = get_option('farazsms_login_settings', []);
        $woo_sms  = $settings['woo_sms'] ?? [];

        $this->send_customer_sms($order, $new_status, $woo_sms);
        $this->send_admin_sms($order, $new_status, $woo_sms);
    }


    private function send_customer_sms(WC_Order $order, $status, $woo_sms) {
        $enable_key = 'customer_' . $status . '_enable';
        $pattern_key = 'customer_' . $status . '_pattern';
        $attributes_key = 'customer_' . $status . '_attributes';

        $enabled = isset($woo_sms[$enable_key]) && $woo_sms[$enable_key] === '1';
        if (!$enabled) {
            return;
        }

        $pattern_code = isset($woo_sms[$pattern_key]) ? trim($woo_sms[$pattern_key]) : '';
        if ($pattern_code === '') {
            return;
        }

        $attributes_raw = isset($woo_sms[$attributes_key]) ? $woo_sms[$attributes_key] : '';
        $attributes = $this->build_attributes_from_text($attributes_raw, $order);

        if (empty($attributes)) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (!$phone) {
            return;
        }

        $sender = new Send_SMS();
        $sender->send_pattern($phone, $pattern_code, $attributes);
    }

    private function send_admin_sms(WC_Order $order, $status, $woo_sms) {
        $admin_phone = isset($woo_sms['admin_phone']) ? trim($woo_sms['admin_phone']) : '';
        if ($admin_phone === '') {
            return;
        }

        $enable_key = 'admin_' . $status . '_enable';
        $pattern_key = 'admin_' . $status . '_pattern';
        $attributes_key = 'admin_' . $status . '_attributes';

        $enabled = isset($woo_sms[$enable_key]) && $woo_sms[$enable_key] === '1';
        if (!$enabled) {
            return;
        }

        $pattern_code = isset($woo_sms[$pattern_key]) ? trim($woo_sms[$pattern_key]) : '';
        if ($pattern_code === '') {
            return;
        }

        $attributes_raw = isset($woo_sms[$attributes_key]) ? $woo_sms[$attributes_key] : '';
        $attributes = $this->build_attributes_from_text($attributes_raw, $order);

        if (empty($attributes)) {
            return;
        }

        $sender = new Send_SMS();
        $sender->send_pattern($admin_phone, $pattern_code, $attributes);
    }

    private function build_attributes_from_text($text, WC_Order $order) {
        $attributes = [];
        $shortcode_map = $this->get_shortcode_values($order);

        $parts = preg_split('/[\s\r\n]+/', trim((string) $text));

        foreach ($parts as $part) {
            $key = trim($part);
            if ($key === '') {
                continue;
            }
            if (isset($shortcode_map[$key])) {
                $attributes[$key] = $shortcode_map[$key];
            }
        }

        return $attributes;
    }

    private function replace_shortcodes($text, WC_Order $order) {
        $map = $this->get_shortcode_values($order);
        return strtr($text, $map);
    }

    private function get_shortcode_values(WC_Order $order) {
        $items = $order->get_items();
        $all_items = [];
        $all_items_full = [];
        $all_items_qty = [];

        foreach ($items as $item) {
            $name = $item->get_name();
            $qty = $item->get_quantity();
            $all_items[] = $name;
            $all_items_full[] = $name;
            $all_items_qty[] = $name . ' x ' . $qty;
        }

        $order_id = $order->get_id();
        $total = $order->get_total();
        $price = function_exists('wc_price') ? wp_strip_all_tags(wc_price($total)) : (string) $total;

        $date_created = $order->get_date_created();
        $date = $date_created ? $date_created->date_i18n(get_option('date_format')) : '';

        $map = [
            'mobile' => $order->get_billing_phone(),
            'email' => $order->get_billing_email(),
            'status' => $order->get_status(),
            'all_items' => implode(', ', $all_items),
            'all_items_full' => implode(', ', $all_items_full),
            'all_items_qty' => implode(' | ', $all_items_qty),
            'count_items' => (string) $order->get_item_count(),
            'price' => $price,
            'order_id' => (string) $order_id,
            'transaction_id' => (string) $order->get_transaction_id(),
            'date' => $date,
            'description' => (string) $order->get_customer_note(),
            'payment_method' => (string) $order->get_payment_method_title(),
            'shipping_method' => (string) $order->get_shipping_method(),
            'b_first_name' => $order->get_billing_first_name(),
            'b_last_name' => $order->get_billing_last_name(),
            'b_company' => $order->get_billing_company(),
            'b_country' => $order->get_billing_country(),
            'b_state' => $order->get_billing_state(),
            'b_city' => $order->get_billing_city(),
            'b_address_1' => $order->get_billing_address_1(),
            'b_address_2' => $order->get_billing_address_2(),
            'b_postcode' => $order->get_billing_postcode(),
            's_first_name' => $order->get_shipping_first_name(),
            's_last_name' => $order->get_shipping_last_name(),
            's_company' => $order->get_shipping_company(),
            's_country' => $order->get_shipping_country(),
            's_state' => $order->get_shipping_state(),
            's_city' => $order->get_shipping_city(),
            's_address_1' => $order->get_shipping_address_1(),
            's_address_2' => $order->get_shipping_address_2(),
            's_postcode' => $order->get_shipping_postcode(),
            'post_tracking_code' => (string) get_post_meta($order_id, '_tracking_code', true),
            'post_tracking_url' => (string) get_post_meta($order_id, '_tracking_url', true),
        ];

        return $map;
    }
}

new WooCommerce_SMS();