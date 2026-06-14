<?php
/**
 * Phonebook API Class
 *
 * Handles API calls to FarazSMS Phonebook service
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Phonebook_API {

    /**
     * API Base URL
     */
    private $api_base_url = 'https://api.iranpayamak.com/ws/v1';

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
     * Make API request using cURL
     *
     * @param string $url API endpoint URL
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array $data Request data
     * @param string $api_key API key
     * @return array|false Response data or false on error
     */
    protected function make_request($url, $method = 'GET', $data = array(), $api_key = '') {
        if (empty($api_key)) {
            return false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $curl = curl_init();

        $curl_options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Api-Key: ' . $api_key,
                'Content-Type: application/json'
            ),
        );

        if ($method === 'POST') {
            $curl_options[CURLOPT_CUSTOMREQUEST] = 'POST';
            if (!empty($data)) {
                $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        } elseif ($method === 'PUT') {
            $curl_options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            if (!empty($data)) {
                $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        } elseif ($method === 'DELETE') {
            $curl_options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($curl, $curl_options);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        
        curl_close($curl);

        if ($curl_error) {
            return array('status' => 'error', 'message' => __('خطای cURL: ', 'farazsms-next') . $curl_error);
        }

        // Decode response even if HTTP code is error
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // اگر JSON decode نشد، پاسخ خام را برگردان
            return array('status' => 'error', 'message' => __('پاسخ نامعتبر از API: ', 'farazsms-next') . substr($response, 0, 200));
        }

        // اگر HTTP code خطا بود اما JSON decode شد، data را برگردان (شامل پیام خطا)
        if ($http_code >= 400) {
            // اگر data شامل status نبود، اضافه کن
            if (!isset($data['status'])) {
                $data['status'] = 'error';
            }
            return $data;
        }

        return $data;
    }

    /**
     * Create a new phonebook
     *
     * @param string $name Phonebook name
     * @param string $api_key API key
     * @param array $attributes Optional attributes array
     * @return array|false Phonebook data or false on error
     */
    public function create_phonebook($name, $api_key, $attributes = null) {
        if (empty($name) || empty($api_key)) {
            return false;
        }

        $url = $this->api_base_url . '/phone_book';

        $body = array(
            'title' => $name,
        );

        if ($attributes !== null && is_array($attributes)) {
            $body['attributes'] = $attributes;
        }

        $data = $this->make_request($url, 'POST', $body, $api_key);
             
        if (!$data || !is_array($data)) {
            return false;
        }

        // بررسی خطا در پاسخ
        if (isset($data['status']) && $data['status'] === 'error') {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            return array('error' => $error_message);
        }

        if (empty($data)) {
            return false;
        }

        if (isset($data['status']) && $data['status'] !== 'success') {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            return array('error' => $error_message);
        }

        if (isset($data['status']) && $data['status'] === 'success') {
            return $data;
        }

        if (isset($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    /**
     * Add contact to phonebook
     *
     * @param int $phonebook_id Phonebook ID
     * @param string $name Contact name
     * @param string $phone Contact phone number
     * @param string $api_key API key
     * @param string $prefix Optional prefix (man | woman | co | org). Default: 'man'
     * @param array $attributes Optional attributes array
     * @return array Array with 'success' (bool) and 'error' (string) keys
     */
    public function add_contact($phonebook_id, $name, $phone, $api_key, $prefix = 'man', $attributes = array()) {
        if (empty($phonebook_id) || empty($name) || empty($phone) || empty($api_key)) {
            return array('success' => false, 'error' => __('پارامترهای ورودی ناقص است.', 'farazsms-next'));
        }

        // Correct endpoint according to documentation
        $url = $this->api_base_url . '/phone_book_data';

        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // بررسی اعتبار شماره تلفن
        if (empty($phone) || strlen($phone) < 10) {
            return array('success' => false, 'error' => __('شماره تلفن نامعتبر است.', 'farazsms-next'));
        }
        
        if (empty($prefix)) {
            $prefix = 'man';
        }

        $body = array(
            'phone_book_id' => intval($phonebook_id),
            'prefix' => $prefix,
            'mobile' => $phone,
            'name' => $name,
        );

        // Add attributes if provided
        if (!empty($attributes) && is_array($attributes)) {
            $body['attributes'] = $attributes;
        }

        $data = $this->make_request($url, 'POST', $body, $api_key);

        if (!$data || !is_array($data)) {
            return array('success' => false, 'error' => __('پاسخ از API دریافت نشد.', 'farazsms-next'));
        }

        // بررسی status در پاسخ
        if (isset($data['status']) && $data['status'] === 'success') {
            return array('success' => true);
        }

        // استخراج پیام خطا از پاسخ API
        $error_message = __('خطای نامشخص از API', 'farazsms-next');
        if (isset($data['message'])) {
            $error_message = is_array($data['message']) ? json_encode($data['message'], JSON_UNESCAPED_UNICODE) : $data['message'];
        } elseif (isset($data['data']['message'])) {
            $error_message = is_array($data['data']['message']) ? json_encode($data['data']['message'], JSON_UNESCAPED_UNICODE) : $data['data']['message'];
        } elseif (isset($data['error'])) {
            $error_message = is_array($data['error']) ? json_encode($data['error'], JSON_UNESCAPED_UNICODE) : $data['error'];
        }

        return array('success' => false, 'error' => $error_message);
    }

    /**
     * نرمال‌سازی شماره برای API دفترچه (09xxxxxxxxx).
     *
     * @param string $phone
     * @return string
     */
    public function normalize_contact_mobile($phone) {
        $phone = (string) $phone;
        if (function_exists('wto_normalize_phone')) {
            $phone = wto_normalize_phone($phone);
        }
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (preg_match('/^09\d{9}$/', $phone)) {
            return $phone;
        }
        return '';
    }

    /**
     * ساخت CSV برای bulk-upsert (بدون وابستگی به اکسل).
     *
     * @param array $contacts هر آیتم: mobile, name, prefix
     * @return string
     */
    public function build_contacts_import_csv(array $contacts) {
        $lines = array('prefix,mobile,name');
        foreach ($contacts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mobile = isset($row['mobile']) ? (string) $row['mobile'] : '';
            $name   = isset($row['name']) ? (string) $row['name'] : '';
            $prefix = isset($row['prefix']) ? (string) $row['prefix'] : 'man';
            if ($mobile === '') {
                continue;
            }
            if (!in_array($prefix, array('man', 'woman', 'co', 'org'), true)) {
                $prefix = 'man';
            }
            $name = str_replace(array('"', "\r", "\n"), '', $name);
            $lines[] = $prefix . ',' . $mobile . ',"' . $name . '"';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * درخواست multipart به API (برای bulk-upsert).
     *
     * @param string $url
     * @param array  $fields
     * @param string $file_field
     * @param string $file_path
     * @param string $file_name
     * @param string $api_key
     * @param string $mime
     * @return array|false
     */
    protected function make_multipart_request($url, array $fields, $file_field, $file_path, $file_name, $api_key, $mime = 'text/csv') {
        if (empty($api_key) || !function_exists('curl_init') || !is_readable($file_path)) {
            return false;
        }

        $post_fields = $fields;
        if (class_exists('CURLFile')) {
            $post_fields[$file_field] = new CURLFile($file_path, $mime, $file_name);
        } else {
            $post_fields[$file_field] = '@' . $file_path;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Api-Key: ' . $api_key,
            ),
            CURLOPT_TIMEOUT => 300,
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if ($curl_error) {
            return array('status' => 'error', 'message' => __('خطای cURL: ', 'farazsms-next') . $curl_error);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('status' => 'error', 'message' => __('پاسخ نامعتبر از API: ', 'farazsms-next') . substr((string) $response, 0, 200));
        }
        if ($http_code >= 400 && !isset($data['status'])) {
            $data['status'] = 'error';
        }
        return $data;
    }

    /**
     * آماده‌سازی آیتم‌های bulk-upsert برای API (فیلد items).
     *
     * @param array $rows
     * @return array
     */
    protected function format_bulk_upsert_items(array $rows) {
        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['mobile'])) {
                continue;
            }
            $prefix = isset($row['prefix']) ? (string) $row['prefix'] : 'man';
            if (!in_array($prefix, array('man', 'woman', 'co', 'org'), true)) {
                $prefix = 'man';
            }
            $items[] = array(
                'prefix' => $prefix,
                'mobile' => (string) $row['mobile'],
                'name'   => isset($row['name']) ? (string) $row['name'] : '',
            );
        }
        return $items;
    }

    /**
     * استخراج پیام خطا از پاسخ API.
     *
     * @param array $response
     * @return string
     */
    protected function extract_api_error_message(array $response) {
        if (isset($response['message'])) {
            $msg = $response['message'];
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
            if (is_array($msg)) {
                return wp_json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
        }
        if (isset($response['messages'])) {
            $msg = $response['messages'];
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
            if (is_array($msg)) {
                return wp_json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
        }
        return __('خطای API در import گروهی', 'farazsms-next');
    }

    /**
     * افزودن/به‌روزرسانی گروهی مخاطبین با POST /phone_book_data/bulk-upsert (JSON + items)
     *
     * @param int    $phonebook_id
     * @param array  $contacts آرایه‌ای از ['phone'|'mobile', 'name', 'prefix'?]
     * @param string $api_key
     * @param int    $chunk_size
     * @return array success, imported, error?, chunks?
     */
    public function bulk_upsert_contacts($phonebook_id, array $contacts, $api_key, $chunk_size = 500) {
        $phonebook_id = (int) $phonebook_id;
        if ($phonebook_id <= 0 || empty($api_key)) {
            return array(
                'success' => false,
                'imported' => 0,
                'error' => __('پارامترهای ورودی ناقص است.', 'farazsms-next'),
            );
        }

        $by_mobile = array();
        foreach ($contacts as $c) {
            if (!is_array($c)) {
                continue;
            }
            $raw_phone = isset($c['phone']) ? $c['phone'] : (isset($c['mobile']) ? $c['mobile'] : '');
            $mobile = $this->normalize_contact_mobile($raw_phone);
            if ($mobile === '') {
                continue;
            }
            $name = isset($c['name']) ? trim((string) $c['name']) : '';
            if ($name === '') {
                $name = __('کاربر', 'farazsms-next');
            }
            $prefix = isset($c['prefix']) ? (string) $c['prefix'] : 'man';
            $by_mobile[$mobile] = array(
                'mobile' => $mobile,
                'name' => $name,
                'prefix' => $prefix,
            );
        }

        $prepared = array_values($by_mobile);
        if (empty($prepared)) {
            return array(
                'success' => false,
                'imported' => 0,
                'error' => __('هیچ شماره معتبری برای import یافت نشد.', 'farazsms-next'),
            );
        }

        $chunks = array_chunk($prepared, max(50, (int) $chunk_size));
        $url = $this->api_base_url . '/phone_book_data/bulk-upsert';
        $total_imported = 0;
        $chunk_count = 0;

        foreach ($chunks as $chunk) {
            $items = $this->format_bulk_upsert_items($chunk);
            if (empty($items)) {
                continue;
            }

            $body = array(
                'phone_book_id' => $phonebook_id,
                'items'         => $items,
            );

            $response = $this->make_request($url, 'POST', $body, $api_key);

            if (!is_array($response)) {
                return array(
                    'success' => false,
                    'imported' => $total_imported,
                    'error' => __('پاسخ از API دریافت نشد.', 'farazsms-next'),
                );
            }
            if (isset($response['status']) && $response['status'] === 'error') {
                return array(
                    'success' => false,
                    'imported' => $total_imported,
                    'error' => $this->extract_api_error_message($response),
                );
            }

            $total_imported += count($items);
            $chunk_count++;
        }

        return array(
            'success' => true,
            'imported' => $total_imported,
            'chunks' => $chunk_count,
        );
    }

    /**
     * Get list of phonebooks
     *
     * @param string $api_key API key
     * @return array|false List of phonebooks or false on error
     */
    public function get_phonebooks($api_key) {
        if (empty($api_key)) {
            return false;
        }

        $url = $this->api_base_url . '/phone_book';

        $data = $this->make_request($url, 'GET', array(), $api_key);

        if (!$data || !is_array($data)) {
            return false;
        }

        // بررسی خطا در پاسخ
        if (isset($data['status']) && $data['status'] === 'error') {
            return false;
        }

        return $data;
    }

    /**
     * Get list of contacts from phonebook
     *
     * @param int $phonebook_id Phonebook ID
     * @param string $api_key API key
     * @return array|false Array of normalized mobile numbers or false on error
     */
    public function get_phonebook_contacts($phonebook_id, $api_key) {
        if (empty($phonebook_id) || empty($api_key)) {
            return false;
        }

        $all_mobile_numbers = array();
        $page = 1;
        $has_more = true;

        while ($has_more) {
            $url = $this->api_base_url . '/phone_book_data';
            $url .= '?phone_book_id=' . intval($phonebook_id) . '&page=' . $page;
            
            $data = $this->make_request($url, 'GET', array(), $api_key);

            if (!$data || !is_array($data)) {
                break;
            }

            // بررسی خطا در پاسخ
            if (isset($data['status']) && $data['status'] === 'error') {
                break;
            }

            $contacts = array();
            
            if (isset($data['data']['phone_book_data']['data']) && is_array($data['data']['phone_book_data']['data'])) {
                $contacts = $data['data']['phone_book_data']['data'];
            } elseif (isset($data['data']['data']) && is_array($data['data']['data'])) {
                $contacts = $data['data']['data'];
            } elseif (isset($data['data']['items']) && is_array($data['data']['items'])) {
                $contacts = $data['data']['items'];
            }

            foreach ($contacts as $contact) {
                if (isset($contact['mobile'])) {
                    $normalized = preg_replace('/[^0-9]/', '', $contact['mobile']);
                    if (!empty($normalized)) {
                        $all_mobile_numbers[] = $normalized;
                    }
                }
            }

            $has_more = false;
            if (isset($data['data']['phone_book_data']['last_page']) && $page < intval($data['data']['phone_book_data']['last_page'])) {
                $has_more = true;
                $page++;
            } elseif (isset($data['data']['phone_book_data']['next_page_url']) && !empty($data['data']['phone_book_data']['next_page_url'])) {
                $has_more = true;
                $page++;
            } elseif (isset($data['data']['last_page']) && $page < intval($data['data']['last_page'])) {
                $has_more = true;
                $page++;
            } elseif (isset($data['data']['next_page_url']) && !empty($data['data']['next_page_url'])) {
                $has_more = true;
                $page++;
            }
        }

        return $all_mobile_numbers;
    }

    /**
     * Get phonebook contacts count
     *
     * @param int $phonebook_id Phonebook ID
     * @param string $api_key API key
     * @return int|false Contacts count or false on error
     */
    public function get_phonebook_contacts_count($phonebook_id, $api_key) {
        if (empty($phonebook_id) || empty($api_key)) {
            return false;
        }

        $url = $this->api_base_url . '/phone_book_data';
        $url .= '?phone_book_id=' . intval($phonebook_id);
        
        $data = $this->make_request($url, 'GET', array(), $api_key);

        if (!$data) {
            return false;
        }

        if (isset($data['data']['phone_book_data']['total'])) {
            return intval($data['data']['phone_book_data']['total']);
        } elseif (isset($data['data']['phone_book_data']['totalItems'])) {
            return intval($data['data']['phone_book_data']['totalItems']);
        } elseif (isset($data['data']['phone_book_data']['total_items'])) {
            return intval($data['data']['phone_book_data']['total_items']);
        } elseif (isset($data['data']['totalItems'])) {
            return intval($data['data']['totalItems']);
        } elseif (isset($data['data']['total_items'])) {
            return intval($data['data']['total_items']);
        } elseif (isset($data['data']['total'])) {
            return intval($data['data']['total']);
        } elseif (isset($data['data']['phone_book_data']['data']) && is_array($data['data']['phone_book_data']['data'])) {
            return count($data['data']['phone_book_data']['data']);
        } elseif (isset($data['data']['items']) && is_array($data['data']['items'])) {
            return count($data['data']['items']);
        } elseif (isset($data['data']['data']) && is_array($data['data']['data'])) {
            return count($data['data']['data']);
        }

        return false;
    }

    /**
     * لیست خطوط ارسال (چند مسیر رایج API؛ اولین پاسخ معتبر برگردانده می‌شود)
     *
     * @param string $api_key
     * @return array آرایه‌ای از [ 'line_number' => string, 'title' => string ]
     */
    public function get_sender_lines_normalized($api_key) {
        if (empty($api_key)) {
            return array();
        }
        $paths = array('line', 'lines', 'account/lines', 'sms/lines');
        foreach ($paths as $path) {
            $data = $this->make_request($this->api_base_url . '/' . $path, 'GET', array(), $api_key);
            if (!is_array($data) || (isset($data['status']) && $data['status'] === 'error')) {
                continue;
            }
            $items = array();
            if (isset($data['data']['items']) && is_array($data['data']['items'])) {
                $items = $data['data']['items'];
            } elseif (isset($data['data']['data']) && is_array($data['data']['data'])) {
                $items = $data['data']['data'];
            } elseif (isset($data['data']) && is_array($data['data']) && !empty($data['data']) && isset($data['data'][0])) {
                $items = $data['data'];
            }
            $out = array();
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $num = '';
                if (isset($row['number'])) {
                    $num = $row['number'];
                } elseif (isset($row['line_number'])) {
                    $num = $row['line_number'];
                } elseif (isset($row['line'])) {
                    $num = $row['line'];
                }
                $num = is_scalar($num) ? (string) $num : '';
                if ($num !== '') {
                    $title = isset($row['title']) ? (string) $row['title'] : $num;
                    $out[] = array('line_number' => $num, 'title' => $title);
                }
            }
            if (!empty($out)) {
                return $out;
            }
        }
        return array();
    }

    /**
     * ارسال پیامک ساده به لیست گیرندگان (خرد‌بندی خودکار)
     *
     * @param string|int $line_number
     * @param string     $message
     * @param array      $recipients شماره‌ها (فقط رقم)
     * @param string     $api_key
     * @param int        $chunk_size
     * @return array آخرین پاسخ API یا خطا
     */
    public function send_simple_sms_to_recipients($line_number, $message, array $recipients, $api_key, $chunk_size = 80) {
        $line_number = $this->normalize_sender_line($line_number);
        if (empty($api_key) || $line_number === '' || $line_number === null) {
            return array('status' => 'error', 'message' => __('خط یا کلید API نامعتبر است.', 'farazsms-next'));
        }
        $message = (string) $message;
        if ($message === '') {
            return array('status' => 'error', 'message' => __('متن پیام خالی است.', 'farazsms-next'));
        }
        $clean = array();
        foreach ($recipients as $r) {
            $n = preg_replace('/[^0-9]/', '', (string) $r);
            if (strlen($n) >= 10) {
                $clean[$n] = $n;
            }
        }
        $clean = array_values($clean);
        if (empty($clean)) {
            return array('status' => 'error', 'message' => __('هیچ شماره معتبری یافت نشد.', 'farazsms-next'));
        }
        $chunks = array_chunk($clean, max(1, (int) $chunk_size));
        $last = null;
        foreach ($chunks as $chunk) {
            $body = array(
                'text' => $message,
                'line_number' => $line_number,
                'recipients' => $chunk,
                'number_format' => 'english',
            );
            $last = $this->make_request($this->api_base_url . '/sms/simple', 'POST', $body, $api_key);
            if (is_array($last) && isset($last['status']) && $last['status'] === 'error') {
                return $last;
            }
            usleep(100000);
        }
        return $last ? $last : array('status' => 'success');
    }

    /**
     * ارسال گروهی به همه مخاطبان یک دفترچه (از طریق خواندن مخاطبین و sms/simple)
     *
     * @param int    $phonebook_id
     * @param mixed  $line_number
     * @param string $message
     * @param string $api_key
     * @return array
     */
    public function send_simple_sms_to_phonebook($phonebook_id, $line_number, $message, $api_key) {
        $recipients = $this->get_phonebook_contacts($phonebook_id, $api_key);
        if ($recipients === false || empty($recipients)) {
            return array('status' => 'error', 'message' => __('دفترچه خالی است یا خطا در خواندن مخاطبین.', 'farazsms-next'));
        }
        return $this->send_simple_sms_to_recipients($line_number, $message, $recipients, $api_key);
    }
}
