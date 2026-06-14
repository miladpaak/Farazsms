<?php
/**
 * Login Module Bridge — v3.13.22 (rewrite)
 *
 * اصول طراحی این بار (مطابق درخواست کاربر):
 *
 *   ۱) همه تنظیمات ماژول login در همان صفحه ما رندر می‌شوند — صفحه جداگانه
 *      «تنظیمات پیشرفته ماژول» وجود ندارد.
 *
 *   ۲) فیلد API Key حذف شده — از option('wto_apikey') افزونه اصلی استفاده می‌شود.
 *
 *   ۳) فیلد Sender حذف شده — از option('wto_sender') افزونه اصلی استفاده می‌شود.
 *
 *   ۴) تب «New User Signup Bonus» (wallet) حذف شده — افزونه ما wallet داریم.
 *
 *   ۵) تب «WooCommerce SMS» (woo_sms) حذف شده — افزونه ما WC SMS داریم.
 *
 *   ۶) فیلدهای WC داخل تب general (checkout_redirect, woocommerce_login_redirect) حذف.
 *
 *   ۷) منوی top-level خود ماژول (farazsms_login_settings) به‌کلی remove شده.
 *
 *   ۸) استایل صفحه: مطابق قاب یکپارچه ما (سفید، border-radius، رنگ ایندیگو، ...).
 *
 *   ۹) admin part ماژول همیشه load می‌شود تا All_Settings() در دسترس باشد.
 *      frontend part فقط با toggle=on فعال می‌شود.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

function wto_login_module_is_enabled() {
	return get_option( 'wto_login_module_enabled', '0' ) === '1';
}

/**
 * تضمین می‌کنیم api_key و sender ماژول login همیشه با تنظیمات اصلی افزونه
 * (wto_apikey, wto_sender) sync باشد — حتی اگر کاربر هرگز ذخیره نکرد.
 * این filter روی هر خواندن option اعمال می‌شود.
 */
add_filter( 'option_farazsms_login_settings', 'wto_login_inject_main_credentials' );
function wto_login_inject_main_credentials( $value ) {
	if ( ! is_array( $value ) ) {
		$value = array();
	}
	if ( ! isset( $value['sms'] ) || ! is_array( $value['sms'] ) ) {
		$value['sms'] = array();
	}
	// override همیشگی — کاربر نباید بتواند مقدار دیگری در ماژول ست کند.
	$value['sms']['api_key'] = (string) get_option( 'wto_apikey', '' );
	$wto_sender = trim( (string) get_option( 'wto_sender', '' ) );
	if ( $wto_sender !== '' ) {
		$value['sms']['sender'] = $wto_sender;
	} elseif ( ! isset( $value['sms']['sender'] ) || $value['sms']['sender'] === '' ) {
		$value['sms']['sender'] = '90008361'; // fallback پیش‌فرض فراز
	}
	return $value;
}

/**
 * Load ماژول — admin همیشه، frontend شرطی.
 *
 * v3.20.5 CRITICAL FIX: اگر کاربر افزونه‌ی standalone `farazsms-login` را هم
 * نصب داشته باشد (در /wp-content/plugins/farazsms-login/)، load کردن ماژول
 * bundled ما باعث «Constant FarazSMS__FILE__ already defined» Notice می‌شود.
 *
 * این Notice به‌صورت HTML قبل از headers چاپ می‌شود → `wp_redirect()` به
 * درگاه پرداخت fail می‌کند → **کاربر نمی‌تواند وارد درگاه پرداخت شود**.
 *
 * راه‌حل: اگر standalone فعال است، ماژول bundled را load نکن.
 */
$wto_login_module_main = dirname( __DIR__ ) . '/modules/farazsms-login/farazsms-login.php';

// تشخیص standalone farazsms-login plugin
$active_plugins = (array) get_option( 'active_plugins', array() );
if ( is_multisite() ) {
	$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
}
$standalone_active = false;
foreach ( $active_plugins as $plugin_path ) {
	// matches: farazsms-login/farazsms-login.php یا farazsms-login-X.Y/farazsms-login.php
	if ( preg_match( '#(^|/)farazsms-login[^/]*\/farazsms-login\.php$#i', (string) $plugin_path ) ) {
		$standalone_active = true;
		break;
	}
}
// همچنین چک کنیم constant از قبل تعریف شده — یعنی standalone زودتر load شده
if ( defined( 'FarazSMS__FILE__' ) ) {
	$standalone_active = true;
}

if ( $standalone_active ) {
	// admin notice یک‌بار به ادمین نشان بدهیم — کاربر باید یکی را انتخاب کند
	add_action( 'admin_notices', 'wto_login_module_duplicate_notice' );
	function wto_login_module_duplicate_notice() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		?>
		<div class="notice notice-error">
			<p>
				<strong>⚠ تداخل افزونه فراز اس ام اس:</strong>
				شما هم «افزونه فراز اس ام اس» را نصب کرده‌اید (که شامل ماژول ورود/ثبت‌نام است)
				و هم افزونه‌ی <code>farazsms-login</code> به‌صورت جداگانه فعال است.
				این تداخل باعث «Notice: Constant already defined» می‌شود که می‌تواند
				<strong>redirect به درگاه پرداخت را خراب کند</strong>.
			</p>
			<p>
				راه‌حل: از منوی «افزونه‌ها» افزونه‌ی <code>farazsms-login</code> را
				<strong>غیرفعال</strong> کنید — ماژول ورود/ثبت‌نام به‌صورت داخلی در افزونه‌ی فراز اس ام اس موجود است.
			</p>
		</div>
		<?php
	}
} elseif ( file_exists( $wto_login_module_main ) ) {
	// نسخه‌ی bundled داخل افزونه‌ی فراز اس ام اس — فقط وقتی افزونه‌ی standalone فعال نیست.
	// با تعریف این ثابت، کیف‌پول، هدیه‌ی عضویتِ ماژول، و پیامک سفارشات ووکامرسِ ماژول
	// بارگذاری نمی‌شوند (کیف‌پول بومی + PWSMS جایگزین آن‌ها هستند) تا تداخل پیش نیاید.
	if ( ! defined( 'WTO_LOGIN_BUNDLED' ) ) {
		define( 'WTO_LOGIN_BUNDLED', true );
	}
	if ( ! defined( 'WTO_LOGIN_FRONTEND_ENABLED' ) ) {
		define( 'WTO_LOGIN_FRONTEND_ENABLED', wto_login_module_is_enabled() );
	}
	require_once $wto_login_module_main;
}

/**
 * اجرای activator ماژول (ساخت جدول farazsms_verification، تنظیمات پیش‌فرض، صفحه ورود).
 * فایل activator با require_once بارگذاری می‌شود (باگ قبلی: فقط class_exists چک می‌شد
 * بدون require → کلاس هیچ‌وقت لود نمی‌شد و جدول ساخته نمی‌شد).
 *
 * @return bool موفقیت
 */
function wto_login_module_run_activator() {
	$activator = dirname( __DIR__ ) . '/modules/farazsms-login/includes/class-farazsms-activator.php';
	if ( ! class_exists( '\\FarazSMS\\Activator' ) && file_exists( $activator ) ) {
		require_once $activator;
	}
	if ( class_exists( '\\FarazSMS\\Activator' ) ) {
		\FarazSMS\Activator::activate();
		return true;
	}
	return false;
}

/**
 * Activator هنگام روشن شدن toggle.
 */
add_action( 'update_option_wto_login_module_enabled', 'wto_login_module_handle_toggle', 10, 2 );
function wto_login_module_handle_toggle( $old, $new ) {
	if ( $new === '1' && $old !== '1' ) {
		wto_login_module_run_activator();
		update_option( 'wto_login_module_db_ready', '1', false );
	}
}

/**
 * تضمینِ وجودِ جداولِ ماژول در حالت bundled — یک‌بار.
 *
 * چون activation hook برای نسخه‌ی bundled اجرا نمی‌شود، جدول farazsms_verification
 * هرگز ساخته نمی‌شد؛ در نتیجه کدِ OTP ذخیره نمی‌شد و هر کدی «اشتباه» اعلام می‌گشت
 * (خطای DB: Table 'wp_farazsms_verification' doesn't exist). این تابع یک‌بار آن را می‌سازد.
 */
add_action( 'init', 'wto_login_module_ensure_tables', 9 );
function wto_login_module_ensure_tables() {
	if ( ! defined( 'WTO_LOGIN_BUNDLED' ) ) {
		return; // نسخه‌ی standalone خودش هنگام فعال‌سازی جدول را می‌سازد.
	}
	global $wpdb;
	$table = $wpdb->prefix . 'farazsms_verification';

	// مهم: به پرچمِ db_ready تنها اعتماد نکن. روی بعضی سایت‌ها (نسخه‌ی باگ‌دارِ قبلی)
	// پرچم '1' شده بود اما جدول واقعاً ساخته نشده بود → هر کدِ OTP «اشتباه» می‌شد.
	// پس وجودِ واقعیِ جدول را تأیید می‌کنیم. برای پرهیز از SHOW TABLES در هر request،
	// نتیجه‌ی مثبت را ۱۲ ساعت کش می‌کنیم.
	if ( get_option( 'wto_login_module_db_ready' ) === '1'
		&& get_transient( 'wto_login_module_db_verified' ) === '1' ) {
		return;
	}

	$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

	if ( ! $exists ) {
		// جدول نیست — مستقیم و مطمئن بساز (وابسته به موفقیتِ کاملِ activate() نباش).
		wto_login_module_create_verification_table();
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		if ( ! $exists ) {
			// fallback نهایی: کلِ activator (options + صفحه + جداول).
			wto_login_module_run_activator();
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
	}

	if ( $exists ) {
		update_option( 'wto_login_module_db_ready', '1', false );
		set_transient( 'wto_login_module_db_verified', '1', 12 * HOUR_IN_SECONDS );
	} else {
		// نتوانستیم بسازیم — پرچم را پاک کن تا در request بعدی دوباره تلاش شود.
		delete_option( 'wto_login_module_db_ready' );
	}
}

/**
 * ساختِ مستقیمِ جدولِ farazsms_verification با همان schema فعال‌سازی.
 * مستقل از activate() کامل تا حتی اگر بخش‌های دیگرِ activate شکست بخورند، جدول ساخته شود.
 *
 * @return bool وجودِ جدول پس از تلاش
 */
function wto_login_module_create_verification_table() {
	global $wpdb;
	$table           = $wpdb->prefix . 'farazsms_verification';
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		verification VARCHAR(100) NOT NULL,
		code VARCHAR(10) NOT NULL,
		expire_date DATETIME NULL,
		PRIMARY KEY (id),
		UNIQUE (verification)
	) $charset_collate;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
}

/**
 * حذف کامل منوی top-level ماژول از سایدبار + submenu های wallet/shortcodes.
 */
add_action( 'admin_menu', 'wto_login_module_remove_module_menus', 99999 );
function wto_login_module_remove_module_menus() {
	remove_menu_page( 'farazsms_login_settings' );
	remove_submenu_page( 'farazsms_login_settings', 'farazsms-wallet' );
	remove_submenu_page( 'farazsms_login_settings', 'farazsms-shortcodes' );
}

/**
 * فیلتر تنظیمات ماژول — حذف فیلدهای api_key، sender و تب‌های ووکامرس/wallet.
 *
 * @param array $settings خروجی All_Settings() از ماژول
 * @return array تنظیمات فیلتر شده برای نمایش در صفحه ما
 */
function wto_login_filter_settings( $settings ) {
	if ( ! is_array( $settings ) ) {
		return array();
	}

	// حذف کامل تب‌های مرتبط با ووکامرس/wallet/slide:
	//   - wallet, woo_sms: افزونه اصلی این‌ها را دارد
	//   - slide (پاپ‌آپ خروج): چون افزونه ما لید مگنت دارد، redundant است
	unset( $settings['wallet'] );
	unset( $settings['woo_sms'] );
	unset( $settings['slide'] );

	// در تب SMS، api_key و sender را حذف می‌کنیم (از تنظیمات اصلی افزونه می‌آیند).
	if ( isset( $settings['sms']['settings'] ) && is_array( $settings['sms']['settings'] ) ) {
		unset( $settings['sms']['settings']['api_key'] );
		unset( $settings['sms']['settings']['sender'] );
		// sms_test هم لازم نیست — افزونه ما تست SMS دارد.
		unset( $settings['sms']['settings']['sms_test'] );
	}

	// در تب general، فیلدهای WC را حذف کنیم.
	if ( isset( $settings['general']['settings'] ) && is_array( $settings['general']['settings'] ) ) {
		unset( $settings['general']['settings']['checkout_redirect_to_login'] );
		unset( $settings['general']['settings']['woocommerce_login_redirect'] );
		unset( $settings['general']['settings']['headin_woocommerce'] );
	}

	return $settings;
}

/**
 * Save handler — برای ذخیره تنظیمات ماژول از فرم ما.
 */
function wto_login_save_settings() {
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
		return false;
	}
	if ( ! isset( $_POST['wto_login_settings_submit'] ) ) {
		return false;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	check_admin_referer( 'wto_login_settings', 'wto_login_settings_nonce' );

	// تنظیمات local (toggle + ask_name)
	// toggle: همیشه فرم در همه تب‌ها داراست → '1' یا '0'
	$enabled = isset( $_POST['wto_login_module_enabled'] ) ? '1' : '0';
	update_option( 'wto_login_module_enabled', $enabled, false );

	// ask_name: فقط در تب general در فرم می‌آید. در سایر تب‌ها، مقدار قبلی حفظ می‌شود
	// تا روی save در تب‌های دیگر، انتخاب کاربر reset نشود.
	if ( isset( $_POST['wto_login_ask_name'] ) ) {
		$ask_name = $_POST['wto_login_ask_name'] === 'yes' ? 'yes' : 'no';
		update_option( 'wto_login_ask_name', $ask_name, false );
	}

	// تنظیمات ماژول — در همان option اصلی farazsms_login_settings
	$current_module_settings = get_option( 'farazsms_login_settings', array() );
	if ( ! is_array( $current_module_settings ) ) {
		$current_module_settings = array();
	}

	$active_tab = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : '';
	if ( $active_tab === '' || ! in_array( $active_tab, array( 'sms', 'appearance', 'general', 'slide' ), true ) ) {
		return true; // saved local-only
	}

	// مقادیر api_key و sender از تنظیمات اصلی افزونه ست می‌شود (override).
	if ( ! isset( $current_module_settings['sms'] ) || ! is_array( $current_module_settings['sms'] ) ) {
		$current_module_settings['sms'] = array();
	}
	$current_module_settings['sms']['api_key'] = (string) get_option( 'wto_apikey', '' );
	$current_module_settings['sms']['sender']  = (string) get_option( 'wto_sender', '' );

	// آرایه فیلدهای قابل ذخیره را از All_Settings می‌گیریم تا فقط فیلدهای معتبر همان تب را ذخیره کنیم.
	if ( ! class_exists( '\\FarazSMS\\Admin\\Settings' ) ) {
		return true;
	}
	$settings_obj = new \FarazSMS\Admin\Settings();
	$all_settings = wto_login_filter_settings( $settings_obj->All_Settings() );
	if ( ! isset( $all_settings[ $active_tab ]['settings'] ) ) {
		return true;
	}

	foreach ( $all_settings[ $active_tab ]['settings'] as $field_id => $field ) {
		$type = $field['type'] ?? 'text';
		if ( in_array( $type, array( 'heading', 'html', 'sms-test' ), true ) ) {
			continue;
		}
		$value = isset( $_POST[ $field_id ] ) ? $_POST[ $field_id ] : '';
		switch ( $type ) {
			case 'switch':
				$value = ! empty( $value ) ? '1' : '0';
				break;
			case 'number':
				$value = sanitize_text_field( $value );
				break;
			case 'textarea':
				$value = wp_kses_post( wp_unslash( $value ) );
				break;
			case 'color':
				$value = sanitize_hex_color_no_hash( ltrim( $value, '#' ) );
				$value = $value !== null ? '#' . $value : '';
				break;
			case 'file':
			case 'url':
				$value = esc_url_raw( $value );
				break;
			default:
				$value = sanitize_text_field( wp_unslash( $value ) );
		}
		$current_module_settings[ $active_tab ][ $field_id ] = $value;
	}

	update_option( 'farazsms_login_settings', $current_module_settings, false );
	return true;
}

/**
 * رندر یک فیلد از روی schema ماژول — استایل قاب یکپارچه.
 */
function wto_login_render_field( $id, $field ) {
	$type        = $field['type'] ?? 'text';
	$title       = $field['title'] ?? '';
	$description = $field['description'] ?? '';
	$value       = $field['value'] ?? '';
	$width       = $field['width'] ?? 'w100';

	// عرض: w100=100%, w50=48%, w33=31%, w20=18%
	$width_map = array(
		'w100' => '100%',
		'w50'  => 'calc(50% - 8px)',
		'w33'  => 'calc(33.33% - 11px)',
		'w20'  => 'calc(20% - 13px)',
	);
	$css_width = isset( $width_map[ trim( explode( ' ', $width )[0] ) ] )
		? $width_map[ trim( explode( ' ', $width )[0] ) ]
		: '100%';
	$is_ltr = strpos( $width, 'ltr' ) !== false ? 'direction:ltr; text-align:left;' : '';

	if ( $type === 'heading' ) {
		echo '<h4 style="grid-column:1/-1; margin:18px 0 6px; font-size:14px; font-weight:700; color:#0f172a; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">' . esc_html( $title ) . '</h4>';
		return;
	}
	if ( $type === 'html' ) {
		echo '<div style="grid-column:1/-1;">' . wp_kses_post( $field['html'] ?? '' ) . '</div>';
		return;
	}

	echo '<div style="flex: 0 1 ' . esc_attr( $css_width ) . '; min-width:200px;">';
	echo '<label for="' . esc_attr( $id ) . '" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:6px;">' . esc_html( $title ) . '</label>';

	$common_style = 'width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit; box-sizing:border-box; ' . $is_ltr;

	switch ( $type ) {
		case 'textarea':
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" rows="6" style="' . esc_attr( $common_style ) . ' min-height:120px; line-height:1.8;">' . esc_textarea( (string) $value ) . '</textarea>';
			break;
		case 'number':
			echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" style="' . esc_attr( $common_style ) . '">';
			break;
		case 'color':
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" placeholder="#000000" style="' . esc_attr( $common_style ) . ' direction:ltr; text-align:left; font-family:monospace;">';
			break;
		case 'switch':
		case 'checkbox':
			$checked = ! empty( $value ) && $value !== '0' ? 'checked' : '';
			echo '<label style="display:inline-flex; align-items:center; gap:10px; cursor:pointer;">';
			echo '<input type="checkbox" class="wto-toggle" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="1" ' . $checked . '>';
			echo '<span style="font-size:12px; font-weight:600; color:' . ( $checked ? '#16a34a' : '#94a3b8' ) . ';">' . ( $checked ? 'فعال' : 'غیرفعال' ) . '</span>';
			echo '</label>';
			break;
		case 'select':
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" style="' . esc_attr( $common_style ) . '">';
			foreach ( ( $field['array'] ?? array() ) as $opt_val => $opt_label ) {
				$sel = (string) $value === (string) $opt_val ? 'selected' : '';
				echo '<option value="' . esc_attr( $opt_val ) . '" ' . $sel . '>' . esc_html( $opt_label ) . '</option>';
			}
			echo '</select>';
			break;
		case 'image-radio':
			echo '<div style="display:flex; flex-wrap:wrap; gap:8px;">';
			foreach ( ( $field['options'] ?? array() ) as $opt_val => $opt ) {
				$is_sel = (string) $value === (string) $opt_val;
				echo '<label style="cursor:pointer; border:2px solid ' . ( $is_sel ? '#4338ca' : '#e5e7eb' ) . '; border-radius:8px; padding:6px; background:#fff; transition:border-color 0.15s;">';
				echo '<input type="radio" name="' . esc_attr( $id ) . '" value="' . esc_attr( $opt_val ) . '" ' . checked( $is_sel, true, false ) . ' style="display:none;">';
				if ( ! empty( $opt['image'] ) ) {
					echo '<img src="' . esc_url( $opt['image'] ) . '" alt="' . esc_attr( $opt['label'] ?? '' ) . '" style="display:block; width:80px; height:auto; border-radius:4px;">';
				}
				echo '<div style="text-align:center; font-size:11px; margin-top:4px; color:' . ( $is_sel ? '#4338ca' : '#64748b' ) . ';">' . esc_html( $opt['label'] ?? '' ) . '</div>';
				echo '</label>';
			}
			echo '</div>';
			break;
		case 'file':
			// آپلود مستقیم از کتابخانه‌ی رسانه‌ی وردپرس — کاربر دیگر لازم نیست لینک را دستی وارد کند.
			// فیلدِ متنی هم می‌ماند (برای ذخیره از همان handler و برای کاربرانی که می‌خواهند لینک بزنند).
			echo '<div class="wto-media-field" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" placeholder="https://..." class="wto-media-url" style="flex:1; min-width:200px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; direction:ltr; text-align:left; font-family:monospace;">';
			echo '<button type="button" class="button button-secondary wto-media-upload" data-target="' . esc_attr( $id ) . '"><span class="dashicons dashicons-upload" style="vertical-align:text-bottom;"></span> بارگذاری/انتخاب فایل</button>';
			echo '<button type="button" class="button wto-media-remove" data-target="' . esc_attr( $id ) . '" style="' . ( empty( $value ) ? 'display:none;' : '' ) . '">حذف</button>';
			echo '</div>';
			echo '<div class="wto-media-preview" data-for="' . esc_attr( $id ) . '" style="margin-top:6px;">';
			if ( ! empty( $value ) ) {
				echo '<img src="' . esc_url( $value ) . '" style="max-height:60px; border:1px solid #e5e7eb; border-radius:4px; padding:4px;">';
			}
			echo '</div>';
			break;
		default: // text
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" style="' . esc_attr( $common_style ) . '">';
	}

	if ( $description !== '' ) {
		echo '<p style="margin:4px 0 0; font-size:11px; color:#64748b; line-height:1.7;">' . wp_kses_post( $description ) . '</p>';
	}
	echo '</div>';
}

/**
 * صفحه اصلی تنظیمات «ورود و ثبت‌نام» — همه چیز inline در همان صفحه.
 */
/**
 * اسکریپتِ آپلودگرِ رسانه برای فیلدهای نوع file (لوگو / پس‌زمینه).
 * با کلیک روی دکمه، کتابخانه‌ی رسانه‌ی وردپرس باز می‌شود؛ فایلِ انتخاب/آپلودشده
 * در فیلدِ متنی همان فیلد قرار می‌گیرد و پیش‌نمایش به‌روز می‌شود. در فوتر چاپ می‌شود
 * تا jQuery و wp.media آماده باشند. add_action با همین نام، چاپِ تکراری را خنثی می‌کند.
 */
function wto_login_media_uploader_script() {
	?>
	<script>
	jQuery(function($){
		$(document).on('click', '.wto-media-upload', function(e){
			e.preventDefault();
			var target = $(this).data('target');
			var frame = wp.media({
				title: 'انتخاب یا بارگذاری فایل',
				button: { text: 'استفاده از این فایل' },
				multiple: false
			});
			frame.on('select', function(){
				var att = frame.state().get('selection').first().toJSON();
				$('#' + target).val(att.url).trigger('change');
				$('.wto-media-preview[data-for="' + target + '"]').html(
					'<img src="' + att.url + '" style="max-height:60px; border:1px solid #e5e7eb; border-radius:4px; padding:4px;">'
				);
				$('.wto-media-remove[data-target="' + target + '"]').show();
			});
			frame.open();
		});
		$(document).on('click', '.wto-media-remove', function(e){
			e.preventDefault();
			var target = $(this).data('target');
			$('#' + target).val('').trigger('change');
			$('.wto-media-preview[data-for="' + target + '"]').empty();
			$(this).hide();
		});
	});
	</script>
	<?php
}

/**
 * نوارِ تب‌های صفحه‌ی ورود/ثبت‌نام (مشترک بینِ فرمِ تنظیمات و تبِ خوش‌آمدگویی).
 */
function wto_login_render_tabnav( $valid_tabs, $active_tab, $tab_labels, $tab_icons, $module_settings ) {
	?>
	<div style="display:flex; gap:4px; border-bottom:1px solid #e5e7eb; padding:0 14px; background:#f8fafc; flex-wrap:wrap;">
		<?php foreach ( $valid_tabs as $tab_key ) :
			$virtual_tabs = array( 'migration', 'shortcodes', 'welcome' );
			if ( ! in_array( $tab_key, $virtual_tabs, true ) && ! isset( $module_settings[ $tab_key ] ) ) {
				continue;
			}
			$is_active = $tab_key === $active_tab;
			$tab_url   = add_query_arg( array( 'page' => 'farazwto-login-register', 'lt' => $tab_key ), admin_url( 'admin.php' ) );
			?>
			<a href="<?php echo esc_url( $tab_url ); ?>" style="display:inline-flex; align-items:center; gap:6px; padding:12px 18px; font-size:13px; font-weight:<?php echo $is_active ? '700' : '500'; ?>; color:<?php echo $is_active ? '#4338ca' : '#64748b'; ?>; border-bottom:2px solid <?php echo $is_active ? '#6366f1' : 'transparent'; ?>; text-decoration:none; margin-bottom:-1px;">
				<span class="dashicons dashicons-<?php echo esc_attr( $tab_icons[ $tab_key ] ?? 'admin-generic' ); ?>" style="font-size:16px; width:16px; height:16px;"></span>
				<?php echo esc_html( $tab_labels[ $tab_key ] ?? ( $module_settings[ $tab_key ]['menu'] ?? $tab_key ) ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
}

function wto_login_module_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'دسترسی غیرمجاز.' );
	}

	// آپلودگرِ رسانه‌ی وردپرس برای فیلدهای لوگو و پس‌زمینه (دکمه‌ی «بارگذاری/انتخاب فایل»).
	wp_enqueue_media();
	add_action( 'admin_footer', 'wto_login_media_uploader_script' );

	$saved_ok = wto_login_save_settings();

	$enabled    = wto_login_module_is_enabled();
	$ask_name   = get_option( 'wto_login_ask_name', 'yes' );

	$active_tab = isset( $_GET['lt'] ) ? sanitize_key( $_GET['lt'] ) : 'sms';
	$valid_tabs = array( 'sms', 'appearance', 'general', 'welcome', 'migration', 'shortcodes' );
	if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
		$active_tab = 'sms';
	}

	// تنظیمات ماژول — فیلتر شده.
	$module_settings = array();
	if ( class_exists( '\\FarazSMS\\Admin\\Settings' ) ) {
		$settings_obj    = new \FarazSMS\Admin\Settings();
		$module_settings = wto_login_filter_settings( $settings_obj->All_Settings() );
	}

	$tab_labels = array(
		'sms'        => 'پیامک و OTP',
		'appearance' => 'ظاهر فرم',
		'general'    => 'تنظیمات عمومی',
		'welcome'    => 'پیامک خوش‌آمدگویی',
		'migration'  => 'مهاجرت از سایر افزونه‌ها',
		'shortcodes' => 'شورت‌کدها',
	);
	$tab_icons = array(
		'sms'        => 'email-alt',
		'appearance' => 'admin-appearance',
		'general'    => 'admin-settings',
		'welcome'    => 'megaphone',
		'migration'  => 'migrate',
		'shortcodes' => 'shortcode',
	);
	// تب migration یک تب «مجازی» است — در $module_settings از فراز نمی‌آید.
	// خودمان content آن را زیر تب render می‌کنیم.
	?>
	<div style="direction:rtl; font-family:inherit; max-width:980px;">

		<?php if ( $saved_ok ) : ?>
			<div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:10px 14px; border-radius:8px; margin-bottom:16px;">✓ تنظیمات با موفقیت ذخیره شد.</div>
		<?php endif; ?>

		<!-- Hero -->
		<div style="background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 100%); border:1px solid #c7d2fe; border-radius:12px; padding:18px 22px; margin-bottom:20px;">
			<h3 style="margin:0 0 6px; font-size:16px; color:#312e81; font-weight:700;">
				🔐 ورود و ثبت‌نام با پیامک
			</h3>
			<p style="margin:0; color:#4338ca; font-size:13px; line-height:1.9;">
				ماژول اختصاصی ورود/ثبت‌نام با OTP. کلید دسترسی و خط ارسال از <strong>تنظیمات اصلی افزونه</strong> خوانده می‌شود — نیازی به وارد کردن مجدد نیست. این ماژول در حالت غیرفعال <strong>هیچ تأثیری</strong> روی فرم ورود سایر افزونه‌ها ندارد.
			</p>
		</div>

		<?php if ( $active_tab === 'welcome' ) : ?>
			<!-- تبِ مجزای پیامک خوش‌آمدگویی — فرمِ مستقلِ خودش را دارد، پس بیرون از فرمِ اصلی رندر می‌شود. -->
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px 12px 0 0; overflow:hidden;">
				<?php wto_login_render_tabnav( $valid_tabs, $active_tab, $tab_labels, $tab_icons, $module_settings ); ?>
			</div>
			<?php do_action( 'wto_login_register_render_welcome_tab' ); ?>
		</div>
		<?php return; endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'wto_login_settings', 'wto_login_settings_nonce' ); ?>
			<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

			<!-- Card 1: فقط master toggle — ask_name حالا داخل تب «تنظیمات عمومی» است -->
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:18px;">
				<label style="display:flex; align-items:center; gap:14px; padding:12px 16px; background:<?php echo $enabled ? '#f0fdf4' : '#fff7ed'; ?>; border:1px solid <?php echo $enabled ? '#bbf7d0' : '#fed7aa'; ?>; border-radius:10px; cursor:pointer; margin:0;">
					<input type="checkbox" class="wto-toggle" name="wto_login_module_enabled" value="1" <?php checked( $enabled, true ); ?> style="margin:0; width:18px; height:18px;">
					<span style="flex:1; font-size:13px; font-weight:600;">
						فعال بودن ماژول ورود/ثبت‌نام با موبایل
						<?php if ( $enabled ) : ?>
							<span style="display:inline-block; background:#16a34a; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px;">فعال ✓</span>
						<?php else : ?>
							<span style="display:inline-block; background:#f97316; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px;">غیرفعال</span>
						<?php endif; ?>
					</span>
				</label>
			</div>

			<!-- Card 2: Tabbed advanced settings -->
			<?php if ( ! empty( $module_settings ) ) : ?>
				<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:0; margin-bottom:18px; overflow:hidden;">

					<!-- Tab nav -->
						<?php wto_login_render_tabnav( $valid_tabs, $active_tab, $tab_labels, $tab_icons, $module_settings ); ?>

						<!-- Tab content -->
					<div style="padding:24px;">
						<?php if ( $active_tab === 'migration' ) : ?>
							<?php wto_login_render_migration_tab(); ?>
						<?php elseif ( $active_tab === 'shortcodes' ) : ?>
							<?php wto_login_render_shortcodes_tab(); ?>
						<?php elseif ( isset( $module_settings[ $active_tab ]['settings'] ) ) : ?>
							<div style="display:flex; flex-wrap:wrap; gap:16px;">
								<?php foreach ( $module_settings[ $active_tab ]['settings'] as $field_id => $field ) : ?>
									<?php wto_login_render_field( $field_id, $field ); ?>
								<?php endforeach; ?>

								<?php if ( $active_tab === 'general' ) : ?>
									<!-- ask_name در تب تنظیمات عمومی -->
									<div style="flex:0 1 100%; min-width:200px; margin-top:8px;">
										<label style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:8px;">
											فیلد نام و نام خانوادگی در صفحه ثبت‌نام
										</label>
										<div style="display:flex; gap:24px; flex-wrap:wrap; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
											<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
												<input type="radio" name="wto_login_ask_name" value="yes" <?php checked( $ask_name, 'yes' ); ?> style="margin:0;">
												<span>بله <strong style="color:#dc2626;">(اجباری)</strong></span>
											</label>
											<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
												<input type="radio" name="wto_login_ask_name" value="no" <?php checked( $ask_name, 'no' ); ?> style="margin:0;">
												<span>خیر، فیلد نام را حذف کن</span>
											</label>
										</div>
										<p style="margin:4px 0 0; font-size:11px; color:#64748b; line-height:1.7;">
											اگر «بله» را انتخاب کنید، فیلد نام در صفحه ثبت‌نام اجباری می‌شود.
										</p>
									</div>

								<?php endif; ?>

								<?php if ( $active_tab === 'sms' ) : ?>
									<?php
									$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
									$site_domain = preg_replace( '#^www\.#i', '', (string) $site_domain );
									?>
									<div style="flex:0 1 100%; min-width:200px; margin-top:8px; padding:14px 16px; background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%); border:1px solid #c7d2fe; border-radius:10px;">
										<div style="font-size:13px; font-weight:700; color:#3730a3; margin-bottom:6px;">
											🤖 ساخت پترن خودکار
										</div>
										<p style="margin:0 0 10px; font-size:12px; color:#4338ca; line-height:1.8;">
											پترن استاندارد OTP را با یک کلیک در پنل فراز اس‌ام‌اس بسازید. متغیر <code style="background:#fff; padding:1px 4px; border-radius:3px;">%code%</code> عددی ۶ رقمی است.
										</p>
										<div style="background:#fff; border:1px dashed #c7d2fe; padding:10px 12px; border-radius:6px; margin-bottom:10px; font-family:inherit; font-size:12px; line-height:1.9; color:#0f172a;">
											کدتایید شما %code%<br>
											<span style="color:#475569; direction:ltr; display:inline-block;"><?php echo esc_html( $site_domain ); ?></span>
										</div>
										<button type="button" id="wto-login-autobuild-pattern" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wto_login_autobuild_pattern' ) ); ?>" style="background:#16a34a; color:#fff; border:none; padding:8px 16px; font-size:12px; font-weight:600; border-radius:6px; cursor:pointer; font-family:inherit;">
											⚡ ساخت پترن خودکار و درج در فیلد بالا
										</button>
										<span id="wto-login-autobuild-status" style="margin-right:10px; font-size:12px; color:#64748b;"></span>
										<p style="margin:8px 0 0; font-size:11px; color:#64748b; line-height:1.7;">
											بعد از کلیک، یک درخواست به سامانه فراز اس‌ام‌اس ارسال می‌شود و کد پترن ساخته‌شده در فیلد «کد پترن» قرار می‌گیرد. اگر می‌خواهید دستی پترن بسازید، می‌توانید کد آن را مستقیماً در فیلد «کد پترن» وارد کنید.
										</p>
									</div>
									<script>
									(function(){
										var btn = document.getElementById('wto-login-autobuild-pattern');
										if (!btn) return;
										btn.addEventListener('click', function(e){
											e.preventDefault();
											var status = document.getElementById('wto-login-autobuild-status');
											btn.disabled = true;
											btn.style.opacity = '0.6';
											status.textContent = 'در حال ساخت پترن…';
											var fd = new FormData();
											fd.append('action', 'wto_login_autobuild_pattern');
											fd.append('nonce', btn.dataset.nonce);
											fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
												.then(function(r){ return r.json(); })
												.then(function(res){
													if (res && res.success && res.data && res.data.pattern_code) {
														var input = document.getElementById('pattern_code');
														if (input) input.value = res.data.pattern_code;
														status.textContent = '✓ پترن ساخته شد: ' + res.data.pattern_code + ' — دکمه ذخیره را بزنید';
														status.style.color = '#16a34a';
													} else {
														var msg = (res && res.data && res.data.message) ? res.data.message : 'خطای ناشناخته';
														status.textContent = '✗ ' + msg;
														status.style.color = '#dc2626';
													}
												})
												.catch(function(){
													status.textContent = '✗ خطای ارتباط';
													status.style.color = '#dc2626';
												})
												.finally(function(){
													btn.disabled = false;
													btn.style.opacity = '1';
												});
										});
									})();
									</script>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<!-- Save -->
			<div style="display:flex; gap:12px; align-items:center; margin-bottom:18px;">
				<button type="submit" name="wto_login_settings_submit" value="1" style="background:#4338ca; color:#fff; border:none; padding:10px 28px; font-size:14px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit;">
					💾 ذخیره تنظیمات
				</button>
			</div>
		</form>

		<!-- Card 3: راهنمای نمایش فرم در سایت + shortcodes -->
		<?php
		// چک می‌کنیم آیا برگه با قالب login از قبل موجود است؟
		$existing_login_page_id = 0;
		$existing_login_pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 1,
			'meta_key'       => '_wp_page_template',
			'meta_value'     => 'farazsms-login-page.php',
			'fields'         => 'ids',
		) );
		if ( ! empty( $existing_login_pages ) ) {
			$existing_login_page_id = (int) $existing_login_pages[0];
		}
		?>
		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:18px;">
			<h4 style="margin:0 0 6px; font-size:14px; font-weight:700; color:#0f172a;">📍 نحوه نمایش فرم ورود در سایت</h4>
			<p style="margin:0 0 14px; font-size:12.5px; color:#64748b; line-height:1.9;">
				پس از فعال‌سازی ماژول، فرم ورود/ثبت‌نام در یک برگه با قالب اختصاصی نمایش داده می‌شود. می‌توانید این برگه را با یک کلیک بسازید:
			</p>

			<?php if ( $existing_login_page_id > 0 ) : ?>
				<div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px 16px; border-radius:8px; margin-bottom:10px;">
					<strong style="color:#15803d;">✓ برگه ورود ساخته شده است.</strong>
					<div style="margin-top:6px; font-size:12.5px; color:#065f46;">
						URL برگه:
						<a href="<?php echo esc_url( get_permalink( $existing_login_page_id ) ); ?>" target="_blank" style="direction:ltr; display:inline-block; color:#15803d;">
							<?php echo esc_html( get_permalink( $existing_login_page_id ) ); ?>
						</a>
					</div>
					<div style="margin-top:8px;">
						<a href="<?php echo esc_url( get_edit_post_link( $existing_login_page_id ) ); ?>" style="display:inline-block; background:#fff; color:#15803d; padding:6px 12px; border:1px solid #bbf7d0; border-radius:6px; text-decoration:none; font-size:12px; margin-left:6px;">
							✏️ ویرایش برگه
						</a>
						<a href="<?php echo esc_url( get_permalink( $existing_login_page_id ) ); ?>" target="_blank" style="display:inline-block; background:#fff; color:#15803d; padding:6px 12px; border:1px solid #bbf7d0; border-radius:6px; text-decoration:none; font-size:12px;">
							🔗 مشاهده برگه
						</a>
					</div>
				</div>
			<?php else : ?>
				<button type="button" id="wto-login-create-page" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wto_login_create_page' ) ); ?>" style="background:#16a34a; color:#fff; border:none; padding:10px 22px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit;">
					⚡ ساخت خودکار برگه ورود/ثبت‌نام
				</button>
				<span id="wto-login-create-page-status" style="margin-right:10px; font-size:12px; color:#64748b;"></span>
				<p style="margin:10px 0 0; font-size:11px; color:#64748b; line-height:1.7;">
					با کلیک، یک برگه با عنوان «ورود و ثبت‌نام» با قالب اختصاصی «FarazSMS Login Page» ساخته می‌شود. URL آن را در منوی سایت قرار دهید.
				</p>
				<script>
				(function(){
					var btn = document.getElementById('wto-login-create-page');
					if (!btn) return;
					btn.addEventListener('click', function(){
						var status = document.getElementById('wto-login-create-page-status');
						btn.disabled = true; btn.style.opacity = '0.6';
						status.textContent = 'در حال ساخت برگه…';
						var fd = new FormData();
						fd.append('action', 'wto_login_create_page');
						fd.append('nonce', btn.dataset.nonce);
						fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
							.then(function(r){ return r.json(); })
							.then(function(res){
								if (res && res.success && res.data && res.data.url) {
									status.innerHTML = '✓ برگه ساخته شد — ' + '<a href="' + res.data.edit_url + '">ویرایش</a> | <a href="' + res.data.url + '" target="_blank">مشاهده</a>';
									status.style.color = '#16a34a';
									setTimeout(function(){ window.location.reload(); }, 1500);
								} else {
									var msg = (res && res.data && res.data.message) ? res.data.message : 'خطا';
									status.textContent = '✗ ' + msg;
									status.style.color = '#dc2626';
									btn.disabled = false; btn.style.opacity = '1';
								}
							})
							.catch(function(){
								status.textContent = '✗ خطای ارتباط';
								status.style.color = '#dc2626';
								btn.disabled = false; btn.style.opacity = '1';
							});
					});
				})();
				</script>
			<?php endif; ?>

			<!-- v3.14.10: بخش شورت‌کدها از پایان همه تب‌ها حذف شد — حالا فقط در تب «شورت‌کدها» نمایش داده می‌شود.
			     برای جلوگیری از شلوغی صفحات تنظیمات. -->
		</div>

		<?php
		// نقطه‌ی تزریقِ بخش‌های اضافی در صفحه‌ی ورود/ثبت‌نام (مثلِ پیامکِ خوش‌آمدگویی).
		do_action( 'wto_login_register_extra_sections' );
		?>
	</div>
	<?php
}

/**
 * AJAX: ساخت خودکار برگه ورود با قالب FarazSMS Login Page.
 */
add_action( 'wp_ajax_wto_login_create_page', 'wto_login_create_page_ajax' );
function wto_login_create_page_ajax() {
	check_ajax_referer( 'wto_login_create_page', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}

	// چک کنیم برگه قبلاً ساخته نشده باشد
	$existing = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => array( 'publish', 'draft' ),
		'posts_per_page' => 1,
		'meta_key'       => '_wp_page_template',
		'meta_value'     => 'farazsms-login-page.php',
		'fields'         => 'ids',
	) );
	if ( ! empty( $existing ) ) {
		$page_id = (int) $existing[0];
		wp_send_json_success( array(
			'url'      => get_permalink( $page_id ),
			'edit_url' => get_edit_post_link( $page_id, 'raw' ),
			'message'  => 'برگه از قبل وجود دارد.',
		) );
	}

	// ساخت برگه جدید
	$page_id = wp_insert_post( array(
		'post_title'    => 'ورود و ثبت‌نام',
		'post_name'     => 'login-register',
		'post_status'   => 'publish',
		'post_type'     => 'page',
		'post_content'  => '<!-- این برگه با قالب FarazSMS Login Page نمایش داده می‌شود. ویرایش محتوای آن ضروری نیست. -->',
		'post_author'   => get_current_user_id(),
		'meta_input'    => array(
			'_wp_page_template' => 'farazsms-login-page.php',
		),
	), true );

	if ( is_wp_error( $page_id ) ) {
		wp_send_json_error( array( 'message' => $page_id->get_error_message() ) );
	}

	wp_send_json_success( array(
		'url'      => get_permalink( $page_id ),
		'edit_url' => get_edit_post_link( $page_id, 'raw' ),
		'message'  => 'برگه با موفقیت ساخته شد.',
	) );
}

/**
 * AJAX endpoint: ساخت پترن خودکار OTP برای ماژول login.
 *
 * متن پترن:
 *
 *   کدتایید شما %code%
 *   [domain]
 *
 *   - %code% متغیر عددی ۶ رقمی
 *   - [domain] دامنه سایت (بدون www / http(s))
 *
 * توضیحات پترن: «ساخته شده از طریق افزونه فراز اس ام اس»
 *
 * Endpoint:
 *
 *   POST /ws/v1/pattern  با Api-Key header
 *
 * پاسخ موفق: pattern code در فیلد pattern_code کاربر درج می‌شود (در DOM)
 * و در ذخیره بعدی فرم، در farazsms_login_settings ست می‌شود. کاربر می‌تواند
 * این پترن را تغییر دهد یا دستی کد دیگری وارد کند.
 */
add_action( 'wp_ajax_wto_login_autobuild_pattern', 'wto_login_autobuild_pattern' );
function wto_login_autobuild_pattern() {
	check_ajax_referer( 'wto_login_autobuild_pattern', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}

	$apikey = trim( (string) get_option( 'wto_apikey', '' ) );
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => 'ابتدا کلید دسترسی (Api-Key) را در تنظیمات افزونه وارد کنید.' ) );
	}

	// استخراج دامنه سایت بدون www / http(s)
	$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$domain = preg_replace( '#^www\.#i', '', $domain );

	// v3.14.3 BUG FIX: endpoint صحیح `/patterns` + فرمت body صحیح فراز.
	// فرمت: { text, share, website, category, vars: [{var, type, length}] }
	$pattern_message = "کدتایید شما %code%\n" . $domain;
	$payload = array(
		'text'     => $pattern_message,
		'share'    => 0,
		'website'  => get_site_url(),
		'category' => 1,
		'vars'     => array(
			// type باید یکی از enumهای معتبر فراز باشد: 'str' یا 'int'.
			// مقدار قبلی 'number' نامعتبر بود و خطای «vars.0.type صحیح نمی‌باشد» می‌داد.
			array( 'var' => 'code', 'type' => 'int', 'length' => 6 ),
		),
	);
	$args = array(
		'method'    => 'POST',
		'timeout'   => 25,
		'sslverify' => true,
		'headers'   => array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'Api-Key'      => $apikey,
		),
		'body'      => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
	);
	$endpoint = 'https://api.iranpayamak.com/ws/v1/patterns';
	if ( function_exists( 'wto_remote_post_with_fallback' ) ) {
		$response = wto_remote_post_with_fallback( $endpoint, $args );
	} else {
		$response = wp_remote_post( $endpoint, $args );
	}

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => 'خطای شبکه: ' . $response->get_error_message() ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	// استخراج pattern_code از پاسخ — defensive چندین نام فیلد بررسی می‌شود
	$pattern_code = '';
	if ( is_array( $data ) ) {
		if ( ! empty( $data['data']['code'] ) ) {
			$pattern_code = (string) $data['data']['code'];
		} elseif ( ! empty( $data['data'] ) && is_string( $data['data'] ) ) {
			$pattern_code = (string) $data['data'];
		} elseif ( ! empty( $data['code'] ) ) {
			$pattern_code = (string) $data['code'];
		}
	}

	if ( $pattern_code === '' ) {
		$msg = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['messages'] ) ) {
				$msg = is_array( $data['messages'] ) ? implode( '، ', array_map( 'strval', $data['messages'] ) ) : (string) $data['messages'];
			} elseif ( isset( $data['message'] ) ) {
				$msg = (string) $data['message'];
			}
		}
		if ( $msg === '' ) {
			$msg = 'پاسخ نامعتبر از سرور (HTTP ' . $code . ')';
		}
		wp_send_json_error( array( 'message' => $msg ) );
	}

	// ذخیره فوری در farazsms_login_settings تا اگر کاربر هرگز ذخیره نکرد، باز هم باقی بماند.
	$current = get_option( 'farazsms_login_settings', array() );
	if ( ! is_array( $current ) ) {
		$current = array();
	}
	if ( ! isset( $current['sms'] ) || ! is_array( $current['sms'] ) ) {
		$current['sms'] = array();
	}
	$current['sms']['pattern_code'] = $pattern_code;
	update_option( 'farazsms_login_settings', $current, false );

	wp_send_json_success( array(
		'pattern_code' => $pattern_code,
		'message'      => 'پترن با موفقیت ساخته شد.',
	) );
}

/**
 * AJAX endpoint: toggle سریع.
 */
add_action( 'wp_ajax_wto_login_module_toggle', 'wto_login_module_ajax_toggle' );
function wto_login_module_ajax_toggle() {
	check_ajax_referer( 'wto_login_module_toggle', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}
	$enable = isset( $_POST['enable'] ) && $_POST['enable'] === '1' ? '1' : '0';
	update_option( 'wto_login_module_enabled', $enable, false );
	wp_send_json_success( array(
		'enabled' => $enable === '1',
		'message' => $enable === '1' ? 'ماژول فعال شد.' : 'ماژول غیرفعال شد.',
	) );
}

/**
 * Migration: کپی meta شماره موبایل از افزونه‌های دیگر به mobile_number ما.
 *
 * Plugins پشتیبانی‌شده:
 *
 *   - Digits   (meta key: digits_phone)
 *   - Cresno   (meta key: cresno_phone — تحقیق: نام دقیق ممکن است متفاوت باشد)
 *   - WC       (meta key: billing_phone)
 *   - DokanPro (meta key: phone)
 *
 * تابع روی هر کاربر در batch (LIMIT 500) اجرا می‌شود. اگر کاربر هم mobile_number
 * موجود دارد، skip می‌شود (تا انتخاب اولویت کاربر حفظ شود).
 *
 * @param string $from key افزونه مبدأ
 * @param int    $offset برای batch
 * @return array  ['migrated'=>N, 'skipped'=>N, 'total'=>N, 'done'=>bool]
 */
function wto_login_migration_run( $from, $offset = 0 ) {
	$map = array(
		'digits'   => 'digits_phone',
		'wc'       => 'billing_phone',
		'dokan'    => 'phone',
	);
	if ( ! isset( $map[ $from ] ) ) {
		return array( 'error' => 'افزونه مبدأ ناشناخته است.' );
	}
	$source_meta = $map[ $from ];

	global $wpdb;
	$batch_size = 500;

	// همه user_id هایی که meta $source_meta با مقدار غیرخالی دارند
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT user_id, meta_value FROM {$wpdb->usermeta}
		 WHERE meta_key = %s AND meta_value != ''
		 ORDER BY user_id ASC
		 LIMIT %d OFFSET %d",
		$source_meta, $batch_size, (int) $offset
	), ARRAY_A );

	$migrated = 0;
	$skipped  = 0;
	foreach ( $rows as $row ) {
		$user_id = (int) $row['user_id'];
		$value   = trim( (string) $row['meta_value'] );
		if ( $user_id <= 0 || $value === '' ) continue;

		// اگر mobile_number قبلاً مقدار دارد، رد کنیم (اولویت با مقدار موجود)
		$existing = (string) get_user_meta( $user_id, 'mobile_number', true );
		if ( $existing !== '' ) {
			$skipped++;
			continue;
		}
		// نرمال‌سازی به فرمت 09xxxxxxxxx اگر تابع موجود
		if ( function_exists( 'wto_normalize_phone' ) ) {
			$value = wto_normalize_phone( $value );
		}
		update_user_meta( $user_id, 'mobile_number', $value );
		// اگر billing_phone خالی است، آن هم پر کنیم (برای WC integration)
		$bp = (string) get_user_meta( $user_id, 'billing_phone', true );
		if ( $bp === '' ) {
			update_user_meta( $user_id, 'billing_phone', $value );
		}
		$migrated++;
	}

	$total_remaining = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
		$source_meta
	) );

	return array(
		'migrated' => $migrated,
		'skipped'  => $skipped,
		'batch'    => count( $rows ),
		'total'    => $total_remaining,
		'done'     => count( $rows ) < $batch_size,
	);
}

add_action( 'wp_ajax_wto_login_migration_run', 'wto_login_migration_ajax' );
function wto_login_migration_ajax() {
	check_ajax_referer( 'wto_login_migration', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
	}
	$from   = sanitize_key( $_POST['from'] ?? '' );
	$offset = (int) ( $_POST['offset'] ?? 0 );
	$result = wto_login_migration_run( $from, $offset );
	if ( isset( $result['error'] ) ) {
		wp_send_json_error( array( 'message' => $result['error'] ) );
	}
	wp_send_json_success( $result );
}

/**
 * Render تب «مهاجرت».
 */
function wto_login_render_migration_tab() {
	$plugins = array(
		'digits' => array(
			'title'    => 'Digits',
			'meta_key' => 'digits_phone',
			'desc'     => 'افزونه ورود/ثبت‌نام با موبایل Digits — meta key کاربر: digits_phone',
		),
		'wc'     => array(
			'title'    => 'WooCommerce',
			'meta_key' => 'billing_phone',
			'desc'     => 'کپی از فیلد billing_phone ووکامرس به mobile_number',
		),
		'dokan'  => array(
			'title'    => 'Dokan',
			'meta_key' => 'phone',
			'desc'     => 'کپی از فیلد phone دکان به mobile_number',
		),
	);
	?>
	<div style="direction:rtl; max-width:780px;">
		<div style="background:#eef2ff; border:1px solid #c7d2fe; padding:14px 18px; border-radius:10px; margin-bottom:18px;">
			<h4 style="margin:0 0 6px; font-size:14px; font-weight:700; color:#3730a3;">🔄 مهاجرت از سایر افزونه‌های ورود</h4>
			<p style="margin:0; font-size:12.5px; color:#4338ca; line-height:1.9;">
				اگر قبلاً از افزونه دیگری برای ورود با موبایل استفاده می‌کرد‌ید (Digits، Cresno، ...)،
				این ابزار <strong>شماره موبایل کاربران فعلی</strong> را به فرمت مورد نیاز افزونه ما کپی می‌کند.
				کاربران دیگر به‌عنوان مشتری جدید شناسایی نمی‌شوند و با همان حساب قبلی وارد می‌شوند.
			</p>
		</div>

		<div style="background:#fffbeb; border:1px solid #fde68a; padding:12px 16px; border-radius:8px; margin-bottom:18px;">
			<strong style="color:#78350f;">⚠️ نکات مهم:</strong>
			<ul style="margin:6px 0 0 18px; padding:0; font-size:12.5px; color:#92400e; line-height:1.9;">
				<li>قبل از اجرا، از دیتابیس <strong>بکاپ بگیرید</strong>.</li>
				<li>کاربرانی که قبلاً <code>mobile_number</code> غیرخالی دارند، رد می‌شوند (اولویت با مقدار موجود).</li>
				<li>عملیات روی کل کاربران سایت اجرا می‌شود — برای ۱۰k+ کاربر، چند دقیقه طول می‌کشد.</li>
				<li>افزونه مبدأ بعد از مهاجرت همچنان می‌تواند فعال باشد — این فقط کپی است نه حذف.</li>
			</ul>
		</div>

		<?php foreach ( $plugins as $key => $cfg ) : ?>
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
				<div style="flex:1 1 300px;">
					<div style="font-size:14px; font-weight:700; color:#0f172a;"><?php echo esc_html( $cfg['title'] ); ?></div>
					<div style="font-size:11.5px; color:#64748b; margin-top:4px;"><?php echo esc_html( $cfg['desc'] ); ?></div>
				</div>
				<button type="button" class="wto-login-migration-btn" data-from="<?php echo esc_attr( $key ); ?>" data-title="<?php echo esc_attr( $cfg['title'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wto_login_migration' ) ); ?>" data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="background:#4338ca; color:#fff; border:none; padding:8px 18px; font-size:13px; font-weight:600; border-radius:6px; cursor:pointer; font-family:inherit;">
					🔄 شروع مهاجرت
				</button>
			</div>
		<?php endforeach; ?>

		<div id="wto-login-migration-result" style="margin-top:16px;"></div>

		<script>
		(function(){
			document.querySelectorAll('.wto-login-migration-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					if (!confirm('آیا مطمئن هستید می‌خواهید مهاجرت از ' + btn.dataset.title + ' را آغاز کنید؟ این عملیات روی همه کاربران سایت اجرا می‌شود.')) return;
					var resultBox = document.getElementById('wto-login-migration-result');
					var totalMigrated = 0, totalSkipped = 0, offset = 0;
					btn.disabled = true; btn.style.opacity = '0.6';
					resultBox.innerHTML = '<div style="background:#eef2ff; border:1px solid #c7d2fe; padding:12px 16px; border-radius:8px; color:#3730a3;">⏳ در حال اجرا… (batch ' + offset + ')</div>';

					function runBatch(){
						var fd = new FormData();
						fd.append('action', 'wto_login_migration_run');
						fd.append('nonce', btn.dataset.nonce);
						fd.append('from', btn.dataset.from);
						fd.append('offset', offset);
						fetch(btn.dataset.ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
							.then(function(r){ return r.json(); })
							.then(function(res){
								if (!res || !res.success) {
									resultBox.innerHTML = '<div style="background:#fef2f2; border:1px solid #fecaca; padding:12px 16px; border-radius:8px; color:#b91c1c;">✗ ' + ((res && res.data && res.data.message) || 'خطا') + '</div>';
									btn.disabled = false; btn.style.opacity = '1'; return;
								}
								totalMigrated += res.data.migrated;
								totalSkipped  += res.data.skipped;
								offset        += res.data.batch;
								resultBox.innerHTML = '<div style="background:#eef2ff; border:1px solid #c7d2fe; padding:12px 16px; border-radius:8px; color:#3730a3;">⏳ پردازش… منتقل‌شده: ' + totalMigrated + ' | رد شده: ' + totalSkipped + ' | تا اینجا: ' + offset + ' کاربر</div>';
								if (res.data.done) {
									resultBox.innerHTML = '<div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:14px 18px; border-radius:8px; color:#065f46;">✓ مهاجرت کامل شد. <strong>' + totalMigrated + '</strong> کاربر منتقل شد و <strong>' + totalSkipped + '</strong> کاربر رد شد (mobile_number از قبل داشتند).</div>';
									btn.disabled = false; btn.style.opacity = '1';
								} else {
									runBatch();
								}
							})
							.catch(function(){
								resultBox.innerHTML = '<div style="background:#fef2f2; border:1px solid #fecaca; padding:12px 16px; border-radius:8px; color:#b91c1c;">✗ خطای ارتباط</div>';
								btn.disabled = false; btn.style.opacity = '1';
							});
					}
					runBatch();
				});
			});
		})();
		</script>
	</div>
	<?php
}

/**
 * Render تب «شورت‌کدها».
 * شورت‌کدهای ماژول login + نحوه استفاده.
 */
function wto_login_render_shortcodes_tab() {
	?>
	<div style="direction:rtl; max-width:860px;">
		<div style="background:#eef2ff; border:1px solid #c7d2fe; padding:14px 18px; border-radius:10px; margin-bottom:18px;">
			<h4 style="margin:0 0 6px; font-size:14px; font-weight:700; color:#3730a3;">🔤 شورت‌کدهای موجود</h4>
			<p style="margin:0; font-size:12.5px; color:#4338ca; line-height:1.9;">
				برای نمایش فرم یا دکمه ورود/ثبت‌نام در هر صفحه، نوشته یا ویجت سایت خود از این شورت‌کدها استفاده کنید.
			</p>
		</div>

		<!-- Shortcode 1: Login Form (Full) -->
		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 22px; margin-bottom:16px; border-right:4px solid #16a34a;">
			<h4 style="margin:0 0 8px; font-size:14px; font-weight:700; color:#0f172a;">📋 فرم کامل ورود / ثبت‌نام</h4>
			<p style="margin:0 0 12px; font-size:12.5px; color:#475569; line-height:1.9;">
				نمایش <strong>فرم کامل</strong> ورود/ثبت‌نام در هر صفحه یا پست. اگر می‌خواهید فرم را داخل صفحه‌ای از قبل موجود اضافه کنید (مثلاً یک صفحه فرود)، این شورت‌کد را در آن قرار دهید.
			</p>
			<div style="background:#0f172a; color:#e2e8f0; border-radius:6px; padding:12px 14px; direction:ltr; text-align:left; font-family:monospace; font-size:13px;">
				[farazsms_login_form]
			</div>
		</div>

		<!-- Shortcode: Login Modal (popup) -->
		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 22px; margin-bottom:16px; border-right:4px solid #db2777;">
			<h4 style="margin:0 0 8px; font-size:14px; font-weight:700; color:#0f172a;">🪟 فرم ورود به‌صورت پاپ‌آپ (مودال)</h4>
			<p style="margin:0 0 12px; font-size:12.5px; color:#475569; line-height:1.9;">
				یک <strong>دکمه</strong> نمایش می‌دهد که با کلیک، فرمِ ورود/ثبت‌نام در یک <strong>پنجره‌ی شناور (پاپ‌آپ)</strong> باز می‌شود — بدون انتقال به صفحه‌ی دیگر. کاربرِ واردشده به‌جای آن، لینکِ «حساب من» می‌بیند. رنگ، متن و عرضِ پنجره دلخواهِ شماست.
			</p>
			<div style="background:#0f172a; color:#e2e8f0; border-radius:6px; padding:12px 14px; margin-bottom:10px; direction:ltr; text-align:left; font-family:monospace; font-size:13px;">
				[farazsms_login_modal]
			</div>
			<div style="font-size:11.5px; font-weight:600; color:#475569; margin:14px 0 6px;">با پارامترهای دلخواه:</div>
			<div style="background:#0f172a; color:#e2e8f0; border-radius:6px; padding:12px 14px; direction:ltr; text-align:left; font-family:monospace; font-size:12.5px; line-height:1.9;">
				[farazsms_login_modal<br>
				&nbsp;&nbsp;text="ورود / ثبت‌نام"<br>
				&nbsp;&nbsp;bg_color="#db2777"<br>
				&nbsp;&nbsp;text_color="#fff"<br>
				&nbsp;&nbsp;width="420"<br>
				]
			</div>
		</div>

		<!-- Shortcode 2: Login Button -->
		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 22px; margin-bottom:16px; border-right:4px solid #4338ca;">
			<h4 style="margin:0 0 8px; font-size:14px; font-weight:700; color:#0f172a;">🔘 دکمه ورود / ثبت‌نام</h4>
			<p style="margin:0 0 12px; font-size:12.5px; color:#475569; line-height:1.9;">
				نمایش <strong>دکمه «ورود/ثبت‌نام»</strong> که به برگه فرم منتقل می‌کند. اگر کاربر قبلاً وارد شده، دکمه «حساب کاربری من» نمایش داده می‌شود.
			</p>
			<div style="background:#0f172a; color:#e2e8f0; border-radius:6px; padding:12px 14px; margin-bottom:10px; direction:ltr; text-align:left; font-family:monospace; font-size:13px;">
				[farazsms_login_button]
			</div>

			<div style="font-size:11.5px; font-weight:600; color:#475569; margin:14px 0 6px;">با پارامترهای دلخواه:</div>
			<div style="background:#0f172a; color:#e2e8f0; border-radius:6px; padding:12px 14px; direction:ltr; text-align:left; font-family:monospace; font-size:12.5px; line-height:1.9;">
				[farazsms_login_button<br>
				&nbsp;&nbsp;bg_color="#4338ca"<br>
				&nbsp;&nbsp;text_color="#fff"<br>
				&nbsp;&nbsp;text="ورود/ثبت‌نام"<br>
				&nbsp;&nbsp;account_text="حساب کاربری"<br>
				]
			</div>

			<details style="margin-top:12px;">
				<summary style="cursor:pointer; font-size:12px; font-weight:600; color:#4338ca;">جدول کامل پارامترها</summary>
				<table style="width:100%; margin-top:10px; border-collapse:collapse; font-size:12px;">
					<thead style="background:#f8fafc;">
						<tr>
							<th style="text-align:right; padding:8px 10px; border:1px solid #e5e7eb; font-weight:700;">پارامتر</th>
							<th style="text-align:right; padding:8px 10px; border:1px solid #e5e7eb; font-weight:700;">توضیح</th>
							<th style="text-align:right; padding:8px 10px; border:1px solid #e5e7eb; font-weight:700;">پیش‌فرض</th>
						</tr>
					</thead>
					<tbody>
						<tr><td style="padding:8px 10px; border:1px solid #e5e7eb;"><code>bg_color</code></td><td style="padding:8px 10px; border:1px solid #e5e7eb;">رنگ پس‌زمینه دکمه (HEX)</td><td style="padding:8px 10px; border:1px solid #e5e7eb;">#0BD08B</td></tr>
						<tr><td style="padding:8px 10px; border:1px solid #e5e7eb;"><code>text_color</code></td><td style="padding:8px 10px; border:1px solid #e5e7eb;">رنگ متن دکمه (HEX)</td><td style="padding:8px 10px; border:1px solid #e5e7eb;">#ffffff</td></tr>
						<tr><td style="padding:8px 10px; border:1px solid #e5e7eb;"><code>text</code></td><td style="padding:8px 10px; border:1px solid #e5e7eb;">متن دکمه برای کاربر مهمان</td><td style="padding:8px 10px; border:1px solid #e5e7eb;">ورود / ثبت‌نام</td></tr>
						<tr><td style="padding:8px 10px; border:1px solid #e5e7eb;"><code>account_text</code></td><td style="padding:8px 10px; border:1px solid #e5e7eb;">متن دکمه برای کاربر وارد شده</td><td style="padding:8px 10px; border:1px solid #e5e7eb;">حساب کاربری من</td></tr>
						<tr><td style="padding:8px 10px; border:1px solid #e5e7eb;"><code>account_url</code></td><td style="padding:8px 10px; border:1px solid #e5e7eb;">URL سفارشی صفحه حساب کاربری</td><td style="padding:8px 10px; border:1px solid #e5e7eb;">my-account ووکامرس</td></tr>
					</tbody>
				</table>
			</details>
		</div>

		<div style="background:#fffbeb; border:1px solid #fde68a; padding:12px 16px; border-radius:8px;">
			<p style="margin:0; font-size:12px; color:#78350f; line-height:1.9;">
				💡 <strong>نکته:</strong> برای ساخت یک صفحه اختصاصی ورود/ثبت‌نام بدون استفاده از شورت‌کد، یک صفحه جدید بسازید و قالب آن را روی <code>FarazSMS Login Page</code> تنظیم کنید — سپس از لینک منوی «ورود» به این صفحه ببرید.
			</p>
		</div>
	</div>
	<?php
}

/**
 * آمار برای داشبورد افزونه.
 */
function wto_login_module_get_stats() {
	global $wpdb;
	$total_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	$module_users = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
		'mobile_number'
	) );
	return array(
		'total_users'  => $total_users,
		'module_users' => $module_users,
		'enabled'      => wto_login_module_is_enabled(),
	);
}
