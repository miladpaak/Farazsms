<?php
/**
 * DLR Dashboard — v3.17.2
 *
 * «به دست مشتری رسید؟» — مهم‌ترین معیار اعتماد به پنل SMS.
 * با تجمیع وضعیت item-level از endpoint `/send_request/{id}/items`،
 * کاربر بالاخره می‌فهمد:
 *
 *   ✅ چند پیامک واقعاً به گوشی رسید
 *   ⏳ چند تا در صف مخابرات گیر کرده
 *   ❌ چند تا شماره خاموش/invalid بود
 *   🚫 چند تا در blacklist است
 *   📞 کدام شماره‌ها مکرر fail می‌شوند (dirty data)
 *
 * استراتژی performance برای ۱۰۰k سایت:
 *  - Aggregate در transient cache (۳۰ دقیقه)
 *  - فقط N آخرین send_request (پیش‌فرض ۱۰) را fetch می‌کند
 *  - برای هر request، حداکثر 100 item نمونه می‌گیرد (نمونه‌گیری منصفانه برای رقم بزرگ)
 *  - دکمه refresh دستی + cron اختیاری ۶ ساعته
 *
 * وابستگی: wto_send_reports_api_get() از wto-send-reports.php
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_DLR_CACHE_KEY      = 'wto_dlr_aggregates_v1';
const WTO_DLR_CACHE_TTL      = 30 * MINUTE_IN_SECONDS;
const WTO_DLR_FETCH_REQUESTS = 10;
const WTO_DLR_FETCH_ITEMS    = 100;
const WTO_DLR_CRON_HOOK      = 'wto_dlr_refresh';

// v3.17.6: standalone submenu حذف شد — DLR حالا به‌صورت تب در farazwto-reports
// رندر می‌شود. تابع wto_dlr_render_content() محتوای صفحه را برمی‌گرداند.

// ============================================================================
// Data layer — fetch + aggregate
// ============================================================================

/**
 * Fetch آخرین send_requests و item-level deliveries و aggregate آنها.
 * هیچ fallback بدون cache — هر بار صدا زده شود، N+1 API call می‌زند.
 * Caller ها باید از طریق wto_dlr_get_stats() که cache دارد دسترسی بگیرند.
 */
function wto_dlr_fetch_and_aggregate( $limit_requests = WTO_DLR_FETCH_REQUESTS, $items_per_request = WTO_DLR_FETCH_ITEMS ) {
	if ( ! function_exists( 'wto_send_reports_api_get' ) ) {
		return new WP_Error( 'missing_dependency', 'ماژول گزارشات ارسال در دسترس نیست.' );
	}

	$start = microtime( true );

	// آخرین send_requests
	$list = wto_send_reports_api_get( 'send_request', array(
		'page'  => 1,
		'limit' => $limit_requests,
	) );

	if ( empty( $list['success'] ) ) {
		return new WP_Error( 'api_fail', $list['message'] ?? 'خطا در API' );
	}

	// استخراج آرایه requests از پاسخ (انعطاف نسبت به شکل پاسخ)
	$data     = isset( $list['data'] ) ? $list['data'] : array();
	$requests = array();
	if ( is_array( $data ) ) {
		foreach ( array( 'items', 'list', 'rows', 'data' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				$requests = $data[ $k ];
				break;
			}
		}
		if ( empty( $requests ) && array_values( $data ) === $data ) {
			$requests = $data;
		}
	}

	$stats = array(
		'generated_at'       => current_time( 'mysql' ),
		'requests_examined'  => count( $requests ),
		'items_examined'     => 0,
		'by_status'          => array(),  // status → count
		'recent_requests'    => array(),  // [ {id, date, sent, delivered, failed, total} ]
		'problem_mobiles'    => array(),  // mobile → fail_count
		'duration_ms'        => 0,
	);

	$item_statuses = function_exists( 'wto_send_reports_item_statuses' )
		? wto_send_reports_item_statuses() : array();

	$mobile_fails = array();

	foreach ( $requests as $req ) {
		$req = is_array( $req ) ? $req : array();
		$rid = wto_send_reports_find( $req, array( 'id', 'send_request_id', 'sendRequestId', 'request_id', 'requestId' ) );
		if ( $rid === '' || ! ctype_digit( (string) $rid ) ) continue;

		$date_raw = wto_send_reports_find( $req, array(
			'created_at', 'createdAt', 'submit_time', 'submitTime',
			'send_time', 'sendTime', 'date', 'datetime', 'created',
		) );

		// items per recipient
		$items_resp = wto_send_reports_api_get(
			'send_request/' . rawurlencode( (string) $rid ) . '/items',
			array( 'page' => 1, 'limit' => $items_per_request )
		);
		if ( empty( $items_resp['success'] ) ) continue;

		$idata = isset( $items_resp['data'] ) ? $items_resp['data'] : array();
		$items = array();
		if ( is_array( $idata ) ) {
			foreach ( array( 'items', 'list', 'rows', 'data' ) as $k ) {
				if ( isset( $idata[ $k ] ) && is_array( $idata[ $k ] ) ) {
					$items = $idata[ $k ];
					break;
				}
			}
			if ( empty( $items ) && array_values( $idata ) === $idata ) {
				$items = $idata;
			}
		}

		$req_counts = array();
		foreach ( $items as $item ) {
			$item   = is_array( $item ) ? $item : array();
			$istat  = (string) wto_send_reports_find( $item, array( 'status', 'state', 'delivery_status', 'deliveryStatus' ) );
			$imob   = (string) wto_send_reports_find( $item, array( 'mobile', 'recipient', 'phone', 'msisdn', 'number' ) );

			if ( $istat === '' ) $istat = 'unknown';

			// aggregate global
			if ( ! isset( $stats['by_status'][ $istat ] ) ) $stats['by_status'][ $istat ] = 0;
			$stats['by_status'][ $istat ]++;
			$stats['items_examined']++;

			// aggregate per-request
			if ( ! isset( $req_counts[ $istat ] ) ) $req_counts[ $istat ] = 0;
			$req_counts[ $istat ]++;

			// problem mobiles
			if ( in_array( $istat, array( 'send-failure', 'delivery-failure', 'system-error', 'blacklist' ), true ) ) {
				if ( $imob !== '' ) {
					if ( ! isset( $mobile_fails[ $imob ] ) ) $mobile_fails[ $imob ] = 0;
					$mobile_fails[ $imob ]++;
				}
			}
		}

		$delivered = ( $req_counts['delivered'] ?? 0 ) + ( $req_counts['sent'] ?? 0 );
		$failed    = ( $req_counts['send-failure'] ?? 0 ) + ( $req_counts['delivery-failure'] ?? 0 ) + ( $req_counts['system-error'] ?? 0 ) + ( $req_counts['blacklist'] ?? 0 );
		$total_r   = array_sum( $req_counts );

		$stats['recent_requests'][] = array(
			'id'        => (int) $rid,
			'date'      => $date_raw !== '' && function_exists( 'wto_send_reports_to_jalali' )
				? wto_send_reports_to_jalali( $date_raw ) : (string) $date_raw,
			'total'     => $total_r,
			'delivered' => $delivered,
			'failed'    => $failed,
			'in_queue'  => $req_counts['in-queue'] ?? 0,
		);
	}

	// Top 10 problem mobiles
	arsort( $mobile_fails );
	$stats['problem_mobiles'] = array_slice( $mobile_fails, 0, 10, true );

	$stats['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

	set_transient( WTO_DLR_CACHE_KEY, $stats, WTO_DLR_CACHE_TTL );
	return $stats;
}

/**
 * گرفتن آمار — اول از cache، اگر نبود null برمی‌گرداند.
 * (نباید روی page load auto-fetch کنیم — کاربر باید دستی refresh بزند.)
 */
function wto_dlr_get_stats() {
	$cached = get_transient( WTO_DLR_CACHE_KEY );
	return $cached !== false ? $cached : null;
}

/**
 * محاسبه‌ی نرخ تحویل کلی (٪) از stats.
 */
function wto_dlr_calc_delivery_rate( $stats ) {
	if ( empty( $stats['items_examined'] ) ) return 0;
	$delivered = ( $stats['by_status']['delivered'] ?? 0 ) + ( $stats['by_status']['sent'] ?? 0 );
	return round( ( $delivered / $stats['items_examined'] ) * 100, 1 );
}

// ============================================================================
// Refresh handler — دکمه «به‌روزرسانی»
// ============================================================================

add_action( 'admin_post_wto_dlr_refresh', 'wto_dlr_handle_refresh' );
function wto_dlr_handle_refresh() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	check_admin_referer( 'wto_dlr_refresh' );

	delete_transient( WTO_DLR_CACHE_KEY );
	$result = wto_dlr_fetch_and_aggregate();

	$msg = is_wp_error( $result )
		? rawurlencode( $result->get_error_message() )
		: '1';
	wp_safe_redirect( add_query_arg(
		is_wp_error( $result ) ? array( 'dlr_error' => $msg ) : array( 'dlr_refreshed' => $msg ),
		wp_get_referer() ?: admin_url( 'admin.php?page=farazwto-dlr' )
	) );
	exit;
}

// ============================================================================
// Cron — به‌روزرسانی خودکار ۶ ساعته (اختیاری، silent)
// ============================================================================

add_filter( 'cron_schedules', 'wto_dlr_cron_schedules' );
function wto_dlr_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['wto_six_hours'] ) ) {
		$schedules['wto_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'هر ۶ ساعت (فراز DLR)',
		);
	}
	return $schedules;
}

add_action( 'init', 'wto_dlr_schedule_cron' );
function wto_dlr_schedule_cron() {
	if ( ! wp_next_scheduled( WTO_DLR_CRON_HOOK ) ) {
		wp_schedule_event( time() + 300, 'wto_six_hours', WTO_DLR_CRON_HOOK );
	}
}

register_deactivation_hook(
	defined( 'WTO_PLUGIN_FILE' ) ? WTO_PLUGIN_FILE : __FILE__,
	'wto_dlr_clear_cron'
);
function wto_dlr_clear_cron() {
	$ts = wp_next_scheduled( WTO_DLR_CRON_HOOK );
	if ( $ts ) wp_unschedule_event( $ts, WTO_DLR_CRON_HOOK );
}

add_action( WTO_DLR_CRON_HOOK, 'wto_dlr_cron_run' );
function wto_dlr_cron_run() {
	// silent — اگر شکست خورد، transient قبلی باقی می‌ماند
	wto_dlr_fetch_and_aggregate();
}

// ============================================================================
// Render
// ============================================================================

/**
 * Standalone render — برای backward compatibility.
 */
function wto_dlr_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	echo '<section class="wrapper">';
	wto_dlr_render_content();
	echo '</section>';
}

/**
 * v3.17.6: محتوای صفحه DLR بدون wrapper — برای embed به‌صورت تب در گزارشات.
 */
function wto_dlr_render_content() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$stats  = wto_dlr_get_stats();
	$apikey = get_option( 'wto_apikey', '' );

	// labels و رنگ‌های وضعیت — هماهنگ با کلاس‌های موجود
	$status_label = function_exists( 'wto_send_reports_item_statuses' )
		? wto_send_reports_item_statuses() : array();
	$status_groups = array(
		'success' => array(
			'label' => '✅ تحویل شده / ارسال موفق',
			'color' => '#16a34a',
			'bg'    => '#dcfce7',
			'border'=> '#86efac',
			'keys'  => array( 'delivered', 'sent' ),
		),
		'queue' => array(
			'label' => '⏳ در صف ارسال',
			'color' => '#0369a1',
			'bg'    => '#dbeafe',
			'border'=> '#93c5fd',
			'keys'  => array( 'in-queue', 'not-started' ),
		),
		'failed' => array(
			'label' => '❌ ناموفق',
			'color' => '#dc2626',
			'bg'    => '#fee2e2',
			'border'=> '#fca5a5',
			'keys'  => array( 'send-failure', 'delivery-failure', 'system-error' ),
		),
		'blacklist' => array(
			'label' => '🚫 لیست سیاه / لغو',
			'color' => '#7c2d12',
			'bg'    => '#fed7aa',
			'border'=> '#fdba74',
			'keys'  => array( 'blacklist' ),
		),
		'unknown' => array(
			'label' => '❓ نامشخص',
			'color' => '#64748b',
			'bg'    => '#f1f5f9',
			'border'=> '#cbd5e1',
			'keys'  => array( 'delivery-undetermined', 'unknown' ),
		),
	);

	// اگر stats داریم، تعداد هر گروه را حساب کن
	$group_counts = array();
	if ( $stats ) {
		foreach ( $status_groups as $gk => $g ) {
			$cnt = 0;
			foreach ( $g['keys'] as $sk ) {
				$cnt += $stats['by_status'][ $sk ] ?? 0;
			}
			$group_counts[ $gk ] = $cnt;
		}
	}

	$delivery_rate = $stats ? wto_dlr_calc_delivery_rate( $stats ) : 0;
	?>
	<div style="direction:rtl;">
		<div style="display:none;">
			<?php // v3.17.6: header حذف شد چون داخل تب گزارشات embed می‌شود — header از parent استفاده می‌شود
			if ( false && ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) : $credit = wto_get_credit(); ?>
				<div id="wto_account_info"><div class="wto_credit_amount"><span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?><span> تومان</span></div><?php if ( function_exists( 'wto_render_profile_block' ) ) wto_render_profile_block(); ?></div>
			<?php endif; ?>
		</div>

		<?php if ( isset( $_GET['dlr_refreshed'] ) ) : ?>
			<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px;">✓ اطلاعات DLR با موفقیت به‌روز شد.</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['dlr_error'] ) ) : ?>
			<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:14px;">⚠ <?php echo esc_html( rawurldecode( $_GET['dlr_error'] ) ); ?></div>
		<?php endif; ?>

		<!-- Hero — نرخ تحویل کلی -->
		<?php if ( $stats && $stats['items_examined'] > 0 ) :
			$rate = $delivery_rate;
			$hero_gradient = $rate >= 90 ? 'linear-gradient(135deg, #059669 0%, #10b981 100%)'
				: ( $rate >= 75 ? 'linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%)'
					: 'linear-gradient(135deg, #dc2626 0%, #f97316 100%)' );
			?>
			<div style="background:<?php echo $hero_gradient; ?>; color:#fff; border-radius:18px; padding:30px 36px; margin-bottom:18px; box-shadow:0 12px 32px rgba(5,150,105,0.20); position:relative; overflow:hidden;">
				<div style="position:absolute; top:-20px; left:-30px; font-size:220px; opacity:0.08; line-height:1;">📬</div>
				<div style="position:relative; z-index:2;">
					<div style="font-size:13px; opacity:0.92; margin-bottom:8px; font-weight:500;">
						نرخ تحویل بر اساس <?php echo (int) $stats['items_examined']; ?> پیامک از <?php echo (int) $stats['requests_examined']; ?> ارسال اخیر
					</div>
					<div style="font-size:54px; font-weight:800; line-height:1; margin-bottom:8px; letter-spacing:-1px;">
						<?php echo esc_html( $rate ); ?>٪
					</div>
					<div style="font-size:15px; opacity:0.95; font-weight:500;">
						<?php if ( $rate >= 90 ) : ?>
							🎉 عالی! اکثر پیامک‌ها به مشتری می‌رسد.
						<?php elseif ( $rate >= 75 ) : ?>
							👍 خوب، ولی فضای بهبود وجود دارد.
						<?php else : ?>
							⚠ نرخ پایین — لیست شماره‌های دیتابیس را تمیز کنید.
						<?php endif; ?>
					</div>

					<!-- نوار پروگرس visual -->
					<div style="margin-top:18px; background:rgba(255,255,255,0.18); border-radius:10px; padding:4px; backdrop-filter:blur(4px);">
						<div style="background:rgba(255,255,255,0.95); height:10px; border-radius:7px; width:<?php echo (float) $rate; ?>%; transition:width 1s;"></div>
					</div>
				</div>
			</div>
		<?php else : ?>
			<div style="background:linear-gradient(135deg, #4338ca 0%, #6366f1 100%); color:#fff; border-radius:18px; padding:30px 36px; margin-bottom:18px; box-shadow:0 12px 32px rgba(67,56,202,0.18);">
				<div style="display:flex; gap:18px; align-items:center; flex-wrap:wrap;">
					<div style="font-size:60px;">📬</div>
					<div style="flex:1; min-width:240px;">
						<h2 style="margin:0 0 6px; font-size:22px; font-weight:800;">داشبورد وضعیت تحویل پیامک</h2>
						<div style="font-size:13.5px; opacity:0.93; line-height:1.7;">
							ببینید چند درصد پیامک‌هایتان واقعاً به گوشی مشتری رسید — فراتر از فقط «ارسال شد».
							اولین بار است؟ روی دکمه پایین کلیک کنید.
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<!-- Refresh bar -->
		<div style="background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
			<div style="flex:1; min-width:200px;">
				<div style="font-size:13px; color:#0f172a; font-weight:600;">
					<?php if ( $stats ) : ?>
						📊 آخرین به‌روزرسانی:
						<code style="background:#f1f5f9; padding:2px 8px; border-radius:5px; direction:ltr;"><?php echo esc_html( $stats['generated_at'] ); ?></code>
						— زمان aggregation:
						<strong><?php echo (int) ( $stats['duration_ms'] / 1000 ); ?> ثانیه</strong>
					<?php else : ?>
						🔄 هنوز هیچ aggregation انجام نشده. دکمه «به‌روزرسانی» را بزنید.
					<?php endif; ?>
				</div>
				<div style="font-size:11.5px; color:#94a3b8; margin-top:4px;">
					به‌صورت خودکار هر ۶ ساعت یک‌بار به‌روز می‌شود. cache: ۳۰ دقیقه.
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
				<input type="hidden" name="action" value="wto_dlr_refresh">
				<?php wp_nonce_field( 'wto_dlr_refresh' ); ?>
				<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:10px 22px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(67,56,202,0.22);">
					🔄 به‌روزرسانی اکنون
				</button>
			</form>
		</div>

		<?php if ( $stats && $stats['items_examined'] > 0 ) : ?>

			<!-- Breakdown cards -->
			<h3 style="margin:6px 0 12px; font-size:15px; color:#0f172a; font-weight:700;">📊 تفکیک وضعیت پیامک‌ها</h3>
			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:24px;">
				<?php foreach ( $status_groups as $gk => $g ) :
					$cnt = $group_counts[ $gk ] ?? 0;
					$pct = $stats['items_examined'] > 0 ? round( ( $cnt / $stats['items_examined'] ) * 100, 1 ) : 0;
					?>
					<div style="background:#fff; border:1.5px solid <?php echo esc_attr( $g['border'] ); ?>; border-radius:14px; padding:16px 18px; position:relative; overflow:hidden;">
						<div style="position:absolute; top:0; right:0; height:4px; width:<?php echo (float) $pct; ?>%; background:<?php echo esc_attr( $g['color'] ); ?>;"></div>
						<div style="font-size:12px; color:<?php echo esc_attr( $g['color'] ); ?>; font-weight:700; margin-bottom:6px;">
							<?php echo esc_html( $g['label'] ); ?>
						</div>
						<div style="font-size:28px; font-weight:800; color:#0f172a; line-height:1.1; margin-bottom:3px;">
							<?php echo esc_html( number_format_i18n( $cnt ) ); ?>
						</div>
						<div style="font-size:12px; color:#64748b; font-weight:600;">
							<?php echo esc_html( $pct ); ?>٪ از کل
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Recent sends bar list -->
			<?php if ( ! empty( $stats['recent_requests'] ) ) : ?>
				<h3 style="margin:18px 0 12px; font-size:15px; color:#0f172a; font-weight:700;">📨 آخرین ارسال‌ها</h3>
				<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; margin-bottom:24px;">
					<?php foreach ( $stats['recent_requests'] as $i => $r ) :
						if ( $r['total'] === 0 ) continue;
						$rate = round( ( $r['delivered'] / $r['total'] ) * 100, 1 );
						$detail_url = admin_url( 'admin.php?page=farazwto-reports&view=detail&id=' . $r['id'] );
						$bar_color = $rate >= 90 ? '#16a34a' : ( $rate >= 75 ? '#f59e0b' : '#dc2626' );
						?>
						<div style="padding:14px 18px; border-bottom:<?php echo $i < count( $stats['recent_requests'] ) - 1 ? '1px solid #f1f5f9' : '0'; ?>;">
							<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:10px;">
								<div style="font-size:13px;">
									<a href="<?php echo esc_url( $detail_url ); ?>" style="color:#4338ca; text-decoration:none; font-weight:700;">#<?php echo (int) $r['id']; ?></a>
									<span style="color:#94a3b8; font-size:11.5px; margin:0 8px;">|</span>
									<span style="color:#64748b; font-size:11.5px;"><?php echo esc_html( $r['date'] ); ?></span>
									<span style="color:#94a3b8; font-size:11.5px; margin:0 8px;">|</span>
									<span style="color:#64748b; font-size:11.5px;"><?php echo number_format_i18n( $r['total'] ); ?> گیرنده</span>
								</div>
								<div style="font-size:13px; font-weight:700; color:<?php echo $bar_color; ?>;">
									<?php echo esc_html( $rate ); ?>٪ تحویل
								</div>
							</div>
							<div style="background:#f1f5f9; height:8px; border-radius:5px; overflow:hidden; display:flex;">
								<div style="background:<?php echo $bar_color; ?>; width:<?php echo (float) $rate; ?>%;" title="تحویل: <?php echo (int) $r['delivered']; ?>"></div>
								<?php
								$queue_pct = round( ( $r['in_queue'] / $r['total'] ) * 100, 1 );
								$fail_pct  = round( ( $r['failed'] / $r['total'] ) * 100, 1 );
								?>
								<?php if ( $queue_pct > 0 ) : ?>
									<div style="background:#3b82f6; width:<?php echo (float) $queue_pct; ?>%;" title="در صف: <?php echo (int) $r['in_queue']; ?>"></div>
								<?php endif; ?>
								<?php if ( $fail_pct > 0 ) : ?>
									<div style="background:#dc2626; width:<?php echo (float) $fail_pct; ?>%;" title="ناموفق: <?php echo (int) $r['failed']; ?>"></div>
								<?php endif; ?>
							</div>
							<div style="margin-top:6px; font-size:11px; color:#94a3b8;">
								<span style="color:#16a34a;">●</span> <?php echo (int) $r['delivered']; ?> تحویل
								&nbsp;
								<span style="color:#3b82f6;">●</span> <?php echo (int) $r['in_queue']; ?> در صف
								&nbsp;
								<span style="color:#dc2626;">●</span> <?php echo (int) $r['failed']; ?> ناموفق
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Problem mobiles alert -->
			<?php if ( ! empty( $stats['problem_mobiles'] ) ) : ?>
				<div style="background:linear-gradient(135deg, #fef3c7 0%, #fed7aa 100%); border:1.5px solid #fdba74; border-radius:14px; padding:18px 22px; margin-bottom:18px;">
					<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
						<div style="font-size:32px;">⚠</div>
						<div>
							<h3 style="margin:0; font-size:15px; color:#7c2d12; font-weight:700;">شماره‌های مشکل‌دار — دیتابیس آلوده</h3>
							<div style="font-size:12px; color:#9a3412; margin-top:3px;">
								این شماره‌ها در ارسال‌های اخیر مکرر fail شده‌اند. حذف یا اصلاح آن‌ها = پس‌انداز اعتبار.
							</div>
						</div>
					</div>
					<div style="background:#fff; border-radius:10px; overflow:hidden;">
						<table style="width:100%; border-collapse:collapse; font-size:12.5px;">
							<thead style="background:#fffbeb;">
								<tr>
									<th style="text-align:right; padding:10px 14px; border-bottom:1px solid #fed7aa; color:#7c2d12; font-weight:700;">شماره موبایل</th>
									<th style="text-align:right; padding:10px 14px; border-bottom:1px solid #fed7aa; color:#7c2d12; font-weight:700;">تعداد دفعات fail</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $stats['problem_mobiles'] as $mobile => $cnt ) : ?>
									<tr style="border-bottom:1px solid #fef3c7;">
										<td style="padding:9px 14px; direction:ltr; text-align:right; font-family:Menlo,Consolas,monospace; color:#0f172a; font-weight:600;"><?php echo esc_html( $mobile ); ?></td>
										<td style="padding:9px 14px;"><strong style="color:#dc2626;"><?php echo (int) $cnt; ?> بار</strong></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>

		<?php elseif ( $stats && $stats['items_examined'] === 0 ) : ?>
			<div style="background:#fff; border:1.5px dashed #cbd5e1; border-radius:14px; padding:40px 30px; text-align:center; color:#64748b;">
				<div style="font-size:48px; margin-bottom:12px;">📭</div>
				<div style="font-size:14px; font-weight:600; color:#0f172a; margin-bottom:6px;">هیچ پیامکی در ارسال‌های اخیر یافت نشد</div>
				<div style="font-size:12.5px; line-height:1.7;">پنل API شما هنوز هیچ پیامکی ارسال نکرده است، یا داده‌های ارسال در دسترس نیستند.</div>
			</div>
		<?php endif; ?>

		<!-- توضیح فنی -->
		<details style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; font-size:12px; color:#475569; line-height:1.8;">
			<summary style="cursor:pointer; font-weight:600; color:#0f172a; font-size:12.5px;">🔍 این اعداد چطور محاسبه می‌شوند؟</summary>
			<div style="margin-top:10px;">
				<strong>۱) منبع داده:</strong> آخرین <?php echo (int) WTO_DLR_FETCH_REQUESTS; ?> ارسال (send_request) از API فراز اس‌ام‌اس fetch می‌شوند.
				<br>
				<strong>۲) Item-level fetch:</strong> برای هر ارسال، حداکثر <?php echo (int) WTO_DLR_FETCH_ITEMS; ?> recipient sample می‌شود از endpoint
				<code style="background:#fff; padding:2px 6px; border-radius:4px; direction:ltr;">/send_request/{id}/items</code>
				<br>
				<strong>۳) Aggregation:</strong> وضعیت item-level (sent/delivered/failed/...) شمارش می‌شود.
				<br>
				<strong>۴) Cache:</strong> نتایج ۳۰ دقیقه در transient cache می‌شوند تا روی هر page load API call نخوره.
				<br>
				<strong>۵) Auto-refresh:</strong> cron WordPress هر ۶ ساعت silently به‌روزرسانی می‌کند.
				<br>
				<strong>توجه:</strong> برای ارسال‌های با تعداد گیرنده زیاد (مثلاً ۵۰۰۰)، اعداد بر اساس sample اولین ۱۰۰ گیرنده تخمین زده می‌شود — ممکن است با دقت ۹۵٪+ یک‌سان نباشد.
			</div>
		</details>
	</div>
	<?php
}
