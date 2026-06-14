<?php
/**
 * Daily Sales Report SMS — Phase 14 (v3.14.8)
 *
 * هر شب ساعت 00:10 (پیش‌فرض)، آمار فروش ۲۴ ساعت گذشته (مجموع مبلغ + تعداد سفارش)
 * را به شماره مدیران فروشگاه به‌صورت پیامک با پترن ارسال می‌کند.
 *
 * Option:
 *
 *   wto_daily_sales_settings:
 *     - enabled        ('1' یا '0' — پیش‌فرض: '1')
 *     - admin_phones   (رشته با شماره‌ها جدا با ,)
 *     - pattern_code   (پیش‌فرض: GPwbnk6dDE)
 *     - hour           (پیش‌فرض: '00:10')
 *
 * متغیرهای پترن:
 *   - %amount%       مجموع مبلغ سفارشات
 *   - %order_count%  تعداد سفارشات
 *
 * طراحی برای ۱۰۰k سایت:
 *   - یک query WC_Order_Query در روز فقط در cron — صفر impact روی frontend
 *   - statuses فقط 'completed' و 'processing' — قابل تنظیم با فیلتر
 *   - چند شماره مدیر → چند ریکوئست جدا به API فراز (پترن single-recipient است)
 *   - اگر WC غیرفعال شد یا API قطع شد، cron برمی‌گردد بدون خطا
 *   - تابع render در صفحه تنظیمات افزونه inject می‌شود (نه صفحه جدا)
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_DAILY_REPORT_CRON_HOOK = 'wto_daily_sales_report_cron';

function wto_daily_report_get_settings() {
	$defaults = array(
		'enabled'      => '1',
		'admin_phones' => '',
		'pattern_code' => 'GPwbnk6dDE',
		'hour'         => '00:10',
	);
	$saved = get_option( 'wto_daily_sales_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( $defaults, $saved );
}

function wto_daily_report_is_enabled() {
	$s = wto_daily_report_get_settings();
	return $s['enabled'] === '1';
}

// ── Cron Registration ────────────────────────────────────────────────────

/**
 * ثبت cron — همیشه یک‌بار در روز.
 * نکته: WP-Cron قابل اعتماد نیست برای زمان دقیق (فقط در بازدید سایت اجرا می‌شود).
 * برای زمان دقیق، system cron پیشنهاد می‌شود (راهنما در UI نوشته شده).
 */
add_action( 'init', 'wto_daily_report_schedule_cron' );
function wto_daily_report_schedule_cron() {
	if ( wp_next_scheduled( WTO_DAILY_REPORT_CRON_HOOK ) ) {
		return;
	}
	// زمان اجرای اول: ساعت تنظیم‌شده فردا
	$s          = wto_daily_report_get_settings();
	$hour       = $s['hour'];
	$site_tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
	$tomorrow   = new DateTime( 'tomorrow ' . $hour, $site_tz );
	$timestamp  = $tomorrow->getTimestamp();
	wp_schedule_event( $timestamp, 'daily', WTO_DAILY_REPORT_CRON_HOOK );
}

// re-schedule هر بار که option تغییر کرد (تغییر ساعت)
add_action( 'update_option_wto_daily_sales_settings', 'wto_daily_report_reschedule', 10, 2 );
function wto_daily_report_reschedule( $old, $new ) {
	$old_hour = ( is_array( $old ) && isset( $old['hour'] ) ) ? $old['hour'] : '';
	$new_hour = ( is_array( $new ) && isset( $new['hour'] ) ) ? $new['hour'] : '00:10';
	if ( $old_hour === $new_hour ) {
		return;
	}
	wp_clear_scheduled_hook( WTO_DAILY_REPORT_CRON_HOOK );
	$site_tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
	$tomorrow  = new DateTime( 'tomorrow ' . $new_hour, $site_tz );
	wp_schedule_event( $tomorrow->getTimestamp(), 'daily', WTO_DAILY_REPORT_CRON_HOOK );
}

// ── Cron Handler ────────────────────────────────────────────────────────

add_action( WTO_DAILY_REPORT_CRON_HOOK, 'wto_daily_report_run' );
function wto_daily_report_run() {
	if ( ! wto_daily_report_is_enabled() ) {
		return;
	}
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return; // WC غیرفعال — silent skip
	}
	$s = wto_daily_report_get_settings();

	$phones_raw = trim( (string) $s['admin_phones'] );
	// اگر شماره‌ی مدیر خالی است، از وب‌سرویسِ پروفایلِ پنلِ فراز، شماره‌ی مالکِ پنل را بگیر.
	if ( $phones_raw === '' && function_exists( 'wto_get_profile' ) ) {
		$profile = wto_get_profile();
		if ( is_array( $profile ) && ! empty( $profile['mobile'] ) ) {
			$phones_raw = (string) $profile['mobile'];
		}
	}
	if ( $phones_raw === '' ) {
		return;
	}
	$phones = array_filter( array_map( 'trim', explode( ',', $phones_raw ) ) );
	if ( empty( $phones ) ) {
		return;
	}

	$pattern_code = trim( (string) $s['pattern_code'] );
	if ( $pattern_code === '' ) {
		return;
	}

	// محاسبه آمار ۲۴ ساعت گذشته
	list( $total_amount, $order_count ) = wto_daily_report_calculate_stats();

	// طبق خواسته‌ی کاربر: حتی در روزِ بدونِ فروش هم پیامک بفرست («۰ تومان، ۰ سفارش» هم
	// اطلاع‌رسانیِ مفیدی است که افزونه زنده و فعال است). قبلاً اینجا return می‌شد.

	// پترنِ گزارشِ فروش متغیرهای نام‌دار «amount» (مبلغِ فروش) و «count» (تعدادِ سفارش)
	// دارد — همان‌طور که خطای «فیلد amount باید ارسال شود» نشان داد. متغیرهای نام‌دار
	// مثلِ کد رهگیری روی API سالم‌اند، پس همین نام‌ها را می‌فرستیم.
	$attrs = array(
		'amount'      => number_format( $total_amount ),
		'order_count' => (string) $order_count,
	);

	$sender = trim( (string) get_option( 'wto_sender', '' ) );

	// چند شماره → چند ریکوئست جدا (API پترن single-recipient است)
	$sent_ok    = 0;
	$send_error = '';
	foreach ( $phones as $phone ) {
		if ( function_exists( 'wto_normalize_phone' ) ) {
			$phone = wto_normalize_phone( $phone );
		}
		if ( $phone === '' ) {
			continue;
		}
		if ( function_exists( 'wto_send_pattern_sms_raw' ) ) {
			$res = wto_send_pattern_sms_raw( $phone, $pattern_code, $attrs, $sender );
			if ( $res === 'success' || $res === true ) {
				$sent_ok++;
			} elseif ( $send_error === '' && is_string( $res ) ) {
				$send_error = $res;
			}
		}
	}

	// لاگ آخرین اجرا — وضعیتِ واقعیِ ارسال ثبت می‌شود (نه صرفِ تلاش). قبلاً بدونِ
	// بررسیِ پاسخِ API همیشه «ارسال شد» نوشته می‌شد؛ برای همین حتی وقتی پترن تأیید
	// نشده/شماره اشتباه/اعتبار صفر بود، باز هم «ارسال شد» نمایش داده می‌شد.
	update_option( 'wto_daily_sales_last_run', array(
		'time'        => current_time( 'mysql' ),
		'amount'      => $total_amount,
		'order_count' => $order_count,
		'recipients'  => count( $phones ),
		'sent_ok'     => $sent_ok,
		'success'     => $sent_ok > 0,
		'error'       => $send_error,
	), false );
}

/**
 * محاسبه آمار ۲۴ ساعت گذشته با WC_Order_Query.
 *
 * @return array [total_amount, order_count]
 */
function wto_daily_report_calculate_stats() {
	$site_tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
	$now       = new DateTime( 'now', $site_tz );
	$yesterday = new DateTime( '-24 hours', $site_tz );

	$args = array(
		'limit'        => -1,
		'status'       => apply_filters( 'wto_daily_report_order_statuses', array( 'completed', 'processing' ) ),
		'date_created' => $yesterday->getTimestamp() . '...' . $now->getTimestamp(),
		'return'       => 'objects',
	);
	$orders = wc_get_orders( $args );

	$total = 0.0;
	$count = 0;
	if ( is_array( $orders ) ) {
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$total += (float) $order->get_total();
				$count++;
			}
		}
	}
	return array( $total, $count );
}

// ── Admin UI — Section در صفحه farazwto-settings ─────────────────────────

// v3.14.9: قبلاً از admin_notices استفاده می‌کرد که کارت را «بالای صفحه» قرار
// می‌داد (بیرون از قاب visual صفحه تنظیمات). حالا از یک action hook اختصاصی
// استفاده می‌شود که داخل خود template صفحه تنظیمات قرار می‌گیرد — همراستا با
// بقیه محتوا.
add_action( 'wto_settings_page_extra_sections', 'wto_daily_report_render_section' );

// POST handler جدا از render — تا قبل از render اجرا شود.
add_action( 'admin_init', 'wto_daily_report_handle_save' );
function wto_daily_report_handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || empty( $_POST['wto_daily_report_save'] ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $page !== 'farazwto-settings' ) {
		return;
	}
	check_admin_referer( 'wto_daily_report_settings', 'wto_daily_report_nonce' );
	$current = wto_daily_report_get_settings();
	$current['enabled']      = isset( $_POST['daily_report_enabled'] ) ? '1' : '0';
	$current['admin_phones'] = sanitize_text_field( $_POST['daily_report_admin_phones'] ?? '' );
	$current['pattern_code'] = sanitize_text_field( $_POST['daily_report_pattern_code'] ?? 'GPwbnk6dDE' );
	$current['hour']         = sanitize_text_field( $_POST['daily_report_hour'] ?? '00:10' );
	update_option( 'wto_daily_sales_settings', $current, false );
	wp_safe_redirect( add_query_arg( 'wto_daily_saved', '1', admin_url( 'admin.php?page=farazwto-settings' ) ) );
	exit;
}

// اجرای دستی برای تست — همان تابعِ cron را اجرا می‌کند و نتیجه‌ی واقعی را در «آخرین اجرا» نشان می‌دهد.
add_action( 'admin_init', 'wto_daily_report_handle_test_send' );
function wto_daily_report_handle_test_send() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || empty( $_POST['wto_daily_report_test'] ) ) {
		return;
	}
	if ( ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) !== 'farazwto-settings' ) {
		return;
	}
	check_admin_referer( 'wto_daily_report_settings', 'wto_daily_report_nonce' );
	wto_daily_report_run(); // وضعیتِ واقعی (موفق/خطا) در option ثبت می‌شود.
	wp_safe_redirect( add_query_arg( 'wto_daily_tested', '1', admin_url( 'admin.php?page=farazwto-settings' ) ) );
	exit;
}

function wto_daily_report_render_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// نکته: POST handler حالا در admin_init قبل از render اجرا می‌شود.
	// اینجا فقط notice موفقیت را بر اساس query string نمایش می‌دهیم.
	$saved_ok = isset( $_GET['wto_daily_saved'] ) && $_GET['wto_daily_saved'] === '1';

	$s        = wto_daily_report_get_settings();
	$last_run = get_option( 'wto_daily_sales_last_run', array() );

	// اگر شماره‌ی مدیر هنوز وارد نشده، شماره‌ی مالکِ پنل را از وب‌سرویسِ فراز پیش‌پُر کن
	// تا کاربر فقط ذخیره را بزند (و پیامک به همان شماره برود).
	$admin_phones_value = (string) $s['admin_phones'];
	if ( trim( $admin_phones_value ) === '' && function_exists( 'wto_get_profile' ) ) {
		$profile = wto_get_profile();
		if ( is_array( $profile ) && ! empty( $profile['mobile'] ) ) {
			$admin_phones_value = (string) $profile['mobile'];
		}
	}
	?>
	<!-- v3.14.9: داخل ساختار قاب صفحه تنظیمات — wrapper بیرونی حذف شد تا با
	     بقیه محتوای صفحه (فرم تنظیمات اصلی، connection panel) هم‌راستا باشد. -->
	<div style="direction:rtl; font-family:inherit; margin-top:24px;">
		<?php if ( $saved_ok ) : ?>
			<div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:10px 14px; border-radius:8px; margin-bottom:14px;">
				✓ تنظیمات گزارش روزانه ذخیره شد.
			</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['wto_daily_tested'] ) && $_GET['wto_daily_tested'] === '1' ) : ?>
			<div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:10px 14px; border-radius:8px; margin-bottom:14px;">
				📤 ارسالِ آزمایشی انجام شد — نتیجه‌ی واقعی را در کادرِ «آخرین اجرا» پایین ببینید.
			</div>
		<?php endif; ?>
		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:22px 24px;">
			<div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
				<div style="width:38px; height:38px; background:linear-gradient(135deg,#16a34a 0%,#059669 100%); color:#fff; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px;">📊</div>
				<div>
					<h3 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">گزارش پیامکی روزانه فروش</h3>
					<p style="margin:2px 0 0; font-size:11.5px; color:#64748b;">
						هر شب ساعت تنظیم‌شده، آمار ۲۴ ساعت گذشته (مجموع مبلغ + تعداد سفارش) به شماره مدیران ارسال می‌شود
					</p>
				</div>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'wto_daily_report_settings', 'wto_daily_report_nonce' ); ?>

				<!-- Master toggle -->
				<label style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:<?php echo $s['enabled']==='1' ? '#f0fdf4' : '#fff7ed'; ?>; border:1px solid <?php echo $s['enabled']==='1' ? '#bbf7d0' : '#fed7aa'; ?>; border-radius:10px; cursor:pointer; margin-bottom:14px;">
					<input type="checkbox" class="wto-toggle" name="daily_report_enabled" value="1" <?php checked( $s['enabled'], '1' ); ?> style="margin:0; width:18px; height:18px;">
					<span style="flex:1; font-size:13px; font-weight:600;">
						ارسال پیامک گزارش روزانه فروش به مدیر
						<?php echo $s['enabled']==='1' ? '<span style="background:#16a34a; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px;">فعال ✓</span>' : '<span style="background:#f97316; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px;">غیرفعال</span>'; ?>
					</span>
				</label>

				<!-- Phones + Hour + Pattern -->
				<div style="display:flex; flex-wrap:wrap; gap:14px; margin-bottom:14px;">
					<div style="flex:1 1 360px;">
						<label for="daily_report_admin_phones" style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
							شماره موبایل مدیران <span style="color:#dc2626;">*</span>
						</label>
						<input type="text" id="daily_report_admin_phones" name="daily_report_admin_phones" value="<?php echo esc_attr( $admin_phones_value ); ?>" placeholder="09120000000,09130000000" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; direction:ltr; text-align:left; font-family:monospace;">
						<p style="margin:4px 0 0; font-size:11px; color:#64748b;">برای چند مدیر، شماره‌ها را با کاما (<code>,</code>) جدا کنید — به هر یک پیامک جداگانه می‌رود.</p>
					</div>
					<div style="flex:1 1 120px;">
						<label for="daily_report_hour" style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">ساعت ارسال</label>
						<input type="time" id="daily_report_hour" name="daily_report_hour" value="<?php echo esc_attr( $s['hour'] ); ?>" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
						<p style="margin:4px 0 0; font-size:11px; color:#64748b;">پیش‌فرض: <code>00:10</code></p>
					</div>
				</div>

				<div style="margin-bottom:14px;">
					<label for="daily_report_pattern_code" style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">کد پترن</label>
					<input type="text" id="daily_report_pattern_code" name="daily_report_pattern_code" value="<?php echo esc_attr( $s['pattern_code'] ); ?>" placeholder="GPwbnk6dDE" style="width:100%; max-width:300px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; direction:ltr; text-align:left; font-family:monospace;">
					<p style="margin:4px 0 0; font-size:11px; color:#64748b; line-height:1.8;">
						متغیرهای پترن: <code>%amount%</code> (مجموع مبلغ) و <code>%order_count%</code> (تعداد سفارش)
					</p>
				</div>

				<!-- Last run info -->
				<?php if ( ! empty( $last_run ) && is_array( $last_run ) ) :
					$lr_time   = isset( $last_run['time'] ) ? (string) $last_run['time'] : '';
					$lr_jalali = ( $lr_time !== '' && function_exists( 'wto_send_reports_to_jalali' ) ) ? wto_send_reports_to_jalali( $lr_time ) : $lr_time;
					// سازگاری با لاگ‌های قدیمی که فیلدِ success نداشتند.
					$lr_ok      = array_key_exists( 'success', $last_run ) ? (bool) $last_run['success'] : true;
					$lr_sent_ok = isset( $last_run['sent_ok'] ) ? (int) $last_run['sent_ok'] : (int) ( $last_run['recipients'] ?? 0 );
					?>
					<div style="background:<?php echo $lr_ok ? '#eef2ff' : '#fef2f2'; ?>; border:1px solid <?php echo $lr_ok ? '#c7d2fe' : '#fecaca'; ?>; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:11.5px; color:<?php echo $lr_ok ? '#3730a3' : '#b32d2e'; ?>;">
						📤 آخرین اجرا: <strong><?php echo esc_html( $lr_jalali ); ?></strong>
						— <?php echo esc_html( number_format_i18n( $last_run['amount'] ?? 0 ) ); ?> تومان
						/ <?php echo esc_html( number_format_i18n( $last_run['order_count'] ?? 0 ) ); ?> سفارش
						<?php if ( $lr_ok ) : ?>
							/ ✅ به <?php echo esc_html( $lr_sent_ok ); ?> شماره ارسال شد
						<?php else : ?>
							/ ❌ ارسال ناموفق<?php echo ! empty( $last_run['error'] ) ? ' — ' . esc_html( $last_run['error'] ) : ''; ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<!-- Tip -->
				<div style="background:#fffbeb; border:1px solid #fde68a; color:#78350f; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:11.5px; line-height:1.9;">
					<strong>💡 نکته فنی:</strong> WP-Cron فقط هنگام بازدید سایت اجرا می‌شود.
					برای ساعت دقیق ارسال، در سرور خود system cron تنظیم کنید (cPanel/CLI). در غیر این صورت پیامک ممکن است چند دقیقه/ساعت دیرتر ارسال شود.
				</div>

				<button type="submit" name="wto_daily_report_save" value="1" style="background:#4338ca; color:#fff; border:none; padding:9px 22px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit;">
					💾 ذخیره
				</button>
				<button type="submit" name="wto_daily_report_test" value="1" style="background:#0f766e; color:#fff; border:none; padding:9px 22px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit; margin-right:8px;">
					📤 ارسال آزمایشی الان
				</button>
				<p style="margin-top:8px; font-size:11px; color:#64748b;">«ارسال آزمایشی الان» همین حالا گزارش را می‌فرستد و نتیجه‌ی واقعی (موفق یا خطا) را در کادرِ «آخرین اجرا» نشان می‌دهد.</p>
			</form>
		</div>
	</div>
	<?php
}
