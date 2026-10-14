<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_Search_Console {

    const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public static function redirect_uri() {
        return admin_url('admin-post.php?action=wp_clickgauge_oauth_callback');
    }

    public static function is_connected() {
        $token = get_option('wp_clickgauge_google_token', array());
        return is_array($token) && !empty($token['refresh_token']);
    }

    public static function auth_url() {
        $settings = Clickgauge_Admin::settings();
        $state = wp_generate_password(24, false);
        set_transient('wp_clickgauge_oauth_state', $state, 10 * MINUTE_IN_SECONDS);
        $query = array(
            'client_id' => $settings['google_client_id'],
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        );
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($query);
    }

    public static function exchange_code($code) {
        $settings = Clickgauge_Admin::settings();
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array(
                'code' => $code,
                'client_id' => $settings['google_client_id'],
                'client_secret' => $settings['google_client_secret'],
                'redirect_uri' => self::redirect_uri(),
                'grant_type' => 'authorization_code',
            ),
        ));
        return self::store_token_response($response);
    }

    public static function access_token() {
        $token = get_option('wp_clickgauge_google_token', array());
        if (!is_array($token) || empty($token['refresh_token'])) {
            return new WP_Error('wp_clickgauge_gsc', __('Google Search Console is not connected.', 'wp-clickgauge'));
        }
        if (!empty($token['access_token']) && !empty($token['expires_at']) && time() < ((int) $token['expires_at'] - 60)) {
            return $token['access_token'];
        }
        $settings = Clickgauge_Admin::settings();
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array(
                'client_id' => $settings['google_client_id'],
                'client_secret' => $settings['google_client_secret'],
                'refresh_token' => $token['refresh_token'],
                'grant_type' => 'refresh_token',
            ),
        ));
        $stored = self::store_token_response($response, $token['refresh_token']);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $fresh = get_option('wp_clickgauge_google_token', array());
        return $fresh['access_token'];
    }

    public static function query($body) {
        $settings = Clickgauge_Admin::settings();
        if ($settings['gsc_property'] === '') {
            return new WP_Error('wp_clickgauge_gsc', __('Enter the Search Console property before scanning.', 'wp-clickgauge'));
        }
        $access = self::access_token();
        if (is_wp_error($access)) {
            return $access;
        }
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($settings['gsc_property']) . '/searchAnalytics/query';
        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = isset($data['error']['message']) ? $data['error']['message'] : __('Search Console request failed.', 'wp-clickgauge');
            return new WP_Error('wp_clickgauge_gsc', $message);
        }
        return isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : array();
    }

    public static function page_rows($days = 28) {
        return self::query(array(
            'startDate' => gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS)),
            'endDate' => gmdate('Y-m-d', time() - (3 * DAY_IN_SECONDS)),
            'dimensions' => array('page'),
            'rowLimit' => 1000,
            'dataState' => 'final',
        ));
    }

    public static function page_metrics($page_url, $days = 28) {
        $rows = self::query(array(
            'startDate' => gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS)),
            'endDate' => gmdate('Y-m-d', time() - (3 * DAY_IN_SECONDS)),
            'dimensions' => array('page'),
            'dimensionFilterGroups' => array(array(
                'filters' => array(array(
                    'dimension' => 'page',
                    'operator' => 'equals',
                    'expression' => $page_url,
                )),
            )),
            'rowLimit' => 1,
        ));
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (empty($rows[0])) {
            return array(
                'impressions' => 0,
                'clicks' => 0,
                'ctr' => 0,
                'position' => 0,
            );
        }
        return self::metrics_from_row($rows[0]);
    }

    public static function top_queries($page_url) {
        $rows = self::query(array(
            'startDate' => gmdate('Y-m-d', time() - (28 * DAY_IN_SECONDS)),
            'endDate' => gmdate('Y-m-d', time() - (3 * DAY_IN_SECONDS)),
            'dimensions' => array('query'),
            'dimensionFilterGroups' => array(array(
                'filters' => array(array(
                    'dimension' => 'page',
                    'operator' => 'equals',
                    'expression' => $page_url,
                )),
            )),
            'rowLimit' => 8,
        ));
        if (is_wp_error($rows)) {
            return array();
        }
        $queries = array();
        foreach ($rows as $row) {
            if (!empty($row['keys'][0])) {
                $queries[] = sanitize_text_field($row['keys'][0]);
            }
        }
        return $queries;
    }

    public static function metrics_from_row($row) {
        return array(
            'impressions' => isset($row['impressions']) ? (int) $row['impressions'] : 0,
            'clicks' => isset($row['clicks']) ? (int) $row['clicks'] : 0,
            'ctr' => isset($row['ctr']) ? (float) $row['ctr'] : 0,
            'position' => isset($row['position']) ? (float) $row['position'] : 0,
        );
    }

    private static function store_token_response($response, $existing_refresh = '') {
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['access_token'])) {
            $message = isset($data['error_description']) ? $data['error_description'] : __('Google did not return an access token.', 'wp-clickgauge');
            return new WP_Error('wp_clickgauge_gsc', $message);
        }
        $refresh = !empty($data['refresh_token']) ? $data['refresh_token'] : $existing_refresh;
        update_option('wp_clickgauge_google_token', array(
            'access_token' => $data['access_token'],
            'refresh_token' => $refresh,
            'expires_at' => time() + (isset($data['expires_in']) ? (int) $data['expires_in'] : 3600),
        ), false);
        return true;
    }
}
