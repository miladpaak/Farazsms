<?php
/**
 * Gravity Forms SMS Configurations Class
 *
 * Handles feed configuration/editing page
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Gravity_Forms_SMS_Configurations {

    /**
     * Constructor
     */
    public static function construct() {
    }
    
    /**
     * Register AJAX handlers early (called from main plugin file)
     */
    public static function register_ajax_handlers() {
        // Register AJAX handler - this must be called before admin-ajax.php processes the request
        if (!has_action('wp_ajax_farazsms_select_form')) {
            add_action('wp_ajax_farazsms_select_form', array(__CLASS__, 'select_form_ajax'), 10);
        }
        if (!has_action('wp_ajax_farazsms_create_pattern')) {
            add_action('wp_ajax_farazsms_create_pattern', array(__CLASS__, 'create_pattern_ajax'), 10);
        }
        if (!has_action('wp_ajax_farazsms_get_pattern')) {
            add_action('wp_ajax_farazsms_get_pattern', array(__CLASS__, 'get_pattern_ajax'), 10);
        }
    }

    /**
     * Display configuration page
     */
    public static function configuration() {
        wp_register_style('gform_admin_sms', GFCommon::get_base_url() . '/css/admin.css');
        // Ensure dashicons are loaded for icons
        wp_enqueue_style('dashicons');
        wp_print_styles(array('jquery-ui-styles', 'gform_admin_sms', 'wp-pointer', 'dashicons')); ?>

        <div class="wrap gforms_edit_form">
            <?php
            // Get ID from id parameter (Gravity Forms submenu standard)
            $id_param = function_exists('rgget') ? rgget('id') : (isset($_GET['id']) ? $_GET['id'] : '');
            $id = !empty($_POST['farazsms_setting_id']) ? absint($_POST['farazsms_setting_id']) : absint($id_param);
            $config = empty($id) ? array(
                'is_active' => true,
                'meta' => array()
            ) : FarazSMS_Next_Gravity_Forms_SMS_SQL::get_feed($id);

            $fid_param = function_exists('rgget') ? rgget('fid') : (isset($_GET['fid']) ? $_GET['fid'] : '');
            $gf_fid_param = function_exists('rgget') ? rgget('gf_fid') : (isset($_GET['gf_fid']) ? $_GET['gf_fid'] : '');
            $_get_form_id = !empty($config['form_id']) ? $config['form_id'] : (absint($fid_param) ?: absint($gf_fid_param));

            // Handle form submission
            if (!empty($_POST['farazsms_feed_submit'])) {
                check_admin_referer('farazsms_feed_update', 'farazsms_feed_nonce');

                $config['form_id'] = absint($_POST['gf_farazsms_form']);
                // Get sender number from panel settings (default if not set in POST)
                $posted_from = isset($_POST['gf_farazsms_from']) ? sanitize_text_field($_POST['gf_farazsms_from']) : '';
                $default_sender = get_option('wto_sender', '90008361');
                if (empty($posted_from)) {
                    $config['meta']['from'] = $default_sender;
                } else {
                    $config['meta']['from'] = $posted_from;
                }
                $config['meta']['to'] = sanitize_text_field($_POST['gf_farazsms_to']);
                $config['meta']['to_c'] = sanitize_text_field($_POST['gf_farazsms_to_c']);
                $config['meta']['when'] = sanitize_text_field($_POST['gf_farazsms_when']);
                // زمان ارسالِ پیامکِ مدیر — مستقل از کاربر. اگر ارسال نشده بود خالی می‌ماند
                // و در زمان ارسال به‌صورت سازگار با نسخه‌های قبلی از when (کاربر) پیروی می‌کند.
                $config['meta']['when_admin'] = isset($_POST['gf_farazsms_when_admin']) ? sanitize_text_field($_POST['gf_farazsms_when_admin']) : '';
                $config['meta']['message'] = wp_kses_post($_POST['gf_farazsms_message']);
                $config['meta']['message_c'] = wp_kses_post($_POST['gf_farazsms_message_c']);
                $config['meta']['customer_field_clientnum'] = sanitize_text_field($_POST['farazsms_customer_field_clientnum']);

                // Conditional logic for admin
                $config['meta']['adminsms_conditional_enabled'] = isset($_POST['gf_adminsms_conditional_enabled']) ? '1' : '';
                $config['meta']['adminsms_conditional_type'] = sanitize_text_field($_POST['gf_adminsms_conditional_type']);
                $config['meta']['adminsms_conditional_field_id'] = isset($_POST['gf_adminsms_conditional_field_id']) ? array_map('sanitize_text_field', $_POST['gf_adminsms_conditional_field_id']) : array();
                $config['meta']['adminsms_conditional_operator'] = isset($_POST['gf_adminsms_conditional_operator']) ? array_map('sanitize_text_field', $_POST['gf_adminsms_conditional_operator']) : array();
                $config['meta']['adminsms_conditional_value'] = isset($_POST['gf_adminsms_conditional_value']) ? array_map('sanitize_text_field', $_POST['gf_adminsms_conditional_value']) : array();

                // Conditional logic for client
                $config['meta']['clientsms_conditional_enabled'] = isset($_POST['gf_clientsms_conditional_enabled']) ? '1' : '';
                $config['meta']['clientsms_conditional_type'] = sanitize_text_field($_POST['gf_clientsms_conditional_type']);
                $config['meta']['clientsms_conditional_field_id'] = isset($_POST['gf_clientsms_conditional_field_id']) ? array_map('sanitize_text_field', $_POST['gf_clientsms_conditional_field_id']) : array();
                $config['meta']['clientsms_conditional_operator'] = isset($_POST['gf_clientsms_conditional_operator']) ? array_map('sanitize_text_field', $_POST['gf_clientsms_conditional_operator']) : array();
                $config['meta']['clientsms_conditional_value'] = isset($_POST['gf_clientsms_conditional_value']) ? array_map('sanitize_text_field', $_POST['gf_clientsms_conditional_value']) : array();

                // Save pattern codes if manually entered
                if (isset($_POST['pattern_code_admin_input']) && !empty(trim($_POST['pattern_code_admin_input']))) {
                    $config['meta']['pattern_code_admin'] = sanitize_text_field(trim($_POST['pattern_code_admin_input']));
                }
                if (isset($_POST['pattern_code_customer_input']) && !empty(trim($_POST['pattern_code_customer_input']))) {
                    $config['meta']['pattern_code_customer'] = sanitize_text_field(trim($_POST['pattern_code_customer_input']));
                }

                $id = FarazSMS_Next_Gravity_Forms_SMS_SQL::update_feed($id, $config['form_id'], $config['is_active'], $config['meta']);
                
                // After saving, check and load pattern details if pattern codes exist
                if (!empty($config['meta']['pattern_code_admin']) || !empty($config['meta']['pattern_code_customer'])) {
                    // Pattern details will be loaded on page reload via loadSavedPatterns()
                }

                // Redirect to Gravity Forms submenu
                wp_redirect(admin_url('admin.php?page=gf_farazsms&view=edit&id=' . $id . '&updated=true'));
                exit;
            }

            // Get form ID again (duplicate line removal - already set above)
            if (empty($_get_form_id)) {
                $fid_param = function_exists('rgget') ? rgget('fid') : (isset($_GET['fid']) ? $_GET['fid'] : '');
                $gf_fid_param = function_exists('rgget') ? rgget('gf_fid') : (isset($_GET['gf_fid']) ? $_GET['gf_fid'] : '');
                $_get_form_id = absint($fid_param) ?: absint($gf_fid_param);
            }

            $updated = function_exists('rgget') ? rgget('updated') : (isset($_GET['updated']) ? $_GET['updated'] : '');
            if ($updated == 'true') {
                $back_url = admin_url('admin.php?page=gf_farazsms');
                echo '<div class="updated fade" style="padding:6px">' . sprintf(__('Feed به‌روزرسانی شد. %sبازگشت به لیست%s', 'farazsms-next'), '<a href="' . esc_url($back_url) . '">', '</a>') . '</div>';
            }

            // Get sender number from unified settings
            $default_sender = get_option('wto_sender', '90008361');
            ?>

            <h2><?php _e('تنظیمات پیامک برای فرم‌ها', 'farazsms-next'); ?>
                <?php if (!empty($_get_form_id)) { ?>
                    <span class="gf_admin_page_subtitle">
                            <span class="gf_admin_page_formid"><?php echo sprintf(__('شناسه Feed: %s', 'farazsms-next'), esc_html($id)); ?></span>
                    </span>
                <?php } ?>
            </h2>

            <form method="post" action="" id="gform_form_settings">
                <?php wp_nonce_field('farazsms_feed_update', 'farazsms_feed_nonce'); ?>
                <input type="hidden" name="farazsms_setting_id" value="<?php echo $id; ?>"/>

                <table class="gforms_form_settings" cellspacing="0" cellpadding="0">
                    <tbody>
                    <tr>
                        <td colspan="2">
                            <h4 class="gf_settings_subgroup_title"><?php _e('تنظیمات عمومی', 'farazsms-next'); ?></h4>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('انتخاب فرم', 'farazsms-next'); ?></th>
                        <td>
                            <select id="gf_farazsms_form" name="gf_farazsms_form" onchange="SelectFormAjax(jQuery(this).val());">
                                <option value=""><?php _e('لطفا یک فرم انتخاب کنید', 'farazsms-next'); ?></option>
                                <?php
                                // همیشه فرم‌ها را از مدل داخلی گراویتی فرم بگیر تا لیست با ادمین یکی باشد
                                $forms = RGFormsModel::get_forms();
                                foreach ((array)$forms as $form) {
                                    $selected = absint($form->id) == $_get_form_id ? "selected='selected'" : "";
                                    ?>
                                    <option value="<?php echo absint($form->id); ?>" <?php echo $selected; ?>>
                                        <?php echo esc_html($form->title . ' (ID: ' . $form->id . ')'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('شماره فرستنده', 'farazsms-next'); ?></th>
                        <td>
                            <input type="text" name="gf_farazsms_from" class="fieldwidth-1"
                                   value="<?php echo isset($config['meta']['from']) && !empty($config['meta']['from']) ? esc_attr($config['meta']['from']) : esc_attr($default_sender); ?>"
                                   style="direction:ltr !important; text-align:left; background-color:#f0f0f0;"
                                   readonly>
                            <span class="description"><?php _e('شماره فرستنده از تنظیمات پنل پیامکی خوانده می‌شود', 'farazsms-next'); ?></span>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group2" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <td colspan="2">
                            <h4 class="gf_settings_subgroup_title"><?php _e('تنظیمات پیامک مدیر', 'farazsms-next'); ?></h4>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group3" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('شماره‌های مدیر', 'farazsms-next'); ?></th>
                        <td>
                            <input type="text" name="gf_farazsms_to" class="fieldwidth-1"
                                   value="<?php echo isset($config['meta']['to']) ? esc_attr($config['meta']['to']) : ''; ?>"
                                   style="direction:ltr !important; text-align:left;">
                            <span class="description"><?php _e('با کاما (,) جدا کنید. فرمت صحیح: 09123456789', 'farazsms-next'); ?></span>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group4" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('متن پیام مدیر', 'farazsms-next'); ?></th>
                        <td>
                            <?php
                            // همیشه کمبوباکس برچسب‌ها را نشان بده؛ در صورت نبود فرم/فیلد، پیام راهنما نمایش داده می‌شود
                            $merge_tags_options = '';

                            if (!empty($_get_form_id)) {
                                // Always use RGFormsModel to get form as array format
                                if (class_exists('RGFormsModel')) {
                                    $form_meta = RGFormsModel::get_form_meta($_get_form_id);
                                } elseif (class_exists('GFAPI')) {
                                    $form_meta = GFAPI::get_form($_get_form_id);
                                    // Convert object to array if needed
                                    if (is_object($form_meta) && isset($form_meta->id)) {
                                        $form_meta = RGFormsModel::get_form_meta($form_meta->id);
                                    }
                                }

                                if (!empty($form_meta)) {
                                    $merge_tags_options = self::get_form_fields_merge($form_meta);
                                }
                            }
                            ?>
                            <select id="gf_farazsms_message_variable_select" onchange="InsertVariable('gf_farazsms_message');">
                                <?php
                                if (!empty($merge_tags_options)) {
                                    echo $merge_tags_options; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                } else {
                                    echo '<option value="">' . esc_html__('برچسبی برای این فرم یافت نشد یا فرم انتخاب نشده است', 'farazsms-next') . '</option>';
                                }
                                ?>
                            </select>
                            <br/>
                            <textarea id="gf_farazsms_message" name="gf_farazsms_message"
                                      style="height: 150px; width:550px; margin-bottom: 12px;"><?php echo isset($config['meta']['message']) ? esc_textarea($config['meta']['message']) : ''; ?></textarea>
                            <div style="margin-top: 15px; padding: 12px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                                    <button type="button" id="create_pattern_admin_btn" class="button button-primary">
                                        <span class="dashicons dashicons-plus-alt" style="margin-top: 5px;font-size: 16px; width: 16px; height: 16px; line-height: 1;"></span>
                                        <?php _e('ساخت پترن', 'farazsms-next'); ?>
                                    </button>
                                    <input type="text"
                                           id="pattern_code_admin_input"
                                           name="pattern_code_admin_input"
                                           readonly
                                           placeholder="<?php _e('کد پترن پس از ساخت، اینجا نمایش داده می‌شود', 'farazsms-next'); ?>"
                                           value="<?php echo isset($config['meta']['pattern_code_admin']) ? esc_attr($config['meta']['pattern_code_admin']) : ''; ?>"
                                           style="width: 220px; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 3px; background:#f0f0f1; color:#50575e; cursor:not-allowed;">
                                </div>
                                <small style="color: #646970; font-size: 12px; display: block; line-height: 1.5;">
                                    <span class="dashicons dashicons-info" style="font-size: 14px; width: 14px; height: 14px; line-height: 1.5; margin-left: 3px; vertical-align: text-bottom;"></span>
                                    <?php _e('برای ارسال پیامک، روی دکمه «ساخت پترن» کلیک کنید تا کد پترن ساخته و در کادر بالا نمایش داده شود؛ سپس در پایین دکمه ذخیره را بزنید.', 'farazsms-next'); ?>
                                </small>
                            </div>
                            <div id="pattern_info_admin" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; display: none;">
                                <strong style="font-size: 14px; color: #23282d; display: flex; align-items: center; margin-bottom: 12px;">
                                    <span class="dashicons dashicons-info" style="font-size: 18px; width: 18px; height: 18px; margin-left: 5px; color: #2271b1;"></span>
                                    <?php _e('اطلاعات پترن:', 'farazsms-next'); ?>
                                </strong>
                                <div id="pattern_info_admin_content" style="line-height: 1.8;"></div>
                            </div>
                        </td>
                    </tr>

                    <tr id="gf_adminsms_conditional_option" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('منطق شرطی', 'farazsms-next'); ?></th>
                        <td>
                            <input type="checkbox" id="gf_adminsms_conditional_enabled" name="gf_adminsms_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_adminsms_conditional_container').fadeIn('fast');} else{ jQuery('#gf_adminsms_conditional_container').fadeOut('fast'); }" <?php echo isset($config['meta']['adminsms_conditional_enabled']) && $config['meta']['adminsms_conditional_enabled'] ? "checked='checked'" : ""; ?>>
                            <label style="font-family:tahoma,serif !important;" for="gf_adminsms_conditional_enabled"><?php _e('فعالسازی منطق شرطی برای مدیر', 'farazsms-next'); ?></label><br>
                            <table cellspacing="0" cellpadding="0">
                                <tbody><tr>
                                    <td>
                                        <div id="gf_adminsms_conditional_container" style="<?php echo !isset($config['meta']['adminsms_conditional_enabled']) || !$config['meta']['adminsms_conditional_enabled'] ? "display:none" : ""; ?>">
                                            <span><?php _e('ارسال پیامک به مدیر اگر ', 'farazsms-next'); ?></span>
                                            <select name="gf_adminsms_conditional_type">
                                                <option value="all" <?php echo (isset($config['meta']['adminsms_conditional_type']) && $config['meta']['adminsms_conditional_type'] == 'all') ? 'selected="selected"' : ''; ?>><?php _e('همه', 'farazsms-next'); ?></option>
                                                <option value="any" <?php echo (isset($config['meta']['adminsms_conditional_type']) && $config['meta']['adminsms_conditional_type'] == 'any') ? 'selected="selected"' : ''; ?>><?php _e('حداقل یکی', 'farazsms-next'); ?></option>
                                            </select>
                                            <span><?php _e('از شرط های زیر برقرار بود:', 'farazsms-next'); ?></span>
                                            <?php
                                            // Get saved conditions
                                            if (!empty($config['meta']['adminsms_conditional_field_id'])) {
                                                $admin_conditions = $config['meta']['adminsms_conditional_field_id'];
                                                if (!is_array($admin_conditions)) {
                                                    $admin_conditions = array('1' => $admin_conditions);
                                                }
                                            } else {
                                                $admin_conditions = array('1' => '');
                                            }
                                            
                                            if (!empty($config['meta']['adminsms_conditional_value'])) {
                                                $admin_condition_values = $config['meta']['adminsms_conditional_value'];
                                                if (!is_array($admin_condition_values)) {
                                                    $admin_condition_values = array('1' => $admin_condition_values);
                                                }
                                            } else {
                                                $admin_condition_values = array('1' => '');
                                            }
                                            
                                            if (!empty($config['meta']['adminsms_conditional_operator'])) {
                                                $admin_condition_operators = $config['meta']['adminsms_conditional_operator'];
                                                if (!is_array($admin_condition_operators)) {
                                                    $admin_condition_operators = array('1' => $admin_condition_operators);
                                                }
                                            } else {
                                                $admin_condition_operators = array('1' => 'is');
                                            }
                                            
                                            ksort($admin_conditions);
                                            foreach ($admin_conditions as $i => $value): 
                                                $selected_operator = isset($admin_condition_operators[$i]) ? $admin_condition_operators[$i] : 'is';
                                                $selected_value = isset($admin_condition_values[$i]) ? $admin_condition_values[$i] : '';
                                            ?>
                                                <div class="gf_adminsms_conditional_div" id="gf_adminsms_<?php echo $i; ?>__conditional_div">
                                                    <select class="gf_adminsms_conditional_field_id" id="gf_adminsms_<?php echo $i; ?>__conditional_field_id" name="gf_adminsms_conditional_field_id[<?php echo $i; ?>]" title="">
                                                    </select>
                                                    <select class="gf_adminsms_conditional_operator" id="gf_adminsms_<?php echo $i; ?>__conditional_operator" name="gf_adminsms_conditional_operator[<?php echo $i; ?>]" style="font-family:tahoma,serif !important" title="">
                                                        <option value="is" <?php echo $selected_operator == 'is' ? 'selected="selected"' : ''; ?>><?php _e('هست', 'farazsms-next'); ?></option>
                                                        <option value="isnot" <?php echo $selected_operator == 'isnot' ? 'selected="selected"' : ''; ?>><?php _e('نیست', 'farazsms-next'); ?></option>
                                                        <option value=">" <?php echo $selected_operator == '>' ? 'selected="selected"' : ''; ?>><?php _e('بزرگتر از', 'farazsms-next'); ?></option>
                                                        <option value="<" <?php echo $selected_operator == '<' ? 'selected="selected"' : ''; ?>><?php _e('کوچکتر از', 'farazsms-next'); ?></option>
                                                        <option value="contains" <?php echo $selected_operator == 'contains' ? 'selected="selected"' : ''; ?>><?php _e('شامل میشود', 'farazsms-next'); ?></option>
                                                        <option value="starts_with" <?php echo $selected_operator == 'starts_with' ? 'selected="selected"' : ''; ?>><?php _e('شروع میشود', 'farazsms-next'); ?></option>
                                                        <option value="ends_with" <?php echo $selected_operator == 'ends_with' ? 'selected="selected"' : ''; ?>><?php _e('تمام میشود', 'farazsms-next'); ?></option>
                                                    </select>
                                                    <div id="gf_adminsms_<?php echo $i; ?>__conditional_value_container" style="display:inline;">
                                                        <input type="text" class="condition_field_value" style="padding:3px" placeholder="<?php _e('یک مقدار وارد کنید', 'farazsms-next'); ?>" id="gf_adminsms_<?php echo $i; ?>__conditional_value" name="gf_adminsms_conditional_value[<?php echo $i; ?>]" value="<?php echo esc_attr($selected_value); ?>">
                                                    </div>
                                                    <a class="add_admin_condition gficon_link" href="#">
                                                        <i class="gficon-add"></i>
                                                    </a>
                                                    <a class="delete_admin_condition gficon_link" href="#" style="display: none;">
                                                        <i class="gficon-subtract"></i>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                            <input type="hidden" value="<?php echo !empty($admin_conditions) ? key(array_slice($admin_conditions, -1, 1, true)) : '1'; ?>" id="gf_adminsms_conditional_counter">
                                            <div id="gf_adminsms_conditional_message" style="display:none;background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding:18px; border:1px dotted #C89797;">
                                                <?php _e('برای استفاده از منطق شرطی فرم شما باید شامل فیلدهای شرطی باشد.', 'farazsms-next'); ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr></tbody>
                            </table>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_when_admin_option" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('زمان ارسال پیامک مدیر', 'farazsms-next'); ?></th>
                        <td>
                            <?php
                            // سازگاری با feedهای قدیمی: اگر when_admin هنوز ذخیره نشده، مقدارِ when (کاربر) نمایش داده می‌شود.
                            $when_admin_val = isset($config['meta']['when_admin']) && $config['meta']['when_admin'] !== ''
                                ? $config['meta']['when_admin']
                                : (isset($config['meta']['when']) ? $config['meta']['when'] : 'after_submit');
                            ?>
                            <select name="gf_farazsms_when_admin">
                                <option value="after_submit" <?php echo ($when_admin_val == 'after_submit') ? 'selected' : ''; ?>>
                                    <?php _e('بعد از ارسال فرم', 'farazsms-next'); ?>
                                </option>
                                <option value="after_payment" <?php echo ($when_admin_val == 'after_payment') ? 'selected' : ''; ?>>
                                    <?php _e('بعد از پرداخت (همه وضعیت‌ها)', 'farazsms-next'); ?>
                                </option>
                                <option value="after_payment_success" <?php echo ($when_admin_val == 'after_payment_success') ? 'selected' : ''; ?>>
                                    <?php _e('بعد از پرداخت موفق', 'farazsms-next'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group5" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <td colspan="2">
                            <h4 class="gf_settings_subgroup_title"><?php _e('User SMS Configuration', 'farazsms-next'); ?></h4>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group6" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('فیلد شماره تلفن', 'farazsms-next'); ?></th>
                        <td id="farazsms_customer_field_container">
                            <?php
                            if (!empty($_get_form_id)) {
                                // Always use RGFormsModel to get form as array format
                                if (class_exists('RGFormsModel')) {
                                    $form_meta = RGFormsModel::get_form_meta($_get_form_id);
                                } elseif (class_exists('GFAPI')) {
                                    $form_meta = GFAPI::get_form($_get_form_id);
                                    // Convert object to array if needed
                                    if (is_object($form_meta) && isset($form_meta->id)) {
                                        $form_meta = RGFormsModel::get_form_meta($form_meta->id);
                                    }
                                }
                                if (!empty($form_meta)) {
                                    echo self::get_client_information($form_meta, $config);
                                } else {
                                    echo '<select name="farazsms_customer_field_clientnum"><option value="">' . __('یک فیلد انتخاب کنید', 'farazsms-next') . '</option></select>';
                                }
                            } else {
                                echo '<select name="farazsms_customer_field_clientnum"><option value="">' . __('یک فیلد انتخاب کنید', 'farazsms-next') . '</option></select>';
                            }
                            ?>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group7" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('شماره‌های اضافی', 'farazsms-next'); ?></th>
                        <td>
                            <input type="text" name="gf_farazsms_to_c" class="fieldwidth-1"
                                   value="<?php echo isset($config['meta']['to_c']) ? esc_attr($config['meta']['to_c']) : ''; ?>"
                                   style="direction:ltr !important; text-align:left;">
                            <span class="description"><?php _e('با کاما (,) جدا کنید. فرمت صحیح: 09123456789', 'farazsms-next'); ?></span>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group8" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('متن پیام کاربر', 'farazsms-next'); ?></th>
                        <td>
                            <?php
                            // همیشه کمبوباکس برچسب‌های کاربر را نشان بده؛ در صورت نبود فرم/فیلد، پیام راهنما نمایش داده می‌شود
                            $merge_tags_options_c = '';

                            if (!empty($_get_form_id)) {
                                // Always use RGFormsModel to get form as array format
                                if (class_exists('RGFormsModel')) {
                                    $form_meta = RGFormsModel::get_form_meta($_get_form_id);
                                } elseif (class_exists('GFAPI')) {
                                    $form_meta = GFAPI::get_form($_get_form_id);
                                    // Convert object to array if needed
                                    if (is_object($form_meta) && isset($form_meta->id)) {
                                        $form_meta = RGFormsModel::get_form_meta($form_meta->id);
                                    }
                                }

                                if (!empty($form_meta)) {
                                    $merge_tags_options_c = self::get_form_fields_merge($form_meta);
                                }
                            }
                            ?>
                            <select id="gf_farazsms_message_c_variable_select" onchange="InsertVariable('gf_farazsms_message_c');">
                                <?php
                                if (!empty($merge_tags_options_c)) {
                                    echo $merge_tags_options_c; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                } else {
                                    echo '<option value="">' . esc_html__('برچسبی برای این فرم یافت نشد یا فرم انتخاب نشده است', 'farazsms-next') . '</option>';
                                }
                                ?>
                            </select>
                            <br/>
                            <textarea id="gf_farazsms_message_c" name="gf_farazsms_message_c"
                                      style="height: 150px; width:550px; margin-bottom: 12px;"><?php echo isset($config['meta']['message_c']) ? esc_textarea($config['meta']['message_c']) : ''; ?></textarea>
                            <div style="margin-top: 15px; padding: 12px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                                    <button type="button" id="create_pattern_customer_btn" class="button button-primary">
                                        <span class="dashicons dashicons-plus-alt" style="margin-top: 5px;font-size: 16px; width: 16px; height: 16px; line-height: 1;"></span>
                                        <?php _e('ساخت پترن', 'farazsms-next'); ?>
                                    </button>
                                    <input type="text"
                                           id="pattern_code_customer_input"
                                           name="pattern_code_customer_input"
                                           readonly
                                           placeholder="<?php _e('کد پترن پس از ساخت، اینجا نمایش داده می‌شود', 'farazsms-next'); ?>"
                                           value="<?php echo isset($config['meta']['pattern_code_customer']) ? esc_attr($config['meta']['pattern_code_customer']) : ''; ?>"
                                           style="width: 220px; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 3px; background:#f0f0f1; color:#50575e; cursor:not-allowed;">
                                </div>
                                <small style="color: #646970; font-size: 12px; display: block; line-height: 1.5;">
                                    <span class="dashicons dashicons-info" style="font-size: 14px; width: 14px; height: 14px; line-height: 1.5; margin-left: 3px; vertical-align: text-bottom;"></span>
                                    <?php _e('برای ارسال پیامک، روی دکمه «ساخت پترن» کلیک کنید تا کد پترن ساخته و در کادر بالا نمایش داده شود؛ سپس در پایین دکمه ذخیره را بزنید.', 'farazsms-next'); ?>
                                </small>
                            </div>
                            <div id="pattern_info_customer" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; display: none;">
                                <strong style="font-size: 14px; color: #23282d; display: flex; align-items: center; margin-bottom: 12px;">
                                    <span class="dashicons dashicons-info" style="font-size: 18px; width: 18px; height: 18px; margin-left: 5px; color: #2271b1;"></span>
                                    <?php _e('اطلاعات پترن:', 'farazsms-next'); ?>
                                </strong>
                                <div id="pattern_info_customer_content" style="line-height: 1.8;"></div>
                            </div>
                        </td>
                    </tr>

                    <tr id="gf_clientsms_conditional_option" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('منطق شرطی', 'farazsms-next'); ?></th>
                        <td>
                            <input type="checkbox" id="gf_clientsms_conditional_enabled" name="gf_clientsms_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_clientsms_conditional_container').fadeIn('fast');} else{ jQuery('#gf_clientsms_conditional_container').fadeOut('fast'); }" <?php echo isset($config['meta']['clientsms_conditional_enabled']) && $config['meta']['clientsms_conditional_enabled'] ? "checked='checked'" : ""; ?>>
                            <label style="font-family:tahoma,serif !important;" for="gf_clientsms_conditional_enabled"><?php _e('فعالسازی منطق شرطی برای کاربر', 'farazsms-next'); ?></label><br>
                            <table cellspacing="0" cellpadding="0">
                                <tbody><tr>
                                    <td>
                                        <div id="gf_clientsms_conditional_container" style="<?php echo !isset($config['meta']['clientsms_conditional_enabled']) || !$config['meta']['clientsms_conditional_enabled'] ? "display:none" : ""; ?>">
                                            <span><?php _e('پیامک را به کاربر ارسال کن اگر ', 'farazsms-next'); ?></span>
                                            <select name="gf_clientsms_conditional_type">
                                                <option value="all" <?php echo (isset($config['meta']['clientsms_conditional_type']) && $config['meta']['clientsms_conditional_type'] == 'all') ? 'selected="selected"' : ''; ?>><?php _e('همه', 'farazsms-next'); ?></option>
                                                <option value="any" <?php echo (isset($config['meta']['clientsms_conditional_type']) && $config['meta']['clientsms_conditional_type'] == 'any') ? 'selected="selected"' : ''; ?>><?php _e('حداقل یکی', 'farazsms-next'); ?></option>
                                            </select>
                                            <span><?php _e('از شرط های زیر برقرار بود:', 'farazsms-next'); ?></span>
                                            <?php
                                            // Get saved conditions
                                            if (!empty($config['meta']['clientsms_conditional_field_id'])) {
                                                $client_conditions = $config['meta']['clientsms_conditional_field_id'];
                                                if (!is_array($client_conditions)) {
                                                    $client_conditions = array('1' => $client_conditions);
                                                }
                                            } else {
                                                $client_conditions = array('1' => '');
                                            }
                                            
                                            if (!empty($config['meta']['clientsms_conditional_value'])) {
                                                $client_condition_values = $config['meta']['clientsms_conditional_value'];
                                                if (!is_array($client_condition_values)) {
                                                    $client_condition_values = array('1' => $client_condition_values);
                                                }
                                            } else {
                                                $client_condition_values = array('1' => '');
                                            }
                                            
                                            if (!empty($config['meta']['clientsms_conditional_operator'])) {
                                                $client_condition_operators = $config['meta']['clientsms_conditional_operator'];
                                                if (!is_array($client_condition_operators)) {
                                                    $client_condition_operators = array('1' => $client_condition_operators);
                                                }
                                            } else {
                                                $client_condition_operators = array('1' => 'is');
                                            }
                                            
                                            ksort($client_conditions);
                                            foreach ($client_conditions as $i => $value): 
                                                $selected_operator = isset($client_condition_operators[$i]) ? $client_condition_operators[$i] : 'is';
                                                $selected_value = isset($client_condition_values[$i]) ? $client_condition_values[$i] : '';
                                            ?>
                                                <div class="gf_clientsms_conditional_div" id="gf_clientsms_<?php echo $i; ?>__conditional_div">
                                                    <select class="gf_clientsms_conditional_field_id" id="gf_clientsms_<?php echo $i; ?>__conditional_field_id" name="gf_clientsms_conditional_field_id[<?php echo $i; ?>]" title="">
                                                    </select>
                                                    <select class="gf_clientsms_conditional_operator" id="gf_clientsms_<?php echo $i; ?>__conditional_operator" name="gf_clientsms_conditional_operator[<?php echo $i; ?>]" style="font-family:tahoma,serif !important" title="">
                                                        <option value="is" <?php echo $selected_operator == 'is' ? 'selected="selected"' : ''; ?>><?php _e('هست', 'farazsms-next'); ?></option>
                                                        <option value="isnot" <?php echo $selected_operator == 'isnot' ? 'selected="selected"' : ''; ?>><?php _e('نیست', 'farazsms-next'); ?></option>
                                                        <option value=">" <?php echo $selected_operator == '>' ? 'selected="selected"' : ''; ?>><?php _e('بزرگتر از', 'farazsms-next'); ?></option>
                                                        <option value="<" <?php echo $selected_operator == '<' ? 'selected="selected"' : ''; ?>><?php _e('کوچکتر از', 'farazsms-next'); ?></option>
                                                        <option value="contains" <?php echo $selected_operator == 'contains' ? 'selected="selected"' : ''; ?>><?php _e('شامل میشود', 'farazsms-next'); ?></option>
                                                        <option value="starts_with" <?php echo $selected_operator == 'starts_with' ? 'selected="selected"' : ''; ?>><?php _e('شروع میشود', 'farazsms-next'); ?></option>
                                                        <option value="ends_with" <?php echo $selected_operator == 'ends_with' ? 'selected="selected"' : ''; ?>><?php _e('تمام میشود', 'farazsms-next'); ?></option>
                                                    </select>
                                                    <div id="gf_clientsms_<?php echo $i; ?>__conditional_value_container" style="display:inline;">
                                                        <input type="text" class="condition_field_value" style="padding:3px" placeholder="<?php _e('یک مقدار وارد کنید', 'farazsms-next'); ?>" id="gf_clientsms_<?php echo $i; ?>__conditional_value" name="gf_clientsms_conditional_value[<?php echo $i; ?>]" value="<?php echo esc_attr($selected_value); ?>">
                                                    </div>
                                                    <a class="add_client_condition gficon_link" href="#">
                                                        <i class="gficon-add"></i>
                                                    </a>
                                                    <a class="delete_client_condition gficon_link" href="#" style="display: none;">
                                                        <i class="gficon-subtract"></i>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                            <input type="hidden" value="<?php echo !empty($client_conditions) ? key(array_slice($client_conditions, -1, 1, true)) : '1'; ?>" id="gf_clientsms_conditional_counter">
                                            <div id="gf_clientsms_conditional_message" style="display:none;background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding:18px; border:1px dotted #C89797;">
                                                <?php _e('برای استفاده از منطق شرطی فرم شما باید شامل فیلدهای شرطی باشد.', 'farazsms-next'); ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr></tbody>
                            </table>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group9" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <th><?php _e('زمان ارسال پیامک کاربر', 'farazsms-next'); ?></th>
                        <td>
                            <select name="gf_farazsms_when">
                                <option value="after_submit" <?php echo (isset($config['meta']['when']) && $config['meta']['when'] == 'after_submit') ? 'selected' : ''; ?>>
                                    <?php _e('بعد از ارسال فرم', 'farazsms-next'); ?>
                                </option>
                                <option value="after_payment" <?php echo (isset($config['meta']['when']) && $config['meta']['when'] == 'after_payment') ? 'selected' : ''; ?>>
                                    <?php _e('بعد از پرداخت (همه وضعیت‌ها)', 'farazsms-next'); ?>
                                </option>
                                <option value="after_payment_success" <?php echo (isset($config['meta']['when']) && $config['meta']['when'] == 'after_payment_success') ? 'selected' : ''; ?>>
                                    <?php _e('بعد از پرداخت موفق', 'farazsms-next'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>

                    <tr id="gf_farazsms_field_group10" <?php echo empty($_get_form_id) ? "style='display:none;'" : ""; ?>>
                        <td colspan="2" style="padding-top:20px;">
                            <input type="submit" name="farazsms_feed_submit" class="button-primary"
                                   value="<?php _e('به‌روزرسانی Feed', 'farazsms-next'); ?>"/>
                            <?php
                            $cancel_url = admin_url('admin.php?page=gf_farazsms');
                            ?>
                            <a href="<?php echo esc_url($cancel_url); ?>" class="button"><?php _e('انصراف', 'farazsms-next'); ?></a>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </form>
        </div>

        <script type="text/javascript">
            // Define ajaxurl if not already defined
            if (typeof ajaxurl === 'undefined') {
                var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            }
            
            // Pattern codes for current feed
            var feedPatternCodes = {
                admin: <?php echo isset($config['meta']['pattern_code_admin']) ? json_encode($config['meta']['pattern_code_admin']) : 'null'; ?>,
                customer: <?php echo isset($config['meta']['pattern_code_customer']) ? json_encode($config['meta']['pattern_code_customer']) : 'null'; ?>
            };
            
            // Debug: log feedPatternCodes
            
            window.SelectFormAjax = function(formId) {
                if (!formId) {
                    jQuery('#gf_farazsms_field_group, #gf_farazsms_field_group2, #gf_farazsms_field_group3, #gf_farazsms_field_group4, #gf_adminsms_conditional_option, #gf_farazsms_field_group5, #gf_farazsms_field_group6, #gf_farazsms_field_group7, #gf_farazsms_field_group8, #gf_clientsms_conditional_option, #gf_farazsms_field_group9, #gf_farazsms_field_group10').fadeOut();
                    return;
                }
                
                // Show loading indicator if exists
                var waitImg = jQuery('#gf_farazsms_wait');
                if (waitImg.length) {
                    waitImg.show();
                }
                
                // Hide fields first
                jQuery('#gf_farazsms_field_group, #gf_farazsms_field_group2, #gf_farazsms_field_group3, #gf_farazsms_field_group4, #gf_farazsms_field_group5, #gf_farazsms_field_group6, #gf_farazsms_field_group7, #gf_farazsms_field_group8, #gf_farazsms_field_group9, #gf_farazsms_field_group10').fadeOut();
                
                // Make AJAX request
                jQuery.post(ajaxurl, {
                    action: 'farazsms_select_form',
                    farazsms_select_form: '<?php echo wp_create_nonce('farazsms_select_form'); ?>',
                    form_id: formId
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Update merge tag selects
                        jQuery('#gf_farazsms_message_variable_select').html(data.fields);
                        jQuery('#gf_farazsms_message_c_variable_select').html(data.fields);
                        
                        // Update customer phone field
                        if (data.customer_field) {
                            jQuery('#farazsms_customer_field_container').html(data.customer_field);
                        }
                        
                        // Update form variable for conditional logic
                        if (data.form_json && typeof window.form !== 'undefined') {
                            try {
                                window.form = JSON.parse(data.form_json);
                                // Re-initialize conditional field dropdowns if functions exist
                                if (typeof GetSelectableFields === 'function') {
                                    jQuery('.gf_adminsms_conditional_field_id').each(function() {
                                        var id = jQuery(this).attr('id');
                                        if (!id) return;
                                        id = id.replace('gf_adminsms_', '').replace('__conditional_field_id', '');
                                        var selectedField = jQuery(this).val() || '';
                                        var selectedOperator = jQuery('#gf_adminsms_' + id + '__conditional_operator').val() || 'is';
                                        var selectedValue = jQuery('#gf_adminsms_' + id + '__conditional_value').val() || '';
                                        jQuery(this).html(GetSelectableFields(selectedField, 20));
                                        jQuery(this).val(selectedField);
                                        if (selectedField && typeof GetConditionalFieldValues === 'function') {
                                            jQuery('#gf_adminsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_adminsms_" + id + "__conditional", selectedField, selectedOperator, selectedValue, 20, id));
                                        }
                                    });
                                    
                                    jQuery('.gf_clientsms_conditional_field_id').each(function() {
                                        var id = jQuery(this).attr('id');
                                        if (!id) return;
                                        id = id.replace('gf_clientsms_', '').replace('__conditional_field_id', '');
                                        var selectedField = jQuery(this).val() || '';
                                        var selectedOperator = jQuery('#gf_clientsms_' + id + '__conditional_operator').val() || 'is';
                                        var selectedValue = jQuery('#gf_clientsms_' + id + '__conditional_value').val() || '';
                                        jQuery(this).html(GetSelectableFields(selectedField, 20));
                                        jQuery(this).val(selectedField);
                                        if (selectedField && typeof GetConditionalFieldValues === 'function') {
                                            jQuery('#gf_clientsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_clientsms_" + id + "__conditional", selectedField, selectedOperator, selectedValue, 20, id));
                                        }
                                    });
                                }
                            } catch(e) {
                            }
                        }
                        
                        // Show all field groups
                        jQuery('#gf_farazsms_field_group, #gf_farazsms_field_group2, #gf_farazsms_field_group3, #gf_farazsms_field_group4, #gf_adminsms_conditional_option, #gf_farazsms_field_group5, #gf_farazsms_field_group6, #gf_farazsms_field_group7, #gf_farazsms_field_group8, #gf_clientsms_conditional_option, #gf_farazsms_field_group9, #gf_farazsms_field_group10').fadeIn();
                        
                    } else {
                        alert(response.data.message || 'خطا در دریافت اطلاعات فرم');
                    }
                    
                    // Hide loading indicator
                    if (waitImg.length) {
                        waitImg.hide();
                    }
                }, 'json').fail(function(xhr, status, error) {
                    alert('خطا در ارتباط با سرور: ' + error);
                    if (waitImg.length) {
                        waitImg.hide();
                    }
                });
            }

            function InsertVariable(element_id, callback, variable) {
                var obj;
                var variable_select = jQuery('#' + element_id + '_variable_select');
                if (!variable)
                    variable = variable_select.val();
                var messageElement = jQuery("#" + element_id);
                if (document.selection) {
                    messageElement[0].focus();
                    document.selection.createRange().text = variable;
                }
                else if (messageElement[0].selectionStart !== undefined) {
                    obj = messageElement[0];
                    obj.value = obj.value.substr(0, obj.selectionStart) + variable + obj.value.substr(obj.selectionEnd, obj.value.length);
                }
                else {
                    messageElement.val(variable + messageElement.val());
                }
                variable_select[0].selectedIndex = 0;
                if (callback && window[callback])
                    window[callback].call();
            }

            // Pattern creation handlers
            jQuery(document).ready(function($) {
                // Admin pattern button
                $('#create_pattern_admin_btn').on('click', function() {
                    var messageText = $('#gf_farazsms_message').val();
                    if (!messageText.trim()) {
                        alert('لطفا ابتدا متن پیام را وارد کنید');
                        return;
                    }
               
                    var btn = $(this);
                    var originalText = btn.text();
                    btn.prop('disabled', true).text('در حال ساخت...');
                    
                    var feedId = jQuery('input[name="farazsms_setting_id"]').val() || 0;
                    jQuery.post(ajaxurl, {
                        action: 'farazsms_create_pattern',
                        farazsms_create_pattern: '<?php echo wp_create_nonce('farazsms_create_pattern'); ?>',
                        message: messageText,
                        type: 'admin',
                        feed_id: feedId
                    }, function(response) {
                        btn.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            // Update pattern code input
                            if (response.data.pattern_code) {
                                jQuery('#pattern_code_admin_input').val(response.data.pattern_code);
                                // Update feedPatternCodes
                                feedPatternCodes.admin = response.data.pattern_code;
                            }
                            
                            // Display pattern info
                            if (response.data.pattern_code && response.data.pattern_details) {
                                if (typeof displayPatternInfo === 'function') {
                                    displayPatternInfo('admin', response.data.pattern_code, response.data.pattern_details);
                                } else if (typeof window.displayPatternInfo === 'function') {
                                    window.displayPatternInfo('admin', response.data.pattern_code, response.data.pattern_details);
                                }
                            }
                            
                            alert(response.data.message + '\n' + 'متن پیام و کد پترن ذخیره شد.');
                        } else {
                            alert(response.data.message || 'خطا در ساخت پترن');
                        }
                    }, 'json').fail(function(xhr, status, error) {
                        btn.prop('disabled', false).text(originalText);
                        alert('خطا در ارتباط با سرور: ' + error);
                    });
                });
                
                // Customer pattern button
                $('#create_pattern_customer_btn').on('click', function() {
                    var messageText = $('#gf_farazsms_message_c').val();
                    if (!messageText.trim()) {
                        alert('لطفا ابتدا متن پیام را وارد کنید');
                        return;
                    }
                    
                    var btn = $(this);
                    var originalText = btn.text();
                    btn.prop('disabled', true).text('در حال ساخت...');
                    
                    var feedId = jQuery('input[name="farazsms_setting_id"]').val() || 0;
                    jQuery.post(ajaxurl, {
                        action: 'farazsms_create_pattern',
                        farazsms_create_pattern: '<?php echo wp_create_nonce('farazsms_create_pattern'); ?>',
                        message: encodeURIComponent(messageText),
                        type: 'customer',
                        feed_id: feedId
                    }, function(response) {
                        btn.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            // Update pattern code input
                            if (response.data.pattern_code) {
                                jQuery('#pattern_code_customer_input').val(response.data.pattern_code);
                                // Update feedPatternCodes
                                feedPatternCodes.customer = response.data.pattern_code;
                            }
                            
                            // Display pattern info
                            if (response.data.pattern_code && response.data.pattern_details) {
                                if (typeof displayPatternInfo === 'function') {
                                    displayPatternInfo('customer', response.data.pattern_code, response.data.pattern_details);
                                } else if (typeof window.displayPatternInfo === 'function') {
                                    window.displayPatternInfo('customer', response.data.pattern_code, response.data.pattern_details);
                                }
                            }
                            
                            alert(response.data.message + '\n' + 'متن پیام و کد پترن ذخیره شد.');
                        } else {
                            alert(response.data.message || 'خطا در ساخت پترن');
                        }
                    }, 'json').fail(function(xhr, status, error) {
                        btn.prop('disabled', false).text(originalText);
                        alert('خطا در ارتباط با سرور: ' + error);
                    });
                });
                
                // Function to display pattern info (make it globally accessible)
                window.displayPatternInfo = function(type, patternCode, patternDetails) {
                    var infoBox = type === 'admin' ? $('#pattern_info_admin') : $('#pattern_info_customer');
                    var infoContent = type === 'admin' ? $('#pattern_info_admin_content') : $('#pattern_info_customer_content');
                    
                    var html = '<div style="display: grid; grid-template-columns: auto 1fr; gap: 10px 15px; align-items: start;">';
                    
                    // Pattern Code
                    html += '<span class="dashicons dashicons-tag" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; color: #646970;"></span>';
                    html += '<div><strong style="color: #1d2327;">کد پترن:</strong> <code style="background: #fff; padding: 2px 6px; border-radius: 3px; font-family: monospace;">' + patternCode + '</code></div>';
                    
                    if (patternDetails && typeof patternDetails === 'object') {
                        // patternDetails is already the data object (extracted in PHP)
                        var data = patternDetails;
                        
                        // Status
                        if (data.status !== undefined) {
                            var statusText = '';
                            var statusIcon = '';
                            var statusColor = '';
                            
                            switch(data.status) {
                                case 'approved':
                                case 'active':
                                    statusText = 'تایید شده';
                                    statusIcon = 'dashicons-yes-alt';
                                    statusColor = '#00a32a';
                                    break;
                                case 'pending':
                                case 'waiting':
                                    statusText = 'در انتظار تایید';
                                    statusIcon = 'dashicons-clock';
                                    statusColor = '#dba617';
                                    break;
                                case 'rejected':
                                case 'failed':
                                    statusText = 'رد شده';
                                    statusIcon = 'dashicons-dismiss';
                                    statusColor = '#d63638';
                                    break;
                                default:
                                    statusText = data.status || 'نامشخص';
                                    statusIcon = 'dashicons-info';
                                    statusColor = '#646970';
                            }
                            
                            html += '<span class="dashicons ' + statusIcon + '" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; color: ' + statusColor + ';"></span>';
                            html += '<div><strong style="color: #1d2327;">وضعیت:</strong> <span style="color: ' + statusColor + '; font-weight: 600;">' + statusText + '</span></div>';
                        }
                        
                        // Text
                        if (data.text) {
                            html += '<span class="dashicons dashicons-text" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; color: #646970;"></span>';
                            html += '<div><strong style="color: #1d2327;">متن:</strong> <span style="color: #50575e;">' + data.text + '</span></div>';
                        }
                        
                        // Description
                        if (data.description) {
                            html += '<span class="dashicons dashicons-edit" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; color: #646970;"></span>';
                            html += '<div><strong style="color: #1d2327;">توضیحات:</strong> <span style="color: #50575e;">' + data.description + '</span></div>';
                        }
                        
                        // Shared
                        if (data.shared !== undefined) {
                            html += '<span class="dashicons dashicons-share" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; color: #646970;"></span>';
                            html += '<div><strong style="color: #1d2327;">اشتراک‌گذاری:</strong> <span style="color: #50575e;">' + (data.shared ? 'بله' : 'خیر') + '</span></div>';
                        }
                        
                        // Variables
                        if (data.attributes && Array.isArray(data.attributes) && data.attributes.length > 0) {
                            html += '<span class="dashicons dashicons-list-view" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; color: #646970;"></span>';
                            html += '<div><strong style="color: #1d2327;">متغیرها:</strong> <div style="margin-top: 5px;">';
                            data.attributes.forEach(function(attr, index) {
                                var varName = attr.name || attr.var || '';
                                var varType = attr.type || '';
                                var varLength = attr.length || '';
                                html += '<span style="display: inline-block; background: #fff; padding: 4px 8px; margin: 3px 5px 3px 0; border-radius: 4px; border: 1px solid #ddd; font-size: 12px;">';
                                html += '<strong>' + varName + '</strong> <span style="color: #646970;">(' + varType + ', ' + varLength + ')</span>';
                                html += '</span>';
                            });
                            html += '</div></div>';
                        } else if (data.vars && Array.isArray(data.vars) && data.vars.length > 0) {
                            html += '<span class="dashicons dashicons-list-view" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; color: #646970;"></span>';
                            html += '<div><strong style="color: #1d2327;">متغیرها:</strong> <div style="margin-top: 5px;">';
                            data.vars.forEach(function(attr, index) {
                                var varName = attr.name || attr.var || '';
                                var varType = attr.type || '';
                                var varLength = attr.length || '';
                                html += '<span style="display: inline-block; background: #fff; padding: 4px 8px; margin: 3px 5px 3px 0; border-radius: 4px; border: 1px solid #ddd; font-size: 12px;">';
                                html += '<strong>' + varName + '</strong> <span style="color: #646970;">(' + varType + ', ' + varLength + ')</span>';
                                html += '</span>';
                            });
                            html += '</div></div>';
                        }
                    }
                    
                    html += '</div>';
                    infoContent.html(html);
                    infoBox.show();
                };
                
                // Also keep local reference
                var displayPatternInfo = window.displayPatternInfo;
                
                // Load saved pattern details on page load
                function loadSavedPatterns() {
                    // Load admin pattern - check both input field and saved value
                    var adminCode = jQuery('#pattern_code_admin_input').val() || feedPatternCodes.admin;
                    if (adminCode) {
                        jQuery.post(ajaxurl, {
                            action: 'farazsms_get_pattern',
                            farazsms_get_pattern: '<?php echo wp_create_nonce('farazsms_get_pattern'); ?>',
                            pattern_code: adminCode
                        }, function(response) {
                            if (response.success && response.data && response.data.pattern_details) {
                                if (typeof displayPatternInfo === 'function') {
                                    displayPatternInfo('admin', adminCode, response.data.pattern_details);
                                } else if (typeof window.displayPatternInfo === 'function') {
                                    window.displayPatternInfo('admin', adminCode, response.data.pattern_details);
                                }
                            }
                        }, 'json').fail(function() {
                        });
                    }
                    
                    // Load customer pattern - check both input field and saved value
                    var customerCode = jQuery('#pattern_code_customer_input').val() || feedPatternCodes.customer;
                    if (customerCode) {
                        jQuery.post(ajaxurl, {
                            action: 'farazsms_get_pattern',
                            farazsms_get_pattern: '<?php echo wp_create_nonce('farazsms_get_pattern'); ?>',
                            pattern_code: customerCode
                        }, function(response) {
                            if (response.success && response.data && response.data.pattern_details) {
                                if (typeof displayPatternInfo === 'function') {
                                    displayPatternInfo('customer', customerCode, response.data.pattern_details);
                                } else if (typeof window.displayPatternInfo === 'function') {
                                    window.displayPatternInfo('customer', customerCode, response.data.pattern_details);
                                }
                            }
                        }, 'json').fail(function() {
                        });
                    }
                }
                
                // Load patterns when document is ready
                loadSavedPatterns();
                
                // Also load patterns after form save (when page reloads with updated parameter)
                if (window.location.search.indexOf('updated=true') !== -1) {
                    setTimeout(function() {
                        loadSavedPatterns();
                    }, 500);
                }
            });
            
            // Make loadSavedPatterns globally accessible (use the same logic as local function)
            window.loadSavedPatterns = function() {
                // Load admin pattern - check both input field and saved value
                var adminCode = jQuery('#pattern_code_admin_input').val() || feedPatternCodes.admin;
                if (adminCode) {
                    jQuery.post(ajaxurl, {
                        action: 'farazsms_get_pattern',
                        farazsms_get_pattern: '<?php echo wp_create_nonce('farazsms_get_pattern'); ?>',
                        pattern_code: adminCode
                    }, function(response) {
                        if (response.success && response.data && response.data.pattern_details) {
                            if (typeof window.displayPatternInfo === 'function') {
                                window.displayPatternInfo('admin', adminCode, response.data.pattern_details);
                            }
                        }
                        }, 'json').fail(function() {
                        });
                }
                
                // Load customer pattern - check both input field and saved value
                var customerCode = jQuery('#pattern_code_customer_input').val() || feedPatternCodes.customer;
                if (customerCode) {
                    jQuery.post(ajaxurl, {
                        action: 'farazsms_get_pattern',
                        farazsms_get_pattern: '<?php echo wp_create_nonce('farazsms_get_pattern'); ?>',
                        pattern_code: customerCode
                    }, function(response) {
                        if (response.success && response.data && response.data.pattern_details) {
                            if (typeof window.displayPatternInfo === 'function') {
                                window.displayPatternInfo('customer', customerCode, response.data.pattern_details);
                            }
                        }
                        }, 'json').fail(function() {
                        });
                }
            };
        </script>
        
        <style type="text/css">
            /* Conditional Logic Styles */
            .delete_admin_condition, .add_admin_condition, .delete_client_condition, .add_client_condition {
                text-decoration: none !important;
                color: #2271b1;
                margin: 0 5px;
            }
            .delete_admin_condition:hover, .add_admin_condition:hover, .delete_client_condition:hover, .add_client_condition:hover {
                color: #135e96;
            }
            .condition_field_value {
                width: 200px !important;
                padding: 5px 8px !important;
            }
            .gf_adminsms_conditional_div, .gf_clientsms_conditional_div {
                margin: 8px 0;
                padding: 8px;
                background: #f8f9fa;
                border-radius: 4px;
                border: 1px solid #ddd;
            }
            
            /* Form Table Styles */
            table.gforms_form_settings {
                margin-top: 20px;
            }
            table.gforms_form_settings th {
                padding: 15px 20px 15px 0;
                width: 200px;
                vertical-align: top;
            }
            table.gforms_form_settings td {
                padding: 15px 0;
                vertical-align: top;
            }
            .gf_settings_subgroup_title {
                margin: 25px 0 15px 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #ddd;
                font-size: 16px;
                font-weight: 600;
            }
            
            /* Pattern Info Box Styles */
            #pattern_info_admin, #pattern_info_customer {
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            #pattern_info_admin_content code, #pattern_info_customer_content code {
                font-size: 13px;
                color: #2271b1;
            }
            
            /* Textarea and Input Spacing */
            textarea[name="gf_farazsms_message"],
            textarea[name="gf_farazsms_message_c"] {
                margin-bottom: 12px;
            }
            input[name="gf_farazsms_from"],
            input[name="gf_farazsms_to"],
            input[name="gf_farazsms_to_c"] {
                margin-bottom: 5px;
            }
            
            /* Button spacing */
            .button-primary {
                margin-left: 5px;
            }
        </style>
        
        <script type="text/javascript">
            var form = [];
            <?php 
            if (!empty($_get_form_id)) {
                if (class_exists('RGFormsModel')) {
                    $form_meta = RGFormsModel::get_form_meta($_get_form_id);
                } elseif (class_exists('GFAPI')) {
                    $form_meta = GFAPI::get_form($_get_form_id);
                    if (is_object($form_meta) && isset($form_meta->id)) {
                        $form_meta = RGFormsModel::get_form_meta($form_meta->id);
                    }
                }
            } else {
                $form_meta = array();
            }
            ?>
            var form = [];
            form = <?php echo !empty($form_meta) ? (function_exists('GFCommon') && method_exists('GFCommon', 'json_encode') ? GFCommon::json_encode($form_meta) : json_encode($form_meta)) : '[]'; ?>;
            window.form = form; // Make form available globally
            
            jQuery(document).ready(function ($) {
                var delete_link;
                
                delete_link = $('.delete_admin_condition');
                if (delete_link.length === 1)
                    delete_link.hide();
                
                delete_link = $('.delete_client_condition');
                if (delete_link.length === 1)
                    delete_link.hide();
                
                // Admin conditional logic handlers
                $(document.body).on('change', '.gf_adminsms_conditional_field_id', function () {
                    var id = $(this).attr('id');
                    id = id.replace('gf_adminsms_', '').replace('__conditional_field_id', '');
                    var selectedOperator = $('#gf_adminsms_' + id + '__conditional_operator').val();
                    $('#gf_adminsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_adminsms_" + id + "__conditional", jQuery(this).val(), selectedOperator, "", 20, id));
                }).on('change', '.gf_adminsms_conditional_operator', function () {
                    var id = $(this).attr('id');
                    id = id.replace('gf_adminsms_', '').replace('__conditional_operator', '');
                    var selectedOperator = $(this).val();
                    var field_id = $('#gf_adminsms_' + id + '__conditional_field_id').val();
                    $('#gf_adminsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_adminsms_" + id + "__conditional", field_id, selectedOperator, "", 20, id));
                }).on('click', '.add_admin_condition', function (e) {
                    e.preventDefault();
                    var parent_div = $(this).parent('.gf_adminsms_conditional_div');
                    var counter = $('#gf_adminsms_conditional_counter');
                    var new_id = parseInt(counter.val()) + 1;
                    var content = parent_div[0].outerHTML
                        .replace(new RegExp('gf_adminsms_\\d+__', 'g'), ('gf_adminsms_' + new_id + '__'))
                        .replace(new RegExp('\\[\\d+\\]', 'g'), ('[' + new_id + ']'));
                    counter.val(new_id);
                    parent_div.after(content);
                    RefreshConditionRow("gf_adminsms_" + new_id + "__conditional", "", "is", "", new_id);
                    $('.delete_admin_condition').show();
                    return false;
                }).on('click', '.delete_admin_condition', function (e) {
                    e.preventDefault();
                    $(this).parent('.gf_adminsms_conditional_div').remove();
                    var delete_link = $('.delete_admin_condition');
                    if (delete_link.length === 1)
                        delete_link.hide();
                    return false;
                });
                
                // Client conditional logic handlers
                $(document.body).on('change', '.gf_clientsms_conditional_field_id', function () {
                    var id = $(this).attr('id');
                    id = id.replace('gf_clientsms_', '').replace('__conditional_field_id', '');
                    var selectedOperator = $('#gf_clientsms_' + id + '__conditional_operator').val();
                    $('#gf_clientsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_clientsms_" + id + "__conditional", jQuery(this).val(), selectedOperator, "", 20, id));
                }).on('change', '.gf_clientsms_conditional_operator', function () {
                    var id = $(this).attr('id');
                    id = id.replace('gf_clientsms_', '').replace('__conditional_operator', '');
                    var selectedOperator = $(this).val();
                    var field_id = $('#gf_clientsms_' + id + '__conditional_field_id').val();
                    $('#gf_clientsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_clientsms_" + id + "__conditional", field_id, selectedOperator, "", 20, id));
                }).on('click', '.add_client_condition', function (e) {
                    e.preventDefault();
                    var parent_div = $(this).parent('.gf_clientsms_conditional_div');
                    var counter = $('#gf_clientsms_conditional_counter');
                    var new_id = parseInt(counter.val()) + 1;
                    var content = parent_div[0].outerHTML
                        .replace(new RegExp('gf_clientsms_\\d+__', 'g'), ('gf_clientsms_' + new_id + '__'))
                        .replace(new RegExp('\\[\\d+\\]', 'g'), ('[' + new_id + ']'));
                    counter.val(new_id);
                    parent_div.after(content);
                    RefreshConditionRow("gf_clientsms_" + new_id + "__conditional", "", "is", "", new_id);
                    $('.delete_client_condition').show();
                    return false;
                }).on('click', '.delete_client_condition', function (e) {
                    e.preventDefault();
                    $(this).parent('.gf_clientsms_conditional_div').remove();
                    var delete_link = $('.delete_client_condition');
                    if (delete_link.length === 1)
                        delete_link.hide();
                    return false;
                });
                
                // Initialize conditional field dropdowns
                $('.gf_adminsms_conditional_field_id').each(function() {
                    var id = $(this).attr('id');
                    id = id.replace('gf_adminsms_', '').replace('__conditional_field_id', '');
                    var selectedField = $(this).val() || '';
                    var selectedOperator = $('#gf_adminsms_' + id + '__conditional_operator').val() || 'is';
                    var selectedValue = $('#gf_adminsms_' + id + '__conditional_value').val() || '';
                    $(this).html(GetSelectableFields(selectedField, 20));
                    $(this).val(selectedField);
                    if (selectedField) {
                        $('#gf_adminsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_adminsms_" + id + "__conditional", selectedField, selectedOperator, selectedValue, 20, id));
                    }
                });
                
                $('.gf_clientsms_conditional_field_id').each(function() {
                    var id = $(this).attr('id');
                    id = id.replace('gf_clientsms_', '').replace('__conditional_field_id', '');
                    var selectedField = $(this).val() || '';
                    var selectedOperator = $('#gf_clientsms_' + id + '__conditional_operator').val() || 'is';
                    var selectedValue = $('#gf_clientsms_' + id + '__conditional_value').val() || '';
                    $(this).html(GetSelectableFields(selectedField, 20));
                    $(this).val(selectedField);
                    if (selectedField) {
                        $('#gf_clientsms_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_clientsms_" + id + "__conditional", selectedField, selectedOperator, selectedValue, 20, id));
                    }
                });
            });
            
            function RefreshConditionRow(input, selectedField, selectedOperator, selectedValue, index) {
                var field_id = jQuery("#" + input + "_field_id");
                field_id.html(GetSelectableFields(selectedField, 20));
                var optinConditionField = field_id.val();
                if (optinConditionField) {
                    jQuery("#" + input + "_value_container").html(GetConditionalFieldValues("" + input + "", optinConditionField, selectedOperator, selectedValue, 20, index));
                }
            }
            
            function GetSelectableFields(selectedFieldId, labelMaxCharacters) {
                var str = "";
                if (typeof form.fields !== "undefined") {
                    var inputType;
                    var fieldLabel;
                    for (var i = 0; i < form.fields.length; i++) {
                        fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                        inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                        if (IsConditionalLogicField(form.fields[i])) {
                            var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                            str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                        }
                    }
                }
                return str;
            }
            
            function TruncateMiddle(text, maxCharacters) {
                if (!text)
                    return "";
                if (text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }
            
            function GetFieldById(fieldId) {
                for (var i = 0; i < form.fields.length; i++) {
                    if (form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }
            
            function IsConditionalLogicField(field) {
                var inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                    "post_tags", "post_custom_field", "post_content", "post_excerpt"];
                var index = jQuery.inArray(inputType, supported_fields);
                return index >= 0;
            }
            
            function GetConditionalFieldValues(input, fieldId, selectedOperator, selectedValue, labelMaxCharacters, index) {
                if (!fieldId)
                    return "<input type='text' class='condition_field_value' style='padding:3px' placeholder='یک مقدار وارد کنید' id='" + input + "_value' name='" + input.replace(new RegExp('_\\d+__', 'g'), '_').replace('__conditional', '') + "_value[" + index + "]' value='" + (selectedValue || '') + "'>";
                var str = "";
                var name = (input.replace(new RegExp('_\\d+__', 'g'), '_')) + "_value[" + index + "]";
                var field = GetFieldById(fieldId);
                if (!field)
                    return "<input type='text' class='condition_field_value' style='padding:3px' placeholder='یک مقدار وارد کنید' id='" + input + "_value' name='" + name + "' value='" + (selectedValue || '') + "'>";
                
                var is_text = false;
                
                if (selectedOperator == '' || selectedOperator == 'is' || selectedOperator == 'isnot') {
                    if (field["type"] == "post_category" && field["displayAllCategories"]) {
                        // Post category dropdown - simplified to text input for now
                        is_text = true;
                    }
                    else if (field.choices) {
                        str += "<select name='" + name + "' id='" + input + "_value' class='condition_field_value'>";
                        for (var i = 0; i < field.choices.length; i++) {
                            var choiceValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                            var choiceText = field.choices[i].text;
                            var selected = (choiceValue == selectedValue || choiceText == selectedValue) ? "selected='selected'" : "";
                            str += "<option value='" + choiceValue + "' " + selected + ">" + choiceText + "</option>";
                        }
                        str += "</select>";
                    } else {
                        is_text = true;
                    }
                } else {
                    is_text = true;
                }
                
                if (is_text) {
                    str = "<input type='text' class='condition_field_value' style='padding:3px' placeholder='یک مقدار وارد کنید' id='" + input + "_value' name='" + name + "' value='" + (selectedValue || '') + "'>";
                }
                
                return str;
            }
        </script>
        <?php
    }

    /**
     * Extract merge tags mapping (order is important for pattern variables)
     *
     * @param string $message Message text with Gravity Forms merge tags
     * @return array Array of merge tags in order: array('{نام (نام):1.3}', '{نام (نام خانوادگی):1.6}', ...)
     */
    private static function extract_merge_tags_mapping($message) {
        $merge_tags = array();
        // Pattern to match all Gravity Forms merge tags: {LABEL:ID}, {user:field}, {form_title}, etc.
        preg_match_all('/{([^}]+)}/i', $message, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $merge_tags[] = $match[0]; // Full merge tag: {نام (نام):1.3}, {user:user_login}, {form_title}, etc.
        }
        
        return $merge_tags;
    }

    /**
     * Extract merge tags from message text and convert to pattern variables
     *
     * @param string $message Message text with Gravity Forms merge tags
     * @return array Array of pattern attributes
     */
    private static function extract_pattern_variables($message) {
        $vars = array();
        
        // Pattern to match all Gravity Forms merge tags: {LABEL:ID}, {user:field}, {form_title}, etc.
        preg_match_all('/{([^}]+)}/i', $message, $matches, PREG_SET_ORDER);
        
        $var_counter = 1;
        foreach ($matches as $match) {
            $tag = isset( $match[1] ) ? trim( $match[1] ) : '';
            $length = ( $tag === 'all_items' || strpos( $tag, 'all_items_' ) === 0 ) ? 120 : 25;
            $var_name = 'var' . $var_counter;
            $vars[] = array(
                'var' => $var_name, // API expects 'var' not 'name'
                'length' => $length,
                'type' => 'str' // API expects 'int', 'str', or 'date' (not 'string')
            );
            $var_counter++;
        }
        
        return $vars;
    }
    
    /**
     * Convert Gravity Forms merge tags to pattern format (%var%, %var2%, etc.)
     *
     * @param string $message Message text with Gravity Forms merge tags
     * @return string Message text with pattern variables
     */
    private static function convert_to_pattern_format($message) {
        $var_counter = 1;
        
        // Replace all merge tags with pattern variables
        $pattern = '/{([^}]+)}/i';
        $message = preg_replace_callback($pattern, function($matches) use (&$var_counter) {
            $var_name = '%var' . $var_counter . '%';
            $var_counter++;
            return $var_name;
        }, $message);
        
        return $message;
    }

    /**
     * AJAX handler for creating pattern
     */
    public static function create_pattern_ajax() {
        // Check nonce
        $nonce = isset($_REQUEST['farazsms_create_pattern']) ? $_REQUEST['farazsms_create_pattern'] : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'farazsms_create_pattern')) {
            wp_send_json_error(array('message' => __('خطای امنیتی', 'farazsms-next')));
            return;
        }
        
        // Check permissions
        $has_permission = false;
        if (class_exists('GFCommon')) {
            $has_permission = GFCommon::current_user_can_any('gravityforms_edit_forms');
        } else {
            $has_permission = current_user_can('gravityforms_edit_forms') || current_user_can('gform_full_access') || current_user_can('manage_options');
        }
        
        if (!$has_permission) {
            wp_send_json_error(array('message' => __('شما دسترسی لازم را ندارید', 'farazsms-next')));
            return;
        }
        
        $feed_id = isset($_REQUEST['feed_id']) ? absint($_REQUEST['feed_id']) : 0;
        $message = isset($_REQUEST['message']) ? $_REQUEST['message'] : '';
        // Decode URL encoded message (jQuery.post converts spaces to +)
        $message = str_replace('+', ' ', $message);
        $message = urldecode($message);
        $message = trim($message);
        $type = isset($_REQUEST['type']) ? sanitize_text_field($_REQUEST['type']) : 'admin'; // 'admin' or 'customer'
        
        if (empty($message)) {
            wp_send_json_error(array('message' => __('متن پیام نمی‌تواند خالی باشد', 'farazsms-next')));
            return;
        }

        // Append site URL at the end of message automatically (per requirement)
        $site_url = home_url();
        if (!empty($site_url)) {
            $message .= "\n" . $site_url;
        }
        
        // Load SMS API class
        if (!class_exists('FarazSMS_Next_SMS_API')) {
            require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-sms-api.php';
        }
        
        $api = new FarazSMS_Next_SMS_API();
        
        // Extract variables from message
        $vars = self::extract_pattern_variables($message);
        
        // Convert merge tags to pattern format
        $pattern_text = self::convert_to_pattern_format($message);
        
        // If no merge tags found, use original message
        if (empty($pattern_text)) {
            $pattern_text = $message;
        }
        
        $description = __('پترن ساخته شده از Gravity Forms', 'farazsms-next') . ' - ' . ($type === 'admin' ? __('پیام مدیر', 'farazsms-next') : __('پیام کاربر', 'farazsms-next'));
        $existing_code = '';
        if (!empty($feed_id) && $feed_id > 0) {
            $feed = FarazSMS_Next_Gravity_Forms_SMS_SQL::get_feed($feed_id);
            if (!empty($feed['meta']['pattern_code_' . $type])) {
                $existing_code = trim($feed['meta']['pattern_code_' . $type]);
            }
        }
        if (!empty($existing_code)) {
            $result = $api->update_pattern($existing_code, $pattern_text, false, home_url(), $vars, $description, 255);
        } else {
            $result = $api->create_pattern($pattern_text, false, home_url(), $vars, $description, 255);
        }

        // اگر کد ذخیره‌شده در پنل حذف شده باشد، بعد از خطای update یک create جدید انجام بده
        if (
            !empty($existing_code) &&
            (!is_array($result) || !isset($result['status']) || $result['status'] !== 'success') &&
            class_exists('FarazSMS_Next_SMS_API') &&
            FarazSMS_Next_SMS_API::is_pattern_not_found_response($result)
        ) {
            $result = $api->create_pattern($pattern_text, false, home_url(), $vars, $description, 255);
        }
        
        if ($result && isset($result['status']) && $result['status'] === 'success') {
            // Extract pattern code from response; on update API may not return code, use existing
            $pattern_code = '';
            if (isset($result['data'])) {
                if (is_array($result['data']) && isset($result['data']['code'])) {
                    $pattern_code = $result['data']['code'];
                } elseif (is_string($result['data'])) {
                    $pattern_code = $result['data'];
                }
            }
            if (empty($pattern_code) && !empty($existing_code)) {
                $pattern_code = $existing_code;
            }
            if (empty($pattern_code)) {
                wp_send_json_error(array('message' => __('کد پترن از پاسخ API استخراج نشد', 'farazsms-next')));
                return;
            }
            
            // Save pattern code and message to feed meta if feed_id is provided
            if (!empty($feed_id) && $feed_id > 0) {
                $feed = FarazSMS_Next_Gravity_Forms_SMS_SQL::get_feed($feed_id);
                if ($feed) {
                    // Ensure meta is an array
                    if (!is_array($feed['meta'])) {
                        $feed['meta'] = array();
                    }
                    // Save pattern code
                    $feed['meta']['pattern_code_' . $type] = $pattern_code;
                    // Save message text (admin or customer)
                    if ($type === 'admin') {
                        $feed['meta']['message'] = $message;
                    } else {
                        $feed['meta']['message_c'] = $message;
                    }
                    // Save merge tags mapping (order is important for pattern variables)
                    $merge_tags_mapping = self::extract_merge_tags_mapping($message);
                    $feed['meta']['pattern_merge_tags_mapping_' . $type] = $merge_tags_mapping;
                    FarazSMS_Next_Gravity_Forms_SMS_SQL::update_feed($feed_id, $feed['form_id'], $feed['is_active'], $feed['meta']);
                }
            }
            
            // Get pattern details
            $pattern_details_result = $api->get_pattern_details($pattern_code);
            $pattern_details = null;
            if ($pattern_details_result && isset($pattern_details_result['status']) && $pattern_details_result['status'] === 'success') {
                // Extract data from response
                $pattern_details = isset($pattern_details_result['data']) ? $pattern_details_result['data'] : $pattern_details_result;
            }
            
            wp_send_json_success(array(
                'message' => __('پترن با موفقیت ساخته شد', 'farazsms-next'),
                'pattern_code' => $pattern_code,
                'pattern_details' => $pattern_details
            ));
        } else {
            $error_message = __('خطا در ساخت پترن', 'farazsms-next');
            if ($result === false || ! is_array($result)) {
                $error_message .= ': ' . __('کلید دسترسی را در تنظیمات فراز اس ام اس وارد کنید یا اتصال به API را بررسی کنید.', 'farazsms-next');
            } elseif (isset($result['message'])) {
                $error_message .= ': ' . ( is_string($result['message']) ? $result['message'] : implode(', ', (array) $result['message']) );
            } elseif (isset($result['messages'])) {
                if (is_array($result['messages'])) {
                    $error_message .= ': ' . implode(', ', $result['messages']);
                } else {
                    $error_message .= ': ' . $result['messages'];
                }
            }
            wp_send_json_error(array('message' => $error_message));
        }
    }
    
    /**
     * AJAX handler for getting pattern details
     */
    public static function get_pattern_ajax() {
        // Check nonce
        $nonce = isset($_REQUEST['farazsms_get_pattern']) ? $_REQUEST['farazsms_get_pattern'] : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'farazsms_get_pattern')) {
            wp_send_json_error(array('message' => __('خطای امنیتی', 'farazsms-next')));
            return;
        }
        
        // Check permissions
        $has_permission = false;
        if (class_exists('GFCommon')) {
            $has_permission = GFCommon::current_user_can_any('gravityforms_edit_forms');
        } else {
            $has_permission = current_user_can('gravityforms_edit_forms') || current_user_can('gform_full_access') || current_user_can('manage_options');
        }
        
        if (!$has_permission) {
            wp_send_json_error(array('message' => __('شما دسترسی لازم را ندارید', 'farazsms-next')));
            return;
        }
        
        $pattern_code = isset($_REQUEST['pattern_code']) ? sanitize_text_field($_REQUEST['pattern_code']) : '';
        
        if (empty($pattern_code)) {
            wp_send_json_error(array('message' => __('کد پترن ارائه نشده است', 'farazsms-next')));
            return;
        }
        
        // Load SMS API class
        if (!class_exists('FarazSMS_Next_SMS_API')) {
            require_once FARAZSMS_NEXT_PLUGIN_DIR . 'includes/class-sms-api.php';
        }
        
        $api = new FarazSMS_Next_SMS_API();
        $result = $api->get_pattern_details($pattern_code);
        
        if ($result && isset($result['status']) && $result['status'] === 'success') {
            // Return the data part of the response
            $pattern_data = isset($result['data']) ? $result['data'] : $result;
            wp_send_json_success(array('pattern_details' => $pattern_data));
        } else {
            $error_message = __('خطا در دریافت اطلاعات پترن', 'farazsms-next');
            if (isset($result['messages'])) {
                if (is_array($result['messages'])) {
                    $error_message .= ': ' . implode(', ', $result['messages']);
                } else {
                    $error_message .= ': ' . $result['messages'];
                }
            }
            wp_send_json_error(array('message' => $error_message));
        }
    }

    /**
     * AJAX handler for form selection
     */
    public static function select_form_ajax() {
        // Check nonce
        $nonce = isset($_REQUEST['farazsms_select_form']) ? $_REQUEST['farazsms_select_form'] : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'farazsms_select_form')) {
            wp_send_json_error(array('message' => __('خطای امنیتی', 'farazsms-next')));
            return;
        }
        
        $form_id = isset($_REQUEST['form_id']) ? absint($_REQUEST['form_id']) : 0;
        if (empty($form_id)) {
            wp_send_json_error(array('message' => __('فرم انتخاب نشده است', 'farazsms-next')));
            return;
        }
        
        // Get form - always use RGFormsModel to get array format
        if (class_exists('RGFormsModel')) {
            $form = RGFormsModel::get_form_meta($form_id);
        } else {
            wp_send_json_error(array('message' => __('RGFormsModel در دسترس نیست', 'farazsms-next')));
            return;
        }
        
        if (is_wp_error($form) || empty($form)) {
            wp_send_json_error(array('message' => __('فرم یافت نشد', 'farazsms-next')));
            return;
        }
        
        // Get fields for merge tags
        $fields_html = self::get_form_fields_merge($form);
        
        // Get customer phone field
        $customer_field_html = self::get_client_information($form, array());
        
        // Get form JSON for conditional logic
        $form_json = '';
        if (!empty($form)) {
            if (function_exists('GFCommon') && method_exists('GFCommon', 'json_encode')) {
                $form_json = GFCommon::json_encode($form);
            } else {
                $form_json = json_encode($form);
            }
        }
        
        $result = array(
            'fields' => $fields_html,
            'customer_field' => $customer_field_html,
            'form_json' => $form_json
        );
        
        wp_send_json_success($result);
    }
    
    /**
     * Get form fields for merge tags
     */
    private static function get_form_fields_merge($form) {
        // Ensure form is array format (RGFormsModel format)
        if (is_object($form)) {
            // If it's an object, try to get form ID and fetch as array
            if (isset($form->id) && class_exists('RGFormsModel')) {
                $form = RGFormsModel::get_form_meta($form->id);
            } elseif (isset($form->id) && class_exists('GFAPI')) {
                // Fallback to GFAPI but convert result
                $form_data = GFAPI::get_form($form->id);
                if (!is_wp_error($form_data)) {
                    $form = $form_data;
                }
            }
        }
        
        $str = '<option value="">' . __('برچسب‌های ادغام', 'farazsms-next') . '</option>';
        $required_fields = array();
        $optional_fields = array();
        $pricing_fields = array();
        
        if (!empty($form['fields']) && is_array($form['fields'])) {
            foreach ((array)$form['fields'] as $field) {
                if (isset($field['displayOnly']) && $field['displayOnly']) {
                    continue;
                }
                $input_type = RGFormsModel::get_input_type($field);
                if (isset($field['isRequired']) && $field['isRequired']) {
                    switch ($input_type) {
                        case "name":
                            if (isset($field['nameFormat']) && $field['nameFormat'] == 'extended') {
                                $prefix = GFCommon::get_input($field, $field['id'] + 0.2);
                                $suffix = GFCommon::get_input($field, $field['id'] + 0.8);
                                $optional_field = $field;
                                $optional_field['inputs'] = array($prefix, $suffix);
                                $optional_fields[] = $optional_field;
                                unset($field['inputs'][0]);
                                unset($field['inputs'][3]);
                            }
                            $required_fields[] = $field;
                            break;
                        default:
                            $required_fields[] = $field;
                    }
                } else {
                    $optional_fields[] = $field;
                }
                if (isset($field['type']) && GFCommon::is_pricing_field($field['type'])) {
                    $pricing_fields[] = $field;
                }
            }
        }
        
        // Required fields
        if (!empty($required_fields)) {
            $str .= '<optgroup label="' . __('فیلدهای اجباری', 'farazsms-next') . '">';
            foreach ($required_fields as $field) {
                $str .= self::get_fields_options($field);
            }
            $str .= '</optgroup>';
        }
        
        // Optional fields
        if (!empty($optional_fields)) {
            $str .= '<optgroup label="' . __('فیلدهای اختیاری', 'farazsms-next') . '">';
            foreach ($optional_fields as $field) {
                $str .= self::get_fields_options($field);
            }
            $str .= '</optgroup>';
        }
        
        // Pricing fields
        if (!empty($pricing_fields)) {
            $str .= '<optgroup label="' . __('فیلدهای قیمت', 'farazsms-next') . '">';
            foreach ($pricing_fields as $field) {
                $str .= self::get_fields_options($field);
            }
            $str .= '</optgroup>';
        }
        
        // Other options
        $str .= '<optgroup label="' . __('سایر', 'farazsms-next') . '">';
        $str .= '<option value="{payment_gateway}">' . __('درگاه پرداخت', 'farazsms-next') . '</option>';
        $str .= '<option value="{payment_status}">' . __('وضعیت پرداخت', 'farazsms-next') . '</option>';
        $str .= '<option value="{transaction_id}">' . __('شماره تراکنش', 'farazsms-next') . '</option>';
        $str .= '<option value="{ip}">IP</option>';
        $str .= '<option value="{date_mdy}">' . __('تاریخ (mm/dd/yyyy)', 'farazsms-next') . '</option>';
        $str .= '<option value="{date_dmy}">' . __('تاریخ (dd/mm/yyyy)', 'farazsms-next') . '</option>';
        $str .= '<option value="{embed_post:ID}">' . __('قرار دادن شماره نوشته / برگه', 'farazsms-next') . '</option>';
        $str .= '<option value="{embed_post:post_title}">' . __('قرار دادن شماره عنوان نوشته / برگه', 'farazsms-next') . '</option>';
        $str .= '<option value="{embed_url}">' . __('قرار دادن لینک', 'farazsms-next') . '</option>';
        $str .= '<option value="{entry_id}">' . __('شماره پیام ورودی', 'farazsms-next') . '</option>';
        $str .= '<option value="{entry_url}">' . __('لینک پیام ورودی', 'farazsms-next') . '</option>';
        $str .= '<option value="{form_id}">' . __('شماره فرم', 'farazsms-next') . '</option>';
        $str .= '<option value="{form_title}">' . __('عنوان فرم', 'farazsms-next') . '</option>';
        $str .= '<option value="{user_agent}">' . __('عامل کاربری HTTP', 'farazsms-next') . '</option>';
        
        // Check if form has post fields
        if (class_exists('GFCommon') && !empty($form['fields']) && GFCommon::has_post_field($form['fields'])) {
            $str .= '<option value="{post_id}">' . __('شماره نوشته', 'farazsms-next') . '</option>';
            $str .= '<option value="{post_edit_url}">' . __('لینک ویرایش نوشته', 'farazsms-next') . '</option>';
        }
        
        $str .= '<option value="{user:display_name}">' . __('نام نمایشی کاربر', 'farazsms-next') . '</option>';
        $str .= '<option value="{user:user_email}">' . __('ایمیل کاربر', 'farazsms-next') . '</option>';
        $str .= '<option value="{user:user_login}">' . __('نام کاربری', 'farazsms-next') . '</option>';
        $str .= '</optgroup>';
        
        return $str;
    }
    
    /**
     * Get fields options for merge tags
     */
    private static function get_fields_options($field, $max_label_size = 100) {
        $str = "";
        if (is_array($field['inputs'])) {
            foreach ((array)$field['inputs'] as $input) {
                $str .= "<option value='{" . esc_attr(GFCommon::get_label($field, $input["id"])) . ":" . $input["id"] . "}'>" . esc_html(GFCommon::truncate_middle(GFCommon::get_label($field, $input["id"]), $max_label_size)) . "</option>";
            }
        } else {
            $str .= "<option value='{" . esc_html(GFCommon::get_label($field)) . ":" . $field["id"] . "}'>" . esc_html(GFCommon::truncate_middle(GFCommon::get_label($field), $max_label_size)) . "</option>";
        }
        return $str;
    }
    
    /**
     * Get client form fields (all fields, not just phone)
     */
    private static function get_client_form_fields($form) {
        $fields = array();
        if (is_array($form['fields'])) {
            foreach ((array)$form['fields'] as $field) {
                if (isset($field['inputs']) && is_array($field['inputs'])) {
                    foreach ((array)$field['inputs'] as $input) {
                        if (!(GFCommon::is_pricing_field($field['type']) || ($field['type'] == 'total'))) {
                            $fields[] = array($input['id'], GFCommon::get_label($field, $input['id']));
                        }
                    }
                } elseif (empty($field['displayOnly'])) {
                    if (!(GFCommon::is_pricing_field($field['type']) || ($field['type'] == 'total'))) {
                        $fields[] = array($field['id'], GFCommon::get_label($field));
                    }
                }
            }
        }
        return $fields;
    }
    
    /**
     * Get client phone field select (shows all fields, not just phone)
     */
    private static function get_client_information($form, $config) {
        $form_fields = self::get_client_form_fields($form);
        $selected_field = isset($config['meta']['customer_field_clientnum']) ? $config['meta']['customer_field_clientnum'] : '';
        
        $html = '<select name="farazsms_customer_field_clientnum">';
        $html .= '<option value="">' . __('یک فیلد انتخاب کنید', 'farazsms-next') . '</option>';
        
        foreach ((array)$form_fields as $field) {
            $field_id = $field[0];
            $field_label = isset($field[1]) ? esc_html($field[1]) : '';
            
            // Truncate label if needed
            if (class_exists('GFCommon')) {
                $field_label = GFCommon::truncate_middle($field_label, 40);
            } else {
                $field_label = mb_strlen($field_label) > 40 ? mb_substr($field_label, 0, 40) . '...' : $field_label;
            }
            
            $selected = ($field_id == $selected_field) ? 'selected' : '';
            $html .= '<option value="' . esc_attr($field_id) . '" ' . $selected . '>' . $field_label . '</option>';
        }
        
        $html .= '</select>';
        return $html;
    }
}

