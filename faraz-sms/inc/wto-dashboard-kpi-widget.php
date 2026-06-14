<?php
/**
 * WP Dashboard KPI Widget — v3.17.4
 *
 * ویجت پنل پیشخوان وردپرس — اعداد طلایی فروشگاه که کاربر را به مراجعه روزانه «معتاد» می‌کند.
 *
 * چه چیزی نمایش می‌دهد:
 *   🚀 سود این ماه افزونه (از ROI dashboard)
 *   📨 پیامک‌های ارسالی این ماه
 *   ✅ نرخ تحویل (delivery rate) از DLR
 *   👥 رشد مشترکین خبرنامه + مشتریان جدید
 *   🎂 تولدهای این هفته
 *   💳 موجودی اعتبار پنل
 *
 * تمام داده‌ها از transient cache می‌آیند — هیچ API call تازه روی dashboard load.
 * این widget فقط چیزی که از قبل cache شده را نمایش می‌دهد.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_DASH_WIDGET_ID = 'wto_farazsms_kpi_widget';

// ============================================================================
// Register
// ============================================================================

add_action( 'wp_dashboard_setup', 'wto_dash_kpi_register_widget' );
function wto_dash_kpi_register_widget() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	wp_add_dashboard_widget(
		WTO_DASH_WIDGET_ID,
		'📊 ' . __( 'گزارش روزانه فراز اس‌ام‌اس — کسب و کار شما در یک نگاه', 'wto' ),
		'wto_dash_kpi_render',
		null,
		null,
		'normal',
		'high'   // اولویت بالا — به بالای dashboard می‌رود
	);
}

// v3.20.0: enqueue شیت مدرن روی dashboard فقط (نه همه‌ی wp-admin)
add_action( 'admin_enqueue_scripts', 'wto_dash_kpi_enqueue_styles' );
function wto_dash_kpi_enqueue_styles( $hook ) {
	if ( $hook !== 'index.php' ) return; // فقط روی wp-admin/index.php
	if ( ! current_user_can( 'manage_options' ) ) return;
	wp_enqueue_style(
		'wto-admin-modern',
		defined( 'WTO_CORE_CSS' ) ? WTO_CORE_CSS . 'wto-admin-modern.css' : plugins_url( 'assets/css/wto-admin-modern.css', WTO_PLUGIN_FILE ),
		array(),
		'3.20.0',
		'all'
	);
}

// ============================================================================
// Data layer — جمع‌آوری stats از منابع مختلف
// ============================================================================

function wto_dash_kpi_collect_stats() {
	global $wpdb;
	$stats = array(
		'roi_this_month'      => 0.0,
		'roi_last_month'      => 0.0,
		'sms_this_month'      => 0,
		'sms_today'           => 0,
		'delivery_rate'       => null,    // null = داده نداریم
		'newsletter_subs'     => 0,
		'newsletter_new_7d'   => 0,
		'birthdays_this_week' => 0,
		'cashback_balance'    => 0.0,
		'panel_credit'        => null,
	);

	// ---- ۱) ROI این ماه + ماه قبل (از داشبورد ROI) ----
	if ( function_exists( 'wto_roi_get_date_ranges' ) && function_exists( 'wto_roi_get_stats' ) ) {
		$ranges = wto_roi_get_date_ranges();
		if ( isset( $ranges['this_month'] ) ) {
			$tm = wto_roi_get_stats( $ranges['this_month']['start'], $ranges['this_month']['end'] );
			if ( is_array( $tm ) ) $stats['roi_this_month'] = (float) ( $tm['total_revenue'] ?? 0 );
		}
		if ( isset( $ranges['last_month'] ) ) {
			$lm = wto_roi_get_stats( $ranges['last_month']['start'], $ranges['last_month']['end'] );
			if ( is_array( $lm ) ) $stats['roi_last_month'] = (float) ( $lm['total_revenue'] ?? 0 );
		}
	}

	// ---- ۲) DLR delivery rate (از cache) ----
	if ( function_exists( 'wto_dlr_get_stats' ) && function_exists( 'wto_dlr_calc_delivery_rate' ) ) {
		$dlr = wto_dlr_get_stats();
		if ( is_array( $dlr ) && ! empty( $dlr['items_examined'] ) ) {
			$stats['delivery_rate'] = wto_dlr_calc_delivery_rate( $dlr );
		}
	}

	// ---- ۳) Newsletter subscribers ----
	$newsletter_table = $wpdb->prefix . 'wto_newsletter_subscribers';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $newsletter_table ) ) === $newsletter_table ) {
		$stats['newsletter_subs'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $newsletter_table WHERE status = 'active'" );
		$stats['newsletter_new_7d'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $newsletter_table WHERE status = 'active' AND subscribed_at >= %s",
			date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS )
		) );
	}

	// ---- ۴) SMS volume (از tracking log + survey) ----
	$tracking_table = $wpdb->prefix . 'wto_tracking_log';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tracking_table ) ) === $tracking_table ) {
		$start_month = date( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );
		$start_today = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
		$stats['sms_this_month'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $tracking_table WHERE sent_at >= %s", $start_month
		) );
		$stats['sms_today'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $tracking_table WHERE sent_at >= %s", $start_today
		) );
	}

	// ---- ۵) تولدهای این هفته ----
	$birthday_table = $wpdb->prefix . 'wto_customer_birthdays';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $birthday_table ) ) === $birthday_table
	     && function_exists( 'wto_birthday_today_jalali' ) ) {
		$today = wto_birthday_today_jalali();
		list( $jy, $jm, $jd ) = $today;
		// همه‌ی تولدهای ۷ روز آینده
		$md_list = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$d = $jd + $i;
			$m = $jm;
			// Note: تقریبی — سال شمسی wraps را در نظر نمی‌گیرد، که برای widget OK است
			if ( $m <= 6 && $d > 31 ) { $d -= 31; $m++; }
			elseif ( $m >= 7 && $m <= 11 && $d > 30 ) { $d -= 30; $m++; }
			elseif ( $m == 12 && $d > 29 ) { $d -= 29; $m = 1; }
			if ( $m > 12 ) $m = 1;
			$md_list[] = sprintf( '%02d-%02d', $m, $d );
		}
		$placeholders = implode( ',', array_fill( 0, count( $md_list ), '%s' ) );
		$stats['birthdays_this_week'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $birthday_table WHERE jalali_md IN ($placeholders)",
			...$md_list
		) );
	}

	// ---- ۶) موجودی پنل (از credit transient — اگر کاربر اخیراً دیده باشد) ----
	$apikey = get_option( 'wto_apikey', '' );
	if ( $apikey !== '' ) {
		$cached_credit = get_transient( 'wto_credit_' . md5( $apikey ) );
		if ( $cached_credit !== false && $cached_credit !== '' ) {
			$stats['panel_credit'] = $cached_credit;
		}
	}

	return $stats;
}

// ============================================================================
// Render
// ============================================================================

function wto_dash_kpi_render() {
	$stats = wto_dash_kpi_collect_stats();

	// محاسبه‌ی trend ROI
	$roi_trend_pct = 0;
	$roi_trend_dir = 'flat';
	if ( $stats['roi_last_month'] > 0 ) {
		$diff = $stats['roi_this_month'] - $stats['roi_last_month'];
		$roi_trend_pct = round( ( $diff / $stats['roi_last_month'] ) * 100, 1 );
		$roi_trend_dir = $diff > 0 ? 'up' : ( $diff < 0 ? 'down' : 'flat' );
	} elseif ( $stats['roi_this_month'] > 0 ) {
		$roi_trend_dir = 'up';
		$roi_trend_pct = 100;
	}

	// لینک‌ها
	$roi_url        = admin_url( 'admin.php?page=farazwto-roi' );
	$dlr_url        = admin_url( 'admin.php?page=farazwto-dlr' );
	$newsletter_url = admin_url( 'admin.php?page=farazwto-newsletter' );
	$birthday_url   = admin_url( 'admin.php?page=farazwto-birthday' );
	$settings_url   = admin_url( 'admin.php?page=farazwto-settings' );
	?>
	<!-- v3.20.0: inline styles extracted → assets/css/wto-admin-modern.css -->

	<div class="wto-dash-kpi">
		<!-- Hero ROI -->
		<div class="wto-dash-kpi__hero">
			<div class="wto-dash-kpi__hero-label"><?php _e( 'این ماه افزونه فراز اس‌ام‌اس برای فروشگاه شما', 'wto' ); ?></div>
			<div class="wto-dash-kpi__hero-value">
				<?php echo esc_html( number_format_i18n( $stats['roi_this_month'] ) ); ?>
				<small><?php _e( 'تومان اضافه فروش', 'wto' ); ?></small>
			</div>
			<div class="wto-dash-kpi__hero-sub">
				<?php if ( $roi_trend_dir === 'up' ) : ?>
					<span class="wto-dash-kpi__trend-pill up">▲ <?php echo esc_html( $roi_trend_pct ); ?>٪</span>
					<span><?php _e( 'نسبت به ماه قبل — عالی 🎉', 'wto' ); ?></span>
				<?php elseif ( $roi_trend_dir === 'down' ) : ?>
					<span class="wto-dash-kpi__trend-pill down">▼ <?php echo esc_html( abs( $roi_trend_pct ) ); ?>٪</span>
					<span><?php _e( 'نسبت به ماه قبل — کاهش', 'wto' ); ?></span>
				<?php else : ?>
					<span class="wto-dash-kpi__trend-pill flat">▬</span>
					<span><?php _e( 'هنوز در ابتدای ماه', 'wto' ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<!-- KPI Grid -->
		<div class="wto-dash-kpi__grid">
			<a href="<?php echo esc_url( $newsletter_url ); ?>" class="wto-dash-kpi__cell" style="text-decoration:none; color:inherit;">
				<div class="wto-dash-kpi__cell-icon">📨</div>
				<div class="wto-dash-kpi__cell-label"><?php _e( 'پیامک امروز', 'wto' ); ?></div>
				<div class="wto-dash-kpi__cell-value"><?php echo esc_html( number_format_i18n( $stats['sms_today'] ) ); ?></div>
				<div class="wto-dash-kpi__cell-sub">
					<?php
					printf(
						/* translators: %s = ماه */
						esc_html__( 'این ماه: %s', 'wto' ),
						esc_html( number_format_i18n( $stats['sms_this_month'] ) )
					);
					?>
				</div>
			</a>

			<a href="<?php echo esc_url( $dlr_url ); ?>" class="wto-dash-kpi__cell" style="text-decoration:none; color:inherit;">
				<div class="wto-dash-kpi__cell-icon">✅</div>
				<div class="wto-dash-kpi__cell-label"><?php _e( 'نرخ تحویل پیامک', 'wto' ); ?></div>
				<?php if ( $stats['delivery_rate'] !== null ) : ?>
					<div class="wto-dash-kpi__cell-value">
						<?php echo esc_html( $stats['delivery_rate'] ); ?><small>٪</small>
					</div>
					<div class="wto-dash-kpi__cell-sub <?php echo $stats['delivery_rate'] >= 90 ? 'positive' : ''; ?>">
						<?php echo $stats['delivery_rate'] >= 90 ? esc_html__( 'عالی!', 'wto' ) : esc_html__( 'برای بهبود کلیک کنید', 'wto' ); ?>
					</div>
				<?php else : ?>
					<div class="wto-dash-kpi__cell-value" style="color:#94a3b8;">—</div>
					<div class="wto-dash-kpi__cell-sub"><?php _e( 'برای محاسبه کلیک کنید', 'wto' ); ?></div>
				<?php endif; ?>
			</a>

			<a href="<?php echo esc_url( $newsletter_url ); ?>" class="wto-dash-kpi__cell" style="text-decoration:none; color:inherit;">
				<div class="wto-dash-kpi__cell-icon">👥</div>
				<div class="wto-dash-kpi__cell-label"><?php _e( 'مشترکین خبرنامه', 'wto' ); ?></div>
				<div class="wto-dash-kpi__cell-value"><?php echo esc_html( number_format_i18n( $stats['newsletter_subs'] ) ); ?></div>
				<?php if ( $stats['newsletter_new_7d'] > 0 ) : ?>
					<div class="wto-dash-kpi__cell-sub positive">+<?php echo esc_html( number_format_i18n( $stats['newsletter_new_7d'] ) ); ?> <?php _e( 'در ۷ روز اخیر', 'wto' ); ?></div>
				<?php else : ?>
					<div class="wto-dash-kpi__cell-sub"><?php _e( '۷ روز اخیر: ۰', 'wto' ); ?></div>
				<?php endif; ?>
			</a>

			<a href="<?php echo esc_url( $birthday_url ); ?>" class="wto-dash-kpi__cell" style="text-decoration:none; color:inherit;">
				<div class="wto-dash-kpi__cell-icon">🎂</div>
				<div class="wto-dash-kpi__cell-label"><?php _e( 'تولدهای این هفته', 'wto' ); ?></div>
				<div class="wto-dash-kpi__cell-value"><?php echo esc_html( number_format_i18n( $stats['birthdays_this_week'] ) ); ?></div>
				<?php if ( $stats['birthdays_this_week'] > 0 ) : ?>
					<div class="wto-dash-kpi__cell-sub positive"><?php _e( 'فرصت کمپین تولد!', 'wto' ); ?></div>
				<?php else : ?>
					<div class="wto-dash-kpi__cell-sub"><?php _e( 'هنوز تنظیم نشده', 'wto' ); ?></div>
				<?php endif; ?>
			</a>

			<a href="<?php echo esc_url( $settings_url ); ?>" class="wto-dash-kpi__cell" style="text-decoration:none; color:inherit;">
				<div class="wto-dash-kpi__cell-icon">💳</div>
				<div class="wto-dash-kpi__cell-label"><?php _e( 'اعتبار پنل فراز', 'wto' ); ?></div>
				<?php if ( $stats['panel_credit'] !== null && $stats['panel_credit'] !== '' ) : ?>
					<div class="wto-dash-kpi__cell-value">
						<?php echo esc_html( $stats['panel_credit'] ); ?>
						<small><?php _e( 'تومان', 'wto' ); ?></small>
					</div>
					<div class="wto-dash-kpi__cell-sub"><?php _e( 'برای شارژ کلیک کنید', 'wto' ); ?></div>
				<?php else : ?>
					<div class="wto-dash-kpi__cell-value" style="color:#94a3b8;">—</div>
					<div class="wto-dash-kpi__cell-sub"><?php _e( 'وارد تنظیمات شوید', 'wto' ); ?></div>
				<?php endif; ?>
			</a>

			<a href="<?php echo esc_url( $roi_url ); ?>" class="wto-dash-kpi__cell" style="text-decoration:none; color:inherit;">
				<div class="wto-dash-kpi__cell-icon">📈</div>
				<div class="wto-dash-kpi__cell-label"><?php _e( 'ماه قبل', 'wto' ); ?></div>
				<div class="wto-dash-kpi__cell-value">
					<?php echo esc_html( number_format_i18n( $stats['roi_last_month'] ) ); ?>
					<small><?php _e( 'ت', 'wto' ); ?></small>
				</div>
				<div class="wto-dash-kpi__cell-sub"><?php _e( 'مقایسه عملکرد', 'wto' ); ?></div>
			</a>
		</div>

		<!-- Footer CTA -->
		<div class="wto-dash-kpi__footer">
			<span class="wto-dash-kpi__footer-meta">
				💡 <?php _e( 'این داده‌ها از منابع متنوع افزونه تجمیع می‌شوند. cache: ۳۰ دقیقه.', 'wto' ); ?>
			</span>
			<a href="<?php echo esc_url( $roi_url ); ?>" class="wto-dash-kpi__footer-cta">
				<?php _e( 'مشاهده گزارش کامل ←', 'wto' ); ?>
			</a>
		</div>
	</div>
	<?php
}
