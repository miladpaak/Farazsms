<?php
/**
 * سازگاری با WP Rocket.
 *
 * مشکل: وقتی افزونه‌ی فراز اس ام اس همراهِ WP Rocket با تنظیماتِ «Delay JavaScript
 * Execution» یا «Minify/Combine JS» فعال باشد، اسکریپت‌های افزونه دستکاری/به‌تعویق
 * می‌افتند و گاهی خطای JS ایجاد می‌کنند که جریانِ تسویه‌حساب را می‌شکند و کاربر «وارد
 * درگاه پرداخت نمی‌شود». راهکار: اسکریپت‌های خودِ افزونه را از بهینه‌سازی‌های Rocket
 * مستثنا می‌کنیم تا عادی و سرِ وقت بارگذاری شوند.
 *
 * این فیلترها اگر WP Rocket نصب نباشد هیچ اثری ندارند (no-op).
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * الگوهای مسیرِ اسکریپت‌های افزونه برای مستثنا کردن.
 *
 * @return array
 */
function wto_rocket_script_patterns() {
	return array(
		'/wp-content/plugins/faraz-sms/',
		'faraz-sms',
		'farazsms',
	);
}

/**
 * مستثنا از Minify/Combine JS.
 */
add_filter( 'rocket_exclude_js', 'wto_rocket_exclude_js' );
function wto_rocket_exclude_js( $excluded ) {
	$excluded   = is_array( $excluded ) ? $excluded : array();
	$excluded[] = '/wp-content/plugins/faraz-sms/(.*).js';
	return $excluded;
}

/**
 * مستثنا از «Delay JavaScript Execution» — مهم‌ترین فیلتر برای رفعِ مشکلِ درگاه پرداخت.
 */
add_filter( 'rocket_delay_js_exclusions', 'wto_rocket_delay_js_exclusions' );
function wto_rocket_delay_js_exclusions( $excluded ) {
	$excluded = is_array( $excluded ) ? $excluded : array();
	foreach ( wto_rocket_script_patterns() as $pattern ) {
		$excluded[] = $pattern;
	}
	return $excluded;
}

/**
 * مستثنا از فشرده‌سازیِ اسکریپت‌های inline افزونه.
 */
add_filter( 'rocket_excluded_inline_js_content', 'wto_rocket_excluded_inline_js' );
function wto_rocket_excluded_inline_js( $excluded ) {
	$excluded   = is_array( $excluded ) ? $excluded : array();
	$excluded[] = 'farazsms';
	$excluded[] = 'wto_';
	$excluded[] = 'wto-';
	return $excluded;
}

/**
 * صفحاتِ سبد/تسویه/حساب و صفحه‌ی بازبینیِ سفارشِ ما هرگز کش نشوند (Rocket معمولاً
 * صفحاتِ WooCommerce را خودش مستثنا می‌کند، اما صفحه‌ی سفارشیِ ما را نمی‌شناسد).
 */
add_filter( 'rocket_cache_reject_uri', 'wto_rocket_reject_uri' );
function wto_rocket_reject_uri( $uris ) {
	$uris = is_array( $uris ) ? $uris : array();
	// صفحه‌ی بازبینیِ سفارشِ ما (با عنوان orderreview و شورت‌کدِ [order_review]) نباید کش شود
	// چون به سبدِ خرید وابسته است. مسیر را یک‌بار کش می‌کنیم تا کوئریِ تکراری نزنیم.
	$path = get_transient( 'wto_order_review_path' );
	if ( $path === false ) {
		$path = '';
		$query = new WP_Query( array(
			'post_type'      => 'page',
			'title'          => 'orderreview',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		) );
		if ( ! empty( $query->posts ) ) {
			$path = (string) wp_parse_url( get_permalink( (int) $query->posts[0] ), PHP_URL_PATH );
		}
		set_transient( 'wto_order_review_path', $path, DAY_IN_SECONDS );
	}
	if ( ! empty( $path ) ) {
		$uris[] = rtrim( $path, '/' ) . '/(.*)';
	}
	return $uris;
}
