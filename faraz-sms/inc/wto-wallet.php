<?php
/**
 * کیف‌پولِ بومیِ مشتری — مستقل از هر افزونه‌ی دیگر (از جمله farazsms-login).
 *
 * قابلیت‌ها:
 *   - اعتبار با انقضای اختیاری (مدت‌زمانِ دلخواه مدیر)
 *   - هدیه‌ی عضویت: هنگام ثبت‌نام هر کاربر، مبلغی به کیف‌پول او اضافه می‌شود
 *     (منبع مبلغ: تنظیمات «لید مگنت» در صورت فعال بودن، وگرنه تنظیمات کیف‌پول)
 *   - مصرف هنگام تسویه‌حساب: کسر از کیف‌پول و پرداخت مابقی توسط کاربر
 *
 * این ماژول هیچ وابستگی‌ای به افزونه‌ی ورود/ثبت‌نام ندارد.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// ============================================================================
// جدول و نصب
// ============================================================================

function wto_wallet_table() {
	global $wpdb;
	return $wpdb->prefix . 'wto_wallet';
}

/**
 * تضمینِ وجودِ جدولِ کیف‌پول — مقاوم.
 * به پرچمِ option تنها اعتماد نمی‌کنیم؛ وجودِ واقعیِ جدول تأیید می‌شود (روی بعضی سایت‌ها
 * پرچم '1' می‌شود اما جدول ساخته نشده/پاک شده و کیف‌پول بی‌صدا می‌شکند). نتیجه‌ی مثبت
 * ۱۲ ساعت کش می‌شود تا SHOW TABLES در هر request اجرا نشود.
 */
function wto_wallet_maybe_install() {
	global $wpdb;
	$table = wto_wallet_table();

	if ( get_option( 'wto_wallet_db_installed' ) === '1'
		&& get_transient( 'wto_wallet_db_verified' ) === '1' ) {
		return;
	}

	$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

	if ( ! $exists ) {
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			used_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			source VARCHAR(40) NOT NULL DEFAULT '',
			description TEXT NULL,
			order_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY user_status_expires (user_id, status, expires_at),
			KEY order_id (order_id)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	}

	if ( $exists ) {
		update_option( 'wto_wallet_db_installed', '1', false );
		set_transient( 'wto_wallet_db_verified', '1', 12 * HOUR_IN_SECONDS );
	} else {
		delete_option( 'wto_wallet_db_installed' );
	}
}
// روی init تا هم در admin و هم در frontend (ثبت‌نام/تسویه) جدول تضمین شود؛ به‌خاطرِ
// کشِ transient، مسیرِ داغ فقط یک get_transient است.
add_action( 'init', 'wto_wallet_maybe_install', 8 );

// ============================================================================
// تنظیمات
// ============================================================================

function wto_wallet_get_settings() {
	$defaults = array(
		'reg_bonus_enabled'     => '0',
		'reg_bonus_amount'      => '0',
		'reg_bonus_expiry_days' => '0', // 0 = بدون انقضا
		'checkout_enabled'      => '1',
	);
	$s = get_option( 'wto_wallet_settings', array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	return array_merge( $defaults, $s );
}

// ============================================================================
// Core API — balance / credit / deduct
// ============================================================================

/**
 * موجودی فعلی کیف‌پول کاربر (فقط اعتبار فعال و منقضی‌نشده).
 */
function wto_wallet_balance( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0.0;
	}
	global $wpdb;
	$table = wto_wallet_table();
	$now   = current_time( 'mysql' );
	$bal   = $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE( SUM( amount - used_amount ), 0 ) FROM $table
		 WHERE user_id = %d AND status = 'active' AND ( expires_at IS NULL OR expires_at > %s )",
		$user_id,
		$now
	) );
	return (float) $bal;
}

/**
 * افزودن اعتبار به کیف‌پول.
 *
 * @param int   $user_id
 * @param float $amount
 * @param array $args description, expiry_days (0=بدون انقضا), source, order_id
 * @return int|false شناسه‌ی رکورد یا false
 */
function wto_wallet_add_credit( $user_id, $amount, $args = array() ) {
	wto_wallet_maybe_install();
	$user_id = (int) $user_id;
	$amount  = round( (float) $amount, 2 );
	if ( $user_id <= 0 || $amount <= 0 ) {
		return false;
	}
	$now         = current_time( 'mysql' );
	$expiry_days = isset( $args['expiry_days'] ) ? (int) $args['expiry_days'] : 0;
	$expires_at  = $expiry_days > 0 ? date( 'Y-m-d H:i:s', strtotime( $now . ' +' . $expiry_days . ' days' ) ) : null;

	global $wpdb;
	$ok = $wpdb->insert(
		wto_wallet_table(),
		array(
			'user_id'     => $user_id,
			'amount'      => $amount,
			'used_amount' => 0,
			'status'      => 'active',
			'source'      => isset( $args['source'] ) ? (string) $args['source'] : '',
			'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
			'order_id'    => isset( $args['order_id'] ) ? (int) $args['order_id'] : null,
			'created_at'  => $now,
			'expires_at'  => $expires_at,
		),
		array( '%d', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%s' )
	);
	return $ok ? (int) $wpdb->insert_id : false;
}

/**
 * کسر از کیف‌پول — FIFO (نزدیک‌ترین انقضا اول، تا اعتبار کاربر نسوزد).
 *
 * @return float مبلغِ واقعاً کسرشده
 */
function wto_wallet_deduct( $user_id, $amount, $order_id = 0, $desc = '' ) {
	$user_id = (int) $user_id;
	$amount  = round( (float) $amount, 2 );
	if ( $user_id <= 0 || $amount <= 0 ) {
		return 0.0;
	}
	global $wpdb;
	$table = wto_wallet_table();
	$now   = current_time( 'mysql' );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, amount, used_amount FROM $table
		 WHERE user_id = %d AND status = 'active' AND ( expires_at IS NULL OR expires_at > %s )
		 ORDER BY ( expires_at IS NULL ) ASC, expires_at ASC, id ASC",
		$user_id,
		$now
	), ARRAY_A );

	$deducted = 0.0;
	foreach ( $rows as $row ) {
		if ( $amount <= 0 ) {
			break;
		}
		$remaining = (float) $row['amount'] - (float) $row['used_amount'];
		if ( $remaining <= 0 ) {
			continue;
		}
		$take       = min( $remaining, $amount );
		$new_used   = (float) $row['used_amount'] + $take;
		$new_status = ( $new_used >= (float) $row['amount'] ) ? 'used' : 'active';
		$wpdb->update(
			$table,
			array( 'used_amount' => $new_used, 'status' => $new_status ),
			array( 'id' => (int) $row['id'] ),
			array( '%f', '%s' ),
			array( '%d' )
		);
		$deducted += $take;
		$amount   -= $take;
	}
	return $deducted;
}

// ============================================================================
// هدیه‌ی عضویت — هنگام ثبت‌نام کاربر
// ============================================================================

/**
 * پیکربندیِ مبلغِ هدیه‌ی عضویت — منبعِ واحدِ حقیقت: تنظیماتِ «لید مگنت».
 * لید مگنت ماژولی است که بازدیدکننده را به کاربر تبدیل می‌کند و مبلغی که فروشنده
 * تعیین کرده به کیف‌پولِ او اضافه می‌شود تا اولین خریدش را ارزان‌تر انجام دهد.
 * بنابراین «هدیه‌ی عضویت» همان اعتبارِ لید مگنت است و از همان تنظیمات می‌خواند:
 *
 *   farazsms_next_get_lead_magnet_settings()  → enabled, credit_amount, expiry_days
 *
 * @return array|null amount, expiry_days, source
 */
function wto_wallet_registration_gift_config() {
	if ( ! function_exists( 'farazsms_next_get_lead_magnet_settings' ) ) {
		return null;
	}
	$s       = farazsms_next_get_lead_magnet_settings();
	$enabled = ! isset( $s['enabled'] ) || $s['enabled'] === '1' || $s['enabled'] === 1 || $s['enabled'] === true;
	$amount  = isset( $s['credit_amount'] ) ? (float) $s['credit_amount'] : 0;
	if ( $enabled && $amount > 0 ) {
		return array(
			'amount'      => $amount,
			'expiry_days' => isset( $s['expiry_days'] ) ? (int) $s['expiry_days'] : 0,
			'source'      => 'lead_magnet',
		);
	}
	return null;
}

add_action( 'user_register', 'wto_wallet_grant_registration_gift', 10, 1 );
function wto_wallet_grant_registration_gift( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	// idempotent — برای هر کاربر فقط یک‌بار.
	if ( get_user_meta( $user_id, '_wto_wallet_gift_received', true ) ) {
		return;
	}
	$cfg = wto_wallet_registration_gift_config();
	if ( ! $cfg ) {
		return;
	}
	$credit_id = wto_wallet_add_credit( $user_id, $cfg['amount'], array(
		'source'      => $cfg['source'],
		'expiry_days' => $cfg['expiry_days'],
		'description' => __( 'هدیه عضویت', 'wto' ),
	) );
	if ( $credit_id ) {
		update_user_meta( $user_id, '_wto_wallet_gift_received', '1' );
	}
}

// ============================================================================
// مصرف هنگام تسویه‌حساب (WooCommerce — checkout کلاسیک)
// ============================================================================

function wto_wallet_register_wc_hooks() {
	$s = wto_wallet_get_settings();
	if ( $s['checkout_enabled'] !== '1' ) {
		return;
	}
	if ( ! function_exists( 'WC' ) ) {
		return;
	}
	add_action( 'woocommerce_cart_calculate_fees', 'wto_wallet_apply_fee', 25 );
	add_action( 'woocommerce_review_order_before_payment', 'wto_wallet_render_checkbox' );
	add_action( 'woocommerce_checkout_update_order_review', 'wto_wallet_persist_choice' );
	add_action( 'woocommerce_checkout_order_processed', 'wto_wallet_finalize', 21, 3 );
	add_action( 'woocommerce_account_dashboard', 'wto_wallet_my_account_balance' );
}
add_action( 'init', 'wto_wallet_register_wc_hooks', 21 );

/**
 * نام fee کیف‌پول — منبع واحد.
 */
function wto_wallet_fee_label() {
	return __( 'پرداخت از کیف پول', 'wto' );
}

function wto_wallet_apply_fee() {
	if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}
	if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$use = WC()->session ? WC()->session->get( 'wto_use_wallet', '1' ) : '1';
	if ( $use !== '1' ) {
		return;
	}
	$balance = wto_wallet_balance( get_current_user_id() );
	if ( $balance <= 0 ) {
		return;
	}
	// مبلغ قابل پرداخت = subtotal منهای fee های منفیِ قبلاً اعمال‌شده (مثل کش‌بک)
	$subtotal = (float) WC()->cart->get_subtotal();
	$already  = 0.0;
	foreach ( WC()->cart->get_fees() as $f ) {
		if ( (float) $f->amount < 0 ) {
			$already += abs( (float) $f->amount );
		}
	}
	$payable = max( 0, $subtotal - $already );
	$apply   = min( $balance, $payable );
	if ( $apply > 0 ) {
		WC()->cart->add_fee( wto_wallet_fee_label(), -$apply, false );
	}
}

function wto_wallet_render_checkbox() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$balance = wto_wallet_balance( get_current_user_id() );
	if ( $balance <= 0 ) {
		return;
	}
	$use = WC()->session ? WC()->session->get( 'wto_use_wallet', '1' ) : '1';
	?>
	<div style="background:#eef2ff; border:1px solid #c7d2fe; padding:14px 16px; border-radius:10px; margin:14px 0; direction:rtl;">
		<label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0;">
			<input type="checkbox" name="wto_use_wallet" value="1" <?php checked( $use === '1' ); ?> onchange="if(window.jQuery){jQuery('body').trigger('update_checkout');}" style="margin:0; width:18px; height:18px;">
			<span style="font-size:14px; font-weight:600; color:#3730a3;">
				👛 استفاده از اعتبار کیف پول:
				<strong style="color:#4338ca;"><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong>
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

function wto_wallet_persist_choice( $post_data ) {
	parse_str( (string) $post_data, $data );
	if ( ! WC()->session ) {
		return;
	}
	$use = isset( $data['wto_use_wallet'] ) && $data['wto_use_wallet'] === '1' ? '1' : '0';
	WC()->session->set( 'wto_use_wallet', $use );
}

function wto_wallet_finalize( $order_id, $posted_data, $order ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
	}
	$user_id = (int) $order->get_user_id();
	if ( $user_id <= 0 ) {
		return;
	}
	// idempotent
	if ( $order->get_meta( '_wto_wallet_used_amount' ) !== '' && (float) $order->get_meta( '_wto_wallet_used_amount' ) > 0 ) {
		return;
	}
	$applied = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( $fee->get_name() === wto_wallet_fee_label() ) {
			$amt = abs( (float) $fee->get_amount() );
			if ( $amt > 0 ) {
				$applied += $amt;
			}
		}
	}
	if ( $applied <= 0 ) {
		return;
	}
	$deducted = wto_wallet_deduct( $user_id, $applied, $order_id, 'checkout' );
	$order->update_meta_data( '_wto_wallet_used_amount', $deducted );
	$order->save();
}

/**
 * نمایش موجودی در داشبورد «حساب من».
 */
function wto_wallet_my_account_balance() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$balance = wto_wallet_balance( get_current_user_id() );
	// فقط وقتی کاربر واقعاً اعتبار دارد نمایش بده تا پنلِ کاربرانِ بدونِ اعتبار شلوغ نشود.
	if ( $balance <= 0 ) {
		return;
	}
	echo '<div style="background:#f8fafc; padding:14px 16px; margin:16px 0; border:1px solid #e2e8f0; border-radius:10px;">';
	echo '<strong>👛 ' . esc_html__( 'اعتبار کیف پول شما', 'wto' ) . ': </strong>';
	echo esc_html( number_format_i18n( $balance ) );
	if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
		echo ' ' . esc_html( get_woocommerce_currency_symbol() );
	}
	echo '</div>';
}
