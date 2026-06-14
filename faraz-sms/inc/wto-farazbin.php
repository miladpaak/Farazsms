<?php
/**
 * فراز بین — موتور جستجوی قیمت — Phase 13 (v3.14.5)
 *
 * این فایل صفحه ادمین فراز بین را می‌سازد. فیچر هسته‌ای (meta tags + sitemap)
 * در inc/wto-product-meta-tags.php پیاده شده — اینجا فقط toggle + UI تبلیغاتی.
 *
 * option:
 *
 *   wto_farazbin_enabled   ('1' یا '0' — پیش‌فرض: '1')
 *
 * منوی این صفحه در گروه «باشگاه مشتریان و افزایش فروش» قرار می‌گیرد.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_action( 'admin_menu', 'wto_farazbin_register_menu', 28 );
function wto_farazbin_register_menu() {
	add_submenu_page(
		'farazwto',
		__( 'فراز بین', 'wto' ),
		__( 'فراز بین', 'wto' ),
		'manage_options',
		'farazwto-farazbin',
		'wto_farazbin_render_page'
	);
}

/**
 * Render صفحه تنظیمات فراز بین.
 */
function wto_farazbin_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'دسترسی غیرمجاز.' );
	}

	$saved_ok = false;
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && isset( $_POST['wto_farazbin_save'] ) ) {
		check_admin_referer( 'wto_farazbin_settings', 'wto_farazbin_nonce' );
		$enabled = isset( $_POST['wto_farazbin_enabled'] ) ? '1' : '0';
		update_option( 'wto_farazbin_enabled', $enabled, false );
		$saved_ok = true;
	}

	$enabled = get_option( 'wto_farazbin_enabled', '1' ) === '1';
	$wc_active = function_exists( 'WC' ) && function_exists( 'is_product' );
	$site_domain = preg_replace( '#^www\.#i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$sitemap_url = home_url( '/my-sitemap-products/' );
	?>
	<div style="direction:rtl; font-family:inherit; max-width:920px;">

		<?php if ( $saved_ok ) : ?>
			<div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:10px 14px; border-radius:8px; margin-bottom:16px;">✓ تنظیمات ذخیره شد.</div>
		<?php endif; ?>

		<!-- Hero تبلیغاتی -->
		<div style="background:linear-gradient(135deg,#7c3aed 0%,#4338ca 50%,#0ea5e9 100%); border-radius:14px; padding:24px 28px; margin-bottom:18px; color:#fff; position:relative; overflow:hidden;">
			<div style="position:absolute; top:-20px; left:-20px; font-size:120px; opacity:0.10;">🔍</div>
			<h2 style="margin:0 0 8px; font-size:22px; font-weight:800;">
				🚀 محصولاتت را روی موتور جستجوی قیمت اختصاصی فراز نمایش بده
			</h2>
			<p style="margin:0 0 12px; font-size:14px; line-height:1.9; opacity:0.97;">
				ما در حال راه‌اندازی یک پلتفرم مقایسه قیمت محصولات هستیم — مشابه ترب — که محصولات فروشگاه شما را به‌صورت <strong>کاملاً رایگان</strong> در دسترس <strong>هزاران خریدار بالقوه</strong> در سراسر کشور قرار می‌دهد.
			</p>
			<div style="background:rgba(255,255,255,0.15); padding:12px 16px; border-radius:10px; margin-top:10px;">
				🎁 <strong>پیشنهاد ویژه فراز اس‌ام‌اس:</strong>
				هدف اولیه ما کمک به رشد فروش فروشگاه‌های شماست،
				<strong>اعتبار شارژ رایگان</strong> به مشتریان پنل پیامکی فراز می‌دهیم تا کمپین تبلیغاتی محصولات‌شان را راه‌اندازی کنند.
			</div>
		</div>

		<?php if ( ! $wc_active ) : ?>
			<div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:14px 18px; border-radius:10px; margin-bottom:18px;">
				<strong>⚠️ ووکامرس فعال نیست</strong> — فراز بین به ووکامرس وابسته است (برای خواندن قیمت/موجودی محصولات). ابتدا WC را فعال کنید.
			</div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'wto_farazbin_settings', 'wto_farazbin_nonce' ); ?>

			<!-- Master toggle -->
			<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:22px; margin-bottom:18px;">
				<label style="display:flex; align-items:center; gap:14px; padding:14px 18px; background:<?php echo $enabled ? '#f0fdf4' : '#fff7ed'; ?>; border:1px solid <?php echo $enabled ? '#bbf7d0' : '#fed7aa'; ?>; border-radius:10px; cursor:pointer;">
					<input type="checkbox" class="wto-toggle" name="wto_farazbin_enabled" value="1" <?php checked( $enabled, true ); ?> style="margin:0; width:20px; height:20px;">
					<span style="flex:1; font-size:15px; font-weight:600;">
						نمایش محصولات این فروشگاه روی موتور جستجوی فراز بین
						<?php if ( $enabled ) : ?>
							<span style="display:inline-block; background:#16a34a; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px; font-weight:700;">فعال ✓</span>
						<?php else : ?>
							<span style="display:inline-block; background:#f97316; color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; margin-right:8px; font-weight:700;">غیرفعال</span>
						<?php endif; ?>
					</span>
				</label>
				<p style="margin:12px 0 0; font-size:12.5px; color:#475569; line-height:1.9;">
					در حالت <strong>فعال</strong>:
				</p>
				<ul style="margin:6px 0 0 18px; padding:0; font-size:12px; color:#475569; line-height:1.9;">
					<li>متاتگ‌های قیمت، موجودی و تصویر روی صفحه محصول شما درج می‌شود</li>
					<li>یک sitemap اختصاصی محصولات با اطلاعات کامل قیمت ساخته می‌شود</li>
					<li>فراز بین به‌صورت دوره‌ای محصولات شما را ایندکس می‌کند</li>
				</ul>
				<p style="margin:10px 0 0; font-size:12px; color:#64748b; line-height:1.9;">
					در حالت <strong>غیرفعال</strong>: متاتگ‌ها روی صفحات محصول منتشر نمی‌شوند و sitemap اختصاصی هم پاسخ ۴۰۴ می‌دهد —
					هیچ تأثیری روی SEO/سرعت سایت ندارد.
				</p>
			</div>

			<!-- اطلاعات فنی -->
			<?php if ( $enabled && $wc_active ) : ?>
				<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 22px; margin-bottom:18px;">
					<h4 style="margin:0 0 12px; font-size:13.5px; font-weight:700; color:#0f172a;">🔗 اطلاعات اتصال</h4>
					<table style="width:100%; border-collapse:collapse; font-size:12.5px;">
						<tr>
							<td style="padding:8px 0; color:#64748b; width:160px;">آدرس sitemap اختصاصی:</td>
							<td style="padding:8px 0; direction:ltr; text-align:left;">
								<code style="background:#f1f5f9; padding:3px 8px; border-radius:4px; font-family:monospace; font-size:12px;"><?php echo esc_html( $sitemap_url ); ?></code>
								<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" style="margin-right:8px; font-size:11px; color:#4338ca;">↗ بازکردن</a>
							</td>
						</tr>
						<tr>
							<td style="padding:8px 0; color:#64748b;">دامنه ثبت‌شده:</td>
							<td style="padding:8px 0; direction:ltr; text-align:left;">
								<code style="background:#f1f5f9; padding:3px 8px; border-radius:4px; font-family:monospace; font-size:12px;"><?php echo esc_html( $site_domain ); ?></code>
							</td>
						</tr>
						<tr>
							<td style="padding:8px 0; color:#64748b;">قابلیت‌های فعال:</td>
							<td style="padding:8px 0;">
								<span style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:4px;">✓ متاتگ قیمت</span>
								<span style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:4px;">✓ متاتگ موجودی</span>
								<span style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:4px;">✓ Sitemap با paging ۵۰۰‌تایی</span>
								<span style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:4px; font-size:11px;">✓ Cache ۶ ساعته</span>
							</td>
						</tr>
					</table>
				</div>
			<?php endif; ?>

			<!-- راهنمای ثبت‌نام -->
			<div style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%); border:1px solid #fcd34d; border-radius:12px; padding:16px 20px; margin-bottom:18px;">
				<h4 style="margin:0 0 8px; font-size:13.5px; font-weight:700; color:#78350f;">📝 برای ثبت فروشگاه در فراز بین</h4>
				<p style="margin:0; font-size:12.5px; color:#92400e; line-height:1.9;">
					۱) این گزینه را فعال نگه دارید (پیش‌فرض فعال است)<br>
					۲) از صفحه «تنظیمات افزونه» مطمئن شوید Api-Key فراز اس‌ام‌اس را وارد کرده‌اید<br>
					۳) برای دریافت اعتبار رایگان تبلیغات، با پشتیبانی فراز اس‌ام‌اس تماس بگیرید
				</p>
			</div>

			<!-- دکمه ذخیره -->
			<button type="submit" name="wto_farazbin_save" value="1" style="background:#4338ca; color:#fff; border:none; padding:11px 32px; font-size:14px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit;">
				💾 ذخیره تنظیمات
			</button>
		</form>
	</div>
	<?php
}
