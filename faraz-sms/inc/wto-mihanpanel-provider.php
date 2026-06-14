<?php
/**
 * MihanPanel SMS Provider — Phase 11 (v3.13.8)
 *
 * این فایل افزونه فراز اس‌ام‌اس را به‌عنوان یک سرویس‌دهنده پیامک به افزونه میهن پنل
 * اضافه می‌کند. پیامک‌های OTP میهن پنل (ورود/ثبت‌نام) از طریق پترن فراز ارسال
 * می‌شوند — همانطور که میهن پنل و فراز هر دو الزام می‌کنند.
 *
 * منبع: مستند رسمی میهن پنل، آیتم شماره ۱۵
 *
 *   https://mihanwp.com/docs/mihanpanel/
 *
 * این فایل فقط در صورت فعال بودن میهن پنل بارگذاری می‌شود (در core-init).
 *
 * معماری دو-فایلی:
 *
 *   1) همین فایل: فیلتر mihanpanel_sms_providers را با path → فایل کلاس ثبت می‌کند.
 *   2) wto-mihanpanel-provider-class.php: کلاس واقعی provider با implementation
 *      interface میهن پنل. این فایل توسط میهن پنل با require_once(path) لود می‌شود
 *      — به همین دلیل کلاس از فایل اصلی جدا است تا اگر میهن پنل غیرفعال شود،
 *      هیچ ارجاع به interface ای که وجود ندارد رخ ندهد.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * ثبت فراز اس‌ام‌اس به‌عنوان provider در میهن پنل.
 *
 * نکته: مقدار 'class' را به‌صورت رشته می‌فرستیم (نه FQCN::class) تا حتی اگر کلاس
 * هنوز بارگذاری نشده، autoload trigger نشود. میهن پنل خودش با require_once(path)
 * فایل را لود می‌کند، سپس new $class() را صدا می‌زند.
 */
add_filter( 'mihanpanel_sms_providers', 'wto_mihanpanel_register_provider' );
function wto_mihanpanel_register_provider( $providers ) {
	if ( ! is_array( $providers ) ) {
		$providers = array();
	}
	$providers['farazsms'] = array(
		'title' => 'فراز اس‌ام‌اس',
		'class' => 'WTO_FarazSMS_Mihanpanel_Provider',
		'path'  => WTO_CORE_INC . 'wto-mihanpanel-provider-class.php',
	);
	return $providers;
}
