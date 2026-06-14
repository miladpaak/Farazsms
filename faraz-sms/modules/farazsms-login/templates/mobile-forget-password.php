<?php
/**
 * Mobile Forget Password Form Template
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<a href="<?php echo esc_url( wp_get_referer() ?: home_url() ); ?>" class="farazsms-back"><svg xmlns="http://www.w3.org/2000/svg" height="16" width="14" viewBox="0 0 448 512"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></svg></a>
<h3 class="farazsms-form-title"><?php _e('Password recovery', 'farazsms'); ?></h3>
<p class="farazsms-form-subtitle"><?php printf(__('Verification code has been sent to %s. Please enter the code.', 'farazsms'), esc_attr($identifier)); ?></p>
<form method="post" id="submit-forget-code" action="">
    <input class="text-center" type="text" placeholder="<?php _e('Enter verification code...', 'farazsms'); ?>" name="verification_code" required>
    <div class="farazsms-countdown-timer flex align-items-center justify-content-center">
        <div id="farazsms-timer" data-time="2">02:00</div>
        <div class="farazsms-timer-text"> <?php _e('Time remaining until resend', 'farazsms'); ?> </div>
    </div>
    <input class="farazsms-submit" type="submit" name="submit_forget_code" value="<?php _e('Confirm', 'farazsms'); ?>">
    <input type="hidden" name="identifier_type" value="<?php echo esc_attr($identifier_type); ?>">
    <input type="hidden" name="identifier" value="<?php echo esc_attr($identifier); ?>">
    <input type="hidden" name="back_url" value="<?php echo esc_attr($back_url); ?>">
</form>