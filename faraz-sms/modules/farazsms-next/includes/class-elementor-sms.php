<?php
/**
 * Elementor SMS Main Class
 *
 * Main class for managing Elementor SMS notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Elementor_SMS {

    /**
     * Plugin version
     */
    public static $version = '3.8.4';

    /**
     * Minimum Elementor version required
     */
    public static $elementor_version = '3.0.0';

    /**
     * Constructor
     */
    public static function construct() {
        // Check if Elementor is installed
        // Elementor fires 'elementor/loaded' action when loaded
        // Also check for class existence as fallback
        if (!did_action('elementor/loaded') && !class_exists('\Elementor\Plugin')) {
            return false;
        }

        // Check Elementor version if possible
        if (defined('ELEMENTOR_VERSION')) {
            if (!version_compare(ELEMENTOR_VERSION, self::$elementor_version, '>=')) {
                return false;
            }
        }

        // Load required classes
        if (!class_exists('FarazSMS_Next_Elementor_SMS_SQL')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-elementor-sms-sql.php');
        }
        
        // Setup database tables
        FarazSMS_Next_Elementor_SMS_SQL::setup_update();

        // Load other classes
        if (!class_exists('FarazSMS_Next_Elementor_SMS_Feeds')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-elementor-sms-feeds.php');
        }
        FarazSMS_Next_Elementor_SMS_Feeds::construct();

        if (!class_exists('FarazSMS_Next_Elementor_SMS_Configurations')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-elementor-sms-configurations.php');
        }
        FarazSMS_Next_Elementor_SMS_Configurations::construct();

        if (!class_exists('FarazSMS_Next_Elementor_SMS_Send')) {
            require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-elementor-sms-send.php');
        }
        FarazSMS_Next_Elementor_SMS_Send::construct();

        // Register OTP verification form field
        add_action('elementor_pro/forms/fields/register', array(__CLASS__, 'register_otp_form_field'));
        // نمایش «فراز اس ام اس» در Actions After Submit (ارسال واقعی همچنان از new_record + فیدها)
        add_action('elementor_pro/forms/actions/register', array(__CLASS__, 'register_farazsms_form_action'));
        add_action('elementor/frontend/after_enqueue_scripts', array(__CLASS__, 'enqueue_otp_frontend_script'));

        // Add submenu to Elementor - use priority 99 to ensure Elementor menu is already added
        // Elementor typically adds its menu with priority 10, so we use 99 to be safe
        add_action('admin_menu', array(__CLASS__, 'add_submenu'), 99);

        if (is_admin()) {
            // Load sent messages class
            if (!class_exists('FarazSMS_Next_Elementor_SMS_Sent')) {
                require_once(FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-elementor-sms-sent.php');
            }
        }
    }

    /**
     * Add submenu to Elementor navigation
     *
     * @return void
     */
    public static function add_submenu() {
        // Check if Elementor is loaded
        if (!did_action('elementor/loaded') && !class_exists('\Elementor\Plugin')) {
            return;
        }

        // Check if Elementor menu exists by checking global $menu
        global $menu, $submenu;
        
        // Try different possible parent menu slugs for Elementor
        $possible_parents = array('elementor', 'edit.php?post_type=elementor_library');
        
        $parent_slug = null;
        foreach ($possible_parents as $possible_parent) {
            // Check if parent menu exists
            if (isset($submenu[$possible_parent])) {
                $parent_slug = $possible_parent;
                break;
            }
        }
        
        // If no parent found, try to find Elementor menu by title
        if (!$parent_slug) {
            foreach ($menu as $menu_item) {
                if (isset($menu_item[0]) && (stripos($menu_item[0], 'Elementor') !== false || stripos($menu_item[0], 'المنتور') !== false)) {
                    $parent_slug = $menu_item[2];
                    break;
                }
            }
        }
        
        // If still no parent found, use 'elementor' as default
        if (!$parent_slug) {
            $parent_slug = 'elementor';
        }

        // Add submenu to Elementor
        // Use 'edit_posts' capability which is more permissive and commonly used by Elementor
        add_submenu_page(
            $parent_slug,
            __('اطلاع رسانی پیامک فراز', 'farazsms-next'),
            __('اطلاع رسانی پیامک فراز', 'farazsms-next'),
            'edit_posts', // Changed from 'manage_options' to 'edit_posts' for better compatibility
            'elementor_farazsms',
            array(__CLASS__, 'pages')
        );
    }

    /**
     * Handle page routing
     */
    public static function pages() {
        // Use $_GET for view parameter
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';

        if ($view === 'edit') {
            FarazSMS_Next_Elementor_SMS_Configurations::configuration();
        } elseif ($view === 'sent') {
            FarazSMS_Next_Elementor_SMS_Sent::table();
        } else {
            FarazSMS_Next_Elementor_SMS_Feeds::feeds('');
        }
    }

    /**
     * Get plugin settings
     *
     * @return array Settings
     */
    public static function get_option() {
        return get_option('farazsms_next_elementor_settings', array());
    }

    /**
     * Register OTP verification field with Elementor Pro forms.
     *
     * @param \ElementorPro\Modules\Forms\Registrars\Form_Fields_Registrar $registrar
     */
    public static function register_otp_form_field( $registrar ) {
        if ( ! class_exists( 'FarazSMS_Elementor_Field_OTP' ) ) {
            require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/forms/fields/class-elementor-field-otp.php';
        }
        $registrar->register( new FarazSMS_Elementor_Field_OTP() );
    }

    /**
     * ثبت اکشن فرم المنتور پرو (لیست Actions After Submit).
     *
     * @param \ElementorPro\Modules\Forms\Registrars\Form_Actions_Registrar $registrar
     * @return void
     */
    public static function register_farazsms_form_action( $registrar ) {
        if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
            return;
        }
        if ( ! class_exists( 'FarazSMS_Elementor_Form_Action_After_Submit' ) ) {
            require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/forms/actions/class-elementor-form-action-farazsms.php';
        }
        $registrar->register( new FarazSMS_Elementor_Form_Action_After_Submit() );
    }

    /**
     * Enqueue frontend script for OTP send/verify (inits when .wto-elementor-otp exists).
     */
    public static function enqueue_otp_frontend_script() {
        // v3.13.16: استفاده از WTO_PLUGIN_FILE به‌جای path هاردکدشده با نام فایل.
        $script_url = defined( 'WTO_CORE_JS' )
            ? WTO_CORE_JS . 'wto-elementor-otp.js'
            : plugins_url( 'assets/js/wto-elementor-otp.js', defined( 'WTO_PLUGIN_FILE' ) ? WTO_PLUGIN_FILE : __FILE__ );
        wp_enqueue_script(
            'wto-elementor-otp',
            $script_url,
            array( 'jquery' ),
            defined( 'FARAZSMS_NEXT_VERSION' ) ? FARAZSMS_NEXT_VERSION : '1.0.0',
            true
        );
        wp_localize_script( 'wto-elementor-otp', 'wtoElementorOtp', array(
            'ajaxurl'      => admin_url( 'admin-ajax.php' ),
            'nonce_send'   => wp_create_nonce( 'wto_otp_send' ),
            'nonce_verify' => wp_create_nonce( 'wto_otp_verify' ),
            'strings'      => array(
                'enter_mobile'  => __( 'شماره موبایل را وارد کنید.', 'farazsms-next' ),
                'sending'      => __( 'در حال ارسال کد...', 'farazsms-next' ),
                'sent'         => __( 'کد تأیید ارسال شد.', 'farazsms-next' ),
                'enter_code'   => __( 'کد تأیید را وارد کنید.', 'farazsms-next' ),
                'verifying'    => __( 'در حال تأیید...', 'farazsms-next' ),
                'verified'     => __( 'شماره موبایل تأیید شد.', 'farazsms-next' ),
                'code_invalid' => __( 'کد وارد شده اشتباه یا منقضی است.', 'farazsms-next' ),
                'error'        => __( 'خطایی رخ داد. دوباره تلاش کنید.', 'farazsms-next' ),
            ),
        ) );
    }
}

