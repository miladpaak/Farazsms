<?php
/*
*
*	***** Faraz woo Scheduled sms *****
*
*	Ajax Request
*	
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}
/*
Ajax Requests
*/
add_action( 'wp_ajax_wto_save_credentials', 'wto_ajax_save_credentials' );
// nopriv intentionally NOT registered — admin-only endpoint that writes to wp_options.
function wto_ajax_save_credentials() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		return;
	}
	if ( ! check_ajax_referer( 'wto_save_settings', 'wto_save_nonce', false ) ) {
		wp_send_json_error( 'نشست منقضی شده است؛ صفحه را یک‌بار رفرش کنید.' );
		return;
	}
	$form_context = isset( $_POST['form'] ) ? sanitize_text_field( $_POST['form'] ) : '';
	$apikey       = isset( $_POST['apikey'] ) ? $_POST['apikey'] : '';
	$pattern      = isset( $_POST['pattern'] ) ? $_POST['pattern'] : '';
	$sender       = isset( $_POST['sender'] ) ? $_POST['sender'] : '';
	$poll_pattern = isset( $_POST['poll_pattern'] ) ? $_POST['poll_pattern'] : '';
	$send_poll_sms = isset( $_POST['send_poll_sms'] ) ? $_POST['send_poll_sms'] : '0';
	$send_time    = $send_poll_sms === '1' ? ( isset( $_POST['send_time'] ) ? $_POST['send_time'] : '' ) : '';
	$send_status  = $send_poll_sms === '1' ? ( isset( $_POST['send_status'] ) ? $_POST['send_status'] : '' ) : '';

	// فقط تنظیمات: کلید دسترسی و خط ارسال کننده
	if ( $form_context === 'settings' ) {
		if ( empty( trim( $apikey ) ) ) {
			wp_send_json_error( 'لطفا کلید دسترسی را وارد کنید' );
			return;
		}
		$clean_apikey = sanitize_text_field( $apikey );
		update_option( 'wto_apikey', $clean_apikey );
		update_option( 'wto_sender', sanitize_text_field( $sender ) );
		$show_credit = isset( $_POST['show_credit_in_admin_bar'] ) ? sanitize_text_field( $_POST['show_credit_in_admin_bar'] ) : '0';
		update_option( 'wto_show_credit_in_admin_bar', ( $show_credit === '1' ) ? '1' : '0' );

		// همگام‌سازی دفاعی به افزونه پیامک ووکامرس — حتی اگر update_option_X hook فعال نشد
		// (مثلاً به دلیل اینکه مقدار تغییری نکرده)، این مسیر مستقیم تضمین می‌کند که کلید در
		// sms_main_settings هم نوشته شود تا کاربر مجبور به وارد کردن دوباره نشود.
		if ( function_exists( 'wto_sync_apikey_to_pwsms' ) ) {
			wto_sync_apikey_to_pwsms( '', $clean_apikey );
		}

		// Cache اعتبار پاک شود تا UI تازه‌ترین مقدار را بگیرد.
		delete_transient( 'wto_credit_' . md5( $clean_apikey ) );

		wp_send_json_success();
		return;
	}

	// تنظیمات OTP (صفحه اطلاع رسانی گرویتی - المنتور)
	if ( $form_context === 'otp' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
			return;
		}
		if ( isset( $_POST['wto_otp_pattern'] ) ) {
			update_option( 'wto_otp_pattern', sanitize_text_field( wp_unslash( $_POST['wto_otp_pattern'] ) ), false );
		}
		if ( isset( $_POST['wto_otp_message'] ) ) {
			update_option( 'wto_otp_message', wp_kses_post( wp_unslash( $_POST['wto_otp_message'] ) ), false );
		}
		wp_send_json_success();
		return;
	}

	// کد رهگیری — Multi-pattern (v3.13.7+): پشتیبانی از سه پترن جداگانه برای post/tipax/other.
	// فرم جدید carrier را پست می‌کند و pattern + message هر دو فیلد جداگانه ارسال می‌شوند.
	if ( $form_context === 'tracking' ) {
		$carrier = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : '';
		$msg     = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';

		if ( in_array( $carrier, array( 'post', 'tipax', 'other' ), true ) ) {
			update_option( 'wto_pattern_' . $carrier, sanitize_text_field( $pattern ), false );
			update_option( 'wto_message_' . $carrier, $msg, false );
			// برای backward compat، post هم در wto_pattern و wto_message قدیمی ذخیره می‌شود
			// تا کد قدیمی که هنوز به این options اشاره می‌کند کار کند.
			if ( $carrier === 'post' ) {
				update_option( 'wto_pattern', sanitize_text_field( $pattern ), false );
				update_option( 'wto_message', $msg, false );
			}
		} else {
			// Fallback به رفتار قبلی برای backward compatibility با کلاینت‌های قدیمی.
			update_option( 'wto_pattern', sanitize_text_field( $pattern ), false );
		}
		wp_send_json_success();
		return;
	}

	// فرم دیدگاه سایت: ذخیره همه تنظیمات دیدگاه (فقط مدیر)
	if ( $form_context === 'comments' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
			return;
		}
		if ( isset( $_POST['wto_comment_admin_phones'] ) ) {
			update_option( 'wto_comment_admin_phones', sanitize_text_field( wp_unslash( $_POST['wto_comment_admin_phones'] ) ), false );
		}
		if ( isset( $_POST['wto_comment_admin_pattern'] ) ) {
			update_option( 'wto_comment_admin_pattern', sanitize_text_field( wp_unslash( $_POST['wto_comment_admin_pattern'] ) ), false );
		}
		if ( isset( $_POST['wto_comment_admin_message'] ) ) {
			update_option( 'wto_comment_admin_message', wp_kses_post( wp_unslash( $_POST['wto_comment_admin_message'] ) ), false );
		}
		if ( isset( $_POST['wto_comment_user_approve_pattern'] ) ) {
			update_option( 'wto_comment_user_approve_pattern', sanitize_text_field( wp_unslash( $_POST['wto_comment_user_approve_pattern'] ) ), false );
		}
		if ( isset( $_POST['wto_comment_user_approve_message'] ) ) {
			update_option( 'wto_comment_user_approve_message', wp_kses_post( wp_unslash( $_POST['wto_comment_user_approve_message'] ) ), false );
		}
		if ( isset( $_POST['wto_comment_user_reply_pattern'] ) ) {
			update_option( 'wto_comment_user_reply_pattern', sanitize_text_field( wp_unslash( $_POST['wto_comment_user_reply_pattern'] ) ), false );
		}
		if ( isset( $_POST['wto_comment_user_reply_message'] ) ) {
			update_option( 'wto_comment_user_reply_message', wp_kses_post( wp_unslash( $_POST['wto_comment_user_reply_message'] ) ), false );
		}
		wp_send_json_success();
		return;
	}

	// فرم نظرسنجی: اگر apikey خالی و poll_pattern ارسال شده
	if ( empty( trim( $apikey ) ) && ! empty( $poll_pattern ) ) {
		update_option( 'wto_poll_pattern', sanitize_text_field( $poll_pattern ), false );
		update_option( 'wto_send_poll_sms', sanitize_text_field( $send_poll_sms ), false );
		update_option( 'wto_send_time', sanitize_text_field( $send_time ), false );
		update_option( 'wto_send_status', sanitize_text_field( $send_status ), false );
		wp_send_json_success();
		return;
	}

	if ( empty( trim( $apikey ) ) ) {
		wp_send_json_error( 'لطفا اطلاعات را کامل وارد کنید' );
		return;
	}

	update_option( 'wto_apikey', sanitize_text_field( $apikey ) );
	update_option( 'wto_pattern', sanitize_text_field( $pattern ), false );
	update_option( 'wto_sender', sanitize_text_field( $sender ) );

	if ( isset( $_POST['poll_pattern'] ) ) {
		update_option( 'wto_poll_pattern', sanitize_text_field( $poll_pattern ), false );
		update_option( 'wto_send_poll_sms', sanitize_text_field( $send_poll_sms ), false );
		update_option( 'wto_send_time', sanitize_text_field( $send_time ), false );
		update_option( 'wto_send_status', sanitize_text_field( $send_status ), false );
	}

	wp_send_json_success();
}

add_action('wp_ajax_wto_create_pattern', 'wto_create_pattern_callback');

function wto_create_pattern_callback() {
	// بررسی nonce با check_ajax_referer (خودش خطا را مدیریت می‌کند)
	check_ajax_referer('wto_create_pattern', 'nonce');

	// v3.13.19 BUG FIX: قبل از این، early-return فقط 'message_text' را چک می‌کرد
	// و باعث خطای «متن پیام ارسال نشده است» در صفحه ۳-پترنی کد رهگیری می‌شد
	// (که با کلید 'message' می‌فرستد). حالا هر دو کلید را early-check می‌کنیم.
	if ( ! isset( $_POST['message_text'] ) && ! isset( $_POST['message'] ) ) {
		wp_send_json_error( 'متن پیام ارسال نشده است.' );
		return;
	}

	// دریافت متن پیام — اول 'message_text' (JS قدیمی)، fallback به 'message' (JS جدید کد رهگیری).
	$message = '';
	if ( isset( $_POST['message_text'] ) && $_POST['message_text'] !== '' ) {
		$message = $_POST['message_text'];
	} elseif ( isset( $_POST['message'] ) && $_POST['message'] !== '' ) {
		$message = $_POST['message'];
	}

	// حذف slashes اضافی که WordPress اضافه می‌کند
	$message = wp_unslash($message);
	
	// حفظ خطوط جدید و کاراکترهای خاص، فقط کاراکترهای خطرناک را حذف کن
	$message = wp_check_invalid_utf8($message, true);
	
	if (empty(trim($message))) {
		wp_send_json_error('متن پیام خالی است.');
		return;
	}
	
	// دریافت section_type برای بررسی اینکه آیا باید آدرس سایت اضافه شود
	$section_type = isset($_POST['section_type']) ? sanitize_text_field($_POST['section_type']) : '';
	
	// اگر section_type مربوط به پیامک فارسی است (buyer, super_admin) یا بخش رهگیری (خالی یا tracking) یا دیدگاه (comment)، آدرس سایت را اضافه کن (به‌جز OTP)
	if ( ( in_array($section_type, ['buyer', 'super_admin']) || empty($section_type) || $section_type === 'tracking' || $section_type === 'comment' || strpos($section_type, 'comment') === 0 ) && $section_type !== 'otp' ) {
		$site_url = get_site_url();
		// اضافه کردن آدرس سایت به انتهای متن در یک خط جدید
		$message = rtrim($message) . "\n" . $site_url;
	}
	
	// تعیین کد پترن موجود (در صورت وجود) برای آپدیت به‌جای ساخت جدید
	$status_key = isset($_POST['status_key']) ? sanitize_text_field($_POST['status_key']) : '';
	$existing_code = '';
	$patterns = get_option('wto_patterns', array());
	if ( ! empty( $section_type ) && ! empty( $status_key ) && is_array( $patterns ) && isset( $patterns[ $section_type ][ $status_key ] ) ) {
		$existing_code = trim( $patterns[ $section_type ][ $status_key ] );
	}
	if ( empty( $existing_code ) && $section_type === 'otp' ) {
		$existing_code = trim( get_option( 'wto_otp_pattern', '' ) );
	}
	if ( empty( $existing_code ) && ( $section_type === '' || $section_type === 'tracking' ) ) {
		$existing_code = trim( get_option( 'wto_pattern', '' ) );
	}
	$is_comment_section = ( $section_type === 'comment' || strpos( $section_type, 'comment' ) === 0 );
	if ( empty( $existing_code ) && $is_comment_section ) {
		if ( isset( $_POST['pattern_code'] ) ) {
			$posted = trim( sanitize_text_field( wp_unslash( $_POST['pattern_code'] ) ) );
			if ( $posted !== '' ) {
				$existing_code = $posted;
			}
		}
		if ( empty( $existing_code ) && ! empty( $status_key ) && function_exists( 'wto_get_comment_pattern_code' ) ) {
			$existing_code = wto_get_comment_pattern_code( $status_key );
		}
	}
	$use_update = ! empty( $existing_code );
	
	// تعیین دسته پترن برای API: 1=otp, 3=order, 255=others
	if ( $section_type === 'otp' ) {
		$category = 1;
	} elseif ( in_array( $section_type, array( 'buyer', 'super_admin', 'product_admin' ), true ) || $section_type === '' || $section_type === 'tracking' ) {
		$category = 3;
	} else {
		$category = 255;
	}
	
	// v3.17.3: description همیشه برندشده — هر پترن به admin فراز می‌گوید
	// از کدام بخش افزونه فراز اس ام اس آمده.
	$carrier            = isset( $_POST['carrier'] ) ? sanitize_key( $_POST['carrier'] ) : '';
	$pattern_description = wto_pattern_brand_description( $section_type, $status_key, $carrier );

	// ارسال به IranPayamak: آپدیت پترن موجود یا ساخت جدید
	if ( in_array( $section_type, array( 'buyer', 'super_admin', 'product_admin' ) ) ) {
		$response = $use_update
			? wto_update_pattern_for_pwsms( $existing_code, $message, $category )
			: wto_create_pattern_for_pwsms( $message, $category, $pattern_description );
	} else {
		$response = $use_update
			? wto_update_pattern( $existing_code, $message, $category, $pattern_description )
			: wto_create_pattern( $message, $category, $pattern_description );
	}

	// ریسپانس ممکن است JSON استرینگ باشد → تبدیل به آرایه
	$data = json_decode($response, true);

	// اگر JSON نبود، احتمالاً خطاست
	if (!$data) {
		wp_send_json_error([
			'message' => 'خطا در پردازش پاسخ سرور: ' . substr($response, 0, 200),
			'raw_response' => $response
		]);
		return;
	}

	// اگر آپدیت انجام شده و پترن در پنل حذف شده باشد، به صورت خودکار پترن جدید بساز
	if ( $use_update && isset( $data['status'] ) && $data['status'] !== 'success' && function_exists( 'wto_is_pattern_not_found_response' ) && wto_is_pattern_not_found_response( $data ) ) {
		if ( in_array( $section_type, array( 'buyer', 'super_admin', 'product_admin' ), true ) ) {
			$response = wto_create_pattern_for_pwsms( $message, $category, $pattern_description );
		} else {
			$response = wto_create_pattern( $message, $category, $pattern_description );
		}
		$data = json_decode( $response, true );
		if ( ! $data ) {
			wp_send_json_error([
				'message' => 'خطا در پردازش پاسخ ساخت پترن جدید: ' . substr($response, 0, 200),
				'raw_response' => $response
			]);
			return;
		}
	}

	// بررسی وضعیت success
	if (isset($data['status']) && $data['status'] === 'success') {
		// استخراج code از: data → code (در آپدیت ممکن است خالی باشد، از کد قبلی استفاده می‌کنیم)
		$pattern_code = null;
		if (isset($data['data']) && is_string($data['data'])) {
			$pattern_code = sanitize_text_field($data['data']);
		} elseif (isset($data['data']['code'])) {
			$pattern_code = sanitize_text_field($data['data']['code']);
		}
		if ( empty( $pattern_code ) && $use_update && ! empty( $existing_code ) ) {
			$pattern_code = $existing_code;
		}
		// $section_type قبلاً در خط 67 تعریف شده است
		
		// اگر pattern_code پیدا شد، در option ذخیره کن
		if (!empty($pattern_code)) {
			// پترن OTP برای فرم‌های المنتور و گرویتی
			if ( $section_type === 'otp' ) {
				update_option( 'wto_otp_pattern', sanitize_text_field( $pattern_code ), false );
			}
			// به‌روزرسانی wto_pattern با کد پترن جدید — فقط برای بخش رهگیری (نه دیدگاه و نه پیامک فارسی)
			if ( ! $is_comment_section && $section_type !== 'otp' ) {
				// Multi-pattern (v3.13.7+): اگر carrier در POST آمده، در option اختصاصی همان carrier ذخیره کن.
				$carrier = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : '';
				if ( in_array( $carrier, array( 'post', 'tipax', 'other' ), true ) ) {
					update_option( 'wto_pattern_' . $carrier, sanitize_text_field( $pattern_code ), false );
					if ( ! empty( $message ) ) {
						update_option( 'wto_message_' . $carrier, wp_unslash( $message ), false );
					}
					// post همیشه در wto_pattern قدیمی هم mirror می‌شود (backward compat).
					if ( $carrier === 'post' ) {
						update_option( 'wto_pattern', sanitize_text_field( $pattern_code ), false );
					}
				} else {
					// Fallback به رفتار قبلی.
					update_option( 'wto_pattern', sanitize_text_field( $pattern_code ), false );
				}
			}
			if ( $is_comment_section && ! empty( $status_key ) && function_exists( 'wto_comment_pattern_option_name' ) ) {
				$comment_option = wto_comment_pattern_option_name( $status_key );
				if ( $comment_option !== '' ) {
					update_option( $comment_option, sanitize_text_field( $pattern_code ), false );
				}
			}
			// ذخیره متن پیام در wto_message - فقط برای بخش رهگیری (نه پیامک فارسی و نه دیدگاه)
			if (!empty($message) && !in_array($section_type, ['buyer', 'super_admin', 'product_admin']) && ! $is_comment_section) {
				update_option('wto_message', wp_unslash($message), false);
			}
			// اگر status_key وجود داشت، در array structure هم ذخیره کن
			if (!empty($status_key)) {
			// دریافت array موجود
			$patterns = get_option('wto_patterns', []);
			
			// اطمینان از وجود ساختار
			if (!is_array($patterns)) {
				$patterns = [];
			}
			if (!isset($patterns[$section_type])) {
				$patterns[$section_type] = [];
			}
			
			// ذخیره کد پترن برای این وضعیت
			$patterns[$section_type][$status_key] = $pattern_code;
			
			// ذخیره در option
			update_option('wto_patterns', $patterns, false);
			}
		}
 
		
		if ($pattern_code) {
			$success_msg = $use_update
				? 'پترن با موفقیت به‌روزرسانی شد.'
				: 'پترن با موفقیت ایجاد شد و تنظیمات به‌روزرسانی شد.';
			wp_send_json_success([
				'message' => $success_msg,
				'pattern_code' => $pattern_code,
				'updated' => $use_update,
				'status_key' => $status_key,
				'section_type' => $section_type,
				'response' => $data
			]);
			return;
		}

		wp_send_json_error([
			'message' => 'فیلد code در پاسخ پیدا نشد.',
			'response' => $data
		]);
		return;
	}

	// اگر status != success بود
	$error_message = isset($data['message']) ? $data['message'] : 'خطای نامشخص در ساخت پترن.';
	if (isset($data['messages']) && is_array($data['messages'])) {
		$error_message = implode(', ', $data['messages']);
	}
	
	wp_send_json_error([
		'message' => $error_message,
		'response' => $data
	]);
}

/**
 * AJAX endpoint برای دریافت جزئیات پترن
 */
add_action('wp_ajax_wto_get_pattern_details', 'wto_get_pattern_details_callback');

function wto_get_pattern_details_callback() {
	// بررسی nonce
	if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wto_get_pattern_details')) {
		wp_send_json_error('خطا در احراز هویت.');
		return;
	}

	if (!isset($_POST['pattern_code']) || empty($_POST['pattern_code'])) {
		wp_send_json_error('کد پترن ارسال نشده است.');
		return;
	}

	$pattern_code = sanitize_text_field($_POST['pattern_code']);
	$section_type = isset($_POST['section_type']) ? sanitize_text_field($_POST['section_type']) : '';
	
	// دریافت جزئیات پترن از API
	// اگر section_type مربوط به پیامک فارسی است، از wto_get_pattern_details_for_pwsms استفاده کن
	// در غیر این صورت از wto_get_pattern_details استفاده کن (برای بخش رهگیری)
	if (in_array($section_type, ['buyer', 'super_admin', 'product_admin'])) {
		// استفاده از تابع دریافت جزئیات پترن برای پیامک فارسی (apikey از PWSMS گرفته می‌شود)
		$response = wto_get_pattern_details_for_pwsms($pattern_code);
	} else {
		// استفاده از تابع دریافت جزئیات پترن برای بخش رهگیری (apikey از wto_apikey گرفته می‌شود)
		$response = wto_get_pattern_details($pattern_code);
	}
	
	// ریسپانس ممکن است JSON استرینگ باشد → تبدیل به آرایه
	$data = json_decode($response, true);
	
	// اگر JSON نبود، احتمالاً خطاست
	if (!$data) {
		wp_send_json_error([
			'message' => 'خطا در پردازش پاسخ سرور: ' . substr($response, 0, 200),
			'raw_response' => $response
		]);
		return;
	}
	
	// بررسی وضعیت success
	if (isset($data['status']) && $data['status'] === 'success') {
		wp_send_json_success([
			'pattern_code' => $pattern_code,
			'data' => $data['data'] ?? null,
			'response' => $data
		]);
		return;
	}
	
	// اگر status != success بود
	$error_message = isset($data['message']) ? $data['message'] : 'خطای نامشخص در دریافت جزئیات پترن.';
	if (isset($data['messages']) && is_array($data['messages'])) {
		$error_message = implode(', ', $data['messages']);
	}
	
	wp_send_json_error([
		'message' => $error_message,
		'response' => $data
	]);
}



add_action( 'wp_ajax_wto_save_wc_sms_data', 'wto_save_wc_sms_data' );
// nopriv intentionally NOT registered — admin-only endpoint that writes to wp_options.
function wto_save_wc_sms_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		return;
	}
	if ( ! check_ajax_referer( 'wto_save_settings', 'wto_save_nonce', false ) ) {
		wp_send_json_error( 'نشست منقضی شده است؛ صفحه را یک‌بار رفرش کنید.' );
		return;
	}
	$so_tmp = array();
	$sms_data = $_POST['sms_data'] ?? '';
	if ( ! is_array( $sms_data ) ) {
		wp_send_json_error( 'داده‌ای برای ذخیره ارسال نشد.' );
		return;
	}
	foreach ( $sms_data as $i => $data ) {
		preg_match_all( '!\d+!', $data['name'], $match_id );
		preg_match_all( "/\[([^\]]*)\]/", $data['name'], $match_name );
		$so_tmp[ $match_id[0][0] ][ $match_name[1][1] ] = $data['value'];
	}
	update_option( 'wto_wc_sms_data', $so_tmp, false );
	wp_send_json_success();
}

add_action( 'wp_ajax_wto_save_users_sms_data', 'wto_save_users_sms_data' );
// nopriv intentionally NOT registered — admin-only endpoint that writes to wp_options.
function wto_save_users_sms_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		return;
	}
	if ( ! check_ajax_referer( 'wto_save_settings', 'wto_save_nonce', false ) ) {
		wp_send_json_error( 'نشست منقضی شده است؛ صفحه را یک‌بار رفرش کنید.' );
		return;
	}
	$so_tmp = array();
	$sms_data = $_POST['sms_data'] ?? '';
	if ( ! is_array( $sms_data ) ) {
		wp_send_json_error( 'داده‌ای برای ذخیره ارسال نشد.' );
		return;
	}
	foreach ( $sms_data as $i => $data ) {
		preg_match_all( '!\d+!', $data['name'], $match_id );
		preg_match_all( "/\[([^\]]*)\]/", $data['name'], $match_name );
		$so_tmp[ $match_id[0][0] ][ $match_name[1][1] ] = $data['value'];
	}
	update_option( 'wto_users_sms_data', $so_tmp, false );
	wp_send_json_success();
}

add_action( 'wp_ajax_wto_save_users_settings', 'wto_save_users_settings' );
// nopriv intentionally NOT registered — admin-only endpoint that writes to wp_options.
function wto_save_users_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		return;
	}
	if ( ! check_ajax_referer( 'wto_save_settings', 'wto_save_nonce', false ) ) {
		wp_send_json_error( 'نشست منقضی شده است؛ صفحه را یک‌بار رفرش کنید.' );
		return;
	}
	update_option( 'wto_active_digits', sanitize_text_field( $_POST['wto_active_digits'] ?? '' ), false );
	update_option( 'wto_custom_phone_meta_keys', sanitize_text_field( $_POST['wto_custom_phone_meta_keys'] ?? '' ), false );
	wp_send_json_success();
}

add_action( 'wp_ajax_wto_save_gf_sms_data', 'wto_save_gf_sms_data' );
// nopriv intentionally NOT registered — admin-only endpoint that writes to wp_options.
function wto_save_gf_sms_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		return;
	}
	if ( ! check_ajax_referer( 'wto_save_settings', 'wto_save_nonce', false ) ) {
		wp_send_json_error( 'نشست منقضی شده است؛ صفحه را یک‌بار رفرش کنید.' );
		return;
	}
	$so_tmp = array();
	$sms_data = $_POST['sms_data'] ?? '';
	if ( ! is_array( $sms_data ) ) {
		wp_send_json_error( 'داده‌ای برای ذخیره ارسال نشد.' );
		return;
	}
	foreach ( $sms_data as $i => $data ) {
		preg_match_all( '!\d+!', $data['name'], $match_id );
		preg_match_all( "/\[([^\]]*)\]/", $data['name'], $match_name );
		//print_r($match_name);
		if ( ! empty( $data['value'] ) ) {
			if ( isset( $match_id[0][1] ) ) {
				$so_tmp[ $match_id[0][0] ]['condition'][ $match_id[0][1] ][ $match_name[1][2] ] = $data['value'];
			} else {
				$so_tmp[ $match_id[0][0] ][ $match_name[1][1] ] = $data['value'];
			}
		}
	}
	update_option( 'wto_gf_sms_data', $so_tmp, false );
	wp_send_json_success();
}
