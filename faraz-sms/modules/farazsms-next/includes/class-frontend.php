<?php
/**
 * Frontend Class
 *
 * Handles frontend display of lead magnet box
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Frontend {

    /**
     * Constructor
     */
    public function __construct() {
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Render lead magnet box in footer
        add_action('wp_footer', array($this, 'render_lead_magnet_box'));
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        // محلِ نمایش بر اساسِ تنظیمِ کاربر (همه‌جا/صفحه اصلی/بلاگ/برگه‌های خاص)
        if (!function_exists('farazsms_next_lead_magnet_should_display') || !farazsms_next_lead_magnet_should_display()) {
            return;
        }

        $settings = farazsms_next_get_lead_magnet_settings();

        // Enqueue CSS
        wp_enqueue_style(
            'farazsms-next-lead-magnet',
            WTO_CORE_CSS . 'lead-magnet.css',
            array(),
            FARAZSMS_NEXT_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'farazsms-next-lead-magnet',
            WTO_CORE_JS . 'lead-magnet.js',
            array('jquery'),
            FARAZSMS_NEXT_VERSION,
            true
        );

        // Prepare redirect URL for CTA button
        // v3.13.16: chain اولویت‌بندی:
        //   1) صفحه‌ای که کاربر در تنظیمات انتخاب کرده (target_page_id)
        //   2) صفحه my-account ووکامرس (پیش‌فرض جدید — جای ثبت‌نام طبیعی برای فروشگاه)
        //   3) صفحه ورود وردپرس به‌عنوان fallback
        $redirect_url = '';
        if (!empty($settings['target_page_id'])) {
            $target_page_id = absint($settings['target_page_id']);
            $page_link      = get_permalink($target_page_id);
            if ($page_link) {
                $redirect_url = $page_link;
            }
        }

        // Fallback ۲: my-account ووکامرس (اگر ووکامرس فعال است)
        if (empty($redirect_url) && function_exists('wc_get_page_id')) {
            $myaccount_id = (int) wc_get_page_id('myaccount');
            if ($myaccount_id > 0) {
                $myaccount_link = get_permalink($myaccount_id);
                if ($myaccount_link) {
                    $redirect_url = $myaccount_link;
                }
            }
        }

        if (empty($redirect_url)) {
            // Fallback ۳: login/register page
            $redirect_url = wp_login_url(home_url());
        }

        // مبلغ/انقضای هدیه از تنظیماتِ خودِ لید مگنت خوانده می‌شود — همین مقدار هنگام
        // ثبت‌نام به کیف‌پول اضافه می‌شود (wto_wallet_registration_gift_config هم از همین
        // تنظیمات می‌خواند)، پس آنچه پاپ‌آپ تبلیغ می‌کند دقیقاً همان اعتباری است که تحویل می‌شود.
        // Localize script with settings
        wp_localize_script('farazsms-next-lead-magnet', 'farazsmsLeadMagnet', array(
            'countdownSeconds' => 60,
            'creditAmount'     => isset($settings['credit_amount']) ? $settings['credit_amount'] : '',
            'expiryDays'       => isset($settings['expiry_days']) ? $settings['expiry_days'] : '',
            'displayPosition'  => isset($settings['display_position']) ? $settings['display_position'] : 'bottom-right',
            'redirectUrl'      => $redirect_url,
        ));
    }

    /**
     * Render lead magnet box
     */
    public function render_lead_magnet_box() {
        // محلِ نمایش بر اساسِ تنظیمِ کاربر (همه‌جا/صفحه اصلی/بلاگ/برگه‌های خاص)
        if (!function_exists('farazsms_next_lead_magnet_should_display') || !farazsms_next_lead_magnet_should_display()) {
            return;
        }

        $settings = farazsms_next_get_lead_magnet_settings();

        // Load template
        $template_path = FARAZSMS_NEXT_PLUGIN_DIR . 'includes/lead-magnet-box.php';
        if (file_exists($template_path)) {
            include $template_path;
        }
    }
}

