<?php
/**
 * MihanPanel SMS Provider Class — کلاس پیاده‌سازی interface میهن پنل.
 *
 * این فایل توسط میهن پنل از طریق
 *
 *   require_once( $path )
 *
 * بارگذاری می‌شود. در زمان بارگذاری، interface میهن پنل باید در دسترس باشد. اگر
 * به هر دلیلی نباشد (مثلاً میهن پنل deactivate شده)، با guard زیر از fatal error
 * جلوگیری می‌کنیم.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// محافظت در برابر بارگذاری از مسیر اشتباه:
// اگر interface میهن پنل وجود نداشته باشد، کلاس را تعریف نمی‌کنیم.
if ( ! interface_exists( '\\mihanpanel\\pro\\app\\contracts\\sms_provider_interface' ) ) {
	return;
}

// همچنین اگر کلاس قبلاً تعریف شده (به‌دلیل require_once دوباره) رد شو.
if ( class_exists( 'WTO_FarazSMS_Mihanpanel_Provider' ) ) {
	return;
}

use mihanpanel\pro\app\contracts\sms_provider_interface;

/**
 * پیاده‌سازی provider برای میهن پنل.
 *
 * طبق مستندات، چهار متد static باید پیاده‌سازی شود:
 *
 *   send( $to, $msg )           — ارسال پیامک
 *   render_settings()            — UI تنظیمات در پنل میهن
 *   get_provider_settings()      — لیست option keys
 *   validate_send_message( $r )  — تأیید موفقیت ارسال
 *
 * نکته مهم: پیامک‌های میهن پنل برای OTP هستند و طبق سیاست‌های فراز اس‌ام‌اس
 * فقط با پترن قابل ارسال‌اند. کاربر باید یک پترن با حداقل یک متغیر برای کد OTP
 * در پنل فراز بسازد و کد آن را اینجا وارد کند.
 *
 * متن OTP از سمت میهن پنل به صورت زیر می‌آید (مثال):
 *
 *   کد تایید شما: 12345
 *
 * ما کد عددی (اولین رشته رقم ۴ تا ۸ کاراکتری) را استخراج می‌کنیم و به‌عنوان
 * مقدار متغیر پترن می‌فرستیم.
 */
class WTO_FarazSMS_Mihanpanel_Provider implements sms_provider_interface {

	/**
	 * ارسال پیامک از طریق وب‌سرویس پترن فراز اس‌ام‌اس.
	 *
	 * @param string $to  شماره موبایل گیرنده (مثلاً 09xxxxxxxxx یا +989xxxxxxxxx)
	 * @param string $msg متن پیام شامل کد OTP
	 *
	 * @return array|WP_Error پاسخ wp_remote_post یا WP_Error
	 */
	public static function send( $to, $msg ) {
		$apikey       = trim( (string) get_option( 'wto_apikey', '' ) );
		$pattern_code = trim( (string) get_option( 'wto_mihanpanel_pattern_code', '' ) );
		$var_name     = trim( (string) get_option( 'wto_mihanpanel_variable_name', 'code' ) );
		if ( $var_name === '' ) {
			$var_name = 'code';
		}
		$sender = trim( (string) get_option( 'wto_sender', '' ) );
		if ( $sender === '' ) {
			$sender = '90008361'; // خط پیش‌فرض فراز
		}

		// تنظیمات ضروری
		if ( $apikey === '' ) {
			return new WP_Error( 'wto_mihanpanel_no_apikey', 'کلید دسترسی (Api-Key) فراز اس‌ام‌اس وارد نشده است.' );
		}
		if ( $pattern_code === '' ) {
			return new WP_Error( 'wto_mihanpanel_no_pattern', 'کد پترن میهن پنل در تنظیمات وارد نشده است.' );
		}

		// نرمال‌سازی شماره به 09xxxxxxxxx
		if ( function_exists( 'wto_normalize_phone' ) ) {
			$to = wto_normalize_phone( $to );
		}
		$to = preg_replace( '/[^0-9]/', '', (string) $to );
		// اگر بعد از نرمال‌سازی فرمت غلط بود، Error برگردانیم.
		if ( ! preg_match( '/^09\d{9}$/', $to ) ) {
			return new WP_Error( 'wto_mihanpanel_bad_phone', 'شماره موبایل گیرنده نامعتبر است: ' . $to );
		}

		// استخراج کد OTP از متن پیام (longest match بین ۴ تا ۸ رقمی)
		$otp_code = '';
		if ( preg_match_all( '/\d{4,8}/u', (string) $msg, $matches ) ) {
			foreach ( $matches[0] as $m ) {
				if ( strlen( $m ) > strlen( $otp_code ) ) {
					$otp_code = $m;
				}
			}
		}
		// اگر استخراج موفق نبود، کل متن را به‌عنوان مقدار متغیر بفرستیم.
		if ( $otp_code === '' ) {
			$otp_code = trim( (string) $msg );
		}

		$payload = array(
			'code'          => $pattern_code,
			'recipient'     => $to,
			'attributes'    => array( $var_name => $otp_code ),
			'line_number'   => $sender,
			'number_format' => 'english',
		);

		$request_args = array(
			'method'    => 'POST',
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'Api-Key'      => $apikey,
			),
			'body'      => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		);

		// استفاده از helper فراز با fallback به cURL برای هاست‌های مسدود.
		if ( function_exists( 'wto_remote_post_with_fallback' ) ) {
			$response = wto_remote_post_with_fallback( 'https://api.iranpayamak.com/ws/v1/sms/pattern', $request_args );
		} else {
			$response = wp_remote_post( 'https://api.iranpayamak.com/ws/v1/sms/pattern', $request_args );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			error_log( sprintf( '[wto-mihanpanel] sent to=%s pattern=%s code=%d body=%s',
				$to, $pattern_code, (int) $code, substr( (string) $body, 0, 150 ) ) );
		}

		return $response;
	}

	/**
	 * فیلدهای تنظیمات که در صفحه میهن پنل نشان داده می‌شوند.
	 * نام input ها باید با کلیدهای get_provider_settings() یکسان باشد.
	 */
	public static function render_settings() {
		$api_key      = get_option( 'wto_apikey', '' );
		$pattern_code = get_option( 'wto_mihanpanel_pattern_code', '' );
		$var_name     = get_option( 'wto_mihanpanel_variable_name', 'code' );
		$sender       = get_option( 'wto_sender', '' );
		?>
		<style>
			.wto-mihanpanel-settings { direction: rtl; font-family: Tahoma, IRANSans, Vazir, sans-serif; }
			.wto-mihanpanel-settings p { margin: 12px 0; }
			.wto-mihanpanel-settings label { display: block; font-weight: 600; color: #0f172a; margin-bottom: 4px; font-size: 13px; }
			.wto-mihanpanel-settings input[type="text"] { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; direction: ltr; text-align: left; }
			.wto-mihanpanel-settings input[type="text"]:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
			.wto-mihanpanel-settings .desc { font-size: 11px; color: #64748b; display: block; margin-top: 4px; }
			.wto-mihanpanel-settings .notice-box { background: #eef2ff; border: 1px solid #c7d2fe; padding: 12px 16px; border-radius: 8px; color: #3730a3; line-height: 1.8; margin-bottom: 18px; }
			.wto-mihanpanel-settings .warn-box { background: #fffbeb; border: 1px solid #fde68a; padding: 10px 14px; border-radius: 6px; color: #78350f; line-height: 1.8; margin-top: 16px; }
			.wto-mihanpanel-settings code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
		</style>
		<div class="wto-mihanpanel-settings">
			<div class="notice-box">
				<strong>💡 راهنمای اتصال:</strong>
				پیامک‌های میهن پنل (OTP ورود/ثبت‌نام) فقط از طریق <strong>پترن</strong> ارسال می‌شوند. مراحل:
				<ol style="margin: 8px 0 0 18px; padding: 0;">
					<li>وارد پنل فراز اس‌ام‌اس شوید (<a href="https://sms.farazsms.com/" target="_blank" rel="noopener">sms.farazsms.com</a>)</li>
					<li>یک پترن با یک متغیر بسازید — مثلاً: <code>کد تایید شما در [نام برند فروشگاه]: %code%</code> (نام برند را به‌صورت ثابت در متن بنویسید)</li>
					<li>بعد از تأیید پترن، کد آن را در فیلد «کد پترن» پایین کپی کنید</li>
				</ol>
			</div>

			<p>
				<label for="wto_apikey">کلید دسترسی (Api-Key) <span style="color:#dc2626;">*</span></label>
				<input value="<?php echo esc_attr( $api_key ); ?>" type="text" name="wto_apikey" id="wto_apikey">
				<span class="desc">این کلید با تنظیمات اصلی افزونه فراز اس‌ام‌اس و افزونه پیامک ووکامرس مشترک است.</span>
			</p>

			<p>
				<label for="wto_mihanpanel_pattern_code">کد پترن میهن پنل <span style="color:#dc2626;">*</span></label>
				<input value="<?php echo esc_attr( $pattern_code ); ?>" type="text" name="wto_mihanpanel_pattern_code" id="wto_mihanpanel_pattern_code">
				<span class="desc">کد پترنی که در پنل فراز برای OTP میهن پنل ساخته‌اید (مثلاً <code>aB12cD34</code>).</span>
			</p>

			<p>
				<label for="wto_mihanpanel_variable_name">نام متغیر کد در پترن</label>
				<input value="<?php echo esc_attr( $var_name ); ?>" type="text" name="wto_mihanpanel_variable_name" id="wto_mihanpanel_variable_name" placeholder="code">
				<span class="desc">نام متغیر داخل متن پترن. پیش‌فرض: <code>code</code>. اگر متغیر شما اسم دیگری دارد (مثل <code>otp</code>، <code>verify</code>)، اینجا وارد کنید.</span>
			</p>

			<p>
				<label for="wto_sender">خط ارسال (Line Number)</label>
				<input value="<?php echo esc_attr( $sender ); ?>" type="text" name="wto_sender" id="wto_sender">
				<span class="desc">اختیاری. اگر خالی باشد، خط پیش‌فرض پنل فراز استفاده می‌شود.</span>
			</p>

			<div class="warn-box">
				<strong>⚠️ نکات مهم:</strong>
				<ul style="margin: 6px 0 0 18px; padding: 0;">
					<li>پترن فقط باید <strong>یک متغیر</strong> داشته باشد (کد OTP)</li>
					<li>برند فروشگاه شما <strong>باید در متن پترن hardcoded</strong> باشد (نه به‌صورت متغیر) — در غیر این صورت پنل فراز پترن را تأیید نمی‌کند</li>
					<li>تأیید پترن توسط مدیر پنل فراز معمولاً تا یک ساعت طول می‌کشد</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * کلیدهای option که میهن پنل باید مدیریت کند (register_setting انجام دهد).
	 */
	public static function get_provider_settings() {
		return array(
			'wto_apikey',
			'wto_mihanpanel_pattern_code',
			'wto_mihanpanel_variable_name',
			'wto_sender',
		);
	}

	/**
	 * تأیید موفقیت ارسال — میهن پنل پاسخ send() را به این متد می‌فرستد.
	 *
	 * @param mixed $response خروجی send() — array یا WP_Error
	 * @return bool
	 */
	public static function validate_send_message( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return false;
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return false;
		}
		if ( isset( $data['status'] ) && $data['status'] === 'success' ) {
			return true;
		}
		// fallback: HTTP 2xx + بدون پیام خطا → success
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 && ! isset( $data['message'] ) ) {
			return true;
		}
		return false;
	}
}
