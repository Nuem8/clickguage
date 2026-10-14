<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_IndexNow {

    public static function maybe_serve_key() {
        $key = get_option('wp_clickgauge_indexnow_key');
        if (!is_string($key) || $key === '') {
            return;
        }
        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        $home = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home = is_string($home) ? $home : '/';
        $expected = trailingslashit($home) . $key . '.txt';
        if (!is_string($path) || untrailingslashit($path) !== untrailingslashit($expected)) {
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $key;
        exit;
    }

    public static function ping($url) {
        $key = get_option('wp_clickgauge_indexnow_key');
        if (!is_string($key) || $key === '' || $url === '') {
            return;
        }
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        wp_remote_post('https://api.indexnow.org/indexnow', array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => wp_json_encode(array(
                'host' => $host,
                'key' => $key,
                'keyLocation' => home_url('/' . $key . '.txt'),
                'urlList' => array($url),
            )),
        ));
    }
}
