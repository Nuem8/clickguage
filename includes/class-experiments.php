<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_Experiments {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'clickguage_experiments';
    }

    public static function scan() {
        Clickgauge_Runtime::watch('scan');
        $rows = Clickgauge_Search_Console::page_rows(28);
        if (is_wp_error($rows)) {
            return $rows;
        }
        $settings = Clickgauge_Admin::settings();
        $found = array();
        foreach ($rows as $row) {
            if (empty($row['keys'][0])) {
                continue;
            }
            $url = esc_url_raw($row['keys'][0]);
            $metrics = Clickgauge_Search_Console::metrics_from_row($row);
            if (!self::is_opportunity($metrics, $settings)) {
                continue;
            }
            $post_id = url_to_postid($url);
            if ($post_id < 1) {
                $post_id = url_to_postid(trailingslashit($url));
            }
            if ($post_id < 1) {
                continue;
            }
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            if (self::open_for_post($post_id)) {
                continue;
            }
            $found[] = array(
                'post_id' => $post_id,
                'url' => $url,
                'title' => get_the_title($post_id),
                'impressions' => $metrics['impressions'],
                'clicks' => $metrics['clicks'],
                'ctr' => $metrics['ctr'],
                'position' => $metrics['position'],
            );
            if (count($found) >= 20) {
                break;
            }
        }
        set_transient('clickguage_opportunities', $found, 12 * HOUR_IN_SECONDS);

        if (!empty($settings['auto_apply'])) {
            $limit = max(1, (int) $settings['auto_limit']);
            $applied = 0;
            foreach ($found as $item) {
                if ($applied >= $limit) {
                    break;
                }
                $draft = self::draft($item['post_id'], $item['url'], $item);
                if (is_wp_error($draft)) {
                    return $draft;
                }
                $result = self::apply($draft);
                if (is_wp_error($result)) {
                    return $result;
                }
                $applied++;
            }
        }
        return $found;
    }

    public static function draft($post_id, $url, $metrics = null) {
        Clickgauge_Runtime::watch('draft');
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error('clickguage_post', __('That page is not a published post.', 'clickguage'));
        }
        if (self::applied_for_post($post_id)) {
            return new WP_Error('clickguage_post', __('This page already has an active snippet test.', 'clickguage'));
        }
        if ($metrics === null) {
            $metrics = Clickgauge_Search_Console::page_metrics($url);
            if (is_wp_error($metrics)) {
                return $metrics;
            }
        }
        $queries = Clickgauge_Search_Console::top_queries($url);
        $current = Clickgauge_Seo_Meta::read($post_id);
        $suggestion = Clickgauge_Ai::draft($post, $queries, $current['title'], $current['description']);
        if (is_wp_error($suggestion)) {
            return $suggestion;
        }
        $existing = self::pending_for_post($post_id);
        $data = array(
            'post_id' => $post_id,
            'url' => $url,
            'status' => 'pending',
            'old_title' => $current['title'],
            'old_description' => $current['description'],
            'new_title' => $suggestion['title'],
            'new_description' => $suggestion['description'],
            'queries' => wp_json_encode($queries),
            'baseline_impressions' => $metrics['impressions'],
            'baseline_clicks' => $metrics['clicks'],
            'baseline_ctr' => $metrics['ctr'],
            'baseline_position' => $metrics['position'],
            'created_at' => current_time('mysql', true),
        );
        global $wpdb;
        if ($existing) {
            $wpdb->update(self::table(), $data, array('id' => (int) $existing->id));
            return (int) $existing->id;
        }
        $wpdb->insert(self::table(), $data);
        return (int) $wpdb->insert_id;
    }

    public static function apply($experiment_id) {
        $row = self::get($experiment_id);
        if (!$row || $row->status !== 'pending') {
            return new WP_Error('clickguage_apply', __('That draft is no longer waiting for approval.', 'clickguage'));
        }
        Clickgauge_Seo_Meta::write((int) $row->post_id, $row->new_title, $row->new_description);
        global $wpdb;
        $wpdb->update(self::table(), array(
            'status' => 'applied',
            'applied_at' => current_time('mysql', true),
            'checked_at' => null,
        ), array('id' => (int) $row->id));
        Clickgauge_IndexNow::ping($row->url);
        return true;
    }

    public static function dismiss($experiment_id) {
        global $wpdb;
        $wpdb->update(self::table(), array('status' => 'dismissed'), array('id' => (int) $experiment_id, 'status' => 'pending'));
    }

    public static function rollback($experiment_id) {
        $row = self::get($experiment_id);
        if (!$row || $row->status !== 'applied') {
            return new WP_Error('clickguage_rollback', __('That change is not active.', 'clickguage'));
        }
        Clickgauge_Seo_Meta::write((int) $row->post_id, $row->old_title, $row->old_description);
        global $wpdb;
        $wpdb->update(self::table(), array(
            'status' => 'rolled_back',
            'checked_at' => current_time('mysql', true),
        ), array('id' => (int) $row->id));
        Clickgauge_IndexNow::ping($row->url);
        return true;
    }

    public static function recheck_due() {
        Clickgauge_Runtime::watch('recheck');
        global $wpdb;
        $settings = Clickgauge_Admin::settings();
        $days = max(7, (int) $settings['recheck_days']);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s AND applied_at IS NOT NULL AND applied_at <= %s AND checked_at IS NULL",
            'applied',
            $cutoff
        ));
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $metrics = Clickgauge_Search_Console::page_metrics($row->url);
            if (is_wp_error($metrics) || ($metrics['impressions'] === 0 && $metrics['clicks'] === 0)) {
                continue;
            }
            $status = self::judge($row, $metrics);
            if ($status === 'rolled_back') {
                Clickgauge_Seo_Meta::write((int) $row->post_id, $row->old_title, $row->old_description);
                Clickgauge_IndexNow::ping($row->url);
            }
            $wpdb->update($table, array(
                'followup_impressions' => $metrics['impressions'],
                'followup_clicks' => $metrics['clicks'],
                'followup_ctr' => $metrics['ctr'],
                'followup_position' => $metrics['position'],
                'checked_at' => current_time('mysql', true),
                'status' => $status,
            ), array('id' => (int) $row->id));
        }
    }

    public static function list_by_status($status, $limit = 50) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
            $status,
            $limit
        ));
    }

    public static function is_opportunity($metrics, $settings) {
        $impressions = $metrics['impressions'];
        $position = $metrics['position'];
        $ctr = $metrics['ctr'];
        $minimum = max(1, (int) $settings['min_impressions']);
        if ($impressions < $minimum) {
            return false;
        }
        $near_page_one = $position >= 8 && $position <= 20;
        $weak_ctr = $impressions >= 100 && $position <= 15 && $ctr < 0.03;
        return $near_page_one || $weak_ctr;
    }

    private static function judge($row, $metrics) {
        $position_worse = ($metrics['position'] - (float) $row->baseline_position) >= 1;
        $position_better = ((float) $row->baseline_position - $metrics['position']) >= 1;
        $ctr_worse = $metrics['ctr'] < ((float) $row->baseline_ctr * 0.8);
        $ctr_better = $metrics['ctr'] > ((float) $row->baseline_ctr * 1.1);
        if (($position_worse && $metrics['ctr'] <= (float) $row->baseline_ctr) || ($ctr_worse && !$position_better)) {
            return 'rolled_back';
        }
        if ($position_better || $ctr_better) {
            return 'won';
        }
        return 'applied';
    }

    private static function get($id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    private static function open_for_post($post_id) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND status IN ('pending', 'applied') LIMIT 1",
            $post_id
        ));
    }

    private static function applied_for_post($post_id) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND status = %s LIMIT 1",
            $post_id,
            'applied'
        ));
    }

    private static function pending_for_post($post_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
            $post_id,
            'pending'
        ));
    }
}
