<?php
/**
 * Object Cache Wrapper — v3.18.0
 *
 * چرا این فایل وجود دارد؟
 *  WordPress دو لایه cache دارد:
 *    ۱) Object Cache (wp_cache_*)   — اگر سایت Redis/Memcached نصب کرده، persistent.
 *       اگر نه، فقط در حافظه‌ی همان request زنده است.
 *    ۲) Transient (set_transient/get_transient) — بدون object cache، DB-backed.
 *
 *  وقتی فقط از transient استفاده می‌کنیم، سایت‌های enterprise که Redis دارند
 *  از مزیت آن استفاده نمی‌کنند — هر بار DB hit می‌خورد. این wrapper:
 *    - اگر persistent cache در دسترس بود → wp_cache_* (Redis / Memcached)
 *    - اگر نبود → transient (fallback DB)
 *
 *  نتیجه: روی سایت‌های با object cache، تا ۱۰× سریع‌تر؛ روی سایت‌های معمولی
 *  بدون regression.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_CACHE_GROUP = 'wto_farazsms';

/**
 * آیا سایت persistent object cache دارد؟ static تا چندین فراخوانی per request
 * فقط یک‌بار بررسی شود.
 */
function wto_cache_has_persistent() {
	static $has = null;
	if ( $has === null ) {
		$has = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}
	return $has;
}

/**
 * Get from cache. Returns false if not found (همانند transient و wp_cache).
 *
 * @param string $key       کلید — به‌صورت خودکار prefix نمی‌شود (caller مسئول unique بودن).
 * @param mixed  $default   مقدار پیش‌فرض اگر cache miss.
 * @return mixed
 */
function wto_cache_get( $key, $default = false ) {
	if ( wto_cache_has_persistent() ) {
		$found = false;
		$value = wp_cache_get( $key, WTO_CACHE_GROUP, false, $found );
		if ( $found ) {
			return $value;
		}
		return $default;
	}
	$value = get_transient( $key );
	return ( $value !== false ) ? $value : $default;
}

/**
 * Set into cache.
 *
 * @param string $key
 * @param mixed  $value
 * @param int    $ttl  ثانیه — اگر 0، تا انتهای session زنده می‌ماند (در object cache).
 *                    در transient، 0 = تا حذف دستی.
 */
function wto_cache_set( $key, $value, $ttl = HOUR_IN_SECONDS ) {
	if ( wto_cache_has_persistent() ) {
		return wp_cache_set( $key, $value, WTO_CACHE_GROUP, $ttl );
	}
	return set_transient( $key, $value, $ttl );
}

/**
 * Delete from cache.
 */
function wto_cache_delete( $key ) {
	if ( wto_cache_has_persistent() ) {
		wp_cache_delete( $key, WTO_CACHE_GROUP );
	}
	// همیشه transient را هم پاک کن — احتمالاً قبلاً ست شده بوده
	delete_transient( $key );
	return true;
}

/**
 * Flush تمام cache های گروه ما — برای reset دستی روی صفحه‌ی debug.
 * Note: wp_cache_flush_group در WP 6.1+ موجود است. fallback به transient cleanup.
 */
function wto_cache_flush_group() {
	if ( wto_cache_has_persistent() && function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( WTO_CACHE_GROUP );
		return true;
	}
	// fallback: پاک کردن transient های شناخته‌شده افزونه
	global $wpdb;
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_wto_%'
		    OR option_name LIKE '_transient_timeout_wto_%'"
	);
	return true;
}
