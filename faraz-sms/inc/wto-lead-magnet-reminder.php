<?php
/**
 * یادآورِ هدیه‌ی لید مگنت — پیامک به کاربرانی که هدیه‌ی عضویت گرفته‌اند ولی
 * هنوز از آن استفاده نکرده‌اند (خرید نکرده‌اند) و هدیه‌شان دارد منقضی می‌شود.
 *
 * منطق:
 *   - هدیه‌ی لید مگنت در همان جدولِ کیفِ پول ذخیره می‌شود (source = 'lead_magnet').
 *   - رکوردِ active یعنی مصرف‌نشده؛ یعنی کاربر هنوز خرید نکرده. به‌محضِ خرید/مصرف،
 *     status از active خارج می‌شود و دیگر یادآور نمی‌رود. (دقیقاً خواسته‌ی کاربر.)
 *   - چند یادآور قابل‌تنظیم است (مثلاً «۲ روز مانده» و «۱ روز مانده») — هر تعداد.
 *   - ارسال با پترنِ فراز: %balance% (موجودیِ هدیه) و %days_left% (روزهای مانده).
 *
 * مدلِ این ماژول دقیقاً مثلِ یادآورِ کش‌بک است (wto-cashback.php).
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_LM_REMINDER_OPTION    = 'wto_lm_reminder_settings';
const WTO_LM_REMINDER_CRON_HOOK = 'wto_lm_reminder_daily_cron';

/**
 * تنظیمات با مقادیرِ پیش‌فرض.
 *
 * @return array enabled, reminders (آرایه‌ی روزها), message, pattern
 */
function wto_lm_reminder_settings() {
	$s = get_option( WTO_LM_REMINDER_OPTION, array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	$domain = preg_replace( '#^www\.#i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$defaults = array(
		'enabled'  => '0',
		'reminders' => array( 2, 1 ),
		'message'  => "کاربر گرامی، %balance% تومان هدیه‌ی عضویتِ شما تنها %days_left% روز دیگر اعتبار دارد.\nبرای استفاده، خرید کنید:\n" . $domain,
		'pattern'  => '',
	);
	$merged = array_merge( $defaults, $s );
	if ( ! is_array( $merged['reminders'] ) ) {
		$merged['reminders'] = $defaults['reminders'];
	}
	if ( trim( (string) $merged['message'] ) === '' ) {
		$merged['message'] = $defaults['message'];
	}
	return $merged;
}

function wto_lm_reminder_is_enabled() {
	$s = wto_lm_reminder_settings();
	return $s['enabled'] === '1' && trim( (string) $s['pattern'] ) !== '';
}

// ============================================================================
// Cron
// ============================================================================

add_action( 'init', 'wto_lm_reminder_schedule_cron' );
function wto_lm_reminder_schedule_cron() {
	if ( ! wp_next_scheduled( WTO_LM_REMINDER_CRON_HOOK ) ) {
		wp_schedule_event( time() + 120, 'daily', WTO_LM_REMINDER_CRON_HOOK );
	}
}

add_action( WTO_LM_REMINDER_CRON_HOOK, 'wto_lm_reminder_cron_runner' );
function wto_lm_reminder_cron_runner() {
	if ( ! wto_lm_reminder_is_enabled() || ! function_exists( 'wto_wallet_table' ) || ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		return;
	}
	$settings  = wto_lm_reminder_settings();
	$pattern   = trim( (string) $settings['pattern'] );
	$reminders = array_filter( array_map( 'intval', (array) $settings['reminders'] ) );
	if ( $pattern === '' || empty( $reminders ) ) {
		return;
	}

	global $wpdb;
	$table = wto_wallet_table();
	$now   = current_time( 'mysql' );

	foreach ( $reminders as $days_ahead ) {
		$days_ahead = (int) $days_ahead;
		if ( $days_ahead <= 0 ) {
			continue;
		}
		$window_start = date( 'Y-m-d 00:00:00', strtotime( "+{$days_ahead} days", strtotime( $now ) ) );
		$window_end   = date( 'Y-m-d 23:59:59', strtotime( "+{$days_ahead} days", strtotime( $now ) ) );

		// فقط هدیه‌ی لید مگنتِ مصرف‌نشده (active) که در آن روز منقضی می‌شود.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, SUM(amount - used_amount) AS balance, MIN(expires_at) AS soonest
			 FROM $table
			 WHERE source = %s AND status = %s AND expires_at BETWEEN %s AND %s
			 GROUP BY user_id LIMIT 500",
			'lead_magnet',
			'active',
			$window_start,
			$window_end
		), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			continue;
		}

		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$balance = (float) $row['balance'];
			if ( $balance <= 0 ) {
				continue;
			}
			$mobile = (string) get_user_meta( $user_id, 'billing_phone', true );
			if ( $mobile === '' ) {
				$mobile = (string) get_user_meta( $user_id, 'mobile_number', true );
			}
			if ( $mobile === '' ) {
				continue;
			}

			$reminder_key = 'wto_lm_remind_' . $days_ahead . '_' . $user_id . '_' . md5( (string) $row['soonest'] );
			if ( get_transient( $reminder_key ) ) {
				continue; // قبلاً فرستاده شده.
			}

			// مثلِ کش‌بک: نگاشتِ %balance%→var1 و %days_left%→var2.
			$attrs = array(
				'var1' => number_format( $balance ),
				'var2' => (string) $days_ahead,
			);
			wto_send_pattern_sms_raw( $mobile, $pattern, $attrs );
			set_transient( $reminder_key, '1', DAY_IN_SECONDS * 2 );
		}
	}
}

// ============================================================================
// تنظیمات — به‌صورتِ یک بخش در صفحه‌ی «لید مگنت»
// ============================================================================

add_action( 'wto_lead_magnet_extra_sections', 'wto_lm_reminder_render_section' );
function wto_lm_reminder_render_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$faraz_ready = function_exists( 'farazsms_is_ready' );
	$s           = wto_lm_reminder_settings();

	if ( isset( $_POST['wto_lm_reminder_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wto_lm_reminder_nonce'] ) ), 'wto_lm_reminder_save' ) ) {
		$s['enabled']   = isset( $_POST['lm_reminder_enabled'] ) ? '1' : '0';
		$s['reminders'] = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['lm_reminder_days'] ?? '' ) ) ) ) );
		if ( empty( $s['reminders'] ) ) {
			$s['reminders'] = array( 2, 1 );
		}
		if ( isset( $_POST['lm_reminder_message'] ) ) {
			$s['message'] = sanitize_textarea_field( wp_unslash( $_POST['lm_reminder_message'] ) );
		}

		if ( isset( $_POST['wto_lm_reminder_build'] ) ) {
			if ( ! $faraz_ready || ! function_exists( 'wto_create_pattern' ) ) {
				echo '<div class="notice notice-error"><p>ابتدا کلید دسترسی را در تنظیماتِ اصلیِ افزونه وارد کنید.</p></div>';
			} else {
				// %balance%→%var1% و %days_left%→%var2% — چون API نام‌های اصلی را خراب می‌سازد.
				$pattern_text = str_replace(
					array( '%balance%', '%days_left%', '{balance}', '{days_left}' ),
					array( '%var1%', '%var2%', '%var1%', '%var2%' ),
					$s['message']
				);
				$resp = wto_create_pattern( $pattern_text, 1, 'افزونه فراز اس ام اس / یادآور لید مگنت' );
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
					echo '<div class="notice notice-success"><p>✅ پترن ساخته شد: <code style="direction:ltr;">' . esc_html( $code ) . '</code> — پس از تأییدِ پنلِ فراز فعال می‌شود.</p></div>';
				} else {
					$em = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'پاسخ نامعتبر';
					echo '<div class="notice notice-error"><p>ساختِ پترن ناموفق: ' . esc_html( $em ) . '</p></div>';
				}
			}
		}

		update_option( WTO_LM_REMINDER_OPTION, $s, false );
		echo '<div class="notice notice-success"><p>تنظیماتِ یادآور ذخیره شد.</p></div>';
	}

	$enabled = $s['enabled'] === '1';
	$pattern = (string) $s['pattern'];
	?>
	<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-top:18px; direction:rtl; max-width:720px;">
		<h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">⏰ یادآورِ هدیه‌ی عضویت (قبل از انقضا)</h3>
		<p style="margin:0 0 14px; font-size:12.5px; color:#64748b; line-height:1.9;">
			به کاربرانی که هدیه‌ی عضویت گرفته‌اند ولی <strong>هنوز خرید نکرده‌اند</strong>، پیش از انقضای هدیه پیامکِ یادآوری می‌رود.
			به‌محضِ خرید/استفاده از هدیه، دیگر یادآوری ارسال نمی‌شود.
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'wto_lm_reminder_save', 'wto_lm_reminder_nonce' ); ?>

			<p style="margin:0 0 14px;">
				<label style="display:inline-flex; align-items:center; gap:10px; font-weight:600; font-size:13px;">
					<input type="checkbox" class="wto-toggle" name="lm_reminder_enabled" value="1" <?php checked( $enabled, true ); ?>>
					ارسالِ پیامکِ یادآور فعال باشد
				</label>
			</p>

			<p style="margin:0 0 12px;">
				<label style="display:block; font-size:12.5px; font-weight:600; color:#334155; margin-bottom:6px;">روزهای یادآوری قبل از انقضا (جدا با ,)</label>
				<input type="text" name="lm_reminder_days" value="<?php echo esc_attr( implode( ',', (array) $s['reminders'] ) ); ?>" placeholder="2,1" style="width:100%; max-width:320px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; direction:ltr; text-align:left;">
				<span style="display:block; margin-top:4px; font-size:11px; color:#64748b;">مثال: <code style="direction:ltr;">2,1</code> یعنی ۲ روز و ۱ روز مانده به انقضا. هر تعداد مجاز است (مثلاً <code style="direction:ltr;">6,4,2,1</code>).</span>
			</p>

			<p style="margin:0 0 8px;">
				<label style="display:block; font-size:12.5px; font-weight:600; color:#334155; margin-bottom:6px;">متن پیامک</label>
				<textarea name="lm_reminder_message" rows="4" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; direction:rtl; line-height:1.9; font-family:inherit; font-size:13px;"><?php echo esc_textarea( $s['message'] ); ?></textarea>
				<span style="display:block; margin-top:4px; font-size:11px; color:#64748b;">متغیرها: <code style="direction:ltr;">%balance%</code> (موجودیِ هدیه) و <code style="direction:ltr;">%days_left%</code> (روزهای مانده). نامِ برند را ثابت بنویسید (نه متغیر) تا پترن رد نشود.</span>
			</p>

			<p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:12px 0 0;">
				<button type="submit" name="wto_lm_reminder_build" value="1" class="button button-secondary" <?php disabled( ! $faraz_ready ); ?>>🤖 ساخت پترن</button>
				<span style="font-size:12.5px; color:#555;">کد پترن: <code style="direction:ltr; background:#f6f7f7; border:1px solid #ddd; padding:3px 8px; border-radius:4px;"><?php echo $pattern !== '' ? esc_html( $pattern ) : '—'; ?></code></span>
			</p>
			<p style="margin-top:16px;"><button type="submit" class="button button-primary">💾 ذخیره یادآور</button></p>
		</form>
	</div>
	<?php
}
