<?php

namespace FarazSMS\Admin;

use FarazSMS\Admin\Settings;
class Admin_Settings extends Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_farazsms_settings_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_farazsms_send_test_sms', array($this, 'send_test_sms'));
        add_action('wp_ajax_farazsms_wallet_transaction', array($this, 'handle_wallet_transaction'));
    }

    public function add_farazsms_settings_page() {
        add_menu_page(
            __('FarazSMS Login Settings', 'farazsms'),
            __('FarazSMS Login', 'farazsms'),
            'manage_options',
            'farazsms_login_settings',
            array($this, 'render_settings_page'),
            'dashicons-admin-generic',
            30
        );
        if (class_exists('WooCommerce')) {
            add_submenu_page(
                'farazsms_login_settings',
                __('Wallet Management', 'farazsms'),
                __('Wallet', 'farazsms'),
                'manage_options',
                'farazsms-wallet',
                array($this, 'wallet_management_page')
            );
        }
        add_submenu_page(
            'farazsms_login_settings',
            __('Shortcodes', 'farazsms'),
            __('Shortcodes', 'farazsms'),
            'manage_options',
            'farazsms-shortcodes',
            array($this, 'shortcodes_page')
        );

        // Add wallet balance column to users list
        add_filter('manage_users_columns', array($this, 'add_wallet_balance_column'));
        add_filter('manage_users_custom_column', array($this, 'show_wallet_balance_column'), 10, 3);

        // Add wallet info to user profile pages
        add_action('show_user_profile', array($this, 'show_user_wallet_info'));
        add_action('edit_user_profile', array($this, 'show_user_wallet_info'));
    }

    public function render_settings_page() {
        $this->Save_Change();
        $settings = $this->All_Settings();
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'sms';
        
        echo "<div class='farazsms-admin-wrapper'>";
        
        // Sidebar
        echo "<aside class='farazsms-admin-sidebar'>";
            // Logo
            echo "<div class='farazsms-sidebar-logo'>";
                echo "<img src='" . FarazSMS_URL . "assets/images/farazsms.png' alt='FarazSMS Logo'>";
            echo "</div>";
            
            // Navigation Menu
            echo "<nav class='farazsms-sidebar-menu'>";
            
            $menu_icons = [
                'sms' => 'dashicons-email-alt',
                'appearance' => 'dashicons-admin-appearance',
                'general' => 'dashicons-admin-settings',
                'wallet' => 'dashicons-admin-users',
                'slide' => 'dashicons-visibility'
            ];
            
            foreach($settings as $id => $menu) {
                $menu_title = $menu['menu'];
                $tab_url = admin_url('admin.php?page=farazsms_login_settings&tab=' . $id);
                $active_class = ($active_tab == $id) ? ' active' : '';
                $icon_class = isset($menu_icons[$id]) ? $menu_icons[$id] : 'dashicons-admin-generic';
                echo "<a href='$tab_url' class='farazsms-menu-item$active_class'>";
                    echo "<span class='farazsms-menu-icon dashicons $icon_class'></span>";
                    echo "<span class='farazsms-menu-text'>$menu_title</span>";
                echo "</a>";
            }
            
            // Wallet menu item (if exists)
            if (class_exists('WooCommerce')) {
                $wallet_url = admin_url('admin.php?page=farazsms-wallet');
                $wallet_active = (isset($_GET['page']) && $_GET['page'] == 'farazsms-wallet') ? ' active' : '';
                echo "<a href='$wallet_url' class='farazsms-menu-item$wallet_active'>";
                    echo "<span class='farazsms-menu-icon dashicons dashicons-money-alt'></span>";
                    echo "<span class='farazsms-menu-text'>" . __('Wallet', 'farazsms') . "</span>";
                echo "</a>";
            }
            
            // Shortcodes menu item
            $shortcodes_url = admin_url('admin.php?page=farazsms-shortcodes');
            $shortcodes_active = (isset($_GET['page']) && $_GET['page'] == 'farazsms-shortcodes') ? ' active' : '';
            echo "<a href='$shortcodes_url' class='farazsms-menu-item$shortcodes_active'>";
                echo "<span class='farazsms-menu-icon dashicons dashicons-shortcode'></span>";
                echo "<span class='farazsms-menu-text'>" . __('Shortcodes', 'farazsms') . "</span>";
            echo "</a>";
            
            echo "</nav>";
        echo "</aside>";
        
        // Main Content
        echo "<main class='farazsms-admin-content'>";
            echo FlashMessage::get();
            $this->render_stats_cards();
            echo "<div class='farazsms-content-card'>";
                echo "<form action='' method='POST' id='setting-form'>";
                    echo "<input type='hidden' name='current_tab' value='$active_tab'>";
                    $this->General_Settings($active_tab);
                    echo "<div class='farazsms-form-actions'>";
                        echo "<button type='submit' class='farazsms-submit-button' name='SaveSetting'>";
                            echo "<span>" . __('Save Changes', 'farazsms') . "</span>";
                        echo "</button>";
                    echo "</div>";
                echo "</form>";
            echo "</div>";
        echo "</main>";
        
        echo "</div>";
    }

    public function Save_Change() {
        if (isset($_POST['SaveSetting'])) {
            $current_tab = isset($_POST['current_tab']) ? $_POST['current_tab'] : 'sms';
            if ($current_tab) {
                // Get all current settings in one option
                $all_current_settings = get_option('farazsms_login_settings', []);

                $all_settings = $this->All_Settings();

                foreach ($all_settings[$current_tab]['settings'] as $id => $setting) {
                    $type = $setting['type'];

                    if ($type === 'tabs') {
                        if (isset($setting['tabs']) && is_array($setting['tabs'])) {
                            foreach ($setting['tabs'] as $tab_key => $tab_data) {
                                if (isset($tab_data['settings']) && is_array($tab_data['settings'])) {
                                    foreach ($tab_data['settings'] as $tab_field_id => $tab_field_setting) {
                                        $tab_field_type = $tab_field_setting['type'];
                                        
                                        if ($tab_field_type === 'html' || $tab_field_type === 'heading') {
                                            continue;
                                        }
                                        
                                        $tab_field_value = isset($_POST[$tab_field_id]) ? $_POST[$tab_field_id] : '';
                                        
                                        if ($tab_field_type === 'image-radio' && empty($tab_field_value)) {
                                            $tab_field_value = $all_current_settings[$current_tab][$tab_field_id] ?? '';
                                        }

                                        switch ($tab_field_type) {
                                            case 'text':
                                            case 'number':
                                            case 'select':
                                            case 'color':
                                            case 'image-gallery':
                                            case 'image-radio':
                                                $tab_value = $tab_field_value;
                                                break;
                                            case 'textarea':
                                                $tab_value = $this->Code_Validator($tab_field_value);
                                                break;
                                            case 'repeater':
                                            case 'select2':
                                                $tab_value = $tab_field_value;
                                                break;
                                            case 'file':
                                            case 'url':
                                                $tab_value = sanitize_url($tab_field_value);
                                                break;
                                            case 'checkbox':
                                                $tab_value = isset($tab_field_value);
                                                break;
                                            case 'editor':
                                                $tab_value = $this->Code_Validator($tab_field_value);
                                                break;
                                            case 'switch':
                                                $tab_value = !empty($tab_field_value) ? '1' : '0';
                                                break;
                                            default:
                                                $tab_value = $tab_field_value;
                                                break;
                                        }

                                        $all_current_settings[$current_tab][$tab_field_id] = $tab_value;
                                    }
                                }
                            }
                        }
                        continue;
                    }

                    $field_value = isset($_POST[$id]) ? $_POST[$id] : '';

                    if ($type === 'image-radio' && empty($field_value)) {
                        $field_value = $all_current_settings[$current_tab][$id] ?? '';
                    }

                    switch ($type) {
                        case 'text':
                        case 'number':
                        case 'select':
                        case 'color':
                        case 'image-gallery':
                        case 'image-radio':
                            $value = $field_value;
                            break;
                        case 'textarea':
                            $value = $this->Code_Validator($field_value);
                            break;
                        case 'repeater':
                        case 'select2':
                            $value = $field_value;
                            break;
                        case 'file':
                        case 'url':
                            $value = sanitize_url($field_value);
                            break;
                        case 'checkbox':
                            $value = isset($field_value);
                            break;
                        case 'editor':
                            $value = $this->Code_Validator($field_value);
                            break;
                        case 'switch':
                            $value = !empty($field_value) ? '1' : '0';
                            break;
                        case 'html':
                        case 'heading':
                            continue 2;
                        default:
                            $value = $field_value;
                            break;
                    }

                    $all_current_settings[$current_tab][$id] = $value;
                }

                update_option('farazsms_login_settings', $all_current_settings);

                FlashMessage::add(__('Settings saved successfully', 'farazsms'));
            }
        }
    }

    public function send_test_sms() {
        if (
            ! isset($_POST['nonce']) ||
            ! wp_verify_nonce($_POST['nonce'], 'farazsms_admin_nonce')
        ) {
            wp_send_json_error([
                'message' => __('Security check failed', 'farazsms')
            ]);
        }
    
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        if (empty($phone)) {
            wp_send_json_error([
                'message' => __('Phone number is required', 'farazsms')
            ]);
        }
    
        $api_key = Farazsms_Get_Setting('sms', 'api_key');
        $sender  = Farazsms_Get_Setting('sms', 'sender');
    
        if (empty($api_key) || empty($sender)) {
            wp_send_json_error([
                'message' => __('SMS settings are not configured properly', 'farazsms')
            ]);
        }
    
        $sms_sender = new \FarazSMS\Send_SMS();
        $test_code  = 123456;
    
        try {
    
            $result = $sms_sender->send($phone, $test_code);
    
            if ($result === false) {
                wp_send_json_error([
                    'message' => __('Connection error while sending SMS.', 'farazsms')
                ]);
            }
    
            if (
                ! is_array($result) ||
                ! isset($result['status']) ||
                $result['status'] !== 'success'
            ) {
                $error_message = $this->get_readable_sms_api_error($result);

                wp_send_json_error([
                    'message' => $error_message,
                ]);
            }
    
            wp_send_json_success([
                'message' => __('Test SMS sent successfully!', 'farazsms'),
                'phone'   => $phone
            ]);
    
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => __('Unexpected error: ', 'farazsms') . $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert API error response to a readable string (avoids [object Object] in JS).
     * Handles sender/line number errors with a clear message.
     *
     * @param array|null $result Decoded API response
     * @return string
     */
    private function get_readable_sms_api_error($result) {
        $default = __('SMS API error.', 'farazsms');

        if ( ! is_array($result) ) {
            return $default;
        }

        $msg = $result['message'] ?? $result['error'] ?? $result['error_message'] ?? null;

        if ( $msg === null && ! empty($result['errors']) && is_array($result['errors']) ) {
            $msg = $result['errors'];
        }

        if ( is_array($msg) ) {
            $line_key = 'line_number';
            if ( isset($msg[ $line_key ]) && is_array($msg[ $line_key ]) ) {
                return __("Sender number (line) is invalid or does not exist. Please check your FarazSMS sender number in settings.", 'farazsms');
            }
            if ( isset($msg[ $line_key ]) && is_string($msg[ $line_key ]) ) {
                return $msg[ $line_key ];
            }
            $first = reset($msg);
            $msg = is_array($first) ? (string) reset($first) : (string) $first;
        }

        if ( is_object($msg) ) {
            $msg = (string) json_encode($msg);
        }

        $str = is_string($msg) ? trim($msg) : '';
        if ( $str !== '' ) {
            $lower = mb_strtolower($str);
            $line_related = ( strpos($lower, 'line') !== false || strpos($lower, 'line_number') !== false
                || strpos($lower, 'sender') !== false || strpos($lower, 'فرستنده') !== false
                || strpos($lower, 'خط') !== false );
            if ( $line_related ) {
                return __("Sender number (line) is invalid or does not exist. Please check your FarazSMS sender number in settings.", 'farazsms');
            }
            return $str;
        }

        return $default;
    }
    

    /**
     * Wallet management page
     */
    public function wallet_management_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        
        $settings = $this->All_Settings();

        echo "<div class='farazsms-admin-wrapper'>";
        
        // Sidebar
        echo "<aside class='farazsms-admin-sidebar'>";
            // Logo
            echo "<div class='farazsms-sidebar-logo'>";
                echo "<img src='" . FarazSMS_URL . "assets/images/farazsms.png' alt='FarazSMS Logo'>";
            echo "</div>";
            
            // Navigation Menu
            echo "<nav class='farazsms-sidebar-menu'>";
            
            $menu_icons = [
                'sms' => 'dashicons-email-alt',
                'appearance' => 'dashicons-admin-appearance',
                'general' => 'dashicons-admin-settings',
                'wallet' => 'dashicons-admin-users',
                'slide' => 'dashicons-visibility'
            ];
            
            foreach($settings as $id => $menu) {
                $menu_title = $menu['menu'];
                $tab_url = admin_url('admin.php?page=farazsms_login_settings&tab=' . $id);
                $active_class = '';
                echo "<a href='$tab_url' class='farazsms-menu-item$active_class'>";
                    echo "<span class='farazsms-menu-icon dashicons " . (isset($menu_icons[$id]) ? $menu_icons[$id] : 'dashicons-admin-generic') . "'></span>";
                    echo "<span class='farazsms-menu-text'>$menu_title</span>";
                echo "</a>";
            }
            
            // Wallet menu item
            $wallet_url = admin_url('admin.php?page=farazsms-wallet');
            $wallet_active = ' active';
            echo "<a href='$wallet_url' class='farazsms-menu-item$wallet_active'>";
                echo "<span class='farazsms-menu-icon dashicons dashicons-money-alt'></span>";
                echo "<span class='farazsms-menu-text'>" . __('Wallet', 'farazsms') . "</span>";
            echo "</a>";
            
            // Shortcodes menu item
            $shortcodes_url = admin_url('admin.php?page=farazsms-shortcodes');
            $shortcodes_active = (isset($_GET['page']) && $_GET['page'] == 'farazsms-shortcodes') ? ' active' : '';
            echo "<a href='$shortcodes_url' class='farazsms-menu-item$shortcodes_active'>";
                echo "<span class='farazsms-menu-icon dashicons dashicons-shortcode'></span>";
                echo "<span class='farazsms-menu-text'>" . __('Shortcodes', 'farazsms') . "</span>";
            echo "</a>";
            
            echo "</nav>";
        echo "</aside>";
        
        // Main Content
        echo "<main class='farazsms-admin-content'>";
        
        switch ($action) {
            case 'edit':
                $this->wallet_user_page($user_id);
                break;
            default:
                $this->wallet_users_list();
                break;
        }

        echo "</main>";
        echo "</div>";
    }

    /**
     * Shortcodes documentation page
     */
    public function shortcodes_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'farazsms'));
        }

        $settings = $this->All_Settings();

        echo "<div class='farazsms-admin-wrapper'>";
        
        // Sidebar
        echo "<aside class='farazsms-admin-sidebar'>";
            // Logo
            echo "<div class='farazsms-sidebar-logo'>";
                echo "<img src='" . FarazSMS_URL . "assets/images/farazsms.png' alt='FarazSMS Logo'>";
            echo "</div>";
            
            // Navigation Menu
            echo "<nav class='farazsms-sidebar-menu'>";
            
            $menu_icons = [
                'sms' => 'dashicons-email-alt',
                'appearance' => 'dashicons-admin-appearance',
                'general' => 'dashicons-admin-settings',
                'wallet' => 'dashicons-admin-users',
                'slide' => 'dashicons-visibility'
            ];
            
            foreach($settings as $id => $menu) {
                $menu_title = $menu['menu'];
                $tab_url = admin_url('admin.php?page=farazsms_login_settings&tab=' . $id);
                $active_class = '';
                $icon_class = isset($menu_icons[$id]) ? $menu_icons[$id] : 'dashicons-admin-generic';
                echo "<a href='$tab_url' class='farazsms-menu-item$active_class'>";
                    echo "<span class='farazsms-menu-icon dashicons $icon_class'></span>";
                    echo "<span class='farazsms-menu-text'>$menu_title</span>";
                echo "</a>";
            }
            
            // Wallet menu item
            if (class_exists('WooCommerce')) {
                $wallet_url = admin_url('admin.php?page=farazsms-wallet');
                $wallet_active = '';
                echo "<a href='$wallet_url' class='farazsms-menu-item$wallet_active'>";
                    echo "<span class='farazsms-menu-icon dashicons dashicons-money-alt'></span>";
                    echo "<span class='farazsms-menu-text'>" . __('Wallet', 'farazsms') . "</span>";
                echo "</a>";
            }
            
            // Shortcodes menu item
            $shortcodes_url = admin_url('admin.php?page=farazsms-shortcodes');
            $shortcodes_active = ' active';
            echo "<a href='$shortcodes_url' class='farazsms-menu-item$shortcodes_active'>";
                echo "<span class='farazsms-menu-icon dashicons dashicons-shortcode'></span>";
                echo "<span class='farazsms-menu-text'>" . __('Shortcodes', 'farazsms') . "</span>";
            echo "</a>";
            
            echo "</nav>";
        echo "</aside>";
        
        // Main Content
        echo "<main class='farazsms-admin-content'>";
            echo "<div class='farazsms-content-card'>";
                echo "<h1>" . __('Shortcodes', 'farazsms') . "</h1>";
                echo "<p>" . __('Use these shortcodes to display FarazSMS features on your site.', 'farazsms') . "</p>";
                
                // Login Button Shortcode
                echo "<div style='margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #0BD08B;'>";
                    echo "<h2 style='margin-top: 0;'>" . __('Login Button Shortcode', 'farazsms') . "</h2>";
                    echo "<p>" . __('Display a button that links to the login/registration page.', 'farazsms') . "</p>";
                    
                    echo "<h3>" . __('Basic Usage', 'farazsms') . "</h3>";
                    echo "<pre style='background: #fff; padding: 15px; border-radius: 5px; overflow-x: auto;'><code>[farazsms_login_button]</code></pre>";
                    
                    echo "<h3>" . __('Parameters', 'farazsms') . "</h3>";
                    echo "<ul>";
                        echo "<li><strong>bg_color</strong>: " . __('Button background color (default: #0BD08B)', 'farazsms') . "</li>";
                        echo "<li><strong>text_color</strong>: " . __('Button text color (default: #ffffff)', 'farazsms') . "</li>";
                        echo "<li><strong>text</strong>: " . sprintf(__('Button text for guests (default: "%s")', 'farazsms'), __('Login / Register', 'farazsms')) . "</li>";
                        echo "<li><strong>account_text</strong>: " . sprintf(__('Button text when logged in (default: "%s")', 'farazsms'), __('My Account', 'farazsms')) . "</li>";
                        echo "<li><strong>account_url</strong>: " . __('Custom account page URL when logged in (optional)', 'farazsms') . "</li>";
                    echo "</ul>";
                    
                    echo "<h3>" . __('Examples', 'farazsms') . "</h3>";
                    echo "<pre style='background: #fff; padding: 15px; border-radius: 5px; overflow-x: auto;'><code>[farazsms_login_button bg_color=\"#6d50fa\" text_color=\"#ffffff\"]
[farazsms_login_button text=\"" . esc_attr__('Login / Register', 'farazsms') . "\" bg_color=\"#0BD08B\"]
[farazsms_login_button account_text=\"" . esc_attr__('My Account', 'farazsms') . "\" account_url=\"https://example.com/custom-account\"]
[farazsms_login_button bg_color=\"#0BD08B\" text_color=\"#ffffff\" text=\"" . esc_attr__('Login / Register', 'farazsms') . "\" account_text=\"" . esc_attr__('My Account', 'farazsms') . "\" account_url=\"https://example.com/custom-account\"]</code></pre>";
                echo "</div>";
                
                // Wallet Balance Shortcode
                echo "<div style='margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #0BD08B;'>";
                    echo "<h2 style='margin-top: 0;'>" . __('Wallet Balance Shortcode', 'farazsms') . "</h2>";
                    echo "<p>" . __('Display the current user\'s wallet balance. Only visible to logged-in users.', 'farazsms') . "</p>";
                    
                    echo "<h3>" . __('Basic Usage', 'farazsms') . "</h3>";
                    echo "<pre style='background: #fff; padding: 15px; border-radius: 5px; overflow-x: auto;'><code>[farazsms_wallet_balance]</code></pre>";
                    
                    echo "<h3>" . __('Parameters', 'farazsms') . "</h3>";
                    echo "<ul>";
                        echo "<li><strong>format</strong>: " . __('Display format: "formatted" (default) or "raw" (number only)', 'farazsms') . "</li>";
                    echo "</ul>";
                    
                    echo "<h3>" . __('Examples', 'farazsms') . "</h3>";
                    echo "<pre style='background: #fff; padding: 15px; border-radius: 5px; overflow-x: auto;'><code>[farazsms_wallet_balance]
[farazsms_wallet_balance format=\"raw\"]</code></pre>";
                    
                    echo "<div style='margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 5px; border-left: 4px solid #ffc107;'>";
                        echo "<strong>" . __('Note:', 'farazsms') . "</strong> " . __('This shortcode will only display content for logged-in users. If the user is not logged in, nothing will be shown.', 'farazsms');
                    echo "</div>";
                echo "</div>";
                
            echo "</div>";
        echo "</main>";
        
        echo "</div>";
    }

    /**
     * List all users with wallet balances
     */
    private function wallet_users_list() {
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'display_name';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'ASC';
        $offset = ($current_page - 1) * $per_page;

        // Build user query
        $user_args = array(
            'number' => $per_page,
            'offset' => $offset,
            'orderby' => $orderby,
            'order' => $order
        );

        if (!empty($search_query)) {
            $user_args['search'] = '*' . $search_query . '*';
            $user_args['search_columns'] = array('user_login', 'user_nicename', 'user_email', 'display_name');
        }

        $users = get_users($user_args);

        // Get total users count for pagination
        if (!empty($search_query)) {
            $total_users_query = get_users(array(
                'search' => '*' . $search_query . '*',
                'search_columns' => array('user_login', 'user_nicename', 'user_email', 'display_name'),
                'number' => -1,
                'fields' => 'ID'
            ));
            $total_users = count($total_users_query);
        } else {
            $total_users_count = count_users();
            $total_users = $total_users_count['total_users'];
        }
        
        $total_pages = ceil($total_users / $per_page);

        // Build pagination URL
        $pagination_base = add_query_arg(array(
            'page' => 'farazsms-wallet',
            's' => $search_query,
            'orderby' => $orderby,
            'order' => $order
        ), admin_url('admin.php'));

        echo '<div class="farazsms-wallet-users-page">';
        
        // Page Header
        echo '<div class="farazsms-page-header">';
            echo '<h1 class="farazsms-page-title">' . __('Wallet Users List', 'farazsms') . '</h1>';
            echo '<p class="farazsms-page-description">' . __('You can review and manage user wallets in this section.', 'farazsms') . '</p>';
        echo '</div>';

        // Search and Filter Section
        echo '<div class="farazsms-search-filter-section">';
            echo '<form method="get" class="farazsms-search-form">';
                echo '<input type="hidden" name="page" value="farazsms-wallet">';
                echo '<div class="farazsms-search-box">';
                    echo '<input type="text" name="s" value="' . esc_attr($search_query) . '" placeholder="' . __('Search...', 'farazsms') . '" class="farazsms-search-input">';
                    echo '<button type="submit" class="farazsms-search-button">';
                        echo '<span class="dashicons dashicons-search"></span>';
                    echo '</button>';
                echo '</div>';
            echo '</form>';
        echo '</div>';

        // Table Section
        echo '<div class="farazsms-wallet-table-container">';
            echo '<table class="farazsms-wallet-table">';
                echo '<thead>';
                    echo '<tr>';
                        echo '<th class="column-number">#</th>';
                        $title_sort_url = add_query_arg(array('orderby' => 'display_name', 'order' => ($orderby == 'display_name' && $order == 'ASC') ? 'DESC' : 'ASC'), $pagination_base);
                        echo '<th class="column-title sortable">';
                            echo '<a href="' . esc_url($title_sort_url) . '">' . __('User', 'farazsms');
                            if ($orderby == 'display_name') {
                                echo ' <span class="sort-indicator ' . strtolower($order) . '">▲</span>';
                            }
                            echo '</a>';
                        echo '</th>';
                        echo '<th class="column-email">' . __('Email', 'farazsms') . '</th>';
                        $balance_sort_url = add_query_arg(array('orderby' => 'balance', 'order' => ($orderby == 'balance' && $order == 'ASC') ? 'DESC' : 'ASC'), $pagination_base);
                        echo '<th class="column-balance sortable">';
                            echo '<a href="' . esc_url($balance_sort_url) . '">' . __('Wallet Balance', 'farazsms');
                            if ($orderby == 'balance') {
                                echo ' <span class="sort-indicator ' . strtolower($order) . '">▲</span>';
                            }
                            echo '</a>';
                        echo '</th>';
                        echo '<th class="column-actions">' . __('Actions', 'farazsms') . '</th>';
                    echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                if (empty($users)) {
                    echo '<tr>';
                        echo '<td colspan="5" class="farazsms-no-data">';
                            echo '<div class="farazsms-empty-state">';
                                echo '<span class="dashicons dashicons-info"></span>';
                                echo '<p>' . __('No users found.', 'farazsms') . '</p>';
                            echo '</div>';
                        echo '</td>';
                    echo '</tr>';
                } else {
                    // Get all balances for sorting
                    $user_balances = array();
                    if ($orderby == 'balance') {
                        foreach ($users as $user) {
                            $user_balances[$user->ID] = \FarazSMS\Wallet::get_balance($user->ID);
                        }
                        usort($users, function($a, $b) use ($user_balances, $order) {
                            $balance_a = $user_balances[$a->ID];
                            $balance_b = $user_balances[$b->ID];
                            return $order == 'ASC' ? $balance_a <=> $balance_b : $balance_b <=> $balance_a;
                        });
                    }

                    $row_number = $offset + 1;
                    foreach ($users as $user) {
                        $balance = \FarazSMS\Wallet::get_balance($user->ID);
                        $edit_url = add_query_arg(array(
                            'page' => 'farazsms-wallet',
                            'action' => 'edit',
                            'user_id' => $user->ID
                        ), admin_url('admin.php'));

                        echo '<tr>';
                            echo '<td class="column-number">' . $row_number . '</td>';
                            echo '<td class="column-title">' . esc_html($user->display_name) . '</td>';
                            echo '<td class="column-email">' . esc_html($user->user_email) . '</td>';
                            echo '<td class="column-balance"><strong>' . wc_price($balance) . '</strong></td>';
                            echo '<td class="column-actions">';
                                echo '<a href="' . esc_url($edit_url) . '" class="farazsms-view-button">';
                                    echo '<span class="dashicons dashicons-visibility"></span>';
                                    echo __('View', 'farazsms');
                                echo '</a>';
                            echo '</td>';
                        echo '</tr>';
                        $row_number++;
                    }
                }

                echo '</tbody>';
            echo '</table>';
        echo '</div>';

        // Pagination Section
        if ($total_pages > 1) {
            echo '<div class="farazsms-pagination-section">';
                echo '<div class="farazsms-pagination">';
                    $prev_url = ($current_page > 1) ? add_query_arg('paged', $current_page - 1, $pagination_base) : '#';
                    $next_url = ($current_page < $total_pages) ? add_query_arg('paged', $current_page + 1, $pagination_base) : '#';
                    
                    echo '<a href="' . esc_url($prev_url) . '" class="farazsms-pagination-arrow' . ($current_page <= 1 ? ' disabled' : '') . '">';
                        echo '<span class="dashicons dashicons-arrow-right-alt2"></span>';
                    echo '</a>';
                    
                    for ($i = 1; $i <= $total_pages; $i++) {
                        if ($i == 1 || $i == $total_pages || ($i >= $current_page - 2 && $i <= $current_page + 2)) {
                            $page_url = add_query_arg('paged', $i, $pagination_base);
                            $active_class = ($i == $current_page) ? ' active' : '';
                            echo '<a href="' . esc_url($page_url) . '" class="farazsms-pagination-number' . $active_class . '">' . $i . '</a>';
                        } elseif ($i == $current_page - 3 || $i == $current_page + 3) {
                            echo '<span class="farazsms-pagination-dots">...</span>';
                        }
                    }
                    
                    echo '<a href="' . esc_url($next_url) . '" class="farazsms-pagination-arrow' . ($current_page >= $total_pages ? ' disabled' : '') . '">';
                        echo '<span class="dashicons dashicons-arrow-left-alt2"></span>';
                    echo '</a>';
                echo '</div>';
                
                $start_record = $offset + 1;
                $end_record = min($offset + $per_page, $total_users);
                echo '<div class="farazsms-pagination-info">';
                    echo sprintf(__('%d record(s) - Showing records from %d to %d', 'farazsms'), $total_users, $start_record, $end_record);
                echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Individual user wallet management
     */
    private function wallet_user_page($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            echo '<div class="farazsms-content-card">';
            echo '<div class="notice notice-error"><p>' . __('User not found.', 'farazsms') . '</p></div>';
            echo '</div>';
            return;
        }

        $balance = \FarazSMS\Wallet::get_balance($user_id);
        $transactions = \FarazSMS\Wallet::get_transactions($user_id, 20);

        $back_url = admin_url('admin.php?page=farazsms-wallet');

        echo '<div class="farazsms-wallet-user-page">';
        
        // Back Button and Header
        echo '<div class="farazsms-page-header">';
            echo '<div class="farazsms-back-button-wrapper">';
                echo '<a href="' . esc_url($back_url) . '" class="farazsms-back-button">';
                    echo '<span class="dashicons dashicons-arrow-right-alt2"></span>';
                    echo __('Back to Users List', 'farazsms');
                echo '</a>';
            echo '</div>';
            echo '<h1 class="farazsms-page-title">' . sprintf(__('Wallet for %s', 'farazsms'), esc_html($user->display_name)) . '</h1>';
        echo '</div>';

        // Balance Card
        echo '<div class="farazsms-balance-card">';
            echo '<div class="farazsms-balance-info">';
                echo '<div class="farazsms-balance-label">' . __('Current Balance', 'farazsms') . '</div>';
                echo '<div class="farazsms-balance-amount">' . wc_price($balance) . '</div>';
            echo '</div>';
        echo '</div>';

        // Transaction form
        echo '<div class="farazsms-content-card">';
        echo '<h2 class="farazsms-section-title">' . __('Add Transaction', 'farazsms') . '</h2>';
        echo '<form method="post" id="wallet-transaction-form">';
        wp_nonce_field('farazsms_wallet_transaction', 'wallet_nonce');
        echo '<input type="hidden" name="user_id" value="' . esc_attr($user_id) . '">';
        echo '<div class="farazsms-form-setting flex flex-wrap">';
        
        echo '<div class="farazsms-field w50">';
            echo '<label for="transaction_type">' . __('Transaction Type', 'farazsms') . '</label>';
            echo '<select name="transaction_type" id="transaction_type" class="farazsms-select" required>';
            echo '<option value="credit">' . __('Credit (Add to balance)', 'farazsms') . '</option>';
            echo '<option value="debit">' . __('Debit (Subtract from balance)', 'farazsms') . '</option>';
            echo '</select>';
        echo '</div>';
        
        echo '<div class="farazsms-field w50">';
            echo '<label for="amount">' . __('Amount', 'farazsms') . ' (' . get_woocommerce_currency_symbol() . ')' . '</label>';
            echo '<input type="number" name="amount" id="amount" step="1" min="1" required>';
        echo '</div>';
        
        echo '<div class="farazsms-field w100">';
            echo '<label for="description">' . __('Description', 'farazsms') . '</label>';
            echo '<textarea name="description" id="description" rows="3" required></textarea>';
        echo '</div>';
        
        echo '</div>';
        echo '<div class="farazsms-form-actions">';
        echo '<button type="submit" class="farazsms-submit-button">';
            echo '<span>' . __('Add Transaction', 'farazsms') . '</span>';
        echo '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        // Transaction history
        echo '<div class="farazsms-content-card">';
        echo '<h2 class="farazsms-section-title">' . __('Transaction History', 'farazsms') . '</h2>';
        
        if (empty($transactions)) {
            echo '<div class="farazsms-empty-state">';
                echo '<span class="dashicons dashicons-info"></span>';
                echo '<p>' . __('No transactions found.', 'farazsms') . '</p>';
            echo '</div>';
        } else {
            echo '<div class="farazsms-wallet-table-container">';
            echo '<table class="farazsms-wallet-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th class="column-date">' . __('Date', 'farazsms') . '</th>';
            echo '<th class="column-type">' . __('Type', 'farazsms') . '</th>';
            echo '<th class="column-amount">' . __('Amount', 'farazsms') . '</th>';
            echo '<th class="column-description">' . __('Description', 'farazsms') . '</th>';
            echo '<th class="column-order">' . __('Order', 'farazsms') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($transactions as $transaction) {
                $type_label = $transaction->transaction_type === 'credit' ? __('Credit', 'farazsms') : __('Debit', 'farazsms');
                $type_class = $transaction->transaction_type === 'credit' ? 'positive' : 'negative';
                $amount_display = $transaction->transaction_type === 'credit' ? '+' . wc_price($transaction->amount) : '-' . wc_price($transaction->amount);
                $order_link = $transaction->order_id ? '<a href="' . admin_url('post.php?post=' . $transaction->order_id . '&action=edit') . '">#' . $transaction->order_id . '</a>' : '-';

                echo '<tr>';
                echo '<td class="column-date">' . esc_html(date_i18n(get_option('date_format') . ' | ' . get_option('time_format'), strtotime($transaction->created_at))) . '</td>';
                echo '<td class="column-type"><span class="transaction-type ' . $type_class . '">' . $type_label . '</span></td>';
                echo '<td class="column-amount"><strong>' . $amount_display . '</strong></td>';
                echo '<td class="column-description">' . esc_html($transaction->description) . '</td>';
                echo '<td class="column-order">' . $order_link . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Handle wallet transaction AJAX
     */
    public function handle_wallet_transaction() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['wallet_nonce'], 'farazsms_wallet_transaction')) {
            wp_send_json_error(['message' => __('Security check failed.', 'farazsms')]);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'farazsms')]);
        }

        $user_id = intval($_POST['user_id']);
        $type = sanitize_text_field($_POST['transaction_type']);
        $amount = floatval($_POST['amount']);
        $description = sanitize_textarea_field($_POST['description']);

        if (!$user_id || $amount <= 0) {
            wp_send_json_error(['message' => __('Invalid data provided.', 'farazsms')]);
        }

        if ($type === 'credit') {
            $result = \FarazSMS\Wallet::add_credit($user_id, $amount, $description);
        } elseif ($type === 'debit') {
            $result = \FarazSMS\Wallet::deduct_balance($user_id, $amount, $description);
        } else {
            wp_send_json_error(['message' => __('Invalid transaction type.', 'farazsms')]);
        }

        if ($result) {
            wp_send_json_success([
                'message' => __('Transaction completed successfully.', 'farazsms'),
                'balance' => wc_price(\FarazSMS\Wallet::get_balance($user_id))
            ]);
        } else {
            if ($type === 'debit') {
                wp_send_json_error(['message' => __('Insufficient balance.', 'farazsms')]);
            } else {
                wp_send_json_error(['message' => __('Transaction failed.', 'farazsms')]);
            }
        }
    }

    public function enqueue_admin_scripts() {
        wp_enqueue_style('farazsms-style', FarazSMS_URL . 'admin/assets/css/style.css', [], '1.0.1');
        wp_enqueue_style('select2', FarazSMS_URL . 'admin/assets/css/select2.min.css', [], '4.0.13');

        wp_enqueue_media();
        wp_enqueue_script('farazsms-admin-script', FarazSMS_URL . 'admin/assets/js/admin.js', array('jquery'), '1.0.0', true);
        wp_localize_script('farazsms-admin-script', 'farazsms_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('farazsms_admin_nonce'),
            'i18n' => array(
                'enter_phone' => __('Please enter a phone number', 'farazsms'),
                'sending' => __('Sending...', 'farazsms'),
                'send_test_sms' => __('Send Test SMS', 'farazsms'),
                'success' => __('SMS sent successfully!', 'farazsms'),
                'error' => __('Error sending SMS', 'farazsms'),
                'select_placeholder' => __('Select...', 'farazsms'),
                'select_image' => __('Select Image', 'farazsms'),
                'select_button' => __('Select', 'farazsms')
            )
        ));
        wp_enqueue_script('select2', FarazSMS_URL . 'admin/assets/js/select2.min.js', array('jquery'), '4.0.13', true);
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script('wp-color-picker-alpha', FarazSMS_URL . 'admin/assets/js/wp-color-picker-alpha.min.js', array( 'wp-color-picker' ), '4.1.0', true);

        // Enqueue wallet admin scripts
        if (isset($_GET['page']) && $_GET['page'] === 'farazsms-wallet') {
            wp_enqueue_script('farazsms-wallet-admin', FarazSMS_URL . 'admin/assets/js/wallet-admin.js', array('jquery'), '1.0.0', true);
            wp_localize_script('farazsms-wallet-admin', 'farazsms_wallet_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('farazsms_wallet_transaction'),
                'strings' => array(
                    'processing' => __('Processing...', 'farazsms'),
                    'success' => __('Transaction completed successfully!', 'farazsms'),
                    'error' => __('An error occurred. Please try again.', 'farazsms'),
                )
            ));
        }
    }

    private function Code_Validator($code) {
        return str_replace( array('\'', '\"'), '"', $code);
    }

    public function start_session() {
		if(!session_id()) {
			session_start();
		}
	}


	/**
	 * Add wallet balance column to users list
	 */
	public function add_wallet_balance_column($columns) {
		$columns['wallet_balance'] = __('Wallet Balance', 'farazsms');
		return $columns;
	}

	/**
	 * Show wallet balance in users list
	 */
	public function show_wallet_balance_column($value, $column_name, $user_id) {
		if ($column_name === 'wallet_balance') {
			$balance = \FarazSMS\Wallet::get_balance($user_id);
			return '<strong>' . wc_price($balance) . '</strong>';
		}
		return $value;
	}

	/**
	 * Add wallet info to user profile page
	 */
	public function show_user_wallet_info($user) {
		$balance = \FarazSMS\Wallet::get_balance($user->ID);
		$wallet_url = add_query_arg(array(
			'page' => 'farazsms-wallet',
			'action' => 'edit',
			'user_id' => $user->ID
		), admin_url('admin.php?page=farazsms_login_settings'));

		echo '<h3>' . __('Wallet Information', 'farazsms') . '</h3>';
		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th><label>' . __('Current Balance', 'farazsms') . '</label></th>';
		echo '<td><strong>' . wc_price($balance) . '</strong></td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th><label>' . __('Manage Wallet', 'farazsms') . '</label></th>';
		echo '<td><a href="' . esc_url($wallet_url) . '" class="button button-primary">' . __('View/Manage Transactions', 'farazsms') . '</a></td>';
		echo '</tr>';
		echo '</table>';
	}

    public function get_balance_sms() {
        $api_key = Farazsms_Get_Setting('sms', 'api_key');
        if (empty($api_key)) {
            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/account/balance',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Api-Key: ' . $api_key,
                'Content-Type: application/json'
            ],
        ]);
    
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code == 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['status']) && $data['status'] == 'success') {
                return $data['data'];
            }
        }
        
        return false;
    }

    public function get_balance_sms_profile() {
        $api_key = Farazsms_Get_Setting('sms', 'api_key');
        if (empty($api_key)) {
            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/account/profile',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Api-Key: ' . $api_key,
                'Content-Type: application/json'
            ],
        ]);
    
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code == 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['status']) && $data['status'] == 'success') {
                return $data['data'];
            }
        }
        
        return false;
    }

    public function render_stats_cards() {
        $balance_data = $this->get_balance_sms();
        $profile_data = $this->get_balance_sms_profile();
        
        $is_connected = ($balance_data !== false && $profile_data !== false);
        $balance_amount = $is_connected && isset($balance_data['balance_amount']) ? number_format($balance_data['balance_amount']) : '0';
        $balance_count = $is_connected && isset($balance_data['balance_count']) ? number_format(floatval($balance_data['balance_count']), 2) : '0';
        $display_name = $is_connected && isset($profile_data['displayName']) ? $profile_data['displayName'] : '';
        $mobile = $is_connected && isset($profile_data['mobile']) ? $profile_data['mobile'] : '';
        
        echo "<div class='farazsms-stats-cards'>";
        
        // Connection Status Card
        echo "<div class='farazsms-stat-card'>";
            echo "<div class='farazsms-stat-icon'>";
                echo "<span class='dashicons dashicons-admin-links'></span>";
            echo "</div>";
            echo "<div class='farazsms-stat-content'>";
                echo "<div class='farazsms-stat-label'>" . __('Connection Status', 'farazsms') . "</div>";
                echo "<div class='farazsms-stat-value " . ($is_connected ? 'connected' : 'disconnected') . "'>";
                    echo $is_connected ? __('Connected', 'farazsms') : __('Not Connected', 'farazsms');
                echo "</div>";
            echo "</div>";
        echo "</div>";
        
        // Balance Amount Card
        echo "<div class='farazsms-stat-card'>";
            echo "<div class='farazsms-stat-icon'>";
                echo "<span class='dashicons dashicons-money-alt'></span>";
            echo "</div>";
            echo "<div class='farazsms-stat-content'>";
                echo "<div class='farazsms-stat-label'>" . __('Account Balance', 'farazsms') . "</div>";
                echo "<div class='farazsms-stat-value'>" . $balance_amount . " " . __('Toman', 'farazsms') . "</div>";
            echo "</div>";
        echo "</div>";
        
        // SMS Count Card
        echo "<div class='farazsms-stat-card'>";
            echo "<div class='farazsms-stat-icon'>";
                echo "<span class='dashicons dashicons-email-alt'></span>";
            echo "</div>";
            echo "<div class='farazsms-stat-content'>";
                echo "<div class='farazsms-stat-label'>" . __('Available SMS', 'farazsms') . "</div>";
                echo "<div class='farazsms-stat-value'>" . $balance_count . " " . __('SMS', 'farazsms') . "</div>";
            echo "</div>";
        echo "</div>";
        
        // Account Info Card
        echo "<div class='farazsms-stat-card'>";
            echo "<div class='farazsms-stat-icon'>";
                echo "<span class='dashicons dashicons-admin-users'></span>";
            echo "</div>";
            echo "<div class='farazsms-stat-content'>";
                echo "<div class='farazsms-stat-label'>" . __('Account', 'farazsms') . "</div>";
                echo "<div class='farazsms-stat-value'>" . ($display_name ?: __('Not Available', 'farazsms')) . "</div>";
                if ($mobile) {
                    echo "<div class='farazsms-stat-subvalue'>" . $mobile . "</div>";
                }
            echo "</div>";
        echo "</div>";
        
        echo "</div>";
    }
}

new Admin_Settings;