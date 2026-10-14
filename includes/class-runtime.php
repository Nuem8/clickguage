<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_Runtime {

    private static $task = '';
    private static $registered = false;

    public static function time_limit() {
        $limit = (int) ini_get('max_execution_time');
        return $limit < 0 ? 0 : $limit;
    }

    public static function status_text() {
        $limit = self::time_limit();
        if ($limit === 0) {
            return __('PHP is not set to stop requests on this site.', 'wp-clickgauge');
        }
        return sprintf(__('PHP stops requests on this site after %d seconds.', 'wp-clickgauge'), $limit);
    }

    public static function warning_text() {
        $limit = self::time_limit();
        if ($limit === 0 || $limit >= 120) {
            return '';
        }
        if ($limit < 60) {
            return sprintf(__('PHP stops this site after %d seconds. A Clickgauge scan can be cut off before it finishes. Auto-apply makes that more likely.', 'wp-clickgauge'), $limit);
        }
        return sprintf(__('PHP stops this site after %d seconds. A normal scan usually finishes. Auto-apply of several drafts can still be cut off.', 'wp-clickgauge'), $limit);
    }

    public static function watch($task) {
        self::$task = $task;
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function(array(__CLASS__, 'on_shutdown'));
    }

    public static function on_shutdown() {
        $error = error_get_last();
        if (!is_array($error) || empty($error['message']) || stripos($error['message'], 'Maximum execution time') === false) {
            return;
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(20);
        }
        $settings = Clickgauge_Admin::settings();
        if (empty($settings['notify_on_timeout'])) {
            return;
        }
        $admin = get_option('admin_email');
        if (!is_email($admin)) {
            return;
        }
        $name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] PHP stopped Clickgauge', $name);
        $body = sprintf(
            "PHP stopped %s on %s.\n\n%s\n\n%s\n%s\n",
            self::task_label(),
            home_url('/'),
            $error['message'],
            self::status_text(),
            admin_url('admin.php?page=wp-clickgauge-settings&tab=rules')
        );
        wp_mail($admin, $subject, $body);
    }

    private static function task_label() {
        if (self::$task === 'scan') {
            return __('a scan', 'wp-clickgauge');
        }
        if (self::$task === 'draft') {
            return __('a snippet draft', 'wp-clickgauge');
        }
        if (self::$task === 'recheck') {
            return __('a Search Console recheck', 'wp-clickgauge');
        }
        return __('a Clickgauge request', 'wp-clickgauge');
    }
}
