<?php
/**
 * Gravity Forms Tab Content
 *
 * @var FarazSMS_Next_Admin_Page $this
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if Gravity Forms is installed
if (!class_exists('GFForms')) {
    ?>
    <div class="fwss-error-message">
        <p><?php _e('افزونه Gravity Forms نصب نیست. برای استفاده از این قابلیت ابتدا Gravity Forms را نصب کنید.', 'farazsms-next'); ?></p>
    </div>
    <?php
    return;
}
?>

<div class="notice notice-info" style="margin-top: 20px;">
    <p>
        <?php _e('برای مدیریت اطلاع‌رسانی پیامکی Gravity Forms، به منوی', 'farazsms-next'); ?>
        <strong><?php _e('Forms → اطلاع‌رسانی پیامکی', 'farazsms-next'); ?></strong>
        <?php _e('بروید.', 'farazsms-next'); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gf_farazsms')); ?>" class="button button-primary" style="margin-right: 10px;">
            <?php _e('رفتن به اطلاع‌رسانی پیامکی', 'farazsms-next'); ?>
        </a>
    </p>
</div>
