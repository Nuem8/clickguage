<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

wp_clear_scheduled_hook('clickguage_recheck');
delete_option('clickguage_settings');
delete_option('clickguage_google_token');
delete_option('clickguage_indexnow_key');
delete_transient('clickguage_opportunities');
delete_transient('clickguage_oauth_state');

$table = $wpdb->prefix . 'clickguage_experiments';
$wpdb->query("DROP TABLE IF EXISTS {$table}");
