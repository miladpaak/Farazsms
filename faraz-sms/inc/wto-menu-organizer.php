<?php
/**
 * سازماندهی و گروه‌بندی منوی پلاگین — Phase 5
 *
 * این فایل ساختار منوی «فراز اس‌ام‌اس» در پیشخوان وردپرس را بازچینی می‌کند
 * و گروه‌بندی بصری اضافه می‌کند. ترتیب مطلوب:
 *
 *   تنظیمات اصلی
 *   ─────────────────
 *     تنظیمات افزونه
 *     ارسال پیامک
 *     گزارشات ارسال پیامک
 *
 *   باشگاه مشتریان و افزایش فروش
 *   ─────────────────
 *     باشگاه مشتریان
 *     خبرنامه پیامکی
 *     لید مگنت
 *
 *   ووکامرس و سفارشات
 *   ─────────────────
 *     کد رهگیری سفارش
 *     نظرسنجی پس از خرید
 *     دیدگاه سایت
 *     موجود شد خبرم کن
 *     سبد خرید رها‌شده
 *
 *   ابزارها و فرم‌ها
 *   ─────────────────
 *     اطلاع‌رسانی گرویتی - المنتور
 *     ورود و ثبت نام
 *     بازخورد
 *
 * پیاده‌سازی: hook روی admin_menu با اولویت 9999 (پس از همه ثبت‌ها) و دستکاری
 * مستقیم آرایه‌ی `$submenu['farazwto']`.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_action( 'admin_menu', 'wto_menu_reorganize', 9999 );
function wto_menu_reorganize() {
	global $submenu;
	if ( ! isset( $submenu['farazwto'] ) || ! is_array( $submenu['farazwto'] ) ) {
		return;
	}

	// Group definitions — each group is rendered with a divider header.
	// در v3.13.0:
	//   - «داشبورد» اولین آیتم در گروه «تنظیمات اصلی»
	//   - «اتومیشن مارکتینگ» از other به club منتقل شد (تصمیم محصول)
	$groups = array(
		'general' => array(
			'label' => __( 'تنظیمات اصلی', 'wto' ),
			'items' => array(
				'farazwto-dashboard',
				'farazwto-roi',          // v3.15.0: داشبورد ROI — اولین چیزی که کاربر بعد از داشبورد می‌بیند
				'farazwto-settings',
				'farazwto-send-sms',
				'farazwto-reports',      // v3.17.6: DLR حالا تب داخل گزارشات است
				'farazwto-updates',      // v3.16.0: به‌روزرسانی خودکار
			),
		),
		'club'    => array(
			'label' => __( 'باشگاه مشتریان و افزایش فروش', 'wto' ),
			'items' => array(
				'farazwto-phonebook',
				'farazwto-newsletter',
				'farazwto-lead-magnet',
				'farazwto-birthday',     // v3.17.0: تبریک تولد + کوپن
				'farazwto-user-panel',   // پنل کاربریِ مشتری
				'farazwto-automation',
			),
		),
		'woo'     => array(
			'label' => __( 'ووکامرس و سفارشات', 'wto' ),
			'items' => array(
				'farazwto',           // کد رهگیری (top-level slug)
				'farazwto-poll',
				'farazwto-comments',
				'farazwto-notify',
				'farazwto-abandoned',
			),
		),
		'other'   => array(
			'label' => __( 'ابزارها و فرم‌ها', 'wto' ),
			'items' => array(
				'farazwto-sms-forms',
				'farazwto-login-register',
				'farazwto-developers',   // بالای «بازخورد»
				'farazwto-feedback',
			),
		),
	);

	// Index existing $submenu entries by slug.
	$by_slug = array();
	foreach ( $submenu['farazwto'] as $entry ) {
		if ( isset( $entry[2] ) ) {
			$by_slug[ $entry[2] ] = $entry;
		}
	}

	// نکته مهم: WP برای URL منوی والد، اولین زیرمنوی قابل دسترس را انتخاب می‌کند.
	// اگر اولین آیتم یک divider (با اسلاگ #wto-divider-X) باشد، کلیک روی فراز اس‌ام‌اس
	// به جای صفحه واقعی، به یک URL با hash منتقل می‌شود (در عمل به /wp-admin/ می‌رود).
	// بنابراین گروه اول را بدون divider header شروع می‌کنیم تا اولین آیتم واقعی
	// (داشبورد) به‌عنوان landing کلیک روی منوی والد عمل کند.
	$new   = array();
	$first = true;
	foreach ( $groups as $key => $group ) {
		if ( ! $first ) {
			// Insert divider header (only for groups AFTER the first).
			$new[] = array(
				'<span class="wto-menu-divider">' . esc_html( $group['label'] ) . '</span>',
				'read',
				'#wto-divider-' . sanitize_key( $key ),
			);
		}
		$first = false;
		// Pull configured items in the desired order.
		foreach ( $group['items'] as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$new[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}
	}
	// Append any unmapped items at the end (so we never lose menu entries).
	foreach ( $by_slug as $entry ) {
		$new[] = $entry;
	}
	$submenu['farazwto'] = array_values( $new );
}

/**
 * کوتاه کردنِ منوی کناریِ وردپرس به «بخش‌های اصلی» — فقط با CSS (بدونِ دستکاریِ $submenu).
 *
 * درسِ مهم: حذفِ آیتم‌ها از $submenu باعث می‌شود کنترلِ دسترسیِ وردپرس آن صفحات را
 * «غیرمجاز» بداند (get_admin_page_parent والد را پیدا نمی‌کند → خطای دسترسی). پس به‌جای
 * حذف، فقط با CSS از سایدبار پنهان می‌کنیم. صفحات ثبت‌شده و کاملاً قابل‌دسترس می‌مانند
 * (از طریقِ سایدبارِ داخلیِ افزونه و آدرسِ مستقیم).
 *
 * بخش‌هایی که در سایدبار می‌مانند: داشبورد، تنظیمات، ارسال پیامک، گزارشات، ورود و ثبت‌نام، کش‌بک.
 * فهرست با فیلتر wto_sidebar_visible_slugs قابل تغییر است.
 */
add_action( 'admin_head', 'wto_menu_trim_sidebar_css', 100 );
function wto_menu_trim_sidebar_css() {
	global $submenu;
	if ( empty( $submenu['farazwto'] ) || ! is_array( $submenu['farazwto'] ) ) {
		return;
	}
	$keep = apply_filters( 'wto_sidebar_visible_slugs', array(
		'farazwto-dashboard',
		'farazwto-settings',
		'farazwto-send-sms',
		'farazwto-reports',
		'farazwto-login-register',
		'farazwto-cashback',
		'farazwto-updates',      // بروزرسانی — به درخواستِ کاربر در سایدبار بماند
		'farazwto-developers',   // برنامه‌نویسان — به درخواستِ کاربر در سایدبار بماند
	) );

	$selectors = array();
	foreach ( $submenu['farazwto'] as $entry ) {
		$slug = isset( $entry[2] ) ? (string) $entry[2] : '';
		if ( $slug === '' || in_array( $slug, $keep, true ) ) {
			continue;
		}
		if ( strpos( $slug, 'wto-divider' ) !== false ) {
			continue; // dividerها جداگانه پایین مخفی می‌شوند
		}
		$s           = esc_attr( $slug );
		$selectors[] = '#adminmenu .toplevel_page_farazwto .wp-submenu li:has(> a[href$="page=' . $s . '"])';
		$selectors[] = '#adminmenu .toplevel_page_farazwto .wp-submenu li > a[href$="page=' . $s . '"]';
	}
	// همه‌ی divider headerها هم پنهان شوند (با ۶ آیتم، گروه‌بندی لازم نیست).
	$selectors[] = '#adminmenu .toplevel_page_farazwto .wp-submenu li:has(.wto-menu-divider)';

	if ( ! empty( $selectors ) ) {
		echo "\n<style id=\"wto-menu-trim\">" . implode( ',', $selectors ) . "{display:none !important;}</style>\n";
	}
}


/**
 * Style the menu dividers — gray uppercase headers that are visually distinct
 * but still readable. Targets the <span class="wto-menu-divider"> we inject above.
 */
add_action( 'admin_head', 'wto_menu_divider_css' );
function wto_menu_divider_css() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	// Only inject on pages where the admin menu is shown (i.e. any admin screen).
	?>
	<style id="wto-menu-divider-css">
	#adminmenu .toplevel_page_farazwto .wp-submenu .wto-menu-divider {
		display: block;
		color: #c1c8d4;
		font-size: 11px;
		font-weight: 600;
		letter-spacing: 0.4px;
		padding: 8px 12px 4px;
		margin-top: 8px;
		border-top: 1px solid rgba(255, 255, 255, 0.08);
		text-transform: none;
		opacity: 0.85;
		cursor: default;
	}
	#adminmenu .toplevel_page_farazwto .wp-submenu .wto-menu-divider.wto-menu-divider-first {
		margin-top: 0;
		border-top: 0;
	}
	#adminmenu .toplevel_page_farazwto .wp-submenu a:has(.wto-menu-divider) {
		pointer-events: none;
		padding: 0 !important;
		background: transparent !important;
	}
	#adminmenu .toplevel_page_farazwto .wp-submenu a:has(.wto-menu-divider):hover,
	#adminmenu .toplevel_page_farazwto .wp-submenu a:has(.wto-menu-divider):focus {
		color: inherit !important;
		background: transparent !important;
	}
	</style>
	<?php
}

/**
 * Headers use slug `#wto-divider-X` which WP turns into a real link. Click on
 * a header redirects to the first real item of that group to avoid 404.
 * Without this, clicking on a divider would 404.
 */
add_action( 'admin_init', 'wto_menu_divider_redirect' );
function wto_menu_divider_redirect() {
	if ( ! isset( $_GET['page'] ) ) {
		return;
	}
	// sanitize_key() در PHP کاراکتر `#` را حذف می‌کند. بنابراین اسلاگ‌ها بعد از sanitize
	// به شکل `wto-divider-X` می‌رسند (بدون `#`). map باید بر اساس فرم sanitize شده باشد.
	$page = sanitize_key( wp_unslash( $_GET['page'] ) );
	$map  = array(
		'wto-divider-general' => 'farazwto-dashboard',
		'wto-divider-club'    => 'farazwto-phonebook',
		'wto-divider-woo'     => 'farazwto',
		'wto-divider-other'   => 'farazwto-sms-forms',
	);
	if ( isset( $map[ $page ] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $map[ $page ] ) );
		exit;
	}
}

/**
 * Onboarding notice — shows a friendly setup hint on any plugin page when the
 * Api-Key isn't configured yet. This single banner replaces dozens of «Api-Key
 * missing» error messages users would otherwise see, and gives them a clear
 * one-click path to the settings page.
 *
 * Plus: a "did you know" hint with quick links to the major features for first-time admins.
 */
add_action( 'admin_notices', 'wto_admin_onboarding_notice' );
function wto_admin_onboarding_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$on_plugin_page = $screen && strpos( (string) $screen->id, 'farazwto' ) !== false;
	if ( ! $on_plugin_page ) {
		return;
	}
	// Don't show on the settings page itself (user is already there), and don't show
	// on the dashboard hub (it shows its own onboarding hero box).
	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $current_page === 'farazwto-settings' || $current_page === 'farazwto-dashboard' ) {
		return;
	}
	$apikey = function_exists( 'wto_get_apikey' ) ? wto_get_apikey() : '';
	if ( $apikey !== '' ) {
		return;
	}
	$settings_url = admin_url( 'admin.php?page=farazwto-settings' );
	?>
	<div class="notice notice-warning wto-onboarding-notice">
		<p>
			<strong>👋 <?php esc_html_e( 'به افزونه فراز اس‌ام‌اس خوش آمدید!', 'wto' ); ?></strong>
			<?php esc_html_e( 'برای شروع، کلید دسترسی (Api-Key) را وارد کنید — این کلید ارتباط افزونه با سامانه پیامکی شما را برقرار می‌کند.', 'wto' ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary"><?php esc_html_e( 'تنظیمات و وارد کردن Api-Key', 'wto' ); ?></a>
		</p>
		<p class="wto-onboarding-help">
			<?php esc_html_e( 'برای دریافت Api-Key:', 'wto' ); ?>
			<a href="https://sms.farazsms.com/" target="_blank" rel="noopener"><?php esc_html_e( 'ورود به پنل فراز اس‌ام‌اس', 'wto' ); ?></a>
			← <?php esc_html_e( 'مدیریت کلیدهای دسترسی', 'wto' ); ?> ← <?php esc_html_e( 'کپی کلید', 'wto' ); ?>
		</p>
	</div>
	<style>
	.wto-onboarding-notice { border-right-color: #6366f1 !important; padding: 14px 18px !important; }
	.wto-onboarding-notice p { font-size: 14px; margin: 6px 0; }
	.wto-onboarding-notice .button { margin-right: 8px; }
	.wto-onboarding-notice .wto-onboarding-help { color: #50575e; font-size: 13px; }
	</style>
	<?php
}

/**
 * Page-specific help blurbs. Adds a short info block at the top of pages that
 * don't already have their own guide section (settings, tracking-code, comments,
 * sms-forms, etc.). The text answers the most common first-time-user questions.
 */
add_action( 'admin_notices', 'wto_admin_page_specific_help' );
function wto_admin_page_specific_help() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$blurbs = array(
		'farazwto-settings' => array(
			'title' => __( 'تنظیمات افزونه فراز اس‌ام‌اس', 'wto' ),
			'body'  => __( 'در این صفحه کلید دسترسی (Api-Key) و خط ارسال پیش‌فرض را وارد می‌کنید. این دو در همه قابلیت‌های پلاگین استفاده می‌شود.', 'wto' ),
			'tip'   => __( 'اگر Api-Key ندارید: وارد پنل فراز شوید → مدیریت کلیدهای دسترسی → ساخت کلید جدید → کپی → چسباندن در فیلد زیر.', 'wto' ),
		),
		'farazwto'          => array(
			'title' => __( 'کد رهگیری سفارش', 'wto' ),
			'body'  => __( 'با ساخت الگوی پیامک «کد رهگیری»، پس از ثبت کد رهگیری روی سفارش ووکامرس، یک پیامک خودکار با کد رهگیری به مشتری ارسال می‌شود.', 'wto' ),
			'tip'   => __( 'متغیرهای قابل استفاده در متن الگو: %order_id% و %tracking_code% — نام برند فروشگاه را به‌صورت ثابت بنویسید.', 'wto' ),
		),
		'farazwto-comments' => array(
			'title' => __( 'پیامک رویدادهای دیدگاه', 'wto' ),
			'body'  => __( 'با تنظیم الگوهای پیامک برای ثبت دیدگاه/تأیید/پاسخ، در رویدادهای مربوط به دیدگاه‌های سایت پیامک خودکار ارسال می‌شود.', 'wto' ),
			'tip'   => __( 'برای دیدگاه‌های سفارش‌محور (پیامک نظرسنجی)، به منوی «نظرسنجی پس از خرید» مراجعه کنید.', 'wto' ),
		),
		'farazwto-sms-forms' => array(
			'title' => __( 'یکپارچگی با فرم‌ها', 'wto' ),
			'body'  => __( 'با Gravity Forms و Elementor Forms یکپارچه می‌شود. می‌توانید روی submit فرم، پیامک به ادمین یا کاربر بفرستید + فیلد OTP تأیید موبایل اضافه کنید.', 'wto' ),
			'tip'   => __( 'فیلد OTP کاربر را مجبور به تأیید موبایل قبل از ارسال فرم می‌کند — راهی ساده برای جلوگیری از فرم‌های اسپم.', 'wto' ),
		),
	);
	if ( ! isset( $blurbs[ $current_page ] ) ) {
		return;
	}
	$b = $blurbs[ $current_page ];
	?>
	<div class="notice notice-info wto-page-help-notice">
		<p>
			<strong>💡 <?php echo esc_html( $b['title'] ); ?></strong>
			— <?php echo esc_html( $b['body'] ); ?>
		</p>
		<?php if ( ! empty( $b['tip'] ) ) : ?>
			<p class="wto-page-help-tip">
				<strong><?php esc_html_e( 'نکته:', 'wto' ); ?></strong>
				<?php echo wp_kses_post( $b['tip'] ); ?>
			</p>
		<?php endif; ?>
	</div>
	<style>
	.wto-page-help-notice { border-right-color: #6366f1 !important; padding: 12px 18px !important; }
	.wto-page-help-notice p { margin: 4px 0; font-size: 13px; line-height: 1.8; }
	.wto-page-help-notice .wto-page-help-tip { color: #50575e; }
	</style>
	<?php
}
