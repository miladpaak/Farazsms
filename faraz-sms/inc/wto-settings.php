<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

add_action( 'admin_menu', 'wto_add_credentials_menu' );
function wto_add_credentials_menu() {
	add_menu_page(
		'فراز اس ام اس',
        'فراز اس ام اس',
		'manage_options',
		'farazwto',
		'wto_admin_settings_page2',
		WTO_CORE_IMG . 'logo.png'
	);

    add_submenu_page(
		'farazwto',
		'تنظیمات',
		'تنظیمات',
		'manage_options',
		'farazwto-settings',
		'wto_admin_settings_page'
	);

    add_submenu_page(
		'farazwto',
		'کد رهگیری',
		'کد رهگیری',
		'manage_options',
		'farazwto',
		'wto_admin_settings_page2'
	);

    add_submenu_page(
		'farazwto',
		'نظرسنجی',
		'نظرسنجی',
		'manage_options',
		'farazwto-poll',
		'wto_admin_poll_page'
	);

    add_submenu_page(
		'farazwto',
		'دیدگاه سایت',
		'دیدگاه سایت',
		'manage_options',
		'farazwto-comments',
		'wto_admin_comments_page'
	);

    add_submenu_page(
		'farazwto',
		'ورود و ثبت نام',
		'ورود و ثبت نام',
		'manage_options',
		'farazwto-login-register',
		'wto_redirect_to_farazsms_login_register'
	);

	// حذف لینک تکراری «فراز اس ام اس» از زیرمنو (وقتی اولین زیرمنو اسلاگ متفاوت دارد وردپرس آن را اضافه می‌کند)
	add_action( 'admin_menu', 'wto_remove_duplicate_parent_submenu', 999 );
}

/**
 * آدرس پیش‌فرض پنل ورود فراز اس‌ام‌اس (قابل تغییر با فیلتر wto_farazsms_portal_url)
 */
function wto_get_farazsms_portal_url() {
	return apply_filters( 'wto_farazsms_portal_url', 'https://sms.farazsms.com/' );
}

/**
 * ریدایرکت زودهنگام به پنل فراز اس‌ام‌اس وقتی روی «ورود و ثبت نام» کلیک شده (قبل از هر خروجی)
 */
// v3.13.20: redirect خارجی این صفحه حذف شد. حالا صفحه «ورود و ثبت‌نام» تنظیمات
// ماژول داخلی login را نشان می‌دهد (در فایل wto-login-module-bridge.php).
// تابع زیر برای backward compatibility نگه داشته شده ولی hook نشده است.
function wto_maybe_redirect_to_farazsms_login_register() {
	// no-op — handled by login module bridge
}

function wto_remove_duplicate_parent_submenu() {
	global $submenu;
	if ( empty( $submenu['farazwto'] ) || ! is_array( $submenu['farazwto'] ) ) {
		return;
	}
	// آیتم اول اگر همان اسلاگ والد (farazwto) با عنوان «فراز اس ام اس» باشد، حذفش کن
	$first = $submenu['farazwto'][0];
	// ساختار: [0]=عنوان منو، [1]=قابلیت، [2]=اسلاگ، [3]=عنوان نمایشی
	if ( isset( $first[2] ) && $first[2] === 'farazwto' && isset( $first[0] ) && $first[0] === 'فراز اس ام اس' ) {
		array_shift( $submenu['farazwto'] );
	}
}

/**
 * ریدایرکت به پنل ورود/ثبت‌نام فراز اس‌ام‌اس
 * از wp_redirect استفاده می‌شود چون wp_safe_redirect فقط به دامنه خود سایت اجازه می‌دهد.
 */
function wto_redirect_to_farazsms_login_register() {
	// v3.13.20: callback صفحه «ورود و ثبت‌نام» حالا توسط ماژول login bridge
	// override می‌شود (wto_login_module_render_settings_page). این تابع به‌عنوان
	// fallback نگه‌داشته شده — اگر فایل bridge بارگذاری نشد، حداقل پیام بدهد.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.', 'wto' ) );
	}
	if ( function_exists( 'wto_login_module_render_settings_page' ) ) {
		wto_login_module_render_settings_page();
		return;
	}
	echo '<div class="wrap"><h2>ورود و ثبت‌نام</h2><p>ماژول ورود/ثبت‌نام در دسترس نیست.</p></div>';
}

/**
 * نوتیس حساب کاربری رسمی فراز اس‌ام‌اس — در v3.13.9 به درخواست کاربر حذف شد
 * (UI شلوغ می‌شد). تابع را خالی نگه می‌داریم تا call siteهای موجود کرش نکنند.
 */
function wto_farazsms_panel_notice() {
	// intentionally empty
}

/**
 * صفحه تنظیمات: فقط کلید دسترسی و خط ارسال کننده
 */
function wto_admin_settings_page() {
	$apikey = get_option( 'wto_apikey', '' );
	$sender = get_option( 'wto_sender', '' );
	$show_credit_in_admin_bar = get_option( 'wto_show_credit_in_admin_bar', '1' );
	?>
	<section class="wrapper">
		<div id="wto_header">
			<div>
				<a href="https://farazsms.com" target="_blank">
					<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>"
						 alt="پنل ارسال اس ام اس – سامانه پیام کوتاه – سامانه ارسال پیامک">
				</a>
			</div>
			<?php
			if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) {
				$credit = wto_get_credit();
				?>
				<div id="wto_account_info">
					<div class="wto_credit_amount">
						<span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?>
						<span> تومان</span>
					</div>
					<?php wto_render_profile_block(); ?>
				</div>
				<?php
			}
			?>
		</div>

		<?php wto_farazsms_panel_notice(); ?>

		<ul class="tabs">
			<li class="active">تنظیمات پنل پیامکی</li>
		</ul>

		<ul class="tab__content">
			<li class="active">
				<div class="content__wrapper">
					<form id="wto_settings_credentials_form" class="wto_form form-style-2">
						<div class="container">
							<div class="row">
								<div class="col-6">
									<label for="wto_apikey_settings">
										<span class="label">کلید دسترسی<span class="required">*</span></span>
										<input type="text" class="input-field" id="wto_apikey_settings" name="wto_apikey"
											   value="<?php echo esc_attr( $apikey ); ?>">
									</label>
									<br><br>
									<label for="wto_sender_settings">
										<span class="label">خط ارسال کننده</span>
										<input type="text" class="input-field" id="wto_sender_settings" name="wto_sender"
											   value="<?php echo esc_attr( $sender ); ?>">
									</label>
									<br><br>
									<label for="wto_show_credit_in_admin_bar" style="display: flex; align-items: center; gap: 8px;">
										<input type="checkbox" class="wto-toggle" id="wto_show_credit_in_admin_bar" name="wto_show_credit_in_admin_bar" value="1" <?php checked( $show_credit_in_admin_bar, '1' ); ?>>
										<span class="label">نمایش اعتبار پنل در نوار بالای پیشخوان</span>
									</label>
								</div>
							</div>
						</div>
						<br><br>
						<div class="wto_save_button_container">
							<button type="submit" class="wto_button" id="wto_save_settings_button"><span class="button__text">ذخیره</span></button>
							<div id="wto-settings-response-message" style="display: none;"></div>
						</div>
					</form>
				</div>
			</li>
		</ul>

		<?php
		// action hook برای inject کردن بخش‌های اضافی توسط ماژول‌های دیگر.
		do_action( 'wto_settings_page_extra_sections' );
		?>
	</section>
	<?php
}

function wto_admin_settings_page2() {
	$apikey = get_option( 'wto_apikey', '' );

	// v3.14.10: تب افقی — settings (سه فرم پترن) | log (لیست ارسال‌های ثبت‌شده)
	$active_tab = isset( $_GET['tt'] ) ? sanitize_key( $_GET['tt'] ) : 'settings';
	if ( ! in_array( $active_tab, array( 'settings', 'log' ), true ) ) {
		$active_tab = 'settings';
	}

	// Multi-pattern: سه پترن جداگانه برای پست/تیپاکس/سایر.
	// Backward compatibility: اگر wto_pattern_post خالی است، از wto_pattern (legacy) استفاده کن.
	// v3.14.10: theme metadata (icon + color + slogan) برای ظاهر کارت‌محور.
	$patterns = array(
		'post'  => array(
			'label'        => 'کد رهگیری پست',
			'icon'         => '📮',
			'color'        => '#dc2626', // red-600
			'bg'           => '#fef2f2', // red-50
			'border'       => '#fecaca', // red-200
			'tagline'      => 'برای ارسال‌های پست عادی و پست پیشتاز — لینک رهگیری post.ir',
			'pattern_opt'  => 'wto_pattern_post',
			'message_opt'  => 'wto_message_post',
			'legacy_p'     => 'wto_pattern',
			'legacy_m'     => 'wto_message',
			'default_text' => "{customer_fullname} عزیز\nسفارش شماره {order_id} با پست ارسال شد.\nبا تشکر از اعتماد شما\n\nلینک رهگیری پستی\nhttps://tracking.post.ir/?id={tracking_code}",
		),
		'tipax' => array(
			'label'        => 'کد رهگیری تیپاکس',
			'icon'         => '🚚',
			'color'        => '#ea580c', // orange-600
			'bg'           => '#fff7ed', // orange-50
			'border'       => '#fed7aa', // orange-200
			'tagline'      => 'برای سفارش‌هایی که با تیپاکس ارسال می‌شوند',
			'pattern_opt'  => 'wto_pattern_tipax',
			'message_opt'  => 'wto_message_tipax',
			'legacy_p'     => '',
			'legacy_m'     => '',
			'default_text' => "{customer_fullname} عزیز\nسفارش شماره {order_id} با تیپاکس ارسال شد.\nکد رهگیری: {tracking_code}\nبا تشکر از اعتماد شما",
		),
		'other' => array(
			'label'        => 'سایر سرویس‌های ارسال',
			'icon'         => '📦',
			'color'        => '#475569', // slate-600
			'bg'           => '#f1f5f9', // slate-100
			'border'       => '#cbd5e1', // slate-300
			'tagline'      => 'پیک، باربری، چاپار، اسنپ‌باکس و هر سرویس دیگری',
			'pattern_opt'  => 'wto_pattern_other',
			'message_opt'  => 'wto_message_other',
			'legacy_p'     => '',
			'legacy_m'     => '',
			'default_text' => "{customer_fullname} عزیز\nسفارش شماره {order_id} ارسال شد.\nکد رهگیری: {tracking_code}\nبا تشکر از اعتماد شما",
		),
	);

	$tab_settings_url = admin_url( 'admin.php?page=farazwto&tt=settings' );
	$tab_log_url      = admin_url( 'admin.php?page=farazwto&tt=log' );
	?>

	<section class="wrapper">
		<div id="wto_header">
			<div>
				<a href="https://farazsms.com" target="_blank">
					<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>"
						 alt="پنل ارسال اس ام اس – سامانه پیام کوتاه – سامانه ارسال پیامک">
				</a>
			</div>
			<?php
			if ( ! empty( $apikey ) ) {
				$credit = wto_get_credit();
				?>
				<div id="wto_account_info">
					<div class="wto_credit_amount">
						<span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?>
						<span> تومان</span>
					</div>
					<?php wto_render_profile_block(); ?>
				</div>
				<?php
			}
			?>
		</div>

		<?php wto_farazsms_panel_notice(); ?>

		<!-- v3.14.10: تب افقی pill-style — settings | log -->
		<div style="display:flex; gap:8px; flex-wrap:wrap; margin:6px 0 18px; direction:rtl;">
			<a href="<?php echo esc_url( $tab_settings_url ); ?>" style="
				background:<?php echo $active_tab === 'settings' ? '#4338ca' : '#fff'; ?>;
				color:<?php echo $active_tab === 'settings' ? '#fff' : '#475569'; ?>;
				border:1px solid <?php echo $active_tab === 'settings' ? '#4338ca' : '#cbd5e1'; ?>;
				padding:9px 18px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;
				box-shadow:<?php echo $active_tab === 'settings' ? '0 4px 12px rgba(67,56,202,0.25)' : 'none'; ?>;">
				⚙️ تنظیمات پترن کد رهگیری
			</a>
			<a href="<?php echo esc_url( $tab_log_url ); ?>" style="
				background:<?php echo $active_tab === 'log' ? '#4338ca' : '#fff'; ?>;
				color:<?php echo $active_tab === 'log' ? '#fff' : '#475569'; ?>;
				border:1px solid <?php echo $active_tab === 'log' ? '#4338ca' : '#cbd5e1'; ?>;
				padding:9px 18px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;
				box-shadow:<?php echo $active_tab === 'log' ? '0 4px 12px rgba(67,56,202,0.25)' : 'none'; ?>;">
				📊 گزارش ارسال‌ها (تاریخچه)
			</a>
		</div>

		<?php if ( $active_tab === 'log' ) : ?>
			<?php if ( function_exists( 'wto_tracking_log_render_table' ) ) {
				wto_tracking_log_render_table();
			} else {
				echo '<div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:14px; border-radius:8px;">ماژول لاگ کد رهگیری در دسترس نیست. لطفاً ووکامرس را فعال کنید.</div>';
			} ?>
		<?php else : ?>

		<ul class="tab__content">
			<li class="active">
				<div class="content__wrapper">

					<!-- v3.14.10: کارت‌های visual انتخاب نوع پست — برای navigation سریع داخل صفحه -->
					<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:18px; direction:rtl;">
						<?php foreach ( $patterns as $carrier => $cfg ) : ?>
							<a href="#carrier-card-<?php echo esc_attr( $carrier ); ?>" style="
								background:<?php echo esc_attr( $cfg['bg'] ); ?>;
								border:1.5px solid <?php echo esc_attr( $cfg['border'] ); ?>;
								border-radius:12px; padding:16px; text-decoration:none;
								display:flex; align-items:center; gap:14px; transition:transform 0.15s;"
								onmouseover="this.style.transform='translateY(-2px)';"
								onmouseout="this.style.transform='translateY(0)';">
								<div style="font-size:36px; line-height:1;"><?php echo esc_html( $cfg['icon'] ); ?></div>
								<div style="flex:1;">
									<div style="color:<?php echo esc_attr( $cfg['color'] ); ?>; font-weight:700; font-size:14px; margin-bottom:3px;"><?php echo esc_html( $cfg['label'] ); ?></div>
									<div style="color:#64748b; font-size:11.5px; line-height:1.5;"><?php echo esc_html( $cfg['tagline'] ); ?></div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>

					<div class="wto-info-message" style="margin-bottom: 16px; background: #eef2ff; border: 1px solid #c7d2fe; padding: 12px 16px; border-radius: 8px; color: #3730a3; line-height: 1.8;">
						<strong>💡 سه پترن برای سه نوع پست:</strong>
						متن هر پترن را وارد کنید و دکمه «ساخت پترن» را بزنید. در صفحه سفارش ووکامرس، یک منوی کشویی برای انتخاب «نوع پست» قرار داده شده — بر اساس انتخاب ادمین، پترن مربوطه ارسال می‌شود.
					</div>

					<div class="wto-info-message digits_pattern_info" style="margin-bottom: 16px;">
						متغیرهای قابل استفاده با هر دو قالب %نام% و {نام}؛ مثال %customer_fullname%، %order_id%، %tracking_code%، %b_first_name%، %b_last_name% (متغیر tracking_code الزامی است).
					</div>

					<?php foreach ( $patterns as $carrier => $cfg ) :
						$current_pattern = get_option( $cfg['pattern_opt'], '' );
						$current_message = get_option( $cfg['message_opt'], '' );
						if ( $current_pattern === '' && $cfg['legacy_p'] !== '' ) {
							$current_pattern = get_option( $cfg['legacy_p'], '' );
						}
						if ( $current_message === '' && $cfg['legacy_m'] !== '' ) {
							$current_message = get_option( $cfg['legacy_m'], '' );
						}
						$display_text = $current_message !== '' ? $current_message : $cfg['default_text'];
						$has_pattern  = $current_pattern !== '';
						?>
						<form id="carrier-card-<?php echo esc_attr( $carrier ); ?>" class="wto_form form-style-2 wto-tracking-pattern-form" data-carrier="<?php echo esc_attr( $carrier ); ?>" style="margin-bottom: 24px; padding: 0; border: 1.5px solid <?php echo esc_attr( $cfg['border'] ); ?>; border-radius: 12px; background: #fff; overflow:hidden;">
							<!-- v3.14.10: هدر رنگی کارت با آیکن carrier -->
							<div style="background:<?php echo esc_attr( $cfg['bg'] ); ?>; padding:14px 20px; display:flex; align-items:center; gap:14px; border-bottom:1px solid <?php echo esc_attr( $cfg['border'] ); ?>;">
								<div style="font-size:34px; line-height:1;"><?php echo esc_html( $cfg['icon'] ); ?></div>
								<div style="flex:1;">
									<h3 style="margin:0 0 4px; font-size:15.5px; color:<?php echo esc_attr( $cfg['color'] ); ?>; font-weight:700;">
										<?php echo esc_html( $cfg['label'] ); ?>
									</h3>
									<div style="font-size:12px; color:#64748b; line-height:1.5;"><?php echo esc_html( $cfg['tagline'] ); ?></div>
								</div>
								<div>
									<?php if ( $has_pattern ) : ?>
										<span style="background:#dcfce7; color:#166534; padding:4px 11px; border-radius:14px; font-size:11.5px; font-weight:600;">✓ پترن ذخیره شده</span>
									<?php else : ?>
										<span style="background:#fef3c7; color:#92400e; padding:4px 11px; border-radius:14px; font-size:11.5px; font-weight:600;">⚠ هنوز پترن ندارد</span>
									<?php endif; ?>
								</div>
							</div>

							<div style="padding:18px 20px;">
								<label for="wto_message_<?php echo esc_attr( $carrier ); ?>">
									<span class="label">متن پیام</span>
									<textarea class="input-field wto-tracking-pattern-message" id="wto_message_<?php echo esc_attr( $carrier ); ?>" name="message" rows="6" data-carrier="<?php echo esc_attr( $carrier ); ?>" style="direction: rtl; white-space: pre-wrap; width: 100%;"><?php echo esc_textarea( $display_text ); ?></textarea>
								</label>

								<div style="margin-top: 10px;">
									<button type="button" class="wto_button wto-tracking-create-pattern-btn" data-carrier="<?php echo esc_attr( $carrier ); ?>">
										<span class="button__text">ساخت پترن</span>
									</button>
									<div class="wto-tracking-create-pattern-response" style="display: none; margin-top: 10px;"></div>
								</div>

								<br>

								<label for="wto_pattern_<?php echo esc_attr( $carrier ); ?>">
									<span class="label">کد پترن<span class="required">*</span></span>
									<input type="text" class="input-field wto-tracking-pattern-code" id="wto_pattern_<?php echo esc_attr( $carrier ); ?>" name="pattern" data-carrier="<?php echo esc_attr( $carrier ); ?>" value="<?php echo esc_attr( $current_pattern ); ?>">
								</label>

								<div class="wto_save_button_container" style="margin-top: 14px;">
									<button type="submit" class="wto_button wto-tracking-save-btn" data-carrier="<?php echo esc_attr( $carrier ); ?>">
										<span class="button__text">ذخیره این پترن</span>
									</button>
									<div class="wto-tracking-save-response" style="display: none; margin-top: 10px;"></div>
								</div>
							</div>
						</form>
					<?php endforeach; ?>

				</div>
			</li>
		</ul>
		<?php endif; ?>
	</section>

	<script type="text/javascript">
	jQuery(function($) {
		// ساخت پترن — برای هر carrier جداگانه
		$('.wto-tracking-create-pattern-btn').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var carrier = $btn.data('carrier');
			var $form = $btn.closest('form');
			var $msg = $form.find('.wto-tracking-pattern-message');
			var $code = $form.find('.wto-tracking-pattern-code');
			var $resp = $form.find('.wto-tracking-create-pattern-response');

			$btn.prop('disabled', true).find('.button__text').text('در حال ساخت...');
			$resp.hide().removeClass('is-success is-error');

			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'wto_create_pattern',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wto_create_pattern' ) ); ?>',
					message: $msg.val(),
					section_type: 'tracking',
					status_key: 'tracking',
					carrier: carrier
				}
			}).done(function(res) {
				if (res && res.success) {
					var data = res.data || {};
					if (data.pattern_code) {
						$code.val(data.pattern_code);
					}
					$resp.css({background:'#f0fdf4', color:'#15803d', padding:'8px 12px', border:'1px solid #bbf7d0', borderRadius:'6px'}).html('✓ ' + (data.message || 'پترن با موفقیت ساخته شد.')).show();
				} else {
					var emsg = (res && res.data) ? (typeof res.data === 'string' ? res.data : (res.data.message || 'خطای ناشناخته')) : 'خطای ناشناخته';
					$resp.css({background:'#fef2f2', color:'#b91c1c', padding:'8px 12px', border:'1px solid #fecaca', borderRadius:'6px'}).html('✗ ' + emsg).show();
				}
			}).fail(function() {
				$resp.css({background:'#fef2f2', color:'#b91c1c', padding:'8px 12px', border:'1px solid #fecaca', borderRadius:'6px'}).text('خطای ارتباط با سرور.').show();
			}).always(function() {
				$btn.prop('disabled', false).find('.button__text').text('ساخت پترن');
			});
		});

		// ذخیره کد پترن — برای هر carrier جداگانه
		$('.wto-tracking-pattern-form').on('submit', function(e) {
			e.preventDefault();
			var $form = $(this);
			var carrier = $form.data('carrier');
			var $btn = $form.find('.wto-tracking-save-btn');
			var $code = $form.find('.wto-tracking-pattern-code');
			var $msg = $form.find('.wto-tracking-pattern-message');
			var $resp = $form.find('.wto-tracking-save-response');

			$btn.prop('disabled', true).find('.button__text').text('در حال ذخیره...');
			$resp.hide().removeClass('is-success is-error');

			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'wto_save_credentials',
					form: 'tracking',
					carrier: carrier,
					pattern: $code.val(),
					message: $msg.val()
				}
			}).done(function(res) {
				if (res && res.success) {
					$resp.css({background:'#f0fdf4', color:'#15803d', padding:'8px 12px', border:'1px solid #bbf7d0', borderRadius:'6px'}).html('✓ تنظیمات این پترن ذخیره شد.').show();
				} else {
					var emsg = (res && res.data) ? (typeof res.data === 'string' ? res.data : (res.data.message || 'خطای ذخیره')) : 'خطای ذخیره';
					$resp.css({background:'#fef2f2', color:'#b91c1c', padding:'8px 12px', border:'1px solid #fecaca', borderRadius:'6px'}).html('✗ ' + emsg).show();
				}
			}).fail(function() {
				$resp.css({background:'#fef2f2', color:'#b91c1c', padding:'8px 12px', border:'1px solid #fecaca', borderRadius:'6px'}).text('خطای ارتباط با سرور.').show();
			}).always(function() {
				$btn.prop('disabled', false).find('.button__text').text('ذخیره این پترن');
			});
		});
	});
	</script>
	<?php
}

function wto_admin_poll_page() {
    // New tabbed survey UI lives in inc/wto-survey.php. Fall back to the legacy
    // form below only if that module isn't loaded for some reason.
    if ( function_exists( 'wto_render_survey_page' ) ) {
        wto_render_survey_page();
        return;
    }
    $poll_pattern = get_option('wto_poll_pattern', '');
    $send_poll_sms = get_option('wto_send_poll_sms', '0');
    $send_time = get_option('wto_send_time', '');
    $send_status = get_option('wto_send_status', '');
    $apikey = get_option('wto_apikey', '');
    ?>
    
    <section class="wrapper">
        <div id="wto_header">
            <div>
                <a href="https://farazsms.com" target="_blank">
                    <img src="<?php echo WTO_CORE_IMG . 'logo-1.png'; ?>"
                         alt="پنل ارسال اس ام اس – سامانه پیام کوتاه – سامانه ارسال پیامک">
                </a>
            </div>
            <?php
            if (!empty($apikey)) {
                $credit = wto_get_credit();
                ?>
                <div id="wto_account_info">
                    <div class="wto_credit_amount">
                        <span>میزان اعتبار: </span><?php echo esc_html($credit); ?>
                        <span> تومان</span>
                    </div>
                    <?php wto_render_profile_block(); ?>
                </div>
                <?php
            }
            ?>
        </div>
        
        <?php if (!get_option('wto_ticket_send')) { ?>
            <div class="wto_notice">
                <p>جهت فعالسازی افزونه کلید دسترسی وبسرویس خود را وارد کنید.</p>
            </div>
        <?php } ?>
        
        <ul class="tabs">
            <li class="active">تنظیمات نظرسنجی</li>
        </ul>
        
        <ul class="tab__content">
            <li class="active">
                <div class="content__wrapper">
                    <form id="wto_poll_settings_form" class="wto_form form-style-2">
                        <div class="container">
                            <div class="row">
                                <div class="col-6">
                 
                                    
                                    <label>
                                        میخوام پیامک نظر سنجی ارسال بشه
                                        <input type="checkbox" class="wto-toggle" id="sms_poll_checkbox" name="send_poll_sms" value="1" <?php echo ($send_poll_sms === '1') ? 'checked' : ''; ?> />
                                    </label>
                                    <br><br>
                                    
                                    <label for="wto_poll_pattern">
                                        <span class="label">پترن پیامک نظرسنجی(فقط %order_id%)<span class="required">*</span></span>
                                        <input type="text" class="input-field" id="wto_poll_pattern" name="wto_poll_pattern"
                                               value="<?php echo esc_attr($poll_pattern); ?>">
                                    </label>
                                    <br><br>
                                    
                                    <label style="display: block; margin-top: 8px;">
                                        <input type="number" id="send_time" name="send_time" min="1" 
                                               value="<?php echo esc_attr($send_time); ?>" 
                                               <?php echo ($send_poll_sms === '1') ? '' : 'disabled'; ?> 
                                               style="width: 60px; display: inline-block; vertical-align: middle;" />
                                        <span style="display: inline-block; vertical-align: middle; margin: 0 5px;">روز بعد از تغییر وضعیت سفارش به</span>
                                        <select id="send_status" name="send_status" 
                                                <?php echo ($send_poll_sms === '1') ? '' : 'disabled'; ?> 
                                                style="display: inline-block; vertical-align: middle;">
                                            <option value="">انتخاب وضعیت</option>
                                            <?php
                                            if (function_exists('wc_get_order_statuses')) {
                                                $order_statuses = wc_get_order_statuses();
                                                foreach ($order_statuses as $status_key => $status_label) {
                                                    $status_key_clean = str_replace('wc-', '', $status_key);
                                                    // Label is filterable by third parties — must be escaped before render.
                                                    echo '<option value="' . esc_attr( $status_key_clean ) . '" ' . selected( $send_status, $status_key_clean, false ) . '>' . esc_html( $status_label ) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <br><br>
                        
                        <div class="wto_save_button_container">
                            <button type="submit" class="wto_button" id="wto_save_poll_button">
                                <span class="button__text">ذخیره</span>
                            </button>
                            <div id="wto-poll-response-message" style="display: none;"></div>
                        </div>
                    </form>
                </div>
            </li>
        </ul>
    </section>
    <?php
}

/**
 * صفحه تنظیمات دیدگاه سایت (پیامک دیدگاه)
 */
function wto_admin_comments_page() {
	$apikey                       = get_option( 'wto_apikey', '' );
	$comment_admin_phones         = get_option( 'wto_comment_admin_phones', '' );
	$comment_admin_pattern        = get_option( 'wto_comment_admin_pattern', '' );
	$comment_admin_message        = get_option( 'wto_comment_admin_message', '' );
	$comment_user_approve_pattern = get_option( 'wto_comment_user_approve_pattern', '' );
	$comment_user_approve_message = get_option( 'wto_comment_user_approve_message', '' );
	$comment_user_reply_pattern   = get_option( 'wto_comment_user_reply_pattern', '' );
	$comment_user_reply_message   = get_option( 'wto_comment_user_reply_message', '' );
	$site_url                     = preg_replace( '#^https?://#', '', get_bloginfo( 'url' ) );

	// v3.14.10: redesign visual cards برای ۳ نوع اطلاع‌رسانی.
	// هر کارت theme جدا، header رنگی، badge وضعیت، variable chips.
	$blocks = array(
		array(
			'key'           => 'admin',
			'title'         => 'اطلاع‌رسانی به مدیر هنگام ثبت دیدگاه',
			'tagline'       => 'هر بار که کسی دیدگاه ثبت کند، یک پیامک به مدیر سایت ارسال می‌شود.',
			'icon'          => '🔔',
			'color'         => '#4338ca', // indigo-700
			'bg'            => '#eef2ff', // indigo-50
			'border'        => '#c7d2fe', // indigo-200
			'vars'          => array( '%comment_author%', '%comment_author_email%' ),
			'msg_id'        => 'wto_comment_admin_message',
			'msg_name'      => 'wto_comment_admin_message',
			'msg_status'    => 'admin',
			'pat_id'        => 'wto_comment_admin_pattern',
			'pat_name'      => 'wto_comment_admin_pattern',
			'pat_value'     => $comment_admin_pattern,
			'msg_value'     => $comment_admin_message ? $comment_admin_message : "دیدگاه جدید از طرف %comment_author% با ایمیل %comment_author_email% ثبت شد.\n" . $site_url,
			'extra_field'   => 'admin_phones',
		),
		array(
			'key'           => 'approve',
			'title'         => 'اطلاع‌رسانی به کاربر هنگام تایید یا رد دیدگاه',
			'tagline'       => 'وقتی دیدگاه کاربر تایید یا رد می‌شود، با پیامک به او اطلاع داده شود.',
			'icon'          => '✅',
			'color'         => '#15803d', // green-700
			'bg'            => '#f0fdf4', // green-50
			'border'        => '#bbf7d0', // green-200
			'vars'          => array( '%comment_author%', '%comment_link%', '%status%' ),
			'msg_id'        => 'wto_comment_user_approve_message',
			'msg_name'      => 'wto_comment_user_approve_message',
			'msg_status'    => 'user_approve',
			'pat_id'        => 'wto_comment_user_approve_pattern',
			'pat_name'      => 'wto_comment_user_approve_pattern',
			'pat_value'     => $comment_user_approve_pattern,
			'msg_value'     => $comment_user_approve_message ? $comment_user_approve_message : "دیدگاه شما %status% شد.\nلینک: %comment_link%\n" . $site_url,
			'extra_field'   => '',
		),
		array(
			'key'           => 'reply',
			'title'         => 'اطلاع‌رسانی به کاربر هنگام پاسخ به دیدگاه',
			'tagline'       => 'وقتی کسی به دیدگاه کاربر پاسخ دهد، پیامک برای کاربر ارسال می‌شود.',
			'icon'          => '💬',
			'color'         => '#0369a1', // sky-700
			'bg'            => '#f0f9ff', // sky-50
			'border'        => '#bae6fd', // sky-200
			'vars'          => array( '%comment_author%', '%comment_link%' ),
			'msg_id'        => 'wto_comment_user_reply_message',
			'msg_name'      => 'wto_comment_user_reply_message',
			'msg_status'    => 'user_reply',
			'pat_id'        => 'wto_comment_user_reply_pattern',
			'pat_name'      => 'wto_comment_user_reply_pattern',
			'pat_value'     => $comment_user_reply_pattern,
			'msg_value'     => $comment_user_reply_message ? $comment_user_reply_message : "به دیدگاه شما پاسخ داده شد.\nلینک: %comment_link%\n" . $site_url,
			'extra_field'   => '',
		),
	);

	// خلاصه آماری ساده — چند تا از سه پترن ذخیره شده‌اند؟
	$saved_count = 0;
	foreach ( $blocks as $b ) {
		if ( $b['pat_value'] !== '' ) {
			$saved_count++;
		}
	}
	$has_admin_phone = $comment_admin_phones !== '';
	?>
	<section class="wrapper">
		<div id="wto_header">
			<div>
				<a href="https://farazsms.com" target="_blank">
					<img src="<?php echo esc_url( WTO_CORE_IMG . 'logo-1.png' ); ?>" alt="پنل ارسال اس ام اس">
				</a>
			</div>
			<?php
			if ( ! empty( $apikey ) && function_exists( 'wto_get_credit' ) ) {
				$credit = wto_get_credit();
				?>
				<div id="wto_account_info">
					<div class="wto_credit_amount">
						<span>میزان اعتبار: </span><?php echo esc_html( $credit ); ?>
						<span> تومان</span>
					</div>
					<?php wto_render_profile_block(); ?>
				</div>
				<?php
			}
			?>
		</div>
		<?php wto_farazsms_panel_notice(); ?>

		<!-- v3.14.10: hero header — موقعیت‌نمای visual و وضعیت کلی -->
		<div style="background:linear-gradient(135deg,#4338ca 0%,#6366f1 100%); color:#fff; border-radius:14px; padding:24px 28px; margin:6px 0 18px; direction:rtl; box-shadow:0 8px 24px rgba(67,56,202,0.18);">
			<div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
				<div style="font-size:48px; line-height:1;">💬</div>
				<div style="flex:1; min-width:240px;">
					<h2 style="margin:0 0 6px; font-size:20px; font-weight:700;">اطلاع‌رسانی پیامکی دیدگاه‌ها</h2>
					<div style="font-size:13px; opacity:0.92; line-height:1.7;">سه نوع پیامک خودکار: اطلاع به مدیر هنگام ثبت دیدگاه، اطلاع به کاربر هنگام تایید/رد، و اطلاع به کاربر هنگام دریافت پاسخ.</div>
				</div>
				<div style="display:flex; gap:10px; flex-wrap:wrap;">
					<div style="background:rgba(255,255,255,0.18); backdrop-filter:blur(4px); padding:10px 18px; border-radius:10px; text-align:center; min-width:110px;">
						<div style="font-size:22px; font-weight:700;"><?php echo (int) $saved_count; ?>/3</div>
						<div style="font-size:11px; opacity:0.9;">پترن ذخیره شده</div>
					</div>
					<div style="background:rgba(255,255,255,0.18); backdrop-filter:blur(4px); padding:10px 18px; border-radius:10px; text-align:center; min-width:110px;">
						<div style="font-size:22px; font-weight:700;"><?php echo $has_admin_phone ? '✓' : '✗'; ?></div>
						<div style="font-size:11px; opacity:0.9;">شماره مدیر</div>
					</div>
				</div>
			</div>
		</div>

		<!-- v3.17.3: تنظیمات فرم دیدگاه (شماره موبایل + ایمیل) -->
		<?php $cfs = function_exists( 'wto_comment_form_settings' ) ? wto_comment_form_settings() : array(
			'phone_enabled' => '1', 'phone_required' => '0', 'hide_email' => '1',
			'fake_email_domain' => '', 'remember_user_phone' => '1',
		); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="direction:rtl; margin-bottom:18px;">
			<input type="hidden" name="action" value="wto_save_comment_form_settings">
			<?php wp_nonce_field( 'wto_save_comment_form_settings' ); ?>
			<div style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:18px 22px;">
				<h3 style="margin:0 0 14px; font-size:14.5px; color:#0f172a; font-weight:700; display:flex; align-items:center; gap:8px;">
					⚙️ تنظیمات فیلدهای فرم دیدگاه سایت
				</h3>
				<p style="font-size:12.5px; color:#64748b; line-height:1.7; margin:0 0 14px;">
					کنترل دقیق این که چه فیلدهایی در فرم دیدگاه سایت ظاهر شوند. پیش‌فرض: شماره موبایل اختیاری، فیلد ایمیل پنهان (با تولید خودکار ایمیل از شماره موبایل).
				</p>
				<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
					<label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
						<input type="checkbox" class="wto-toggle" name="phone_enabled" value="1" <?php checked( $cfs['phone_enabled'], '1' ); ?> style="margin-top:3px;">
						<div>
							<div style="font-weight:600; font-size:13px; color:#0f172a;">📱 نمایش فیلد شماره موبایل</div>
							<div style="font-size:11.5px; color:#64748b; margin-top:2px;">بازدیدکننده می‌تواند شماره خود را برای دریافت اطلاع از پاسخ ثبت کند</div>
						</div>
					</label>
					<label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
						<input type="checkbox" class="wto-toggle" name="phone_required" value="1" <?php checked( $cfs['phone_required'], '1' ); ?> style="margin-top:3px;">
						<div>
							<div style="font-weight:600; font-size:13px; color:#0f172a;">⚠ اجباری بودن شماره موبایل</div>
							<div style="font-size:11.5px; color:#64748b; margin-top:2px;">بدون شماره موبایل، دیدگاه ثبت نمی‌شود</div>
						</div>
					</label>
					<label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
						<input type="checkbox" class="wto-toggle" name="hide_email" value="1" <?php checked( $cfs['hide_email'], '1' ); ?> style="margin-top:3px;">
						<div>
							<div style="font-weight:600; font-size:13px; color:#0f172a;">✉ پنهان کردن فیلد ایمیل</div>
							<div style="font-size:11.5px; color:#64748b; margin-top:2px;">ایمیل خودکار از شماره موبایل ساخته می‌شود (mobile@domain)</div>
						</div>
					</label>
					<label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
						<input type="checkbox" class="wto-toggle" name="remember_user_phone" value="1" <?php checked( $cfs['remember_user_phone'], '1' ); ?> style="margin-top:3px;">
						<div>
							<div style="font-weight:600; font-size:13px; color:#0f172a;">💾 یادآوری برای کاربر لاگین</div>
							<div style="font-size:11.5px; color:#64748b; margin-top:2px;">یک‌بار شماره گرفته شد، در پروفایل کاربر ذخیره و دفعات بعد پرسیده نمی‌شود</div>
						</div>
					</label>
				</div>
				<div style="margin-top:14px; padding-top:14px; border-top:1px solid #f1f5f9;">
					<label style="display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#1f2937;">دامنه ایمیل ساختگی (اختیاری)</label>
					<input type="text" name="fake_email_domain" value="<?php echo esc_attr( $cfs['fake_email_domain'] ); ?>" placeholder="مثال: mydomain.com (خالی = خودکار از سایت)" style="width:100%; max-width:360px; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; direction:ltr; font-family:Menlo,Consolas,monospace; font-size:13px;">
					<small style="display:block; margin-top:4px; color:#94a3b8; font-size:11.5px;">اگر خالی باشد، دامنه‌ی سایت شما به‌صورت خودکار استفاده می‌شود.</small>
				</div>
				<div style="margin-top:14px; display:flex; justify-content:flex-end;">
					<button type="submit" style="background:#4338ca; color:#fff; border:none; padding:9px 22px; border-radius:7px; font-size:12.5px; font-weight:700; cursor:pointer;">💾 ذخیره تنظیمات فرم</button>
				</div>
			</div>
		</form>

		<!-- راهنمای متغیرها — یک نوار اطلاع‌رسانی -->
		<div style="background:#fefce8; border:1px solid #fde68a; border-radius:10px; padding:12px 16px; margin-bottom:18px; color:#854d0e; font-size:12.5px; line-height:1.8; direction:rtl;">
			<strong style="color:#713f12;">💡 راهنما:</strong>
			هر بلوک پترن جداگانه خود را دارد. ابتدا متن پیام را در textarea وارد کنید، روی «ساخت پترن» بزنید تا کد پترن دریافت شود، سپس روی «ذخیره همه تنظیمات» در پایین صفحه کلیک کنید.
		</div>

		<form id="wto_comments_settings_form" class="wto_form form-style-2" style="margin:0;">
			<?php foreach ( $blocks as $b ) :
				$has_pattern = $b['pat_value'] !== '';
				?>
				<!-- کارت بلوک — هر کدام theme رنگی متفاوت -->
				<div style="background:#fff; border:1.5px solid <?php echo esc_attr( $b['border'] ); ?>; border-radius:12px; margin-bottom:18px; overflow:hidden; direction:rtl;">
					<!-- هدر رنگی کارت -->
					<div style="background:<?php echo esc_attr( $b['bg'] ); ?>; padding:14px 20px; display:flex; align-items:center; gap:14px; border-bottom:1px solid <?php echo esc_attr( $b['border'] ); ?>;">
						<div style="font-size:32px; line-height:1;"><?php echo esc_html( $b['icon'] ); ?></div>
						<div style="flex:1;">
							<h3 style="margin:0 0 3px; font-size:15px; color:<?php echo esc_attr( $b['color'] ); ?>; font-weight:700;">
								<?php echo esc_html( $b['title'] ); ?>
							</h3>
							<div style="font-size:12px; color:#64748b; line-height:1.5;"><?php echo esc_html( $b['tagline'] ); ?></div>
						</div>
						<div>
							<?php if ( $has_pattern ) : ?>
								<span style="background:#dcfce7; color:#166534; padding:4px 11px; border-radius:14px; font-size:11.5px; font-weight:600; white-space:nowrap;">✓ پترن ذخیره شده</span>
							<?php else : ?>
								<span style="background:#fef3c7; color:#92400e; padding:4px 11px; border-radius:14px; font-size:11.5px; font-weight:600; white-space:nowrap;">⚠ هنوز پترن ندارد</span>
							<?php endif; ?>
						</div>
					</div>

					<div style="padding:18px 20px;">
						<!-- متغیرهای قابل استفاده — chip ها -->
						<div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; align-items:center;">
							<span style="font-size:12px; color:#475569; font-weight:600;">متغیرها:</span>
							<?php foreach ( $b['vars'] as $v ) : ?>
								<code style="background:<?php echo esc_attr( $b['bg'] ); ?>; color:<?php echo esc_attr( $b['color'] ); ?>; border:1px solid <?php echo esc_attr( $b['border'] ); ?>; padding:2px 9px; border-radius:5px; font-size:11.5px; direction:ltr; display:inline-block; font-family:Menlo,Consolas,monospace;"><?php echo esc_html( $v ); ?></code>
							<?php endforeach; ?>
						</div>

						<?php if ( $b['extra_field'] === 'admin_phones' ) : ?>
							<label for="wto_comment_admin_phones" style="display:block; margin-bottom:14px;">
								<span class="label" style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a; font-size:13px;">📱 شماره موبایل مدیر <span style="color:#94a3b8; font-weight:400;">(چند شماره را با کاما (,) جدا کنید)</span></span>
								<input type="text" class="input-field" id="wto_comment_admin_phones" name="wto_comment_admin_phones" value="<?php echo esc_attr( $comment_admin_phones ); ?>" placeholder="09120000000, 09120000001" style="direction:ltr; width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:Menlo,Consolas,monospace;">
							</label>
						<?php endif; ?>

						<label for="<?php echo esc_attr( $b['msg_id'] ); ?>" style="display:block; margin-bottom:10px;">
							<span class="label" style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a; font-size:13px;">✍️ متن پیام پیش‌فرض</span>
							<textarea class="input-field wto-comment-message" id="<?php echo esc_attr( $b['msg_id'] ); ?>" name="<?php echo esc_attr( $b['msg_name'] ); ?>" rows="5" style="direction:rtl; white-space:pre-wrap; width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-family:inherit; font-size:13px; line-height:1.9; resize:vertical;" data-section_type="comment" data-status_key="<?php echo esc_attr( $b['msg_status'] ); ?>"><?php echo esc_textarea( $b['msg_value'] ); ?></textarea>
						</label>

						<div style="display:flex; gap:10px; align-items:center; margin-bottom:14px; flex-wrap:wrap;">
							<button type="button" class="wto_button wto-comment-create-pattern" data-target_pattern="<?php echo esc_attr( $b['pat_id'] ); ?>" data-target_message="<?php echo esc_attr( $b['msg_id'] ); ?>" style="background:<?php echo esc_attr( $b['color'] ); ?>; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:12.5px; font-weight:600; cursor:pointer;">
								<span class="button__text">⚡ ساخت پترن</span>
							</button>
							<div class="wto-create-pattern-response wto-comment-pattern-response" style="display:none; flex:1;"></div>
						</div>

						<label for="<?php echo esc_attr( $b['pat_id'] ); ?>" style="display:block;">
							<span class="label" style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a; font-size:13px;">🔑 کد پترن <span style="color:#94a3b8; font-weight:400;">(بعد از تأیید توسط فراز اس‌ام‌اس)</span></span>
							<input type="text" class="input-field" id="<?php echo esc_attr( $b['pat_id'] ); ?>" name="<?php echo esc_attr( $b['pat_name'] ); ?>" value="<?php echo esc_attr( $b['pat_value'] ); ?>" placeholder="کد پترن به‌صورت خودکار پر می‌شود" style="direction:ltr; width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:Menlo,Consolas,monospace;">
						</label>
					</div>
				</div>
			<?php endforeach; ?>

			<!-- دکمه ذخیره استیکی پایین -->
			<div class="wto_save_button_container" style="background:#fff; border:1.5px solid #c7d2fe; border-radius:12px; padding:16px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; direction:rtl;">
				<div style="flex:1; min-width:200px;">
					<div style="color:#0f172a; font-weight:700; font-size:14px; margin-bottom:3px;">💾 ذخیره همه تنظیمات</div>
					<div style="color:#64748b; font-size:12px;">تمام پترن‌ها و شماره مدیر در یک کلیک ذخیره می‌شوند.</div>
				</div>
				<button type="submit" class="wto_button" id="wto_save_comments_button" style="background:#4338ca; color:#fff; border:none; padding:10px 28px; border-radius:8px; font-size:13.5px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(67,56,202,0.25);">
					<span class="button__text">ذخیره</span>
				</button>
				<div id="wto-comments-response-message" style="display:none; flex-basis:100%;"></div>
			</div>
		</form>
	</section>
	<?php
}

