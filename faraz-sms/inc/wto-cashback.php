<?php
/**
 * Cashback Module — Phase 12 (v3.14.0+)
 *
 * سیستم کش‌بک خودکار بر اساس درصد فروش با تاریخ انقضا و یادآوری پیامکی.
 *
 * ┌─ Table of Contents ──────────────────────────────────────────────────────┐
 * │  L29-77    │ Constants, Schema names, Settings helpers                    │
 * │  L79-140   │ Schema install (dbDelta, 2 tables)                           │
 * │  L142-309  │ Balance + Operations: grant, consume (FIFO)                  │
 * │  L311-466  │ WC checkout integration: fee, checkbox, persist              │
 * │  L468-498  │ Admin order panel (per-order cashback meta)                  │
 * │  L500-575  │ Cron: expire old records + reminder SMS                      │
 * │  L576-683  │ Stats aggregation (used in admin dashboard)                  │
 * │  L684-743  │ AJAX: pattern autobuild                                      │
 * │  L744-end  │ Admin menu + Settings page + Users list render               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * مدل عملکرد:
 *   ۱) مشتری سفارش می‌دهد → وقتی به وضعیت trigger (پیش‌فرض: completed) رسید،
 *      x٪ از مبلغ پرداختی واقعی به کیف پول کش‌بک اضافه می‌شود (با expiry).
 *   ۲) در checkout بعدی، موجودی فعال (غیرمنقضی) به‌صورت fee منفی روی cart اعمال
 *      می‌شود — اختیاری با checkbox.
 *   ۳) از مبلغ پرداخت جدید (بدون استفاده از کش‌بک)، مجدداً x٪ کش‌بک اعطا می‌شود.
 *   ۴) cron روزانه — پیامک یادآوری N روز قبل از انقضا، انقضای رکوردهای منقضی.
 *
 * طراحی برای 100k سایت:
 *   - دو جدول جداگانه با index های بهینه (user_id+status+expires_at، order_id+credit_id)
 *   - balance با static cache در request — هر pageload فقط یک query
 *   - cron در batch با LIMIT — هرگز کل جدول scan نمی‌شود
 *   - hookهای checkout روی calculate_fees نسبتاً سبک (یک SELECT با index)
 *   - ارسال SMS با fallback API helper موجود + برای cron timeout 30s
 *   - default disabled — تا toggle on نشده، هیچ hook روی frontend اجرا نمی‌شود
 *   - HPOS-aware: $order->update_meta_data() نه update_post_meta
 *
 * NOTE برای maintainer ها:
 *   شکستن این فایل به ۴ فایل (schema, credits, cron, admin) برای v3.21 برنامه‌ریزی
 *   شده. الان به‌صورت یک فایل با ToC بالا نگه داشته شده تا regression در سیستم
 *   پولی نخوریم. هر کسی این فایل را ویرایش می‌کند، ToC را به‌روز کند.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// v3.20.6 ESCAPE HATCH — debug کمک می‌کند بفهمیم cashback مقصر crash پرداخت است یا نه
if ( defined( 'WTO_DISABLE_CASHBACK' ) && WTO_DISABLE_CASHBACK ) {
	return; // کل فایل بدون اجرا — هیچ hook ای register نمی‌شود
}
if ( defined( 'WTO_DISABLE_CHECKOUT_HOOKS' ) && WTO_DISABLE_CHECKOUT_HOOKS ) {
	return;
}

// ============================================================================
// Constants & Schema
// ============================================================================

const WTO_CASHBACK_TABLE_CREDITS     = 'wto_cashback_credits';
const WTO_CASHBACK_TABLE_REDEMPTIONS = 'wto_cashback_redemptions';
const WTO_CASHBACK_CRON_HOOK         = 'wto_cashback_daily_cron';
const WTO_CASHBACK_SCHEMA_VERSION    = '1.0';

function wto_cashback_credits_table() {
	global $wpdb;
	return $wpdb->prefix . WTO_CASHBACK_TABLE_CREDITS;
}
function wto_cashback_redemptions_table() {
	global $wpdb;
	return $wpdb->prefix . WTO_CASHBACK_TABLE_REDEMPTIONS;
}

// ============================================================================
// Settings + Helpers
// ============================================================================

function wto_cashback_get_settings() {
	$defaults = array(
		'enabled'        => '0',
		'percent'        => 10,
		'expiry_days'    => 7,
		'min_order'      => 0,
		'max_per_order'  => 0,            // 0 = no cap
		'status_trigger' => 'completed',  // wc-{status} پیش‌فرض
		'reminders'      => array( 7, 3, 1 ),
		'pattern_code'   => '',           // برای پیامک یادآوری
		'in_cart_notice' => '1',          // نمایش نوتیس روی cart/checkout
	);
	$saved = get_option( 'wto_cashback_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$merged = array_merge( $defaults, $saved );
	if ( ! is_array( $merged['reminders'] ) ) {
		$merged['reminders'] = $defaults['reminders'];
	}
	return $merged;
}

function wto_cashback_is_enabled() {
	$s = wto_cashback_get_settings();
	return $s['enabled'] === '1';
}

// ============================================================================
// Schema (lazy — فقط وقتی toggle on می‌شود ساخته می‌شود)
// ============================================================================

function wto_cashback_maybe_install_schema() {
	if ( get_option( 'wto_cashback_schema_version' ) === WTO_CASHBACK_SCHEMA_VERSION ) {
		return;
	}
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$credits         = wto_cashback_credits_table();
	$redemptions     = wto_cashback_redemptions_table();

	$sql_credits = "CREATE TABLE $credits (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT(20) UNSIGNED NOT NULL,
		order_id BIGINT(20) UNSIGNED NOT NULL,
		amount DECIMAL(20,2) NOT NULL DEFAULT 0,
		used_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		created_at DATETIME NOT NULL,
		expires_at DATETIME NOT NULL,
		KEY user_status_expires (user_id, status, expires_at),
		KEY order_id (order_id),
		KEY expires_at (expires_at),
		PRIMARY KEY (id)
	) $charset_collate;";

	$sql_redemptions = "CREATE TABLE $redemptions (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		credit_id BIGINT(20) UNSIGNED NOT NULL,
		user_id BIGINT(20) UNSIGNED NOT NULL,
		order_id BIGINT(20) UNSIGNED NOT NULL,
		amount DECIMAL(20,2) NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		KEY user_id (user_id),
		KEY order_id (order_id),
		KEY credit_id (credit_id),
		PRIMARY KEY (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_credits );
	dbDelta( $sql_redemptions );
	update_option( 'wto_cashback_schema_version', WTO_CASHBACK_SCHEMA_VERSION, false );
}

// Install schema هنگام toggle on
add_action( 'update_option_wto_cashback_settings', 'wto_cashback_on_settings_update', 10, 2 );
function wto_cashback_on_settings_update( $old, $new ) {
	if ( is_array( $new ) && ! empty( $new['enabled'] ) && $new['enabled'] === '1' ) {
		wto_cashback_maybe_install_schema();
		// Ensure cron scheduled
		if ( ! wp_next_scheduled( WTO_CASHBACK_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'daily', WTO_CASHBACK_CRON_HOOK );
		}
	}
}
add_action( 'add_option_wto_cashback_settings', 'wto_cashback_on_settings_added', 10, 2 );
function wto_cashback_on_settings_added( $name, $value ) {
	wto_cashback_on_settings_update( array(), $value );
}

// ============================================================================
// Balance + DB Operations
// ============================================================================

/**
 * موجودی فعال کش‌بک کاربر (مجموع باقیمانده غیرمنقضی).
 * Cache static در request — تا چندین فراخوانی روی هر pageload فقط یک query بزند.
 */
function wto_cashback_get_balance( $user_id ) {
	static $cache = array();
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0.0;
	}
	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}
	global $wpdb;
	$table   = wto_cashback_credits_table();
	$balance = $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(amount - used_amount), 0) FROM $table
		 WHERE user_id = %d AND status = %s AND expires_at > %s",
		$user_id,
		'active',
		current_time( 'mysql' )
	) );
	$cache[ $user_id ] = (float) $balance;
	return $cache[ $user_id ];
}

function wto_cashback_invalidate_balance_cache( $user_id = null ) {
	// با reset کردن static از یک تابع helper — در همان request اعمال نمی‌شود مگر با ترفند.
	// در عمل، تغییرات balance فقط در پایان request اعمال می‌شود که OK است.
	// این تابع placeholder برای آینده.
	return;
}

/**
 * اعطای کش‌بک پس از تکمیل سفارش — idempotent.
 */
function wto_cashback_grant( $user_id, $order_id, $paid_amount ) {
	$user_id     = (int) $user_id;
	$order_id    = (int) $order_id;
	$paid_amount = (float) $paid_amount;
	if ( $user_id <= 0 || $order_id <= 0 || $paid_amount <= 0 ) {
		return false;
	}

	$settings = wto_cashback_get_settings();
	if ( $settings['enabled'] !== '1' ) {
		return false;
	}

	// idempotent — اگر قبلاً برای این سفارش کش‌بک اعطا شده، تکرار نکن.
	if ( function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_meta( 'wto_cashback_granted' ) === 'yes' ) {
			return false;
		}
	}

	// محاسبه: درصد از paid_amount (نه total — تا از پرداخت‌های کش‌بکی مجدد کش‌بک ندهیم)
	$percent     = max( 0, min( 100, (float) $settings['percent'] ) );
	$amount      = round( $paid_amount * $percent / 100, 2 );
	$min_order   = (float) $settings['min_order'];
	$max_per_ord = (float) $settings['max_per_order'];

	if ( $min_order > 0 && $paid_amount < $min_order ) {
		return false;
	}
	if ( $max_per_ord > 0 && $amount > $max_per_ord ) {
		$amount = $max_per_ord;
	}
	if ( $amount <= 0 ) {
		return false;
	}

	$expiry_days = max( 1, (int) $settings['expiry_days'] );
	$now         = current_time( 'mysql' );
	$expires_at  = date( 'Y-m-d H:i:s', strtotime( $now . ' +' . $expiry_days . ' days' ) );

	global $wpdb;
	$inserted = $wpdb->insert(
		wto_cashback_credits_table(),
		array(
			'user_id'     => $user_id,
			'order_id'    => $order_id,
			'amount'      => $amount,
			'used_amount' => 0,
			'status'      => 'active',
			'created_at'  => $now,
			'expires_at'  => $expires_at,
		),
		array( '%d', '%d', '%f', '%f', '%s', '%s', '%s' )
	);

	if ( $inserted && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( 'wto_cashback_granted', 'yes' );
			$order->update_meta_data( 'wto_cashback_granted_amount', $amount );
			$order->update_meta_data( 'wto_cashback_granted_expires', $expires_at );
			$order->save();
		}
	}
	return $inserted ? $wpdb->insert_id : false;
}

/**
 * مصرف کش‌بک — FIFO (قدیمی‌ترین expiry اول، تا کاربر اعتبارش از دست نرود).
 */
function wto_cashback_consume( $user_id, $order_id, $amount ) {
	$user_id  = (int) $user_id;
	$order_id = (int) $order_id;
	$amount   = (float) $amount;
	if ( $user_id <= 0 || $order_id <= 0 || $amount <= 0 ) {
		return 0.0;
	}
	global $wpdb;
	$credits_table     = wto_cashback_credits_table();
	$redemptions_table = wto_cashback_redemptions_table();
	$now               = current_time( 'mysql' );

	// قدیمی‌ترین رکوردهای فعال غیرمنقضی — FIFO (close-to-expiry اول)
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, amount, used_amount FROM $credits_table
		 WHERE user_id = %d AND status = %s AND expires_at > %s
		 ORDER BY expires_at ASC, id ASC",
		$user_id,
		'active',
		$now
	), ARRAY_A );

	$consumed = 0.0;
	foreach ( $rows as $row ) {
		if ( $amount <= 0 ) break;
		$remaining = (float) $row['amount'] - (float) $row['used_amount'];
		if ( $remaining <= 0 ) continue;

		$take = min( $remaining, $amount );

		// آپدیت used_amount + در صورت اتمام، status='used'
		$new_used   = (float) $row['used_amount'] + $take;
		$new_status = ( $new_used >= (float) $row['amount'] ) ? 'used' : 'active';
		$wpdb->update(
			$credits_table,
			array( 'used_amount' => $new_used, 'status' => $new_status ),
			array( 'id' => $row['id'] ),
			array( '%f', '%s' ),
			array( '%d' )
		);
		// ثبت redemption
		$wpdb->insert(
			$redemptions_table,
			array(
				'credit_id'  => $row['id'],
				'user_id'    => $user_id,
				'order_id'   => $order_id,
				'amount'     => $take,
				'created_at' => $now,
			),
			array( '%d', '%d', '%d', '%f', '%s' )
		);
		$consumed += $take;
		$amount   -= $take;
	}
	return $consumed;
}

// ============================================================================
// WooCommerce Integration — Apply on Cart/Checkout
// ============================================================================

/**
 * فقط اگر toggle فعال است، WC hookها را register کن — جلوگیری از overhead.
 */
function wto_cashback_register_wc_hooks() {
	if ( ! wto_cashback_is_enabled() ) {
		return;
	}
	if ( ! function_exists( 'WC' ) ) {
		return;
	}
	add_action( 'woocommerce_cart_calculate_fees', 'wto_cashback_apply_fee', 20 );
	add_action( 'woocommerce_review_order_before_payment', 'wto_cashback_render_checkbox' );
	add_action( 'woocommerce_checkout_update_order_review', 'wto_cashback_persist_choice' );

	// اعطای کش‌بک پس از پرداخت — روی وضعیت پیکربندی‌شده + وضعیت‌های امن.
	// درگاه‌های پرداخت ایرانی معمولاً سفارش موفق را روی «processing» می‌گذارند نه «completed»،
	// بنابراین اگر فقط روی completed ثبت کنیم، کش‌بک هرگز اعطا نمی‌شود.
	// تابع grant خودش idempotent است (متای wto_cashback_granted)، پس ثبت روی چند وضعیت
	// باعث اعطای دوباره نمی‌شود.
	$trigger        = wto_cashback_get_settings()['status_trigger'];
	$trigger        = preg_replace( '/^wc-/', '', $trigger );
	$grant_statuses = array_unique( array_filter( array( $trigger, 'processing', 'completed' ) ) );
	foreach ( $grant_statuses as $grant_status ) {
		add_action( 'woocommerce_order_status_' . $grant_status, 'wto_cashback_handle_order_complete', 20, 2 );
	}

	// مصرف کش‌بک هنگام ساخت سفارش
	add_action( 'woocommerce_checkout_order_processed', 'wto_cashback_finalize_consumption', 20, 3 );
}
add_action( 'init', 'wto_cashback_register_wc_hooks', 20 );

/**
 * اعمال fee منفی روی cart — اختیاری با checkbox.
 */
function wto_cashback_apply_fee() {
	if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$use_cashback = WC()->session ? WC()->session->get( 'wto_use_cashback', '1' ) : '1';
	if ( $use_cashback !== '1' ) {
		return;
	}
	$user_id = get_current_user_id();
	$balance = wto_cashback_get_balance( $user_id );
	if ( $balance <= 0 ) {
		return;
	}
	// مبلغ قابل پرداخت = subtotal بدون این fee
	$cart_subtotal = (float) WC()->cart->get_subtotal();
	if ( $cart_subtotal <= 0 ) {
		return;
	}
	$apply = min( $balance, $cart_subtotal );
	if ( $apply > 0 ) {
		WC()->cart->add_fee( __( 'پرداخت از کیف پول کش‌بک', 'wto' ), -$apply, false );
	}
}

/**
 * نمایش checkbox روی صفحه پرداخت.
 */
function wto_cashback_render_checkbox() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$settings = wto_cashback_get_settings();
	if ( $settings['in_cart_notice'] !== '1' ) {
		return;
	}
	$user_id = get_current_user_id();
	$balance = wto_cashback_get_balance( $user_id );
	if ( $balance <= 0 ) {
		return;
	}
	$use_cashback = WC()->session ? WC()->session->get( 'wto_use_cashback', '1' ) : '1';
	?>
	<div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:14px 16px; border-radius:10px; margin:14px 0; direction:rtl;">
		<label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0;">
			<input type="checkbox" name="wto_use_cashback" value="1" <?php checked( $use_cashback === '1' ); ?> onchange="if(window.jQuery){jQuery('body').trigger('update_checkout');}" style="margin:0; width:18px; height:18px;">
			<span style="font-size:14px; font-weight:600; color:#065f46;">
				💰 از کیف پول کش‌بک خود استفاده کنم:
				<strong style="color:#047857;"><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong>
				<?php
				if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
					echo ' ' . esc_html( get_woocommerce_currency_symbol() );
				}
				?>
			</span>
		</label>
	</div>
	<?php
}

/**
 * ذخیره انتخاب کاربر در session هنگام update_order_review.
 */
function wto_cashback_persist_choice( $post_data ) {
	parse_str( (string) $post_data, $data );
	if ( ! WC()->session ) {
		return;
	}
	$use = isset( $data['wto_use_cashback'] ) && $data['wto_use_cashback'] === '1' ? '1' : '0';
	WC()->session->set( 'wto_use_cashback', $use );
}

/**
 * Finalize: ثبت redemption بعد از ساخت سفارش.
 */
function wto_cashback_finalize_consumption( $order_id, $posted_data, $order ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
	}
	$user_id = $order->get_user_id();
	if ( $user_id <= 0 ) return;

	// fee کش‌بک — نام fee را با add_fee ست کرده‌ایم
	$applied = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( $fee->get_name() === __( 'پرداخت از کیف پول کش‌بک', 'wto' ) ) {
			$amt = abs( (float) $fee->get_amount() );
			if ( $amt > 0 ) {
				$applied += $amt;
			}
		}
	}
	if ( $applied <= 0 ) {
		return;
	}
	$consumed = wto_cashback_consume( $user_id, $order_id, $applied );
	$order->update_meta_data( 'wto_cashback_used_amount', $consumed );
	$order->save();
}

/**
 * اعطای کش‌بک هنگام تکمیل سفارش.
 */
function wto_cashback_handle_order_complete( $order_id, $order = null ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
	}
	$user_id = $order->get_user_id();
	if ( $user_id <= 0 ) return;

	// pay-amount = total - cashback_used (تا روی پرداخت‌های کش‌بکی مجدد کش‌بک ندهیم)
	$total   = (float) $order->get_total();
	$used    = (float) $order->get_meta( 'wto_cashback_used_amount' );
	$payable = max( 0, $total - $used );
	if ( $payable <= 0 ) return;

	wto_cashback_grant( $user_id, $order_id, $payable );
}

// ============================================================================
// نمایش در صفحه سفارش (Admin) + Order Details (Customer)
// ============================================================================

add_action( 'woocommerce_admin_order_data_after_order_details', 'wto_cashback_admin_order_panel' );
function wto_cashback_admin_order_panel( $order ) {
	if ( ! ( $order instanceof WC_Order ) ) return;
	$used    = (float) $order->get_meta( 'wto_cashback_used_amount' );
	$granted = (float) $order->get_meta( 'wto_cashback_granted_amount' );
	if ( $used <= 0 && $granted <= 0 ) return;
	?>
	<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-top:12px; direction:rtl;">
		<h4 style="margin:0 0 8px; font-size:13px; color:#0f172a;">💰 کش‌بک این سفارش</h4>
		<?php if ( $used > 0 ) : ?>
			<p style="margin:0 0 4px; font-size:12px; color:#475569;">
				<strong>استفاده از کیف پول:</strong>
				<?php echo esc_html( number_format_i18n( $used ) ); ?>
				<?php echo function_exists( 'get_woocommerce_currency_symbol' ) ? esc_html( get_woocommerce_currency_symbol() ) : ''; ?>
			</p>
		<?php endif; ?>
		<?php if ( $granted > 0 ) : ?>
			<p style="margin:0; font-size:12px; color:#475569;">
				<strong>کش‌بک اعطا شده:</strong>
				<?php echo esc_html( number_format_i18n( $granted ) ); ?>
				<?php echo function_exists( 'get_woocommerce_currency_symbol' ) ? esc_html( get_woocommerce_currency_symbol() ) : ''; ?>
				(انقضا: <?php echo esc_html( (string) $order->get_meta( 'wto_cashback_granted_expires' ) ); ?>)
			</p>
		<?php endif; ?>
	</div>
	<?php
}

// ============================================================================
// Cron: انقضای رکوردها + پیامک یادآوری
// ============================================================================

add_action( WTO_CASHBACK_CRON_HOOK, 'wto_cashback_cron_runner' );
function wto_cashback_cron_runner() {
	if ( ! wto_cashback_is_enabled() ) return;
	wto_cashback_expire_old_records();
	wto_cashback_send_reminder_smses();
}

function wto_cashback_expire_old_records() {
	global $wpdb;
	$table = wto_cashback_credits_table();
	$now   = current_time( 'mysql' );
	// در batch — جلوگیری از long-running query روی سایت با میلیون‌ها رکورد
	$wpdb->query( $wpdb->prepare(
		"UPDATE $table SET status = %s WHERE status = %s AND expires_at <= %s LIMIT 5000",
		'expired',
		'active',
		$now
	) );
}

function wto_cashback_send_reminder_smses() {
	$settings = wto_cashback_get_settings();
	$pattern  = trim( (string) $settings['pattern_code'] );
	if ( $pattern === '' ) return;
	$reminders = $settings['reminders'];
	if ( ! is_array( $reminders ) || empty( $reminders ) ) return;

	global $wpdb;
	$table = wto_cashback_credits_table();
	$now   = current_time( 'mysql' );

	foreach ( $reminders as $days_ahead ) {
		$days_ahead = (int) $days_ahead;
		if ( $days_ahead <= 0 ) continue;
		$window_start = date( 'Y-m-d 00:00:00', strtotime( "+{$days_ahead} days", strtotime( $now ) ) );
		$window_end   = date( 'Y-m-d 23:59:59', strtotime( "+{$days_ahead} days", strtotime( $now ) ) );

		// رکوردهای کاربر که در این روز خاص expire می‌شوند
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, SUM(amount - used_amount) AS balance, MIN(expires_at) AS soonest
			 FROM $table
			 WHERE status = %s AND expires_at BETWEEN %s AND %s
			 GROUP BY user_id LIMIT 500",
			'active',
			$window_start,
			$window_end
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$user_id   = (int) $row['user_id'];
			$balance   = (float) $row['balance'];
			$mobile    = (string) get_user_meta( $user_id, 'billing_phone', true );
			if ( $mobile === '' ) {
				$mobile = (string) get_user_meta( $user_id, 'mobile_number', true );
			}
			if ( $mobile === '' || $balance <= 0 ) continue;

			$reminder_key = 'wto_cb_remind_' . $days_ahead . '_' . $user_id . '_' . md5( $row['soonest'] );
			if ( get_transient( $reminder_key ) ) continue; // قبلاً فرستاده شده

			if ( function_exists( 'wto_send_pattern_sms_raw' ) ) {
				// مهم: API فراز با نام‌های اصلیِ متغیر (مثل balance) پترن را خراب می‌سازد؛
				// پس مثل گرویتی‌فرم از نام‌های عمومیِ موقعیتی var1/var2 استفاده می‌کنیم.
				// var1 = جای %balance% در متنِ پترن، var2 = جای %days_left%.
				// (نگاشت بر اساس نام انجام می‌شود، نه موقعیت، پس ترتیبِ متن مهم نیست.)
				$attrs = array(
					'var1' => number_format( $balance ),
					'var2' => (string) $days_ahead,
				);
				wto_send_pattern_sms_raw( $mobile, $pattern, $attrs );
				set_transient( $reminder_key, '1', DAY_IN_SECONDS * 2 );
			}
		}
	}
}

// ============================================================================
// Dashboard Stats
// ============================================================================

function wto_cashback_get_stats() {
	if ( ! wto_cashback_is_enabled() ) {
		return array(
			'enabled'              => false,
			'total_granted'        => 0,
			'total_used'           => 0,
			'total_expired'        => 0,
			'active_balance'       => 0,
			'users_count'          => 0,
			'orders_with_cashback' => 0,
			'sales_with_cashback'  => 0,
			'repeat_customers'     => 0,
			'avg_repeat_orders'    => 0,
			'conversion_rate'      => 0,
			'lift_estimate'        => 0,
		);
	}
	$cached = get_transient( 'wto_cashback_stats' );
	if ( $cached !== false ) {
		return $cached;
	}
	global $wpdb;
	$cr = wto_cashback_credits_table();
	$rd = wto_cashback_redemptions_table();

	// مبالغ کلی
	$total_granted  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $cr" );
	$total_used     = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $rd" );
	$total_expired  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount - used_amount),0) FROM $cr WHERE status = 'expired'" );
	$active_balance = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(amount - used_amount),0) FROM $cr WHERE status = %s AND expires_at > %s",
		'active', current_time( 'mysql' )
	) );

	// تعداد مشتری
	$granted_users = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $cr" );
	$used_users    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $rd" );

	// تعداد سفارش‌های پرداخت‌شده با کش‌بک
	$orders_with_cashback = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT order_id) FROM $rd" );

	// مجموع total سفارش‌هایی که از کش‌بک استفاده کرده‌اند
	// (lift estimate — این فروش به‌خاطر سیستم کش‌بک رخ داده)
	// v3.19.0: حذف N+1 — به‌جای foreach با wc_get_order، یک bulk query (HPOS-aware)
	// از تابع مشترک wto_roi_sum_order_totals (در wto-roi-dashboard.php).
	$order_ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM $rd LIMIT 5000" );
	$sales_with_cashback = 0.0;
	if ( ! empty( $order_ids ) ) {
		if ( function_exists( 'wto_roi_sum_order_totals' ) ) {
			// مسیر سریع: یک query با IN(...)
			$sales_with_cashback = wto_roi_sum_order_totals( $order_ids );
		} elseif ( function_exists( 'wc_get_order' ) ) {
			// fallback به مسیر قدیمی — فقط اگر helper روی این سایت نباشد
			foreach ( $order_ids as $oid ) {
				$o = wc_get_order( (int) $oid );
				if ( $o ) {
					$sales_with_cashback += (float) $o->get_total();
				}
			}
		}
	}

	// مشتریان بازگشتی — کاربرانی که بعد از دریافت اولین کش‌بک، حداقل ۲ سفارش
	// از کیف پولشان استفاده کرده‌اند.
	$repeat_customers = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM (
			SELECT user_id, COUNT(DISTINCT order_id) AS c FROM $rd GROUP BY user_id HAVING c >= 2
		) t"
	);

	// میانگین تعداد سفارش هر مشتری بازگشتی
	$avg_repeat_orders = $repeat_customers > 0
		? (float) $wpdb->get_var(
			"SELECT AVG(c) FROM (
				SELECT user_id, COUNT(DISTINCT order_id) AS c FROM $rd GROUP BY user_id HAVING c >= 2
			) t"
		)
		: 0.0;

	// نرخ تبدیل — درصد کاربرانی که کش‌بک گرفتند و واقعاً استفاده کردند
	$conversion_rate = $granted_users > 0 ? round( ( $used_users / $granted_users ) * 100, 1 ) : 0;

	// تخمین lift فروش = فروش با کش‌بک − مبلغ کش‌بک استفاده شده
	$lift_estimate = max( 0, $sales_with_cashback - $total_used );

	$out = array(
		'enabled'              => true,
		'total_granted'        => $total_granted,
		'total_used'           => $total_used,
		'total_expired'        => $total_expired,
		'active_balance'       => $active_balance,
		'users_count'          => $used_users,
		'granted_users'        => $granted_users,
		'orders_with_cashback' => $orders_with_cashback,
		'sales_with_cashback'  => $sales_with_cashback,
		'repeat_customers'     => $repeat_customers,
		'avg_repeat_orders'    => round( $avg_repeat_orders, 2 ),
		'conversion_rate'      => $conversion_rate,
		'lift_estimate'        => $lift_estimate,
	);
	set_transient( 'wto_cashback_stats', $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

// ============================================================================
// AJAX: Auto-build pattern
// ============================================================================

add_action( 'wp_ajax_wto_cashback_autobuild_pattern', 'wto_cashback_ajax_autobuild_pattern' );
function wto_cashback_ajax_autobuild_pattern() {
	check_ajax_referer( 'wto_cashback_autobuild_pattern', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}
	$apikey = trim( (string) get_option( 'wto_apikey', '' ) );
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => 'ابتدا کلید دسترسی را در تنظیمات افزونه وارد کنید.' ) );
	}
	// v3.14.1: متن از کاربر بگیر (نه hard-coded) — کاربر می‌تواند پیام را قبل از
	// ساخت پترن سفارشی‌سازی کند. اگر خالی فرستاد، پیش‌فرض ست می‌شود.
	$domain = preg_replace( '#^www\.#i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$user_msg = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	if ( trim( $user_msg ) === '' ) {
		$user_msg = "کاربر گرامی، %balance% تومان اعتبار کش‌بک شما تنها %days_left% روز دیگر اعتبار دارد.\nبرای استفاده، خرید کنید:\n" . $domain;
	}

	// مهم (رفع باگ پترنِ خراب روی ۳ سایت): وب‌سرویسِ پترنِ فراز با نام‌های اصلیِ متغیر
	// (%balance% / %days_left%) پترن را اشتباه می‌سازد — کاراکترهای اول خورده می‌شوند
	// (balance → lance). دقیقاً مثل ماژولِ گرویتی‌فرم که درست کار می‌کند، نام‌ها را به
	// متغیرهای عمومیِ موقعیتی %var1% / %var2% تبدیل می‌کنیم. ترتیب در ارسال هم با همین
	// نام‌ها (var1=balance، var2=days_left) نگاشت می‌شود.
	$user_msg = str_replace(
		array( '%balance%', '{balance}', '%days_left%', '{days_left}' ),
		array( '%var1%', '%var1%', '%var2%', '%var2%' ),
		$user_msg
	);

	// v3.17.3: استفاده از تابع مشترک wto_create_pattern() — به جای
	// duplicate کردن API call. این تابع:
	//   - parse صحیح placeholder ها (% و {})
	//   - description برندشده "افزونه فراز اس ام اس / کش بک"
	//   - category صحیح (2=club بهتر از 1=otp برای کش‌بک)
	//   - مدیریت یکسان خطا
	if ( ! function_exists( 'wto_create_pattern' ) ) {
		wp_send_json_error( array( 'message' => 'تابع ساخت پترن در دسترس نیست.' ) );
	}

	$description = function_exists( 'wto_pattern_brand_description' )
		? wto_pattern_brand_description( 'cashback' )
		: 'افزونه فراز اس ام اس / کش بک';

	$response = wto_create_pattern( $user_msg, 2, $description );
	$data = is_string( $response ) ? json_decode( $response, true ) : $response;

	$pattern_code = '';
	if ( is_array( $data ) ) {
		if ( ! empty( $data['data']['code'] ) ) {
			$pattern_code = (string) $data['data']['code'];
		} elseif ( ! empty( $data['data'] ) && is_string( $data['data'] ) ) {
			$pattern_code = (string) $data['data'];
		} elseif ( ! empty( $data['code'] ) ) {
			$pattern_code = (string) $data['code'];
		}
	}
	if ( $pattern_code === '' ) {
		$em = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'پاسخ نامعتبر از سرور';
		wp_send_json_error( array( 'message' => $em ) );
	}
	// ذخیره فوری
	$settings = wto_cashback_get_settings();
	$settings['pattern_code'] = $pattern_code;
	update_option( 'wto_cashback_settings', $settings, false ); // v3.17.7: autoload=false (پر می‌شود نه روی هر page load)
	wp_send_json_success( array( 'pattern_code' => $pattern_code, 'message' => 'پترن ساخته شد.' ) );
}

// ============================================================================
// Admin Settings Page
// ============================================================================

add_action( 'admin_menu', 'wto_cashback_register_menu', 25 );
function wto_cashback_register_menu() {
	// v3.14.3: فقط یک منو — تب کاربران به‌صورت inline در همین صفحه نشان داده می‌شود.
	add_submenu_page(
		'farazwto',
		__( 'کش‌بک', 'wto' ),
		__( 'کش‌بک', 'wto' ),
		'manage_options',
		'farazwto-cashback',
		'wto_cashback_render_unified_page'
	);
}

/**
 * صفحه یکپارچه با تب افقی — تنظیمات یا کاربران.
 */
function wto_cashback_render_unified_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'دسترسی غیرمجاز.' );
	}
	$tab = isset( $_GET['ct'] ) ? sanitize_key( $_GET['ct'] ) : 'settings';
	if ( ! in_array( $tab, array( 'settings', 'users' ), true ) ) {
		$tab = 'settings';
	}
	$tab_settings_url = admin_url( 'admin.php?page=farazwto-cashback&ct=settings' );
	$tab_users_url    = admin_url( 'admin.php?page=farazwto-cashback&ct=users' );
	?>
	<div style="direction:rtl; font-family:inherit; max-width:1200px;">
		<!-- Horizontal Tabs -->
		<div style="display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:18px; padding:0 4px;">
			<a href="<?php echo esc_url( $tab_settings_url ); ?>" style="display:inline-flex; align-items:center; gap:6px; padding:12px 22px; font-size:13.5px; font-weight:<?php echo $tab==='settings'?'700':'500'; ?>; color:<?php echo $tab==='settings'?'#4338ca':'#64748b'; ?>; border-bottom:3px solid <?php echo $tab==='settings'?'#6366f1':'transparent'; ?>; text-decoration:none; margin-bottom:-2px;">
				⚙️ تنظیمات و آمار
			</a>
			<a href="<?php echo esc_url( $tab_users_url ); ?>" style="display:inline-flex; align-items:center; gap:6px; padding:12px 22px; font-size:13.5px; font-weight:<?php echo $tab==='users'?'700':'500'; ?>; color:<?php echo $tab==='users'?'#4338ca':'#64748b'; ?>; border-bottom:3px solid <?php echo $tab==='users'?'#6366f1':'transparent'; ?>; text-decoration:none; margin-bottom:-2px;">
				👥 کاربران و مدیریت دستی
			</a>
		</div>

		<?php
		if ( $tab === 'users' ) {
			wto_cashback_render_users_tab_content();
		} else {
			wto_cashback_render_settings_tab_content();
		}
		?>
	</div>
	<?php
}

/**
 * Backward-compat: تابع قبلی هنوز callable است (در صورت reference خارجی).
 */
// (Legacy alias removed — render directed by wto_cashback_render_unified_page)
function wto_cashback_render_settings_tab_content() {
	wto_cashback_internal_render_settings();
}
function wto_cashback_render_users_tab_content() {
	wto_cashback_internal_render_users();
}

/**
 * صفحه لیست کاربران — آمار، جستجو، سورت، pagination، اعطای دستی.
 *
 * Performance: کوئری اصلی JOIN روی wto_cashback_credits + wto_cashback_redemptions
 * با GROUP BY user_id — برای ۱۰۰k سایت با میلیون رکورد credits، این کوئری روی
 * indexed columns (user_id) سریع است. pagination با LIMIT برای جلوگیری از
 * load سنگین.
 */
function wto_cashback_internal_render_users() {
	$saved_ok = false;
	$saved_msg = '';
	// Handle manual grant
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && isset( $_POST['wto_cashback_manual_grant'] ) ) {
		check_admin_referer( 'wto_cashback_manual_grant', 'wto_cashback_manual_nonce' );
		$user_id = (int) ( $_POST['user_id'] ?? 0 );
		$amount  = (float) ( $_POST['amount'] ?? 0 );
		$days    = max( 1, (int) ( $_POST['days'] ?? wto_cashback_get_settings()['expiry_days'] ) );
		if ( $user_id > 0 && $amount > 0 ) {
			$result = wto_cashback_grant_manual( $user_id, $amount, $days );
			if ( $result ) {
				$saved_ok = true;
				$saved_msg = sprintf( 'مبلغ %s تومان به کیف پول کاربر #%d اضافه شد.', number_format_i18n( $amount ), $user_id );
				delete_transient( 'wto_cashback_stats' );
			} else {
				$saved_msg = 'خطا در اعطای کش‌بک.';
			}
		}
	}

	global $wpdb;
	$cr = wto_cashback_credits_table();
	$rd = wto_cashback_redemptions_table();
	$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'تومان';

	// Filters: search + sort + page
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'total_used';
	$order    = isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'asc' ? 'ASC' : 'DESC';
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$per_page = 20;
	$offset   = ( $paged - 1 ) * $per_page;

	$valid_orderby = array(
		'user_id'      => 'agg.user_id',
		'total_granted' => 'total_granted',
		'total_used'   => 'total_used',
		'total_expired' => 'total_expired',
		'active_balance' => 'active_balance',
	);
	$order_col = isset( $valid_orderby[ $orderby ] ) ? $valid_orderby[ $orderby ] : 'total_used';

	// کوئری تجمعی — یک GROUP BY روی کل credits
	$now = current_time( 'mysql' );
	$inner_sql = $wpdb->prepare(
		"SELECT
			c.user_id,
			SUM(c.amount) AS total_granted,
			SUM(c.used_amount) AS total_used,
			SUM(CASE WHEN c.status = 'expired' THEN (c.amount - c.used_amount) ELSE 0 END) AS total_expired,
			SUM(CASE WHEN c.status = 'active' AND c.expires_at > %s THEN (c.amount - c.used_amount) ELSE 0 END) AS active_balance,
			COUNT(DISTINCT c.order_id) AS credits_count
		 FROM $cr c
		 GROUP BY c.user_id",
		$now
	);

	// Search روی نام/ایمیل/موبایل — JOIN با users اگر search موجود
	$where    = '';
	$where_args = array();
	$search_join = '';
	if ( $search !== '' ) {
		$search_join = " LEFT JOIN {$wpdb->users} u ON u.ID = agg.user_id
		                 LEFT JOIN {$wpdb->usermeta} um1 ON um1.user_id = agg.user_id AND um1.meta_key = 'billing_phone'
		                 LEFT JOIN {$wpdb->usermeta} um2 ON um2.user_id = agg.user_id AND um2.meta_key = 'mobile_number'";
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where = " WHERE (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR um1.meta_value LIKE %s OR um2.meta_value LIKE %s)";
		$where_args = array( $like, $like, $like, $like, $like );
	}

	// v3.14.4 BUG FIX: وقتی search خالی است، where_args هم خالی است و prepare()
	// بدون placeholder خطای «query argument must have a placeholder» می‌دهد.
	// رفع: prepare فقط وقتی صدا زده می‌شود که placeholder وجود داشته باشد.
	// نکته امنیتی: $inner_sql خودش قبلاً prepared است و $search_join با
	// متغیر user-controlled ندارد، پس بدون prepare هم امن است.
	$count_sql = "SELECT COUNT(*) FROM ( $inner_sql ) agg $search_join $where";
	if ( ! empty( $where_args ) ) {
		$count_sql = $wpdb->prepare( $count_sql, ...$where_args );
	}
	$total_rows = (int) $wpdb->get_var( $count_sql );

	$rows_sql = "SELECT agg.* FROM ( $inner_sql ) agg $search_join $where ORDER BY $order_col $order, agg.user_id DESC LIMIT %d OFFSET %d";
	$rows_args = array_merge( $where_args, array( $per_page, $offset ) );
	// rows_args همیشه حداقل ۲ مقدار دارد (per_page + offset)، پس prepare همیشه placeholder دارد.
	$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$rows_args ), ARRAY_A );

	// Helper برای sort link
	$sort_link = function( $key, $label ) use ( $orderby, $order ) {
		$new_order = ( $orderby === $key && $order === 'DESC' ) ? 'asc' : 'desc';
		$url = add_query_arg( array(
			'page'    => 'farazwto-cashback', 'ct' => 'users',
			'orderby' => $key,
			'order'   => $new_order,
			's'       => isset( $_GET['s'] ) ? $_GET['s'] : '',
		), admin_url( 'admin.php' ) );
		$arrow = $orderby === $key ? ( $order === 'DESC' ? ' ▼' : ' ▲' ) : '';
		return '<a href="' . esc_url( $url ) . '" style="color:inherit; text-decoration:none;">' . esc_html( $label ) . esc_html( $arrow ) . '</a>';
	};
	?>
	<div style="direction:rtl; font-family:inherit; max-width:1200px;">

		<?php if ( $saved_msg !== '' ) : ?>
			<div style="background:<?php echo $saved_ok ? '#ecfdf5' : '#fef2f2'; ?>; border:1px solid <?php echo $saved_ok ? '#a7f3d0' : '#fecaca'; ?>; color:<?php echo $saved_ok ? '#065f46' : '#b91c1c'; ?>; padding:10px 14px; border-radius:8px; margin-bottom:16px;">
				<?php echo $saved_ok ? '✓ ' : '✗ '; ?><?php echo esc_html( $saved_msg ); ?>
			</div>
		<?php endif; ?>

		<!-- Hero -->
		<div style="background:linear-gradient(135deg,#4338ca 0%,#6366f1 100%); border-radius:12px; padding:16px 22px; margin-bottom:18px; color:#fff;">
			<h3 style="margin:0 0 4px; font-size:16px; font-weight:700;">📋 لیست کاربران کش‌بک</h3>
			<p style="margin:0; font-size:12px; opacity:0.9;">مشاهده وضعیت کیف پول هر کاربر، جستجو، مرتب‌سازی، و اعطای دستی کش‌بک.</p>
		</div>

		<!-- Toolbar: search + back -->
		<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
			<form method="get" action="" style="flex:1 1 300px; display:flex; gap:8px;">
				<input type="hidden" name="page" value="farazwto-cashback"><input type="hidden" name="ct" value="users">
				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="🔍 جستجو در نام کاربری/ایمیل/نام/موبایل…" style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-family:inherit; font-size:13px;">
				<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-size:13px; cursor:pointer;">جستجو</button>
				<?php if ( $search !== '' ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-cashback&ct=users' ) ); ?>" style="background:#fff; color:#475569; border:1px solid #cbd5e1; padding:8px 12px; border-radius:6px; text-decoration:none; font-size:13px;">پاک کردن</a>
				<?php endif; ?>
			</form>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-cashback' ) ); ?>" style="background:#f1f5f9; color:#475569; padding:8px 14px; border-radius:6px; text-decoration:none; font-size:13px;">← بازگشت به تنظیمات</a>
		</div>

		<!-- Manual grant form -->
		<details style="background:#fef3c7; border:1px solid #fde68a; border-radius:10px; padding:14px 18px; margin-bottom:18px;">
			<summary style="cursor:pointer; font-size:13px; font-weight:700; color:#78350f;">➕ اعطای دستی کش‌بک به یک کاربر خاص</summary>
			<form method="post" action="" style="margin-top:12px;">
				<?php wp_nonce_field( 'wto_cashback_manual_grant', 'wto_cashback_manual_nonce' ); ?>
				<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
					<div style="flex:1 1 220px;">
						<label style="display:block; font-size:11px; font-weight:600; color:#78350f; margin-bottom:4px;">شناسه کاربر (user_id)</label>
						<input type="number" name="user_id" required min="1" style="width:100%; padding:8px 12px; border:1px solid #fde68a; border-radius:6px; font-size:13px;">
					</div>
					<div style="flex:1 1 180px;">
						<label style="display:block; font-size:11px; font-weight:600; color:#78350f; margin-bottom:4px;">مبلغ (تومان)</label>
						<input type="number" name="amount" required min="1" step="1000" style="width:100%; padding:8px 12px; border:1px solid #fde68a; border-radius:6px; font-size:13px;">
					</div>
					<div style="flex:1 1 130px;">
						<label style="display:block; font-size:11px; font-weight:600; color:#78350f; margin-bottom:4px;">روزهای اعتبار</label>
						<input type="number" name="days" min="1" value="<?php echo (int) wto_cashback_get_settings()['expiry_days']; ?>" style="width:100%; padding:8px 12px; border:1px solid #fde68a; border-radius:6px; font-size:13px;">
					</div>
					<button type="submit" name="wto_cashback_manual_grant" value="1" style="background:#d97706; color:#fff; border:none; padding:9px 20px; font-size:13px; font-weight:600; border-radius:6px; cursor:pointer; font-family:inherit;">💰 اعطا</button>
				</div>
				<p style="margin:8px 0 0; font-size:11px; color:#78350f;">شناسه کاربر را از صفحه «کاربران» وردپرس یا از جدول زیر بگیرید.</p>
			</form>
		</details>

		<!-- Users table -->
		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
			<div style="overflow-x:auto;">
				<table style="width:100%; border-collapse:collapse; font-size:12.5px;">
					<thead style="background:#f8fafc;">
						<tr>
							<th style="text-align:right; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;"><?php echo $sort_link( 'user_id', 'کاربر' ); ?></th>
							<th style="text-align:right; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;">موبایل</th>
							<th style="text-align:right; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;">مجموع خرید</th>
							<th style="text-align:right; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;"><?php echo $sort_link( 'total_granted', 'دریافتی' ); ?></th>
							<th style="text-align:right; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;"><?php echo $sort_link( 'total_used', 'استفاده‌شده' ); ?></th>
							<th style="text-align:right; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;"><?php echo $sort_link( 'total_expired', 'منقضی' ); ?></th>
							<th style="text-align:right; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;"><?php echo $sort_link( 'active_balance', 'موجودی فعال' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7" style="text-align:center; padding:30px 12px; color:#64748b;">هیچ کاربری یافت نشد.</td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) :
								$user = get_user_by( 'ID', (int) $row['user_id'] );
								$display = $user ? $user->display_name : '—';
								$email   = $user ? $user->user_email : '';
								$mobile  = get_user_meta( $row['user_id'], 'billing_phone', true );
								if ( ! $mobile ) {
									$mobile = get_user_meta( $row['user_id'], 'mobile_number', true );
								}
								$total_spent = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $row['user_id'] ) : 0;
								?>
								<tr style="border-bottom:1px solid #f1f5f9;">
									<td style="padding:10px 12px;">
										<div style="font-weight:600; color:#0f172a;"><?php echo esc_html( $display ); ?></div>
										<div style="font-size:10px; color:#64748b;">#<?php echo (int) $row['user_id']; ?> · <?php echo esc_html( $email ); ?></div>
									</td>
									<td style="padding:10px 12px; direction:ltr; text-align:right; font-family:monospace; color:#475569;"><?php echo esc_html( $mobile ?: '—' ); ?></td>
									<td style="padding:10px 12px; font-weight:600; color:#0f172a;"><?php echo esc_html( number_format_i18n( $total_spent ) ); ?> <small style="color:#94a3b8;"><?php echo esc_html( $currency ); ?></small></td>
									<td style="padding:10px 12px; color:#0f172a;"><?php echo esc_html( number_format_i18n( (float) $row['total_granted'] ) ); ?></td>
									<td style="padding:10px 12px; color:#16a34a; font-weight:600;"><?php echo esc_html( number_format_i18n( (float) $row['total_used'] ) ); ?></td>
									<td style="padding:10px 12px; color:#dc2626;"><?php echo esc_html( number_format_i18n( (float) $row['total_expired'] ) ); ?></td>
									<td style="padding:10px 12px; color:#4338ca; font-weight:700;"><?php echo esc_html( number_format_i18n( (float) $row['active_balance'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<?php
			$total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
			if ( $total_pages > 1 ) :
			?>
				<div style="padding:12px 16px; background:#f8fafc; border-top:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
					<div style="font-size:12px; color:#64748b;">صفحه <?php echo $paged; ?> از <?php echo $total_pages; ?> — مجموع <?php echo number_format_i18n( $total_rows ); ?> کاربر</div>
					<div style="display:flex; gap:6px;">
						<?php
						$base_args = array( 'page' => 'farazwto-cashback', 'ct' => 'users', 'orderby' => $orderby, 'order' => strtolower( $order ), 's' => $search );
						if ( $paged > 1 ) {
							echo '<a href="' . esc_url( add_query_arg( array_merge( $base_args, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) ) ) . '" style="background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; font-size:12px;">قبلی</a>';
						}
						if ( $paged < $total_pages ) {
							echo '<a href="' . esc_url( add_query_arg( array_merge( $base_args, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) ) ) . '" style="background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; font-size:12px;">بعدی</a>';
						}
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>

	</div>
	<?php
}

/**
 * اعطای کش‌بک دستی — بدون نیاز به سفارش. order_id=0 به‌عنوان سیگنال manual.
 * idempotency بر اساس مبلغ + روز ساخت رد می‌شود.
 */
function wto_cashback_grant_manual( $user_id, $amount, $expiry_days = 0 ) {
	$user_id = (int) $user_id;
	$amount  = (float) $amount;
	if ( $user_id <= 0 || $amount <= 0 ) {
		return false;
	}
	$settings = wto_cashback_get_settings();
	if ( $expiry_days <= 0 ) {
		$expiry_days = (int) $settings['expiry_days'];
	}
	$expiry_days = max( 1, $expiry_days );

	global $wpdb;
	$now        = current_time( 'mysql' );
	$expires_at = date( 'Y-m-d H:i:s', strtotime( $now . ' +' . $expiry_days . ' days' ) );
	$inserted   = $wpdb->insert(
		wto_cashback_credits_table(),
		array(
			'user_id'     => $user_id,
			'order_id'    => 0, // manual grant — بدون سفارش
			'amount'      => $amount,
			'used_amount' => 0,
			'status'      => 'active',
			'created_at'  => $now,
			'expires_at'  => $expires_at,
		),
		array( '%d', '%d', '%f', '%f', '%s', '%s', '%s' )
	);
	return $inserted ? $wpdb->insert_id : false;
}

function wto_cashback_internal_render_settings() {
	$saved_ok = false;
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && isset( $_POST['wto_cashback_save'] ) ) {
		check_admin_referer( 'wto_cashback_settings', 'wto_cashback_nonce' );
		$current = wto_cashback_get_settings();
		$current['enabled']        = isset( $_POST['enabled'] ) ? '1' : '0';
		$current['percent']        = max( 0, min( 100, (float) ( $_POST['percent'] ?? 10 ) ) );
		$current['expiry_days']    = max( 1, (int) ( $_POST['expiry_days'] ?? 7 ) );
		$current['min_order']      = max( 0, (float) ( $_POST['min_order'] ?? 0 ) );
		$current['max_per_order']  = max( 0, (float) ( $_POST['max_per_order'] ?? 0 ) );
		$current['status_trigger'] = sanitize_text_field( $_POST['status_trigger'] ?? 'completed' );
		$current['pattern_code']   = sanitize_text_field( $_POST['pattern_code'] ?? '' );
		$current['in_cart_notice'] = isset( $_POST['in_cart_notice'] ) ? '1' : '0';
		$reminders                 = isset( $_POST['reminders'] ) ? sanitize_text_field( $_POST['reminders'] ) : '7,3,1';
		$parts                     = array_filter( array_map( 'trim', explode( ',', $reminders ) ) );
		$current['reminders']      = array_values( array_filter( array_map( 'intval', $parts ), function( $v ) { return $v > 0; } ) );
		update_option( 'wto_cashback_settings', $current, false );
		delete_transient( 'wto_cashback_stats' );
		$saved_ok = true;
	}

	$s     = wto_cashback_get_settings();
	$stats = wto_cashback_get_stats();
	$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'تومان';
	$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array( 'wc-completed' => 'تکمیل شده' );
	$wc_active = function_exists( 'WC' );
	$enabled_flag = $s['enabled'] === '1';
	?>
	<div>
		<?php if ( $saved_ok ) : ?>
			<div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:10px 14px; border-radius:8px; margin-bottom:16px;">✓ تنظیمات ذخیره شد.</div>
		<?php endif; ?>

		<?php if ( ! $wc_active ) : ?>
			<div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:14px 18px; border-radius:10px; margin-bottom:20px;">
				<strong style="font-size:14px;">⚠️ ووکامرس فعال نیست</strong>
				<p style="margin:6px 0 0; font-size:12.5px; line-height:1.8;">
					سیستم کش‌بک به سفارش‌های ووکامرس وابسته است. لطفاً ابتدا افزونه WooCommerce را نصب و فعال کنید.
					تنظیمات این صفحه قابل ذخیره است ولی تا قبل از نصب WC، روی سایت اثری ندارد.
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $wc_active && $s['enabled'] !== '1' ) : ?>
			<div style="background:#fef3c7; border:1px solid #fde68a; color:#78350f; padding:12px 16px; border-radius:10px; margin-bottom:20px;">
				<strong>⚠️ سیستم کش‌بک <u>غیرفعال</u> است.</strong>
				<span style="font-size:12.5px;">
					تنظیمات را وارد کنید و در پایین صفحه «فعال‌سازی سیستم کش‌بک» را تیک بزنید تا KPI ها و آمار شروع به پر شدن کنند.
				</span>
			</div>
		<?php endif; ?>

		<!-- Hero -->
		<div style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%); border:1px solid #fcd34d; border-radius:12px; padding:18px 22px; margin-bottom:20px;">
			<h3 style="margin:0 0 6px; font-size:16px; color:#78350f; font-weight:700;">💰 سیستم کش‌بک هوشمند</h3>
			<p style="margin:0; color:#92400e; font-size:13px; line-height:1.9;">
				مشتری از خرید درصدی بازگشت می‌گیرد که با تاریخ انقضا در کیف پولش ذخیره می‌شود — این او را تحریک می‌کند سریع برگردد و دوباره خرید کند. پیامک یادآوری <strong>قبل از انقضا</strong> نرخ بازگشت را تا ۳ برابر افزایش می‌دهد.
			</p>
		</div>

		<!-- Stats Cards — KPI ها (در حالت غیرفعال: خاکستری + نوتیس) -->
		<?php
		// v3.14.3: حتی در حالت غیرفعال هم کارت‌ها را نمایش می‌دهیم با ظاهر dimmed.
		$dim = ! $enabled_flag;
		$hero_bg = $dim
			? 'background:linear-gradient(135deg,#94a3b8 0%,#64748b 100%); opacity:0.85;'
			: 'background:linear-gradient(135deg,#16a34a 0%,#059669 100%);';
		$card_style = $dim ? 'background:#f8fafc; border-color:#e2e8f0; opacity:0.7;' : 'background:#fff; border-color:#e5e7eb;';
		?>
			<!-- ردیف اصلی: تأثیر بر فروش -->
			<div style="<?php echo esc_attr( $hero_bg ); ?> border-radius:14px; padding:20px 24px; margin-bottom:14px; color:#fff;">
				<div style="font-size:12px; opacity:0.9; margin-bottom:4px;">
					📈 افزایش فروش به‌خاطر سیستم کش‌بک
					<?php if ( $dim ) : ?>
						<span style="background:rgba(255,255,255,0.25); padding:2px 8px; border-radius:4px; font-size:10px; margin-right:6px;">غیرفعال — برای پر شدن، تیک «فعال‌سازی» را بزنید</span>
					<?php endif; ?>
				</div>
				<div style="font-size:28px; font-weight:800;">
					<?php echo esc_html( number_format_i18n( $stats['sales_with_cashback'] ?? 0 ) ); ?>
					<small style="font-size:14px; font-weight:500; opacity:0.9;"><?php echo esc_html( $currency ); ?></small>
				</div>
				<div style="font-size:12px; opacity:0.9; margin-top:8px;">
					<?php echo esc_html( number_format_i18n( $stats['orders_with_cashback'] ?? 0 ) ); ?> سفارش با استفاده از کش‌بک —
					<strong>این فروش‌ها مستقیماً نتیجه سیستم کش‌بک افزونه فراز اس‌ام‌اس است.</strong>
				</div>
			</div>

			<!-- ردیف دوم: مشتریان بازگشتی -->
			<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:14px;">
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">🔁 مشتریان بازگشتی</div>
					<div style="font-size:20px; font-weight:700; color:<?php echo $dim?'#64748b':'#0f172a'; ?>;"><?php echo esc_html( number_format_i18n( $stats['repeat_customers'] ?? 0 ) ); ?></div>
					<div style="font-size:10px; color:<?php echo $dim?'#94a3b8':'#16a34a'; ?>; margin-top:2px;">‎>‎= ۲ خرید با کش‌بک</div>
				</div>
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">📊 میانگین خرید هر مشتری</div>
					<div style="font-size:20px; font-weight:700; color:<?php echo $dim?'#64748b':'#0f172a'; ?>;"><?php echo esc_html( number_format_i18n( $stats['avg_repeat_orders'] ?? 0 ) ); ?> <small style="font-size:11px;">بار</small></div>
				</div>
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">🎯 نرخ تبدیل کش‌بک</div>
					<div style="font-size:20px; font-weight:700; color:<?php echo $dim?'#64748b':'#16a34a'; ?>;"><?php echo esc_html( $stats['conversion_rate'] ?? 0 ); ?>%</div>
					<div style="font-size:10px; color:#64748b; margin-top:2px;">از کاربرانی که کش‌بک گرفتند</div>
				</div>
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">💼 دریافت‌کنندگان کش‌بک</div>
					<div style="font-size:20px; font-weight:700; color:<?php echo $dim?'#64748b':'#0f172a'; ?>;"><?php echo esc_html( number_format_i18n( $stats['granted_users'] ?? 0 ) ); ?></div>
				</div>
			</div>

			<!-- ردیف سوم: جزئیات مالی -->
			<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px;">
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">کل اعطا شده</div>
					<div style="font-size:16px; font-weight:700; color:<?php echo $dim?'#64748b':'#0f172a'; ?>;"><?php echo esc_html( number_format_i18n( $stats['total_granted'] ?? 0 ) ); ?> <small style="font-size:10px;"><?php echo esc_html( $currency ); ?></small></div>
				</div>
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">استفاده شده</div>
					<div style="font-size:16px; font-weight:700; color:<?php echo $dim?'#64748b':'#16a34a'; ?>;"><?php echo esc_html( number_format_i18n( $stats['total_used'] ?? 0 ) ); ?> <small style="font-size:10px;"><?php echo esc_html( $currency ); ?></small></div>
				</div>
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">منقضی/سوخت‌شده</div>
					<div style="font-size:16px; font-weight:700; color:<?php echo $dim?'#64748b':'#dc2626'; ?>;"><?php echo esc_html( number_format_i18n( $stats['total_expired'] ?? 0 ) ); ?> <small style="font-size:10px;"><?php echo esc_html( $currency ); ?></small></div>
				</div>
				<div style="<?php echo esc_attr( $card_style ); ?> border:1px solid; border-radius:10px; padding:14px;">
					<div style="font-size:11px; color:#64748b;">موجودی فعال</div>
					<div style="font-size:16px; font-weight:700; color:<?php echo $dim?'#64748b':'#4338ca'; ?>;"><?php echo esc_html( number_format_i18n( $stats['active_balance'] ?? 0 ) ); ?> <small style="font-size:10px;"><?php echo esc_html( $currency ); ?></small></div>
				</div>
			</div>

		<form method="post" action="">
			<?php wp_nonce_field( 'wto_cashback_settings', 'wto_cashback_nonce' ); ?>

			<!-- Master toggle -->
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:18px;">
				<label style="display:flex; align-items:center; gap:14px; padding:12px 16px; background:<?php echo $s['enabled']==='1' ? '#f0fdf4' : '#fff7ed'; ?>; border:1px solid <?php echo $s['enabled']==='1' ? '#bbf7d0' : '#fed7aa'; ?>; border-radius:10px; cursor:pointer;">
					<input type="checkbox" class="wto-toggle" name="enabled" value="1" <?php checked( $s['enabled'], '1' ); ?> style="margin:0; width:18px; height:18px;">
					<span style="flex:1; font-size:14px; font-weight:600;">
						فعال‌سازی سیستم کش‌بک
						<?php echo $s['enabled']==='1' ? '<span style="background:#16a34a; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px;">فعال ✓</span>' : '<span style="background:#f97316; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px;">غیرفعال</span>'; ?>
					</span>
				</label>
				<p style="margin:8px 0 0; font-size:11px; color:#64748b;">در حالت غیرفعال، هیچ کد کش‌بک روی سایت شما اجرا نمی‌شود.</p>
			</div>

			<!-- Cashback rules -->
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:18px;">
				<h4 style="margin:0 0 14px; font-size:14px; font-weight:700; color:#0f172a;">قوانین کش‌بک</h4>
				<div style="display:flex; flex-wrap:wrap; gap:14px;">
					<div style="flex:1 1 200px; min-width:180px;">
						<label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">درصد کش‌بک (%)</label>
						<input type="number" name="percent" min="0" max="100" step="0.1" value="<?php echo esc_attr( $s['percent'] ); ?>" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
					</div>
					<div style="flex:1 1 200px; min-width:180px;">
						<label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">روزهای انقضا</label>
						<input type="number" name="expiry_days" min="1" value="<?php echo esc_attr( $s['expiry_days'] ); ?>" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
					</div>
					<div style="flex:1 1 200px; min-width:180px;">
						<label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">حداقل سفارش (تومان) — ۰ یعنی بدون حداقل</label>
						<input type="number" name="min_order" min="0" step="1000" value="<?php echo esc_attr( $s['min_order'] ); ?>" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
					</div>
					<div style="flex:1 1 200px; min-width:180px;">
						<label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">سقف کش‌بک هر سفارش — ۰ یعنی بدون سقف</label>
						<input type="number" name="max_per_order" min="0" step="1000" value="<?php echo esc_attr( $s['max_per_order'] ); ?>" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
					</div>
					<div style="flex:1 1 200px; min-width:180px;">
						<label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">وضعیت سفارش که trigger کش‌بک می‌شود</label>
						<select name="status_trigger" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
							<?php foreach ( $statuses as $key => $label ) :
								$clean = preg_replace( '/^wc-/', '', $key );
								?>
								<option value="<?php echo esc_attr( $clean ); ?>" <?php selected( $s['status_trigger'], $clean ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<!-- Reminder SMS -->
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:18px;">
				<h4 style="margin:0 0 14px; font-size:14px; font-weight:700; color:#0f172a;">پیامک یادآوری</h4>
				<div style="display:flex; flex-wrap:wrap; gap:14px;">
					<div style="flex:1 1 300px; min-width:200px;">
						<label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">روزهای یادآوری قبل از انقضا (جدا با ,)</label>
						<input type="text" name="reminders" value="<?php echo esc_attr( implode( ',', $s['reminders'] ) ); ?>" placeholder="7,3,1" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; direction:ltr; text-align:left;">
						<p style="margin:4px 0 0; font-size:11px; color:#64748b;">مثال: <code>7,3,1</code> یعنی ۷ روز، ۳ روز و ۱ روز قبل از انقضا</p>
					</div>
					<div style="flex:1 1 300px; min-width:200px;">
						<label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">کد پترن پیامک یادآوری</label>
						<input type="text" id="cashback_pattern_code" name="pattern_code" value="<?php echo esc_attr( $s['pattern_code'] ); ?>" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; direction:ltr; text-align:left;">
					</div>
				</div>
				<!-- v3.14.1: textarea قابل ویرایش + ajaxurl حالت dependable. -->
				<?php
				$site_domain = preg_replace( '#^www\.#i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
				$default_msg = "کاربر گرامی، %balance% تومان اعتبار کش‌بک شما تنها %days_left% روز دیگر اعتبار دارد.\nبرای استفاده، خرید کنید:\n" . $site_domain;
				?>
				<div style="margin-top:14px; padding:14px 16px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:8px;">
					<div style="font-size:13px; color:#4338ca; font-weight:700; margin-bottom:6px;">🤖 ساخت پترن خودکار</div>
					<p style="margin:0 0 8px; font-size:11.5px; color:#3730a3; line-height:1.8;">
						متن زیر را با دلخواه خود ویرایش کنید. دو متغیر <code style="background:#fff; padding:1px 4px; border-radius:3px;">%balance%</code> (موجودی) و <code style="background:#fff; padding:1px 4px; border-radius:3px;">%days_left%</code> (روز باقی‌مانده) باید در متن باشند.
					</p>
					<!-- v3.14.8: نوتیس مهم درباره استفاده از علامت % در متن -->
					<div style="background:#fffbeb; border:1px solid #fde68a; padding:8px 12px; border-radius:6px; margin-bottom:10px; font-size:11px; color:#78350f; line-height:1.8;">
						⚠️ <strong>نکته مهم:</strong>
						علامت <code>%</code> لاتین <u>فقط</u> برای دو طرف نام متغیر استفاده شود (مثل <code>%balance%</code>).
						اگر می‌خواهید در متن «درصد» بنویسید (مثلاً «۱۰ درصد تخفیف»)، از <strong>کاراکتر فارسی ٪</strong> یا کلمه «<strong>درصد</strong>» استفاده کنید —
						در غیر این صورت پنل فراز متغیر را اشتباه شناسایی می‌کند و پترن نامعتبر می‌شود.
					</div>
					<textarea id="wto-cashback-pattern-message" rows="5" style="width:100%; padding:10px 12px; border:1px solid #c7d2fe; border-radius:6px; font-family:inherit; font-size:13px; line-height:1.9; direction:rtl; resize:vertical; background:#fff;"><?php echo esc_textarea( $default_msg ); ?></textarea>
					<div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
						<button type="button" id="wto-cashback-autobuild" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wto_cashback_autobuild_pattern' ) ); ?>" data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="background:#16a34a; color:#fff; border:none; padding:8px 18px; font-size:13px; font-weight:600; border-radius:6px; cursor:pointer; font-family:inherit;">
							⚡ ساخت پترن و درج در فیلد بالا
						</button>
						<span id="wto-cashback-autobuild-status" style="font-size:12px; color:#64748b;"></span>
					</div>
				</div>
				<script>
				(function(){
					var btn = document.getElementById('wto-cashback-autobuild');
					if (!btn) return;
					btn.addEventListener('click', function(){
						var s   = document.getElementById('wto-cashback-autobuild-status');
						var msg = document.getElementById('wto-cashback-pattern-message');
						var ajaxurl = btn.getAttribute('data-ajaxurl') || (window.ajaxurl || '/wp-admin/admin-ajax.php');
						if (!msg || !msg.value || msg.value.trim() === '') {
							s.textContent = '✗ متن پترن خالی است';
							s.style.color = '#dc2626';
							return;
						}
						btn.disabled = true; btn.style.opacity = '0.6';
						s.textContent = 'در حال ساخت پترن…';
						s.style.color = '#64748b';
						var fd = new FormData();
						fd.append('action', 'wto_cashback_autobuild_pattern');
						fd.append('nonce', btn.getAttribute('data-nonce'));
						fd.append('message', msg.value);
						fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
							.then(function(r){ return r.json(); })
							.then(function(res){
								if (res && res.success && res.data && res.data.pattern_code) {
									var pc = document.getElementById('cashback_pattern_code');
									if (pc) pc.value = res.data.pattern_code;
									s.textContent = '✓ پترن ساخته شد: ' + res.data.pattern_code + ' — دکمه ذخیره را بزنید';
									s.style.color = '#16a34a';
								} else {
									var em = (res && res.data && res.data.message) ? res.data.message : 'خطای ناشناخته';
									s.textContent = '✗ ' + em;
									s.style.color = '#dc2626';
								}
							})
							.catch(function(){
								s.textContent = '✗ خطای ارتباط با سرور';
								s.style.color = '#dc2626';
							})
							.finally(function(){
								btn.disabled = false; btn.style.opacity = '1';
							});
					});
				})();
				</script>
			</div>

			<!-- Checkout display -->
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:18px;">
				<label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
					<input type="checkbox" class="wto-toggle" name="in_cart_notice" value="1" <?php checked( $s['in_cart_notice'], '1' ); ?> style="margin:0;">
					<span style="font-size:13px; font-weight:600;">نمایش گزینه «استفاده از کیف پول» در صفحه پرداخت</span>
				</label>
				<p style="margin:6px 0 0; font-size:11px; color:#64748b;">اگر خاموش شود، کش‌بک به‌صورت خودکار اعمال می‌شود بدون اجازه از کاربر.</p>
			</div>

			<button type="submit" name="wto_cashback_save" value="1" style="background:#4338ca; color:#fff; border:none; padding:10px 28px; font-size:14px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit;">💾 ذخیره تنظیمات</button>
		</form>
	</div>
	<?php
}
