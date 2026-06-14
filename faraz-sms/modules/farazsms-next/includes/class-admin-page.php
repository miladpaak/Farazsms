<?php
/**
 * Admin Page Class
 *
 * Handles the admin settings page with tabs
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Admin_Page {

    /**
     * Current active tab
     *
     * @var string
     */
    private $current_tab;

    /**
     * Available tabs
     *
     * @var array
     */
    private $tabs = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->tabs = array(
            'panel-settings' => __('تنظیمات پنل پیامکی', 'farazsms-next'),
            'phonebook' => __('دفترچه تلفن', 'farazsms-next'),
            'lead-magnet' => __('لید مگنت', 'farazsms-next'),
            'gravity-forms' => __('تنظیمات گرویتی فرم', 'farazsms-next'),
            'elementor' => __('تنظیمات المنتور', 'farazsms-next'),
        );

        // Get current tab from URL
        $this->current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'panel-settings';

        // Validate tab
        if (!array_key_exists($this->current_tab, $this->tabs)) {
            $this->current_tab = 'panel-settings';
        }

        // Handle form submissions
        add_action('admin_init', array($this, 'handle_form_submission'));
        
        // Register AJAX handlers
        add_action('wp_ajax_farazsms_next_create_phonebook_from_woocommerce', array($this, 'ajax_create_phonebook_from_woocommerce'));
        add_action('wp_ajax_farazsms_next_sync_custom_meta_phonebook', array($this, 'ajax_sync_custom_meta_phonebook'));
        add_action('wp_ajax_farazsms_next_phonebook_marketing_data', array($this, 'ajax_phonebook_marketing_data'));
        add_action('wp_ajax_farazsms_next_send_bulk_phonebook_sms', array($this, 'ajax_send_bulk_phonebook_sms'));
    }

    /**
     * عنوان ثابت دفترچه ووکامرس در API (یکسان در تمام افزونه)
     */
    public static function woocommerce_phonebook_title() {
        return 'مشتریان ووکامرس';
    }

    /**
     * Handle form submission
     */
    public function handle_form_submission() {
        if (!isset($_POST['farazsms_next_settings_submit'])) {
            return;
        }

        // Verify nonce
        if (!isset($_POST['farazsms_next_settings_nonce']) || 
            !wp_verify_nonce($_POST['farazsms_next_settings_nonce'], 'farazsms_next_settings')) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get current tab
        $tab = isset($_POST['current_tab']) ? sanitize_text_field($_POST['current_tab']) : 'panel-settings';

        // Process settings based on tab
        switch ($tab) {
            case 'panel-settings':
                $this->save_panel_settings();
                break;
            case 'lead-magnet':
                $this->save_lead_magnet_settings();
                break;
            case 'phonebook':
                $this->save_phonebook_settings();
                break;
            case 'gravity-forms':
                $this->save_gravity_forms_settings();
                break;
        }

        // Redirect to prevent resubmission
        // Map tab to page slug
        $page_slug_map = array(
            'phonebook' => 'farazwto-phonebook',
            'lead-magnet' => 'farazwto-lead-magnet',
            'panel-settings' => 'farazwto-phonebook', // Default to phonebook for panel settings
            'gravity-forms' => 'farazwto-sms-forms',
            'elementor' => 'farazwto-sms-forms'
        );
        
        $page_slug = isset($page_slug_map[$tab]) ? $page_slug_map[$tab] : 'farazwto-phonebook';
        
        wp_redirect(add_query_arg(array(
            'page' => $page_slug,
            'settings-updated' => 'true'
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Get API key from unified settings
     *
     * @return string API key
     */
    private function get_api_key() {
        if (function_exists('wto_get_apikey')) {
            return wto_get_apikey();
        }
        return get_option('wto_apikey', '');
    }

    /**
     * Get WooCommerce phonebook if exists
     *
     * @return array|false Phonebook data or false if not found
     */
    public function get_woocommerce_phonebook() {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return false;
        }

        $phonebook_api = new FarazSMS_Next_Phonebook_API();
        $response = $phonebook_api->get_phonebooks($api_key);

        if (!$response) {
            return false;
        }

        // Response structure based on documentation:
        // { status: "success", data: { items: [...], ... } }
        // But actual API might return: { status: "success", data: { data: [...], ... } }
        // Check both structures
        $phonebooks = array();
        
        if (isset($response['data']['items']) && is_array($response['data']['items'])) {
            // Documentation structure: items array
            $phonebooks = $response['data']['items'];
        } elseif (isset($response['data']['data']) && is_array($response['data']['data'])) {
            // Actual API structure: data array
            $phonebooks = $response['data']['data'];
        } elseif (isset($response['data']) && is_array($response['data']) && isset($response['data'][0]) && isset($response['data'][0]['id'])) {
            // Fallback: if data is directly an array of phonebooks
            $phonebooks = $response['data'];
        } else {
            return false;
        }

        if (empty($phonebooks)) {
            return false;
        }

        $phonebook_name = self::woocommerce_phonebook_title();
        $legacy_titles    = array( $phonebook_name, 'کاربران سایت' );

        foreach ( $phonebooks as $phonebook ) {
            if ( isset( $phonebook['title'] ) && in_array( $phonebook['title'], $legacy_titles, true ) ) {
                return $phonebook;
            }
        }

        return false;
    }

    /**
     * Save panel settings
     */
    private function save_panel_settings() {
        // Save API key to unified settings
        if (isset($_POST['panel_api_key'])) {
            $api_key = sanitize_text_field(trim($_POST['panel_api_key']));
            update_option('wto_apikey', $api_key);
        }
        
        // Save sender number to unified settings
        if (isset($_POST['panel_sender_number'])) {
            $sender_number = sanitize_text_field(trim($_POST['panel_sender_number']));
            if (function_exists('wto_normalize_sender_line')) {
                $sender_number = wto_normalize_sender_line($sender_number);
            }
            $sender_number = !empty($sender_number) ? $sender_number : '90008361';
            update_option('wto_sender', $sender_number);
        } else {
            // Default value if not set
            update_option('wto_sender', '90008361');
        }
        
        // Keep farazsms_next_panel_settings for backward compatibility (optional)
        $settings = get_option('farazsms_next_panel_settings', array());
        if (isset($_POST['panel_api_key'])) {
            $settings['api_key'] = sanitize_text_field(trim($_POST['panel_api_key']));
        }
        if (isset($_POST['panel_sender_number'])) {
            $sender_number = sanitize_text_field(trim($_POST['panel_sender_number']));
            $settings['sender_number'] = !empty($sender_number) ? $sender_number : '90008361';
        } else {
            $settings['sender_number'] = '90008361';
        }
        update_option('farazsms_next_panel_settings', $settings, false);
    }

    /**
     * Save phonebook settings
     */
    private function save_phonebook_settings() {
        $prev = get_option('farazsms_next_phonebook_settings', array());
        if (!is_array($prev)) {
            $prev = array();
        }
        $settings = $prev;

        if (isset($_POST['phonebook_enabled'])) {
            $settings['enabled'] = sanitize_text_field(wp_unslash($_POST['phonebook_enabled']));
        }

        $settings['auto_add_new_wc_customers'] = isset($_POST['farazsms_auto_add_new_wc_customers']) ? '1' : '0';

        update_option('farazsms_next_phonebook_settings', $settings, false);
    }

    /**
     * Save lead magnet settings
     */
    private function save_lead_magnet_settings() {
        $settings = array();

        $settings['enabled'] = isset($_POST['lead_magnet_enabled']) ? '1' : '0';

        if (isset($_POST['lead_magnet_credit_amount'])) {
            $settings['credit_amount'] = sanitize_text_field($_POST['lead_magnet_credit_amount']);
        }

        if (isset($_POST['lead_magnet_expiry_days'])) {
            $settings['expiry_days'] = sanitize_text_field($_POST['lead_magnet_expiry_days']);
        }

        if (isset($_POST['lead_magnet_display_position'])) {
            $position = sanitize_text_field($_POST['lead_magnet_display_position']);
            // Validate position value
            if (in_array($position, array('bottom-right', 'bottom-left'))) {
                $settings['display_position'] = $position;
            } else {
                $settings['display_position'] = 'bottom-right'; // Default
            }
        } else {
            $settings['display_position'] = 'bottom-right'; // Default
        }

        // محلِ نمایش لید مگنت (همه‌جا/صفحه اصلی/بلاگ/برگه‌های خاص)
        $allowed_locations = array('everywhere', 'home', 'blog', 'specific');
        $location = isset($_POST['lead_magnet_display_location']) ? sanitize_text_field(wp_unslash($_POST['lead_magnet_display_location'])) : 'everywhere';
        $settings['display_location'] = in_array($location, $allowed_locations, true) ? $location : 'everywhere';

        // برگه‌های خاص (وقتی display_location = specific) — آرایه‌ی شناسه‌ها → رشته‌ی با کاما
        if (isset($_POST['lead_magnet_display_pages']) && is_array($_POST['lead_magnet_display_pages'])) {
            $ids = array_filter(array_map('absint', wp_unslash($_POST['lead_magnet_display_pages'])));
            $settings['display_pages'] = implode(',', $ids);
        } else {
            $settings['display_pages'] = '';
        }

        // Target page for CTA button redirect
        if (isset($_POST['lead_magnet_target_page_id'])) {
            $settings['target_page_id'] = absint($_POST['lead_magnet_target_page_id']);
        }

        // Shop name
        if (isset($_POST['lead_magnet_shop_name'])) {
            $shop_name = sanitize_text_field($_POST['lead_magnet_shop_name']);
            if (!empty($shop_name)) {
                $settings['shop_name'] = $shop_name;
            } else {
                // اگر خالی بود، از نام سایت استفاده کن
                $settings['shop_name'] = get_bloginfo('name');
            }
        } else {
            // اگر ارسال نشد، از نام سایت استفاده کن
            $settings['shop_name'] = get_bloginfo('name');
        }

        // v3.17.4: متن‌های قابل تنظیم
        $text_fields = array(
            'badge_text', 'title_template', 'headline_template',
            'disclaimer_template', 'cta_text',
        );
        foreach ( $text_fields as $field ) {
            $post_key = 'lead_magnet_' . $field;
            if ( isset( $_POST[ $post_key ] ) ) {
                // متن‌ها ممکن است شامل ایموجی باشند — sanitize_text_field کافی است
                $settings[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
            }
        }

        update_option('farazsms_next_lead_magnet_settings', $settings, false);
    }

    /**
     * Save gravity forms settings
     */
    private function save_gravity_forms_settings() {
        $settings = array();
        
        if (isset($_POST['gravity_forms_enabled'])) {
            $settings['enabled'] = sanitize_text_field($_POST['gravity_forms_enabled']);
        }

        update_option('farazsms_next_gravity_forms_settings', $settings, false);
    }

    /**
     * Get credit balance from API
     *
     * @param string $api_key API key
     * @return string|false Credit amount or false on error
     */
    public function get_credit($api_key) {
        if (empty($api_key)) {
            return false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $url = 'https://api.iranpayamak.com/ws/v1/account/balance';

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array(
                'Api-Key: ' . $api_key,
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);

        curl_close($curl);

        if ($curl_error || $response === false) {
            // اتصال برقرار نشد (مسدودسازی/قطعی) — نه «کلیدِ نامعتبر». برای نگهبانِ اتصال ثبت کن.
            if (function_exists('wto_connectivity_note_failure')) {
                wto_connectivity_note_failure($curl_error ?: 'curl: no response from balance endpoint');
            }
            return false;
        }
        // پاسخِ HTTP دریافت شد → اتصال سالم است (حتی اگر کلید نامعتبر/۴۰۱ باشد).
        if (function_exists('wto_connectivity_note_success')) {
            wto_connectivity_note_success();
        }

        if ($http_code >= 400) {
            return false;
        }

        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (empty($data) || !isset($data['status']) || $data['status'] !== 'success') {
            return false;
        }

        if (!isset($data['data']['balance_amount'])) {
            return false;
        }

        return number_format(intval($data['data']['balance_amount']));
    }

    /**
     * Render the admin page
     */
    public function render() {
        // Get panel API key for credit display
        $panel_api_key = $this->get_api_key();
        $credit = false;
        if (!empty($panel_api_key)) {
            $credit = $this->get_credit($panel_api_key);
        }
        ?>
        <section class="wrapper">
            <div id="fwss_header">
                <div></div>
                <?php if (!empty($panel_api_key) && $credit !== false) : ?>
                <div id="fwss_account_info">
                    <div class="fsms_credit_amount">
                        <span>میزان اعتبار: </span><?php echo esc_html($credit); ?>
                        <span> تومان</span>
                    </div>
                    <?php wto_render_profile_block(); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
            // Show success message
            if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
                echo '<div class="fwss_notice"><p>';
                echo __('تنظیمات با موفقیت ذخیره شد.', 'farazsms-next');
                echo '</p></div>';
            }
            ?>

            <ul class="tabs">
                <?php foreach ($this->tabs as $tab_key => $tab_label) : ?>
                    <li class="<?php echo $this->current_tab === $tab_key ? 'active' : ''; ?>" 
                        data-tab="<?php echo esc_attr($tab_key); ?>">
                        <?php echo esc_html($tab_label); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <ul class="tab__content">
                <?php $this->render_tab_content(); ?>
            </ul>
        </section>
        <?php
    }

    /**
     * Render tab content
     */
    private function render_tab_content() {
        // Render all tabs so JavaScript can switch between them without page reload
        foreach ($this->tabs as $tab_key => $tab_label) {
            $tab_file = WTO_CORE_INC . 'farazsms-next-tabs/tab-' . $tab_key . '.php';
            
            if (file_exists($tab_file)) {
                // Add active class to current tab panel
                $active_class = ($this->current_tab === $tab_key) ? 'active' : '';
                echo '<li class="' . esc_attr($active_class) . '">';
                echo '<div class="content__wrapper">';
                // Pass $this to the included file
                $admin_page = $this;
                include $tab_file;
                echo '</div>';
                echo '</li>';
            }
        }
    }

    /**
     * Get current tab
     *
     * @return string
     */
    public function get_current_tab() {
        return $this->current_tab;
    }

    /**
     * Render a specific tab content
     *
     * @param string $tab_key Tab key to render
     */
    public function render_tab($tab_key) {
        if (!array_key_exists($tab_key, $this->tabs)) {
            wp_die(__('Invalid tab.', 'farazsms-next'));
        }

        $tab_file = WTO_CORE_INC . 'farazsms-next-tabs/tab-' . $tab_key . '.php';
        
        if (file_exists($tab_file)) {
            // Pass $this to the included file
            $admin_page = $this;
            include $tab_file;
        } else {
            echo '<p>' . __('Tab file not found.', 'farazsms-next') . '</p>';
        }
    }

    /**
     * AJAX handler for creating phonebook from WooCommerce
     */
    public function ajax_create_phonebook_from_woocommerce() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'farazsms_next_phonebook_ajax')) {
            wp_send_json_error(array('message' => __('خطای امنیتی. لطفا صفحه را رفرش کنید.', 'farazsms-next')));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('شما دسترسی لازم را ندارید.', 'farazsms-next')));
        }

        // Check if WooCommerce is installed
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => __('افزونه ووکامرس نصب نیست.', 'farazsms-next')));
        }

        // Get API key from panel settings
        $api_key = $this->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('لطفا ابتدا کلید دسترسی را در تب تنظیمات پنل پیامکی وارد کنید.', 'farazsms-next')));
        }

        // Get all users (not just customers)
        $customers = get_users(array(
            'number' => -1, // همه کاربران با هر نقشی
        ));

        if (empty($customers)) {
            wp_send_json_error(array('message' => __('کاربری یافت نشد.', 'farazsms-next')));
        }

        // Initialize API class
        $phonebook_api = new FarazSMS_Next_Phonebook_API();
        $phonebook_name = self::woocommerce_phonebook_title();

        // Check if phonebook already exists
        $existing_phonebook = $this->get_woocommerce_phonebook();
        $phonebook_id = null;
        $is_update = false;

        if ($existing_phonebook && isset($existing_phonebook['id'])) {
            // Use existing phonebook
            $phonebook_id = $existing_phonebook['id'];
            $is_update = true;
        } else {
            // Create new phonebook
            $phonebook_response = $phonebook_api->create_phonebook($phonebook_name, $api_key);

            if (!$phonebook_response) {
                wp_send_json_error(array('message' => __('خطا در ایجاد دفترچه تلفن. لطفا کلید دسترسی را بررسی کنید.', 'farazsms-next')));
            }

            // Check if API returned an error
            if (isset($phonebook_response['error'])) {
                wp_send_json_error(array('message' => __('خطا از API: ', 'farazsms-next') . $phonebook_response['error']));
            }

            if (!isset($phonebook_response['status']) || $phonebook_response['status'] !== 'success') {
                wp_send_json_error(array('message' => __('خطا در ایجاد دفترچه تلفن. لطفا کلید دسترسی را بررسی کنید.', 'farazsms-next')));
            }

            // Get phonebook ID from data or fetch from list
            if (isset($phonebook_response['data']) && is_array($phonebook_response['data']) && isset($phonebook_response['data']['id'])) {
                $phonebook_id = $phonebook_response['data']['id'];
            } elseif (isset($phonebook_response['data']) && is_object($phonebook_response['data']) && isset($phonebook_response['data']->id)) {
                $phonebook_id = $phonebook_response['data']->id;
            } else {
                // If data is null, get the latest phonebook from the list
                // Wait a moment for API to update
                sleep(1);
                
                $phonebooks_response = $phonebook_api->get_phonebooks($api_key);
                
                // Extract phonebooks array from response
                // Response structure: { status: "success", data: { items: [...], ... } }
                // But actual API might return: { status: "success", data: { data: [...], ... } }
                $phonebooks = array();
                if (isset($phonebooks_response['data']['items']) && is_array($phonebooks_response['data']['items'])) {
                    // Documentation structure: items array
                    $phonebooks = $phonebooks_response['data']['items'];
                } elseif (isset($phonebooks_response['data']['data']) && is_array($phonebooks_response['data']['data'])) {
                    // Actual API structure: data array
                    $phonebooks = $phonebooks_response['data']['data'];
                } elseif (isset($phonebooks_response['data']) && is_array($phonebooks_response['data']) && isset($phonebooks_response['data'][0]) && isset($phonebooks_response['data'][0]['id'])) {
                    // Fallback: if data is directly an array of phonebooks
                    $phonebooks = $phonebooks_response['data'];
                }
     
                if (!empty($phonebooks)) {
                    // Find phonebook with matching name
                    foreach ($phonebooks as $pb) {
                        if (isset($pb['title']) && $pb['title'] === $phonebook_name) {
                            $phonebook_id = isset($pb['id']) ? $pb['id'] : null;
                            break;
                        }
                    }
                    // If not found, use the last one (most recent)
                    if (!$phonebook_id) {
                        $last_index = count($phonebooks) - 1;
                        if (isset($phonebooks[$last_index]['id'])) {
                            $phonebook_id = $phonebooks[$last_index]['id'];
                        } elseif (isset($phonebooks[0]['id'])) {
                            $phonebook_id = $phonebooks[0]['id'];
                        }
                    }
                }
            }

            if (!$phonebook_id) {
                wp_send_json_error(array('message' => __('دفترچه تلفن ایجاد شد اما شناسه آن یافت نشد. لطفا دوباره تلاش کنید.', 'farazsms-next')));
            }
        }
        @set_time_limit(300);

        $collected = $this->collect_woocommerce_user_contacts($customers, $phonebook_api);
        if (empty($collected['contacts'])) {
            wp_send_json_error(array(
                'message' => __('هیچ شماره معتبری برای import یافت نشد.', 'farazsms-next'),
            ));
        }

        $bulk = $phonebook_api->bulk_upsert_contacts($phonebook_id, $collected['contacts'], $api_key);
        if (empty($bulk['success'])) {
            $err = isset($bulk['error']) ? $bulk['error'] : __('خطا در import گروهی مخاطبین.', 'farazsms-next');
            wp_send_json_error(array('message' => $err));
        }

        $added_count = isset($bulk['imported']) ? (int) $bulk['imported'] : 0;
        $no_phone_count = $collected['no_phone_count'];
        $no_user_id_count = $collected['no_user_id_count'];
        $invalid_phone_count = $collected['invalid_phone_count'];

        $reasons = array();
        if ($no_phone_count > 0) {
            $reasons[] = sprintf(__('%d کاربر بدون شماره تلفن', 'farazsms-next'), $no_phone_count);
        }
        if ($no_user_id_count > 0) {
            $reasons[] = sprintf(__('%d کاربر بدون ID معتبر', 'farazsms-next'), $no_user_id_count);
        }
        if ($invalid_phone_count > 0) {
            $reasons[] = sprintf(__('%d شماره نامعتبر', 'farazsms-next'), $invalid_phone_count);
        }
        $reasons_text = !empty($reasons) ? ' (' . implode('، ', $reasons) . ')' : '';

        $message = $is_update
            ? sprintf(
                __('دفترچه تلفن با import گروهی بروزرسانی شد. %d مخاطب ثبت شد.%s', 'farazsms-next'),
                $added_count,
                $reasons_text
            )
            : sprintf(
                __('دفترچه تلفن ایجاد و %d مخاطب با import گروهی اضافه شد.%s', 'farazsms-next'),
                $added_count,
                $reasons_text
            );

        wp_send_json_success(array(
            'message' => $message,
            'phonebook_id' => $phonebook_id,
            'added_count' => $added_count,
            'no_phone_count' => $no_phone_count,
            'no_user_id_count' => $no_user_id_count,
            'invalid_phone_count' => $invalid_phone_count,
            'bulk_chunks' => isset($bulk['chunks']) ? (int) $bulk['chunks'] : 1,
        ));
    }

    /**
     * جمع‌آوری مخاطبین کاربران ووکامرس برای import گروهی.
     *
     * @param array                    $customers
     * @param FarazSMS_Next_Phonebook_API $phonebook_api
     * @return array
     */
    private function collect_woocommerce_user_contacts($customers, $phonebook_api) {
        $contacts = array();
        $no_phone_count = 0;
        $no_user_id_count = 0;
        $invalid_phone_count = 0;

        foreach ($customers as $customer) {
            $customer_id = is_object($customer) ? $customer->ID : (isset($customer['ID']) ? $customer['ID'] : null);
            if (!$customer_id) {
                $no_user_id_count++;
                continue;
            }

            $phone = get_user_meta($customer_id, 'billing_phone', true);
            if (empty($phone)) {
                $no_phone_count++;
                continue;
            }

            $first_name = get_user_meta($customer_id, 'billing_first_name', true);
            $last_name = get_user_meta($customer_id, 'billing_last_name', true);
            $full_name = trim($first_name . ' ' . $last_name);
            if ($full_name === '') {
                $display_name = is_object($customer) ? $customer->display_name : (isset($customer['display_name']) ? $customer['display_name'] : '');
                $full_name = !empty($display_name) ? $display_name : __('کاربر', 'farazsms-next');
            }

            if ($phonebook_api->normalize_contact_mobile($phone) === '') {
                $invalid_phone_count++;
                continue;
            }

            $contacts[] = array(
                'phone' => $phone,
                'name' => $full_name,
            );
        }

        return array(
            'contacts' => $contacts,
            'no_phone_count' => $no_phone_count,
            'no_user_id_count' => $no_user_id_count,
            'invalid_phone_count' => $invalid_phone_count,
        );
    }

    /**
     * استخراج آرایه دفترچه‌ها از پاسخ API
     *
     * @param array|false $response
     * @return array
     */
    private function extract_phonebooks_list($response) {
        if (!$response || !is_array($response)) {
            return array();
        }
        if (isset($response['data']['items']) && is_array($response['data']['items'])) {
            return $response['data']['items'];
        }
        if (isset($response['data']['data']) && is_array($response['data']['data'])) {
            return $response['data']['data'];
        }
        if (isset($response['data']) && is_array($response['data']) && isset($response['data'][0]['id'])) {
            return $response['data'];
        }
        return array();
    }

    /**
     * AJAX: داده برای فرم ارسال گروهی (دفترچه‌ها و خطوط)
     */
    public function ajax_phonebook_marketing_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'farazsms_next_phonebook_ajax')) {
            wp_send_json_error(array('message' => __('خطای امنیتی.', 'farazsms-next')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('دسترسی ندارید.', 'farazsms-next')));
        }
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('کلید API تنظیم نشده.', 'farazsms-next')));
        }
        $api = new FarazSMS_Next_Phonebook_API();
        $raw = $api->get_phonebooks($api_key);
        $phonebooks = $this->extract_phonebooks_list($raw);
        $list = array();
        foreach ($phonebooks as $pb) {
            if (isset($pb['id'])) {
                $list[] = array(
                    'id' => $pb['id'],
                    'title' => isset($pb['title']) ? $pb['title'] : (string) $pb['id'],
                );
            }
        }
        $lines = $api->get_sender_lines_normalized($api_key);
        wp_send_json_success(array(
            'phonebooks' => $list,
            'lines' => $lines,
        ));
    }

    /**
     * AJAX: ارسال پیامک گروهی به یک دفترچه
     */
    public function ajax_send_bulk_phonebook_sms() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'farazsms_next_phonebook_ajax')) {
            wp_send_json_error(array('message' => __('خطای امنیتی.', 'farazsms-next')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('دسترسی ندارید.', 'farazsms-next')));
        }
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('کلید API تنظیم نشده.', 'farazsms-next')));
        }
        $phonebook_id = isset($_POST['phonebook_id']) ? absint($_POST['phonebook_id']) : 0;
        $line_number = isset($_POST['line_number']) ? sanitize_text_field(wp_unslash($_POST['line_number'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        if ($phonebook_id <= 0 || $line_number === '' || $message === '') {
            wp_send_json_error(array('message' => __('دفترچه، خط و متن پیام را کامل کنید.', 'farazsms-next')));
        }
        $api = new FarazSMS_Next_Phonebook_API();
        $result = $api->send_simple_sms_to_phonebook($phonebook_id, $line_number, $message, $api_key);
        if (is_array($result) && isset($result['status']) && $result['status'] === 'success') {
            wp_send_json_success(array('message' => __('ارسال با موفقیت ثبت شد.', 'farazsms-next')));
        }
        $err = __('خطا در ارسال.', 'farazsms-next');
        if (is_array($result)) {
            if (isset($result['message'])) {
                $err .= ' ' . (is_string($result['message']) ? $result['message'] : wp_json_encode($result['message']));
            } elseif (isset($result['messages'])) {
                $err .= ' ' . (is_string($result['messages']) ? $result['messages'] : wp_json_encode($result['messages']));
            }
        }
        wp_send_json_error(array('message' => $err));
    }

    /**
     * یافتن دفترچه بر اساس عنوان یا شناسه ذخیره‌شده
     *
     * @param string   $title
     * @param int|null $saved_id
     * @return array|false
     */
    private function find_phonebook_by_title_or_id($title, $saved_id, $api_key) {
        $api = new FarazSMS_Next_Phonebook_API();
        $response = $api->get_phonebooks($api_key);
        $phonebooks = $this->extract_phonebooks_list($response);
        if ($saved_id) {
            foreach ($phonebooks as $pb) {
                if (isset($pb['id']) && (int) $pb['id'] === (int) $saved_id) {
                    return $pb;
                }
            }
        }
        foreach ($phonebooks as $pb) {
            if (isset($pb['title']) && $pb['title'] === $title) {
                return $pb;
            }
        }
        return false;
    }

    /**
     * AJAX: سینک دفترچه از meta_key کاربر یا سفارش
     */
    public function ajax_sync_custom_meta_phonebook() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'farazsms_next_phonebook_ajax')) {
            wp_send_json_error(array('message' => __('خطای امنیتی.', 'farazsms-next')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('دسترسی ندارید.', 'farazsms-next')));
        }
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('کلید API تنظیم نشده.', 'farazsms-next')));
        }

        $title = isset($_POST['custom_phonebook_title']) ? sanitize_text_field(wp_unslash($_POST['custom_phonebook_title'])) : '';
        $meta_key = isset($_POST['custom_meta_key']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) wp_unslash($_POST['custom_meta_key'])) : '';
        $source = isset($_POST['custom_meta_source']) ? sanitize_text_field(wp_unslash($_POST['custom_meta_source'])) : 'usermeta';
        if (!in_array($source, array('usermeta', 'order_meta'), true)) {
            $source = 'usermeta';
        }

        if ($title === '') {
            $title = __('مخاطبین فیلد سفارشی', 'farazsms-next');
        }
        if ($meta_key === '') {
            wp_send_json_error(array('message' => __('نام فیلد (meta key) را وارد کنید.', 'farazsms-next')));
        }
        if ($source === 'order_meta' && !class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => __('برای متای سفارش، ووکامرس لازم است.', 'farazsms-next')));
        }

        update_option('farazsms_custom_phonebook_title', $title, false);
        update_option('farazsms_custom_phonebook_meta_key', $meta_key, false);
        update_option('farazsms_custom_phonebook_source', $source, false);

        $saved_id = absint(get_option('farazsms_custom_phonebook_id', 0));
        $existing = $this->find_phonebook_by_title_or_id($title, $saved_id > 0 ? $saved_id : null, $api_key);
        $phonebook_api = new FarazSMS_Next_Phonebook_API();
        $phonebook_id = null;
        $is_update = false;

        if ($existing && isset($existing['id'])) {
            $phonebook_id = $existing['id'];
            $is_update = true;
            update_option('farazsms_custom_phonebook_id', (int) $phonebook_id);
        } else {
            $phonebook_response = $phonebook_api->create_phonebook($title, $api_key);
            if (!$phonebook_response || (isset($phonebook_response['error']))) {
                $em = isset($phonebook_response['error']) ? $phonebook_response['error'] : __('خطا در ایجاد دفترچه', 'farazsms-next');
                wp_send_json_error(array('message' => $em));
            }
            if (isset($phonebook_response['status']) && $phonebook_response['status'] === 'success' && isset($phonebook_response['data']['id'])) {
                $phonebook_id = $phonebook_response['data']['id'];
            } elseif (isset($phonebook_response['data']) && is_array($phonebook_response['data']) && isset($phonebook_response['data']['id'])) {
                $phonebook_id = $phonebook_response['data']['id'];
            }
            if (!$phonebook_id) {
                sleep(1);
                $again = $this->find_phonebook_by_title_or_id($title, null, $api_key);
                if ($again && isset($again['id'])) {
                    $phonebook_id = $again['id'];
                }
            }
            if (!$phonebook_id) {
                wp_send_json_error(array('message' => __('شناسه دفترچه پس از ایجاد یافت نشد.', 'farazsms-next')));
            }
            update_option('farazsms_custom_phonebook_id', (int) $phonebook_id);
        }

        $numbers = array();
        if ($source === 'usermeta') {
            $users = get_users(array('number' => -1, 'fields' => array('ID', 'display_name')));
            foreach ($users as $u) {
                $phone = get_user_meta($u->ID, $meta_key, true);
                $phone = is_string($phone) ? trim($phone) : '';
                if ($phone !== '') {
                    $numbers[] = array(
                        'phone' => $phone,
                        'name' => $u->display_name ? $u->display_name : __('کاربر', 'farazsms-next') . ' ' . $u->ID,
                    );
                }
            }
        } else {
            // رفع N+1: به‌جای hydrate کردن همه‌ی سفارش‌ها (limit=-1 + wc_get_order در حلقه)
            // که روی فروشگاه‌های بزرگ OOM/timeout می‌داد، شماره و نام را با چند کوئریِ bulk
            // و آگاه به HPOS مستقیم می‌خوانیم.
            global $wpdb;
            $rows = array();
            $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

            if ($hpos) {
                $addr_table = $wpdb->prefix . 'wc_order_addresses';
                if ($meta_key === '_billing_phone') {
                    // فیلد اصلیِ تلفنِ صورتحساب در HPOS داخل جدول آدرس‌هاست، نه orders_meta.
                    $rows = $wpdb->get_results(
                        "SELECT order_id AS oid, phone AS phone,
                                TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name
                         FROM {$addr_table}
                         WHERE address_type = 'billing' AND phone <> ''",
                        ARRAY_A
                    );
                } else {
                    // متای دلخواهِ سفارش از orders_meta؛ نام از جدول آدرس‌ها.
                    $meta_table = $wpdb->prefix . 'wc_orders_meta';
                    $rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT om.order_id AS oid, om.meta_value AS phone,
                                TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))) AS name
                         FROM {$meta_table} om
                         LEFT JOIN {$addr_table} a ON a.order_id = om.order_id AND a.address_type = 'billing'
                         WHERE om.meta_key = %s AND om.meta_value <> ''",
                        $meta_key
                    ), ARRAY_A);
                }
            } else {
                // legacy postmeta
                $pm = $wpdb->prefix . 'postmeta';
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT pm.post_id AS oid, pm.meta_value AS phone,
                            TRIM(CONCAT(COALESCE(fn.meta_value,''),' ',COALESCE(ln.meta_value,''))) AS name
                     FROM {$pm} pm
                     LEFT JOIN {$pm} fn ON fn.post_id = pm.post_id AND fn.meta_key = '_billing_first_name'
                     LEFT JOIN {$pm} ln ON ln.post_id = pm.post_id AND ln.meta_key = '_billing_last_name'
                     WHERE pm.meta_key = %s AND pm.meta_value <> ''",
                    $meta_key
                ), ARRAY_A);
            }

            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $phone = trim((string) $r['phone']);
                    if ($phone === '') {
                        continue;
                    }
                    $name = trim((string) $r['name']);
                    if ($name === '') {
                        $name = __('سفارش', 'farazsms-next') . ' #' . (int) $r['oid'];
                    }
                    $numbers[] = array('phone' => $phone, 'name' => $name);
                }
            }
        }

        if (empty($numbers)) {
            wp_send_json_error(array('message' => __('هیچ شماره‌ای برای این فیلد یافت نشد.', 'farazsms-next')));
        }

        @set_time_limit(300);

        $contacts = array();
        $invalid = 0;
        foreach ($numbers as $row) {
            if ($phonebook_api->normalize_contact_mobile($row['phone']) === '') {
                $invalid++;
                continue;
            }
            $contacts[] = array(
                'phone' => $row['phone'],
                'name' => $row['name'],
            );
        }

        if (empty($contacts)) {
            wp_send_json_error(array('message' => __('هیچ شماره معتبری برای import یافت نشد.', 'farazsms-next')));
        }

        $bulk = $phonebook_api->bulk_upsert_contacts($phonebook_id, $contacts, $api_key);
        if (empty($bulk['success'])) {
            $err = isset($bulk['error']) ? $bulk['error'] : __('خطا در import گروهی مخاطبین.', 'farazsms-next');
            wp_send_json_error(array('message' => $err));
        }

        $added = isset($bulk['imported']) ? (int) $bulk['imported'] : 0;
        $extra = $invalid > 0 ? ' ' . sprintf(__('(%d شماره نامعتبر نادیده گرفته شد)', 'farazsms-next'), $invalid) : '';

        wp_send_json_success(array(
            'message' => sprintf(
                __('دفترچه با import گروهی به‌روز شد. %d مخاطب ثبت شد.%s', 'farazsms-next'),
                $added,
                $extra
            ),
            'phonebook_id' => $phonebook_id,
            'added_count' => $added,
            'invalid_phone_count' => $invalid,
            'bulk_chunks' => isset($bulk['chunks']) ? (int) $bulk['chunks'] : 1,
        ));
    }
}
