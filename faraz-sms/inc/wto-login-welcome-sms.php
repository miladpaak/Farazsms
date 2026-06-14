<?php
/**
 * پیامکِ خوش‌آمدگویی و اطلاع‌رسانیِ ورود — بخشِ ورود/ثبت‌نام.
 *
 *   - عضویت (register): اولین‌بار که کاربر عضو می‌شود، پیامکِ خوش‌آمد می‌رود.
 *   - ورود (login): هر بار که کاربرِ موجود وارد می‌شود، پیامکِ اطلاع‌رسانی
 *     (مثلاً «از آی‌پی … در ساعت … وارد شدید») می‌رود.
 *
 * هر دو با پترنِ فراز ارسال می‌شوند و کاربر متنِ دلخواهِ خودش را می‌نویسد
 * (مثلِ بخشِ کد رهگیری / اسپات‌پلیر): متنِ قابلِ‌ویرایش + لیستِ متغیرها + «ساخت پترن».
 *
 * نکته‌ی فنی: در فلوِ ثبت‌نام، موبایل کمی بعد از هوکِ user_register ذخیره می‌شود
 * و کاربر بلافاصله لاگین می‌شود. برای همین هر دو پیامک سرِ wp_login ارسال می‌شوند؛
 * یک فلگ (usermeta) ثبت‌نام را از ورودِ عادی تفکیک می‌کند تا پیامکِ درست برود.
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_LWS_OPTION = 'wto_login_welcome_settings';

/**
 * رویدادها + متغیرهای مجاز + متنِ پیش‌فرض.
 *
 * @return array
 */
function wto_lws_events() {
	$brand = get_bloginfo( 'name' );
	return array(
		'register' => array(
			'label'   => '🎉 خوش‌آمدگویی هنگام عضویت',
			'vars'    => array(
				'fullname' => 'نام و نام خانوادگی',
				'username' => 'نام کاربری',
				'sitename' => 'نام سایت',
				'date'     => 'تاریخ (شمسی)',
			),
			'default' => $brand . "\n%fullname% عزیز، عضویتِ شما با موفقیت انجام شد. خوش آمدید!",
		),
		'login'    => array(
			'label'   => '🔐 اطلاع‌رسانی هنگام ورود',
			'vars'    => array(
				'ip'       => 'آی‌پی',
				'time'     => 'ساعت',
				'date'     => 'تاریخ (شمسی)',
				'fullname' => 'نام و نام خانوادگی',
				'username' => 'نام کاربری',
				'sitename' => 'نام سایت',
			),
			'default' => $brand . "\n%fullname% عزیز، ورود به حساب کاربریِ شما در ساعت %time% مورخ %date% از آی‌پی %ip% ثبت شد.",
		),
	);
}

function wto_lws_settings() {
	$s = get_option( WTO_LWS_OPTION, array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	if ( ! isset( $s['events'] ) || ! is_array( $s['events'] ) ) {
		$s['events'] = array();
	}
	return $s;
}

function wto_lws_event_settings( $key ) {
	$s      = wto_lws_settings();
	$events = wto_lws_events();
	$def    = isset( $events[ $key ]['default'] ) ? $events[ $key ]['default'] : '';
	$e      = isset( $s['events'][ $key ] ) && is_array( $s['events'][ $key ] ) ? $s['events'][ $key ] : array();
	return array(
		'enabled' => isset( $e['enabled'] ) ? $e['enabled'] : '0',
		'message' => isset( $e['message'] ) && $e['message'] !== '' ? (string) $e['message'] : $def,
		'pattern' => isset( $e['pattern'] ) ? (string) $e['pattern'] : '',
	);
}

function wto_lws_extract_var_names( $message ) {
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
// بخشِ تنظیمات — داخلِ صفحه‌ی «ورود و ثبت‌نام» (هوکِ پلِ ماژولِ ورود)
// ============================================================================

add_action( 'wto_login_register_render_welcome_tab', 'wto_lws_render_page' );
function wto_lws_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$faraz_ready = function_exists( 'farazsms_is_ready' );
	$events      = wto_lws_events();
	$s           = wto_lws_settings();

	if ( isset( $_POST['wto_lws_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wto_lws_nonce'] ) ), 'wto_lws_save' ) ) {
		$msgs  = isset( $_POST['message'] ) && is_array( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : array();
		$ens   = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] ) ? $_POST['enabled'] : array();
		$build = isset( $_POST['wto_lws_build'] ) ? sanitize_text_field( wp_unslash( $_POST['wto_lws_build'] ) ) : '';

		foreach ( $events as $key => $ev ) {
			if ( ! isset( $s['events'][ $key ] ) || ! is_array( $s['events'][ $key ] ) ) {
				$s['events'][ $key ] = array( 'enabled' => '0', 'message' => '', 'pattern' => '' );
			}
			if ( isset( $msgs[ $key ] ) ) {
				$s['events'][ $key ]['message'] = sanitize_textarea_field( $msgs[ $key ] );
			}
			$s['events'][ $key ]['enabled'] = isset( $ens[ $key ] ) ? '1' : '0';
		}

		if ( $build !== '' && isset( $events[ $build ] ) ) {
			$message = isset( $s['events'][ $build ]['message'] ) ? $s['events'][ $build ]['message'] : '';
			if ( ! $faraz_ready || ! function_exists( 'wto_create_pattern' ) ) {
				echo '<div class="notice notice-error"><p>ابتدا کلید دسترسی را در تنظیماتِ اصلیِ افزونه‌ی فراز اس ام اس وارد کنید.</p></div>';
			} elseif ( trim( $message ) === '' ) {
				echo '<div class="notice notice-error"><p>ابتدا متنِ پیامک را بنویسید.</p></div>';
			} else {
				$desc = function_exists( 'wto_pattern_brand_description' ) ? wto_pattern_brand_description( 'buyer' ) : 'افزونه فراز اس ام اس / ورود و ثبت‌نام';
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

		update_option( WTO_LWS_OPTION, $s, false );
		echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
	}
	?>
	<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:18px; direction:rtl; max-width:100%">
		<h4 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">📩 پیامک خوش‌آمدگویی و اطلاع‌رسانیِ ورود</h4>
		<p style="margin:0 0 12px; font-size:12.5px; color:#64748b; line-height:1.9;">پس از <strong>عضویت</strong> و هنگامِ <strong>ورود</strong>، پیامک با پترنِ دلخواهِ شما برای کاربر ارسال می‌شود.</p>

		<?php if ( ! $faraz_ready ) : ?>
			<div class="notice notice-warning" style="margin:14px 0"><p>کلید دسترسی و خط ارسال را در تنظیماتِ اصلیِ افزونه‌ی <strong>فراز اس ام اس</strong> وارد کنید.</p></div>
		<?php else : ?>
			<div class="notice notice-info" style="margin:14px 0"><p>✓ کلید دسترسی و خط ارسال از تنظیماتِ اصلیِ فراز اس ام اس خوانده می‌شود.</p></div>
		<?php endif; ?>

		<p style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:10px 14px;font-size:13px;color:#3730a3;line-height:2">
			برای هر رویداد، متنِ دلخواه را بنویسید (روی متغیرها کلیک کنید تا اضافه شوند)، «ساخت پترن» بزنید و تیکِ فعال‌سازی را بزنید.
			ارسالِ پیامک نیازمندِ وجودِ شماره‌ی موبایلِ کاربر است (که هنگامِ ورود/ثبت‌نام ذخیره می‌شود).
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'wto_lws_save', 'wto_lws_nonce' ); ?>

			<?php foreach ( $events as $key => $ev ) :
				$es = wto_lws_event_settings( $key );
				$id = 'wto_lws_msg_' . sanitize_html_class( $key );
				?>
				<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;margin:12px 0">
					<label style="display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;margin-bottom:10px">
						<input type="checkbox" class="wto-toggle" name="enabled[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $es['enabled'], '1' ); ?> style="width:18px;height:18px">
						<?php echo esc_html( $ev['label'] ); ?>
					</label>

					<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
						<?php foreach ( $ev['vars'] as $vk => $vl ) : ?>
							<button type="button" class="button button-small wto-lws-var" data-target="<?php echo esc_attr( $id ); ?>" data-var="%<?php echo esc_attr( $vk ); ?>%">
								<?php echo esc_html( $vl ); ?> <code style="direction:ltr;background:transparent">%<?php echo esc_html( $vk ); ?>%</code>
							</button>
						<?php endforeach; ?>
					</div>

					<textarea id="<?php echo esc_attr( $id ); ?>" name="message[<?php echo esc_attr( $key ); ?>]" rows="4" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;direction:rtl;line-height:1.9;font-family:inherit;font-size:13px"><?php echo esc_textarea( $es['message'] ); ?></textarea>

					<p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 0">
						<button type="submit" name="wto_lws_build" value="<?php echo esc_attr( $key ); ?>" class="button button-secondary" <?php disabled( ! $faraz_ready ); ?>>🤖 ساخت پترن</button>
						<span style="font-size:13px;color:#555">کد پترن: <code style="direction:ltr;background:#f6f7f7;border:1px solid #ddd;padding:3px 8px;border-radius:4px"><?php echo $es['pattern'] !== '' ? esc_html( $es['pattern'] ) : '—'; ?></code></span>
					</p>
				</div>
			<?php endforeach; ?>

			<p style="margin-top:18px"><button type="submit" class="button button-primary">💾 ذخیره همه تنظیمات</button></p>
		</form>
	</div>
	<script>
	(function(){
		document.querySelectorAll('.wto-lws-var').forEach(function(btn){
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
// ارسال
// ============================================================================

/**
 * موبایلِ کاربر از متاهای ماژولِ ورود.
 *
 * @param int $user_id
 * @return string
 */
function wto_lws_get_mobile( $user_id ) {
	foreach ( array( 'mobile_number', 'billing_phone', '0digits_phone_no' ) as $k ) {
		$m = get_user_meta( $user_id, $k, true );
		if ( ! empty( $m ) ) {
			return (string) $m;
		}
	}
	return '';
}

function wto_lws_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

function wto_lws_jalali_date() {
	$now = current_time( 'mysql' );
	if ( function_exists( 'wto_send_reports_to_jalali' ) ) {
		return wto_send_reports_to_jalali( $now, false );
	}
	return date_i18n( 'Y/m/d', current_time( 'timestamp' ) );
}

/**
 * مقداردهیِ متغیرها از کاربر + context.
 *
 * @param array   $names
 * @param WP_User $user
 * @return array
 */
function wto_lws_resolve_attrs( $names, $user ) {
	$fullname = trim( $user->first_name . ' ' . $user->last_name );
	if ( $fullname === '' ) {
		$fullname = $user->display_name ? $user->display_name : $user->user_login;
	}
	$attrs = array();
	foreach ( $names as $v ) {
		switch ( $v ) {
			case 'fullname':
				$attrs['fullname'] = $fullname;
				break;
			case 'username':
				$attrs['username'] = (string) $user->user_login;
				break;
			case 'sitename':
				$attrs['sitename'] = (string) get_bloginfo( 'name' );
				break;
			case 'date':
				$attrs['date'] = wto_lws_jalali_date();
				break;
			case 'time':
				$attrs['time'] = current_time( 'H:i' );
				break;
			case 'ip':
				$attrs['ip'] = wto_lws_client_ip();
				break;
			default:
				$attrs[ $v ] = '';
		}
	}
	return $attrs;
}

/**
 * ارسالِ پیامکِ یک رویداد برای یک کاربر.
 *
 * @param string $key
 * @param int    $user_id
 * @return void
 */
function wto_lws_send( $key, $user_id ) {
	if ( ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return;
	}
	$es = wto_lws_event_settings( $key );
	if ( $es['enabled'] !== '1' || trim( $es['pattern'] ) === '' ) {
		return;
	}
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	$mobile = wto_lws_get_mobile( $user_id );
	if ( $mobile === '' ) {
		return;
	}
	if ( function_exists( 'wto_normalize_phone' ) ) {
		$mobile = wto_normalize_phone( $mobile );
	}
	$names  = wto_lws_extract_var_names( $es['message'] );
	$attrs  = wto_lws_resolve_attrs( $names, $user );
	$sender = trim( (string) get_option( 'wto_sender', '' ) );
	wto_send_pattern_sms_raw( $mobile, trim( $es['pattern'] ), $attrs, $sender );
}

// هنگامِ ثبت‌نام فقط یک فلگ می‌گذاریم (موبایل هنوز ممکن است ذخیره نشده باشد).
add_action( 'user_register', 'wto_lws_mark_new_user', 5, 1 );
function wto_lws_mark_new_user( $user_id ) {
	update_user_meta( $user_id, '_wto_lws_pending_welcome', '1' );
}

// هر دو پیامک سرِ ورود ارسال می‌شوند (موبایل ذخیره شده و کاربر احراز شده است).
add_action( 'wp_login', 'wto_lws_on_login', 20, 2 );
function wto_lws_on_login( $user_login, $user ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return;
	}
	if ( get_user_meta( $user->ID, '_wto_lws_pending_welcome', true ) === '1' ) {
		// اولین ورود پس از ثبت‌نام → پیامکِ خوش‌آمد (نه اطلاع‌رسانیِ ورود).
		delete_user_meta( $user->ID, '_wto_lws_pending_welcome' );
		wto_lws_send( 'register', $user->ID );
		return;
	}
	wto_lws_send( 'login', $user->ID );
}
