<?php
/*
Plugin Name: فراز اس ام اس
Plugin URI: https://farazsms.com/
Description: با استفاده از افزونه فراز اس ام اس می توانید به صورت حرفه ای سایت خود را به یک ابزار قدرتمند پیامکی برای اطلاع رسانی و بازاریابی مجهز کنید
Version: 3.20.50
Author: FarazSMS
Author URI: https://farazsms.com/
Text Domain: wto
Domain Path: /languages
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.5
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'WPINC' ) ) {
	die;
}

// نسخه فعلی افزونه برای مقایسه با نسخه سایت فراز اس ام اس
if ( ! defined( 'FARAZSMS_PLUGIN_VERSION' ) ) {
	define( 'FARAZSMS_PLUGIN_VERSION', '3.20.50' );
}

// آمار Matomoِ فراز — روی همه‌ی نصب‌ها فعال (مدیرِ هر سایت می‌تواند از تنظیمات خاموش کند).
// https اجباری است تا رویدادهای سمتِ سرور هم کار کنند (نه فقط ردیابِ JS).
if ( ! defined( 'WTO_MATOMO_URL' ) ) {
	define( 'WTO_MATOMO_URL', 'https://matomo.faraz.club/' );
}
if ( ! defined( 'WTO_MATOMO_SITE_ID' ) ) {
	define( 'WTO_MATOMO_SITE_ID', '3' );
}

/**
 * v3.20.9 PAYMENT SAFETY — REMOVED in v3.20.9
 *
 * output buffer روی صفحات WC ایده‌ی بدی بود — WC از AJAX برای checkout
 * استفاده می‌کند و buffering با wp_send_json قاطی می‌شود.
 * این کد حذف شد. fix واقعی در hookهای مدول‌ها انجام می‌شود.
 */

/**
 * v3.20.9 ESCAPE HATCH — برای تشخیص سریع کدام مدول مقصر است.
 * در wp-config.php یکی از این constant ها را اضافه کنید و checkout را تست کنید:
 *   define( 'WTO_DISABLE_CHECKOUT_HOOKS', true );  // کل hookهای WC ما را خاموش می‌کند
 *   define( 'WTO_DISABLE_CASHBACK', true );        // فقط cashback
 *   define( 'WTO_DISABLE_ABANDONED', true );       // فقط abandoned cart
 *   define( 'WTO_DISABLE_BIRTHDAY_CHECKOUT', true ); // فقط فیلد تولد در checkout
 *   define( 'WTO_DISABLE_COMMENTS_FILTER', true ); // فیلد موبایل دیدگاه
 * هرکدام که فعال شد و checkout دوباره کار کرد، آن مدول مقصر است.
 */

// v3.14.9: constant مرجع برای مسیر فایل اصلی افزونه. هر ماژولی که نیاز به
// register_activation_hook یا register_deactivation_hook دارد، باید از این
// constant استفاده کند نه از string هاردکدشده با نام فایل. این به ما اجازه می‌دهد
// نام فایل اصلی را عوض کنیم بدون شکستن چیزی.
if ( ! defined( 'WTO_PLUGIN_FILE' ) ) {
	define( 'WTO_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WTO_PLUGIN_BASENAME' ) ) {
	define( 'WTO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

// v3.20.5: بارگذاری ترجمه — .mo فایل‌ها در پوشه languages/.
add_action( 'init', function () {
	load_plugin_textdomain( 'wto', false, dirname( WTO_PLUGIN_BASENAME ) . '/languages' );
	load_plugin_textdomain( 'farazsms-next', false, dirname( WTO_PLUGIN_BASENAME ) . '/languages' );
} );

require_once( plugin_dir_path( __FILE__ ) . 'core-init.php' );

// Activation hook is bound to this file (__FILE__) so renaming the plugin directory
// does not silently break activation-time setup. The callback is defined in core-init.php.
register_activation_hook( __FILE__, 'create_order_review_page' );

// v3.20.5: اعلام سازگاری با HPOS (High-Performance Order Storage) ووکامرس
// تمام query های افزونه ما از API استاندارد WC (wc_get_order, get_meta) استفاده می‌کنند
// که هم با postmeta قدیمی هم با wc_orders جدید کار می‌کند.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
} );
