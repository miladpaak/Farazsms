<?php
/**
 * Elementor SMS SQL Class
 *
 * Handles database operations for SMS feeds and sent messages
 */

if (!defined('ABSPATH')) {
    exit;
}

class FarazSMS_Next_Elementor_SMS_SQL {

    /**
     * Bump this whenever the DB schema (CREATE/ALTER statements below) changes.
     * setup_update() will only run dbDelta + ALTER when the stored DB version
     * differs from this constant. Running DDL on every request (the previous
     * behavior) caused the MySQL DDL queue to back up on busy shops.
     */
    const DB_VERSION = '1.1.0';
    const DB_VERSION_OPTION = 'farazsms_next_elementor_db_version';

    /**
     * Get feeds table name
     *
     * @return string
     */
    public static function feeds_table() {
        global $wpdb;
        return $wpdb->prefix . 'farazsms_elementor_feeds';
    }

    /**
     * Get sent messages table name
     *
     * @return string
     */
    public static function sent_table() {
        global $wpdb;
        return $wpdb->prefix . 'farazsms_elementor_sent';
    }

    /**
     * Normalize form storage key: numeric legacy "123" or composite "123_widgetid".
     *
     * @param mixed $form_id Raw form key from UI or POST.
     * @return string Empty if invalid.
     */
    public static function sanitize_form_storage_key( $form_id ) {
        if ( is_array( $form_id ) ) {
            return '';
        }
        $s = trim( (string) wp_unslash( $form_id ) );
        if ( $s === '' ) {
            return '';
        }
        if ( preg_match( '/^(\d+)_([a-zA-Z0-9]+)$/', $s, $m ) ) {
            return $m[1] . '_' . sanitize_key( $m[2] );
        }
        if ( ctype_digit( $s ) ) {
            return (string) absint( $s );
        }

        return '';
    }

    /**
     * Post ID part of storage key (for titles, merge context).
     *
     * @param string $key Sanitized key.
     * @return int
     */
    public static function post_id_from_storage_key( $key ) {
        $k = self::sanitize_form_storage_key( $key );
        if ( $k === '' ) {
            return 0;
        }
        if ( strpos( $k, '_' ) !== false ) {
            return absint( strstr( $k, '_', true ) );
        }

        return absint( $k );
    }

    /**
     * Upgrade form_id columns to VARCHAR for composite keys (one-time per site).
     */
    public static function maybe_migrate_elementor_form_id_columns() {
        global $wpdb;

        foreach ( array( self::feeds_table(), self::sent_table() ) as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                continue;
            }
            $row = $wpdb->get_row( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . "` LIKE 'form_id'" );
            if ( ! $row || empty( $row->Type ) ) {
                continue;
            }
            $type = strtolower( (string) $row->Type );
            if ( strpos( $type, 'int' ) === false && strpos( $type, 'char' ) !== false ) {
                continue;
            }
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . "` MODIFY `form_id` VARCHAR(64) NOT NULL" );
        }
    }

    /**
     * Setup or update database tables.
     *
     * IMPORTANT: this is called from FarazSMS_Next_Elementor_SMS::construct(),
     * which fires on every request where Elementor is loaded. Running dbDelta +
     * ALTER on every request is a DDL storm and a deadlock risk on busy shops.
     * We gate the entire work behind a stored version flag — DDL only runs when
     * the constant DB_VERSION above is bumped.
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

        $feeds_table = self::feeds_table();
        $sql_feeds = "CREATE TABLE IF NOT EXISTS $feeds_table (
            id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
            form_id VARCHAR(64) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            meta longtext,
            PRIMARY KEY  (id),
            KEY form_id (form_id)
        ) $charset_collate;";
        dbDelta($sql_feeds);

        $sent_table = self::sent_table();
        $sql_sent = "CREATE TABLE IF NOT EXISTS $sent_table (
            id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
            form_id VARCHAR(64) NOT NULL,
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

        self::maybe_migrate_elementor_form_id_columns();

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
            $feed['form_title'] = '';
            $pid = self::post_id_from_storage_key( isset( $feed['form_id'] ) ? (string) $feed['form_id'] : '' );
            if ( $pid && function_exists( 'get_post' ) ) {
                $post = get_post( $pid );
                if ( $post ) {
                    $feed['form_title'] = $post->post_title;
                    $key = isset( $feed['form_id'] ) ? (string) $feed['form_id'] : '';
                    if ( strpos( $key, '_' ) !== false ) {
                        $suffix = preg_replace( '/^\d+_/', '', $key );
                        if ( $suffix !== '' ) {
                            $feed['form_title'] .= ' — ' . $suffix;
                        }
                    }
                }
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
     * Get feeds by form storage key (composite or legacy numeric string).
     *
     * @param string|int $form_id Form key
     * @param bool $only_active Only return active feeds
     * @return array
     */
    public static function get_feed_via_formid($form_id, $only_active = false) {
        global $wpdb;
        $table_name = self::feeds_table();

        $key = self::sanitize_form_storage_key( $form_id );
        if ( $key === '' ) {
            return array();
        }

        $where_sql = 'form_id = %s';
        $params = array( $key );
        if ( $only_active ) {
            $where_sql .= ' AND is_active = 1';
        }

        $sql = "SELECT * FROM $table_name WHERE $where_sql ORDER BY id DESC";
        $results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        if ( empty( $results ) && strpos( $key, '_' ) !== false ) {
            $legacy = (string) self::post_id_from_storage_key( $key );
            if ( $legacy !== '' && $legacy !== '0' ) {
                $params2 = array( $legacy );
                $where2 = 'form_id = %s';
                if ( $only_active ) {
                    $where2 .= ' AND is_active = 1';
                }
                $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE $where2 ORDER BY id DESC", $params2 ), ARRAY_A );
            }
        }

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
     * @param string|int $form_id Form storage key
     * @param int $is_active Is active (1 or 0)
     * @param array $meta Feed meta data
     * @return int Feed ID
     */
    public static function update_feed($id, $form_id, $is_active, $meta) {
        global $wpdb;
        $table_name = self::feeds_table();

        $form_key = self::sanitize_form_storage_key( $form_id );
        if ( $form_key === '' ) {
            $form_key = (string) absint( $form_id );
        }

        $data = array(
            'form_id' => $form_key,
            'is_active' => absint($is_active),
            'meta' => json_encode($meta),
        );

        if (empty($id)) {
            $wpdb->insert($table_name, $data);
            return $wpdb->insert_id;
        } else {
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
     * @param string|int $form_id Form storage key
     * @param int|string $entry_id Entry ID
     * @param string $sender Sender number
     * @param string $receiver Receiver number
     * @param string $message Message content
     * @return int|false Insert ID or false on failure
     */
    public static function save_sms_sent($form_id, $entry_id, $sender, $receiver, $message) {
        global $wpdb;
        $table_name = self::sent_table();

        $form_key = self::sanitize_form_storage_key( $form_id );
        if ( $form_key === '' ) {
            $form_key = (string) absint( $form_id );
        }

        $data = array(
            'form_id' => $form_key,
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
     * @param string|int|null $form_id Form key (optional)
     * @param int|string|null $entry_id Entry ID (optional)
     * @param int $limit Limit results
     * @return array
     */
    public static function get_sent_messages($form_id = null, $entry_id = null, $limit = 50) {
        global $wpdb;
        $table_name = self::sent_table();

        $where = array('1=1');
        $values = array();

        if ( null !== $form_id && '' !== (string) $form_id ) {
            $fk = self::sanitize_form_storage_key( $form_id );
            if ( $fk !== '' ) {
                $where[] = 'form_id = %s';
                $values[] = $fk;
            }
        }

        if ($entry_id !== null && $entry_id !== '') {
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
