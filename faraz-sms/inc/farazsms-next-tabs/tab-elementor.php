<?php
/**
 * Elementor Tab Content
 *
 * @var FarazSMS_Next_Admin_Page $this
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if Elementor is installed
if (!did_action('elementor/loaded')) {
    ?>
    <div class="fwss-error-message">
        <p><?php _e('افزونه Elementor نصب نیست. برای استفاده از این قابلیت ابتدا Elementor را نصب کنید.', 'farazsms-next'); ?></p>
    </div>
    <?php
    return;
}
?>

<div class="notice notice-info" style="margin-top: 20px;">
    <p>
        <?php _e('برای مدیریت اطلاع‌رسانی پیامکی Elementor، به منوی', 'farazsms-next'); ?>
        <strong><?php _e('Elementor → اطلاع رسانی پیامک فراز', 'farazsms-next'); ?></strong>
        <?php _e('بروید.', 'farazsms-next'); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=elementor_farazsms')); ?>" class="button button-primary" style="margin-right: 10px;">
            <?php _e('رفتن به اطلاع‌رسانی پیامکی', 'farazsms-next'); ?>
        </a>
    </p>
</div>

