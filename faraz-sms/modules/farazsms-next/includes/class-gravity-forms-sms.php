<?php
/**
 * Gravity Forms SMS Main Class
 *
 * Main class for managing Gravity Forms SMS notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Gravity_Forms_SMS {

    /**
     * Plugin version
     */
    public static $version = '3.8.4';

    /**
     * Minimum Gravity Forms version required
     */
    public static $gf_version = '1.9.10';

    /**
     * Constructor
     */
    public static function construct() {
        // Check if Gravity Forms is installed
        if (!class_exists('GFCommon')) {
            return false;
        }

        // Check Gravity Forms version
        if (!version_compare(GFCommon::$version, self::$gf_version, '>=')) {
            return false;
        }

        // Load required classes
        if (!class_exists('FarazSMS_Next_Gravity_Forms_SMS_SQL')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-gravity-forms-sms-sql.php');
        }
        
        // Setup database tables
        FarazSMS_Next_Gravity_Forms_SMS_SQL::setup_update();

        // Load other classes
        if (!class_exists('FarazSMS_Next_Gravity_Forms_SMS_Feeds')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-gravity-forms-sms-feeds.php');
        }
        FarazSMS_Next_Gravity_Forms_SMS_Feeds::construct();

        if (!class_exists('FarazSMS_Next_Gravity_Forms_SMS_Configurations')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-gravity-forms-sms-configurations.php');
        }
        FarazSMS_Next_Gravity_Forms_SMS_Configurations::construct();

        if (!class_exists('FarazSMS_Next_Gravity_Forms_SMS_Send')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-gravity-forms-sms-send.php');
        }
        FarazSMS_Next_Gravity_Forms_SMS_Send::construct();

        // Register OTP verification field type
        add_action('gform_loaded', array(__CLASS__, 'register_otp_field'), 5);
        if (did_action('gform_loaded')) {
            self::register_otp_field();
        }
        add_action('gform_enqueue_scripts', array(__CLASS__, 'enqueue_otp_script_when_form_has_otp'), 10, 2);

        // هشدارِ ادیتورِ فرم: اگر فرم فیلدِ تأییدِ فراز را دارد ولی پترن ساخته نشده.
        add_action('admin_notices', array(__CLASS__, 'otp_editor_notice'));

        // Add submenu to Gravity Forms - must be added early
        add_filter('gform_addon_navigation', array(__CLASS__, 'submenu'), 10);

        if (is_admin()) {
            // Load sent messages class
            if (!class_exists('FarazSMS_Next_Gravity_Forms_SMS_Sent')) {
                require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-gravity-forms-sms-sent.php');
            }
        }
    }

    /**
     * Add submenu to Gravity Forms navigation
     *
     * @param array $submenus Existing submenus
     * @return array Modified submenus
     */
    public static function submenu($submenus) {
        if (!is_array($submenus)) {
            $submenus = array();
        }
        
        $submenus[] = array(
            'name' => 'gf_farazsms',
            'label' => __('اطلاع رسانی پیامک فراز', 'farazsms-next'),
            'callback' => array(__CLASS__, 'pages'),
            'permission' => 'gravityforms_edit_forms'
        );

        return $submenus;
    }

    /**
     * Handle page routing
     */
    public static function pages() {
        // Use rgget if available (Gravity Forms function), otherwise use $_GET
        $view = function_exists('rgget') ? rgget('view') : (isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '');

        if ($view === 'edit') {
            FarazSMS_Next_Gravity_Forms_SMS_Configurations::configuration();
        } elseif ($view === 'sent') {
            FarazSMS_Next_Gravity_Forms_SMS_Sent::table();
        } else {
            FarazSMS_Next_Gravity_Forms_SMS_Feeds::feeds('');
        }
    }

    /**
     * Get plugin settings
     *
     * @return array Settings
     */
    public static function get_option() {
        return get_option('farazsms_next_gravity_forms_settings', array());
    }

    /**
     * هشدار در ادیتورِ فرمِ گرویتی: اگر فرمِ در حالِ ویرایش، فیلدِ تأییدِ فراز را
     * دارد ولی هنوز پترنِ کد تأیید ساخته نشده — قبل از انتشار به مدیر اطلاع بده.
     */
    public static function otp_editor_notice() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'gf_edit_forms') {
            return;
        }
        $form_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($form_id <= 0 || !class_exists('GFAPI')) {
            return;
        }

        $form = \GFAPI::get_form($form_id);
        if (empty($form['fields']) || !is_array($form['fields'])) {
            return;
        }
        $has_otp = false;
        foreach ($form['fields'] as $field) {
            if (isset($field->type) && $field->type === 'otp_verification') {
                $has_otp = true;
                break;
            }
        }
        if (!$has_otp) {
            return;
        }
        if (trim((string) get_option('wto_otp_pattern', '')) !== '') {
            return; // پترن ساخته شده — هشدار لازم نیست.
        }

        $settings_url = admin_url('admin.php?page=farazwto-sms-forms');
        echo '<div class="notice notice-error"><p style="font-size:13px;line-height:1.9;">'
            . '⚠️ <strong>این فرم فیلدِ «تأیید موبایل با فراز اس ام اس» دارد، اما هنوز «پترن کد تأیید» ساخته نشده است.</strong> '
            . 'تا پترن را نسازید و در پنلِ فراز تأیید نشود، کدِ تأیید برای کاربران ارسال نمی‌شود. '
            . 'لطفاً از <a href="' . esc_url($settings_url) . '"><strong>فراز اس ام اس ← گرویتی‌فرم/المنتور</strong></a> پترن را بسازید.'
            . '</p></div>';
    }

    /**
     * Register OTP verification field with Gravity Forms.
     */
    public static function register_otp_field() {
        if (!class_exists('GF_Field')) {
            return;
        }
        if (!class_exists('GF_Field_OTP_Verification')) {
            require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/fields/class-gf-field-otp.php';
        }
        GF_Fields::register(new GF_Field_OTP_Verification());
    }

    /**
     * Enqueue OTP script and localize when form contains OTP field.
     *
     * @param array $form   Form object.
     * @param bool  $is_ajax Whether form is submitted via AJAX.
     */
    public static function enqueue_otp_script_when_form_has_otp($form, $is_ajax) {
        if (empty($form['fields']) || !is_array($form['fields'])) {
            return;
        }
        foreach ($form['fields'] as $field) {
            if (isset($field->type) && $field->type === 'otp_verification') {
                $script_url = defined('WTO_CORE_JS') ? WTO_CORE_JS . 'wto-gf-otp.js' : plugins_url('assets/js/wto-gf-otp.js', dirname(dirname(dirname(__FILE__))));
                wp_enqueue_script(
                    'wto-gf-otp',
                    $script_url,
                    array('jquery'),
                    defined('FARAZSMS_NEXT_VERSION') ? FARAZSMS_NEXT_VERSION : '1.0.0',
                    true
                );
                wp_localize_script('wto-gf-otp', 'wtoGfOtp', array(
                    'ajaxurl'    => admin_url('admin-ajax.php'),
                    'nonce_send' => wp_create_nonce('wto_otp_send'),
                    'nonce_verify' => wp_create_nonce('wto_otp_verify'),
                    'strings'    => array(
                        'enter_mobile'  => __('شماره موبایل را وارد کنید.', 'farazsms-next'),
                        'sending'       => __('در حال ارسال کد...', 'farazsms-next'),
                        'sent'          => __('کد تأیید ارسال شد.', 'farazsms-next'),
                        'enter_code'    => __('کد تأیید را وارد کنید.', 'farazsms-next'),
                        'verifying'     => __('در حال تأیید...', 'farazsms-next'),
                        'verified'      => __('شماره موبایل تأیید شد.', 'farazsms-next'),
                        'code_invalid'  => __('کد وارد شده اشتباه یا منقضی است.', 'farazsms-next'),
                        'error'         => __('خطایی رخ داد. دوباره تلاش کنید.', 'farazsms-next'),
                    ),
                ));
                break;
            }
        }
    }
}

