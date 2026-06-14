<?php
/**
 * Data Archival / Cleanup — v3.18.0
 *
 * چرا این فایل؟
 *  جدول‌های log افزونه روی سایت‌های پرترافیک بدون cleanup می‌توانند به میلیون‌ها
 *  رکورد برسند. این:
 *    - DB سایت کاربر را sluggish می‌کند
 *    - backup را سنگین می‌کند
 *    - گزارش‌گیری ROI/DLR را کند می‌کند
 *
 *  این فایل cron هفتگی اجرا می‌کند که رکوردهای قدیمی‌تر از retention period را
 *  DELETE می‌کند. برای پیشگیری از اشتباه:
 *    - retention پیش‌فرض ۶ ماه است (نه ۱ ماه)
 *    - فقط رکوردهای «نهایی‌شده» (recovered/expired/sent) پاک می‌شوند، نه active
 *    - LIMIT روی هر run تا مسبب timeout نشود
 *
 *  کاربر می‌تواند با constant این رفتار را override کند:
 *    define( 'WTO_DATA_RETENTION_DAYS', 365 ); // در wp-config.php
 *    define( 'WTO_DATA_ARCHIVAL_DISABLED', true );
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_ARCHIVAL_CRON_HOOK = 'wto_data_archival_cron';

/**
 * تعداد روزهای نگهداری — پیش‌فرض ۱۸۰ روز (۶ ماه). قابل override در wp-config.php.
 */
function wto_archival_retention_days() {
	if ( defined( 'WTO_DATA_RETENTION_DAYS' ) ) {
		return max( 30, (int) WTO_DATA_RETENTION_DAYS );
	}
	return 180;
}

/**
 * آیا cron archival فعال است؟ کاربر می‌تواند با constant خاموش کند.
 */
function wto_archival_is_enabled() {
	if ( defined( 'WTO_DATA_ARCHIVAL_DISABLED' ) && WTO_DATA_ARCHIVAL_DISABLED ) {
		return false;
	}
	return true;
}

// Schedule cron — weekly
add_action( 'init', 'wto_archival_schedule_cron' );
function wto_archival_schedule_cron() {
	if ( ! wto_archival_is_enabled() ) {
		// اگر disabled شد، event موجود را remove کن
		$ts = wp_next_scheduled( WTO_ARCHIVAL_CRON_HOOK );
		if ( $ts ) wp_unschedule_event( $ts, WTO_ARCHIVAL_CRON_HOOK );
		return;
	}
	if ( ! wp_next_scheduled( WTO_ARCHIVAL_CRON_HOOK ) ) {
		// شروع از یک هفته بعد تا با نصب اولیه conflict نکند
		wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', WTO_ARCHIVAL_CRON_HOOK );
	}
}

register_deactivation_hook(
	defined( 'WTO_PLUGIN_FILE' ) ? WTO_PLUGIN_FILE : __FILE__,
	'wto_archival_clear_cron'
);
function wto_archival_clear_cron() {
	$ts = wp_next_scheduled( WTO_ARCHIVAL_CRON_HOOK );
	if ( $ts ) wp_unschedule_event( $ts, WTO_ARCHIVAL_CRON_HOOK );
}

add_action( WTO_ARCHIVAL_CRON_HOOK, 'wto_archival_run' );

/**
 * اجرای cleanup روی همه‌ی جدول‌های قابل آرشیو.
 * هر یک با LIMIT 5000 تا حداکثر زمان اجرا را محدود کنیم.
 */
function wto_archival_run() {
	if ( ! wto_archival_is_enabled() ) {
		return;
	}

	$days   = wto_archival_retention_days();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	$stats  = array(
		'tracking_log'        => 0,
		'abandoned_recovered' => 0,
		'abandoned_expired'   => 0,
		'survey_targets'      => 0,
		'newsletter_unsubs'   => 0,
		'started_at'          => current_time( 'mysql' ),
		'cutoff'              => $cutoff,
		'retention_days'      => $days,
	);

	global $wpdb;
	$lim = 5000;

	// ---- ۱) tracking_log: همه‌ی رکوردهای قدیمی‌تر از retention ----
	$t = $wpdb->prefix . 'wto_tracking_log';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) ) === $t ) {
		$stats['tracking_log'] = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $t WHERE sent_at < %s LIMIT %d",
			$cutoff, $lim
		) );
	}

	// ---- ۲) abandoned_carts: status=recovered یا expired ----
	$t = $wpdb->prefix . 'wto_abandoned_carts';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) ) === $t ) {
		// recovered: بر اساس recovered_at
		$stats['abandoned_recovered'] = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $t WHERE status = 'recovered' AND recovered_at < %s LIMIT %d",
			$cutoff, $lim
		) );
		// expired/cancelled: بر اساس updated_at
		$stats['abandoned_expired'] = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $t WHERE status IN ('expired','cancelled') AND updated_at < %s LIMIT %d",
			$cutoff, $lim
		) );
	}

	// ---- ۳) survey_targets: همه‌ی رکوردهای قدیمی (نظرسنجی نتیجه‌اش گرفته شده) ----
	$t = $wpdb->prefix . 'wto_survey_targets';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) ) === $t ) {
		$stats['survey_targets'] = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $t WHERE dispatched_at < %s LIMIT %d",
			$cutoff, $lim
		) );
	}

	// ---- ۴) newsletter unsubscribed خیلی قدیمی (>۱ سال) ----
	//      گذاشتیم مدت طولانی‌تر چون فاصله از آخرین interaction مهم است.
	$t = $wpdb->prefix . 'wto_newsletter_subscribers';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) ) === $t ) {
		$year_cutoff = gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS );
		$stats['newsletter_unsubs'] = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $t WHERE status = 'unsubscribed' AND unsubscribed_at < %s LIMIT %d",
			$year_cutoff, $lim
		) );
	}

	$stats['finished_at'] = current_time( 'mysql' );
	$stats['total_purged'] = array_sum( array(
		$stats['tracking_log'],
		$stats['abandoned_recovered'],
		$stats['abandoned_expired'],
		$stats['survey_targets'],
		$stats['newsletter_unsubs'],
	) );

	update_option( 'wto_archival_last_run', $stats, false );
}
