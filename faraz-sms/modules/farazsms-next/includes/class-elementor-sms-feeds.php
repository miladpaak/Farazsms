<?php
/**
 * Elementor SMS Feeds Class
 *
 * Handles feed listing and management
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Elementor_SMS_Feeds {

    /**
     * Constructor
     */
    public static function construct() {
        // AJAX handler for feed activation/deactivation
        add_action('wp_ajax_farazsms_elementor_feed_ajax_active', array(__CLASS__, 'ajax_toggle_active'));
    }

    /**
     * AJAX handler to toggle feed active status
     */
    public static function ajax_toggle_active() {
        check_ajax_referer('farazsms_elementor_feed_ajax_active', 'farazsms_elementor_feed_ajax_active');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('دسترسی مجاز نیست', 'farazsms-next')));
        }

        $id = isset($_POST['feed_id']) ? absint($_POST['feed_id']) : 0;
        $is_active = isset($_POST['is_active']) ? absint($_POST['is_active']) : 0;

        if (empty($id)) {
            wp_send_json_error(array('message' => __('شناسه Feed نامعتبر است', 'farazsms-next')));
        }

        $feed = FarazSMS_Next_Elementor_SMS_SQL::get_feed($id);
        if (!$feed) {
            wp_send_json_error(array('message' => __('Feed یافت نشد', 'farazsms-next')));
        }

        FarazSMS_Next_Elementor_SMS_SQL::update_feed($id, $feed['form_id'], $is_active, $feed['meta']);

        wp_send_json_success();
    }

    /**
     * Display feeds list
     *
     * @param string $arg Context
     */
    public static function feeds($arg = '') {
        $show = $arg !== 'settings';

        // Capability gate — destructive feed actions require admin rights.
        $can_manage = current_user_can( 'manage_options' );

        // Handle delete action
        if ($can_manage && isset($_POST['action']) && $_POST['action'] === 'delete') {
            check_admin_referer('farazsms_elementor_list_action', 'farazsms_elementor_list');
            $id = isset($_POST['action_argument']) ? absint($_POST['action_argument']) : 0;
            if ($id) {
                FarazSMS_Next_Elementor_SMS_SQL::remove_feed($id);
                echo '<div class="updated fade" style="padding:6px">' . esc_html__('Feed حذف شد.', 'farazsms-next') . '</div>';
            }
        }

        // Handle bulk delete — capped to 200 ids to prevent DoS via huge POST arrays.
        if ($can_manage && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
            check_admin_referer('farazsms_elementor_list_action', 'farazsms_elementor_list');
            $selected_feeds = isset($_POST['feed']) && is_array($_POST['feed']) ? array_slice( $_POST['feed'], 0, 200 ) : array();
            foreach ($selected_feeds as $feed_id) {
                FarazSMS_Next_Elementor_SMS_SQL::remove_feed(absint($feed_id));
            }
            echo '<div class="updated fade" style="padding:6px">' . esc_html__('Feeds حذف شدند.', 'farazsms-next') . '</div>';
        }

        $form_id_param = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $form_id = $show ? 0 : $form_id_param;
        ?>

        <div class="wrap">
            <?php if ($show) { ?>
                <h2><?php _e('Feeds پیامک - تمام فرم‌ها', 'farazsms-next'); ?></h2>
                <br/>
                <a class="add-new-h2" href="admin.php?page=elementor_farazsms&view=edit&id=0">
                    <?php _e('افزودن', 'farazsms-next'); ?>
                </a>
                <a class="add-new-h2" href="admin.php?page=elementor_farazsms&view=sent">
                    <?php _e('پیامک‌های ارسال شده', 'farazsms-next'); ?>
                </a>
            <?php } ?>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('farazsms_elementor_list_action', 'farazsms_elementor_list'); ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e('عملیات گروهی', 'farazsms-next'); ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=""><?php _e('عملیات گروهی', 'farazsms-next'); ?></option>
                            <option value="delete"><?php _e('حذف', 'farazsms-next'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php _e('اعمال', 'farazsms-next'); ?>"
                               onclick="if(jQuery('#bulk_action').val() == 'delete' && !confirm('<?php echo esc_js(__('آیا از حذف Feedهای انتخاب شده اطمینان دارید؟', 'farazsms-next')); ?>')) { return false; } return true;"/>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped" cellspacing="0">
                    <thead>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column" style="padding:13px 3px">
                            <input type="checkbox"/>
                        </th>
                        <th scope="col" id="active" class="manage-column" style="width:30px;text-align:center"></th>
                        <th scope="col" class="manage-column" <?php echo $show ? 'style="width:60px;"' : ''; ?>>
                            <?php _e('شناسه Feed', 'farazsms-next'); ?>
                        </th>
                        <?php if ($show) { ?>
                            <th scope="col" class="manage-column" style="width:60px;">
                                <?php _e('شناسه فرم', 'farazsms-next'); ?>
                            </th>
                            <th scope="col" class="manage-column"><?php _e('عنوان فرم', 'farazsms-next'); ?></th>
                        <?php } ?>
                        <th scope="col" class="manage-column"><?php _e('شماره فرستنده', 'farazsms-next'); ?></th>
                        <th scope="col" class="manage-column"><?php _e('گیرندگان', 'farazsms-next'); ?></th>
                    </tr>
                    </thead>
                    <tfoot>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column" style="padding:13px 3px">
                            <input type="checkbox"/>
                        </th>
                        <th scope="col" id="active" class="manage-column"></th>
                        <th scope="col" class="manage-column"><?php _e('شناسه Feed', 'farazsms-next'); ?></th>
                        <?php if ($show) { ?>
                            <th scope="col" class="manage-column"><?php _e('شناسه فرم', 'farazsms-next'); ?></th>
                            <th scope="col" class="manage-column"><?php _e('عنوان فرم', 'farazsms-next'); ?></th>
                        <?php } ?>
                        <th scope="col" class="manage-column"><?php _e('شماره فرستنده', 'farazsms-next'); ?></th>
                        <th scope="col" class="manage-column"><?php _e('گیرندگان', 'farazsms-next'); ?></th>
                    </tr>
                    </tfoot>
                    <tbody class="the-list">
                    <?php
                    $form_id_filter_raw = isset($_GET['fid']) ? wp_unslash($_GET['fid']) : (isset($_GET['id']) ? wp_unslash($_GET['id']) : '');
                    $form_id_filter = FarazSMS_Next_Elementor_SMS_SQL::sanitize_form_storage_key($form_id_filter_raw);
                    if ($form_id_filter) {
                        $feeds = FarazSMS_Next_Elementor_SMS_SQL::get_feed_via_formid($form_id_filter, false);
                    } else {
                        $feeds = FarazSMS_Next_Elementor_SMS_SQL::get_feeds();
                    }

                    if (!empty($feeds)) {
                        foreach ($feeds as $feed) {
                            $active = !empty($feed['is_active']);
                            $meta = !empty($feed['meta']) ? $feed['meta'] : array();
                            ?>
                            <tr id="feed-<?php echo esc_attr($feed['id']); ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="feed[]" value="<?php echo esc_attr($feed['id']); ?>"/>
                                </th>
                                <td style="text-align:center">
                                    <span class="dashicons dashicons-<?php echo $active ? 'yes-alt' : 'dismiss'; ?>" 
                                          style="color: <?php echo $active ? '#46b450' : '#dc3232'; ?>; cursor: pointer;"
                                          onclick="farazsmsElementorToggleActive(this, <?php echo esc_js($feed['id']); ?>);"
                                          title="<?php echo $active ? esc_attr__('فعال', 'farazsms-next') : esc_attr__('غیرفعال', 'farazsms-next'); ?>"></span>
                                </td>
                                <td>
                                    <a href="admin.php?page=elementor_farazsms&view=edit&id=<?php echo esc_attr($feed['id']); ?>">
                                        <?php echo esc_html($feed['id']); ?>
                                    </a>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="admin.php?page=elementor_farazsms&view=edit&id=<?php echo esc_attr($feed['id']); ?>">
                                                <?php _e('ویرایش', 'farazsms-next'); ?>
                                            </a> |
                                        </span>
                                        <span class="trash">
                                            <a href="javascript:if(confirm('<?php echo esc_js(__('آیا از حذف این Feed اطمینان دارید؟', 'farazsms-next')); ?>')){ farazsmsElementorDeleteFeed(<?php echo esc_js($feed['id']); ?>);}">
                                                <?php _e('حذف', 'farazsms-next'); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <?php if ($show) { ?>
                                    <td><?php echo esc_html($feed['form_id']); ?></td>
                                    <td>
                                        <strong>
                                            <?php echo esc_html($feed['form_title']); ?>
                                        </strong>
                                    </td>
                                <?php } ?>
                                <td><?php echo esc_html(isset($meta['from']) ? $meta['from'] : ''); ?></td>
                                <td>
                                    <?php
                                    $to = isset($meta['to']) ? esc_html($meta['to']) : '';
                                    $to_c = isset($meta['to_c']) ? esc_html($meta['to_c']) : '';
                                    echo $to;
                                    if ($to && $to_c) {
                                        echo ', ';
                                    }
                                    echo $to_c;
                                    ?>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td colspan="<?php echo $show ? '6' : '4'; ?>" style="padding:20px;">
                                <?php
                                if ($form_id) {
                                    echo sprintf(__("شما هیچ Feed پیامکی تنظیم نکرده‌اید. %sیک Feed ایجاد کنید%s!", 'farazsms-next'),
                                        '<a href="admin.php?page=elementor_farazsms&view=edit&id=0&fid=' . esc_attr(rawurlencode((string) $form_id)) . '">', '</a>');
                                } else {
                                    echo sprintf(__("شما هیچ Feed پیامکی تنظیم نکرده‌اید. %sیک Feed ایجاد کنید%s!", 'farazsms-next'),
                                        '<a href="admin.php?page=elementor_farazsms&view=edit&id=0">', '</a>');
                                }
                                ?>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
            </form>
        </div>

        <script type="text/javascript">
            function farazsmsElementorDeleteFeed(id) {
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }

            function farazsmsElementorToggleActive(icon, feed_id) {
                var is_active = icon.classList.contains('dashicons-yes-alt');
                var new_state = is_active ? 0 : 1;

                if (is_active) {
                    icon.classList.remove('dashicons-yes-alt');
                    icon.classList.add('dashicons-dismiss');
                    icon.style.color = '#dc3232';
                    icon.setAttribute('title', '<?php echo esc_js(__("غیرفعال", "farazsms-next")); ?>');
                } else {
                    icon.classList.remove('dashicons-dismiss');
                    icon.classList.add('dashicons-yes-alt');
                    icon.style.color = '#46b450';
                    icon.setAttribute('title', '<?php echo esc_js(__("فعال", "farazsms-next")); ?>');
                }

                jQuery.post(ajaxurl, {
                    action: "farazsms_elementor_feed_ajax_active",
                    farazsms_elementor_feed_ajax_active: "<?php echo wp_create_nonce('farazsms_elementor_feed_ajax_active'); ?>",
                    feed_id: feed_id,
                    is_active: new_state
                });
            }
        </script>
        <?php
    }
}

