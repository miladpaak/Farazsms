<?php
/**
 * آمار با Matomo — دو هدف:
 *   ۱) آمار بازدید سایت (بازدید، صفحات پربازدید و…) با تزریقِ ردیابِ async مَتومو.
 *   ۲) آمار استفاده از قابلیت‌های افزونه با رویدادهای custom (سمتِ سرور، non-blocking).
 *
 * تنظیمات (آدرس مَتومو + Site ID) سطحِ شرکت است و یکی برای همه‌ی نصب‌ها:
 *   - اولویت با ثابت‌های WTO_MATOMO_URL و WTO_MATOMO_SITE_ID (برای bake توسطِ فراز)
 *   - سپس آپشن‌های wto_matomo_url / wto_matomo_site_id (برای تست توسطِ مدیر)
 *   - سپس فیلترهای wto_matomo_url / wto_matomo_site_id
 *
 * ردیابیِ بازدید پیش‌فرض روشن است؛ مدیرِ هر سایت می‌تواند خاموش کند
 * (آپشن wto_matomo_optout یا ثابتِ WTO_MATOMO_FORCE_OFF).
 *
 * معماری برای آینده: الان همه‌ی سایت‌ها در یک Site مشترک‌اند و با دامنه تفکیک
 * می‌شوند؛ با فیلترِ wto_matomo_site_id می‌توان در آینده با Matomo API برای هر
 * دامنه یک Site ID جدا برگرداند.
 *
 * کارایی: ردیابِ بازدید async است و رویدادهای سرور با blocking=false ارسال
 * می‌شوند تا سرعتِ سایت تحتِ تأثیر قرار نگیرد.
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * آدرسِ پایه‌ی مَتومو (با اسلشِ پایانی).
 *
 * @return string
 */
function wto_matomo_url() {
	$url = '';
	if ( defined( 'WTO_MATOMO_URL' ) && WTO_MATOMO_URL ) {
		$url = WTO_MATOMO_URL;
	} else {
		$url = (string) get_option( 'wto_matomo_url', '' );
	}
	$url = (string) apply_filters( 'wto_matomo_url', $url );
	$url = trim( $url );
	return $url !== '' ? trailingslashit( $url ) : '';
}

/**
 * شناسه‌ی Site در مَتومو. (فیلتر برای تفکیکِ هر دامنه در آینده.)
 *
 * @return string
 */
function wto_matomo_site_id() {
	$id = '';
	if ( defined( 'WTO_MATOMO_SITE_ID' ) && WTO_MATOMO_SITE_ID ) {
		$id = WTO_MATOMO_SITE_ID;
	} else {
		$id = (string) get_option( 'wto_matomo_site_id', '' );
	}
	return trim( (string) apply_filters( 'wto_matomo_site_id', $id ) );
}

/**
 * آیا ردیابی فعال است؟ (پیکربندی موجود + خاموش‌نشده)
 *
 * @return bool
 */
function wto_matomo_is_enabled() {
	if ( defined( 'WTO_MATOMO_FORCE_OFF' ) && WTO_MATOMO_FORCE_OFF ) {
		return false;
	}
	if ( get_option( 'wto_matomo_optout', '0' ) === '1' ) {
		return false;
	}
	return wto_matomo_url() !== '' && wto_matomo_site_id() !== '';
}

/**
 * URLِ نقطه‌ی ردیابیِ HTTPِ مَتومو.
 *
 * @return string
 */
function wto_matomo_tracker_endpoint() {
	$file = (string) apply_filters( 'wto_matomo_tracker_file', 'matomo.php' );
	return wto_matomo_url() . ltrim( $file, '/' );
}

// ============================================================================
// ۱) آمار بازدید سایت — تزریقِ ردیابِ async در فرانت‌اند
// ============================================================================

add_action( 'wp_footer', 'wto_matomo_render_tracker', 99 );
function wto_matomo_render_tracker() {
	if ( ! wto_matomo_is_enabled() ) {
		return;
	}
	if ( ! (bool) apply_filters( 'wto_matomo_track_visitors', true ) ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	$url     = wto_matomo_url();
	$site_id = wto_matomo_site_id();
	?>
	<!-- Faraz SMS · Matomo analytics (async) -->
	<script>
	var _paq = window._paq = window._paq || [];
	_paq.push(['trackPageView']);
	_paq.push(['enableLinkTracking']);
	(function() {
		var u = <?php echo wp_json_encode( $url ); ?>;
		_paq.push(['setTrackerUrl', u + 'matomo.php']);
		_paq.push(['setSiteId', <?php echo wp_json_encode( $site_id ); ?>]);
		// تزریقِ ردیاب فقط پس از کاملِ بارگذاریِ صفحه تا هیچ تأثیری روی سرعتِ
		// نمایشِ سایت برای بازدیدکننده نگذارد.
		function farazLoadMatomo() {
			var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
			g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g, s);
		}
		if (document.readyState === 'complete') { farazLoadMatomo(); }
		else { window.addEventListener('load', farazLoadMatomo); }
	})();
	</script>
	<?php
}

// ============================================================================
// ۲) آمار استفاده از قابلیت‌ها — رویدادهای custom سمتِ سرور
// ============================================================================

/**
 * ارسالِ یک رویدادِ مَتومو (سمتِ سرور، non-blocking).
 *
 * @param string      $category دسته (مثلاً Feature، AdminFeature، SMS).
 * @param string      $action   عمل (مثلاً pattern_created، send).
 * @param string      $name     نام/جزئیات (اختیاری).
 * @param int|null    $value    مقدار عددی (اختیاری).
 * @return void
 */
function wto_matomo_track_event( $category, $action, $name = '', $value = null ) {
	if ( ! wto_matomo_is_enabled() ) {
		return;
	}
	if ( ! (bool) apply_filters( 'wto_matomo_track_features', true ) ) {
		return;
	}
	$home = home_url( '/' );
	$args = array(
		'idsite'     => wto_matomo_site_id(),
		'rec'        => 1,
		'apiv'       => 1,
		'send_image' => 0,
		'e_c'        => $category,
		'e_a'        => $action,
		// URL را دامنه‌ی همین سایت می‌گذاریم تا در Site مشترک، گزارش‌ها با دامنه تفکیک شوند.
		'url'        => $home . '?wto_event=' . rawurlencode( $category . '/' . $action ),
		'_id'        => substr( md5( $home ), 0, 16 ), // شناسه‌ی شبه‌ثابتِ نصب (ناشناس).
	);
	if ( $name !== '' ) {
		$args['e_n'] = $name;
	}
	if ( $value !== null ) {
		$args['e_v'] = (int) $value;
	}

	$endpoint = add_query_arg( array_map( 'rawurlencode', $args ), wto_matomo_tracker_endpoint() );

	wp_remote_get( $endpoint, array(
		'blocking'  => false,
		'timeout'   => 0.5,
		'sslverify' => false,
		'headers'   => array( 'User-Agent' => 'FarazSMS-Matomo/' . ( defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '1' ) ),
	) );
}

/**
 * ثبتِ بازدیدِ صفحاتِ قابلیت‌های افزونه به‌عنوان رویداد — نشان می‌دهد مدیر از کدام
 * قابلیت‌ها بیشتر استفاده می‌کند (engagement). سبک و non-blocking.
 */
add_action( 'current_screen', 'wto_matomo_track_feature_screen' );
function wto_matomo_track_feature_screen( $screen ) {
	if ( ! wto_matomo_is_enabled() || ! is_object( $screen ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $page === '' || strpos( $page, 'farazwto' ) !== 0 ) {
		return;
	}
	wto_matomo_track_event( 'AdminFeature', $page, wp_parse_url( home_url(), PHP_URL_HOST ) );
}

// ============================================================================
// کارتِ تنظیمات — در صفحه‌ی تنظیماتِ اصلیِ افزونه (هوکِ extra sections)
// ============================================================================

// مدیریتِ ذخیره‌ی فرمِ مستقلِ این کارت.
add_action( 'admin_init', 'wto_matomo_handle_save' );
function wto_matomo_handle_save() {
	if ( ! isset( $_POST['wto_matomo_nonce'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wto_matomo_nonce'] ) ), 'wto_matomo_save' ) ) {
		return;
	}
	update_option( 'wto_matomo_optout', isset( $_POST['wto_matomo_enable'] ) ? '0' : '1' );
	// فیلدهای آدرس/Site فقط وقتی با ثابت bake نشده‌اند قابلِ ویرایش‌اند (برای تست).
	if ( ! defined( 'WTO_MATOMO_URL' ) && isset( $_POST['wto_matomo_url'] ) ) {
		update_option( 'wto_matomo_url', esc_url_raw( wp_unslash( $_POST['wto_matomo_url'] ) ) );
	}
	if ( ! defined( 'WTO_MATOMO_SITE_ID' ) && isset( $_POST['wto_matomo_site_id'] ) ) {
		update_option( 'wto_matomo_site_id', sanitize_text_field( wp_unslash( $_POST['wto_matomo_site_id'] ) ) );
	}
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>تنظیماتِ آمار ذخیره شد.</p></div>';
	} );
}

add_action( 'wto_settings_page_extra_sections', 'wto_matomo_render_settings_card' );
function wto_matomo_render_settings_card() {
	$optout    = get_option( 'wto_matomo_optout', '0' ) === '1';
	$configured = wto_matomo_url() !== '' && wto_matomo_site_id() !== '';
	$baked      = defined( 'WTO_MATOMO_URL' );
	$url        = $baked ? WTO_MATOMO_URL : get_option( 'wto_matomo_url', '' );
	$site_id    = defined( 'WTO_MATOMO_SITE_ID' ) ? WTO_MATOMO_SITE_ID : get_option( 'wto_matomo_site_id', '' );
	?>
	<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:18px;margin-top:18px;direction:rtl">
		<h2 style="font-size:15px;margin:0 0 6px">📊 آمار و حریم خصوصی</h2>
		<p style="color:#555;font-size:13px;line-height:2;margin:0 0 12px">
			برای بهبودِ افزونه، میزانِ استفاده از قابلیت‌ها به‌صورتِ ناشناس جمع‌آوری می‌شود. می‌توانید هر زمان خاموش کنید.
		</p>
		<form method="post" action="">
			<?php wp_nonce_field( 'wto_matomo_save', 'wto_matomo_nonce' ); ?>
			<p>
				<label style="display:inline-flex;align-items:center;gap:10px;font-weight:600;font-size:14px">
					<input type="checkbox" class="wto-toggle" name="wto_matomo_enable" value="1" <?php checked( ! $optout ); ?> style="width:18px;height:18px">
					جمع‌آوریِ آمار فعال باشد
				</label>
			</p>
			<?php if ( ! $baked ) : ?>
				<p style="margin:10px 0 0">
					<label style="font-size:13px;font-weight:600">آدرس سرور آمار:</label><br>
					<input type="url" name="wto_matomo_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://matomo.faraz.club/" class="regular-text" style="direction:ltr">
				</p>
				<p style="margin:10px 0 0">
					<label style="font-size:13px;font-weight:600">Site ID:</label><br>
					<input type="text" name="wto_matomo_site_id" value="<?php echo esc_attr( $site_id ); ?>" placeholder="1" class="small-text" style="direction:ltr">
				</p>
			<?php else : ?>
				<p style="color:#888;font-size:12px">آدرس و Site ID توسطِ فراز پیکربندی شده است.</p>
			<?php endif; ?>
			<p style="font-size:12px;color:<?php echo $configured ? '#1a7f37' : '#b32d2e'; ?>;margin:10px 0 0">
				وضعیت: <?php echo $configured ? '✓ پیکربندی‌شده' . ( $optout ? ' (خاموش توسطِ مدیر)' : ' و فعال' ) : '— هنوز آدرس/Site ID وارد نشده'; ?>
			</p>
			<p style="margin-top:14px"><button type="submit" class="button button-primary">💾 ذخیره</button></p>
		</form>
	</div>
	<?php
}
