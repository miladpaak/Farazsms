<?php
/**
 * Gravity Forms SMS Sent Messages Class
 *
 * Displays sent SMS messages history
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Gravity_Forms_SMS_Sent {

    /**
     * Display sent messages table
     */
    public static function table() {
        global $wpdb;

        // Capability gate — destructive actions on stored SMS rows require GF-edit rights.
        $can_manage = current_user_can( 'gravityforms_edit_forms' ) || current_user_can( 'manage_options' );

        // Handle delete action
        if ($can_manage && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['item'])) {
            check_admin_referer('farazsms_delete_sms');
            $item_id = absint($_GET['item']);
            $table_name = FarazSMS_Next_Gravity_Forms_SMS_SQL::sent_table();
            $wpdb->delete($table_name, array('id' => $item_id), array('%d'));
            echo '<div class="updated fade" style="padding:6px">' . esc_html__('پیامک حذف شد.', 'farazsms-next') . '</div>';
        }

        // Handle bulk delete — capped to 200 ids per request to prevent DoS via huge POST arrays.
        if ($can_manage && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'bulk_delete' && isset($_POST['item']) && is_array($_POST['item'])) {
            check_admin_referer('farazsms_sent_bulk_action');
            $table_name = FarazSMS_Next_Gravity_Forms_SMS_SQL::sent_table();
            $items = array_slice( $_POST['item'], 0, 200 );
            foreach ($items as $item_id) {
                $wpdb->delete($table_name, array('id' => absint($item_id)), array('%d'));
            }
            echo '<div class="updated fade" style="padding:6px">' . esc_html__('پیامک‌ها حذف شدند.', 'farazsms-next') . '</div>';
        }

        $form_id = isset($_GET['id']) ? absint($_GET['id']) : null;
        $sent_messages = FarazSMS_Next_Gravity_Forms_SMS_SQL::get_sent_messages($form_id, null, 100);
        ?>

        <div class="wrap">
            <h2><?php _e('پیامک‌های ارسال شده', 'farazsms-next'); ?></h2>
            
            <?php if ($form_id) { ?>
                <a href="admin.php?page=gf_farazsms&view=sent" class="button"><?php _e('نمایش همه', 'farazsms-next'); ?></a>
            <?php } ?>

            <form method="post" action="">
                <?php wp_nonce_field('farazsms_sent_bulk_action', 'farazsms_sent_bulk_action'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="bulk_action">
                            <option value=""><?php _e('عملیات گروهی', 'farazsms-next'); ?></option>
                            <option value="bulk_delete"><?php _e('حذف', 'farazsms-next'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php _e('اعمال', 'farazsms-next'); ?>">
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all"/>
                        </th>
                        <th scope="col" class="manage-column"><?php _e('تاریخ', 'farazsms-next'); ?></th>
                        <th scope="col" class="manage-column"><?php _e('شناسه فرم', 'farazsms-next'); ?></th>
                        <th scope="col" class="manage-column"><?php _e('شناسه ورودی', 'farazsms-next'); ?></th>
                        <th scope="col" class="manage-column"><?php _e('از', 'farazsms-next'); ?></th>
                        <th scope="col" class="manage-column"><?php _e('به', 'farazsms-next'); ?></th>
                        <th scope="col" class="manage-column"><?php _e('پیام', 'farazsms-next'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($sent_messages)) { ?>
                        <?php foreach ($sent_messages as $message) { ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="item[]" value="<?php echo esc_attr($message['id']); ?>"/>
                                </th>
                                <td>
                                    <?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($message['date']))); ?>
                                    <div class="row-actions">
                                        <span class="delete">
                                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'delete', 'item' => $message['id'])), 'farazsms_delete_sms')); ?>">
                                                <?php _e('حذف', 'farazsms-next'); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=gf_entries&view=entries&id=' . $message['form_id']); ?>">
                                        <?php echo esc_html($message['form_id']); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=gf_entries&view=entry&id=' . $message['form_id'] . '&lid=' . $message['entry_id']); ?>">
                                        <?php echo esc_html($message['entry_id']); ?>
                                    </a>
                                </td>
                                <td style="direction:ltr;text-align:left;"><?php echo esc_html($message['sender']); ?></td>
                                <td style="direction:ltr;text-align:left;"><?php echo esc_html($message['reciever']); ?></td>
                                <td><?php echo esc_html(wp_trim_words($message['message'], 20)); ?></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="7" style="padding:20px;text-align:center;">
                                <?php _e('هیچ پیامک ارسال شده‌ای یافت نشد.', 'farazsms-next'); ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </form>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#cb-select-all').on('change', function() {
                    $('tbody input[type="checkbox"]').prop('checked', $(this).prop('checked'));
                });
            });
        </script>
        <?php
    }
}

