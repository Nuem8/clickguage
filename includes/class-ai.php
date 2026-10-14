<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_Ai {

    public static function draft($post, $queries, $current_title, $current_description) {
        $settings = Clickgauge_Admin::settings();
        if ($settings['ai_api_key'] === '') {
            return new WP_Error('clickguage_ai', __('Add an AI API key in Clickgauge settings.', 'clickguage'));
        }
        $excerpt = wp_strip_all_tags($post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content);
        $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt));
        if (function_exists('mb_substr')) {
            $excerpt = mb_substr($excerpt, 0, 1200);
        } else {
            $excerpt = substr($excerpt, 0, 1200);
        }
        $prompt = "Return only JSON with keys title and description.\n"
            . "Title must be 60 characters or fewer. Description must be 155 characters or fewer.\n"
            . "Use the real search queries. Do not invent facts that are not in the excerpt.\n"
            . "Page title: " . $post->post_title . "\n"
            . "Current SEO title: " . $current_title . "\n"
            . "Current meta description: " . $current_description . "\n"
            . "Queries: " . implode(', ', $queries) . "\n"
            . "Excerpt: " . $excerpt;

        $endpoint = rtrim($settings['ai_base_url'], '/') . '/chat/completions';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 40,
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['ai_api_key'],
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => $settings['ai_model'],
                'temperature' => 0.3,
                'messages' => array(
                    array('role' => 'system', 'content' => 'You write search snippets. Respond with JSON only.'),
                    array('role' => 'user', 'content' => $prompt),
                ),
            )),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = isset($data['error']['message']) ? $data['error']['message'] : __('The AI request failed.', 'clickguage');
            return new WP_Error('clickguage_ai', $message);
        }
        $content = '';
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
        }
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);
        $parsed = json_decode(trim($content), true);
        if (!is_array($parsed) || empty($parsed['title']) || empty($parsed['description'])) {
            return new WP_Error('clickguage_ai', __('The AI response was not a title and description.', 'clickguage'));
        }
        return array(
            'title' => self::clip(wp_strip_all_tags($parsed['title']), 60),
            'description' => self::clip(wp_strip_all_tags($parsed['description']), 155),
        );
    }

    private static function clip($text, $limit) {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
            return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
        }
        if (strlen($text) > $limit) {
            return rtrim(substr($text, 0, $limit - 1)) . '...';
        }
        return $text;
    }
}
