<?php
/**
 * فراز اس ام اس - بخش دیدگاه سایت
 *
 * v3.17.3 — رفتار جدید فرم دیدگاه:
 *   - فیلد شماره موبایل اختیاری (toggle قابل تنظیم)
 *   - فیلد ایمیل پیش‌فرض حذف می‌شود (toggle قابل تنظیم)
 *   - اگر WP ایمیل را الزامی بداند، fake email از روی موبایل ساخته می‌شود
 *     (مثلاً 09120000000@my-domain.com) — تا بازدیدکننده با فقط نام + موبایل
 *     بتواند دیدگاه ثبت کند
 *   - برای کاربر لاگین: شماره موبایل یکبار در user_meta ذخیره می‌شود و
 *     دفعات بعدی دیگر پرسیده نمی‌شود
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

// ============================================================================
// تنظیمات بخش فرم دیدگاه — با defaults منطقی
// ============================================================================

function wto_comment_form_settings() {
	$defaults = array(
		'phone_enabled'        => '1',  // نمایش فیلد موبایل
		'phone_required'       => '0',  // الزامی بودن موبایل
		'hide_email'           => '1',  // پنهان کردن فیلد ایمیل
		'fake_email_domain'    => '',   // domain برای ایمیل ساختگی (خالی = خودکار از site_url)
		'remember_user_phone'  => '1',  // یادآوری شماره موبایل کاربر لاگین
	);
	$saved = get_option( 'wto_comment_form_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

// Save handler — v3.17.3
add_action( 'admin_post_wto_save_comment_form_settings', 'wto_save_comment_form_settings' );
function wto_save_comment_form_settings() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_save_comment_form_settings' );

	$new = array(
		'phone_enabled'        => isset( $_POST['phone_enabled'] ) ? '1' : '0',
		'phone_required'       => isset( $_POST['phone_required'] ) ? '1' : '0',
		'hide_email'           => isset( $_POST['hide_email'] ) ? '1' : '0',
		'fake_email_domain'    => isset( $_POST['fake_email_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['fake_email_domain'] ) ) : '',
		'remember_user_phone'  => isset( $_POST['remember_user_phone'] ) ? '1' : '0',
	);
	update_option( 'wto_comment_form_settings', $new, false );

	wp_safe_redirect( add_query_arg( 'comment_form_saved', '1', wp_get_referer() ) );
	exit;
}

// ============================================================================
// فیلد موبایل + پنهان‌سازی ایمیل
// ============================================================================

add_filter( 'comment_form_default_fields', 'wto_comment_form_add_mobile_field', 20 );
function wto_comment_form_add_mobile_field( $fields ) {
	$s = wto_comment_form_settings();

	// v3.17.3: حذف فیلد ایمیل پیش‌فرض (در صورت toggle hide_email)
	if ( $s['hide_email'] === '1' && isset( $fields['email'] ) ) {
		unset( $fields['email'] );
	}

	// v3.17.3: اگر toggle phone خاموش است، فیلد را اضافه نکن
	if ( $s['phone_enabled'] !== '1' ) {
		return $fields;
	}

	$placeholder = $s['phone_required'] === '1' ? 'مثال: 09123456789' : 'اختیاری — برای اطلاع از پاسخ دیدگاه';
	$req_mark    = $s['phone_required'] === '1' ? ' <span class="required" style="color:#dc2626;">*</span>' : '';
	$req_attr    = $s['phone_required'] === '1' ? ' required="required" aria-required="true"' : '';

	$fields['farazwto_comment_mobile'] = '<p class="comment-form-farazwto-mobile">' .
		'<label for="farazwto_comment_mobile" style="display:block; margin-bottom:6px; font-weight:600;">📱 شماره موبایل' . $req_mark . '</label> ' .
		'<input id="farazwto_comment_mobile" name="farazwto_comment_mobile" type="tel" value="' .
		esc_attr( isset( $_POST['farazwto_comment_mobile'] ) ? wp_unslash( $_POST['farazwto_comment_mobile'] ) : '' ) .
		'" size="30" maxlength="15" placeholder="' . esc_attr( $placeholder ) . '"' . $req_attr .
		' style="direction:ltr; text-align:right; width:100%; max-width:340px; padding:9px 12px; border:1px solid #ccd0d4; border-radius:8px; font-size:14px; line-height:1.6; box-sizing:border-box;" /></p>';
	return $fields;
}

/**
 * فیلد موبایل برای کاربر لاگین — با remember logic.
 * اگر کاربر قبلاً موبایل ثبت کرده باشد، فیلد نمایش داده نمی‌شود.
 */
add_action( 'comment_form_logged_in_after', 'wto_comment_form_logged_in_mobile_field' );
function wto_comment_form_logged_in_mobile_field() {
	$s = wto_comment_form_settings();
	if ( $s['phone_enabled'] !== '1' ) {
		return;
	}

	// v3.17.3: remember logic — اگر قبلاً ست شده، نمی‌پرسیم
	if ( $s['remember_user_phone'] === '1' && is_user_logged_in() ) {
		$existing = (string) get_user_meta( get_current_user_id(), 'wto_comment_mobile', true );
		if ( $existing !== '' ) {
			// در hidden field می‌فرستیم تا handler ها بدانند موبایل موجود است
			echo '<input type="hidden" name="farazwto_comment_mobile" value="' . esc_attr( $existing ) . '">';
			return;
		}
	}

	$placeholder = $s['phone_required'] === '1' ? 'مثال: 09123456789' : 'اختیاری — یک‌بار وارد می‌کنید و ذخیره می‌شود';
	$req_mark    = $s['phone_required'] === '1' ? ' <span class="required" style="color:#dc2626;">*</span>' : '';
	$req_attr    = $s['phone_required'] === '1' ? ' required="required" aria-required="true"' : '';

	echo '<p class="comment-form-farazwto-mobile">';
	echo '<label for="farazwto_comment_mobile" style="display:block; margin-bottom:6px; font-weight:600;">📱 شماره موبایل' . wp_kses_post( $req_mark ) . '</label> ';
	echo '<input id="farazwto_comment_mobile" name="farazwto_comment_mobile" type="tel" value="' .
		esc_attr( isset( $_POST['farazwto_comment_mobile'] ) ? wp_unslash( $_POST['farazwto_comment_mobile'] ) : '' ) .
		'" size="30" maxlength="15" placeholder="' . esc_attr( $placeholder ) . '"' . $req_attr .
		' style="direction:ltr; text-align:right; width:100%; max-width:340px; padding:9px 12px; border:1px solid #ccd0d4; border-radius:8px; font-size:14px; line-height:1.6; box-sizing:border-box;" /></p>';
}

/**
 * v3.17.3: اگر hide_email روشن است، WP option ای که ایمیل را الزامی می‌کند
 * را در runtime override کن — تا comment submit بدون ایمیل fail نشود.
 *
 * v3.20.6 BUG: این filter روی هر صفحه‌ای که option را می‌خواند fire می‌شود،
 * نه فقط روی صفحات comment. حتی WC checkout هم از این option استفاده می‌کند.
 * اضافه‌ی escape hatch + محدود کردن به صفحات غیر-checkout.
 */
add_filter( 'pre_option_require_name_email', 'wto_comment_relax_email_requirement' );
function wto_comment_relax_email_requirement( $value ) {
	// v3.20.6 ESCAPE HATCH
	if ( defined( 'WTO_DISABLE_COMMENTS_FILTER' ) && WTO_DISABLE_COMMENTS_FILTER ) return $value;
	// روی صفحات checkout این override را اعمال نکن — WC به این option وابسته است
	if ( function_exists( 'is_checkout' ) && is_checkout() ) return $value;
	if ( function_exists( 'is_admin' ) && is_admin() ) return $value;

	$s = wto_comment_form_settings();
	if ( $s['hide_email'] === '1' ) {
		return '0';
	}
	return $value;
}

/**
 * Validate + auto-generate fake email از موبایل، در preprocess_comment.
 */
add_filter( 'preprocess_comment', 'wto_comment_validate_mobile' );
function wto_comment_validate_mobile( $commentdata ) {
	$s = wto_comment_form_settings();

	$mobile     = isset( $_POST['farazwto_comment_mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['farazwto_comment_mobile'] ) ) : '';
	$normalized = '';

	if ( ! empty( trim( $mobile ) ) ) {
		$normalized = wto_comment_normalize_mobile( $mobile );
		if ( ! $normalized ) {
			wp_die( 'شماره موبایل معتبر نیست. فرمت صحیح: 09123456789', 'خطا', array( 'back_link' => true ) );
		}
	}

	// پاسخِ مدیر از پیشخان نباید نیازمند شماره موبایل باشد. وقتی مدیر به دیدگاه کاربر
	// پاسخ می‌دهد، فیلد موبایلِ فرانت‌اند وجود ندارد و نباید اجباری‌بودن موبایل اعمال شود؛
	// پیامکِ پاسخ جداگانه به صاحب دیدگاهِ والد ارسال می‌شود.
	$is_admin_reply = is_admin() || current_user_can( 'moderate_comments' );

	// v3.17.3: اگر موبایل اجباری است ولی وارد نشده، خطا (به‌جز پاسخ مدیر)
	if ( ! $is_admin_reply && $s['phone_enabled'] === '1' && $s['phone_required'] === '1' && $normalized === '' ) {
		wp_die( 'وارد کردن شماره موبایل برای ثبت دیدگاه الزامی است.', 'خطا', array( 'back_link' => true ) );
	}

	// v3.17.3: اگر ایمیل خالی است (چون فیلد را پنهان کرده‌ایم) و موبایل داریم،
	// fake email از روی موبایل بساز — مثل 09120000000@mydomain.com
	if ( $s['hide_email'] === '1' && empty( $commentdata['comment_author_email'] ) && $normalized !== '' ) {
		$commentdata['comment_author_email'] = wto_comment_generate_fake_email( $normalized, $s['fake_email_domain'] );
	}

	return $commentdata;
}

/**
 * تولید ایمیل ساختگی از روی شماره موبایل — برای رضایت WP بدون نیاز به فیلد ایمیل.
 */
function wto_comment_generate_fake_email( $mobile, $custom_domain = '' ) {
	$domain = trim( (string) $custom_domain );
	if ( $domain === '' ) {
		$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = preg_replace( '#^www\.#i', '', $domain );
	}
	if ( $domain === '' ) {
		$domain = 'example.com';
	}
	return $mobile . '@' . $domain;
}

function wto_comment_normalize_mobile( $phone ) {
	if ( empty( $phone ) ) {
		return '';
	}
	$phone = preg_replace( '/\s+/', '', $phone );
	if ( function_exists( 'wto_normalize_phone' ) ) {
		$phone = wto_normalize_phone( $phone );
	}
	if ( preg_match( '/^09\d{9}$/', $phone ) ) {
		return $phone;
	}
	return false;
}

add_action( 'comment_post', 'wto_comment_save_mobile_meta', 10, 3 );
function wto_comment_save_mobile_meta( $comment_id, $comment_approved, $commentdata ) {
	$mobile = isset( $_POST['farazwto_comment_mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['farazwto_comment_mobile'] ) ) : '';
	if ( empty( $mobile ) ) {
		return;
	}
	$normalized = wto_comment_normalize_mobile( $mobile );
	if ( ! $normalized ) {
		return;
	}
	add_comment_meta( $comment_id, 'farazwto_mobile', $normalized, true ) || update_comment_meta( $comment_id, 'farazwto_mobile', $normalized );

	// v3.17.3: اگر کاربر لاگین است و toggle remember روشن، در user_meta ذخیره کن
	$s = wto_comment_form_settings();
	if ( $s['remember_user_phone'] === '1' && is_user_logged_in() ) {
		update_user_meta( get_current_user_id(), 'wto_comment_mobile', $normalized );
	}
}

// --- ارسال پیامک در رویدادها ---

/**
 * ساخت attributes از متن پترن و مقادیر داده‌شده و ارسال با wto_send_pattern_sms_raw
 */
function wto_comment_send_pattern( $recipient, $pattern_code, $message_template, $all_values ) {
	if ( empty( $pattern_code ) || empty( $recipient ) || ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return false;
	}
	$keys = function_exists( 'wto_tracking_pattern_var_keys_from_message' )
		? wto_tracking_pattern_var_keys_from_message( $message_template )
		: array();
	if ( empty( $keys ) && is_string( $message_template ) ) {
		preg_match_all( '/%([a-zA-Z0-9_]+)%/', $message_template, $matches );
		$keys = ! empty( $matches[1] ) ? array_unique( $matches[1] ) : array();
	}
	$attributes = array();
	foreach ( $keys as $key ) {
		if ( isset( $all_values[ $key ] ) ) {
			$attributes[ $key ] = $all_values[ $key ];
		}
	}
	$result = wto_send_pattern_sms_raw( $recipient, $pattern_code, $attributes, '' );
	return $result === 'success';
}

/**
 * ۱) اطلاع به مدیر هنگام ثبت دیدگاه
 * ۲) اطلاع به کاربر هنگام پاسخ به دیدگاه
 */
add_action( 'comment_post', 'wto_comment_on_comment_post_sms', 20, 3 );
function wto_comment_on_comment_post_sms( $comment_id, $comment_approved, $commentdata ) {
	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return;
	}
	$parent_id = (int) $comment->comment_parent;

	if ( $parent_id === 0 ) {
		// دیدگاه جدید → اطلاع به مدیر
		$pattern  = get_option( 'wto_comment_admin_pattern', '' );
		$message  = get_option( 'wto_comment_admin_message', '' );
		$phones   = get_option( 'wto_comment_admin_phones', '' );
		if ( empty( $pattern ) || empty( trim( $phones ) ) ) {
			return;
		}
		$all_values = array(
			'comment_author'       => $comment->comment_author,
			'comment_author_email' => $comment->comment_author_email,
		);
		$phones = function_exists( 'wto_split_mobile_list' ) ? wto_split_mobile_list( $phones ) : array_map( 'trim', explode( ',', $phones ) );
		foreach ( $phones as $phone ) {
			if ( $phone !== '' ) {
				wto_comment_send_pattern( $phone, $pattern, $message, $all_values );
			}
		}
		return;
	}

	// پاسخ به دیدگاه → اطلاع به صاحب نظر والد
	$pattern = get_option( 'wto_comment_user_reply_pattern', '' );
	$message = get_option( 'wto_comment_user_reply_message', '' );
	if ( empty( $pattern ) ) {
		return;
	}
	$parent = get_comment( $parent_id );
	if ( ! $parent ) {
		return;
	}
	$parent_mobile = get_comment_meta( $parent_id, 'farazwto_mobile', true );
	if ( empty( $parent_mobile ) ) {
		return;
	}
	$reply_link = get_comment_link( $comment_id );
	$all_values = array(
		'comment_author' => $comment->comment_author,
		'comment_link'   => $reply_link,
	);
	wto_comment_send_pattern( $parent_mobile, $pattern, $message, $all_values );
}

/**
 * ۳) اطلاع به کاربر هنگام تایید یا رد دیدگاه
 */
add_action( 'transition_comment_status', 'wto_comment_on_status_transition_sms', 10, 3 );
function wto_comment_on_status_transition_sms( $new_status, $old_status, $comment ) {
	$pattern = get_option( 'wto_comment_user_approve_pattern', '' );
	$message = get_option( 'wto_comment_user_approve_message', '' );
	if ( empty( $pattern ) ) {
		return;
	}
	// فقط هنگام تایید (approved) یا رد (hold, spam, trash)
	$send_on = array( 'approved', 'hold', 'spam', 'trash' );
	if ( ! in_array( $new_status, $send_on, true ) ) {
		return;
	}
	$mobile = get_comment_meta( $comment->comment_ID, 'farazwto_mobile', true );
	if ( empty( $mobile ) ) {
		return;
	}
	$status_label = ( $new_status === 'approved' ) ? 'تایید' : 'رد';
	$comment_link = get_comment_link( $comment );
	$all_values   = array(
		'comment_author' => $comment->comment_author,
		'comment_link'   => $comment_link,
		'status'         => $status_label,
	);
	wto_comment_send_pattern( $mobile, $pattern, $message, $all_values );
}
