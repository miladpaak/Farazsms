<?php
/**
 * یکپارچگی با افزونه‌ی «حمل و نقل ووکامرس» (تاپین/نبیک) — بدونِ دستکاریِ آن.
 *
 * وقتی هم این افزونه و هم فراز اس ام اس نصب باشند، این ماژول:
 *   ۱) زیرمنوی «اتصال به پیامک فراز اس ام اس» را زیرِ منوی «حمل و نقل» اضافه می‌کند
 *      (با فیلترِ pws_submenu).
 *   ۲) برای هر رویدادِ پیامکیِ افزونه (بارکدِ تاپین + وضعیت‌های سفارش) اجازه می‌دهد
 *      کاربر متنِ دلخواه بنویسد، «ساخت پترن» بزند و با پترنِ فراز ارسال شود.
 *   ۳) مهم‌ترین قابلیت: وقتی تاپین بارکد (کدِ رهگیریِ پستی) صادر کرد، با هوکِ
 *      pws_save_order_post_barcode کدِ رهگیری به‌صورتِ خودکار با پیامک برای مشتری
 *      ارسال می‌شود — بدونِ کپی/پیستِ دستی در صفحه‌ی سفارش.
 *
 * کلید دسترسی و خطِ ارسال از تنظیماتِ اصلیِ افزونه‌ی فراز خوانده می‌شود.
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_PWS_OPTION = 'wto_pws_settings';

/**
 * آیا افزونه‌ی حمل و نقل فعال است؟
 *
 * @return bool
 */
function wto_pws_is_active() {
	return class_exists( 'PWS_SMS' ) || class_exists( 'PWS' ) || function_exists( 'PWS' ) || defined( 'PWS_VERSION' );
}

/**
 * متغیرهای قابل‌استفاده در پترن‌ها — کلید => برچسبِ فارسی.
 *
 * @return array
 */
function wto_pws_available_vars() {
	return array(
		'tracking_code' => 'کد رهگیری پستی',
		'order_id'      => 'شماره سفارش',
		'first_name'    => 'نام مشتری',
		'last_name'     => 'نام خانوادگی',
		'total'         => 'مبلغ کل سفارش',
	);
}

/**
 * فهرستِ رویدادهای پیامکی. کلیدِ «barcode» ویژه است (هوکِ بارکد)؛ بقیه وضعیت‌های سفارش‌اند.
 *
 * @return array key => array('label'=>.., 'priority'=>bool, 'default'=>..)
 */
function wto_pws_events() {
	$brand  = get_bloginfo( 'name' );
	$events = array(
		'barcode' => array(
			'label'    => '📦 کد رهگیری پستی (بارکد تاپین) — مهم',
			'priority' => true,
			'default'  => $brand . "\nمشتری گرامی، سفارش %order_id% به پست تحویل شد.\nکد رهگیری مرسوله: %tracking_code%\nرهگیری: radgir.net",
		),
	);

	if ( function_exists( 'wc_get_order_statuses' ) ) {
		$suggest = array(
			'wc-processing'   => 'سفارش %order_id% ثبت و در حال پردازش است.',
			'wc-pws-courier'  => 'سفارش %order_id% تحویلِ پیک شد.',
			'wc-pws-packaged' => 'سفارش %order_id% بسته‌بندی و آماده‌ی ارسال شد.',
			'wc-pws-in-stock' => 'سفارش %order_id% در حال آماده‌سازی در انبار است.',
			'wc-completed'    => 'سفارش %order_id% تکمیل شد. از خریدتان سپاسگزاریم.',
			'wc-cancelled'    => 'سفارش %order_id% لغو شد.',
			'wc-refunded'     => 'سفارش %order_id% مسترد شد.',
		);
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$events[ $key ] = array(
				'label'    => $label,
				'priority' => false,
				'default'  => $brand . "\n" . ( isset( $suggest[ $key ] ) ? $suggest[ $key ] : 'سفارش %order_id%' ),
			);
		}
	}
	return $events;
}

/**
 * تنظیماتِ ذخیره‌شده.
 *
 * @return array events[key] => array(enabled, message, pattern)
 */
function wto_pws_settings() {
	$s = get_option( WTO_PWS_OPTION, array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	if ( ! isset( $s['events'] ) || ! is_array( $s['events'] ) ) {
		$s['events'] = array();
	}
	return $s;
}

/**
 * تنظیماتِ یک رویداد با مقادیرِ پیش‌فرض.
 *
 * @param string $key
 * @return array enabled, message, pattern
 */
function wto_pws_event_settings( $key ) {
	$s      = wto_pws_settings();
	$events = wto_pws_events();
	$def    = isset( $events[ $key ]['default'] ) ? $events[ $key ]['default'] : '';
	$e      = isset( $s['events'][ $key ] ) && is_array( $s['events'][ $key ] ) ? $s['events'][ $key ] : array();
	return array(
		'enabled' => isset( $e['enabled'] ) ? $e['enabled'] : '0',
		'message' => isset( $e['message'] ) && $e['message'] !== '' ? (string) $e['message'] : $def,
		'pattern' => isset( $e['pattern'] ) ? (string) $e['pattern'] : '',
	);
}

/**
 * استخراجِ نام‌های متغیرِ موجود در متن.
 *
 * @param string $message
 * @return array
 */
function wto_pws_extract_var_names( $message ) {
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
// زیرمنو زیرِ «حمل و نقل» — با فیلترِ خودِ افزونه‌ی حمل و نقل
// ============================================================================

add_filter( 'pws_submenu', 'wto_pws_register_submenu' );
function wto_pws_register_submenu( $submenus ) {
	$cap            = apply_filters( 'pws_menu_capability', 'manage_woocommerce' );
	$submenus[35]   = array(
		'title'      => 'اتصال به پیامک فراز اس ام اس',
		'capability' => $cap,
		'slug'       => 'wto-pws-sms',
		'callback'   => 'wto_pws_render_settings_page',
	);
	return $submenus;
}

function wto_pws_render_settings_page() {
	$cap = apply_filters( 'pws_menu_capability', 'manage_woocommerce' );
	if ( ! current_user_can( $cap ) ) {
		return;
	}
	$faraz_ready = function_exists( 'farazsms_is_ready' );
	$events      = wto_pws_events();
	$s           = wto_pws_settings();

	// ذخیره / ساخت پترن
	if ( isset( $_POST['wto_pws_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wto_pws_nonce'] ) ), 'wto_pws_save' ) ) {

		$msgs  = isset( $_POST['message'] ) && is_array( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : array();
		$ens   = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] ) ? $_POST['enabled'] : array();
		$build = isset( $_POST['wto_pws_build'] ) ? sanitize_text_field( wp_unslash( $_POST['wto_pws_build'] ) ) : '';

		foreach ( $events as $key => $ev ) {
			if ( ! isset( $s['events'][ $key ] ) || ! is_array( $s['events'][ $key ] ) ) {
				$s['events'][ $key ] = array( 'enabled' => '0', 'message' => '', 'pattern' => '' );
			}
			if ( isset( $msgs[ $key ] ) ) {
				$s['events'][ $key ]['message'] = sanitize_textarea_field( $msgs[ $key ] );
			}
			$s['events'][ $key ]['enabled'] = isset( $ens[ $key ] ) ? '1' : '0';
		}

		// ساختِ پترن برای رویدادِ خواسته‌شده
		if ( $build !== '' && isset( $events[ $build ] ) ) {
			$message = isset( $s['events'][ $build ]['message'] ) ? $s['events'][ $build ]['message'] : '';
			$req_ok  = ( $build !== 'barcode' ) || ( strpos( $message, '%tracking_code%' ) !== false || strpos( $message, '{tracking_code}' ) !== false );
			if ( ! $faraz_ready || ! function_exists( 'wto_create_pattern' ) ) {
				echo '<div class="notice notice-error"><p>ابتدا کلید دسترسی را در تنظیماتِ اصلیِ افزونه‌ی فراز اس ام اس وارد کنید.</p></div>';
			} elseif ( ! $req_ok ) {
				echo '<div class="notice notice-error"><p>برای رویدادِ «کد رهگیری»، متغیرِ <code>%tracking_code%</code> الزامی است.</p></div>';
			} elseif ( trim( $message ) === '' ) {
				echo '<div class="notice notice-error"><p>ابتدا متنِ پیامک را بنویسید.</p></div>';
			} else {
				$desc = function_exists( 'wto_pattern_brand_description' ) ? wto_pattern_brand_description( 'buyer' ) : 'افزونه فراز اس ام اس / حمل و نقل';
				$resp = wto_create_pattern( $message, 1, $desc );
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
					$s['events'][ $build ]['pattern'] = $code;
					echo '<div class="notice notice-success"><p>✅ پترن ساخته شد: <code style="direction:ltr;">' . esc_html( $code ) . '</code> — پس از تأییدِ پنلِ فراز فعال می‌شود.</p></div>';
				} else {
					$em = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'پاسخ نامعتبر';
					echo '<div class="notice notice-error"><p>ساختِ پترن ناموفق: ' . esc_html( $em ) . '</p></div>';
				}
			}
		}

		update_option( WTO_PWS_OPTION, $s, false );
		echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
	}

	$vars = wto_pws_available_vars();
	?>
	<div class="wrap" style="direction:rtl;max-width:820px">
		<h1>📩 اتصال به پیامک فراز اس ام اس</h1>

		<?php if ( ! $faraz_ready ) : ?>
			<div class="notice notice-warning" style="margin:14px 0"><p>کلید دسترسی و خط ارسال را در تنظیماتِ اصلیِ افزونه‌ی <strong>فراز اس ام اس</strong> وارد کنید.</p></div>
		<?php else : ?>
			<div class="notice notice-info" style="margin:14px 0"><p>✓ کلید دسترسی و خط ارسال از تنظیماتِ اصلیِ فراز اس ام اس خوانده می‌شود.</p></div>
		<?php endif; ?>

		<div class="notice notice-warning" style="margin:14px 0"><p>⚠️ برای هر رویداد که اینجا فعال می‌کنید، همان رویداد را در بخشِ <strong>حمل و نقل ← پیامک (ملی‌پیامک)</strong> <strong>خاموش</strong> کنید تا پیامک دوبار ارسال نشود.</p></div>

		<p style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:10px 14px;font-size:13px;color:#3730a3;line-height:2">
			برای هر رویداد، متنِ دلخواه را بنویسید (روی متغیرها کلیک کنید تا اضافه شوند)، سپس «ساخت پترن» بزنید و تیکِ فعال‌سازی را بزنید.
			مهم‌ترین رویداد «کد رهگیری پستی» است: وقتی تاپین بارکد صادر کند، کد رهگیری <strong>خودکار</strong> برای مشتری ارسال می‌شود.
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'wto_pws_save', 'wto_pws_nonce' ); ?>

			<?php foreach ( $events as $key => $ev ) :
				$es = wto_pws_event_settings( $key );
				$id = 'wto_pws_msg_' . sanitize_html_class( $key );
				?>
				<div style="background:#fff;border:1px solid <?php echo ! empty( $ev['priority'] ) ? '#d63638' : '#ccd0d4'; ?>;border-radius:8px;padding:16px;margin:12px 0">
					<label style="display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;margin-bottom:10px">
						<input type="checkbox" class="wto-toggle" name="enabled[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $es['enabled'], '1' ); ?> style="width:18px;height:18px">
						<?php echo esc_html( $ev['label'] ); ?>
					</label>

					<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
						<?php foreach ( $vars as $vk => $vl ) : ?>
							<button type="button" class="button button-small wto-pws-var" data-target="<?php echo esc_attr( $id ); ?>" data-var="%<?php echo esc_attr( $vk ); ?>%"<?php echo ( $vk === 'tracking_code' && ! empty( $ev['priority'] ) ) ? ' style="border-color:#d63638;color:#b32d2e;font-weight:600"' : ''; ?>>
								<?php echo esc_html( $vl ); ?> <code style="direction:ltr;background:transparent">%<?php echo esc_html( $vk ); ?>%</code>
							</button>
						<?php endforeach; ?>
					</div>

					<textarea id="<?php echo esc_attr( $id ); ?>" name="message[<?php echo esc_attr( $key ); ?>]" rows="4" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;direction:rtl;line-height:1.9;font-family:inherit;font-size:13px"><?php echo esc_textarea( $es['message'] ); ?></textarea>

					<p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 0">
						<button type="submit" name="wto_pws_build" value="<?php echo esc_attr( $key ); ?>" class="button button-secondary" <?php disabled( ! $faraz_ready ); ?>>🤖 ساخت پترن</button>
						<span style="font-size:13px;color:#555">کد پترن: <code style="direction:ltr;background:#f6f7f7;border:1px solid #ddd;padding:3px 8px;border-radius:4px"><?php echo $es['pattern'] !== '' ? esc_html( $es['pattern'] ) : '—'; ?></code></span>
					</p>
				</div>
			<?php endforeach; ?>

			<p style="margin-top:18px"><button type="submit" class="button button-primary">💾 ذخیره همه تنظیمات</button></p>
		</form>
	</div>
	<script>
	(function(){
		document.querySelectorAll('.wto-pws-var').forEach(function(btn){
			btn.addEventListener('click',function(){
				var ta=document.getElementById(btn.getAttribute('data-target'));
				if(!ta)return;
				var v=btn.getAttribute('data-var');
				var s=ta.selectionStart,e=ta.selectionEnd;
				ta.value=ta.value.substring(0,s)+v+ta.value.substring(e);
				ta.focus();ta.selectionStart=ta.selectionEnd=s+v.length;
			});
		});
	})();
	</script>
	<?php
}

// ============================================================================
// ارسالِ پیامک
// ============================================================================

/**
 * نگاشتِ نام‌های متغیر به مقدارِ واقعی از سفارش.
 *
 * @param WC_Order $order
 * @param array    $names
 * @return array
 */
function wto_pws_resolve_attrs( $order, $names ) {
	$attrs = array();
	foreach ( $names as $v ) {
		switch ( $v ) {
			case 'tracking_code':
				$attrs['tracking_code'] = (string) $order->get_meta( 'post_barcode' );
				break;
			case 'order_id':
				$attrs['order_id'] = (string) $order->get_id();
				break;
			case 'first_name':
				$attrs['first_name'] = (string) $order->get_billing_first_name();
				break;
			case 'last_name':
				$attrs['last_name'] = (string) $order->get_billing_last_name();
				break;
			case 'total':
				$attrs['total'] = (string) $order->get_total();
				break;
			default:
				$attrs[ $v ] = '';
		}
	}
	return $attrs;
}

/**
 * ارسالِ پیامکِ یک رویداد برای یک سفارش.
 *
 * @param string   $key
 * @param WC_Order $order
 * @param string   $dedup_meta متای جلوگیری از ارسالِ تکراری (خالی = بدونِ dedup).
 * @return void
 */
function wto_pws_send_event( $key, $order, $dedup_meta = '' ) {
	if ( ! ( $order instanceof WC_Order ) || ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return;
	}
	$es = wto_pws_event_settings( $key );
	if ( $es['enabled'] !== '1' || trim( $es['pattern'] ) === '' ) {
		return;
	}
	if ( $dedup_meta !== '' && $order->get_meta( $dedup_meta ) ) {
		return;
	}

	$to    = $order->get_billing_phone();
	$names = wto_pws_extract_var_names( $es['message'] );
	$attrs = wto_pws_resolve_attrs( $order, $names );

	$res = wto_send_pattern_sms_raw( $to, trim( $es['pattern'] ), $attrs );
	$ok  = ( $res === 'success' || $res === true );

	if ( $dedup_meta !== '' ) {
		$order->update_meta_data( $dedup_meta, '1' );
		$order->save();
	}
	$order->add_order_note( 'پیامکِ فراز اس ام اس (' . $key . '): ' . ( $ok ? 'موفق' : ( 'خطا — ' . ( is_string( $res ) ? $res : 'نامشخص' ) ) ) );
}

// رویدادِ بارکد/کد رهگیریِ تاپین — مهم‌ترین قابلیت (بدونِ کپی/پیستِ دستی).
add_action( 'pws_save_order_post_barcode', 'wto_pws_on_barcode', 120, 2 );
function wto_pws_on_barcode( $order, $barcode ) {
	if ( ! wto_pws_is_active() ) {
		return;
	}
	if ( ! ( $order instanceof WC_Order ) ) {
		$order = wc_get_order( $order );
	}
	if ( $order instanceof WC_Order ) {
		// مطمئن شو بارکد روی متا هست (resolve از post_barcode می‌خواند).
		if ( ! $order->get_meta( 'post_barcode' ) && $barcode ) {
			$order->update_meta_data( 'post_barcode', $barcode );
		}
		wto_pws_send_event( 'barcode', $order ); // بدونِ dedup: هر بار بارکدِ جدید صادر شد، ارسال شود.
	}
}

// رویدادهای وضعیتِ سفارش.
add_action( 'woocommerce_order_status_changed', 'wto_pws_on_status_changed', 120, 3 );
function wto_pws_on_status_changed( $order_id, $old_status, $new_status ) {
	if ( ! wto_pws_is_active() ) {
		return;
	}
	$key   = 'wc-' . $new_status;
	$order = wc_get_order( $order_id );
	if ( $order instanceof WC_Order ) {
		wto_pws_send_event( $key, $order );
	}
}

// ============================================================================
// بخشِ یکپارچگی در صفحه‌ی تنظیماتِ «کد رهگیری» افزونه‌ی فراز
// ============================================================================

add_action( 'admin_notices', 'wto_pws_tracking_page_notice' );
function wto_pws_tracking_page_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $page !== 'farazwto' ) {
		return;
	}
	$active   = wto_pws_is_active();
	$settings = admin_url( 'admin.php?page=wto-pws-sms' );
	?>
	<div class="notice notice-info" style="border-right-color:#6366f1">
		<p style="font-size:13px;line-height:2">
			<strong>🔗 یکپارچگی با افزونه‌ی «حمل و نقل ووکامرس» (تاپین):</strong>
			<?php if ( $active ) : ?>
				افزونه‌ی حمل و نقل شناسایی شد. وقتی تاپین <strong>بارکدِ پستی</strong> صادر کند، کدِ رهگیری به‌صورتِ خودکار با فراز اس ام اس برای مشتری ارسال می‌شود — بدونِ کپی/پیستِ دستی.
				تنظیمات و ساختِ پترن در صفحه‌ی <a href="<?php echo esc_url( $settings ); ?>">حمل و نقل ← اتصال به پیامک فراز اس ام اس</a> انجام می‌شود.
			<?php else : ?>
				افزونه‌ی «حمل و نقل ووکامرس» نصب/فعال نیست. در صورتِ نصب، می‌توانید کدِ رهگیریِ تاپین را خودکار با فراز ارسال کنید.
			<?php endif; ?>
		</p>
	</div>
	<?php
}
