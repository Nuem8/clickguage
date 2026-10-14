<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_Seo_Meta {

    public static function provider() {
        static $provider = null;
        if ($provider !== null) {
            return $provider;
        }
        if (defined('WPSEO_VERSION')) {
            $provider = 'yoast';
            return $provider;
        }
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            $provider = 'rank_math';
            return $provider;
        }
        if (defined('SEOPRESS_VERSION')) {
            $provider = 'seopress';
            return $provider;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $provider = 'aioseo';
            return $provider;
        }
        $provider = 'clickguage';
        return $provider;
    }

    public static function provider_label() {
        $labels = array(
            'yoast' => 'Yoast SEO',
            'rank_math' => 'Rank Math',
            'seopress' => 'SEOPress',
            'aioseo' => 'All in One SEO',
            'clickguage' => __('Clickgauge (no SEO plugin detected)', 'clickguage'),
        );
        $provider = self::provider();
        return isset($labels[$provider]) ? $labels[$provider] : $provider;
    }

    public static function read($post_id) {
        $provider = self::provider();
        if ($provider === 'yoast') {
            return array(
                'title' => (string) get_post_meta($post_id, '_yoast_wpseo_title', true),
                'description' => (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            );
        }
        if ($provider === 'rank_math') {
            return array(
                'title' => (string) get_post_meta($post_id, 'rank_math_title', true),
                'description' => (string) get_post_meta($post_id, 'rank_math_description', true),
            );
        }
        if ($provider === 'seopress') {
            return array(
                'title' => (string) get_post_meta($post_id, '_seopress_titles_title', true),
                'description' => (string) get_post_meta($post_id, '_seopress_titles_desc', true),
            );
        }
        if ($provider === 'aioseo') {
            return self::read_aioseo($post_id);
        }
        return array(
            'title' => (string) get_post_meta($post_id, '_clickguage_title', true),
            'description' => (string) get_post_meta($post_id, '_clickguage_description', true),
        );
    }

    public static function write($post_id, $title, $description) {
        $provider = self::provider();
        if ($provider === 'yoast') {
            update_post_meta($post_id, '_yoast_wpseo_title', $title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
            return;
        }
        if ($provider === 'rank_math') {
            update_post_meta($post_id, 'rank_math_title', $title);
            update_post_meta($post_id, 'rank_math_description', $description);
            return;
        }
        if ($provider === 'seopress') {
            update_post_meta($post_id, '_seopress_titles_title', $title);
            update_post_meta($post_id, '_seopress_titles_desc', $description);
            return;
        }
        if ($provider === 'aioseo') {
            self::write_aioseo($post_id, $title, $description);
            return;
        }
        update_post_meta($post_id, '_clickguage_title', $title);
        update_post_meta($post_id, '_clickguage_description', $description);
    }

    public static function filter_document_title($parts) {
        if (self::provider() !== 'clickguage' || !is_singular()) {
            return $parts;
        }
        $title = get_post_meta(get_queried_object_id(), '_clickguage_title', true);
        if (is_string($title) && $title !== '') {
            $parts['title'] = $title;
        }
        return $parts;
    }

    public static function print_description() {
        if (self::provider() !== 'clickguage' || !is_singular()) {
            return;
        }
        $description = get_post_meta(get_queried_object_id(), '_clickguage_description', true);
        if (!is_string($description) || $description === '') {
            return;
        }
        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
    }

    private static function read_aioseo($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';
        $row = $wpdb->get_row($wpdb->prepare("SELECT title, description FROM {$table} WHERE post_id = %d", $post_id), ARRAY_A);
        if (!is_array($row)) {
            return array('title' => '', 'description' => '');
        }
        return array(
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
        );
    }

    private static function write_aioseo($post_id, $title, $description) {
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d", $post_id));
        if ($exists) {
            $wpdb->update($table, array(
                'title' => $title,
                'description' => $description,
            ), array('post_id' => $post_id));
            return;
        }
        $wpdb->insert($table, array(
            'post_id' => $post_id,
            'title' => $title,
            'description' => $description,
        ));
    }
}
