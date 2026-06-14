<?php
/**
 * ROI Dashboard — v3.15.0
 *
 * صفحه «این ماه افزونه فراز چقدر فروش برات آورد؟» — تجمیع revenue واقعی
 * از منابع موجود (کش‌بک، سبد رها، تراکینگ، نظرسنجی) + کارت‌های breakdown.
 *
 * تمام query ها در یک transient 30 دقیقه‌ای cache می‌شوند — برای ۱۰۰k سایت
 * هر صفحه‌گشایی نباید heavy aggregation اجرا کند.
 *
 * HPOS-aware: total سفارش‌ها مستقیماً از wc_orders.total_amount می‌آید
 * اگر HPOS فعال است، وگرنه از postmeta._order_total.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_ROI_CACHE_TTL = 30 * MINUTE_IN_SECONDS;

// ============================================================================
// Helpers
// ============================================================================

/**
 * تشخیص فعال بودن HPOS — برای انتخاب جدول صحیح در aggregation.
 */
function wto_roi_hpos_enabled() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return false;
	}
	return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * مجموع total سفارش‌ها از روی لیست order_id ها — bulk query، HPOS-aware.
 * بدون N+1 — تنها یک query.
 */
function wto_roi_sum_order_totals( $order_ids ) {
	if ( empty( $order_ids ) ) {
		return 0.0;
	}
	global $wpdb;
	$order_ids = array_unique( array_map( 'intval', $order_ids ) );
	$order_ids = array_filter( $order_ids, function( $v ) { return $v > 0; } );
	if ( empty( $order_ids ) ) {
		return 0.0;
	}
	$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

	if ( wto_roi_hpos_enabled() ) {
		$table = $wpdb->prefix . 'wc_orders';
		$sql   = "SELECT COALESCE(SUM(total_amount), 0) FROM $table
		          WHERE id IN ($placeholders) AND status NOT IN ('trash','wc-cancelled','wc-refunded','wc-failed')";
	} else {
		$sql = "SELECT COALESCE(SUM(CAST(meta_value AS DECIMAL(20,2))), 0)
		        FROM {$wpdb->postmeta} pm
		        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		        WHERE pm.post_id IN ($placeholders)
		          AND pm.meta_key = '_order_total'
		          AND p.post_status NOT IN ('trash','wc-cancelled','wc-refunded','wc-failed')";
	}

	return (float) $wpdb->get_var( $wpdb->prepare( $sql, ...$order_ids ) );
}

/**
 * بازه‌های زمانی — کلیدها به انگلیسی، برچسب‌ها به فارسی.
 */
function wto_roi_get_date_ranges() {
	$now      = current_time( 'timestamp' );
	$today    = strtotime( 'today', $now );
	$end      = current_time( 'mysql' );

	$ranges = array(
		'this_month' => array(
			'label' => 'این ماه',
			'start' => date( 'Y-m-01 00:00:00', $now ),
			'end'   => $end,
		),
		'last_month' => array(
			'label' => 'ماه گذشته',
			'start' => date( 'Y-m-01 00:00:00', strtotime( 'first day of last month', $now ) ),
			'end'   => date( 'Y-m-t 23:59:59', strtotime( 'first day of last month', $now ) ),
		),
		'last_30' => array(
			'label' => '۳۰ روز اخیر',
			'start' => date( 'Y-m-d 00:00:00', $today - ( 30 * DAY_IN_SECONDS ) ),
			'end'   => $end,
		),
		'last_90' => array(
			'label' => '۹۰ روز اخیر',
			'start' => date( 'Y-m-d 00:00:00', $today - ( 90 * DAY_IN_SECONDS ) ),
			'end'   => $end,
		),
		'all' => array(
			'label' => 'همه (از روز نخست)',
			'start' => '2000-01-01 00:00:00',
			'end'   => $end,
		),
	);
	return $ranges;
}

// ============================================================================
// Data layer — tally هر منبع
// ============================================================================

/**
 * Aggregate آمار ROI در یک بازه — با transient cache.
 * این تابع heavy lifter اصلی است؛ فقط 3-4 query کوتاه اجرا می‌کند.
 */
function wto_roi_get_stats( $start, $end ) {
	$cache_key = 'wto_roi_v1_' . md5( $start . '|' . $end );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false && is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$stats = array(
		// منابع revenue مستقیم
		'cashback_revenue'        => 0.0,
		'cashback_orders'         => 0,
		'cashback_redeemed_sum'   => 0.0,
		'abandoned_revenue'       => 0.0,
		'abandoned_orders'        => 0,
		// SMS volume — اعتبار افزونه نه revenue
		'tracking_sms_count'      => 0,
		'survey_sms_count'        => 0,
		'survey_with_review'      => 0,
		'notify_subscribers'      => 0,
		// مجموع‌ها
		'total_revenue'           => 0.0,
		'total_sms_volume'        => 0,
		// متادیتا
		'start'                   => $start,
		'end'                     => $end,
		'generated_at'            => current_time( 'mysql' ),
	);

	// ---- ۱) کش‌بک ----
	$cashback_redemptions_table = $wpdb->prefix . 'wto_cashback_redemptions';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $cashback_redemptions_table ) ) === $cashback_redemptions_table ) {
		$cashback_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT order_id, SUM(amount) AS used_amount
			 FROM $cashback_redemptions_table
			 WHERE created_at BETWEEN %s AND %s
			 GROUP BY order_id",
			$start, $end
		), ARRAY_A );

		$ids = array();
		foreach ( $cashback_rows as $r ) {
			$ids[] = (int) $r['order_id'];
			$stats['cashback_redeemed_sum'] += (float) $r['used_amount'];
		}
		$stats['cashback_orders']   = count( $ids );
		$stats['cashback_revenue']  = wto_roi_sum_order_totals( $ids );
	}

	// ---- ۲) سبد خرید رها‌شده recovered ----
	$abandoned_table = $wpdb->prefix . 'wto_abandoned_carts';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $abandoned_table ) ) === $abandoned_table ) {
		// از خود table مقدار `total_value` ذخیره‌شده هنگام recovery می‌خوانیم — sufficient و سریع.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(total_value), 0) AS total
			 FROM $abandoned_table
			 WHERE status = 'recovered' AND recovered_at BETWEEN %s AND %s",
			$start, $end
		), ARRAY_A );
		if ( $row ) {
			$stats['abandoned_orders']  = (int) $row['cnt'];
			$stats['abandoned_revenue'] = (float) $row['total'];
		}
	}

	// ---- ۳) تراکینگ کد رهگیری — count فقط ----
	$tracking_table = $wpdb->prefix . 'wto_tracking_log';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tracking_table ) ) === $tracking_table ) {
		$stats['tracking_sms_count'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $tracking_table WHERE sent_at BETWEEN %s AND %s",
			$start, $end
		) );
	}

	// ---- ۴) نظرسنجی پس از خرید — count + conversion ----
	$survey_table = $wpdb->prefix . 'wto_survey_log';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $survey_table ) ) === $survey_table ) {
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS cnt,
			        SUM(CASE WHEN reviews_count > 0 THEN 1 ELSE 0 END) AS with_review
			 FROM $survey_table
			 WHERE dispatched_at BETWEEN %s AND %s
			   AND status IN ('scheduled','sent')",
			$start, $end
		), ARRAY_A );
		if ( $row ) {
			$stats['survey_sms_count']   = (int) $row['cnt'];
			$stats['survey_with_review'] = (int) $row['with_review'];
		}
	}

	// ---- ۵) موجود شد خبرم کن — subscribers active ----
	$notify_table = $wpdb->prefix . 'wto_notify_subscribers';
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $notify_table ) ) === $notify_table ) {
		$stats['notify_subscribers'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $notify_table WHERE subscribed_at BETWEEN %s AND %s",
			$start, $end
		) );
	}

	// مجموع‌ها
	$stats['total_revenue']    = $stats['cashback_revenue'] + $stats['abandoned_revenue'];
	$stats['total_sms_volume'] = $stats['tracking_sms_count'] + $stats['survey_sms_count'];

	set_transient( $cache_key, $stats, WTO_ROI_CACHE_TTL );
	return $stats;
}

/**
 * Invalidate همه ROI cache ها — هنگام تغییرات بنیادی (مثلاً ریست دستی).
 */
function wto_roi_invalidate_cache() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wto_roi_v1_%' OR option_name LIKE '_transient_timeout_wto_roi_v1_%'" );
}

// ============================================================================
// Submenu — اولویت بالا تا نزدیک ابتدای منو دیده شود
// ============================================================================

add_action( 'admin_menu', 'wto_roi_register_submenu', 12 );
function wto_roi_register_submenu() {
	add_submenu_page(
		'farazwto',
		'گزارش سودآوری افزونه',
		'💰 سود افزونه',
		'manage_options',
		'farazwto-roi',
		'wto_roi_render_page'
	);
}

// ============================================================================
// Render page
// ============================================================================

function wto_roi_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$ranges = wto_roi_get_date_ranges();
	$active = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : 'this_month';
	if ( ! isset( $ranges[ $active ] ) ) {
		$active = 'this_month';
	}
	$range = $ranges[ $active ];
	$stats = wto_roi_get_stats( $range['start'], $range['end'] );

	$apikey = get_option( 'wto_apikey', '' );
	?>
	<section class="wrapper">
		<div id="wto_header">
			<div>
				<a href="https://farazsms.com" target="_blank">
					<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt="پنل ارسال اس ام اس">
				</a>
			</div>
			<?php if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) :
				$credit = wto_get_credit(); ?>
				<div id="wto_account_info">
					<div class="wto_credit_amount">
						<span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?>
						<span> تومان</span>
					</div>
					<?php if ( function_exists( 'wto_render_profile_block' ) ) wto_render_profile_block(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( function_exists( 'wto_farazsms_panel_notice' ) ) wto_farazsms_panel_notice(); ?>

		<!-- Hero — عدد بزرگ مجموع revenue -->
		<div style="background:linear-gradient(135deg, #059669 0%, #10b981 60%, #34d399 100%); color:#fff; border-radius:18px; padding:32px 36px; margin-bottom:18px; direction:rtl; box-shadow:0 12px 32px rgba(5,150,105,0.22); position:relative; overflow:hidden;">
			<div style="position:absolute; top:-30px; left:-30px; font-size:220px; opacity:0.08; line-height:1;">💰</div>
			<div style="position:relative; z-index:2;">
				<div style="font-size:14px; opacity:0.92; margin-bottom:8px; font-weight:500;">
					<?php echo esc_html( $range['label'] ); ?> — افزونه فراز اس‌ام‌اس برای فروشگاه شما
				</div>
				<div style="font-size:46px; font-weight:800; line-height:1.1; margin-bottom:10px; letter-spacing:-1px;">
					<?php echo esc_html( number_format_i18n( $stats['total_revenue'] ) ); ?>
					<span style="font-size:24px; font-weight:500; opacity:0.85;">تومان</span>
				</div>
				<div style="font-size:15px; opacity:0.95; font-weight:500;">
					اضافه فروش آورد ✨
				</div>
				<?php if ( $stats['total_revenue'] === 0.0 ) : ?>
					<div style="margin-top:14px; background:rgba(255,255,255,0.18); padding:10px 14px; border-radius:8px; font-size:12.5px; line-height:1.7; backdrop-filter:blur(4px);">
						💡 هنوز هیچ سفارشی از طریق ماژول‌های افزونه (کش‌بک، سبد رها‌شده) ثبت نشده. این عدد به محض اولین برگشت مشتری از کش‌بک یا سبد رها شروع به رشد می‌کند.
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Date range pills -->
		<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; direction:rtl;">
			<?php foreach ( $ranges as $k => $r ) :
				$is_active = $k === $active;
				$url = add_query_arg( array( 'page' => 'farazwto-roi', 'range' => $k ), admin_url( 'admin.php' ) );
				?>
				<a href="<?php echo esc_url( $url ); ?>" style="
					background:<?php echo $is_active ? '#0f172a' : '#fff'; ?>;
					color:<?php echo $is_active ? '#fff' : '#475569'; ?>;
					border:1px solid <?php echo $is_active ? '#0f172a' : '#cbd5e1'; ?>;
					padding:9px 18px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;
					box-shadow:<?php echo $is_active ? '0 4px 12px rgba(15,23,42,0.22)' : 'none'; ?>;">
					<?php echo esc_html( $r['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<!-- منابع revenue — کارت‌های breakdown -->
		<h3 style="margin:14px 0 12px; font-size:15px; color:#0f172a; font-weight:700; direction:rtl;">📊 منابع درآمد افزونه</h3>
		<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-bottom:24px; direction:rtl;">

			<!-- کش‌بک -->
			<div style="background:#fff; border:1.5px solid #fde68a; border-radius:14px; padding:18px 20px; position:relative; overflow:hidden;">
				<div style="position:absolute; top:14px; left:14px; font-size:38px; opacity:0.9;">💰</div>
				<div style="font-size:12.5px; color:#92400e; font-weight:600; margin-bottom:6px;">سیستم کش‌بک</div>
				<div style="font-size:24px; font-weight:800; color:#0f172a; line-height:1.2; margin-bottom:4px;">
					<?php echo esc_html( number_format_i18n( $stats['cashback_revenue'] ) ); ?>
					<span style="font-size:13px; font-weight:500; color:#64748b;">تومان</span>
				</div>
				<div style="font-size:11.5px; color:#64748b; line-height:1.7;">
					<?php echo (int) $stats['cashback_orders']; ?> سفارش با کش‌بک تکمیل شد
					<br>
					<?php echo esc_html( number_format_i18n( $stats['cashback_redeemed_sum'] ) ); ?> تومان اعتبار مصرف شد
				</div>
			</div>

			<!-- سبد رهاشده -->
			<div style="background:#fff; border:1.5px solid #fecaca; border-radius:14px; padding:18px 20px; position:relative; overflow:hidden;">
				<div style="position:absolute; top:14px; left:14px; font-size:38px; opacity:0.9;">🛒</div>
				<div style="font-size:12.5px; color:#b91c1c; font-weight:600; margin-bottom:6px;">بازگشت سبد رهاشده</div>
				<div style="font-size:24px; font-weight:800; color:#0f172a; line-height:1.2; margin-bottom:4px;">
					<?php echo esc_html( number_format_i18n( $stats['abandoned_revenue'] ) ); ?>
					<span style="font-size:13px; font-weight:500; color:#64748b;">تومان</span>
				</div>
				<div style="font-size:11.5px; color:#64748b; line-height:1.7;">
					<?php echo (int) $stats['abandoned_orders']; ?> سفارش از سبدهای رهاشده برگشت
				</div>
			</div>

			<!-- مجموع revenue -->
			<div style="background:linear-gradient(135deg, #ecfeff 0%, #f0f9ff 100%); border:1.5px solid #67e8f9; border-radius:14px; padding:18px 20px; position:relative; overflow:hidden;">
				<div style="position:absolute; top:14px; left:14px; font-size:38px; opacity:0.9;">📈</div>
				<div style="font-size:12.5px; color:#0e7490; font-weight:600; margin-bottom:6px;">مجموع درآمد</div>
				<div style="font-size:24px; font-weight:800; color:#0f172a; line-height:1.2; margin-bottom:4px;">
					<?php echo esc_html( number_format_i18n( $stats['total_revenue'] ) ); ?>
					<span style="font-size:13px; font-weight:500; color:#64748b;">تومان</span>
				</div>
				<div style="font-size:11.5px; color:#64748b; line-height:1.7;">
					<?php echo (int) ( $stats['cashback_orders'] + $stats['abandoned_orders'] ); ?> سفارش در مجموع
				</div>
			</div>
		</div>

		<!-- اعتبار و مشارکت — KPI های secondary -->
		<h3 style="margin:14px 0 12px; font-size:15px; color:#0f172a; font-weight:700; direction:rtl;">📩 فعالیت پیامکی و engagement</h3>
		<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:24px; direction:rtl;">

			<div style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:16px 18px;">
				<div style="font-size:32px; line-height:1; margin-bottom:8px;">📮</div>
				<div style="font-size:12px; color:#4338ca; font-weight:600; margin-bottom:4px;">کد رهگیری ارسال شد</div>
				<div style="font-size:22px; font-weight:800; color:#0f172a;"><?php echo esc_html( number_format_i18n( $stats['tracking_sms_count'] ) ); ?></div>
				<div style="font-size:11px; color:#94a3b8; margin-top:4px;">سفارش — اعتماد مشتری</div>
			</div>

			<div style="background:#fff; border:1.5px solid #fbcfe8; border-radius:12px; padding:16px 18px;">
				<div style="font-size:32px; line-height:1; margin-bottom:8px;">⭐</div>
				<div style="font-size:12px; color:#be185d; font-weight:600; margin-bottom:4px;">نظرسنجی ارسال شد</div>
				<div style="font-size:22px; font-weight:800; color:#0f172a;"><?php echo esc_html( number_format_i18n( $stats['survey_sms_count'] ) ); ?></div>
				<div style="font-size:11px; color:#94a3b8; margin-top:4px;">
					<?php echo (int) $stats['survey_with_review']; ?> پاسخ دریافت شد
					<?php if ( $stats['survey_sms_count'] > 0 ) :
						$rate = round( ( $stats['survey_with_review'] / $stats['survey_sms_count'] ) * 100, 1 ); ?>
						(<?php echo esc_html( $rate ); ?>٪)
					<?php endif; ?>
				</div>
			</div>

			<div style="background:#fff; border:1.5px solid #bbf7d0; border-radius:12px; padding:16px 18px;">
				<div style="font-size:32px; line-height:1; margin-bottom:8px;">🔔</div>
				<div style="font-size:12px; color:#15803d; font-weight:600; margin-bottom:4px;">مشترک «خبرم کن»</div>
				<div style="font-size:22px; font-weight:800; color:#0f172a;"><?php echo esc_html( number_format_i18n( $stats['notify_subscribers'] ) ); ?></div>
				<div style="font-size:11px; color:#94a3b8; margin-top:4px;">منتظر موجودی مجدد</div>
			</div>

			<div style="background:#fff; border:1.5px solid #cbd5e1; border-radius:12px; padding:16px 18px;">
				<div style="font-size:32px; line-height:1; margin-bottom:8px;">📊</div>
				<div style="font-size:12px; color:#475569; font-weight:600; margin-bottom:4px;">مجموع پیامک‌های ماژول</div>
				<div style="font-size:22px; font-weight:800; color:#0f172a;"><?php echo esc_html( number_format_i18n( $stats['total_sms_volume'] ) ); ?></div>
				<div style="font-size:11px; color:#94a3b8; margin-top:4px;">تراکینگ + نظرسنجی</div>
			</div>
		</div>

		<!-- متن دعوت به اشتراک‌گذاری -->
		<?php if ( $stats['total_revenue'] > 0 ) : ?>
			<div style="background:linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%); border:1.5px solid #fde68a; border-radius:14px; padding:18px 22px; direction:rtl; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
				<div style="font-size:44px; line-height:1;">🎉</div>
				<div style="flex:1; min-width:220px;">
					<div style="font-size:14.5px; font-weight:700; color:#713f12; margin-bottom:4px;">
						افزونه فراز برای فروشگاه شما نتیجه داد!
					</div>
					<div style="font-size:12.5px; color:#92400e; line-height:1.7;">
						اگر این داشبورد را مفید می‌بینید، با امتیاز ۵ ستاره در مخزن وردپرس به ما کمک کنید تا فروشگاه‌داران بیشتری هم بتوانند از این قابلیت‌ها استفاده کنند.
					</div>
				</div>
				<a href="https://wordpress.org/support/plugin/farazsms/reviews/?rate=5#new-post" target="_blank" rel="noopener" style="background:#dc2626; color:#fff; padding:10px 22px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:700; box-shadow:0 4px 12px rgba(220,38,38,0.25);">
					⭐ ثبت نظر
				</a>
			</div>
		<?php endif; ?>

		<!-- توضیح روش محاسبه -->
		<details style="margin-top:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; direction:rtl; font-size:12px; color:#475569; line-height:1.8;">
			<summary style="cursor:pointer; font-weight:600; color:#0f172a; font-size:12.5px;">🔍 روش محاسبه این اعداد چیست؟</summary>
			<div style="margin-top:10px;">
				<strong>سیستم کش‌بک:</strong> مجموع total سفارش‌هایی که در آن کاربر از اعتبار کش‌بک خود استفاده کرده است.
				<br>
				<strong>سبد رهاشده:</strong> مجموع <code>total_value</code> سبدهای ثبت‌شده در جدول
				<code>wto_abandoned_carts</code>
				که status آن‌ها
				<code>recovered</code>
				شده است.
				<br>
				<strong>کد رهگیری / نظرسنجی / خبرم کن:</strong> صرفاً تعداد فعالیت — به‌عنوان «اعتماد و engagement»، نه revenue.
				<br>
				<strong>cache:</strong> این اعداد ۳۰ دقیقه cache می‌شوند تا روی سایت‌های با حجم بالا فشار query نیاید.
				تاریخ آخرین به‌روزرسانی این داشبورد:
				<code><?php echo esc_html( $stats['generated_at'] ); ?></code>
				<br>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wto_roi_clear_cache', '1' ), 'wto_roi_clear_cache' ) ); ?>" style="color:#dc2626; font-size:11px;">↻ پاک کردن cache و محاسبه مجدد</a>
			</div>
		</details>
	</section>
	<?php
}

// ============================================================================
// Cache clear handler
// ============================================================================

add_action( 'admin_init', 'wto_roi_handle_cache_clear' );
function wto_roi_handle_cache_clear() {
	if ( ! isset( $_GET['wto_roi_clear_cache'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;
	check_admin_referer( 'wto_roi_clear_cache' );
	wto_roi_invalidate_cache();
	wp_safe_redirect( remove_query_arg( array( 'wto_roi_clear_cache', '_wpnonce' ) ) );
	exit;
}
