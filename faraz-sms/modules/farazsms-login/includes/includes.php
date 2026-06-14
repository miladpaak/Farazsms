<?php

require_once FarazSMS_INC . 'class-wallet.php';
require_once FarazSMS_INC . 'class-helper.php';
require_once FarazSMS_INC . 'class-forms.php';
require_once FarazSMS_INC . 'class-send-sms.php';
require_once FarazSMS_INC . 'class-otp.php';
require_once FarazSMS_INC . 'class-main.php';

// در حالت bundled داخل افزونه‌ی «فراز اس ام اس»، پیامک سفارشات ووکامرس بارگذاری نمی‌شود؛
// این کار از طریق افزونه‌ی ثالثِ «پیامک حرفه‌ای ووکامرس» (PWSMS) انجام می‌شود.
if ( ! defined( 'WTO_LOGIN_BUNDLED' ) ) {
	require_once FarazSMS_INC . 'class-woocommerce-sms.php';
}