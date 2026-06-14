<?php
/**
 * یکپارچگی با افزونه‌ی «اسپات پلیر» — بدونِ دستکاریِ آن.
 *
 * وقتی هم اسپات‌پلیر و هم فراز اس ام اس نصب باشند، این ماژول:
 *   ۱) زیرمنوی «تنظیمات پیامک فراز اس ام اس» را زیرِ منوی اسپات‌پلیر تزریق می‌کند.
 *   ۲) کاربر متنِ دلخواهِ پترن را می‌نویسد (با متغیرها) و «ساخت پترن» می‌زند.
 *      متغیرِ %code% (کد لایسنس) الزامی است؛ بقیه اختیاری‌اند.
 *   ۳) موقعِ ساخته‌شدنِ لایسنس روی سفارش، پیامک با همان متن/پترن برای خریدار می‌رود.
 *
 * کلید دسترسی و خطِ ارسال از تنظیماتِ اصلیِ افزونه‌ی فراز خوانده می‌شود.
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_SPOT_OPTION = 'wto_spotplayer_settings';

/**
 * متغیرهای قابل‌استفاده در پترنِ اسپات‌پلیر — کلید => برچسبِ فارسی.
 * code الزامی است؛ بقیه اختیاری.
 *
 * @return array
 */
function wto_spot_available_vars() {
	return array(
		'code'              => 'کد لایسنس (الزامی)',
		'customer_fullname' => 'نام و نام خانوادگی',
		'b_first_name'      => 'نام',
		'b_last_name'       => 'نام خانوادگی',
		'order_id'          => 'شماره سفارش',
		'all_items'         => 'نام محصول/دوره',
	);
}

function wto_spot_default_message() {
	$domain = preg_replace( '#^www\.#i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	return "سلام مشتری محترم،\nکد لایسنس شما %code% می‌باشد.\n" . $domain;
}

/**
 * آیا افزونه‌ی اسپات‌پلیر فعال است؟
 *
 * @return bool
 */
function wto_spot_is_active() {
	return class_exists( 'spot_sms_melipayamak' ) || function_exists( 'spot_woo_shop_order' );
}

/**
 * تنظیماتِ یکپارچگیِ ما.
 *
 * @return array active, pattern, message
 */
function wto_spot_get_settings() {
	$s = get_option( WTO_SPOT_OPTION, array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	return array(
		'active'  => isset( $s['active'] ) ? $s['active'] : '0',
		'pattern' => isset( $s['pattern'] ) ? (string) $s['pattern'] : '',
		'message' => isset( $s['message'] ) && $s['message'] !== '' ? (string) $s['message'] : wto_spot_default_message(),
	);
}

function wto_spot_gateway_active() {
	$s = wto_spot_get_settings();
	return $s['active'] === '1' && trim( $s['pattern'] ) !== '';
}

/**
 * استخراجِ نام‌های متغیرِ موجود در متن (هر دو فرمتِ %نام% و {نام}).
 *
 * @param string $message
 * @return array
 */
function wto_spot_extract_var_names( $message ) {
	$names = array();
	if ( preg_match_all( '/%([a-zA-Z0-9_]+)%/', $message, $m ) ) {
		$names = array_merge( $names, $m[1] );
	}
	if ( preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $message, $m ) ) {
		$names = array_merge( $names, $m[1] );
	}
	return array_values( array_unique( $names ) );
}

// ============================================================================
// زیرمنو زیرِ منوی اسپات‌پلیر
// ============================================================================

add_action( 'admin_menu', 'wto_spot_register_submenu', 99 );
function wto_spot_register_submenu() {
	if ( ! wto_spot_is_active() ) {
		return;
	}
	add_submenu_page(
		'spotplayer',
		'تنظیمات پیامک فراز اس ام اس',
		'تنظیمات پیامک فراز اس ام اس',
		'manage_options',
		'wto-spotplayer-sms',
		'wto_spot_render_settings_page'
	);
}

function wto_spot_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$faraz_ready = function_exists( 'farazsms_is_ready' );
	$s           = wto_spot_get_settings();

	if ( isset( $_POST['wto_spot_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wto_spot_nonce'] ) ), 'wto_spot_save' ) ) {

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		if ( trim( $message ) !== '' ) {
			$s['message'] = $message;
		}

		// ساختِ پترن از متنِ نوشته‌شده‌ی کاربر
		if ( isset( $_POST['wto_spot_build_pattern'] ) ) {
			$has_code = ( strpos( $s['message'], '%code%' ) !== false ) || ( strpos( $s['message'], '{code}' ) !== false );
			if ( ! $faraz_ready || ! function_exists( 'wto_create_pattern' ) ) {
				echo '<div class="notice notice-error"><p>ابتدا کلید دسترسی را در تنظیماتِ اصلیِ افزونه‌ی فراز اس ام اس وارد کنید.</p></div>';
			} elseif ( ! $has_code ) {
				echo '<div class="notice notice-error"><p>متغیرِ <code>%code%</code> (کد لایسنس) الزامی است — حتماً در متن قرارش دهید.</p></div>';
			} else {
				$desc = function_exists( 'wto_pattern_brand_description' ) ? wto_pattern_brand_description( 'buyer' ) : 'افزونه فراز اس ام اس / اسپات‌پلیر';
				$resp = wto_create_pattern( $s['message'], 1, $desc );
				$data = is_string( $resp ) ? json_decode( $resp, true ) : $resp;
				$code = '';
				if ( is_array( $data ) ) {
					if ( ! empty( $data['data']['code'] ) ) {
						$code = (string) $data['data']['code'];
					} elseif ( ! empty( $data['data'] ) && is_string( $data['data'] ) ) {
						$code = (string) $data['data'];
					} elseif ( ! empty( $data['code'] ) ) {
						$code = (string) $data['code'];
					}
				}
				if ( $code !== '' ) {
					$s['pattern'] = $code;
					echo '<div class="notice notice-success"><p>✅ پترن ساخته شد: <code style="direction:ltr;">' . esc_html( $code ) . '</code> — پس از تأییدِ پنل فراز فعال می‌شود.</p></div>';
				} else {
					$em = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'پاسخ نامعتبر';
					echo '<div class="notice notice-error"><p>ساختِ پترن ناموفق: ' . esc_html( $em ) . '</p></div>';
				}
			}
		}

		$s['active'] = isset( $_POST['active'] ) ? '1' : '0';
		update_option( WTO_SPOT_OPTION, $s, false );
		echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
	}

	$active  = $s['active'] === '1';
	$pattern = (string) $s['pattern'];
	$message = (string) $s['message'];
	?>
	<div class="wrap" style="direction:rtl; max-width:780px;">
		<h1>📩 تنظیمات پیامک فراز اس ام اس (اسپات پلیر)</h1>

		<?php if ( ! $faraz_ready ) : ?>
			<div class="notice notice-warning" style="margin:14px 0;"><p>کلید دسترسی و خط ارسال را در تنظیماتِ اصلیِ افزونه‌ی <strong>فراز اس ام اس</strong> وارد کنید.</p></div>
		<?php else : ?>
			<div class="notice notice-info" style="margin:14px 0;"><p>✓ کلید دسترسی و خط ارسال از تنظیماتِ اصلیِ فراز اس ام اس خوانده می‌شود — اینجا فقط متن و پترن لازم است.</p></div>
		<?php endif; ?>

		<div class="notice notice-warning" style="margin:14px 0;"><p>⚠️ اگر این درگاه را فعال می‌کنید، در <strong>اسپات پلیر ← تنظیمات پیامک</strong> (ملی‌پیامک) گزینه‌ی «ارسال پیامک» را <strong>خاموش</strong> کنید تا پیامک دوبار ارسال نشود.</p></div>

		<form method="post" action="" style="background:#fff; border:1px solid #ccd0d4; border-radius:8px; padding:20px; margin-top:10px;">
			<?php wp_nonce_field( 'wto_spot_save', 'wto_spot_nonce' ); ?>

			<p style="margin:0 0 16px;">
				<label style="display:inline-flex; align-items:center; gap:10px; font-size:14px; font-weight:600;">
					<input type="checkbox" class="wto-toggle" name="active" value="1" <?php checked( $active, true ); ?> style="width:18px; height:18px;">
					ارسالِ کد لایسنس با فراز اس ام اس فعال باشد
				</label>
			</p>

			<hr style="border:none; border-top:1px solid #eee; margin:16px 0;">

			<h2 style="font-size:15px; margin:0 0 6px;">متن پیامک (دلخواه)</h2>
			<p style="color:#555; font-size:13px; margin:0 0 8px;">متنِ دلخواهِ خود را بنویسید. روی هر متغیر کلیک کنید تا در محلِ نشانگر اضافه شود. متغیرِ <code style="direction:ltr;">%code%</code> (کد لایسنس) <strong>الزامی</strong> است؛ بقیه اختیاری‌اند.</p>

			<div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
				<?php foreach ( wto_spot_available_vars() as $vk => $vl ) : ?>
					<button type="button" class="button button-small wto-spot-var" data-var="%<?php echo esc_attr( $vk ); ?>%" style="<?php echo $vk === 'code' ? 'border-color:#d63638; color:#b32d2e; font-weight:600;' : ''; ?>">
						<?php echo esc_html( $vl ); ?> <code style="direction:ltr; background:transparent;">%<?php echo esc_html( $vk ); ?>%</code>
					</button>
				<?php endforeach; ?>
			</div>

			<textarea id="wto_spot_message" name="message" rows="6" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; direction:rtl; line-height:1.9; font-family:inherit; font-size:13px;"><?php echo esc_textarea( $message ); ?></textarea>

			<p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:14px;">
				<button type="submit" name="wto_spot_build_pattern" value="1" class="button button-secondary" <?php disabled( ! $faraz_ready ); ?>>🤖 ساخت پترن</button>
				<span style="font-size:13px; color:#555;">کد پترن: <code style="direction:ltr; background:#f6f7f7; border:1px solid #ddd; padding:3px 8px; border-radius:4px;"><?php echo $pattern !== '' ? esc_html( $pattern ) : '—'; ?></code></span>
			</p>
			<p style="color:#777; font-size:12px; margin:6px 0 0;">پترن پس از ساخت باید در پنلِ فراز <strong>تأیید</strong> شود تا قابلِ ارسال گردد. اگر متن را عوض کردید، دوباره «ساخت پترن» بزنید.</p>

			<p style="margin-top:20px;"><button type="submit" class="button button-primary">💾 ذخیره تنظیمات</button></p>
		</form>
	</div>
	<script>
	(function(){
		var ta = document.getElementById('wto_spot_message');
		document.querySelectorAll('.wto-spot-var').forEach(function(btn){
			btn.addEventListener('click', function(){
				var v = btn.getAttribute('data-var');
				var start = ta.selectionStart, end = ta.selectionEnd;
				ta.value = ta.value.substring(0, start) + v + ta.value.substring(end);
				ta.focus();
				ta.selectionStart = ta.selectionEnd = start + v.length;
			});
		});
	})();
	</script>
	<?php
}

// ============================================================================
// ارسالِ لایسنس با فراز — وقتی اسپات‌پلیر لایسنس را روی سفارش ساخت
// ============================================================================

add_action( 'woocommerce_order_details_before_order_table', 'wto_spot_maybe_send_license', 20 );
function wto_spot_maybe_send_license( $order ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		return;
	}
	if ( ! wto_spot_gateway_active() || ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return;
	}
	if ( $order->get_meta( '_faraz_spot_sent' ) ) {
		return; // فقط یک‌بار برای هر سفارش.
	}
	$data = $order->get_meta( '_spotplayer_data' );
	$id   = is_array( $data ) && ! empty( $data['_id'] ) ? (string) $data['_id'] : '';
	if ( $id === '' ) {
		return; // هنوز لایسنسی ساخته نشده.
	}
	$key = wto_spot_fetch_license_key( $id );
	if ( empty( $key ) ) {
		return;
	}

	$s       = wto_spot_get_settings();
	$pattern = trim( $s['pattern'] );
	$to      = $order->get_billing_phone();

	// متغیرهای موجود در متن را به داده‌های سفارش/لایسنس نگاشت کن.
	$names = wto_spot_extract_var_names( $s['message'] );
	$attrs = wto_spot_resolve_attrs( $order, $key, $names );

	$res = wto_send_pattern_sms_raw( $to, $pattern, $attrs );
	$ok  = ( $res === 'success' || $res === true );

	$order->update_meta_data( '_faraz_spot_sent', '1' );
	$order->save();
	$order->add_order_note( 'ارسال کد لایسنس با فراز اس ام اس: ' . ( $ok ? 'موفق' : ( 'خطا — ' . ( is_string( $res ) ? $res : 'نامشخص' ) ) ) );
}

/**
 * نگاشتِ نام‌های متغیر به مقدارِ واقعی از سفارش + لایسنس.
 *
 * @param WC_Order $order
 * @param string   $key   کد لایسنس.
 * @param array    $names نام متغیرهای موجود در متن.
 * @return array attributes (نام => مقدار)
 */
function wto_spot_resolve_attrs( $order, $key, $names ) {
	$attrs = array();
	foreach ( $names as $v ) {
		switch ( $v ) {
			case 'code':
				$attrs['code'] = (string) $key;
				break;
			case 'customer_fullname':
				$attrs['customer_fullname'] = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				break;
			case 'b_first_name':
				$attrs['b_first_name'] = (string) $order->get_billing_first_name();
				break;
			case 'b_last_name':
				$attrs['b_last_name'] = (string) $order->get_billing_last_name();
				break;
			case 'order_id':
				$attrs['order_id'] = (string) $order->get_id();
				break;
			case 'all_items':
				$names_list = array();
				foreach ( $order->get_items() as $item ) {
					$names_list[] = $item->get_name();
				}
				$attrs['all_items'] = implode( '، ', $names_list );
				break;
			default:
				$attrs[ $v ] = ''; // متغیرِ ناشناخته → خالی (تا ارسال نشکند).
		}
	}
	// تضمینِ وجودِ code حتی اگر در نگاشت جا افتاد.
	if ( ! isset( $attrs['code'] ) ) {
		$attrs['code'] = (string) $key;
	}
	return $attrs;
}

/**
 * دریافتِ کدِ لایسنس از وب‌سرویسِ اسپات‌پلیر (همان روشِ خودِ اسپات‌پلیر).
 *
 * @param string $id شناسه‌ی لایسنس.
 * @return string|null
 */
function wto_spot_fetch_license_key( $id ) {
	$sp  = get_option( 'spotplayer' );
	$api = is_array( $sp ) && isset( $sp['api'] ) ? $sp['api'] : '';
	if ( $api === '' || ! function_exists( 'curl_init' ) ) {
		return null;
	}
	$curl = curl_init();
	curl_setopt_array( $curl, array(
		CURLOPT_URL            => 'https://panel.spotplayer.ir/license/edit/' . $id,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_CUSTOMREQUEST  => 'GET',
		CURLOPT_HTTPHEADER     => array(
			'$API:' . $api,
			'$LEVEL: -1',
			'Content-Type: application/json',
		),
	) );
	$response = curl_exec( $curl );
	curl_close( $curl );
	$decoded = json_decode( $response );
	return $decoded->key ?? null;
}
