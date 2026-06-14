<?php

namespace FarazSMS;


class Form_Settings {

    public function Main_Form($back_url = '') {
        include FarazSMS_PATH . 'templates/main-form.php';
    }

    public function Mobile_Login($identifier, $identifier_type, $back_url) {
        include FarazSMS_PATH . 'templates/mobile-login.php';
    }

    public function Mobile_Login_With_Password($identifier, $identifier_type, $back_url) {
        include FarazSMS_PATH . 'templates/mobile-login-password.php';
    }

    public function Mobile_Forget_Password($identifier, $identifier_type, $back_url) {
        include FarazSMS_PATH . 'templates/mobile-forget-password.php';
    }

    public function Mobile_Reset_Password($identifier, $identifier_type, $back_url) {
        include FarazSMS_PATH . 'templates/mobile-reset-password.php';
    }

    public function Mobile_Register($identifier, $identifier_type, $back_url) {
        include FarazSMS_PATH . 'templates/mobile-register.php';
    }
}
