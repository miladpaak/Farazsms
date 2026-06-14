<?php

namespace FarazSMS;



class Main_Settings extends Helper {

    private $form_settings;
    private $send_sms;
    private $otp;
    private $shortcode_back_url = '';

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_shortcode('farazsms_login_form', [$this, 'Shortcode']);
        add_shortcode('farazsms_login_button', [$this, 'login_button_shortcode']);
        add_shortcode('farazsms_login_modal', [$this, 'login_modal_shortcode']);
        add_shortcode('farazsms_wallet_balance', [$this, 'wallet_balance_shortcode']);
        add_action('plugins_loaded', [$this, 'init']);
        add_action('wp_footer', [$this, 'display_exit_intent_slide']);
        add_filter('woocommerce_locate_template', [$this, 'replace_woocommerce_login_template'], 10, 3);

        add_action('wp_ajax_identifier_ajax_handler', [$this, 'Identifier_ajax_Process_handler']);
        add_action('wp_ajax_nopriv_identifier_ajax_handler', [$this, 'Identifier_ajax_Process_handler']);

        add_action('wp_ajax_login_ajax_handler', [$this, 'Login_ajax_Process_handler']);
        add_action('wp_ajax_nopriv_login_ajax_handler', [$this, 'Login_ajax_Process_handler']);

        add_action('wp_ajax_login_password_ajax_handler', [$this, 'Login_Password_ajax_Process_handler']);
        add_action('wp_ajax_nopriv_login_password_ajax_handler', [$this, 'Login_Password_ajax_Process_handler']);

        add_action('wp_ajax_register_ajax_handler', [$this, 'Register_ajax_Process_handler']);
        add_action('wp_ajax_nopriv_register_ajax_handler', [$this, 'Register_ajax_Process_handler']);
        
        add_action('wp_ajax_forget_mobile_password_ajax_handler', [$this, 'Forget_Mobile_Password_ajax_Process_handler']);
        add_action('wp_ajax_nopriv_forget_mobile_password_ajax_handler', [$this, 'Forget_Mobile_Password_ajax_Process_handler']);

        add_action('wp_ajax_reset_password_ajax_handler', [$this, 'Reset_Password_ajax_Process_handler']);
        add_action('wp_ajax_nopriv_reset_password_ajax_handler', [$this, 'Reset_Password_ajax_Process_handler']);

        add_action('wp_ajax_forget_code_ajax_handler', [$this, 'Forget_Code_ajax_Process_handler']);
        add_action('wp_ajax_nopriv_forget_code_ajax_handler', [$this, 'Forget_Code_ajax_Process_handler']);

        $this->form_settings = new Form_Settings;
        $this->send_sms = new Send_SMS;
        $this->otp = new OTP;
    }

    public function enqueue_frontend_assets() {
        // نسخه‌ی asset را به نسخه‌ی افزونه گره می‌زنیم تا با هر ریلیز، کشِ مرورگر/CDN
        // بشکند. قبلاً '1.0.0' هاردکد بود؛ در نتیجه پس از به‌روزرسانیِ login.js (مثلاً
        // افزودنِ auto-advance) مرورگرها نسخه‌ی کهنه را سرو می‌کردند و قابلیت کار نمی‌کرد
        // («روی بعضی سایت‌ها بله، بعضی نه» = وضعیتِ کشِ متفاوت).
        $ver = defined('FARAZSMS_PLUGIN_VERSION') ? FARAZSMS_PLUGIN_VERSION : '1.0.1';
        wp_register_style('farazsms-login', FarazSMS_ASSETS_URL . 'css/login.css',[], $ver);
        wp_register_script('farazsms-login',FarazSMS_ASSETS_URL . 'js/login.js',['jquery'], $ver,true);
        wp_localize_script('farazsms-login', 'farazsms_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('farazsms_nonce'),
            'resend_code_text' => __('Resend Code', 'farazsms'),
            'replace_default_forms' => $this->should_replace_default_forms() ? '1' : '0',
            'replacement_form_html' => $this->should_replace_default_forms() ? $this->render_embedded_login_form($this->get_current_request_url()) : '',
        ]);

        if ($this->should_replace_default_forms() && !is_admin()) {
            $this->enqueue_login_assets();
        }
        
        // Enqueue slide assets if enabled
        if (!is_admin()) {
            $enable_slide = Farazsms_Get_Setting('slide', 'enable_slide') ?? '0';
            if ($enable_slide == '1') {
                wp_enqueue_style('farazsms-slide', FarazSMS_ASSETS_URL . 'css/slide.css', [], $ver);
                wp_enqueue_script('farazsms-slide', FarazSMS_ASSETS_URL . 'js/slide.js', ['jquery'], $ver, true);
            }
        }
    }


    public function Main() {
        $this->enqueue_login_assets();
        if (is_user_logged_in()) {
            if(! is_admin()) {
            ?>
            <div class='btn-loading success-login'><div><div></div><div></div><div></div><div></div></div></div>
            <script type="text/javascript">jQuery(document).ready(function($) {window.location.href = '<?php echo home_url(); ?>';});</script>
            <?php
            }
        } else {
            $form_content = '';

            if (isset($_POST['submit_login'])) {
                $identifier = $this->ConvertToEnglish($_POST['identifier']);
                $identifier = sanitize_text_field($identifier);
                $back_url = sanitize_text_field($_POST['back_url']);

                ob_start();
                $this->Submit_Identifier($identifier, $back_url);
                $form_content = ob_get_clean();
            } elseif(isset($_POST['login_with_password'])) {
                $identifier = sanitize_text_field($_POST['identifier']);
                $identifier_type = sanitize_text_field($_POST['identifier_type']);
                $back_url = sanitize_text_field($_POST['back_url']);

                $form_content = $this->form_settings->Mobile_Login_With_Password($identifier, $identifier_type, $back_url);
            } elseif(isset($_POST['submit_forget_password'])) {
                $identifier = sanitize_text_field($_POST['identifier']);
                $identifier_type = sanitize_text_field($_POST['identifier_type']);
                $back_url = sanitize_text_field($_POST['back_url']);

                ob_start();
                $this->Email_Forget_Password_Content($identifier, $identifier_type, $back_url);
                $form_content = ob_get_clean();
            } else {
                $form_content = $this->form_settings->Main_Form($this->get_shortcode_back_url());
            }
        }
    }
    
    public function Submit_Identifier($identifier, $back_url) {
        $identifier_type = $this->Identifier($identifier);

        switch($identifier_type) {
            case 'mobile':
                $identifier = $this->ConvertToEnglish($identifier);

                // Validate mobile number
                if (!$this->validate_mobile($identifier)) {
                    echo "<div class='farazsms-error-text'>" . __('Invalid mobile number.', 'farazsms') . "</div>";
                    return;
                }

                // Check rate limiting
                if (!$this->check_rate_limit($identifier, 'sms')) {
                    echo "<div class='farazsms-error-text'>" . __('Too many requests. Please wait 5 minutes.', 'farazsms') . "</div>";
                    return;
                }

                $username = $this->get_username_by_mobile_number($identifier);
                $verification_code = $this->send_sms->code();
                $send_result = $this->send_sms->send($identifier, $verification_code);

                if ($send_result) {
                    $this->otp->delete_user_verification_data($identifier);
                    $this->otp->send_verification_code($identifier, $verification_code);

                    if (!empty($username)) {
                        $this->form_settings->Mobile_Login($identifier, $identifier_type, $back_url);
                    } else {
                        $this->form_settings->Mobile_Register($identifier, $identifier_type, $back_url);
                    }
                } else {
                    echo "<div class='farazsms-error-text'>" . __('SMS sending failed. Please try again.', 'farazsms') . "</div>";
                }
            break;
            case 'none':
                echo "<div class='farazsms-error-text'>" . __('Invalid input! Please enter a valid mobile number.', 'farazsms') . "</div>";
            break;
        }
    }

    public function Shortcode($atts = []) {
        $atts = shortcode_atts([
            'embedded' => '0',
            'back_url' => '',
        ], $atts, 'farazsms_login_form');

        $back_url = $this->resolve_back_url($atts['back_url']);

        if (!empty($atts['embedded']) && $atts['embedded'] !== '0') {
            return $this->render_embedded_login_form($back_url);
        }

        $previous_back_url = $this->shortcode_back_url;
        $this->shortcode_back_url = $back_url;

        ob_start();
        $this->Main();
        $output = ob_get_clean();

        $this->shortcode_back_url = $previous_back_url;

        return $output;
    }

    public function Login_Process() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'farazsms_nonce')) {
            wp_die(__('Security error occurred.', 'farazsms'));
        }

        if (isset($_POST['submit_identifier'])) {
            $identifier = sanitize_text_field($_POST['identifier']);
            $identifier_type = sanitize_text_field($_POST['identifier_type']);
            $verification_code = sanitize_text_field($_POST['verification_code']);
            $password = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';
            $back_url = esc_url_raw($_POST['back_url']);

            $this->Login_Process_Content($identifier, $identifier_type, $verification_code, $password, $back_url);
        }
    }

    public function Login_Process_Content($identifier, $identifier_type, $verification_code, $password, $back_url) {
        $error_verification_code = "<div class='farazsms-error-text'>" . __('Incorrect verification code.', 'farazsms') . "</div>";
        $success_login = "<div class='btn-loading success-login'><div><div></div><div></div><div></div><div></div></div></div>";
        $error_login = "<div class='farazsms-error-text'>" . __('Incorrect password.', 'farazsms') . "</div>";

        if ($identifier_type === 'mobile') {
            $username = $this->get_username_by_mobile_number($identifier);

            if($verification_code) {
                $verification_result = $this->otp->verify_verification_code($identifier, $verification_code);
                if ($verification_result) {
                    echo $success_login;
                    $login_in = $this->login_in_wordpress_with_out_password($username, $back_url);
                } else {
                    if (!empty($username)) {
                        $this->form_settings->Mobile_Login($identifier, $identifier_type, $back_url);
                    } else {
                        $this->form_settings->Mobile_Register($identifier, $identifier_type, $back_url);
                    }
                    echo $error_verification_code;
                }
            } else {
                $login_in = $this->login_in_wordpress($username, $password, $back_url);
                if ($login_in) {
                    echo $success_login;
                } else {
                    $this->form_settings->Mobile_Login_With_Password($identifier, $identifier_type, $back_url);
                    echo $error_login;
                }
            }
        } elseif ($identifier_type === 'none') {
            echo "<div class='farazsms-error-text'>" . __('Invalid input! Please enter a valid mobile number.', 'farazsms') . "</div>";
        }
    }
    
    public function Registration_Process() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'farazsms_nonce')) {
            wp_die(__('Security error occurred.', 'farazsms'));
        }

        if (isset($_POST['submit_registration'])) {
            $identifier = sanitize_text_field($_POST['identifier'] ?? '');
            $identifier_type = sanitize_text_field($_POST['identifier_type'] ?? '');
            $full_name = sanitize_text_field($_POST['full_name'] ?? '');
            $verification_code = sanitize_text_field($_POST['verification_code'] ?? '');
            $password = sanitize_text_field($_POST['password'] ?? '');
            $back_url = esc_url_raw($_POST['back_url'] ?? '');

            // وقتی فیلد نام اجباری است ولی خالی مانده، قبل از ساخت کاربر خطای واضح بده.
            if (Farazsms_Login_Ask_Name() && trim($full_name) === '') {
                $this->form_settings->Mobile_Register($identifier, $identifier_type, $back_url);
                echo "<div class='farazsms-error-text'>" . __('Please enter your name.', 'farazsms') . "</div>";
                return;
            }

            $this->Registration_Process_Content($identifier, $identifier_type, $full_name, $verification_code, $password, $back_url);
        }
    }

    public function Registration_Process_Content($identifier, $identifier_type, $full_name, $verification_code, $password, $back_url) {
        $registration_successful_msg = "<div class='farazsms-success-text'>" . __('Registration completed successfully.', 'farazsms') . "</div>";
        if ($identifier_type === 'mobile') {
            $mobile_number = $username = $identifier;
            $verification_result = $this->otp->verify_verification_code($identifier, $verification_code);
            if ($verification_result) {
                $password = wp_generate_password();
                $user_id = wp_create_user($username, $password, '');

                if (is_wp_error($user_id)) {
                    echo "<div class='farazsms-error-text'>" . __('Registration failed. Please try again.', 'farazsms') . "</div>";
                    return;
                }

                // وقتی نام داده نشده (فیلد غیرفعال است)، display_name را با شماره موبایل پر کن
                // تا هیچ مسیری با مقدار خالی نشکند.
                $display = trim((string) $full_name) !== '' ? $full_name : $mobile_number;
                update_user_meta($user_id, 'first_name', $full_name);
                update_user_meta($user_id, 'mobile_number', $mobile_number);
                update_user_meta($user_id, 'nickname', $display);
                wp_update_user(array('ID' => $user_id, 'display_name' => $display));
                update_user_meta($user_id, 'billing_phone', $mobile_number);
                $this->login_in_wordpress_with_out_password($username, $back_url);
                echo $registration_successful_msg;
            } else {
                // فرم ثبت‌نام را دوباره رندر کن تا کاربر بتواند کد را اصلاح و دوباره وارد کند
                // (قبلاً فقط خطا echo می‌شد و فرم ناپدید می‌شد → امکان ویرایش نبود).
                $this->form_settings->Mobile_Register($identifier, $identifier_type, $back_url);
                echo "<div class='farazsms-error-text'>" . __('Incorrect verification code.', 'farazsms') . "</div>";
            }
        }
    }

    public function Forget_Mobile_Password_Content($identifier, $identifier_type, $back_url) {
        $verification_code = $this->send_sms->code();
        $this->send_sms->send($identifier, $verification_code);
        $this->otp->delete_user_verification_data($identifier);
        $this->otp->send_verification_code($identifier, $verification_code);
    
        $this->form_settings->Mobile_Forget_Password($identifier, $identifier_type, $back_url);
    }

    public function Forget_Code_Process() {
        if (isset($_POST['submit_forget_code'])) {
            $identifier = sanitize_text_field($_POST['identifier']);
            $identifier_type = sanitize_text_field($_POST['identifier_type']);
            $verification_code = sanitize_text_field($_POST['verification_code']);
            $back_url = sanitize_text_field($_POST['back_url']);
    
            $verification_result = $this->otp->verify_verification_code($identifier, $verification_code);
            if ($verification_result) {
                $this->form_settings->Mobile_Reset_Password($identifier, $identifier_type, $back_url);
            } else {
                $this->form_settings->Mobile_Forget_Password($identifier, $identifier_type, $back_url);
                echo "<div class='farazsms-error-text'>" . __('Verification code is not correct.', 'farazsms') . "</div>";
            }
        }
    }

    public function Reset_Password_Process() {
        if (isset($_POST['submit_reset_password'])) {
            $identifier = sanitize_text_field($_POST['identifier']);
            $identifier_type = sanitize_text_field($_POST['identifier_type']);
            $new_password = sanitize_text_field($_POST['new_password']);
            $confirm_password = sanitize_text_field($_POST['confirm_password']);
            $back_url = sanitize_text_field($_POST['back_url']);
    
            if ($new_password === $confirm_password) {
                $username = $this->get_username_by_mobile_number($identifier);
                $user = get_user_by('login', $username);
                if ($user) {
                    wp_set_password($new_password, $user->ID);
                    $this->login_in_wordpress($username, $new_password, $back_url);
                    echo "<div class='farazsms-success-text'>" . __('Password changed successfully.', 'farazsms') . "</div>";
                } else {
                    echo "<div class='farazsms-error-text'>" . __('User not found.', 'farazsms') . "</div>";
                }
            } else {
                $this->form_settings->Mobile_Reset_Password($identifier, $identifier_type, $back_url);
                echo "<div class='farazsms-error-text'>" . __('Password and confirmation do not match.', 'farazsms') . "</div>";
            }
        }
    }

    public function Identifier_ajax_Process_handler() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'farazsms_nonce')) {
            wp_send_json_error(__('Security error occurred.', 'farazsms'));
        }

        $data = $_POST['data'];
        $parsedData = [];
        parse_str($data, $parsedData);
        $identifier = sanitize_text_field($parsedData['identifier'] ?? '');
        $back_url = isset($parsedData['back_url']) ? esc_url_raw(urldecode($parsedData['back_url'])) : '';

        if (empty($identifier)) {
            wp_send_json_error(__('Invalid identifier.', 'farazsms'));
        }

        $this->Submit_Identifier($identifier, $back_url);

        wp_die();
    }

    public function Login_ajax_Process_handler() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'farazsms_nonce')) {
            wp_send_json_error(__('Security error occurred.', 'farazsms'));
        }

        $data = $_POST['data'];
        $parsedData = [];
        parse_str($data, $parsedData);
        $identifier = sanitize_text_field($parsedData['identifier'] ?? '');
        $identifier_type = sanitize_text_field($parsedData['identifier_type'] ?? '');
        $verification_code = sanitize_text_field($parsedData['verification_code'] ?? '');
        $password = sanitize_text_field($parsedData['password'] ?? '');
        $back_url = isset($parsedData['back_url']) ? esc_url_raw(urldecode($parsedData['back_url'])) : '';

        if (empty($identifier) || empty($identifier_type)) {
            wp_send_json_error(__('Invalid input data.', 'farazsms'));
        }

        $this->Login_Process_Content($identifier, $identifier_type, $verification_code, $password, $back_url);

        wp_die();
    }

    public function Login_Password_ajax_Process_handler() {
        $data = $_POST['data'];
        $parsedData = [];
        parse_str($data, $parsedData);
        $identifier = $parsedData['identifier'] ?? null;
        $identifier_type = $parsedData['identifier_type'] ?? null;
        $back_url = isset($parsedData['back_url']) ? urldecode($parsedData['back_url']) : null;
        
        $this->form_settings->Mobile_Login_With_Password($identifier, $identifier_type, $back_url);
        
        wp_die();
    }

    public function Register_ajax_Process_handler() {
        try {
            // پاسخ این هندلر به‌صورت HTML داخل فرم تزریق می‌شود (login.js → .html(response)).
            // پس خطاها هم باید HTML باشند، نه JSON — وگرنه صفحه سفید/خراب می‌شود.
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'farazsms_nonce')) {
                echo "<div class='farazsms-error-text'>" . __('Security error occurred.', 'farazsms') . "</div>";
                wp_die();
            }

            $data = $_POST['data'];
            $parsedData = [];
            parse_str($data, $parsedData);
            $identifier = sanitize_text_field($parsedData['identifier'] ?? '');
            $identifier_type = sanitize_text_field($parsedData['identifier_type'] ?? '');
            $full_name = sanitize_text_field($parsedData['full_name'] ?? '');
            $verification_code = sanitize_text_field($parsedData['verification_code'] ?? '');
            $password = sanitize_text_field($parsedData['password'] ?? '');
            $back_url = isset($parsedData['back_url']) ? esc_url_raw(urldecode($parsedData['back_url'])) : '';

            // فیلد نام فقط وقتی اجباری است که گزینه‌ی ask_name روشن باشد.
            $ask_name = Farazsms_Login_Ask_Name();

            if (empty($identifier) || empty($identifier_type)) {
                echo "<div class='farazsms-error-text'>" . __('Incomplete input data.', 'farazsms') . "</div>";
                wp_die();
            }

            if ($ask_name && trim($full_name) === '') {
                // فرم ثبت‌نام را دوباره رندر کن تا کاربر بتواند نام را وارد کند، سپس خطا را نشان بده.
                $this->form_settings->Mobile_Register($identifier, $identifier_type, $back_url);
                echo "<div class='farazsms-error-text'>" . __('Please enter your name.', 'farazsms') . "</div>";
                wp_die();
            }

            $this->Registration_Process_Content($identifier, $identifier_type, $full_name, $verification_code, $password, $back_url);

            wp_die();
        } catch (\Exception $e) {
            echo "<div class='farazsms-error-text'>" . esc_html(__('Registration failed: ', 'farazsms') . $e->getMessage()) . "</div>";
            wp_die();
        }
    }

    public function Forget_Mobile_Password_ajax_Process_handler() {
        $data = $_POST['data'];
        $parsedData = [];
        parse_str($data, $parsedData);
        $identifier = $parsedData['identifier'] ?? null;
        $identifier_type = $parsedData['identifier_type'] ?? null;
        $back_url = isset($parsedData['back_url']) ? urldecode($parsedData['back_url']) : null;
    
        $this->Forget_Mobile_Password_Content($identifier, $identifier_type, $back_url);
    
        wp_die();
    }
    
    public function Reset_Password_ajax_Process_handler() {
        $data = $_POST['data'];
        $parsedData = [];
        parse_str($data, $parsedData);
        $identifier = $parsedData['identifier'] ?? null;
        $identifier_type = $parsedData['identifier_type'] ?? null;
        $new_password = $parsedData['new_password'] ?? null;
        $confirm_password = $parsedData['confirm_password'] ?? null;
        $back_url = isset($parsedData['back_url']) ? urldecode($parsedData['back_url']) : null;
    
        if ($new_password === $confirm_password) {
            $username = $this->get_username_by_mobile_number($identifier);
            $user = get_user_by('login', $username);
            if ($user) {
                wp_set_password($new_password, $user->ID);
                $this->login_in_wordpress($username, $new_password, $back_url);
                echo "<div class='farazsms-success-text'>" . __('Password changed successfully.', 'farazsms') . "</div>";
            }
        } else {
            $this->form_settings->Mobile_Reset_Password($identifier, $identifier_type, $back_url);
            echo "<div class='farazsms-error-text'>" . __('Password and confirmation do not match.', 'farazsms') . "</div>";
        }
    
        wp_die();
    }

    public function Forget_Code_ajax_Process_handler() {
        $data = $_POST['data'];
        $parsedData = [];
        parse_str($data, $parsedData);
        $identifier = $parsedData['identifier'] ?? null;
        $identifier_type = $parsedData['identifier_type'] ?? null;
        $verification_code = $parsedData['verification_code'] ?? null;
        $back_url = isset($parsedData['back_url']) ? urldecode($parsedData['back_url']) : null;
    
        $verification_result = $this->otp->verify_verification_code($identifier, $verification_code);
        if ($verification_result) {
            $this->form_settings->Mobile_Reset_Password($identifier, $identifier_type, $back_url);
        } else {
            $this->form_settings->Mobile_Forget_Password($identifier, $identifier_type, $back_url);
            echo "<div class='farazsms-error-text'>" . __('Verification code is incorrect.', 'farazsms') . "</div>";
        }
    
        wp_die();
    }

    /**
     * Check rate limiting for SMS sending
     */
    private function check_rate_limit($identifier, $action = 'sms') {
        $transient_key = 'farazsms_rate_limit_' . md5($identifier . $action);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            set_transient($transient_key, 1, 300); // 5 minutes
            return true;
        }

        if ($attempts >= 3) { // Max 3 attempts per 5 minutes
            return false;
        }

        set_transient($transient_key, $attempts + 1, 300);
        return true;
    }

    /**
     * Validate mobile number format
     */
    private function validate_mobile($mobile) {
        // Iranian mobile number validation
        $pattern = '/^(\+98|0)?9\d{9}$/';
        return preg_match($pattern, $mobile);
    }


    public function init() {
        $this->Login_Process();
        $this->Registration_Process();
        $this->Forget_Code_Process();
        $this->Reset_Password_Process();
    }

    /**
     * Display exit intent slide
     */
    public function display_exit_intent_slide() {
        if (is_admin()) {
            return;
        }

        $enable_slide = Farazsms_Get_Setting('slide', 'enable_slide') ?? '0';
        if ($enable_slide != '1') {
            return;
        }

        $show_only_guests = Farazsms_Get_Setting('slide', 'slide_show_only_guests') ?? '1';
        if ($show_only_guests == '1' && is_user_logged_in()) {
            return;
        }

        $slide_settings = [
            'position' => Farazsms_Get_Setting('slide', 'slide_position') ?? 'right',
            'image' => Farazsms_Get_Setting('slide', 'slide_image') ?? '',
            'title' => Farazsms_Get_Setting('slide', 'slide_title') ?? __('Registration Gift', 'farazsms'),
            'description' => Farazsms_Get_Setting('slide', 'slide_description') ?? __('Sign up now and get a special gift!', 'farazsms'),
            'countdown_minutes' => intval(Farazsms_Get_Setting('slide', 'slide_countdown_minutes') ?? 1),
            'button_text' => Farazsms_Get_Setting('slide', 'slide_button_text') ?? __('Sign Up', 'farazsms'),
            'button_link' => Farazsms_Get_Setting('slide', 'slide_button_link') ?? '#',
            'button_color' => Farazsms_Get_Setting('slide', 'slide_button_color') ?? '#0BD08B',
            'background_color' => Farazsms_Get_Setting('slide', 'slide_background_color') ?? '#ffffff',
            'text_color' => Farazsms_Get_Setting('slide', 'slide_text_color') ?? '#333333',
            'title_color' => Farazsms_Get_Setting('slide', 'slide_title_color') ?? '#000000',
        ];

        $countdown_seconds = $slide_settings['countdown_minutes'] * 60;
        $position_class = $slide_settings['position'] == 'left' ? 'farazsms-slide-left' : 'farazsms-slide-right';
        ?>
        <div class="farazsms-exit-slide <?php echo esc_attr($position_class); ?>" 
             data-countdown="<?php echo esc_attr($countdown_seconds); ?>">
            <button type="button" class="farazsms-slide-close" aria-label="<?php echo esc_attr__('Close', 'farazsms'); ?>"></button>
            <div class="farazsms-slide-content" 
                 style="background-color: <?php echo esc_attr($slide_settings['background_color']); ?>;">
                <?php if (!empty($slide_settings['image'])): ?>
                    <div class="farazsms-slide-image">
                        <img src="<?php echo esc_url($slide_settings['image']); ?>" alt="<?php echo esc_attr($slide_settings['title']); ?>">
                    </div>
                <?php endif; ?>
                
                <div class="farazsms-slide-body">
                    <div class="farazsms-slide-content-inner">
                        <div class="farazsms-slide-content-text">
                            <h3 class="farazsms-slide-title" style="color: <?php echo esc_attr($slide_settings['title_color']); ?>;">
                                <?php echo esc_html($slide_settings['title']); ?>
                            </h3>
                            <p class="farazsms-slide-description" style="color: <?php echo esc_attr($slide_settings['text_color']); ?>;">
                                <?php echo esc_html($slide_settings['description']); ?>
                            </p>
                        </div>
                        
                        <div class="farazsms-slide-countdown-wrapper">
                            <div class="farazsms-slide-countdown-circle">
                                <svg class="farazsms-countdown-svg" viewBox="0 0 100 100">
                                    <circle class="farazsms-countdown-circle-bg" cx="50" cy="50" r="45"></circle>
                                    <circle class="farazsms-countdown-circle-progress" cx="50" cy="50" r="45"></circle>
                                </svg>
                                <div class="farazsms-countdown-text" style="color: <?php echo esc_attr($slide_settings['text_color']); ?>;">
                                    <span class="farazsms-countdown-seconds"><?php echo $countdown_seconds; ?></span>
                                    <span class="farazsms-countdown-label"><?php echo __('sec', 'farazsms'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <a href="<?php echo esc_url($slide_settings['button_link']); ?>" 
                       class="farazsms-slide-button" 
                       style="background-color: <?php echo esc_attr($slide_settings['button_color']); ?>;">
                        <?php echo esc_html($slide_settings['button_text']); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
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
     * Login button shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Button HTML
     * 
     * Usage: [farazsms_login_button] or [farazsms_login_button bg_color="#0BD08B" text_color="#ffffff"]
     */
    public function login_button_shortcode($atts) {
        $atts = shortcode_atts(array(
            'bg_color' => '#0BD08B',
            'text_color' => '#ffffff',
            'text' => __('Login / Register', 'farazsms'),
            'account_url' => '',
            'account_text' => __('My Account', 'farazsms'),
        ), $atts, 'farazsms_login_button');

        $is_logged_in = is_user_logged_in();
        $login_url = $this->get_farazsms_login_page_url();
        $redirect_after_login = trim((string) Farazsms_Get_Setting('general', 'redirect_after_login'));

        if ($is_logged_in) {
            $custom_account_url = trim((string) $atts['account_url']);
            if ($custom_account_url !== '') {
                $login_url = $custom_account_url;
            } elseif (class_exists('WooCommerce') && function_exists('wc_get_page_permalink')) {
                $my_account_url = wc_get_page_permalink('myaccount');
                if (!empty($my_account_url)) {
                    $login_url = $my_account_url;
                }
            } elseif (!empty($redirect_after_login)) {
                $login_url = $redirect_after_login;
            }
        }

        // If no fixed redirect is set, pass current page as back_url for guests.
        if (!$is_logged_in && $redirect_after_login === '') {
            $current_url = $this->get_current_request_url();
            $login_url = add_query_arg('back_url', $current_url, $login_url);
        }
        
        $bg_color = esc_attr($atts['bg_color']);
        $text_color = esc_attr($atts['text_color']);
        $button_text = $is_logged_in ? esc_html($atts['account_text']) : esc_html($atts['text']);

        $inline_style = sprintf(
            'background-color: %s; color: %s; display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s ease; border: none; cursor: pointer;',
            $bg_color,
            $text_color
        );

        return sprintf(
            '<a href="%s" class="farazsms-login-button" style="%s">%s</a>',
            esc_url($login_url),
            $inline_style,
            $button_text
        );
    }

    /**
     * شورت‌کدِ فرمِ ورود/ثبت‌نام به‌صورت مودال (پاپ‌آپ).
     * یک دکمه نمایش می‌دهد که با کلیک، همان فرمِ امبدِ ورود را در یک پنجره‌ی شناور باز می‌کند.
     * کاربرِ واردشده به‌جای دکمه، لینکِ «حساب من» می‌بیند (مثل [farazsms_login_button]).
     *
     * استفاده:
     *   [farazsms_login_modal text="ورود / ثبت‌نام" bg_color="#0BD08B" text_color="#fff" width="420"]
     *
     * @param array $atts
     * @return string
     */
    public function login_modal_shortcode($atts) {
        $atts = shortcode_atts(array(
            'bg_color'     => '#0BD08B',
            'text_color'   => '#ffffff',
            'text'         => __('Login / Register', 'farazsms'),
            'account_url'  => '',
            'account_text' => __('My Account', 'farazsms'),
            'width'        => '420',
        ), $atts, 'farazsms_login_modal');

        // کاربرِ واردشده → همان دکمه/لینکِ حساب (بدون مودال).
        if (is_user_logged_in()) {
            return $this->login_button_shortcode($atts);
        }

        // اطمینان از بارگذاریِ CSS/JSِ فرمِ ورود برای کارکردِ فرمِ داخلِ مودال.
        $this->enqueue_login_assets();

        // شناسه‌ی یکتا و قطعی (بدون رندوم) برای هر نمونه‌ی شورت‌کد در صفحه.
        static $instance = 0;
        $instance++;
        $uid = 'farazsms-modal-' . $instance;

        $back_url  = $this->get_current_request_url();
        $form_html = $this->render_embedded_login_form($back_url);

        $btn_style = sprintf(
            'background-color:%s; color:%s; display:inline-block; padding:12px 24px; border-radius:8px; border:none; cursor:pointer; font-weight:600; font-family:inherit;',
            esc_attr($atts['bg_color']),
            esc_attr($atts['text_color'])
        );
        $width = absint($atts['width']) ?: 420;

        ob_start();

        // CSS و JS فقط یک‌بار در کلِ صفحه چاپ می‌شوند (همه سلکتورها به .farazsms-modal محدودند).
        static $assets_printed = false;
        if (!$assets_printed) {
            $assets_printed = true;
            ?>
            <style>
                .farazsms-modal-overlay{position:fixed;inset:0;z-index:999999;display:none;align-items:flex-start;justify-content:center;background:rgba(15,23,42,.55);overflow-y:auto;padding:5vh 16px;}
                .farazsms-modal-overlay.is-open{display:flex;}
                .farazsms-modal-box{position:relative;width:100%;background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);padding:8px;animation:farazsmsModalIn .18s ease;}
                @keyframes farazsmsModalIn{from{opacity:0;transform:translateY(-12px);}to{opacity:1;transform:translateY(0);}}
                .farazsms-modal-close{position:absolute;top:10px;left:10px;width:34px;height:34px;border:none;background:#f1f5f9;color:#334155;border-radius:50%;font-size:20px;line-height:1;cursor:pointer;z-index:2;}
                .farazsms-modal-close:hover{background:#e2e8f0;}
                .farazsms-modal-box .login-register-page{min-height:auto;}
                .farazsms-modal-box .login-register-page .login-register-form{min-width:auto;max-width:none;margin-bottom:0;border:none;}
                html.farazsms-modal-open,body.farazsms-modal-open{overflow:hidden;}
            </style>
            <script>
            (function(){
                function close(o){ o.classList.remove('is-open'); document.documentElement.classList.remove('farazsms-modal-open'); document.body.classList.remove('farazsms-modal-open'); }
                document.addEventListener('click', function(e){
                    var trigger = e.target.closest('.farazsms-modal-trigger');
                    if (trigger){ e.preventDefault(); var o=document.getElementById(trigger.getAttribute('data-target')); if(o){ o.classList.add('is-open'); document.documentElement.classList.add('farazsms-modal-open'); document.body.classList.add('farazsms-modal-open'); } return; }
                    if (e.target.classList && e.target.classList.contains('farazsms-modal-close')){ var box=e.target.closest('.farazsms-modal-overlay'); if(box){ close(box); } return; }
                    if (e.target.classList && e.target.classList.contains('farazsms-modal-overlay')){ close(e.target); return; }
                });
                document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ var open=document.querySelector('.farazsms-modal-overlay.is-open'); if(open){ close(open); } } });
            })();
            </script>
            <?php
        }
        ?>
        <button type="button" class="farazsms-modal-trigger" data-target="<?php echo esc_attr($uid); ?>" style="<?php echo $btn_style; ?>"><?php echo esc_html($atts['text']); ?></button>
        <div class="farazsms-modal-overlay" id="<?php echo esc_attr($uid); ?>" role="dialog" aria-modal="true">
            <div class="farazsms-modal-box" style="max-width:<?php echo $width; ?>px;">
                <button type="button" class="farazsms-modal-close" aria-label="<?php esc_attr_e('بستن', 'farazsms'); ?>">&times;</button>
                <div class="farazsms-modal-content"><?php echo $form_html; // فرمِ امبد قبلاً escape/render شده ?></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Wallet balance shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Balance HTML
     * 
     * Usage: [farazsms_wallet_balance]
     */
    public function wallet_balance_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '';
        }

        $user_id = get_current_user_id();
        $balance = \FarazSMS\Wallet::get_balance($user_id);

        $atts = shortcode_atts(array(
            'format' => 'formatted', // formatted or raw
        ), $atts, 'farazsms_wallet_balance');

        if ($atts['format'] === 'raw') {
            return number_format($balance, 2, '.', '');
        }

        // Formatted output with WooCommerce price if available
        if (function_exists('wc_price')) {
            return wc_price($balance);
        }

        return number_format($balance, 0) . ' ' . __('Toman', 'farazsms');
    }

    private function enqueue_login_assets() {
        wp_enqueue_script('farazsms-login');
        wp_enqueue_style('farazsms-login');
    }

    private function should_replace_default_forms() {
        return !is_admin() && Farazsms_Get_Setting('general', 'replace_default_login_forms') === '1';
    }

    private function get_shortcode_back_url() {
        if (!empty($this->shortcode_back_url)) {
            return $this->shortcode_back_url;
        }

        if (!empty($_POST['back_url'])) {
            return $this->resolve_back_url(wp_unslash($_POST['back_url']));
        }

        if (!empty($_GET['back_url'])) {
            return $this->resolve_back_url(wp_unslash($_GET['back_url']));
        }

        return $this->get_current_request_url();
    }

    private function resolve_back_url($back_url = '') {
        if (!empty($back_url)) {
            return esc_url_raw($back_url);
        }

        return $this->get_shortcode_back_url();
    }

    private function get_current_request_url() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $request_uri = is_string($request_uri) ? trim($request_uri) : '/';

        if ($request_uri === '') {
            $request_uri = '/';
        }

        if (preg_match('#^https?:/[^/]#i', $request_uri)) {
            $request_uri = preg_replace('#^(https?:)/#i', '$1//', $request_uri, 1);
        }

        if (preg_match('#^https?://#i', $request_uri)) {
            return esc_url_raw($request_uri);
        }

        $home_url = home_url('/');
        $home_path = (string) wp_parse_url($home_url, PHP_URL_PATH);
        $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $request_query = (string) wp_parse_url($request_uri, PHP_URL_QUERY);

        $home_path = '/' . trim($home_path, '/');
        $home_path = $home_path === '/' ? '/' : $home_path . '/';

        if ($home_path !== '/' && strpos($request_path, $home_path) === 0) {
            $request_path = '/' . ltrim(substr($request_path, strlen($home_path)), '/');
        }

        $normalized_path = ltrim($request_path, '/');
        $current_url = home_url($normalized_path);

        if ($request_query !== '') {
            $current_url .= '?' . $request_query;
        }

        return esc_url_raw($current_url);
    }

    public function render_embedded_login_form($back_url = '') {
        $this->enqueue_login_assets();

        $back_url = $this->resolve_back_url($back_url);
        $previous_back_url = $this->shortcode_back_url;
        $this->shortcode_back_url = $back_url;

        $theme = Farazsms_Get_Setting('appearance', 'theme') ?: 'style-1';
        $text_alignment = Farazsms_Get_Setting('appearance', 'text_alignment') ?: 'center';
        $logo = Farazsms_Get_Setting('appearance', 'logo');
        // پیش‌فرض/فالبک: لوگوی خالی یا لوگوی قدیمیِ فراز → نمادکِ سایت (favicon).
        if (empty($logo) || strpos($logo, 'farazsms-login/assets/images/farazsms.png') !== false) {
            $logo = function_exists('get_site_icon_url') ? get_site_icon_url() : '';
        }

        ob_start();
        ?>
        <div class="farazsms-inline-login-wrapper">
            <div class="login-register-page farazsms-embedded-login <?php echo esc_attr('farazsms-' . $theme . ' text-align-' . $text_alignment); ?>">
                <div class="login-register-form">
                    <?php if (!empty($logo)) : ?>
                        <div class="logo">
                            <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="farazsms-main-login-form">
                        <?php $this->Main(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $output = ob_get_clean();

        $this->shortcode_back_url = $previous_back_url;

        return $output;
    }

    public function replace_woocommerce_login_template($template, $template_name, $template_path) {
        if (!$this->should_replace_default_forms() || !class_exists('WooCommerce')) {
            return $template;
        }

        $supported_templates = [
            'global/form-login.php',
            'myaccount/form-login.php',
        ];

        if (!in_array($template_name, $supported_templates, true)) {
            return $template;
        }

        $custom_template = FarazSMS_PATH . 'templates/woocommerce-form-login.php';

        if (file_exists($custom_template)) {
            return $custom_template;
        }

        return $template;
    }
}

new Main_Settings;
