<?php
/**
 * گزارشات ارسال پیامک — Send Requests reports
 *
 * این فایل صفحه‌ی «گزارشات ارسال پیامک» را در پیشخوان وردپرس اضافه می‌کند.
 * داده‌ها از API سامانه فراز اس‌ام‌اس خوانده می‌شود:
 *
 *   GET https://api.iranpayamak.com/ws/v1/send_request
 *   GET https://api.iranpayamak.com/ws/v1/send_request/{id}
 *   GET https://api.iranpayamak.com/ws/v1/send_request/{id}/items
 *
 * احراز هویت با header `Api-Key`. مقدار آن از تنظیمات افزونه (option `wto_apikey`)
 * از طریق تابع `wto_get_apikey()` خوانده می‌شود.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * ثبت زیرمنو «گزارشات ارسال پیامک» — درست بالای «بازخورد».
 *
 * بازخورد در priority 999 ثبت می‌شود؛ گزارشات را در priority 990 می‌گذاریم تا
 * در فهرست بالای بازخورد ظاهر شود.
 */
add_action( 'admin_menu', 'wto_register_send_reports_submenu', 990 );
function wto_register_send_reports_submenu() {
	add_submenu_page(
		'farazwto',
		__( 'گزارشات ارسال پیامک', 'wto' ),
		__( 'گزارشات ارسال پیامک', 'wto' ),
		'manage_options',
		'farazwto-reports',
		'wto_render_send_reports_page'
	);
}

/**
 * Persian labels for send_request status codes.
 *
 * @return array<string,string>
 */
function wto_send_reports_request_statuses() {
	return array(
		''                     => __( 'همه وضعیت‌ها', 'wto' ),
		'init'                 => __( 'آغازی', 'wto' ),
		'pending-approval'     => __( 'در انتظار تأیید', 'wto' ),
		'insufficient-balance' => __( 'اعتبار ناکافی', 'wto' ),
		'cancelled'            => __( 'لغو شده', 'wto' ),
		'rejected'             => __( 'رد شده', 'wto' ),
		'in-queue'             => __( 'در صف ارسال', 'wto' ),
		'sent'                 => __( 'ارسال شده', 'wto' ),
	);
}

/**
 * Persian labels for send_request item delivery statuses.
 *
 * @return array<string,string>
 */
function wto_send_reports_item_statuses() {
	return array(
		''                       => __( 'همه وضعیت‌ها', 'wto' ),
		'not-started'            => __( 'شروع نشده', 'wto' ),
		'in-queue'               => __( 'در صف ارسال', 'wto' ),
		'sent'                   => __( 'ارسال شده', 'wto' ),
		'send-failure'           => __( 'ناموفق در ارسال', 'wto' ),
		'delivered'              => __( 'تحویل داده شده', 'wto' ),
		'delivery-failure'       => __( 'عدم تحویل', 'wto' ),
		'delivery-undetermined'  => __( 'وضعیت نامشخص', 'wto' ),
		'system-error'           => __( 'خطای سیستم', 'wto' ),
		'blacklist'              => __( 'لیست سیاه', 'wto' ),
	);
}

/**
 * Color-class for a status badge — used as a `wto-status-<key>` CSS class.
 *
 * @param string $status
 * @return string
 */
function wto_send_reports_status_class( $status ) {
	$map = array(
		'sent'                 => 'success',
		'delivered'            => 'success',
		'in-queue'             => 'info',
		'init'                 => 'info',
		'pending-approval'     => 'warning',
		'not-started'          => 'muted',
		'delivery-undetermined'=> 'muted',
		'rejected'             => 'danger',
		'cancelled'            => 'danger',
		'insufficient-balance' => 'danger',
		'send-failure'         => 'danger',
		'delivery-failure'     => 'danger',
		'system-error'         => 'danger',
		'blacklist'            => 'danger',
	);
	return isset( $map[ $status ] ) ? $map[ $status ] : 'muted';
}

/**
 * Low-level HTTP GET to the FarazSMS reports API using raw cURL (per the docs).
 *
 * We still go through `wto_remote_get_with_fallback` when available because it
 * applies the hardened timeouts and TLS verification used elsewhere in the
 * plugin. When that wrapper is not present we fall back to a direct curl_init
 * with the same defensive options.
 *
 * @param string $endpoint  Path relative to the base URL, e.g. "send_request" or "send_request/123/items".
 * @param array  $query     Optional query params.
 * @return array{success:bool, data?:mixed, raw?:string, url?:string, message?:string, http_code?:int}
 */
function wto_send_reports_api_get( $endpoint, $query = array() ) {
	$api_key = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( $api_key === '' ) {
		return array(
			'success' => false,
			'message' => __( 'کلید دسترسی (Api-Key) در تنظیمات افزونه وارد نشده است.', 'wto' ),
		);
	}

	$base_url = 'https://api.iranpayamak.com/ws/v1/';
	$url      = $base_url . ltrim( (string) $endpoint, '/' );
	if ( ! empty( $query ) ) {
		$clean = array();
		foreach ( $query as $k => $v ) {
			if ( $v === '' || $v === null ) {
				continue;
			}
			$clean[ $k ] = $v;
		}
		if ( ! empty( $clean ) ) {
			$url = add_query_arg( $clean, $url );
		}
	}

	$headers = array(
		'Accept'  => 'application/json',
		'Api-Key' => $api_key,
	);

	$body      = '';
	$http_code = 0;

	// مسیر اصلی: استفاده از wrapper پلاگین که timeout و TLS verify امن دارد.
	if ( function_exists( 'wto_remote_get_with_fallback' ) ) {
		$response = wto_remote_get_with_fallback( $url, array(
			'headers' => $headers,
			'timeout' => 30,
		) );
		if ( is_wp_error( $response ) ) {
			$em = $response->get_error_message();
			if ( stripos( $em, 'timed out' ) !== false || stripos( $em, 'timeout' ) !== false ) {
				$em = 'سرورِ گزارش‌ها در زمانِ مقرر پاسخ نداد (timeout). معمولاً به‌خاطرِ کندیِ موقتِ شبکه یا مسدودسازیِ خروجیِ سرور به api.iranpayamak.com است؛ چند لحظه بعد دوباره تلاش کنید.';
			}
			return array(
				'success' => false,
				'url'     => $url,
				'message' => $em,
			);
		}
		$body      = wp_remote_retrieve_body( $response );
		$http_code = (int) wp_remote_retrieve_response_code( $response );
	} else {
		// Fallback: cURL خام با تنظیمات دفاعی.
		if ( ! function_exists( 'curl_init' ) ) {
			return array(
				'success' => false,
				'url'     => $url,
				'message' => __( 'افزونه cURL در سرور فعال نیست.', 'wto' ),
			);
		}
		$header_lines = array();
		foreach ( $headers as $hk => $hv ) {
			$header_lines[] = $hk . ': ' . $hv;
		}
		$curl = curl_init();
		curl_setopt_array( $curl, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => $header_lines,
		) );
		$body      = curl_exec( $curl );
		$http_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$curl_err  = curl_errno( $curl ) ? curl_error( $curl ) : '';
		curl_close( $curl );
		if ( $body === false ) {
			return array(
				'success' => false,
				'url'     => $url,
				'message' => $curl_err !== '' ? $curl_err : __( 'خطا در ارتباط با سرور فراز اس‌ام‌اس.', 'wto' ),
			);
		}
	}

	$decoded = json_decode( $body, true );

	$result = array(
		'success'   => false,
		'http_code' => $http_code,
		'url'       => $url,
		'raw'       => is_string( $body ) ? $body : '',
	);

	if ( ! is_array( $decoded ) ) {
		$result['message'] = sprintf(
			/* translators: %d HTTP status */
			__( 'پاسخ سرور قابل پردازش نیست (کد %d).', 'wto' ),
			$http_code
		);
		return $result;
	}

	$result['decoded'] = $decoded;

	$is_error = ( isset( $decoded['status'] ) && $decoded['status'] === 'error' ) || ( $http_code !== 0 && $http_code >= 400 );
	if ( $is_error ) {
		$msg = '';
		if ( isset( $decoded['messages'] ) ) {
			if ( is_string( $decoded['messages'] ) ) {
				$msg = $decoded['messages'];
			} elseif ( is_array( $decoded['messages'] ) ) {
				$flat = array();
				array_walk_recursive( $decoded['messages'], function ( $v ) use ( &$flat ) {
					if ( is_scalar( $v ) ) {
						$flat[] = (string) $v;
					}
				} );
				$msg = implode( '، ', $flat );
			}
		} elseif ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			$msg = $decoded['message'];
		}
		if ( $msg === '' ) {
			/* translators: %d HTTP status */
			$msg = sprintf( __( 'خطای API (کد %d).', 'wto' ), $http_code );
		}
		$result['message'] = $msg;
		return $result;
	}

	$result['success'] = true;
	$result['data']    = isset( $decoded['data'] ) ? $decoded['data'] : $decoded;
	return $result;
}

/**
 * Convert a Gregorian date string (anything strtotime() understands) to Jalali (Shamsi)
 * with a Persian-numerals output, e.g. "۱۴۰۴/۰۳/۱۰ ۱۴:۳۰".
 *
 * Returns the original input unchanged when it cannot be parsed (defensive — so
 * that a date in an unexpected format still renders something readable).
 *
 * @param string $input
 * @param bool   $with_time
 * @return string
 */
function wto_send_reports_to_jalali( $input, $with_time = true ) {
	$input = (string) $input;
	if ( $input === '' ) {
		return '';
	}
	// Numeric input → assume unix timestamp.
	if ( is_numeric( $input ) ) {
		$ts = (int) $input;
	} else {
		$ts = strtotime( $input );
		if ( $ts === false ) {
			return $input;
		}
	}

	$site_tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
	$dt = new DateTime( '@' . $ts );
	$dt->setTimezone( $site_tz );
	$gy = (int) $dt->format( 'Y' );
	$gm = (int) $dt->format( 'n' );
	$gd = (int) $dt->format( 'j' );
	$time = $dt->format( 'H:i' );

	// Well-known Gregorian → Jalali conversion (Behrouz Parsi algorithm).
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days  = 355666 + ( 365 * $gy ) + (int) ( ( $gy2 + 3 ) / 4 ) - (int) ( ( $gy2 + 99 ) / 100 ) + (int) ( ( $gy2 + 399 ) / 400 ) + $gd + $g_d_m[ $gm - 1 ];
	$jy    = -1595 + ( 33 * (int) ( $days / 12053 ) );
	$days %= 12053;
	$jy   += 4 * (int) ( $days / 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy   += (int) ( ( $days - 1 ) / 365 );
		$days  = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + (int) ( $days / 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + (int) ( ( $days - 186 ) / 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}

	$formatted = sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	if ( $with_time ) {
		$formatted .= ' ' . $time;
	}

	// Convert ASCII digits to Persian digits — matches Persian UI elsewhere in the plugin.
	$ascii   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	return str_replace( $ascii, $persian, $formatted );
}

/**
 * Recursively search a value across many candidate keys (case-insensitive).
 * Walks nested arrays up to $max_depth levels deep. The list endpoint and the
 * detail endpoint of the FarazSMS reports API return DIFFERENT shapes for the
 * same data — the list endpoint often nests fields like `text` and `created_at`
 * inside an inner `sms` / `payload` / `request` object — so we have to look
 * deeper, not just at the top level.
 *
 * @param array    $row
 * @param string[] $keys
 * @param int      $max_depth
 * @return string
 */
function wto_send_reports_find( $row, $keys, $max_depth = 3 ) {
	$found = wto_send_reports_pick( $row, $keys );
	if ( $found !== '' ) {
		return $found;
	}
	if ( $max_depth <= 0 || ! is_array( $row ) ) {
		return '';
	}
	foreach ( $row as $v ) {
		if ( is_array( $v ) ) {
			$sub = wto_send_reports_find( $v, $keys, $max_depth - 1 );
			if ( $sub !== '' ) {
				return $sub;
			}
		}
	}
	return '';
}

/**
 * Find the pattern code in a send-request row.
 *
 * Pattern SMS responses usually carry a code (string or numeric) that points to
 * a template stored separately under `/ws/v1/patterns/{code}`. We look across
 * many candidate names and accept the code at any depth.
 *
 * @param array $row
 * @return string  Pattern code or empty string.
 */
function wto_send_reports_pick_pattern_code( $row ) {
	if ( ! is_array( $row ) ) {
		return '';
	}
	$candidates = array(
		'pattern_code', 'patternCode', 'patterncode',
		'template_code', 'templateCode',
		'pattern_id', 'patternId', 'patternid',
	);
	$direct = wto_send_reports_pick( $row, $candidates );
	if ( $direct !== '' ) {
		return $direct;
	}
	// Look inside nested objects like `pattern: {code: ...}` or `template: {code: ...}`.
	$lower_map = array();
	foreach ( $row as $k => $v ) {
		$lower_map[ strtolower( (string) $k ) ] = $v;
	}
	foreach ( array( 'pattern', 'template' ) as $parent ) {
		if ( isset( $lower_map[ $parent ] ) && is_array( $lower_map[ $parent ] ) ) {
			$code = wto_send_reports_pick( $lower_map[ $parent ], array( 'code', 'id', 'value' ) );
			if ( $code !== '' ) {
				return $code;
			}
		}
	}
	// Recurse one extra level for `payload.pattern.code` shapes.
	foreach ( $row as $v ) {
		if ( is_array( $v ) ) {
			$nested = wto_send_reports_pick_pattern_code( $v );
			if ( $nested !== '' ) {
				return $nested;
			}
		}
	}
	return '';
}

/**
 * Fetch a pattern template by code, with a 1-hour transient cache.
 *
 * The list endpoint for pattern-type SMS often returns only the pattern code,
 * not the actual message text. To still show something meaningful in the
 * report list we look the template up via `/ws/v1/patterns/{code}` once and
 * cache the result so subsequent page-loads are free.
 *
 * @param string $code
 * @return string  Template text or empty string on failure.
 */
function wto_send_reports_fetch_pattern_text( $code ) {
	$code = trim( (string) $code );
	if ( $code === '' ) {
		return '';
	}
	$cache_key = 'wto_pattern_tpl_' . md5( $code );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return (string) $cached;
	}
	$response = wto_send_reports_api_get( 'patterns/' . rawurlencode( $code ) );
	$text     = '';
	if ( ! empty( $response['success'] ) ) {
		$data = isset( $response['data'] ) ? $response['data'] : array();
		// Unwrap nested response shapes.
		if ( is_array( $data ) ) {
			if ( isset( $data['item'] ) && is_array( $data['item'] ) ) {
				$data = $data['item'];
			} elseif ( isset( $data['pattern'] ) && is_array( $data['pattern'] ) ) {
				$data = $data['pattern'];
			}
			$text = wto_send_reports_find( $data, array(
				'text', 'message', 'body', 'content', 'template',
				'pattern_text', 'patternText', 'pattern_body',
			) );
		}
	}
	// Cache even an empty result for 5 minutes so a transient outage doesn't
	// hammer the API on every page-load — but cache successful results longer.
	set_transient( $cache_key, $text, $text !== '' ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	return $text;
}

/**
 * Recursive variant of pick_count that also looks inside nested arrays.
 *
 * @param array    $row
 * @param string[] $keys
 * @param int      $max_depth
 * @return int
 */
function wto_send_reports_find_count( $row, $keys, $max_depth = 3 ) {
	$count = wto_send_reports_pick_count( $row, $keys );
	if ( $count > 0 ) {
		return $count;
	}
	if ( $max_depth <= 0 || ! is_array( $row ) ) {
		return 0;
	}
	foreach ( $row as $v ) {
		if ( is_array( $v ) ) {
			$c = wto_send_reports_find_count( $v, $keys, $max_depth - 1 );
			if ( $c > 0 ) {
				return $c;
			}
		}
	}
	return 0;
}

/**
 * Look for a "sender line / phone number" value in the row. Different SMS providers
 * use different shapes — sometimes a flat string, sometimes a nested object that
 * contains both an ID and a phone-number sub-field. Recursive variant — walks
 * nested objects but still avoids the `id` sub-key.
 *
 * @param array $row
 * @param int   $max_depth
 * @return string
 */
function wto_send_reports_pick_line_number( $row, $max_depth = 3 ) {
	if ( ! is_array( $row ) ) {
		return '';
	}
	$lower_map = array();
	foreach ( $row as $k => $v ) {
		$lower_map[ strtolower( (string) $k ) ] = $v;
	}

	// Direct candidates that should hold the human-visible phone string.
	$direct_keys = array(
		'line_number', 'linenumber', 'sender_number', 'sendernumber',
		'sender', 'from', 'phone_number', 'phonenumber',
	);
	foreach ( $direct_keys as $k ) {
		if ( array_key_exists( $k, $lower_map ) && is_scalar( $lower_map[ $k ] ) ) {
			$val = trim( (string) $lower_map[ $k ] );
			if ( $val !== '' ) {
				return $val;
			}
		}
	}

	// Nested objects — `line: { id: 123, number: "3000xxx" }` is the common shape.
	foreach ( array( 'line', 'sender' ) as $k ) {
		if ( ! isset( $lower_map[ $k ] ) || ! is_array( $lower_map[ $k ] ) ) {
			continue;
		}
		$sub_lower = array();
		foreach ( $lower_map[ $k ] as $sk => $sv ) {
			$sub_lower[ strtolower( (string) $sk ) ] = $sv;
		}
		$sub_phone_keys = array( 'number', 'phone', 'phone_number', 'phonenumber', 'mobile', 'display', 'value', 'title', 'name' );
		foreach ( $sub_phone_keys as $sk ) {
			if ( array_key_exists( $sk, $sub_lower ) && is_scalar( $sub_lower[ $sk ] ) ) {
				$val = trim( (string) $sub_lower[ $sk ] );
				if ( $val !== '' ) {
					return $val;
				}
			}
		}
	}

	// Scalar `line` (e.g. when it's just a string).
	if ( isset( $lower_map['line'] ) && is_scalar( $lower_map['line'] ) ) {
		$val = trim( (string) $lower_map['line'] );
		if ( $val !== '' ) {
			return $val;
		}
	}

	// Recurse: walk every nested array in the row in case the line info is in
	// `sms.line.number`, `payload.sender.phone`, etc.
	if ( $max_depth > 0 ) {
		foreach ( $row as $v ) {
			if ( is_array( $v ) ) {
				$nested = wto_send_reports_pick_line_number( $v, $max_depth - 1 );
				if ( $nested !== '' ) {
					return $nested;
				}
			}
		}
	}
	return '';
}

/**
 * Look for a "recipient phone number" value. The list endpoint usually returns
 * a `recipients` array of strings or a numeric `count`; the items endpoint
 * returns one row per recipient where the phone may be at the top level or
 * nested. We try every reasonable name + nested fields like
 * `recipient.phone` / `recipient.mobile` / `to.number`.
 *
 * @param array $row
 * @param int   $max_depth
 * @return string
 */
function wto_send_reports_pick_recipient( $row, $max_depth = 3 ) {
	if ( ! is_array( $row ) ) {
		return '';
	}
	$direct_candidates = array(
		'recipient', 'recipient_number', 'recipientNumber', 'recipient_phone', 'recipientPhone',
		'mobile', 'mobile_number', 'mobileNumber', 'phone', 'phone_number', 'phoneNumber',
		'msisdn', 'to', 'destination', 'destination_number', 'destinationNumber',
		'dst', 'dest', 'dest_mobile', 'number',
	);
	$found = wto_send_reports_pick( $row, $direct_candidates );
	if ( $found !== '' && wto_send_reports_looks_like_phone( $found ) ) {
		return $found;
	}

	// Some APIs nest the phone under a recipient/to object.
	$lower_map = array();
	foreach ( $row as $k => $v ) {
		$lower_map[ strtolower( (string) $k ) ] = $v;
	}
	foreach ( array( 'recipient', 'to', 'destination', 'target' ) as $parent ) {
		if ( isset( $lower_map[ $parent ] ) && is_array( $lower_map[ $parent ] ) ) {
			$nested = wto_send_reports_pick( $lower_map[ $parent ], array( 'number', 'phone', 'mobile', 'msisdn', 'value', 'display' ) );
			if ( $nested !== '' && wto_send_reports_looks_like_phone( $nested ) ) {
				return $nested;
			}
		}
	}

	// Last resort: recurse through every nested array and return the first
	// value that looks like a phone number.
	if ( $max_depth > 0 ) {
		foreach ( $row as $v ) {
			if ( is_array( $v ) ) {
				$nested = wto_send_reports_pick_recipient( $v, $max_depth - 1 );
				if ( $nested !== '' ) {
					return $nested;
				}
			}
		}
	}

	// Final fallback: scan all scalar values for anything that looks like a phone.
	foreach ( $row as $v ) {
		if ( is_scalar( $v ) && wto_send_reports_looks_like_phone( (string) $v ) ) {
			return (string) $v;
		}
	}
	return '';
}

/**
 * Heuristic: does the string look like a phone number?
 *
 * Accepts strings that are mostly digits and 8-15 characters long after
 * stripping spaces, dashes, plus signs, and leading 98/0.
 *
 * @param string $s
 * @return bool
 */
function wto_send_reports_looks_like_phone( $s ) {
	$digits = preg_replace( '/\D+/', '', (string) $s );
	if ( $digits === '' ) {
		return false;
	}
	$len = strlen( $digits );
	return $len >= 8 && $len <= 15;
}

/**
 * Look up a value across many candidate keys (case-insensitive). Walks nested
 * arrays once. Returns the first non-empty scalar found, or '' otherwise.
 *
 * This is intentionally loose: the Send Requests API documentation does not
 * pin down the exact field names (it reuses the Phonebook schema as placeholder),
 * so we accept several common naming styles (snake_case, camelCase, alt names).
 *
 * @param array        $row
 * @param string[]     $keys
 * @return string
 */
function wto_send_reports_pick( $row, $keys ) {
	if ( ! is_array( $row ) ) {
		return '';
	}
	$lower_map = array();
	foreach ( $row as $k => $v ) {
		$lower_map[ strtolower( (string) $k ) ] = $v;
	}
	foreach ( $keys as $candidate ) {
		$c = strtolower( $candidate );
		if ( array_key_exists( $c, $lower_map ) ) {
			$v = $lower_map[ $c ];
			if ( is_scalar( $v ) && (string) $v !== '' ) {
				return (string) $v;
			}
			if ( is_array( $v ) ) {
				// Walk one level deep for things like {line:{number:'3000xxx'}}.
				foreach ( $v as $vv ) {
					if ( is_scalar( $vv ) && (string) $vv !== '' ) {
						return (string) $vv;
					}
				}
			}
		}
	}
	return '';
}

/**
 * Count items for a recipients-style field that may be int, array, or nested.
 */
function wto_send_reports_pick_count( $row, $keys ) {
	if ( ! is_array( $row ) ) {
		return 0;
	}
	$lower_map = array();
	foreach ( $row as $k => $v ) {
		$lower_map[ strtolower( (string) $k ) ] = $v;
	}
	foreach ( $keys as $candidate ) {
		$c = strtolower( $candidate );
		if ( array_key_exists( $c, $lower_map ) ) {
			$v = $lower_map[ $c ];
			if ( is_array( $v ) ) {
				return count( $v );
			}
			if ( is_numeric( $v ) ) {
				return (int) $v;
			}
		}
	}
	return 0;
}

/**
 * Auto-diagnostic: dump just the keys of the first row when items exist but
 * key fields (text or date) are systematically missing. This is much smaller
 * than the full debug=1 dump but precise enough to identify mis-named fields.
 *
 * @param array $rows
 * @param array $missing  Names of fields we tried to find but failed.
 */
function wto_send_reports_render_keys_hint( $rows, $missing ) {
	if ( empty( $rows ) || empty( $missing ) ) {
		return;
	}
	$first = $rows[0];
	if ( ! is_array( $first ) ) {
		return;
	}
	// Build a recursive map of keys → type/example up to depth 2.
	$snapshot = wto_send_reports_keys_snapshot( $first, 2 );
	?>
	<details class="wto-reports-keys-hint">
		<summary>
			<?php esc_html_e( '⚠ بعضی فیلدها در داده‌های API پیدا نشد — کلیک کنید تا کلیدهای ردیف اول را ببینید', 'wto' ); ?>
		</summary>
		<p>
			<?php esc_html_e( 'این فیلدها در نسخه فعلی پیدا نشدند:', 'wto' ); ?>
			<strong><?php echo esc_html( implode( '، ', $missing ) ); ?></strong>
		</p>
		<p>
			<?php esc_html_e( 'کلیدهای ردیف اول پاسخ API (لطفاً برای پشتیبانی کپی کنید):', 'wto' ); ?>
		</p>
		<pre class="wto-reports-pre wto-reports-debug-pre"><?php echo esc_html( $snapshot ); ?></pre>
	</details>
	<?php
}

/**
 * Build a textual snapshot of an array's keys and value types, up to N levels deep.
 * Used by the keys-hint diagnostic — same info as a full JSON dump but smaller.
 *
 * @param mixed $value
 * @param int   $depth
 * @param int   $indent
 * @return string
 */
function wto_send_reports_keys_snapshot( $value, $depth, $indent = 0 ) {
	$pad = str_repeat( '  ', $indent );
	if ( is_array( $value ) ) {
		if ( $depth <= 0 ) {
			return $pad . '{ ... ' . count( $value ) . ' keys ... }';
		}
		$lines = array();
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$lines[] = $pad . $k . ': {';
				$lines[] = wto_send_reports_keys_snapshot( $v, $depth - 1, $indent + 1 );
				$lines[] = $pad . '}';
			} else {
				$preview = '';
				if ( is_scalar( $v ) ) {
					$sv = (string) $v;
					if ( mb_strlen( $sv ) > 60 ) {
						$sv = mb_substr( $sv, 0, 60 ) . '…';
					}
					$preview = ' = ' . $sv;
				} elseif ( is_null( $v ) ) {
					$preview = ' = null';
				}
				$lines[] = $pad . $k . $preview;
			}
		}
		return implode( "\n", $lines );
	}
	return $pad . (string) $value;
}

/**
 * Render the optional debug panel (URL + raw JSON) when ?debug=1 is on the URL.
 *
 * @param string $title
 * @param array  $response  Result returned from wto_send_reports_api_get().
 */
function wto_send_reports_maybe_render_debug( $title, $response ) {
	$debug = isset( $_GET['debug'] ) ? (int) $_GET['debug'] : 0;
	if ( ! $debug ) {
		return;
	}
	$raw     = isset( $response['raw'] ) ? (string) $response['raw'] : '';
	$decoded = isset( $response['decoded'] ) ? $response['decoded'] : null;
	$pretty  = $decoded !== null ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : $raw;
	?>
	<details class="wto-reports-debug" open>
		<summary><?php echo esc_html( $title ); ?></summary>
		<div class="wto-reports-debug-meta">
			<strong><?php esc_html_e( 'URL درخواست:', 'wto' ); ?></strong>
			<code><?php echo esc_html( isset( $response['url'] ) ? $response['url'] : '' ); ?></code><br>
			<strong><?php esc_html_e( 'کد HTTP:', 'wto' ); ?></strong>
			<code><?php echo esc_html( (string) ( $response['http_code'] ?? 0 ) ); ?></code>
		</div>
		<pre class="wto-reports-pre wto-reports-debug-pre"><?php echo esc_html( (string) $pretty ); ?></pre>
	</details>
	<?php
}

/**
 * Render entry point — chooses between list / detail view based on `view` param.
 */
function wto_render_send_reports_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی غیرمجاز.', 'wto' ) );
	}

	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
	// v3.17.6: تب افقی — گزارشات | وضعیت تحویل (DLR)
	$tab = isset( $_GET['tt'] ) ? sanitize_key( wp_unslash( $_GET['tt'] ) ) : 'reports';
	if ( ! in_array( $tab, array( 'reports', 'dlr' ), true ) ) {
		$tab = 'reports';
	}

	echo '<section class="wrapper wto-reports-wrapper">';
	wto_send_reports_render_header();

	// تب pill-style
	$tab_reports_url = admin_url( 'admin.php?page=farazwto-reports&tt=reports' );
	$tab_dlr_url     = admin_url( 'admin.php?page=farazwto-reports&tt=dlr' );
	?>
	<div style="display:flex; gap:8px; flex-wrap:wrap; margin:6px 0 18px; direction:rtl;">
		<a href="<?php echo esc_url( $tab_reports_url ); ?>" style="
			background:<?php echo $tab === 'reports' ? '#0f172a' : '#fff'; ?>;
			color:<?php echo $tab === 'reports' ? '#fff' : '#475569'; ?>;
			border:1px solid <?php echo $tab === 'reports' ? '#0f172a' : '#cbd5e1'; ?>;
			padding:9px 18px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;
			box-shadow:<?php echo $tab === 'reports' ? '0 4px 12px rgba(15,23,42,0.22)' : 'none'; ?>;">
			📊 گزارشات ارسال پیامک
		</a>
		<a href="<?php echo esc_url( $tab_dlr_url ); ?>" style="
			background:<?php echo $tab === 'dlr' ? '#0f172a' : '#fff'; ?>;
			color:<?php echo $tab === 'dlr' ? '#fff' : '#475569'; ?>;
			border:1px solid <?php echo $tab === 'dlr' ? '#0f172a' : '#cbd5e1'; ?>;
			padding:9px 18px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;
			box-shadow:<?php echo $tab === 'dlr' ? '0 4px 12px rgba(15,23,42,0.22)' : 'none'; ?>;">
			📬 وضعیت تحویل (DLR)
		</a>
	</div>
	<?php

	if ( $tab === 'dlr' ) {
		// embed DLR content
		if ( function_exists( 'wto_dlr_render_content' ) ) {
			wto_dlr_render_content();
		} else {
			echo '<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px; border-radius:8px;">ماژول DLR در دسترس نیست.</div>';
		}
	} elseif ( $view === 'detail' ) {
		wto_send_reports_render_detail();
	} else {
		wto_send_reports_render_list();
	}

	wto_send_reports_render_inline_styles();
	echo '</section>';
}

/**
 * Page header (same look as other admin pages — logo + balance).
 */
function wto_send_reports_render_header() {
	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : '';
	?>
	<div id="wto_header">
		<div>
			<a href="https://farazsms.com" target="_blank" rel="noopener">
				<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt="<?php esc_attr_e( 'فراز اس‌ام‌اس', 'wto' ); ?>">
			</a>
		</div>
		<?php if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) : ?>
			<div id="wto_account_info">
				<div class="wto_credit_amount">
					<span><?php esc_html_e( 'میزان اعتبار: ', 'wto' ); ?></span>
					<?php echo esc_html( (string) wto_get_credit() ); ?>
					<span> <?php esc_html_e( 'تومان', 'wto' ); ?></span>
				</div>
				<?php if ( function_exists( 'wto_render_profile_block' ) ) { wto_render_profile_block(); } ?>
			</div>
		<?php endif; ?>
	</div>
	<h1 class="wto-reports-title"><?php esc_html_e( 'گزارشات ارسال پیامک', 'wto' ); ?></h1>
	<?php
	if ( ! isset( $_GET['debug'] ) ) {
		$debug_url = add_query_arg(
			array_merge(
				array_map( function ( $v ) { return is_array( $v ) ? '' : sanitize_text_field( wp_unslash( (string) $v ) ); }, $_GET ),
				array( 'debug' => '1' )
			),
			admin_url( 'admin.php' )
		);
		?>
		<p class="wto-reports-debug-hint">
			<?php esc_html_e( 'اگر داده‌ها به‌درستی نمایش داده نمی‌شود، با', 'wto' ); ?>
			<a href="<?php echo esc_url( $debug_url ); ?>"><?php esc_html_e( 'فعال کردن حالت دیباگ', 'wto' ); ?></a>
			<?php esc_html_e( 'می‌توانید پاسخ خام API را ببینید و برای پشتیبانی ارسال کنید.', 'wto' ); ?>
		</p>
		<?php
	}
}

/**
 * Render the list of send_requests with filters + pagination.
 */
function wto_send_reports_render_list() {
	$page   = isset( $_GET['paged'] )  ? max( 1, (int) $_GET['paged'] )  : 1;
	$limit  = isset( $_GET['limit'] )  ? max( 5, min( 100, (int) $_GET['limit'] ) ) : 20;
	$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
	$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

	$valid_statuses = array_keys( wto_send_reports_request_statuses() );
	if ( ! in_array( $status, $valid_statuses, true ) ) {
		$status = '';
	}

	$response = wto_send_reports_api_get( 'send_request', array(
		'page'   => $page,
		'limit'  => $limit,
		'status' => $status,
		'search' => $search,
	) );

	?>
	<form method="get" class="wto-reports-filters">
		<input type="hidden" name="page" value="farazwto-reports">

		<label>
			<span><?php esc_html_e( 'جستجو:', 'wto' ); ?></span>
			<input type="text" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'جستجو در شناسه یا متن', 'wto' ); ?>">
		</label>

		<label>
			<span><?php esc_html_e( 'وضعیت:', 'wto' ); ?></span>
			<select name="status">
				<?php foreach ( wto_send_reports_request_statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>
			<span><?php esc_html_e( 'تعداد در صفحه:', 'wto' ); ?></span>
			<select name="limit">
				<?php foreach ( array( 10, 20, 50, 100 ) as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $limit, $opt ); ?>>
						<?php echo esc_html( (string) $opt ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<button type="submit" class="button button-primary"><?php esc_html_e( 'اعمال فیلتر', 'wto' ); ?></button>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-reports' ) ); ?>"><?php esc_html_e( 'پاک‌سازی', 'wto' ); ?></a>
	</form>

	<?php if ( ! $response['success'] ) : ?>
		<div class="notice notice-error wto-reports-notice">
			<p><?php echo esc_html( $response['message'] ?? __( 'خطای نامشخص.', 'wto' ) ); ?></p>
		</div>
		<?php
		wto_send_reports_maybe_render_debug( __( 'پاسخ خام API (debug)', 'wto' ), $response );
		return;
	endif;

	// انعطاف نسبت به شکل پاسخ: گاهی data خودش یک آرایه از آیتم‌هاست (بدون پوشش items).
	$data  = isset( $response['data'] ) ? $response['data'] : array();
	$items = array();
	if ( is_array( $data ) ) {
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$items = $data['items'];
		} elseif ( isset( $data['list'] ) && is_array( $data['list'] ) ) {
			$items = $data['list'];
		} elseif ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
			$items = $data['rows'];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$items = $data['data'];
		} elseif ( $data && array_values( $data ) === $data ) {
			// pure list (numeric-keyed array)
			$items = $data;
		}
	}

	$current_page = 0;
	$total_pages  = 0;
	$total_items  = 0;
	if ( is_array( $data ) ) {
		foreach ( array( 'currentPage', 'current_page', 'page' ) as $k ) {
			if ( isset( $data[ $k ] ) ) { $current_page = (int) $data[ $k ]; break; }
		}
		foreach ( array( 'totalPages', 'total_pages', 'pages' ) as $k ) {
			if ( isset( $data[ $k ] ) ) { $total_pages = (int) $data[ $k ]; break; }
		}
		foreach ( array( 'totalItems', 'total_items', 'total', 'count' ) as $k ) {
			if ( isset( $data[ $k ] ) ) { $total_items = (int) $data[ $k ]; break; }
		}
	}
	if ( $current_page <= 0 ) { $current_page = $page; }
	if ( $total_items <= 0 )  { $total_items  = count( $items ); }
	if ( $total_pages <= 0 )  { $total_pages  = max( 1, (int) ceil( $total_items / max( 1, $limit ) ) ); }
	?>

	<div class="wto-reports-meta">
		<?php
		printf(
			/* translators: 1 total items, 2 current page, 3 total pages */
			esc_html__( 'مجموع: %1$s درخواست — صفحه %2$s از %3$s', 'wto' ),
			esc_html( number_format_i18n( $total_items ) ),
			esc_html( number_format_i18n( $current_page ) ),
			esc_html( number_format_i18n( $total_pages ) )
		);
		?>
	</div>

	<table class="widefat striped wto-reports-table wto-reports-table-list">
		<thead>
			<tr>
				<th class="wto-col-id"><?php esc_html_e( 'شناسه', 'wto' ); ?></th>
				<th class="wto-col-date"><?php esc_html_e( 'تاریخ ایجاد', 'wto' ); ?></th>
				<th class="wto-col-line"><?php esc_html_e( 'شماره ارسال‌کننده', 'wto' ); ?></th>
				<th class="wto-col-status"><?php esc_html_e( 'وضعیت', 'wto' ); ?></th>
				<th class="wto-col-count"><?php esc_html_e( 'تعداد گیرنده', 'wto' ); ?></th>
				<th class="wto-col-text"><?php esc_html_e( 'متن پیامک', 'wto' ); ?></th>
				<th class="wto-col-action"><?php esc_html_e( 'عملیات', 'wto' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $items ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'هیچ درخواست ارسالی یافت نشد.', 'wto' ); ?></td></tr>
			<?php else : ?>
				<?php
				$status_label = wto_send_reports_request_statuses();
				$missing_counts = array( 'text' => 0, 'date' => 0, 'line' => 0, 'count' => 0 );
				foreach ( $items as $row ) :
					$row    = is_array( $row ) ? $row : array();
					$rid    = wto_send_reports_find( $row, array( 'id', 'send_request_id', 'sendRequestId', 'request_id', 'requestId' ) );
					$rstat  = wto_send_reports_find( $row, array( 'status', 'state', 'send_status', 'sendStatus' ) );
					$rline  = wto_send_reports_pick_line_number( $row );
					// Recipient count — search recursively because the list endpoint
					// often nests it under sms / payload / request, AND pattern SMS
					// uses different key names (e.g. variables_count, attributes_count).
					$rcount = wto_send_reports_find_count( $row, array(
						'recipients_count', 'recipientsCount', 'total_recipients', 'totalRecipients',
						'recipient_count', 'recipientCount', 'totalRecipient',
						'variables_count', 'variablesCount', 'attributes_count', 'attributesCount',
						'count', 'recipients_total', 'recipients', 'total',
						'sms_count', 'smsCount', 'variables', 'attributes',
					) );
					// Try to detect pattern code first — pattern-type SMS rows usually
					// carry only a code reference, not the literal text.
					$pattern_code = wto_send_reports_pick_pattern_code( $row );

					// Message text — search recursively. List endpoint nests this
					// inside `sms.text` / `message.text` / `payload.text` etc. Pattern
					// SMS use different field names (`pattern.text`, `template`, ...).
					$rtext  = wto_send_reports_find( $row, array(
						'text', 'message', 'body', 'content', 'sms_text', 'smsText',
						'message_text', 'messageText', 'sms_body', 'smsBody', 'payload',
						'pattern_text', 'patternText', 'pattern_message', 'patternMessage',
						'template_text', 'templateText', 'template',
						'pattern_body', 'patternBody',
					) );

					// Fallback for pattern SMS: if the row carries a pattern code but
					// no literal text, fetch the template from /patterns/{code} (cached
					// for an hour). Slow only on the very first page-load.
					if ( $rtext === '' && $pattern_code !== '' ) {
						$rtext = wto_send_reports_fetch_pattern_text( $pattern_code );
					}

					// Date — search recursively. List endpoint often uses `submit_time`
					// or nests `created_at` deeper.
					$rdate_raw = wto_send_reports_find( $row, array(
						'created_at', 'createdAt', 'create_date', 'createDate', 'creation_time', 'creationTime',
						'submit_time', 'submitTime', 'submit_date', 'submitDate',
						'send_time', 'sendTime', 'send_date', 'sendDate',
						'date', 'datetime', 'created', 'timestamp',
					) );
					$rdate = $rdate_raw !== '' ? wto_send_reports_to_jalali( $rdate_raw ) : '';
					if ( $rtext === '' )      { $missing_counts['text']++; }
					if ( $rdate_raw === '' )  { $missing_counts['date']++; }
					if ( $rline === '' )      { $missing_counts['line']++; }
					if ( $rcount === 0 )      { $missing_counts['count']++; }
					$status_class = wto_send_reports_status_class( $rstat );
					$status_text  = isset( $status_label[ $rstat ] ) ? $status_label[ $rstat ] : ( $rstat !== '' ? $rstat : '—' );
					$detail_url   = add_query_arg(
						array( 'page' => 'farazwto-reports', 'view' => 'detail', 'id' => $rid ),
						admin_url( 'admin.php' )
					);
				?>
				<tr>
					<td><?php echo esc_html( $rid !== '' ? $rid : '—' ); ?></td>
					<td class="wto-reports-date-cell"><?php echo esc_html( $rdate !== '' ? $rdate : '—' ); ?></td>
					<td dir="ltr" class="wto-reports-line-cell"><?php echo esc_html( $rline !== '' ? $rline : '—' ); ?></td>
					<td><span class="wto-status wto-status-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span></td>
					<td><?php echo esc_html( number_format_i18n( $rcount ) ); ?></td>
					<td class="wto-reports-text-cell">
						<?php if ( $pattern_code !== '' ) : ?>
							<span class="wto-pattern-badge" title="<?php esc_attr_e( 'پیامک پترن (الگو)', 'wto' ); ?>">
								<?php
								printf(
									/* translators: %s pattern code */
									esc_html__( 'الگو: %s', 'wto' ),
									esc_html( $pattern_code )
								);
								?>
							</span>
						<?php endif; ?>
						<?php if ( $rtext !== '' ) : ?>
							<div class="wto-reports-text-body"><?php echo esc_html( $rtext ); ?></div>
						<?php elseif ( $pattern_code === '' ) : ?>
							—
						<?php else : ?>
							<div class="wto-reports-text-body wto-reports-text-muted"><?php esc_html_e( '(متن الگو در دسترس نیست)', 'wto' ); ?></div>
						<?php endif; ?>
					</td>
					<td><?php if ( $rid !== '' ) : ?><a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'جزئیات', 'wto' ); ?></a><?php else: echo '—'; endif; ?></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php
	// Auto-diagnostic — if any of the key fields was missing in more than half
	// of the rows, show a one-click expander with the keys of the first row.
	if ( ! empty( $items ) ) {
		$total = count( $items );
		$missing_labels = array();
		if ( isset( $missing_counts['text'] )  && $missing_counts['text']  >= ceil( $total / 2 ) ) { $missing_labels[] = __( 'متن پیامک', 'wto' ); }
		if ( isset( $missing_counts['date'] )  && $missing_counts['date']  >= ceil( $total / 2 ) ) { $missing_labels[] = __( 'تاریخ', 'wto' ); }
		if ( isset( $missing_counts['line'] )  && $missing_counts['line']  >= ceil( $total / 2 ) ) { $missing_labels[] = __( 'شماره ارسال‌کننده', 'wto' ); }
		if ( isset( $missing_counts['count'] ) && $missing_counts['count'] >= ceil( $total / 2 ) ) { $missing_labels[] = __( 'تعداد گیرنده', 'wto' ); }
		if ( ! empty( $missing_labels ) ) {
			wto_send_reports_render_keys_hint( $items, $missing_labels );
		}
	}
	?>

	<?php if ( $total_pages > 1 ) :
		$base = add_query_arg(
			array(
				'page'   => 'farazwto-reports',
				'limit'  => $limit,
				'status' => $status,
				'search' => $search,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wto-reports-pagination">
			<?php if ( $current_page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base ) ); ?>">« <?php esc_html_e( 'صفحه قبل', 'wto' ); ?></a>
			<?php endif; ?>
			<span class="wto-reports-page-info">
				<?php
				printf(
					/* translators: 1 current page, 2 total pages */
					esc_html__( 'صفحه %1$s از %2$s', 'wto' ),
					esc_html( number_format_i18n( $current_page ) ),
					esc_html( number_format_i18n( $total_pages ) )
				);
				?>
			</span>
			<?php if ( $current_page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base ) ); ?>"><?php esc_html_e( 'صفحه بعد', 'wto' ); ?> »</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php
	wto_send_reports_maybe_render_debug( __( 'پاسخ خام API — لیست (debug)', 'wto' ), $response );
}

/**
 * Render detail view: header (request meta) + items table with filters/pagination.
 */
function wto_send_reports_render_detail() {
	$send_request_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
	if ( $send_request_id === '' || ! ctype_digit( $send_request_id ) ) {
		echo '<div class="notice notice-error wto-reports-notice"><p>' . esc_html__( 'شناسه درخواست ارسال نامعتبر است.', 'wto' ) . '</p></div>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=farazwto-reports' ) ) . '">« ' . esc_html__( 'بازگشت به لیست', 'wto' ) . '</a>';
		return;
	}

	$meta = wto_send_reports_api_get( 'send_request/' . rawurlencode( $send_request_id ) );

	$page   = isset( $_GET['paged'] )  ? max( 1, (int) $_GET['paged'] )  : 1;
	$limit  = isset( $_GET['limit'] )  ? max( 5, min( 200, (int) $_GET['limit'] ) ) : 50;
	$status = isset( $_GET['istatus'] ) ? sanitize_text_field( wp_unslash( $_GET['istatus'] ) ) : '';
	$search = isset( $_GET['search'] )  ? sanitize_text_field( wp_unslash( $_GET['search'] ) )  : '';
	$valid_item_statuses = array_keys( wto_send_reports_item_statuses() );
	if ( ! in_array( $status, $valid_item_statuses, true ) ) {
		$status = '';
	}

	$items_response = wto_send_reports_api_get( 'send_request/' . rawurlencode( $send_request_id ) . '/items', array(
		'page'   => $page,
		'limit'  => $limit,
		'status' => $status,
		'search' => $search,
	) );

	$back_url = admin_url( 'admin.php?page=farazwto-reports' );
	?>
	<p>
		<a class="button" href="<?php echo esc_url( $back_url ); ?>">« <?php esc_html_e( 'بازگشت به لیست', 'wto' ); ?></a>
	</p>

	<h2 class="wto-reports-detail-title">
		<?php
		printf(
			/* translators: %s send request id */
			esc_html__( 'جزئیات درخواست ارسال #%s', 'wto' ),
			esc_html( $send_request_id )
		);
		?>
	</h2>

	<?php if ( $meta['success'] && is_array( $meta['data'] ) ) :
		$m            = $meta['data'];
		// Sometimes the singular endpoint wraps the row again in `item` or `request`.
		if ( isset( $m['item'] ) && is_array( $m['item'] ) ) {
			$m = $m['item'];
		} elseif ( isset( $m['request'] ) && is_array( $m['request'] ) ) {
			$m = $m['request'];
		}
		$mstat        = wto_send_reports_find( $m, array( 'status', 'state', 'send_status', 'sendStatus' ) );
		$status_label = wto_send_reports_request_statuses();
		$status_class = wto_send_reports_status_class( $mstat );
		$status_text  = isset( $status_label[ $mstat ] ) ? $status_label[ $mstat ] : ( $mstat !== '' ? $mstat : '—' );
		$m_pattern_code = wto_send_reports_pick_pattern_code( $m );
		$mtext        = wto_send_reports_find( $m, array(
			'text', 'message', 'body', 'content', 'sms_text', 'smsText',
			'message_text', 'messageText', 'sms_body', 'smsBody',
			'pattern_text', 'patternText', 'pattern_message', 'patternMessage',
			'template_text', 'templateText', 'template', 'pattern_body', 'patternBody',
		) );
		if ( $mtext === '' && $m_pattern_code !== '' ) {
			$mtext = wto_send_reports_fetch_pattern_text( $m_pattern_code );
		}
		$mline        = wto_send_reports_pick_line_number( $m );
		$mdate_raw    = wto_send_reports_find( $m, array(
			'created_at', 'createdAt', 'create_date', 'createDate', 'creation_time',
			'submit_time', 'submitTime', 'submit_date',
			'send_time', 'sendTime', 'send_date',
			'date', 'datetime', 'created', 'timestamp',
		) );
		$mdate        = $mdate_raw !== '' ? wto_send_reports_to_jalali( $mdate_raw ) : '';
		?>
		<table class="widefat wto-reports-detail-meta">
			<tbody>
				<tr><th><?php esc_html_e( 'تاریخ', 'wto' ); ?></th><td><?php echo esc_html( $mdate !== '' ? $mdate : '—' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'شماره ارسال‌کننده', 'wto' ); ?></th><td dir="ltr" class="wto-reports-line-cell"><?php echo esc_html( $mline !== '' ? $mline : '—' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'وضعیت', 'wto' ); ?></th><td><span class="wto-status wto-status-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span></td></tr>
				<?php if ( $m_pattern_code !== '' ) : ?>
					<tr><th><?php esc_html_e( 'نوع پیامک', 'wto' ); ?></th><td><span class="wto-pattern-badge"><?php printf( esc_html__( 'الگو: %s', 'wto' ), esc_html( $m_pattern_code ) ); ?></span></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'متن پیام', 'wto' ); ?></th><td><pre class="wto-reports-pre"><?php echo esc_html( $mtext !== '' ? $mtext : '—' ); ?></pre></td></tr>
			</tbody>
		</table>
	<?php endif; ?>

	<h3 class="wto-reports-items-title"><?php esc_html_e( 'وضعیت ارسال به گیرندگان', 'wto' ); ?></h3>

	<form method="get" class="wto-reports-filters">
		<input type="hidden" name="page" value="farazwto-reports">
		<input type="hidden" name="view" value="detail">
		<input type="hidden" name="id" value="<?php echo esc_attr( $send_request_id ); ?>">

		<label>
			<span><?php esc_html_e( 'جستجوی شماره/متن:', 'wto' ); ?></span>
			<input type="text" name="search" value="<?php echo esc_attr( $search ); ?>">
		</label>

		<label>
			<span><?php esc_html_e( 'وضعیت تحویل:', 'wto' ); ?></span>
			<select name="istatus">
				<?php foreach ( wto_send_reports_item_statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>
			<span><?php esc_html_e( 'تعداد در صفحه:', 'wto' ); ?></span>
			<select name="limit">
				<?php foreach ( array( 25, 50, 100, 200 ) as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $limit, $opt ); ?>>
						<?php echo esc_html( (string) $opt ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<button type="submit" class="button button-primary"><?php esc_html_e( 'اعمال فیلتر', 'wto' ); ?></button>
	</form>

	<?php if ( ! $items_response['success'] ) : ?>
		<div class="notice notice-error wto-reports-notice">
			<p><?php echo esc_html( $items_response['message'] ?? __( 'خطای نامشخص.', 'wto' ) ); ?></p>
		</div>
		<?php
		wto_send_reports_maybe_render_debug( __( 'پاسخ خام API — جزئیات (debug)', 'wto' ), $items_response );
		wto_send_reports_maybe_render_debug( __( 'پاسخ خام API — متادیتای درخواست (debug)', 'wto' ), $meta );
		return;
	endif;

	$idata = isset( $items_response['data'] ) ? $items_response['data'] : array();
	$rows  = array();
	if ( is_array( $idata ) ) {
		if ( isset( $idata['items'] ) && is_array( $idata['items'] ) ) {
			$rows = $idata['items'];
		} elseif ( isset( $idata['list'] ) && is_array( $idata['list'] ) ) {
			$rows = $idata['list'];
		} elseif ( isset( $idata['rows'] ) && is_array( $idata['rows'] ) ) {
			$rows = $idata['rows'];
		} elseif ( isset( $idata['data'] ) && is_array( $idata['data'] ) ) {
			$rows = $idata['data'];
		} elseif ( $idata && array_values( $idata ) === $idata ) {
			$rows = $idata;
		}
	}

	$current_page = 0;
	$total_pages  = 0;
	$total_items  = 0;
	if ( is_array( $idata ) ) {
		foreach ( array( 'currentPage', 'current_page', 'page' ) as $k ) {
			if ( isset( $idata[ $k ] ) ) { $current_page = (int) $idata[ $k ]; break; }
		}
		foreach ( array( 'totalPages', 'total_pages', 'pages' ) as $k ) {
			if ( isset( $idata[ $k ] ) ) { $total_pages = (int) $idata[ $k ]; break; }
		}
		foreach ( array( 'totalItems', 'total_items', 'total', 'count' ) as $k ) {
			if ( isset( $idata[ $k ] ) ) { $total_items = (int) $idata[ $k ]; break; }
		}
	}
	if ( $current_page <= 0 ) { $current_page = $page; }
	if ( $total_items <= 0 )  { $total_items  = count( $rows ); }
	if ( $total_pages <= 0 )  { $total_pages  = max( 1, (int) ceil( $total_items / max( 1, $limit ) ) ); }
	?>

	<div class="wto-reports-meta">
		<?php
		printf(
			/* translators: 1 total recipients, 2 current page, 3 total pages */
			esc_html__( 'مجموع: %1$s گیرنده — صفحه %2$s از %3$s', 'wto' ),
			esc_html( number_format_i18n( $total_items ) ),
			esc_html( number_format_i18n( $current_page ) ),
			esc_html( number_format_i18n( $total_pages ) )
		);
		?>
	</div>

	<table class="widefat striped wto-reports-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'گیرنده', 'wto' ); ?></th>
				<th><?php esc_html_e( 'وضعیت', 'wto' ); ?></th>
				<th><?php esc_html_e( 'متن ارسالی', 'wto' ); ?></th>
				<th><?php esc_html_e( 'تاریخ', 'wto' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'هیچ گیرنده‌ای یافت نشد.', 'wto' ); ?></td></tr>
			<?php else :
				$item_labels = wto_send_reports_item_statuses();
				$item_missing_mobile = 0;
				foreach ( $rows as $r ) :
					$r       = is_array( $r ) ? $r : array();
					$mobile  = wto_send_reports_pick_recipient( $r );
					if ( $mobile === '' ) { $item_missing_mobile++; }
					$rstat   = wto_send_reports_find( $r, array( 'status', 'state', 'delivery_status', 'deliveryStatus' ) );
					$rclass  = wto_send_reports_status_class( $rstat );
					$rtxt    = isset( $item_labels[ $rstat ] ) ? $item_labels[ $rstat ] : ( $rstat !== '' ? $rstat : '—' );
					$rmsg    = wto_send_reports_find( $r, array(
						'text', 'message', 'body', 'content', 'sms_text', 'smsText',
						'message_text', 'messageText', 'sms_body',
					) );
					$rdate_r = wto_send_reports_find( $r, array(
						'sent_at', 'sentAt', 'delivered_at', 'deliveredAt',
						'created_at', 'createdAt', 'submit_time', 'submitTime',
						'send_time', 'sendTime', 'date',
					) );
					$rdate   = $rdate_r !== '' ? wto_send_reports_to_jalali( $rdate_r ) : '';
			?>
				<tr>
					<td dir="ltr"><?php echo esc_html( $mobile !== '' ? $mobile : '—' ); ?></td>
					<td><span class="wto-status wto-status-<?php echo esc_attr( $rclass ); ?>"><?php echo esc_html( $rtxt ); ?></span></td>
					<td class="wto-reports-text-cell"><?php echo esc_html( $rmsg !== '' ? $rmsg : '—' ); ?></td>
					<td class="wto-reports-date-cell"><?php echo esc_html( $rdate !== '' ? $rdate : '—' ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>

	<?php
	if ( ! empty( $rows ) && isset( $item_missing_mobile ) && $item_missing_mobile >= ceil( count( $rows ) / 2 ) ) {
		wto_send_reports_render_keys_hint( $rows, array( __( 'شماره گیرنده', 'wto' ) ) );
	}
	?>

	<?php if ( $total_pages > 1 ) :
		$base = add_query_arg(
			array(
				'page'    => 'farazwto-reports',
				'view'    => 'detail',
				'id'      => $send_request_id,
				'limit'   => $limit,
				'istatus' => $status,
				'search'  => $search,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wto-reports-pagination">
			<?php if ( $current_page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base ) ); ?>">« <?php esc_html_e( 'صفحه قبل', 'wto' ); ?></a>
			<?php endif; ?>
			<span class="wto-reports-page-info">
				<?php
				printf(
					/* translators: 1 current page, 2 total pages */
					esc_html__( 'صفحه %1$s از %2$s', 'wto' ),
					esc_html( number_format_i18n( $current_page ) ),
					esc_html( number_format_i18n( $total_pages ) )
				);
				?>
			</span>
			<?php if ( $current_page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base ) ); ?>"><?php esc_html_e( 'صفحه بعد', 'wto' ); ?> »</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php
	wto_send_reports_maybe_render_debug( __( 'پاسخ خام API — متادیتای درخواست (debug)', 'wto' ), $meta );
	wto_send_reports_maybe_render_debug( __( 'پاسخ خام API — آیتم‌ها (debug)', 'wto' ), $items_response );
}

/**
 * Page-scoped CSS (kept inline because this is a small admin-only page).
 *
 * All selectors are prefixed with .wto-reports-wrapper so they cannot leak
 * into other admin screens or theme styles.
 */
function wto_send_reports_render_inline_styles() {
	?>
	<style>
	/* v3.17.1: Modern card-based design — هماهنگ با ROI/Newsletter/Birthday
	   تمام class name های قبلی حفظ شده تا JS و markup نشکنند.
	   v3.17.5: font-family صریح — جلوگیری از override توسط wp-admin defaults. */
	.wto-reports-wrapper { direction: rtl; font-family: IRANSans, Tahoma, sans-serif; }
	.wto-reports-wrapper * { box-sizing: border-box; }

	/* Hero عنوان */
	.wto-reports-wrapper .wto-reports-title {
		background: linear-gradient(135deg, #0f172a 0%, #334155 60%, #475569 100%);
		color: #fff;
		margin: 6px 0 14px;
		padding: 22px 28px;
		border-radius: 14px;
		font-size: 20px;
		font-weight: 800;
		box-shadow: 0 8px 24px rgba(15, 23, 42, 0.16);
		position: relative;
		overflow: hidden;
	}
	.wto-reports-wrapper .wto-reports-title::before {
		content: '📊';
		position: absolute;
		top: -10px;
		left: -8px;
		font-size: 110px;
		opacity: 0.10;
		line-height: 1;
	}

	.wto-reports-wrapper .wto-reports-debug-hint {
		color: #475569;
		font-size: 12px;
		margin: 0 0 16px;
		padding: 10px 14px;
		background: #f8fafc;
		border-radius: 8px;
		border-right: 3px solid #94a3b8;
		line-height: 1.7;
	}
	.wto-reports-wrapper .wto-reports-debug-hint a {
		font-weight: 700;
		color: #4338ca;
		text-decoration: none;
	}
	.wto-reports-wrapper .wto-reports-debug-hint a:hover { text-decoration: underline; }

	/* فیلتر بار */
	.wto-reports-wrapper .wto-reports-filters {
		background: #fff;
		padding: 16px 18px;
		border: 1px solid #e5e7eb;
		border-radius: 12px;
		margin: 0 0 14px;
		display: flex;
		flex-wrap: wrap;
		gap: 14px;
		align-items: flex-end;
	}
	.wto-reports-wrapper .wto-reports-filters label {
		display: flex;
		flex-direction: column;
		gap: 5px;
		font-size: 12px;
		color: #475569;
		font-weight: 600;
	}
	.wto-reports-wrapper .wto-reports-filters input[type="text"],
	.wto-reports-wrapper .wto-reports-filters select {
		min-width: 180px;
		padding: 8px 12px;
		border: 1px solid #cbd5e1;
		border-radius: 7px;
		font-size: 13px;
		color: #0f172a;
		background: #fff;
		transition: border-color .15s, box-shadow .15s;
	}
	.wto-reports-wrapper .wto-reports-filters input[type="text"]:focus,
	.wto-reports-wrapper .wto-reports-filters select:focus {
		outline: 0;
		border-color: #4338ca;
		box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.12);
	}

	/* OVERRIDE دکمه‌های WP — کلید رفع ظاهر «ویندوز ۹۸» */
	.wto-reports-wrapper .button,
	.wto-reports-wrapper button.button {
		background: #fff !important;
		color: #475569 !important;
		border: 1px solid #cbd5e1 !important;
		border-radius: 7px !important;
		padding: 8px 16px !important;
		font-size: 12.5px !important;
		font-weight: 600 !important;
		min-height: auto !important;
		line-height: 1.4 !important;
		text-shadow: none !important;
		box-shadow: none !important;
		cursor: pointer !important;
		transition: all .15s !important;
		text-decoration: none !important;
	}
	.wto-reports-wrapper .button:hover,
	.wto-reports-wrapper button.button:hover {
		background: #f8fafc !important;
		border-color: #94a3b8 !important;
		color: #0f172a !important;
	}
	.wto-reports-wrapper .button-primary,
	.wto-reports-wrapper button.button-primary {
		background: #4338ca !important;
		color: #fff !important;
		border: 1px solid #4338ca !important;
		box-shadow: 0 4px 12px rgba(67, 56, 202, 0.22) !important;
	}
	.wto-reports-wrapper .button-primary:hover,
	.wto-reports-wrapper button.button-primary:hover {
		background: #3730a3 !important;
		border-color: #3730a3 !important;
		color: #fff !important;
	}
	.wto-reports-wrapper .button-small {
		padding: 5px 12px !important;
		font-size: 11.5px !important;
	}

	/* meta bar (تعداد رکورد + شماره صفحه) */
	.wto-reports-wrapper .wto-reports-meta {
		color: #475569;
		font-size: 12.5px;
		font-weight: 600;
		margin: 0 0 12px;
		padding: 10px 14px;
		background: #f8fafc;
		border-radius: 8px;
		border-right: 3px solid #4338ca;
	}

	/* جدول modernized */
	.wto-reports-wrapper .wto-reports-table {
		background: #fff;
		border: 1px solid #e5e7eb !important;
		border-radius: 12px !important;
		overflow: hidden;
		box-shadow: none !important;
		margin-bottom: 18px;
		width: 100%;
		border-collapse: separate !important;
		border-spacing: 0;
		table-layout: auto;
	}
	.wto-reports-wrapper .wto-reports-table thead {
		background: #f8fafc !important;
	}
	.wto-reports-wrapper .wto-reports-table thead th {
		background: #f8fafc !important;
		color: #0f172a !important;
		font-weight: 700 !important;
		font-size: 12.5px !important;
		text-align: right !important;
		padding: 13px 16px !important;
		border-bottom: 1px solid #e5e7eb !important;
		border-top: 0 !important;
		vertical-align: middle !important;
	}
	.wto-reports-wrapper .wto-reports-table tbody td {
		padding: 12px 16px !important;
		vertical-align: top !important;
		font-size: 12.5px !important;
		color: #1f2937 !important;
		border-bottom: 1px solid #f1f5f9 !important;
		background: #fff !important;
	}
	.wto-reports-wrapper .wto-reports-table tbody tr:last-child td {
		border-bottom: 0 !important;
	}
	.wto-reports-wrapper .wto-reports-table tbody tr:hover td {
		background: #f8fafc !important;
	}
	.wto-reports-wrapper .wto-reports-text-cell {
		min-width: 280px;
		max-width: 520px;
		white-space: pre-wrap;
		word-break: break-word;
		line-height: 1.8 !important;
	}
	.wto-reports-wrapper .wto-reports-date-cell {
		white-space: nowrap;
		direction: ltr;
		text-align: right !important;
		font-variant-numeric: tabular-nums;
		color: #64748b !important;
		font-size: 12px !important;
	}
	.wto-reports-wrapper .wto-reports-line-cell {
		font-variant-numeric: tabular-nums;
		direction: ltr;
		text-align: right;
		font-family: Menlo, Consolas, monospace;
		color: #475569 !important;
		font-size: 12px !important;
	}
	.wto-reports-wrapper .wto-col-id     { width: 90px; }
	.wto-reports-wrapper .wto-col-date   { width: 150px; }
	.wto-reports-wrapper .wto-col-line   { width: 140px; }
	.wto-reports-wrapper .wto-col-status { width: 110px; }
	.wto-reports-wrapper .wto-col-count  { width: 100px; text-align: center; }
	.wto-reports-wrapper .wto-col-action { width: 90px; }

	/* pagination */
	.wto-reports-wrapper .wto-reports-pagination {
		display: flex;
		gap: 8px;
		align-items: center;
		justify-content: center;
		margin: 16px 0;
		padding: 12px;
		background: #fff;
		border: 1px solid #e5e7eb;
		border-radius: 10px;
	}
	.wto-reports-wrapper .wto-reports-page-info {
		color: #475569;
		font-size: 12.5px;
		font-weight: 600;
	}

	/* status pills */
	.wto-reports-wrapper .wto-status {
		display: inline-block;
		padding: 3px 11px;
		border-radius: 14px;
		font-size: 11.5px;
		font-weight: 600;
		line-height: 1.6;
		white-space: nowrap;
	}
	.wto-reports-wrapper .wto-status-success { background: #dcfce7; color: #166534; }
	.wto-reports-wrapper .wto-status-info    { background: #dbeafe; color: #1e40af; }
	.wto-reports-wrapper .wto-status-warning { background: #fef3c7; color: #92400e; }
	.wto-reports-wrapper .wto-status-danger  { background: #fee2e2; color: #991b1b; }
	.wto-reports-wrapper .wto-status-muted   { background: #f1f5f9; color: #64748b; }

	/* notice خطا — override WP */
	.wto-reports-wrapper .notice,
	.wto-reports-wrapper .wto-reports-notice {
		background: #fef2f2 !important;
		border: 1px solid #fecaca !important;
		border-right: 3px solid #dc2626 !important;
		border-left-width: 1px !important;
		padding: 12px 16px !important;
		border-radius: 10px !important;
		color: #991b1b !important;
		margin: 0 0 16px !important;
	}
	.wto-reports-wrapper .notice p,
	.wto-reports-wrapper .wto-reports-notice p { margin: 0; font-weight: 600; }

	/* pre / debug blocks */
	.wto-reports-wrapper .wto-reports-pre {
		background: #f8fafc;
		padding: 12px 14px;
		border-radius: 8px;
		border: 1px solid #e5e7eb;
		white-space: pre-wrap;
		word-break: break-word;
		max-width: 720px;
		margin: 0;
		font-family: Menlo, Consolas, monospace;
		font-size: 12px;
		line-height: 1.7;
		color: #0f172a;
	}

	/* detail page meta + titles */
	.wto-reports-wrapper .wto-reports-detail-meta {
		background: #fff;
		border: 1px solid #e5e7eb;
		border-radius: 12px;
		padding: 18px 22px;
		max-width: 720px;
		margin: 12px 0 24px;
	}
	.wto-reports-wrapper .wto-reports-detail-title,
	.wto-reports-wrapper .wto-reports-items-title {
		margin: 18px 0 12px;
		font-size: 15px;
		font-weight: 700;
		color: #0f172a;
		padding-bottom: 8px;
		border-bottom: 2px solid #e5e7eb;
	}

	/* debug pane */
	.wto-reports-wrapper .wto-reports-debug {
		background: linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);
		border: 1.5px solid #fde68a;
		border-radius: 10px;
		padding: 14px 18px;
		margin: 20px 0;
	}
	.wto-reports-wrapper .wto-reports-debug summary {
		cursor: pointer;
		font-weight: 700;
		color: #713f12;
		font-size: 13px;
	}
	.wto-reports-wrapper .wto-reports-debug-meta {
		font-size: 12px;
		margin: 10px 0;
		color: #92400e;
		line-height: 1.8;
	}
	.wto-reports-wrapper .wto-reports-debug-meta code {
		background: #fff;
		padding: 2px 8px;
		border-radius: 4px;
		direction: ltr;
		border: 1px solid #fcd34d;
		font-family: Menlo, Consolas, monospace;
	}
	.wto-reports-wrapper .wto-reports-debug-pre {
		max-height: 400px;
		overflow: auto;
		direction: ltr;
		text-align: left;
		font-size: 12px;
		background: #fff;
		padding: 12px 14px;
		border-radius: 8px;
		border: 1px solid #fcd34d;
		font-family: Menlo, Consolas, monospace;
		line-height: 1.7;
	}

	/* keys hint */
	.wto-reports-wrapper .wto-reports-keys-hint {
		background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
		border: 1.5px solid #fdba74;
		border-radius: 10px;
		padding: 14px 18px;
		margin: 16px 0;
	}
	.wto-reports-wrapper .wto-reports-keys-hint summary {
		cursor: pointer;
		font-weight: 700;
		color: #9a3412;
		font-size: 13px;
	}
	.wto-reports-wrapper .wto-reports-keys-hint p {
		margin: 10px 0;
		color: #7c2d12;
		line-height: 1.7;
		font-size: 12.5px;
	}
	.wto-reports-wrapper .wto-reports-keys-hint pre {
		background: #fff;
		padding: 12px 14px;
		border-radius: 8px;
		max-height: 300px;
		overflow: auto;
		direction: ltr;
		text-align: left;
		font-size: 12px;
		border: 1px solid #fdba74;
		font-family: Menlo, Consolas, monospace;
	}

	/* pattern badge */
	.wto-reports-wrapper .wto-pattern-badge {
		display: inline-block;
		background: #ede9fe;
		color: #5b21b6;
		border: 1px solid #c4b5fd;
		border-radius: 6px;
		padding: 3px 10px;
		font-size: 11.5px;
		font-weight: 600;
		margin-bottom: 6px;
		direction: ltr;
		font-family: Menlo, Consolas, monospace;
	}
	.wto-reports-wrapper .wto-reports-text-body {
		margin-top: 4px;
		color: #1f2937;
	}
	.wto-reports-wrapper .wto-reports-text-muted {
		color: #94a3b8;
		font-style: italic;
		font-size: 11.5px;
	}

	@media (max-width: 720px) {
		.wto-reports-wrapper .wto-reports-filters input[type="text"],
		.wto-reports-wrapper .wto-reports-filters select { min-width: 100%; }
	}
	</style>
	<?php
}
