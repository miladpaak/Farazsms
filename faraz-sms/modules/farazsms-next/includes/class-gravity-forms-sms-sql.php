<?php
/**
 * Gravity Forms SMS SQL Class
 *
 * Handles database operations for SMS feeds and sent messages
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Gravity_Forms_SMS_SQL {

    /**
     * Bump this whenever the DB schema below changes. setup_update() only runs
     * dbDelta when the stored DB version differs — running DDL on every request
     * caused MySQL DDL queue backups on busy shops.
     */
    const DB_VERSION = '1.1.0';
    const DB_VERSION_OPTION = 'farazsms_next_gf_db_version';

    /**
     * Get feeds table name
     *
     * @return string
     */
    public static function feeds_table() {
        global $wpdb;
        return $wpdb->prefix . 'farazsms_gf_feeds';
    }

    /**
     * Get sent messages table name
     *
     * @return string
     */
    public static function sent_table() {
        global $wpdb;
        return $wpdb->prefix . 'farazsms_gf_sent';
    }

    /**
     * Setup or update database tables.
     *
     * Called from FarazSMS_Next_Gravity_Forms_SMS::construct() on every
     * Gravity-Forms-loaded request. We gate dbDelta behind a stored version
     * flag to avoid DDL on every page-load.
     */
    public static function setup_update() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
            return;
        }

        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Create feeds table
        $feeds_table = self::feeds_table();
        $sql_feeds = "CREATE TABLE IF NOT EXISTS $feeds_table (
            id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
            form_id mediumint(8) unsigned NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            meta longtext,
            PRIMARY KEY  (id),
            KEY form_id (form_id)
        ) $charset_collate;";
        dbDelta($sql_feeds);

        // Create sent messages table
        $sent_table = self::sent_table();
        $sql_sent = "CREATE TABLE IF NOT EXISTS $sent_table (
            id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
            form_id mediumint(8) unsigned NOT NULL,
            entry_id VARCHAR(20) NOT NULL,
            date DATETIME,
            sender VARCHAR(20) NOT NULL,
            reciever VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY entry_id (entry_id)
        ) $charset_collate;";
        dbDelta($sql_sent);

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
    }

    /**
     * Get all feeds
     *
     * @return array
     */
    public static function get_feeds() {
        global $wpdb;
        $table_name = self::feeds_table();

            $results = $wpdb->get_results(
            "SELECT f.*
             FROM $table_name f
             ORDER BY f.id DESC",
            ARRAY_A
        );

        if (empty($results)) {
            return array();
        }

        foreach ($results as &$feed) {
            $feed['meta'] = !empty($feed['meta']) ? json_decode($feed['meta'], true) : array();
            // Get form title
            $feed['form_title'] = '';
            if (class_exists('GFAPI')) {
                $form = GFAPI::get_form($feed['form_id']);
                $feed['form_title'] = !is_wp_error($form) && isset($form['title']) ? $form['title'] : '';
            } elseif (class_exists('RGFormsModel')) {
                $form = RGFormsModel::get_form($feed['form_id']);
                $feed['form_title'] = !empty($form) && isset($form->title) ? $form->title : '';
            }
        }

        return $results;
    }

    /**
     * Get feed by ID
     *
     * @param int $id Feed ID
     * @return array|false
     */
    public static function get_feed($id) {
        global $wpdb;
        $table_name = self::feeds_table();

        $feed = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$feed) {
            return false;
        }

        $feed['meta'] = !empty($feed['meta']) ? json_decode($feed['meta'], true) : array();
        return $feed;
    }

    /**
     * Get feeds by form ID
     *
     * @param int $form_id Form ID
     * @param bool $only_active Only return active feeds
     * @return array
     */
    public static function get_feed_via_formid($form_id, $only_active = false) {
        global $wpdb;
        $table_name = self::feeds_table();

        $where = $wpdb->prepare("form_id = %d", $form_id);
        if ($only_active) {
            $where .= " AND is_active = 1";
        }

        $results = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE $where ORDER BY id DESC",
            ARRAY_A
        );

        if (empty($results)) {
            return array();
        }

        foreach ($results as &$feed) {
            $feed['meta'] = !empty($feed['meta']) ? json_decode($feed['meta'], true) : array();
        }

        return $results;
    }

    /**
     * Update or insert feed
     *
     * @param int $id Feed ID (0 for new feed)
     * @param int $form_id Form ID
     * @param int $is_active Is active (1 or 0)
     * @param array $meta Feed meta data
     * @return int Feed ID
     */
    public static function update_feed($id, $form_id, $is_active, $meta) {
        global $wpdb;
        $table_name = self::feeds_table();

        $data = array(
            'form_id' => absint($form_id),
            'is_active' => absint($is_active),
            'meta' => json_encode($meta),
        );

        if (empty($id)) {
            // Insert new feed
            $wpdb->insert($table_name, $data);
            return $wpdb->insert_id;
        } else {
            // Update existing feed
            $wpdb->update(
                $table_name,
                $data,
                array('id' => absint($id))
            );
            return absint($id);
        }
    }

    /**
     * Remove feed
     *
     * @param int $id Feed ID
     * @return bool
     */
    public static function remove_feed($id) {
        global $wpdb;
        $table_name = self::feeds_table();

        return $wpdb->delete(
            $table_name,
            array('id' => absint($id)),
            array('%d')
        ) !== false;
    }

    /**
     * Save sent SMS message
     *
     * @param int $form_id Form ID
     * @param int|string $entry_id Entry ID
     * @param string $sender Sender number
     * @param string $receiver Receiver number
     * @param string $message Message content
     * @return int|false Insert ID or false on failure
     */
    public static function save_sms_sent($form_id, $entry_id, $sender, $receiver, $message) {
        global $wpdb;
        $table_name = self::sent_table();

        $data = array(
            'form_id' => absint($form_id),
            'entry_id' => sanitize_text_field($entry_id),
            'sender' => sanitize_text_field($sender),
            'reciever' => sanitize_text_field($receiver),
            'message' => wp_kses_post($message),
            'date' => current_time('mysql'),
        );

        $result = $wpdb->insert($table_name, $data);

        if ($result) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get sent messages
     *
     * @param int|null $form_id Form ID (optional)
     * @param int|null $entry_id Entry ID (optional)
     * @param int $limit Limit results
     * @return array
     */
    public static function get_sent_messages($form_id = null, $entry_id = null, $limit = 50) {
        global $wpdb;
        $table_name = self::sent_table();

        $where = array('1=1');
        $values = array();

        if ($form_id) {
            $where[] = 'form_id = %d';
            $values[] = absint($form_id);
        }

        if ($entry_id) {
            $where[] = 'entry_id = %s';
            $values[] = sanitize_text_field($entry_id);
        }

        $where_clause = implode(' AND ', $where);

        if (!empty($values)) {
            $query = $wpdb->prepare(
                "SELECT * FROM $table_name WHERE $where_clause ORDER BY date DESC LIMIT %d",
                array_merge($values, array($limit))
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT * FROM $table_name WHERE $where_clause ORDER BY date DESC LIMIT %d",
                $limit
            );
        }

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Drop tables (for uninstall)
     */
    public static function drop_table() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS " . self::feeds_table());
        $wpdb->query("DROP TABLE IF EXISTS " . self::sent_table());
    }
}

