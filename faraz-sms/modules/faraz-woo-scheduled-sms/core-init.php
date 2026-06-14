<?php
/*
*
*	***** Faraz woo Scheduled sms *****
*
*	This file initializes all FWSS Core components
*	
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}
// Define FWSS Constants
define( 'FWSS_CORE_INC', dirname( __FILE__ ) . '/inc/' );
define( 'FWSS_CORE_IMG', plugins_url( 'assets/img/', __FILE__ ) );
define( 'FWSS_CORE_CSS', plugins_url( 'assets/css/', __FILE__ ) );
define( 'FWSS_CORE_JS', plugins_url( 'assets/js/', __FILE__ ) );
/*
*
*  Register CSS
*
*/
function fwss_register_core_css( $page ) {
	if ( $page === 'farazwto_page_farazwto-automation' ) {
		wp_enqueue_style( 'fwss-settings', FWSS_CORE_CSS . 'fwss-settings.css', null, '0.0.1', 'all' );
		wp_enqueue_style( 'select2css', FWSS_CORE_CSS . 'select2.min.css', false, '1.0', 'all' );
	}
	if ( get_post_type() === 'product' || get_post_type() === 'lp_course' ) {
		wp_enqueue_style( 'fwss-core', FWSS_CORE_CSS . 'fwss-core.css', null, '0.0.1', 'all' );
	}
}

;
add_action( 'admin_enqueue_scripts', 'fwss_register_core_css' );
/*
*
*  Register JS/Jquery Ready
*
*/
function fwss_register_core_js( $page ) {
	if ( $page === 'farazwto_page_farazwto-automation' ) {
		wp_enqueue_script( 'jquery-validate', FWSS_CORE_JS . 'jquery.validate.min.js', array( 'jquery' ), '0.0.1', true );
		// v3.18.0: استفاده از نسخه‌ی مشترک select2 در پلاگین اصلی به‌جای کپی محلی.
		// قبلاً سه نسخه‌ی ۷۱KB در پلاگین داشتیم — حذف ~۱۴۰KB سایز روی دیسک + cache بهتر مرورگر.
		$shared_select2 = plugins_url( 'assets/js/select2.min.js', WTO_PLUGIN_FILE );
		wp_enqueue_script( 'select2', $shared_select2, array( 'jquery-validate' ), '1.0', true );
		// v3.17.7: dependency array صحیح — قبلاً 'jquery' string بود که برخی dependency ها را override می‌کرد
		wp_enqueue_script( 'fwss-settings', FWSS_CORE_JS . 'fwss-settings.js', array( 'jquery', 'jquery-validate', 'select2' ), '3.17.7', true );
		$fwss_settings_info = [
			'delete_button' => FWSS_CORE_IMG . 'macos-close.png',
		];
		if (function_exists('is_woocommerce')){
			$fwss_settings_info['order_statuses'] = wc_get_order_statuses();
		}
		wp_localize_script( 'fwss-settings', 'fwss_settings_info', $fwss_settings_info );
	}
	if ( get_post_type() === 'product' || get_post_type() === 'lp_course' ) {
		wp_register_script( 'fwss-core', FWSS_CORE_JS . 'fwss-core.js', 'jquery', time(), true );
		$fwss_data = [
			'delete_button'  => FWSS_CORE_IMG . 'macos-close.png',
		];
		if (get_post_type() === 'product'){
			$fwss_data['order_statuses'] = wc_get_order_statuses();
		}elseif (get_post_type() === 'lp_course'){
			$fwss_data['order_statuses'] = learn_press_get_order_statuses();
		}
		wp_localize_script( 'fwss-core', 'fwss_data', $fwss_data );
		wp_enqueue_script( 'fwss-core' );
	}
}

add_action( 'admin_enqueue_scripts', 'fwss_register_core_js' );

/*
*
*  Includes
*
*/
// Load the sms api
require_once FWSS_CORE_INC . 'fwss-sms-api.php';
// Load the admin settings
require_once FWSS_CORE_INC . 'fwss-settings.php';
// Load the Functions
require_once FWSS_CORE_INC . 'fwss-core-functions.php';
// Load the ajax Request
require_once FWSS_CORE_INC . 'fwss-ajax-request.php';