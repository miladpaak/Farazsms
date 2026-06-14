<?php

namespace FarazSMS;


class Wallet {

    /**
     * Get user wallet balance
     */
    public static function get_balance($user_id) {
        global $wpdb;

        if (!$user_id) {
            return 0;
        }

        $table_name = $wpdb->prefix . 'farazsms_wallet';

        // Ensure table exists
        self::ensure_table_exists();

        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END) as balance
             FROM $table_name
             WHERE user_id = %d",
            $user_id
        ));

        return floatval($balance ?: 0);
    }

    /**
     * Add credit to user wallet
     */
    public static function add_credit($user_id, $amount, $description = '', $created_by = null) {
        global $wpdb;

        if (!$user_id || $amount <= 0) {
            return false;
        }

        $table_name = $wpdb->prefix . 'farazsms_wallet';

        // Ensure table exists
        self::ensure_table_exists();

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'amount' => $amount,
                'description' => $description,
                'transaction_type' => 'credit',
                'created_by' => $created_by ?: get_current_user_id()
            ),
            array('%d', '%f', '%s', '%s', '%d')
        );

        if ($result) {
            do_action('farazsms_wallet_credit_added', $user_id, $amount, $description);
        }

        return $result;
    }

    /**
     * Deduct from user wallet
     */
    public static function deduct_balance($user_id, $amount, $description = '', $order_id = null) {
        global $wpdb;

        if (!$user_id || $amount <= 0) {
            return false;
        }

        $current_balance = self::get_balance($user_id);

        if ($current_balance < $amount) {
            return false; // Insufficient balance
        }

        $table_name = $wpdb->prefix . 'farazsms_wallet';

        // Ensure table exists
        self::ensure_table_exists();

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'amount' => $amount,
                'description' => $description,
                'transaction_type' => 'debit',
                'order_id' => $order_id
            ),
            array('%d', '%f', '%s', '%s', '%d')
        );

        if ($result) {
            do_action('farazsms_wallet_balance_deducted', $user_id, $amount, $description, $order_id);
        }

        return $result;
    }

    /**
     * Get wallet transactions
     */
    public static function get_transactions($user_id, $limit = 20, $offset = 0) {
        global $wpdb;

        if (!$user_id) {
            return array();
        }

        $table_name = $wpdb->prefix . 'farazsms_wallet';

        // Ensure table exists
        self::ensure_table_exists();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }

    /**
     * Add balance to user wallet (alias for add_credit for backward compatibility)
     */
    public static function add_balance($user_id, $amount, $description = '', $transaction_type = 'credit') {
        // For backward compatibility, ignore transaction_type parameter and always use 'credit'
        return self::add_credit($user_id, $amount, $description, null);
    }

    /**
     * Ensure wallet table exists
     */
    private static function ensure_table_exists() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'farazsms_wallet';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                description TEXT,
                transaction_type ENUM('credit', 'debit') NOT NULL,
                order_id BIGINT UNSIGNED NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY order_id (order_id)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }

    /**
     * Check if user can use wallet for payment
     */
    public static function can_use_wallet($user_id, $amount) {
        if (!$user_id || !is_user_logged_in()) {
            return false;
        }

        $balance = self::get_balance($user_id);
        return $balance >= $amount;
    }
}