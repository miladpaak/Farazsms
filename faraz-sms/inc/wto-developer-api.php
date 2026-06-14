<?php
/**
 * Faraz SMS — Public Developer API.
 *
 * لایه‌ی پایدارِ عمومی برای برنامه‌نویسانِ سایر افزونه‌ها/قالب‌ها. این توابع تضمین می‌کنند
 * که حتی اگر پیاده‌سازیِ داخلیِ ما عوض شود، امضا و شکلِ خروجی ثابت بماند. کافی است افزونه‌ی
 * «فراز اس ام اس» فعال باشد و کاربر کلید دسترسی + خطِ ارسال را وارد کرده باشد.
 *
 * شکلِ خروجیِ توابعِ کنشی (ارسال/ذخیره):
 *   array( 'ok' => bool, 'message' => string, 'raw' => mixed )
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * کلید دسترسیِ پنل (Api-Key) که کاربر در تنظیمات وارد کرده.
 *
 * @return string کلید یا رشته‌ی خالی
 */
function farazsms_get_apikey() {
	if ( function_exists( 'wto_get_apikey' ) ) {
		return (string) wto_get_apikey();
	}
	return (string) get_option( 'wto_apikey', '' );
}

/**
 * خطِ ارسالِ پیش‌فرض.
 *
 * @return string
 */
function farazsms_get_sender() {
	return (string) get_option( 'wto_sender', '' );
}

/**
 * آیا افزونه آماده‌ی ارسال است؟ (کلید و خطِ ارسال هر دو تنظیم شده‌اند)
 *
 * @return bool
 */
function farazsms_is_ready() {
	return farazsms_get_apikey() !== '' && farazsms_get_sender() !== '';
}

/**
 * بارگذاریِ کلاسِ دفترچه‌تلفن/ارسالِ ساده در صورتِ نیاز.
 *
 * @return bool در دسترس بودن
 */
function farazsms_ensure_phonebook_api() {
	if ( class_exists( 'FarazSMS_Next_Phonebook_API' ) ) {
		return true;
	}
	if ( defined( 'FARAZSMS_NEXT_PLUGIN_DIR' ) ) {
		$file = FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-phonebook-api.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
	return class_exists( 'FarazSMS_Next_Phonebook_API' );
}

/**
 * ارسالِ پیامکِ ساده (غیرپترن).
 *
 * @param string|array $recipient یک شماره (09xxxxxxxxx) یا آرایه‌ای از شماره‌ها.
 * @param string       $message   متنِ پیام.
 * @param string       $sender    خطِ ارسال (خالی = خطِ پیش‌فرضِ تنظیمات).
 * @return array ok, message, raw
 */
function farazsms_send_sms( $recipient, $message, $sender = '' ) {
	$api_key = farazsms_get_apikey();
	$sender  = $sender !== '' ? $sender : farazsms_get_sender();

	if ( $api_key === '' || $sender === '' ) {
		return array( 'ok' => false, 'message' => __( 'کلید دسترسی یا خط ارسال تنظیم نشده است.', 'wto' ), 'raw' => null );
	}
	if ( trim( (string) $message ) === '' ) {
		return array( 'ok' => false, 'message' => __( 'متن پیام خالی است.', 'wto' ), 'raw' => null );
	}
	if ( ! farazsms_ensure_phonebook_api() ) {
		return array( 'ok' => false, 'message' => __( 'ماژول ارسال در دسترس نیست.', 'wto' ), 'raw' => null );
	}

	$recipients = is_array( $recipient ) ? array_values( $recipient ) : array( $recipient );
	$api        = new FarazSMS_Next_Phonebook_API();
	$res        = $api->send_simple_sms_to_recipients( $sender, $message, $recipients, $api_key );

	$ok = is_array( $res ) && isset( $res['status'] ) && $res['status'] === 'success';
	return array(
		'ok'      => $ok,
		'message' => $ok ? __( 'ارسال شد.', 'wto' ) : ( is_array( $res ) && isset( $res['message'] ) ? $res['message'] : __( 'خطا در ارسال.', 'wto' ) ),
		'raw'     => $res,
	);
}

/**
 * ارسالِ پیامکِ پترن (الگو) با متغیرها.
 *
 * @param string $recipient    شماره گیرنده (09xxxxxxxxx).
 * @param string $pattern_code کدِ پترن که در پنل ساخته‌اید.
 * @param array  $variables    آرایه‌ی متغیرها: array( 'var1' => 'مقدار', ... ).
 * @param string $sender       خطِ ارسال (خالی = خطِ پیش‌فرض).
 * @return array ok, message, raw
 */
function farazsms_send_pattern( $recipient, $pattern_code, $variables = array(), $sender = '' ) {
	if ( ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return array( 'ok' => false, 'message' => __( 'تابع ارسال پترن در دسترس نیست.', 'wto' ), 'raw' => null );
	}
	if ( $recipient === '' || $pattern_code === '' ) {
		return array( 'ok' => false, 'message' => __( 'شماره گیرنده یا کد پترن خالی است.', 'wto' ), 'raw' => null );
	}
	$res = wto_send_pattern_sms_raw( $recipient, $pattern_code, (array) $variables, $sender );
	$ok  = ( $res === 'success' || $res === true );
	return array(
		'ok'      => $ok,
		'message' => $ok ? __( 'ارسال شد.', 'wto' ) : ( is_string( $res ) ? $res : __( 'خطا در ارسال.', 'wto' ) ),
		'raw'     => $res,
	);
}

/**
 * افزودنِ یک شماره به دفترچه‌ی تلفنِ پنل.
 *
 * @param int    $phonebook_id شناسه‌ی دفترچه (از farazsms_phonebook_list).
 * @param string $name         نامِ مخاطب.
 * @param string $mobile       شماره موبایل (هر فرمتی — نرمال می‌شود).
 * @param string $prefix       man | woman | co | org.
 * @return array ok, message, raw
 */
function farazsms_phonebook_add( $phonebook_id, $name, $mobile, $prefix = 'man' ) {
	$api_key = farazsms_get_apikey();
	if ( $api_key === '' ) {
		return array( 'ok' => false, 'message' => __( 'کلید دسترسی تنظیم نشده است.', 'wto' ), 'raw' => null );
	}
	if ( ! farazsms_ensure_phonebook_api() ) {
		return array( 'ok' => false, 'message' => __( 'ماژول دفترچه تلفن در دسترس نیست.', 'wto' ), 'raw' => null );
	}
	$api = new FarazSMS_Next_Phonebook_API();
	$res = $api->add_contact( (int) $phonebook_id, $name, $mobile, $api_key, $prefix );
	$ok  = is_array( $res ) && ! empty( $res['success'] );
	return array(
		'ok'      => $ok,
		'message' => $ok ? __( 'ذخیره شد.', 'wto' ) : ( is_array( $res ) && isset( $res['error'] ) ? $res['error'] : __( 'خطا در ذخیره.', 'wto' ) ),
		'raw'     => $res,
	);
}

/**
 * فهرستِ دفترچه‌های تلفنِ پنل.
 *
 * @return array لیستی از array( 'id' => int|string, 'title' => string ) — خالی در صورتِ خطا.
 */
function farazsms_phonebook_list() {
	$api_key = farazsms_get_apikey();
	if ( $api_key === '' || ! farazsms_ensure_phonebook_api() ) {
		return array();
	}
	$api  = new FarazSMS_Next_Phonebook_API();
	$data = $api->get_phonebooks( $api_key );
	$out  = array();
	if ( ! is_array( $data ) ) {
		return $out;
	}
	$items = array();
	if ( isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) ) {
		$items = $data['data']['items'];
	} elseif ( isset( $data['data']['data'] ) && is_array( $data['data']['data'] ) ) {
		$items = $data['data']['data'];
	} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
		$items = $data['data'];
	}
	foreach ( $items as $pb ) {
		if ( ! is_array( $pb ) ) {
			continue;
		}
		$id = isset( $pb['id'] ) ? $pb['id'] : ( isset( $pb['phone_book_id'] ) ? $pb['phone_book_id'] : null );
		if ( $id === null ) {
			continue;
		}
		$title = isset( $pb['title'] ) ? $pb['title'] : ( isset( $pb['name'] ) ? $pb['name'] : '' );
		$out[] = array( 'id' => $id, 'title' => $title );
	}
	return $out;
}

/**
 * موجودیِ پنل (رشته‌ی فرمت‌شده به تومان) یا false در صورتِ خطا/مسدودی.
 *
 * @return string|false
 */
function farazsms_get_credit() {
	return function_exists( 'wto_get_credit' ) ? wto_get_credit() : false;
}
