<?php
/**
 * Admin «بازخورد»: ارسال تیکت به پنل فراز (POST /ws/v1/ticket).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * شناسه دپارتمان پشتیبانی (ثابت: ۷).
 *
 * @return string
 */
function wto_feedback_get_support_department_id() {
	return (string) apply_filters( 'wto_feedback_support_department_id', '3' );
}

/**
 * عنوان ثابت تیکت بازخورد.
 *
 * @return string
 */
function wto_feedback_get_ticket_title() {
	return (string) apply_filters( 'wto_feedback_ticket_title', 'بازخورد افزونه پیامکی فراز اس ام اس' );
}

/**
 * POST /ws/v1/ticket — multipart/form-data per Faraz API spec.
 *
 * @param string               $api_key Api-Key header.
 * @param array<string,string> $fields  Form fields.
 * @return array|\WP_Error
 */
function wto_feedback_ticket_api_post( $api_key, $fields ) {
	$boundary = '----WTO' . wp_generate_password( 16, false );
	$body     = '';
	foreach ( $fields as $name => $value ) {
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
		$body .= $value . "\r\n";
	}
	$body .= '--' . $boundary . "--\r\n";

	$url  = 'https://api.iranpayamak.com/ws/v1/ticket';
	$args = array(
		'timeout' => 45,
		'headers' => array(
			'Accept'       => 'application/json',
			'Api-Key'      => $api_key,
			'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
		),
		'body'    => $body,
	);

	if ( function_exists( 'wto_remote_post_with_fallback' ) ) {
		return wto_remote_post_with_fallback( $url, $args );
	}
	return wp_remote_post( $url, $args );
}

/**
 * @return int
 */
function wto_feedback_ticket_rate_count() {
	$key = 'wto_fb_ticket_rl_' . get_current_user_id();
	$n   = (int) get_transient( $key );
	return max( 0, $n );
}

/**
 * @return void
 */
function wto_feedback_ticket_rate_bump() {
	$key = 'wto_fb_ticket_rl_' . get_current_user_id();
	$n   = wto_feedback_ticket_rate_count() + 1;
	set_transient( $key, $n, HOUR_IN_SECONDS );
}

/**
 * ثبت زیرمنو در انتهای منوی فراز اس ام اس.
 */
add_action( 'admin_menu', 'wto_register_feedback_submenu', 999 );
function wto_register_feedback_submenu() {
	add_submenu_page(
		'farazwto',
		__( 'بازخورد', 'wto' ),
		__( 'بازخورد', 'wto' ),
		'manage_options',
		'farazwto-feedback',
		'wto_render_feedback_ticket_page'
	);
}

add_action( 'admin_init', 'wto_feedback_ticket_handle_post', 1 );
function wto_feedback_ticket_handle_post() {
	if ( ! is_admin() ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'farazwto-feedback' ) {
		return;
	}
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || empty( $_POST['wto_feedback_ticket_submit'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'wto_feedback_ticket', 'wto_feedback_ticket_nonce' );

	$redirect_base = admin_url( 'admin.php?page=farazwto-feedback' );

	if ( wto_feedback_ticket_rate_count() >= 8 ) {
		wp_safe_redirect( add_query_arg( 'wto_ticket', 'ratelimit', $redirect_base ) );
		exit;
	}

	$api_key = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	if ( $api_key === '' ) {
		wp_safe_redirect( add_query_arg( 'wto_ticket', 'noapikey', $redirect_base ) );
		exit;
	}

	$dept = wto_feedback_get_support_department_id();

	$question = isset( $_POST['ticket_question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_question'] ) ) : '';
	$question = trim( $question );
	if ( $question === '' ) {
		wp_safe_redirect( add_query_arg( 'wto_ticket', 'needbody', $redirect_base ) );
		exit;
	}

	$title = wto_feedback_get_ticket_title();

	$user = wp_get_current_user();
	$ctx  = sprintf(
		"\n\n---\nسایت: %s\nوردپرس: %s\nافزونه: %s\nکاربر مدیر: %s (%s)\n",
		home_url( '/' ),
		get_bloginfo( 'version' ),
		defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '',
		$user->display_name,
		$user->user_email
	);
	$question .= $ctx;

	$fields = array(
		'support_department_id' => $dept,
		'title'                 => $title,
		'importance'            => 'normal',
		'question'              => $question,
	);

	$response = wto_feedback_ticket_api_post( $api_key, $fields );

	if ( is_wp_error( $response ) ) {
		wto_feedback_ticket_rate_bump();
		wp_safe_redirect(
			add_query_arg(
				array(
					'wto_ticket' => 'error',
					'wto_err'    => rawurlencode( $response->get_error_message() ),
				),
				$redirect_base
			)
		);
		exit;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( is_array( $data ) && isset( $data['status'] ) && $data['status'] === 'success' ) {
		wto_feedback_ticket_rate_bump();
		wp_safe_redirect( add_query_arg( 'wto_ticket', 'sent', $redirect_base ) );
		exit;
	}

	wto_feedback_ticket_rate_bump();
	$msg = is_array( $data ) && isset( $data['message'] )
		? ( is_string( $data['message'] ) ? $data['message'] : wp_json_encode( $data['message'], JSON_UNESCAPED_UNICODE ) )
		: ( 'HTTP ' . (int) $code . ' ' . ( function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 200 ) : substr( $raw, 0, 200 ) ) );

	wp_safe_redirect(
		add_query_arg(
			array(
				'wto_ticket' => 'error',
				'wto_err'    => rawurlencode( $msg ),
			),
			$redirect_base
		)
	);
	exit;
}

/**
 * پیام وضعیت برای نمایش در صفحه بازخورد.
 *
 * @param string $flag
 * @param string $err
 * @return string HTML یا خالی
 */
function wto_feedback_ticket_status_message( $flag, $err = '' ) {
	if ( $flag === 'sent' ) {
		return '<div class="wto_notice wto_notice--success"><p>' . esc_html__( 'بازخورد شما با موفقیت ثبت شد.', 'wto' ) . '</p></div>';
	}
	if ( $flag === 'ratelimit' ) {
		return '<div class="wto_notice"><p>' . esc_html__( 'تعداد درخواست‌ها زیاد است؛ یک ساعت بعد دوباره تلاش کنید.', 'wto' ) . '</p></div>';
	}
	if ( $flag === 'error' && $err !== '' ) {
		return '<div class="wto_notice"><p>' . esc_html( $err ) . '</p></div>';
	}
	if ( $flag === 'needbody' ) {
		return '<div class="wto_notice"><p>' . esc_html__( 'لطفاً نظر خود را بنویسید.', 'wto' ) . '</p></div>';
	}
	if ( $flag === 'noapikey' ) {
		return '<div class="wto_notice"><p>' . esc_html__( 'ابتدا در تب «تنظیمات» کلید دسترسی (Api-Key) را ذخیره کنید.', 'wto' ) . '</p></div>';
	}
	return '';
}

/**
 * @return void
 */
function wto_render_feedback_ticket_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'شما به این صفحه دسترسی ندارید.', 'wto' ) );
	}

	$api_key = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : get_option( 'wto_apikey', '' );
	$flag    = isset( $_GET['wto_ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['wto_ticket'] ) ) : '';
	$err     = isset( $_GET['wto_err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['wto_err'] ) ) ) : '';
	$status     = wto_feedback_ticket_status_message( $flag, $err );
	$can_submit = ( $api_key !== '' );

	// v3.13.19 REWRITE: ساختار قدیمی section.wrapper + ul.tabs + ul.tab__content حذف شد.
	// قاب یکپارچه (Unified Frame) خودش این chrome را فراهم می‌کند، بنابراین تکرار
	// آن باعث تداخل CSS و در شرایط خاص صفحه سفید می‌شد. حالا فقط فرم را با
	// inline style مستقیم render می‌کنیم — هیچ class قدیمی استفاده نمی‌شود.
	?>
	<div class="wto-feedback-wrapper" style="direction:rtl; font-family:inherit; max-width:720px;">
		<?php if ( $status !== '' ) : ?>
			<div style="margin-bottom:14px;"><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?></div>
		<?php endif; ?>

		<?php if ( $api_key === '' ) : ?>
			<div style="background:#fef3c7; border:1px solid #fde68a; color:#78350f; padding:12px 16px; border-radius:8px; margin-bottom:18px;">
				⚠️
				<?php esc_html_e( 'ابتدا در صفحه «تنظیمات» کلید دسترسی (Api-Key) را ذخیره کنید.', 'wto' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-settings' ) ); ?>" style="color:#92400e; font-weight:600; margin-right:8px;"><?php esc_html_e( 'رفتن به تنظیمات', 'wto' ); ?></a>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-feedback' ) ); ?>" id="wto_feedback_ticket_form" style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:24px;">
			<?php wp_nonce_field( 'wto_feedback_ticket', 'wto_feedback_ticket_nonce' ); ?>

			<p style="margin:0 0 16px; color:#475569; font-size:13px; line-height:1.9;">
				💬 <?php esc_html_e( 'نظر، پیشنهاد یا گزارش مشکل خود را بنویسید؛ پس از ثبت، مستقیماً به پشتیبانی فراز اس‌ام‌اس ارسال می‌شود.', 'wto' ); ?>
			</p>

			<label for="ticket_question" style="display:block; margin-bottom:16px;">
				<span style="display:block; font-size:14px; font-weight:600; color:#0f172a; margin-bottom:8px;">
					<?php esc_html_e( 'متن بازخورد', 'wto' ); ?>
					<span style="color:#dc2626;">*</span>
				</span>
				<textarea
					name="ticket_question"
					id="ticket_question"
					rows="10"
					required
					dir="rtl"
					placeholder="<?php esc_attr_e( 'بازخورد یا گزارش مشکل خود را اینجا بنویسید...', 'wto' ); ?>"
					style="display:block; width:100%; min-height:220px; padding:14px 16px; border:1px solid #cbd5e1; border-radius:8px; font-family:inherit; font-size:14px; line-height:1.9; resize:vertical; box-sizing:border-box; background:#fff; color:#0f172a;"
				><?php echo isset( $_POST['ticket_question'] ) ? esc_textarea( wp_unslash( $_POST['ticket_question'] ) ) : ''; ?></textarea>
			</label>

			<div style="display:flex; justify-content:flex-start; align-items:center; gap:12px;">
				<button
					type="submit"
					name="wto_feedback_ticket_submit"
					value="1"
					<?php disabled( ! $can_submit ); ?>
					style="background:#4338ca; color:#fff; border:none; padding:10px 24px; font-size:14px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit; transition:background 0.15s;"
					onmouseover="this.style.background='#3730a3'"
					onmouseout="this.style.background='#4338ca'"
				>
					📨 <?php esc_html_e( 'ثبت بازخورد', 'wto' ); ?>
				</button>
				<?php if ( ! $can_submit ) : ?>
					<span style="color:#dc2626; font-size:12px;"><?php esc_html_e( 'برای ثبت بازخورد، Api-Key لازم است.', 'wto' ); ?></span>
				<?php endif; ?>
			</div>
		</form>
	</div>
	<?php
}
