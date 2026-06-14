<?php
/**
 * سبد خرید رها‌شده (Abandoned Cart Recovery) — Phase 4
 *
 * جریان کار:
 *   1. کاربر محصول به سبد اضافه می‌کند → ردیفی در DB با session_id ساخته می‌شود.
 *   2. در هر تغییر سبد (add / remove / qty / coupon)، ردیف به‌روز می‌شود.
 *   3. شماره موبایل از این منابع تشخیص داده می‌شود:
 *       - meta `billing_phone` کاربر لاگین
 *       - فیلد phone در فرم checkout (با JS که هنگام تایپ، AJAX می‌زند)
 *       - اگر OTP/Newsletter شناسایی شده باشد
 *   4. wp-cron هر ۳۰ دقیقه: سبدهای active که X ساعت قدیمی‌اند و شماره دارند →
 *      پیامک با الگو ارسال + status='sent'.
 *   5. روی `woocommerce_thankyou`: ردیف active/sent با session/user/mobile مطابق
 *      یافت می‌شود و status='recovered' + recovered_order_id ست می‌شود.
 *   6. سبدهای قدیمی‌تر از expiry → 'expired'.
 *
 * صفحه ادمین:
 *   - داشبورد آماری: تعداد رهاشده، ارسال‌شده، بازیافته، نرخ بازیافت، ارزش بازیافته
 *   - لیست سبدها با فیلتر وضعیت/تاریخ/جستجو
 *   - تنظیمات: فعال‌سازی، تأخیر ارسال، الگو، تاریخ انقضاء
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// v3.20.6 ESCAPE HATCH
if ( defined( 'WTO_DISABLE_ABANDONED' ) && WTO_DISABLE_ABANDONED ) {
	return;
}
if ( defined( 'WTO_DISABLE_CHECKOUT_HOOKS' ) && WTO_DISABLE_CHECKOUT_HOOKS ) {
	return;
}

// ============================================================================
// Schema
// ============================================================================

const WTO_ABANDONED_DB_VERSION        = '1.1.0'; // v3.18.0: composite index status_recovered_at
const WTO_ABANDONED_DB_VERSION_OPTION = 'wto_abandoned_db_version';
const WTO_ABANDONED_CRON_HOOK         = 'wto_abandoned_dispatch';

function wto_abandoned_table() {
	global $wpdb;
	return $wpdb->prefix . 'wto_abandoned_carts';
}

function wto_abandoned_maybe_setup_table() {
	if ( get_option( WTO_ABANDONED_DB_VERSION_OPTION ) === WTO_ABANDONED_DB_VERSION ) {
		return;
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	global $wpdb;
	$table           = wto_abandoned_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		session_id VARCHAR(64) NOT NULL,
		user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		mobile VARCHAR(20) NOT NULL DEFAULT '',
		email VARCHAR(190) NOT NULL DEFAULT '',
		first_name VARCHAR(120) NOT NULL DEFAULT '',
		cart_data LONGTEXT,
		total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
		items_count INT UNSIGNED NOT NULL DEFAULT 0,
		token VARCHAR(64) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		sent_at DATETIME NULL,
		recovered_order_id BIGINT(20) UNSIGNED NULL,
		recovered_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY session_id (session_id),
		KEY status (status),
		KEY mobile (mobile),
		KEY updated_at (updated_at),
		KEY token (token),
		KEY status_recovered_at (status, recovered_at),
		KEY status_updated_at (status, updated_at)
	) $charset_collate;";
	dbDelta( $sql );
	update_option( WTO_ABANDONED_DB_VERSION_OPTION, WTO_ABANDONED_DB_VERSION, false );
}
add_action( 'admin_init', 'wto_abandoned_maybe_setup_table' );

// ============================================================================
// Settings
// ============================================================================

function wto_abandoned_settings() {
	$defaults = array(
		'enabled'         => '1',
		'delay_hours'     => '1',     // ساعت پس از آخرین تغییر سبد تا ارسال
		'expiry_days'     => '7',     // پس از این مدت، سبد expire می‌شود
		'pattern_code'    => '',
		'batch_size'      => '50',    // سقف ارسال در هر cron run
		'capture_checkout'=> '1',     // JS برای ضبط موبایل در checkout
	);
	$saved = get_option( 'wto_abandoned_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

// ============================================================================
// Submenu (priority 988 — بین Notify Me و Reports)
// ============================================================================

add_action( 'admin_menu', 'wto_abandoned_register_submenu', 988 );
function wto_abandoned_register_submenu() {
	add_submenu_page(
		'farazwto',
		__( 'سبد خرید رها‌شده', 'wto' ),
		__( 'سبد خرید رها‌شده', 'wto' ),
		'manage_options',
		'farazwto-abandoned',
		'wto_render_abandoned_page'
	);
}

// ============================================================================
// WP-Cron schedule
// ============================================================================

add_filter( 'cron_schedules', 'wto_abandoned_cron_schedules' );
function wto_abandoned_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['wto_thirty_minutes'] ) ) {
		$schedules['wto_thirty_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'هر ۳۰ دقیقه (فراز اس‌ام‌اس)', 'wto' ),
		);
	}
	return $schedules;
}

add_action( 'init', 'wto_abandoned_register_cron' );
function wto_abandoned_register_cron() {
	if ( ! wp_next_scheduled( WTO_ABANDONED_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'wto_thirty_minutes', WTO_ABANDONED_CRON_HOOK );
	}
}

// تعداد فعال‌سازی‌ها / غیرفعال‌سازی پلاگین به ما اعتماد نیست — pre-clean در غیرفعال شدن
// v3.13.16: استفاده از constant مرجع به‌جای string هاردکدشده. این کار باعث می‌شود
// نام فولدر/فایل افزونه قابل تغییر باشد بدون شکستن register_deactivation_hook.
register_deactivation_hook( defined( 'WTO_PLUGIN_FILE' ) ? WTO_PLUGIN_FILE : __FILE__, 'wto_abandoned_clear_cron' );
function wto_abandoned_clear_cron() {
	$ts = wp_next_scheduled( WTO_ABANDONED_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, WTO_ABANDONED_CRON_HOOK );
	}
}

// ============================================================================
// Core: capture cart on every change
// ============================================================================

add_action( 'woocommerce_add_to_cart',        'wto_abandoned_track_cart', 10, 0 );
add_action( 'woocommerce_cart_item_removed',  'wto_abandoned_track_cart', 10, 0 );
add_action( 'woocommerce_cart_item_restored', 'wto_abandoned_track_cart', 10, 0 );
add_action( 'woocommerce_after_cart_item_quantity_update', 'wto_abandoned_track_cart', 10, 0 );
add_action( 'woocommerce_applied_coupon',     'wto_abandoned_track_cart', 10, 0 );
add_action( 'woocommerce_removed_coupon',     'wto_abandoned_track_cart', 10, 0 );

function wto_abandoned_track_cart() {
	// v3.13.13 PERF: Debounce در سطح request. شش hook ووکامرس این تابع را
	// در یک request به‌طور پشت سر هم صدا می‌زنند (مثلاً add_to_cart + applied_coupon).
	// با static flag، فقط یک بار در هر request اجرا می‌شود — DB writes را تا
	// ۶ برابر کاهش می‌دهد روی صفحاتی که چندین cart event رخ می‌دهد.
	static $done_this_request = false;
	if ( $done_this_request ) {
		return;
	}
	$done_this_request = true;

	$settings = wto_abandoned_settings();
	if ( $settings['enabled'] !== '1' ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
		return;
	}
	$session_id = wto_abandoned_get_session_id();
	if ( $session_id === '' ) {
		return;
	}
	if ( WC()->cart->is_empty() ) {
		// Cart cleared — drop our row.
		wto_abandoned_delete_by_session( $session_id );
		return;
	}

	$cart_items = array();
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$pid    = isset( $item['product_id'] )   ? (int) $item['product_id']   : 0;
		$vid    = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		$qty    = isset( $item['quantity'] )     ? (int) $item['quantity']     : 0;
		$line   = isset( $item['line_total'] )   ? (float) $item['line_total'] : 0;
		$cart_items[] = array(
			'product_id'   => $pid,
			'variation_id' => $vid,
			'quantity'     => $qty,
			'line_total'   => $line,
			'name'         => $pid ? get_the_title( $pid ) : '',
		);
	}
	$total = (float) WC()->cart->get_total( 'edit' );
	$count = (int) WC()->cart->get_cart_contents_count();

	$mobile = '';
	$email  = '';
	$first  = '';
	$uid    = get_current_user_id();
	if ( $uid > 0 ) {
		$mobile = (string) get_user_meta( $uid, 'billing_phone', true );
		$email  = (string) get_user_meta( $uid, 'billing_email', true );
		$first  = (string) get_user_meta( $uid, 'billing_first_name', true );
		if ( $email === '' ) {
			$user_obj = get_userdata( $uid );
			if ( $user_obj ) {
				$email = (string) $user_obj->user_email;
				if ( $first === '' ) {
					$first = (string) ( $user_obj->display_name ?: $user_obj->user_login );
				}
			}
		}
	}
	if ( $mobile !== '' && function_exists( 'wto_newsletter_normalize_mobile' ) ) {
		$mobile = wto_newsletter_normalize_mobile( $mobile );
	}

	wto_abandoned_upsert_row( $session_id, array(
		'user_id'     => $uid,
		'mobile'      => $mobile,
		'email'       => $email,
		'first_name'  => $first,
		'cart_data'   => wp_json_encode( $cart_items, JSON_UNESCAPED_UNICODE ),
		'total_value' => $total,
		'items_count' => $count,
	) );
}

/**
 * Get a stable session ID for tracking. Uses WC's customer session.
 *
 * @return string
 */
function wto_abandoned_get_session_id() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return '';
	}
	$id = WC()->session->get_customer_id();
	return is_string( $id ) ? $id : '';
}

/**
 * Insert or update an abandoned-cart row. Also generates a recovery token
 * the first time a row is created.
 *
 * @param string $session_id
 * @param array  $data
 */
function wto_abandoned_upsert_row( $session_id, $data ) {
	global $wpdb;
	$table = wto_abandoned_table();
	$now   = current_time( 'mysql' );
	$existing = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, status FROM $table WHERE session_id = %s LIMIT 1", $session_id ),
		ARRAY_A
	);
	if ( $existing ) {
		// Do NOT touch rows already marked recovered.
		if ( $existing['status'] === 'recovered' ) {
			return;
		}
		// Reset 'sent' or 'expired' back to active when the user comes back and
		// edits the cart — they're engaged again.
		$update = array_merge( $data, array(
			'status'     => 'active',
			'sent_at'    => null,
			'updated_at' => $now,
		) );
		// Build placeholder list for $wpdb->update — but PHP doesn't enforce types here.
		$wpdb->update( $table, $update, array( 'id' => (int) $existing['id'] ) );
		return;
	}
	$token = wto_abandoned_generate_token();
	$wpdb->insert( $table, array_merge( $data, array(
		'session_id' => $session_id,
		'token'      => $token,
		'status'     => 'active',
		'created_at' => $now,
		'updated_at' => $now,
	) ) );
}

function wto_abandoned_delete_by_session( $session_id ) {
	global $wpdb;
	$table = wto_abandoned_table();
	// Only delete rows that are still active (not sent/recovered) so we don't
	// lose history when the cart is cleared after a successful purchase.
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE session_id = %s AND status = %s", $session_id, 'active' ) );
}

function wto_abandoned_generate_token() {
	if ( function_exists( 'random_bytes' ) ) {
		return bin2hex( random_bytes( 16 ) );
	}
	return wp_generate_password( 32, false, false );
}

// ============================================================================
// Mobile capture from checkout (AJAX)
// ============================================================================

add_action( 'wp_ajax_wto_abandoned_capture_phone',        'wto_abandoned_ajax_capture_phone' );
add_action( 'wp_ajax_nopriv_wto_abandoned_capture_phone', 'wto_abandoned_ajax_capture_phone' );
function wto_abandoned_ajax_capture_phone() {
	check_ajax_referer( 'wto_abandoned_capture', 'nonce' );
	$mobile = isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '';
	$email  = isset( $_POST['email'] )  ? sanitize_email( wp_unslash( $_POST['email'] ) )  : '';
	$first  = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	if ( function_exists( 'wto_newsletter_normalize_mobile' ) ) {
		$mobile = wto_newsletter_normalize_mobile( $mobile );
	}
	if ( $mobile === '' ) {
		wp_send_json_error();
	}
	$session_id = wto_abandoned_get_session_id();
	if ( $session_id === '' ) {
		wp_send_json_error();
	}
	global $wpdb;
	$table = wto_abandoned_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE session_id = %s LIMIT 1", $session_id ), ARRAY_A );
	if ( ! $row ) {
		wp_send_json_success(); // No cart row yet — nothing to update, but not an error.
	}
	$wpdb->update(
		$table,
		array(
			'mobile'     => $mobile,
			'email'      => $email,
			'first_name' => $first,
			'updated_at' => current_time( 'mysql' ),
		),
		array( 'id' => (int) $row['id'] )
	);
	wp_send_json_success();
}

// Inject minimal capture JS on the WC checkout page.
add_action( 'wp_enqueue_scripts', 'wto_abandoned_maybe_enqueue_capture_js' );
function wto_abandoned_maybe_enqueue_capture_js() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	$settings = wto_abandoned_settings();
	if ( $settings['enabled'] !== '1' || $settings['capture_checkout'] !== '1' ) {
		return;
	}
	add_action( 'wp_footer', 'wto_abandoned_render_capture_js', 99 );
}
function wto_abandoned_render_capture_js() {
	$nonce    = wp_create_nonce( 'wto_abandoned_capture' );
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>
	<script>
	(function(){
		var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
		var debounce = null;
		function push() {
			var phone = (document.getElementById('billing_phone') || {}).value || '';
			var email = (document.getElementById('billing_email') || {}).value || '';
			var first = (document.getElementById('billing_first_name') || {}).value || '';
			if (!phone) return;
			var fd = new FormData();
			fd.append('action', 'wto_abandoned_capture_phone');
			fd.append('nonce', nonce);
			fd.append('mobile', phone);
			fd.append('email', email);
			fd.append('first_name', first);
			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function(){});
		}
		function bind(){
			['billing_phone', 'billing_email', 'billing_first_name'].forEach(function(id){
				var el = document.getElementById(id);
				if (!el || el._wtoBound) return;
				el._wtoBound = true;
				el.addEventListener('blur', function(){
					clearTimeout(debounce);
					debounce = setTimeout(push, 200);
				});
				el.addEventListener('change', function(){
					clearTimeout(debounce);
					debounce = setTimeout(push, 200);
				});
			});
		}
		bind();
		// Re-bind whenever WC updates the checkout fragment (AJAX shipping etc.).
		if (window.jQuery) {
			jQuery(document.body).on('updated_checkout updated_wc_div', bind);
		}
	})();
	</script>
	<?php
}

// ============================================================================
// Recovery: mark as recovered + restore cart from token
// ============================================================================

add_action( 'woocommerce_thankyou', 'wto_abandoned_mark_recovered_on_thankyou', 10, 1 );
function wto_abandoned_mark_recovered_on_thankyou( $order_id ) {
	if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$session_id = wto_abandoned_get_session_id();
	$uid        = (int) $order->get_user_id();
	$mobile     = $order->get_billing_phone();
	if ( $mobile !== '' && function_exists( 'wto_newsletter_normalize_mobile' ) ) {
		$mobile = wto_newsletter_normalize_mobile( $mobile );
	}

	global $wpdb;
	$table = wto_abandoned_table();
	$now   = current_time( 'mysql' );
	$row   = null;
	if ( $session_id !== '' ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE session_id = %s AND status != %s ORDER BY id DESC LIMIT 1", $session_id, 'recovered' ), ARRAY_A );
	}
	if ( ! $row && $uid > 0 ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE user_id = %d AND status != %s ORDER BY id DESC LIMIT 1", $uid, 'recovered' ), ARRAY_A );
	}
	if ( ! $row && $mobile !== '' ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE mobile = %s AND status != %s ORDER BY id DESC LIMIT 1", $mobile, 'recovered' ), ARRAY_A );
	}
	if ( ! $row ) {
		return;
	}
	$wpdb->update(
		$table,
		array(
			'status'             => 'recovered',
			'recovered_order_id' => (int) $order_id,
			'recovered_at'       => $now,
			'updated_at'         => $now,
		),
		array( 'id' => (int) $row['id'] )
	);
}

// Recovery URL — `?wto_recover_cart=<token>` restores the cart.
add_action( 'init', 'wto_abandoned_maybe_recover_cart' );
function wto_abandoned_maybe_recover_cart() {
	if ( empty( $_GET['wto_recover_cart'] ) || ! function_exists( 'WC' ) ) {
		return;
	}
	$token = sanitize_text_field( wp_unslash( $_GET['wto_recover_cart'] ) );
	if ( ! preg_match( '/^[a-f0-9]{16,64}$/i', $token ) ) {
		return;
	}
	global $wpdb;
	$table = wto_abandoned_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token = %s AND status != %s LIMIT 1", $token, 'recovered' ), ARRAY_A );
	if ( ! $row ) {
		return;
	}
	$items = json_decode( (string) $row['cart_data'], true );
	if ( ! is_array( $items ) || empty( $items ) ) {
		return;
	}
	// Restore cart contents into the current session.
	if ( ! WC()->cart ) {
		return;
	}
	WC()->cart->empty_cart();
	foreach ( $items as $item ) {
		$pid = isset( $item['product_id'] )   ? (int) $item['product_id']   : 0;
		$vid = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		$qty = isset( $item['quantity'] )     ? max( 1, (int) $item['quantity'] ) : 1;
		if ( $pid > 0 ) {
			WC()->cart->add_to_cart( $pid, $qty, $vid );
		}
	}
	// Redirect to the cart page after restore (clean URL).
	wp_safe_redirect( wc_get_cart_url() );
	exit;
}

// ============================================================================
// Cron dispatcher
// ============================================================================

add_action( WTO_ABANDONED_CRON_HOOK, 'wto_abandoned_run_dispatch' );
function wto_abandoned_run_dispatch() {
	$settings = wto_abandoned_settings();
	if ( $settings['enabled'] !== '1' ) {
		return;
	}
	$pattern = trim( (string) $settings['pattern_code'] );
	if ( $pattern === '' || ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return;
	}

	$delay_hours = max( 1, (int) $settings['delay_hours'] );
	$expiry_days = max( 1, (int) $settings['expiry_days'] );
	$batch_size  = max( 1, (int) $settings['batch_size'] );

	global $wpdb;
	$table  = wto_abandoned_table();
	$now    = current_time( 'mysql' );
	$cutoff_send   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$delay_hours} hours" ) );
	$cutoff_expire = gmdate( 'Y-m-d H:i:s', strtotime( "-{$expiry_days} days" ) );

	// 1) Mark too-old carts as expired (no SMS — they're not worth recovering).
	$wpdb->query( $wpdb->prepare(
		"UPDATE $table SET status = %s, updated_at = %s WHERE status IN ('active','sent') AND updated_at < %s",
		'expired',
		$now,
		$cutoff_expire
	) );

	// 2) Find candidates: active, has mobile, last updated ≥ delay_hours ago.
	// v3.13.13 PERF: ستون‌های دقیق به‌جای SELECT * — ستون cart_data از نوع LONGTEXT
	// است و در ارسال پیامک به آن نیاز نداریم. این تغییر برای فروشگاه‌هایی با ۱۰k+
	// سبد معلق، حافظه و زمان query را به‌طور قابل‌توجهی کاهش می‌دهد.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, mobile, first_name, total_value, items_count, token
		 FROM $table
		 WHERE status = %s AND mobile != '' AND updated_at <= %s
		 ORDER BY updated_at ASC
		 LIMIT %d",
		'active',
		$cutoff_send,
		$batch_size
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return;
	}

	// v3.13.13 PERF: site name و sender را خارج از حلقه می‌خوانیم — قبل از این
	// در هر iteration یک get_option اضافه اجرا می‌شد.
	$sender    = get_option( 'wto_sender', '' );
	$site_name = get_bloginfo( 'name' );
	foreach ( $rows as $row ) {
		$mobile = (string) $row['mobile'];
		if ( $mobile === '' ) {
			continue;
		}
		$recover_url = add_query_arg( 'wto_recover_cart', $row['token'], home_url( '/' ) );
		$attrs = array(
			'first_name'   => $row['first_name'] !== '' ? $row['first_name'] : 'مشتری گرامی',
			'sitename'     => $site_name,
			'cart_total'   => wto_abandoned_format_price( (float) $row['total_value'] ),
			'items_count'  => (int) $row['items_count'],
			'resume_url'   => $recover_url,
		);
		$result = wto_send_pattern_sms_raw( $mobile, $pattern, $attrs, $sender );
		$wpdb->update(
			$table,
			array(
				'status'     => $result === 'success' ? 'sent' : 'active',
				'sent_at'    => $result === 'success' ? $now : null,
				'updated_at' => $now,
			),
			array( 'id' => (int) $row['id'] )
		);
	}
}

function wto_abandoned_format_price( $value ) {
	if ( function_exists( 'wc_price' ) ) {
		$html = wc_price( (float) $value );
		return trim( wp_strip_all_tags( $html ) );
	}
	return number_format_i18n( (float) $value );
}

// ============================================================================
// Stats
// ============================================================================

function wto_abandoned_get_stats( $days = 30 ) {
	global $wpdb;
	$table  = wto_abandoned_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	$total        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_at >= %s", $cutoff ) );
	$active       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_at >= %s AND status = %s", $cutoff, 'active' ) );
	$sent         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE sent_at IS NOT NULL AND sent_at >= %s", $cutoff ) );
	$recovered    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE recovered_at IS NOT NULL AND recovered_at >= %s", $cutoff ) );
	$recov_value  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_value), 0) FROM $table WHERE recovered_at IS NOT NULL AND recovered_at >= %s", $cutoff ) );
	$total_value  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_value), 0) FROM $table WHERE created_at >= %s", $cutoff ) );
	$expired      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s AND updated_at >= %s", 'expired', $cutoff ) );
	// "Recovered after SMS" = recovered_at is later than sent_at.
	$recov_after_sms = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE recovered_at IS NOT NULL AND sent_at IS NOT NULL AND recovered_at >= sent_at AND recovered_at >= %s",
		$cutoff
	) );

	$recov_rate     = $total > 0 ? round( ( $recovered / $total ) * 100, 1 ) : 0;
	$post_sms_rate  = $sent  > 0 ? round( ( $recov_after_sms / $sent ) * 100, 1 ) : 0;

	return array(
		'total'           => $total,
		'active'          => $active,
		'sent'            => $sent,
		'recovered'       => $recovered,
		'recov_after_sms' => $recov_after_sms,
		'expired'         => $expired,
		'total_value'     => $total_value,
		'recov_value'     => $recov_value,
		'recov_rate'      => $recov_rate,
		'post_sms_rate'   => $post_sms_rate,
	);
}

// ============================================================================
// Settings save
// ============================================================================

add_action( 'admin_post_wto_abandoned_save_settings', 'wto_abandoned_handle_save_settings' );
function wto_abandoned_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'دسترسی غیرمجاز.', 'wto' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_abandoned_settings' );

	$new = wto_abandoned_settings();
	$new['enabled']          = isset( $_POST['enabled'] )          && $_POST['enabled'] === '1' ? '1' : '0';
	$new['capture_checkout'] = isset( $_POST['capture_checkout'] ) && $_POST['capture_checkout'] === '1' ? '1' : '0';
	$new['delay_hours']      = isset( $_POST['delay_hours'] )  ? max( 1, (int) $_POST['delay_hours'] )  : 1;
	$new['expiry_days']      = isset( $_POST['expiry_days'] )  ? max( 1, (int) $_POST['expiry_days'] )  : 7;
	$new['batch_size']       = isset( $_POST['batch_size'] )   ? max( 1, min( 500, (int) $_POST['batch_size'] ) ) : 50;
	$new['pattern_code']     = isset( $_POST['pattern_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_code'] ) ) : '';
	update_option( 'wto_abandoned_settings', $new, false );
	wp_safe_redirect( add_query_arg( array( 'page' => 'farazwto-abandoned', 'tab' => 'settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'wp_ajax_wto_abandoned_delete', 'wto_abandoned_ajax_delete' );
function wto_abandoned_ajax_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_abandoned_admin', 'nonce' );
	global $wpdb;
	$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
	$ids = array_filter( array_map( 'absint', $ids ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => __( 'هیچ ردیفی انتخاب نشده.', 'wto' ) ) );
	}
	$ids   = array_slice( $ids, 0, 500 );
	$table = wto_abandoned_table();
	$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$count = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($ph)", $ids ) );
	wp_send_json_success( array(
		'message' => sprintf( __( '%s ردیف حذف شد.', 'wto' ), number_format_i18n( $count ) ),
		'deleted' => $count,
	) );
}

// Manual "run dispatch now" button for testing.
add_action( 'admin_post_wto_abandoned_run_now', 'wto_abandoned_handle_run_now' );
function wto_abandoned_handle_run_now() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'دسترسی غیرمجاز.', 'wto' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_abandoned_run_now' );
	wto_abandoned_run_dispatch();
	wp_safe_redirect( add_query_arg( array( 'page' => 'farazwto-abandoned', 'ran' => '1' ), admin_url( 'admin.php' ) ) );
	exit;
}

// ============================================================================
// Admin page render
// ============================================================================

function wto_render_abandoned_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی غیرمجاز.', 'wto' ) );
	}
	wto_abandoned_maybe_setup_table();

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
	$tab = in_array( $tab, array( 'dashboard', 'list', 'settings' ), true ) ? $tab : 'dashboard';

	echo '<section class="wrapper wto-abandoned-wrapper">';
	wto_abandoned_render_header();
	wto_abandoned_render_tabs( $tab );
	switch ( $tab ) {
		case 'list':
			wto_abandoned_render_list_tab();
			break;
		case 'settings':
			wto_abandoned_render_settings_tab();
			break;
		default:
			wto_abandoned_render_dashboard_tab();
	}
	wto_abandoned_render_inline();
	echo '</section>';
}

function wto_abandoned_render_header() {
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
	<h1 class="wto-abandoned-title-main"><?php esc_html_e( 'سبد خرید رها‌شده', 'wto' ); ?></h1>
	<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'این قابلیت نیازمند ووکامرس است.', 'wto' ); ?></p></div>
	<?php endif; ?>
	<?php
}

function wto_abandoned_render_tabs( $active ) {
	$tabs = array(
		'dashboard' => __( 'داشبورد آماری', 'wto' ),
		'list'      => __( 'لیست سبدها', 'wto' ),
		'settings'  => __( 'تنظیمات', 'wto' ),
	);
	?>
	<nav class="wto-abandoned-tabs">
		<?php foreach ( $tabs as $key => $label ) :
			$url = add_query_arg( array( 'page' => 'farazwto-abandoned', 'tab' => $key ), admin_url( 'admin.php' ) );
		?>
			<a class="wto-abandoned-tab <?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
}

function wto_abandoned_render_dashboard_tab() {
	$days = isset( $_GET['days'] ) ? max( 1, (int) $_GET['days'] ) : 30;
	$days = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
	$s    = wto_abandoned_get_stats( $days );
	$next_cron = wp_next_scheduled( WTO_ABANDONED_CRON_HOOK );
	$ran  = isset( $_GET['ran'] ) ? sanitize_key( $_GET['ran'] ) : '';
	?>
	<?php if ( $ran === '1' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'اجرای دستی dispatch انجام شد.', 'wto' ); ?></p></div>
	<?php endif; ?>

	<div class="wto-abandoned-range-bar">
		<?php
		$ranges = array( 7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => '۱ سال' );
		foreach ( $ranges as $d => $lbl ) :
			$url = add_query_arg( array( 'page' => 'farazwto-abandoned', 'days' => $d ), admin_url( 'admin.php' ) );
			?>
			<a href="<?php echo esc_url( $url ); ?>" class="wto-abandoned-range <?php echo $days === $d ? 'is-active' : ''; ?>"><?php echo esc_html( $lbl ); ?></a>
		<?php endforeach; ?>
	</div>

	<div class="wto-abandoned-stats">
		<div class="wto-abandoned-stat">
			<div class="num"><?php echo esc_html( number_format_i18n( $s['total'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'کل سبدهای رها‌شده', 'wto' ); ?></div>
		</div>
		<div class="wto-abandoned-stat">
			<div class="num wto-num-info"><?php echo esc_html( number_format_i18n( $s['sent'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'پیامک یادآوری ارسال شده', 'wto' ); ?></div>
		</div>
		<div class="wto-abandoned-stat">
			<div class="num wto-num-success"><?php echo esc_html( number_format_i18n( $s['recovered'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'سبد بازیافت‌شده', 'wto' ); ?></div>
		</div>
		<div class="wto-abandoned-stat">
			<div class="num wto-num-success"><?php echo esc_html( $s['recov_rate'] ); ?>٪</div>
			<div class="lbl"><?php esc_html_e( 'نرخ بازیافت کلی', 'wto' ); ?></div>
		</div>
	</div>

	<div class="wto-abandoned-stats">
		<div class="wto-abandoned-stat">
			<div class="num wto-num-success"><?php echo esc_html( $s['post_sms_rate'] ); ?>٪</div>
			<div class="lbl"><?php esc_html_e( 'نرخ بازیافت پس از پیامک', 'wto' ); ?></div>
		</div>
		<div class="wto-abandoned-stat">
			<div class="num wto-num-info"><?php echo esc_html( number_format_i18n( $s['recov_after_sms'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'تعداد بازیافت پس از پیامک', 'wto' ); ?></div>
		</div>
		<div class="wto-abandoned-stat">
			<div class="num wto-num-muted"><?php echo esc_html( number_format_i18n( $s['expired'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'منقضی‌شده', 'wto' ); ?></div>
		</div>
		<div class="wto-abandoned-stat">
			<div class="num wto-num-warning"><?php echo esc_html( number_format_i18n( $s['active'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'هنوز فعال (در انتظار)', 'wto' ); ?></div>
		</div>
	</div>

	<div class="wto-abandoned-value-card">
		<h3><?php esc_html_e( 'ارزش بازیافت‌شده', 'wto' ); ?></h3>
		<div class="wto-abandoned-value-row">
			<div>
				<div class="wto-value-num"><?php echo esc_html( wto_abandoned_format_price( $s['recov_value'] ) ); ?></div>
				<div class="wto-value-lbl"><?php esc_html_e( 'مجموع ارزش سفارش‌های بازیافت‌شده', 'wto' ); ?></div>
			</div>
			<div>
				<div class="wto-value-num wto-value-num-muted"><?php echo esc_html( wto_abandoned_format_price( $s['total_value'] ) ); ?></div>
				<div class="wto-value-lbl"><?php esc_html_e( 'مجموع ارزش همه سبدهای رها‌شده', 'wto' ); ?></div>
			</div>
		</div>
	</div>

	<div class="wto-abandoned-cron-card">
		<div>
			<strong><?php esc_html_e( 'زمان‌بندی wp-cron:', 'wto' ); ?></strong>
			<?php
			if ( $next_cron ) {
				$jdate = function_exists( 'wto_send_reports_to_jalali' ) ? wto_send_reports_to_jalali( gmdate( 'Y-m-d H:i:s', $next_cron ) ) : gmdate( 'Y-m-d H:i:s', $next_cron );
				printf(
					/* translators: %s next run datetime */
					esc_html__( 'اجرای بعدی: %s', 'wto' ),
					'<code>' . esc_html( $jdate ) . '</code>'
				);
			} else {
				esc_html_e( 'cron زمان‌بندی نشده!', 'wto' );
			}
			?>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="wto_abandoned_run_now">
			<?php wp_nonce_field( 'wto_abandoned_run_now' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'اجرای فوری dispatch (برای تست)', 'wto' ); ?></button>
		</form>
	</div>
	<?php
}

function wto_abandoned_render_list_tab() {
	global $wpdb;
	$table  = wto_abandoned_table();

	$page   = isset( $_GET['paged'] )  ? max( 1, (int) $_GET['paged'] )  : 1;
	$limit  = isset( $_GET['limit'] )  ? max( 10, min( 200, (int) $_GET['limit'] ) ) : 25;
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$where  = ' WHERE 1=1 ';
	$params = array();
	if ( in_array( $status, array( 'active', 'sent', 'recovered', 'expired' ), true ) ) {
		$where   .= ' AND status = %s';
		$params[] = $status;
	}
	if ( $search !== '' ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where   .= ' AND (mobile LIKE %s OR email LIKE %s OR first_name LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}
	$total = (int) ( $params
		? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $where", $params ) )
		: $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" ) );
	$total_pages = max( 1, (int) ceil( $total / $limit ) );
	$offset      = ( $page - 1 ) * $limit;
	$query       = "SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d";
	$all_params  = array_merge( $params, array( $limit, $offset ) );
	$rows        = $wpdb->get_results( $wpdb->prepare( $query, $all_params ), ARRAY_A );

	$status_labels = array(
		'active'    => __( 'فعال', 'wto' ),
		'sent'      => __( 'پیامک ارسال شده', 'wto' ),
		'recovered' => __( 'بازیافت‌شده', 'wto' ),
		'expired'   => __( 'منقضی', 'wto' ),
	);
	$status_classes = array(
		'active'    => 'warning',
		'sent'      => 'info',
		'recovered' => 'success',
		'expired'   => 'muted',
	);
	?>
	<form method="get" class="wto-abandoned-filters">
		<input type="hidden" name="page" value="farazwto-abandoned">
		<input type="hidden" name="tab" value="list">
		<label>
			<span><?php esc_html_e( 'جستجو:', 'wto' ); ?></span>
			<input type="text" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'موبایل، ایمیل، نام', 'wto' ); ?>">
		</label>
		<label>
			<span><?php esc_html_e( 'وضعیت:', 'wto' ); ?></span>
			<select name="status">
				<option value=""<?php selected( $status, '' ); ?>><?php esc_html_e( 'همه', 'wto' ); ?></option>
				<?php foreach ( $status_labels as $k => $lbl ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $status, $k ); ?>><?php echo esc_html( $lbl ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'تعداد در صفحه:', 'wto' ); ?></span>
			<select name="limit">
				<?php foreach ( array( 25, 50, 100, 200 ) as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>"<?php selected( $limit, $opt ); ?>><?php echo esc_html( (string) $opt ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'اعمال', 'wto' ); ?></button>
	</form>

	<form id="wto-abandoned-bulk-form">
		<?php wp_nonce_field( 'wto_abandoned_admin', 'wto_abandoned_admin_nonce' ); ?>
		<div class="wto-abandoned-bulk-bar">
			<button type="button" class="button wto-abandoned-bulk-delete" disabled><?php esc_html_e( 'حذف انتخاب‌شده‌ها', 'wto' ); ?></button>
			<span class="wto-abandoned-bulk-info"></span>
		</div>
		<table class="widefat striped wto-abandoned-table">
			<thead>
				<tr>
					<th class="check-column"><input type="checkbox" id="wto-abandoned-select-all"></th>
					<th><?php esc_html_e( 'شناسه', 'wto' ); ?></th>
					<th><?php esc_html_e( 'تاریخ', 'wto' ); ?></th>
					<th><?php esc_html_e( 'مشتری', 'wto' ); ?></th>
					<th><?php esc_html_e( 'موبایل', 'wto' ); ?></th>
					<th><?php esc_html_e( 'اقلام', 'wto' ); ?></th>
					<th><?php esc_html_e( 'مبلغ', 'wto' ); ?></th>
					<th><?php esc_html_e( 'وضعیت', 'wto' ); ?></th>
					<th><?php esc_html_e( 'بازیافت', 'wto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'هیچ سبد رهاشده‌ای ثبت نشده.', 'wto' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$jdate     = function_exists( 'wto_send_reports_to_jalali' ) ? wto_send_reports_to_jalali( $r['updated_at'] ) : $r['updated_at'];
					$status_t  = isset( $status_labels[ $r['status'] ] )  ? $status_labels[ $r['status'] ]  : $r['status'];
					$status_c  = isset( $status_classes[ $r['status'] ] ) ? $status_classes[ $r['status'] ] : 'muted';
					$customer  = '';
					if ( $r['first_name'] !== '' ) $customer = $r['first_name'];
					if ( (int) $r['user_id'] > 0 )  $customer .= ' #' . (int) $r['user_id'];
					if ( $customer === '' ) $customer = '—';
					$order_html = '—';
					if ( $r['recovered_order_id'] && function_exists( 'wc_get_order' ) ) {
						$order = wc_get_order( (int) $r['recovered_order_id'] );
						if ( $order ) {
							$order_html = '<a href="' . esc_url( $order->get_edit_order_url() ) . '" target="_blank">#' . (int) $r['recovered_order_id'] . '</a>';
						}
					}
				?>
					<tr>
						<td><input type="checkbox" class="wto-abandoned-row" value="<?php echo esc_attr( $r['id'] ); ?>"></td>
						<td><?php echo esc_html( $r['id'] ); ?></td>
						<td class="wto-abandoned-date-cell"><?php echo esc_html( $jdate ); ?></td>
						<td><?php echo esc_html( $customer ); ?></td>
						<td dir="ltr"><?php echo esc_html( $r['mobile'] !== '' ? $r['mobile'] : '—' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['items_count'] ) ); ?></td>
						<td><?php echo esc_html( wto_abandoned_format_price( (float) $r['total_value'] ) ); ?></td>
						<td><span class="wto-status wto-status-<?php echo esc_attr( $status_c ); ?>"><?php echo esc_html( $status_t ); ?></span></td>
						<td><?php echo wp_kses_post( $order_html ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</form>

	<?php if ( $total_pages > 1 ) :
		$base = add_query_arg( array(
			'page' => 'farazwto-abandoned', 'tab' => 'list',
			'status' => $status, 'search' => $search, 'limit' => $limit,
		), admin_url( 'admin.php' ) );
		?>
		<div class="wto-abandoned-pagination">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1, $base ) ); ?>">« <?php esc_html_e( 'قبل', 'wto' ); ?></a>
			<?php endif; ?>
			<span><?php printf( esc_html__( 'صفحه %1$s از %2$s', 'wto' ), esc_html( number_format_i18n( $page ) ), esc_html( number_format_i18n( $total_pages ) ) ); ?></span>
			<?php if ( $page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $base ) ); ?>"><?php esc_html_e( 'بعد', 'wto' ); ?> »</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php
}

function wto_abandoned_render_settings_tab() {
	$s = wto_abandoned_settings();
	$updated = isset( $_GET['updated'] ) ? sanitize_key( $_GET['updated'] ) : '';
	?>
	<?php if ( $updated === '1' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'wto' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wto-abandoned-card">
		<input type="hidden" name="action" value="wto_abandoned_save_settings">
		<?php wp_nonce_field( 'wto_abandoned_settings' ); ?>

		<h2><?php esc_html_e( 'تنظیمات سبد رها‌شده', 'wto' ); ?></h2>

		<div class="wto-abandoned-settings-grid">
			<label class="wto-abandoned-setting wto-setting-wide">
				<span><?php esc_html_e( 'فعال‌سازی', 'wto' ); ?></span>
				<label class="wto-abandoned-switch">
					<input type="checkbox" class="wto-toggle" name="enabled" value="1" <?php checked( $s['enabled'], '1' ); ?>>
					<span><?php esc_html_e( 'ردیابی سبد رها‌شده و ارسال پیامک یادآوری فعال است.', 'wto' ); ?></span>
				</label>
			</label>

			<label class="wto-abandoned-setting wto-setting-wide">
				<span><?php esc_html_e( 'ضبط موبایل از فرم checkout', 'wto' ); ?></span>
				<label class="wto-abandoned-switch">
					<input type="checkbox" class="wto-toggle" name="capture_checkout" value="1" <?php checked( $s['capture_checkout'], '1' ); ?>>
					<span><?php esc_html_e( 'اگر کاربر شماره موبایل را در checkout وارد کند ولی پرداخت را تکمیل نکند، آن شماره برای ارسال یادآوری استفاده می‌شود.', 'wto' ); ?></span>
				</label>
			</label>

			<label class="wto-abandoned-setting">
				<span><?php esc_html_e( 'تأخیر ارسال (ساعت)', 'wto' ); ?></span>
				<input type="number" name="delay_hours" value="<?php echo esc_attr( $s['delay_hours'] ); ?>" min="1" max="168" dir="ltr">
				<small><?php esc_html_e( 'پس از این مدت از آخرین تغییر سبد، پیامک یادآوری ارسال می‌شود.', 'wto' ); ?></small>
			</label>

			<label class="wto-abandoned-setting">
				<span><?php esc_html_e( 'انقضاء (روز)', 'wto' ); ?></span>
				<input type="number" name="expiry_days" value="<?php echo esc_attr( $s['expiry_days'] ); ?>" min="1" max="365" dir="ltr">
				<small><?php esc_html_e( 'سبدهای قدیمی‌تر از این مدت دیگر پیامک نخواهند گرفت.', 'wto' ); ?></small>
			</label>

			<label class="wto-abandoned-setting">
				<span><?php esc_html_e( 'سقف ارسال در هر اجرای cron', 'wto' ); ?></span>
				<input type="number" name="batch_size" value="<?php echo esc_attr( $s['batch_size'] ); ?>" min="1" max="500" dir="ltr">
				<small><?php esc_html_e( 'cron هر ۳۰ دقیقه اجرا می‌شود.', 'wto' ); ?></small>
			</label>

			<label class="wto-abandoned-setting wto-setting-wide">
				<span><?php esc_html_e( 'کد الگوی پیامک یادآوری *', 'wto' ); ?></span>
				<input type="text" name="pattern_code" value="<?php echo esc_attr( $s['pattern_code'] ); ?>" dir="ltr">
				<small class="wto-abandoned-help">
					<?php esc_html_e( 'الزامی — متغیرهای قابل استفاده:', 'wto' ); ?>
					<code dir="ltr">%first_name%</code>
					<code dir="ltr">%cart_total%</code>
					<code dir="ltr">%items_count%</code>
					<code dir="ltr">%resume_url%</code>
					<br><?php esc_html_e( '⚠ نام برند فروشگاه را به‌صورت ثابت در متن الگو بنویسید (نه به‌صورت متغیر) — برای تأیید الگو در پنل فراز ضروری است.', 'wto' ); ?>
				</small>
			</label>
		</div>

		<div class="wto-abandoned-save-row">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تنظیمات', 'wto' ); ?></button>
		</div>
	</form>
	<?php
}

function wto_abandoned_render_inline() {
	$nonce = wp_create_nonce( 'wto_abandoned_admin' );
	?>
	<style>
	.wto-abandoned-wrapper .wto-abandoned-title-main { margin: 16px 0 8px; }
	.wto-abandoned-wrapper .wto-abandoned-tabs { display: flex; gap: 4px; border-bottom: 1px solid #c3c4c7; margin: 16px 0 20px; }
	.wto-abandoned-wrapper .wto-abandoned-tab { padding: 10px 18px; text-decoration: none; color: #50575e; background: #f1f1f1; border: 1px solid #c3c4c7; border-bottom: 0; border-radius: 6px 6px 0 0; margin-bottom: -1px; font-size: 13px; }
	.wto-abandoned-wrapper .wto-abandoned-tab.is-active { background: #fff; color: #1d2327; font-weight: 600; }
	.wto-abandoned-wrapper .wto-abandoned-range-bar { display: flex; gap: 6px; margin-bottom: 16px; }
	.wto-abandoned-wrapper .wto-abandoned-range { padding: 6px 14px; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; color: #4b5563; text-decoration: none; font-size: 13px; }
	.wto-abandoned-wrapper .wto-abandoned-range.is-active { background: #6366f1; color: #fff; border-color: #6366f1; font-weight: 600; }
	.wto-abandoned-wrapper .wto-abandoned-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 0 0 16px; }
	.wto-abandoned-wrapper .wto-abandoned-stat { background: #fff; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; text-align: center; }
	.wto-abandoned-wrapper .wto-abandoned-stat .num { font-size: 26px; font-weight: 700; line-height: 1; color: #1f2937; }
	.wto-abandoned-wrapper .wto-abandoned-stat .num.wto-num-success { color: #047857; }
	.wto-abandoned-wrapper .wto-abandoned-stat .num.wto-num-info { color: #1d4ed8; }
	.wto-abandoned-wrapper .wto-abandoned-stat .num.wto-num-warning { color: #b45309; }
	.wto-abandoned-wrapper .wto-abandoned-stat .num.wto-num-muted { color: #6b7280; }
	.wto-abandoned-wrapper .wto-abandoned-stat .lbl { color: #6b7280; font-size: 12px; margin-top: 6px; }
	.wto-abandoned-wrapper .wto-abandoned-value-card { background: #fff; padding: 18px 22px; border: 1px solid #e5e7eb; border-radius: 10px; margin: 16px 0; }
	.wto-abandoned-wrapper .wto-abandoned-value-card h3 { margin: 0 0 12px; font-size: 14px; color: #1f2937; }
	.wto-abandoned-wrapper .wto-abandoned-value-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
	.wto-abandoned-wrapper .wto-value-num { font-size: 22px; font-weight: 700; color: #047857; }
	.wto-abandoned-wrapper .wto-value-num-muted { color: #6b7280; }
	.wto-abandoned-wrapper .wto-value-lbl { color: #6b7280; font-size: 12px; margin-top: 4px; }
	.wto-abandoned-wrapper .wto-abandoned-cron-card { background: #f9fafb; padding: 12px 16px; border: 1px solid #e5e7eb; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin: 16px 0; }
	.wto-abandoned-wrapper .wto-abandoned-cron-card code { background: #fff; padding: 2px 6px; border-radius: 4px; direction: ltr; }
	.wto-abandoned-wrapper .wto-abandoned-filters { background: #fff; padding: 14px 16px; border: 1px solid #dcdcde; border-radius: 8px; margin: 0 0 14px; display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
	.wto-abandoned-wrapper .wto-abandoned-filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
	.wto-abandoned-wrapper .wto-abandoned-bulk-bar { margin: 0 0 10px; display: flex; align-items: center; gap: 10px; }
	.wto-abandoned-wrapper .wto-abandoned-table th,
	.wto-abandoned-wrapper .wto-abandoned-table td { padding: 10px 12px; vertical-align: middle; }
	.wto-abandoned-wrapper .wto-abandoned-date-cell { direction: ltr; font-variant-numeric: tabular-nums; }
	.wto-abandoned-wrapper .wto-abandoned-pagination { display: flex; gap: 8px; align-items: center; }
	.wto-abandoned-wrapper .wto-status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
	.wto-abandoned-wrapper .wto-status-success { background: #d1f5e0; color: #006d28; }
	.wto-abandoned-wrapper .wto-status-warning { background: #fef3c7; color: #92400e; }
	.wto-abandoned-wrapper .wto-status-info { background: #dbe9ff; color: #134a99; }
	.wto-abandoned-wrapper .wto-status-muted { background: #e0e0e0; color: #50575e; }
	.wto-abandoned-wrapper .wto-abandoned-card { background: #fff; padding: 20px 24px; border: 1px solid #e5e7eb; border-radius: 10px; max-width: 760px; }
	.wto-abandoned-wrapper .wto-abandoned-card h2 { margin: 0 0 12px; font-size: 16px; }
	.wto-abandoned-wrapper .wto-abandoned-settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; margin: 12px 0 20px; }
	.wto-abandoned-wrapper .wto-abandoned-setting { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
	.wto-abandoned-wrapper .wto-abandoned-setting.wto-setting-wide { grid-column: 1 / -1; }
	.wto-abandoned-wrapper .wto-abandoned-setting input { padding: 8px 10px; }
	.wto-abandoned-wrapper .wto-abandoned-setting small { color: #6b7280; font-size: 11px; margin-top: 4px; }
	.wto-abandoned-wrapper .wto-abandoned-help code { background: #f3f4f6; padding: 1px 5px; border-radius: 3px; margin: 0 2px; }
	.wto-abandoned-wrapper .wto-abandoned-switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
	.wto-abandoned-wrapper .wto-abandoned-save-row { margin-top: 10px; }
	@media (max-width: 720px) {
		.wto-abandoned-wrapper .wto-abandoned-stats { grid-template-columns: repeat(2, 1fr); }
		.wto-abandoned-wrapper .wto-abandoned-settings-grid { grid-template-columns: 1fr; }
		.wto-abandoned-wrapper .wto-abandoned-value-row { grid-template-columns: 1fr; }
	}
	</style>
	<script>
	(function(){
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
		var selAll  = document.getElementById('wto-abandoned-select-all');
		var rows    = document.querySelectorAll('.wto-abandoned-row');
		var delBtn  = document.querySelector('.wto-abandoned-bulk-delete');
		var info    = document.querySelector('.wto-abandoned-bulk-info');
		function upd(){
			var c = Array.prototype.filter.call(rows, function(x){ return x.checked; });
			if (delBtn) {
				delBtn.disabled = c.length === 0;
				if (info) info.textContent = c.length ? (c.length + ' ردیف انتخاب شده') : '';
			}
		}
		if (selAll) selAll.addEventListener('change', function(){
			Array.prototype.forEach.call(rows, function(x){ x.checked = selAll.checked; });
			upd();
		});
		Array.prototype.forEach.call(rows, function(x){ x.addEventListener('change', upd); });
		if (delBtn) delBtn.addEventListener('click', function(){
			var c = Array.prototype.filter.call(rows, function(x){ return x.checked; });
			if (c.length === 0) return;
			if (!confirm('حذف ' + c.length + ' ردیف — مطمئن هستید؟')) return;
			var fd = new FormData();
			fd.append('action', 'wto_abandoned_delete');
			fd.append('nonce', nonce);
			c.forEach(function(x){ fd.append('ids[]', x.value); });
			delBtn.disabled = true;
			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(json){
					if (json.success) window.location.reload();
					else { alert((json.data && json.data.message) || 'خطا.'); delBtn.disabled = false; }
				})
				.catch(function(){ alert('خطا.'); delBtn.disabled = false; });
		});
	})();
	</script>
	<?php
}
