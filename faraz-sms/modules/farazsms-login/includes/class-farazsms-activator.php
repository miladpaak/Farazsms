<?php
/**
 * Handle plugin activation tasks
 */

namespace FarazSMS;

if (!defined('ABSPATH')) {
    exit;
}

class Activator {

    public static function activate() {
        self::create_verification_table();
        self::create_default_options();
        self::create_login_page();
    }

    /**
     * Create the verification table for SMS/Email codes
     */
    private static function create_verification_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'farazsms_verification';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            verification VARCHAR(100) NOT NULL,
            code VARCHAR(10) NOT NULL,
            expire_date DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE (verification)
        ) $charset_collate;";

        // Create wallet table
        $wallet_table = $wpdb->prefix . 'farazsms_wallet';
        $wallet_sql = "CREATE TABLE IF NOT EXISTS $wallet_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            description TEXT,
            transaction_type ENUM('credit', 'debit') NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY order_id (order_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($wallet_sql);
    }

    /**
     * Create default plugin options
     */
    private static function create_default_options() {
        // Default unified login settings
        if (!get_option('farazsms_login_settings')) {
            add_option('farazsms_login_settings', [
                'sms' => [
                    'api_key' => '',
                    'sender' => '90008361',
                    'pattern_code' => '',
                    'code_length' => '6',
                    'test_sender' => '',
                ],
                'appearance' => [
                    'theme' => 'style-1',
                    'text_alignment' => 'center',
                    // پیش‌فرضِ لوگو = نمادکِ سایت (favicon) از تنظیمات عمومی وردپرس.
                    'logo' => function_exists('get_site_icon_url') ? get_site_icon_url() : '',
                    'background_image' => '',
                    'primary_color' => '',
                    'background_color' => '',
                    'background_color_box' => '',
                    'text_color' => '',
                    'border_color' => '',
                    'custom_css' => '',
                ],
                'general' => [
                    'terms_link' => '#',
                    'redirect_after_login' => '',
                    'checkout_redirect_to_login' => '0',
                    'woocommerce_login_redirect' => '0',
                    'replace_default_login_forms' => '1',
                    'apply_redirect_all_themes' => '1',
                ],
                'wallet' => [
                    'enable_registration_bonus' => '0',
                    'registration_bonus_amount' => '0',
                    'registration_bonus_description' => __('Welcome bonus for new registration', 'farazsms'),
                ],
                'slide' => [
                    'enable_slide' => '0',
                    'slide_show_only_guests' => '0',
                    'slide_position' => 'right',
                    'slide_image' => '',
                    'slide_title' => __('Registration Gift', 'farazsms'),
                    'slide_description' => __('Sign up now and get a special gift!', 'farazsms'),
                    'slide_countdown_minutes' => '1',
                    'slide_button_text' => __('Sign Up', 'farazsms'),
                    'slide_button_link' => '#',
                    'slide_button_color' => '#0BD08B',
                    'slide_background_color' => '#ffffff',
                    'slide_text_color' => '#333333',
                    'slide_title_color' => '#000000',
                ]
            ]);
        }
    }

    /**
     * Create login/register page automatically on activation
     */
    private static function create_login_page() {
        // Check if login page already exists
        $existing_pages = get_pages(array(
            'meta_key' => '_wp_page_template',
            'meta_value' => 'farazsms-login-page.php'
        ));

        // If page already exists, don't create a new one
        if (!empty($existing_pages)) {
            return;
        }

        // Create the login page
        $page_data = array(
            'post_title'    => __('Login / Registration', 'farazsms'),
            'post_content'  => '[farazsms_login_form]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => 1,
            'post_name'     => 'login-register',
        );

        $page_id = wp_insert_post($page_data);

        // Set the page template
        if ($page_id && !is_wp_error($page_id)) {
            update_post_meta($page_id, '_wp_page_template', 'farazsms-login-page.php');
        }
    }
}
