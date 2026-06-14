<?php
/**
 * Mobile Register Form Template
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<a href="<?php echo esc_url( wp_get_referer() ?: home_url() ); ?>" class="farazsms-back"><svg xmlns="http://www.w3.org/2000/svg" height="16" width="14" viewBox="0 0 448 512"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></svg></a>
<h3 class="farazsms-form-title"><?php _e('Register new user', 'farazsms'); ?></h3>
<?php $farazsms_ask_name = Farazsms_Login_Ask_Name(); ?>
<p class="farazsms-form-subtitle"><?php
    if ($farazsms_ask_name) {
        printf(__('User with number %s is not registered. <br /> For registration, please enter your name and the sent code.', 'farazsms'), esc_attr($identifier));
    } else {
        printf(__('User with number %s is not registered. <br /> For registration, please enter the sent code.', 'farazsms'), esc_attr($identifier));
    }
?></p>
<form method="post" id="submit-register" action="">
    <?php
    wp_nonce_field('farazsms_login_action', 'farazsms_nonce');
    $code_length = FarazSMS_Get_Setting('sms', 'code_length') ?: 4;
    ?>
    <?php if ($farazsms_ask_name): ?>
    <input type="text" name="full_name" placeholder="<?php _e('Your name', 'farazsms'); ?>" required>
    <?php endif; ?>
    <div class="farazsms-otp-inputs">
        <?php for ($i = 0; $i < $code_length; $i++): ?>
            <input type="text" name="otp[]" class="otp-digit" maxlength="1" data-index="<?php echo $i; ?>" inputmode="numeric" pattern="[0-9]*"<?php echo $i === 0 ? ' autocomplete="one-time-code"' : ' autocomplete="off"'; ?> required>
        <?php endfor; ?>
        <input type="hidden" name="verification_code" id="verification_code">
    </div>
    <div class="farazsms-countdown-timer flex align-items-center justify-content-center">
        <div id="farazsms-timer" data-time="2">02:00</div>
        <div class="farazsms-timer-text"> <?php _e('Time remaining until resend', 'farazsms'); ?> </div>
    </div>
    <input class="farazsms-submit" type="submit" name="submit_registration" value="<?php _e('Register', 'farazsms'); ?>">
    <input type="hidden" name="identifier_type" value="<?php echo esc_attr($identifier_type); ?>">
    <input type="hidden" name="identifier" value="<?php echo esc_attr($identifier); ?>">
    <input type="hidden" name="back_url" value="<?php echo esc_attr($back_url); ?>">
</form>