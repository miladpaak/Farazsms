<?php
/**
 * Lead Magnet Tab Content
 *
 * @var FarazSMS_Next_Admin_Page $this
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get saved settings (defaults: enabled on)
$settings        = farazsms_next_get_lead_magnet_settings();
// در v3.13.11 پیش‌فرض «فعال» تضمین شد — اگر option وجود ندارد یا key مفقود است، '1' برمی‌گرداند.
$enabled         = isset($settings['enabled']) ? $settings['enabled'] : '1';
// v3.13.15: پیش‌فرض ۵۰,۰۰۰ تومان اعتبار + ۳ روز مهلت — تا کاربر بدون پیکربندی هم
// تجربه‌ای کارا داشته باشد. helper هم همین پیش‌فرض‌ها را برمی‌گرداند.
$credit_amount   = isset($settings['credit_amount']) && $settings['credit_amount'] !== '' ? $settings['credit_amount'] : 50000;
$expiry_days     = isset($settings['expiry_days']) && $settings['expiry_days'] !== '' ? $settings['expiry_days'] : 3;
$display_position = isset($settings['display_position']) ? $settings['display_position'] : 'bottom-right';
$display_location = isset($settings['display_location']) ? $settings['display_location'] : 'everywhere';
$display_pages    = array_filter(array_map('absint', explode(',', (string) (isset($settings['display_pages']) ? $settings['display_pages'] : ''))));
// v3.13.16: اگر کاربر صفحه‌ای انتخاب نکرده، my-account ووکامرس را به‌صورت
// پیش‌فرض پیشنهاد می‌دهیم (طبیعی‌ترین جای ثبت‌نام در فروشگاه).
$target_page_id  = isset($settings['target_page_id']) ? absint($settings['target_page_id']) : 0;
if ( $target_page_id === 0 && function_exists( 'wc_get_page_id' ) ) {
	$wc_myaccount_id = (int) wc_get_page_id( 'myaccount' );
	if ( $wc_myaccount_id > 0 ) {
		$target_page_id = $wc_myaccount_id;
	}
}
$shop_name       = isset($settings['shop_name']) && !empty($settings['shop_name']) ? $settings['shop_name'] : get_bloginfo('name');
?>

<form method="post" action="" class="fwss_form form-style-2">
        <?php wp_nonce_field('farazsms_next_settings', 'farazsms_next_settings_nonce'); ?>
        <input type="hidden" name="current_tab" value="lead-magnet">

        <!-- پیغام انگیزشی برای فعال نگه‌داشتن لید مگنت — v3.13.11 -->
        <div class="wto-lead-magnet-hero" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0f9ff 100%); border: 1px solid #a7f3d0; border-radius: 12px; padding: 18px 22px; margin-bottom: 20px; position: relative; overflow: hidden;">
            <div style="position: absolute; top: -20px; left: -20px; font-size: 90px; opacity: 0.08;">🎯</div>
            <h3 style="margin: 0 0 8px; font-size: 16px; color: #065f46; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                🚀 <?php esc_html_e( 'تا ۳ برابر بازدیدکننده‌های سایت‌تان را به مشتری تبدیل کنید', 'farazsms-next' ); ?>
            </h3>
            <p style="margin: 0 0 10px; color: #047857; font-size: 13px; line-height: 1.9;">
                <?php esc_html_e( 'لید مگنت یک پنجره‌ی ظریف در گوشه‌ی سایت است که به بازدیدکنندگان «اعتبار رایگان» در ازای شماره موبایل پیشنهاد می‌دهد. فروشگاه‌هایی که این قابلیت را فعال نگه می‌دارند، به‌طور میانگین:', 'farazsms-next' ); ?>
            </p>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin: 12px 0;">
                <div style="background: rgba(255,255,255,0.6); padding: 10px 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 20px; font-weight: 800; color: #065f46;">+۲۸٪</div>
                    <div style="font-size: 11px; color: #047857;"><?php esc_html_e( 'رشد ثبت‌نام', 'farazsms-next' ); ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.6); padding: 10px 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 20px; font-weight: 800; color: #065f46;">+۳۵٪</div>
                    <div style="font-size: 11px; color: #047857;"><?php esc_html_e( 'دیتابیس مشتری', 'farazsms-next' ); ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.6); padding: 10px 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 20px; font-weight: 800; color: #065f46;">×۲.۱</div>
                    <div style="font-size: 11px; color: #047857;"><?php esc_html_e( 'بازگشت مشتری', 'farazsms-next' ); ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.6); padding: 10px 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 20px; font-weight: 800; color: #065f46;">۰ ریال</div>
                    <div style="font-size: 11px; color: #047857;"><?php esc_html_e( 'هزینه راه‌اندازی', 'farazsms-next' ); ?></div>
                </div>
            </div>
            <p style="margin: 8px 0 0; color: #065f46; font-size: 12.5px; line-height: 1.8;">
                💡 <strong><?php esc_html_e( 'پیشنهاد می‌کنیم این قابلیت را فعال نگه دارید', 'farazsms-next' ); ?></strong>
                — <?php esc_html_e( 'اعتبار رایگانی که می‌دهید، فقط در همان فروشگاه شما قابل استفاده است. هزینه‌ای ندارد، اما بازدیدکننده را به یک «مشتری ثبت‌نام‌شده» تبدیل می‌کند که می‌توانید بعداً با پیامک، تخفیف و کمپین به او پیشنهاد بدهید.', 'farazsms-next' ); ?>
            </p>
        </div>

        <label for="lead_magnet_enabled" class="toggle-control" style="display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: <?php echo $enabled === '1' ? '#f0fdf4' : '#fff7ed'; ?>; border: 1px solid <?php echo $enabled === '1' ? '#bbf7d0' : '#fed7aa'; ?>; border-radius: 10px;">
            <span class="label" style="margin: 0; font-size: 14px; font-weight: 600; flex: 1;">
                <?php _e('فعال بودن نمایش لید مگنت روی صفحه اول سایت', 'farazsms-next'); ?>
                <?php if ( $enabled === '1' ) : ?>
                    <span style="display: inline-block; background: #16a34a; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 4px; margin-right: 8px; font-weight: 700;">فعال ✓</span>
                <?php else : ?>
                    <span style="display: inline-block; background: #f97316; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 4px; margin-right: 8px; font-weight: 700;">غیرفعال</span>
                <?php endif; ?>
            </span>
            <input type="checkbox"
                   name="lead_magnet_enabled"
                   id="lead_magnet_enabled"
                   value="1"
                   <?php checked($enabled, '1'); ?>>
            <span class="control"></span>
        </label>
        <br><br>

        <label>
            <span class="label"><?php _e('نام فروشگاه', 'farazsms-next'); ?></span>
            <input type="text" 
                   name="lead_magnet_shop_name" 
                   id="lead_magnet_shop_name"
                   class="input-field"
                   value="<?php echo esc_attr($shop_name); ?>"
                   placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
            <small class="description" style="display:block;margin-top:4px;">
                <?php _e('نام فروشگاه که در لید مگنت نمایش داده می‌شود.', 'farazsms-next'); ?>
            </small>
        </label>
        <br><br>

        <label>
            <span class="label"><?php _e('مقدار اعتبار عضویت (تومان)', 'farazsms-next'); ?><span class="required">*</span></span>
            <input type="number" 
                   name="lead_magnet_credit_amount" 
                   id="lead_magnet_credit_amount"
                   class="input-field"
                   value="<?php echo esc_attr($credit_amount); ?>"
                   placeholder="<?php _e('مثلاً 5000', 'farazsms-next'); ?>"
                   min="0"
                   step="1"
                   required>
        </label>
        <br><br>

        <label>
            <span class="label"><?php _e('مهلت استفاده (روز)', 'farazsms-next'); ?><span class="required">*</span></span>
            <input type="number" 
                   name="lead_magnet_expiry_days" 
                   id="lead_magnet_expiry_days"
                   class="input-field"
                   value="<?php echo esc_attr($expiry_days); ?>"
                   placeholder="<?php _e('مثلاً 3', 'farazsms-next'); ?>"
                   min="1"
                   step="1"
                   required>
        </label>
        <br><br>

        <!-- v3.13.16: layout fix — <label> تو در تو حذف شد (HTML نامعتبر و در RTL خراب می‌شد).
             حالا div container + label های مستقل + flexbox برای تراز صحیح. -->
        <div class="wto-lm-field" style="margin-bottom: 18px;">
            <div class="wto-lm-field-label" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 8px;">
                <?php _e('محل نمایش لید مگنت', 'farazsms-next'); ?>
            </div>
            <div style="display: flex; gap: 24px; flex-wrap: wrap;">
                <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: normal; cursor: pointer;">
                    <input type="radio"
                           name="lead_magnet_display_position"
                           value="bottom-right"
                           <?php checked($display_position, 'bottom-right'); ?>
                           style="margin: 0;">
                    <span><?php _e('پایین راست', 'farazsms-next'); ?></span>
                </label>
                <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: normal; cursor: pointer;">
                    <input type="radio"
                           name="lead_magnet_display_position"
                           value="bottom-left"
                           <?php checked($display_position, 'bottom-left'); ?>
                           style="margin: 0;">
                    <span><?php _e('پایین چپ', 'farazsms-next'); ?></span>
                </label>
            </div>
        </div>

        <!-- محل نمایش در سایت -->
        <div class="wto-lm-field" style="margin-bottom: 18px;">
            <div class="wto-lm-field-label" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 8px;">
                <?php _e('در کدام صفحات نمایش داده شود؟', 'farazsms-next'); ?>
            </div>
            <div style="display: flex; gap: 22px; flex-wrap: wrap;">
                <?php
                $loc_options = array(
                    'everywhere' => __('همه‌ی صفحات سایت (پیش‌فرض)', 'farazsms-next'),
                    'home'       => __('فقط صفحه اصلی', 'farazsms-next'),
                    'blog'       => __('بلاگ (نوشته‌ها و آرشیوها)', 'farazsms-next'),
                    'specific'   => __('فقط برگه‌های انتخاب‌شده', 'farazsms-next'),
                );
                foreach ($loc_options as $loc_val => $loc_label) :
                    ?>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: normal; cursor: pointer;">
                        <input type="radio"
                               name="lead_magnet_display_location"
                               value="<?php echo esc_attr($loc_val); ?>"
                               class="wto-lm-location-radio"
                               <?php checked($display_location, $loc_val); ?>
                               style="margin: 0;">
                        <span><?php echo esc_html($loc_label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div id="wto-lm-specific-pages" style="margin-top: 12px; <?php echo $display_location === 'specific' ? '' : 'display:none;'; ?>">
                <label for="lead_magnet_display_pages" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                    <?php _e('برگه‌ها را انتخاب کنید (چند انتخابی با Ctrl/Cmd):', 'farazsms-next'); ?>
                </label>
                <select name="lead_magnet_display_pages[]" id="lead_magnet_display_pages" multiple size="6" style="max-width: 380px; width: 100%; border-radius: 8px;">
                    <?php
                    $all_pages = get_pages(array('sort_column' => 'post_title', 'sort_order' => 'ASC'));
                    foreach ($all_pages as $p) {
                        printf(
                            '<option value="%d"%s>%s</option>',
                            absint($p->ID),
                            in_array((int) $p->ID, $display_pages, true) ? ' selected' : '',
                            esc_html($p->post_title)
                        );
                    }
                    ?>
                </select>
            </div>
            <p class="description" style="margin: 6px 0 0; font-size: 12px; color: #64748b;">
                <?php _e('پیش‌فرض: همه‌ی صفحات سایت. می‌توانید نمایش را به صفحه اصلی، بلاگ، یا برگه‌های خاص محدود کنید.', 'farazsms-next'); ?>
            </p>
        </div>
        <script>
        (function(){
            var radios = document.querySelectorAll('.wto-lm-location-radio');
            var box = document.getElementById('wto-lm-specific-pages');
            function sync(){
                var v = document.querySelector('.wto-lm-location-radio:checked');
                if (box) box.style.display = (v && v.value === 'specific') ? '' : 'none';
            }
            radios.forEach(function(r){ r.addEventListener('change', sync); });
        })();
        </script>

        <div class="wto-lm-field" style="margin-bottom: 18px;">
            <label for="lead_magnet_target_page_id" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 8px;">
                <?php _e('صفحه مقصد دکمه لید مگنت', 'farazsms-next'); ?>
            </label>
            <select name="lead_magnet_target_page_id" id="lead_magnet_target_page_id" class="select-field" style="max-width: 380px; width: 100%;">
                <option value="0"><?php _e('— انتخاب صفحه —', 'farazsms-next'); ?></option>
                <?php
                $pages = get_pages(array('sort_column' => 'post_title', 'sort_order' => 'ASC'));
                foreach ($pages as $page) {
                    printf(
                        '<option value="%d"%s>%s</option>',
                        absint($page->ID),
                        selected($target_page_id, $page->ID, false),
                        esc_html($page->post_title)
                    );
                }
                ?>
            </select>
            <p class="description" style="margin: 6px 0 0; font-size: 12px; color: #64748b;">
                <?php _e('در صورت انتخاب، با کلیک روی دکمه لید مگنت کاربر به این صفحه هدایت می‌شود. پیش‌فرض: صفحه «حساب کاربری» ووکامرس.', 'farazsms-next'); ?>
            </p>
        </div>
        
        <!-- v3.17.4: متن‌های قابل تنظیم — کاربر می‌تواند هر متنی که می‌خواهد بنویسد -->
        <div style="background:linear-gradient(135deg, #f0f9ff 0%, #ecfeff 100%); border:1.5px solid #67e8f9; border-radius:12px; padding:18px 22px; margin: 24px 0 18px; direction:rtl;">
            <h3 style="margin:0 0 12px; font-size:14.5px; color:#0e7490; font-weight:700; display:flex; align-items:center; gap:8px;">
                ✍️ <?php _e('شخصی‌سازی متن لید مگنت', 'farazsms-next'); ?>
            </h3>
            <p style="font-size:12.5px; color:#155e75; line-height:1.7; margin:0 0 14px;">
                <?php _e('هر متن که می‌خواهید بنویسید. متغیرهای قابل استفاده:', 'farazsms-next'); ?>
                <code style="background:#fff; padding:1px 7px; border-radius:4px; font-family:Menlo,Consolas,monospace; color:#7c3aed; direction:ltr; display:inline-block;">{amount}</code>
                <code style="background:#fff; padding:1px 7px; border-radius:4px; font-family:Menlo,Consolas,monospace; color:#7c3aed; direction:ltr; display:inline-block;">{shop}</code>
                <code style="background:#fff; padding:1px 7px; border-radius:4px; font-family:Menlo,Consolas,monospace; color:#7c3aed; direction:ltr; display:inline-block;">{days}</code>
            </p>

            <label style="display:block; margin-bottom:14px;">
                <span class="label" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:5px;">🔥 <?php _e('متن نشان (badge)', 'farazsms-next'); ?></span>
                <input type="text" name="lead_magnet_badge_text" class="input-field" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;"
                       value="<?php echo esc_attr( isset($settings['badge_text']) ? $settings['badge_text'] : '🔥 فقط امروز' ); ?>"
                       placeholder="🔥 فقط امروز">
            </label>

            <label style="display:block; margin-bottom:14px;">
                <span class="label" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:5px;">📣 <?php _e('عنوان اصلی', 'farazsms-next'); ?></span>
                <input type="text" name="lead_magnet_title_template" class="input-field" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;"
                       value="<?php echo esc_attr( isset($settings['title_template']) ? $settings['title_template'] : '{amount} تومان هدیه!' ); ?>"
                       placeholder="{amount} تومان هدیه!">
                <small style="display:block; margin-top:3px; color:#94a3b8; font-size:11px;"><?php _e('پیشنهاد: استفاده از {amount} برای رنگی شدن مقدار.', 'farazsms-next'); ?></small>
            </label>

            <label style="display:block; margin-bottom:14px;">
                <span class="label" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:5px;">💬 <?php _e('متن توضیح', 'farazsms-next'); ?></span>
                <textarea name="lead_magnet_headline_template" class="input-field" rows="2" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px; resize:vertical; line-height:1.8;"
                          placeholder="با عضویت در {shop}، اعتبار رایگان بگیر..."><?php echo esc_textarea( isset($settings['headline_template']) ? $settings['headline_template'] : 'با عضویت در {shop}، اعتبار رایگان بگیر و اولین خریدت رو ارزون‌تر کن.' ); ?></textarea>
            </label>

            <label style="display:block; margin-bottom:14px;">
                <span class="label" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:5px;">⏰ <?php _e('متن مهلت', 'farazsms-next'); ?></span>
                <input type="text" name="lead_magnet_disclaimer_template" class="input-field" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;"
                       value="<?php echo esc_attr( isset($settings['disclaimer_template']) ? $settings['disclaimer_template'] : '⏰ این هدیه فقط {days} روز اعتبار دارد' ); ?>"
                       placeholder="⏰ این هدیه فقط {days} روز اعتبار دارد">
            </label>

            <label style="display:block;">
                <span class="label" style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:5px;">🎁 <?php _e('متن دکمه', 'farazsms-next'); ?></span>
                <input type="text" name="lead_magnet_cta_text" class="input-field" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px;"
                       value="<?php echo esc_attr( isset($settings['cta_text']) ? $settings['cta_text'] : '🎁 دریافت اعتبار هدیه' ); ?>"
                       placeholder="🎁 دریافت اعتبار هدیه">
            </label>
        </div>

        <div class="fwss_save_button_container">
            <button type="submit" class="fwss_button" name="farazsms_next_settings_submit">
                <span class="button__text"><?php _e('ذخیره', 'farazsms-next'); ?></span>
            </button>
        </div>
    </form>

<?php
// بخشِ یادآورِ هدیه‌ی لید مگنت (پیامک به کاربرانی که خرید نکرده‌اند).
do_action( 'wto_lead_magnet_extra_sections' );
