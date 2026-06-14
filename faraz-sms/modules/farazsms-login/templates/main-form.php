<?php
/**
 * Main Login Form Template
 */
if (!defined('ABSPATH')) {
    exit;
}

$sms_terms_link = !empty(Farazsms_Get_Setting('general', 'terms_link')) ? Farazsms_Get_Setting('general', 'terms_link') : home_url();
$back_url = !empty($back_url) ? $back_url : (isset($_GET['back_url']) ? wp_unslash($_GET['back_url']) : '');
?>
<h3 class="farazsms-form-title"><?php _e('Login | Register', 'farazsms'); ?></h3>
<p class="farazsms-form-subtitle"><?php _e('Hello! <br /> Please enter your mobile number.', 'farazsms'); ?></p>
<form method="post" id="submit-identifier" action="">
    <?php wp_nonce_field('farazsms_login_action', 'farazsms_nonce'); ?>
    <input type="text" id="identifier" name="identifier" required>
    <p class="text-error"><?php _e('Please do not leave this field empty', 'farazsms'); ?></p>
    <input class="farazsms-submit" type="submit" name="submit_login" value="<?php _e('Login', 'farazsms'); ?>">
    <input type="hidden" name="back_url" value="<?php echo esc_attr($back_url); ?>">
</form>
<p class="farazsms-footer-text"><?php printf(__('Registration and login means acceptance of %s of this site.', 'farazsms'), '<a href="' . $sms_terms_link . '">' . __('Terms and Conditions', 'farazsms') . '</a>'); ?></p>
