<?php

namespace FarazSMS;


class OTP {

    /**
     * تبدیل ارقام فارسی/عربی به لاتین — دفاع لایه‌دوم سمت سرور.
     * اگر افزونه فراز اس ام اس فعال باشد از تابع مشترک آن استفاده می‌کند،
     * وگرنه به نگاشت داخلی برمی‌گردد (خوداتکا).
     */
    private function normalize_digits($str) {
        if ($str === null || $str === '') {
            return $str;
        }
        if (function_exists('wto_tr_num')) {
            return wto_tr_num($str);
        }
        $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $arabic  = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
        $latin   = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        return str_replace(array_merge($persian, $arabic), array_merge($latin, $latin), $str);
    }

    public function send_verification_code($verification, $verification_code) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'farazsms_verification';
        // اعتبارِ کد: ۵ دقیقه (قبلاً ۲ دقیقه بود که با تأخیرِ تحویلِ پیامک گاهی منقضی می‌شد
        // و کدِ درست «اشتباه» اعلام می‌گشت).
        $expire_time = 5;
        $expire_date = date("Y-m-d H:i:s", strtotime("+$expire_time minutes"));

        // نکته: جریانِ ارسال در class-main.php همیشه قبل از این، delete_user_verification_data
        // را صدا می‌زند، پس ردیفِ قبلیِ این شماره حذف شده و insert هرگز با خطای کلیدِ تکراری
        // (UNIQUE verification) مواجه نمی‌شود. این رفتارِ پایدارِ نسخه‌ی ۳.۲۰.۱۵ است.
        $wpdb->insert(
            $table_name,
            array(
                'verification'  => $verification,
                'code'          => $verification_code,
                'expire_date'   => $expire_date,
            ),
            array('%s', '%s', '%s')
        );
    }
    

    public function verify_verification_code($verification, $verification_code) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'farazsms_verification';
    
        // آخرین کدِ ثبت‌شده برای این شماره ملاک است (در صورت ارسال مجدد، ردیفِ قدیمی انتخاب نشود).
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE verification = %s ORDER BY id DESC LIMIT 1", $verification));

        if ($result) {
            // trim برای حذف فاصله‌ی احتمالی + نرمال‌سازی ارقام فارسی/عربی در هر دو طرف.
            $expected_code     = trim((string) $this->normalize_digits($result->code));
            $verification_code = trim((string) $this->normalize_digits($verification_code));
            $expire_date       = strtotime($result->expire_date);
            if ($verification_code !== '' && $verification_code === $expected_code && $expire_date > time()) {
                return true;
            }
        }
    
        return false;
    }
    
    public function delete_user_verification_data($verification) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'farazsms_verification';

        $expire_condition = 'expire_date < %s';
        $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE $expire_condition", date("Y-m-d H:i:s")));
        $wpdb->delete($table_name, ['verification' => $verification], ['%s']);
    }
}