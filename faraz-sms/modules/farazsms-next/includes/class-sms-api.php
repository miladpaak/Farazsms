<?php
/**
 * SMS API Class
 *
 * Handles SMS sending via FarazSMS API
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_SMS_API {

    /**
     * API Base URL
     */
    private $api_base_url = 'https://api.iranpayamak.com/ws/v1';

    /**
     * Normalize Iranian mobile number to local format (09xxxxxxxxx).
     *
     * @param string $mobile
     * @return string
     */
    private function normalize_mobile($mobile) {
        if (function_exists('wto_normalize_phone')) {
            return wto_normalize_phone($mobile);
        }
        return (string) $mobile;
    }

    /**
     * Normalize sender line to plain latin digits for API validation.
     *
     * @param string|int $line
     * @return string
     */
    private function normalize_sender_line($line) {
        if (function_exists('wto_normalize_sender_line')) {
            return wto_normalize_sender_line($line);
        }
        $line = trim((string) $line);
        $line = str_replace(
            array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'),
            array('0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'),
            $line
        );
        return preg_replace('/\D+/', '', $line);
    }

    /**
     * Send SMS
     *
     * @param string $mobile Mobile number
     * @param string $message Message content
     * @param string $sender Sender number
     * @param string $api_key API key
     * @return array|false Response data or false on error
     */
    public function send_sms($mobile, $message, $sender = '', $api_key = '') {
        if (empty($api_key)) {
            // Get API key from unified settings
            if (function_exists('wto_get_apikey')) {
                $api_key = wto_get_apikey();
            } else {
                $api_key = get_option('wto_apikey', '');
            }
        }

        if (empty($api_key)) {
            return false;
        }

        $mobile = $this->normalize_mobile($mobile);

        if (!function_exists('curl_init')) {
            return false;
        }

        $curl = curl_init();

        // Prepare request body
        $body = array(
            'mobile' => sanitize_text_field($mobile),
            'message' => sanitize_text_field($message),
        );

        $sender = $this->normalize_sender_line($sender);
        if (!empty($sender)) {
            $body['sender'] = sanitize_text_field($sender);
        }

        $curl_options = array(
            CURLOPT_URL => $this->api_base_url . '/send/sms',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Api-Key: ' . $api_key,
                'Content-Type: application/json',
            ),
        );

        curl_setopt_array($curl, $curl_options);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);

        curl_close($curl);

        if ($curl_error) {
            return false;
        }

        if ($http_code !== 200) {
            return false;
        }

        $response_data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (isset($response_data['status']) && $response_data['status'] === 'success') {
            return $response_data;
        }

        return false;
    }

    /**
     * Make generic API request
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $data Request data
     * @param string $api_key API key
     * @return array Response data (success or error)
     */
    private function make_request($endpoint, $method = 'GET', $data = array(), $api_key = '') {
        if (empty($api_key)) {
            // Get API key from unified settings
            if (function_exists('wto_get_apikey')) {
                $api_key = wto_get_apikey();
            } else {
                $api_key = get_option('wto_apikey', '');
            }
        }

        if (empty($api_key)) {
            return array(
                'status' => 'error',
                'error_code' => 'missing_api_key',
                'message' => __('کلید دسترسی API تنظیم نشده است.', 'farazsms-next'),
            );
        }

        if (!function_exists('curl_init')) {
            return array(
                'status' => 'error',
                'error_code' => 'curl_unavailable',
                'message' => __('تابع cURL در سرور در دسترس نیست.', 'farazsms-next'),
            );
        }

        $curl = curl_init();

        $url = $this->api_base_url . '/' . ltrim($endpoint, '/');

        $curl_options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Api-Key: ' . $api_key,
                'Content-Type: application/json',
            ),
        );

        if (($method === 'POST' || $method === 'PUT') && !empty($data)) {
            $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            $curl_options[CURLOPT_URL] = $url;
        }

        curl_setopt_array($curl, $curl_options);

        $response = curl_exec($curl);
       
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);

        curl_close($curl);

        if ($curl_error) {
            return array(
                'status' => 'error',
                'error_code' => 'curl_error',
                'message' => $curl_error,
            );
        }

        if ($http_code !== 200 && $http_code !== 201) {
            $response_data = json_decode($response, true);
            $error_message = __('خطای نامشخص از وب سرویس.', 'farazsms-next');
            if (is_array($response_data)) {
                if (!empty($response_data['message'])) {
                    $error_message = is_array($response_data['message']) ? wp_json_encode($response_data['message'], JSON_UNESCAPED_UNICODE) : $response_data['message'];
                } elseif (!empty($response_data['data']['message'])) {
                    $error_message = is_array($response_data['data']['message']) ? wp_json_encode($response_data['data']['message'], JSON_UNESCAPED_UNICODE) : $response_data['data']['message'];
                }
            }
            $error_code = self::is_pattern_not_found_response($response_data, $http_code) ? 'pattern_not_found' : 'http_error';
            return array(
                'status' => 'error',
                'error_code' => $error_code,
                'http_code' => (int) $http_code,
                'message' => $error_message,
                'raw_response' => $response,
            );
        }

        $response_data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'status' => 'error',
                'error_code' => 'invalid_json',
                'message' => __('فرمت پاسخ وب سرویس معتبر نیست.', 'farazsms-next'),
                'raw_response' => $response,
            );
        }

        return $response_data;
    }

    /**
     * Check if API response indicates missing/deleted pattern.
     *
     * @param mixed $response_data
     * @param int $http_code
     * @return bool
     */
    public static function is_pattern_not_found_response($response_data, $http_code = 0) {
        if ((int) $http_code === 404) {
            return true;
        }

        if (!is_array($response_data)) {
            return false;
        }

        if (!empty($response_data['error_code']) && $response_data['error_code'] === 'pattern_not_found') {
            return true;
        }

        $messages = array();
        if (isset($response_data['message'])) {
            $messages[] = $response_data['message'];
        }
        if (isset($response_data['messages'])) {
            $messages[] = $response_data['messages'];
        }
        if (isset($response_data['data']['message'])) {
            $messages[] = $response_data['data']['message'];
        }

        foreach ($messages as $message) {
            if (is_array($message)) {
                $message = wp_json_encode($message, JSON_UNESCAPED_UNICODE);
            }
            $message = is_string($message) ? strtolower(trim($message)) : '';
            if ($message === '') {
                continue;
            }
            if (strpos($message, 'pattern not found') !== false || strpos($message, 'not found') !== false || strpos($message, 'پترن یافت نشد') !== false || strpos($message, 'پترن وجود ندارد') !== false || strpos($message, 'الگو یافت نشد') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create Pattern
     *
     * @param string $text Pattern text (with %var% placeholders)
     * @param bool $shared Whether pattern is shared (1 for true, 0 for false)
     * @param string $website Website URL
     * @param array $vars Pattern variables (each with var, length, type)
     * @param string $description Pattern description (optional)
     * @param int $category Pattern category: 1=otp, 2=club, 3=order, 255=others (default 255)
     * @param string $api_key API key
     * @return array|false Response data or false on error
     */
    public function create_pattern($text, $shared = false, $website = '', $vars = array(), $description = '', $category = 255, $api_key = '') {
        if (empty($api_key)) {
            // Get API key from unified settings
            if (function_exists('wto_get_apikey')) {
                $api_key = wto_get_apikey();
            } else {
                $api_key = get_option('wto_apikey', '');
            }
        }

        if (empty($api_key)) {
            return false;
        }

        if (empty($website)) {
            $website = home_url();
        }

        if (empty(trim($text))) {
            return false;
        }
        
        $data = array(
            'text' => $text,
            'share' => $shared ? 1 : 0,
            'website' => esc_url_raw($website),
            'vars' => $vars
        );

        if (!empty($description)) {
            $data['description'] = sanitize_text_field($description);
        }

        $valid_categories = array( 1, 2, 3, 255 );
        if ( in_array( (int) $category, $valid_categories, true ) ) {
            $data['category'] = (int) $category;
        }

        return $this->make_request('patterns', 'POST', $data, $api_key);
    }

    /**
     * Update Pattern
     *
     * @param string $code Pattern code to update
     * @param string $text Pattern text (with %var% placeholders)
     * @param bool $shared Whether pattern is shared (1 for true, 0 for false)
     * @param string $website Website URL
     * @param array $vars Pattern variables (each with var, length, type)
     * @param string $description Pattern description (optional)
     * @param int|null $category Pattern category: 1=otp, 2=club, 3=order, 255=others (optional)
     * @param string $api_key API key
     * @return array|false Response data or false on error
     */
    public function update_pattern($code, $text, $shared = false, $website = '', $vars = array(), $description = '', $category = null, $api_key = '') {
        if (empty($api_key)) {
            if (function_exists('wto_get_apikey')) {
                $api_key = wto_get_apikey();
            } else {
                $api_key = get_option('wto_apikey', '');
            }
        }

        if (empty($api_key) || empty(trim($code)) || empty(trim($text))) {
            return false;
        }

        if (empty($website)) {
            $website = home_url();
        }

        $data = array(
            'text' => $text,
            'share' => $shared ? 1 : 0,
            'website' => esc_url_raw($website),
            'vars' => $vars
        );

        if (!empty($description)) {
            $data['description'] = sanitize_text_field($description);
        }

        $valid_categories = array( 1, 2, 3, 255 );
        if ( $category !== null && in_array( (int) $category, $valid_categories, true ) ) {
            $data['category'] = (int) $category;
        }

        return $this->make_request('patterns/' . sanitize_text_field($code), 'PUT', $data, $api_key);
    }

    /**
     * Get Pattern Details
     *
     * @param string $code Pattern code
     * @param string $api_key API key
     * @return array|false Response data or false on error
     */
    public function get_pattern_details($code, $api_key = '') {
        if (empty($api_key)) {
            // Get API key from unified settings
            if (function_exists('wto_get_apikey')) {
                $api_key = wto_get_apikey();
            } else {
                $api_key = get_option('wto_apikey', '');
            }
        }

        if (empty($api_key)) {
            return false;
        }

        return $this->make_request('patterns/' . sanitize_text_field($code), 'GET', array(), $api_key);
    }

    /**
     * Send Pattern-Based SMS
     *
     * @param string $mobile Mobile number (recipient)
     * @param string $pattern_code Pattern code
     * @param array $attributes Array of pattern variables (var1 => value1, var2 => value2, ...)
     * @param string $line_number Line number (sender)
     * @param string $number_format Number format (e.g. 'english' or 'persian' based on API docs)
     * @param string $api_key API key
     * @return array|false Response data or false on error
     */
    public function send_pattern_sms($mobile, $pattern_code, $attributes = array(), $line_number = '', $number_format = 'en', $api_key = '') {
        if (empty($api_key)) {
            // Get API key from unified settings
            if (function_exists('wto_get_apikey')) {
                $api_key = wto_get_apikey();
            } else {
                $api_key = get_option('wto_apikey', '');
            }
        }

        if (empty($api_key)) {
            return false;
        }

        if (empty($pattern_code)) {
            return false;
        }

        if (empty($mobile)) {
            return false;
        }

        $mobile = $this->normalize_mobile($mobile);

        // اگر mobile شامل کاما باشد، شماره‌ها را جدا کن و به صورت جداگانه ارسال کن
        if (strpos($mobile, ',') !== false) {
            $mobiles = explode(',', $mobile);
            $mobiles = array_map('trim', $mobiles);
            $mobiles = array_filter($mobiles);
            $mobiles = array_map(array($this, 'normalize_mobile'), $mobiles);
            
            if (empty($mobiles)) {
                return false;
            }
            
            $results = array();
            $success_count = 0;
            $fail_count = 0;
            $errors = array();
            
            foreach ($mobiles as $single_mobile) {
                $result = $this->send_pattern_sms($single_mobile, $pattern_code, $attributes, $line_number, $number_format, $api_key);
                if ($result && isset($result['status']) && $result['status'] === 'success') {
                    $success_count++;
                    $results[] = array('mobile' => $single_mobile, 'status' => 'success');
                } else {
                    $fail_count++;
                    $error_msg = __('خطای نامشخص', 'farazsms-next');
                    if (isset($result['message'])) {
                        $error_msg = is_array($result['message']) ? json_encode($result['message'], JSON_UNESCAPED_UNICODE) : $result['message'];
                    } elseif (isset($result['data']['message'])) {
                        $error_msg = is_array($result['data']['message']) ? json_encode($result['data']['message'], JSON_UNESCAPED_UNICODE) : $result['data']['message'];
                    }
                    $errors[] = sprintf(__('شماره %s: %s', 'farazsms-next'), $single_mobile, $error_msg);
                    $results[] = array('mobile' => $single_mobile, 'status' => 'error', 'error' => $error_msg);
                }
            }
            
            // برگرداندن نتیجه کلی
            if ($success_count > 0 && $fail_count == 0) {
                return array('status' => 'success', 'results' => $results);
            } elseif ($success_count > 0) {
                return array('status' => 'partial', 'success_count' => $success_count, 'fail_count' => $fail_count, 'message' => implode(' | ', $errors), 'results' => $results);
            } else {
                return array('status' => 'error', 'message' => __('همه پیامک‌ها با خطا مواجه شدند', 'farazsms-next') . ': ' . implode(' | ', $errors), 'results' => $results);
            }
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $curl = curl_init();

        $attributes_formatted = array();
        if (is_array($attributes)) {
            foreach ($attributes as $key => $value) {
                $attributes_formatted[$key] = (string)$value;
            }
        }
        
        $normalized_format = strtolower(trim($number_format));
        switch ($normalized_format) {
            case 'fa':
            case 'farsi':
            case 'persian':
                $api_number_format = 'persian';
                break;
            case 'en':
            case 'english':
            default:
                $api_number_format = 'english';
                break;
        }

        $body = array(
            'code' => sanitize_text_field($pattern_code),
            'recipient' => sanitize_text_field($mobile),
            'attributes' => $attributes_formatted,
            'number_format' => $api_number_format,
        );

        $line_number = $this->normalize_sender_line($line_number);
        if (!empty($line_number)) {
            $body['line_number'] = sanitize_text_field($line_number);
        }

        $url = $this->api_base_url . '/sms/pattern';

        $curl_options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Api-Key: ' . $api_key,
                'Content-Type: application/json',
            ),
        );

        curl_setopt_array($curl, $curl_options);

        $response = curl_exec($curl);
       
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);

        curl_close($curl);

        if ($curl_error) {
            return false;
        }

        if ($http_code !== 200 && $http_code !== 201) {
            $response_data = json_decode($response, true);
            $message = '';
            if (is_array($response_data)) {
                if (isset($response_data['message'])) {
                    $message = is_array($response_data['message']) ? wp_json_encode($response_data['message'], JSON_UNESCAPED_UNICODE) : (string) $response_data['message'];
                } elseif (isset($response_data['data']['message'])) {
                    $message = is_array($response_data['data']['message']) ? wp_json_encode($response_data['data']['message'], JSON_UNESCAPED_UNICODE) : (string) $response_data['data']['message'];
                }
            }
            if (!empty($body['line_number']) && stripos($message, 'line number') !== false) {
                unset($body['line_number']);
                $retry = curl_init();
                $curl_options[CURLOPT_POSTFIELDS] = json_encode($body);
                curl_setopt_array($retry, $curl_options);
                $retry_response = curl_exec($retry);
                $retry_http_code = curl_getinfo($retry, CURLINFO_HTTP_CODE);
                $retry_error = curl_error($retry);
                curl_close($retry);
                if (!$retry_error && ($retry_http_code === 200 || $retry_http_code === 201)) {
                    $retry_data = json_decode($retry_response, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $retry_data;
                    }
                }
            }
            return false;
        }

        $response_data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $response_data;
    }
}
