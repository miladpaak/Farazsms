<?php
/**
 * موجود شد خبرم کن (Back-in-stock notifications) — Phase 2
 *
 * این ماژول فرم «موجود شد خبرم کن» را روی صفحه محصولات ناموجود ووکامرس
 * فعال می‌کند، شماره موبایل مشترک را ذخیره می‌کند، و وقتی موجودی محصول
 * به `instock` تغییر کرد، با الگوی تنظیم‌شده پیامک اطلاع‌رسانی می‌فرستد.
 *
 * جدول DB:  {$wpdb->prefix}wto_notify_subscribers
 * Submenu:  farazwto-notify (priority 986، بلافاصله بعد از خبرنامه)
 *
 * توجه: تمام بخش‌های وابسته به ووکامرس داخل شرط `function_exists('WC')`
 * قرار دارند تا روی سایت‌های بدون ووکامرس بدون خطا load شوند.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// ============================================================================
// Schema
// ============================================================================

const WTO_NOTIFY_DB_VERSION        = '1.0.0';
const WTO_NOTIFY_DB_VERSION_OPTION = 'wto_notify_db_version';

function wto_notify_table() {
	global $wpdb;
	return $wpdb->prefix . 'wto_notify_subscribers';
}

function wto_notify_maybe_setup_table() {
	if ( get_option( WTO_NOTIFY_DB_VERSION_OPTION ) === WTO_NOTIFY_DB_VERSION ) {
		return;
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	global $wpdb;
	$table           = wto_notify_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		product_id BIGINT(20) UNSIGNED NOT NULL,
		variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		mobile VARCHAR(20) NOT NULL,
		ip VARCHAR(45) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		subscribed_at DATETIME NOT NULL,
		notified_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY product_mobile (product_id, variation_id, mobile),
		KEY status (status),
		KEY product_id (product_id),
		KEY subscribed_at (subscribed_at)
	) $charset_collate;";

	dbDelta( $sql );
	update_option( WTO_NOTIFY_DB_VERSION_OPTION, WTO_NOTIFY_DB_VERSION, false );
}
add_action( 'admin_init', 'wto_notify_maybe_setup_table' );

// ============================================================================
// Settings
// ============================================================================

function wto_notify_settings() {
	$defaults = array(
		'enabled'             => '1',
		'button_text'         => 'موجود شد، خبرم کن',
		'subscribed_text'     => 'ثبت شد ✓ — به‌محض موجود شدن اطلاع‌رسانی می‌شود.',
		'popup_title'         => 'اطلاع‌رسانی موجودی',
		'popup_description'   => 'شماره موبایل خود را وارد کنید تا به‌محض موجود شدن این محصول، اطلاع دهیم.',
		'mobile_label'        => 'شماره موبایل',
		'submit_text'         => 'ثبت',
		'success_message'     => 'با موفقیت ثبت شد. به‌محض موجود شدن، اطلاع داده می‌شود.',
		'duplicate_message'   => 'این شماره قبلاً برای این محصول ثبت شده است.',
		'error_message'       => 'خطا در ثبت. لطفاً مجدداً تلاش کنید.',
		'pattern_code'        => '',
		'dispatch_delay'      => '60', // ثانیه — تأخیر بعد از تغییر stock تا ارسال پیامک
	);
	$saved = get_option( 'wto_notify_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

// ============================================================================
// Submenu (priority 986 — بلافاصله بعد از خبرنامه)
// ============================================================================

add_action( 'admin_menu', 'wto_notify_register_submenu', 986 );
function wto_notify_register_submenu() {
	add_submenu_page(
		'farazwto',
		__( 'موجود شد خبرم کن', 'wto' ),
		__( 'موجود شد خبرم کن', 'wto' ),
		'manage_options',
		'farazwto-notify',
		'wto_render_notify_page'
	);
}

// ============================================================================
// CRUD helpers
// ============================================================================

function wto_notify_normalize_mobile( $raw ) {
	// reuse newsletter helper (same Iranian phone normalization rule)
	if ( function_exists( 'wto_newsletter_normalize_mobile' ) ) {
		return wto_newsletter_normalize_mobile( $raw );
	}
	$raw    = (string) $raw;
	$digits = preg_replace( '/\D+/', '', $raw );
	if ( strpos( $digits, '98' ) === 0 && strlen( $digits ) === 12 ) {
		$digits = '0' . substr( $digits, 2 );
	} elseif ( strlen( $digits ) === 10 && $digits[0] === '9' ) {
		$digits = '0' . $digits;
	}
	if ( strlen( $digits ) !== 11 || strpos( $digits, '09' ) !== 0 ) {
		return '';
	}
	return $digits;
}

/**
 * Insert (or restore) a subscription for a specific product (+ optional variation).
 *
 * @return array{success:bool, code:string, message:string, id?:int}
 */
function wto_notify_insert_subscriber( $product_id, $variation_id, $mobile_raw ) {
	global $wpdb;
	$product_id   = (int) $product_id;
	$variation_id = (int) $variation_id;
	$mobile       = wto_notify_normalize_mobile( $mobile_raw );
	if ( $mobile === '' ) {
		return array(
			'success' => false,
			'code'    => 'invalid',
			'message' => __( 'شماره موبایل معتبر نیست.', 'wto' ),
		);
	}
	if ( $product_id <= 0 ) {
		return array(
			'success' => false,
			'code'    => 'invalid',
			'message' => __( 'محصول نامعتبر است.', 'wto' ),
		);
	}
	$ip = '';
	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
	$now   = current_time( 'mysql' );
	$table = wto_notify_table();

	$existing = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, status FROM $table WHERE product_id = %d AND variation_id = %d AND mobile = %s LIMIT 1",
			$product_id,
			$variation_id,
			$mobile
		),
		ARRAY_A
	);

	$settings = wto_notify_settings();

	if ( $existing ) {
		if ( $existing['status'] === 'pending' ) {
			return array(
				'success' => false,
				'code'    => 'duplicate',
				'message' => $settings['duplicate_message'],
				'id'      => (int) $existing['id'],
			);
		}
		// Already notified or cancelled — re-subscribe (set back to pending).
		$wpdb->update(
			$table,
			array(
				'status'        => 'pending',
				'subscribed_at' => $now,
				'notified_at'   => null,
				'ip'            => $ip,
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return array(
			'success' => true,
			'code'    => 'reactivated',
			'message' => $settings['success_message'],
			'id'      => (int) $existing['id'],
		);
	}

	$inserted = $wpdb->insert(
		$table,
		array(
			'product_id'    => $product_id,
			'variation_id'  => $variation_id,
			'mobile'        => $mobile,
			'ip'            => $ip,
			'status'        => 'pending',
			'subscribed_at' => $now,
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s' )
	);
	if ( $inserted === false ) {
		return array(
			'success' => false,
			'code'    => 'error',
			'message' => $settings['error_message'],
		);
	}
	return array(
		'success' => true,
		'code'    => 'ok',
		'message' => $settings['success_message'],
		'id'      => (int) $wpdb->insert_id,
	);
}

function wto_notify_get_counts() {
	global $wpdb;
	$table = wto_notify_table();
	return array(
		'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
		'pending'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", 'pending' ) ),
		'notified' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", 'notified' ) ),
		'products' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT product_id) FROM $table WHERE status = %s", 'pending' ) ),
	);
}

// ============================================================================
// AJAX subscribe (Public)
// ============================================================================

add_action( 'wp_ajax_wto_notify_subscribe',        'wto_notify_ajax_subscribe' );
add_action( 'wp_ajax_nopriv_wto_notify_subscribe', 'wto_notify_ajax_subscribe' );
function wto_notify_ajax_subscribe() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'wto_notify_subscribe' ) ) {
		wp_send_json_error( array( 'message' => __( 'خطای امنیتی. لطفاً صفحه را رفرش کنید.', 'wto' ) ), 403 );
	}
	// rate-limit per IP
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( $ip !== '' ) {
		$rl_key = 'wto_nfy_rl_' . md5( $ip );
		$count  = (int) get_transient( $rl_key );
		if ( $count >= 15 ) {
			wp_send_json_error( array( 'message' => __( 'تعداد درخواست‌ها زیاد است. لطفاً ۱۵ دقیقه دیگر مجدداً تلاش کنید.', 'wto' ) ), 429 );
		}
		set_transient( $rl_key, $count + 1, 15 * MINUTE_IN_SECONDS );
	}

	$settings = wto_notify_settings();
	if ( $settings['enabled'] !== '1' ) {
		wp_send_json_error( array( 'message' => __( 'این قابلیت در حال حاضر غیرفعال است.', 'wto' ) ) );
	}

	$product_id   = isset( $_POST['product_id'] )   ? (int) $_POST['product_id']   : 0;
	$variation_id = isset( $_POST['variation_id'] ) ? (int) $_POST['variation_id'] : 0;
	$mobile       = isset( $_POST['mobile'] )       ? wp_unslash( $_POST['mobile'] ) : '';

	// Verify product exists and is actually out of stock — otherwise no point subscribing.
	if ( ! function_exists( 'wc_get_product' ) ) {
		wp_send_json_error( array( 'message' => __( 'ووکامرس نصب نیست.', 'wto' ) ) );
	}
	$check_id = $variation_id > 0 ? $variation_id : $product_id;
	$product  = wc_get_product( $check_id );
	if ( ! $product ) {
		wp_send_json_error( array( 'message' => __( 'محصول یافت نشد.', 'wto' ) ) );
	}
	if ( $product->is_in_stock() ) {
		wp_send_json_error( array( 'message' => __( 'این محصول هم‌اکنون موجود است.', 'wto' ) ) );
	}

	$result = wto_notify_insert_subscriber( $product_id, $variation_id, $mobile );
	if ( ! $result['success'] ) {
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
	wp_send_json_success( array( 'message' => $result['message'] ) );
}

// ============================================================================
// Hook: when WC product stock changes, schedule SMS dispatch
// ============================================================================

add_action( 'woocommerce_product_set_stock_status', 'wto_notify_on_stock_status_change', 10, 3 );
function wto_notify_on_stock_status_change( $product_id, $stock_status, $product = null ) {
	if ( $stock_status !== 'instock' ) {
		return;
	}
	$settings = wto_notify_settings();
	if ( $settings['enabled'] !== '1' ) {
		return;
	}
	$delay = max( 10, (int) $settings['dispatch_delay'] );
	// Variation products also fire on the parent — store both IDs.
	$is_variation = false;
	if ( function_exists( 'wc_get_product' ) ) {
		$p = wc_get_product( $product_id );
		if ( $p && method_exists( $p, 'is_type' ) && $p->is_type( 'variation' ) ) {
			$is_variation = true;
		}
	}
	$args = $is_variation
		? array( (int) $p->get_parent_id(), (int) $product_id )
		: array( (int) $product_id, 0 );

	if ( ! wp_next_scheduled( 'wto_notify_dispatch', $args ) ) {
		wp_schedule_single_event( time() + $delay, 'wto_notify_dispatch', $args );
	}
}

add_action( 'wto_notify_dispatch', 'wto_notify_handle_dispatch', 10, 2 );
function wto_notify_handle_dispatch( $product_id, $variation_id = 0 ) {
	$product_id   = (int) $product_id;
	$variation_id = (int) $variation_id;
	if ( $product_id <= 0 ) {
		return;
	}
	// Final stock check — admin may have changed status back before the dispatch fired.
	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	$check_id = $variation_id > 0 ? $variation_id : $product_id;
	$product  = wc_get_product( $check_id );
	if ( ! $product || ! $product->is_in_stock() ) {
		return;
	}

	$settings = wto_notify_settings();
	$pattern  = trim( (string) $settings['pattern_code'] );
	if ( $pattern === '' || ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return;
	}

	global $wpdb;
	$table = wto_notify_table();

	// Fetch pending subscribers — chunked to 200 per dispatch.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, mobile FROM $table WHERE product_id = %d AND variation_id = %d AND status = %s LIMIT 200",
			$product_id,
			$variation_id,
			'pending'
		),
		ARRAY_A
	);
	if ( empty( $rows ) ) {
		return;
	}

	$sender   = get_option( 'wto_sender', '' );
	$attrs    = array(
		'product_name'  => $product->get_name(),
		'product_link'  => get_permalink( $product_id ),
		'product_price' => $product->get_price() ? wc_price( $product->get_price() ) : '',
		'sitename'      => get_bloginfo( 'name' ),
	);
	// Strip HTML from wc_price (uses span/markup) — patterns expect plain text.
	$attrs['product_price'] = trim( wp_strip_all_tags( $attrs['product_price'] ) );

	$now    = current_time( 'mysql' );
	$sent   = 0;
	$failed = 0;
	foreach ( $rows as $r ) {
		$result = wto_send_pattern_sms_raw( $r['mobile'], $pattern, $attrs, $sender );
		if ( $result === 'success' ) {
			$wpdb->update(
				$table,
				array( 'status' => 'notified', 'notified_at' => $now ),
				array( 'id' => (int) $r['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$sent++;
		} else {
			$failed++;
		}
	}

	// If there are still pending rows (>200), schedule another dispatch in 60s.
	$remaining = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE product_id = %d AND variation_id = %d AND status = %s",
		$product_id,
		$variation_id,
		'pending'
	) );
	if ( $remaining > 0 ) {
		wp_schedule_single_event( time() + 60, 'wto_notify_dispatch', array( $product_id, $variation_id ) );
	}
}

// ============================================================================
// Frontend: button on WC product page
// ============================================================================

add_action( 'woocommerce_single_product_summary', 'wto_notify_render_button', 31 );
function wto_notify_render_button() {
	$settings = wto_notify_settings();
	if ( $settings['enabled'] !== '1' ) {
		return;
	}
	global $product;
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return;
	}
	// Only show on out-of-stock simple/external/grouped products.
	// For variable products, the JS-driven WC variation handler shows the form per-variation.
	if ( $product->is_type( 'variable' ) ) {
		// Render hidden form — JS will reveal once user picks an unavailable variation.
		wto_notify_render_form_markup( $product, true );
		return;
	}
	if ( $product->is_in_stock() ) {
		return;
	}
	wto_notify_render_form_markup( $product, false );
}

/**
 * Render the button + inline expandable form. JS handles toggle + AJAX submit.
 *
 * @param WC_Product $product
 * @param bool       $hidden_initial  When true, the wrapper starts hidden (used for variable products).
 */
function wto_notify_render_form_markup( $product, $hidden_initial ) {
	$settings = wto_notify_settings();
	$nonce    = wp_create_nonce( 'wto_notify_subscribe' );
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>
	<div class="wto-notify-wrapper" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-success-text="<?php echo esc_attr( $settings['subscribed_text'] ); ?>"<?php if ( $hidden_initial ) echo ' style="display:none"'; ?>>
		<button type="button" class="wto-notify-button" aria-expanded="false">
			<span class="wto-notify-icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
			</span>
			<span class="wto-notify-button-text"><?php echo esc_html( $settings['button_text'] ); ?></span>
		</button>
		<div class="wto-notify-form" hidden>
			<h4 class="wto-notify-form-title"><?php echo esc_html( $settings['popup_title'] ); ?></h4>
			<p class="wto-notify-form-description"><?php echo esc_html( $settings['popup_description'] ); ?></p>
			<label class="wto-notify-field">
				<span><?php echo esc_html( $settings['mobile_label'] ); ?></span>
				<input type="tel" name="mobile" dir="ltr" inputmode="numeric" pattern="[0-9۰-۹]+" maxlength="15" required>
			</label>
			<input type="hidden" name="variation_id" value="0">
			<button type="button" class="wto-notify-submit"><?php echo esc_html( $settings['submit_text'] ); ?></button>
			<div class="wto-notify-message" role="status" aria-live="polite"></div>
		</div>
	</div>
	<?php
	// Render assets once per page-load.
	wto_notify_render_frontend_inline();
}

/**
 * Frontend CSS + JS (scoped under .wto-notify-wrapper).
 *
 * Called from render_form_markup. Uses static flag to ensure single output.
 */
function wto_notify_render_frontend_inline() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style>
	.wto-notify-wrapper { margin: 14px 0; font-family: inherit; direction: rtl; }
	.wto-notify-wrapper .wto-notify-button { display: inline-flex; align-items: center; gap: 8px; background: #6366f1; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s, transform .05s; }
	.wto-notify-wrapper .wto-notify-button:hover { background: #4f46e5; }
	.wto-notify-wrapper .wto-notify-button:active { transform: scale(0.98); }
	.wto-notify-wrapper .wto-notify-button.is-success { background: #047857; }
	.wto-notify-wrapper .wto-notify-icon svg { vertical-align: middle; }
	.wto-notify-wrapper .wto-notify-form { margin-top: 12px; padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; max-width: 420px; }
	.wto-notify-wrapper .wto-notify-form[hidden] { display: none !important; }
	.wto-notify-wrapper .wto-notify-form-title { margin: 0 0 6px; font-size: 15px; font-weight: 700; color: #1f2937; }
	.wto-notify-wrapper .wto-notify-form-description { margin: 0 0 12px; font-size: 13px; color: #4b5563; line-height: 1.7; }
	.wto-notify-wrapper .wto-notify-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
	.wto-notify-wrapper .wto-notify-field span { font-size: 12px; color: #374151; }
	.wto-notify-wrapper .wto-notify-field input { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
	.wto-notify-wrapper .wto-notify-field input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
	.wto-notify-wrapper .wto-notify-submit { background: #6366f1; color: #fff; border: 0; border-radius: 6px; padding: 9px 18px; font-size: 14px; font-weight: 600; cursor: pointer; }
	.wto-notify-wrapper .wto-notify-submit:hover { background: #4f46e5; }
	.wto-notify-wrapper .wto-notify-submit:disabled { opacity: 0.6; cursor: not-allowed; }
	.wto-notify-wrapper .wto-notify-message { margin-top: 8px; min-height: 18px; font-size: 13px; }
	.wto-notify-wrapper .wto-notify-message.success { color: #047857; }
	.wto-notify-wrapper .wto-notify-message.error { color: #b91c1c; }
	</style>
	<script>
	(function(){
		function persianToAscii(str){
			var p = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
			var a = ['0','1','2','3','4','5','6','7','8','9'];
			for (var i=0;i<p.length;i++){ str = str.split(p[i]).join(a[i]); }
			return str;
		}
		function bindWrapper(wrap){
			if (wrap._wtoBound) return; wrap._wtoBound = true;
			var btn  = wrap.querySelector('.wto-notify-button');
			var form = wrap.querySelector('.wto-notify-form');
			var sub  = wrap.querySelector('.wto-notify-submit');
			var msg  = wrap.querySelector('.wto-notify-message');
			var inp  = wrap.querySelector('input[name="mobile"]');
			var vinp = wrap.querySelector('input[name="variation_id"]');

			if (btn) btn.addEventListener('click', function(){
				var open = !form.hasAttribute('hidden');
				if (open) { form.setAttribute('hidden',''); btn.setAttribute('aria-expanded','false'); }
				else      { form.removeAttribute('hidden'); btn.setAttribute('aria-expanded','true'); inp && inp.focus(); }
			});

			if (sub) sub.addEventListener('click', function(){
				var mobile = persianToAscii((inp && inp.value) || '').replace(/\D+/g, '');
				if (!/^0?9\d{9}$/.test(mobile)) {
					msg.className = 'wto-notify-message error';
					msg.textContent = 'شماره موبایل معتبر نیست.';
					return;
				}
				var data = new FormData();
				data.append('action', 'wto_notify_subscribe');
				data.append('nonce', wrap.dataset.nonce);
				data.append('product_id', wrap.dataset.productId);
				data.append('variation_id', (vinp && vinp.value) || '0');
				data.append('mobile', mobile);
				sub.disabled = true;
				var oldText = sub.textContent;
				sub.textContent = 'در حال ثبت...';
				fetch(wrap.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function(r){ return r.json(); })
					.then(function(json){
						if (json.success) {
							msg.className = 'wto-notify-message success';
							msg.textContent = (json.data && json.data.message) || 'انجام شد.';
							// Replace the button with success state.
							var labelEl = btn.querySelector('.wto-notify-button-text');
							if (labelEl) labelEl.textContent = wrap.dataset.successText || 'ثبت شد ✓';
							btn.classList.add('is-success');
							btn.disabled = true;
							// Auto-close form after 2s.
							setTimeout(function(){ form.setAttribute('hidden',''); btn.setAttribute('aria-expanded','false'); }, 2000);
						} else {
							msg.className = 'wto-notify-message error';
							msg.textContent = (json.data && json.data.message) || 'خطا.';
						}
					})
					.catch(function(){
						msg.className = 'wto-notify-message error';
						msg.textContent = 'خطا در ارتباط با سرور.';
					})
					.then(function(){ sub.disabled = false; sub.textContent = oldText; });
			});
		}

		document.querySelectorAll('.wto-notify-wrapper').forEach(bindWrapper);

		// Variable product support — listen for WC variation change events.
		if (window.jQuery) {
			jQuery(function($){
				$('.variations_form').on('found_variation', function(ev, variation){
					var wrap = document.querySelector('.wto-notify-wrapper');
					if (!wrap) return;
					var vinp = wrap.querySelector('input[name="variation_id"]');
					if (vinp) vinp.value = variation.variation_id || 0;
					if (variation && variation.is_in_stock === false) {
						wrap.style.display = '';
					} else {
						wrap.style.display = 'none';
					}
				}).on('reset_data', function(){
					var wrap = document.querySelector('.wto-notify-wrapper');
					if (wrap) wrap.style.display = 'none';
				});
			});
		}
	})();
	</script>
	<?php
}

// ============================================================================
// Admin: bulk delete + save settings
// ============================================================================

add_action( 'wp_ajax_wto_notify_delete', 'wto_notify_ajax_delete' );
function wto_notify_ajax_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_notify_admin', 'nonce' );
	global $wpdb;
	$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
	$ids = array_filter( array_map( 'absint', $ids ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => __( 'هیچ ردیفی انتخاب نشده.', 'wto' ) ) );
	}
	$ids   = array_slice( $ids, 0, 500 );
	$table = wto_notify_table();
	$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$count = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($ph)", $ids ) );
	wp_send_json_success( array(
		'message' => sprintf( /* translators: %s deleted count */ __( '%s ردیف حذف شد.', 'wto' ), number_format_i18n( $count ) ),
		'deleted' => $count,
	) );
}

add_action( 'admin_post_wto_notify_save_settings', 'wto_notify_handle_save_settings' );
function wto_notify_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'دسترسی غیرمجاز.', 'wto' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_notify_settings' );

	$new      = wto_notify_settings();
	$fields   = array(
		'button_text', 'subscribed_text', 'popup_title', 'popup_description',
		'mobile_label', 'submit_text', 'success_message', 'duplicate_message',
		'error_message', 'pattern_code',
	);
	foreach ( $fields as $f ) {
		if ( isset( $_POST[ $f ] ) ) {
			$new[ $f ] = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
		}
	}
	$new['enabled']         = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? '1' : '0';
	$new['dispatch_delay']  = isset( $_POST['dispatch_delay'] ) ? max( 10, (int) $_POST['dispatch_delay'] ) : 60;

	update_option( 'wto_notify_settings', $new, false );

	wp_safe_redirect( add_query_arg( array(
		'page'    => 'farazwto-notify',
		'updated' => '1',
	), admin_url( 'admin.php' ) ) );
	exit;
}

// ============================================================================
// Admin page render
// ============================================================================

function wto_render_notify_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی غیرمجاز.', 'wto' ) );
	}
	wto_notify_maybe_setup_table();

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'list';
	$tab = in_array( $tab, array( 'list', 'settings' ), true ) ? $tab : 'list';

	echo '<section class="wrapper wto-notify-admin-wrapper">';
	wto_notify_render_admin_header();
	wto_notify_render_admin_tabs( $tab );
	if ( $tab === 'settings' ) {
		wto_notify_render_settings_tab();
	} else {
		wto_notify_render_list_tab();
	}
	wto_notify_render_admin_inline();
	echo '</section>';
}

function wto_notify_render_admin_header() {
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
	<h1 class="wto-notify-title-main"><?php esc_html_e( 'موجود شد خبرم کن', 'wto' ); ?></h1>
	<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'این قابلیت نیازمند ووکامرس است. لطفاً ابتدا ووکامرس را نصب و فعال کنید.', 'wto' ); ?></p></div>
	<?php endif; ?>
	<?php
}

function wto_notify_render_admin_tabs( $active ) {
	$tabs = array(
		'list'     => __( 'لیست مشترکین', 'wto' ),
		'settings' => __( 'تنظیمات', 'wto' ),
	);
	?>
	<nav class="wto-notify-tabs">
		<?php foreach ( $tabs as $key => $label ) :
			$url = add_query_arg( array( 'page' => 'farazwto-notify', 'tab' => $key ), admin_url( 'admin.php' ) );
		?>
			<a class="wto-notify-tab <?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
}

function wto_notify_render_list_tab() {
	global $wpdb;
	$table  = wto_notify_table();
	$counts = wto_notify_get_counts();

	$page   = isset( $_GET['paged'] )  ? max( 1, (int) $_GET['paged'] )  : 1;
	$limit  = isset( $_GET['limit'] )  ? max( 10, min( 200, (int) $_GET['limit'] ) ) : 25;
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$where  = ' WHERE 1=1 ';
	$params = array();
	if ( in_array( $status, array( 'pending', 'notified' ), true ) ) {
		$where   .= ' AND status = %s';
		$params[] = $status;
	}
	if ( $search !== '' ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where   .= ' AND mobile LIKE %s';
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

	$updated = isset( $_GET['updated'] ) ? sanitize_key( $_GET['updated'] ) : '';
	?>
	<?php if ( $updated === '1' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'wto' ); ?></p></div>
	<?php endif; ?>

	<div class="wto-notify-stats">
		<div class="wto-notify-stat"><div class="num"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></div><div class="lbl"><?php esc_html_e( 'مجموع درخواست‌ها', 'wto' ); ?></div></div>
		<div class="wto-notify-stat"><div class="num wto-num-warning"><?php echo esc_html( number_format_i18n( $counts['pending'] ) ); ?></div><div class="lbl"><?php esc_html_e( 'در انتظار', 'wto' ); ?></div></div>
		<div class="wto-notify-stat"><div class="num wto-num-success"><?php echo esc_html( number_format_i18n( $counts['notified'] ) ); ?></div><div class="lbl"><?php esc_html_e( 'اطلاع‌رسانی شده', 'wto' ); ?></div></div>
		<div class="wto-notify-stat"><div class="num wto-num-info"><?php echo esc_html( number_format_i18n( $counts['products'] ) ); ?></div><div class="lbl"><?php esc_html_e( 'محصول در انتظار', 'wto' ); ?></div></div>
	</div>

	<form method="get" class="wto-notify-filters">
		<input type="hidden" name="page" value="farazwto-notify">
		<label>
			<span><?php esc_html_e( 'جستجو موبایل:', 'wto' ); ?></span>
			<input type="text" name="search" value="<?php echo esc_attr( $search ); ?>">
		</label>
		<label>
			<span><?php esc_html_e( 'وضعیت:', 'wto' ); ?></span>
			<select name="status">
				<option value=""<?php selected( $status, '' ); ?>><?php esc_html_e( 'همه', 'wto' ); ?></option>
				<option value="pending"<?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'در انتظار', 'wto' ); ?></option>
				<option value="notified"<?php selected( $status, 'notified' ); ?>><?php esc_html_e( 'اطلاع‌رسانی شده', 'wto' ); ?></option>
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
		<button type="submit" class="button button-primary"><?php esc_html_e( 'اعمال فیلتر', 'wto' ); ?></button>
	</form>

	<form id="wto-notify-bulk-form">
		<?php wp_nonce_field( 'wto_notify_admin', 'wto_notify_admin_nonce' ); ?>
		<div class="wto-notify-bulk-bar">
			<button type="button" class="button wto-notify-bulk-delete" disabled><?php esc_html_e( 'حذف انتخاب‌شده‌ها', 'wto' ); ?></button>
			<span class="wto-notify-bulk-info"></span>
		</div>
		<table class="widefat striped wto-notify-table">
			<thead>
				<tr>
					<th class="check-column"><input type="checkbox" id="wto-notify-select-all"></th>
					<th><?php esc_html_e( 'شناسه', 'wto' ); ?></th>
					<th><?php esc_html_e( 'محصول', 'wto' ); ?></th>
					<th><?php esc_html_e( 'موبایل', 'wto' ); ?></th>
					<th><?php esc_html_e( 'وضعیت', 'wto' ); ?></th>
					<th><?php esc_html_e( 'تاریخ ثبت', 'wto' ); ?></th>
					<th><?php esc_html_e( 'اطلاع‌رسانی', 'wto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'هیچ درخواستی یافت نشد.', 'wto' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$prod_title = '';
					$prod_url   = '';
					if ( function_exists( 'wc_get_product' ) ) {
						$p = wc_get_product( (int) $r['product_id'] );
						if ( $p ) {
							$prod_title = $p->get_name();
							$prod_url   = get_edit_post_link( (int) $r['product_id'] );
						}
					}
					if ( $prod_title === '' ) {
						$prod_title = sprintf( '#%d', (int) $r['product_id'] );
					}
					if ( (int) $r['variation_id'] > 0 ) {
						$prod_title .= sprintf( ' (متغیر: %d)', (int) $r['variation_id'] );
					}
					$jdate_sub = function_exists( 'wto_send_reports_to_jalali' ) ? wto_send_reports_to_jalali( $r['subscribed_at'] ) : $r['subscribed_at'];
					$jdate_not = $r['notified_at'] && function_exists( 'wto_send_reports_to_jalali' ) ? wto_send_reports_to_jalali( $r['notified_at'] ) : ( $r['notified_at'] ? $r['notified_at'] : '—' );
					$status_class = $r['status'] === 'notified' ? 'success' : 'warning';
					$status_text  = $r['status'] === 'notified' ? __( 'ارسال شده', 'wto' ) : __( 'در انتظار', 'wto' );
				?>
					<tr>
						<td><input type="checkbox" class="wto-notify-row" value="<?php echo esc_attr( $r['id'] ); ?>"></td>
						<td><?php echo esc_html( $r['id'] ); ?></td>
						<td>
							<?php if ( $prod_url ) : ?>
								<a href="<?php echo esc_url( $prod_url ); ?>" target="_blank"><?php echo esc_html( $prod_title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $prod_title ); ?>
							<?php endif; ?>
						</td>
						<td dir="ltr"><?php echo esc_html( $r['mobile'] ); ?></td>
						<td><span class="wto-status wto-status-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span></td>
						<td class="wto-notify-date-cell"><?php echo esc_html( $jdate_sub ); ?></td>
						<td class="wto-notify-date-cell"><?php echo esc_html( $jdate_not ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</form>

	<?php if ( $total_pages > 1 ) :
		$base = add_query_arg( array(
			'page'   => 'farazwto-notify',
			'status' => $status,
			'search' => $search,
			'limit'  => $limit,
		), admin_url( 'admin.php' ) );
		?>
		<div class="wto-notify-pagination">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1, $base ) ); ?>">« <?php esc_html_e( 'صفحه قبل', 'wto' ); ?></a>
			<?php endif; ?>
			<span class="wto-notify-page-info">
				<?php printf( esc_html__( 'صفحه %1$s از %2$s', 'wto' ), esc_html( number_format_i18n( $page ) ), esc_html( number_format_i18n( $total_pages ) ) ); ?>
			</span>
			<?php if ( $page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $base ) ); ?>"><?php esc_html_e( 'صفحه بعد', 'wto' ); ?> »</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php
}

function wto_notify_render_settings_tab() {
	$s = wto_notify_settings();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wto-notify-card">
		<input type="hidden" name="action" value="wto_notify_save_settings">
		<?php wp_nonce_field( 'wto_notify_settings' ); ?>

		<h2><?php esc_html_e( 'تنظیمات «موجود شد خبرم کن»', 'wto' ); ?></h2>

		<div class="wto-notify-settings-grid">
			<label class="wto-notify-setting wto-setting-wide">
				<span><?php esc_html_e( 'فعال‌سازی', 'wto' ); ?></span>
				<label class="wto-notify-switch">
					<input type="checkbox" class="wto-toggle" name="enabled" value="1" <?php checked( $s['enabled'], '1' ); ?>>
					<span><?php esc_html_e( 'فعال — دکمه روی صفحه محصولات ناموجود ووکامرس نمایش داده می‌شود.', 'wto' ); ?></span>
				</label>
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'متن دکمه', 'wto' ); ?></span>
				<input type="text" name="button_text" value="<?php echo esc_attr( $s['button_text'] ); ?>">
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'متن دکمه بعد از ثبت', 'wto' ); ?></span>
				<input type="text" name="subscribed_text" value="<?php echo esc_attr( $s['subscribed_text'] ); ?>">
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'عنوان فرم', 'wto' ); ?></span>
				<input type="text" name="popup_title" value="<?php echo esc_attr( $s['popup_title'] ); ?>">
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'برچسب فیلد موبایل', 'wto' ); ?></span>
				<input type="text" name="mobile_label" value="<?php echo esc_attr( $s['mobile_label'] ); ?>">
			</label>

			<label class="wto-notify-setting wto-setting-wide">
				<span><?php esc_html_e( 'توضیح فرم', 'wto' ); ?></span>
				<input type="text" name="popup_description" value="<?php echo esc_attr( $s['popup_description'] ); ?>">
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'متن دکمه ثبت', 'wto' ); ?></span>
				<input type="text" name="submit_text" value="<?php echo esc_attr( $s['submit_text'] ); ?>">
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'پیام موفقیت', 'wto' ); ?></span>
				<input type="text" name="success_message" value="<?php echo esc_attr( $s['success_message'] ); ?>">
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'پیام تکراری', 'wto' ); ?></span>
				<input type="text" name="duplicate_message" value="<?php echo esc_attr( $s['duplicate_message'] ); ?>">
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'پیام خطا', 'wto' ); ?></span>
				<input type="text" name="error_message" value="<?php echo esc_attr( $s['error_message'] ); ?>">
			</label>

			<label class="wto-notify-setting wto-setting-wide">
				<span><?php esc_html_e( 'کد الگوی اطلاع‌رسانی *', 'wto' ); ?></span>
				<input type="text" name="pattern_code" value="<?php echo esc_attr( $s['pattern_code'] ); ?>" dir="ltr">
				<small class="wto-notify-help">
					<?php esc_html_e( 'الزامی — متغیرهای قابل استفاده در متن الگو:', 'wto' ); ?>
					<code dir="ltr">%product_name%</code>
					<code dir="ltr">%product_link%</code>
					<code dir="ltr">%product_price%</code>
					<br><?php esc_html_e( '⚠ نام برند فروشگاه را به‌صورت ثابت در متن الگو بنویسید (نه به‌صورت متغیر) — این برای تأیید الگو در پنل فراز ضروری است.', 'wto' ); ?>
				</small>
			</label>

			<label class="wto-notify-setting">
				<span><?php esc_html_e( 'تأخیر ارسال پس از موجود شدن (ثانیه)', 'wto' ); ?></span>
				<input type="number" name="dispatch_delay" value="<?php echo esc_attr( $s['dispatch_delay'] ); ?>" min="10" max="3600" dir="ltr">
				<small class="wto-notify-help"><?php esc_html_e( 'حداقل ۱۰ ثانیه. مقدار پیش‌فرض ۶۰ ثانیه برای فرصت undo توسط مدیر.', 'wto' ); ?></small>
			</label>
		</div>

		<div class="wto-notify-save-row">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تنظیمات', 'wto' ); ?></button>
		</div>
	</form>
	<?php
}

function wto_notify_render_admin_inline() {
	$nonce = wp_create_nonce( 'wto_notify_admin' );
	?>
	<style>
	.wto-notify-admin-wrapper .wto-notify-title-main { margin: 16px 0 8px; }
	.wto-notify-admin-wrapper .wto-notify-tabs { display: flex; gap: 4px; border-bottom: 1px solid #c3c4c7; margin: 16px 0 20px; }
	.wto-notify-admin-wrapper .wto-notify-tab { padding: 10px 18px; text-decoration: none; color: #50575e; background: #f1f1f1; border: 1px solid #c3c4c7; border-bottom: 0; border-radius: 6px 6px 0 0; margin-bottom: -1px; font-size: 13px; }
	.wto-notify-admin-wrapper .wto-notify-tab.is-active { background: #fff; color: #1d2327; font-weight: 600; }
	.wto-notify-admin-wrapper .wto-notify-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 0 0 20px; }
	.wto-notify-admin-wrapper .wto-notify-stat { background: #fff; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; text-align: center; }
	.wto-notify-admin-wrapper .wto-notify-stat .num { font-size: 26px; font-weight: 700; line-height: 1; color: #1f2937; }
	.wto-notify-admin-wrapper .wto-notify-stat .num.wto-num-success { color: #047857; }
	.wto-notify-admin-wrapper .wto-notify-stat .num.wto-num-warning { color: #b45309; }
	.wto-notify-admin-wrapper .wto-notify-stat .num.wto-num-info    { color: #1d4ed8; }
	.wto-notify-admin-wrapper .wto-notify-stat .lbl { color: #6b7280; font-size: 12px; margin-top: 6px; }
	.wto-notify-admin-wrapper .wto-notify-filters { background: #fff; padding: 14px 16px; border: 1px solid #dcdcde; border-radius: 8px; margin: 0 0 14px; display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
	.wto-notify-admin-wrapper .wto-notify-filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
	.wto-notify-admin-wrapper .wto-notify-bulk-bar { margin: 0 0 10px; display: flex; align-items: center; gap: 10px; }
	.wto-notify-admin-wrapper .wto-notify-bulk-info { color: #50575e; font-size: 13px; }
	.wto-notify-admin-wrapper .wto-notify-table th,
	.wto-notify-admin-wrapper .wto-notify-table td { padding: 10px 12px; vertical-align: middle; }
	.wto-notify-admin-wrapper .wto-notify-date-cell { direction: ltr; font-variant-numeric: tabular-nums; }
	.wto-notify-admin-wrapper .wto-notify-pagination { display: flex; gap: 8px; align-items: center; }
	.wto-notify-admin-wrapper .wto-notify-page-info { color: #50575e; }
	.wto-notify-admin-wrapper .wto-status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
	.wto-notify-admin-wrapper .wto-status-success { background: #d1f5e0; color: #006d28; }
	.wto-notify-admin-wrapper .wto-status-warning { background: #fef3c7; color: #92400e; }
	.wto-notify-admin-wrapper .wto-notify-card { background: #fff; padding: 20px 24px; border: 1px solid #e5e7eb; border-radius: 10px; max-width: 760px; }
	.wto-notify-admin-wrapper .wto-notify-card h2 { margin: 0 0 12px; font-size: 16px; }
	.wto-notify-admin-wrapper .wto-notify-settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; margin: 12px 0 20px; }
	.wto-notify-admin-wrapper .wto-notify-setting { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
	.wto-notify-admin-wrapper .wto-notify-setting.wto-setting-wide { grid-column: 1 / -1; }
	.wto-notify-admin-wrapper .wto-notify-setting input { padding: 8px 10px; }
	.wto-notify-admin-wrapper .wto-notify-help { color: #6b7280; font-size: 11px; }
	.wto-notify-admin-wrapper .wto-notify-help code { background: #f3f4f6; padding: 1px 5px; border-radius: 3px; margin: 0 2px; }
	.wto-notify-admin-wrapper .wto-notify-switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
	.wto-notify-admin-wrapper .wto-notify-save-row { margin-top: 10px; }
	@media (max-width: 720px) {
		.wto-notify-admin-wrapper .wto-notify-stats { grid-template-columns: repeat(2, 1fr); }
		.wto-notify-admin-wrapper .wto-notify-settings-grid { grid-template-columns: 1fr; }
	}
	</style>
	<script>
	(function(){
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
		var selAll  = document.getElementById('wto-notify-select-all');
		var rows    = document.querySelectorAll('.wto-notify-row');
		var delBtn  = document.querySelector('.wto-notify-bulk-delete');
		var info    = document.querySelector('.wto-notify-bulk-info');
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
			fd.append('action', 'wto_notify_delete');
			fd.append('nonce', nonce);
			c.forEach(function(x){ fd.append('ids[]', x.value); });
			delBtn.disabled = true;
			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(json){
					if (json.success) window.location.reload();
					else { alert((json.data && json.data.message) || 'خطا.'); delBtn.disabled = false; }
				})
				.catch(function(){ alert('خطا در ارتباط با سرور.'); delBtn.disabled = false; });
		});
	})();
	</script>
	<?php
}
