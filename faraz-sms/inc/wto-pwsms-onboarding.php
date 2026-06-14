<?php
/**
 * PW SMS Onboarding Bridge — Phase 8 (v3.13.1)
 *
 * این فایل سه قابلیت اضافه می‌کند:
 *
 *   ۱) همگام‌سازی دوطرفه کلید دسترسی بین تنظیمات افزونه ما و افزونه «پیامک ووکامرس».
 *      کاربر فقط در یکی از دو جا کلید را وارد کند، خودش به جای دیگر کپی می‌شود.
 *
 *   ۲) پنل وضعیت اتصال + اعتبار پنل در بالای صفحه تنظیمات افزونه پیامک ووکامرس
 *
 *        /wp-admin/admin.php?page=persian-woocommerce-sms-pro
 *
 *   ۳) دکمه «ارسال پیامک تست» با پترن از پیش تعریف‌شده برای تست اتصال:
 *
 *        پترن: j3MnVzhrMj (بدون متغیر)
 *
 * نکات امنیتی مهم:
 *
 *   - تمام endpointهای AJAX با
 *
 *       check_ajax_referer()
 *
 *     و
 *
 *       current_user_can( 'manage_woocommerce' )
 *
 *     محافظت می‌شوند.
 *   - حلقه بازگشتی همگام‌سازی (sync loop) با remove_action قبل از update_option
 *     جلوگیری می‌شود.
 *   - کلید دسترسی هرگز در پاسخ AJAX برگردانده نمی‌شود.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/* ════════════════════════════════════════════════════════════════════════ *
 *  بخش ۱: همگام‌سازی دوطرفه کلید دسترسی
 *
 *  منبع‌ها:
 *    - افزونه ما:    option('wto_apikey')
 *    - افزونه PWSMS: option('sms_main_settings')['sms_gateway_apikey']
 *
 *  هرگاه یکی تغییر کند، دیگری هم به‌روز می‌شود (با محافظت در برابر recursion).
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'update_option_sms_main_settings', 'wto_sync_apikey_from_pwsms', 12, 2 );
add_action( 'add_option_sms_main_settings',    'wto_sync_apikey_from_pwsms_added', 12, 2 );

function wto_sync_apikey_from_pwsms_added( $name, $value ) {
	wto_sync_apikey_from_pwsms( array(), $value );
}

function wto_sync_apikey_from_pwsms( $old_value, $value ) {
	if ( ! is_array( $value ) ) {
		return;
	}

	// تشخیص کلید جدید با اولویت apikey > username > password
	$new_apikey = '';
	if ( ! empty( $value['sms_gateway_apikey'] ) ) {
		$new_apikey = trim( (string) $value['sms_gateway_apikey'] );
	} elseif ( ! empty( $value['sms_gateway_username'] ) ) {
		$new_apikey = trim( (string) $value['sms_gateway_username'] );
	} elseif ( ! empty( $value['sms_gateway_password'] ) ) {
		$new_apikey = trim( (string) $value['sms_gateway_password'] );
	}

	if ( $new_apikey === '' ) {
		return;
	}

	$current = (string) get_option( 'wto_apikey', '' );
	if ( $current === $new_apikey ) {
		return; // قبلاً sync شده
	}

	// قبل از update مسیر برعکس را حذف می‌کنیم تا حلقه بازگشتی رخ ندهد.
	remove_action( 'update_option_wto_apikey', 'wto_sync_apikey_to_pwsms', 12 );
	remove_action( 'add_option_wto_apikey',    'wto_sync_apikey_to_pwsms_added', 12 );

	update_option( 'wto_apikey', $new_apikey );

	add_action( 'update_option_wto_apikey', 'wto_sync_apikey_to_pwsms', 12, 2 );
	add_action( 'add_option_wto_apikey',    'wto_sync_apikey_to_pwsms_added', 12, 2 );

	// Cache اعتبار را پاک کن تا با کلید جدید fetch جدید بشه.
	delete_transient( 'wto_credit_' . md5( $current ) );
	delete_transient( 'wto_credit_' . md5( $new_apikey ) );
}

add_action( 'update_option_wto_apikey', 'wto_sync_apikey_to_pwsms', 12, 2 );
add_action( 'add_option_wto_apikey',    'wto_sync_apikey_to_pwsms_added', 12, 2 );

function wto_sync_apikey_to_pwsms_added( $name, $value ) {
	wto_sync_apikey_to_pwsms( '', $value );
}

function wto_sync_apikey_to_pwsms( $old_value, $value ) {
	$new_apikey = trim( (string) $value );
	if ( $new_apikey === '' ) {
		return;
	}

	$settings = get_option( 'sms_main_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$current        = isset( $settings['sms_gateway_apikey'] )   ? trim( (string) $settings['sms_gateway_apikey'] )   : '';
	$legacy_current = isset( $settings['sms_gateway_username'] ) ? trim( (string) $settings['sms_gateway_username'] ) : '';
	if ( $current === $new_apikey && $legacy_current === $new_apikey ) {
		return;
	}

	$settings['sms_gateway_apikey'] = $new_apikey;
	// همچنین در sms_gateway_username می‌نویسیم تا گیتوی built-in قدیمی PWSMS
	// (FarazSMSToken) که فقط username/password می‌خواند هم با همان کلید کار کند.
	// این کار سازگاری به عقب را تضمین می‌کند بدون اینکه کاربر مجبور باشد دوبار وارد کند.
	$settings['sms_gateway_username'] = $new_apikey;

	// قبل از update مسیر برعکس را حذف می‌کنیم.
	remove_action( 'update_option_sms_main_settings', 'wto_sync_apikey_from_pwsms', 12 );
	remove_action( 'add_option_sms_main_settings',    'wto_sync_apikey_from_pwsms_added', 12 );

	$result = update_option( 'sms_main_settings', $settings, false );

	add_action( 'update_option_sms_main_settings', 'wto_sync_apikey_from_pwsms', 12, 2 );
	add_action( 'add_option_sms_main_settings',    'wto_sync_apikey_from_pwsms_added', 12, 2 );

	delete_transient( 'wto_credit_' . md5( $new_apikey ) );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( '[wto-pwsms] sync TO pwsms: key=%s len=%d result=%s', substr( $new_apikey, 0, 4 ) . '…', strlen( $new_apikey ), $result ? 'ok' : 'no-change' ) );
	}
}

/**
 * در بارگذاری اولیه نیز اگر بین دو منبع ناهماهنگی باشد، sync اولیه را انجام می‌دهد.
 * این برای نصب‌های قدیمی است که قبل از این ویژگی کلید را در یکی از دو جا داشتند.
 */
add_action( 'admin_init', 'wto_pwsms_initial_apikey_sync', 5 );
function wto_pwsms_initial_apikey_sync() {
	// v3.13.13 PERF: روی هر admin_init این تابع دو get_option اجرا می‌کرد. حالا
	// با یک static flag + transient ۱-ساعته، فقط یک بار در ساعت چک می‌شود.
	// این برای مدیران سایت‌های پربازدید (هزاران admin pageload در روز) حدوداً
	// ۹۹٪ کاهش option read می‌دهد.
	static $checked_this_request = false;
	if ( $checked_this_request ) {
		return;
	}
	$checked_this_request = true;
	if ( get_transient( 'wto_pwsms_sync_checked' ) ) {
		return;
	}
	set_transient( 'wto_pwsms_sync_checked', '1', HOUR_IN_SECONDS );

	if ( ! function_exists( 'PWSMS' ) ) {
		return;
	}
	$wto_key   = trim( (string) get_option( 'wto_apikey', '' ) );
	$pwsms_key = trim( (string) PWSMS()->get_option( 'sms_gateway_apikey' ) );
	if ( $pwsms_key === '' ) {
		$pwsms_key = trim( (string) PWSMS()->get_option( 'sms_gateway_username' ) );
	}

	// اگر هر دو خالی هستند یا هر دو برابرند، کاری نکن.
	if ( $wto_key === $pwsms_key ) {
		return;
	}

	// اگر فقط یکی پر است، آن را به طرف دیگر کپی کن.
	if ( $wto_key !== '' && $pwsms_key === '' ) {
		wto_sync_apikey_to_pwsms( '', $wto_key );
	} elseif ( $pwsms_key !== '' && $wto_key === '' ) {
		$settings = array(
			'sms_gateway_apikey' => $pwsms_key,
		);
		wto_sync_apikey_from_pwsms( array(), $settings );
	}
	// اگر هر دو پر هستند ولی متفاوت، احتمالاً کاربر اخیراً یکی را آپدیت کرده —
	// به update_option hook اعتماد می‌کنیم و در این مرحله مداخله نمی‌کنیم.
}

/* ════════════════════════════════════════════════════════════════════════ *
 *  بخش ۲: پنل وضعیت اتصال + تست SMS بالای صفحه PWSMS
 *
 *  hook: pwoosms_settings_form_top_sms_main_settings
 *  (در داخل تب وبسرویس قبل از فرم اصلی رندر می‌شود)
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Render the panel on our own plugin's settings page (farazwto-settings).
 * Hook into admin_notices which fires inside the unified frame, above the page's form.
 */
add_action( 'admin_notices', 'wto_render_panel_on_settings_page', 20 );
function wto_render_panel_on_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $page !== 'farazwto-settings' ) {
		return;
	}
	wto_render_connection_test_panel( array(
		'title'          => 'وضعیت اتصال و تست پیامک',
		'intro'          => 'پس از وارد کردن کلید دسترسی و ذخیره، اتصال خودکار بررسی می‌شود. می‌توانید یک پیامک تست برای اطمینان از صحت اتصال ارسال کنید.',
		'show_help_link' => true,
	) );
}

add_action( 'pwoosms_settings_form_top_sms_main_settings', 'wto_pwsms_render_onboarding_panel', 5 );
function wto_pwsms_render_onboarding_panel( $form ) {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// تنها برای gatewayی که FarazSMSNext است این پنل را نمایش می‌دهیم
	// (یا گیتوی هنوز انتخاب نشده و کاربر در حال راه‌اندازی است).
	$selected_gateway = '';
	if ( function_exists( 'PWSMS' ) ) {
		$selected_gateway = (string) PWSMS()->get_option( 'sms_gateway' );
	}
	$is_faraz = ( $selected_gateway === '' ||
				strpos( $selected_gateway, 'FarazSMSNext' ) !== false ||
				strpos( $selected_gateway, 'IranPayamak' ) !== false ||
				strpos( $selected_gateway, 'FarazSMS' ) !== false ||
				strpos( $selected_gateway, 'farazsms' ) !== false );
	if ( ! $is_faraz ) {
		// روی گیتوی دیگری است؛ پنل ما را نشان نده.
		return;
	}

	wto_render_connection_test_panel( array(
		'title'  => 'راه‌اندازی فراز اس‌ام‌اس',
		'intro'  => 'کلید دسترسی (Api-Key) را در فیلد زیر وارد کنید و ذخیره نمایید. اتصال و موجودی پنل خودکار بررسی می‌شود — همچنین می‌توانید یک پیامک تست برای اطمینان ارسال کنید.',
		'show_help_link' => true,
	) );
}

/**
 * Render the reusable Connection Status + Test SMS panel.
 *
 * این تابع همان پنلی است که در صفحه افزونه پیامک ووکامرس نشان داده می‌شود،
 * اما می‌تواند از هر صفحه‌ای از پلاگین فراخوانی شود (صفحه تنظیمات ما، داشبورد، و ...).
 *
 * Args:
 *   - title          (string) عنوان پنل
 *   - intro          (string) متن کوتاه زیر عنوان
 *   - show_help_link (bool)   آیا لینک «دریافت Api-Key از پنل» نشان داده شود
 *   - compact        (bool)   حالت کم‌حجم بدون hero (برای داشبورد)
 *
 * CSS و JS فقط در اولین فراخوانی emit می‌شوند (static guard).
 */
function wto_render_connection_test_panel( $args = array() ) {
	static $instance = 0;
	static $assets_printed = false;
	$instance++;

	$args = wp_parse_args( $args, array(
		'title'          => 'وضعیت اتصال و تست پیامک',
		'intro'          => 'برای اطمینان از اتصال، وضعیت پنل را بررسی کنید و یک پیامک تست ارسال نمایید.',
		'show_help_link' => false,
		'compact'        => false,
	) );

	$nonce = wp_create_nonce( 'wto_pwsms_panel' );
	$portal_url = function_exists( 'wto_get_farazsms_portal_url' )
		? wto_get_farazsms_portal_url()
		: 'https://sms.farazsms.com/';

	// رندر سرور-ساید اعتبار + وضعیت اتصال — همان منطق داشبورد و admin bar.
	// این کار باعث می‌شود حتی اگر AJAX کار نکند، کاربر در لحظه‌ی load صفحه
	// اعتبار و وضعیت اتصال را ببیند.
	$initial_apikey = wto_pwsms_resolve_apikey();
	$initial_credit = '';
	$initial_state  = 'no-key'; // no-key | connected | disconnected
	if ( $initial_apikey !== '' && function_exists( 'wto_get_credit' ) ) {
		// wto_get_credit() مقدار فرمت‌شده برمی‌گرداند یا false در صورت خطا.
		$c = wto_get_credit();
		if ( $c !== false && $c !== '' ) {
			$initial_credit = (string) $c;
			$initial_state  = 'connected';
		} else {
			$initial_state = 'disconnected';
		}
	}
	?>
	<div class="wto-pwsms-panel<?php echo $args['compact'] ? ' wto-pwsms-panel--compact' : ''; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<?php if ( ! $args['compact'] ) : ?>
		<div class="wto-pwsms-panel__hero">
			<div class="wto-pwsms-panel__hero-icon">
				<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt="فراز اس‌ام‌اس">
			</div>
			<div class="wto-pwsms-panel__hero-text">
				<h3><?php echo esc_html( $args['title'] ); ?></h3>
				<p><?php echo esc_html( $args['intro'] ); ?></p>
				<?php if ( $args['show_help_link'] ) : ?>
				<p class="wto-pwsms-panel__hero-help">
					Api-Key را از پنل فراز اس‌ام‌اس دریافت کنید:
					<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener">ورود به پنل</a>
					← مدیریت کلیدهای دسترسی → ساخت کلید جدید → کپی کلید.
				</p>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! $args['compact'] ) : // در compact mode (داشبورد) کارت‌های وضعیت/اعتبار را پنهان می‌کنیم چون stat cards بالای داشبورد همان اطلاعات را نمایش می‌دهند. ?>
		<div class="wto-pwsms-panel__grid">
			<!-- وضعیت اتصال (رندر اولیه سمت سرور) -->
			<div class="wto-pwsms-card wto-pwsms-status-card" data-state="<?php echo esc_attr( $initial_state ); ?>">
				<div class="wto-pwsms-card__head">
					<span class="wto-pwsms-card__icon dashicons dashicons-rest-api"></span>
					<span class="wto-pwsms-card__label">وضعیت اتصال</span>
				</div>
				<div class="wto-pwsms-card__value">
					<?php if ( $initial_state === 'connected' ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#16a34a;"></span>
						<span class="wto-pwsms-card__text">متصل ✓</span>
					<?php elseif ( $initial_state === 'no-key' ) : ?>
						<span class="dashicons dashicons-warning" style="color:#dc2626;"></span>
						<span class="wto-pwsms-card__text">Api-Key وارد نشده</span>
					<?php else : ?>
						<span class="dashicons dashicons-warning" style="color:#dc2626;"></span>
						<span class="wto-pwsms-card__text">اتصال برقرار نیست</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- اعتبار پنل (رندر اولیه سمت سرور با wto_get_credit) -->
			<div class="wto-pwsms-card wto-pwsms-credit-card" data-state="<?php echo esc_attr( $initial_state ); ?>">
				<div class="wto-pwsms-card__head">
					<span class="wto-pwsms-card__icon dashicons dashicons-money-alt"></span>
					<span class="wto-pwsms-card__label">اعتبار پنل</span>
				</div>
				<div class="wto-pwsms-card__value">
					<span class="wto-pwsms-card__credit"><?php echo esc_html( $initial_credit !== '' ? $initial_credit : '—' ); ?></span>
					<span class="wto-pwsms-card__credit-unit">تومان</span>
				</div>
			</div>

			<!-- دکمه refresh -->
			<div class="wto-pwsms-card wto-pwsms-card--action">
				<button type="button" class="button wto-pwsms-refresh-btn">
					<span class="dashicons dashicons-update-alt"></span>
					تازه‌سازی وضعیت
				</button>
			</div>
		</div>
		<?php endif; // end of !compact for status grid ?>

		<!-- تست SMS -->
		<div class="wto-pwsms-test">
			<h4 class="wto-pwsms-test__title">
				<span class="dashicons dashicons-email-alt"></span>
				ارسال پیامک تست
			</h4>
			<p class="wto-pwsms-test__desc">
				پیامک نمونه برای اطمینان از اتصال (با پترن مخصوص تست فراز اس‌ام‌اس) ارسال می‌شود.
			</p>
			<div class="wto-pwsms-test__form">
				<label>شماره موبایل گیرنده:</label>
				<input type="text" class="wto-pwsms-test-mobile" placeholder="09xxxxxxxxx" maxlength="13" autocomplete="off">
				<button type="button" class="button button-primary wto-pwsms-test-send">
					<span class="dashicons dashicons-yes"></span>
					ارسال پیامک تست
				</button>
			</div>
			<div class="wto-pwsms-test__result" style="display:none;"></div>
		</div>
	</div>
	<?php
	// CSS و JS فقط یک بار در صفحه emit می‌شوند (حتی اگر چندین پنل وجود داشته باشد).
	if ( $assets_printed ) {
		return;
	}
	$assets_printed = true;
	?>

	<style id="wto-pwsms-panel-css">
	.wto-pwsms-panel {
		direction: rtl;
		background: #fff;
		border: 1px solid #e2e8f0;
		border-radius: 12px;
		padding: 20px 22px;
		margin: 0 0 24px;
		box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
		font-family: Tahoma, 'IRANSans', 'Vazir', sans-serif;
	}
	.wto-pwsms-panel * { box-sizing: border-box; }

	.wto-pwsms-panel__hero {
		display: flex;
		gap: 16px;
		align-items: flex-start;
		padding-bottom: 18px;
		margin-bottom: 18px;
		border-bottom: 1px solid #f1f5f9;
	}
	.wto-pwsms-panel__hero-icon {
		flex-shrink: 0;
		width: 64px;
		height: 64px;
		background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
		border-radius: 14px;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 12px;
	}
	.wto-pwsms-panel__hero-icon img {
		width: 100%;
		height: auto;
		filter: brightness(0) invert(1);
	}
	.wto-pwsms-panel__hero-text h3 {
		margin: 0 0 6px;
		font-size: 18px;
		color: #0f172a;
		font-weight: 700;
	}
	.wto-pwsms-panel__hero-text p {
		margin: 0 0 6px;
		color: #475569;
		font-size: 13px;
		line-height: 1.9;
	}
	.wto-pwsms-panel__hero-help {
		color: #64748b !important;
		font-size: 12px !important;
	}
	.wto-pwsms-panel__hero-help a {
		color: #4338ca;
		text-decoration: none;
		font-weight: 600;
	}
	.wto-pwsms-panel__hero-help a:hover { text-decoration: underline; }

	.wto-pwsms-panel__grid {
		display: grid;
		grid-template-columns: 1fr 1fr auto;
		gap: 12px;
		margin-bottom: 22px;
	}
	.wto-pwsms-card {
		background: #f8fafc;
		border: 1px solid #e2e8f0;
		border-radius: 10px;
		padding: 14px 16px;
		min-height: 78px;
	}
	.wto-pwsms-card__head {
		display: flex;
		align-items: center;
		gap: 6px;
		margin-bottom: 6px;
	}
	.wto-pwsms-card__icon { font-size: 16px; width: 16px; height: 16px; color: #94a3b8; }
	.wto-pwsms-card__label { font-size: 12px; color: #64748b; font-weight: 500; }
	.wto-pwsms-card__value {
		display: flex;
		align-items: baseline;
		gap: 5px;
		font-size: 16px;
		font-weight: 700;
		color: #0f172a;
	}
	.wto-pwsms-card__text { color: #64748b; font-size: 14px; font-weight: 500; }
	.wto-pwsms-card__credit { font-size: 22px; color: #0f172a; }
	.wto-pwsms-card__credit-unit { font-size: 11px; color: #64748b; font-weight: 500; }

	.wto-pwsms-card[data-state="connected"] { background: #f0fdf4; border-color: #bbf7d0; }
	.wto-pwsms-card[data-state="connected"] .wto-pwsms-card__icon { color: #16a34a; }
	.wto-pwsms-card[data-state="connected"] .wto-pwsms-card__text { color: #16a34a; }
	.wto-pwsms-card[data-state="disconnected"] { background: #fef2f2; border-color: #fecaca; }
	.wto-pwsms-card[data-state="disconnected"] .wto-pwsms-card__icon { color: #dc2626; }
	.wto-pwsms-card[data-state="disconnected"] .wto-pwsms-card__text { color: #dc2626; }
	.wto-pwsms-card[data-state="loading"] .wto-pwsms-card__text { color: #64748b; }

	.wto-pwsms-card--action {
		background: transparent;
		border: none;
		padding: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		min-width: 130px;
	}
	.wto-pwsms-card--action .button {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 8px 14px;
		height: auto;
	}
	.wto-pwsms-card--action .button .dashicons { font-size: 16px; width: 16px; height: 16px; }
	.wto-pwsms-card--action .button.is-loading .dashicons { animation: wto-pwsms-spin 0.8s linear infinite; }

	.wto-pwsms-spinner {
		display: inline-block;
		width: 14px;
		height: 14px;
		border: 2px solid #cbd5e1;
		border-top-color: #6366f1;
		border-radius: 50%;
		animation: wto-pwsms-spin 0.8s linear infinite;
	}
	@keyframes wto-pwsms-spin {
		to { transform: rotate(360deg); }
	}

	.wto-pwsms-test {
		background: #f8fafc;
		border: 1px solid #e2e8f0;
		border-radius: 10px;
		padding: 16px 18px;
	}
	.wto-pwsms-test__title {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0 0 6px;
		font-size: 14px;
		color: #0f172a;
		font-weight: 700;
	}
	.wto-pwsms-test__title .dashicons { color: #6366f1; }
	.wto-pwsms-test__desc {
		margin: 0 0 12px;
		color: #64748b;
		font-size: 12px;
	}
	.wto-pwsms-test__form {
		display: flex;
		gap: 8px;
		align-items: center;
		flex-wrap: wrap;
	}
	.wto-pwsms-test__form label {
		font-size: 13px;
		color: #475569;
		font-weight: 500;
	}
	.wto-pwsms-test__form input[type="text"] {
		flex: 0 1 200px;
		padding: 6px 10px;
		border: 1px solid #cbd5e1;
		border-radius: 6px;
		direction: ltr;
		text-align: center;
		font-family: monospace;
		font-size: 14px;
	}
	.wto-pwsms-test__form input[type="text"]:focus {
		outline: none;
		border-color: #6366f1;
		box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
	}
	.wto-pwsms-test__form .button {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 7px 16px;
		height: auto;
	}
	.wto-pwsms-test__form .button .dashicons { font-size: 14px; width: 14px; height: 14px; }
	.wto-pwsms-test__form .button.is-loading .dashicons { animation: wto-pwsms-spin 0.8s linear infinite; }
	.wto-pwsms-test__result {
		margin-top: 12px;
		padding: 10px 14px;
		border-radius: 8px;
		font-size: 13px;
		line-height: 1.7;
	}
	.wto-pwsms-test__result.is-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
	.wto-pwsms-test__result.is-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

	@media (max-width: 768px) {
		.wto-pwsms-panel__grid { grid-template-columns: 1fr; }
		.wto-pwsms-panel__hero { flex-direction: column; }
	}
	</style>

	<script type="text/javascript">
	jQuery(function($) {
		// تبدیل اعداد فارسی به انگلیسی
		function normalizeDigits(s) {
			var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
			var ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
			s = String(s || '');
			for (var i = 0; i < 10; i++) {
				s = s.replace(new RegExp(fa[i], 'g'), i).replace(new RegExp(ar[i], 'g'), i);
			}
			return s;
		}

		function formatNumber(n) {
			if (n === null || n === undefined || n === '') return '—';
			return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		}

		// Initialize each panel on the page independently — selectors are scoped to
		// the panel via .find() so multiple panels on one page don't collide.
		$('.wto-pwsms-panel').each(function() {
			var $panel = $(this);
			if ($panel.data('wto-initialized')) return;
			$panel.data('wto-initialized', true);

			var nonce = $panel.data('nonce');
			var $statusCard = $panel.find('.wto-pwsms-status-card');
			var $creditCard = $panel.find('.wto-pwsms-credit-card');
			var $refreshBtn = $panel.find('.wto-pwsms-refresh-btn');
			var $testInput  = $panel.find('.wto-pwsms-test-mobile');
			var $testBtn    = $panel.find('.wto-pwsms-test-send');
			var $testResult = $panel.find('.wto-pwsms-test__result');

			function checkConnection() {
				$statusCard.attr('data-state', 'loading');
				$statusCard.find('.wto-pwsms-card__value').html(
					'<span class="wto-pwsms-spinner" aria-hidden="true"></span>' +
					'<span class="wto-pwsms-card__text">در حال بررسی…</span>'
				);
				$creditCard.attr('data-state', 'loading');
				$creditCard.find('.wto-pwsms-card__credit').text('—');

				$refreshBtn.addClass('is-loading').prop('disabled', true);

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'wto_pwsms_check_connection',
						nonce: nonce
					}
				}).done(function(res) {
					if (res && res.success) {
						$statusCard.attr('data-state', 'connected');
						$statusCard.find('.wto-pwsms-card__value').html(
							'<span class="dashicons dashicons-yes-alt" style="color:#16a34a;"></span>' +
							'<span class="wto-pwsms-card__text">متصل ✓</span>'
						);
						$creditCard.attr('data-state', 'connected');
						$creditCard.find('.wto-pwsms-card__credit').text(formatNumber(res.data.credit));
					} else {
						var msg = (res && res.data && res.data.message) ? res.data.message : 'اتصال برقرار نیست';
						$statusCard.attr('data-state', 'disconnected');
						$statusCard.find('.wto-pwsms-card__value').html(
							'<span class="dashicons dashicons-warning" style="color:#dc2626;"></span>' +
							'<span class="wto-pwsms-card__text">' + msg + '</span>'
						);
						$creditCard.attr('data-state', 'disconnected');
						$creditCard.find('.wto-pwsms-card__credit').text('—');
					}
				}).fail(function() {
					$statusCard.attr('data-state', 'disconnected');
					$statusCard.find('.wto-pwsms-card__value').html(
						'<span class="dashicons dashicons-warning" style="color:#dc2626;"></span>' +
						'<span class="wto-pwsms-card__text">خطا در بررسی اتصال</span>'
					);
					$creditCard.attr('data-state', 'disconnected');
				}).always(function() {
					$refreshBtn.removeClass('is-loading').prop('disabled', false);
				});
			}

			// نکته: رندر اولیه اعتبار سمت سرور انجام می‌شود (با wto_get_credit). به همین
			// دلیل اینجا checkConnection اولیه را صدا نمی‌زنیم تا کاربر دائماً
			// «در حال بررسی…» نبیند. فقط با کلیک «تازه‌سازی» AJAX اجرا می‌شود.

			// Refresh button — fetches latest credit via AJAX
			$refreshBtn.on('click', function(e) {
				e.preventDefault();
				checkConnection();
			});

			// Normalize Persian digits on input
			$testInput.on('input', function() {
				var v = normalizeDigits($(this).val()).replace(/[^\d]/g, '');
				if (v.length > 11) v = v.substring(0, 11);
				$(this).val(v);
			});

			// Test SMS button
			$testBtn.on('click', function(e) {
				e.preventDefault();
				var mobile = normalizeDigits($testInput.val()).replace(/[^\d]/g, '');
				if (!/^09\d{9}$/.test(mobile)) {
					$testResult
						.removeClass('is-success').addClass('is-error')
						.text('شماره موبایل نامعتبر است. مثال صحیح: 09121234567')
						.show();
					return;
				}
				$testBtn.addClass('is-loading').prop('disabled', true);
				$testResult.hide();

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'wto_pwsms_send_test_sms',
						nonce: nonce,
						mobile: mobile
					}
			}).done(function(res) {
				if (res && res.success) {
					$testResult
						.removeClass('is-error').addClass('is-success')
						.html('✓ ' + (res.data && res.data.message ? res.data.message : 'پیامک با موفقیت ارسال شد.'))
						.show();
					// Refresh credit after a test send (it just consumed credit)
					setTimeout(checkConnection, 800);
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'خطای ناشناخته';
					$testResult
						.removeClass('is-success').addClass('is-error')
						.html('✗ ' + msg)
						.show();
				}
			}).fail(function() {
				$testResult
					.removeClass('is-success').addClass('is-error')
					.text('خطا در ارسال درخواست. لطفاً دوباره تلاش کنید.')
					.show();
			}).always(function() {
				$testBtn.removeClass('is-loading').prop('disabled', false);
			});
		});

		// Submit on Enter inside test mobile input
		$testInput.on('keypress', function(e) {
			if (e.which === 13) {
				e.preventDefault();
				$testBtn.click();
			}
		});
		});  // end .each()
	});  // end jQuery(function)
	</script>
	<?php
}

/* ════════════════════════════════════════════════════════════════════════ *
 *  بخش ۳: AJAX endpoints
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * بررسی اتصال + برگرداندن اعتبار پنل.
 *
 * Endpoint:
 *
 *   POST /wp-admin/admin-ajax.php?action=wto_pwsms_check_connection
 *
 * مصرف API: GET /ws/v1/account/balance
 */
add_action( 'wp_ajax_wto_pwsms_check_connection', 'wto_pwsms_ajax_check_connection' );
function wto_pwsms_ajax_check_connection() {
	check_ajax_referer( 'wto_pwsms_panel', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}

	$apikey = wto_pwsms_resolve_apikey();
	if ( $apikey === '' ) {
		wp_send_json_error( array(
			'connected' => false,
			'message'   => 'کلید دسترسی (Api-Key) وارد نشده است',
		) );
	}

	// قبل از فراخوانی API، cache را پاک می‌کنیم تا همیشه مقدار live گرفته شود.
	delete_transient( 'wto_credit_' . md5( $apikey ) );

	// مسیر مستقیم به /account/balance با fallback به cURL.
	// نکته: روی هاست‌هایی که wp_remote_get مسدود است، helper موجود از cURL استفاده می‌کند.
	if ( function_exists( 'wto_remote_get_with_fallback' ) ) {
		$response = wto_remote_get_with_fallback(
			'https://api.iranpayamak.com/ws/v1/account/balance',
			array(
				'headers' => array(
					'Accept'  => 'application/json',
					'Api-Key' => $apikey,
				),
				'timeout' => 15,
			)
		);
	} else {
		$response = wp_remote_get(
			'https://api.iranpayamak.com/ws/v1/account/balance',
			array(
				'headers'   => array(
					'Accept'  => 'application/json',
					'Api-Key' => $apikey,
				),
				'timeout'   => 15,
				'sslverify' => true,
			)
		);
	}

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array(
			'connected' => false,
			'message'   => 'خطای شبکه: ' . esc_html( $response->get_error_message() ),
		) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	// اگر API balance_amount را برگرداند، اتصال برقرار است — هر کد HTTP که باشد.
	if ( is_array( $data ) && isset( $data['data']['balance_amount'] ) ) {
		wp_send_json_success( array(
			'connected' => true,
			'credit'    => (int) $data['data']['balance_amount'],
		) );
	}

	if ( $code === 401 || $code === 403 ) {
		wp_send_json_error( array(
			'connected' => false,
			'message'   => 'کلید دسترسی نامعتبر است',
		) );
	}
	if ( (int) $code === 0 ) {
		wp_send_json_error( array(
			'connected' => false,
			'message'   => 'دسترسی به API ممکن نیست — سرور هاست شما درخواست‌های خروجی را مسدود کرده است',
		) );
	}
	if ( $code !== 200 ) {
		$api_msg = wto_pwsms_extract_api_message( $data );
		wp_send_json_error( array(
			'connected' => false,
			'message'   => $api_msg ?: ( 'خطای سرور (HTTP ' . (int) $code . ')' ),
		) );
	}
	wp_send_json_error( array(
		'connected' => false,
		'message'   => 'پاسخ نامعتبر از سرور پیامک',
	) );
}

/**
 * ارسال پیامک تست با پترن مخصوص فراز اس‌ام‌اس.
 *
 * v3.17.9: برگشت به /sms/pattern با کد پترن سیستمی فراز اس‌ام‌اس.
 *
 *   پترن: j3MnVzhrMj   (بدون متغیر — attributes خالی)
 *
 * چرا پترن، نه simple؟
 *  - پترن از قبل تأیید شده و سریع‌تر ارسال می‌شود
 *  - simple SMS نیاز به sender فعال + تأیید متن دارد که برای تست overhead دارد
 *  - فرمت پیام برای همه‌ی کاربران یکسان است — بهتر است Faraz-owned باشد
 */
add_action( 'wp_ajax_wto_pwsms_send_test_sms', 'wto_pwsms_ajax_send_test_sms' );
function wto_pwsms_ajax_send_test_sms() {
	check_ajax_referer( 'wto_pwsms_panel', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}

	$mobile = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) ) : '';
	if ( function_exists( 'wto_normalize_phone' ) ) {
		$mobile = wto_normalize_phone( $mobile );
	}
	$mobile = preg_replace( '/[^0-9]/', '', (string) $mobile );
	if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
		wp_send_json_error( array( 'message' => 'شماره موبایل نامعتبر است (فرمت صحیح: 09xxxxxxxxx)' ) );
	}

	$apikey = wto_pwsms_resolve_apikey();
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => 'ابتدا کلید دسترسی (Api-Key) را وارد و ذخیره کنید' ) );
	}

	// خط ارسال: ابتدا از PWSMS، سپس از تنظیمات ما، در نهایت fallback به خط پیش‌فرض.
	$sender = '';
	if ( function_exists( 'PWSMS' ) ) {
		$sender = trim( (string) PWSMS()->get_option( 'sms_gateway_sender' ) );
	}
	if ( $sender === '' ) {
		$sender = trim( (string) get_option( 'wto_sender', '' ) );
	}
	if ( $sender === '' ) {
		$sender = '90008361';
	}

	$pattern_code = 'j3MnVzhrMj'; // پترن اشتراکی تست فراز اس‌ام‌اس (بدون متغیر)

	// v3.20.4: فقط پترن — بدون fallback. اگر پترن fail کرد، خطای دقیق نمایش
	// داده می‌شود (نه تغییر روش).
	$payload = array(
		'code'          => $pattern_code,
		'recipient'     => $mobile,
		'attributes'    => (object) array(),
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

	$response = function_exists( 'wto_remote_post_with_fallback' )
		? wto_remote_post_with_fallback( 'https://api.iranpayamak.com/ws/v1/sms/pattern', $request_args )
		: wp_remote_post( 'https://api.iranpayamak.com/ws/v1/sms/pattern', $request_args );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => 'خطای شبکه: ' . esc_html( $response->get_error_message() ) ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( is_array( $data ) && isset( $data['status'] ) && $data['status'] === 'success' ) {
		delete_transient( 'wto_credit_' . md5( $apikey ) );
		wp_send_json_success( array(
			'message' => '✓ پیامک تست با پترن ' . esc_html( $pattern_code ) . ' به ' . esc_html( $mobile ) . ' ارسال شد.',
		) );
	}

	if ( $code === 401 || $code === 403 ) {
		wp_send_json_error( array( 'message' => 'کلید دسترسی (Api-Key) نامعتبر است.' ) );
	}

	$em = wto_pwsms_extract_api_message( $data );
	wp_send_json_error( array(
		'message' => $em !== ''
			? 'خطا: ' . esc_html( $em )
			: sprintf( 'ارسال ناموفق (HTTP %d). پاسخ خام: %s', $code, esc_html( mb_substr( $body, 0, 200 ) ) ),
	) );
}

/**
 * پیدا کردن Api-Key از منابع موجود به ترتیب اولویت.
 */
function wto_pwsms_resolve_apikey() {
	$apikey = '';
	if ( function_exists( 'PWSMS' ) ) {
		$apikey = trim( (string) PWSMS()->get_option( 'sms_gateway_apikey' ) );
		if ( $apikey === '' ) {
			$apikey = trim( (string) PWSMS()->get_option( 'sms_gateway_username' ) );
		}
		if ( $apikey === '' ) {
			$apikey = trim( (string) PWSMS()->get_option( 'sms_gateway_password' ) );
		}
	}
	if ( $apikey === '' ) {
		$apikey = trim( (string) get_option( 'wto_apikey', '' ) );
	}
	return $apikey;
}

/**
 * استخراج پیام خطای انسان‌خوان از پاسخ Faraz API.
 */
/**
 * v3.17.6 BUG FIX: قبلاً اگر message یک آرایه nested بود (مثل validation errors)،
 * (string)$array می‌داد "Array" — کاربر می‌دید "✗ Array" که معنی‌دار نبود.
 * helper جدید: flatten کن آرایه را به یک string قابل خواندن.
 */
function wto_pwsms_flatten_to_string( $value, $separator = '، ' ) {
	if ( is_string( $value ) || is_numeric( $value ) ) {
		return (string) $value;
	}
	if ( ! is_array( $value ) ) {
		return '';
	}
	$parts = array();
	array_walk_recursive( $value, function ( $v ) use ( &$parts ) {
		if ( is_string( $v ) || is_numeric( $v ) ) {
			$s = trim( (string) $v );
			if ( $s !== '' ) $parts[] = $s;
		}
	} );
	return implode( $separator, $parts );
}

function wto_pwsms_extract_api_message( $data ) {
	if ( ! is_array( $data ) ) {
		return '';
	}
	if ( isset( $data['messages'] ) ) {
		return wto_pwsms_flatten_to_string( $data['messages'] );
	}
	if ( isset( $data['message'] ) ) {
		return wto_pwsms_flatten_to_string( $data['message'] );
	}
	if ( isset( $data['error'] ) ) {
		return wto_pwsms_flatten_to_string( $data['error'] );
	}
	// fallback — اگر هیچ کلید معتبر نبود، خود data را flatten کن
	return wto_pwsms_flatten_to_string( $data );
}
