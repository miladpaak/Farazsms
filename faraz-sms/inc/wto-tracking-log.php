<?php
/**
 * Tracking Log — v3.14.10
 *
 * ثبت همه ارسال‌های کد رهگیری در یک جدول جدا (به‌جای query روی postmeta).
 * این برای ۱۰۰k سایت با ده‌ها هزار سفارش، عملکرد گزارش‌گیری را سریع نگه می‌دارد.
 *
 * جدول: wp_wto_tracking_log
 *   id, order_id, tracking_code, carrier, customer_name, mobile, sent_at, status
 *
 * Hook: wto_tracking_code_sent  (در handle_save_tracking_code بعد از موفقیت SMS)
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

const WTO_TRACKING_LOG_TABLE   = 'wto_tracking_log';
const WTO_TRACKING_LOG_SCHEMA  = '1.0';

/**
 * افزودنِ دکمه‌ی «تاریخچه کد رهگیری ارسال شده» زیرِ منوی ووکامرس (کنارِ سفارشات) که
 * مستقیم به صفحه‌ی گزارشِ کد رهگیری لینک می‌شود — برای دسترسیِ ساده‌تر.
 * فقط یک لینک است (صفحه‌ی جدید ثبت نمی‌شود)، پس کنترلِ دسترسی را نمی‌شکند.
 */
add_action( 'admin_menu', 'wto_tracking_log_add_wc_orders_link', 99 );
function wto_tracking_log_add_wc_orders_link() {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$has_wc = function_exists( 'wto_is_wc_active' ) ? wto_is_wc_active() : class_exists( 'WooCommerce' );
	if ( ! $has_wc ) {
		return;
	}
	global $submenu;
	if ( ! isset( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
		return;
	}
	// آیتمِ لینک (عنصر سومِ آرایه یک URL کامل است → وردپرس آن را href مستقیم می‌سازد).
	$submenu['woocommerce'][] = array(
		'📦 تاریخچه کد رهگیری ارسال شده',
		'manage_woocommerce',
		admin_url( 'admin.php?page=farazwto&tt=log' ),
	);
}

function wto_tracking_log_table() {
	global $wpdb;
	return $wpdb->prefix . WTO_TRACKING_LOG_TABLE;
}

/**
 * Lazy schema install — فقط روی صفحه گزارش، یک‌بار اجرا می‌شود.
 */
function wto_tracking_log_maybe_install_schema() {
	if ( get_option( 'wto_tracking_log_schema_version' ) === WTO_TRACKING_LOG_SCHEMA ) {
		return;
	}
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$table   = wto_tracking_log_table();
	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		order_id BIGINT(20) UNSIGNED NOT NULL,
		tracking_code VARCHAR(64) NOT NULL DEFAULT '',
		carrier VARCHAR(20) NOT NULL DEFAULT 'post',
		customer_name VARCHAR(160) NOT NULL DEFAULT '',
		mobile VARCHAR(20) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'sent',
		sent_at DATETIME NOT NULL,
		KEY order_id (order_id),
		KEY sent_at (sent_at),
		KEY carrier (carrier),
		KEY mobile (mobile),
		PRIMARY KEY (id)
	) $charset;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'wto_tracking_log_schema_version', WTO_TRACKING_LOG_SCHEMA, false );
}

/**
 * ثبت یک رکورد در log — روی هر ارسال موفق SMS کد رهگیری.
 */
add_action( 'wto_tracking_code_sent', 'wto_tracking_log_record', 10, 3 );
function wto_tracking_log_record( $order_id, $tracking_code, $carrier ) {
	wto_tracking_log_maybe_install_schema();
	if ( ! function_exists( 'wc_get_order' ) ) return;
	$order = wc_get_order( (int) $order_id );
	if ( ! $order ) return;

	$first = (string) $order->get_billing_first_name();
	$last  = (string) $order->get_billing_last_name();
	$name  = trim( $first . ' ' . $last );
	if ( $name === '' ) {
		$name = $order->get_formatted_billing_full_name();
	}
	$mobile = (string) $order->get_billing_phone();
	if ( function_exists( 'wto_normalize_phone' ) ) {
		$mobile = wto_normalize_phone( $mobile );
	}

	global $wpdb;
	$wpdb->insert(
		wto_tracking_log_table(),
		array(
			'order_id'      => (int) $order_id,
			'tracking_code' => sanitize_text_field( (string) $tracking_code ),
			'carrier'       => sanitize_key( $carrier ),
			'customer_name' => sanitize_text_field( $name ),
			'mobile'        => sanitize_text_field( $mobile ),
			'status'        => 'sent',
			'sent_at'       => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
}

/**
 * تبدیل تاریخ میلادی YYYY-MM-DD HH:MM:SS به جلالی — الگوریتم Behrouz Parsi.
 */
function wto_tracking_log_to_jalali( $mysql_datetime ) {
	if ( ! $mysql_datetime ) return '';
	$ts = strtotime( $mysql_datetime );
	if ( ! $ts ) return '';
	$gy = (int) date( 'Y', $ts );
	$gm = (int) date( 'n', $ts );
	$gd = (int) date( 'j', $ts );
	$hi = date( 'H:i', $ts );

	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	if ( $gm > 2 ) {
		$gy2 = $gy + 1;
	} else {
		$gy2 = $gy;
	}
	$days = 355666 + ( 365 * $gy ) + ( (int) ( ( $gy2 + 3 ) / 4 ) ) - ( (int) ( ( $gy2 + 99 ) / 100 ) ) + ( (int) ( ( $gy2 + 399 ) / 400 ) ) + $gd + $g_d_m[ $gm - 1 ];
	$jy = -1595 + ( 33 * ( (int) ( $days / 12053 ) ) );
	$days = $days % 12053;
	$jy += 4 * ( (int) ( $days / 1461 ) );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy += (int) ( ( $days - 1 ) / 365 );
		$days = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + (int) ( $days / 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + (int) ( ( $days - 186 ) / 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}
	return sprintf( '%04d/%02d/%02d %s', $jy, $jm, $jd, $hi );
}

/**
 * Render جدول log — جستجو، pagination، فیلتر carrier.
 */
function wto_tracking_log_render_table() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	wto_tracking_log_maybe_install_schema();

	global $wpdb;
	$table = wto_tracking_log_table();

	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$filter_c = isset( $_GET['fc'] ) ? sanitize_key( $_GET['fc'] ) : '';
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$per_page = 20;
	$offset   = ( $paged - 1 ) * $per_page;

	$where = ' WHERE 1=1';
	$args  = array();
	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (tracking_code LIKE %s OR customer_name LIKE %s OR mobile LIKE %s OR order_id = %d)';
		$args[] = $like; $args[] = $like; $args[] = $like; $args[] = (int) $search;
	}
	if ( in_array( $filter_c, array( 'post', 'tipax', 'other' ), true ) ) {
		$where .= ' AND carrier = %s';
		$args[] = $filter_c;
	}

	// Total count
	$count_sql = "SELECT COUNT(*) FROM $table $where";
	$total = (int) $wpdb->get_var( empty( $args ) ? $count_sql : $wpdb->prepare( $count_sql, ...$args ) );

	// Rows
	$rows_sql_template = "SELECT * FROM $table $where ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d";
	$rows_args = array_merge( $args, array( $per_page, $offset ) );
	$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql_template, ...$rows_args ), ARRAY_A );

	$carrier_label = array(
		'post'  => array( 'label' => 'پست', 'icon' => '📮', 'color' => '#dc2626', 'bg' => '#fef2f2' ),
		'tipax' => array( 'label' => 'تیپاکس', 'icon' => '🚚', 'color' => '#ea580c', 'bg' => '#fff7ed' ),
		'other' => array( 'label' => 'سایر', 'icon' => '📦', 'color' => '#475569', 'bg' => '#f1f5f9' ),
	);
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	?>
	<div style="direction:rtl; font-family:inherit;">
		<!-- Filter toolbar -->
		<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; align-items:center;">
			<form method="get" action="" style="flex:1 1 320px; display:flex; gap:8px;">
				<input type="hidden" name="page" value="farazwto">
				<input type="hidden" name="tt" value="log">
				<?php if ( $filter_c !== '' ) : ?><input type="hidden" name="fc" value="<?php echo esc_attr( $filter_c ); ?>"><?php endif; ?>
				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="🔍 جستجو در کد رهگیری / نام / موبایل / شماره سفارش" style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
				<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-size:13px; cursor:pointer;">جستجو</button>
				<?php if ( $search !== '' || $filter_c !== '' ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto&tt=log' ) ); ?>" style="background:#fff; color:#475569; border:1px solid #cbd5e1; padding:8px 12px; border-radius:6px; text-decoration:none; font-size:13px;">پاک کردن</a>
				<?php endif; ?>
			</form>
			<div style="display:flex; gap:6px;">
				<?php
				$base_args = array_filter( array( 'page' => 'farazwto', 'tt' => 'log', 's' => $search ) );
				$f_options = array( '' => 'همه', 'post' => '📮 پست', 'tipax' => '🚚 تیپاکس', 'other' => '📦 سایر' );
				foreach ( $f_options as $fv => $fl ) :
					$url = $fv === '' ? add_query_arg( $base_args, admin_url( 'admin.php' ) ) : add_query_arg( array_merge( $base_args, array( 'fc' => $fv ) ), admin_url( 'admin.php' ) );
					$is_active = $filter_c === $fv;
					?>
					<a href="<?php echo esc_url( $url ); ?>" style="background:<?php echo $is_active ? '#4338ca' : '#fff'; ?>; color:<?php echo $is_active ? '#fff' : '#475569'; ?>; border:1px solid <?php echo $is_active ? '#4338ca' : '#cbd5e1'; ?>; padding:7px 12px; border-radius:6px; text-decoration:none; font-size:12px;"><?php echo esc_html( $fl ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Table -->
		<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
			<div style="overflow-x:auto;">
				<table style="width:100%; border-collapse:collapse; font-size:12.5px;">
					<thead style="background:#f8fafc;">
						<tr>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb; font-weight:700;">سفارش</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb; font-weight:700;">مشتری</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb; font-weight:700;">موبایل</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb; font-weight:700;">کد رهگیری</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb; font-weight:700;">نحوه ارسال</th>
							<th style="text-align:right; padding:11px 14px; border-bottom:1px solid #e5e7eb; font-weight:700;">تاریخ ارسال (شمسی)</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">هیچ کد رهگیری ثبت نشده است. ارسال‌های جدید بعد از ذخیره روی سفارش‌ها در این لیست نمایش داده می‌شوند.</td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $r ) :
								$c   = isset( $carrier_label[ $r['carrier'] ] ) ? $carrier_label[ $r['carrier'] ] : $carrier_label['other'];
								$order_url = function_exists( 'wc_get_order' ) ? ( ( $o = wc_get_order( (int) $r['order_id'] ) ) ? $o->get_edit_order_url() : '' ) : '';
								?>
								<tr style="border-bottom:1px solid #f1f5f9;">
									<td style="padding:10px 14px;">
										<?php if ( $order_url ) : ?>
											<a href="<?php echo esc_url( $order_url ); ?>" style="color:#4338ca; text-decoration:none; font-weight:600;">#<?php echo (int) $r['order_id']; ?></a>
										<?php else : ?>
											<strong>#<?php echo (int) $r['order_id']; ?></strong>
										<?php endif; ?>
									</td>
									<td style="padding:10px 14px;"><?php echo esc_html( $r['customer_name'] ?: '—' ); ?></td>
									<td style="padding:10px 14px; direction:ltr; text-align:right; font-family:monospace; color:#475569;"><?php echo esc_html( $r['mobile'] ?: '—' ); ?></td>
									<td style="padding:10px 14px; direction:ltr; text-align:right; font-family:monospace; font-weight:600; color:#0f172a;"><?php echo esc_html( $r['tracking_code'] ); ?></td>
									<td style="padding:10px 14px;"><span style="background:<?php echo esc_attr( $c['bg'] ); ?>; color:<?php echo esc_attr( $c['color'] ); ?>; padding:3px 10px; border-radius:14px; font-size:11.5px; font-weight:600;"><?php echo esc_html( $c['icon'] . ' ' . $c['label'] ); ?></span></td>
									<td style="padding:10px 14px; color:#475569; font-size:12px;"><?php echo esc_html( wto_tracking_log_to_jalali( $r['sent_at'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $total_pages > 1 ) : ?>
				<div style="padding:12px 16px; background:#f8fafc; border-top:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
					<div style="font-size:12px; color:#64748b;">صفحه <?php echo $paged; ?> از <?php echo $total_pages; ?> — مجموع <?php echo number_format_i18n( $total ); ?> رکورد</div>
					<div style="display:flex; gap:6px;">
						<?php
						$pag_base = array_filter( array( 'page' => 'farazwto', 'tt' => 'log', 's' => $search, 'fc' => $filter_c ) );
						if ( $paged > 1 ) {
							echo '<a href="' . esc_url( add_query_arg( array_merge( $pag_base, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) ) ) . '" style="background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; font-size:12px;">قبلی</a>';
						}
						if ( $paged < $total_pages ) {
							echo '<a href="' . esc_url( add_query_arg( array_merge( $pag_base, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) ) ) . '" style="background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; font-size:12px;">بعدی</a>';
						}
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
