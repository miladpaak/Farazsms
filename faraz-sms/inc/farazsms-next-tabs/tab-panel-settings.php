<?php
/**
 * Panel Settings Tab Content
 *
 * @var FarazSMS_Next_Admin_Page $this
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get saved settings from unified options
if (function_exists('wto_get_apikey')) {
    $api_key = wto_get_apikey();
} else {
    $api_key = get_option('wto_apikey', '');
}
$sender_number = get_option('wto_sender', '90008361');
?>

<form method="post" action="" class="fwss_form form-style-2">
    <?php wp_nonce_field('farazsms_next_settings', 'farazsms_next_settings_nonce'); ?>
    <input type="hidden" name="current_tab" value="panel-settings">
    
    <label for="panel_api_key">
        <span class="label"><?php _e('کلید دسترسی', 'farazsms-next'); ?><span class="required">*</span></span>
        <input type="text" 
               name="panel_api_key" 
               id="panel_api_key" 
               value="<?php echo esc_attr($api_key); ?>" 
               class="input-field"
               required>
    </label>
    <br><br>
    
    <label for="panel_sender_number">
        <span class="label"><?php _e('شماره فرستنده', 'farazsms-next'); ?><span class="required">*</span></span>
        <input type="text" 
               name="panel_sender_number" 
               id="panel_sender_number" 
               value="<?php echo esc_attr($sender_number); ?>" 
               class="input-field"
               required>
    </label>
    <br><br>
    
    <?php if (!empty($api_key)) : 
        $credit = $this->get_credit($api_key);
        if ($credit !== false) : ?>
            <div class="fwss-info-message">
                <p><?php _e('میزان اعتبار شما: ', 'farazsms-next'); ?><strong><?php echo esc_html($credit); ?> تومان</strong></p>
            </div>
            <br><br>
        <?php else : ?>
            <div class="fwss-error-message">
                <p><?php _e('خطا در دریافت موجودی. لطفا کلید دسترسی را بررسی کنید.', 'farazsms-next'); ?></p>
            </div>
            <?php
            // اگر علتِ خطا مسدود بودنِ درخواست‌ها باشد (نه کلیدِ نامعتبر)، اخطارِ روشن نشان بده.
            if ( function_exists( 'wto_connectivity_inline_warning_html' ) ) {
                echo wto_connectivity_inline_warning_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
            <br><br>
        <?php endif; ?>
    <?php endif; ?>
    
    <div class="fwss_save_button_container">
        <button type="submit" class="fwss_button" name="farazsms_next_settings_submit">
            <span class="button__text"><?php _e('ذخیره', 'farazsms-next'); ?></span>
        </button>
    </div>
</form>

