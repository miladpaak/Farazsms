<?php
/**
 * Rate Limit Helper — v3.19.0
 *
 * چرا این فایل؟
 *  endpoint های public-facing (nopriv) را هر کسی می‌تواند بدون لاگین صدا بزند:
 *    - newsletter/subscribe
 *    - birthday capture shortcode
 *    - OTP send (که خودش rate-limited دارد)
 *
 *  بدون rate-limit، یک حمله ساده می‌تواند:
 *    - دیتابیس ما را با subscribers جعلی پر کند
 *    - اعتبار پنل SMS را به اتمام برساند (هر submit یک پیامک)
 *    - سرور سایت کاربر را زمین بزند
 *
 *  این helper یک مکانیزم سبک per-IP + per-mobile throttle ارائه می‌دهد که
 *  از Object Cache (Redis/Memcached) یا transient استفاده می‌کند.
 *
 *  استفاده:
 *    if ( ! wto_rate_limit_check( 'newsletter_sub', $ip, 5, MINUTE_IN_SECONDS ) ) {
 *        wp_send_json_error( array( 'message' => 'تعداد درخواست زیاد است.' ) );
 *    }
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * IP کلاینت — با پشتیبانی از reverse proxy (Cloudflare, Nginx).
 */
function wto_rate_limit_client_ip() {
	$candidates = array(
		'HTTP_CF_CONNECTING_IP',   // Cloudflare
		'HTTP_X_FORWARDED_FOR',    // reverse proxy
		'HTTP_X_REAL_IP',           // nginx
		'REMOTE_ADDR',
	);
	foreach ( $candidates as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$ip = (string) $_SERVER[ $key ];
			// X-Forwarded-For می‌تواند CSV باشد؛ اولی client است
			if ( strpos( $ip, ',' ) !== false ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '0.0.0.0';
}

/**
 * بررسی + افزایش شمارنده‌ی rate-limit.
 *
 * @param string $action    نام endpoint (مثل 'newsletter_sub').
 * @param string $key       شناسه‌ی منحصر‌به‌فرد کلاینت (IP، موبایل، user_id...).
 * @param int    $limit     سقف تعداد درخواست در window.
 * @param int    $window    طول window به ثانیه.
 * @return bool   true اگر اجازه دارد، false اگر throttled شده.
 */
function wto_rate_limit_check( $action, $key, $limit = 5, $window = 60 ) {
	$action = sanitize_key( $action );
	$key    = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $key );
	if ( $key === '' ) {
		return true; // بدون key نمی‌توان throttle کرد — اجازه می‌دهیم اما در log
	}

	$cache_key = 'wto_rl_' . $action . '_' . md5( $key );

	if ( function_exists( 'wto_cache_get' ) ) {
		$count = (int) wto_cache_get( $cache_key, 0 );
	} else {
		$count = (int) get_transient( $cache_key );
	}

	if ( $count >= $limit ) {
		return false;
	}

	$new_count = $count + 1;
	if ( function_exists( 'wto_cache_set' ) ) {
		wto_cache_set( $cache_key, $new_count, $window );
	} else {
		set_transient( $cache_key, $new_count, $window );
	}

	return true;
}

/**
 * Throttle برای endpoint های public — همزمان روی IP و یک شناسه‌ی اختیاری.
 * اگر هر یک fail شد، throttle می‌شود.
 *
 * @return array{allowed:bool, message:string}
 */
function wto_rate_limit_guard_public( $action, $secondary_key = '' ) {
	$ip = wto_rate_limit_client_ip();

	// لایه ۱: IP — ۱۰ درخواست در دقیقه
	if ( ! wto_rate_limit_check( $action . '_ip', $ip, 10, MINUTE_IN_SECONDS ) ) {
		return array(
			'allowed' => false,
			'message' => 'تعداد درخواست از این IP زیاد است. لطفاً چند دقیقه صبر کنید.',
		);
	}

	// لایه ۲: secondary key (مثلاً شماره موبایل) — ۳ در ۱۰ دقیقه
	if ( $secondary_key !== '' ) {
		if ( ! wto_rate_limit_check( $action . '_2nd', $secondary_key, 3, 10 * MINUTE_IN_SECONDS ) ) {
			return array(
				'allowed' => false,
				'message' => 'تعداد درخواست برای این شماره زیاد است. لطفاً ۱۰ دقیقه صبر کنید.',
			);
		}
	}

	return array( 'allowed' => true, 'message' => '' );
}
