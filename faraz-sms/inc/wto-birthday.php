<?php
/**
 * Birthday SMS + Auto-Coupon — v3.17.0
 *
 * تبریک تولد به مشتری + کد تخفیف اختصاصی ۲۴ ساعته → لحظه‌ی emotional برای فروشگاه.
 *
 * منابع جمع‌آوری تاریخ تولد:
 *  ۱) فیلد اختیاری در checkout ووکامرس
 *  ۲) فیلد در «حساب کاربری → ویرایش آدرس» ووکامرس
 *  ۳) شورت‌کد [farazsms_birthday_form] برای landing page و کمپین SMS
 *
 * فلوی روزانه:
 *  cron هر ساعت → بررسی می‌کند ساعت ارسال تنظیم‌شده فرارسیده باشد
 *  → جلالی امروز را به MM-DD تبدیل می‌کند
 *  → افرادی که jalali_md = امروز و last_sms_year < سال فعلی است را می‌گیرد
 *  → برای هر یک، کوپن WC اختصاصی می‌سازد
 *  → SMS با %first_name% و %coupon_code% می‌فرستد
 *  → last_sms_year را ست می‌کند تا تکرار نشود
 *
 * Performance: ۱۰۰k سایت × ~۲۰۰ مشتری در روز = پایدار.
 * Idempotency: last_sms_year جلوگیری از ارسال دوبار حتی اگر cron دو بار run شود.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_BIRTHDAY_SCHEMA_VERSION = '1.0';
const WTO_BIRTHDAY_CRON_HOOK      = 'wto_birthday_hourly_check';

function wto_birthday_table() {
	global $wpdb;
	return $wpdb->prefix . 'wto_customer_birthdays';
}

// ============================================================================
// Settings
// ============================================================================

function wto_birthday_get_settings() {
	$defaults = array(
		'enabled'             => '0',           // پیش‌فرض خاموش — کاربر باید فعال کند
		'send_hour'           => '10',          // ساعت ارسال (۰-۲۳، local time)
		'pattern_code'        => '',
		'capture_checkout'    => '1',
		'capture_myaccount'   => '1',
		'capture_shortcode'   => '1',
		// تنظیمات کوپن
		'coupon_enabled'      => '1',
		'coupon_type'         => 'percent',     // percent | fixed_cart
		'coupon_amount'       => '20',
		'coupon_validity_days'=> '7',
		'coupon_prefix'       => 'BDAY',
		'coupon_min_amount'   => '0',
	);
	$saved = get_option( 'wto_birthday_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function wto_birthday_is_enabled() {
	$s = wto_birthday_get_settings();
	return $s['enabled'] === '1';
}

// ============================================================================
// Schema — lazy install
// ============================================================================

function wto_birthday_maybe_install_schema() {
	if ( get_option( 'wto_birthday_schema_version' ) === WTO_BIRTHDAY_SCHEMA_VERSION ) {
		return;
	}
	global $wpdb;
	$table   = wto_birthday_table();
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT(20) UNSIGNED NULL,
		mobile VARCHAR(20) NOT NULL,
		email VARCHAR(190) NOT NULL DEFAULT '',
		first_name VARCHAR(120) NOT NULL DEFAULT '',
		last_name VARCHAR(120) NOT NULL DEFAULT '',
		jalali_md VARCHAR(5) NOT NULL,
		jalali_full VARCHAR(10) NOT NULL DEFAULT '',
		source VARCHAR(20) NOT NULL DEFAULT 'checkout',
		created_at DATETIME NOT NULL,
		last_sms_year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY mobile_unique (mobile),
		KEY jalali_md (jalali_md),
		KEY user_id (user_id),
		KEY last_sms_year (last_sms_year)
	) $charset;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'wto_birthday_schema_version', WTO_BIRTHDAY_SCHEMA_VERSION, false );
}

// Schema install در زمان enable
add_action( 'update_option_wto_birthday_settings', 'wto_birthday_on_settings_change', 10, 2 );
function wto_birthday_on_settings_change( $old, $new ) {
	if ( is_array( $new ) && ! empty( $new['enabled'] ) && $new['enabled'] === '1' ) {
		wto_birthday_maybe_install_schema();
		wto_birthday_schedule_cron();
	}
}

// ============================================================================
// Jalali helpers — Behrouz Parsi algorithm (bidirectional)
// ============================================================================

function wto_birthday_gregorian_to_jalali( $gy, $gm, $gd ) {
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
	$jy = -1595 + ( 33 * intdiv( $days, 12053 ) );
	$days = $days % 12053;
	$jy += 4 * intdiv( $days, 1461 );
	$days = $days % 1461;
	if ( $days > 365 ) {
		$jy += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + intdiv( $days, 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + intdiv( $days - 186, 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}
	return array( $jy, $jm, $jd );
}

function wto_birthday_today_jalali() {
	$now = current_time( 'timestamp' );
	return wto_birthday_gregorian_to_jalali(
		(int) date( 'Y', $now ),
		(int) date( 'n', $now ),
		(int) date( 'j', $now )
	);
}

function wto_birthday_today_jalali_md() {
	list( , $jm, $jd ) = wto_birthday_today_jalali();
	return sprintf( '%02d-%02d', $jm, $jd );
}

function wto_birthday_today_jalali_year() {
	list( $jy ) = wto_birthday_today_jalali();
	return $jy;
}

/**
 * Parse ورودی کاربر به ساختار jalali — حمایت از Persian/Arabic/Latin digits و جداکننده‌های مختلف.
 * فرمت قابل قبول: YYYY/MM/DD یا YYYY-MM-DD یا MM/DD (سال اختیاری).
 */
function wto_birthday_parse_jalali_input( $input ) {
	if ( empty( $input ) ) return null;
	$input = trim( (string) $input );

	// تبدیل ارقام فارسی و عربی به ASCII
	$from = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹', '٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
	$to   = array( '0','1','2','3','4','5','6','7','8','9', '0','1','2','3','4','5','6','7','8','9' );
	$input = str_replace( $from, $to, $input );

	// نرمالایز جداکننده
	$input = preg_replace( '/[\s]/', '', $input );
	$input = preg_replace( '/[\.\/]/', '-', $input );

	if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $input, $m ) ) {
		$jy = (int) $m[1]; $jm = (int) $m[2]; $jd = (int) $m[3];
	} elseif ( preg_match( '/^(\d{1,2})-(\d{1,2})$/', $input, $m ) ) {
		$jy = 0; $jm = (int) $m[1]; $jd = (int) $m[2];
	} else {
		return null;
	}

	// Validate ranges
	if ( $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 ) return null;
	if ( $jm <= 6 && $jd > 31 ) return null;
	if ( $jm >= 7 && $jm <= 11 && $jd > 30 ) return null;
	if ( $jm == 12 && $jd > 30 ) return null;       // اسفند: ۲۹/۳۰
	if ( $jy > 0 && ( $jy < 1280 || $jy > 1450 ) ) return null;

	return array(
		'jy'          => $jy,
		'jm'          => $jm,
		'jd'          => $jd,
		'jalali_md'   => sprintf( '%02d-%02d', $jm, $jd ),
		'jalali_full' => $jy > 0 ? sprintf( '%04d-%02d-%02d', $jy, $jm, $jd ) : '',
	);
}

// ============================================================================
// CRUD — ذخیره/خواندن
// ============================================================================

/**
 * ذخیره یا به‌روزرسانی تولد یک مشتری (mobile کلید یکتا).
 */
function wto_birthday_save( $args ) {
	$args = wp_parse_args( $args, array(
		'mobile'      => '',
		'first_name'  => '',
		'last_name'   => '',
		'email'       => '',
		'user_id'     => 0,
		'input'       => '',
		'source'      => 'unknown',
	) );

	if ( empty( $args['mobile'] ) || empty( $args['input'] ) ) {
		return new WP_Error( 'missing', 'موبایل و تاریخ الزامی است.' );
	}

	$mobile = function_exists( 'wto_normalize_phone' ) ? wto_normalize_phone( $args['mobile'] ) : preg_replace( '/[^0-9]/', '', $args['mobile'] );
	if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
		return new WP_Error( 'invalid_mobile', 'فرمت شماره موبایل صحیح نیست.' );
	}

	$parsed = wto_birthday_parse_jalali_input( $args['input'] );
	if ( ! $parsed ) {
		return new WP_Error( 'invalid_date', 'فرمت تاریخ تولد صحیح نیست. نمونه: ۱۳۷۰/۰۳/۱۵' );
	}

	wto_birthday_maybe_install_schema();
	global $wpdb;
	$table = wto_birthday_table();

	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT id FROM $table WHERE mobile = %s LIMIT 1", $mobile
	) );

	$data = array(
		'mobile'      => $mobile,
		'first_name'  => sanitize_text_field( $args['first_name'] ),
		'last_name'   => sanitize_text_field( $args['last_name'] ),
		'email'       => sanitize_email( $args['email'] ),
		'user_id'     => max( 0, (int) $args['user_id'] ) ?: null,
		'jalali_md'   => $parsed['jalali_md'],
		'jalali_full' => $parsed['jalali_full'],
		'source'      => sanitize_key( $args['source'] ),
	);

	if ( $existing ) {
		$wpdb->update( $table, $data, array( 'id' => $existing->id ) );
		$id = (int) $existing->id;
	} else {
		$data['created_at']    = current_time( 'mysql' );
		$data['last_sms_year'] = 0;
		$wpdb->insert( $table, $data );
		$id = (int) $wpdb->insert_id;
	}

	// Mirror در user_meta اگر user_id داریم
	if ( $data['user_id'] ) {
		update_user_meta( $data['user_id'], 'wto_birthday_jalali', $parsed['jalali_full'] ?: $parsed['jalali_md'] );
	}

	return $id;
}

// ============================================================================
// Capture Source 1: WooCommerce Checkout
// ============================================================================

/**
 * v3.17.8: تشخیص اینکه آیا checkout این صفحه block-based است یا shortcode-based.
 * فیلد ما فقط روی shortcode checkout کار می‌کند — برای block checkout باید
 * از WC Blocks API استفاده شود (در نسخه‌ی آینده).
 */
function wto_birthday_is_block_checkout() {
	if ( function_exists( 'has_block' ) && function_exists( 'wc_get_page_id' ) ) {
		$page_id = wc_get_page_id( 'checkout' );
		if ( $page_id > 0 ) {
			$post = get_post( $page_id );
			if ( $post && has_block( 'woocommerce/checkout', $post ) ) {
				return true;
			}
		}
	}
	return false;
}

add_filter( 'woocommerce_checkout_fields', 'wto_birthday_add_checkout_field', 20 );
function wto_birthday_add_checkout_field( $fields ) {
	// v3.20.6 ESCAPE HATCH
	if ( defined( 'WTO_DISABLE_BIRTHDAY_CHECKOUT' ) && WTO_DISABLE_BIRTHDAY_CHECKOUT ) return $fields;
	if ( defined( 'WTO_DISABLE_CHECKOUT_HOOKS' ) && WTO_DISABLE_CHECKOUT_HOOKS ) return $fields;
	if ( ! wto_birthday_is_enabled() ) return $fields;
	$s = wto_birthday_get_settings();
	if ( $s['capture_checkout'] !== '1' ) return $fields;

	$fields['billing']['billing_wto_birthday'] = array(
		'label'       => '🎂 تاریخ تولد (اختیاری)',
		'placeholder' => 'مثال: ۱۳۷۰/۰۳/۱۵',
		'required'    => false,
		'class'       => array( 'form-row-wide' ),
		'priority'    => 120,
		'description' => 'برای دریافت کد تخفیف انحصاری روز تولد',
	);
	return $fields;
}

add_action( 'woocommerce_checkout_update_order_meta', 'wto_birthday_save_from_checkout', 10, 2 );
function wto_birthday_save_from_checkout( $order_id, $data ) {
	if ( ! wto_birthday_is_enabled() ) return;
	$input = isset( $_POST['billing_wto_birthday'] ) ? wp_unslash( $_POST['billing_wto_birthday'] ) : '';
	if ( ! $input ) return;

	$order = wc_get_order( $order_id );
	if ( ! $order ) return;

	wto_birthday_save( array(
		'mobile'     => $order->get_billing_phone(),
		'first_name' => $order->get_billing_first_name(),
		'last_name'  => $order->get_billing_last_name(),
		'email'      => $order->get_billing_email(),
		'user_id'    => $order->get_user_id(),
		'input'      => $input,
		'source'     => 'checkout',
	) );
}

// ============================================================================
// Capture Source 2: My Account → Edit Address
// ============================================================================

add_action( 'woocommerce_after_edit_address_form_billing', 'wto_birthday_render_myaccount_field' );
function wto_birthday_render_myaccount_field() {
	if ( ! wto_birthday_is_enabled() ) return;
	$s = wto_birthday_get_settings();
	if ( $s['capture_myaccount'] !== '1' ) return;

	$user_id = get_current_user_id();
	$current = '';
	if ( $user_id ) {
		$current = (string) get_user_meta( $user_id, 'wto_birthday_jalali', true );
	}
	?>
	<p class="form-row form-row-wide">
		<label for="wto_birthday_input">🎂 تاریخ تولد (برای دریافت کد تخفیف روز تولد)</label>
		<input type="text" class="input-text" name="wto_birthday_input" id="wto_birthday_input"
		       placeholder="مثال: ۱۳۷۰/۰۳/۱۵" value="<?php echo esc_attr( $current ); ?>"
		       style="direction:ltr; text-align:right;">
	</p>
	<?php
}

add_action( 'woocommerce_customer_save_address', 'wto_birthday_save_from_myaccount', 10, 2 );
function wto_birthday_save_from_myaccount( $user_id, $address_type ) {
	if ( ! wto_birthday_is_enabled() ) return;
	if ( $address_type !== 'billing' ) return;
	$input = isset( $_POST['wto_birthday_input'] ) ? wp_unslash( $_POST['wto_birthday_input'] ) : '';
	if ( ! $input ) return;

	$user = get_userdata( $user_id );
	if ( ! $user ) return;

	wto_birthday_save( array(
		'mobile'     => get_user_meta( $user_id, 'billing_phone', true ),
		'first_name' => $user->first_name,
		'last_name'  => $user->last_name,
		'email'      => $user->user_email,
		'user_id'    => $user_id,
		'input'      => $input,
		'source'     => 'myaccount',
	) );
}

// ============================================================================
// Capture Source 3: Shortcode [farazsms_birthday_form]
// ============================================================================

add_shortcode( 'farazsms_birthday_form', 'wto_birthday_render_shortcode' );
function wto_birthday_render_shortcode( $atts ) {
	if ( ! wto_birthday_is_enabled() ) {
		return '<div style="background:#fef3c7; border:1px solid #fde68a; padding:12px 16px; border-radius:8px;">سیستم تبریک تولد فعال نیست.</div>';
	}
	$s = wto_birthday_get_settings();
	if ( $s['capture_shortcode'] !== '1' ) return '';

	$atts = shortcode_atts( array(
		'title' => '🎂 تاریخ تولدتان را ثبت کنید و کد تخفیف روز تولد بگیرید',
		'submit_label' => 'ثبت تاریخ تولد',
		'success_msg'  => '✓ تاریخ تولدتان ثبت شد. روز تولد، کد تخفیف برایتان ارسال می‌شود.',
	), $atts, 'farazsms_birthday_form' );

	$msg = '';
	$is_error = false;
	if ( isset( $_POST['wto_birthday_submit'] ) && check_admin_referer( 'wto_birthday_submit', 'wto_birthday_nonce' ) ) {
		// v3.19.0: rate-limit برای جلوگیری از submit انبوه
		$submitted_mobile = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) ) : '';
		if ( function_exists( 'wto_rate_limit_guard_public' ) ) {
			$guard = wto_rate_limit_guard_public( 'birthday_capture', $submitted_mobile );
			if ( ! $guard['allowed'] ) {
				$msg = $guard['message'];
				$is_error = true;
				$result = null; // skip save
			}
		}
		if ( ! isset( $result ) ) {
			$result = wto_birthday_save( array(
				'mobile'     => $submitted_mobile,
				'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
				'input'      => isset( $_POST['birthday'] ) ? sanitize_text_field( wp_unslash( $_POST['birthday'] ) ) : '',
				'source'     => 'shortcode',
				'user_id'    => get_current_user_id(),
			) );
			if ( is_wp_error( $result ) ) {
				$msg = $result->get_error_message();
				$is_error = true;
			} else {
				$msg = $atts['success_msg'];
			}
		}
	}

	ob_start();
	?>
	<div style="background:#fff; border:2px solid #fde68a; border-radius:14px; padding:24px 28px; max-width:480px; margin:20px auto; direction:rtl; font-family:inherit; box-shadow:0 4px 14px rgba(0,0,0,0.06);">
		<div style="text-align:center; margin-bottom:18px;">
			<div style="font-size:48px; line-height:1; margin-bottom:8px;">🎂</div>
			<h3 style="margin:0; font-size:17px; color:#0f172a; font-weight:700;"><?php echo esc_html( $atts['title'] ); ?></h3>
		</div>
		<?php if ( $msg ) : ?>
			<div style="background:<?php echo $is_error ? '#fef2f2' : '#dcfce7'; ?>; color:<?php echo $is_error ? '#b91c1c' : '#166534'; ?>; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13px;">
				<?php echo esc_html( $msg ); ?>
			</div>
		<?php endif; ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wto_birthday_submit', 'wto_birthday_nonce' ); ?>
			<input type="hidden" name="wto_birthday_submit" value="1">
			<div style="margin-bottom:12px;">
				<label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#374151;">نام شما</label>
				<input type="text" name="first_name" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;">
			</div>
			<div style="margin-bottom:12px;">
				<label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#374151;">شماره موبایل</label>
				<input type="tel" name="mobile" required placeholder="09120000000" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; direction:ltr; text-align:right; font-family:Menlo,Consolas,monospace;">
			</div>
			<div style="margin-bottom:18px;">
				<label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#374151;">تاریخ تولد (شمسی)</label>
				<input type="text" name="birthday" required placeholder="مثال: ۱۳۷۰/۰۳/۱۵" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; direction:ltr; text-align:right; font-family:Menlo,Consolas,monospace;">
				<small style="display:block; margin-top:4px; color:#94a3b8; font-size:11.5px;">می‌توانید فقط ماه و روز (مثل ۰۳/۱۵) هم وارد کنید</small>
			</div>
			<button type="submit" style="width:100%; background:#dc2626; color:#fff; padding:11px 16px; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(220,38,38,0.18);">
				<?php echo esc_html( $atts['submit_label'] ); ?>
			</button>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

// ============================================================================
// Cron — hourly check + daily run
// ============================================================================

function wto_birthday_schedule_cron() {
	if ( ! wp_next_scheduled( WTO_BIRTHDAY_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'hourly', WTO_BIRTHDAY_CRON_HOOK );
	}
}
add_action( 'init', 'wto_birthday_schedule_cron' );

register_deactivation_hook(
	defined( 'WTO_PLUGIN_FILE' ) ? WTO_PLUGIN_FILE : __FILE__,
	'wto_birthday_clear_cron'
);
function wto_birthday_clear_cron() {
	$ts = wp_next_scheduled( WTO_BIRTHDAY_CRON_HOOK );
	if ( $ts ) wp_unschedule_event( $ts, WTO_BIRTHDAY_CRON_HOOK );
}

add_action( WTO_BIRTHDAY_CRON_HOOK, 'wto_birthday_run_hourly' );
function wto_birthday_run_hourly() {
	if ( ! wto_birthday_is_enabled() ) return;

	$s            = wto_birthday_get_settings();
	$target_hour  = max( 0, min( 23, (int) $s['send_hour'] ) );
	$current_hour = (int) current_time( 'H' );

	if ( $current_hour !== $target_hour ) return;

	// Dedup روزانه — اگر امروز اجرا شد، تکرار نکن
	$today    = current_time( 'Y-m-d' );
	$last_run = get_option( 'wto_birthday_last_run', '' );
	if ( $last_run === $today ) return;
	update_option( 'wto_birthday_last_run', $today, false );

	wto_birthday_dispatch_today();
}

/**
 * dispatch تولدهای امروز — قابل صدا زدن دستی هم هست (دکمه «ارسال اکنون»)
 */
function wto_birthday_dispatch_today() {
	wto_birthday_maybe_install_schema();
	$s          = wto_birthday_get_settings();
	$pattern    = (string) $s['pattern_code'];
	$today_md   = wto_birthday_today_jalali_md();
	$today_year = wto_birthday_today_jalali_year();

	global $wpdb;
	$table = wto_birthday_table();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table
		 WHERE jalali_md = %s AND last_sms_year < %d
		 LIMIT 500",
		$today_md, $today_year
	) );

	$stats = array( 'attempted' => 0, 'sent' => 0, 'failed' => 0 );

	foreach ( $rows as $row ) {
		$stats['attempted']++;

		$coupon_code = '';
		if ( $s['coupon_enabled'] === '1' ) {
			$coupon_code = wto_birthday_create_coupon( $row, $s );
		}

		$attrs = array(
			'first_name'  => $row->first_name ?: 'مشتری',
			'coupon_code' => $coupon_code ?: '—',
		);

		$result = false;
		if ( function_exists( 'wto_send_pattern_sms_raw' ) && $pattern ) {
			$send = wto_send_pattern_sms_raw( $row->mobile, $pattern, $attrs );
			$result = ( $send === 'success' );
		}

		if ( $result ) {
			$stats['sent']++;
			$wpdb->update( $table,
				array( 'last_sms_year' => $today_year ),
				array( 'id' => $row->id )
			);
		} else {
			$stats['failed']++;
		}
	}

	// لاگ آخرین run برای نمایش در UI
	update_option( 'wto_birthday_last_stats', array_merge( $stats, array(
		'date'    => current_time( 'mysql' ),
		'jalali'  => $today_md,
		'matched' => count( $rows ),
	) ), false );

	return $stats;
}

// ============================================================================
// Coupon generator
// ============================================================================

function wto_birthday_create_coupon( $row, $settings ) {
	if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) return '';

	$prefix    = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $settings['coupon_prefix'] ?: 'BDAY' ) );
	$rand      = strtoupper( wp_generate_password( 6, false, false ) );
	$code      = $prefix . '-' . $rand;

	// در صورت بدشانسی collision، یک‌بار دیگر تولید کن
	if ( wc_get_coupon_id_by_code( $code ) ) {
		$code = $prefix . '-' . strtoupper( wp_generate_password( 8, false, false ) );
	}

	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( $settings['coupon_type'] === 'fixed_cart' ? 'fixed_cart' : 'percent' );
	$coupon->set_amount( (float) $settings['coupon_amount'] );
	$coupon->set_individual_use( true );
	$coupon->set_usage_limit( 1 );
	$coupon->set_usage_limit_per_user( 1 );

	$days = max( 1, (int) $settings['coupon_validity_days'] );
	$coupon->set_date_expires( strtotime( "+{$days} days" ) );

	if ( ! empty( $row->email ) ) {
		$coupon->set_email_restrictions( array( $row->email ) );
	}

	if ( (float) $settings['coupon_min_amount'] > 0 ) {
		$coupon->set_minimum_amount( (float) $settings['coupon_min_amount'] );
	}

	$coupon->add_meta_data( '_wto_birthday_coupon', '1', true );
	$coupon->add_meta_data( '_wto_birthday_for_user', $row->user_id ?: $row->mobile, true );
	$coupon->save();

	return $code;
}

// ============================================================================
// Submenu + Settings + List UI
// ============================================================================

add_action( 'admin_menu', 'wto_birthday_register_submenu', 988 );
function wto_birthday_register_submenu() {
	add_submenu_page(
		'farazwto',
		'تبریک تولد',
		'🎂 تبریک تولد',
		'manage_options',
		'farazwto-birthday',
		'wto_birthday_render_admin'
	);
}

// Handler ذخیره تنظیمات
add_action( 'admin_post_wto_birthday_save_settings', 'wto_birthday_handle_save_settings' );
function wto_birthday_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_birthday_save_settings' );

	$defaults = wto_birthday_get_settings();
	$new = array(
		'enabled'              => isset( $_POST['enabled'] ) ? '1' : '0',
		'send_hour'            => max( 0, min( 23, (int) ( $_POST['send_hour'] ?? 10 ) ) ),
		'pattern_code'         => sanitize_text_field( wp_unslash( $_POST['pattern_code'] ?? '' ) ),
		'capture_checkout'     => isset( $_POST['capture_checkout'] ) ? '1' : '0',
		'capture_myaccount'    => isset( $_POST['capture_myaccount'] ) ? '1' : '0',
		'capture_shortcode'    => isset( $_POST['capture_shortcode'] ) ? '1' : '0',
		'coupon_enabled'       => isset( $_POST['coupon_enabled'] ) ? '1' : '0',
		'coupon_type'          => in_array( $_POST['coupon_type'] ?? '', array( 'percent', 'fixed_cart' ), true ) ? $_POST['coupon_type'] : 'percent',
		'coupon_amount'        => max( 0, (float) ( $_POST['coupon_amount'] ?? 20 ) ),
		'coupon_validity_days' => max( 1, (int) ( $_POST['coupon_validity_days'] ?? 7 ) ),
		'coupon_prefix'        => sanitize_text_field( wp_unslash( $_POST['coupon_prefix'] ?? 'BDAY' ) ),
		'coupon_min_amount'    => max( 0, (float) ( $_POST['coupon_min_amount'] ?? 0 ) ),
	);
	update_option( 'wto_birthday_settings', array_merge( $defaults, $new ), false );

	wp_safe_redirect( add_query_arg( 'saved', '1', wp_get_referer() ) );
	exit;
}

// Handler ارسال دستی همین الان
add_action( 'admin_post_wto_birthday_send_now', 'wto_birthday_handle_send_now' );
function wto_birthday_handle_send_now() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_birthday_send_now' );
	$stats = wto_birthday_dispatch_today();
	$msg   = sprintf( 'تلاش: %d / موفق: %d / ناموفق: %d', $stats['attempted'], $stats['sent'], $stats['failed'] );
	wp_safe_redirect( add_query_arg( array( 'sent_now' => '1', 'msg' => rawurlencode( $msg ) ), wp_get_referer() ) );
	exit;
}

function wto_birthday_render_admin() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	wto_birthday_maybe_install_schema();
	$s     = wto_birthday_get_settings();
	$tab   = isset( $_GET['tt'] ) ? sanitize_key( $_GET['tt'] ) : 'settings';
	if ( ! in_array( $tab, array( 'settings', 'list' ), true ) ) $tab = 'settings';

	global $wpdb;
	$table = wto_birthday_table();
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	$today_md = wto_birthday_today_jalali_md();
	$today_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE jalali_md = %s", $today_md ) );

	$apikey = get_option( 'wto_apikey', '' );
	?>
	<section class="wrapper">
		<div id="wto_header">
			<div><a href="https://farazsms.com" target="_blank"><img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt=""></a></div>
			<?php if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) : $credit = wto_get_credit(); ?>
				<div id="wto_account_info"><div class="wto_credit_amount"><span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?><span> تومان</span></div><?php if ( function_exists( 'wto_render_profile_block' ) ) wto_render_profile_block(); ?></div>
			<?php endif; ?>
		</div>

		<!-- Hero -->
		<div style="background:linear-gradient(135deg, #ec4899 0%, #f97316 100%); color:#fff; border-radius:16px; padding:24px 28px; margin-bottom:18px; direction:rtl; box-shadow:0 8px 24px rgba(236,72,153,0.18);">
			<div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
				<div style="font-size:56px; line-height:1;">🎂</div>
				<div style="flex:1; min-width:240px;">
					<h2 style="margin:0 0 6px; font-size:20px; font-weight:700;">تبریک تولد خودکار + کد تخفیف انحصاری</h2>
					<div style="font-size:13px; opacity:0.92; line-height:1.7;">روز تولد هر مشتری، یک پیامک تبریک با کد تخفیف اختصاصی برایش ارسال می‌شود. <strong>پربازده‌ترین کمپین SMS برای فروشگاه ایرانی.</strong></div>
				</div>
				<div style="display:flex; gap:10px; flex-wrap:wrap;">
					<div style="background:rgba(255,255,255,0.18); padding:10px 18px; border-radius:10px; text-align:center; min-width:100px;">
						<div style="font-size:22px; font-weight:700;"><?php echo number_format_i18n( $total ); ?></div>
						<div style="font-size:11px; opacity:0.9;">تولد ثبت شده</div>
					</div>
					<div style="background:rgba(255,255,255,0.18); padding:10px 18px; border-radius:10px; text-align:center; min-width:100px;">
						<div style="font-size:22px; font-weight:700;"><?php echo number_format_i18n( $today_count ); ?></div>
						<div style="font-size:11px; opacity:0.9;">امروز تولد است</div>
					</div>
				</div>
			</div>
		</div>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px; direction:rtl;">✓ تنظیمات ذخیره شد.</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['sent_now'] ) ) : ?>
			<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px; direction:rtl;">✓ ارسال انجام شد. <?php echo esc_html( rawurldecode( $_GET['msg'] ?? '' ) ); ?></div>
		<?php endif; ?>

		<!-- Tabs -->
		<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; direction:rtl;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-birthday&tt=settings' ) ); ?>" style="background:<?php echo $tab === 'settings' ? '#0f172a' : '#fff'; ?>; color:<?php echo $tab === 'settings' ? '#fff' : '#475569'; ?>; border:1px solid <?php echo $tab === 'settings' ? '#0f172a' : '#cbd5e1'; ?>; padding:9px 18px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;">⚙️ تنظیمات و الگو</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-birthday&tt=list' ) ); ?>" style="background:<?php echo $tab === 'list' ? '#0f172a' : '#fff'; ?>; color:<?php echo $tab === 'list' ? '#fff' : '#475569'; ?>; border:1px solid <?php echo $tab === 'list' ? '#0f172a' : '#cbd5e1'; ?>; padding:9px 18px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;">📋 لیست تولدها (<?php echo number_format_i18n( $total ); ?>)</a>
		</div>

		<?php if ( $tab === 'settings' ) {
			wto_birthday_render_settings_tab( $s );
		} else {
			wto_birthday_render_list_tab();
		} ?>
	</section>
	<?php
}

function wto_birthday_render_settings_tab( $s ) {
	$last_stats = get_option( 'wto_birthday_last_stats', array() );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="direction:rtl;">
		<input type="hidden" name="action" value="wto_birthday_save_settings">
		<?php wp_nonce_field( 'wto_birthday_save_settings' ); ?>

		<!-- بلوک ۱: فعال‌سازی -->
		<div style="background:#fff; border:1.5px solid <?php echo $s['enabled'] === '1' ? '#bbf7d0' : '#fecaca'; ?>; border-radius:12px; padding:18px 22px; margin-bottom:16px;">
			<label style="display:flex; align-items:center; gap:12px; cursor:pointer;">
				<input type="checkbox" class="wto-toggle" name="enabled" value="1" <?php checked( $s['enabled'], '1' ); ?> style="width:20px; height:20px;">
				<div>
					<div style="font-weight:700; font-size:15px; color:#0f172a;">✨ فعال‌سازی سیستم تبریک تولد</div>
					<div style="font-size:12px; color:#64748b; margin-top:3px;">با فعال‌سازی، فیلدهای جمع‌آوری تاریخ تولد روی سایت ظاهر می‌شوند و cron روزانه شروع به کار می‌کند.</div>
				</div>
			</label>
		</div>

		<!-- بلوک ۲: تنظیمات پترن -->
		<div style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:18px 22px; margin-bottom:16px;">
			<h3 style="margin:0 0 14px; font-size:14.5px; color:#0f172a; font-weight:700;">📨 پترن پیامک و زمان ارسال</h3>
			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:12px;">
				<label style="display:block;">
					<span style="display:block; font-size:12.5px; font-weight:600; color:#374151; margin-bottom:6px;">کد پترن تأیید‌شده</span>
					<input type="text" name="pattern_code" value="<?php echo esc_attr( $s['pattern_code'] ); ?>" placeholder="مثال: H7fGk2mP" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px; direction:ltr; font-family:Menlo,Consolas,monospace; font-size:13px;">
					<small style="color:#94a3b8; font-size:11px;">پترن باید متغیرهای <code>%first_name%</code> و <code>%coupon_code%</code> داشته باشد</small>
				</label>
				<label style="display:block;">
					<span style="display:block; font-size:12.5px; font-weight:600; color:#374151; margin-bottom:6px;">ساعت ارسال (۰-۲۳)</span>
					<input type="number" name="send_hour" value="<?php echo esc_attr( $s['send_hour'] ); ?>" min="0" max="23" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
					<small style="color:#94a3b8; font-size:11px;">به وقت سرور سایت (پیش‌فرض: ۱۰ صبح)</small>
				</label>
			</div>

			<div style="background:#fefce8; border:1px solid #fde68a; border-radius:7px; padding:10px 12px; margin-top:10px; font-size:12px; color:#854d0e; line-height:1.7;">
				💡 <strong>نمونه متن پترن:</strong>
				<br>
				<pre style="margin:6px 0 0; background:#fff; padding:10px; border-radius:5px; direction:rtl; white-space:pre-wrap; font-family:inherit; font-size:12px; color:#0f172a;">سلام %first_name% عزیز 🎂
تولدت مبارک!
کد تخفیف انحصاری روز تولد شما:
%coupon_code%

این کد تا ۷ روز معتبر است.
لغو ۱۱</pre>
			</div>
		</div>

		<!-- بلوک ۳: کوپن تخفیف -->
		<div style="background:#fff; border:1.5px solid #fde68a; border-radius:12px; padding:18px 22px; margin-bottom:16px;">
			<label style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
				<input type="checkbox" class="wto-toggle" name="coupon_enabled" value="1" <?php checked( $s['coupon_enabled'], '1' ); ?> style="width:18px; height:18px;">
				<span style="font-weight:700; font-size:14.5px; color:#0f172a;">🎁 ساخت کد تخفیف اختصاصی برای هر مشتری</span>
			</label>
			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px;">
				<label>
					<span style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">نوع تخفیف</span>
					<select name="coupon_type" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
						<option value="percent" <?php selected( $s['coupon_type'], 'percent' ); ?>>درصدی (٪)</option>
						<option value="fixed_cart" <?php selected( $s['coupon_type'], 'fixed_cart' ); ?>>مبلغ ثابت (تومان)</option>
					</select>
				</label>
				<label>
					<span style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">مقدار تخفیف</span>
					<input type="number" name="coupon_amount" value="<?php echo esc_attr( $s['coupon_amount'] ); ?>" min="0" step="0.01" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
				</label>
				<label>
					<span style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">اعتبار (روز)</span>
					<input type="number" name="coupon_validity_days" value="<?php echo esc_attr( $s['coupon_validity_days'] ); ?>" min="1" max="365" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
				</label>
				<label>
					<span style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">پیشوند کد</span>
					<input type="text" name="coupon_prefix" value="<?php echo esc_attr( $s['coupon_prefix'] ); ?>" maxlength="10" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px; direction:ltr; font-family:Menlo,Consolas,monospace;">
				</label>
				<label>
					<span style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">حداقل خرید (تومان)</span>
					<input type="number" name="coupon_min_amount" value="<?php echo esc_attr( $s['coupon_min_amount'] ); ?>" min="0" step="1000" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;">
				</label>
			</div>
		</div>

		<!-- بلوک ۴: منابع جمع‌آوری -->
		<div style="background:#fff; border:1.5px solid #bae6fd; border-radius:12px; padding:18px 22px; margin-bottom:16px;">
			<h3 style="margin:0 0 12px; font-size:14.5px; color:#0f172a; font-weight:700;">📥 منابع جمع‌آوری تاریخ تولد</h3>
			<label style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
				<input type="checkbox" class="wto-toggle" name="capture_checkout" value="1" <?php checked( $s['capture_checkout'], '1' ); ?>>
				<span style="font-size:13px; color:#374151;"><strong>checkout ووکامرس</strong> — فیلد اختیاری زیر بخش صورتحساب</span>
			</label>
			<label style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
				<input type="checkbox" class="wto-toggle" name="capture_myaccount" value="1" <?php checked( $s['capture_myaccount'], '1' ); ?>>
				<span style="font-size:13px; color:#374151;"><strong>حساب کاربری</strong> — فیلد در «ویرایش آدرس» مشتری</span>
			</label>
			<label style="display:flex; align-items:center; gap:10px;">
				<input type="checkbox" class="wto-toggle" name="capture_shortcode" value="1" <?php checked( $s['capture_shortcode'], '1' ); ?>>
				<span style="font-size:13px; color:#374151;"><strong>شورت‌کد</strong> — <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;">[farazsms_birthday_form]</code> برای landing page</span>
			</label>
		</div>

		<!-- دکمه ذخیره + آمار آخرین run -->
		<div style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:18px 22px; display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
			<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:10px 28px; border-radius:8px; font-size:13.5px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(67,56,202,0.25);">💾 ذخیره تنظیمات</button>
		</div>
	</form>

	<!-- دکمه ارسال دستی -->
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px; direction:rtl;">
		<input type="hidden" name="action" value="wto_birthday_send_now">
		<?php wp_nonce_field( 'wto_birthday_send_now' ); ?>
		<div style="background:#fff; border:1.5px dashed #cbd5e1; border-radius:12px; padding:14px 18px; display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
			<div style="flex:1; min-width:200px;">
				<div style="font-weight:600; font-size:13px; color:#0f172a;">🧪 ارسال دستی همین الان (تست)</div>
				<div style="font-size:11.5px; color:#64748b;">برای تست: تولدهای امروز فوراً ارسال می‌شوند (حتی اگر ساعت ارسال هنوز نرسیده باشد).</div>
			</div>
			<button type="submit" style="background:#dc2626; color:#fff; border:none; padding:9px 20px; border-radius:7px; font-size:12.5px; font-weight:700; cursor:pointer;">🚀 ارسال اکنون</button>
		</div>
	</form>

	<?php if ( ! empty( $last_stats ) ) : ?>
		<div style="margin-top:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; direction:rtl; font-size:12.5px; color:#475569; line-height:1.8;">
			<strong style="color:#0f172a;">📊 آخرین اجرا:</strong>
			تاریخ <code style="direction:ltr; background:#fff; padding:2px 6px; border-radius:4px;"><?php echo esc_html( $last_stats['date'] ?? '' ); ?></code>
			— روز شمسی <strong><?php echo esc_html( $last_stats['jalali'] ?? '' ); ?></strong>
			— پیدا شد <?php echo (int) ( $last_stats['matched'] ?? 0 ); ?>
			— ارسال شد <strong style="color:#16a34a;"><?php echo (int) ( $last_stats['sent'] ?? 0 ); ?></strong>
			— ناموفق <strong style="color:#dc2626;"><?php echo (int) ( $last_stats['failed'] ?? 0 ); ?></strong>
		</div>
	<?php endif; ?>
	<?php
}

function wto_birthday_render_list_tab() {
	global $wpdb;
	$table = wto_birthday_table();
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$per_page = 25;
	$offset   = ( $paged - 1 ) * $per_page;

	$where = ' WHERE 1=1';
	$args  = array();
	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (mobile LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR jalali_md LIKE %s)';
		$args  = array( $like, $like, $like, $like );
	}

	$total = (int) $wpdb->get_var( empty( $args ) ? "SELECT COUNT(*) FROM $table $where" : $wpdb->prepare( "SELECT COUNT(*) FROM $table $where", ...$args ) );
	$rows_sql = "SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d";
	$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...array_merge( $args, array( $per_page, $offset ) ) ) );
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );

	$source_label = array(
		'checkout'  => '🛒 checkout',
		'myaccount' => '👤 حساب کاربری',
		'shortcode' => '🔗 شورت‌کد',
		'import'    => '📥 import',
		'manual'    => '✋ دستی',
	);
	?>
	<div style="direction:rtl;">
		<form method="get" action="" style="margin-bottom:14px; display:flex; gap:8px; align-items:center;">
			<input type="hidden" name="page" value="farazwto-birthday">
			<input type="hidden" name="tt" value="list">
			<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="🔍 جستجو در شماره / نام / تاریخ (مثل 03-15)" style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
			<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-size:13px; cursor:pointer;">جستجو</button>
		</form>

		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
			<div style="overflow-x:auto;">
				<table style="width:100%; border-collapse:collapse; font-size:12.5px;">
					<thead style="background:#f8fafc;">
						<tr>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb;">مشتری</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb;">موبایل</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb;">تاریخ تولد</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb;">منبع</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb;">آخرین SMS</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="5" style="text-align:center; padding:34px; color:#64748b;">هیچ تولدی هنوز ثبت نشده. به محض اولین ثبت در checkout یا شورت‌کد، اینجا نمایش داده می‌شود.</td></tr>
						<?php else : foreach ( $rows as $r ) : ?>
							<tr style="border-bottom:1px solid #f1f5f9;">
								<td style="padding:10px 14px;"><?php echo esc_html( trim( $r->first_name . ' ' . $r->last_name ) ?: '—' ); ?></td>
								<td style="padding:10px 14px; direction:ltr; text-align:right; font-family:Menlo,Consolas,monospace; color:#475569;"><?php echo esc_html( $r->mobile ); ?></td>
								<td style="padding:10px 14px;"><strong style="direction:ltr; display:inline-block;"><?php echo esc_html( $r->jalali_full ?: $r->jalali_md ); ?></strong></td>
								<td style="padding:10px 14px;"><span style="background:#f1f5f9; padding:3px 10px; border-radius:12px; font-size:11.5px;"><?php echo esc_html( $source_label[ $r->source ] ?? $r->source ); ?></span></td>
								<td style="padding:10px 14px;"><?php echo $r->last_sms_year > 0 ? '<span style="color:#16a34a; font-weight:600;">سال ' . esc_html( $r->last_sms_year ) . '</span>' : '<span style="color:#94a3b8;">هنوز ارسال نشده</span>'; ?></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $total_pages > 1 ) : ?>
				<div style="padding:12px 16px; background:#f8fafc; border-top:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
					<div style="font-size:12px; color:#64748b;">صفحه <?php echo $paged; ?> از <?php echo $total_pages; ?> — مجموع <?php echo number_format_i18n( $total ); ?> رکورد</div>
					<div style="display:flex; gap:6px;">
						<?php
						$pag_base = array_filter( array( 'page' => 'farazwto-birthday', 'tt' => 'list', 's' => $search ) );
						if ( $paged > 1 ) echo '<a href="' . esc_url( add_query_arg( array_merge( $pag_base, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) ) ) . '" style="background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; font-size:12px;">قبلی</a>';
						if ( $paged < $total_pages ) echo '<a href="' . esc_url( add_query_arg( array_merge( $pag_base, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) ) ) . '" style="background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; font-size:12px;">بعدی</a>';
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
