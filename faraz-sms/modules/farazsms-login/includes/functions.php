<?php

// Global helper function for getting settings
if (!function_exists('Farazsms_Get_Setting')) {
	function Farazsms_Get_Setting($section, $id) {
		$theme_options = get_option('farazsms_login_settings', [] );
		$section_data = isset($theme_options[$section]) ? $theme_options[$section] : [];
		$value = isset($section_data[$id]) ? $section_data[$id] : '';
		return $value;
	}
}

// آیا فیلد «نام» در فرم ثبت‌نام نمایش داده و اجباری شود؟
// منبع حقیقت: option ای که افزونه «فراز اس ام اس» (bridge) ست می‌کند.
//   wto_login_ask_name = 'yes' → نام نمایش داده و اجباری است (پیش‌فرض)
//   wto_login_ask_name = 'no'  → فیلد نام به‌کلی حذف می‌شود
// اگر افزونه فراز نصب نباشد، مقدار پیش‌فرض 'yes' رفتار قبلی را حفظ می‌کند.
if (!function_exists('Farazsms_Login_Ask_Name')) {
	function Farazsms_Login_Ask_Name() {
		return get_option('wto_login_ask_name', 'yes') !== 'no';
	}
}
