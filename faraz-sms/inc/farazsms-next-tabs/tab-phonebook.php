<?php
/**
 * Phonebook Tab Content
 *
 * @var FarazSMS_Next_Admin_Page $this
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get API key from unified settings
if (function_exists('wto_get_apikey')) {
    $api_key = wto_get_apikey();
} else {
    $api_key = get_option('wto_apikey', '');
}
?>

<?php if (empty($api_key)) : ?>
    <div class="fwss-info-message">
        <p><?php _e('برای استفاده از این بخش، ابتدا کلید دسترسی را در تب "تنظیمات پنل پیامکی" وارد کنید.', 'farazsms-next'); ?></p>
    </div>
<?php else : ?>
        <form method="post" action="" class="fwss_form form-style-2" style="margin-bottom: 24px;">
            <?php wp_nonce_field('farazsms_next_settings', 'farazsms_next_settings_nonce'); ?>
            <input type="hidden" name="current_tab" value="phonebook">
            <?php
            $pb_merged = function_exists('farazsms_next_get_phonebook_settings_merged')
                ? farazsms_next_get_phonebook_settings_merged()
                : array_merge(
                    array('auto_add_new_wc_customers' => '1'),
                    (array) get_option('farazsms_next_phonebook_settings', array())
                );
            $auto_add_wc = isset($pb_merged['auto_add_new_wc_customers']) ? $pb_merged['auto_add_new_wc_customers'] : '1';
            ?>
            <label class="toggle-control" style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <span class="label"><?php _e('افزودن خودکار مشتری جدید ووکامرس به دفترچه تلفن پس از ثبت سفارش', 'farazsms-next'); ?></span>
                <input type="checkbox" class="wto-toggle" name="farazsms_auto_add_new_wc_customers" id="farazsms_auto_add_new_wc_customers" value="1" <?php checked($auto_add_wc, '1'); ?>>
                <span class="control"></span>
            </label>
            <p class="description" style="margin-bottom: 16px;"><?php _e('برای کاربران لاگین‌شده: فقط در اولین سفارش؛ برای مهمان: یک‌بار برای هر سفارش (تا دفترچه از قبل ایجاد شده باشد).', 'farazsms-next'); ?></p>
            <div class="fwss_save_button_container">
                <button type="submit" class="fwss_button" name="farazsms_next_settings_submit">
                    <span class="button__text"><?php _e('ذخیره تنظیمات دفترچه', 'farazsms-next'); ?></span>
                </button>
            </div>
        </form>
        <div style="border-top: 1px solid #e9e9e9;">
            
            <?php if (!class_exists('WooCommerce')) : ?>
                <div class="fwss-error-message">
                    <p><?php _e('افزونه ووکامرس نصب نیست. برای استفاده از این قابلیت ابتدا ووکامرس را نصب کنید.', 'farazsms-next'); ?></p>
                </div>
            <?php else : ?>
                <?php
                // Check if WooCommerce phonebook exists
                // Try using method if available, otherwise check directly
                $woocommerce_phonebook = false;
                $phonebook_name = 'مشتریان ووکامرس';
                
                if (isset($admin_page) && method_exists($admin_page, 'get_woocommerce_phonebook')) {
                    $woocommerce_phonebook = $admin_page->get_woocommerce_phonebook();
                } else {
                    // Fallback: check directly
                    if (!empty($api_key)) {
                        $phonebook_api = new FarazSMS_Next_Phonebook_API();
                        $response = $phonebook_api->get_phonebooks($api_key);
                        
                        // Response structure: check both items and data arrays
                        $phonebooks = array();
                        if ($response && isset($response['data']['items']) && is_array($response['data']['items'])) {
                            $phonebooks = $response['data']['items'];
                        } elseif ($response && isset($response['data']['data']) && is_array($response['data']['data'])) {
                            $phonebooks = $response['data']['data'];
                        }
                        
                        if (!empty($phonebooks)) {
                            // Search for phonebook with matching name
                            foreach ($phonebooks as $phonebook) {
                                if (isset($phonebook['title']) && $phonebook['title'] === $phonebook_name) {
                                    $woocommerce_phonebook = $phonebook;
                                    break;
                                }
                            }
                        }
                    }
                }
                ?>
                
                <?php if ($woocommerce_phonebook && is_array($woocommerce_phonebook)) : ?>
                    <!-- Display existing phonebook details -->
                    <div class="fwss-phonebook-info" style="background: #f9f9f9; border: 1px solid #e9e9e9; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
                        <h3 style="margin-top: 0; margin-bottom: 15px; color: #333;">
                            <?php _e('دفترچه تلفن ووکامرس', 'farazsms-next'); ?>
                        </h3>
                        <table class="widefat" style="background: #fff;">
                            <tbody>
                                <tr>
                                    <td style="width: 150px; font-weight: bold; padding: 10px;">
                                        <?php  _e('شناسه دفترچه:', 'farazsms-next'); ?>
                                    </td>
                                    <td style="padding: 10px;">
                                        <?php echo esc_html(isset($woocommerce_phonebook['id']) ? $woocommerce_phonebook['id'] : '-'); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width: 150px; font-weight: bold; padding: 10px;">
                                        <?php _e('نام دفترچه:', 'farazsms-next'); ?>
                                    </td>
                                    <td style="padding: 10px;">
                                        <?php echo esc_html(isset($woocommerce_phonebook['title']) ? $woocommerce_phonebook['title'] : '-'); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width: 150px; font-weight: bold; padding: 10px;">
                                        <?php _e('تعداد مخاطبین:', 'farazsms-next'); ?>
                                    </td>
                                    <td style="padding: 10px;">
                                        <?php 
                                        // Check for records field (may be in different locations based on API response)
                                        $records_count = null;
                                        
                                        if (isset($woocommerce_phonebook['records'])) {
                                            $records_count = intval($woocommerce_phonebook['records']);
                                        } elseif (isset($woocommerce_phonebook['total_records'])) {
                                            $records_count = intval($woocommerce_phonebook['total_records']);
                                        } elseif (isset($woocommerce_phonebook['contacts_count'])) {
                                            $records_count = intval($woocommerce_phonebook['contacts_count']);
                                        }
                                        
                                        // If records count not found in phonebook data, try to get it from API
                                        if ($records_count === null && isset($woocommerce_phonebook['id']) && !empty($api_key)) {
                                            $phonebook_api = new FarazSMS_Next_Phonebook_API();
                                            $records_count = $phonebook_api->get_phonebook_contacts_count($woocommerce_phonebook['id'], $api_key);
                                        }
                                        
                                        if ($records_count !== null && $records_count >= 0) {
                                            echo esc_html(number_format_i18n($records_count));
                                        } else {
                                            echo '<span style="color: #999;">' . esc_html__('نامشخص', 'farazsms-next') . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php if (isset($woocommerce_phonebook['created_at'])) : ?>
                                <tr>
                                    <td style="width: 150px; font-weight: bold; padding: 10px;">
                                        <?php _e('تاریخ ایجاد:', 'farazsms-next'); ?>
                                    </td>
                                    <td style="padding: 10px;">
                                        <?php 
                                        $created_at = $woocommerce_phonebook['created_at'];
                                        // Convert ISO 8601 to readable format
                                        $date = date_create($created_at);
                                        if ($date) {
                                            echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $date->getTimestamp()));
                                        } else {
                                            echo esc_html($created_at);
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="fwss_save_button_container" style="margin-top: 20px;">
                        <button type="button" class="fwss_button" id="farazsms-update-phonebook-btn" data-phonebook-id="<?php echo esc_attr(isset($woocommerce_phonebook['id']) ? $woocommerce_phonebook['id'] : ''); ?>">
                            <span class="button__text"><?php _e('بروزرسانی دفترچه تلفن', 'farazsms-next'); ?></span>
                        </button>
                    </div>
                <?php else : ?>
                    <!-- Display create button if phonebook doesn't exist -->
                    <p class="description" style="margin-bottom: 20px;text-align : center;">
                        <?php _e('با کلیک روی دکمه زیر، یک دفترچه تلفن در وب‌سرویس فراز اس‌ام‌اس ایجاد شده و مشتریان ووکامرس به آن اضافه می‌شوند.', 'farazsms-next'); ?>
                    </p>
                    <div class="fwss_save_button_container">
                        <button type="button" class="fwss_button" id="farazsms-create-phonebook-btn">
                            <span class="button__text"><?php _e('ایجاد دفترچه تلفن از ووکامرس', 'farazsms-next'); ?></span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <div id="farazsms-phonebook-response" style="display: none; margin-top: 15px;"></div>
            <?php endif; ?>

            <!-- v3.17.7: بخش «دفترچه تلفن از فیلد سفارشی» از اینجا حذف شد —
                 حالا در یک بخش جداگانه در همین صفحه رندر می‌شود. -->
        </div>
    <?php endif; ?>
