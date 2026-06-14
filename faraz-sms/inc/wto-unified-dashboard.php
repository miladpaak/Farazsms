<?php
/**
 * Unified Dashboard Frame — Phase 7 (v3.13.0)
 *
 * این فایل یک قالب یکپارچه (شبیه تنظیمات قالب وودمارت) برای کل صفحات افزونه فراز اس‌ام‌اس
 * فراهم می‌کند. تمام صفحات افزونه درون یک Frame مشترک رندر می‌شوند که شامل:
 *
 *   - نوار بالا (Topbar): لوگو، اعتبار، نسخه، لینک ورود به پنل
 *   - ناوبری کنار صفحه (داخلی): گروه‌های فیچر + دسترسی سریع به همه قابلیت‌ها
 *   - بدنه: محتوای صفحه فعلی
 *
 * صفحه جدید «داشبورد» با اسلاگ
 *
 *   farazwto-dashboard
 *
 * هم به‌عنوان hub اصلی اضافه می‌شود و در ابتدای منو قرار می‌گیرد. در ناوبری داخلی Frame
 * هم اولین آیتم همین صفحه است.
 *
 * Hook ها:
 *
 *   all_admin_notices   priority 99999  →  باز کردن Frame
 *   in_admin_footer     priority 0      →  بستن Frame
 *
 * طراحی RTL-aware. سی‌اس‌اس inline اما scoped زیر کلاس
 *
 *   .wto-app-frame
 *
 * هیچ leak به سایر افزونه‌ها/قالب پیشخوان نخواهد داشت.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/* ------------------------------------------------------------------------- *
 *  Helpers
 * ------------------------------------------------------------------------- */

/**
 * تشخیص اینکه صفحه فعلی، صفحه‌ای از افزونه است.
 * بر اساس
 *
 *   $_GET['page']
 *
 * یا
 *
 *   $screen->id
 *
 * تصمیم می‌گیرد.
 */
function wto_is_plugin_page() {
	$plugin_pages = wto_dashboard_known_slugs();
	if ( isset( $_GET['page'] ) ) {
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		if ( in_array( $page, $plugin_pages, true ) ) {
			return true;
		}
	}
	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && isset( $screen->id ) && strpos( (string) $screen->id, 'farazwto' ) !== false ) {
			return true;
		}
	}
	return false;
}

/**
 * لیست همه slugهای شناخته‌شده افزونه (برای بررسی + ناوبری داخلی).
 */
function wto_dashboard_known_slugs() {
	return array(
		'farazwto-dashboard',
		'farazwto-settings',
		'farazwto',
		'farazwto-poll',
		'farazwto-comments',
		'farazwto-phonebook',
		'farazwto-newsletter',
		'farazwto-lead-magnet',
		'farazwto-notify',
		'farazwto-abandoned',
		'farazwto-send-sms',
		'farazwto-reports',
		'farazwto-sms-forms',
		'farazwto-automation',
		'farazwto-login-register',
		'farazwto-feedback',
		'farazwto-cashback',
		'farazwto-farazbin',
	);
}

/**
 * ساختار گروه‌های ناوبری داخلی Frame — هم برای sidebar داخل صفحه و هم برای hub.
 * هر آیتم: slug، عنوان، آیکن dashicons، توضیح کوتاه.
 */
function wto_dashboard_nav_groups() {
	$has_wc = function_exists( 'wto_is_wc_active' ) ? wto_is_wc_active() : class_exists( 'WooCommerce' );
	$has_gf = function_exists( 'wto_is_gf_active' ) ? wto_is_gf_active() : class_exists( 'GFForms' );
	$has_elem = function_exists( 'wto_is_elementor_active' ) ? wto_is_elementor_active() : did_action( 'elementor/loaded' );

	$groups = array(
		'general' => array(
			'label' => __( 'تنظیمات اصلی', 'wto' ),
			'icon'  => 'dashicons-admin-generic',
			'items' => array(
				array( 'slug' => 'farazwto-dashboard', 'title' => __( 'داشبورد', 'wto' ),         'icon' => 'dashicons-dashboard',     'desc' => __( 'نمای کلی، اعتبار، آمار سریع', 'wto' ) ),
				// v3.17.3: ROI + Updates در sidebar داخلی frame اضافه شد
				// v3.17.6: DLR حذف شد — حالا تب در farazwto-reports است
				array( 'slug' => 'farazwto-roi',       'title' => __( 'محاسبه سود افزونه', 'wto' ), 'icon' => 'dashicons-chart-line',  'desc' => __( 'این ماه چقدر فروش از پیامک آمد؟', 'wto' ) ),
				array( 'slug' => 'farazwto-settings',  'title' => __( 'تنظیمات افزونه', 'wto' ),  'icon' => 'dashicons-admin-settings', 'desc' => __( 'کلید دسترسی (Api-Key) و خط ارسال', 'wto' ) ),
				array( 'slug' => 'farazwto-send-sms',  'title' => __( 'ارسال پیامک', 'wto' ),     'icon' => 'dashicons-email-alt',     'desc' => __( 'ارسال پیامک تکی یا انبوه از داخل سایت', 'wto' ) ),
				array( 'slug' => 'farazwto-reports',   'title' => __( 'گزارشات ارسال', 'wto' ),   'icon' => 'dashicons-chart-area',    'desc' => __( 'گزارش‌گیری از API فراز اس‌ام‌اس', 'wto' ) ),
				array( 'slug' => 'farazwto-updates',   'title' => __( 'به‌روزرسانی افزونه', 'wto' ),'icon' => 'dashicons-update',       'desc' => __( 'به‌روزرسانی خودکار از GitLab فراز', 'wto' ) ),
			),
		),
		'club'    => array(
			'label' => __( 'باشگاه مشتریان و افزایش فروش', 'wto' ),
			'icon'  => 'dashicons-groups',
			'items' => array(
				array( 'slug' => 'farazwto-phonebook',   'title' => __( 'باشگاه مشتریان', 'wto' ),       'icon' => 'dashicons-book-alt',     'desc' => __( 'دفترچه تلفن + سینک فرم‌ها', 'wto' ) ),
				// v3.17.5: سینک فرم Gravity در همان صفحه phonebook به‌صورت تب — منو کم‌تر شلوغ
				array( 'slug' => 'farazwto-newsletter',  'title' => __( 'خبرنامه پیامکی', 'wto' ),       'icon' => 'dashicons-megaphone',    'desc' => __( 'شورت‌کد، ویجت، ارسال انبوه', 'wto' ) ),
				array( 'slug' => 'farazwto-lead-magnet', 'title' => __( 'لید مگنت', 'wto' ),             'icon' => 'dashicons-tickets-alt',  'desc' => __( 'تخفیف در ازای شماره موبایل', 'wto' ) ),
				// v3.17.3: تبریک تولد در sidebar داخلی frame اضافه شد
				// v3.17.8: dashicons-cake وجود ندارد → emoji 🎂 به‌جای آن
				array( 'slug' => 'farazwto-birthday',    'title' => __( '🎂 تبریک تولد + کوپن', 'wto' ),    'icon' => 'dashicons-buddicons-friends',  'desc' => __( 'پیامک تبریک با کد تخفیف انحصاری', 'wto' ) ),
				array( 'slug' => 'farazwto-automation',  'title' => __( 'اتومیشن مارکتینگ', 'wto' ),     'icon' => 'dashicons-clock',        'desc' => __( 'پیامک زمان‌دار و کمپین‌های خودکار', 'wto' ) ),
				array( 'slug' => 'farazwto-cashback',    'title' => __( 'کش‌بک هوشمند', 'wto' ),       'icon' => 'dashicons-money-alt',    'desc' => __( 'بازگشت درصدی + کیف پول + پیامک یادآوری', 'wto' ) ),
				array( 'slug' => 'farazwto-user-panel',  'title' => __( 'پنل کاربری', 'wto' ),          'icon' => 'dashicons-id',           'desc' => __( 'پنل مشتری: خریدها، کیف پول، کش‌بک، تولد', 'wto' ) ),
				array( 'slug' => 'farazwto-farazbin',    'title' => __( 'فراز بین', 'wto' ),            'icon' => 'dashicons-search',       'desc' => __( 'موتور جستجوی قیمت + ایندکس محصولات', 'wto' ) ),
			),
		),
		'woo'     => array(
			'label' => __( 'ووکامرس و سفارشات', 'wto' ),
			'icon'  => 'dashicons-cart',
			'items' => array(
				array( 'slug' => 'farazwto',           'title' => __( 'کد رهگیری سفارش', 'wto' ),    'icon' => 'dashicons-location',     'desc' => __( 'ارسال خودکار کد رهگیری', 'wto' ) ),
				array( 'slug' => 'farazwto-poll',      'title' => __( 'نظرسنجی پس از خرید', 'wto' ), 'icon' => 'dashicons-star-filled',  'desc' => __( 'بازخورد مشتری بعد از خرید', 'wto' ) ),
				array( 'slug' => 'farazwto-comments',  'title' => __( 'دیدگاه سایت', 'wto' ),         'icon' => 'dashicons-format-chat',  'desc' => __( 'پیامک رویدادهای دیدگاه', 'wto' ) ),
				array( 'slug' => 'farazwto-notify',    'title' => __( 'موجود شد خبرم کن', 'wto' ),    'icon' => 'dashicons-bell',         'desc' => __( 'پیامک بازگشت موجودی محصول', 'wto' ) ),
				array( 'slug' => 'farazwto-abandoned', 'title' => __( 'سبد خرید رهاشده', 'wto' ),     'icon' => 'dashicons-cart',         'desc' => __( 'بازگردانی سبد + گزارش بازگشت', 'wto' ) ),
			),
		),
		'other'   => array(
			'label' => __( 'ابزارها و فرم‌ها', 'wto' ),
			'icon'  => 'dashicons-admin-tools',
			'items' => array(
				array( 'slug' => 'farazwto-sms-forms',     'title' => __( 'گرویتی - المنتور', 'wto' ),    'icon' => 'dashicons-feedback',   'desc' => __( 'یکپارچگی با فرم‌سازها + OTP', 'wto' ) ),
				array( 'slug' => 'farazwto-login-register','title' => __( 'ورود و ثبت نام', 'wto' ),       'icon' => 'dashicons-admin-users','desc' => __( 'انتقال به پنل فراز اس‌ام‌اس', 'wto' ) ),
				array( 'slug' => 'farazwto-developers',    'title' => __( 'برنامه‌نویسان', 'wto' ),         'icon' => 'dashicons-editor-code', 'desc' => __( 'مستندات API برای توسعه‌دهندگان', 'wto' ) ),
				array( 'slug' => 'farazwto-feedback',      'title' => __( 'بازخورد', 'wto' ),              'icon' => 'dashicons-format-status', 'desc' => __( 'ارسال تیکت و گزارش مشکل', 'wto' ) ),
			),
		),
	);

	// آیتم‌های وابسته به ووکامرس را در صورت غیرفعال بودن WC حذف می‌کنیم تا کاربران
	// بدون WC، آیتم‌های شکسته نبینند.
	if ( ! $has_wc ) {
		$wc_only_slugs = array( 'farazwto', 'farazwto-poll', 'farazwto-notify', 'farazwto-abandoned', 'farazwto-automation' );
		foreach ( $groups as $gkey => &$group ) {
			$group['items'] = array_values( array_filter( $group['items'], function( $item ) use ( $wc_only_slugs ) {
				return ! in_array( $item['slug'], $wc_only_slugs, true );
			} ) );
		}
		unset( $group );
		// گروه «ووکامرس و سفارشات» اگر خالی شد، کلش حذف شود.
		if ( isset( $groups['woo'] ) && empty( $groups['woo']['items'] ) ) {
			unset( $groups['woo'] );
		}
	}

	// آیتم گرویتی/المنتور را در صورت فعال نبودن هر دو، حذف می‌کنیم.
	if ( ! $has_gf && ! $has_elem ) {
		foreach ( $groups as &$group ) {
			$group['items'] = array_values( array_filter( $group['items'], function( $item ) {
				return $item['slug'] !== 'farazwto-sms-forms';
			} ) );
		}
		unset( $group );
	}

	return $groups;
}

/**
 * URL هر slug پلاگین. برای پنل فراز چون مقصد خارجی است، URL خاص می‌گذاریم
 * تا کاربر مستقیم منتقل شود.
 */
function wto_dashboard_item_url( $slug ) {
	return admin_url( 'admin.php?page=' . $slug );
}

/**
 * پیدا کردن گروه و آیتم فعال بر اساس slug فعلی.
 *
 * @return array{0:string,1:?array}  [group_key, item_array_or_null]
 */
function wto_dashboard_current_item() {
	$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	foreach ( wto_dashboard_nav_groups() as $gkey => $group ) {
		foreach ( $group['items'] as $item ) {
			if ( $item['slug'] === $current ) {
				return array( $gkey, $item );
			}
		}
	}
	return array( '', null );
}

/* ------------------------------------------------------------------------- *
 *  Register hub submenu
 * ------------------------------------------------------------------------- */

add_action( 'admin_menu', 'wto_dashboard_register_hub', 20 );
function wto_dashboard_register_hub() {
	add_submenu_page(
		'farazwto',
		__( 'داشبورد فراز اس‌ام‌اس', 'wto' ),
		__( 'داشبورد', 'wto' ),
		'manage_options',
		'farazwto-dashboard',
		'wto_dashboard_hub_render'
	);
}

/* ------------------------------------------------------------------------- *
 *  Frame: open + close hooks
 * ------------------------------------------------------------------------- */

/**
 * نکته فنی مهم درباره Lifecycle Frame:
 *
 *   - باز کردن frame داخل `all_admin_notices` (در `#wpbody-content`) انجام می‌شود.
 *   - بستن frame باید قبل از بسته شدن `#wpbody-content` رخ دهد، اما هیچ action رسمی WP
 *     بین callback صفحه و `</div><!-- wpbody-content -->` وجود ندارد.
 *   - راه‌حل: بعد از باز کردن frame یک `ob_start()` می‌گذاریم تا کل خروجی بعدی buffer شود.
 *     سپس در `in_admin_footer` priority 0، buffer را گرفته، تگ بستن frame را دقیقاً
 *     قبل از marker `</div><!-- wpbody-content -->` تزریق می‌کنیم و echo می‌کنیم.
 *   - این کار باعث می‌شود ساختار DOM معتبر بماند:
 *
 *       #wpbody-content
 *         .wto-app-frame
 *           .wto-app-content__inner [page content]
 *         /.wto-app-frame
 *       /#wpbody-content
 */
add_action( 'all_admin_notices', 'wto_unified_frame_open', 99999 );
function wto_unified_frame_open() {
	if ( ! wto_is_plugin_page() ) {
		return;
	}
	wto_unified_frame_inline_css();
	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : '';
	$credit = '';
	if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) {
		$credit_raw = wto_get_credit();
		if ( $credit_raw !== false && $credit_raw !== '' ) {
			$credit = (string) $credit_raw;
		}
	}
	$version = defined( 'FARAZSMS_PLUGIN_VERSION' ) ? FARAZSMS_PLUGIN_VERSION : '';
	$portal_url = function_exists( 'wto_get_farazsms_portal_url' ) ? wto_get_farazsms_portal_url() : 'https://sms.farazsms.com/';
	list( $current_gkey, $current_item ) = wto_dashboard_current_item();
	?>
	<div class="wto-app-frame" dir="rtl">
		<header class="wto-app-topbar">
			<div class="wto-app-topbar__brand">
				<a href="<?php echo esc_url( wto_dashboard_item_url( 'farazwto-dashboard' ) ); ?>" class="wto-app-topbar__logo">
					<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt="فراز اس‌ام‌اس">
				</a>
				<?php if ( $version ) : ?>
					<span class="wto-app-topbar__version">v<?php echo esc_html( $version ); ?></span>
				<?php endif; ?>
			</div>
			<div class="wto-app-topbar__meta">
				<?php if ( $credit !== '' ) : ?>
					<a href="<?php echo esc_url( wto_dashboard_item_url( 'farazwto-settings' ) ); ?>" class="wto-app-topbar__credit" title="<?php esc_attr_e( 'مشاهده تنظیمات اعتبار', 'wto' ); ?>">
						<span class="dashicons dashicons-money-alt"></span>
						<span class="wto-app-topbar__credit-label"><?php esc_html_e( 'اعتبار:', 'wto' ); ?></span>
						<strong><?php echo esc_html( $credit ); ?></strong>
						<span class="wto-app-topbar__credit-unit"><?php esc_html_e( 'تومان', 'wto' ); ?></span>
					</a>
				<?php else : ?>
					<a href="<?php echo esc_url( wto_dashboard_item_url( 'farazwto-settings' ) ); ?>" class="wto-app-topbar__credit wto-app-topbar__credit--empty">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Api-Key وارد نشده', 'wto' ); ?>
					</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener" class="wto-app-topbar__button wto-app-topbar__button--ghost">
					<span class="dashicons dashicons-external"></span>
					<?php esc_html_e( 'پنل فراز اس‌ام‌اس', 'wto' ); ?>
				</a>
				<a href="https://farazsms.com/" target="_blank" rel="noopener" class="wto-app-topbar__button wto-app-topbar__button--ghost wto-app-topbar__button--icon" title="<?php esc_attr_e( 'سایت رسمی', 'wto' ); ?>">
					<span class="dashicons dashicons-admin-site-alt3"></span>
				</a>
			</div>
		</header>
		<div class="wto-app-body">
			<aside class="wto-app-sidebar">
				<button type="button" class="wto-app-sidebar__toggle" aria-label="<?php esc_attr_e( 'باز/بستن ناوبری', 'wto' ); ?>">
					<span class="dashicons dashicons-menu-alt"></span>
				</button>
				<nav class="wto-app-sidebar__nav" aria-label="<?php esc_attr_e( 'ناوبری فراز اس‌ام‌اس', 'wto' ); ?>">
					<?php foreach ( wto_dashboard_nav_groups() as $gkey => $group ) : ?>
						<div class="wto-app-sidebar__group">
							<div class="wto-app-sidebar__group-label">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</div>
							<ul class="wto-app-sidebar__items">
								<?php foreach ( $group['items'] as $item ) :
									$is_active = ( $current_item && $item['slug'] === $current_item['slug'] );
									?>
									<li class="wto-app-sidebar__item <?php echo $is_active ? 'is-active' : ''; ?>">
										<a href="<?php echo esc_url( wto_dashboard_item_url( $item['slug'] ) ); ?>">
											<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
											<span class="wto-app-sidebar__item-title"><?php echo esc_html( $item['title'] ); ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				</nav>
			</aside>
			<main class="wto-app-content">
				<?php if ( $current_item ) : ?>
					<div class="wto-app-pagehead">
						<h1 class="wto-app-pagehead__title">
							<span class="dashicons <?php echo esc_attr( $current_item['icon'] ); ?>"></span>
							<?php echo esc_html( $current_item['title'] ); ?>
						</h1>
						<?php if ( ! empty( $current_item['desc'] ) ) : ?>
							<p class="wto-app-pagehead__desc"><?php echo esc_html( $current_item['desc'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="wto-app-content__inner">
	<?php
	// شروع buffering تا بتوانیم تگ‌های بستن را در جای درست (قبل از بسته‌شدن
	// wpbody-content) تزریق کنیم. سطح فعلی buffer را ذخیره می‌کنیم تا close
	// بداند کدام buffer متعلق به ماست (در صورتی که سایر افزونه‌ها هم ob_start بزنند).
	ob_start();
	$GLOBALS['wto_frame_ob_level'] = ob_get_level();
}

add_action( 'in_admin_footer', 'wto_unified_frame_close', 0 );
function wto_unified_frame_close() {
	if ( ! wto_is_plugin_page() ) {
		return;
	}
	$our_level = isset( $GLOBALS['wto_frame_ob_level'] ) ? (int) $GLOBALS['wto_frame_ob_level'] : 0;
	if ( $our_level <= 0 || ob_get_level() <= 0 ) {
		return;
	}
	// اگر افزونه‌ای بالاتر از ما buffer باز کرده، آن‌ها را flush می‌کنیم تا به buffer ما برسیم.
	$safety = 0;
	while ( ob_get_level() > $our_level && $safety < 20 ) {
		ob_end_flush();
		$safety++;
	}
	// v3.13.19 BUG FIX: قبل از این، اگر buffer ما به هر دلیلی بسته شده بود
	// (مثلاً افزونه دیگری ob_end_clean زده یا exception buffer را trash کرده)،
	// این تابع return می‌کرد بدون flush کردن buffer ما → صفحه سفید!
	// حالا اگر به buffer خود نرسیم، با تمام buffers باز موجود flush می‌کنیم تا
	// محتوای صفحه از دست نرود.
	if ( ob_get_level() !== $our_level ) {
		// emergency flush — تمام buffer های موجود را خروجی بده تا صفحه سفید نشود
		while ( ob_get_level() > 0 ) {
			@ob_end_flush();
		}
		return;
	}
	$buf = ob_get_clean();
	unset( $GLOBALS['wto_frame_ob_level'] );

	// آماده‌سازی markup بستن frame.
	ob_start();
	?>
				</div>
			</main>
		</div>
		<footer class="wto-app-footer">
			<div class="wto-app-footer__row">
				<span><?php esc_html_e( 'افزونه فراز اس‌ام‌اس', 'wto' ); ?></span>
				<span class="wto-app-footer__sep">•</span>
				<a href="https://farazsms.com/" target="_blank" rel="noopener"><?php esc_html_e( 'سایت رسمی', 'wto' ); ?></a>
				<span class="wto-app-footer__sep">•</span>
				<a href="https://sms.farazsms.com/" target="_blank" rel="noopener"><?php esc_html_e( 'ورود به پنل', 'wto' ); ?></a>
				<span class="wto-app-footer__sep">•</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-feedback' ) ); ?>"><?php esc_html_e( 'بازخورد و گزارش مشکل', 'wto' ); ?></a>
			</div>
			<!-- v3.17.7: اعلامیه‌ی کپی‌رایت رسمی -->
			<div class="wto-app-footer__copyright">
				© <?php echo esc_html( wp_date( 'Y' ) ); ?> —
				<?php esc_html_e( 'کلیه حقوق مادی و معنوی این افزونه متعلق به سامانه پیامک فراز اس‌ام‌اس (شرکت زرین ارتباطات آسیا) است و هرگونه کپی‌برداری از آن غیرمجاز و پیگرد قانونی دارد.', 'wto' ); ?>
			</div>
		</footer>
	</div>
	<?php
	$close = ob_get_clean();

	// تزریق close markup دقیقاً قبل از بسته شدن wpbody-content (که WP در admin-footer.php
	// emit می‌کند). اگر marker پیدا نشد، fallback به prepend می‌رویم — این تضمین می‌کند
	// frame همیشه بسته می‌شود حتی اگر ساختار admin-footer.php در آینده عوض شود.
	$marker = '</div><!-- wpbody-content -->';
	$pos    = strpos( $buf, $marker );
	if ( $pos !== false ) {
		$buf = substr_replace( $buf, $close, $pos, 0 );
	} else {
		// Fallback: bracket frame around the whole buffer.
		$buf = $close . $buf;
	}
	echo $buf;
}

/* ------------------------------------------------------------------------- *
 *  Inline CSS — scoped under .wto-app-frame
 * ------------------------------------------------------------------------- */

function wto_unified_frame_inline_css() {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	?>
	<style id="wto-app-frame-css">
	/* Reset within the frame */
	#wpbody-content { padding-bottom: 0 !important; }
	#wpbody-content .wto-app-frame { margin: 12px 0 0 12px; }
	#wpcontent { padding-right: 12px !important; }
	.wto-app-frame * { box-sizing: border-box; }

	/* v3.17.6: فونت سراسری frame — IRANSans روی کل قاب اعمال می‌شود.
	   قبلاً inherit بود که از body wp-admin فونت سیستم می‌گرفت. این rule
	   روی sidebar، topbar، content area، و همه‌ی dropdown ها اعمال می‌شود. */
	.wto-app-frame,
	.wto-app-frame *:not(code):not(pre):not(kbd):not(samp):not([class*="dashicons"]) {
		font-family: IRANSans, Tahoma, sans-serif !important;
	}
	.wto-app-frame code,
	.wto-app-frame pre,
	.wto-app-frame kbd,
	.wto-app-frame samp { font-family: Menlo, Consolas, "Courier New", monospace !important; }

	/* Hide the default .wrap padding/H1 — pages use their own headers; the frame's pagehead is enough */
	.wto-app-content__inner > .wrap,
	.wto-app-content__inner > section.wrapper {
		margin: 0 !important;
		padding: 0 !important;
	}
	/* Hide the duplicate per-page header (logo + credit) since the unified topbar has them already */
	.wto-app-frame .wto-app-content__inner section.wrapper > #wto_header,
	.wto-app-frame .wto-app-content__inner section.wrapper > #fwss_header { display: none !important; }
	/* Plugin pages that use <ul class="tabs"> at the top — keep them but normalize spacing */
	.wto-app-frame .wto-app-content__inner ul.tabs { margin-top: 0; }

	/* ════════════════════════════════════════════════════════════════════════
	 *  Polish/Normalize legacy styles — v3.13.9
	 *  هدف: یکپارچه‌سازی دکمه‌ها/فیلدها/نوتیس‌ها در صفحات قدیمی (تنظیمات، کد رهگیری،
	 *  اتومیشن، ...) با ظاهر جدید قاب. تمام rule ها زیر .wto-app-frame scoped شده‌اند
	 *  تا هیچ leak به سایر صفحات WP نباشد.
	 * ════════════════════════════════════════════════════════════════════════ */

	/* wrapper container — حذف min-width و عرض ثابت 1024px قدیمی */
	.wto-app-frame .wto-app-content__inner section.wrapper {
		min-width: 0 !important;
		max-width: 100% !important;
		margin: 0 !important;
		font-family: inherit !important;
	}

	/* Buttons — همه دکمه‌ها (.wto_button, .fwss_button) compact و یکپارچه */
	.wto-app-frame .wto-app-content__inner .wto_button,
	.wto-app-frame .wto-app-content__inner .fwss_button {
		background: #4338ca !important;
		color: #fff !important;
		border: none !important;
		padding: 8px 18px !important;
		text-align: center !important;
		font-size: 13px !important;
		font-weight: 600 !important;
		cursor: pointer !important;
		position: relative !important;
		font-family: inherit !important;
		width: auto !important;
		min-width: 100px !important;
		border-radius: 6px !important;
		transition: background 0.15s, transform 0.1s !important;
		height: auto !important;
		line-height: 1.6 !important;
		box-shadow: none !important;
	}
	.wto-app-frame .wto-app-content__inner .wto_button:hover,
	.wto-app-frame .wto-app-content__inner .fwss_button:hover {
		background: #3730a3 !important;
		box-shadow: 0 2px 4px rgba(67, 56, 202, 0.2) !important;
		transform: translateY(-1px) !important;
	}
	.wto-app-frame .wto-app-content__inner .wto_button:active,
	.wto-app-frame .wto-app-content__inner .fwss_button:active {
		transform: translateY(0) !important;
	}
	.wto-app-frame .wto-app-content__inner .wto_button--compact,
	.wto-app-frame .wto-app-content__inner .wto_button.wto_button--compact {
		padding: 6px 14px !important;
		font-size: 12px !important;
		min-width: 0 !important;
	}

	/* Input fields — یکپارچه‌سازی ارتفاع و padding */
	.wto-app-frame .wto-app-content__inner .input-field,
	.wto-app-frame .wto-app-content__inner input[type="text"]:not(.wto-pwsms-test-mobile):not(.wto-wallet-custom-amount):not(.wto-app-topbar input),
	.wto-app-frame .wto-app-content__inner input[type="number"],
	.wto-app-frame .wto-app-content__inner input[type="email"],
	.wto-app-frame .wto-app-content__inner input[type="tel"],
	.wto-app-frame .wto-app-content__inner input[type="time"],
	.wto-app-frame .wto-app-content__inner select {
		width: 100% !important;
		padding: 8px 12px !important;
		border: 1px solid #cbd5e1 !important;
		border-radius: 6px !important;
		font-size: 13px !important;
		line-height: 1.5 !important;
		min-height: 38px !important;
		font-family: inherit !important;
		background: #fff !important;
		color: #0f172a !important;
		box-shadow: none !important;
	}
	.wto-app-frame .wto-app-content__inner .input-field:focus,
	.wto-app-frame .wto-app-content__inner input[type="text"]:focus,
	.wto-app-frame .wto-app-content__inner input[type="number"]:focus,
	.wto-app-frame .wto-app-content__inner input[type="email"]:focus,
	.wto-app-frame .wto-app-content__inner select:focus,
	.wto-app-frame .wto-app-content__inner textarea:focus {
		outline: none !important;
		border-color: #6366f1 !important;
		box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important;
	}
	.wto-app-frame .wto-app-content__inner textarea.input-field,
	.wto-app-frame .wto-app-content__inner textarea {
		width: 100% !important;
		padding: 10px 12px !important;
		border: 1px solid #cbd5e1 !important;
		border-radius: 6px !important;
		font-size: 13px !important;
		line-height: 1.8 !important;
		font-family: inherit !important;
		min-height: auto !important;
		background: #fff !important;
		color: #0f172a !important;
	}

	/* Form labels — تایپوگرافی یکپارچه */
	.wto-app-frame .wto-app-content__inner label {
		display: block;
		margin-bottom: 6px !important;
		font-family: inherit !important;
	}
	.wto-app-frame .wto-app-content__inner label .label {
		display: block;
		font-size: 13px;
		font-weight: 600;
		color: #334155;
		margin-bottom: 6px;
		padding: 0 !important;
	}
	.wto-app-frame .wto-app-content__inner label .required {
		color: #dc2626;
		margin-right: 3px;
	}

	/* Save button container — حذف center align */
	.wto-app-frame .wto-app-content__inner .wto_save_button_container,
	.wto-app-frame .wto-app-content__inner .wto_save_button_container_wc,
	.wto-app-frame .wto-app-content__inner .fwss_save_button_container,
	.wto-app-frame .wto-app-content__inner .fwss_save_button_container_wc {
		display: flex !important;
		flex-direction: row !important;
		justify-content: flex-start !important;
		align-items: center !important;
		gap: 12px !important;
		margin-top: 14px !important;
	}

	/* Info/error/success messages — کارت‌های مدرن */
	.wto-app-frame .wto-app-content__inner .wto-info-message,
	.wto-app-frame .wto-app-content__inner .wto_info_message,
	.wto-app-frame .wto-app-content__inner .fwss-info-message,
	.wto-app-frame .wto-app-content__inner .digits_pattern_info {
		background: #eef2ff !important;
		border: 1px solid #c7d2fe !important;
		color: #3730a3 !important;
		padding: 10px 14px !important;
		border-radius: 8px !important;
		font-size: 12.5px !important;
		line-height: 1.8 !important;
		height: auto !important;
	}
	.wto-app-frame .wto-app-content__inner .wto-error-message {
		background: #fef2f2 !important;
		border: 1px solid #fecaca !important;
		color: #b91c1c !important;
		padding: 10px 14px !important;
		border-radius: 8px !important;
		font-size: 12.5px !important;
		line-height: 1.8 !important;
		height: auto !important;
		display: block !important;
	}
	.wto-app-frame .wto-app-content__inner .wto-success-message,
	.wto-app-frame .wto-app-content__inner .fwss-success-message {
		background: #f0fdf4 !important;
		border: 1px solid #bbf7d0 !important;
		color: #15803d !important;
		padding: 10px 14px !important;
		border-radius: 8px !important;
		font-size: 12.5px !important;
		line-height: 1.8 !important;
		height: auto !important;
		display: block !important;
	}
	.wto-app-frame .wto-app-content__inner .fsms-warning-message,
	.wto-app-frame .wto-app-content__inner .fwss-warning-message {
		background: #fffbeb !important;
		border: 1px solid #fde68a !important;
		color: #78350f !important;
		padding: 10px 14px !important;
		border-radius: 8px !important;
		font-size: 12.5px !important;
		line-height: 1.8 !important;
	}
	.wto-app-frame .wto-app-content__inner .fwss_notice {
		background: #eef2ff !important;
		border: 1px solid #c7d2fe !important;
		color: #3730a3 !important;
		padding: 10px 14px !important;
		border-radius: 8px !important;
		margin-bottom: 16px !important;
	}
	.wto-app-frame .wto-app-content__inner .fwss_notice p { margin: 0 !important; font-size: 13px; line-height: 1.8; }

	/* Tabs — کامپکت‌تر */
	.wto-app-frame .wto-app-content__inner ul.tabs {
		display: flex !important;
		gap: 4px !important;
		list-style: none !important;
		margin: 0 0 0 0 !important;
		padding: 0 !important;
		transform: none !important;
		border-bottom: 1px solid #e5e7eb !important;
	}
	.wto-app-frame .wto-app-content__inner ul.tabs > li {
		display: inline-block !important;
		padding: 10px 18px !important;
		cursor: pointer !important;
		color: #64748b !important;
		font-size: 13px !important;
		font-weight: 500 !important;
		border: none !important;
		border-bottom: 2px solid transparent !important;
		box-shadow: none !important;
		margin-bottom: -1px !important;
		background: transparent !important;
		transition: all 0.15s !important;
	}
	.wto-app-frame .wto-app-content__inner ul.tabs > li:hover {
		color: #4338ca !important;
		border-bottom-color: rgba(99, 102, 241, 0.3) !important;
	}
	.wto-app-frame .wto-app-content__inner ul.tabs > li.active {
		color: #4338ca !important;
		background: transparent !important;
		border-bottom: 2px solid #6366f1 !important;
		box-shadow: none !important;
		font-weight: 600 !important;
	}
	.wto-app-frame .wto-app-content__inner ul.tab__content {
		list-style: none !important;
		margin: 0 !important;
		padding: 18px 0 0 0 !important;
	}
	.wto-app-frame .wto-app-content__inner ul.tab__content > li { padding: 0 !important; }
	.wto-app-frame .wto-app-content__inner .content__wrapper { padding: 0 !important; }

	/* Form rows — بهتر نمایش دادن row/col */
	.wto-app-frame .wto-app-content__inner .wto_form .row,
	.wto-app-frame .wto-app-content__inner .fwss_form .row {
		display: flex !important;
		flex-wrap: wrap !important;
		gap: 12px !important;
		margin: 0 !important;
	}
	.wto-app-frame .wto-app-content__inner .wto_form .col-6,
	.wto-app-frame .wto-app-content__inner .fwss_form .col-6 {
		flex: 1 1 280px !important;
		min-width: 0 !important;
		max-width: 100% !important;
		padding: 0 !important;
	}
	.wto-app-frame .wto-app-content__inner .container {
		max-width: 100% !important;
		padding: 0 !important;
		margin: 0 !important;
	}

	/* جداکننده‌های br پشت سر هم اشغال فضای زیاد می‌کنند — همشون رو jam کنیم */
	.wto-app-frame .wto-app-content__inner br + br { display: none; }

	/* Description text زیر فیلدها */
	.wto-app-frame .wto-app-content__inner .description,
	.wto-app-frame .wto-app-content__inner p.description {
		font-size: 11px !important;
		color: #64748b !important;
		margin: 4px 0 0 0 !important;
		line-height: 1.7 !important;
	}

	/* صفحات افزونه — حذف H2/H1 های قدیمی که overlap با pagehead قاب می‌کنند */
	.wto-app-frame .wto-app-content__inner > .wrap > h1:first-child,
	.wto-app-frame .wto-app-content__inner > .wrap > h2:first-child {
		display: none !important;
	}

	/* ── Frame container ───────────────────────────────────────── */
	.wto-app-frame {
		direction: rtl;
		font-family: Tahoma, 'IRANSans', 'Vazir', -apple-system, BlinkMacSystemFont, sans-serif;
		background: #f1f3f6;
		border: 1px solid #e2e6ea;
		border-radius: 12px;
		overflow: hidden;
		box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
		margin-right: 20px !important;
		margin-left: 20px !important;
		margin-top: 16px !important;
	}

	/* ── Topbar ────────────────────────────────────────────────── */
	.wto-app-topbar {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 16px;
		padding: 14px 20px;
		background: linear-gradient(135deg, #4338ca 0%, #6366f1 60%, #8b5cf6 100%);
		color: #fff;
	}
	.wto-app-topbar__brand { display: flex; align-items: center; gap: 12px; }
	.wto-app-topbar__logo img { height: 32px; width: auto; display: block; filter: brightness(0) invert(1); }
	.wto-app-topbar__version {
		background: rgba(255,255,255,0.18);
		color: #fff;
		font-size: 11px;
		font-weight: 600;
		padding: 3px 8px;
		border-radius: 20px;
		letter-spacing: 0.3px;
	}
	.wto-app-topbar__meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
	.wto-app-topbar__credit {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		background: rgba(255,255,255,0.16);
		color: #fff !important;
		padding: 8px 14px;
		border-radius: 8px;
		font-size: 13px;
		font-weight: 500;
		text-decoration: none;
		transition: background 0.15s;
	}
	.wto-app-topbar__credit:hover { background: rgba(255,255,255,0.26); }
	.wto-app-topbar__credit strong { font-size: 14px; font-weight: 700; }
	.wto-app-topbar__credit-unit { opacity: 0.85; font-size: 12px; }
	.wto-app-topbar__credit--empty { background: rgba(244, 67, 54, 0.85); }
	.wto-app-topbar__credit--empty:hover { background: rgba(244, 67, 54, 1); }
	.wto-app-topbar__credit .dashicons { font-size: 18px; width: 18px; height: 18px; }
	.wto-app-topbar__button {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		background: rgba(255,255,255,0.10);
		border: 1px solid rgba(255,255,255,0.22);
		color: #fff !important;
		padding: 7px 12px;
		border-radius: 8px;
		font-size: 13px;
		text-decoration: none;
		transition: background 0.15s;
	}
	.wto-app-topbar__button:hover { background: rgba(255,255,255,0.20); }
	.wto-app-topbar__button .dashicons { font-size: 16px; width: 16px; height: 16px; }
	.wto-app-topbar__button--icon { padding: 7px 9px; }

	/* ── Body (sidebar + content) ─────────────────────────────── */
	.wto-app-body {
		display: grid;
		grid-template-columns: 240px 1fr;
		min-height: 600px;
		background: #fff;
	}
	.wto-app-sidebar {
		background: #f8fafc;
		border-left: 1px solid #e5e7eb;
		padding: 16px 0;
		position: relative;
	}
	.wto-app-sidebar__toggle {
		display: none;
		background: transparent;
		border: 0;
		cursor: pointer;
		color: #475569;
		padding: 6px;
		position: absolute;
		top: 8px;
		left: 8px;
	}
	.wto-app-sidebar__group { margin-bottom: 18px; }
	.wto-app-sidebar__group-label {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 11px;
		font-weight: 700;
		color: #64748b;
		letter-spacing: 0.4px;
		padding: 4px 16px 8px;
		text-transform: none;
	}
	.wto-app-sidebar__group-label .dashicons { font-size: 14px; width: 14px; height: 14px; color: #94a3b8; }
	.wto-app-sidebar__items { list-style: none; margin: 0; padding: 0; }
	.wto-app-sidebar__item a {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 9px 16px;
		color: #334155 !important;
		text-decoration: none;
		font-size: 13px;
		font-weight: 500;
		border-right: 3px solid transparent;
		transition: background 0.12s, border-color 0.12s, color 0.12s;
	}
	.wto-app-sidebar__item a:hover {
		background: #eef2ff;
		color: #4338ca !important;
		border-right-color: rgba(99, 102, 241, 0.35);
	}
	.wto-app-sidebar__item a .dashicons {
		font-size: 16px;
		width: 16px;
		height: 16px;
		color: #94a3b8;
		transition: color 0.12s;
	}
	.wto-app-sidebar__item a:hover .dashicons { color: #6366f1; }
	.wto-app-sidebar__item.is-active a {
		background: #eef2ff;
		color: #4338ca !important;
		border-right-color: #6366f1;
		font-weight: 600;
	}
	.wto-app-sidebar__item.is-active a .dashicons { color: #6366f1; }

	/* ── Content ─────────────────────────────────────────────── */
	.wto-app-content {
		padding: 20px 24px 28px;
		background: #fff;
		min-width: 0;
	}
	.wto-app-pagehead {
		border-bottom: 1px solid #e5e7eb;
		padding-bottom: 14px;
		margin-bottom: 18px;
	}
	.wto-app-pagehead__title {
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 20px;
		margin: 0;
		color: #0f172a;
		font-weight: 700;
	}
	.wto-app-pagehead__title .dashicons {
		font-size: 22px;
		width: 22px;
		height: 22px;
		color: #6366f1;
	}
	.wto-app-pagehead__desc {
		margin: 6px 0 0;
		color: #64748b;
		font-size: 13px;
	}
	.wto-app-content__inner { min-height: 400px; }

	/* ── Footer ──────────────────────────────────────────────── */
	.wto-app-footer {
		padding: 14px 20px;
		background: #f8fafc;
		border-top: 1px solid #e5e7eb;
		font-size: 12px;
		color: #64748b;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.wto-app-footer__row {
		display: flex;
		gap: 8px;
		align-items: center;
		flex-wrap: wrap;
	}
	.wto-app-footer a { color: #475569 !important; text-decoration: none; }
	.wto-app-footer a:hover { color: #4338ca !important; }
	.wto-app-footer__sep { opacity: 0.5; }
	/* v3.17.7: اعلامیه‌ی کپی‌رایت — فونت کوچک، رنگ خنثی، spacing مناسب */
	.wto-app-footer__copyright {
		padding-top: 8px;
		border-top: 1px dashed #e5e7eb;
		font-size: 11px;
		color: #94a3b8;
		line-height: 1.7;
		text-align: center;
	}

	/* ── Responsive ──────────────────────────────────────────── */
	@media (max-width: 960px) {
		.wto-app-body { grid-template-columns: 1fr; }
		.wto-app-sidebar { border-left: 0; border-bottom: 1px solid #e5e7eb; padding: 12px 0; }
		.wto-app-sidebar__group { margin-bottom: 10px; }
		.wto-app-topbar { flex-wrap: wrap; }
	}

	/* ── Hide WP standard top H1 inside frame to avoid duplication ── */
	.wto-app-frame .wto-app-content__inner > .wrap > h1:first-child {
		font-size: 16px;
		font-weight: 600;
		color: #475569;
		padding: 0;
		margin: 0 0 12px;
	}

	/* ── Reduce visual clutter: hide WP submenu dividers in this frame ── */
	#adminmenu .toplevel_page_farazwto .wp-submenu .wto-menu-divider { font-size: 10px; opacity: 0.7; }

	/* ── Hub page styles ─────────────────────────────────────── */
	.wto-hub-hero {
		background: linear-gradient(135deg, #eef2ff 0%, #faf5ff 100%);
		border: 1px solid #e0e7ff;
		border-radius: 12px;
		padding: 24px 26px;
		margin-bottom: 22px;
	}
	.wto-hub-hero h2 { margin: 0 0 6px; font-size: 22px; color: #312e81; font-weight: 700; }
	.wto-hub-hero p { margin: 0; color: #4338ca; font-size: 14px; line-height: 1.9; }

	.wto-hub-stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
		gap: 14px;
		margin-bottom: 26px;
	}
	.wto-hub-stat {
		background: #fff;
		border: 1px solid #e5e7eb;
		border-radius: 10px;
		padding: 16px 18px;
		display: flex;
		align-items: center;
		gap: 12px;
	}
	.wto-hub-stat__icon {
		width: 42px;
		height: 42px;
		border-radius: 10px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		background: #eef2ff;
		color: #6366f1;
		flex-shrink: 0;
	}
	.wto-hub-stat__icon .dashicons { font-size: 22px; width: 22px; height: 22px; }
	.wto-hub-stat__body { flex: 1; min-width: 0; }
	.wto-hub-stat__label { font-size: 12px; color: #64748b; margin: 0 0 3px; }
	.wto-hub-stat__value { font-size: 18px; color: #0f172a; font-weight: 700; margin: 0; }

	.wto-hub-group {
		margin-bottom: 28px;
	}
	.wto-hub-group__head {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
	}
	.wto-hub-group__title {
		font-size: 15px;
		font-weight: 700;
		color: #0f172a;
		margin: 0;
	}
	.wto-hub-group__divider {
		flex: 1;
		height: 1px;
		background: linear-gradient(to left, transparent, #e5e7eb);
	}
	.wto-hub-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 12px;
	}
	.wto-hub-card {
		display: flex;
		gap: 12px;
		padding: 14px 16px;
		background: #fff;
		border: 1px solid #e5e7eb;
		border-radius: 10px;
		text-decoration: none !important;
		color: inherit !important;
		transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
	}
	.wto-hub-card:hover {
		border-color: #c7d2fe;
		box-shadow: 0 4px 12px rgba(99,102,241,0.10);
		transform: translateY(-1px);
	}
	.wto-hub-card__icon {
		width: 36px;
		height: 36px;
		border-radius: 8px;
		background: #f1f5f9;
		color: #6366f1;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}
	.wto-hub-card__icon .dashicons { font-size: 20px; width: 20px; height: 20px; }
	.wto-hub-card__body { flex: 1; min-width: 0; }
	.wto-hub-card__title { font-size: 14px; font-weight: 600; color: #0f172a; margin: 0 0 3px; }
	.wto-hub-card__desc { font-size: 12px; color: #64748b; margin: 0; line-height: 1.7; }
	</style>
	<script>
	// Sidebar toggle on mobile
	document.addEventListener('DOMContentLoaded', function() {
		var btn = document.querySelector('.wto-app-sidebar__toggle');
		var nav = document.querySelector('.wto-app-sidebar__nav');
		if (btn && nav) {
			btn.addEventListener('click', function() {
				nav.style.display = (nav.style.display === 'none') ? '' : 'none';
			});
		}
	});
	</script>
	<?php
}

/* ------------------------------------------------------------------------- *
 *  Hide WP sidebar submenu when inside a plugin page (WoodMart-style)
 *  We don't fully remove the submenu (still accessible via direct click on
 *  parent), but we visually collapse it to reduce sidebar noise.
 * ------------------------------------------------------------------------- */

add_action( 'admin_head', 'wto_hide_wp_submenu_when_in_plugin' );
function wto_hide_wp_submenu_when_in_plugin() {
	if ( ! wto_is_plugin_page() ) {
		return;
	}
	?>
	<style id="wto-hide-wp-submenu-css">
	/* On plugin pages, hide the duplicated WP submenu — internal nav inside the frame
	   already handles navigation. The top-level item stays clickable from sidebar. */
	#adminmenu .toplevel_page_farazwto .wp-submenu-wrap,
	#adminmenu .toplevel_page_farazwto .wp-submenu {
		display: none !important;
	}
	#adminmenu .toplevel_page_farazwto.wp-has-current-submenu .wp-submenu-head {
		display: block !important;
	}
	/* Re-show submenu on hover so users can still jump between top-level items */
	#adminmenu .toplevel_page_farazwto:hover .wp-submenu-wrap,
	#adminmenu .toplevel_page_farazwto:hover .wp-submenu {
		display: block !important;
	}
	/* On non-plugin pages: leave submenu alone (handled by menu organizer normally) */
	</style>
	<?php
}

/* ------------------------------------------------------------------------- *
 *  Hub page render
 * ------------------------------------------------------------------------- */

function wto_dashboard_hub_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'دسترسی غیرمجاز.', 'wto' ) );
	}

	$apikey  = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : '';
	$credit  = '';
	if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) {
		$credit_raw = wto_get_credit();
		if ( $credit_raw !== false && $credit_raw !== '' ) {
			$credit = (string) $credit_raw;
		}
	}
	$stats = wto_dashboard_collect_stats();
	?>
	<div class="wto-hub-wrapper">

		<?php if ( empty( $apikey ) ) : ?>
			<div class="wto-hub-hero" style="background: linear-gradient(135deg, #fef2f2 0%, #fff7ed 100%); border-color:#fecaca;">
				<h2 style="color:#9f1239;">👋 <?php esc_html_e( 'به افزونه فراز اس‌ام‌اس خوش آمدید', 'wto' ); ?></h2>
				<p style="color:#b91c1c;">
					<?php esc_html_e( 'برای شروع، کلید دسترسی (Api-Key) را در صفحه تنظیمات وارد کنید.', 'wto' ); ?>
					&nbsp;
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-settings' ) ); ?>" style="color:#fff !important; background:#b91c1c; padding:6px 14px; border-radius:6px; text-decoration:none; font-weight:600;">
						<?php esc_html_e( 'ورود به صفحه تنظیمات', 'wto' ); ?>
					</a>
				</p>
			</div>
		<?php else : ?>
			<div class="wto-hub-hero">
				<?php
				// نام را از پنل پیامکی (وب‌سرویس پروفایل) بگیر؛ اگر در دسترس نبود به نام
				// نمایشیِ کاربر وردپرس برگرد.
				$wto_hero_profile = function_exists( 'wto_get_profile' ) ? wto_get_profile() : false;
				$wto_hero_name = ( is_array( $wto_hero_profile ) && ! empty( $wto_hero_profile['display_name'] ) )
					? $wto_hero_profile['display_name']
					: wp_get_current_user()->display_name;
				?>
				<h2>👋 <?php printf( esc_html__( 'سلام %s، خوش آمدید', 'wto' ), esc_html( $wto_hero_name ) ); ?></h2>
				<p><?php esc_html_e( 'این داشبورد به شما نمای کلی از وضعیت پلاگین، آمار ارسال و دسترسی سریع به تمام قابلیت‌ها را می‌دهد.', 'wto' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		// اگر ارسالِ درخواست به فراز اس ام اس مسدود شده باشد، علتِ واقعی را همین‌جا (به‌جای
		// «کلید نامعتبر») نشان بده.
		if ( ! empty( $apikey ) && function_exists( 'wto_connectivity_inline_warning_html' ) ) {
			echo wto_connectivity_inline_warning_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>

		<!-- Quick stat cards -->
		<div class="wto-hub-stats">
			<div class="wto-hub-stat">
				<div class="wto-hub-stat__icon"><span class="dashicons dashicons-money-alt"></span></div>
				<div class="wto-hub-stat__body">
					<p class="wto-hub-stat__label"><?php esc_html_e( 'اعتبار پنل', 'wto' ); ?></p>
					<p class="wto-hub-stat__value"><?php echo $credit !== '' ? esc_html( $credit ) . ' <small style="font-size:11px; font-weight:500; color:#64748b;">تومان</small>' : '—'; ?></p>
				</div>
			</div>
			<div class="wto-hub-stat">
				<div class="wto-hub-stat__icon" style="background:#dcfce7; color:#16a34a;"><span class="dashicons dashicons-groups"></span></div>
				<div class="wto-hub-stat__body">
					<p class="wto-hub-stat__label"><?php esc_html_e( 'مشترکین خبرنامه', 'wto' ); ?></p>
					<p class="wto-hub-stat__value"><?php echo esc_html( number_format_i18n( $stats['newsletter_subs'] ) ); ?></p>
				</div>
			</div>
			<div class="wto-hub-stat">
				<div class="wto-hub-stat__icon" style="background:#fef3c7; color:#d97706;"><span class="dashicons dashicons-bell"></span></div>
				<div class="wto-hub-stat__body">
					<p class="wto-hub-stat__label"><?php esc_html_e( 'موجود شد خبرم کن', 'wto' ); ?></p>
					<p class="wto-hub-stat__value"><?php echo esc_html( number_format_i18n( $stats['notify_subs'] ) ); ?></p>
				</div>
			</div>
			<div class="wto-hub-stat">
				<div class="wto-hub-stat__icon" style="background:#fee2e2; color:#dc2626;"><span class="dashicons dashicons-cart"></span></div>
				<div class="wto-hub-stat__body">
					<p class="wto-hub-stat__label"><?php esc_html_e( 'سبد رهاشده (۷ روز)', 'wto' ); ?></p>
					<p class="wto-hub-stat__value"><?php echo esc_html( number_format_i18n( $stats['abandoned_recent'] ) ); ?></p>
				</div>
			</div>
			<?php
			// v3.14.1: کارت برجسته آمار کش‌بک — اگر سیستم فعال، نمایش اثربخشی روی فروش.
			if ( function_exists( 'wto_cashback_get_stats' ) ) :
				$cb_stats = wto_cashback_get_stats();
				if ( ! empty( $cb_stats['enabled'] ) ) :
					$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'تومان';
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-cashback' ) ); ?>" class="wto-hub-stat" style="text-decoration:none; color:inherit; cursor:pointer; background:linear-gradient(135deg,#16a34a 0%,#059669 100%); color:#fff; grid-column: span 2;">
					<div class="wto-hub-stat__icon" style="background:rgba(255,255,255,0.18); color:#fff;"><span class="dashicons dashicons-chart-line"></span></div>
					<div class="wto-hub-stat__body">
						<p class="wto-hub-stat__label" style="color:#fff; opacity:0.95;"><?php esc_html_e( '📈 افزایش فروش از سیستم کش‌بک', 'wto' ); ?></p>
						<p class="wto-hub-stat__value" style="color:#fff;">
							<?php echo esc_html( number_format_i18n( $cb_stats['sales_with_cashback'] ) ); ?>
							<small style="font-size:11px; font-weight:500; opacity:0.85;"><?php echo esc_html( $currency ); ?></small>
						</p>
						<p style="margin:2px 0 0; font-size:10px; opacity:0.85;">
							<?php echo esc_html( number_format_i18n( $cb_stats['repeat_customers'] ) ); ?> مشتری بازگشتی · نرخ تبدیل <?php echo esc_html( $cb_stats['conversion_rate'] ); ?>٪
						</p>
					</div>
				</a>
				<?php endif; endif; ?>

			<?php
			// v3.13.20: کارت‌های آمار ماژول ورود/ثبت‌نام — تعداد کل اعضای سایت + ثبت‌نام با ماژول.
			if ( function_exists( 'wto_login_module_get_stats' ) ) :
				$login_stats = wto_login_module_get_stats();
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-login-register' ) ); ?>" class="wto-hub-stat" style="text-decoration:none; color:inherit; cursor:pointer;">
					<div class="wto-hub-stat__icon" style="background:#e0e7ff; color:#4338ca;"><span class="dashicons dashicons-admin-users"></span></div>
					<div class="wto-hub-stat__body">
						<p class="wto-hub-stat__label"><?php esc_html_e( 'کل اعضای سایت', 'wto' ); ?></p>
						<p class="wto-hub-stat__value"><?php echo esc_html( number_format_i18n( $login_stats['total_users'] ) ); ?></p>
					</div>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=farazwto-login-register' ) ); ?>" class="wto-hub-stat" style="text-decoration:none; color:inherit; cursor:pointer;">
					<div class="wto-hub-stat__icon" style="background:#f3e8ff; color:#7c3aed;"><span class="dashicons dashicons-smartphone"></span></div>
					<div class="wto-hub-stat__body">
						<p class="wto-hub-stat__label">
							<?php esc_html_e( 'ثبت‌نام با موبایل', 'wto' ); ?>
							<?php if ( ! $login_stats['enabled'] ) : ?>
								<span style="display:inline-block; background:#f97316; color:#fff; font-size:9px; padding:1px 6px; border-radius:3px; margin-right:4px;">غیرفعال</span>
							<?php endif; ?>
						</p>
						<p class="wto-hub-stat__value"><?php echo esc_html( number_format_i18n( $login_stats['module_users'] ) ); ?></p>
					</div>
				</a>
			<?php endif; ?>
		</div>

		<!-- پنل خرید شارژ — مستقیم در داشبورد قابل دسترس -->
		<?php if ( function_exists( 'wto_render_wallet_recharge_panel' ) ) : ?>
			<?php wto_render_wallet_recharge_panel(); ?>
		<?php endif; ?>

		<!-- پنل تست ارسال پیامک (compact mode — فقط فرم تست SMS، بدون status/credit cards) -->
		<?php if ( function_exists( 'wto_render_connection_test_panel' ) ) : ?>
			<?php wto_render_connection_test_panel( array( 'compact' => true ) ); ?>
		<?php endif; ?>

		<!-- Feature groups -->
		<?php foreach ( wto_dashboard_nav_groups() as $gkey => $group ) :
			// در hub، گروه «تنظیمات اصلی» خود داشبورد را دوباره نشان ندهد
			$items = array_filter( $group['items'], function( $i ) { return $i['slug'] !== 'farazwto-dashboard'; } );
			if ( empty( $items ) ) continue;
			?>
			<section class="wto-hub-group">
				<div class="wto-hub-group__head">
					<h3 class="wto-hub-group__title">
						<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>" style="color:#6366f1; vertical-align:middle;"></span>
						<?php echo esc_html( $group['label'] ); ?>
					</h3>
					<span class="wto-hub-group__divider"></span>
				</div>
				<div class="wto-hub-grid">
					<?php foreach ( $items as $item ) : ?>
						<a href="<?php echo esc_url( wto_dashboard_item_url( $item['slug'] ) ); ?>" class="wto-hub-card">
							<div class="wto-hub-card__icon"><span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span></div>
							<div class="wto-hub-card__body">
								<p class="wto-hub-card__title"><?php echo esc_html( $item['title'] ); ?></p>
								<p class="wto-hub-card__desc"><?php echo esc_html( $item['desc'] ); ?></p>
							</div>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>

	</div>
	<?php
}

/**
 * جمع‌آوری آمار سریع برای نمایش در hub.
 * هر شمارش inside try/catch تا اگر جدولی نباشد، صفر برگردانده شود.
 */
function wto_dashboard_collect_stats() {
	global $wpdb;
	$out = array(
		'newsletter_subs'  => 0,
		'notify_subs'      => 0,
		'abandoned_recent' => 0,
	);

	// Newsletter subscribers (wp_wto_newsletter_subscribers — column status='active')
	$t = $wpdb->prefix . 'wto_newsletter_subscribers';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
		$out['newsletter_subs'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", 'active' ) );
	}

	// Notify-me subscribers (wp_wto_notify_subscribers — column status='pending')
	$t = $wpdb->prefix . 'wto_notify_subscribers';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
		$out['notify_subs'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", 'pending' ) );
	}

	// Abandoned carts in last 7 days (wp_wto_abandoned_carts — created_at DATETIME)
	$t = $wpdb->prefix . 'wto_abandoned_carts';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
		$out['abandoned_recent'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $t WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);
	}

	return $out;
}

/* ------------------------------------------------------------------------- *
 *  Tell core-init.php to enqueue assets on the new slug too
 * ------------------------------------------------------------------------- */

add_filter( 'admin_body_class', 'wto_dashboard_body_class' );
function wto_dashboard_body_class( $classes ) {
	if ( wto_is_plugin_page() ) {
		$classes .= ' wto-in-plugin-frame';
	}
	return $classes;
}
