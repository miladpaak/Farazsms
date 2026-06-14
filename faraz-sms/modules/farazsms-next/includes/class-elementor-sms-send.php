<?php
/**
 * Elementor SMS Send Class
 *
 * Handles SMS sending when Elementor forms are submitted
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Elementor_SMS_Send {

    /**
     * Constructor
     */
    public static function construct() {
        // Hook into Elementor Pro form submission
        add_action('elementor_pro/forms/new_record', array(__CLASS__, 'after_submit'), 10, 2);
        
        // Add validation hook to check pattern before submission
        add_action('elementor_pro/forms/validation', array(__CLASS__, 'validate_pattern_before_submit'), 10, 2);
    }

    /**
     * After form submission
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Form record object
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Ajax handler object
     */
    public static function after_submit($record, $handler) {
        if (!class_exists('\ElementorPro\Modules\Forms\Classes\Form_Record')) {
            return;
        }

        // Get form settings
        $form_settings = $record->get('form_settings');
        $post_id = isset($form_settings['form_post_id']) ? (int) $form_settings['form_post_id'] : 0;
        $widget_id = isset($form_settings['id']) ? sanitize_key((string) $form_settings['id']) : '';

        if (empty($post_id) || $widget_id === '') {
            return;
        }

        $feed_storage_key = $post_id . '_' . $widget_id;

        // Get form fields
        $fields = $record->get('fields');
        
        // Convert Elementor fields to entry-like format
        $entry = array();
        foreach ($fields as $field_id => $field) {
            $entry[$field_id] = $field['value'];
        }

        self::send_sms_form($entry, $feed_storage_key, $form_settings, $post_id);
    }

    /**
     * Send SMS for form
     *
     * @param array $entry Entry data (field_id => value)
     * @param string      $feed_storage_key post_id or post_id_widgetId for DB lookup.
     * @param array       $form_settings    Elementor form settings.
     * @param int|null    $post_id          Document post id (defaults from key).
     */
    public static function send_sms_form($entry, $feed_storage_key, $form_settings = array(), $post_id = null) {
        $feed_storage_key = FarazSMS_Next_Elementor_SMS_SQL::sanitize_form_storage_key($feed_storage_key);
        if ($feed_storage_key === '') {
            return;
        }
        $post_id = $post_id ? (int) $post_id : FarazSMS_Next_Elementor_SMS_SQL::post_id_from_storage_key($feed_storage_key);

        // Get active feeds for this form (composite first, legacy post-only fallback inside SQL).
        $feeds = FarazSMS_Next_Elementor_SMS_SQL::get_feed_via_formid($feed_storage_key, true);

        foreach ((array)$feeds as $feed) {
            // Skip malformed feeds rather than aborting the whole loop — previously `break`
            // here meant one bad row stopped every remaining feed from sending.
            if (!is_numeric($feed['id'])) {
                continue;
            }

            $from = isset($feed['meta']['from']) ? $feed['meta']['from'] : '';

            // Send admin SMS
            if (!empty($feed['meta']['to']) && !empty($feed['meta']['message'])) {
                // Check conditional logic for admin
                if (!self::check_condition($entry, $post_id, $feed, 'admin')) {
                    continue; // Skip this feed if condition not met
                }
                
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
                        $replaced_value = self::replace_merge_tag($merge_tag, $entry, $post_id);
                        $attributes['var' . $var_counter] = $replaced_value;
                        $var_counter++;
                    }
                    
                    // Send using pattern API
                    if (!class_exists('FarazSMS_Next_SMS_API')) {
                        require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-sms-api.php';
                    }
                    $sms_api = new FarazSMS_Next_SMS_API();

                    // وب‌سرویس پترن فراز، ارسال گروهی به چند شماره را در یک ریکوئست نمی‌پذیرد.
                    // اگر فیلد شماره مدیر چند شماره جداشده با «,» داشته باشد، برای هر شماره یک ریکوئست جدا می‌فرستیم.
                    $admin_numbers = array_filter( array_map( 'trim', explode( ',', (string) $admin_number ) ) );
                    if ( empty( $admin_numbers ) ) {
                        $admin_numbers = array( $admin_number );
                    }
                    $ok_count   = 0;
                    $err_count  = 0;
                    $last_error = '';
                    foreach ( $admin_numbers as $single_admin_no ) {
                        $pattern_result = $sms_api->send_pattern_sms($single_admin_no, $pattern_code, $attributes, $from, 'en');
                        if ( $pattern_result && isset($pattern_result['status']) && $pattern_result['status'] === 'success' ) {
                            $ok_count++;
                        } else {
                            $err_count++;
                            if ( isset($pattern_result['message']) ) {
                                $last_error = $pattern_result['message'];
                            } elseif ( isset($pattern_result['messages']) ) {
                                $last_error = is_array($pattern_result['messages']) ? implode(', ', $pattern_result['messages']) : $pattern_result['messages'];
                            }
                        }
                    }
                    if ( $err_count === 0 ) {
                        $result = 'OK';
                    } elseif ( $ok_count > 0 ) {
                        $result = sprintf(__('تعداد موفق: %d، تعداد ناموفق: %d. %s', 'farazsms-next'), $ok_count, $err_count, $last_error);
                    } else {
                        $result = $last_error !== '' ? $last_error : __('خطا در ارسال پیامک با پترن', 'farazsms-next');
                    }
                } else {
                    // Normal SMS sending (without pattern)
                    $admin_msg = self::replace_merge_tags($feed['meta']['message'], $entry, $post_id);
                    $result = self::Send($admin_number, $admin_msg, $from, $feed_storage_key, '');
                }
            }

            // Send client SMS
            if (!empty($feed['meta']['customer_field_clientnum'])) {
                $field_id = $feed['meta']['customer_field_clientnum'];
                $client_number = isset($entry[$field_id]) ? $entry[$field_id] : '';
                
                if (!empty($client_number) && !empty($feed['meta']['message_c'])) {
                    // Check conditional logic for client
                    if (!self::check_condition($entry, $post_id, $feed, 'client')) {
                        continue; // Skip this feed if condition not met
                    }
                    
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
                            $replaced_value = self::replace_merge_tag($merge_tag, $entry, $post_id);
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
                                $client_msg = self::replace_merge_tags($feed['meta']['message_c'], $entry, $post_id);
                                foreach ($extra_numbers as $extra_num) {
                                    if (!empty($extra_num)) {
                                        self::Send($extra_num, $client_msg, $from, $feed_storage_key, '');
                                    }
                                }
                            }
                        } else {
                            $result = isset($pattern_result['messages']) ? (is_array($pattern_result['messages']) ? implode(', ', $pattern_result['messages']) : $pattern_result['messages']) : __('خطا در ارسال پیامک با پترن', 'farazsms-next');
                        }
                    } else {
                        // Normal SMS sending (without pattern)
                        $client_msg = self::replace_merge_tags($feed['meta']['message_c'], $entry, $post_id);
                        
                        // Combine client number with extra numbers
                        if (!empty($extra_numbers)) {
                            $client_number = $client_number . ',' . implode(',', $extra_numbers);
                        }
                        
                        $result = self::Send($client_number, $client_msg, $from, $feed_storage_key, '');
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
     * @param string|int $form_storage_key Form key for sent log
     * @param int|string $entry_id Entry ID
     * @return string Result ('OK' on success, error message on failure)
     */
    public static function Send($to, $msg, $from = '', $form_storage_key = '', $entry_id = '') {
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
                FarazSMS_Next_Elementor_SMS_SQL::save_sms_sent($form_storage_key, $entry_id, $from, $number, $msg);
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

        // Extract digits only
        preg_match_all('/\d+/', $mobile, $matches);
        $phone = implode('', $matches[0]);

        if (empty($phone)) {
            return '';
        }

        // v3.17.3: همیشه به فرمت ایرانی 09xxxxxxxxx normalize کن — Faraz API این را می‌خواهد.
        if ( function_exists( 'wto_normalize_phone' ) ) {
            $normalized = wto_normalize_phone( $mobile );
            if ( preg_match( '/^09\d{9}$/', $normalized ) ) {
                return $normalized;
            }
        }
        if ( strpos( $phone, '0098' ) === 0 ) $phone = substr( $phone, 4 );
        elseif ( strpos( $phone, '98' ) === 0 && strlen( $phone ) === 12 ) $phone = substr( $phone, 2 );
        if ( strlen( $phone ) === 10 && $phone[0] === '9' ) $phone = '0' . $phone;
        return preg_match( '/^09\d{9}$/', $phone ) ? $phone : '';
    }

    /**
     * Check conditional logic
     *
     * @param array $entry Entry data
     * @param int $form_id Form ID
     * @param array $config Feed config
     * @param string $who 'admin' or 'client'
     * @return bool True if condition is met (or disabled), false otherwise
     */
    public static function check_condition($entry, $form_id, $config, $who = '') {
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
            
            // Get condition value and operator
            $value = !empty($condition_values['' . $i . '']) ? $condition_values['' . $i . ''] : '';
            $operator = !empty($condition_operators['' . $i . '']) ? $condition_operators['' . $i . ''] : 'is';
            
            // Get field value from entry
            $field_value = isset($entry[$field_id]) ? $entry[$field_id] : '';
            
            // Check if value matches
            $is_value_match = false;
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
            
            if ($type == 'any' && $is_value_match) {
                return true; // At least one condition matches
            } elseif ($type == 'all' && !$is_value_match) {
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
     * @param string $message Message text with Elementor merge tags
     * @return array Array of merge tags in order
     */
    private static function extract_merge_tags_mapping_from_message($message) {
        $merge_tags = array();
        // Elementor uses {field:field_id} or {field:field_name} format
        preg_match_all('/{field:([^}]+)}/i', $message, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $merge_tags[] = $match[0]; // Full merge tag: {field:field_id}
        }
        
        return $merge_tags;
    }

    /**
     * Replace merge tag with actual value
     *
     * @param string $merge_tag Merge tag (e.g., {field:field_id})
     * @param array $entry Entry data
     * @param int $form_id Form ID
     * @return string Replaced value
     */
    private static function replace_merge_tag($merge_tag, $entry, $form_id) {
        // Extract field ID or name from merge tag
        if (preg_match('/{field:([^}]+)}/i', $merge_tag, $matches)) {
            $field_identifier = $matches[1];
            
            // Try to find field by ID first, then by name
            if (isset($entry[$field_identifier])) {
                return $entry[$field_identifier];
            }
            
            // Try to find by name (if field_identifier is a name)
            foreach ($entry as $key => $value) {
                // Get field name from form settings if available
                // For now, just return the value if key matches
                if (strpos($key, $field_identifier) !== false || strpos($field_identifier, $key) !== false) {
                    return $value;
                }
            }
        }
        
        return $merge_tag; // Return original if not found
    }

    /**
     * Replace all merge tags in message
     *
     * @param string $message Message with merge tags
     * @param array $entry Entry data
     * @param int $form_id Form ID
     * @return string Message with replaced merge tags
     */
    private static function replace_merge_tags($message, $entry, $form_id) {
        // Find all merge tags
        preg_match_all('/{field:([^}]+)}/i', $message, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $full_tag = $match[0];
            $field_identifier = $match[1];
            
            // Get field value
            $value = isset($entry[$field_identifier]) ? $entry[$field_identifier] : '';
            
            // Replace in message
            $message = str_replace($full_tag, $value, $message);
        }
        
        return $message;
    }

    /**
     * Validate pattern code before form submission
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Form record object
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Ajax handler object
     */
    public static function validate_pattern_before_submit($record, $handler) {
        if (!class_exists('\ElementorPro\Modules\Forms\Classes\Form_Record')) {
            return;
        }

        // Check if record is valid
        if (empty($record) || !is_object($record)) {
            return;
        }

        // Get form settings
        $form_settings = $record->get('form_settings');
        $post_id = isset($form_settings['form_post_id']) ? (int) $form_settings['form_post_id'] : 0;
        $widget_id = isset($form_settings['id']) ? sanitize_key((string) $form_settings['id']) : '';

        if (empty($post_id) || $widget_id === '') {
            return;
        }

        $feed_storage_key = $post_id . '_' . $widget_id;

        // Get active feeds for this form
        $feeds = FarazSMS_Next_Elementor_SMS_SQL::get_feed_via_formid($feed_storage_key, true);

        foreach ((array)$feeds as $feed) {
            // Check admin pattern
            if (!empty($feed['meta']['to']) && !empty($feed['meta']['message'])) {
                // اگر pattern_merge_tags_mapping وجود دارد، یعنی feed برای pattern تنظیم شده
                if (isset($feed['meta']['pattern_merge_tags_mapping_admin']) && 
                    !empty($feed['meta']['pattern_merge_tags_mapping_admin'])) {
                    // اگر feed برای pattern تنظیم شده اما pattern_code خالی است
                    if (empty(trim($feed['meta']['pattern_code_admin'] ?? ''))) {
                        $handler->add_error('pattern_validation', __('خطا: پترن پیامک مدیر ثبت نشده است. لطفا ابتدا پترن را در تنظیمات فرم ایجاد کنید.', 'farazsms-next'));
                        return;
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
                        $handler->add_error('pattern_validation', __('خطا: پترن پیامک مشتری ثبت نشده است. لطفا ابتدا پترن را در تنظیمات فرم ایجاد کنید.', 'farazsms-next'));
                        return;
                    }
                }
            }
        }
    }
}
