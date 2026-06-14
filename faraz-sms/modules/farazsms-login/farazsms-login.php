<?php
/**
 * FarazSMS: WordPress Signup and Login
 *
 * Plugin Name:       FarazSMS: WordPress Signup and Login
 * Plugin URI:        https://farazsms.com/
 * Description:       FarazSMS: WordPress Signup and Login
 * Version:           1.0.0
 * Author:            Zhaket
 * Author URI:        https://farazsms.com/
 * Text Domain:       farazsms
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 */

namespace FarazSMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}


define('FarazSMS__FILE__', __FILE__);
define('FarazSMS_PATH', plugin_dir_path(FarazSMS__FILE__));
define('FarazSMS_INC', FarazSMS_PATH . 'includes/');
define('FarazSMS_URL', plugins_url('/', FarazSMS__FILE__));
define('FarazSMS_ASSETS_URL', FarazSMS_URL . 'assets/');

// Load global functions
require_once FarazSMS_INC . 'functions.php';

if (!class_exists('FarazSMS\Login')) {

    class Login {

        const VERSION = '1.0.0';
        private static $instance = null;

        private function __construct() {
            $this->init_hooks();
        }

        public static function get_instance() {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function init_hooks() {
            register_activation_hook(FarazSMS__FILE__, [__CLASS__, 'activate']);

            // بارگذاری ترجمه روی init با priority پایین تا قبل از رندر رشته‌ها آماده باشد
            // (سازگار با تغییر زمان‌بندی ترجمه در وردپرس ۶.۷+).
            add_action('init', [$this, 'load_textdomain'], 1);
            add_action('init', [$this, 'init_plugin']);
        }

        public static function activate() {
            require_once FarazSMS_INC . 'class-farazsms-activator.php';
            Activator::activate();
        }
        public function load_textdomain() {
            // مسیرِ صحیحِ نسبی به پوشه‌ی plugins — برای هر دو حالتِ standalone و bundled (تو در تو).
            // قبلاً basename(dirname()) بود که فقط نام پوشه‌ی آخر را می‌داد و در حالت bundled
            // (faraz-sms/modules/farazsms-login) مسیر را اشتباه می‌کرد → ترجمه‌ها لود نمی‌شدند و فرم انگلیسی می‌شد.
            $rel = dirname( plugin_basename( FarazSMS__FILE__ ) ) . '/languages';
            load_plugin_textdomain( 'farazsms', false, $rel );

            // fallback مطلق برای اطمینان کامل (به‌خصوص حالت bundled).
            $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
            $mofile = dirname( FarazSMS__FILE__ ) . '/languages/farazsms-' . $locale . '.mo';
            if ( file_exists( $mofile ) ) {
                load_textdomain( 'farazsms', $mofile );
            }
        }

        public function init_includes() {
            if(is_admin()) {
                require_once FarazSMS_PATH . 'admin/includes.php';
            }

            require_once FarazSMS_INC . 'includes.php';
        }

        public function init_plugin() {
            $this->init_includes();

            // Load page templates
            add_filter('theme_page_templates', [$this, 'add_page_templates']);
            add_filter('template_include', [$this, 'load_page_template']);
        }

        public function add_page_templates($templates) {
            $templates['farazsms-login-page.php'] = __('FarazSMS Login Page', 'farazsms');
            return $templates;
        }

        public function load_page_template($template) {
            if (is_page()) {
                $page_template = get_page_template_slug(get_queried_object_id());

                if ($page_template === 'farazsms-login-page.php') {
                    $plugin_template = FarazSMS_PATH . 'page-templates/farazsms-login-page.php';
                    if (file_exists($plugin_template)) {
                        return $plugin_template;
                    }
                }
            }

            return $template;
        }

    }

}

Login::get_instance();