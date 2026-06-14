<?php
/**
 * Template Name: FarazSMS Login Page
 * Description: A clean login page template for FarazSMS plugin
 * Template: farazsms-login-page
 * Template Post Type: page
 */

if (!defined('ABSPATH')) {
    exit;
}

include FarazSMS_PATH . 'page-templates/header-farazsms-login.php';
$theme = FarazSMS_Get_Setting('appearance', 'theme');
$logo = FarazSMS_Get_Setting('appearance', 'logo');
// پیش‌فرض/فالبک: اگر لوگو خالی است یا هنوز لوگوی قدیمیِ فراز اس‌ام‌اس را دارد،
// از نمادکِ سایت (favicon) در تنظیمات عمومی وردپرس استفاده کن.
if (empty($logo) || strpos($logo, 'farazsms-login/assets/images/farazsms.png') !== false) {
    $logo = function_exists('get_site_icon_url') ? get_site_icon_url() : '';
}
$text_alignment = FarazSMS_Get_Setting('appearance', 'text_alignment');
?>

<div class="login-register-page <?php echo 'farazsms-' . $theme . ' ' . 'text-align-' .$text_alignment; ?>">
    <div class="login-register-form">
        <?php if ($logo) : ?>
        <div class="logo">
            <a href="<?php echo home_url(); ?>">
                <img src="<?php echo $logo; ?>" alt="<?php bloginfo('name'); ?>">
            </a>
        </div>
        <?php endif; ?>
        <div class="farazsms-main-login-form">
            <?php echo do_shortcode('[farazsms_login_form]'); ?>
        </div>
    </div>
</div>

<style type="text/css">
    <?php
    $background_image = FarazSMS_Get_Setting('appearance', 'background_image');
    $background_color = FarazSMS_Get_Setting('appearance', 'background_color');
    $background_color_box = FarazSMS_Get_Setting('appearance', 'background_color_box');
    $primary_color = FarazSMS_Get_Setting('appearance', 'primary_color');
    $text_color = FarazSMS_Get_Setting('appearance', 'text_color');
    $border_color = FarazSMS_Get_Setting('appearance', 'border_color');

    if (!empty($background_image)) {
        echo '.login-register-page {background: url('. $background_image .') no-repeat center center;background-size: cover;}';
    }
    if (!empty($background_color)) {
        echo '.login-register-page {background-color: '. $background_color .';}';
    }
    if (!empty($background_color_box)) {
        echo '.login-register-page .login-register-form {background-color: '. $background_color .';}';
    }
    if (!empty($primary_color)) {
        echo '.login-register-page input.farazsms-submit,.login-register-page .btn-loading,.farazsms-error-text.error-fixed,.btn-loading,.login-register-page .farazsms-loading div  {background-color: '. $primary_color .';}';
        echo '.login-register-page .farazsms-error-text,.farazsms-error-text.error-fixed,.login-register-page .farazsms-error-text,.login-register-page #farazsms-timer {color: '. $primary_color .';}';
        echo '.login-register-page .farazsms-back {fill: '. $primary_color .';}';
    }
    if (!empty($text_color)) {
        echo '.login-register-page {color: '. $text_color .';}';
    }
    if (!empty($border_color)) {
        echo '.login-register-page input {border-color: '. $border_color .';}';
    }
    if (!empty($custom_css)) {
        echo $custom_css;
    }
    ?>
</style>

<?php
// Load minimal footer
include FarazSMS_PATH . 'page-templates/footer-farazsms-login.php';