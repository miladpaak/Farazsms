<?php
/**
 * Async Task Helper — v3.18.0
 *
 * چرا این فایل؟
 *  عملیات سنگین (مثلاً import ۵۰۰۰ مخاطب در GF Phonebook، یا dispatch ارسال
 *  انبوه پیامک) اگر sync اجرا شوند:
 *    - PHP timeout (max_execution_time) می‌خورند
 *    - Memory exhaust می‌شوند
 *    - User experience: «صفحه قفل کرد...»
 *
 *  راه‌حل: Action Scheduler — کتابخانه‌ای که WooCommerce هم استفاده می‌کند و
 *  در همه‌ی سایت‌های WC از قبل لود است.
 *
 *  این wrapper:
 *    - اگر AS در دسترس بود → کار را در background queue می‌اندازد
 *    - اگر نبود (سایت بدون WC) → sync اجرا می‌کند
 *
 *  استفاده:
 *    wto_async_dispatch( 'my_callback', array( $arg1, $arg2 ) );
 *
 *  callback باید function نام‌دار باشد (نه closure — AS closures را serialize نمی‌کند).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * آیا Action Scheduler در دسترس است؟
 */
function wto_async_available() {
	return function_exists( 'as_enqueue_async_action' );
}

/**
 * عملیات را به‌صورت async صف‌گذاری کن، یا اگر AS نیست، sync اجرا کن.
 *
 * @param string $callback نام تابع PHP که اجرا شود.
 * @param array  $args     آرگومان‌های تابع.
 * @param string $group    گروه AS (برای فیلتر در Tools → Scheduled Actions).
 * @return mixed   AS action id اگر async، یا نتیجه‌ی تابع اگر sync.
 */
function wto_async_dispatch( $callback, $args = array(), $group = 'wto-farazsms' ) {
	if ( wto_async_available() ) {
		return as_enqueue_async_action(
			'wto_async_run',
			array(
				'callback' => (string) $callback,
				'args'     => $args,
			),
			$group
		);
	}
	// fallback sync
	if ( is_callable( $callback ) ) {
		return call_user_func_array( $callback, $args );
	}
	return false;
}

/**
 * عملیات را با delay اجرا کن (برای throttling).
 *
 * @param int    $delay_seconds
 * @param string $callback
 * @param array  $args
 * @param string $group
 */
function wto_async_dispatch_delayed( $delay_seconds, $callback, $args = array(), $group = 'wto-farazsms' ) {
	if ( wto_async_available() ) {
		return as_schedule_single_action(
			time() + (int) $delay_seconds,
			'wto_async_run',
			array(
				'callback' => (string) $callback,
				'args'     => $args,
			),
			$group
		);
	}
	// fallback sync — delay ignored
	if ( is_callable( $callback ) ) {
		return call_user_func_array( $callback, $args );
	}
	return false;
}

// Dispatcher که AS صدا می‌زند
add_action( 'wto_async_run', 'wto_async_run_handler', 10, 1 );
function wto_async_run_handler( $payload ) {
	if ( ! is_array( $payload ) ) return;
	$cb   = isset( $payload['callback'] ) ? (string) $payload['callback'] : '';
	$args = isset( $payload['args'] ) && is_array( $payload['args'] ) ? $payload['args'] : array();
	if ( $cb !== '' && is_callable( $cb ) ) {
		call_user_func_array( $cb, $args );
	}
}
