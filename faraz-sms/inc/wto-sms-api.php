<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

 

/**
 * تبدیل ارقام فارسی + عربی به لاتین.
 * v3.17.6: ارقام عربی (Eastern Arabic) هم اضافه شد — قبلاً فقط فارسی پشتیبانی می‌شد.
 *
 *   فارسی:  ۰۱۲۳۴۵۶۷۸۹  (U+06F0–U+06F9)
 *   عربی:   ٠١٢٣٤٥٦٧٨٩  (U+0660–U+0669)
 *   لاتین:  0123456789
 */
function wto_tr_num( $str ) {
	if ( $str === null ) return '';
	$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
	$latin   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$str = str_replace( $persian, $latin, (string) $str );
	$str = str_replace( $arabic, $latin, $str );
	return $str;
}

function wto_check_if_credentials_is_valid( $uname, $pass ) {
	$body     = array(
		'username' => wto_tr_num( $uname ),
		'password' => wto_tr_num( $pass ),
	);
	$response = wto_remote_post_with_fallback( 'https://reg.ippanel.com/parent/farazsms', array(
			'method'      => 'POST',
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'data_format' => 'body',
			'body'        => wp_json_encode( $body ),
			'timeout'     => 15,
		)
	);
	if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['body'] ) ) {
		return false;
	}
	$decoded = json_decode( $response['body'] );
	// The remote endpoint returns message === 1 on a valid credential. The previous
	// logic was inverted ("return true when NOT 1"), which treated every error
	// response as a successful login. Bug fix: require exact match.
	if ( ! is_object( $decoded ) || ! isset( $decoded->message ) ) {
		return false;
	}
	return ( (int) $decoded->message === 1 );
}

/**
 * دریافت Api-Key از تنظیمات افزونه رهگیری
 * فقط از wto_apikey می‌خواند تا بخش رهگیری مستقل از افزونه پیامک فارسی باشد
 */
function wto_get_apikey() {
	// فقط از تنظیمات خود افزونه رهگیری بخوان
	return get_option('wto_apikey', '');
}

/**
 * Unique placeholder names from a tracking SMS template (%name% and {name}).
 *
 * @param string $message Template text.
 * @return string[]
 */
function wto_tracking_pattern_var_keys_from_message( $message ) {
	$message = (string) $message;
	$keys    = array();
	if ( preg_match_all( '/%([a-zA-Z0-9_]+)%/', $message, $m1 ) ) {
		foreach ( $m1[1] as $k ) {
			$keys[ $k ] = true;
		}
	}
	if ( preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $message, $m2 ) ) {
		foreach ( $m2[1] as $k ) {
			$keys[ $k ] = true;
		}
	}
	return array_keys( $keys );
}

/**
 * Parse %var% and {var} together for pattern create/update (duplicate names across both styles are an error).
 *
 * @param string $message Raw template.
 * @param string $error_message Filled when invalid.
 * @return array{0: string[], 1: string} variables list and API pattern text (braces normalized to %).
 */
function wto_parse_pattern_message_placeholders_merged( $message, &$error_message ) {
	$error_message = '';
	$message       = (string) $message;
	$names         = array();
	if ( preg_match_all( '/%([a-zA-Z0-9_]+)%/', $message, $m1 ) ) {
		foreach ( $m1[1] as $n ) {
			$names[] = $n;
		}
	}
	if ( preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $message, $m2 ) ) {
		foreach ( $m2[1] as $n ) {
			$names[] = $n;
		}
	}
	if ( empty( $names ) ) {
		return array( array(), $message );
	}
	$counts = array_count_values( $names );
	$dups   = array();
	foreach ( $counts as $n => $c ) {
		if ( $c > 1 ) {
			$dups[] = $n;
		}
	}
	if ( ! empty( $dups ) ) {
		$error_message = 'خطا: متغیر(های) زیر بیش از یک بار در متن استفاده شده‌اند: ' . implode( ', ', $dups ) . '. از هر متغیر فقط یک بار می‌توان استفاده کرد.';
		return array( array(), $message );
	}
	$variables    = array_values( array_unique( $names ) );
	$pattern_text = $message;
	foreach ( $variables as $var ) {
		$pattern_text = str_replace( '{' . $var . '}', '%' . $var . '%', $pattern_text );
	}
	return array( $variables, $pattern_text );
}

/**
 * Pattern API var entry type/length for known tracking placeholders.
 *
 * @param string $var Variable name.
 * @param bool   $pwsms_long_name Use length 100 for customer_fullname only (pwsms module); b_first/last_name always 25.
 * @return array{type: string, length: int}
 */
function wto_tracking_pattern_var_type_length( $var, $pwsms_long_name = false ) {
	$type   = 'str';
	$length = 25;
	if ( $var === 'b_first_name' || $var === 'b_last_name' ) {
		$type   = 'str';
		$length = 25;
	} elseif ( $var === 'customer_fullname' ) {
		$type   = 'str';
		$length = $pwsms_long_name ? 100 : 25;
	} elseif ( $var === 'order_id' ) {
		$type   = 'int';
		$length = 20;
	} elseif ( $var === 'tracking_code' ) {
		$type   = 'int';
		$length = 25;
	} elseif ( $var === 'all_items' || strpos( $var, 'all_items_' ) === 0 ) {
		$type   = 'str';
		$length = 120;
	}
	return array( 'type' => $type, 'length' => $length );
}

/**
 * v3.17.3: ساخت description برندشده برای ارسال به API پترن فراز اس‌ام‌اس.
 *
 * این description را admin پنل فراز هنگام بررسی پترن می‌بیند، و فوراً می‌فهمد
 * این پترن از کدام ماژول افزونه فراز اس ام اس آمده — کمک به تأیید سریع‌تر.
 *
 * فرمت ثابت: «افزونه فراز اس ام اس / [بخش]»
 *
 * @param string $section_type کلید بخش (مثلاً 'cashback', 'comment', 'tracking', 'birthday', 'survey', 'otp', 'buyer', ...)
 * @param string $status_key   کلید فرعی (مثلاً 'admin', 'user_approve', ...)
 * @param string $carrier      برای tracking: 'post' | 'tipax' | 'other'
 * @return string description نهایی
 */
function wto_pattern_brand_description( $section_type, $status_key = '', $carrier = '' ) {
	$brand = 'افزونه فراز اس ام اس';

	$labels = array(
		'tracking'      => 'کد رهگیری سفارش',
		'cashback'      => 'کش بک',
		'birthday'      => 'تبریک تولد',
		'comment'       => 'دیدگاه سایت',
		'survey'        => 'نظرسنجی پس از خرید',
		'otp'           => 'احراز هویت OTP',
		'notify'        => 'موجود شد خبرم کن',
		'abandoned'     => 'سبد خرید رهاشده',
		'newsletter'    => 'خبرنامه پیامکی',
		'login'         => 'ورود و ثبت‌نام',
		'lead_magnet'   => 'لید مگنت',
		'buyer'         => 'پیامک حرفه‌ای ووکامرس — خریدار',
		'super_admin'   => 'پیامک حرفه‌ای ووکامرس — مدیر',
		'product_admin' => 'پیامک حرفه‌ای ووکامرس — مدیر محصول',
		'gravity'       => 'فرم‌های Gravity',
		'elementor'     => 'فرم‌های Elementor',
	);

	$carrier_labels = array(
		'post'  => 'پست',
		'tipax' => 'تیپاکس',
		'other' => 'سایر',
	);

	$status_labels = array(
		'admin'         => 'اطلاع به مدیر',
		'user_approve'  => 'تایید/رد دیدگاه',
		'user_reply'    => 'پاسخ به دیدگاه',
	);

	$section_label = isset( $labels[ $section_type ] ) ? $labels[ $section_type ] : ( $section_type !== '' ? $section_type : 'متفرقه' );

	// tracking با carrier جزئیات بیشتر می‌گیرد
	if ( $section_type === 'tracking' && $carrier !== '' && isset( $carrier_labels[ $carrier ] ) ) {
		$section_label .= ' / ' . $carrier_labels[ $carrier ];
	}
	// comment با status (admin/user_approve/user_reply) جزئیات بیشتر
	if ( strpos( $section_type, 'comment' ) === 0 && $status_key !== '' ) {
		$status_label   = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : $status_key;
		$section_label .= ' / ' . $status_label;
	}

	return $brand . ' / ' . $section_label;
}

/**
 * تبدیل شماره تلفن از فرمت بین‌المللی به فرمت داخلی ایران
 * تبدیل +989... به 09...
 *
 * @param string $phone شماره تلفن
 * @return string شماره تلفن تبدیل شده
 */
function wto_normalize_phone($phone) {
	if (empty($phone)) {
		return $phone;
	}

	$phone = wto_tr_num( (string) $phone );
	$phone = trim( $phone );
	// نگه داشتن فقط رقم و علامت + برای نرمال‌سازی قابل‌اعتماد.
	$phone = preg_replace( '/[^\d\+]+/u', '', $phone );
	$phone = preg_replace( '/^\++/', '+', $phone );

	// قبلاً فرمت داخلی درست است.
	if ( preg_match( '/^09\d{9}$/', $phone ) ) {
		return $phone;
	}

	// +98912... => 0912... — اگر بعد از +98 دوباره 0 آمده (+980912...) صفر اضافه نزن.
	if ( strpos( $phone, '+98' ) === 0 ) {
		$rest  = substr( $phone, 3 );
		$phone = ( $rest !== '' && $rest[0] === '0' ) ? $rest : ( '0' . $rest );
	}

	if ( preg_match( '/^09\d{9}$/', $phone ) ) {
		return $phone;
	}

	// 0098912... => 0912... — همان منطق برای جلوگیری از 0090912...
	if ( strpos( $phone, '0098' ) === 0 ) {
		$rest  = substr( $phone, 4 );
		$phone = ( $rest !== '' && $rest[0] === '0' ) ? $rest : ( '0' . $rest );
	}

	if ( preg_match( '/^09\d{9}$/', $phone ) ) {
		return $phone;
	}

	// 009395302366 => 09395302366 (پیشوند 00 اضافی بدون 98)
	if ( preg_match( '/^0{2,}(9\d{9})$/', $phone, $matches ) ) {
		$phone = '0' . $matches[1];
	}

	if ( preg_match( '/^09\d{9}$/', $phone ) ) {
		return $phone;
	}

	// 98912... => 0912...
	if ( preg_match( '/^98(9\d{9})$/', $phone, $matches ) ) {
		$phone = '0' . $matches[1];
	}

	// 912... => 0912...
	if ( preg_match( '/^9\d{9}$/', $phone ) ) {
		$phone = '0' . $phone;
	}

	return $phone;
}

/**
 * جدا کردن چند شماره موبایل (کامای انگلیسی/فارسی/عربی، ; و خط جدید).
 *
 * @param string|array $raw
 * @return string[] شماره‌های نرمال 09xxxxxxxxx
 */
function wto_split_mobile_list( $raw ) {
	$parts = array();
	if ( is_array( $raw ) ) {
		foreach ( $raw as $item ) {
			$parts = array_merge( $parts, wto_split_mobile_list( $item ) );
		}
		return array_values( array_unique( array_filter( $parts ) ) );
	}

	$raw = wto_tr_num( trim( (string) $raw ) );
	if ( $raw === '' ) {
		return array();
	}

	$chunks = preg_split( '/[\s,،٬;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $chunks ) ) {
		$chunks = array( $raw );
	}

	$out = array();
	foreach ( $chunks as $chunk ) {
		$chunk = trim( $chunk );
		if ( $chunk === '' ) {
			continue;
		}
		$normalized = wto_normalize_phone( $chunk );
		if ( $normalized === '' ) {
			continue;
		}
		if ( preg_match( '/^09\d{9}$/', $normalized ) ) {
			$out[] = $normalized;
		}
	}

	return array_values( array_unique( $out ) );
}

/**
 * تشخیص خطای بلاک شدن WordPress HTTP API.
 *
 * @param mixed $error
 * @return bool
 */
function wto_is_http_blocked_error( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return false;
	}
	$code = $error->get_error_code();
	if ( $code === 'http_request_not_executed' || $code === 'http_request_failed' ) {
		return true;
	}
	$message = strtolower( (string) $error->get_error_message() );
	return (
		strpos( $message, 'blocked' ) !== false ||
		strpos( $message, 'http requests are blocked' ) !== false ||
		strpos( $message, 'بلاک' ) !== false
	);
}

/**
 * اجرای cURL به عنوان fallback برای زمانی که wp_remote بلاک می‌شود.
 *
 * @param string $method
 * @param string $url
 * @param array  $args
 * @return array|WP_Error
 */
function wto_curl_fallback_request( $method, $url, $args = array() ) {
	if ( ! function_exists( 'curl_init' ) ) {
		return new WP_Error( 'curl_unavailable', 'cURL fallback is unavailable.' );
	}
	$method = strtoupper( (string) $method );
	$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
	$header_lines = array();
	foreach ( $headers as $key => $value ) {
		$header_lines[] = $key . ': ' . $value;
	}
	$timeout = isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) : 30;
	$body = isset( $args['body'] ) ? (string) $args['body'] : '';

	$curl = curl_init();
	curl_setopt_array( $curl, array(
		CURLOPT_URL            => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING       => '',
		CURLOPT_MAXREDIRS      => 5,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT        => $timeout,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST  => $method,
		// Explicit TLS verification — relying on libcurl defaults is unsafe in shared hosting
		// environments where some images ship with verification disabled.
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_HTTPHEADER     => $header_lines,
	) );
	if ( $method !== 'GET' && $body !== '' ) {
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
	}
	$response_body = curl_exec( $curl );
	if ( curl_errno( $curl ) ) {
		$error = curl_error( $curl );
		curl_close( $curl );
		return new WP_Error( 'curl_fallback_error', $error );
	}
	$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
	curl_close( $curl );

	return array(
		'response' => array(
			'code'    => $status,
			'message' => '',
		),
		'body'     => (string) $response_body,
	);
}

/**
 * POST request with fallback to cURL on blocked wp_remote.
 *
 * @param string $url
 * @param array  $args
 * @return array|WP_Error
 */
function wto_remote_post_with_fallback( $url, $args = array() ) {
	$response = wp_remote_post( $url, $args );
	if ( ! wto_is_http_blocked_error( $response ) ) {
		return $response;
	}
	return wto_curl_fallback_request( 'POST', $url, $args );
}

/**
 * GET request with fallback to cURL on blocked wp_remote.
 *
 * @param string $url
 * @param array  $args
 * @return array|WP_Error
 */
function wto_remote_get_with_fallback( $url, $args = array() ) {
	$response = wp_remote_get( $url, $args );
	if ( ! wto_is_http_blocked_error( $response ) ) {
		return $response;
	}
	return wto_curl_fallback_request( 'GET', $url, $args );
}

/**
 * دریافت Api-Key از تنظیمات افزونه پیامک فارسی
 * برای استفاده در بخش‌های مربوط به پیامک فارسی (buyer, super_admin, product_admin)
 */
function wto_get_pwsms_apikey() {
	$apikey = '';
	if (function_exists('PWSMS')) {
		$apikey = PWSMS()->get_option('sms_gateway_apikey');
		if (empty($apikey)) {
			$apikey = PWSMS()->get_option('sms_gateway_username');
		}
	}
	return $apikey;
}

function wto_get_credit() {
	// دریافت Api-Key از تنظیمات
	$api_key = wto_get_apikey();

	if (empty($api_key)) {
		return false;
	}

	// Cache for 5 minutes — admin bar & dashboard widgets call this on every page
	// render, which previously meant one external HTTP per page on 10,000 stores.
	$cache_key = 'wto_credit_' . md5( $api_key );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$response = wto_remote_get_with_fallback( 'https://api.iranpayamak.com/ws/v1/account/balance', array(
		'headers' => array(
			'Accept'  => 'application/json',
			'Api-Key' => $api_key,
		),
		'timeout' => 10,
	) );

	if ( ! is_array( $response ) || is_wp_error( $response ) ) {
		// اتصال برقرار نشد (مسدودسازی توسط افزونه/سرور یا قطعی) — این «کلیدِ نامعتبر» نیست.
		// برای نگهبانِ اتصال ثبت کن تا اعلانِ صحیح (نامِ افزونه‌ی مسدودکننده) نمایش داده شود.
		if ( function_exists( 'wto_connectivity_note_failure' ) ) {
			wto_connectivity_note_failure( is_wp_error( $response ) ? $response->get_error_message() : 'no response from balance endpoint' );
		}
		return false;
	}
	// پاسخِ HTTP دریافت شد → اتصال سالم است (حتی اگر کلید نامعتبر باشد).
	if ( function_exists( 'wto_connectivity_note_success' ) ) {
		wto_connectivity_note_success();
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
	$credit    = intval( $data['data']['balance_amount'] );
	$formatted = number_format( $credit );
	// v3.13.12: TTL از ۵ دقیقه به ۱۵ دقیقه افزایش یافت — برای ۱۰۰k سایت، این یعنی
	// ۳ برابر کاهش در فراخوانی API برای credit (که روی هر admin page load انجام
	// می‌شد). 15 دقیقه هنوز fresh است و دکمه «تازه‌سازی» در پنل، cache را
	// صریح حذف می‌کند برای refresh دستی.
	set_transient( $cache_key, $formatted, 15 * MINUTE_IN_SECONDS );
	return $formatted;
}

/**
 * دریافت پروفایل حساب از API (نام و شماره موبایل)
 * از API Profile استفاده می‌کند: GET /ws/v1/account/profile
 *
 * @return array|false آرایه با کلیدهای display_name و mobile، یا false در صورت خطا
 */
function wto_get_profile() {
	$api_key = wto_get_apikey();
	if ( empty( $api_key ) ) {
		return false;
	}
	$cache_key = 'wto_profile_' . md5( $api_key );
	$cached = get_transient( $cache_key );
	if ( $cached !== false && is_array( $cached ) ) {
		return $cached;
	}
	$response = wto_remote_get_with_fallback( 'https://api.iranpayamak.com/ws/v1/account/profile', array(
		'headers' => array(
			'Accept'   => 'application/json',
			'Api-Key'  => $api_key,
		),
		'timeout'  => 15,
	) );
	if ( ! is_array( $response ) || is_wp_error( $response ) ) {
		if ( function_exists( 'wto_connectivity_note_failure' ) ) {
			wto_connectivity_note_failure( is_wp_error( $response ) ? $response->get_error_message() : 'no response from profile endpoint' );
		}
		return false;
	}
	if ( function_exists( 'wto_connectivity_note_success' ) ) {
		wto_connectivity_note_success();
	}
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	if ( empty( $data['status'] ) || $data['status'] !== 'success' || empty( $data['data'] ) ) {
		return false;
	}
	$d = $data['data'];
	$profile = array(
		'display_name' => isset( $d['displayName'] ) ? $d['displayName'] : '',
		'mobile'       => isset( $d['mobile'] ) ? $d['mobile'] : '',
	);
	if ( $profile['display_name'] === '' && $profile['mobile'] === '' ) {
		return false;
	}
	set_transient( $cache_key, $profile, 10 * MINUTE_IN_SECONDS );
	return $profile;
}

/**
 * چاپ بلوک نام و شماره پروفایل برای استفاده در صفحات افزونه (کنار اعتبار)
 * اگر پروفایل موجود نباشد چیزی چاپ نمی‌کند.
 */
function wto_render_profile_block() {
	$profile = function_exists( 'wto_get_profile' ) ? wto_get_profile() : false;
	if ( ! $profile || ( empty( $profile['display_name'] ) && empty( $profile['mobile'] ) ) ) {
		return;
	}
	?>
	<div class="wto_account_profile">
		<?php if ( ! empty( $profile['display_name'] ) ) : ?>
			<div class="wto_profile_row wto_profile_name">
				<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
				<span class="wto_profile_text"><?php echo esc_html( $profile['display_name'] ); ?></span>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $profile['mobile'] ) ) : ?>
			<div class="wto_profile_row wto_profile_mobile">
				<span class="dashicons dashicons-phone" aria-hidden="true"></span>
				<span class="wto_profile_text"><?php echo esc_html( $profile['mobile'] ); ?></span>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * نام option کد پترن برای بخش دیدگاه سایت.
 *
 * @param string $status_key admin|user_approve|user_reply
 * @return string
 */
function wto_comment_pattern_option_name( $status_key ) {
	$map = array(
		'admin'         => 'wto_comment_admin_pattern',
		'user_approve'  => 'wto_comment_user_approve_pattern',
		'user_reply'    => 'wto_comment_user_reply_pattern',
	);
	$status_key = sanitize_key( (string) $status_key );
	return isset( $map[ $status_key ] ) ? $map[ $status_key ] : '';
}

/**
 * کد پترن ذخیره‌شده برای دیدگاه (option اختصاصی یا wto_patterns).
 *
 * @param string $status_key
 * @return string
 */
function wto_get_comment_pattern_code( $status_key ) {
	$option = wto_comment_pattern_option_name( $status_key );
	if ( $option !== '' ) {
		$code = trim( (string) get_option( $option, '' ) );
		if ( $code !== '' ) {
			return $code;
		}
	}
	$patterns = get_option( 'wto_patterns', array() );
	if ( is_array( $patterns ) && isset( $patterns['comment'][ $status_key ] ) ) {
		return trim( (string) $patterns['comment'][ $status_key ] );
	}
	return '';
}

/**
 * ساخت پترن در سامانه پیامکی (تابع اصلی)
 * این تابع برای استفاده در بخش‌های دیگر افزونه رهگیری استفاده می‌شود
 *
 * @param string $message     متن پیام
 * @param int    $category    دسته پترن: 1=otp, 2=club, 3=order, 255=others (پیش‌فرض 255)
 * @param string $description توضیح برندشده پترن. v3.17.3: الزامی — همیشه برای admin فراز
 *                            مشخص می‌کند که این پترن از کدام بخش افزونه فراز اس ام اس آمده.
 *                            مثال: "افزونه فراز اس ام اس / کش بک"
 * @return string JSON response
 */
function wto_create_pattern( $message, $category = 255, $description = '' ) {
    // دریافت Api-Key از تنظیمات
    $apikey = wto_get_apikey();

    if (empty($apikey)) {
        return json_encode([
            'status' => 'error',
            'message' => 'کلید دسترسی (Api-Key) را ابتدا در تنظیمات پیامک وارد کنید.'
        ]);
    }

    // اطمینان از اینکه متن به صورت string است
    $message = (string) $message;

	$parse_err    = '';
	$parsed       = wto_parse_pattern_message_placeholders_merged( $message, $parse_err );
	$variables    = $parsed[0];
	$pattern_text = $parsed[1];
	if ( $parse_err !== '' ) {
		return json_encode(
			array(
				'status'  => 'error',
				'message' => $parse_err,
			)
		);
	}

    // ساخت vars بر اساس متغیرهای موجود (طبق مستندات: vars با فیلد var)
    $vars = [];

    foreach ( $variables as $var ) {
		$tl   = wto_tracking_pattern_var_type_length( $var );
		$vars[] = array(
			'var'    => $var,
			'type'   => $tl['type'],
			'length' => $tl['length'],
		);
    }

    $curl = curl_init();

    $body = array(
        'text' => $pattern_text,      // متن پیام با جای‌گذاری‌ها
        'share' => 0,            // required: boolean (1/0) - 0 برای اختصاصی
        'website' => get_site_url(), // آدرس سایت
    );
    $valid_categories = array( 1, 2, 3, 255 );
    if ( in_array( (int) $category, $valid_categories, true ) ) {
        $body['category'] = (int) $category;
    }

    // اضافه کردن vars فقط اگر متغیری وجود داشته باشد
    if (!empty($vars)) {
        $body['vars'] = $vars;
    }

    // v3.17.3: همیشه description برندشده ارسال می‌کنیم — تا admin فراز بداند
    // این پترن از کدام بخش افزونه فراز اس ام اس آمده.
    $body['description'] = $description !== ''
        ? sanitize_text_field( $description )
        : 'افزونه فراز اس ام اس';
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/patterns',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Api-Key: ' . $apikey
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
}

/**
 * آپدیت پترن موجود در سامانه پیامکی (PUT)
 *
 * @param string $pattern_code کد پترن موجود
 * @param string $message متن جدید پیام
 * @param int|null   $category    دسته پترن: 1=otp, 2=club, 3=order, 255=others (اختیاری)
 * @param string     $description توضیح پترن (اختیاری، PUT /ws/v1/patterns/{code})
 * @return string JSON response
 */
function wto_update_pattern( $pattern_code, $message, $category = null, $description = '' ) {
	$apikey = wto_get_apikey();
	if ( empty( $apikey ) ) {
		return json_encode( array( 'status' => 'error', 'message' => 'کلید دسترسی (Api-Key) را ابتدا در تنظیمات پیامک وارد کنید.' ) );
	}
	if ( empty( trim( $pattern_code ) ) ) {
		return json_encode( array( 'status' => 'error', 'message' => 'کد پترن برای آپدیت مشخص نیست.' ) );
	}
	$message = (string) $message;
	$parse_err    = '';
	$parsed       = wto_parse_pattern_message_placeholders_merged( $message, $parse_err );
	$variables    = $parsed[0];
	$pattern_text = $parsed[1];
	if ( $parse_err !== '' ) {
		return json_encode( array( 'status' => 'error', 'message' => $parse_err ) );
	}
	$vars = array();
	foreach ( $variables as $var ) {
		$tl     = wto_tracking_pattern_var_type_length( $var );
		$vars[] = array( 'var' => $var, 'type' => $tl['type'], 'length' => $tl['length'] );
	}
	$website = wp_parse_url( get_site_url(), PHP_URL_HOST );
	if ( empty( $website ) ) {
		$website = get_site_url();
	}
	$body = array(
		'text'    => $pattern_text,
		'share'   => 0,
		'website' => $website,
	);
	if ( $description !== '' ) {
		$body['description'] = sanitize_text_field( $description );
	}
	$valid_categories = array( 1, 2, 3, 255 );
	if ( $category !== null && in_array( (int) $category, $valid_categories, true ) ) {
		$body['category'] = (int) $category;
	}
	if ( ! empty( $vars ) ) {
		$body['vars'] = $vars;
	}
	$url = 'https://api.iranpayamak.com/ws/v1/patterns/' . rawurlencode( $pattern_code );
	$curl = curl_init();
	curl_setopt_array( $curl, array(
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'PUT',
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_POSTFIELDS => json_encode( $body, JSON_UNESCAPED_UNICODE ),
		CURLOPT_HTTPHEADER => array( 'Accept: application/json', 'Content-Type: application/json', 'Api-Key: ' . $apikey ),
	) );
	$response = curl_exec( $curl );
	$http_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
	curl_close( $curl );
	if ( $http_code !== 200 && $http_code !== 201 ) {
		$data = json_decode( $response, true );
		$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'خطا در آپدیت پترن (کد ' . $http_code . ')';
		$error_code = wto_is_pattern_not_found_message( $msg ) ? 'pattern_not_found' : 'update_failed';
		return json_encode( array( 'status' => 'error', 'message' => $msg, 'error_code' => $error_code, 'http_code' => (int) $http_code ) );
	}
	return $response;
}

/**
 * ساخت پترن برای افزونه پیامک فارسی
 * استخراج خودکار متغیرها از متن و تبدیل به فرمت API
 *
 * @param string $message متن پیام با متغیرهای {variable}
 * @param int $category دسته پترن: 1=otp, 2=club, 3=order, 255=others (پیش‌فرض 3)
 * @return string JSON response
 */
function wto_create_pattern_for_pwsms( $message, $category = 3, $description = '' ) {
    // دریافت Api-Key از تنظیمات افزونه پیامک فارسی
    $apikey = wto_get_pwsms_apikey();
    
    if (empty($apikey)) {
        return json_encode([
            'status' => 'error',
            'message' => 'کلید دسترسی (Api-Key) را ابتدا در تنظیمات پیامک فارسی وارد کنید.'
        ]);
    }

    // اطمینان از اینکه متن به صورت string است و خطوط جدید حفظ می‌شوند
    $message = (string) $message;

	$parse_err    = '';
	$parsed       = wto_parse_pattern_message_placeholders_merged( $message, $parse_err );
	$variables    = $parsed[0];
	$pattern_text = $parsed[1];
	if ( $parse_err !== '' ) {
		return json_encode(
			array(
				'status'  => 'error',
				'message' => $parse_err,
			)
		);
	}

    // ساخت vars بر اساس متغیرهای موجود (طبق مستندات: vars با فیلد var)
    $vars = [];

    foreach ( $variables as $var ) {
		$tl   = wto_tracking_pattern_var_type_length( $var, true );
		$vars[] = array(
			'var'    => $var,
			'type'   => $tl['type'],
			'length' => $tl['length'],
		);
    }

    $website_url = get_site_url();
    
    $body = array(
        'text' => $pattern_text,
        'share' => 0,  // تغییر از 1 به 0 (اختصاصی)
        'website' => $website_url,
    );
    $valid_categories = array( 1, 2, 3, 255 );
    if ( in_array( (int) $category, $valid_categories, true ) ) {
        $body['category'] = (int) $category;
    }
    
    if (!empty($vars)) {
        $body['vars'] = $vars;
    }
    
    // v3.17.3: description برندشده — به جای متن انگلیسی قبلی
    $body['description'] = $description !== ''
        ? sanitize_text_field( $description )
        : 'افزونه فراز اس ام اس / پیامک حرفه‌ای ووکامرس';

    $curl = curl_init();

    // JSON encode با اطمینان از اینکه boolean به true/false تبدیل می‌شه
    $json_body = json_encode($body, JSON_UNESCAPED_UNICODE);
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/patterns',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS => $json_body,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Api-Key: ' . $apikey
        ),
    ));

    $response = curl_exec($curl);
    
    // بررسی خطای curl
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        curl_close($curl);
        return json_encode([
            'status' => 'error',
            'message' => 'خطا در ارسال درخواست: ' . $error
        ]);
    }
    
    curl_close($curl);

	return $response;
}

/**
 * آپدیت پترن موجود برای افزونه پیامک فارسی (PUT)
 *
 * @param string $pattern_code کد پترن موجود
 * @param string $message متن جدید پیام
 * @param int|null $category دسته پترن: 1=otp, 2=club, 3=order, 255=others (اختیاری)
 * @return string JSON response
 */
function wto_update_pattern_for_pwsms( $pattern_code, $message, $category = null ) {
	$apikey = wto_get_pwsms_apikey();
	if ( empty( $apikey ) ) {
		return json_encode( array( 'status' => 'error', 'message' => 'کلید دسترسی را در تنظیمات پیامک فارسی وارد کنید.' ) );
	}
	if ( empty( trim( $pattern_code ) ) ) {
		return json_encode( array( 'status' => 'error', 'message' => 'کد پترن برای آپدیت مشخص نیست.' ) );
	}
	$message = (string) $message;
	$parse_err    = '';
	$parsed       = wto_parse_pattern_message_placeholders_merged( $message, $parse_err );
	$variables    = $parsed[0];
	$pattern_text = $parsed[1];
	if ( $parse_err !== '' ) {
		return json_encode( array( 'status' => 'error', 'message' => $parse_err ) );
	}
	$vars = array();
	foreach ( $variables as $var ) {
		$tl     = wto_tracking_pattern_var_type_length( $var, true );
		$vars[] = array( 'var' => $var, 'type' => $tl['type'], 'length' => $tl['length'] );
	}
	$body = array( 'text' => $pattern_text, 'share' => 0, 'website' => get_site_url() );
	$valid_categories = array( 1, 2, 3, 255 );
	if ( $category !== null && in_array( (int) $category, $valid_categories, true ) ) {
		$body['category'] = (int) $category;
	}
	if ( ! empty( $vars ) ) { $body['vars'] = $vars; }
	$body['description'] = 'Pattern created automatically from SMS template';
	$url = 'https://api.iranpayamak.com/ws/v1/patterns/' . rawurlencode( $pattern_code );
	$curl = curl_init();
	curl_setopt_array( $curl, array(
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'PUT',
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_POSTFIELDS => json_encode( $body, JSON_UNESCAPED_UNICODE ),
		CURLOPT_HTTPHEADER => array( 'Accept: application/json', 'Content-Type: application/json', 'Api-Key: ' . $apikey ),
	) );
	$response = curl_exec( $curl );
	$http_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
	curl_close( $curl );
	if ( $http_code !== 200 && $http_code !== 201 ) {
		$data = json_decode( $response, true );
		$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'خطا در آپدیت پترن (کد ' . $http_code . ')';
		$error_code = wto_is_pattern_not_found_message( $msg ) ? 'pattern_not_found' : 'update_failed';
		return json_encode( array( 'status' => 'error', 'message' => $msg, 'error_code' => $error_code, 'http_code' => (int) $http_code ) );
	}
	return $response;
}

/**
 * بررسی اینکه متن خطا نشان‌دهنده حذف/عدم وجود پترن است.
 *
 * @param mixed $message
 * @return bool
 */
function wto_is_pattern_not_found_message( $message ) {
	if ( is_array( $message ) ) {
		$message = wp_json_encode( $message, JSON_UNESCAPED_UNICODE );
	}
	$message = is_string( $message ) ? strtolower( trim( $message ) ) : '';
	if ( $message === '' ) {
		return false;
	}
	$needles = array(
		'pattern not found',
		'not found',
		'404',
		'کد پترن یافت نشد',
		'پترن یافت نشد',
		'پترن وجود ندارد',
		'الگو یافت نشد',
	);
	foreach ( $needles as $needle ) {
		if ( strpos( $message, strtolower( $needle ) ) !== false ) {
			return true;
		}
	}
	return false;
}

/**
 * بررسی اینکه پاسخ API نشان‌دهنده حذف/عدم وجود پترن است.
 *
 * @param mixed $decoded_response
 * @return bool
 */
function wto_is_pattern_not_found_response( $decoded_response ) {
	if ( ! is_array( $decoded_response ) ) {
		return false;
	}
	if ( isset( $decoded_response['error_code'] ) && $decoded_response['error_code'] === 'pattern_not_found' ) {
		return true;
	}
	if ( isset( $decoded_response['http_code'] ) && (int) $decoded_response['http_code'] === 404 ) {
		return true;
	}
	$message = '';
	if ( isset( $decoded_response['message'] ) ) {
		$message = $decoded_response['message'];
	} elseif ( isset( $decoded_response['messages'] ) ) {
		$message = $decoded_response['messages'];
	}
	return wto_is_pattern_not_found_message( $message );
}

/**
 * دریافت جزئیات پترن از API برای بخش رهگیری
 * 
 * @param string $pattern_code کد پترن
 * @return string JSON response
 */
function wto_get_pattern_details($pattern_code) {
	// دریافت Api-Key از تنظیمات افزونه رهگیری
	$apikey = wto_get_apikey();
	
	if (empty($apikey) || empty($pattern_code)) {
		return json_encode([
			'status' => 'error',
			'message' => 'کلید API یا کد پترن خالی است.'
		]);
	}
	
	return wto_get_pattern_details_with_apikey($apikey, $pattern_code);
}

/**
 * دریافت جزئیات پترن از API برای بخش پیامک فارسی
 * 
 * @param string $pattern_code کد پترن
 * @return string JSON response
 */
function wto_get_pattern_details_for_pwsms($pattern_code) {
	// دریافت Api-Key از تنظیمات افزونه پیامک فارسی
	$apikey = wto_get_pwsms_apikey();
	
	if (empty($apikey) || empty($pattern_code)) {
		return json_encode([
			'status' => 'error',
			'message' => 'کلید API یا کد پترن خالی است.'
		]);
	}
	
	return wto_get_pattern_details_with_apikey($apikey, $pattern_code);
}

/**
 * دریافت جزئیات پترن از API با استفاده از apikey مشخص
 * 
 * @param string $apikey کلید API
 * @param string $pattern_code کد پترن
 * @return string JSON response
 */
function wto_get_pattern_details_with_apikey($apikey, $pattern_code) {
	if (empty($apikey) || empty($pattern_code)) {
		return json_encode([
			'status' => 'error',
			'message' => 'کلید API یا کد پترن خالی است.'
		]);
	}

	$curl = curl_init();

	curl_setopt_array($curl, array(
		CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/patterns/' . urlencode($pattern_code),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'GET',
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_HTTPHEADER => array(
			'Accept: application/json',
			'Api-Key: ' . $apikey
		),
	));

	$response = curl_exec($curl);
	
	// بررسی خطای curl
	if (curl_errno($curl)) {
		$error = curl_error($curl);
		curl_close($curl);
		return json_encode([
			'status' => 'error',
			'message' => 'خطا در ارسال درخواست: ' . $error
		]);
	}
	
	curl_close($curl);

	return $response;
}


function wto_send_scheduled_sms($order_id , $date_to_send ) {
 
    $apikey = get_option( 'wto_apikey', '' );
	$sender  = get_option( 'wto_sender', '' );
    $poll_pattern  = get_option( 'wto_poll_pattern', '' );
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    $phone = $order->get_billing_phone();
    
    // تبدیل شماره تلفن از فرمت بین‌المللی به فرمت داخلی
    $phone = wto_normalize_phone($phone);

 
	if (empty( $apikey ) || empty($poll_pattern) || empty($phone) ) {
		return;
	}

$order_id_str = (string) $order_id;

// Build the per-order review URL for the %review_url% pattern variable.
$review_url = function_exists( 'wto_survey_build_review_url' )
	? wto_survey_build_review_url( $order_id )
	: add_query_arg( 'order_id', (int) $order_id, home_url( '/orderreview/' ) );

$first_name = (string) $order->get_billing_first_name();
$last_name  = (string) $order->get_billing_last_name();
$full_name  = trim( $first_name . ' ' . $last_name );

$body = array(
    'code'        => $poll_pattern,
    'attributes'  => array(
        'order_id'   => $order_id_str,
        'name'       => $first_name,
        'family'     => $last_name,
        'full_name'  => $full_name !== '' ? $full_name : 'مشتری گرامی',
        'review_url' => $review_url,
        'sitename'   => get_bloginfo( 'name' ),
    ),
    'recipient'     => $phone,
    'line_number'   => $sender,
    'number_format' => 'english',
    'schedule'      => $date_to_send,
);

$curl = curl_init();

curl_setopt_array($curl, array(
   CURLOPT_URL => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
   CURLOPT_RETURNTRANSFER => true,
   CURLOPT_ENCODING => '',
   CURLOPT_MAXREDIRS => 5,
   CURLOPT_CONNECTTIMEOUT => 5,
   CURLOPT_TIMEOUT => 15,
   CURLOPT_FOLLOWLOCATION => true,
   CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
   CURLOPT_CUSTOMREQUEST => 'POST',
   CURLOPT_SSL_VERIFYPEER => true,
   CURLOPT_SSL_VERIFYHOST => 2,
   CURLOPT_POSTFIELDS => json_encode( $body, JSON_UNESCAPED_UNICODE ),
   CURLOPT_HTTPHEADER => array(
      'Accept: application/json',
      'Content-Type: application/json',
      'Api-Key: ' . $apikey
   ),
));

$response  = curl_exec( $curl );
$http_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
curl_close( $curl );

// Log the dispatch in the survey log (used by the stats dashboard).
$api_success = false;
if ( $response !== false && $http_code >= 200 && $http_code < 300 ) {
	$decoded = json_decode( (string) $response, true );
	$api_success = is_array( $decoded ) && ( ! isset( $decoded['status'] ) || $decoded['status'] !== 'error' );
}
// نگهبانِ اتصال: response === false یعنی cURL اصلاً به مقصد نرسید (مسدودسازی/قطعی)؛
// علت را برای اعلانِ پیشخوان ثبت کن. پاسخِ HTTP (هر کدی) یعنی اتصال سالم است.
if ( $response === false && function_exists( 'wto_connectivity_note_failure' ) ) {
	wto_connectivity_note_failure( 'curl: no response from ' . ( defined( 'WTO_SMS_API_HOST' ) ? WTO_SMS_API_HOST : 'api.iranpayamak.com' ) );
} elseif ( $response !== false && function_exists( 'wto_connectivity_note_success' ) ) {
	wto_connectivity_note_success();
}
if ( function_exists( 'wto_survey_log_dispatch' ) ) {
	wto_survey_log_dispatch( (int) $order_id, $phone, $first_name, $last_name, (string) $date_to_send, $api_success );
}

}


/**
 * ارسال پیامک پترن با آرایه attributes (برای بخش‌های غیرسفارش مثل دیدگاه)
 *
 * @param string $recipient شماره گیرنده
 * @param string $pattern_code کد پترن
 * @param array  $attributes آرایه متغیرهای پترن (کلید => مقدار)
 * @param string $sender خط ارسال‌کننده (خالی = از تنظیمات)
 * @return string|true 'success' یا رشته خطا
 */
function wto_send_pattern_sms_raw( $recipient, $pattern_code, $attributes = array(), $sender = '' ) {
	$apikey = get_option( 'wto_apikey', '' );
	if ( empty( $sender ) ) {
		$sender = get_option( 'wto_sender', '' );
	}
	if ( empty( $apikey ) || empty( $pattern_code ) || empty( $recipient ) ) {
		return 'missing_params';
	}
	if ( function_exists( 'wto_normalize_phone' ) ) {
		$recipient = wto_normalize_phone( $recipient );
	}
	$attributes = array_map( 'strval', $attributes );
	$body = array(
		'code'          => $pattern_code,
		'recipient'     => $recipient,
		'attributes'    => $attributes,
		'line_number'   => $sender,
		'number_format' => 'english',
	);
	$curl = curl_init();
	curl_setopt_array( $curl, array(
		CURLOPT_URL            => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
		CURLOPT_RETURNTRANSFER => true,
		// timeout اجباری: این تماس روی مسیر checkout/سفارش اجرا می‌شود؛ بدون timeout،
		// کندیِ سرور پیامک می‌تواند worker را قفل کند و تکمیل سفارش/پرداخت را بخواباند.
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_CUSTOMREQUEST  => 'POST',
		CURLOPT_POSTFIELDS     => json_encode( $body, JSON_UNESCAPED_UNICODE ),
		CURLOPT_HTTPHEADER     => array(
			'Accept: application/json',
			'Content-Type: application/json',
			'Api-Key: ' . $apikey,
		),
	) );
	$response = curl_exec( $curl );
	if ( curl_errno( $curl ) ) {
		$err = curl_error( $curl );
		curl_close( $curl );
		return 'curl_error:' . $err;
	}
	curl_close( $curl );
	$data = json_decode( $response, true );
	if ( $data && isset( $data['status'] ) && $data['status'] === 'success' ) {
		// سیگنالِ آمارِ استفاده (ناشناس، non-blocking) — حجمِ ارسالِ پترن.
		if ( function_exists( 'wto_matomo_track_event' ) ) {
			wto_matomo_track_event( 'SMS', 'pattern_sent', wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		return 'success';
	}
	if ( $data && isset( $data['status'] ) && $data['status'] === 'error' ) {
		return 'api_error:' . ( isset( $data['message'] ) ? $data['message'] : 'خطای نامشخص' );
	}
	return $response;
}

/**
 * ارسال پیامک پترن کد رهگیری بر اساس نوع پست (پست/تیپاکس/سایر).
 *
 * این تابع از v3.13.7 از multi-pattern پشتیبانی می‌کند. پترن و پیام بر اساس
 * carrier از این option ها خوانده می‌شوند:
 *
 *   post:  wto_pattern_post  + wto_message_post   (fallback: wto_pattern + wto_message — legacy)
 *   tipax: wto_pattern_tipax + wto_message_tipax
 *   other: wto_pattern_other + wto_message_other
 *
 * @param int    $order_id      شناسه سفارش ووکامرس
 * @param string $tracking_code کد رهگیری وارد شده
 * @param string $carrier       'post' | 'tipax' | 'other' (پیش‌فرض: 'post')
 */
function wto_send_pattern_sms($order_id, $tracking_code, $carrier = 'post') {

    $apikey  = get_option('wto_apikey', '');
    $sender  = get_option('wto_sender', '');

    // انتخاب پترن و پیام بر اساس carrier — با fallback به مقادیر legacy برای post.
    $carrier = in_array( $carrier, array( 'post', 'tipax', 'other' ), true ) ? $carrier : 'post';
    if ( $carrier === 'post' ) {
        $pattern = get_option( 'wto_pattern_post', '' );
        $message = get_option( 'wto_message_post', '' );
        // Backward compatibility: اگر کاربر فقط option قدیمی wto_pattern را تنظیم کرده، استفاده کن.
        if ( $pattern === '' ) { $pattern = get_option( 'wto_pattern', '' ); }
        if ( $message === '' ) { $message = get_option( 'wto_message', '' ); }
    } else {
        $pattern = get_option( 'wto_pattern_' . $carrier, '' );
        $message = get_option( 'wto_message_' . $carrier, '' );
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $phone    = $order->get_billing_phone();
    
    // تبدیل شماره تلفن از فرمت بین‌المللی به فرمت داخلی
    $phone = wto_normalize_phone($phone);
    
    $fullname = $order->get_formatted_billing_full_name();
	$b_first  = (string) $order->get_billing_first_name();
	$b_last   = (string) $order->get_billing_last_name();

    // بررسی وجود موارد ضروری
    if (empty($apikey) || empty($pattern) || empty($phone)) {
        return;
    }

    // لیست تمام مقادیر قابل استفاده
    $all_values = [
        'customer_fullname' => $fullname,
		'b_first_name'      => $b_first,
		'b_last_name'       => $b_last,
        'order_id'          => (string) $order_id,
        'tracking_code'     => $tracking_code,
    ];

	$var_keys   = wto_tracking_pattern_var_keys_from_message( $message );
    $attributes = [];

	foreach ( $var_keys as $key ) {
		if ( isset( $all_values[ $key ] ) ) {
			$attributes[ $key ] = $all_values[ $key ];
		}
	}

    $body = array(
        'code'          => $pattern,
        'recipient'     => $phone,
        'attributes'    => $attributes,
        'line_number'   => $sender,
        'number_format' => 'english'
    );


    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL            => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
        CURLOPT_RETURNTRANSFER => true,
        // timeout اجباری: روی مسیر checkout/سفارش اجرا می‌شود — بدون timeout خطر قفل‌شدن worker.
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Api-Key: ' . $apikey
        ),
    ));

    $response = curl_exec($curl);
    
    // بررسی خطای curl
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        curl_close($curl);
        return 'curl_error:' . $error;
    }
    
    curl_close($curl);
    
    // بررسی پاسخ API
    $response_data = json_decode($response, true);
    if ($response_data && isset($response_data['status']) && $response_data['status'] === 'error') {
        $error_message = isset($response_data['message']) ? $response_data['message'] : 'خطای نامشخص';
        
        // اگر خطا مربوط به "داده درخواستی یافت نشد" است، احتمالاً پترن تایید نشده
        if (strpos($error_message, 'داده درخواستی یافت نشد') !== false || 
            strpos($error_message, 'درخواستی یافت نشد') !== false) {
            return 'pattern_not_approved';
        }
        
        return 'api_error:' . $error_message;
    }
    
    // اگر موفقیت‌آمیز بود
    if ($response_data && isset($response_data['status']) && $response_data['status'] === 'success') {
        return 'success';
    }
    
    return $response;
}



function wto_send_ticket( $subject, $ticket ) {
	$uname = get_option( 'wto_uname', '' );
	$pass  = get_option( 'wto_pass', '' );
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
	$handler = curl_init($url);
	curl_setopt($handler, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($handler, CURLOPT_POSTFIELDS, $param);
	curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
	$response2 = curl_exec($handler);
	$response2 = json_decode($response2);
	$res_code = $response2[0];
	$res_data = $response2[1];
	if (!is_numeric($res_data)){
		return $res_data;
	}
	return true;
}
