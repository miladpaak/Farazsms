<?php
/**
 * When wto_sender (panel line) changes, update meta.from on GF/Elementor feeds that matched the old line or were empty.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New option value.
 */
function wto_sync_faraz_feeds_sender_on_option_change( $old_value, $value ) {
	$old = is_string( $old_value ) ? trim( $old_value ) : '';
	$new = is_string( $value ) ? trim( $value ) : '';
	if ( $new === '' || $old === $new ) {
		return;
	}

	global $wpdb;

	foreach ( array( $wpdb->prefix . 'farazsms_gf_feeds', $wpdb->prefix . 'farazsms_elementor_feeds' ) as $table ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			continue;
		}

		$rows = $wpdb->get_results( "SELECT id, meta FROM `{$table}`", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$meta = ! empty( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}
			$from = isset( $meta['from'] ) ? trim( (string) $meta['from'] ) : '';
			if ( $from !== '' && $from !== $old ) {
				continue;
			}
			$meta['from'] = $new;
			$wpdb->update(
				$table,
				array( 'meta' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}
}

add_action( 'update_option_wto_sender', 'wto_sync_faraz_feeds_sender_on_option_change', 10, 2 );
