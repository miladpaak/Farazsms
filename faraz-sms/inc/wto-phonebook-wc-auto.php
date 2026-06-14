<?php
/**
 * Auto-add WooCommerce checkout customers to FarazSMS WooCommerce phonebook when enabled.
 *
 * "New customer": logged-in user with at most one order (including this one) at checkout time;
 * guest: first time we sync this order (order meta flag).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merged phonebook settings with defaults.
 *
 * @return array
 */
function farazsms_next_get_phonebook_settings_merged() {
	$defaults = array(
		'auto_add_new_wc_customers' => '1',
	);
	$raw = get_option( 'farazsms_next_phonebook_settings', array() );
	if ( ! is_array( $raw ) ) {
		return $defaults;
	}

	return array_merge( $defaults, $raw );
}

/**
 * Find WooCommerce phonebook entry from API (same rules as FarazSMS_Next_Admin_Page::get_woocommerce_phonebook).
 *
 * @return array|false
 */
function farazsms_next_find_woocommerce_phonebook_row() {
	$api_key = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( $api_key === '' ) {
		return false;
	}
	// Cache the resolved phonebook row for 1 hour — this function runs on EVERY
	// checkout via woocommerce_checkout_order_processed, so an uncached external
	// API hit on every order would crush both the upstream and the checkout latency.
	$cache_key = 'wto_pb_wc_row_' . md5( $api_key );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$phonebook_api = new FarazSMS_Next_Phonebook_API();
	$response        = $phonebook_api->get_phonebooks( $api_key );
	if ( ! $response ) {
		return false;
	}
	$phonebooks = array();
	if ( isset( $response['data']['items'] ) && is_array( $response['data']['items'] ) ) {
		$phonebooks = $response['data']['items'];
	} elseif ( isset( $response['data']['data'] ) && is_array( $response['data']['data'] ) ) {
		$phonebooks = $response['data']['data'];
	} elseif ( isset( $response['data'] ) && is_array( $response['data'] ) && isset( $response['data'][0]['id'] ) ) {
		$phonebooks = $response['data'];
	} else {
		return false;
	}
	if ( empty( $phonebooks ) ) {
		return false;
	}
	$phonebook_name = class_exists( 'FarazSMS_Next_Admin_Page' ) ? FarazSMS_Next_Admin_Page::woocommerce_phonebook_title() : __( 'مشتریان ووکامرس', 'farazsms-next' );
	$legacy_titles  = array( $phonebook_name, 'کاربران سایت' );
	foreach ( $phonebooks as $phonebook ) {
		if ( isset( $phonebook['title'] ) && in_array( $phonebook['title'], $legacy_titles, true ) ) {
			set_transient( $cache_key, $phonebook, HOUR_IN_SECONDS );
			return $phonebook;
		}
	}

	return false;
}

/**
 * @param int $order_id Order ID.
 */
function farazsms_next_maybe_add_wc_customer_to_phonebook( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) || ! class_exists( 'FarazSMS_Next_Phonebook_API' ) ) {
		return;
	}

	$pb = farazsms_next_get_phonebook_settings_merged();
	if ( empty( $pb['auto_add_new_wc_customers'] ) || $pb['auto_add_new_wc_customers'] !== '1' ) {
		return;
	}

	$api_key = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( $api_key === '' ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	if ( $order->get_meta( '_farazsms_pb_wc_synced' ) ) {
		return;
	}

	$phone = $order->get_billing_phone();
	if ( ! is_string( $phone ) || trim( $phone ) === '' ) {
		return;
	}
	if ( function_exists( 'wto_normalize_phone' ) ) {
		$phone = wto_normalize_phone( $phone );
	}
	$phone = trim( (string) $phone );
	if ( $phone === '' ) {
		return;
	}

	$customer_id = (int) $order->get_customer_id();
	if ( $customer_id > 0 ) {
		$order_ids = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'limit'       => 2,
				'return'      => 'ids',
				'status'      => array_keys( wc_get_order_statuses() ),
			)
		);
		if ( is_array( $order_ids ) && count( $order_ids ) > 1 ) {
			return;
		}
	}

	$phonebook = farazsms_next_find_woocommerce_phonebook_row();
	if ( empty( $phonebook['id'] ) ) {
		return;
	}

	$phonebook_id = (int) $phonebook['id'];
	$name         = trim( $order->get_formatted_billing_full_name() );
	if ( $name === '' ) {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}
	if ( $name === '' ) {
		$name = __( 'مشتری', 'farazsms-next' );
	}

	// ثبت در دفترچه‌تلفن نباید مسیر خرید را کند یا مختل کند:
	// در صورت امکان async اجرا می‌شود؛ در هر حال داخل worker با try/catch به‌صورت
	// Fail-Open مدیریت می‌شود تا هیچ خطایی تکمیل سفارش را نشکند.
	$args = array(
		'order_id'     => (int) $order_id,
		'phonebook_id' => $phonebook_id,
		'name'         => $name,
		'phone'        => $phone,
		'api_key'      => $api_key,
	);
	if ( function_exists( 'wto_async_dispatch' ) && function_exists( 'wto_async_available' ) && wto_async_available() ) {
		wto_async_dispatch( 'farazsms_next_pb_add_contact_worker', $args );
	} else {
		farazsms_next_pb_add_contact_worker( $args );
	}
}

/**
 * Worker افزودن مخاطب به دفترچه‌تلفن — Fail-Open.
 * هر خطا/Exception/Error بلعیده می‌شود تا خرید کاربر هرگز مختل نشود.
 *
 * @param array $args order_id, phonebook_id, name, phone, api_key
 */
function farazsms_next_pb_add_contact_worker( $args ) {
	try {
		if ( ! class_exists( 'FarazSMS_Next_Phonebook_API' ) ) {
			return;
		}
		$api   = new FarazSMS_Next_Phonebook_API();
		$added = $api->add_contact(
			(int) $args['phonebook_id'],
			(string) $args['name'],
			(string) $args['phone'],
			(string) $args['api_key']
		);
		if ( is_array( $added ) && ! empty( $added['success'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $args['order_id'] );
			if ( $order ) {
				$order->update_meta_data( '_farazsms_pb_wc_synced', '1' );
				$order->save();
			}
		}
	} catch ( \Throwable $e ) {
		// Fail-Open: ثبت دفترچه‌تلفن نباید خرید را بشکند.
		error_log( 'farazsms phonebook wc-auto failed: ' . $e->getMessage() );
	}
}

add_action( 'woocommerce_checkout_order_processed', 'farazsms_next_maybe_add_wc_customer_to_phonebook', 30, 1 );
