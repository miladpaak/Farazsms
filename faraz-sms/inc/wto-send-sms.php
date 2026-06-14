<?php
/**
 * ارسال پیامک از داخل سایت — Phase 3
 *
 * این ماژول یک صفحه ادمین ساده می‌سازد که مدیر سایت بتواند بدون رفتن به
 * پنل فراز اس‌ام‌اس، یک پیامک گروهی (متن آزاد یا الگو) به گیرندگان زیر
 * بفرستد:
 *
 *   - شماره‌های وارد شده دستی (paste)
 *   - مشترکین فعال خبرنامه (wto_newsletter_subscribers)
 *   - مشترکین «موجود شد خبرم کن» (wto_notify_subscribers)
 *   - یک باشگاه مشتریان از پنل فراز اس‌ام‌اس (با API)
 *
 * هر ارسال در جدول لاگ ذخیره می‌شود و در تب «تاریخچه» قابل مشاهده است.
 *
 * Submenu: farazwto-send-sms (priority 11 — درست بعد از تنظیمات)
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// ============================================================================
// Schema (جدول لاگ ارسال‌ها)
// ============================================================================

const WTO_SEND_LOG_DB_VERSION        = '1.0.0';
const WTO_SEND_LOG_DB_VERSION_OPTION = 'wto_send_log_db_version';

function wto_send_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'wto_sms_send_log';
}

function wto_send_sms_maybe_setup_table() {
	if ( get_option( WTO_SEND_LOG_DB_VERSION_OPTION ) === WTO_SEND_LOG_DB_VERSION ) {
		return;
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	global $wpdb;
	$table           = wto_send_log_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		source VARCHAR(50) NOT NULL DEFAULT '',
		mode VARCHAR(20) NOT NULL DEFAULT '',
		pattern_code VARCHAR(80) NOT NULL DEFAULT '',
		message_text TEXT NOT NULL,
		recipients_count INT UNSIGNED NOT NULL DEFAULT 0,
		sent_count INT UNSIGNED NOT NULL DEFAULT 0,
		failed_count INT UNSIGNED NOT NULL DEFAULT 0,
		user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at)
	) $charset_collate;";
	dbDelta( $sql );
	update_option( WTO_SEND_LOG_DB_VERSION_OPTION, WTO_SEND_LOG_DB_VERSION, false );
}
add_action( 'admin_init', 'wto_send_sms_maybe_setup_table' );

// ============================================================================
// Submenu (priority 11 — درست بعد از تنظیمات priority 10)
// ============================================================================

add_action( 'admin_menu', 'wto_send_sms_register_submenu', 11 );
function wto_send_sms_register_submenu() {
	add_submenu_page(
		'farazwto',
		__( 'ارسال پیامک', 'wto' ),
		__( 'ارسال پیامک', 'wto' ),
		'manage_options',
		'farazwto-send-sms',
		'wto_render_send_sms_page'
	);
}

// ============================================================================
// AJAX — Fetch accessible sender lines from FarazSMS panel
// ============================================================================

add_action( 'wp_ajax_wto_send_fetch_lines', 'wto_send_sms_ajax_fetch_lines' );
function wto_send_sms_ajax_fetch_lines() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_send_sms_admin', 'nonce' );

	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => __( 'کلید دسترسی (Api-Key) در تنظیمات افزونه وارد نشده است.', 'wto' ) ) );
	}

	// Short transient cache — lines rarely change but admin may refresh.
	$cache_key = 'wto_send_lines_' . md5( $apikey );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false && is_array( $cached ) ) {
		wp_send_json_success( array( 'lines' => $cached ) );
	}

	$response = function_exists( 'wto_remote_get_with_fallback' )
		? wto_remote_get_with_fallback( 'https://api.iranpayamak.com/ws/v1/lines/accessible', array(
			'headers' => array(
				'Accept'  => 'application/json',
				'Api-Key' => $apikey,
			),
			'timeout' => 15,
		) )
		: null;

	$lines = array();
	if ( ! is_wp_error( $response ) && is_array( $response ) ) {
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $decoded ) && ( ! isset( $decoded['status'] ) || $decoded['status'] === 'success' ) ) {
			$data  = isset( $decoded['data'] ) ? $decoded['data'] : array();
			$items = array();
			if ( is_array( $data ) ) {
				if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
					$items = $data['items'];
				} elseif ( $data && array_values( $data ) === $data ) {
					$items = $data;
				}
			}
			foreach ( $items as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$number = '';
				foreach ( array( 'line_number', 'lineNumber', 'number', 'line', 'value' ) as $k ) {
					if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) ) {
						$number = (string) $row[ $k ];
						break;
					}
				}
				if ( $number === '' ) {
					continue;
				}
				$title = '';
				foreach ( array( 'title', 'name', 'label', 'description' ) as $k ) {
					if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) ) {
						$title = (string) $row[ $k ];
						break;
					}
				}
				$is_dedicated = false;
				foreach ( array( 'is_dedicated', 'isDedicated', 'dedicated' ) as $k ) {
					if ( isset( $row[ $k ] ) ) {
						$is_dedicated = (bool) $row[ $k ];
						break;
					}
				}
				$lines[] = array(
					'number'       => $number,
					'title'        => $title !== '' ? $title : $number,
					'is_dedicated' => $is_dedicated,
				);
			}
		}
	}

	// Always include the "PRO" shared line at the top (default selection).
	$lines = array_merge( array(
		array(
			'number'       => 'PRO',
			'title'        => __( 'خط PRO (پیش‌فرض)', 'wto' ),
			'is_dedicated' => false,
		),
	), $lines );

	// Cache for 1 hour.
	set_transient( $cache_key, $lines, HOUR_IN_SECONDS );

	wp_send_json_success( array( 'lines' => $lines ) );
}

// ============================================================================
// AJAX — Fetch phonebooks from FarazSMS panel
// ============================================================================

add_action( 'wp_ajax_wto_send_fetch_phonebooks', 'wto_send_sms_ajax_fetch_phonebooks' );
function wto_send_sms_ajax_fetch_phonebooks() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_send_sms_admin', 'nonce' );

	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => __( 'کلید دسترسی (Api-Key) در تنظیمات افزونه وارد نشده است.', 'wto' ) ) );
	}

	// Cache result for 5 minutes to avoid hitting the API + counting endpoints on every page-load.
	$cache_key = 'wto_send_pbs_' . md5( $apikey );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false && is_array( $cached ) ) {
		wp_send_json_success( array( 'phonebooks' => $cached ) );
	}

	// FarazSMS uses `/ws/v1/phone_book` (underscore), NOT `/phonebook`.
	$response = function_exists( 'wto_remote_get_with_fallback' )
		? wto_remote_get_with_fallback( 'https://api.iranpayamak.com/ws/v1/phone_book?limit=100', array(
			'headers' => array(
				'Accept'  => 'application/json',
				'Api-Key' => $apikey,
			),
			'timeout' => 15,
		) )
		: null;

	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		wp_send_json_error( array( 'message' => __( 'خطا در ارتباط با سرور فراز.', 'wto' ) ) );
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) ) {
		wp_send_json_error( array( 'message' => __( 'پاسخ سرور قابل پردازش نیست.', 'wto' ) ) );
	}
	if ( isset( $decoded['status'] ) && $decoded['status'] === 'error' ) {
		$msg = '';
		if ( isset( $decoded['messages'] ) ) {
			$msg = is_string( $decoded['messages'] ) ? $decoded['messages'] : ( is_array( $decoded['messages'] ) ? implode( '، ', array_filter( $decoded['messages'], 'is_string' ) ) : '' );
		}
		wp_send_json_error( array( 'message' => $msg !== '' ? $msg : __( 'خطای API.', 'wto' ) ) );
	}

	// Extract the items array — FarazSMS uses *multiple* shapes for the same endpoint:
	//   {data: {items: [...]}}      (docs)
	//   {data: {data: [...]}}       (actual)
	//   {data: [...]}               (occasional)
	//   {data: {phone_book: {data: [...]}}}  (some pagination wrappers)
	$data  = isset( $decoded['data'] ) ? $decoded['data'] : array();
	$items = array();
	if ( is_array( $data ) ) {
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$items = $data['items'];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$items = $data['data'];
		} elseif ( isset( $data['phone_book']['data'] ) && is_array( $data['phone_book']['data'] ) ) {
			$items = $data['phone_book']['data'];
		} elseif ( $data && array_values( $data ) === $data ) {
			$items = $data;
		}
	}

	// Normalize each phonebook row to {id, title, count}.
	$normalized = array();
	foreach ( $items as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$id = '';
		foreach ( array( 'id', 'phone_book_id', 'phonebook_id' ) as $k ) {
			if ( isset( $row[ $k ] ) ) { $id = (string) $row[ $k ]; break; }
		}
		$title = '';
		foreach ( array( 'title', 'name', 'label' ) as $k ) {
			if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) ) { $title = (string) $row[ $k ]; break; }
		}
		$count = null;
		// Direct count field — many possible names.
		foreach ( array( 'phone_book_data_count', 'phoneBookDataCount', 'data_count', 'contacts_count', 'contactsCount', 'members_count', 'count', 'total' ) as $k ) {
			if ( isset( $row[ $k ] ) && is_numeric( $row[ $k ] ) ) {
				$count = (int) $row[ $k ];
				break;
			}
		}
		if ( $id !== '' && $title !== '' ) {
			$normalized[] = array(
				'id'    => $id,
				'title' => $title,
				'count' => $count,
			);
		}
	}

	// If the list endpoint didn't include counts, fetch them in a second pass.
	// We cap to the first 50 phonebooks to keep things fast.
	$need_count = false;
	foreach ( $normalized as $pb ) {
		if ( $pb['count'] === null ) { $need_count = true; break; }
	}
	if ( $need_count ) {
		$max = min( 50, count( $normalized ) );
		for ( $i = 0; $i < $max; $i++ ) {
			if ( $normalized[ $i ]['count'] !== null ) {
				continue;
			}
			$normalized[ $i ]['count'] = wto_send_sms_fetch_phonebook_count( $normalized[ $i ]['id'], $apikey );
		}
	}

	set_transient( $cache_key, $normalized, 5 * MINUTE_IN_SECONDS );
	wp_send_json_success( array( 'phonebooks' => $normalized ) );
}

/**
 * Fetch the total contact count for a given phonebook by reading pagination
 * meta from `/phone_book_data?page=1&limit=1`. Returns null on failure.
 *
 * @param string $phonebook_id
 * @param string $apikey
 * @return int|null
 */
function wto_send_sms_fetch_phonebook_count( $phonebook_id, $apikey ) {
	$url = add_query_arg(
		array(
			'phone_book_id' => (int) $phonebook_id,
			'page'          => 1,
			'limit'         => 1,
		),
		'https://api.iranpayamak.com/ws/v1/phone_book_data'
	);
	$response = function_exists( 'wto_remote_get_with_fallback' )
		? wto_remote_get_with_fallback( $url, array(
			'headers' => array(
				'Accept'  => 'application/json',
				'Api-Key' => $apikey,
			),
			'timeout' => 10,
		) )
		: null;
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return null;
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) ) {
		return null;
	}
	$data = isset( $decoded['data'] ) ? $decoded['data'] : array();
	if ( ! is_array( $data ) ) {
		return null;
	}
	// Try the common pagination meta keys at various depths.
	$candidates = array();
	if ( isset( $data['phone_book_data'] ) && is_array( $data['phone_book_data'] ) ) {
		$candidates[] = $data['phone_book_data'];
	}
	$candidates[] = $data;
	foreach ( $candidates as $node ) {
		foreach ( array( 'total', 'totalItems', 'total_items', 'count', 'last_page' ) as $k ) {
			if ( isset( $node[ $k ] ) && is_numeric( $node[ $k ] ) ) {
				// `last_page` × limit isn't accurate (limit was 1) — actually it equals total when limit=1.
				return (int) $node[ $k ];
			}
		}
	}
	return null;
}

// ============================================================================
// AJAX — Recipient counts (for the "روش گیرنده" selector)
// ============================================================================

add_action( 'wp_ajax_wto_send_recipient_counts', 'wto_send_sms_ajax_recipient_counts' );
function wto_send_sms_ajax_recipient_counts() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_send_sms_admin', 'nonce' );

	global $wpdb;
	$newsletter = 0;
	$notify     = 0;
	if ( function_exists( 'wto_newsletter_table' ) ) {
		$t = wto_newsletter_table();
		$newsletter = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", 'active' ) );
	}
	if ( function_exists( 'wto_notify_table' ) ) {
		$t = wto_notify_table();
		$notify = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", 'pending' ) );
	}

	wp_send_json_success( array(
		'newsletter' => $newsletter,
		'notify'     => $notify,
	) );
}

// ============================================================================
// AJAX — Send SMS
// ============================================================================

add_action( 'wp_ajax_wto_send_sms_dispatch', 'wto_send_sms_ajax_dispatch' );
function wto_send_sms_ajax_dispatch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_send_sms_admin', 'nonce' );

	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( $apikey === '' ) {
		wp_send_json_error( array( 'message' => __( 'کلید دسترسی (Api-Key) در تنظیمات افزونه وارد نشده است.', 'wto' ) ) );
	}

	$source  = isset( $_POST['source'] )      ? sanitize_key( $_POST['source'] )                         : '';
	$message = isset( $_POST['message'] )     ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$line    = isset( $_POST['line_number'] ) ? sanitize_text_field( wp_unslash( $_POST['line_number'] ) ) : 'PRO';

	// Bulk send is always free-text/simple — pattern SMS is per-recipient and not
	// supported for mass dispatch by the FarazSMS API.
	if ( $message === '' ) {
		wp_send_json_error( array( 'message' => __( 'متن پیامک خالی است.', 'wto' ) ) );
	}
	if ( $line === '' ) {
		$line = 'PRO';
	}

	// Collect recipients per source.
	$recipients = wto_send_sms_collect_recipients( $source, $apikey );
	if ( is_wp_error( $recipients ) ) {
		wp_send_json_error( array( 'message' => $recipients->get_error_message() ) );
	}
	if ( empty( $recipients ) ) {
		wp_send_json_error( array( 'message' => __( 'هیچ گیرنده‌ای یافت نشد.', 'wto' ) ) );
	}
	// Dedupe + cap to 10k per single dispatch to avoid runaway requests.
	$recipients = array_values( array_unique( $recipients ) );
	if ( count( $recipients ) > 10000 ) {
		$recipients = array_slice( $recipients, 0, 10000 );
	}

	$sent   = 0;
	$failed = 0;

	// Bulk simple SMS: chunked POSTs to /sms/simple of 100 recipients each.
	$chunks = array_chunk( $recipients, 100 );
	foreach ( $chunks as $chunk ) {
		$body = array(
			'line_number'   => $line,
			'recipients'    => $chunk,
			'text'          => $message,
			'number_format' => 'english',
		);
		$response = function_exists( 'wto_remote_post_with_fallback' )
			? wto_remote_post_with_fallback( 'https://api.iranpayamak.com/ws/v1/sms/simple', array(
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'Api-Key'      => $apikey,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			) )
			: null;
		if ( ! is_wp_error( $response ) && is_array( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && isset( $decoded['status'] ) && $decoded['status'] === 'success' ) {
				$sent += count( $chunk );
				continue;
			}
		}
		$failed += count( $chunk );
	}

	// Log the dispatch.
	global $wpdb;
	$wpdb->insert(
		wto_send_log_table(),
		array(
			'source'           => $source,
			'mode'             => 'simple',
			'pattern_code'     => $line,
			'message_text'     => $message,
			'recipients_count' => count( $recipients ),
			'sent_count'       => $sent,
			'failed_count'     => $failed,
			'user_id'          => get_current_user_id(),
			'created_at'       => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
	);

	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: 1 sent count, 2 failed count, 3 total */
			__( 'ارسال انجام شد — موفق: %1$s، ناموفق: %2$s، کل گیرندگان: %3$s', 'wto' ),
			number_format_i18n( $sent ),
			number_format_i18n( $failed ),
			number_format_i18n( count( $recipients ) )
		),
		'sent'         => $sent,
		'failed'       => $failed,
		'total'        => count( $recipients ),
	) );
}

/**
 * Collect mobile recipients for the given source. Returns array of
 * normalized 11-digit `09xxxxxxxxx` strings or WP_Error.
 *
 * @param string $source
 * @param string $apikey
 * @return array|WP_Error
 */
function wto_send_sms_collect_recipients( $source, $apikey ) {
	switch ( $source ) {
		case 'manual':
			$raw = isset( $_POST['manual_mobiles'] ) ? wp_unslash( $_POST['manual_mobiles'] ) : '';
			return wto_send_sms_parse_manual_mobiles( $raw );

		case 'newsletter':
			if ( ! function_exists( 'wto_newsletter_table' ) ) {
				return new WP_Error( 'unavailable', __( 'ماژول خبرنامه فعال نیست.', 'wto' ) );
			}
			global $wpdb;
			$t    = wto_newsletter_table();
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT mobile FROM $t WHERE status = %s", 'active' ) );
			return is_array( $rows ) ? array_values( array_filter( $rows ) ) : array();

		case 'notify':
			if ( ! function_exists( 'wto_notify_table' ) ) {
				return new WP_Error( 'unavailable', __( 'ماژول موجود شد خبرم کن فعال نیست.', 'wto' ) );
			}
			global $wpdb;
			$t    = wto_notify_table();
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT mobile FROM $t WHERE status = %s", 'pending' ) );
			return is_array( $rows ) ? array_values( array_filter( $rows ) ) : array();

		case 'phonebook':
			$pb_id = isset( $_POST['phonebook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['phonebook_id'] ) ) : '';
			if ( $pb_id === '' ) {
				return new WP_Error( 'no_phonebook', __( 'باشگاه مشتریان انتخاب نشده.', 'wto' ) );
			}
			return wto_send_sms_fetch_phonebook_recipients( $pb_id, $apikey );

		default:
			return new WP_Error( 'invalid_source', __( 'منبع گیرنده نامعتبر است.', 'wto' ) );
	}
}

/**
 * Parse manually-entered mobile list (paste). Accepts newline, comma, semicolon,
 * or space separated. Normalizes each, drops invalid ones.
 *
 * @param string $raw
 * @return array
 */
function wto_send_sms_parse_manual_mobiles( $raw ) {
	$raw = (string) $raw;
	if ( function_exists( 'wto_tr_num' ) ) {
		$raw = wto_tr_num( $raw );
	}
	$tokens = preg_split( '/[\s,;]+/', $raw );
	$out    = array();
	$normalizer = function_exists( 'wto_newsletter_normalize_mobile' )
		? 'wto_newsletter_normalize_mobile'
		: null;
	foreach ( (array) $tokens as $t ) {
		$t = trim( (string) $t );
		if ( $t === '' ) continue;
		$norm = $normalizer ? call_user_func( $normalizer, $t ) : $t;
		if ( $norm !== '' ) {
			$out[] = $norm;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Fetch all contact mobiles from a phonebook in the FarazSMS panel.
 * Paginates through the API up to a soft cap of 10,000 mobiles.
 *
 * @param string $phonebook_id
 * @param string $apikey
 * @return array|WP_Error
 */
function wto_send_sms_fetch_phonebook_recipients( $phonebook_id, $apikey ) {
	$mobiles   = array();
	$page      = 1;
	$per_page  = 200;
	$hard_cap  = 10000;
	$last_page = 0;

	while ( true ) {
		// FarazSMS uses `/ws/v1/phone_book_data?phone_book_id={id}` (NOT a `/phonebook/{id}/contacts` path).
		$url = add_query_arg(
			array(
				'phone_book_id' => (int) $phonebook_id,
				'page'          => $page,
				'limit'         => $per_page,
			),
			'https://api.iranpayamak.com/ws/v1/phone_book_data'
		);
		$response = function_exists( 'wto_remote_get_with_fallback' )
			? wto_remote_get_with_fallback( $url, array(
				'headers' => array(
					'Accept'  => 'application/json',
					'Api-Key' => $apikey,
				),
				'timeout' => 15,
			) )
			: null;
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return new WP_Error( 'http', __( 'خطا در ارتباط با سرور فراز.', 'wto' ) );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'parse', __( 'پاسخ سرور قابل پردازش نیست.', 'wto' ) );
		}
		if ( isset( $decoded['status'] ) && $decoded['status'] === 'error' ) {
			$msg = isset( $decoded['messages'] ) && is_string( $decoded['messages'] ) ? $decoded['messages'] : __( 'خطای API.', 'wto' );
			return new WP_Error( 'api', $msg );
		}

		$data  = isset( $decoded['data'] ) ? $decoded['data'] : array();
		// `phone_book_data` endpoint nests items inside `data.phone_book_data.data[]`,
		// and pagination meta at the same level (`last_page`, `total`).
		$items = array();
		if ( is_array( $data ) ) {
			if ( isset( $data['phone_book_data']['data'] ) && is_array( $data['phone_book_data']['data'] ) ) {
				$items = $data['phone_book_data']['data'];
				if ( isset( $data['phone_book_data']['last_page'] ) ) {
					$last_page = (int) $data['phone_book_data']['last_page'];
				}
			} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				$items = $data['data'];
				if ( isset( $data['last_page'] ) ) {
					$last_page = (int) $data['last_page'];
				}
			} elseif ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
				$items = $data['items'];
			} elseif ( $data && array_values( $data ) === $data ) {
				$items = $data;
			}
		}
		if ( empty( $items ) ) {
			break;
		}
		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) continue;
			$mobile = '';
			foreach ( array( 'mobile', 'mobile_number', 'mobileNumber', 'phone', 'phone_number', 'msisdn', 'number' ) as $k ) {
				if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) ) {
					$mobile = (string) $row[ $k ];
					break;
				}
			}
			if ( $mobile === '' ) continue;
			$norm = function_exists( 'wto_newsletter_normalize_mobile' )
				? wto_newsletter_normalize_mobile( $mobile )
				: $mobile;
			if ( $norm !== '' ) {
				$mobiles[] = $norm;
			}
			if ( count( $mobiles ) >= $hard_cap ) break 2;
		}
		// Pagination — prefer the API-reported last_page when available.
		if ( $last_page > 0 ) {
			if ( $page >= $last_page ) {
				break;
			}
		} elseif ( count( $items ) < $per_page ) {
			break;
		}
		$page++;
		if ( $page > 60 ) {
			break; // safety net
		}
	}
	return array_values( array_unique( $mobiles ) );
}

// ============================================================================
// Admin page render
// ============================================================================

function wto_render_send_sms_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی غیرمجاز.', 'wto' ) );
	}
	wto_send_sms_maybe_setup_table();

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'compose';
	$tab = in_array( $tab, array( 'compose', 'history' ), true ) ? $tab : 'compose';

	echo '<section class="wrapper wto-send-sms-wrapper">';
	wto_send_sms_render_header();
	wto_send_sms_render_tabs( $tab );
	if ( $tab === 'history' ) {
		wto_send_sms_render_history_tab();
	} else {
		wto_send_sms_render_compose_tab();
	}
	wto_send_sms_render_inline();
	echo '</section>';
}

function wto_send_sms_render_header() {
	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : '';
	?>
	<div id="wto_header">
		<div>
			<a href="https://farazsms.com" target="_blank" rel="noopener">
				<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt="<?php esc_attr_e( 'فراز اس‌ام‌اس', 'wto' ); ?>">
			</a>
		</div>
		<?php if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) : ?>
			<div id="wto_account_info">
				<div class="wto_credit_amount">
					<span><?php esc_html_e( 'میزان اعتبار: ', 'wto' ); ?></span>
					<?php echo esc_html( (string) wto_get_credit() ); ?>
					<span> <?php esc_html_e( 'تومان', 'wto' ); ?></span>
				</div>
				<?php if ( function_exists( 'wto_render_profile_block' ) ) { wto_render_profile_block(); } ?>
			</div>
		<?php endif; ?>
	</div>
	<h1 class="wto-send-sms-title-main"><?php esc_html_e( 'ارسال پیامک', 'wto' ); ?></h1>
	<?php if ( empty( $apikey ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s settings page link */
					esc_html__( 'برای ارسال پیامک باید ابتدا کلید دسترسی (Api-Key) را در %s وارد کنید.', 'wto' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=farazwto-settings' ) ) . '">' . esc_html__( 'تنظیمات افزونه', 'wto' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php
}

function wto_send_sms_render_tabs( $active ) {
	$tabs = array(
		'compose' => __( 'ارسال جدید', 'wto' ),
		'history' => __( 'تاریخچه ارسال‌ها', 'wto' ),
	);
	?>
	<nav class="wto-send-sms-tabs">
		<?php foreach ( $tabs as $key => $label ) :
			$url = add_query_arg( array( 'page' => 'farazwto-send-sms', 'tab' => $key ), admin_url( 'admin.php' ) );
		?>
			<a class="wto-send-sms-tab <?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
}

function wto_send_sms_render_compose_tab() {
	?>
	<form id="wto-send-sms-form" class="wto-send-sms-card">
		<?php wp_nonce_field( 'wto_send_sms_admin', 'wto_send_sms_admin_nonce' ); ?>

		<!-- Step 1: Recipients -->
		<section class="wto-send-sms-step">
			<h2 class="wto-send-sms-step-title"><span class="step-num">۱</span> <?php esc_html_e( 'انتخاب گیرندگان', 'wto' ); ?></h2>

			<div class="wto-send-sms-source-grid">
				<label class="wto-send-sms-source-card">
					<input type="radio" name="source" value="manual" checked>
					<div class="wto-source-body">
						<div class="wto-source-title"><?php esc_html_e( 'وارد کردن دستی', 'wto' ); ?></div>
						<div class="wto-source-help"><?php esc_html_e( 'کپی شماره‌ها — جدا با ویرگول، فاصله یا خط جدید', 'wto' ); ?></div>
					</div>
				</label>
				<label class="wto-send-sms-source-card">
					<input type="radio" name="source" value="newsletter">
					<div class="wto-source-body">
						<div class="wto-source-title"><?php esc_html_e( 'مشترکین خبرنامه', 'wto' ); ?></div>
						<div class="wto-source-help wto-count-newsletter"><?php esc_html_e( 'در حال محاسبه...', 'wto' ); ?></div>
					</div>
				</label>
				<label class="wto-send-sms-source-card">
					<input type="radio" name="source" value="notify">
					<div class="wto-source-body">
						<div class="wto-source-title"><?php esc_html_e( 'موجود شد خبرم کن', 'wto' ); ?></div>
						<div class="wto-source-help wto-count-notify"><?php esc_html_e( 'در حال محاسبه...', 'wto' ); ?></div>
					</div>
				</label>
				<label class="wto-send-sms-source-card">
					<input type="radio" name="source" value="phonebook">
					<div class="wto-source-body">
						<div class="wto-source-title"><?php esc_html_e( 'دفترچه تلفن پنل پیامکی', 'wto' ); ?></div>
						<div class="wto-source-help"><?php esc_html_e( 'انتخاب از دفترچه‌های ذخیره‌شده در پنل فراز', 'wto' ); ?></div>
					</div>
				</label>
			</div>

			<div class="wto-source-panel wto-source-manual">
				<label class="wto-send-sms-field">
					<span><?php esc_html_e( 'شماره موبایل‌ها:', 'wto' ); ?></span>
					<textarea name="manual_mobiles" rows="6" placeholder="<?php esc_attr_e( '09121234567&#10;09127654321&#10;...', 'wto' ); ?>"></textarea>
				</label>
				<p class="wto-send-sms-help-small wto-manual-counter"></p>
			</div>

			<div class="wto-source-panel wto-source-phonebook" hidden>
				<label class="wto-send-sms-field">
					<span><?php esc_html_e( 'انتخاب دفترچه تلفن:', 'wto' ); ?></span>
					<select name="phonebook_id">
						<option value=""><?php esc_html_e( 'در حال بارگذاری...', 'wto' ); ?></option>
					</select>
				</label>
				<p class="wto-send-sms-help-small wto-phonebook-info">
					<?php esc_html_e( 'لیست دفترچه‌ها به همراه تعداد اعضای هر کدام از پنل فراز خوانده می‌شود. پیامک به همه اعضای دفترچه‌ی انتخاب‌شده ارسال خواهد شد.', 'wto' ); ?>
				</p>
			</div>
		</section>

		<!-- Step 2: Sender line -->
		<section class="wto-send-sms-step">
			<h2 class="wto-send-sms-step-title"><span class="step-num">۲</span> <?php esc_html_e( 'خط ارسال‌کننده', 'wto' ); ?></h2>

			<label class="wto-send-sms-field wto-send-sms-line-field">
				<span><?php esc_html_e( 'خط:', 'wto' ); ?></span>
				<select name="line_number" dir="ltr">
					<option value="PRO"><?php esc_html_e( 'خط PRO (پیش‌فرض)', 'wto' ); ?></option>
				</select>
			</label>
			<p class="wto-send-sms-help-small"><?php esc_html_e( 'لیست خطوط در دسترس شما از پنل فراز اس‌ام‌اس خوانده می‌شود. اگر خط اختصاصی ندارید، خط PRO پیش‌فرض است.', 'wto' ); ?></p>
		</section>

		<!-- Step 3: Message -->
		<section class="wto-send-sms-step">
			<h2 class="wto-send-sms-step-title"><span class="step-num">۳</span> <?php esc_html_e( 'متن پیامک', 'wto' ); ?></h2>

			<label class="wto-send-sms-field">
				<span><?php esc_html_e( 'متن پیامک:', 'wto' ); ?></span>
				<textarea name="message" rows="5" maxlength="500" placeholder="<?php esc_attr_e( 'متن پیامک را اینجا بنویسید...', 'wto' ); ?>"></textarea>
			</label>
			<p class="wto-send-sms-help-small">
				<span class="wto-text-counter">۰</span> / ۵۰۰ <?php esc_html_e( 'کاراکتر', 'wto' ); ?>
				— <?php esc_html_e( 'ارسال انبوه فقط با متن آزاد امکان‌پذیر است (متن نیاز به تأیید ناظر در پنل فراز دارد).', 'wto' ); ?>
			</p>
		</section>

		<!-- Step 4: Send -->
		<section class="wto-send-sms-step">
			<h2 class="wto-send-sms-step-title"><span class="step-num">۴</span> <?php esc_html_e( 'ارسال', 'wto' ); ?></h2>

			<div class="wto-send-sms-actions">
				<button type="submit" class="button button-primary button-hero wto-send-submit"><?php esc_html_e( 'ارسال پیامک', 'wto' ); ?></button>
				<span class="wto-send-sms-result" role="status" aria-live="polite"></span>
			</div>
		</section>
	</form>
	<?php
}

function wto_send_sms_render_history_tab() {
	global $wpdb;
	$table = wto_send_log_table();

	$page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$limit = 25;
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	$total_pages = max( 1, (int) ceil( $total / $limit ) );
	$offset = ( $page - 1 ) * $limit;

	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );

	$source_labels = array(
		'manual'     => __( 'وارد شده دستی', 'wto' ),
		'newsletter' => __( 'خبرنامه', 'wto' ),
		'notify'     => __( 'موجود شد خبرم کن', 'wto' ),
		'phonebook'  => __( 'باشگاه مشتریان', 'wto' ),
	);
	?>
	<div class="wto-send-sms-card">
		<h2><?php esc_html_e( 'تاریخچه ارسال‌ها', 'wto' ); ?></h2>

		<table class="widefat striped wto-send-sms-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'شناسه', 'wto' ); ?></th>
					<th><?php esc_html_e( 'تاریخ', 'wto' ); ?></th>
					<th><?php esc_html_e( 'منبع', 'wto' ); ?></th>
					<th><?php esc_html_e( 'خط ارسال', 'wto' ); ?></th>
					<th><?php esc_html_e( 'متن پیامک', 'wto' ); ?></th>
					<th><?php esc_html_e( 'گیرندگان', 'wto' ); ?></th>
					<th><?php esc_html_e( 'موفق', 'wto' ); ?></th>
					<th><?php esc_html_e( 'ناموفق', 'wto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'هنوز هیچ ارسالی انجام نشده.', 'wto' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$jdate  = function_exists( 'wto_send_reports_to_jalali' ) ? wto_send_reports_to_jalali( $r['created_at'] ) : $r['created_at'];
					$source = isset( $source_labels[ $r['source'] ] ) ? $source_labels[ $r['source'] ] : $r['source'];
					// `pattern_code` column is reused to store the line number for simple SMS.
					$line   = $r['pattern_code'] !== '' ? $r['pattern_code'] : '—';
					$content = $r['message_text'];
					$content_short = mb_substr( (string) $content, 0, 80 );
					if ( mb_strlen( (string) $content ) > 80 ) {
						$content_short .= '…';
					}
				?>
					<tr>
						<td><?php echo esc_html( $r['id'] ); ?></td>
						<td class="wto-send-sms-date-cell"><?php echo esc_html( $jdate ); ?></td>
						<td><?php echo esc_html( $source ); ?></td>
						<td dir="ltr"><?php echo esc_html( $line ); ?></td>
						<td class="wto-send-sms-content-cell" title="<?php echo esc_attr( $content ); ?>"><?php echo esc_html( $content_short ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['recipients_count'] ) ); ?></td>
						<td><span class="wto-status wto-status-success"><?php echo esc_html( number_format_i18n( (int) $r['sent_count'] ) ); ?></span></td>
						<td><span class="wto-status wto-status-<?php echo (int) $r['failed_count'] > 0 ? 'danger' : 'muted'; ?>"><?php echo esc_html( number_format_i18n( (int) $r['failed_count'] ) ); ?></span></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) :
			$base = add_query_arg( array( 'page' => 'farazwto-send-sms', 'tab' => 'history' ), admin_url( 'admin.php' ) );
			?>
			<div class="wto-send-sms-pagination">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1, $base ) ); ?>">« <?php esc_html_e( 'صفحه قبل', 'wto' ); ?></a>
				<?php endif; ?>
				<span><?php printf( esc_html__( 'صفحه %1$s از %2$s', 'wto' ), esc_html( number_format_i18n( $page ) ), esc_html( number_format_i18n( $total_pages ) ) ); ?></span>
				<?php if ( $page < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $base ) ); ?>"><?php esc_html_e( 'صفحه بعد', 'wto' ); ?> »</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

function wto_send_sms_render_inline() {
	$nonce   = wp_create_nonce( 'wto_send_sms_admin' );
	$ajaxUrl = admin_url( 'admin-ajax.php' );
	?>
	<style>
	.wto-send-sms-wrapper .wto-send-sms-title-main { margin: 16px 0 8px; }
	.wto-send-sms-wrapper .wto-send-sms-tabs { display: flex; gap: 4px; border-bottom: 1px solid #c3c4c7; margin: 16px 0 20px; }
	.wto-send-sms-wrapper .wto-send-sms-tab { padding: 10px 18px; text-decoration: none; color: #50575e; background: #f1f1f1; border: 1px solid #c3c4c7; border-bottom: 0; border-radius: 6px 6px 0 0; margin-bottom: -1px; font-size: 13px; }
	.wto-send-sms-wrapper .wto-send-sms-tab.is-active { background: #fff; color: #1d2327; font-weight: 600; }
	.wto-send-sms-wrapper .wto-send-sms-card { background: #fff; padding: 24px 28px; border: 1px solid #e5e7eb; border-radius: 10px; max-width: 880px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
	.wto-send-sms-wrapper .wto-send-sms-card h2 { margin: 0 0 12px; font-size: 16px; }
	.wto-send-sms-wrapper .wto-send-sms-step { padding: 18px 0; border-bottom: 1px dashed #e5e7eb; }
	.wto-send-sms-wrapper .wto-send-sms-step:last-child { border-bottom: 0; padding-bottom: 0; }
	.wto-send-sms-wrapper .wto-send-sms-step-title { margin: 0 0 14px; font-size: 15px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 10px; }
	.wto-send-sms-wrapper .step-num { display: inline-flex; width: 26px; height: 26px; align-items: center; justify-content: center; background: #6366f1; color: #fff; border-radius: 50%; font-size: 13px; font-weight: 700; }
	.wto-send-sms-wrapper .wto-send-sms-source-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 14px; }
	.wto-send-sms-wrapper .wto-send-sms-source-card { display: flex; align-items: start; gap: 10px; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 8px; cursor: pointer; transition: border-color .15s, background .15s; }
	.wto-send-sms-wrapper .wto-send-sms-source-card:hover { border-color: #6366f1; }
	.wto-send-sms-wrapper .wto-send-sms-source-card input[type="radio"] { margin-top: 4px; }
	.wto-send-sms-wrapper .wto-send-sms-source-card:has(input:checked) { border-color: #6366f1; background: #eef2ff; }
	.wto-send-sms-wrapper .wto-source-title { font-weight: 600; font-size: 13px; color: #1f2937; }
	.wto-send-sms-wrapper .wto-source-help { font-size: 12px; color: #6b7280; margin-top: 2px; }
	.wto-send-sms-wrapper .wto-source-panel { padding: 12px 0 0; }
	.wto-send-sms-wrapper .wto-source-panel[hidden] { display: none !important; }
	.wto-send-sms-wrapper .wto-send-sms-field { display: flex; flex-direction: column; gap: 5px; }
	.wto-send-sms-wrapper .wto-send-sms-field span { font-size: 12px; color: #374151; font-weight: 500; }
	.wto-send-sms-wrapper .wto-send-sms-field input,
	.wto-send-sms-wrapper .wto-send-sms-field select,
	.wto-send-sms-wrapper .wto-send-sms-field textarea { width: 100%; max-width: 600px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; box-sizing: border-box; font-family: inherit; }
	.wto-send-sms-wrapper .wto-send-sms-field textarea { resize: vertical; min-height: 90px; line-height: 1.7; }
	.wto-send-sms-wrapper .wto-send-sms-fieldset { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 14px; margin: 0 0 14px; max-width: 400px; }
	.wto-send-sms-wrapper .wto-send-sms-fieldset legend { font-weight: 600; font-size: 13px; padding: 0 6px; }
	.wto-send-sms-wrapper .wto-send-sms-fieldset label { margin-left: 16px; font-size: 13px; }
	.wto-send-sms-wrapper .wto-mode-panel[hidden] { display: none !important; }
	.wto-send-sms-wrapper .wto-send-sms-help-small { color: #6b7280; font-size: 12px; margin: 4px 0 0; }
	.wto-send-sms-wrapper .wto-text-counter { font-weight: 600; color: #1f2937; }
	.wto-send-sms-wrapper .wto-send-sms-actions { display: flex; align-items: center; gap: 14px; }
	.wto-send-sms-wrapper .wto-send-sms-result { font-size: 13px; }
	.wto-send-sms-wrapper .wto-send-sms-result.success { color: #047857; }
	.wto-send-sms-wrapper .wto-send-sms-result.error { color: #b91c1c; }
	.wto-send-sms-wrapper .wto-send-sms-table th,
	.wto-send-sms-wrapper .wto-send-sms-table td { padding: 10px 12px; vertical-align: middle; }
	.wto-send-sms-wrapper .wto-send-sms-date-cell { direction: ltr; font-variant-numeric: tabular-nums; }
	.wto-send-sms-wrapper .wto-send-sms-content-cell { max-width: 320px; }
	.wto-send-sms-wrapper .wto-send-sms-pagination { display: flex; gap: 8px; align-items: center; margin-top: 14px; }
	.wto-send-sms-wrapper .wto-status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
	.wto-send-sms-wrapper .wto-status-success { background: #d1f5e0; color: #006d28; }
	.wto-send-sms-wrapper .wto-status-danger  { background: #ffd7d7; color: #8a0a0a; }
	.wto-send-sms-wrapper .wto-status-muted   { background: #e0e0e0; color: #50575e; }
	@media (max-width: 720px) {
		.wto-send-sms-wrapper .wto-send-sms-source-grid { grid-template-columns: 1fr; }
	}
	</style>
	<script>
	(function(){
		var ajaxUrl = <?php echo wp_json_encode( $ajaxUrl ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
		var form    = document.getElementById('wto-send-sms-form');
		if (!form) return;

		var sources  = form.querySelectorAll('input[name="source"]');
		var panels   = {
			manual:     form.querySelector('.wto-source-manual'),
			phonebook:  form.querySelector('.wto-source-phonebook')
		};

		// Toggle source-panel visibility based on the selected source.
		function refreshSourcePanels() {
			var picked = (form.querySelector('input[name="source"]:checked') || {}).value || 'manual';
			Object.keys(panels).forEach(function(k){
				if (panels[k]) {
					if (k === picked) panels[k].removeAttribute('hidden');
					else panels[k].setAttribute('hidden','');
				}
			});
			if (picked === 'phonebook') loadPhonebooks();
		}
		sources.forEach(function(r){ r.addEventListener('change', refreshSourcePanels); });
		refreshSourcePanels();

		// Character counter for the message textarea.
		var msgArea = form.querySelector('textarea[name="message"]');
		var msgCounter = form.querySelector('.wto-text-counter');
		if (msgArea && msgCounter) {
			msgArea.addEventListener('input', function(){
				msgCounter.textContent = String(msgArea.value.length);
			});
		}

		// Manual mobiles — live count of valid lines.
		var manualArea = form.querySelector('textarea[name="manual_mobiles"]');
		var manualCounter = form.querySelector('.wto-manual-counter');
		function countManualMobiles(){
			if (!manualArea || !manualCounter) return;
			var raw = manualArea.value
				.replace(/[۰-۹]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x06C0); }); // Persian → ASCII digits
			var tokens = raw.split(/[\s,;]+/);
			var ok = 0;
			tokens.forEach(function(t){
				t = t.replace(/\D+/g, '');
				if (/^0?9\d{9}$/.test(t)) ok++;
			});
			manualCounter.textContent = ok > 0 ? (ok + ' شماره معتبر شناسایی شد') : '';
		}
		if (manualArea) manualArea.addEventListener('input', countManualMobiles);

		// Source counts (newsletter, notify) — fetch once on load.
		fetch(ajaxUrl + '?action=wto_send_recipient_counts&nonce=' + encodeURIComponent(nonce), { credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(json){
				if (!json.success) return;
				var nl = form.querySelector('.wto-count-newsletter');
				var nf = form.querySelector('.wto-count-notify');
				if (nl) nl.textContent = (json.data.newsletter || 0) + ' مشترک فعال';
				if (nf) nf.textContent = (json.data.notify || 0) + ' درخواست در انتظار';
			});

		// Sender lines — fetch the user's accessible lines from the FarazSMS panel.
		var lineSelect = form.querySelector('select[name="line_number"]');
		if (lineSelect) {
			var fd = new FormData();
			fd.append('action', 'wto_send_fetch_lines');
			fd.append('nonce', nonce);
			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(json){
					if (!json.success || !json.data || !Array.isArray(json.data.lines)) return;
					var current = lineSelect.value;
					var html = '';
					json.data.lines.forEach(function(line){
						var label = line.title || line.number;
						if (line.is_dedicated && line.number !== 'PRO') label += ' • اختصاصی';
						html += '<option value="' + String(line.number).replace(/"/g, '&quot;') + '">' + label + '</option>';
					});
					if (html) {
						lineSelect.innerHTML = html;
						// Default to PRO unless the user already changed it.
						if (current === 'PRO' || current === '') {
							lineSelect.value = 'PRO';
						}
					}
				});
		}

		// Phonebooks loader + selected-count display.
		var phonebooksLoaded = false;
		var phonebookData = {}; // id -> count map
		function loadPhonebooks() {
			if (phonebooksLoaded) return;
			phonebooksLoaded = true;
			var select = form.querySelector('select[name="phonebook_id"]');
			var info   = form.querySelector('.wto-phonebook-info');
			if (!select) return;
			var fd = new FormData();
			fd.append('action', 'wto_send_fetch_phonebooks');
			fd.append('nonce', nonce);
			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(json){
					if (!json.success) {
						select.innerHTML = '<option value="">' + ((json.data && json.data.message) || 'خطا در بارگذاری') + '</option>';
						return;
					}
					var pbs = (json.data && json.data.phonebooks) || [];
					if (pbs.length === 0) {
						select.innerHTML = '<option value="">هیچ دفترچه‌ای یافت نشد</option>';
						return;
					}
					var html = '<option value="">— یک دفترچه انتخاب کنید —</option>';
					pbs.forEach(function(pb){
						phonebookData[String(pb.id)] = pb;
						var label = pb.title + (pb.count !== null && pb.count !== undefined ? ' — ' + pb.count + ' نفر' : '');
						html += '<option value="' + pb.id + '">' + label + '</option>';
					});
					select.innerHTML = html;
				})
				.catch(function(){
					select.innerHTML = '<option value="">خطا در ارتباط با سرور</option>';
					phonebooksLoaded = false;
				});

			// When user selects a phonebook, show the count below.
			select.addEventListener('change', function(){
				if (!info) return;
				var pb = phonebookData[select.value];
				if (pb && pb.count !== null && pb.count !== undefined) {
					info.innerHTML = '<strong>' + pb.count + '</strong> نفر در این دفترچه عضو هستند. پیامک به همه آن‌ها ارسال خواهد شد.';
				} else if (pb) {
					info.textContent = 'پیامک به همه اعضای این دفترچه ارسال می‌شود.';
				} else {
					info.textContent = 'لیست دفترچه‌ها به همراه تعداد اعضای هر کدام از پنل فراز خوانده می‌شود. پیامک به همه اعضای دفترچه‌ی انتخاب‌شده ارسال خواهد شد.';
				}
			});
		}

		// Submit handler.
		form.addEventListener('submit', function(ev){
			ev.preventDefault();
			if (!confirm('ارسال پیامک به همه گیرندگان انتخاب‌شده — مطمئن هستید؟')) return;
			var btn = form.querySelector('.wto-send-submit');
			var result = form.querySelector('.wto-send-sms-result');
			var fd = new FormData(form);
			fd.append('action', 'wto_send_sms_dispatch');
			fd.append('nonce', nonce);
			btn.disabled = true;
			result.className = 'wto-send-sms-result';
			result.textContent = 'در حال ارسال...';
			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(json){
					result.className = 'wto-send-sms-result ' + (json.success ? 'success' : 'error');
					result.textContent = (json.data && json.data.message) || 'انجام شد.';
				})
				.catch(function(){
					result.className = 'wto-send-sms-result error';
					result.textContent = 'خطا در ارتباط با سرور.';
				})
				.then(function(){ btn.disabled = false; });
		});
	})();
	</script>
	<?php
}
