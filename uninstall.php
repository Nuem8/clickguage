<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

wp_clear_scheduled_hook('wp_clickgauge_recheck');
delete_option('wp_clickgauge_settings');
delete_option('wp_clickgauge_google_token');
delete_option('wp_clickgauge_indexnow_key');
delete_transient('wp_clickgauge_opportunities');
delete_transient('wp_clickgauge_oauth_state');

$table = $wpdb->prefix . 'wp_clickgauge_experiments';
$wpdb->query("DROP TABLE IF EXISTS {$table}");
