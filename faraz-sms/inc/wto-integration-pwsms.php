<?php
/*
*
*	***** Integration with Persian WooCommerce SMS *****
*
*	این فایل Gateway جدید Farazsms.com(Next) را به افزونه پیامک پیشفرض اضافه می‌کند
*	
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Compatibility shim for some PWSMS gateway packs.
 *
 * In some installations, gateway files may still reference the legacy
 * `PW\PWSMS\Gateways\GatewayInterface` / `GatewayTrait` while the core plugin
 * has moved to the abstract `Gateway` base class (7.2+). If those legacy
 * symbols are missing, PHP fatals while PWSMS scans gateways.
 *
 * We provide minimal no-op definitions to prevent a white screen.
 */
if ( ! interface_exists( 'PW\PWSMS\Gateways\GatewayInterface', false ) ) {
	// phpcs:ignore WordPress.NamingConventions.ValidClassName.NamespaceName
	interface PW_PWSMS_GatewayInterface_Shim {
		public static function id();
		public static function name();
		public function send();
	}
	class_alias( 'PW_PWSMS_GatewayInterface_Shim', 'PW\PWSMS\Gateways\GatewayInterface' );
}
if ( ! trait_exists( 'PW\PWSMS\Gateways\GatewayTrait', false ) ) {
	// phpcs:ignore WordPress.NamingConventions.ValidClassName.NamespaceName
	trait PW_PWSMS_GatewayTrait_Shim {}
	class_alias( 'PW_PWSMS_GatewayTrait_Shim', 'PW\PWSMS\Gateways\GatewayTrait' );
}

/**
 * Ensure Faraz gateways can be autoloaded on demand.
 *
 * PWSMS 7.2+ validates the selected gateway with class_exists() and
 * is_subclass_of(..., Gateway::class). If our gateway class isn't loaded yet,
 * it falls back to Logger (pwsms.log) and test send "succeeds" without sending.
 *
 * This autoloader guarantees the class is defined before PWSMS instantiates it.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( $class !== 'PW\\PWSMS\\Gateways\\FarazSMSNext' && $class !== 'PW\\PWSMS\\Gateways\\IranPayamak' ) {
			return;
		}
		if ( ! defined( 'WTO_CORE_INC' ) ) {
			return;
		}
		$gateway_file = WTO_CORE_INC . 'wto-farazsmsnext-gateway.php';
		if ( file_exists( $gateway_file ) ) {
			require_once $gateway_file;
		}
	},
	true,
	true
);

/**
 * Persian WooCommerce SMS 7.1 (GatewayInterface) or 7.2+ (abstract Gateway).
 *
 * @return bool
 */
function wto_pwsms_supports_custom_gateways() {
	if ( interface_exists( 'PW\PWSMS\Gateways\GatewayInterface' ) && trait_exists( 'PW\PWSMS\Gateways\GatewayTrait' ) ) {
		return true;
	}
	return class_exists( 'PW\PWSMS\Gateways\Gateway' );
}

/**
 * @return void
 */
function wto_ensure_farazsmsnext_gateway_loaded() {
	if ( ! wto_pwsms_supports_custom_gateways() ) {
		return;
	}
	$gateway_file = WTO_CORE_INC . 'wto-farazsmsnext-gateway.php';
	if ( file_exists( $gateway_file ) && ! class_exists( 'PW\PWSMS\Gateways\FarazSMSNext', false ) ) {
		require_once $gateway_file;
	}
}

/**
 * @param mixed $screen
 * @return bool
 */
function wto_is_pwsms_admin_settings_screen( $screen ) {
	if ( ! $screen || ! isset( $screen->id ) ) {
		return false;
	}
	$ids = array(
		'woocommerce_page_persian-woocommerce-sms-pro',
		'persian-wc_page_persian-woocommerce-sms-pro',
		'admin_page_persian-woocommerce-sms-pro',
	);
	return in_array( $screen->id, $ids, true );
}

/**
 * @param string $class
 * @return bool
 */
function wto_is_farazsmsnext_gateway_class( $class ) {
	if ( ! is_string( $class ) || $class === '' ) {
		return false;
	}
	$supported = array(
		'PW\PWSMS\Gateways\FarazSMSNext',
		'PW\PWSMS\Gateways\IranPayamak',
		'farazsmsnext',
		'iranpayamak',
	);
	if ( in_array( $class, $supported, true ) ) {
		return true;
	}
	if ( class_exists( $class, false ) && is_subclass_of( $class, 'PW\PWSMS\Gateways\FarazSMSNext', false ) ) {
		return true;
	}
	return false;
}

add_action( 'init', 'wto_fix_gateway_check', 1 );
function wto_fix_gateway_check() {
	if ( ! function_exists( 'PWSMS' ) ) {
		return;
	}

	$active_gateway = PWSMS()->get_option( 'sms_gateway' );
	if ( ! wto_is_farazsmsnext_gateway_class( $active_gateway ) ) {
		return;
	}

	wto_ensure_farazsmsnext_gateway_loaded();
}

add_action( 'plugins_loaded', 'wto_load_farazsmsnext_gateway', 20 );
function wto_load_farazsmsnext_gateway() {
	wto_ensure_farazsmsnext_gateway_loaded();
}

/**
 * Migrate stored PWSMS gateway id (e.g. 'farazsmsnext') to FQCN so PWSMS 7.2+
 * doesn't fallback to Logger gateway on test send.
 *
 * @return void
 */
add_action( 'plugins_loaded', 'wto_migrate_pwsms_gateway_option', 30 );
function wto_migrate_pwsms_gateway_option() {
	if ( ! function_exists( 'PWSMS' ) ) {
		return;
	}

	$settings = get_option( 'sms_main_settings', array() );
	if ( ! is_array( $settings ) ) {
		return;
	}

	$current = isset( $settings['sms_gateway'] ) ? (string) $settings['sms_gateway'] : '';
	$current = trim( $current );
	if ( $current === '' ) {
		return;
	}

	$map = array(
		'farazsmsnext' => 'PW\PWSMS\Gateways\FarazSMSNext',
		'iranpayamak'  => 'PW\PWSMS\Gateways\IranPayamak',
	);

	if ( ! isset( $map[ $current ] ) ) {
		return;
	}

	// Ensure our gateway class is available before switching.
	wto_ensure_farazsmsnext_gateway_loaded();

	$target = $map[ $current ];
	if ( class_exists( $target ) ) {
		$settings['sms_gateway'] = $target;
		update_option( 'sms_main_settings', $settings );
	}
}

/**
 * اضافه کردن Gateway جدید Farazsms.com(Next) به لیست Gateway‌های افزونه پیامک پیشفرض
 */
add_filter( 'pwoosms_sms_gateways', 'wto_add_farazsmsnext_gateway', 10 );
function wto_add_farazsmsnext_gateway( $gateways ) {
	$farazsmsnext_class = 'PW\PWSMS\Gateways\FarazSMSNext';
	$iranpayamak_class = 'PW\PWSMS\Gateways\IranPayamak';
	
	if ( ! class_exists( $farazsmsnext_class, false ) ) {
		wto_ensure_farazsmsnext_gateway_loaded();
	}
	
	if ( class_exists( $farazsmsnext_class ) ) {
		$gateway_name = $farazsmsnext_class::name();
		$gateways[ $farazsmsnext_class ] = $gateway_name;
	}
	
	if ( class_exists( $iranpayamak_class ) ) {
		$gateway_name = $iranpayamak_class::name();
		$gateways[ $iranpayamak_class ] = $gateway_name;
	}
	
	return $gateways;
}

// Note: an empty `wto_fix_get_sms_gateway` was previously hooked here on `plugins_loaded`
// with priority 25 but did nothing. Removed — no functional change, just less hook overhead.

/**
 * حذف فیلدهای username/password و اضافه کردن فیلد Api-Key به تنظیمات افزونه پیامک پیشفرض
 * اولویت بالا برای اینکه قبل از رندر شدن فیلدها، اونها رو حذف کنیم
 */
add_filter( 'pwoosms_main_settings', 'wto_modify_main_settings', 5 );
function wto_modify_main_settings( $settings ) {
	$farazsmsnext_class = 'PW\PWSMS\Gateways\FarazSMSNext';

	if ( ! class_exists( $farazsmsnext_class ) ) {
		return $settings;
	}

	// One-time defaults migration. Previously this filter wrote to wp_options on every
	// settings-page render (and could recurse through update_option_sms_main_settings),
	// causing write amplification + autoload bloat. The flag below ensures we seed
	// defaults exactly once per site.
	$defaults_migration_version = '1.0.0';
	$migration_flag             = (string) get_option( 'wto_pwsms_defaults_migrated', '' );
	if ( $migration_flag !== $defaults_migration_version && function_exists( 'PWSMS' ) ) {
		$sms_main_settings = get_option( 'sms_main_settings', array() );
		if ( ! is_array( $sms_main_settings ) ) {
			$sms_main_settings = array();
		}
		$changed = false;
		if ( empty( $sms_main_settings['sms_gateway_sender'] ) ) {
			$sms_main_settings['sms_gateway_sender'] = '90008361';
			$changed = true;
		}
		// v3.13.1: default برای پنهان کردن نام کاربری/رمز عوض شد ('0' = پنهان).
		// به این ترتیب کاربر فقط فیلد Api-Key را می‌بیند و فرآیند Onboarding ساده می‌شود.
		// نصب‌های قدیمی که قبلاً مقدار '1' را ذخیره کرده‌اند، تغییر نمی‌کنند.
		if ( ! isset( $sms_main_settings['wto_enable_username_password'] ) ) {
			$enable_user_pass_default = get_option( 'wto_enable_username_password', '0' );
			$sms_main_settings['wto_enable_username_password'] = ( $enable_user_pass_default === '1' ) ? 'on' : 'off';
			$changed = true;
		}
		if ( $changed ) {
			update_option( 'sms_main_settings', $sms_main_settings, false );
		}
		update_option( 'wto_pwsms_defaults_migrated', $defaults_migration_version, false );
	}

	$new_settings   = array();
	$api_key_added  = false;
	$checkbox_added = false;

	foreach ( $settings as $field ) {
		if ( isset( $field['name'] ) && $field['name'] === 'sms_gateway_sender' ) {
			$field['default'] = '90008361';
		}

		$new_settings[] = $field;

		if ( isset( $field['name'] ) && $field['name'] === 'sms_gateway' && ! $checkbox_added ) {
			// v3.13.1: default = '0' (پنهان). کاربر فقط Api-Key می‌بیند، مگر اینکه عمداً تیک بزند.
			$enable_username_password = get_option( 'wto_enable_username_password', '0' );
			$new_settings[] = array(
				'name'    => 'wto_enable_username_password',
				'label'   => 'وارد کردن رمز و یوزرنیم',
				'type'    => 'checkbox',
				'desc'    => 'به‌صورت پیش‌فرض غیرفعال است — افزونه فراز اس‌ام‌اس فقط با کلید دسترسی (Api-Key) کار می‌کند. در صورت نیاز به نام کاربری/رمز برای سایر وب‌سرویس‌ها، این گزینه را فعال کنید.',
				'default' => $enable_username_password === '1' ? 'on' : 'off',
			);
			$checkbox_added = true;
		}
		
		if ( isset( $field['name'] ) && $field['name'] === 'sms_gateway_sender' && ! $api_key_added ) {
			$new_settings[] = [
				'name'  => 'sms_gateway_apikey',
				'label' => 'کلید دسترسی (Api-Key)',
				'type'  => 'text',
				'ltr'   => true,
				'desc'  => 'برای Gateway Farazsms.com(Next) از Api-Key استفاده می‌شود.',
			];
			$api_key_added = true;
		}
	}
	
	return $new_settings;
}

/**
 * ذخیره وضعیت چک‌باکس "وارد کردن رمز و یوزرنیم" هنگام ذخیره تنظیمات
 */
add_action( 'update_option_sms_main_settings', 'wto_save_username_password_checkbox', 10, 2 );
function wto_save_username_password_checkbox( $old_value, $value ) {
	if ( ! is_array( $value ) ) {
		return;
	}
	// When a checkbox is unchecked in an HTML form, its key is absent from $_POST entirely.
	// Previously we defaulted the absent case to '1' (enabled), which made the toggle
	// impossible to turn off. Correct behavior: absent = '0' (disabled).
	if ( isset( $value['wto_enable_username_password'] ) ) {
		$checkbox_value = $value['wto_enable_username_password'] === 'on' ? '1' : '0';
	} else {
		$checkbox_value = '0';
	}
	update_option( 'wto_enable_username_password', $checkbox_value, false );
}

/**
 * افزودن CSS برای مخفی کردن فیلدهای username/password (به عنوان پشتیبان)
 */
add_action( 'admin_head', 'wto_hide_username_password_fields' );
function wto_hide_username_password_fields() {
	$screen = get_current_screen();
	if ( ! wto_is_pwsms_admin_settings_screen( $screen ) ) {
		return;
	}

	// v3.13.1: default = '0' — فیلد u/p به‌صورت پیش‌فرض پنهان است.
	$enable_username_password = get_option( 'wto_enable_username_password', '0' );
	?>
	<style type="text/css">
		.wto-hide-username-password {
			display: none !important;
		}
	</style>
	<?php
}

/**
 * افزودن JavaScript برای toggle کردن فیلدهای username/password با استفاده از action hook
 * استفاده از action hook افزونه پیامک فارسی که مطمئناً اجرا می‌شود
 */
add_action( 'pwoosms_settings_form_bottom_sms_main_settings', 'wto_add_username_password_toggle_script', 20 );
function wto_add_username_password_toggle_script( $form ) {
	$farazsmsnext_class = 'PW\PWSMS\Gateways\FarazSMSNext';
	$iranpayamak_class = 'PW\PWSMS\Gateways\IranPayamak';
	if ( ! class_exists( $farazsmsnext_class ) ) {
		return;
	}
	
	// v3.13.1: default = '0' (پنهان).
	$enable_username_password = get_option( 'wto_enable_username_password', '0' );
	$farazsmsnext_full_class = str_replace( '\\', '\\\\', $farazsmsnext_class );
	$iranpayamak_full_class = str_replace( '\\', '\\\\', $iranpayamak_class );
	?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		function wtoToggleUsernamePasswordFields() {
			var checkbox = $('#wpuf-sms_main_settings\\[wto_enable_username_password\\]');
			var usernameRow = $('input[name="sms_main_settings[sms_gateway_username]"]').closest('tr');
			var passwordRow = $('input[name="sms_main_settings[sms_gateway_password]"]').closest('tr');
			
			if (checkbox.length && usernameRow.length && passwordRow.length) {
				var isChecked = checkbox.is(':checked');
				
				if (isChecked) {
					usernameRow.show();
					passwordRow.show();
				} else {
					usernameRow.hide();
					passwordRow.hide();
			}
			}
		}
		
		$(document.body).on('change', '#wpuf-sms_main_settings\\[wto_enable_username_password\\]', function() {
			wtoToggleUsernamePasswordFields();
		});
		
		var checkboxInit = $('#wpuf-sms_main_settings\\[wto_enable_username_password\\]');
		if (checkboxInit.length) {
			checkboxInit.change(function() {
				wtoToggleUsernamePasswordFields();
			}).change();
		}
		
		setTimeout(function() {
			wtoToggleUsernamePasswordFields();
		}, 100);
		setTimeout(function() {
			wtoToggleUsernamePasswordFields();
		}, 500);
		
		function toggleApiKeyField() {
			var selectedGateway = $('#sms_main_settings\\[sms_gateway\\]').val();
			var apikeyRow = $('input[name="sms_main_settings[sms_gateway_apikey]"]').closest('tr');
			
			if (apikeyRow.length) {
				var supportedGateways = [
					'<?php echo esc_js( $farazsmsnext_full_class ); ?>',
					'<?php echo esc_js( $iranpayamak_full_class ); ?>'
				];
				if (supportedGateways.indexOf(selectedGateway) !== -1) {
					apikeyRow.show();
				} else {
					apikeyRow.hide();
				}
			}
		}
		
		$(document.body).on('change', '#sms_main_settings\\[sms_gateway\\]', function() {
			setTimeout(toggleApiKeyField, 50);
		});
		
		function setDefaultSender() {
			var senderInput = $('input[name="sms_main_settings[sms_gateway_sender]"]');
			if (senderInput.length && senderInput.val() === '') {
				senderInput.val('90008361');
			}
		}
		
		setTimeout(toggleApiKeyField, 100);
		setTimeout(setDefaultSender, 100);
		setTimeout(setDefaultSender, 300);
		
		$(document.body).on('change', '#sms_main_settings\\[sms_gateway\\]', function() {
			setTimeout(setDefaultSender, 50);
		});
	});
	</script>
	<?php
}



/**
 * افزودن دکمه "ساخت پترن" کنار هر textarea وضعیت در صفحه buyer
 * استفاده از action hook برای اضافه کردن دکمه بعد از render شدن فیلدها
 */
add_action( 'pwoosms_settings_form_bottom_sms_buyer_settings', 'wto_add_pattern_buttons_to_buyer_form', 10, 1 );
add_action( 'admin_footer', 'wto_add_pattern_buttons_script' );

function wto_add_pattern_buttons_to_buyer_form( $form ) {
	$patterns = get_option('wto_patterns', []);
	// JSON_HEX_TAG / JSON_HEX_AMP prevent a `</script>` inside an admin-stored pattern
	// from breaking out of the surrounding inline <script> block.
	$patterns_json = wp_json_encode( $patterns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	?>
	<script type="text/javascript">
	(function($) {
		// پاس دادن اطلاعات پترن از PHP به JavaScript
		var wtoPatterns = <?php echo $patterns_json; ?>;
		
		// تابع برای نمایش اطلاعات پترن
		function wtoDisplayPatternInfo($textarea, statusKey, sectionType, $container) {
			// اگر container داده نشده، از th استفاده کن
			if (!$container) {
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				$container = $tr.find('.wto-pattern-container');
				if (!$container.length) {
					return;
				}
			}
			
			// حذف info box قبلی اگر وجود داشته باشد
			$container.find('.wto-pattern-info').remove();
			
			// بررسی وجود کد پترن
			var patternCode = null;
			if (wtoPatterns && wtoPatterns[sectionType] && wtoPatterns[sectionType][statusKey]) {
				patternCode = wtoPatterns[sectionType][statusKey];
			}
			
			if (!patternCode) {
				return; // اگر کد پترن وجود نداشت، چیزی نمایش نده
			}
			
			// ساخت info box
			var $infoBox = $('<div class="wto-pattern-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-right: 3px solid #2271b1; border-radius: 3px; font-size: 13px;"></div>');
			$infoBox.html('<strong>کد پترن:</strong> <code style="background: #fff; padding: 2px 5px; border-radius: 2px;">' + patternCode + '</code><br><span class="wto-pattern-status">در حال دریافت وضعیت...</span>');
			
			// اضافه کردن info box به container (بعد از دکمه)
			$container.append($infoBox);
			
			// دریافت جزئیات پترن از API
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wto_get_pattern_details',
					pattern_code: patternCode,
					section_type: sectionType,
					nonce: '<?php echo wp_create_nonce( "wto_get_pattern_details" ); ?>'
				},
				success: function(response) {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					if (response.success && response.data && response.data.data) {
						var patternData = response.data.data;
						var status = patternData.status || 'نامشخص';
						var statusText = '';
						var statusClass = '';
						
						// تعیین متن و کلاس بر اساس وضعیت
						if (status === 'active' || status === 'approved') {
							statusText = 'فعال';
							statusClass = 'wto-status-active';
						} else if (status === 'pending' || status === 'waiting') {
							statusText = 'در انتظار تایید';
							statusClass = 'wto-status-pending';
						} else if (status === 'inactive' || status === 'rejected') {
							statusText = 'غیرفعال';
							statusClass = 'wto-status-inactive';
						} else {
							statusText = status;
							statusClass = 'wto-status-unknown';
						}
						
						$statusSpan.html('<strong>وضعیت:</strong> <span class="' + statusClass + '">' + statusText + '</span>');
					} else {
						$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
					}
				},
				error: function() {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
				}
			});
		}
		
		function wtoAddPatternButtons() {
			var $textareas = $('textarea[name*="sms_buyer_settings"][name*="sms_body_"]');
			
			if ($textareas.length === 0) {
				$textareas = $('#sms_buyer_settings textarea[name*="sms_body_"]');
			}
			
			if ($textareas.length === 0) {
				$textareas = $('textarea').filter(function() {
					var name = $(this).attr('name') || '';
					return name.indexOf('sms_buyer_settings') !== -1 && name.indexOf('sms_body_') !== -1;
				});
			}
			
			if ($textareas.length === 0) {
				return;
			}
			
			$textareas.each(function() {
				var $textarea = $(this);
				var textareaName = $textarea.attr('name') || '';
				if (textareaName.indexOf('sms_buyer_settings') === -1 || textareaName.indexOf('sms_body_') === -1) {
					return;
				}
				
				// بررسی که آیا دکمه قبلاً اضافه شده یا نه
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				
				// اگر دکمه قبلاً اضافه شده، skip کن
				if ($tr.find('.wto-pattern-button').length > 0) {
					return;
				}
				
				// استخراج نام وضعیت از name
				var statusKey = '';
				var match = textareaName.match(/sms_body_([^\]]+)/);
				if (match && match[1]) {
					statusKey = match[1];
				}
				
				if (!statusKey) {
					return;
				}
				
				// پیدا کردن th (label) برای قرار دادن دکمه و info box
				var $th = $tr.find('th');
				if (!$th.length) {
					return;
				}
				
				// ساخت container برای دکمه و info box
				var $container = $('<div class="wto-pattern-container" style="margin-top: 5px; clear: both;"></div>');
				
				// ساخت دکمه
				var buttonId = 'wto-pattern-btn-' + statusKey.replace(/[^a-zA-Z0-9_-]/g, '-');
				var $button = $('<button type="button" id="' + buttonId + '" class="button wto-pattern-button">ساخت پترن</button>');
				// اعمال استایل inline برای اطمینان از اعمال
				$button.css({
					'background': '#2271b1',
					'background-color': '#2271b1',
					'color': '#fff',
					'border-color': '#2271b1',
					'border': '1px solid #2271b1',
					'font-weight': '600',
					'padding': '4px 10px',
					'margin-top': '10px',
					'margin-right': '5px',
					'cursor': 'pointer',
					'width': '100%',
				});
				
				$button.on('mouseenter', function() {
					$(this).css({
						'background': '#135e96',
						'background-color': '#135e96',
						'border-color': '#135e96',
						'border': '1px solid #135e96'
					});
				}).on('mouseleave', function() {
					$(this).css({
						'background': '#2271b1',
						'background-color': '#2271b1',
						'border-color': '#2271b1',
						'border': '1px solid #2271b1'
					});
				});
				
				// اضافه کردن event handler برای دکمه
				$button.on('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					var textareaValue = $textarea.val();
					if (!textareaValue || textareaValue.trim() === '') {
						alert('⚠️ متن پیامک خالی است. لطفا ابتدا متن را وارد کنید.');
						return;
					}
					
					var $btn = $(this);
					var originalText = $btn.text();
					
					// غیرفعال کردن دکمه و نمایش loading
					$btn.prop('disabled', true).text('در حال ساخت...');
					
					// ارسال درخواست AJAX برای ساخت پترن
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wto_create_pattern',
							message_text: textareaValue,
							status_key: statusKey,
							section_type: 'buyer',
							nonce: '<?php echo wp_create_nonce( "wto_create_pattern" ); ?>'
						},
						success: function(response) {
							if (response.success && response.data && response.data.pattern_code) {
								var patternCode = response.data.pattern_code;
								
								// به‌روزرسانی wtoPatterns
								if (!wtoPatterns) {
									wtoPatterns = {};
								}
								if (!wtoPatterns.buyer) {
									wtoPatterns.buyer = {};
								}
								wtoPatterns.buyer[statusKey] = patternCode;
								
								var result = '✅ پترن با موفقیت ساخته شد!\n\n';
								result += 'کد پترن: ' + patternCode + '\n\n';
								result += 'برای استفاده در پیامک، متن خود را به این صورت تغییر دهید:\n\n';
								result += 'patterncode:' + patternCode + '\n';
								result += 'متغیر1:مقدار1\n';
								result += 'متغیر2:مقدار2\n';
								alert(result);
								
								// نمایش اطلاعات پترن
								wtoDisplayPatternInfo($textarea, statusKey, 'buyer');
							} else {
								var errorMsg = '❌ خطا در ساخت پترن:\n\n';
								if (response.data && response.data.message) {
									errorMsg += response.data.message;
								} else if (response.data && typeof response.data === 'string') {
									errorMsg += response.data;
								} else {
									errorMsg += 'خطای ناشناخته';
								}
								alert(errorMsg);
							}
						},
						error: function(xhr, status, error) {
							alert('❌ خطا در ارسال درخواست:\n\n' + error + '\n\nلطفا دوباره تلاش کنید.');
						},
						complete: function() {
							// فعال کردن مجدد دکمه
							$btn.prop('disabled', false).text(originalText);
						}
					});
				});
				
				// اضافه کردن دکمه به container
				$container.append($button);
				
				// اضافه کردن container به th (label)
				$th.append($container);
				
				// نمایش اطلاعات پترن اگر وجود داشته باشد
				wtoDisplayPatternInfo($textarea, statusKey, 'buyer', $container);
			});
		}
		
		// اجرای فوری - بدون wait کردن
		if (typeof jQuery !== 'undefined') {
			jQuery(function($) {
				wtoAddPatternButtons();
				setTimeout(wtoAddPatternButtons, 50);
				setTimeout(wtoAddPatternButtons, 150);
				setTimeout(wtoAddPatternButtons, 300);
				setTimeout(wtoAddPatternButtons, 500);
			});
		} else {
			// اگر jQuery هنوز لود نشده، wait کن
			window.addEventListener('DOMContentLoaded', function() {
				if (typeof jQuery !== 'undefined') {
					jQuery(function($) {
						wtoAddPatternButtons();
						setTimeout(wtoAddPatternButtons, 50);
						setTimeout(wtoAddPatternButtons, 150);
					});
				}
			});
		}
	})(jQuery || window.jQuery || window.$);
	</script>
	<?php
}

function wto_add_pattern_buttons_script() {
	$screen = get_current_screen();
	if ( ! wto_is_pwsms_admin_settings_screen( $screen ) ) {
		return;
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';
	if ( $tab !== 'buyer' && ! empty( $tab ) ) {
		return;
	}
	
	// دریافت اطلاعات پترن‌های ذخیره شده
	$patterns = get_option('wto_patterns', []);
	// JSON_HEX_TAG / JSON_HEX_AMP prevent a `</script>` inside an admin-stored pattern
	// from breaking out of the surrounding inline <script> block.
	$patterns_json = wp_json_encode( $patterns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	
	?>
	<script type="text/javascript">
	jQuery(function($) {
		// پاس دادن اطلاعات پترن از PHP به JavaScript
		var wtoPatterns = <?php echo $patterns_json; ?>;
		
		// تابع برای نمایش اطلاعات پترن
		function wtoDisplayPatternInfo($textarea, statusKey, sectionType, $container) {
			// اگر container داده نشده، از th استفاده کن
			if (!$container) {
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				$container = $tr.find('.wto-pattern-container');
				if (!$container.length) {
					return;
				}
			}
			
			// حذف info box قبلی اگر وجود داشته باشد
			$container.find('.wto-pattern-info').remove();
			
			// بررسی وجود کد پترن
			var patternCode = null;
			if (wtoPatterns && wtoPatterns[sectionType] && wtoPatterns[sectionType][statusKey]) {
				patternCode = wtoPatterns[sectionType][statusKey];
			}
			
			if (!patternCode) {
				return; // اگر کد پترن وجود نداشت، چیزی نمایش نده
			}
			
			// ساخت info box
			var $infoBox = $('<div class="wto-pattern-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-right: 3px solid #2271b1; border-radius: 3px; font-size: 13px;"></div>');
			$infoBox.html('<strong>کد پترن:</strong> <code style="background: #fff; padding: 2px 5px; border-radius: 2px;">' + patternCode + '</code><br><span class="wto-pattern-status">در حال دریافت وضعیت...</span>');
			
			// اضافه کردن info box به container (بعد از دکمه)
			$container.append($infoBox);
			
			// دریافت جزئیات پترن از API
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wto_get_pattern_details',
					pattern_code: patternCode,
					section_type: sectionType,
					nonce: '<?php echo wp_create_nonce( "wto_get_pattern_details" ); ?>'
				},
				success: function(response) {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					if (response.success && response.data && response.data.data) {
						var patternData = response.data.data;
						var status = patternData.status || 'نامشخص';
						var statusText = '';
						var statusClass = '';
						
						// تعیین متن و کلاس بر اساس وضعیت
						if (status === 'active' || status === 'approved') {
							statusText = 'فعال';
							statusClass = 'wto-status-active';
						} else if (status === 'pending' || status === 'waiting') {
							statusText = 'در انتظار تایید';
							statusClass = 'wto-status-pending';
						} else if (status === 'inactive' || status === 'rejected') {
							statusText = 'غیرفعال';
							statusClass = 'wto-status-inactive';
						} else {
							statusText = status;
							statusClass = 'wto-status-unknown';
						}
						
						$statusSpan.html('<strong>وضعیت:</strong> <span class="' + statusClass + '">' + statusText + '</span>');
					} else {
						$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
					}
				},
				error: function() {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
				}
			});
		}
		
		function wtoAddPatternButtons() {
			// پیدا کردن همه textarea های buyer settings
			var $textareas = $('textarea[name*="sms_buyer_settings"][name*="sms_body_"]');
			
			if ($textareas.length === 0) {
				$textareas = $('#sms_buyer_settings textarea[name*="sms_body_"]');
			}
			
			if ($textareas.length === 0) {
				$textareas = $('textarea[name*="sms_body_"]').filter(function() {
					return $(this).attr('name').indexOf('sms_buyer_settings') !== -1;
				});
			}
			
			$textareas.each(function() {
				var $textarea = $(this);
				var textareaName = $textarea.attr('name') || '';
				
				// فقط textarea های buyer settings
				if (textareaName.indexOf('sms_buyer_settings') === -1 || textareaName.indexOf('sms_body_') === -1) {
					return;
				}
				
				// بررسی که آیا دکمه قبلاً اضافه شده یا نه
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				
				// اگر دکمه قبلاً اضافه شده، skip کن
				if ($tr.find('.wto-pattern-button').length > 0) {
					return;
				}
				
				// استخراج نام وضعیت از name
				var statusKey = '';
				var match = textareaName.match(/sms_body_([^\]]+)/);
				if (match && match[1]) {
					statusKey = match[1];
				}
				
				if (!statusKey) {
					return;
				}
				
				// پیدا کردن th (label) برای قرار دادن دکمه و info box
				var $th = $tr.find('th');
				if (!$th.length) {
					return;
				}
				
				// ساخت container برای دکمه و info box
				var $container = $('<div class="wto-pattern-container" style="margin-top: 5px; clear: both;"></div>');
				
				// ساخت دکمه
				var buttonId = 'wto-pattern-btn-' + statusKey.replace(/[^a-zA-Z0-9_-]/g, '-');
				var $button = $('<button type="button" id="' + buttonId + '" class="button wto-pattern-button">ساخت پترن</button>');
				// اعمال استایل inline برای اطمینان از اعمال
				$button.css({
					'background': '#2271b1',
					'background-color': '#2271b1',
					'color': '#fff',
					'border-color': '#2271b1',
					'border': '1px solid #2271b1',
					'font-weight': '600',
					'padding': '8px 15px',
					'margin-top': '10px',
					'margin-right': '5px',
					'cursor': 'pointer'
				});
				
				// اضافه کردن event handler برای hover
				$button.on('mouseenter', function() {
					$(this).css({
						'background': '#135e96',
						'background-color': '#135e96',
						'border-color': '#135e96',
						'border': '1px solid #135e96'
					});
				}).on('mouseleave', function() {
					$(this).css({
						'background': '#2271b1',
						'background-color': '#2271b1',
						'border-color': '#2271b1',
						'border': '1px solid #2271b1'
					});
				});
				
				// اضافه کردن event handler برای دکمه
				$button.on('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					var textareaValue = $textarea.val();
					if (!textareaValue || textareaValue.trim() === '') {
						alert('⚠️ متن پیامک خالی است. لطفا ابتدا متن را وارد کنید.');
						return;
					}
					
					var $btn = $(this);
					var originalText = $btn.text();
					
					// غیرفعال کردن دکمه و نمایش loading
					$btn.prop('disabled', true).text('در حال ساخت...');
					
					// ارسال درخواست AJAX برای ساخت پترن
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wto_create_pattern',
							message_text: textareaValue,
							status_key: statusKey,
							section_type: 'buyer',
							nonce: '<?php echo wp_create_nonce( "wto_create_pattern" ); ?>'
						},
						success: function(response) {
							if (response.success && response.data && response.data.pattern_code) {
								var patternCode = response.data.pattern_code;
								
								// به‌روزرسانی wtoPatterns
								if (!wtoPatterns) {
									wtoPatterns = {};
								}
								if (!wtoPatterns.buyer) {
									wtoPatterns.buyer = {};
								}
								wtoPatterns.buyer[statusKey] = patternCode;
								
								var result = '✅ پترن با موفقیت ساخته شد!\n\n';
								result += 'کد پترن: ' + patternCode + '\n\n';
								result += 'برای استفاده در پیامک، متن خود را به این صورت تغییر دهید:\n\n';
								result += 'patterncode:' + patternCode + '\n';
								result += 'متغیر1:مقدار1\n';
								result += 'متغیر2:مقدار2\n';
								alert(result);
								
								// نمایش اطلاعات پترن
								wtoDisplayPatternInfo($textarea, statusKey, 'buyer');
							} else {
								var errorMsg = '❌ خطا در ساخت پترن:\n\n';
								if (response.data && response.data.message) {
									errorMsg += response.data.message;
								} else if (response.data && typeof response.data === 'string') {
									errorMsg += response.data;
								} else {
									errorMsg += 'خطای ناشناخته';
								}
								alert(errorMsg);
							}
						},
						error: function(xhr, status, error) {
							alert('❌ خطا در ارسال درخواست:\n\n' + error + '\n\nلطفا دوباره تلاش کنید.');
						},
						complete: function() {
							// فعال کردن مجدد دکمه
							$btn.prop('disabled', false).text(originalText);
						}
					});
				});
				
				// اضافه کردن دکمه به container
				$container.append($button);
				
				// اضافه کردن container به th (label)
				$th.append($container);
				
				// نمایش اطلاعات پترن اگر وجود داشته باشد
				wtoDisplayPatternInfo($textarea, statusKey, 'buyer', $container);
			});
		}
		
		wtoAddPatternButtons();
		setTimeout(wtoAddPatternButtons, 100);
		setTimeout(wtoAddPatternButtons, 300);
		setTimeout(wtoAddPatternButtons, 500);
		setTimeout(wtoAddPatternButtons, 1000);
		setTimeout(wtoAddPatternButtons, 2000);
		
		$(window).on('load', function() {
			setTimeout(wtoAddPatternButtons, 100);
			setTimeout(wtoAddPatternButtons, 500);
		});
		
		if (typeof MutationObserver !== 'undefined') {
			var observer = new MutationObserver(function(mutations) {
				var shouldRun = false;
				mutations.forEach(function(mutation) {
					if (mutation.addedNodes.length > 0) {
						shouldRun = true;
					}
				});
				if (shouldRun) {
					setTimeout(wtoAddPatternButtons, 200);
				}
			});
			
			var bodyElement = document.body;
			if (bodyElement) {
				observer.observe(bodyElement, { 
					childList: true, 
					subtree: true 
				});
			}
		}
		
		$(document).on('click', 'a[href*="tab=buyer"]', function() {
			setTimeout(wtoAddPatternButtons, 500);
			setTimeout(wtoAddPatternButtons, 1000);
		});
	});
	</script>
	<style type="text/css">
		/* استایل برای دکمه ساخت پترن */
		button.wto-pattern-button,
		.button.wto-pattern-button,
		.wto-pattern-button.button {
			background: #2271b1 !important;
			background-color: #2271b1 !important;
			color: #fff !important;
			border-color: #2271b1 !important;
			border: 1px solid #2271b1 !important;
			font-weight: 600 !important;
			padding: 8px 15px !important;
			margin-top: 10px !important;
			margin-right: 5px !important;
			cursor: pointer !important;
		}
		button.wto-pattern-button:hover,
		.button.wto-pattern-button:hover,
		.wto-pattern-button.button:hover {
			background: #135e96 !important;
			background-color: #135e96 !important;
			border-color: #135e96 !important;
			border: 1px solid #135e96 !important;
			color: #fff !important;
		}
		button.wto-pattern-button:focus,
		.button.wto-pattern-button:focus,
		.wto-pattern-button.button:focus {
			box-shadow: 0 0 0 1px #fff, 0 0 0 3px #2271b1 !important;
		}
		/* استایل برای وضعیت پترن */
		.wto-status-active {
			color: #00a32a;
			font-weight: 600;
		}
		.wto-status-pending {
			color: #dba617;
			font-weight: 600;
		}
		.wto-status-inactive {
			color: #d63638;
			font-weight: 600;
		}
		.wto-status-unknown {
			color: #50575e;
		}
		/* استایل برای container دکمه و info box در th */
		.wto-pattern-container {
			margin-top: 5px;
			clear: both;
		}
	</style>
	<?php
}

/**
 * افزودن دکمه "ساخت پترن" کنار هر textarea وضعیت در صفحه super_admin
 * استفاده از action hook برای اضافه کردن دکمه بعد از render شدن فیلدها
 */
add_action( 'pwoosms_settings_form_bottom_sms_super_admin_settings', 'wto_add_pattern_buttons_to_super_admin_form', 10, 1 );
add_action( 'admin_footer', 'wto_add_pattern_buttons_super_admin_script' );

function wto_add_pattern_buttons_to_super_admin_form( $form ) {
	$patterns = get_option('wto_patterns', []);
	// JSON_HEX_TAG / JSON_HEX_AMP prevent a `</script>` inside an admin-stored pattern
	// from breaking out of the surrounding inline <script> block.
	$patterns_json = wp_json_encode( $patterns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	?>
	<script type="text/javascript">
	(function($) {
		var wtoPatterns = <?php echo $patterns_json; ?>;
		
		function wtoDisplayPatternInfo($textarea, statusKey, sectionType, $container) {
			if (!$container) {
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				$container = $tr.find('.wto-pattern-container');
				if (!$container.length) {
					return;
				}
			}
			
			// حذف info box قبلی اگر وجود داشته باشد
			$container.find('.wto-pattern-info').remove();
			
			// بررسی وجود کد پترن
			var patternCode = null;
			if (wtoPatterns && wtoPatterns[sectionType] && wtoPatterns[sectionType][statusKey]) {
				patternCode = wtoPatterns[sectionType][statusKey];
			}
			
			if (!patternCode) {
				return; // اگر کد پترن وجود نداشت، چیزی نمایش نده
			}
			
			// ساخت info box
			var $infoBox = $('<div class="wto-pattern-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-right: 3px solid #2271b1; border-radius: 3px; font-size: 13px;"></div>');
			$infoBox.html('<strong>کد پترن:</strong> <code style="background: #fff; padding: 2px 5px; border-radius: 2px;">' + patternCode + '</code><br><span class="wto-pattern-status">در حال دریافت وضعیت...</span>');
			
			// اضافه کردن info box به container (بعد از دکمه)
			$container.append($infoBox);
			
			// دریافت جزئیات پترن از API
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wto_get_pattern_details',
					pattern_code: patternCode,
					section_type: sectionType,
					nonce: '<?php echo wp_create_nonce( "wto_get_pattern_details" ); ?>'
				},
				success: function(response) {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					if (response.success && response.data && response.data.data) {
						var patternData = response.data.data;
						var status = patternData.status || 'نامشخص';
						var statusText = '';
						var statusClass = '';
						
						// تعیین متن و کلاس بر اساس وضعیت
						if (status === 'active' || status === 'approved') {
							statusText = 'فعال';
							statusClass = 'wto-status-active';
						} else if (status === 'pending' || status === 'waiting') {
							statusText = 'در انتظار تایید';
							statusClass = 'wto-status-pending';
						} else if (status === 'inactive' || status === 'rejected') {
							statusText = 'غیرفعال';
							statusClass = 'wto-status-inactive';
						} else {
							statusText = status;
							statusClass = 'wto-status-unknown';
						}
						
						$statusSpan.html('<strong>وضعیت:</strong> <span class="' + statusClass + '">' + statusText + '</span>');
					} else {
						$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
					}
				},
				error: function() {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
				}
			});
		}
		
		function wtoAddPatternButtonsSuperAdmin() {
			// پیدا کردن همه textarea های super_admin settings
			// شامل: super_admin_sms_body_* و admin_low_stock و admin_out_stock
			var $textareas = $('textarea[name*="sms_super_admin_settings"]').filter(function() {
				var name = $(this).attr('name') || '';
				return name.indexOf('sms_super_admin_settings') !== -1 && (
					name.indexOf('super_admin_sms_body_') !== -1 ||
					name.indexOf('[admin_low_stock]') !== -1 ||
					name.indexOf('[admin_out_stock]') !== -1
				);
			});
			
			if ($textareas.length === 0) {
				$textareas = $('#sms_super_admin_settings textarea').filter(function() {
					var name = $(this).attr('name') || '';
					return name.indexOf('super_admin_sms_body_') !== -1 ||
						name.indexOf('[admin_low_stock]') !== -1 ||
						name.indexOf('[admin_out_stock]') !== -1;
				});
			}
			
			if ($textareas.length === 0) {
				return;
			}
			
			$textareas.each(function() {
				var $textarea = $(this);
				var textareaName = $textarea.attr('name') || '';
				if (textareaName.indexOf('sms_super_admin_settings') === -1) {
					return;
				}
				
				// بررسی که آیا دکمه قبلاً اضافه شده یا نه
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				
				// اگر دکمه قبلاً اضافه شده، skip کن
				if ($tr.find('.wto-pattern-button').length > 0) {
					return;
				}
				
				// استخراج نام وضعیت از name
				var statusKey = '';
				var match = textareaName.match(/super_admin_sms_body_([^\]]+)/);
				if (match && match[1]) {
					statusKey = match[1];
				} else {
					// برای فیلدهای موجودی انبار
					match = textareaName.match(/\[(admin_low_stock|admin_out_stock)\]/);
					if (match && match[1]) {
						statusKey = match[1];
					}
				}
				
				if (!statusKey) {
					return;
				}
				
				// پیدا کردن th (label) برای قرار دادن دکمه و info box
				var $th = $tr.find('th');
				if (!$th.length) {
					return;
				}
				
				// ساخت container برای دکمه و info box
				var $container = $('<div class="wto-pattern-container" style="margin-top: 5px; clear: both;"></div>');
				
				// ساخت دکمه
				var buttonId = 'wto-pattern-btn-super-' + statusKey.replace(/[^a-zA-Z0-9_-]/g, '-');
				var $button = $('<button type="button" id="' + buttonId + '" class="button wto-pattern-button">ساخت پترن</button>');
				// اعمال استایل inline برای اطمینان از اعمال
				$button.css({
					'background': '#2271b1',
					'background-color': '#2271b1',
					'color': '#fff',
					'border-color': '#2271b1',
					'border': '1px solid #2271b1',
					'font-weight': '600',
					'padding': '4px 10px',
					'margin-top': '10px',
					'margin-right': '5px',
					'cursor': 'pointer',
					'width': '100%'
				});
				
				$button.on('mouseenter', function() {
					$(this).css({
						'background': '#135e96',
						'background-color': '#135e96',
						'border-color': '#135e96',
						'border': '1px solid #135e96'
					});
				}).on('mouseleave', function() {
					$(this).css({
						'background': '#2271b1',
						'background-color': '#2271b1',
						'border-color': '#2271b1',
						'border': '1px solid #2271b1'
					});
				});
				
				// اضافه کردن event handler برای دکمه
				$button.on('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					var textareaValue = $textarea.val();
					if (!textareaValue || textareaValue.trim() === '') {
						alert('⚠️ متن پیامک خالی است. لطفا ابتدا متن را وارد کنید.');
						return;
					}
					
					var $btn = $(this);
					var originalText = $btn.text();
					
					// غیرفعال کردن دکمه و نمایش loading
					$btn.prop('disabled', true).text('در حال ساخت...');
					
					// ارسال درخواست AJAX برای ساخت پترن
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wto_create_pattern',
							message_text: textareaValue,
							status_key: statusKey,
							section_type: 'super_admin',
							nonce: '<?php echo wp_create_nonce( "wto_create_pattern" ); ?>'
						},
						success: function(response) {
							if (response.success && response.data && response.data.pattern_code) {
								var patternCode = response.data.pattern_code;
								
								// به‌روزرسانی wtoPatterns
								if (!wtoPatterns) {
									wtoPatterns = {};
								}
								if (!wtoPatterns.super_admin) {
									wtoPatterns.super_admin = {};
								}
								wtoPatterns.super_admin[statusKey] = patternCode;
								
								var result = '✅ پترن با موفقیت ساخته شد!\n\n';
								result += 'کد پترن: ' + patternCode + '\n\n';
								result += 'برای استفاده در پیامک، متن خود را به این صورت تغییر دهید:\n\n';
								result += 'patterncode:' + patternCode + '\n';
								result += 'متغیر1:مقدار1\n';
								result += 'متغیر2:مقدار2\n';
								alert(result);
								
								// نمایش اطلاعات پترن
								wtoDisplayPatternInfo($textarea, statusKey, 'super_admin', $container);
							} else {
								var errorMsg = '❌ خطا در ساخت پترن:\n\n';
								if (response.data && response.data.message) {
									errorMsg += response.data.message;
								} else if (response.data && typeof response.data === 'string') {
									errorMsg += response.data;
								} else {
									errorMsg += 'خطای ناشناخته';
								}
								alert(errorMsg);
							}
						},
						error: function(xhr, status, error) {
							alert('❌ خطا در ارسال درخواست:\n\n' + error + '\n\nلطفا دوباره تلاش کنید.');
						},
						complete: function() {
							// فعال کردن مجدد دکمه
							$btn.prop('disabled', false).text(originalText);
						}
					});
				});
				
				// اضافه کردن دکمه به container
				$container.append($button);
				
				// اضافه کردن container به th (label)
				$th.append($container);
				
				// نمایش اطلاعات پترن اگر وجود داشته باشد
				wtoDisplayPatternInfo($textarea, statusKey, 'super_admin', $container);
			});
		}
		
		// اجرای فوری - بدون wait کردن
		if (typeof jQuery !== 'undefined') {
			jQuery(function($) {
				wtoAddPatternButtonsSuperAdmin();
				setTimeout(wtoAddPatternButtonsSuperAdmin, 50);
				setTimeout(wtoAddPatternButtonsSuperAdmin, 150);
				setTimeout(wtoAddPatternButtonsSuperAdmin, 300);
				setTimeout(wtoAddPatternButtonsSuperAdmin, 500);
			});
		} else {
			// اگر jQuery هنوز لود نشده، wait کن
			window.addEventListener('DOMContentLoaded', function() {
				if (typeof jQuery !== 'undefined') {
					jQuery(function($) {
						wtoAddPatternButtonsSuperAdmin();
						setTimeout(wtoAddPatternButtonsSuperAdmin, 50);
						setTimeout(wtoAddPatternButtonsSuperAdmin, 150);
					});
				}
			});
		}
	})(jQuery || window.jQuery || window.$);
	</script>
	<?php
}

function wto_add_pattern_buttons_super_admin_script() {
	$screen = get_current_screen();
	if ( ! wto_is_pwsms_admin_settings_screen( $screen ) ) {
		return;
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';
	if ( $tab !== 'super_admin' && ! empty( $tab ) ) {
		return;
	}
	
	// دریافت اطلاعات پترن‌های ذخیره شده
	$patterns = get_option('wto_patterns', []);
	// JSON_HEX_TAG / JSON_HEX_AMP prevent a `</script>` inside an admin-stored pattern
	// from breaking out of the surrounding inline <script> block.
	$patterns_json = wp_json_encode( $patterns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	
	?>
	<script type="text/javascript">
	jQuery(function($) {
		// پاس دادن اطلاعات پترن از PHP به JavaScript
		var wtoPatterns = <?php echo $patterns_json; ?>;
		
		// تابع برای نمایش اطلاعات پترن
		function wtoDisplayPatternInfo($textarea, statusKey, sectionType, $container) {
			// اگر container داده نشده، از th استفاده کن
			if (!$container) {
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				$container = $tr.find('.wto-pattern-container');
				if (!$container.length) {
					return;
				}
			}
			
			// حذف info box قبلی اگر وجود داشته باشد
			$container.find('.wto-pattern-info').remove();
			
			// بررسی وجود کد پترن
			var patternCode = null;
			if (wtoPatterns && wtoPatterns[sectionType] && wtoPatterns[sectionType][statusKey]) {
				patternCode = wtoPatterns[sectionType][statusKey];
			}
			
			if (!patternCode) {
				return; // اگر کد پترن وجود نداشت، چیزی نمایش نده
			}
			
			// ساخت info box
			var $infoBox = $('<div class="wto-pattern-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-right: 3px solid #2271b1; border-radius: 3px; font-size: 13px;"></div>');
			$infoBox.html('<strong>کد پترن:</strong> <code style="background: #fff; padding: 2px 5px; border-radius: 2px;">' + patternCode + '</code><br><span class="wto-pattern-status">در حال دریافت وضعیت...</span>');
			
			// اضافه کردن info box به container (بعد از دکمه)
			$container.append($infoBox);
			
			// دریافت جزئیات پترن از API
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wto_get_pattern_details',
					pattern_code: patternCode,
					section_type: sectionType,
					nonce: '<?php echo wp_create_nonce( "wto_get_pattern_details" ); ?>'
				},
				success: function(response) {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					if (response.success && response.data && response.data.data) {
						var patternData = response.data.data;
						var status = patternData.status || 'نامشخص';
						var statusText = '';
						var statusClass = '';
						
						// تعیین متن و کلاس بر اساس وضعیت
						if (status === 'active' || status === 'approved') {
							statusText = 'فعال';
							statusClass = 'wto-status-active';
						} else if (status === 'pending' || status === 'waiting') {
							statusText = 'در انتظار تایید';
							statusClass = 'wto-status-pending';
						} else if (status === 'inactive' || status === 'rejected') {
							statusText = 'غیرفعال';
							statusClass = 'wto-status-inactive';
						} else {
							statusText = status;
							statusClass = 'wto-status-unknown';
						}
						
						$statusSpan.html('<strong>وضعیت:</strong> <span class="' + statusClass + '">' + statusText + '</span>');
					} else {
						$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
					}
				},
				error: function() {
					var $statusSpan = $infoBox.find('.wto-pattern-status');
					$statusSpan.html('<strong>وضعیت:</strong> <span class="wto-status-unknown">خطا در دریافت اطلاعات</span>');
				}
			});
		}
		
		function wtoAddPatternButtonsSuperAdmin() {
			// پیدا کردن همه textarea های super_admin settings
			// شامل: super_admin_sms_body_* و admin_low_stock و admin_out_stock
			var $textareas = $('textarea[name*="sms_super_admin_settings"]').filter(function() {
				var name = $(this).attr('name') || '';
				return name.indexOf('sms_super_admin_settings') !== -1 && (
					name.indexOf('super_admin_sms_body_') !== -1 ||
					name.indexOf('[admin_low_stock]') !== -1 ||
					name.indexOf('[admin_out_stock]') !== -1
				);
			});
			
			if ($textareas.length === 0) {
				$textareas = $('#sms_super_admin_settings textarea').filter(function() {
					var name = $(this).attr('name') || '';
					return name.indexOf('super_admin_sms_body_') !== -1 ||
						name.indexOf('[admin_low_stock]') !== -1 ||
						name.indexOf('[admin_out_stock]') !== -1;
				});
			}
			
			$textareas.each(function() {
				var $textarea = $(this);
				var textareaName = $textarea.attr('name') || '';
				
				// فقط textarea های super_admin settings
				if (textareaName.indexOf('sms_super_admin_settings') === -1) {
					return;
				}
				
				// بررسی که آیا دکمه قبلاً اضافه شده یا نه
				var $tr = $textarea.closest('tr');
				if (!$tr.length) {
					return;
				}
				
				// اگر دکمه قبلاً اضافه شده، skip کن
				if ($tr.find('.wto-pattern-button').length > 0) {
					return;
				}
				
				// استخراج نام وضعیت از name
				var statusKey = '';
				var match = textareaName.match(/super_admin_sms_body_([^\]]+)/);
				if (match && match[1]) {
					statusKey = match[1];
				} else {
					// برای فیلدهای موجودی انبار
					match = textareaName.match(/\[(admin_low_stock|admin_out_stock)\]/);
					if (match && match[1]) {
						statusKey = match[1];
					}
				}
				
				if (!statusKey) {
					return;
				}
				
				// پیدا کردن th (label) برای قرار دادن دکمه و info box
				var $th = $tr.find('th');
				if (!$th.length) {
					return;
				}
				
				// ساخت container برای دکمه و info box
				var $container = $('<div class="wto-pattern-container" style="margin-top: 5px; clear: both;"></div>');
				
				// ساخت دکمه
				var buttonId = 'wto-pattern-btn-super-' + statusKey.replace(/[^a-zA-Z0-9_-]/g, '-');
				var $button = $('<button type="button" id="' + buttonId + '" class="button wto-pattern-button">ساخت پترن</button>');
				// اعمال استایل inline برای اطمینان از اعمال
				$button.css({
					'background': '#2271b1',
					'background-color': '#2271b1',
					'color': '#fff',
					'border-color': '#2271b1',
					'border': '1px solid #2271b1',
					'font-weight': '600',
					'padding': '4px 10px',
					'margin-top': '10px',
					'margin-right': '5px',
					'cursor': 'pointer'
				});
				
				// اضافه کردن event handler برای hover
				$button.on('mouseenter', function() {
					$(this).css({
						'background': '#135e96',
						'background-color': '#135e96',
						'border-color': '#135e96',
						'border': '1px solid #135e96'
					});
				}).on('mouseleave', function() {
					$(this).css({
						'background': '#2271b1',
						'background-color': '#2271b1',
						'border-color': '#2271b1',
						'border': '1px solid #2271b1'
					});
				});
				
				// اضافه کردن event handler برای دکمه
				$button.on('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					var textareaValue = $textarea.val();
					if (!textareaValue || textareaValue.trim() === '') {
						alert('⚠️ متن پیامک خالی است. لطفا ابتدا متن را وارد کنید.');
						return;
					}
					
					var $btn = $(this);
					var originalText = $btn.text();
					
					// غیرفعال کردن دکمه و نمایش loading
					$btn.prop('disabled', true).text('در حال ساخت...');
					
					// ارسال درخواست AJAX برای ساخت پترن
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wto_create_pattern',
							message_text: textareaValue,
							status_key: statusKey,
							section_type: 'super_admin',
							nonce: '<?php echo wp_create_nonce( "wto_create_pattern" ); ?>'
						},
						success: function(response) {
							if (response.success && response.data && response.data.pattern_code) {
								var patternCode = response.data.pattern_code;
								
								// به‌روزرسانی wtoPatterns
								if (!wtoPatterns) {
									wtoPatterns = {};
								}
								if (!wtoPatterns.super_admin) {
									wtoPatterns.super_admin = {};
								}
								wtoPatterns.super_admin[statusKey] = patternCode;
								
								var result = '✅ پترن با موفقیت ساخته شد!\n\n';
								result += 'کد پترن: ' + patternCode + '\n\n';
								result += 'برای استفاده در پیامک، متن خود را به این صورت تغییر دهید:\n\n';
								result += 'patterncode:' + patternCode + '\n';
								result += 'متغیر1:مقدار1\n';
								result += 'متغیر2:مقدار2\n';
								alert(result);
								
								// نمایش اطلاعات پترن
								wtoDisplayPatternInfo($textarea, statusKey, 'super_admin', $container);
							} else {
								var errorMsg = '❌ خطا در ساخت پترن:\n\n';
								if (response.data && response.data.message) {
									errorMsg += response.data.message;
								} else if (response.data && typeof response.data === 'string') {
									errorMsg += response.data;
								} else {
									errorMsg += 'خطای ناشناخته';
								}
								alert(errorMsg);
							}
						},
						error: function(xhr, status, error) {
							alert('❌ خطا در ارسال درخواست:\n\n' + error + '\n\nلطفا دوباره تلاش کنید.');
						},
						complete: function() {
							// فعال کردن مجدد دکمه
							$btn.prop('disabled', false).text(originalText);
						}
					});
				});
				
				// اضافه کردن دکمه به container
				$container.append($button);
				
				// اضافه کردن container به th (label)
				$th.append($container);
				
				// نمایش اطلاعات پترن اگر وجود داشته باشد
				wtoDisplayPatternInfo($textarea, statusKey, 'super_admin', $container);
			});
		}
		
		wtoAddPatternButtonsSuperAdmin();
		setTimeout(wtoAddPatternButtonsSuperAdmin, 100);
		setTimeout(wtoAddPatternButtonsSuperAdmin, 300);
		setTimeout(wtoAddPatternButtonsSuperAdmin, 500);
		setTimeout(wtoAddPatternButtonsSuperAdmin, 1000);
		setTimeout(wtoAddPatternButtonsSuperAdmin, 2000);
		
		$(window).on('load', function() {
			setTimeout(wtoAddPatternButtonsSuperAdmin, 100);
			setTimeout(wtoAddPatternButtonsSuperAdmin, 500);
		});
		
		if (typeof MutationObserver !== 'undefined') {
			var observer = new MutationObserver(function(mutations) {
				var shouldRun = false;
				mutations.forEach(function(mutation) {
					if (mutation.addedNodes.length > 0) {
						shouldRun = true;
					}
				});
				if (shouldRun) {
					setTimeout(wtoAddPatternButtonsSuperAdmin, 200);
				}
			});
			
			var bodyElement = document.body;
			if (bodyElement) {
				observer.observe(bodyElement, { 
					childList: true, 
					subtree: true 
				});
			}
		}
		
		$(document).on('click', 'a[href*="tab=super_admin"]', function() {
			setTimeout(wtoAddPatternButtonsSuperAdmin, 500);
			setTimeout(wtoAddPatternButtonsSuperAdmin, 1000);
		});
	});
	</script>
	<style type="text/css">
		/* استایل برای دکمه ساخت پترن */
		button.wto-pattern-button,
		.button.wto-pattern-button,
		.wto-pattern-button.button {
			background: #2271b1 !important;
			background-color: #2271b1 !important;
			color: #fff !important;
			border-color: #2271b1 !important;
			border: 1px solid #2271b1 !important;
			font-weight: 600 !important;
			padding: 4px 10px !important;
			margin-top: 10px !important;
			margin-right: 5px !important;
			cursor: pointer !important;
		}
		button.wto-pattern-button:hover,
		.button.wto-pattern-button:hover,
		.wto-pattern-button.button:hover {
			background: #135e96 !important;
			background-color: #135e96 !important;
			border-color: #135e96 !important;
			border: 1px solid #135e96 !important;
			color: #fff !important;
		}
		button.wto-pattern-button:focus,
		.button.wto-pattern-button:focus,
		.wto-pattern-button.button:focus {
			box-shadow: 0 0 0 1px #fff, 0 0 0 3px #2271b1 !important;
		}
		/* استایل برای وضعیت پترن */
		.wto-status-active {
			color: #00a32a;
			font-weight: 600;
		}
		.wto-status-pending {
			color: #dba617;
			font-weight: 600;
		}
		.wto-status-inactive {
			color: #d63638;
			font-weight: 600;
		}
		.wto-status-unknown {
			color: #50575e;
		}
		.wto-pattern-container {
			float: left;
			margin-top: 5px;
			clear: both;
		}
		th:has(.wto-pattern-container) {
			position: relative;
		}
		th .wto-pattern-container {
			width: 100%;
		}
	</style>
	<?php
}


