<?php
/**
 * خبرنامه محصولات (Product Newsletter) — Phase 1
 *
 * این ماژول صفحه ادمین، شورت‌کد، ویجت، AJAX subscribe، CSV export، و
 * ارسال گروهی پیامک به اعضای خبرنامه را در پلاگین فراز اس‌ام‌اس فراهم می‌کند.
 *
 * جدول DB:  {$wpdb->prefix}wto_newsletter_subscribers
 * Shortcode: [wto_newsletter]
 * Widget:    WTO_Newsletter_Widget
 * Submenu:   farazwto-newsletter (priority 985، بین گزارشات و بازخورد)
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// ============================================================================
// Schema — جدول DB با version gating
// ============================================================================

const WTO_NEWSLETTER_DB_VERSION        = '1.1.0'; // v3.18.0: composite index status_subscribed_at
const WTO_NEWSLETTER_DB_VERSION_OPTION = 'wto_newsletter_db_version';

/**
 * Returns the subscribers table name (prefixed).
 *
 * @return string
 */
function wto_newsletter_table() {
	global $wpdb;
	return $wpdb->prefix . 'wto_newsletter_subscribers';
}

/**
 * Create/upgrade the subscribers table. Gated by version so dbDelta does not
 * run on every request (same pattern as Elementor SMS SQL class).
 */
function wto_newsletter_maybe_setup_table() {
	if ( get_option( WTO_NEWSLETTER_DB_VERSION_OPTION ) === WTO_NEWSLETTER_DB_VERSION ) {
		return;
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	global $wpdb;
	$table           = wto_newsletter_table();
	$charset_collate = $wpdb->get_charset_collate();

	// v3.18.0: composite index status_subscribed_at — speeds up
	//   SELECT COUNT(*) FROM ... WHERE status='active' AND subscribed_at >= X
	// که در KPI widget و آمار roi استفاده می‌شود.
	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(255) NOT NULL DEFAULT '',
		mobile VARCHAR(20) NOT NULL,
		source VARCHAR(50) NOT NULL DEFAULT '',
		ip VARCHAR(45) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		subscribed_at DATETIME NOT NULL,
		unsubscribed_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY mobile (mobile),
		KEY status (status),
		KEY subscribed_at (subscribed_at),
		KEY status_subscribed_at (status, subscribed_at)
	) $charset_collate;";

	dbDelta( $sql );
	update_option( WTO_NEWSLETTER_DB_VERSION_OPTION, WTO_NEWSLETTER_DB_VERSION, false );
}
add_action( 'admin_init', 'wto_newsletter_maybe_setup_table' );

// ============================================================================
// تنظیمات پیش‌فرض
// ============================================================================

/**
 * Read merged newsletter settings, applying sensible defaults.
 *
 * @return array
 */
function wto_newsletter_settings() {
	$defaults = array(
		'enabled'              => '1',
		'form_title'           => 'عضویت در خبرنامه',
		'form_description'     => 'با عضویت در خبرنامه، از تخفیف‌ها و محصولات جدید با خبر شوید.',
		'name_label'           => 'نام',
		'mobile_label'         => 'شماره موبایل',
		'button_text'          => 'عضویت',
		'success_message'      => 'با موفقیت در خبرنامه عضو شدید.',
		'duplicate_message'    => 'این شماره قبلاً در خبرنامه ثبت شده است.',
		'error_message'        => 'خطا در ثبت عضویت. لطفاً بعداً تلاش کنید.',
		'welcome_pattern_code' => '', // اختیاری — اگر تنظیم شود، پیامک خوش‌آمد ارسال می‌شود
		'redirect_url'         => '',
	);
	$saved = get_option( 'wto_newsletter_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

// ============================================================================
// زیرمنوی ادمین
// ============================================================================

/**
 * Register the newsletter admin page (priority 985 — between Reports at 990
 * and Feedback at 999, so it appears just above Feedback in the menu).
 */
add_action( 'admin_menu', 'wto_newsletter_register_submenu', 985 );
function wto_newsletter_register_submenu() {
	add_submenu_page(
		'farazwto',
		__( 'خبرنامه', 'wto' ),
		__( 'خبرنامه', 'wto' ),
		'manage_options',
		'farazwto-newsletter',
		'wto_render_newsletter_page'
	);
}

// ============================================================================
// CRUD ساده روی جدول
// ============================================================================

/**
 * Normalize an Iranian mobile number to the canonical `09xxxxxxxxx` form.
 * Returns '' when the input cannot be canonicalised — callers should treat
 * that as a validation failure.
 *
 * @param string $raw
 * @return string
 */
function wto_newsletter_normalize_mobile( $raw ) {
	$raw = (string) $raw;
	if ( function_exists( 'wto_tr_num' ) ) {
		$raw = wto_tr_num( $raw );
	}
	$digits = preg_replace( '/\D+/', '', $raw );
	if ( $digits === '' ) {
		return '';
	}
	// Strip 98 / +98 prefix or leading 0.
	if ( strpos( $digits, '98' ) === 0 && strlen( $digits ) === 12 ) {
		$digits = '0' . substr( $digits, 2 );
	} elseif ( strlen( $digits ) === 10 && $digits[0] === '9' ) {
		$digits = '0' . $digits;
	}
	if ( strlen( $digits ) !== 11 || strpos( $digits, '09' ) !== 0 ) {
		return '';
	}
	return $digits;
}

/**
 * Insert (or restore) a subscriber. Returns array{success:bool, message:string, code:string, id?:int}.
 *
 * Codes:
 *   ok           — newly inserted
 *   reactivated  — existed but was unsubscribed, now restored
 *   duplicate    — already active
 *   invalid      — invalid mobile
 *   error        — DB error
 *
 * @param string $name
 * @param string $mobile_raw
 * @param string $source
 * @return array
 */
function wto_newsletter_insert_subscriber( $name, $mobile_raw, $source = '' ) {
	global $wpdb;
	$mobile = wto_newsletter_normalize_mobile( $mobile_raw );
	if ( $mobile === '' ) {
		return array(
			'success' => false,
			'code'    => 'invalid',
			'message' => __( 'شماره موبایل معتبر نیست.', 'wto' ),
		);
	}
	$name   = sanitize_text_field( wp_unslash( (string) $name ) );
	$source = sanitize_key( $source );
	if ( $source === '' ) {
		$source = 'shortcode';
	}
	$ip = '';
	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
	$now   = current_time( 'mysql' );
	$table = wto_newsletter_table();

	// Check existing row by mobile (unique key).
	$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE mobile = %s LIMIT 1", $mobile ), ARRAY_A );

	if ( $existing ) {
		if ( isset( $existing['status'] ) && $existing['status'] === 'active' ) {
			return array(
				'success' => false,
				'code'    => 'duplicate',
				'message' => wto_newsletter_settings()['duplicate_message'],
				'id'      => (int) $existing['id'],
			);
		}
		// Reactivate an unsubscribed entry.
		$wpdb->update(
			$table,
			array(
				'name'            => $name,
				'status'          => 'active',
				'subscribed_at'   => $now,
				'unsubscribed_at' => null,
				'ip'              => $ip,
				'source'          => $source,
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return array(
			'success' => true,
			'code'    => 'reactivated',
			'message' => wto_newsletter_settings()['success_message'],
			'id'      => (int) $existing['id'],
		);
	}

	$inserted = $wpdb->insert(
		$table,
		array(
			'name'          => $name,
			'mobile'        => $mobile,
			'source'        => $source,
			'ip'            => $ip,
			'status'        => 'active',
			'subscribed_at' => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( $inserted === false ) {
		return array(
			'success' => false,
			'code'    => 'error',
			'message' => wto_newsletter_settings()['error_message'],
		);
	}
	return array(
		'success' => true,
		'code'    => 'ok',
		'message' => wto_newsletter_settings()['success_message'],
		'id'      => (int) $wpdb->insert_id,
	);
}

/**
 * Quick counts grouped by status — used in the admin stats box.
 *
 * @return array{total:int, active:int, unsubscribed:int, last_30_days:int}
 */
function wto_newsletter_get_counts() {
	global $wpdb;
	$table = wto_newsletter_table();
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", 'active' ) );
	$unsubscribed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", 'unsubscribed' ) );
	$last_30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE subscribed_at >= %s", gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ) ) );
	return array(
		'total'        => $total,
		'active'       => $active,
		'unsubscribed' => $unsubscribed,
		'last_30_days' => $last_30,
	);
}

// ============================================================================
// AJAX — عضویت در خبرنامه (Public)
// ============================================================================

add_action( 'wp_ajax_wto_newsletter_subscribe',        'wto_newsletter_ajax_subscribe' );
add_action( 'wp_ajax_nopriv_wto_newsletter_subscribe', 'wto_newsletter_ajax_subscribe' );
function wto_newsletter_ajax_subscribe() {
	// nonce
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'wto_newsletter_subscribe' ) ) {
		wp_send_json_error( array( 'message' => __( 'خطای امنیتی. لطفاً صفحه را رفرش کنید.', 'wto' ) ), 403 );
	}
	// v3.19.0: rate-limit دو لایه با helper جدید — IP + شماره موبایل
	$secondary_key = isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '';
	if ( function_exists( 'wto_rate_limit_guard_public' ) ) {
		$guard = wto_rate_limit_guard_public( 'newsletter_subscribe', $secondary_key );
		if ( ! $guard['allowed'] ) {
			wp_send_json_error( array( 'message' => $guard['message'] ), 429 );
		}
	}

	$settings = wto_newsletter_settings();
	if ( $settings['enabled'] !== '1' ) {
		wp_send_json_error( array( 'message' => __( 'عضویت در خبرنامه در حال حاضر غیرفعال است.', 'wto' ) ) );
	}

	$name   = isset( $_POST['name'] )   ? wp_unslash( $_POST['name'] )   : '';
	$mobile = isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '';
	$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : 'shortcode';

	$result = wto_newsletter_insert_subscriber( $name, $mobile, $source );
	if ( ! $result['success'] ) {
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	// ارسال پیامک خوش‌آمد در صورت تنظیم الگو.
	$welcome = trim( (string) $settings['welcome_pattern_code'] );
	if ( $welcome !== '' && function_exists( 'wto_send_pattern_sms_raw' ) ) {
		$mobile_clean = wto_newsletter_normalize_mobile( $mobile );
		if ( $mobile_clean !== '' ) {
			$sender = get_option( 'wto_sender', '' );
			$attrs  = array(
				'name'     => sanitize_text_field( $name ),
				'sitename' => get_bloginfo( 'name' ),
			);
			// fire-and-log — never block the AJAX response.
			wto_send_pattern_sms_raw( $mobile_clean, $welcome, $attrs, $sender );
		}
	}

	$payload = array( 'message' => $result['message'] );
	if ( ! empty( $settings['redirect_url'] ) ) {
		$payload['redirect'] = esc_url_raw( $settings['redirect_url'] );
	}
	wp_send_json_success( $payload );
}

// ============================================================================
// AJAX — Bulk operations (Admin only)
// ============================================================================

add_action( 'wp_ajax_wto_newsletter_delete', 'wto_newsletter_ajax_delete' );
function wto_newsletter_ajax_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_newsletter_admin', 'nonce' );
	global $wpdb;
	$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
	$ids = array_filter( array_map( 'absint', $ids ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => __( 'هیچ ردیفی انتخاب نشده.', 'wto' ) ) );
	}
	// Cap to 500 per request to prevent very large POSTs.
	$ids   = array_slice( $ids, 0, 500 );
	$table = wto_newsletter_table();
	$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$query = $wpdb->prepare( "DELETE FROM $table WHERE id IN ($ph)", $ids );
	$count = (int) $wpdb->query( $query );
	wp_send_json_success( array(
		'message' => sprintf( /* translators: %s deleted count */ __( '%s ردیف حذف شد.', 'wto' ), number_format_i18n( $count ) ),
		'deleted' => $count,
	) );
}

add_action( 'admin_post_wto_newsletter_export', 'wto_newsletter_handle_export' );
function wto_newsletter_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'دسترسی غیرمجاز.', 'wto' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_newsletter_export' );

	global $wpdb;
	$table  = wto_newsletter_table();
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$query  = "SELECT id, name, mobile, source, status, subscribed_at FROM $table";
	$params = array();
	if ( $status !== '' && in_array( $status, array( 'active', 'unsubscribed' ), true ) ) {
		$query .= ' WHERE status = %s';
		$params[] = $status;
	}
	$query .= ' ORDER BY id DESC';
	$rows  = $params ? $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ) : $wpdb->get_results( $query, ARRAY_A );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=wto-newsletter-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	// BOM for Excel UTF-8 compatibility (Persian text).
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array( 'ID', 'نام', 'شماره موبایل', 'منبع', 'وضعیت', 'تاریخ عضویت' ) );
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			fputcsv( $out, array(
				$r['id'],
				$r['name'],
				$r['mobile'],
				$r['source'],
				$r['status'],
				$r['subscribed_at'],
			) );
		}
	}
	fclose( $out );
	exit;
}

add_action( 'wp_ajax_wto_newsletter_send_bulk', 'wto_newsletter_ajax_send_bulk' );
function wto_newsletter_ajax_send_bulk() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_newsletter_admin', 'nonce' );

	$mode    = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
	$pattern = isset( $_POST['pattern_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_code'] ) ) : '';
	$text    = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';

	if ( $mode === 'pattern' && $pattern === '' ) {
		wp_send_json_error( array( 'message' => __( 'کد الگو را وارد کنید.', 'wto' ) ) );
	}
	if ( $mode === 'simple' && $text === '' ) {
		wp_send_json_error( array( 'message' => __( 'متن پیامک خالی است.', 'wto' ) ) );
	}

	global $wpdb;
	$table       = wto_newsletter_table();
	$subscribers = $wpdb->get_results( $wpdb->prepare( "SELECT name, mobile FROM $table WHERE status = %s", 'active' ), ARRAY_A );
	if ( empty( $subscribers ) ) {
		wp_send_json_error( array( 'message' => __( 'هیچ مشترک فعالی در خبرنامه وجود ندارد.', 'wto' ) ) );
	}

	$sender = get_option( 'wto_sender', '' );
	$sent   = 0;
	$failed = 0;

	if ( $mode === 'pattern' && function_exists( 'wto_send_pattern_sms_raw' ) ) {
		foreach ( $subscribers as $sub ) {
			$attrs  = array(
				'name'     => $sub['name'],
				'sitename' => get_bloginfo( 'name' ),
			);
			$result = wto_send_pattern_sms_raw( $sub['mobile'], $pattern, $attrs, $sender );
			if ( $result === 'success' ) {
				$sent++;
			} else {
				$failed++;
			}
		}
	} elseif ( $mode === 'simple' ) {
		// ارسال متن آزاد به همه مشترکین در یک batch (ساده — برای حجم بالا باید queue شود).
		$recipients = array_map( function ( $s ) { return $s['mobile']; }, $subscribers );
		$apikey     = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
		if ( $apikey === '' ) {
			wp_send_json_error( array( 'message' => __( 'کلید دسترسی (Api-Key) در تنظیمات وارد نشده است.', 'wto' ) ) );
		}
		// chunk به 100 تا برای جلوگیری از پاسخ‌های بزرگ.
		$chunks = array_chunk( $recipients, 100 );
		foreach ( $chunks as $chunk ) {
			$body = array(
				'line_number'   => $sender,
				'recipients'    => $chunk,
				'text'          => $text,
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
	} else {
		wp_send_json_error( array( 'message' => __( 'نوع ارسال نامعتبر است.', 'wto' ) ) );
	}

	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: 1 sent count, 2 failed count */
			__( 'ارسال انجام شد — موفق: %1$s، ناموفق: %2$s', 'wto' ),
			number_format_i18n( $sent ),
			number_format_i18n( $failed )
		),
		'sent'    => $sent,
		'failed'  => $failed,
	) );
}

// Save settings (admin only — POST handler).
add_action( 'admin_post_wto_newsletter_save_settings', 'wto_newsletter_handle_save_settings' );
function wto_newsletter_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'دسترسی غیرمجاز.', 'wto' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_newsletter_settings' );

	$defaults = wto_newsletter_settings();
	$new      = $defaults;
	$fields   = array(
		'form_title', 'form_description', 'name_label', 'mobile_label',
		'button_text', 'success_message', 'duplicate_message', 'error_message',
		'welcome_pattern_code',
	);
	foreach ( $fields as $f ) {
		if ( isset( $_POST[ $f ] ) ) {
			$new[ $f ] = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
		}
	}
	$new['enabled']      = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? '1' : '0';
	$new['redirect_url'] = isset( $_POST['redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) ) : '';

	update_option( 'wto_newsletter_settings', $new, false );

	wp_safe_redirect( add_query_arg( array(
		'page'    => 'farazwto-newsletter',
		'updated' => '1',
	), admin_url( 'admin.php' ) ) );
	exit;
}

// ============================================================================
// Shortcode + Widget
// ============================================================================

add_shortcode( 'wto_newsletter', 'wto_newsletter_render_shortcode' );
function wto_newsletter_render_shortcode( $atts ) {
	$settings = wto_newsletter_settings();
	if ( $settings['enabled'] !== '1' ) {
		return '';
	}
	$atts = shortcode_atts( array(
		'title'       => $settings['form_title'],
		'description' => $settings['form_description'],
		'source'      => 'shortcode',
		'show_name'   => '1',
	), $atts, 'wto_newsletter' );

	wto_newsletter_enqueue_frontend_assets();

	ob_start();
	?>
	<div class="wto-newsletter-form-wrapper">
		<form class="wto-newsletter-form" data-source="<?php echo esc_attr( $atts['source'] ); ?>">
			<?php if ( $atts['title'] !== '' ) : ?>
				<h3 class="wto-newsletter-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>
			<?php if ( $atts['description'] !== '' ) : ?>
				<p class="wto-newsletter-description"><?php echo esc_html( $atts['description'] ); ?></p>
			<?php endif; ?>
			<div class="wto-newsletter-fields">
				<?php if ( $atts['show_name'] === '1' ) : ?>
					<label class="wto-newsletter-field">
						<span><?php echo esc_html( $settings['name_label'] ); ?></span>
						<input type="text" name="name" maxlength="100">
					</label>
				<?php endif; ?>
				<label class="wto-newsletter-field">
					<span><?php echo esc_html( $settings['mobile_label'] ); ?></span>
					<input type="tel" name="mobile" maxlength="15" required dir="ltr" inputmode="numeric" pattern="[0-9۰-۹]+">
				</label>
			</div>
			<button type="submit" class="wto-newsletter-submit"><?php echo esc_html( $settings['button_text'] ); ?></button>
			<div class="wto-newsletter-message" role="status" aria-live="polite"></div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Enqueue frontend assets only on pages that actually use the shortcode or widget.
 */
function wto_newsletter_enqueue_frontend_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	// Inline CSS — scoped under .wto-newsletter-form-wrapper so it never leaks.
	add_action( 'wp_footer', 'wto_newsletter_render_frontend_inline', 5 );
}

/**
 * Inline JS + nonce + AJAX URL for the form.
 */
function wto_newsletter_render_frontend_inline() {
	$nonce    = wp_create_nonce( 'wto_newsletter_subscribe' );
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>
	<style>
	.wto-newsletter-form-wrapper { font-family: inherit; direction: rtl; max-width: 420px; margin: 12px auto; padding: 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
	.wto-newsletter-form-wrapper .wto-newsletter-title { margin: 0 0 6px; font-size: 17px; font-weight: 700; color: #1f2937; }
	.wto-newsletter-form-wrapper .wto-newsletter-description { margin: 0 0 16px; font-size: 13px; line-height: 1.7; color: #4b5563; }
	.wto-newsletter-form-wrapper .wto-newsletter-fields { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
	.wto-newsletter-form-wrapper .wto-newsletter-field { display: flex; flex-direction: column; gap: 4px; }
	.wto-newsletter-form-wrapper .wto-newsletter-field span { font-size: 12px; color: #374151; }
	.wto-newsletter-form-wrapper .wto-newsletter-field input { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
	.wto-newsletter-form-wrapper .wto-newsletter-field input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
	.wto-newsletter-form-wrapper .wto-newsletter-submit { width: 100%; background: #6366f1; color: #fff; border: 0; border-radius: 6px; padding: 11px 18px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s; }
	.wto-newsletter-form-wrapper .wto-newsletter-submit:hover { background: #4f46e5; }
	.wto-newsletter-form-wrapper .wto-newsletter-submit:disabled { opacity: 0.6; cursor: not-allowed; }
	.wto-newsletter-form-wrapper .wto-newsletter-message { margin-top: 10px; min-height: 20px; font-size: 13px; }
	.wto-newsletter-form-wrapper .wto-newsletter-message.success { color: #047857; }
	.wto-newsletter-form-wrapper .wto-newsletter-message.error { color: #b91c1c; }
	</style>
	<script>
	(function(){
		var endpoints = {
			url: <?php echo wp_json_encode( $ajax_url ); ?>,
			nonce: <?php echo wp_json_encode( $nonce ); ?>
		};
		function persianToAscii(str){
			var p = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
			var a = ['0','1','2','3','4','5','6','7','8','9'];
			for (var i=0;i<p.length;i++){ str = str.split(p[i]).join(a[i]); }
			return str;
		}
		function init(form){
			if (form._wtoBound) return; form._wtoBound = true;
			form.addEventListener('submit', function(ev){
				ev.preventDefault();
				var msg = form.querySelector('.wto-newsletter-message');
				var btn = form.querySelector('.wto-newsletter-submit');
				var nameI = form.querySelector('input[name="name"]');
				var mobileI = form.querySelector('input[name="mobile"]');
				var mobile = persianToAscii((mobileI && mobileI.value) || '').replace(/\D+/g, '');
				if (!/^0?9\d{9}$/.test(mobile)) {
					msg.className = 'wto-newsletter-message error';
					msg.textContent = 'شماره موبایل معتبر نیست.';
					return;
				}
				var data = new FormData();
				data.append('action', 'wto_newsletter_subscribe');
				data.append('nonce', endpoints.nonce);
				data.append('mobile', mobile);
				data.append('name', nameI ? nameI.value : '');
				data.append('source', form.getAttribute('data-source') || 'shortcode');
				btn.disabled = true;
				var oldText = btn.textContent;
				btn.textContent = 'در حال ارسال...';
				fetch(endpoints.url, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function(r){ return r.json(); })
					.then(function(json){
						if (json.success) {
							msg.className = 'wto-newsletter-message success';
							msg.textContent = (json.data && json.data.message) || 'انجام شد.';
							form.reset();
							if (json.data && json.data.redirect) {
								setTimeout(function(){ window.location.href = json.data.redirect; }, 1500);
							}
						} else {
							msg.className = 'wto-newsletter-message error';
							msg.textContent = (json.data && json.data.message) || 'خطا.';
						}
					})
					.catch(function(){
						msg.className = 'wto-newsletter-message error';
						msg.textContent = 'خطا در ارتباط با سرور.';
					})
					.then(function(){ btn.disabled = false; btn.textContent = oldText; });
			});
		}
		document.querySelectorAll('.wto-newsletter-form').forEach(init);
	})();
	</script>
	<?php
}

/**
 * Sidebar widget — wraps the same shortcode.
 */
class WTO_Newsletter_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'wto_newsletter_widget',
			__( 'خبرنامه فراز اس‌ام‌اس', 'wto' ),
			array( 'description' => __( 'فرم عضویت در خبرنامه پیامکی.', 'wto' ) )
		);
	}
	public function widget( $args, $instance ) {
		echo $args['before_widget']; // phpcs:ignore — provided by sidebar registration
		$title       = ! empty( $instance['title'] )       ? $instance['title']       : '';
		$description = ! empty( $instance['description'] ) ? $instance['description'] : '';
		echo do_shortcode( sprintf(
			'[wto_newsletter title="%s" description="%s" source="widget"]',
			esc_attr( $title ),
			esc_attr( $description )
		) );
		echo $args['after_widget']; // phpcs:ignore
	}
	public function form( $instance ) {
		$title       = isset( $instance['title'] )       ? esc_attr( $instance['title'] )       : '';
		$description = isset( $instance['description'] ) ? esc_attr( $instance['description'] ) : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'عنوان', 'wto' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'description' ) ); ?>"><?php esc_html_e( 'توضیح', 'wto' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'description' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'description' ) ); ?>" type="text" value="<?php echo esc_attr( $description ); ?>">
		</p>
		<?php
	}
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'       => sanitize_text_field( $new_instance['title'] ?? '' ),
			'description' => sanitize_text_field( $new_instance['description'] ?? '' ),
		);
	}
}
add_action( 'widgets_init', function () {
	register_widget( 'WTO_Newsletter_Widget' );
} );

// ============================================================================
// Admin page render
// ============================================================================

function wto_render_newsletter_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی غیرمجاز.', 'wto' ) );
	}
	wto_newsletter_maybe_setup_table();

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'list';
	$tab = in_array( $tab, array( 'list', 'settings', 'send' ), true ) ? $tab : 'list';

	echo '<section class="wrapper wto-newsletter-wrapper">';
	wto_newsletter_render_header();
	wto_newsletter_render_tabs( $tab );

	switch ( $tab ) {
		case 'settings':
			wto_newsletter_render_settings_tab();
			break;
		case 'send':
			wto_newsletter_render_send_tab();
			break;
		default:
			wto_newsletter_render_list_tab();
	}

	wto_newsletter_render_inline_styles();
	echo '</section>';
}

function wto_newsletter_render_header() {
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
	<h1 class="wto-newsletter-title-main"><?php esc_html_e( 'خبرنامه پیامکی', 'wto' ); ?></h1>
	<?php
}

function wto_newsletter_render_tabs( $active ) {
	$tabs = array(
		'list'     => __( 'مشترکین', 'wto' ),
		'send'     => __( 'ارسال گروهی', 'wto' ),
		'settings' => __( 'تنظیمات', 'wto' ),
	);
	?>
	<nav class="wto-newsletter-tabs">
		<?php foreach ( $tabs as $key => $label ) :
			$url = add_query_arg( array( 'page' => 'farazwto-newsletter', 'tab' => $key ), admin_url( 'admin.php' ) );
		?>
			<a class="wto-newsletter-tab <?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
}

function wto_newsletter_render_list_tab() {
	global $wpdb;
	$table  = wto_newsletter_table();
	$counts = wto_newsletter_get_counts();

	$page   = isset( $_GET['paged'] )  ? max( 1, (int) $_GET['paged'] )  : 1;
	$limit  = isset( $_GET['limit'] )  ? max( 10, min( 200, (int) $_GET['limit'] ) ) : 25;
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$where  = ' WHERE 1=1 ';
	$params = array();
	if ( in_array( $status, array( 'active', 'unsubscribed' ), true ) ) {
		$where   .= ' AND status = %s';
		$params[] = $status;
	}
	if ( $search !== '' ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where   .= ' AND (mobile LIKE %s OR name LIKE %s)';
		$params[] = $like;
		$params[] = $like;
	}
	$total = (int) ( $params
		? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $where", $params ) )
		: $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" ) );
	$total_pages = max( 1, (int) ceil( $total / $limit ) );
	$offset      = ( $page - 1 ) * $limit;
	$query       = "SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d";
	$all_params  = array_merge( $params, array( $limit, $offset ) );
	$rows        = $wpdb->get_results( $wpdb->prepare( $query, $all_params ), ARRAY_A );

	$updated = isset( $_GET['updated'] ) ? sanitize_key( $_GET['updated'] ) : '';
	?>
	<?php if ( $updated === '1' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'wto' ); ?></p></div>
	<?php endif; ?>

	<div class="wto-newsletter-stats">
		<div class="wto-newsletter-stat"><div class="num"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></div><div class="lbl"><?php esc_html_e( 'مجموع', 'wto' ); ?></div></div>
		<div class="wto-newsletter-stat"><div class="num wto-num-success"><?php echo esc_html( number_format_i18n( $counts['active'] ) ); ?></div><div class="lbl"><?php esc_html_e( 'فعال', 'wto' ); ?></div></div>
		<div class="wto-newsletter-stat"><div class="num wto-num-muted"><?php echo esc_html( number_format_i18n( $counts['unsubscribed'] ) ); ?></div><div class="lbl"><?php esc_html_e( 'لغو شده', 'wto' ); ?></div></div>
		<div class="wto-newsletter-stat"><div class="num wto-num-info"><?php echo esc_html( number_format_i18n( $counts['last_30_days'] ) ); ?></div><div class="lbl"><?php esc_html_e( '۳۰ روز اخیر', 'wto' ); ?></div></div>
	</div>

	<form method="get" class="wto-newsletter-filters">
		<input type="hidden" name="page" value="farazwto-newsletter">
		<label>
			<span><?php esc_html_e( 'جستجو:', 'wto' ); ?></span>
			<input type="text" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'نام یا شماره موبایل', 'wto' ); ?>">
		</label>
		<label>
			<span><?php esc_html_e( 'وضعیت:', 'wto' ); ?></span>
			<select name="status">
				<option value=""<?php selected( $status, '' ); ?>><?php esc_html_e( 'همه', 'wto' ); ?></option>
				<option value="active"<?php selected( $status, 'active' ); ?>><?php esc_html_e( 'فعال', 'wto' ); ?></option>
				<option value="unsubscribed"<?php selected( $status, 'unsubscribed' ); ?>><?php esc_html_e( 'لغو شده', 'wto' ); ?></option>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'تعداد در صفحه:', 'wto' ); ?></span>
			<select name="limit">
				<?php foreach ( array( 25, 50, 100, 200 ) as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>"<?php selected( $limit, $opt ); ?>><?php echo esc_html( (string) $opt ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'اعمال فیلتر', 'wto' ); ?></button>
		<?php
		$export_url = wp_nonce_url(
			add_query_arg( array(
				'action' => 'wto_newsletter_export',
				'status' => $status,
			), admin_url( 'admin-post.php' ) ),
			'wto_newsletter_export'
		);
		?>
		<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'دانلود CSV', 'wto' ); ?></a>
	</form>

	<form id="wto-newsletter-bulk-form" method="post">
		<?php wp_nonce_field( 'wto_newsletter_admin', 'wto_newsletter_admin_nonce' ); ?>
		<div class="wto-newsletter-bulk-bar">
			<button type="button" class="button wto-newsletter-bulk-delete" disabled><?php esc_html_e( 'حذف انتخاب‌شده‌ها', 'wto' ); ?></button>
			<span class="wto-newsletter-bulk-info"></span>
		</div>

		<table class="widefat striped wto-newsletter-table">
			<thead>
				<tr>
					<th class="check-column"><input type="checkbox" id="wto-newsletter-select-all"></th>
					<th><?php esc_html_e( 'شناسه', 'wto' ); ?></th>
					<th><?php esc_html_e( 'نام', 'wto' ); ?></th>
					<th><?php esc_html_e( 'موبایل', 'wto' ); ?></th>
					<th><?php esc_html_e( 'منبع', 'wto' ); ?></th>
					<th><?php esc_html_e( 'وضعیت', 'wto' ); ?></th>
					<th><?php esc_html_e( 'تاریخ عضویت', 'wto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'هیچ مشترکی یافت نشد.', 'wto' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$jdate = function_exists( 'wto_send_reports_to_jalali' )
						? wto_send_reports_to_jalali( $r['subscribed_at'] )
						: $r['subscribed_at'];
					$status_class = $r['status'] === 'active' ? 'success' : 'muted';
					$status_text  = $r['status'] === 'active' ? __( 'فعال', 'wto' ) : __( 'لغو شده', 'wto' );
				?>
					<tr>
						<td><input type="checkbox" class="wto-newsletter-row" value="<?php echo esc_attr( $r['id'] ); ?>"></td>
						<td><?php echo esc_html( $r['id'] ); ?></td>
						<td><?php echo esc_html( $r['name'] !== '' ? $r['name'] : '—' ); ?></td>
						<td dir="ltr"><?php echo esc_html( $r['mobile'] ); ?></td>
						<td><?php echo esc_html( $r['source'] !== '' ? $r['source'] : '—' ); ?></td>
						<td><span class="wto-status wto-status-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span></td>
						<td class="wto-newsletter-date-cell"><?php echo esc_html( $jdate ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</form>

	<?php if ( $total_pages > 1 ) :
		$base = add_query_arg( array(
			'page'   => 'farazwto-newsletter',
			'status' => $status,
			'search' => $search,
			'limit'  => $limit,
		), admin_url( 'admin.php' ) );
		?>
		<div class="wto-newsletter-pagination">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1, $base ) ); ?>">« <?php esc_html_e( 'صفحه قبل', 'wto' ); ?></a>
			<?php endif; ?>
			<span class="wto-newsletter-page-info">
				<?php printf( esc_html__( 'صفحه %1$s از %2$s', 'wto' ), esc_html( number_format_i18n( $page ) ), esc_html( number_format_i18n( $total_pages ) ) ); ?>
			</span>
			<?php if ( $page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $base ) ); ?>"><?php esc_html_e( 'صفحه بعد', 'wto' ); ?> »</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php
}

function wto_newsletter_render_send_tab() {
	$counts = wto_newsletter_get_counts();
	?>
	<div class="wto-newsletter-card">
		<h2><?php esc_html_e( 'ارسال پیامک گروهی به مشترکین خبرنامه', 'wto' ); ?></h2>
		<p class="wto-newsletter-help">
			<?php
			printf(
				/* translators: %s active subscriber count */
				esc_html__( 'تعداد مشترکین فعال: %s', 'wto' ),
				'<strong>' . esc_html( number_format_i18n( $counts['active'] ) ) . '</strong>'
			);
			?>
		</p>

		<form id="wto-newsletter-send-form" class="wto-newsletter-send-form">
			<?php wp_nonce_field( 'wto_newsletter_admin', 'wto_newsletter_admin_nonce' ); ?>

			<fieldset class="wto-newsletter-fieldset">
				<legend><?php esc_html_e( 'نوع ارسال', 'wto' ); ?></legend>
				<label><input type="radio" name="mode" value="pattern" checked> <?php esc_html_e( 'الگو (پترن)', 'wto' ); ?></label>
				<label><input type="radio" name="mode" value="simple"> <?php esc_html_e( 'متن آزاد', 'wto' ); ?></label>
			</fieldset>

			<div class="wto-newsletter-field-row wto-mode-pattern">
				<label for="wto-send-pattern"><?php esc_html_e( 'کد الگو:', 'wto' ); ?></label>
				<input id="wto-send-pattern" type="text" name="pattern_code" dir="ltr">
				<p class="wto-newsletter-help-small">
					<?php esc_html_e( 'الگو می‌تواند متغیر %name% داشته باشد. نام برند را به‌صورت ثابت در متن الگو بنویسید.', 'wto' ); ?>
				</p>
			</div>

			<div class="wto-newsletter-field-row wto-mode-simple" style="display:none">
				<label for="wto-send-text"><?php esc_html_e( 'متن پیامک:', 'wto' ); ?></label>
				<textarea id="wto-send-text" name="text" rows="4" maxlength="500"></textarea>
				<p class="wto-newsletter-help-small"><?php esc_html_e( 'ارسال متن آزاد نیازمند تأیید ناظر در پنل فراز اس‌ام‌اس است.', 'wto' ); ?></p>
			</div>

			<div class="wto-newsletter-send-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'ارسال به همه مشترکین فعال', 'wto' ); ?></button>
				<span class="wto-newsletter-send-result"></span>
			</div>
		</form>
	</div>
	<?php
}

function wto_newsletter_render_settings_tab() {
	$s = wto_newsletter_settings();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wto-newsletter-card">
		<input type="hidden" name="action" value="wto_newsletter_save_settings">
		<?php wp_nonce_field( 'wto_newsletter_settings' ); ?>

		<h2><?php esc_html_e( 'تنظیمات خبرنامه', 'wto' ); ?></h2>

		<div class="wto-newsletter-settings-grid">
			<label class="wto-newsletter-setting wto-setting-wide">
				<span><?php esc_html_e( 'فعال‌سازی فرم عضویت', 'wto' ); ?></span>
				<label class="wto-newsletter-switch">
					<input type="checkbox" class="wto-toggle" name="enabled" value="1" <?php checked( $s['enabled'], '1' ); ?>>
					<span><?php esc_html_e( 'فعال', 'wto' ); ?></span>
				</label>
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'عنوان فرم', 'wto' ); ?></span>
				<input type="text" name="form_title" value="<?php echo esc_attr( $s['form_title'] ); ?>">
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'برچسب فیلد نام', 'wto' ); ?></span>
				<input type="text" name="name_label" value="<?php echo esc_attr( $s['name_label'] ); ?>">
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'برچسب فیلد موبایل', 'wto' ); ?></span>
				<input type="text" name="mobile_label" value="<?php echo esc_attr( $s['mobile_label'] ); ?>">
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'متن دکمه', 'wto' ); ?></span>
				<input type="text" name="button_text" value="<?php echo esc_attr( $s['button_text'] ); ?>">
			</label>

			<label class="wto-newsletter-setting wto-setting-wide">
				<span><?php esc_html_e( 'توضیح زیر عنوان', 'wto' ); ?></span>
				<input type="text" name="form_description" value="<?php echo esc_attr( $s['form_description'] ); ?>">
			</label>

			<label class="wto-newsletter-setting wto-setting-wide">
				<span><?php esc_html_e( 'پیام موفقیت', 'wto' ); ?></span>
				<input type="text" name="success_message" value="<?php echo esc_attr( $s['success_message'] ); ?>">
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'پیام تکراری', 'wto' ); ?></span>
				<input type="text" name="duplicate_message" value="<?php echo esc_attr( $s['duplicate_message'] ); ?>">
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'پیام خطا', 'wto' ); ?></span>
				<input type="text" name="error_message" value="<?php echo esc_attr( $s['error_message'] ); ?>">
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'کد الگوی خوش‌آمدگویی (اختیاری)', 'wto' ); ?></span>
				<input type="text" name="welcome_pattern_code" value="<?php echo esc_attr( $s['welcome_pattern_code'] ); ?>" dir="ltr">
				<small class="wto-newsletter-help-small">
					<?php esc_html_e( 'متغیر قابل استفاده در متن الگو:', 'wto' ); ?>
					<code dir="ltr">%name%</code>
					<br><?php esc_html_e( '⚠ نام برند فروشگاه را به‌صورت ثابت در متن الگو بنویسید (نه به‌صورت متغیر) تا پنل فراز الگو را تأیید کند.', 'wto' ); ?>
				</small>
			</label>

			<label class="wto-newsletter-setting">
				<span><?php esc_html_e( 'لینک ریدایرکت بعد از عضویت (اختیاری)', 'wto' ); ?></span>
				<input type="url" name="redirect_url" value="<?php echo esc_attr( $s['redirect_url'] ); ?>" dir="ltr" placeholder="https://...">
			</label>
		</div>

		<div class="wto-newsletter-shortcode-help">
			<strong><?php esc_html_e( 'شورت‌کد نمایش فرم در پست/صفحه/سایدبار:', 'wto' ); ?></strong>
			<code dir="ltr">[wto_newsletter]</code>
			<p class="wto-newsletter-help-small">
				<?php esc_html_e( 'برای نمایش در سایدبار یا فوتر می‌توانید از ویجت «خبرنامه فراز اس‌ام‌اس» در', 'wto' ); ?>
				<a href="<?php echo esc_url( admin_url( 'widgets.php' ) ); ?>"><?php esc_html_e( 'ابزارک‌ها', 'wto' ); ?></a>
				<?php esc_html_e( 'استفاده کنید.', 'wto' ); ?>
			</p>
		</div>

		<div class="wto-newsletter-save-row">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تنظیمات', 'wto' ); ?></button>
		</div>
	</form>
	<?php
}

function wto_newsletter_render_inline_styles() {
	$nonce_admin = wp_create_nonce( 'wto_newsletter_admin' );
	?>
	<style>
	/* v3.17.1: Modern card-based design — هماهنگ با ROI/Birthday/Comments
	   تمام class name های قبلی حفظ شده‌اند تا JS و markup نشکنند.
	   v3.17.5: font-family صریح — اگر inherit بگذاریم CSS بعدی override می‌شود. */
	.wto-newsletter-wrapper { direction: rtl; font-family: IRANSans, Tahoma, sans-serif; }
	.wto-newsletter-wrapper * { box-sizing: border-box; }

	/* Hero عنوان صفحه — به جای h1 لخت */
	.wto-newsletter-wrapper .wto-newsletter-title-main {
		background: linear-gradient(135deg, #4338ca 0%, #7c3aed 100%);
		color: #fff;
		margin: 6px 0 20px;
		padding: 22px 28px;
		border-radius: 14px;
		font-size: 20px;
		font-weight: 800;
		box-shadow: 0 8px 24px rgba(67, 56, 202, 0.18);
		position: relative;
		overflow: hidden;
	}
	.wto-newsletter-wrapper .wto-newsletter-title-main::before {
		content: '📨';
		position: absolute;
		top: -8px;
		left: -8px;
		font-size: 110px;
		opacity: 0.08;
		line-height: 1;
	}

	/* تب‌های pill-style */
	.wto-newsletter-wrapper .wto-newsletter-tabs {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
		border-bottom: 0;
		margin: 0 0 18px;
	}
	.wto-newsletter-wrapper .wto-newsletter-tab {
		padding: 9px 20px;
		text-decoration: none;
		color: #475569;
		background: #fff;
		border: 1px solid #cbd5e1;
		border-radius: 8px;
		font-size: 13px;
		font-weight: 600;
		transition: all .15s;
	}
	.wto-newsletter-wrapper .wto-newsletter-tab:hover {
		border-color: #94a3b8;
		color: #0f172a;
	}
	.wto-newsletter-wrapper .wto-newsletter-tab.is-active {
		background: #0f172a;
		color: #fff;
		border-color: #0f172a;
		box-shadow: 0 4px 12px rgba(15, 23, 42, 0.22);
	}

	/* کارت‌های stat */
	.wto-newsletter-wrapper .wto-newsletter-stats {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 14px;
		margin: 0 0 22px;
	}
	.wto-newsletter-wrapper .wto-newsletter-stat {
		background: #fff;
		padding: 18px 16px;
		border: 1.5px solid #e5e7eb;
		border-radius: 12px;
		text-align: center;
		transition: transform .15s, border-color .15s;
	}
	.wto-newsletter-wrapper .wto-newsletter-stat:hover {
		transform: translateY(-2px);
		border-color: #c7d2fe;
	}
	.wto-newsletter-wrapper .wto-newsletter-stat .num {
		font-size: 28px;
		font-weight: 800;
		line-height: 1;
		color: #0f172a;
		letter-spacing: -0.5px;
	}
	.wto-newsletter-wrapper .wto-newsletter-stat .num.wto-num-success { color: #16a34a; }
	.wto-newsletter-wrapper .wto-newsletter-stat .num.wto-num-info    { color: #4338ca; }
	.wto-newsletter-wrapper .wto-newsletter-stat .num.wto-num-muted   { color: #64748b; }
	.wto-newsletter-wrapper .wto-newsletter-stat .lbl {
		color: #64748b;
		font-size: 12px;
		font-weight: 600;
		margin-top: 8px;
	}

	/* فیلتر بار */
	.wto-newsletter-wrapper .wto-newsletter-filters {
		background: #fff;
		padding: 16px 18px;
		border: 1px solid #e5e7eb;
		border-radius: 12px;
		margin: 0 0 14px;
		display: flex;
		flex-wrap: wrap;
		gap: 14px;
		align-items: flex-end;
	}
	.wto-newsletter-wrapper .wto-newsletter-filters label {
		display: flex;
		flex-direction: column;
		gap: 5px;
		font-size: 12px;
		color: #475569;
		font-weight: 600;
	}
	.wto-newsletter-wrapper .wto-newsletter-filters input[type="text"],
	.wto-newsletter-wrapper .wto-newsletter-filters select {
		min-width: 160px;
		padding: 8px 12px;
		border: 1px solid #cbd5e1;
		border-radius: 7px;
		font-size: 13px;
		color: #0f172a;
		background: #fff;
		transition: border-color .15s, box-shadow .15s;
	}
	.wto-newsletter-wrapper .wto-newsletter-filters input[type="text"]:focus,
	.wto-newsletter-wrapper .wto-newsletter-filters select:focus {
		outline: 0;
		border-color: #4338ca;
		box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.12);
	}

	/* OVERRIDE دکمه‌های WP در این صفحه — کلید پایان دادن به ظاهر "ویندوز ۹۸" */
	.wto-newsletter-wrapper .button,
	.wto-newsletter-wrapper button.button {
		background: #fff !important;
		color: #475569 !important;
		border: 1px solid #cbd5e1 !important;
		border-radius: 7px !important;
		padding: 8px 16px !important;
		font-size: 12.5px !important;
		font-weight: 600 !important;
		min-height: auto !important;
		line-height: 1.4 !important;
		text-shadow: none !important;
		box-shadow: none !important;
		cursor: pointer !important;
		transition: all .15s !important;
	}
	.wto-newsletter-wrapper .button:hover,
	.wto-newsletter-wrapper button.button:hover {
		background: #f8fafc !important;
		border-color: #94a3b8 !important;
		color: #0f172a !important;
	}
	.wto-newsletter-wrapper .button-primary,
	.wto-newsletter-wrapper button.button-primary {
		background: #4338ca !important;
		color: #fff !important;
		border: 1px solid #4338ca !important;
		box-shadow: 0 4px 12px rgba(67, 56, 202, 0.22) !important;
	}
	.wto-newsletter-wrapper .button-primary:hover,
	.wto-newsletter-wrapper button.button-primary:hover {
		background: #3730a3 !important;
		border-color: #3730a3 !important;
		color: #fff !important;
	}
	.wto-newsletter-wrapper .button[disabled],
	.wto-newsletter-wrapper button[disabled] {
		opacity: 0.5 !important;
		cursor: not-allowed !important;
	}

	/* bulk bar */
	.wto-newsletter-wrapper .wto-newsletter-bulk-bar {
		margin: 12px 0;
		display: flex;
		align-items: center;
		gap: 12px;
	}
	.wto-newsletter-wrapper .wto-newsletter-bulk-info {
		color: #475569;
		font-size: 12.5px;
		font-weight: 600;
	}

	/* جدول — کاملاً override روی widefat striped */
	.wto-newsletter-wrapper .wto-newsletter-table {
		background: #fff;
		border: 1px solid #e5e7eb !important;
		border-radius: 12px !important;
		overflow: hidden;
		box-shadow: none !important;
		margin-bottom: 18px;
		width: 100%;
		border-collapse: separate !important;
		border-spacing: 0;
	}
	.wto-newsletter-wrapper .wto-newsletter-table thead {
		background: #f8fafc !important;
	}
	.wto-newsletter-wrapper .wto-newsletter-table thead th {
		background: #f8fafc !important;
		color: #0f172a !important;
		font-weight: 700 !important;
		font-size: 12.5px !important;
		text-align: right !important;
		padding: 13px 16px !important;
		border-bottom: 1px solid #e5e7eb !important;
		border-top: 0 !important;
	}
	.wto-newsletter-wrapper .wto-newsletter-table tbody td {
		padding: 12px 16px !important;
		vertical-align: middle !important;
		font-size: 12.5px !important;
		color: #1f2937 !important;
		border-bottom: 1px solid #f1f5f9 !important;
		background: #fff !important;
	}
	.wto-newsletter-wrapper .wto-newsletter-table tbody tr:last-child td {
		border-bottom: 0 !important;
	}
	.wto-newsletter-wrapper .wto-newsletter-table tbody tr:hover td {
		background: #f8fafc !important;
	}
	.wto-newsletter-wrapper .wto-newsletter-table input[type="checkbox"] {
		margin: 0;
	}
	.wto-newsletter-wrapper .wto-newsletter-date-cell {
		direction: ltr;
		font-variant-numeric: tabular-nums;
		color: #64748b !important;
		font-size: 12px !important;
		text-align: right !important;
	}

	/* status pills */
	.wto-newsletter-wrapper .wto-status {
		display: inline-block;
		padding: 3px 11px;
		border-radius: 14px;
		font-size: 11.5px;
		font-weight: 600;
	}
	.wto-newsletter-wrapper .wto-status-success { background: #dcfce7; color: #166534; }
	.wto-newsletter-wrapper .wto-status-muted   { background: #f1f5f9; color: #64748b; }

	/* pagination */
	.wto-newsletter-wrapper .wto-newsletter-pagination {
		display: flex;
		gap: 8px;
		align-items: center;
		justify-content: center;
		padding: 12px;
		background: #f8fafc;
		border-top: 1px solid #e5e7eb;
		border-radius: 0 0 12px 12px;
	}
	.wto-newsletter-wrapper .wto-newsletter-page-info {
		color: #475569;
		font-size: 12.5px;
		font-weight: 600;
	}

	/* کارت اصلی برای send/settings tab */
	.wto-newsletter-wrapper .wto-newsletter-card {
		background: #fff;
		padding: 24px 28px;
		border: 1.5px solid #e5e7eb;
		border-radius: 14px;
		max-width: none;
		box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
	}
	.wto-newsletter-wrapper .wto-newsletter-card h2 {
		margin: 0 0 12px;
		font-size: 17px;
		font-weight: 700;
		color: #0f172a;
		display: flex;
		align-items: center;
		gap: 8px;
	}
	.wto-newsletter-wrapper .wto-newsletter-help {
		color: #475569;
		margin: 0 0 18px;
		font-size: 13px;
		line-height: 1.8;
		background: #f8fafc;
		padding: 10px 14px;
		border-radius: 8px;
		border-right: 3px solid #4338ca;
	}
	.wto-newsletter-wrapper .wto-newsletter-help-small {
		color: #64748b;
		font-size: 11.5px;
		margin: 6px 0 0;
		line-height: 1.7;
	}
	.wto-newsletter-wrapper .wto-newsletter-help-small code {
		background: #f1f5f9;
		padding: 1px 6px;
		border-radius: 4px;
		direction: ltr;
		display: inline-block;
		font-family: Menlo, Consolas, monospace;
	}

	/* fieldset برای radio mode */
	.wto-newsletter-wrapper .wto-newsletter-fieldset {
		border: 1.5px solid #c7d2fe;
		border-radius: 10px;
		padding: 14px 18px;
		margin: 0 0 18px;
		background: #fafbff;
	}
	.wto-newsletter-wrapper .wto-newsletter-fieldset legend {
		padding: 2px 10px;
		font-weight: 700;
		font-size: 12.5px;
		color: #4338ca;
		background: #eef2ff;
		border-radius: 5px;
	}
	.wto-newsletter-wrapper .wto-newsletter-fieldset label {
		margin-left: 20px;
		font-size: 13px;
		color: #1f2937;
		display: inline-flex;
		align-items: center;
		gap: 6px;
		cursor: pointer;
	}

	/* فیلدهای ورودی فرم */
	.wto-newsletter-wrapper .wto-newsletter-field-row { margin: 0 0 16px; }
	.wto-newsletter-wrapper .wto-newsletter-field-row label {
		display: block;
		margin-bottom: 7px;
		font-weight: 600;
		font-size: 13px;
		color: #1f2937;
	}
	.wto-newsletter-wrapper .wto-newsletter-field-row input[type="text"],
	.wto-newsletter-wrapper .wto-newsletter-field-row textarea {
		width: 100%;
		padding: 10px 12px;
		box-sizing: border-box;
		border: 1px solid #cbd5e1;
		border-radius: 8px;
		font-size: 13.5px;
		font-family: inherit;
		line-height: 1.7;
		transition: border-color .15s, box-shadow .15s;
	}
	.wto-newsletter-wrapper .wto-newsletter-field-row input[type="text"]:focus,
	.wto-newsletter-wrapper .wto-newsletter-field-row textarea:focus {
		outline: 0;
		border-color: #4338ca;
		box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.12);
	}

	/* اکشن‌ها در send tab */
	.wto-newsletter-wrapper .wto-newsletter-send-actions {
		display: flex;
		align-items: center;
		gap: 14px;
		flex-wrap: wrap;
		padding-top: 8px;
	}
	.wto-newsletter-wrapper .wto-newsletter-send-result {
		font-size: 13px;
		font-weight: 600;
	}
	.wto-newsletter-wrapper .wto-newsletter-send-result.success { color: #16a34a; }
	.wto-newsletter-wrapper .wto-newsletter-send-result.error   { color: #b91c1c; }

	/* تنظیمات گرید */
	.wto-newsletter-wrapper .wto-newsletter-settings-grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 16px 20px;
		margin: 14px 0 22px;
	}
	.wto-newsletter-wrapper .wto-newsletter-setting {
		display: flex;
		flex-direction: column;
		gap: 6px;
		font-size: 13px;
	}
	.wto-newsletter-wrapper .wto-newsletter-setting > span {
		font-weight: 600;
		color: #1f2937;
	}
	.wto-newsletter-wrapper .wto-newsletter-setting.wto-setting-wide { grid-column: 1 / -1; }
	.wto-newsletter-wrapper .wto-newsletter-setting input[type="text"],
	.wto-newsletter-wrapper .wto-newsletter-setting input[type="url"] {
		padding: 9px 12px;
		border: 1px solid #cbd5e1;
		border-radius: 8px;
		font-size: 13px;
		transition: border-color .15s, box-shadow .15s;
	}
	.wto-newsletter-wrapper .wto-newsletter-setting input[type="text"]:focus,
	.wto-newsletter-wrapper .wto-newsletter-setting input[type="url"]:focus {
		outline: 0;
		border-color: #4338ca;
		box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.12);
	}
	.wto-newsletter-wrapper .wto-newsletter-switch {
		display: inline-flex;
		align-items: center;
		gap: 10px;
		font-size: 13px;
		background: #f8fafc;
		padding: 8px 14px;
		border-radius: 8px;
		border: 1px solid #e5e7eb;
	}

	/* راهنمای shortcode */
	.wto-newsletter-wrapper .wto-newsletter-shortcode-help {
		background: linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);
		border: 1.5px solid #fde68a;
		border-radius: 10px;
		padding: 14px 18px;
		margin: 18px 0;
		color: #713f12;
		font-size: 12.5px;
		line-height: 1.8;
	}
	.wto-newsletter-wrapper .wto-newsletter-shortcode-help strong { color: #713f12; }
	.wto-newsletter-wrapper .wto-newsletter-shortcode-help code {
		background: #fff;
		padding: 4px 10px;
		border-radius: 5px;
		border: 1px solid #fcd34d;
		margin-right: 6px;
		font-family: Menlo, Consolas, monospace;
		color: #92400e;
		direction: ltr;
		display: inline-block;
	}
	.wto-newsletter-wrapper .wto-newsletter-shortcode-help a {
		color: #92400e;
		text-decoration: underline;
		font-weight: 600;
	}
	.wto-newsletter-wrapper .wto-newsletter-save-row {
		margin-top: 14px;
		padding-top: 14px;
		border-top: 1px solid #f1f5f9;
		display: flex;
		justify-content: flex-end;
		gap: 10px;
	}

	/* notice موفقیت — کاملاً override روی WP defaults */
	.wto-newsletter-wrapper .notice {
		background: #dcfce7 !important;
		border: 1px solid #86efac !important;
		border-right: 3px solid #16a34a !important;
		border-left-width: 1px !important;
		padding: 10px 16px !important;
		border-radius: 8px !important;
		color: #166534 !important;
		margin: 0 0 16px !important;
	}
	.wto-newsletter-wrapper .notice p { margin: 0; }

	@media (max-width: 720px) {
		.wto-newsletter-wrapper .wto-newsletter-stats { grid-template-columns: repeat(2, 1fr); }
		.wto-newsletter-wrapper .wto-newsletter-settings-grid { grid-template-columns: 1fr; }
		.wto-newsletter-wrapper .wto-newsletter-filters input[type="text"],
		.wto-newsletter-wrapper .wto-newsletter-filters select { min-width: 100%; }
	}
	</style>
	<script>
	(function(){
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce_admin ); ?>;

		// Bulk select + delete on the list tab.
		var selectAll = document.getElementById('wto-newsletter-select-all');
		var rows = document.querySelectorAll('.wto-newsletter-row');
		var deleteBtn = document.querySelector('.wto-newsletter-bulk-delete');
		var bulkInfo = document.querySelector('.wto-newsletter-bulk-info');
		function updateBulkState(){
			var checked = Array.prototype.filter.call(rows, function(c){ return c.checked; });
			if (deleteBtn) {
				deleteBtn.disabled = checked.length === 0;
				if (bulkInfo) {
					bulkInfo.textContent = checked.length ? (checked.length + ' ردیف انتخاب شده') : '';
				}
			}
		}
		if (selectAll) {
			selectAll.addEventListener('change', function(){
				Array.prototype.forEach.call(rows, function(c){ c.checked = selectAll.checked; });
				updateBulkState();
			});
		}
		Array.prototype.forEach.call(rows, function(c){ c.addEventListener('change', updateBulkState); });
		if (deleteBtn) {
			deleteBtn.addEventListener('click', function(){
				var checked = Array.prototype.filter.call(rows, function(c){ return c.checked; });
				if (checked.length === 0) return;
				if (!confirm('حذف ' + checked.length + ' مشترک — مطمئن هستید؟')) return;
				var data = new FormData();
				data.append('action', 'wto_newsletter_delete');
				data.append('nonce', nonce);
				checked.forEach(function(c){ data.append('ids[]', c.value); });
				deleteBtn.disabled = true;
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function(r){ return r.json(); })
					.then(function(json){
						if (json.success) {
							window.location.reload();
						} else {
							alert((json.data && json.data.message) || 'خطا در حذف.');
							deleteBtn.disabled = false;
						}
					})
					.catch(function(){ alert('خطا در ارتباط با سرور.'); deleteBtn.disabled = false; });
			});
		}

		// Send tab — mode toggle + AJAX submit.
		var modeRadios = document.querySelectorAll('#wto-newsletter-send-form input[name="mode"]');
		var modePattern = document.querySelector('#wto-newsletter-send-form .wto-mode-pattern');
		var modeSimple  = document.querySelector('#wto-newsletter-send-form .wto-mode-simple');
		Array.prototype.forEach.call(modeRadios, function(r){
			r.addEventListener('change', function(){
				if (modePattern) modePattern.style.display = (r.value === 'pattern' && r.checked) ? '' : 'none';
				if (modeSimple)  modeSimple.style.display  = (r.value === 'simple'  && r.checked) ? '' : 'none';
			});
		});
		var sendForm = document.getElementById('wto-newsletter-send-form');
		if (sendForm) {
			sendForm.addEventListener('submit', function(ev){
				ev.preventDefault();
				if (!confirm('ارسال پیامک به همه مشترکین فعال — آیا مطمئن هستید؟')) return;
				var resultEl = sendForm.querySelector('.wto-newsletter-send-result');
				var btn = sendForm.querySelector('button[type="submit"]');
				var fd = new FormData(sendForm);
				fd.append('action', 'wto_newsletter_send_bulk');
				fd.append('nonce', nonce);
				btn.disabled = true;
				resultEl.className = 'wto-newsletter-send-result';
				resultEl.textContent = 'در حال ارسال...';
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(json){
						resultEl.className = 'wto-newsletter-send-result ' + (json.success ? 'success' : 'error');
						resultEl.textContent = (json.data && json.data.message) || 'انجام شد.';
					})
					.catch(function(){ resultEl.className = 'wto-newsletter-send-result error'; resultEl.textContent = 'خطا در ارتباط با سرور.'; })
					.then(function(){ btn.disabled = false; });
			});
		}
	})();
	</script>
	<?php
}
