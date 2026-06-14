<?php
/*
*
*	***** Faraz woo Scheduled sms *****
*
*	Ajax Request — admin-only endpoints. All five handlers below write to wp_options
*	and therefore MUST require manage_options capability. nopriv registration is forbidden.
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Guard: every handler in this file is admin-only.
 *
 * Calls wp_send_json_error and exits via wp_send_json_* (which calls wp_die),
 * so handlers can rely on it as an early return barrier.
 */
function fwss_require_admin_cap() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'دسترسی غیرمجاز', 403 );
	}
}

add_action( 'wp_ajax_fwss_save_credentials', 'fwss_ajax_save_credentials' );
// nopriv intentionally NOT registered — this endpoint sets the SMS API key.
function fwss_ajax_save_credentials() {
	fwss_require_admin_cap();
	// v3.17.6: apikey از POST اختیاری شد — اگر کاربر فیلد ندید (که نباید ببیند)،
	// از تنظیمات اصلی افزونه فراز خوانده می‌شود.
	$apikey    = isset( $_POST['apikey'] ) ? sanitize_text_field( wp_unslash( $_POST['apikey'] ) ) : '';
	$sender    = isset( $_POST['sender'] ) ? sanitize_text_field( wp_unslash( $_POST['sender'] ) ) : '';
	$send_time = isset( $_POST['send_time'] ) ? sanitize_text_field( wp_unslash( $_POST['send_time'] ) ) : '';

	if ( empty( $apikey ) && function_exists( 'wto_get_apikey' ) ) {
		$apikey = (string) wto_get_apikey();
	}
	if ( empty( $apikey ) ) {
		wp_send_json_error( 'کلید دسترسی در تنظیمات اصلی افزونه فراز اس‌ام‌اس وارد نشده است.' );
	}
	if ( $sender === '' ) {
		$sender = 'PRO';
	}
	update_option( 'fwss_apikey', $apikey );
	update_option( 'fwss_sender', $sender );
	update_option( 'fwss_send_time', $send_time, false );
	// v3.17.6: wto_apikey را فقط در صورتی sync کن که خالی است — برای جلوگیری از override تصادفی
	if ( get_option( 'wto_apikey', '' ) === '' ) {
		update_option( 'wto_apikey', $apikey );
	}
	wp_send_json_success();
}

/**
 * Helper: convert the flat name=>value POSTed array into the nested option structure.
 *
 * Each $data['name'] looks like "rule[123][field_key]"; we extract the numeric id
 * and the field key with regex. Returns a sanitized nested array.
 */
function fwss_build_sms_data_from_post( $sms_data ) {
	$out = array();
	if ( ! is_array( $sms_data ) ) {
		return $out;
	}
	foreach ( $sms_data as $data ) {
		if ( ! is_array( $data ) || ! isset( $data['name'], $data['value'] ) ) {
			continue;
		}
		$name  = (string) $data['name'];
		$value = is_scalar( $data['value'] ) ? sanitize_text_field( wp_unslash( (string) $data['value'] ) ) : '';

		preg_match_all( '!\d+!', $name, $match_id );
		preg_match_all( '/\[([^\]]*)\]/', $name, $match_name );

		if ( ! isset( $match_id[0][0], $match_name[1][1] ) ) {
			continue;
		}
		$row_id   = (int) $match_id[0][0];
		$field_key = sanitize_key( $match_name[1][1] );
		if ( $field_key === '' ) {
			continue;
		}
		$out[ $row_id ][ $field_key ] = $value;
	}
	return $out;
}

add_action( 'wp_ajax_fwss_save_wc_sms_data', 'fwss_save_wc_sms_data' );
// nopriv intentionally NOT registered.
function fwss_save_wc_sms_data() {
	fwss_require_admin_cap();
	$sms_data = isset( $_POST['sms_data'] ) ? wp_unslash( $_POST['sms_data'] ) : '';
	update_option( 'fwss_wc_sms_data', fwss_build_sms_data_from_post( $sms_data ) );
	wp_send_json_success();
}

add_action( 'wp_ajax_fwss_save_users_sms_data', 'fwss_save_users_sms_data' );
// nopriv intentionally NOT registered.
function fwss_save_users_sms_data() {
	fwss_require_admin_cap();
	$sms_data = isset( $_POST['sms_data'] ) ? wp_unslash( $_POST['sms_data'] ) : '';
	update_option( 'fwss_users_sms_data', fwss_build_sms_data_from_post( $sms_data ) );
	wp_send_json_success();
}

add_action( 'wp_ajax_fwss_save_users_settings', 'fwss_save_users_settings' );
// nopriv intentionally NOT registered.
function fwss_save_users_settings() {
	fwss_require_admin_cap();
	$active_digits = isset( $_POST['fwss_active_digits'] ) ? sanitize_text_field( wp_unslash( $_POST['fwss_active_digits'] ) ) : '';
	$meta_keys_raw = isset( $_POST['fwss_custom_phone_meta_keys'] ) ? wp_unslash( $_POST['fwss_custom_phone_meta_keys'] ) : '';
	// meta keys are passed as either array or comma-separated string — normalize to array of safe key strings.
	if ( is_array( $meta_keys_raw ) ) {
		$meta_keys = array_values( array_filter( array_map( 'sanitize_key', $meta_keys_raw ) ) );
	} else {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $meta_keys_raw ) ) );
		$meta_keys = array_values( array_filter( array_map( 'sanitize_key', $parts ) ) );
	}
	update_option( 'fwss_active_digits', $active_digits, false );
	update_option( 'fwss_custom_phone_meta_keys', $meta_keys, false );
	wp_send_json_success();
}

add_action( 'wp_ajax_fwss_save_gf_sms_data', 'fwss_save_gf_sms_data' );
// nopriv intentionally NOT registered.
function fwss_save_gf_sms_data() {
	fwss_require_admin_cap();
	$sms_data = isset( $_POST['sms_data'] ) ? wp_unslash( $_POST['sms_data'] ) : '';
	$so_tmp = array();
	if ( is_array( $sms_data ) ) {
		foreach ( $sms_data as $data ) {
			if ( ! is_array( $data ) || ! isset( $data['name'], $data['value'] ) ) {
				continue;
			}
			$value = is_scalar( $data['value'] ) ? sanitize_text_field( wp_unslash( (string) $data['value'] ) ) : '';
			if ( $value === '' ) {
				continue;
			}
			preg_match_all( '!\d+!', $data['name'], $match_id );
			preg_match_all( '/\[([^\]]*)\]/', $data['name'], $match_name );
			if ( ! isset( $match_id[0][0] ) ) {
				continue;
			}
			$row_id = (int) $match_id[0][0];
			if ( isset( $match_id[0][1], $match_name[1][2] ) ) {
				$cond_id   = (int) $match_id[0][1];
				$field_key = sanitize_key( $match_name[1][2] );
				if ( $field_key !== '' ) {
					$so_tmp[ $row_id ]['condition'][ $cond_id ][ $field_key ] = $value;
				}
			} elseif ( isset( $match_name[1][1] ) ) {
				$field_key = sanitize_key( $match_name[1][1] );
				if ( $field_key !== '' ) {
					$so_tmp[ $row_id ][ $field_key ] = $value;
				}
			}
		}
	}
	update_option( 'fwss_gf_sms_data', $so_tmp, false );
	wp_send_json_success();
}
