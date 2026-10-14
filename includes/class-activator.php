<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_Activator {

    public static function activate() {
        global $wpdb;

        $table = $wpdb->prefix . 'clickguage_experiments';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            url varchar(500) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            old_title text NULL,
            old_description text NULL,
            new_title text NULL,
            new_description text NULL,
            queries text NULL,
            baseline_impressions int(10) unsigned NOT NULL DEFAULT 0,
            baseline_clicks int(10) unsigned NOT NULL DEFAULT 0,
            baseline_ctr decimal(8,6) NOT NULL DEFAULT 0,
            baseline_position decimal(8,2) NOT NULL DEFAULT 0,
            followup_impressions int(10) unsigned NOT NULL DEFAULT 0,
            followup_clicks int(10) unsigned NOT NULL DEFAULT 0,
            followup_ctr decimal(8,6) NOT NULL DEFAULT 0,
            followup_position decimal(8,2) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            applied_at datetime NULL,
            checked_at datetime NULL,
            PRIMARY KEY  (id),
            KEY post_status (post_id, status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (!get_option('clickguage_indexnow_key')) {
            add_option('clickguage_indexnow_key', wp_generate_password(32, false), '', false);
        }
        if (!get_option('clickguage_google_token')) {
            add_option('clickguage_google_token', array(), '', false);
        }
        if (!wp_next_scheduled('clickguage_recheck')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'clickguage_recheck');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('clickguage_recheck');
    }
}
