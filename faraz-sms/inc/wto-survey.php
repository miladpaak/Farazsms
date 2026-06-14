<?php
/**
 * نظرسنجی پس از خرید — Phase 5
 *
 * این ماژول جایگزین تنظیمات قدیمی «نظرسنجی» می‌شود و قابلیت‌های زیر را اضافه می‌کند:
 *
 *   - رابط سه‌تب جدید (داشبورد، تنظیمات، آخرین نظرات)
 *   - راهنمای کامل + لیست متغیرهای الگو
 *   - دکمه «ساخت الگو از روی متن» (POST به /sms/pattern)
 *   - دکمه «ارسال پیامک تست» به موبایل مدیر
 *   - جدول DB برای ردیابی پیامک‌های ارسال‌شده + نظرات ثبت‌شده
 *   - آمار: تعداد پیامک، تعداد نظر، در انتظار تأیید، نرخ تبدیل
 *
 * این فایل از تابع‌های موجود استفاده می‌کند:
 *   - wto_send_scheduled_sms()       — در wto-sms-api.php (ارسال پیامک scheduled به API)
 *   - wto_create_pattern()           — در wto-sms-api.php
 *   - wto_send_pattern_sms_raw()     — در wto-sms-api.php (برای تست)
 *
 * Submenu: همان `farazwto-poll` (در wto-settings.php ثبت شده) — تابع `wto_admin_poll_page`
 *           اگر این فایل بارگذاری شود، dispatch به wto_render_survey_page می‌شود.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// ============================================================================
// Schema — جدول لاگ نظرسنجی
// ============================================================================

const WTO_SURVEY_DB_VERSION        = '1.0.0';
const WTO_SURVEY_DB_VERSION_OPTION = 'wto_survey_db_version';

function wto_survey_table() {
	global $wpdb;
	return $wpdb->prefix . 'wto_survey_log';
}

function wto_survey_maybe_setup_table() {
	if ( get_option( WTO_SURVEY_DB_VERSION_OPTION ) === WTO_SURVEY_DB_VERSION ) {
		return;
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	global $wpdb;
	$table           = wto_survey_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		order_id BIGINT(20) UNSIGNED NOT NULL,
		mobile VARCHAR(20) NOT NULL DEFAULT '',
		first_name VARCHAR(120) NOT NULL DEFAULT '',
		last_name VARCHAR(120) NOT NULL DEFAULT '',
		scheduled_for DATETIME NULL,
		dispatched_at DATETIME NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
		reviews_count INT UNSIGNED NOT NULL DEFAULT 0,
		first_review_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY order_id (order_id),
		KEY status (status),
		KEY dispatched_at (dispatched_at)
	) $charset_collate;";
	dbDelta( $sql );
	update_option( WTO_SURVEY_DB_VERSION_OPTION, WTO_SURVEY_DB_VERSION, false );
}
add_action( 'admin_init', 'wto_survey_maybe_setup_table' );

// ============================================================================
// Helpers
// ============================================================================

/**
 * Persian labels for the supported pattern variables. Shown both in the
 * settings UI guide and used as documentation for the user.
 *
 * @return array<string,string>
 */
function wto_survey_variables() {
	return array(
		'full_name'   => __( 'نام و نام خانوادگی کامل', 'wto' ),
		'name'        => __( 'نام', 'wto' ),
		'family'      => __( 'نام خانوادگی', 'wto' ),
		'order_id'    => __( 'شماره سفارش', 'wto' ),
		'review_url'  => __( 'لینک ثبت نظرسنجی (با شناسه سفارش)', 'wto' ),
	);
}

/**
 * Default pattern template — used in the "ساخت الگو" wizard so the admin
 * doesn't start from a blank textarea.
 *
 * @return string
 */
function wto_survey_default_template() {
	// نکته مهم برای تأیید الگو: نام برند فروشگاه باید به‌صورت ثابت در متن نوشته
	// شود (نه به‌صورت متغیر) — در غیر این صورت پنل فراز الگو را تأیید نمی‌کند.
	// خط آخر «نام فروشگاه شما» را با نام واقعی برند خود جایگزین کنید.
	return "%full_name% گرامی\n" .
		"از خریدتان ممنون هستیم.\n" .
		"لطفاً نظرتان درباره محصولات این سفارش را با ما در میان بگذارید:\n" .
		"%review_url%\n\n" .
		"نام فروشگاه شما";
}

/**
 * Return the permalink of the order-review page (the one created on plugin activation).
 *
 * @return string
 */
function wto_survey_review_page_url() {
	$cached = get_transient( 'wto_survey_review_page_url' );
	if ( $cached !== false ) {
		return (string) $cached;
	}
	$url = '';
	$query = new WP_Query( array(
		'post_type'              => 'page',
		'title'                  => 'orderreview',
		'posts_per_page'         => 1,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'fields'                 => 'ids',
	) );
	if ( ! empty( $query->posts ) ) {
		$url = (string) get_permalink( (int) $query->posts[0] );
	}
	if ( $url === '' ) {
		// Fallback to the pretty permalink shape the activation hook creates.
		$url = home_url( '/orderreview/' );
	}
	set_transient( 'wto_survey_review_page_url', $url, HOUR_IN_SECONDS );
	return $url;
}

/**
 * Build the per-order review URL (used as %review_url% in the pattern).
 *
 * @param int $order_id
 * @return string
 */
function wto_survey_build_review_url( $order_id ) {
	$base = wto_survey_review_page_url();
	return add_query_arg( 'order_id', (int) $order_id, $base );
}

/**
 * Record a scheduled survey SMS in the log table. Called from
 * wto_send_scheduled_sms() right after we POST to the FarazSMS API.
 *
 * @param int    $order_id
 * @param string $mobile
 * @param string $first_name
 * @param string $last_name
 * @param string $scheduled_for  e.g. '2026-06-10T15:00:00' (the API schedule field)
 * @param bool   $api_success
 */
function wto_survey_log_dispatch( $order_id, $mobile, $first_name, $last_name, $scheduled_for, $api_success ) {
	global $wpdb;
	$table = wto_survey_table();
	$now   = current_time( 'mysql' );
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE order_id = %d LIMIT 1", $order_id ), ARRAY_A );
	$data  = array(
		'mobile'         => (string) $mobile,
		'first_name'     => (string) $first_name,
		'last_name'      => (string) $last_name,
		'scheduled_for'  => $scheduled_for !== '' ? gmdate( 'Y-m-d H:i:s', strtotime( $scheduled_for ) ) : null,
		'dispatched_at'  => $now,
		'status'         => $api_success ? 'scheduled' : 'failed',
	);
	if ( $row ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
	} else {
		$data['order_id'] = (int) $order_id;
		$wpdb->insert( $table, $data );
	}
}

/**
 * Bump review counter when a review is submitted via the [order_review] shortcode.
 * Hooked on comment_post.
 *
 * @param int   $comment_id
 * @param int   $approved
 * @param array $commentdata
 */
function wto_survey_count_review_submission( $comment_id, $approved, $commentdata ) {
	$order_id = (int) get_comment_meta( $comment_id, 'order_id', true );
	if ( $order_id <= 0 ) {
		return;
	}
	global $wpdb;
	$table = wto_survey_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, reviews_count, first_review_at FROM $table WHERE order_id = %d LIMIT 1", $order_id ), ARRAY_A );
	$now   = current_time( 'mysql' );
	if ( $row ) {
		$wpdb->update(
			$table,
			array(
				'reviews_count'   => (int) $row['reviews_count'] + 1,
				'first_review_at' => $row['first_review_at'] ? $row['first_review_at'] : $now,
			),
			array( 'id' => (int) $row['id'] )
		);
	}
}
add_action( 'comment_post', 'wto_survey_count_review_submission', 30, 3 );

/**
 * Stats for the dashboard. Returns aggregates over the last $days.
 *
 * @param int $days
 * @return array
 */
function wto_survey_get_stats( $days = 30 ) {
	global $wpdb;
	$table  = wto_survey_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	$sms_sent     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE dispatched_at >= %s AND status IN ('scheduled','sent')", $cutoff ) );
	$sms_failed   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE dispatched_at >= %s AND status = %s", $cutoff, 'failed' ) );
	$with_review  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE dispatched_at >= %s AND reviews_count > 0", $cutoff ) );
	$total_reviews= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(reviews_count),0) FROM $table WHERE dispatched_at >= %s", $cutoff ) );

	// Reviews in moderation: count comments with status 0 that have an `order_id` meta.
	$pending = (int) $wpdb->get_var(
		"SELECT COUNT(c.comment_ID)
		 FROM {$wpdb->comments} c
		 INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID
		 WHERE c.comment_approved = '0' AND m.meta_key = 'order_id'"
	);

	$rate = $sms_sent > 0 ? round( ( $with_review / $sms_sent ) * 100, 1 ) : 0;

	return array(
		'sms_sent'      => $sms_sent,
		'sms_failed'    => $sms_failed,
		'with_review'   => $with_review,
		'total_reviews' => $total_reviews,
		'pending'       => $pending,
		'rate'          => $rate,
	);
}

// ============================================================================
// AJAX — Create pattern + send test SMS
// ============================================================================

add_action( 'wp_ajax_wto_survey_create_pattern', 'wto_survey_ajax_create_pattern' );
function wto_survey_ajax_create_pattern() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_survey_admin', 'nonce' );

	$message = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
	$message = trim( (string) $message );
	if ( $message === '' ) {
		wp_send_json_error( array( 'message' => __( 'متن الگو خالی است.', 'wto' ) ) );
	}

	if ( ! function_exists( 'wto_create_pattern' ) ) {
		wp_send_json_error( array( 'message' => __( 'تابع ساخت الگو در دسترس نیست.', 'wto' ) ) );
	}

	// `wto_create_pattern` returns a JSON string per its existing contract.
	$json     = wto_create_pattern( $message, 255 ); // 255 = "others"
	$response = is_string( $json ) ? json_decode( $json, true ) : ( is_array( $json ) ? $json : null );
	if ( ! is_array( $response ) ) {
		wp_send_json_error( array( 'message' => __( 'پاسخ API قابل پردازش نیست.', 'wto' ) ) );
	}

	if ( isset( $response['status'] ) && $response['status'] === 'error' ) {
		$msg = isset( $response['message'] ) ? (string) $response['message'] : __( 'خطای API در ساخت الگو.', 'wto' );
		wp_send_json_error( array( 'message' => $msg ) );
	}

	// Try to extract the new pattern code from various response shapes.
	$pattern_code = '';
	$candidates = array();
	if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
		$candidates[] = $response['data'];
		if ( isset( $response['data']['pattern'] ) && is_array( $response['data']['pattern'] ) ) {
			$candidates[] = $response['data']['pattern'];
		}
	}
	$candidates[] = $response;
	foreach ( $candidates as $node ) {
		foreach ( array( 'code', 'pattern_code', 'pattern', 'id' ) as $k ) {
			if ( isset( $node[ $k ] ) && is_scalar( $node[ $k ] ) && (string) $node[ $k ] !== '' ) {
				$pattern_code = (string) $node[ $k ];
				break 2;
			}
		}
	}

	if ( $pattern_code === '' ) {
		wp_send_json_error( array( 'message' => __( 'الگو ساخته شد ولی کد آن از پاسخ API یافت نشد.', 'wto' ) ) );
	}

	update_option( 'wto_poll_pattern', $pattern_code, false );
	wp_send_json_success( array(
		'message'      => __( 'الگو با موفقیت ساخته و در تنظیمات ذخیره شد.', 'wto' ),
		'pattern_code' => $pattern_code,
	) );
}

add_action( 'wp_ajax_wto_survey_send_test', 'wto_survey_ajax_send_test' );
function wto_survey_ajax_send_test() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'wto' ) ), 403 );
	}
	check_ajax_referer( 'wto_survey_admin', 'nonce' );

	$mobile = isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '';
	if ( function_exists( 'wto_newsletter_normalize_mobile' ) ) {
		$mobile = wto_newsletter_normalize_mobile( $mobile );
	}
	if ( $mobile === '' ) {
		wp_send_json_error( array( 'message' => __( 'شماره موبایل معتبر نیست.', 'wto' ) ) );
	}

	$pattern = trim( (string) get_option( 'wto_poll_pattern', '' ) );
	if ( $pattern === '' ) {
		wp_send_json_error( array( 'message' => __( 'ابتدا کد الگو را تنظیم کنید.', 'wto' ) ) );
	}
	if ( ! function_exists( 'wto_send_pattern_sms_raw' ) ) {
		wp_send_json_error( array( 'message' => __( 'تابع ارسال الگو در دسترس نیست.', 'wto' ) ) );
	}

	$sender = get_option( 'wto_sender', '' );
	$attrs  = array(
		'name'       => 'تست',
		'family'     => 'فراز',
		'full_name'  => 'تست فراز',
		'order_id'   => '0',
		'review_url' => wto_survey_build_review_url( 0 ),
		'sitename'   => get_bloginfo( 'name' ),
	);
	$result = wto_send_pattern_sms_raw( $mobile, $pattern, $attrs, $sender );
	if ( $result === 'success' ) {
		wp_send_json_success( array( 'message' => __( 'پیامک تست ارسال شد.', 'wto' ) ) );
	}
	$msg = is_string( $result ) && $result !== '' ? $result : __( 'ارسال ناموفق.', 'wto' );
	wp_send_json_error( array( 'message' => $msg ) );
}

add_action( 'admin_post_wto_survey_save_settings', 'wto_survey_handle_save_settings' );
function wto_survey_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'دسترسی غیرمجاز.', 'wto' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wto_survey_settings' );
	$enabled = isset( $_POST['send_poll_sms'] ) && $_POST['send_poll_sms'] === '1' ? '1' : '0';
	update_option( 'wto_send_poll_sms', $enabled, false );
	update_option( 'wto_poll_pattern', isset( $_POST['wto_poll_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['wto_poll_pattern'] ) ) : '', false );
	update_option( 'wto_send_time',    isset( $_POST['send_time'] )       ? max( 0, (int) $_POST['send_time'] ) : 0, false );
	update_option( 'wto_send_status',  isset( $_POST['send_status'] )     ? sanitize_text_field( wp_unslash( $_POST['send_status'] ) ) : '', false );
	wp_safe_redirect( add_query_arg( array(
		'page'    => 'farazwto-poll',
		'tab'     => 'settings',
		'updated' => '1',
	), admin_url( 'admin.php' ) ) );
	exit;
}

// ============================================================================
// Admin page render — replaces the legacy wto_admin_poll_page
// ============================================================================

function wto_render_survey_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی غیرمجاز.', 'wto' ) );
	}
	wto_survey_maybe_setup_table();

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
	$tab = in_array( $tab, array( 'dashboard', 'settings', 'reviews' ), true ) ? $tab : 'dashboard';

	echo '<section class="wrapper wto-survey-wrapper">';
	wto_survey_render_header();
	wto_survey_render_tabs( $tab );
	switch ( $tab ) {
		case 'settings':
			wto_survey_render_settings_tab();
			break;
		case 'reviews':
			wto_survey_render_reviews_tab();
			break;
		default:
			wto_survey_render_dashboard_tab();
	}
	wto_survey_render_inline();
	echo '</section>';
}

function wto_survey_render_header() {
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
	<h1 class="wto-survey-title-main"><?php esc_html_e( 'نظرسنجی پس از خرید', 'wto' ); ?></h1>
	<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'این قابلیت نیازمند ووکامرس است.', 'wto' ); ?></p></div>
	<?php endif; ?>
	<?php
}

function wto_survey_render_tabs( $active ) {
	$tabs = array(
		'dashboard' => __( 'داشبورد آماری', 'wto' ),
		'settings'  => __( 'تنظیمات', 'wto' ),
		'reviews'   => __( 'آخرین نظرات', 'wto' ),
	);
	?>
	<nav class="wto-survey-tabs">
		<?php foreach ( $tabs as $key => $label ) :
			$url = add_query_arg( array( 'page' => 'farazwto-poll', 'tab' => $key ), admin_url( 'admin.php' ) );
		?>
			<a class="wto-survey-tab <?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
}

function wto_survey_render_dashboard_tab() {
	$days = isset( $_GET['days'] ) ? max( 1, (int) $_GET['days'] ) : 30;
	$days = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
	$s    = wto_survey_get_stats( $days );
	?>
	<div class="wto-survey-range-bar">
		<?php
		$ranges = array( 7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => '۱ سال' );
		foreach ( $ranges as $d => $lbl ) :
			$url = add_query_arg( array( 'page' => 'farazwto-poll', 'days' => $d ), admin_url( 'admin.php' ) );
		?>
			<a href="<?php echo esc_url( $url ); ?>" class="wto-survey-range <?php echo $days === $d ? 'is-active' : ''; ?>"><?php echo esc_html( $lbl ); ?></a>
		<?php endforeach; ?>
	</div>

	<div class="wto-survey-stats">
		<div class="wto-survey-stat">
			<div class="num wto-num-info"><?php echo esc_html( number_format_i18n( $s['sms_sent'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'پیامک نظرسنجی ارسال شده', 'wto' ); ?></div>
		</div>
		<div class="wto-survey-stat">
			<div class="num wto-num-success"><?php echo esc_html( number_format_i18n( $s['with_review'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'سفارش‌هایی که نظر ثبت کردند', 'wto' ); ?></div>
		</div>
		<div class="wto-survey-stat">
			<div class="num wto-num-success"><?php echo esc_html( $s['rate'] ); ?>٪</div>
			<div class="lbl"><?php esc_html_e( 'نرخ تبدیل', 'wto' ); ?></div>
		</div>
		<div class="wto-survey-stat">
			<div class="num wto-num-warning"><?php echo esc_html( number_format_i18n( $s['pending'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'نظرات در انتظار تأیید', 'wto' ); ?></div>
		</div>
	</div>

	<div class="wto-survey-stats">
		<div class="wto-survey-stat">
			<div class="num"><?php echo esc_html( number_format_i18n( $s['total_reviews'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'کل نظرات ثبت‌شده (همه محصولات)', 'wto' ); ?></div>
		</div>
		<div class="wto-survey-stat">
			<div class="num wto-num-muted"><?php echo esc_html( number_format_i18n( $s['sms_failed'] ) ); ?></div>
			<div class="lbl"><?php esc_html_e( 'پیامک ناموفق', 'wto' ); ?></div>
		</div>
		<div class="wto-survey-stat wto-survey-stat-link">
			<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>">
				<?php esc_html_e( 'مرور نظرات در انتظار تأیید →', 'wto' ); ?>
			</a>
		</div>
		<div class="wto-survey-stat wto-survey-stat-link">
			<a href="<?php echo esc_url( wto_survey_review_page_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'مشاهده صفحه نظرسنجی →', 'wto' ); ?>
			</a>
		</div>
	</div>
	<?php
}

function wto_survey_render_settings_tab() {
	$enabled       = (string) get_option( 'wto_send_poll_sms', '0' );
	$pattern_code  = (string) get_option( 'wto_poll_pattern', '' );
	$send_time     = (int) get_option( 'wto_send_time', 7 );
	$send_status   = (string) get_option( 'wto_send_status', 'completed' );
	$updated       = isset( $_GET['updated'] ) ? sanitize_key( $_GET['updated'] ) : '';

	$default_tpl   = wto_survey_default_template();
	$review_page   = wto_survey_review_page_url();
	$variables     = wto_survey_variables();
	?>
	<?php if ( $updated === '1' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'wto' ); ?></p></div>
	<?php endif; ?>

	<div class="wto-survey-grid">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wto-survey-card wto-survey-card-main">
			<input type="hidden" name="action" value="wto_survey_save_settings">
			<?php wp_nonce_field( 'wto_survey_settings' ); ?>

			<h2><?php esc_html_e( 'تنظیمات اصلی', 'wto' ); ?></h2>

			<label class="wto-survey-setting wto-setting-wide">
				<span><?php esc_html_e( 'فعال‌سازی', 'wto' ); ?></span>
				<label class="wto-survey-switch">
					<input type="checkbox" class="wto-toggle" name="send_poll_sms" value="1" <?php checked( $enabled, '1' ); ?>>
					<span><?php esc_html_e( 'با تغییر وضعیت سفارش، پیامک نظرسنجی برنامه‌ریزی می‌شود.', 'wto' ); ?></span>
				</label>
			</label>

			<label class="wto-survey-setting">
				<span><?php esc_html_e( 'وضعیت سفارش برای ارسال', 'wto' ); ?></span>
				<select name="send_status">
					<?php
					$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
					foreach ( $statuses as $key => $label ) {
						$clean = preg_replace( '/^wc-/', '', $key );
						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( $clean ),
							selected( $send_status, $clean, false ),
							esc_html( $label )
						);
					}
					?>
				</select>
				<small><?php esc_html_e( 'پس از رسیدن سفارش به این وضعیت، پیامک scheduled می‌شود.', 'wto' ); ?></small>
			</label>

			<label class="wto-survey-setting">
				<span><?php esc_html_e( 'تأخیر ارسال (روز)', 'wto' ); ?></span>
				<input type="number" name="send_time" value="<?php echo esc_attr( (string) $send_time ); ?>" min="0" max="60" dir="ltr">
				<small><?php esc_html_e( 'چند روز پس از تغییر وضعیت، پیامک ارسال شود. (پیشنهاد: ۳ تا ۷ روز)', 'wto' ); ?></small>
			</label>

			<label class="wto-survey-setting wto-setting-wide">
				<span><?php esc_html_e( 'کد الگوی پیامک نظرسنجی', 'wto' ); ?></span>
				<input type="text" name="wto_poll_pattern" value="<?php echo esc_attr( $pattern_code ); ?>" dir="ltr" id="wto-survey-pattern-code">
				<small><?php esc_html_e( 'اگر هنوز الگو نساخته‌اید، در بخش روبرو متن را وارد کرده و دکمه «ساخت الگو» را بزنید.', 'wto' ); ?></small>
			</label>

			<div class="wto-survey-save-row">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تنظیمات', 'wto' ); ?></button>
				<button type="button" class="button" id="wto-survey-test-btn"><?php esc_html_e( 'ارسال پیامک تست به موبایل من', 'wto' ); ?></button>
				<input type="text" id="wto-survey-test-mobile" placeholder="<?php esc_attr_e( '09xxxxxxxxx', 'wto' ); ?>" dir="ltr" class="wto-survey-test-input">
				<span class="wto-survey-test-result"></span>
			</div>
		</form>

		<div class="wto-survey-card wto-survey-card-side">
			<h2><?php esc_html_e( 'ساخت الگو از روی متن', 'wto' ); ?></h2>
			<p class="wto-survey-help">
				<?php esc_html_e( 'متن الگو را در پایین بنویسید (می‌توانید از متغیرهای فهرست‌شده استفاده کنید) و دکمه «ساخت الگو» را بزنید. کد الگو خودکار در فیلد سمت چپ نوشته می‌شود.', 'wto' ); ?>
			</p>
			<textarea id="wto-survey-pattern-text" rows="6" class="wto-survey-pattern-text"><?php echo esc_textarea( $default_tpl ); ?></textarea>
			<div class="wto-survey-pattern-actions">
				<button type="button" class="button button-primary" id="wto-survey-create-pattern-btn"><?php esc_html_e( 'ساخت الگو', 'wto' ); ?></button>
				<span class="wto-survey-create-result"></span>
			</div>

			<h3 class="wto-survey-variables-title"><?php esc_html_e( 'متغیرهای قابل استفاده در متن الگو', 'wto' ); ?></h3>
			<div class="wto-survey-variables">
				<?php foreach ( $variables as $key => $label ) : ?>
					<div class="wto-survey-variable">
						<code dir="ltr">%<?php echo esc_html( $key ); ?>%</code>
						<span><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="wto-survey-info-box">
				<strong><?php esc_html_e( 'صفحه نظرسنجی:', 'wto' ); ?></strong>
				<a href="<?php echo esc_url( $review_page ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $review_page ); ?></a>
				<p class="wto-survey-help-small">
					<?php esc_html_e( 'این صفحه به‌صورت خودکار هنگام فعال‌سازی افزونه ساخته شد. شورت‌کد آن', 'wto' ); ?>
					<code dir="ltr">[order_review]</code>
					<?php esc_html_e( 'است و در پیامک، با لینک', 'wto' ); ?>
					<code dir="ltr">%review_url%</code>
					<?php esc_html_e( 'به مشتری ارسال می‌شود.', 'wto' ); ?>
				</p>
			</div>
		</div>
	</div>

	<div class="wto-survey-card">
		<h2><?php esc_html_e( 'راهنمای گام‌به‌گام', 'wto' ); ?></h2>
		<ol class="wto-survey-guide">
			<li><?php esc_html_e( 'مطمئن شوید کلید دسترسی (Api-Key) در «تنظیمات» افزونه وارد شده.', 'wto' ); ?></li>
			<li><?php esc_html_e( 'در پنل کنار، متن الگو را بنویسید (نمونه از قبل پر شده). از متغیرها استفاده کنید.', 'wto' ); ?></li>
			<li><?php esc_html_e( 'روی «ساخت الگو» کلیک کنید — درخواست تأیید الگو در پنل فراز اس‌ام‌اس ثبت می‌شود.', 'wto' ); ?></li>
			<li><?php esc_html_e( 'پس از تأیید الگو توسط ناظر فراز، فعال‌سازی بالا را روشن کنید.', 'wto' ); ?></li>
			<li><?php esc_html_e( 'وضعیت سفارش و تأخیر روز را انتخاب و ذخیره کنید.', 'wto' ); ?></li>
			<li><?php esc_html_e( 'با ارسال «پیامک تست» مطمئن شوید همه‌چیز درست کار می‌کند.', 'wto' ); ?></li>
		</ol>

		<div class="wto-survey-warning-box">
			<strong>⚠ نکته مهم برای تأیید الگو:</strong>
			<p>
				نام برند فروشگاه شما باید به‌صورت <strong>ثابت</strong> در متن الگو نوشته شود (مثلاً «فروشگاه پارسا»)، نه به‌صورت متغیر. پنل فراز اس‌ام‌اس الگوهایی را که نام برند را به‌صورت ثابت ندارند تأیید نمی‌کند، چون گیرنده پیامک باید بداند پیامک از سمت چه برند یا شرکتی ارسال شده است.
			</p>
		</div>
	</div>
	<?php
}

function wto_survey_render_reviews_tab() {
	$args = array(
		'number'  => 25,
		'orderby' => 'comment_date',
		'order'   => 'DESC',
		'meta_query' => array(
			array(
				'key'     => 'order_id',
				'compare' => 'EXISTS',
			),
		),
		'status'  => 'all',
	);
	$comments = get_comments( $args );
	?>
	<div class="wto-survey-card">
		<h2><?php esc_html_e( 'آخرین نظرات ثبت‌شده از طریق نظرسنجی', 'wto' ); ?></h2>
		<p class="wto-survey-help">
			<?php
			printf(
				/* translators: %s link to comments admin page */
				esc_html__( 'برای مدیریت کامل (تأیید/حذف) به %s بروید.', 'wto' ),
				'<a href="' . esc_url( admin_url( 'edit-comments.php' ) ) . '">' . esc_html__( 'مدیریت دیدگاه‌های وردپرس', 'wto' ) . '</a>'
			);
			?>
		</p>
		<table class="widefat striped wto-survey-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'شناسه', 'wto' ); ?></th>
					<th><?php esc_html_e( 'تاریخ', 'wto' ); ?></th>
					<th><?php esc_html_e( 'سفارش', 'wto' ); ?></th>
					<th><?php esc_html_e( 'محصول', 'wto' ); ?></th>
					<th><?php esc_html_e( 'مشتری', 'wto' ); ?></th>
					<th><?php esc_html_e( 'امتیاز', 'wto' ); ?></th>
					<th><?php esc_html_e( 'متن', 'wto' ); ?></th>
					<th><?php esc_html_e( 'وضعیت', 'wto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $comments ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'هنوز نظری از طریق نظرسنجی ثبت نشده.', 'wto' ); ?></td></tr>
				<?php else : foreach ( $comments as $c ) :
					$order_id = (int) get_comment_meta( $c->comment_ID, 'order_id', true );
					$rating   = (int) get_comment_meta( $c->comment_ID, 'rating', true );
					$jdate    = function_exists( 'wto_send_reports_to_jalali' ) ? wto_send_reports_to_jalali( $c->comment_date ) : $c->comment_date;
					$product_id = (int) $c->comment_post_ID;
					$product_title = $product_id ? get_the_title( $product_id ) : '';
					$status   = $c->comment_approved === '1' ? 'success' : ( $c->comment_approved === 'spam' ? 'danger' : 'warning' );
					$status_t = $c->comment_approved === '1' ? __( 'تأیید شده', 'wto' ) : ( $c->comment_approved === 'spam' ? __( 'هرزنامه', 'wto' ) : __( 'در انتظار', 'wto' ) );
					$edit_url = admin_url( 'comment.php?action=editcomment&c=' . $c->comment_ID );
				?>
					<tr>
						<td><?php echo esc_html( $c->comment_ID ); ?></td>
						<td class="wto-survey-date-cell"><?php echo esc_html( $jdate ); ?></td>
						<td>
							<?php if ( $order_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>" target="_blank">#<?php echo esc_html( (string) $order_id ); ?></a>
							<?php else : ?> — <?php endif; ?>
						</td>
						<td><?php echo esc_html( $product_title !== '' ? $product_title : '—' ); ?></td>
						<td><?php echo esc_html( $c->comment_author !== '' ? $c->comment_author : '—' ); ?></td>
						<td><?php echo $rating > 0 ? str_repeat( '⭐', min( 5, $rating ) ) : '—'; ?></td>
						<td class="wto-survey-comment-cell"><?php echo esc_html( wp_trim_words( $c->comment_content, 12, '…' ) ); ?></td>
						<td>
							<span class="wto-status wto-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_t ); ?></span>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small wto-survey-edit-btn" target="_blank"><?php esc_html_e( 'مرور', 'wto' ); ?></a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function wto_survey_render_inline() {
	$nonce = wp_create_nonce( 'wto_survey_admin' );
	?>
	<style>
	.wto-survey-wrapper .wto-survey-title-main { margin: 16px 0 8px; }
	.wto-survey-wrapper .wto-survey-tabs { display: flex; gap: 4px; border-bottom: 1px solid #c3c4c7; margin: 16px 0 20px; }
	.wto-survey-wrapper .wto-survey-tab { padding: 10px 18px; text-decoration: none; color: #50575e; background: #f1f1f1; border: 1px solid #c3c4c7; border-bottom: 0; border-radius: 6px 6px 0 0; margin-bottom: -1px; font-size: 13px; }
	.wto-survey-wrapper .wto-survey-tab.is-active { background: #fff; color: #1d2327; font-weight: 600; }
	.wto-survey-wrapper .wto-survey-range-bar { display: flex; gap: 6px; margin-bottom: 16px; }
	.wto-survey-wrapper .wto-survey-range { padding: 6px 14px; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; color: #4b5563; text-decoration: none; font-size: 13px; }
	.wto-survey-wrapper .wto-survey-range.is-active { background: #6366f1; color: #fff; border-color: #6366f1; font-weight: 600; }
	.wto-survey-wrapper .wto-survey-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 0 0 16px; }
	.wto-survey-wrapper .wto-survey-stat { background: #fff; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; text-align: center; }
	.wto-survey-wrapper .wto-survey-stat .num { font-size: 26px; font-weight: 700; line-height: 1; color: #1f2937; }
	.wto-survey-wrapper .wto-survey-stat .num.wto-num-success { color: #047857; }
	.wto-survey-wrapper .wto-survey-stat .num.wto-num-info { color: #1d4ed8; }
	.wto-survey-wrapper .wto-survey-stat .num.wto-num-warning { color: #b45309; }
	.wto-survey-wrapper .wto-survey-stat .num.wto-num-muted { color: #6b7280; }
	.wto-survey-wrapper .wto-survey-stat .lbl { color: #6b7280; font-size: 12px; margin-top: 6px; }
	.wto-survey-wrapper .wto-survey-stat-link { display: flex; align-items: center; justify-content: center; }
	.wto-survey-wrapper .wto-survey-stat-link a { color: #6366f1; font-weight: 600; text-decoration: none; }
	.wto-survey-wrapper .wto-survey-stat-link a:hover { text-decoration: underline; }
	.wto-survey-wrapper .wto-survey-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 16px; margin-bottom: 16px; }
	.wto-survey-wrapper .wto-survey-card { background: #fff; padding: 20px 24px; border: 1px solid #e5e7eb; border-radius: 10px; }
	.wto-survey-wrapper .wto-survey-card h2 { margin: 0 0 12px; font-size: 16px; }
	.wto-survey-wrapper .wto-survey-card h3 { margin: 16px 0 8px; font-size: 14px; }
	.wto-survey-wrapper .wto-survey-help { color: #50575e; margin: 0 0 12px; font-size: 13px; line-height: 1.7; }
	.wto-survey-wrapper .wto-survey-help-small { color: #6b7280; font-size: 12px; }
	.wto-survey-wrapper .wto-survey-setting { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; font-size: 13px; }
	.wto-survey-wrapper .wto-setting-wide { grid-column: 1 / -1; }
	.wto-survey-wrapper .wto-survey-setting input,
	.wto-survey-wrapper .wto-survey-setting select { padding: 8px 10px; }
	.wto-survey-wrapper .wto-survey-setting small { color: #6b7280; font-size: 11px; margin-top: 4px; }
	.wto-survey-wrapper .wto-survey-switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
	.wto-survey-wrapper .wto-survey-save-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #e5e7eb; }
	.wto-survey-wrapper .wto-survey-test-input { padding: 7px 10px; min-width: 160px; }
	.wto-survey-wrapper .wto-survey-test-result { font-size: 13px; }
	.wto-survey-wrapper .wto-survey-test-result.success { color: #047857; }
	.wto-survey-wrapper .wto-survey-test-result.error { color: #b91c1c; }
	.wto-survey-wrapper .wto-survey-pattern-text { width: 100%; padding: 10px 12px; font-family: inherit; line-height: 1.7; border: 1px solid #d1d5db; border-radius: 6px; box-sizing: border-box; }
	.wto-survey-wrapper .wto-survey-pattern-actions { display: flex; align-items: center; gap: 10px; margin: 10px 0 14px; }
	.wto-survey-wrapper .wto-survey-create-result { font-size: 13px; }
	.wto-survey-wrapper .wto-survey-create-result.success { color: #047857; }
	.wto-survey-wrapper .wto-survey-create-result.error { color: #b91c1c; }
	.wto-survey-wrapper .wto-survey-variables-title { margin: 10px 0 8px; font-size: 13px; color: #1f2937; }
	.wto-survey-wrapper .wto-survey-variables { display: grid; grid-template-columns: 1fr; gap: 6px; margin-bottom: 12px; }
	.wto-survey-wrapper .wto-survey-variable { display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; }
	.wto-survey-wrapper .wto-survey-variable code { background: #fff; padding: 2px 8px; border-radius: 4px; color: #4f46e5; font-weight: 600; }
	.wto-survey-wrapper .wto-survey-variable code:hover { background: #eef2ff; cursor: pointer; }
	.wto-survey-wrapper .wto-survey-info-box { background: #f0f9ff; padding: 12px 14px; border: 1px solid #bae6fd; border-radius: 6px; font-size: 13px; }
	.wto-survey-wrapper .wto-survey-info-box a { color: #0369a1; word-break: break-all; }
	.wto-survey-wrapper .wto-survey-info-box code { background: #fff; padding: 1px 6px; border-radius: 3px; margin: 0 2px; }
	.wto-survey-wrapper .wto-survey-warning-box { background: #fef3c7; padding: 14px 18px; border: 1px solid #fbbf24; border-right: 4px solid #d97706; border-radius: 6px; margin-top: 18px; font-size: 13px; line-height: 1.8; }
	.wto-survey-wrapper .wto-survey-warning-box strong { color: #92400e; display: block; margin-bottom: 6px; }
	.wto-survey-wrapper .wto-survey-warning-box p { margin: 0; color: #78350f; }
	.wto-survey-wrapper .wto-survey-guide { padding-right: 22px; line-height: 2.1; font-size: 14px; }
	.wto-survey-wrapper .wto-survey-guide li { color: #374151; }
	.wto-survey-wrapper .wto-survey-table th,
	.wto-survey-wrapper .wto-survey-table td { padding: 10px 12px; vertical-align: middle; }
	.wto-survey-wrapper .wto-survey-date-cell { direction: ltr; font-variant-numeric: tabular-nums; }
	.wto-survey-wrapper .wto-survey-comment-cell { max-width: 320px; }
	.wto-survey-wrapper .wto-survey-edit-btn { margin-right: 6px; }
	.wto-survey-wrapper .wto-status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; }
	.wto-survey-wrapper .wto-status-success { background: #d1f5e0; color: #006d28; }
	.wto-survey-wrapper .wto-status-warning { background: #fef3c7; color: #92400e; }
	.wto-survey-wrapper .wto-status-danger { background: #ffd7d7; color: #8a0a0a; }
	@media (max-width: 900px) {
		.wto-survey-wrapper .wto-survey-grid { grid-template-columns: 1fr; }
		.wto-survey-wrapper .wto-survey-stats { grid-template-columns: repeat(2, 1fr); }
	}
	</style>
	<script>
	(function(){
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

		// Click on a variable code → insert into the pattern textarea.
		var ta = document.getElementById('wto-survey-pattern-text');
		document.querySelectorAll('.wto-survey-variable code').forEach(function(el){
			el.addEventListener('click', function(){
				if (!ta) return;
				var val = el.textContent;
				var start = ta.selectionStart || ta.value.length;
				var end   = ta.selectionEnd || ta.value.length;
				ta.value = ta.value.slice(0, start) + val + ta.value.slice(end);
				ta.focus();
				ta.selectionStart = ta.selectionEnd = start + val.length;
			});
		});

		// Build pattern via API.
		var btn = document.getElementById('wto-survey-create-pattern-btn');
		var result = document.querySelector('.wto-survey-create-result');
		var codeInput = document.getElementById('wto-survey-pattern-code');
		if (btn && ta) {
			btn.addEventListener('click', function(){
				var msg = ta.value.trim();
				if (!msg) { result.className = 'wto-survey-create-result error'; result.textContent = 'متن الگو خالی است.'; return; }
				btn.disabled = true;
				result.className = 'wto-survey-create-result';
				result.textContent = 'در حال ساخت...';
				var fd = new FormData();
				fd.append('action', 'wto_survey_create_pattern');
				fd.append('nonce', nonce);
				fd.append('message', msg);
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(json){
						if (json.success) {
							result.className = 'wto-survey-create-result success';
							result.textContent = (json.data && json.data.message) || 'انجام شد.';
							if (codeInput && json.data && json.data.pattern_code) {
								codeInput.value = json.data.pattern_code;
							}
						} else {
							result.className = 'wto-survey-create-result error';
							result.textContent = (json.data && json.data.message) || 'خطا در ساخت.';
						}
					})
					.catch(function(){ result.className = 'wto-survey-create-result error'; result.textContent = 'خطا در ارتباط با سرور.'; })
					.then(function(){ btn.disabled = false; });
			});
		}

		// Send test SMS.
		var testBtn = document.getElementById('wto-survey-test-btn');
		var testIn  = document.getElementById('wto-survey-test-mobile');
		var testRes = document.querySelector('.wto-survey-test-result');
		if (testBtn && testIn) {
			testBtn.addEventListener('click', function(){
				var mob = testIn.value.trim();
				if (!mob) { testRes.className = 'wto-survey-test-result error'; testRes.textContent = 'شماره موبایل را وارد کنید.'; return; }
				testBtn.disabled = true;
				testRes.className = 'wto-survey-test-result';
				testRes.textContent = 'در حال ارسال...';
				var fd = new FormData();
				fd.append('action', 'wto_survey_send_test');
				fd.append('nonce', nonce);
				fd.append('mobile', mob);
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(json){
						testRes.className = 'wto-survey-test-result ' + (json.success ? 'success' : 'error');
						testRes.textContent = (json.data && json.data.message) || 'انجام شد.';
					})
					.catch(function(){ testRes.className = 'wto-survey-test-result error'; testRes.textContent = 'خطا.'; })
					.then(function(){ testBtn.disabled = false; });
			});
		}
	})();
	</script>
	<?php
}
