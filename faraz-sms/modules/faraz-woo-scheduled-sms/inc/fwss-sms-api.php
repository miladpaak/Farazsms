<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

 

function fwss_tr_num( $str ) {
	$num_a = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.' );
	$key_a = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	return str_replace( $key_a, $num_a, $str );
}

// function fwss_check_if_credentials_is_valid( $uname, $pass ) {
// 	$body     = array(
// 		'username' => fwss_tr_num( $uname ),
// 		'password' => fwss_tr_num( $pass ),
// 	);
// 	$response = wp_remote_post( 'http://reg.ippanel.com/parent/farazsms', array(
// 			'method'      => 'POST',
// 			'headers'     => [
// 				'Content-Type' => 'application/json',
// 			],
// 			'data_format' => 'body',
// 			'body'        => json_encode( $body )
// 		)
// 	);
// 	if ( is_wp_error( $response ) ) {
// 		return false;
// 	}
// 	$response = json_decode( $response['body'] );
// 	if ( $response->message !== 1 ) {
// 		return true;
// 	}

// 	return false;
// }

function fwss_get_credit( $api_key ) {
	$response = wp_remote_get( 'https://api.iranpayamak.com/ws/v1/account/balance', array(
		'headers' => array(
			'Accept'  => 'application/json',
			'Api-Key' => $api_key,
		),
	) );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	// بررسی صحت و وجود داده‌های مورد انتظار
	if ( ! isset( $data['status'] ) || $data['status'] !== 'success' ) {
		return false;
	}

	if ( ! isset( $data['data']['balance_amount'] ) ) {
		return false;
	}

	// تبدیل به عدد صحیح و فرمت مناسب برای نمایش
	$credit = intval( $data['data']['balance_amount'] );

	return number_format( $credit );
}

/**
 * کلید API: اولویت با تنظیمات افزونه فراز اس ام اس، سپس fwss_apikey قدیمی.
 *
 * @return string
 */
function fwss_get_effective_apikey() {
	if ( function_exists( 'wto_get_apikey' ) ) {
		$k = wto_get_apikey();
		if ( ! empty( $k ) ) {
			return $k;
		}
	}
	return get_option( 'fwss_apikey', '' );
}

/**
 * Normalize mobile to local Iranian format where possible.
 *
 * @param string $mobile
 * @return string
 */
function fwss_normalize_mobile( $mobile ) {
	$mobile = fwss_tr_num( (string) $mobile );
	if ( function_exists( 'wto_normalize_phone' ) ) {
		return wto_normalize_phone( $mobile );
	}
	$mobile = preg_replace( '/[^\d\+]+/u', '', trim( $mobile ) );
	if ( strpos( $mobile, '+98' ) === 0 ) {
		$mobile = '0' . substr( $mobile, 3 );
	} elseif ( strpos( $mobile, '0098' ) === 0 ) {
		$mobile = '0' . substr( $mobile, 4 );
	} elseif ( preg_match( '/^98(9\d{9})$/', $mobile, $m ) ) {
		$mobile = '0' . $m[1];
	} elseif ( preg_match( '/^9\d{9}$/', $mobile ) ) {
		$mobile = '0' . $mobile;
	}
	return $mobile;
}

function fwss_send_scheduled_sms( $mobile, $date_to_send, $message ) {
	$sender = get_option( 'fwss_sender', 'PRO' );
	if ( $sender === '' ) {
		$sender = 'PRO';
	}
	$apikey = fwss_get_effective_apikey();
	if ( empty( $message ) || empty( $apikey ) ) {
		return;
	}
	$mobile = fwss_normalize_mobile( $mobile );

	$curl = curl_init();
	$body   = array(
		'text'            => $message,
		'line_number'     => $sender,
		'recipients'      => array( $mobile ),
		'number_format'   => 'english',
		'schedule'        => $date_to_send,
	);

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => 'https://api.iranpayamak.com/ws/v1/sms/simple',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 5,
			// Timeouts are CRITICAL at scale (10k stores): an unresponsive API would otherwise
			// pin PHP-FPM workers indefinitely. 15s total + 5s connect is a safe upper bound.
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => array(
				'Accept: application/json',
				'Content-Type: application/json',
				'Api-Key: ' . $apikey,
			),
		)
	);

	curl_exec( $curl );
	curl_close( $curl );
}


function fwss_send_ticket( $subject, $ticket ) {
	$uname = get_option( 'fwss_uname', '' );
	$pass  = get_option( 'fwss_pass', '' );
	if ( empty( $uname ) || empty( $pass ) || empty( $ticket ) ) {
		return;
	}

	$url = "https://ippanel.com/services.jspd";
	$param = array
	(
		'uname'            => $uname,
		'pass'             => $pass,
		'subject'          => $subject,
		'description'      => $ticket,
		'type'             => 'fiscal',
		'importance'       => 'low',
		'sms_notification' => 'yes',
		'op'               => 'ticketadd'
	);
	$handler = curl_init( $url );
	curl_setopt( $handler, CURLOPT_CUSTOMREQUEST, 'POST' );
	curl_setopt( $handler, CURLOPT_POSTFIELDS, $param );
	curl_setopt( $handler, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $handler, CURLOPT_CONNECTTIMEOUT, 5 );
	curl_setopt( $handler, CURLOPT_TIMEOUT, 15 );
	curl_setopt( $handler, CURLOPT_SSL_VERIFYPEER, true );
	curl_setopt( $handler, CURLOPT_SSL_VERIFYHOST, 2 );
	$response2 = curl_exec( $handler );
	curl_close( $handler );
	if ( $response2 === false ) {
		return false;
	}
	$decoded = json_decode( $response2, true );
	if ( ! is_array( $decoded ) || count( $decoded ) < 2 ) {
		return false;
	}
	$res_data = $decoded[1] ?? null;
	if ( ! is_numeric( $res_data ) ) {
		return $res_data;
	}
	return true;
}
