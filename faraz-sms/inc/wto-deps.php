<?php
/**
 * Dependency Detection — Phase 10 (v3.13.6)
 *
 * این فایل توابع کوچک و بدون-side-effect برای تشخیص حضور افزونه‌های جانبی ارائه می‌کند.
 * هدف: بارگذاری ماژول‌های وابسته به یک افزونه (مثل ووکامرس، گرویتی، میهن پنل) فقط
 * زمانی که آن افزونه فعال باشد — تا کاربرانی که آن افزونه را ندارند، تحت تأثیر
 * هزینه parse + ثبت هوک‌های اضافی قرار نگیرند.
 *
 * نکته فنی: ترتیب بارگذاری افزونه‌ها در وردپرس قطعی نیست (مخصوصاً وقتی دو افزونه
 * نام مشابه دارند مثل woocommerce و faraz-sms). بنابراین به‌جای
 * class_exists( 'WooCommerce' ) که ممکن است در لحظه core-init هنوز تعریف نشده باشد،
 * از option active_plugins استفاده می‌کنیم — این option قبل از بارگذاری هر افزونه
 * توسط WP آماده است.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * بررسی فعال بودن یک افزونه بر اساس مسیر آن.
 * مستقل از class_exists کار می‌کند تا ترتیب بارگذاری مشکلی ایجاد نکند.
 *
 * @param string $plugin_path مثل 'woocommerce/woocommerce.php'
 */
function wto_is_plugin_active( $plugin_path ) {
	static $cache = array();
	if ( isset( $cache[ $plugin_path ] ) ) {
		return $cache[ $plugin_path ];
	}
	$active = (array) get_option( 'active_plugins', array() );
	if ( in_array( $plugin_path, $active, true ) ) {
		return $cache[ $plugin_path ] = true;
	}
	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( isset( $network[ $plugin_path ] ) ) {
			return $cache[ $plugin_path ] = true;
		}
	}
	return $cache[ $plugin_path ] = false;
}

/**
 * WooCommerce فعال است؟
 */
function wto_is_wc_active() {
	return wto_is_plugin_active( 'woocommerce/woocommerce.php' );
}

/**
 * Gravity Forms فعال است؟ (در دو مسیر متداول می‌نشیند)
 */
function wto_is_gf_active() {
	return wto_is_plugin_active( 'gravityforms/gravityforms.php' );
}

/**
 * Elementor (Free یا Pro) فعال است؟
 */
function wto_is_elementor_active() {
	return wto_is_plugin_active( 'elementor/elementor.php' )
		|| wto_is_plugin_active( 'elementor-pro/elementor-pro.php' );
}

/**
 * افزونه پیامک ووکامرس فارسی (PWSMS) فعال است؟
 */
function wto_is_pwsms_active() {
	return wto_is_plugin_active( 'persian-woocommerce-sms-pro/persian-woocommerce-sms-pro.php' )
		|| wto_is_plugin_active( 'persian-woocommerce-sms/persian-woocommerce-sms.php' );
}

/**
 * میهن پنل فعال است؟ (نام دقیق پلاگین ممکن است متفاوت باشد — چند مسیر متداول)
 */
function wto_is_mihanpanel_active() {
	return wto_is_plugin_active( 'mihanpanel-lite/mihanpanel-lite.php' )
		|| wto_is_plugin_active( 'mihanpanel/mihanpanel.php' )
		|| wto_is_plugin_active( 'mihanpanel-pro/mihanpanel-pro.php' );
}

/**
 * Learnpress فعال است؟
 */
function wto_is_learnpress_active() {
	return wto_is_plugin_active( 'learnpress/learnpress.php' );
}

/**
 * Digits فعال است؟ (OTP login plugin که قبلاً ادغام دارد)
 */
function wto_is_digits_active() {
	return wto_is_plugin_active( 'digits/digits.php' );
}
