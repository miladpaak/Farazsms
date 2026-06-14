<?php
/**
 * Mobile Login with Password Form Template
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<a href="<?php echo esc_url( wp_get_referer() ?: home_url() ); ?>" class="farazsms-back"><svg xmlns="http://www.w3.org/2000/svg" height="16" width="14" viewBox="0 0 448 512"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></svg></a>
<h3 class="farazsms-form-title"><?php _e('Enter your password.', 'farazsms'); ?></h3>
<form method="post" id="submit-login" action="">
    <?php wp_nonce_field('farazsms_login_action', 'farazsms_nonce'); ?>
    <input type="password" name="password" placeholder="<?php _e('Password', 'farazsms'); ?>" required>
    <button type="button" class="farazsms-change-link" id="login-with-code" name="submit_login"><?php _e('Login with one-time code', 'farazsms'); ?></button>
    <button type="button" class="farazsms-change-link" id="forget-password-mobile" name="forget_password_mobile"><?php _e('Forgot password', 'farazsms'); ?></button>
    <input class="farazsms-submit" type="submit" name="submit_identifier" value="<?php _e('Confirm', 'farazsms'); ?>">
    <input type="hidden" name="verification_code">
    <input type="hidden" name="identifier_type" value="<?php echo esc_attr($identifier_type); ?>">
    <input type="hidden" name="identifier" value="<?php echo esc_attr($identifier); ?>">
    <input type="hidden" name="back_url" value="<?php echo esc_attr($back_url); ?>">
</form>