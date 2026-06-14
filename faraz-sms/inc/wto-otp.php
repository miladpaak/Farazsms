<?php
/**
 * OTP (One-Time Password) backend for form verification
 * Used by Gravity Forms and Elementor form OTP fields.
 *
 * Handles: generate/store OTP in transient, send via pattern SMS, verify and set verified transient.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/** OTP transient expiry (seconds) */
define( 'WTO_OTP_TRANSIENT_EXPIRY', 120 );

/** Verified transient expiry (seconds) - form can be submitted within this window */
define( 'WTO_OTP_VERIFIED_EXPIRY', 300 );

/** Rate limit: min seconds between send per mobile */
define( 'WTO_OTP_RATE_LIMIT_SECONDS', 60 );

/** Max consecutive failed verify attempts before lockout */
define( 'WTO_OTP_MAX_ATTEMPTS', 5 );

/** Lockout duration after max failed attempts (seconds) */
define( 'WTO_OTP_LOCKOUT_SECONDS', 15 * MINUTE_IN_SECONDS );

/**
 * v3.17.3: تزریق یک‌بار CSS مدرن برای فیلد OTP — برای GF و Elementor مشترک.
 * v3.20.0: inline `<style>` به فایل خارجی منتقل شد (assets/css/wto-otp-modern.css)
 * تا browser cache کند. این تابع فقط یک‌بار enqueue را تضمین می‌کند.
 */
function wto_otp_maybe_inject_styles() {
	static $injected = false;
	if ( $injected ) return;
	$injected = true;

	$css_url = defined( 'WTO_CORE_CSS' )
		? WTO_CORE_CSS . 'wto-otp-modern.css'
		: ( defined( 'WTO_PLUGIN_FILE' )
			? plugins_url( 'assets/css/wto-otp-modern.css', WTO_PLUGIN_FILE )
			: '' );

	if ( $css_url === '' ) return;

	if ( did_action( 'wp_enqueue_scripts' ) ) {
		// قبلاً enqueue phase تمام شده — print فوری (HTML head ممکن است هنوز باز باشد یا inline در body)
		printf(
			'<link rel="stylesheet" id="wto-otp-modern-css" href="%s" media="all" />',
			esc_url( $css_url )
		);
	} else {
		wp_enqueue_style( 'wto-otp-modern', $css_url, array(), '3.20.0', 'all' );
	}
}

/**
 * Normalize mobile for storage/transient key (consistent format).
 *
 * @param string $mobile Raw mobile input.
 * @return string Normalized mobile.
 */
function wto_otp_normalize_mobile( $mobile ) {
	if ( empty( $mobile ) ) {
		return '';
	}
	// v3.17.6: ابتدا ارقام فارسی/عربی را به لاتین تبدیل کن — قبل از regex
	if ( function_exists( 'wto_tr_num' ) ) {
		$mobile = wto_tr_num( $mobile );
	}
	$mobile = preg_replace( '/[\s\-]/', '', $mobile );
	preg_match_all( '/\d+/', $mobile, $m );
	$digits = implode( '', $m[0] ?? [] );
	if ( empty( $digits ) ) {
		return '';
	}
	// Iranian: 09... or 9... → 09...
	if ( strlen( $digits ) >= 10 && ( substr( $digits, 0, 2 ) === '98' || substr( $digits, 0, 1 ) === '9' ) ) {
		if ( substr( $digits, 0, 2 ) === '98' ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( substr( $digits, 0, 1 ) === '9' && strlen( $digits ) === 10 ) {
			$digits = '0' . $digits;
		}
	}
	return $digits;
}

/**
 * Generate and store OTP for a mobile, send SMS via pattern.
 *
 * @param string $mobile Normalized mobile number.
 * @return array { 'success' => bool, 'message' => string }
 */
function wto_otp_send_code( $mobile ) {
	$pattern_code = get_option( 'wto_otp_pattern', '' );
	if ( empty( $pattern_code ) ) {
		return array(
			'success' => false,
			'message' => __( 'کد پترنِ «تأیید موبایل» ساخته نشده است؛ به همین دلیل پیامک ارسال نمی‌شود. مدیرِ سایت باید از مسیرِ «فراز اس ام اس ← گرویتی‌فرم/المنتور» ابتدا «ساخت پترن کد تأیید» را بزند و آن را در پنلِ فراز تأیید کند.', 'wto' ),
		);
	}

	if ( ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return array( 'success' => false, 'message' => __( 'تابع ارسال پترن در دسترس نیست.', 'wto' ) );
	}

	// v3.13.12 SECURITY FIX: Multi-tier rate-limit برای جلوگیری از spam vector.
	//
	// قبل: فقط ۶۰ ثانیه per-mobile cooldown. مهاجم می‌توانست ~۸۶۴k پیامک/روز
	// (با هزینه‌ی صاحب سایت) به شماره‌های دلخواه ایرانی بفرستد.
	//
	// حالا سه لایه:
	//  1. per-mobile progressive backoff: 60s → 300s → 900s
	//  2. per-IP daily cap: حداکثر 20 درخواست OTP در روز از یک IP
	//  3. global hourly cap: حداکثر 500 OTP در ساعت در کل سایت
	//
	// همه به‌صورت transient که به‌خوبی روی Redis/Memcached هم scale می‌شود.

	$rate_key      = 'wto_otp_sent_' . $mobile;
	$backoff_key   = 'wto_otp_backoff_' . $mobile;
	$backoff_level = (int) get_transient( $backoff_key );

	if ( get_transient( $rate_key ) ) {
		return array( 'success' => false, 'message' => __( 'لطفاً کمی صبر کنید و دوباره درخواست ارسال کد دهید.', 'wto' ) );
	}

	// لایه ۲: per-IP daily cap
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F\.:]/', '', $_SERVER['REMOTE_ADDR'] ) : '';
	if ( $ip !== '' ) {
		$ip_key   = 'wto_otp_ip_' . md5( $ip );
		$ip_count = (int) get_transient( $ip_key );
		if ( $ip_count >= 20 ) {
			return array( 'success' => false, 'message' => __( 'تعداد درخواست از این آی‌پی زیاد است. فردا دوباره تلاش کنید.', 'wto' ) );
		}
		set_transient( $ip_key, $ip_count + 1, DAY_IN_SECONDS );
	}

	// لایه ۳: global hourly cap (محافظت از اعتبار پنل صاحب سایت)
	$global_key   = 'wto_otp_global_hour';
	$global_count = (int) get_transient( $global_key );
	if ( $global_count >= 500 ) {
		return array( 'success' => false, 'message' => __( 'سامانه موقتاً اشباع است. لطفاً دقایقی دیگر تلاش کنید.', 'wto' ) );
	}
	set_transient( $global_key, $global_count + 1, HOUR_IN_SECONDS );

	// 6-digit OTP from a CSPRNG (random_int) — 1,000,000 combinations, not predictable.
	$otp = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	$transient_key = 'wto_otp_' . $mobile;
	// Reset any prior failed-attempt counter when a fresh code is issued.
	delete_transient( 'wto_otp_attempts_' . $mobile );
	set_transient( $transient_key, $otp, WTO_OTP_TRANSIENT_EXPIRY );

	// لایه ۱: progressive backoff per-mobile — هر بار طولانی‌تر می‌شود.
	$backoff_steps = array( 60, 300, 900, 3600 ); // 1m, 5m, 15m, 1h
	$next_idx      = min( $backoff_level, count( $backoff_steps ) - 1 );
	$cooldown      = $backoff_steps[ $next_idx ];
	set_transient( $rate_key, '1', $cooldown );
	set_transient( $backoff_key, $backoff_level + 1, DAY_IN_SECONDS );

	$sender = get_option( 'wto_sender', '' );
	$recipient = $mobile;
	if ( function_exists( 'wto_normalize_phone' ) ) {
		$recipient = wto_normalize_phone( $mobile );
	}
	// API expects attributes; pattern variable name is typically "code"
	$attributes = array( 'code' => $otp );
	$result = wto_send_pattern_sms_raw( $recipient, $pattern_code, $attributes, $sender );

	if ( $result === 'success' ) {
		return array( 'success' => true, 'message' => __( 'کد تأیید ارسال شد.', 'wto' ) );
	}
	delete_transient( $transient_key );
	delete_transient( $rate_key );
	$err = is_string( $result ) ? $result : __( 'خطا در ارسال پیامک.', 'wto' );
	return array( 'success' => false, 'message' => $err );
}

/**
 * Verify OTP and set verified transient for form submission.
 *
 * @param string $mobile   Normalized mobile.
 * @param string $code     User-entered code.
 * @param string $context  'gf' or 'elementor'.
 * @param string $form_id  Form identifier (GF form id or Elementor form post id).
 * @return array { 'success' => bool, 'message' => string }
 */
function wto_otp_verify_code( $mobile, $code, $context, $form_id ) {
	// Brute-force lockout — after WTO_OTP_MAX_ATTEMPTS failures, refuse verify for the lockout window.
	$attempts_key = 'wto_otp_attempts_' . $mobile;
	$attempts     = (int) get_transient( $attempts_key );
	if ( $attempts >= WTO_OTP_MAX_ATTEMPTS ) {
		return array(
			'success' => false,
			'message' => __( 'تعداد تلاش‌های ناموفق زیاد است. لطفاً ۱۵ دقیقه دیگر مجدداً تلاش کنید.', 'wto' ),
		);
	}

	$transient_key = 'wto_otp_' . $mobile;
	$stored        = get_transient( $transient_key );
	if ( $stored === false || (string) $stored !== (string) $code ) {
		set_transient( $attempts_key, $attempts + 1, WTO_OTP_LOCKOUT_SECONDS );
		return array( 'success' => false, 'message' => __( 'کد وارد شده اشتباه یا منقضی است.', 'wto' ) );
	}

	delete_transient( $transient_key );
	delete_transient( $attempts_key );
	// v3.13.12: ریست کردن backoff progressive وقتی verify موفق است — تا کاربر صادق
	// دفعه بعد بدون cooldown طولانی بتواند درخواست بدهد.
	delete_transient( 'wto_otp_backoff_' . $mobile );

	$verified_key = 'wto_otp_verified_' . $context . '_' . $form_id . '_' . $mobile;
	set_transient( $verified_key, '1', WTO_OTP_VERIFIED_EXPIRY );
	return array( 'success' => true, 'message' => __( 'شماره موبایل تأیید شد.', 'wto' ) );
}

/**
 * Check if mobile is verified for this form (used on form validation).
 *
 * @param string $context  'gf' or 'elementor'.
 * @param string $form_id  Form id.
 * @param string $mobile   Normalized mobile.
 * @return bool
 */
function wto_otp_is_verified( $context, $form_id, $mobile ) {
	$key = 'wto_otp_verified_' . $context . '_' . $form_id . '_' . $mobile;
	return get_transient( $key ) === '1';
}

/**
 * AJAX: send OTP to mobile.
 */
function wto_otp_ajax_send() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wto_otp_send' ) ) {
		wp_send_json_error( array( 'message' => __( 'خطای امنیتی. لطفاً صفحه را رفرش کنید.', 'wto' ) ) );
		return;
	}
	$mobile = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) ) : '';
	$mobile = wto_otp_normalize_mobile( $mobile );
	if ( empty( $mobile ) || strlen( $mobile ) < 10 ) {
		wp_send_json_error( array( 'message' => __( 'شماره موبایل معتبر وارد کنید.', 'wto' ) ) );
		return;
	}
	$result = wto_otp_send_code( $mobile );
	if ( $result['success'] ) {
		wp_send_json_success( array( 'message' => $result['message'] ) );
	} else {
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
}

/**
 * AJAX: verify OTP and set verified transient.
 */
function wto_otp_ajax_verify() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wto_otp_verify' ) ) {
		wp_send_json_error( array( 'message' => __( 'خطای امنیتی. لطفاً صفحه را رفرش کنید.', 'wto' ) ) );
		return;
	}
	$mobile   = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) ) : '';
	$code     = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
	$context  = isset( $_POST['context'] ) ? sanitize_text_field( $_POST['context'] ) : '';
	$form_id  = isset( $_POST['form_id'] ) ? sanitize_text_field( $_POST['form_id'] ) : '';

	$mobile = wto_otp_normalize_mobile( $mobile );
	if ( empty( $mobile ) || empty( $code ) || ! in_array( $context, array( 'gf', 'elementor' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'پارامترهای ارسالی ناقص است.', 'wto' ) ) );
		return;
	}

	$result = wto_otp_verify_code( $mobile, $code, $context, $form_id );
	if ( $result['success'] ) {
		wp_send_json_success( array( 'message' => $result['message'] ) );
	} else {
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
}

add_action( 'wp_ajax_wto_otp_send', 'wto_otp_ajax_send' );
add_action( 'wp_ajax_nopriv_wto_otp_send', 'wto_otp_ajax_send' );
add_action( 'wp_ajax_wto_otp_verify', 'wto_otp_ajax_verify' );
add_action( 'wp_ajax_nopriv_wto_otp_verify', 'wto_otp_ajax_verify' );
