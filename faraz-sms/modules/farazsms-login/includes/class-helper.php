<?php

namespace FarazSMS;


class Helper {

    public function __construct() {
        // اگر افزونه‌ی Digits فعال باشد، فیلدِ موبایلِ ما به فرم‌های ثبت‌نام/پروفایل/حساب
        // اضافه نمی‌شود تا با فیلدِ موبایلِ Digits تداخل و تکرار ایجاد نشود (Digits خودش
        // موبایل را مدیریت می‌کند). هوک‌های ذخیره isset-guard دارند، پس بی‌ضرر باقی می‌مانند.
        $digits_active = ( function_exists( 'wto_is_digits_active' ) && wto_is_digits_active() );

        if ( ! $digits_active ) {
            add_action( 'register_form', [$this, 'add_field_to_registration_form']);
            add_action( 'edit_user_profile', [$this, 'add_field_to_registration_form']);
            add_action( 'show_user_profile', [$this, 'add_field_to_registration_form']);
        }
        add_action( 'user_register', [$this, 'save_user_meta_field']);
        // در حالت bundled، هدیه‌ی عضویت توسط کیف‌پولِ بومیِ افزونه‌ی فراز انجام می‌شود (نه اینجا).
        if ( ! defined( 'WTO_LOGIN_BUNDLED' ) ) {
            add_action( 'user_register', [$this, 'give_registration_bonus'], 10, 1);
        }
        add_action( 'personal_options_update', [$this, 'save_user_meta_field']);
        add_action( 'edit_user_profile_update', [$this, 'save_user_meta_field']);
        if (class_exists('WooCommerce')) {
        if ( ! $digits_active ) {
            add_action( 'woocommerce_edit_account_form', [$this, 'add_field_to_edit_account_form'] );
            add_action( 'woocommerce_save_account_details', [$this, 'save_field_account_details'], 12, 1 );
        }

            // Checkout redirect to login
            if (!empty(Farazsms_Get_Setting('general', 'checkout_redirect_to_login'))) {
                add_action('template_redirect', [$this, 'farazsms_checkout_redirect']);
            }

            // WooCommerce login redirect — صریح یا خودکار برای وودمارت
            if ($this->should_redirect_wc_account_to_custom_login()) {
                add_action('template_redirect', [$this, 'farazsms_woocommerce_login_redirect']);
            }

            // Wallet integration - only in frontend.
            // در حالت bundled غیرفعال است؛ کیف‌پولِ بومیِ افزونه‌ی فراز جایگزین آن است.
            if (!is_admin() && ! defined( 'WTO_LOGIN_BUNDLED' )) {
                add_action('woocommerce_checkout_before_order_review', [$this, 'display_wallet_balance']);
                add_action('woocommerce_checkout_update_order_review', [$this, 'process_wallet_payment']);
                add_action('woocommerce_checkout_process', [$this, 'process_wallet_payment']);
                add_action('woocommerce_checkout_create_order', [$this, 'apply_wallet_discount'], 10, 2);
                add_action('woocommerce_checkout_order_processed', [$this, 'deduct_wallet_on_order_processed'], 10, 1);
                add_action('woocommerce_order_status_completed', [$this, 'deduct_wallet_on_order_complete']);
                add_action('woocommerce_order_status_processing', [$this, 'deduct_wallet_on_order_complete']);
                add_action('woocommerce_order_status_on-hold', [$this, 'deduct_wallet_on_order_complete']);
                add_action('woocommerce_before_calculate_totals', [$this, 'add_wallet_fee_to_cart']);
                add_action('wp_enqueue_scripts', [$this, 'enqueue_wallet_scripts']);

                // Display wallet balance in My Account
                add_action('woocommerce_account_dashboard', [$this, 'display_wallet_balance_my_account']);
            }

            // Payment gateways filter — مرتبط با کیف‌پول؛ در حالت bundled غیرفعال است.
            if ( ! defined( 'WTO_LOGIN_BUNDLED' ) ) {
                add_filter('woocommerce_available_payment_gateways', [$this, 'modify_payment_gateways']);
                add_action( 'wp_ajax_farazsms_update_wallet_session', [$this, 'update_wallet_session']);
                add_action( 'wp_ajax_nopriv_farazsms_update_wallet_session', [$this, 'update_wallet_session']);
            }
    }
    }

    /**
     * Get the FarazSMS login page URL
     */
    public function get_farazsms_login_page_url() {
        // Find page with farazsms-login-page.php template
        $pages = get_pages(array(
            'meta_key' => '_wp_page_template',
            'meta_value' => 'farazsms-login-page.php'
        ));

        if (!empty($pages)) {
            return get_permalink($pages[0]->ID);
        }

        // Fallback to default login URL
        return wp_login_url();
    }

    /**
     * آیا قالبِ «وودمارت» فعال است؟ (قالب یا قالبِ والد)
     */
    public function farazsms_is_woodmart_active() {
        $theme = wp_get_theme();
        if ( $theme ) {
            $tmpl = strtolower( (string) $theme->get_template() );
            $name = strtolower( (string) $theme->get( 'Name' ) );
            if ( strpos( $tmpl, 'woodmart' ) !== false || strpos( $name, 'woodmart' ) !== false ) {
                return true;
            }
        }
        return function_exists( 'woodmart_setup' ) || function_exists( 'woodmart_get_opt' ) || defined( 'WOODMART_THEME_DIR' );
    }

    /**
     * آیا صفحه‌ی حساب کاربریِ ووکامرس باید به فرمِ ورودِ سفارشی هدایت شود؟
     * یا با تنظیمِ صریح، یا خودکار وقتی وودمارت فعال و «جایگزینی فرم‌ها» روشن است
     * (پنلِ کشوییِ وودمارت فرم را in-place نمی‌پذیرد و این مطمئن‌ترین راه است).
     */
    public function should_redirect_wc_account_to_custom_login() {
        if ( ! empty( Farazsms_Get_Setting( 'general', 'woocommerce_login_redirect' ) ) ) {
            return true;
        }
        // گزینه‌های خودکار فقط وقتی «جایگزینی فرم‌ها» روشن است معنا دارند.
        if ( Farazsms_Get_Setting( 'general', 'replace_default_login_forms' ) !== '1' ) {
            return false;
        }
        // اعمال برای همه‌ی قالب‌ها — پیش‌فرض روشن (مقدارِ خالی = روشن).
        $all_themes = Farazsms_Get_Setting( 'general', 'apply_redirect_all_themes' );
        if ( $all_themes === '' || $all_themes === null || $all_themes === '1' ) {
            return true;
        }
        // در غیرِ این صورت، فقط برای وودمارت خودکار باقی می‌ماند.
        if ( $this->farazsms_is_woodmart_active() ) {
            return true;
        }
        return false;
    }

    /**
     * Redirect checkout page to login if user is not logged in and setting is enabled
     */
    public function farazsms_checkout_redirect() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Check if on checkout page
        if (!is_checkout()) {
            return;
        }

        // Check if user is not logged in
        if (is_user_logged_in()) {
            return;
        }

        // Check if checkout redirect is enabled
        if (empty(Farazsms_Get_Setting('general', 'checkout_redirect_to_login'))) {
            return;
        }

        // Get login page URL
        $login_url = $this->get_farazsms_login_page_url();

        // Add back_url parameter for checkout page
        $checkout_url = wc_get_checkout_url();
        $login_url = add_query_arg('back_url', urlencode($checkout_url), $login_url);

        // Redirect to login page
        wp_redirect($login_url);
        exit;
    }

    /**
     * Redirect WooCommerce login page to custom login page if setting is enabled
     */
    public function farazsms_woocommerce_login_redirect() {
        // Only redirect if user is not logged in
        if (is_user_logged_in()) {
            return;
        }

        // Check if enabled (explicit setting OR auto for WoodMart)
        if (!$this->should_redirect_wc_account_to_custom_login()) {
            return;
        }

        // Check if on WooCommerce account page
        if (!is_account_page()) {
            return;
        }

        // Get login page URL
        $login_url = $this->get_farazsms_login_page_url();

        // Add back_url parameter for account page
        $account_url = wc_get_page_permalink('myaccount');
        $login_url = add_query_arg('back_url', urlencode($account_url), $login_url);

        // Redirect to login page
        wp_redirect($login_url);
        exit;
    }

    public function Identifier($identifier) {
        $type = 'none';
        $identifier = $this->ConvertToEnglish($identifier);
        if (preg_match("/^(?:98|\+98|0098|0)?9[0-9]{9}$/", $identifier)) {
            $identifier = $this->Phone_Formatter($identifier);
            $type = 'mobile';
        }

        return $type;
    }

    public function add_field_to_registration_form() {
        $current_user = wp_get_current_user();
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : $current_user->ID;
        $mobile_number_value = get_user_meta($user_id, 'mobile_number', true);
        ?>
        <h3><?php _e('Farazsms plugin settings', 'farazsms'); ?></h3>
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="mobile_number"><?php _e('Mobile number', 'farazsms'); ?></label></th>
                    <td><input type="tel" name="mobile_number" id="mobile_number" class="regular-text" value="<?php echo esc_attr($mobile_number_value); ?>" /></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    public function save_user_meta_field($user_id) {
        if (current_user_can('edit_user', $user_id) && isset($_POST['mobile_number'])) {
            update_user_meta($user_id, 'mobile_number', sanitize_text_field($_POST['mobile_number']));
            update_user_meta($user_id, 'billing_phone', sanitize_text_field($_POST['mobile_number']));
        }
    }
    
    public function add_field_to_edit_account_form() {
        $user = wp_get_current_user();        
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="card_number"><?php _e('Mobile Number', 'farazsms'); ?></label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="mobile_number" id="mobile_number" value="<?php echo esc_attr( $user->mobile_number ); ?>" />
        </p>
        <?php
    }
    
    public function save_field_account_details( $user_id ) {
        if( isset( $_POST['mobile_number'] ) ) {
            update_user_meta( $user_id, 'mobile_number', sanitize_text_field( $_POST['mobile_number'] ) );
            update_user_meta( $user_id, 'billing_phone', sanitize_text_field( $_POST['mobile_number'] ) );
        }
    }

    public function get_username_by_mobile_number($mobile_number) {
        $username = get_user_by('login', $mobile_number);
        $mobile_phone = get_users(array(
            'meta_key' => 'mobile_number',
            'meta_value' => $mobile_number,
            'meta_compare' => '=',
        ));
        $billing_phone = get_users(array(
            'meta_key' => 'billing_phone',
            'meta_value' => $mobile_number,
            'meta_compare' => '=',
        ));

        $digits = get_users(array(
            'meta_key' => 0 . 'digits_phone_no',
            'meta_value' => $mobile_number,
            'meta_compare' => '=',
        ));

        if(!empty($username)) {
            $response = $mobile_number;
        } elseif (!empty($billing_phone)) {
            $user = $billing_phone[0];
            $response = $user->user_login;
        } elseif(!empty($mobile_phone)) {
            $user = $mobile_phone[0];
            $response = $user->user_login;
        } elseif(!empty($digits)) {
            $user = $digits[0];
            $response = $user->user_login;
        } else {
            $response = false;
        }
        
        return $response;
    }

    /**
     * Give registration bonus to new users
     */
    public function give_registration_bonus($user_id) {
        try {
            if (!function_exists('WC') || !class_exists('WooCommerce')) {
                return;
            }

            $enable_bonus = Farazsms_Get_Setting('wallet', 'enable_registration_bonus') ?? '0';
            $bonus_amount = Farazsms_Get_Setting('wallet', 'registration_bonus_amount') ?? '0';
            $bonus_description = Farazsms_Get_Setting('wallet', 'registration_bonus_description') ?? __('Welcome bonus for new registration', 'farazsms');

            if ($enable_bonus == '1' && $bonus_amount > 0) {
                // Check if user already received bonus (prevent duplicate)
                $received_bonus = get_user_meta($user_id, '_registration_bonus_received', true);

                if (!$received_bonus) {
                    $result = \FarazSMS\Wallet::add_balance(
                        $user_id,
                        $bonus_amount,
                        $bonus_description,
                        'registration_bonus'
                    );

                    if ($result) {
                        // Mark as received
                        update_user_meta($user_id, '_registration_bonus_received', '1');
                    }
                }
            }
        } catch (\Exception $e) {}
    }

    /**
     * Display wallet balance in WooCommerce My Account dashboard
     */
    public function display_wallet_balance_my_account() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $balance = \FarazSMS\Wallet::get_balance($user_id);
        if ($balance > 0) {
            echo '<div class="farazsms-my-account-wallet" style="background: #f8f9fa; padding: 15px; margin: 20px 0; border: 1px solid #e9ecef; border-radius: 8px;">';
                echo '<h3 style="margin: 0 0 10px 0; color: #333;">' . __('Your Wallet', 'farazsms') . '</h3>';
                echo '<p style="margin: 0; color: #666;"><strong>' . __('Current Balance:', 'farazsms') . '</strong> <span style="color: #28a745; font-weight: bold;">' . wc_price($balance) . '</span></p>';
            echo '</div>';
        }
    }

    /**
     * Display wallet balance on checkout page
     */
    public function display_wallet_balance() {
        if (!is_user_logged_in() || !function_exists('WC') || !WC() || !WC()->cart || !WC()->session) {
            return;
        }

        $user_id = get_current_user_id();
        $balance = \FarazSMS\Wallet::get_balance($user_id);

        // Check if there's an old wallet session amount that's no longer valid
        $session_wallet_amount = WC()->session->get('wallet_payment_amount', 0);
        if ($session_wallet_amount > 0 && ($balance < $session_wallet_amount || $session_wallet_amount > WC()->cart->get_total('edit'))) {
            // Clear invalid session
            WC()->session->set('wallet_payment_amount', 0);
            $session_wallet_amount = 0;
        }

        // Only show wallet section if user has balance
        if ($balance > 0) {
            $cart_total = WC()->cart->get_total('edit');
            $session_wallet_amount = WC()->session->get('wallet_payment_amount', 0);

            echo '<style type="text/css">
                .farazsms-wallet-section{margin:20px 0;background:#ffffff;border:1px solid #e0e0e0;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.08);overflow:hidden;}
                .farazsms-wallet-header{background:#f4f4f4;padding:20px;display:flex;justify-content:space-between;align-items:center;}
                .farazsms-wallet-title{margin:0;font-size:18px;font-weight:700;color:#000;}
                .farazsms-wallet-balance{font-size:14px;color:#000;font-weight:500;}
                .farazsms-wallet-balance strong{font-weight:700;color:#000;}
                .farazsms-wallet-content{padding:20px;}
                .farazsms-wallet-description{margin:0 0 20px 0;color:#666;font-size:14px;line-height:1.6;}
                .farazsms-wallet-description strong{color:#333;font-weight:600;}
                .farazsms-wallet-switch{display:flex;align-items:center;gap:15px;padding:15px;background:#f8f9fa;border-radius:8px;transition:background-color 0.3s ease;}
                .farazsms-wallet-switch:hover{background:#f0f0f0;}
                .farazsms-switch-label{font-size:14px;color:#333;font-weight:500;flex:1;}
                .farazsms-switch{position:relative;display:inline-block;width:50px;height:24px;flex-shrink:0;margin:0 !important;}
                .farazsms-switch input{opacity:0;width:0;height:0;}
                .farazsms-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#ccc;transition:0.4s;border-radius:24px;}
                .farazsms-slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background-color:white;transition:0.4s;border-radius:50%;}
                .farazsms-switch input:checked + .farazsms-slider{background-color:#0BD08B;}
                .farazsms-switch input:focus + .farazsms-slider{box-shadow:0 0 1px #0BD08B;}
                .farazsms-switch input:checked + .farazsms-slider:before{transform:translateX(26px);}
                .farazsms-slider.round{border-radius:24px;}
                .farazsms-slider.round:before{border-radius:50%;}
            </style>';

            echo '<div class="farazsms-wallet-section">';
            echo '<div class="farazsms-wallet-header">';
            echo '<h3 class="farazsms-wallet-title">' . __('Your Wallet', 'farazsms') . '</h3>';
            echo '<div class="farazsms-wallet-balance">' . sprintf(__('Balance: %s', 'farazsms'), '<strong>' . wc_price($balance) . '</strong>') . '</div>';
            echo '</div>';

            if ($balance >= $cart_total) {
                $is_checked = ($session_wallet_amount == $cart_total) ? 'checked' : '';
                echo '<div class="farazsms-wallet-content">';
                echo '<p class="farazsms-wallet-description">' . __('You can pay the full amount using your wallet balance.', 'farazsms') . '</p>';
                echo '<div class="farazsms-wallet-switch">';
                echo '<label class="farazsms-switch">';
                echo '<input type="checkbox" name="use_wallet_full" value="1" ' . $is_checked . '>';
                echo '<span class="farazsms-slider round"></span>';
                echo '</label>';
                echo '<span class="farazsms-switch-label">' . __('Use wallet balance to pay full amount', 'farazsms') . '</span>';
                echo '</div>';
                echo '</div>';
            } else {
                $is_checked = ($session_wallet_amount == $balance) ? 'checked' : '';
                echo '<div class="farazsms-wallet-content">';
                echo '<p class="farazsms-wallet-description">' . sprintf(__('You can use %s from your wallet to reduce the payment amount.', 'farazsms'), '<strong>' . wc_price($balance) . '</strong>') . '</p>';
                echo '<div class="farazsms-wallet-switch">';
                echo '<label class="farazsms-switch">';
                echo '<input type="checkbox" name="use_wallet_partial" value="1" ' . $is_checked . '>';
                echo '<span class="farazsms-slider round"></span>';
                echo '</label>';
                echo '<span class="farazsms-switch-label">' . sprintf(__('Use %s from wallet balance', 'farazsms'), wc_price($balance)) . '</span>';
                echo '</div>';
                echo '</div>';
            }

            echo '</div>';
        }
    }

    /**
     * Process wallet payment during checkout
     */
    public function process_wallet_payment($posted_data = null) {
        if (!is_user_logged_in() || !function_exists('WC') || !WC() || !WC()->session || !WC()->cart) {
            return;
        }

        // Get posted data - woocommerce_checkout_update_order_review passes posted data as string
        if (is_string($posted_data)) {
            parse_str($posted_data, $post_data);
        } else {
            $post_data = $posted_data ? $posted_data : $_POST;
        }

        // Process if wallet fields are present in POST data
        $has_wallet_fields = isset($post_data['use_wallet_full']) || isset($post_data['use_wallet_partial']);

        if (!$has_wallet_fields) {
            return;
        }

        $user_id = get_current_user_id();
        $balance = \FarazSMS\Wallet::get_balance($user_id);
        $cart_total = WC()->cart->get_total('edit');

        $use_full = isset($post_data['use_wallet_full']) && $post_data['use_wallet_full'] == '1';
        $use_partial = isset($post_data['use_wallet_partial']) && $post_data['use_wallet_partial'] == '1';

        // Check if user has sufficient balance
        if (($use_full || $use_partial) && $balance <= 0) {
            // Clear any existing wallet session
            WC()->session->set('wallet_payment_amount', 0);
            return;
        }

        // Clear existing wallet fees
        $fees = WC()->cart->get_fees();
        foreach ($fees as $fee_key => $fee) {
            if (isset($fee->name) && $fee->name === __('Wallet Payment', 'farazsms')) {
                unset(WC()->cart->fees[$fee_key]);
            }
        }

        if ($use_full && $balance >= $cart_total) {
            WC()->session->set('wallet_payment_amount', $cart_total);
            // Add negative fee to reduce total
            WC()->cart->add_fee(__('Wallet Payment', 'farazsms'), -$cart_total);
        } elseif ($use_partial && $balance > 0) {
            $wallet_amount = min($balance, $cart_total);
            WC()->session->set('wallet_payment_amount', $wallet_amount);
            // Add negative fee to reduce total
            WC()->cart->add_fee(__('Wallet Payment', 'farazsms'), -$wallet_amount);
        } else {
            WC()->session->set('wallet_payment_amount', 0);
        }

        // Recalculate totals
        WC()->cart->calculate_totals();
    }

    /**
     * Apply wallet discount to order
     */
    public function apply_wallet_discount($order, $data) {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return $order;
        }

        $wallet_amount = WC()->session->get('wallet_payment_amount', 0);

        if ($wallet_amount > 0) {
            // Add order note
            $order->add_order_note(sprintf(__('Wallet payment: %s deducted from wallet balance.', 'farazsms'), wc_price($wallet_amount)));

            // Store wallet amount in order meta
            $order->update_meta_data('_wallet_payment_amount', $wallet_amount);

            // Add fee/line item for wallet payment (negative amount to reduce total)
            $order->add_item($this->create_wallet_fee_item($wallet_amount));
        }

        return $order;
    }

    /**
     * Create wallet fee item
     */
    private function create_wallet_fee_item($amount) {
        $fee = new \WC_Order_Item_Fee();
        $fee->set_name(__('Wallet Payment', 'farazsms'));
        $fee->set_amount(-$amount);
        $fee->set_tax_status('none');
        $fee->add_meta_data('_wallet_payment', 'yes', true);
        return $fee;
    }

    /**
     * Deduct wallet balance when order is completed/processing
     */
    public function deduct_wallet_on_order_complete($order_id) {
        $this->process_wallet_deduction($order_id);
    }

    /**
     * Deduct wallet balance right after checkout order is created.
     * This prevents reusing the same wallet credit in another order.
     */
    public function deduct_wallet_on_order_processed($order_id) {
        $this->process_wallet_deduction($order_id);
    }

    /**
     * Shared and idempotent wallet deduction logic.
     */
    private function process_wallet_deduction($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Prevent duplicate deduction if multiple hooks fire.
        if ($order->get_meta('_wallet_payment_processed', true) === 'yes') {
            return;
        }

        $wallet_amount = $order->get_meta('_wallet_payment_amount', true);
        $wallet_amount = floatval($wallet_amount);

        if ($wallet_amount > 0) {
            $user_id = $order->get_customer_id();
            if (empty($user_id)) {
                return;
            }

            // Deduct from wallet
            $deducted = \FarazSMS\Wallet::deduct_balance(
                $user_id,
                $wallet_amount,
                sprintf(__('Payment for order #%s', 'farazsms'), $order_id),
                $order_id
            );

            if ($deducted) {
                // Update order meta to mark as processed
                $order->update_meta_data('_wallet_payment_processed', 'yes');
                $order->save();

                // Clear checkout wallet session after successful deduction.
                if (function_exists('WC') && WC() && WC()->session) {
                    WC()->session->set('wallet_payment_amount', 0);
                }
            }
        }
    }

    /**
     * Modify available payment gateways based on wallet usage
     */
    public function modify_payment_gateways($gateways) {
        // Check if WooCommerce and session are available
        if (!function_exists('WC') || !WC() || !WC()->session || !WC()->cart) {
            return $gateways;
        }

        $wallet_amount = WC()->session->get('wallet_payment_amount', 0);
        $cart_total = WC()->cart->get_total('edit');

        if ($wallet_amount > 0 && $wallet_amount >= $cart_total) {}

        return $gateways;
    }

    /**
     * Enqueue wallet scripts
     */
    /**
     * Update wallet session via AJAX
     */
    public function update_wallet_session() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'farazsms_wallet_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'farazsms')]);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('User not logged in.', 'farazsms')]);
        }

        if (!function_exists('WC') || !WC()) {
            wp_send_json_error(['message' => __('WooCommerce not available.', 'farazsms')]);
        }

        // Initialize session if not exists
        if (!WC()->session) {
            WC()->initialize_session();
        }

        if (!WC()->cart) {
            wp_send_json_error(['message' => __('Cart not available.', 'farazsms')]);
        }

        $user_id = get_current_user_id();
        $balance = \FarazSMS\Wallet::get_balance($user_id);
        $cart_total = WC()->cart->get_total('edit');

        $use_full = isset($_POST['use_wallet_full']) && $_POST['use_wallet_full'] == '1';
        $use_partial = isset($_POST['use_wallet_partial']) && $_POST['use_wallet_partial'] == '1';

        // Validate balance before setting session
        if ($use_full) {
            if ($balance >= $cart_total) {
                WC()->session->set('wallet_payment_amount', $cart_total);
            } else {
                WC()->session->set('wallet_payment_amount', 0);
                wp_send_json_error(['message' => __('Insufficient wallet balance for full payment.', 'farazsms')]);
                return;
            }
        } elseif ($use_partial) {
            if ($balance > 0) {
                $wallet_amount = min($balance, $cart_total);
                WC()->session->set('wallet_payment_amount', $wallet_amount);
            } else {
                WC()->session->set('wallet_payment_amount', 0);
                wp_send_json_error(['message' => __('No wallet balance available.', 'farazsms')]);
                return;
            }
        } else {
            WC()->session->set('wallet_payment_amount', 0);
        }

        // Force save session
        WC()->session->save_data();

        // Return success
        wp_send_json_success();
    }

    /**
     * Add wallet fee to cart during calculation
     */
    public function add_wallet_fee_to_cart() {
        if (!is_user_logged_in() || !function_exists('WC') || !WC() || !WC()->session || !WC()->cart) {
            return;
        }

        $wallet_amount = WC()->session->get('wallet_payment_amount', 0);

        if ($wallet_amount > 0) {
            // Clear existing wallet fees first
            $fees = WC()->cart->get_fees();
            foreach ($fees as $fee_key => $fee) {
                if (isset($fee->name) && $fee->name === __('Wallet Payment', 'farazsms')) {
                    unset(WC()->cart->fees[$fee_key]);
                }
            }

            WC()->cart->add_fee(__('Wallet Payment', 'farazsms'), -$wallet_amount);
        }
    }

    public function enqueue_wallet_scripts() {
        // Enqueue on frontend when WooCommerce is active
        if (!is_admin() && function_exists('WC')) {
            wp_enqueue_script('farazsms-wallet', FarazSMS_ASSETS_URL . 'js/wallet.js', array('jquery'), '1.0.0', true);
            wp_localize_script('farazsms-wallet', 'farazsms_wallet', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('farazsms_wallet_nonce'),
            ));
        }
    }

    public function login_in_wordpress($username, $password, $back_url) {
        $back_url = trim((string) urldecode($back_url));
        $creds = array();
        $creds['user_login'] = $username;
        $creds['user_password'] = $password;
        $creds['remember'] = true;
        $user = wp_signon($creds, false);
        
        if (is_wp_error($user)) {
            return false;
        } else {
            wp_set_auth_cookie($user->ID, 0, 0);
            $redirect_after_login = Farazsms_Get_Setting('general', 'redirect_after_login');
            
            // Priority: back_url > redirect_after_login > home
            if (!empty($back_url)) {
                // Fix malformed absolute URLs like "http:/example.com/..."
                if (preg_match('#^https?:/[^/]#i', $back_url)) {
                    $back_url = preg_replace('#^(https?:)/#i', '$1//', $back_url, 1);
                }

                // Check if back_url is an absolute URL (supports non-ASCII paths).
                if (preg_match('#^https?://#i', $back_url)) {
                    $redirect_url = $back_url;
                } else {
                    $redirect_url = home_url($back_url);
                }
            } elseif (!empty($redirect_after_login)) {
                $redirect_url = $redirect_after_login;
            } else {
                $redirect_url = home_url('/');
            }
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {window.location.href = '<?php echo esc_js($redirect_url); ?>';});
            </script>
            <?php
            return true;
        }
    }

    public function login_in_wordpress_with_out_password($username, $back_url) {
        $back_url = trim((string) urldecode($back_url));
        $user = get_user_by('login', $username);
        if ($user) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, 0, 0);
            $redirect_after_login = Farazsms_Get_Setting('general', 'redirect_after_login');
            
            // Priority: back_url > redirect_after_login > home
            if (!empty($back_url)) {
                // Fix malformed absolute URLs like "http:/example.com/..."
                if (preg_match('#^https?:/[^/]#i', $back_url)) {
                    $back_url = preg_replace('#^(https?:)/#i', '$1//', $back_url, 1);
                }

                // Check if back_url is an absolute URL (supports non-ASCII paths).
                if (preg_match('#^https?://#i', $back_url)) {
                    $redirect_url = $back_url;
                } else {
                    $redirect_url = home_url($back_url);
                }
            } elseif (!empty($redirect_after_login)) {
                $redirect_url = $redirect_after_login;
            } else {
                $redirect_url = home_url('/');
            }
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {window.location.href = '<?php echo esc_js($redirect_url); ?>';});
            </script>
            <?php
            return true;
        } else {
            return false;
        }
    }

    public function Phone_Formatter($phone_number) {
        $phone_number = $this->ConvertToEnglish($phone_number);

        if (!preg_match(apply_filters('phone_number_pattern', "/^(?:98|\+98|0098|0)?9[0-9]{9}$/"), $phone_number)) {
            return false;
        }

        if (preg_match('/^09[0-9]{9}$/', $phone_number))
            return $phone_number;
        if (preg_match('/^989[0-9]{9}$/', $phone_number))
            return '0' . substr($phone_number, 2);
        if (preg_match('/^\+989[0-9]{9}$/', $phone_number))
            return '0' . substr($phone_number, 3);
        if (preg_match('/^00989[0-9]{9}$/', $phone_number))
            return '0' . substr($phone_number, 4);
        if (preg_match('/^9[0-9]{9}$/', $phone_number))
            return '0' . $phone_number;

        return false;
    }

    protected function ConvertToEnglish($input) {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($persian, $english, $input);
    }
}

new Helper;