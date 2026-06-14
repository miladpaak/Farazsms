<?php
/**
 * جلوگیری از ارسال تکراری پیامک سفارش در یک تغییر وضعیت / یک درخواست.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return bool
 */
function wto_is_farazsmsnext_active_gateway() {
	if ( ! function_exists( 'PWSMS' ) ) {
		return false;
	}
	$active_gateway = PWSMS()->get_option( 'sms_gateway' );
	if ( function_exists( 'wto_is_farazsmsnext_gateway_class' ) ) {
		return wto_is_farazsmsnext_gateway_class( $active_gateway );
	}
	return in_array(
		$active_gateway,
		array(
			'PW\PWSMS\Gateways\FarazSMSNext',
			'PW\PWSMS\Gateways\IranPayamak',
			'farazsmsnext',
			'iranpayamak',
		),
		true
	);
}

/**
 * حذف همهٔ callbackهای send_order_sms از PW\PWSMS\Orders (همهٔ هوک‌ها).
 *
 * @return void
 */
function wto_pwsms_detach_order_sms_hooks() {
	static $detached = false;
	if ( $detached ) {
		return;
	}
	$detached = true;

	$targets = array(
		'woocommerce_order_status_changed'    => 99,
		'woocommerce_checkout_order_processed' => 99,
		'woocommerce_process_shop_order_meta' => 999,
	);

	global $wp_filter;

	foreach ( $targets as $hook_name => $priority ) {
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			continue;
		}
		$hook = $wp_filter[ $hook_name ];
		if ( ! $hook instanceof WP_Hook ) {
			continue;
		}
		if ( ! isset( $hook->callbacks[ $priority ] ) ) {
			continue;
		}
		foreach ( $hook->callbacks[ $priority ] as $callback ) {
			if ( empty( $callback['function'] ) || ! is_array( $callback['function'] ) ) {
				continue;
			}
			if ( empty( $callback['function'][1] ) || $callback['function'][1] !== 'send_order_sms' ) {
				continue;
			}
			if ( empty( $callback['function'][0] ) || ! is_object( $callback['function'][0] ) ) {
				continue;
			}
			$class = get_class( $callback['function'][0] );
			if ( $class === 'PW\PWSMS\Orders' || is_a( $callback['function'][0], 'PW\PWSMS\Orders' ) ) {
				remove_action( $hook_name, $callback['function'], $priority );
			}
		}
	}
}

/**
 * کلید یکتا برای dedupe: سفارش + نوع پیامک + وضعیت + گیرنده.
 *
 * @param int    $order_id
 * @param int    $sms_type 2=buyer, 4=super_admin, 5=product_admin
 * @param string $status   وضعیت اصلاح‌شده PWSMS
 * @param string $mobile   شماره یا لیست
 * @return string
 */
function wto_order_sms_dedupe_key( $order_id, $sms_type, $status, $mobile ) {
	$order_id = (int) $order_id;
	$sms_type = (int) $sms_type;
	$status   = sanitize_key( (string) $status );
	$mobile   = (string) $mobile;
	if ( strpos( $mobile, ',' ) !== false ) {
		$parts = array_map( 'trim', explode( ',', $mobile ) );
		$parts = array_filter( $parts );
		sort( $parts );
		$mobile = implode( ',', $parts );
	}
	return $order_id . '|' . $sms_type . '|' . $status . '|' . md5( $mobile );
}

/**
 * آیا برای این کلید هنوز می‌توان یک‌بار در این بازه ارسال کرد؟
 *
 * @param string $key
 * @return bool true = ارسال مجاز، false = تکراری
 */
function wto_order_sms_try_acquire_send( $key ) {
	static $request_sent = array();

	$key = (string) $key;
	if ( isset( $request_sent[ $key ] ) ) {
		return false;
	}

	$transient_key = 'wto_osd_' . md5( $key );
	if ( get_transient( $transient_key ) ) {
		return false;
	}

	$request_sent[ $key ] = true;
	set_transient( $transient_key, 1, 2 * MINUTE_IN_SECONDS );

	/**
	 * @param bool   $allow
	 * @param string $key
	 */
	return (bool) apply_filters( 'wto_order_sms_try_acquire_send', true, $key );
}

/**
 * @param int    $order_id
 * @param int    $sms_type
 * @param string $status
 * @param string $mobile
 * @return bool
 */
function wto_order_sms_should_send_once( $order_id, $sms_type, $status, $mobile ) {
	return wto_order_sms_try_acquire_send( wto_order_sms_dedupe_key( $order_id, $sms_type, $status, $mobile ) );
}
