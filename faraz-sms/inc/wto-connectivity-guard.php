<?php
/**
 * نگهبانِ اتصال — تشخیصِ مسدود شدنِ درخواست‌ها به وب‌سرویسِ فراز اس ام اس.
 *
 * بعضی افزونه‌های امنیتی/سرعت (یا تنظیماتِ WP_HTTP_BLOCK_EXTERNAL در wp-config) جلوی
 * درخواست‌های خروجی به api.iranpayamak.com را می‌گیرند؛ در نتیجه پیامک ارسال نمی‌شود و
 * کاربر علتش را نمی‌داند. این ماژول علت را تشخیص می‌دهد و در پیشخوانِ وردپرس پیامِ روشن
 * (قابل‌فهم برای کاربر عادی) نمایش می‌دهد و راهکار می‌دهد: دامنه را در فهرستِ مجاز بگذارید.
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'WTO_SMS_API_HOST' ) ) {
	define( 'WTO_SMS_API_HOST', 'api.iranpayamak.com' );
}

/**
 * فهرستِ افزونه‌های شناخته‌شده‌ای که می‌توانند درخواست‌های خروجی را مسدود کنند.
 * کلید = مسیرِ افزونه، مقدار = نامِ نمایشی.
 *
 * @return array
 */
function wto_connectivity_known_blockers() {
	return array(
		'disable-external-requests/disable-external-requests.php' => 'Disable External Requests',
		'disable-remote-requests/disable-remote-requests.php'     => 'Disable Remote Requests',
		'block-external-requests/block-external-requests.php'     => 'Block External Requests',
		'advanced-external-request-blocker/advanced-external-request-blocker.php' => 'Advanced External Request Blocker',
		'wordfence/wordfence.php'                                 => 'Wordfence Security',
		'better-wp-security/better-wp-security.php'               => 'Solid Security (iThemes)',
		'all-in-one-wp-security-and-firewall/wp-security.php'     => 'All-In-One Security (AIOS)',
		'sucuri-scanner/sucuri.php'                               => 'Sucuri Security',
		'ninjafirewall/ninjafirewall.php'                         => 'NinjaFirewall',
		'wp-hide-security-enhancer/wp-hide.php'                   => 'WP Hide & Security Enhancer',
	);
}

/**
 * افزونه‌ای فعال است که توانِ مسدودسازی دارد؟ نام‌های نمایشیِ مظنون را برمی‌گرداند.
 *
 * @return array لیستِ نام‌های نمایشی
 */
function wto_connectivity_active_blockers() {
	$found = array();
	if ( ! function_exists( 'wto_is_plugin_active' ) ) {
		return $found;
	}
	foreach ( wto_connectivity_known_blockers() as $path => $label ) {
		if ( wto_is_plugin_active( $path ) ) {
			$found[] = $label;
		}
	}
	return $found;
}

/**
 * اگر مکانیزمِ هسته‌ی وردپرس (WP_HTTP_BLOCK_EXTERNAL) فعال باشد و دامنه‌ی ما در
 * فهرستِ مجاز (WP_ACCESSIBLE_HOSTS) نباشد، یعنی هسته جلوی ما را گرفته.
 *
 * @return bool
 */
function wto_connectivity_core_block_active() {
	if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
		return false;
	}
	$accessible = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) WP_ACCESSIBLE_HOSTS : '';
	// اگر دامنه‌ی ما (یا wildcard آن) در لیست باشد، مسدود نیست.
	if ( stripos( $accessible, WTO_SMS_API_HOST ) !== false ) {
		return false;
	}
	if ( stripos( $accessible, 'iranpayamak.com' ) !== false ) {
		return false;
	}
	return true;
}

/**
 * آزمونِ اتصال به وب‌سرویس — کش‌شده تا در هر بارگذاری اجرا نشود.
 * هر پاسخِ HTTP (حتی 401/404) یعنی اتصال برقرار است؛ فقط WP_Error یعنی مسدود/قطع.
 *
 * @param bool $force نادیده گرفتنِ کش
 * @return array ok(bool), reason(string), detail(string), blockers(array)
 */
function wto_connectivity_probe( $force = false ) {
	$cache_key = 'wto_connectivity_state';

	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$state = array( 'ok' => true, 'reason' => '', 'detail' => '', 'blockers' => array() );

	// ۱) مکانیزمِ هسته
	if ( wto_connectivity_core_block_active() ) {
		$state = array(
			'ok'       => false,
			'reason'   => 'core_block',
			'detail'   => 'WP_HTTP_BLOCK_EXTERNAL',
			'blockers' => wto_connectivity_active_blockers(),
		);
		set_transient( $cache_key, $state, 15 * MINUTE_IN_SECONDS );
		return $state;
	}

	// ۲) آزمونِ واقعی — درخواستِ سبک به دامنه‌ی وب‌سرویس.
	$resp = wp_remote_get( 'https://' . WTO_SMS_API_HOST . '/', array(
		'timeout'     => 8,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array( 'Accept' => 'application/json' ),
	) );

	if ( is_wp_error( $resp ) ) {
		$state = array(
			'ok'       => false,
			'reason'   => 'request_failed',
			'detail'   => $resp->get_error_message(),
			'blockers' => wto_connectivity_active_blockers(),
		);
		set_transient( $cache_key, $state, 15 * MINUTE_IN_SECONDS );
		return $state;
	}

	// هر پاسخِ HTTP = اتصال سالم.
	set_transient( $cache_key, $state, HOUR_IN_SECONDS );
	return $state;
}

/**
 * ثبتِ شکستِ ارسالِ واقعی از مسیرِ ارسالِ پیامک — تا اعلان بلافاصله ظاهر شود.
 * توابعِ ارسال (sms-api) در صورتِ خطای اتصال این را صدا می‌زنند.
 *
 * @param string $detail پیامِ خطا
 */
function wto_connectivity_note_failure( $detail = '' ) {
	$state = array(
		'ok'       => false,
		'reason'   => 'request_failed',
		'detail'   => (string) $detail,
		'blockers' => wto_connectivity_active_blockers(),
	);
	set_transient( 'wto_connectivity_state', $state, 15 * MINUTE_IN_SECONDS );
}

/**
 * ثبتِ موفقیتِ ارسال — پاک کردنِ وضعیتِ خطا.
 */
function wto_connectivity_note_success() {
	set_transient( 'wto_connectivity_state', array( 'ok' => true, 'reason' => '', 'detail' => '', 'blockers' => array() ), HOUR_IN_SECONDS );
}

/**
 * آیا در حالِ حاضر (طبقِ آخرین وضعیتِ ثبت‌شده) اتصال مسدود است؟
 * بدونِ زدنِ درخواستِ جدید — صرفاً وضعیتِ کش‌شده را می‌خواند (برای نمایشِ inline در داشبورد).
 *
 * @return bool
 */
function wto_connectivity_is_blocked() {
	$state = get_transient( 'wto_connectivity_state' );
	return is_array( $state ) && empty( $state['ok'] );
}

/**
 * HTMLِ اخطارِ «ارسال پیامک مسدود است» برای نمایشِ inline (مثلاً کنارِ موجودی در تنظیمات).
 * اگر مسدود نباشد، رشته‌ی خالی برمی‌گرداند.
 *
 * @return string
 */
function wto_connectivity_inline_warning_html() {
	if ( ! wto_connectivity_is_blocked() ) {
		return '';
	}
	$state    = get_transient( 'wto_connectivity_state' );
	$blockers = is_array( $state ) && ! empty( $state['blockers'] ) ? $state['blockers'] : array();
	if ( empty( $blockers ) ) {
		$blockers = wto_connectivity_active_blockers();
	}

	if ( ! empty( $blockers ) ) {
		$line = sprintf(
			__( 'افزونه‌ی «%s» جلوی ارسالِ درخواست به فراز اس ام اس را گرفته است؛ بنابراین موجودی/ارسال کار نمی‌کند (این به معنای نامعتبر بودنِ کلید نیست).', 'wto' ),
			implode( '، ', array_map( 'esc_html', $blockers ) )
		);
	} else {
		$line = __( 'یکی از افزونه‌های امنیتی/سرعتِ سایت (یا تنظیماتِ سرور) جلوی ارسالِ درخواست به فراز اس ام اس را گرفته است (این به معنای نامعتبر بودنِ کلید نیست).', 'wto' );
	}

	$html  = '<div style="margin-top:10px; background:#fff5f5; border:1px solid #fecaca; border-right:4px solid #d63638; border-radius:8px; padding:12px 14px; line-height:1.9;">';
	$html .= '<strong style="color:#b91c1c;">📵 ' . esc_html__( 'ارسال پیامک مسدود شده است', 'wto' ) . '</strong><br>';
	$html .= esc_html( $line ) . '<br>';
	$html .= esc_html__( 'راهکار: دامنه‌ی زیر را در فهرستِ مجازِ آن افزونه وارد کنید:', 'wto' );
	$html .= ' <code style="direction:ltr; background:#fff; border:1px solid #dcdcde; padding:2px 8px; border-radius:4px;">' . esc_html( WTO_SMS_API_HOST ) . '</code>';
	$html .= '</div>';
	return $html;
}

/**
 * اعلانِ پیشخوان — فقط برای مدیر، و فقط وقتی کلید API تنظیم شده (یعنی کاربر قصدِ ارسال دارد).
 */
add_action( 'admin_notices', 'wto_connectivity_admin_notice' );
function wto_connectivity_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// فقط وقتی کلید API تنظیم شده باشد سراغِ آزمون برو (سایتِ تازه را اذیت نکن).
	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( empty( $apikey ) ) {
		return;
	}

	$state = wto_connectivity_probe();
	if ( ! empty( $state['ok'] ) ) {
		return;
	}

	$host = WTO_SMS_API_HOST;

	// نامِ افزونه‌ی مظنون (اگر شناخته شد).
	$culprit_line = '';
	if ( ! empty( $state['blockers'] ) ) {
		$names = implode( '، ', array_map( 'esc_html', $state['blockers'] ) );
		$culprit_line = sprintf(
			/* translators: %s: plugin name(s) */
			__( 'به نظر می‌رسد افزونه‌ی «%s» جلوی ارسالِ پیامک را گرفته است.', 'wto' ),
			$names
		);
	} elseif ( $state['reason'] === 'core_block' ) {
		$culprit_line = __( 'تنظیمِ مسدودسازیِ درخواست‌های خارجی (WP_HTTP_BLOCK_EXTERNAL) روی سایتِ شما فعال است و دامنه‌ی فراز اس ام اس در فهرستِ مجاز نیست.', 'wto' );
	} else {
		$culprit_line = __( 'یکی از افزونه‌های امنیتی/سرعتِ سایتِ شما (یا تنظیماتِ سرور) جلوی ارسالِ پیامک را گرفته است.', 'wto' );
	}

	echo '<div class="notice notice-error" style="border-right:4px solid #d63638; padding:12px 16px;">';
	echo '<p style="font-size:14px; font-weight:700; margin:0 0 6px;">📵 ' . esc_html__( 'فراز اس ام اس: ارسالِ پیامک مسدود شده است', 'wto' ) . '</p>';
	echo '<p style="margin:0 0 6px; line-height:1.9;">' . esc_html( $culprit_line ) . '</p>';
	echo '<p style="margin:0; line-height:1.9;">'
		. esc_html__( 'برای رفعِ مشکل، در تنظیماتِ آن افزونه (یا در فهرستِ مجازِ سایت)، دامنه‌ی زیر را به‌عنوان مجاز (Whitelist) وارد کنید تا درخواست‌های ارسالِ پیامکِ شما به فراز اس ام اس برسد:', 'wto' )
		. '</p>';
	echo '<p style="margin:6px 0 0;"><code style="direction:ltr; display:inline-block; background:#fff; border:1px solid #dcdcde; padding:4px 10px; border-radius:4px; font-size:14px;">' . esc_html( $host ) . '</code></p>';
	echo '</div>';
}
