<?php
/**
 * Plugin uninstall cleanup
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('farazsms_login_settings');

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}farazsms_verification");