<?php
/**
 * Lead magnet settings helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lead magnet options with defaults (enabled on when option missing or key omitted).
 *
 * @return array
 */
function farazsms_next_get_lead_magnet_settings() {
	// v3.13.15: پیش‌فرض‌های credit_amount و expiry_days اضافه شد. باگ قبلی:
	// در class-frontend.php اگر credit_amount یا expiry_days خالی بود، لید مگنت
	// رندر نمی‌شد. حالا با پیش‌فرض ۵۰,۰۰۰ تومان اعتبار + ۳ روز مهلت، فروشگاه‌های
	// تازه از همان لحظه فعال‌سازی، لید مگنت کارا دارند.
	// v3.17.4: متن‌ها هم قابل تنظیم — هر کاربری می‌تواند پیام دلخواه خود را بنویسد.
	$defaults = array(
		'enabled'             => '1',
		'display_position'    => 'bottom-right',
		// محلِ نمایش: everywhere (پیش‌فرض) | home | blog | specific
		'display_location'    => 'everywhere',
		'display_pages'       => '', // شناسه‌ی برگه‌ها (با کاما) وقتی specific انتخاب شد
		'credit_amount'       => 50000,
		'expiry_days'         => 3,
		// متن‌های قابل تنظیم — هر یک از این‌ها در template جایگزین می‌شوند
		'badge_text'          => '🔥 فقط امروز',
		'title_template'      => '{amount} تومان هدیه!',                 // {amount} placeholder
		'headline_template'   => 'با عضویت در {shop}، اعتبار رایگان بگیر و اولین خریدت رو ارزون‌تر کن.',
		'disclaimer_template' => '⏰ این هدیه فقط {days} روز اعتبار دارد',
		'cta_text'            => '🎁 دریافت اعتبار هدیه',
	);
	$raw = get_option( 'farazsms_next_lead_magnet_settings', null );
	if ( ! is_array( $raw ) ) {
		return $defaults;
	}

	$merged = array_merge( $defaults, $raw );

	// اگر کاربر فیلدها را خالی کرد (مقدار 0 یا empty string)، باز هم defaults
	// برگردانده می‌شود تا لید مگنت غیرفعال نشود.
	if ( empty( $merged['credit_amount'] ) ) {
		$merged['credit_amount'] = $defaults['credit_amount'];
	}
	if ( empty( $merged['expiry_days'] ) ) {
		$merged['expiry_days'] = $defaults['expiry_days'];
	}
	// اگر متن خالی شد، defaults
	foreach ( array( 'badge_text', 'title_template', 'headline_template', 'disclaimer_template', 'cta_text' ) as $k ) {
		if ( empty( trim( (string) $merged[ $k ] ) ) ) {
			$merged[ $k ] = $defaults[ $k ];
		}
	}

	return $merged;
}

/**
 * آیا لید مگنت باید در صفحه‌ی فعلی نمایش داده شود؟
 * محلِ نمایش بر اساسِ تنظیمِ display_location: همه‌جا / صفحه اصلی / بلاگ / برگه‌های خاص.
 *
 * @return bool
 */
function farazsms_next_lead_magnet_should_display() {
	if ( is_user_logged_in() ) {
		return false;
	}
	$settings = farazsms_next_get_lead_magnet_settings();
	if ( empty( $settings['enabled'] ) || $settings['enabled'] !== '1' ) {
		return false;
	}

	$loc = isset( $settings['display_location'] ) ? $settings['display_location'] : 'everywhere';

	switch ( $loc ) {
		case 'home':
			return (bool) is_front_page();

		case 'blog':
			// خانه‌ی نوشته‌ها، تکْ‌نوشته، و آرشیوها (دسته/برچسب/تاریخ/نویسنده).
			return (bool) ( is_home() || is_singular( 'post' ) || is_category() || is_tag() || is_archive() );

		case 'specific':
			$ids = array_filter( array_map( 'absint', explode( ',', (string) ( isset( $settings['display_pages'] ) ? $settings['display_pages'] : '' ) ) ) );
			return ! empty( $ids ) && is_page( $ids );

		case 'everywhere':
		default:
			return true;
	}
}
