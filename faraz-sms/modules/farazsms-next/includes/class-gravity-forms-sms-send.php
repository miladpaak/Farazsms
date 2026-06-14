<?php
/**
 * Gravity Forms SMS Send Class
 *
 * Handles SMS sending when forms are submitted
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Gravity_Forms_SMS_Send {

    /**
     * Constructor
     */
    public static function construct() {
        // Hook into form confirmation (after submit)
        add_filter('gform_confirmation', array(__CLASS__, 'after_submit'), 9999999, 4);
        
        // Add validation hook to check pattern before submission
        add_filter('gform_validation', array(__CLASS__, 'validate_pattern_before_submit'), 10, 4);
        // OTP field: require verified mobile before submit
        add_filter('gform_field_validation', array(__CLASS__, 'validate_otp_field'), 10, 4);
        
        // Hook into payment status change
        add_action('gform_post_payment_status', array(__CLASS__, 'after_payment'), 999999, 4);
        
        // Process merge tags
        add_filter('gform_replace_merge_tags', array(__CLASS__, 'replace_merge_tags'), 99999, 7);
    }

    /**
     * After form submission
     *
     * @param mixed $confirmation Confirmation message
     * @param array $form Form object
     * @param array $entry Entry object
     * @param bool $ajax Is AJAX submission
     * @return mixed Confirmation message
     */
    public static function after_submit($confirmation, $form, $entry, $ajax) {
        self::send_sms_form($entry, $form, '-', 'immediately');
        return $confirmation;
    }

    /**
     * After payment status change
     *
     * @param array $entry Entry object
     * @param array $action Payment action
     * @param string $status Payment status
     * @param string $transaction_id Transaction ID
     */
    public static function after_payment($entry, $action, $status, $transaction_id) {
        if (class_exists('GFAPI')) {
            $form = GFAPI::get_form($entry['form_id']);
        } else {
            $form = RGFormsModel::get_form_meta($entry['form_id']);
        }
        self::send_sms_form($entry, $form, strtolower($status), 'after_payment');
    }

    /**
     * Send SMS for form
     *
     * @param array $entry Entry object
     * @param array $form Form object
     * @param string $status Status
     * @param string $function_time Function timing
     */
    public static function send_sms_form($entry, $form, $status, $function_time) {
        if (!is_numeric($form['id'])) {
            return;
        }

        // Get active feeds for this form
        $feeds = FarazSMS_Next_Gravity_Forms_SMS_SQL::get_feed_via_formid($form['id'], true);
        $status = strtolower($status);

        foreach ((array)$feeds as $feed) {
            // Skip malformed feeds rather than aborting the whole loop — previously `break`
            // here meant one bad row stopped every remaining feed from sending.
            if (!is_numeric($feed['id'])) {
                continue;
            }

            // زمان ارسال — مستقل برای مدیر و کاربر. سازگاری با feedهای قدیمی:
            // اگر when_admin ذخیره نشده باشد، مدیر هم از when (کاربر) پیروی می‌کند که دقیقاً
            // رفتارِ نسخه‌های قبلی است (که یک «when» کلِ feed را گِیت می‌کرد).
            $when_customer = isset($feed['meta']['when']) ? $feed['meta']['when'] : 'after_submit';
            $when_admin    = (isset($feed['meta']['when_admin']) && $feed['meta']['when_admin'] !== '')
                ? $feed['meta']['when_admin']
                : $when_customer;

            $from = isset($feed['meta']['from']) ? $feed['meta']['from'] : '';

            // Send admin SMS — گِیتِ زمانِ مستقل + پرچمِ ارسالِ جداگانه (تا با ارسال به کاربر
            // تداخل نکند؛ هر گیرنده فقط یک‌بار ارسال می‌شود حتی اگر زمان‌بندی‌شان متفاوت باشد).
            if (!empty($feed['meta']['to']) && !empty($feed['meta']['message'])
                && self::should_send_now($when_admin, $function_time, $status)
                && gform_get_meta($entry['id'], 'farazsms_sent_admin_' . $feed['id']) != 'yes'
                && self::check_condition($entry, $form, $feed, 'admin')
            ) {
                gform_update_meta($entry['id'], 'farazsms_sent_admin_' . $feed['id'], 'yes');

                $admin_number = $feed['meta']['to'];
                
                // Check if pattern code exists for admin message
                if (!empty($feed['meta']['pattern_code_admin'])) {
                    $pattern_code = $feed['meta']['pattern_code_admin'];
                    $original_message = $feed['meta']['message'];
                    
                    // Get merge tags mapping (order is important)
                    $merge_tags_mapping = isset($feed['meta']['pattern_merge_tags_mapping_admin']) 
                        ? $feed['meta']['pattern_merge_tags_mapping_admin'] 
                        : self::extract_merge_tags_mapping_from_message($original_message);
                    
                    // Replace each merge tag with actual value and build attributes array
                    $attributes = array();
                    $var_counter = 1;
                    
                    foreach ($merge_tags_mapping as $merge_tag) {
                        // Replace single merge tag with actual value from entry
                        $replaced_value = GFCommon::replace_variables($merge_tag, $form, $entry, false, true, false, 'text');
                        $attributes['var' . $var_counter] = $replaced_value;
                        $var_counter++;
                    }
                    
                    // Send using pattern API
                    if (!class_exists('FarazSMS_Next_SMS_API')) {
                        require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-sms-api.php';
                    }
                    $sms_api = new FarazSMS_Next_SMS_API();

                    // v3.14.6 BUG FIX: وب‌سرویس پترن فراز چند شماره گروهی را قبول
                    // نمی‌کند. اگر admin_number شامل چند شماره با کاما جدا باشد
                    // (مثلاً «09120000000,09130000000»)، باید برای هر شماره یک
                    // ریکوئست جدا اجرا شود.
                    $admin_numbers = array_filter( array_map( 'trim', explode( ',', (string) $admin_number ) ) );
                    if ( empty( $admin_numbers ) ) {
                        $admin_numbers = array( $admin_number );
                    }
                    $success_count = 0;
                    $fail_count    = 0;
                    $last_err_msg  = '';
                    foreach ( $admin_numbers as $single_admin_no ) {
                        $pattern_result = $sms_api->send_pattern_sms( $single_admin_no, $pattern_code, $attributes, $from, 'en' );
                        if ( $pattern_result && isset( $pattern_result['status'] ) && $pattern_result['status'] === 'success' ) {
                            $success_count++;
                        } else {
                            $fail_count++;
                            if ( is_array( $pattern_result ) ) {
                                if ( isset( $pattern_result['message'] ) ) {
                                    $last_err_msg = (string) $pattern_result['message'];
                                } elseif ( isset( $pattern_result['messages'] ) ) {
                                    $last_err_msg = is_array( $pattern_result['messages'] ) ? implode( '، ', $pattern_result['messages'] ) : (string) $pattern_result['messages'];
                                }
                            }
                        }
                    }
                    if ( $fail_count === 0 ) {
                        $result = 'OK';
                    } elseif ( $success_count > 0 ) {
                        $result = sprintf(
                            __('تعداد موفق: %d، تعداد ناموفق: %d. %s', 'farazsms-next'),
                            $success_count, $fail_count, $last_err_msg
                        );
                    } else {
                        $result = $last_err_msg !== '' ? $last_err_msg : __('خطا در ارسال پیامک با پترن', 'farazsms-next');
                    }
                } else {
                    // Normal SMS sending (without pattern)
                    $admin_msg = GFCommon::replace_variables($feed['meta']['message'], $form, $entry, false, true, false, 'text');
                    $result = self::Send($admin_number, $admin_msg, $from, $form['id'], $entry['id']);
                }
                
                if ($result === 'OK') {
                    if (function_exists('gform_add_note')) {
                        gform_add_note($entry['id'], 0, __('پیامک', 'farazsms-next'), 
                            sprintf(__('Feed %s => پیامک با موفقیت به مدیر ارسال شد. شماره: %s', 'farazsms-next'), $feed['id'], $admin_number));
                    } elseif (class_exists('RGFormsModel')) {
                        RGFormsModel::add_note($entry['id'], 0, __('پیامک', 'farazsms-next'), 
                            sprintf(__('Feed %s => پیامک با موفقیت به مدیر ارسال شد. شماره: %s', 'farazsms-next'), $feed['id'], $admin_number));
                    }
                } else {
                    if (function_exists('gform_add_note')) {
                        gform_add_note($entry['id'], 0, __('پیامک', 'farazsms-next'), 
                            sprintf(__('Feed %s => ارسال پیامک به مدیر با خطا مواجه شد. خطا: %s', 'farazsms-next'), $feed['id'], $result));
                    } elseif (class_exists('RGFormsModel')) {
                        RGFormsModel::add_note($entry['id'], 0, __('پیامک', 'farazsms-next'), 
                            sprintf(__('Feed %s => ارسال پیامک به مدیر با خطا مواجه شد. خطا: %s', 'farazsms-next'), $feed['id'], $result));
                    }
                }
            }

            // Send client SMS
            if (!empty($feed['meta']['customer_field_clientnum'])) {
                $field_id = $feed['meta']['customer_field_clientnum'];
                $client_number = rgar($entry, $field_id);
                
                if (!empty($client_number) && !empty($feed['meta']['message_c'])
                    && self::should_send_now($when_customer, $function_time, $status)
                    && gform_get_meta($entry['id'], 'farazsms_sent_client_' . $feed['id']) != 'yes'
                    && self::check_condition($entry, $form, $feed, 'client')) {
                    gform_update_meta($entry['id'], 'farazsms_sent_client_' . $feed['id'], 'yes');

                    // Add extra numbers if any
                    $extra_numbers = array();
                    if (!empty($feed['meta']['to_c'])) {
                        $extra_numbers = array_map('trim', explode(',', $feed['meta']['to_c']));
                    }
                    
                    // Check if pattern code exists for customer message
                    if (!empty($feed['meta']['pattern_code_customer'])) {
                        $pattern_code = $feed['meta']['pattern_code_customer'];
                        $original_message = $feed['meta']['message_c'];
                        
                        // Get merge tags mapping (order is important)
                        $merge_tags_mapping = isset($feed['meta']['pattern_merge_tags_mapping_customer']) 
                            ? $feed['meta']['pattern_merge_tags_mapping_customer'] 
                            : self::extract_merge_tags_mapping_from_message($original_message);
                        
                        // Replace each merge tag with actual value and build attributes array
                        $attributes = array();
                        $var_counter = 1;
                        
                        foreach ($merge_tags_mapping as $merge_tag) {
                            // Replace single merge tag with actual value from entry
                            $replaced_value = GFCommon::replace_variables($merge_tag, $form, $entry, false, true, false, 'text');
                            $attributes['var' . $var_counter] = $replaced_value;
                            $var_counter++;
                        }
                        
                        // Send using pattern API - send to main client number
                        if (!class_exists('FarazSMS_Next_SMS_API')) {
                            require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-sms-api.php';
                        }
                        $sms_api = new FarazSMS_Next_SMS_API();
                        $pattern_result = $sms_api->send_pattern_sms($client_number, $pattern_code, $attributes, $from, 'en');
                        
                        if ($pattern_result && isset($pattern_result['status']) && $pattern_result['status'] === 'success') {
                            $result = 'OK';
                            // Send to extra numbers if any (using normal SMS as they don't support pattern)
                            if (!empty($extra_numbers)) {
                                $client_msg = GFCommon::replace_variables($feed['meta']['message_c'], $form, $entry, false, true, false, 'text');
                                foreach ($extra_numbers as $extra_num) {
                                    if (!empty($extra_num)) {
                                        self::Send($extra_num, $client_msg, $from, $form['id'], $entry['id']);
                                    }
                                }
                            }
                        } else {
                            $result = isset($pattern_result['messages']) ? (is_array($pattern_result['messages']) ? implode(', ', $pattern_result['messages']) : $pattern_result['messages']) : __('خطا در ارسال پیامک با پترن', 'farazsms-next');
                        }
                    } else {
                        // Normal SMS sending (without pattern)
                        $client_msg = GFCommon::replace_variables($feed['meta']['message_c'], $form, $entry, false, true, false, 'text');
                        
                        // Combine client number with extra numbers
                        if (!empty($extra_numbers)) {
                            $client_number = $client_number . ',' . implode(',', $extra_numbers);
                        }
                        
                        $result = self::Send($client_number, $client_msg, $from, $form['id'], $entry['id']);
                    }
                    
                    if ($result === 'OK') {
                        if (function_exists('gform_add_note')) {
                            gform_add_note($entry['id'], 0, __('SMS', 'farazsms-next'), 
                                sprintf(__('Feed %s => SMS sent to Client successfully. Number: %s', 'farazsms-next'), $feed['id'], $client_number));
                        } elseif (class_exists('RGFormsModel')) {
                            RGFormsModel::add_note($entry['id'], 0, __('SMS', 'farazsms-next'), 
                                sprintf(__('Feed %s => SMS sent to Client successfully. Number: %s', 'farazsms-next'), $feed['id'], $client_number));
                        }
                    } else {
                        if (function_exists('gform_add_note')) {
                            gform_add_note($entry['id'], 0, __('SMS', 'farazsms-next'), 
                                sprintf(__('Feed %s => SMS sending to Client failed. Error: %s', 'farazsms-next'), $feed['id'], $result));
                        } elseif (class_exists('RGFormsModel')) {
                            RGFormsModel::add_note($entry['id'], 0, __('SMS', 'farazsms-next'), 
                                sprintf(__('Feed %s => SMS sending to Client failed. Error: %s', 'farazsms-next'), $feed['id'], $result));
                        }
                    }
                }
            }
        }
    }

    /**
     * Send SMS
     *
     * @param string $to Receiver number(s) - comma separated
     * @param string $msg Message content
     * @param string $from Sender number
     * @param int $form_id Form ID
     * @param int|string $entry_id Entry ID
     * @return string Result ('OK' on success, error message on failure)
     */
    public static function Send($to, $msg, $from = '', $form_id = '', $entry_id = '') {
        if (empty($to) || empty($msg)) {
            return 'Empty receiver or message';
        }

        // Normalize phone numbers
        $to = self::normalize_mobile($to);
        $numbers = explode(',', $to);
        $numbers = array_map('trim', $numbers);
        $numbers = array_filter($numbers);

        if (empty($numbers)) {
            return 'No valid numbers';
        }

        // Load SMS API class if not already loaded
        if (!class_exists('FarazSMS_Next_SMS_API')) {
            require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-sms-api.php';
        }

        $sms_api = new FarazSMS_Next_SMS_API();
        $success_count = 0;
        $fail_count = 0;

        foreach ($numbers as $number) {
            $result = $sms_api->send_sms($number, $msg, $from);
            
            if ($result !== false) {
                $success_count++;
                // Save to sent table
                FarazSMS_Next_Gravity_Forms_SMS_SQL::save_sms_sent($form_id, $entry_id, $from, $number, $msg);
            } else {
                $fail_count++;
            }
        }

        if ($success_count > 0 && $fail_count == 0) {
            return 'OK';
        } elseif ($success_count > 0) {
            return sprintf(__('Partial success: %d sent, %d failed', 'farazsms-next'), $success_count, $fail_count);
        } else {
            return __('All SMS sending failed', 'farazsms-next');
        }
    }

    /**
     * Normalize mobile number
     *
     * @param string $mobile Mobile number
     * @return string Normalized number
     */
    private static function normalize_mobile($mobile) {
        if (empty($mobile)) {
            return '';
        }

        // Remove spaces and dashes
        $mobile = preg_replace('/[\s\-]/', '', $mobile);
        
        // If multiple numbers (comma separated), process each
        if (strpos($mobile, ',') !== false) {
            $mobiles = explode(',', $mobile);
            $normalized = array();
            foreach ($mobiles as $m) {
                $normalized[] = self::normalize_single_mobile(trim($m));
            }
            return implode(',', array_filter($normalized));
        }

        return self::normalize_single_mobile($mobile);
    }

    /**
     * Normalize single mobile number
     *
     * @param string $mobile Mobile number
     * @return string Normalized number
     */
    private static function normalize_single_mobile($mobile) {
        if (empty($mobile)) {
            return '';
        }

        // v3.17.3: همیشه به فرمت ایرانی 09xxxxxxxxx normalize کن — Faraz API این فرمت را می‌خواهد.
        // قبلاً به +98 تبدیل می‌شد که نتیجه‌ی ارسال‌ها fail می‌شد.
        if ( function_exists( 'wto_normalize_phone' ) ) {
            $normalized = wto_normalize_phone( $mobile );
            if ( preg_match( '/^09\d{9}$/', $normalized ) ) {
                return $normalized;
            }
        }

        // Fallback inline normalize
        $phone = preg_replace( '/[^\d]/', '', (string) $mobile );
        if ( strpos( $phone, '0098' ) === 0 ) $phone = substr( $phone, 4 );
        elseif ( strpos( $phone, '98' ) === 0 && strlen( $phone ) === 12 ) $phone = substr( $phone, 2 );
        if ( strlen( $phone ) === 10 && $phone[0] === '9' ) $phone = '0' . $phone;
        return preg_match( '/^09\d{9}$/', $phone ) ? $phone : '';
    }

    /**
     * آیا با توجه به «زمان ارسال» انتخاب‌شده، اکنون باید ارسال شود؟
     * منطق دقیقاً همان نسخه‌های قبلی است، فقط به‌صورت تابعِ مستقل تا برای مدیر و
     * کاربر جداگانه ارزیابی شود.
     *
     * @param string $when          after_submit | after_payment | after_payment_success
     * @param string $function_time immediately | after_payment
     * @param string $status        وضعیتِ پرداخت (در حالتِ after_payment)
     * @return bool
     */
    private static function should_send_now($when, $function_time, $status) {
        $status = strtolower((string) $status);
        if ($when === 'after_payment_success') {
            return ($function_time !== 'immediately')
                && in_array($status, array('completed', 'complete', 'paid', 'active', 'approved'), true);
        }
        if ($when === 'after_payment') {
            return ($function_time !== 'immediately');
        }
        // after_submit (پیش‌فرض) — مثل نسخه‌های قبلی در اولین هوکی که اجرا شود ارسال می‌شود؛
        // پرچمِ «ارسال‌شده» از ارسالِ دوباره جلوگیری می‌کند.
        return true;
    }

    /**
     * Check conditional logic
     *
     * @param array $entry Entry object
     * @param array $form Form object
     * @param array $config Feed config
     * @param string $who 'admin' or 'client'
     * @return bool True if condition is met (or disabled), false otherwise
     */
    public static function check_condition($entry, $form, $config, $who = '') {
        if (empty($config['meta'])) {
            return false;
        }
        
        // If conditional logic is not enabled, always return true
        if (empty($config['meta'][$who . 'sms_conditional_enabled'])) {
            return true;
        }
        
        // Get conditions
        if (!empty($config['meta'][$who . 'sms_conditional_field_id'])) {
            $conditions = $config['meta'][$who . 'sms_conditional_field_id'];
            if (!is_array($conditions)) {
                $conditions = array('1' => $conditions);
            }
        } else {
            return true; // No conditions means always send
        }
        
        // Get condition values
        if (!empty($config['meta'][$who . 'sms_conditional_value'])) {
            $condition_values = $config['meta'][$who . 'sms_conditional_value'];
            if (!is_array($condition_values)) {
                $condition_values = array('1' => $condition_values);
            }
        } else {
            $condition_values = array('1' => '');
        }
        
        // Get condition operators
        if (!empty($config['meta'][$who . 'sms_conditional_operator'])) {
            $condition_operators = $config['meta'][$who . 'sms_conditional_operator'];
            if (!is_array($condition_operators)) {
                $condition_operators = array('1' => $condition_operators);
            }
        } else {
            $condition_operators = array('1' => 'is');
        }
        
        // Get condition type (all or any)
        $type = !empty($config['meta'][$who . 'sms_conditional_type']) ? strtolower($config['meta'][$who . 'sms_conditional_type']) : '';
        $type = $type == 'all' ? 'all' : 'any';
        
        // Check each condition
        foreach ($conditions as $i => $field_id) {
            if (empty($field_id)) {
                continue;
            }
            
            // Get field
            $field = null;
            if (class_exists('RGFormsModel')) {
                $field = RGFormsModel::get_field($form, $field_id);
            } elseif (class_exists('GFAPI')) {
                $fields = $form['fields'];
                foreach ($fields as $f) {
                    if (is_array($f) && isset($f['id']) && $f['id'] == $field_id) {
                        $field = $f;
                        break;
                    } elseif (is_object($f) && isset($f->id) && $f->id == $field_id) {
                        $field = $f;
                        break;
                    }
                }
            }
            
            if (empty($field)) {
                continue;
            }
            
            // Get condition value and operator
            $value = !empty($condition_values['' . $i . '']) ? $condition_values['' . $i . ''] : '';
            $operator = !empty($condition_operators['' . $i . '']) ? $condition_operators['' . $i . ''] : 'is';
            
            // Check if field is visible
            $is_visible = true;
            if (class_exists('RGFormsModel')) {
                $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());
            }
            
            // Get field value from entry
            $field_value = null;
            if (class_exists('GFFormsModel')) {
                $field_value = GFFormsModel::get_lead_field_value($entry, $field);
            } elseif (isset($entry[$field_id])) {
                $field_value = $entry[$field_id];
            }
            
            // Check if value matches
            $is_value_match = false;
            if (class_exists('RGFormsModel')) {
                $is_value_match = RGFormsModel::is_value_match($field_value, $value, $operator);
            } else {
                // Fallback: simple comparison
                $field_value_str = is_array($field_value) ? implode(',', $field_value) : (string)$field_value;
                $value_str = (string)$value;
                
                switch ($operator) {
                    case 'is':
                        $is_value_match = ($field_value_str == $value_str);
                        break;
                    case 'isnot':
                        $is_value_match = ($field_value_str != $value_str);
                        break;
                    case '>':
                        $is_value_match = ($field_value_str > $value_str);
                        break;
                    case '<':
                        $is_value_match = ($field_value_str < $value_str);
                        break;
                    case 'contains':
                        $is_value_match = (strpos($field_value_str, $value_str) !== false);
                        break;
                    case 'starts_with':
                        $is_value_match = (strpos($field_value_str, $value_str) === 0);
                        break;
                    case 'ends_with':
                        $is_value_match = (substr($field_value_str, -strlen($value_str)) === $value_str);
                        break;
                }
            }
            
            $check = $is_value_match && $is_visible;
            
            if ($type == 'any' && $check) {
                return true; // At least one condition matches
            } elseif ($type == 'all' && !$check) {
                return false; // One condition doesn't match (all must match)
            }
        }
        
        // If type is 'any' and we got here, none matched
        if ($type == 'any') {
            return false;
        }
        
        // If type is 'all' and we got here, all matched
        return true;
    }
    
    /**
     * Extract merge tags mapping from message (helper function for pattern variables)
     *
     * @param string $message Message text with Gravity Forms merge tags
     * @return array Array of merge tags in order
     */
    private static function extract_merge_tags_mapping_from_message($message) {
        $merge_tags = array();
        preg_match_all('/{([^}]+):(\d+(?:\.\d+)?)}/i', $message, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $merge_tags[] = $match[0]; // Full merge tag: {نام (نام):1.3}
        }
        
        return $merge_tags;
    }

    /**
     * Replace merge tags (placeholder for future enhancement)
     *
     * @param string $text Text with merge tags
     * @param array $form Form object
     * @param array $entry Entry object
     * @param bool $url_encode URL encode
     * @param bool $esc_html Escape HTML
     * @param bool $nl2br Convert newlines to br
     * @param string $format Format
     * @return string Processed text
     */
    public static function replace_merge_tags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format) {
        // Gravity Forms handles most merge tags, but we can add custom ones here if needed
        return $text;
    }

    /**
     * Validate pattern code before form submission
     *
     * @param array $validation_result Validation result
     * @param array $value Form values
     * @param array $form Form object
     * @param array $field Field object
     * @return array Validation result
     */
    public static function validate_pattern_before_submit($validation_result, $value, $form, $field) {
        if (!$validation_result['is_valid']) {
            return $validation_result; // اگر قبلاً validation fail شده، نیازی به چک کردن نیست
        }

        // Check if form is valid and has ID
        if (empty($form) || !is_array($form) || !isset($form['id'])) {
            return $validation_result;
        }

        // Get active feeds for this form
        $feeds = FarazSMS_Next_Gravity_Forms_SMS_SQL::get_feed_via_formid($form['id'], true);

        foreach ((array)$feeds as $feed) {
            // Check admin pattern
            if (!empty($feed['meta']['to']) && !empty($feed['meta']['message'])) {
                // اگر pattern_merge_tags_mapping وجود دارد، یعنی feed برای pattern تنظیم شده
                if (isset($feed['meta']['pattern_merge_tags_mapping_admin']) && 
                    !empty($feed['meta']['pattern_merge_tags_mapping_admin'])) {
                    // اگر feed برای pattern تنظیم شده اما pattern_code خالی است
                    if (empty(trim($feed['meta']['pattern_code_admin'] ?? ''))) {
                        $validation_result['is_valid'] = false;
                        $validation_result['message'] = __('خطا: پترن پیامک مدیر ثبت نشده است. لطفا ابتدا پترن را در تنظیمات فرم ایجاد کنید.', 'farazsms-next');
                        return $validation_result;
                    }
                }
            }
            
            // Check customer pattern
            if (!empty($feed['meta']['customer_field_clientnum']) && !empty($feed['meta']['message_c'])) {
                // اگر pattern_merge_tags_mapping وجود دارد، یعنی feed برای pattern تنظیم شده
                if (isset($feed['meta']['pattern_merge_tags_mapping_customer']) && 
                    !empty($feed['meta']['pattern_merge_tags_mapping_customer'])) {
                    // اگر feed برای pattern تنظیم شده اما pattern_code خالی است
                    if (empty(trim($feed['meta']['pattern_code_customer'] ?? ''))) {
                        $validation_result['is_valid'] = false;
                        $validation_result['message'] = __('خطا: پترن پیامک مشتری ثبت نشده است. لطفا ابتدا پترن را در تنظیمات فرم ایجاد کنید.', 'farazsms-next');
                        return $validation_result;
                    }
                }
            }
        }
        
        return $validation_result;
    }

    /**
     * Validate OTP verification field: mobile must be verified via transient.
     *
     * @param array $result Validation result (is_valid, message).
     * @param mixed $value  Field value (mobile).
     * @param array $form   Form object.
     * @param array $field  Field object.
     * @return array
     */
    public static function validate_otp_field($result, $value, $form, $field) {
        if (!isset($field->type) || $field->type !== 'otp_verification') {
            return $result;
        }
        if (!function_exists('wto_otp_normalize_mobile') || !function_exists('wto_otp_is_verified')) {
            return $result;
        }
        $mobile = is_array($value) ? implode('', $value) : (string) $value;
        $mobile = wto_otp_normalize_mobile($mobile);
        if (empty($mobile)) {
            $result['is_valid'] = false;
            $result['message']  = __('شماره موبایل را وارد کنید.', 'farazsms-next');
            return $result;
        }
        if (!wto_otp_is_verified('gf', (string) $form['id'], $mobile)) {
            $result['is_valid'] = false;
            $result['message']  = __('لطفاً ابتدا با دکمه «ارسال کد» و «تأیید کد» شماره موبایل را تأیید کنید.', 'farazsms-next');
            return $result;
        }
        return $result;
    }
}

