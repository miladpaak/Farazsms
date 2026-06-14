<?php
/**
 * توگلِ مدرنِ یکپارچه برای کلِ افزونه.
 *
 * هر <input type="checkbox"> با کلاسِ «wto-toggle» به‌صورتِ یک کلیدِ کشوییِ مدرن
 * نمایش داده می‌شود — بدونِ نیاز به تغییرِ ساختارِ HTML (فقط افزودنِ کلاس).
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_action( 'admin_print_styles', 'wto_toggle_print_css' );
function wto_toggle_print_css() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="wto-toggle-css">
	input[type="checkbox"].wto-toggle {
		-webkit-appearance: none !important; appearance: none !important;
		position: relative; display: inline-block;
		width: 46px !important; height: 26px !important; min-width: 46px !important; max-width: 46px !important;
		border-radius: 26px !important; background: #cbd5e1;
		cursor: pointer; transition: .25s; vertical-align: middle;
		margin: 0; padding: 0 !important; border: none !important; box-shadow: none !important; flex: 0 0 auto;
	}
	input[type="checkbox"].wto-toggle::before {
		content: ""; position: absolute; width: 20px; height: 20px;
		border-radius: 50%; background: #fff; top: 3px; right: 3px;
		transition: .25s; box-shadow: 0 1px 3px rgba(0,0,0,.25); margin: 0;
	}
	input[type="checkbox"].wto-toggle:checked { background: #16a34a; }
	input[type="checkbox"].wto-toggle:checked::before { transform: translateX(-20px); }
	input[type="checkbox"].wto-toggle:focus { outline: 2px solid #a5b4fc; outline-offset: 1px; }
	input[type="checkbox"].wto-toggle:disabled { opacity: .5; cursor: default; }
	</style>
	<?php
}

/**
 * چاپِ یک توگلِ مدرن.
 *
 * @param string $name       نامِ فیلد.
 * @param bool   $checked    وضعیتِ فعلی.
 * @param string $value      مقدارِ ارسالی (پیش‌فرض '1').
 * @param string $extra_attr صفت‌های اضافی (id, data-*, …).
 * @return void
 */
function wto_toggle_input( $name, $checked, $value = '1', $extra_attr = '' ) {
	printf(
		'<input type="checkbox" name="%s" value="%s" class="wto-toggle"%s%s />',
		esc_attr( $name ),
		esc_attr( $value ),
		checked( (bool) $checked, true, false ),
		$extra_attr ? ' ' . $extra_attr : ''
	);
}
