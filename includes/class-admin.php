<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clickgauge_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_filter('plugin_action_links_' . plugin_basename(CLICKGAUGE_FILE), array(__CLASS__, 'links'));
        add_action('admin_post_clickguage_oauth_start', array(__CLASS__, 'oauth_start'));
        add_action('admin_post_clickguage_oauth_callback', array(__CLASS__, 'oauth_callback'));
        add_action('admin_post_clickguage_disconnect', array(__CLASS__, 'disconnect'));
        add_action('admin_post_clickguage_scan', array(__CLASS__, 'scan'));
        add_action('admin_post_clickguage_draft', array(__CLASS__, 'draft'));
        add_action('admin_post_clickguage_apply', array(__CLASS__, 'apply'));
        add_action('admin_post_clickguage_dismiss', array(__CLASS__, 'dismiss'));
        add_action('admin_post_clickguage_rollback', array(__CLASS__, 'rollback'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_notices', array(__CLASS__, 'time_limit_notice'));
    }

    public static function settings() {
        $defaults = array(
            'google_client_id' => '',
            'google_client_secret' => '',
            'gsc_property' => '',
            'ai_api_key' => '',
            'ai_model' => 'gpt-4o-mini',
            'ai_base_url' => 'https://api.openai.com/v1',
            'min_impressions' => 50,
            'recheck_days' => 21,
            'auto_apply' => 0,
            'auto_limit' => 5,
            'notify_on_timeout' => 0,
        );
        $stored = get_option('clickguage_settings', array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return array_merge($defaults, $stored);
    }

    public static function menu() {
        add_menu_page(
            __('Clickgauge', 'clickguage'),
            __('Clickgauge', 'clickguage'),
            'manage_options',
            'clickguage',
            array(__CLASS__, 'render_opportunities'),
            'dashicons-chart-line',
            81
        );
        add_submenu_page('clickguage', __('Opportunities', 'clickguage'), __('Opportunities', 'clickguage'), 'manage_options', 'clickguage', array(__CLASS__, 'render_opportunities'));
        add_submenu_page('clickguage', __('Experiments', 'clickguage'), __('Experiments', 'clickguage'), 'manage_options', 'clickguage-experiments', array(__CLASS__, 'render_experiments'));
        add_submenu_page('clickguage', __('Settings', 'clickguage'), __('Settings', 'clickguage'), 'manage_options', 'clickguage-settings', array(__CLASS__, 'render_settings'));
    }

    public static function links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=clickguage-settings')) . '">' . esc_html__('Settings', 'clickguage') . '</a>');
        return $links;
    }

    public static function register_settings() {
        register_setting('clickguage', 'clickguage_settings', array(
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'default' => array(),
        ));
    }

    public static function sanitize($input) {
        $current = self::settings();
        $clean = array(
            'google_client_id' => isset($input['google_client_id']) ? sanitize_text_field($input['google_client_id']) : '',
            'google_client_secret' => isset($input['google_client_secret']) ? sanitize_text_field($input['google_client_secret']) : '',
            'gsc_property' => isset($input['gsc_property']) ? sanitize_text_field($input['gsc_property']) : '',
            'ai_api_key' => isset($input['ai_api_key']) ? sanitize_text_field($input['ai_api_key']) : '',
            'ai_model' => isset($input['ai_model']) ? sanitize_text_field($input['ai_model']) : 'gpt-4o-mini',
            'ai_base_url' => isset($input['ai_base_url']) ? esc_url_raw($input['ai_base_url']) : 'https://api.openai.com/v1',
            'min_impressions' => isset($input['min_impressions']) ? max(1, (int) $input['min_impressions']) : 50,
            'recheck_days' => isset($input['recheck_days']) ? min(90, max(7, (int) $input['recheck_days'])) : 21,
            'auto_apply' => empty($input['auto_apply']) ? 0 : 1,
            'auto_limit' => isset($input['auto_limit']) ? min(20, max(1, (int) $input['auto_limit'])) : 5,
            'notify_on_timeout' => empty($input['notify_on_timeout']) ? 0 : 1,
        );
        if ($clean['google_client_secret'] === '') {
            $clean['google_client_secret'] = $current['google_client_secret'];
        }
        if ($clean['ai_api_key'] === '') {
            $clean['ai_api_key'] = $current['ai_api_key'];
        }
        return $clean;
    }

    public static function render_opportunities() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $items = get_transient('clickguage_opportunities');
        if (!is_array($items)) {
            $items = array();
        }
        echo '<div class="wrap">';
        self::render_header();
        echo '<p>' . esc_html__('Pages here already get impressions and sit near the bottom of page one, or they get impressions with a weak click-through rate. Draft a snippet, approve it, and Clickgauge checks Search Console again after the waiting period.', 'clickguage') . '</p>';
        echo '<p>' . esc_html(sprintf(__('Snippet fields are written to %s. Page content is not changed.', 'clickguage'), Clickgauge_Seo_Meta::provider_label())) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('clickguage_scan');
        echo '<input type="hidden" name="action" value="clickguage_scan" />';
        submit_button(__('Find opportunities', 'clickguage'), 'primary', 'submit', false);
        echo '</form>';
        echo '<table class="widefat striped" style="margin-top:16px"><thead><tr>';
        echo '<th>' . esc_html__('Page', 'clickguage') . '</th><th>' . esc_html__('Impressions', 'clickguage') . '</th><th>' . esc_html__('Clicks', 'clickguage') . '</th><th>' . esc_html__('CTR', 'clickguage') . '</th><th>' . esc_html__('Position', 'clickguage') . '</th><th></th>';
        echo '</tr></thead><tbody>';
        if (empty($items)) {
            echo '<tr><td colspan="6">' . esc_html__('No scan yet, or no pages matched.', 'clickguage') . '</td></tr>';
        }
        foreach ($items as $item) {
            echo '<tr>';
            echo '<td><a href="' . esc_url($item['url']) . '">' . esc_html($item['title']) . '</a></td>';
            echo '<td>' . esc_html((string) $item['impressions']) . '</td>';
            echo '<td>' . esc_html((string) $item['clicks']) . '</td>';
            echo '<td>' . esc_html(self::percent($item['ctr'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $item['position'], 1)) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('clickguage_draft_' . $item['post_id']);
            echo '<input type="hidden" name="action" value="clickguage_draft" />';
            echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $item['post_id']) . '" />';
            echo '<input type="hidden" name="url" value="' . esc_attr($item['url']) . '" />';
            echo '<input type="hidden" name="impressions" value="' . esc_attr((string) $item['impressions']) . '" />';
            echo '<input type="hidden" name="clicks" value="' . esc_attr((string) $item['clicks']) . '" />';
            echo '<input type="hidden" name="ctr" value="' . esc_attr((string) $item['ctr']) . '" />';
            echo '<input type="hidden" name="position" value="' . esc_attr((string) $item['position']) . '" />';
            submit_button(__('Draft snippet', 'clickguage'), 'secondary', 'submit', false);
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
        self::render_pending();
        echo '</div>';
    }

    public static function render_experiments() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap">';
        self::render_header();
        echo '<h2>' . esc_html__('Experiments', 'clickguage') . '</h2>';
        self::experiment_table(__('Active', 'clickguage'), 'applied', true);
        self::experiment_table(__('Won', 'clickguage'), 'won', false);
        self::experiment_table(__('Rolled back', 'clickguage'), 'rolled_back', false);
        echo '</div>';
    }

    public static function render_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
        $redirect_uri = Clickgauge_Search_Console::redirect_uri();
        $has_client = $settings['google_client_id'] !== '' && $settings['google_client_secret'] !== '';
        $connected = Clickgauge_Search_Console::is_connected();
        $has_ai_key = $settings['ai_api_key'] !== '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'search';
        if (!in_array($tab, array('search', 'ai', 'rules'), true)) {
            $tab = 'search';
        }
        $tab_url = admin_url('admin.php?page=clickguage-settings');
        echo '<div class="wrap">';
        self::render_header();
        echo '<h2>' . esc_html__('Settings', 'clickguage') . '</h2>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url(add_query_arg('tab', 'search', $tab_url)) . '" class="nav-tab' . ($tab === 'search' ? ' nav-tab-active' : '') . '">' . esc_html__('Search Console', 'clickguage') . '</a>';
        echo '<a href="' . esc_url(add_query_arg('tab', 'ai', $tab_url)) . '" class="nav-tab' . ($tab === 'ai' ? ' nav-tab-active' : '') . '">' . esc_html__('AI API', 'clickguage') . '</a>';
        echo '<a href="' . esc_url(add_query_arg('tab', 'rules', $tab_url)) . '" class="nav-tab' . ($tab === 'rules' ? ' nav-tab-active' : '') . '">' . esc_html__('Test rules', 'clickguage') . '</a>';
        echo '</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('clickguage');
        echo '<div' . ($tab === 'search' ? '' : ' hidden') . '>';
        echo '<div class="card" style="max-width:760px;padding:8px 20px 16px;margin-top:16px;">';
        echo '<h2>' . esc_html__('Connect Google Search Console', 'clickguage') . '</h2>';
        if ($connected) {
            echo '<p><strong>' . esc_html__('Search Console is connected.', 'clickguage') . '</strong> ';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=clickguage_disconnect'), 'clickguage_disconnect')) . '">' . esc_html__('Disconnect', 'clickguage') . '</a></p>';
        } elseif ($has_client) {
            echo '<p>' . esc_html__('The client ID and secret are saved. Connect Search Console with the Google account that can open this site’s property.', 'clickguage') . '</p>';
        } else {
            echo '<p>' . esc_html__('Clickgauge is not connected yet. Create a Google Cloud OAuth client, paste its ID and secret below, save, then connect.', 'clickguage') . '</p>';
        }
        echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url('https://console.cloud.google.com/apis/credentials') . '">' . esc_html__('Start in Google Cloud', 'clickguage') . '</a></p>';
        echo '<ol>';
        echo '<li>' . esc_html__('In Google Cloud, create a project or choose an existing one.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Enable the Google Search Console API for that project.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Open the OAuth consent screen and choose External. While the app stays in Testing, add the Google account that owns this Search Console property as a test user.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Create an OAuth client ID. Set the application type to Web application.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Under Authorized redirect URIs, add this value exactly. The query string is part of the URI.', 'clickguage');
        echo '<p><input type="text" class="large-text code" readonly="readonly" value="' . esc_attr($redirect_uri) . '" onclick="this.select();" /></p></li>';
        echo '<li>' . esc_html__('Copy the client ID and client secret into the fields below and click Save Changes.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Click Connect Search Console and approve access. Clickgauge only requests read access to Search Console.', 'clickguage') . '</li>';
        echo '</ol>';
        if (!$connected && $has_client) {
            $start = wp_nonce_url(admin_url('admin-post.php?action=clickguage_oauth_start'), 'clickguage_oauth_start');
            echo '<p><a class="button button-primary" href="' . esc_url($start) . '">' . esc_html__('Connect Search Console', 'clickguage') . '</a></p>';
        }
        echo '<p>' . esc_html__('Search Console property is the exact property name, such as https://example.com/ or sc-domain:example.com.', 'clickguage') . '</p>';
        echo '</div>';
        echo '<table class="form-table">';
        self::text_row('google_client_id', __('Google client ID', 'clickguage'), $settings['google_client_id']);
        self::secret_row('google_client_secret', __('Google client secret', 'clickguage'), $settings['google_client_secret']);
        self::text_row('gsc_property', __('Search Console property', 'clickguage'), $settings['gsc_property']);
        echo '</table>';
        submit_button();
        echo '</div>';
        echo '<div' . ($tab === 'ai' ? '' : ' hidden') . '>';
        echo '<div class="card" style="max-width:760px;padding:8px 20px 16px;margin-top:16px;">';
        echo '<h2>' . esc_html__('Connect the AI API', 'clickguage') . '</h2>';
        if ($has_ai_key) {
            echo '<p><strong>' . esc_html__('An AI API key is saved.', 'clickguage') . '</strong> ';
            echo esc_html__('Draft a snippet on the Opportunities screen to confirm the key, model, and base URL.', 'clickguage') . '</p>';
        } else {
            echo '<p>' . esc_html__('No AI API key is saved yet. Create a key, paste it into the field below, and save.', 'clickguage') . '</p>';
        }
        echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url('https://platform.openai.com/api-keys') . '">' . esc_html__('Create an API key', 'clickguage') . '</a></p>';
        echo '<ol>';
        echo '<li>' . esc_html__('Sign in to OpenAI, or to another service that implements the OpenAI chat completions API.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Create a secret API key and copy it. The provider shows the full key once.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Add a payment method on that account if the provider requires one. A key with no credit returns an error when a snippet is drafted.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('Paste the key into AI API key below. After it is saved, leave that field blank and save again to keep the same key.', 'clickguage') . '</li>';
        echo '<li>' . esc_html__('For OpenAI, set AI base URL to this value. For another provider, use that provider’s base URL. Leave /chat/completions off the end. Clickgauge adds it.', 'clickguage');
        echo '<p><input type="text" class="large-text code" readonly="readonly" value="https://api.openai.com/v1" onclick="this.select();" /></p></li>';
        echo '<li>' . esc_html__('Set AI model to a model this key is allowed to use. The default is:', 'clickguage');
        echo '<p><input type="text" class="large-text code" readonly="readonly" value="gpt-4o-mini" onclick="this.select();" /></p></li>';
        echo '<li>' . esc_html__('Click Save Changes.', 'clickguage') . '</li>';
        echo '</ol>';
        if ($has_ai_key) {
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=clickguage')) . '">' . esc_html__('Try a draft', 'clickguage') . '</a></p>';
        }
        echo '</div>';
        echo '<table class="form-table">';
        self::secret_row('ai_api_key', __('AI API key', 'clickguage'), $settings['ai_api_key']);
        self::text_row('ai_model', __('AI model', 'clickguage'), $settings['ai_model']);
        self::text_row('ai_base_url', __('AI base URL', 'clickguage'), $settings['ai_base_url']);
        echo '</table>';
        submit_button();
        echo '</div>';
        echo '<div' . ($tab === 'rules' ? '' : ' hidden') . '>';
        echo '<div class="card" style="max-width:760px;padding:8px 20px 16px;margin-top:16px;">';
        echo '<h2>' . esc_html__('Test rules', 'clickguage') . '</h2>';
        echo '<p>' . esc_html__('These control which pages are offered, how long a test runs, and whether a draft goes live without approval.', 'clickguage') . '</p>';
        echo '<p>' . esc_html__('IndexNow key file:', 'clickguage') . ' <code>' . esc_html(home_url('/' . get_option('clickguage_indexnow_key') . '.txt')) . '</code></p>';
        echo '</div>';
        echo '<table class="form-table">';
        self::number_row('min_impressions', __('Minimum impressions', 'clickguage'), $settings['min_impressions']);
        self::number_row('recheck_days', __('Days before recheck', 'clickguage'), $settings['recheck_days']);
        self::number_row('auto_limit', __('Auto-apply limit per scan', 'clickguage'), $settings['auto_limit']);
        echo '<tr><th>' . esc_html__('Auto-apply', 'clickguage') . '</th><td><label><input type="checkbox" name="clickguage_settings[auto_apply]" value="1" ' . checked(1, (int) $settings['auto_apply'], false) . ' /> ';
        echo esc_html__('Apply title and meta description without approval. Page content is never changed.', 'clickguage') . '</label></td></tr>';
        echo '<tr><th>' . esc_html__('PHP time limit', 'clickguage') . '</th><td>';
        echo '<p>' . esc_html(Clickgauge_Runtime::status_text()) . '</p>';
        $time_warning = Clickgauge_Runtime::warning_text();
        if ($time_warning !== '') {
            echo '<p><strong>' . esc_html($time_warning) . '</strong></p>';
        }
        echo '<label><input type="checkbox" name="clickguage_settings[notify_on_timeout]" value="1" ' . checked(1, (int) $settings['notify_on_timeout'], false) . ' /> ';
        echo esc_html(sprintf(__('Email %s if PHP stops a scan, draft, or recheck.', 'clickguage'), get_option('admin_email'))) . '</label>';
        echo '<p class="description">' . esc_html__('This email is sent when PHP reports that it stopped the request. A web server timeout can end the page without sending mail.', 'clickguage') . '</p>';
        echo '</td></tr>';
        echo '</table>';
        submit_button();
        echo '</div>';
        echo '</form></div>';
    }

    private static function render_header() {
        $logo = plugins_url('ClickGauge_badge.png', CLICKGAUGE_FILE);
        echo '<div style="display:flex;align-items:center;gap:16px;margin:8px 0 16px;">';
        echo '<img src="' . esc_url($logo) . '" alt="" width="80" height="80" style="width:80px;height:80px;flex:0 0 auto;" />';
        echo '<div><h1 style="padding:0;margin:0 0 6px;line-height:1.2;">' . esc_html__('Clickgauge', 'clickguage') . '</h1>';
        echo '<p style="margin:0;max-width:640px;">' . esc_html__('Clickgauge tests a new title and meta description, then checks Search Console and restores the previous snippet if clicks drop. Page content is never changed.', 'clickguage') . '</p>';
        echo '</div></div>';
    }

    public static function oauth_start() {
        self::guard('clickguage_oauth_start');
        $settings = self::settings();
        if ($settings['google_client_id'] === '' || $settings['google_client_secret'] === '') {
            self::redirect_notice('error', __('Save the Google client ID and secret first.', 'clickguage'));
        }
        wp_redirect(Clickgauge_Search_Console::auth_url());
        exit;
    }

    public static function oauth_callback() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot connect Search Console.', 'clickguage'));
        }
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $saved = get_transient('clickguage_oauth_state');
        delete_transient('clickguage_oauth_state');
        if (!$saved || !hash_equals((string) $saved, $state)) {
            self::redirect_notice('error', __('The Google login session expired. Connect again.', 'clickguage'));
        }
        if (empty($_GET['code'])) {
            self::redirect_notice('error', __('Google did not return an authorization code.', 'clickguage'));
        }
        $result = Clickgauge_Search_Console::exchange_code(sanitize_text_field(wp_unslash($_GET['code'])));
        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }
        self::redirect_notice('success', __('Search Console is connected.', 'clickguage'), 'clickguage-settings');
    }

    public static function disconnect() {
        self::guard('clickguage_disconnect');
        update_option('clickguage_google_token', array(), false);
        self::redirect_notice('success', __('Search Console was disconnected.', 'clickguage'), 'clickguage-settings');
    }

    public static function scan() {
        self::guard('clickguage_scan');
        $result = Clickgauge_Experiments::scan();
        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }
        self::redirect_notice('success', sprintf(__('Found %d pages.', 'clickguage'), count($result)));
    }

    public static function draft() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot draft snippets.', 'clickguage'));
        }
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        check_admin_referer('clickguage_draft_' . $post_id);
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $metrics = array(
            'impressions' => isset($_POST['impressions']) ? (int) $_POST['impressions'] : 0,
            'clicks' => isset($_POST['clicks']) ? (int) $_POST['clicks'] : 0,
            'ctr' => isset($_POST['ctr']) ? (float) $_POST['ctr'] : 0,
            'position' => isset($_POST['position']) ? (float) $_POST['position'] : 0,
        );
        $result = Clickgauge_Experiments::draft($post_id, $url, $metrics);
        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }
        self::redirect_notice('success', __('Draft saved. Review it below before it goes live.', 'clickguage'));
    }

    public static function apply() {
        $id = isset($_POST['experiment_id']) ? (int) $_POST['experiment_id'] : 0;
        self::guard('clickguage_apply_' . $id);
        $result = Clickgauge_Experiments::apply($id);
        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }
        self::redirect_notice('success', __('Snippet applied. Search Console will be checked again after the waiting period.', 'clickguage'));
    }

    public static function dismiss() {
        $id = isset($_POST['experiment_id']) ? (int) $_POST['experiment_id'] : 0;
        self::guard('clickguage_dismiss_' . $id);
        Clickgauge_Experiments::dismiss($id);
        self::redirect_notice('success', __('Draft dismissed.', 'clickguage'));
    }

    public static function rollback() {
        $id = isset($_POST['experiment_id']) ? (int) $_POST['experiment_id'] : 0;
        self::guard('clickguage_rollback_' . $id);
        $result = Clickgauge_Experiments::rollback($id);
        if (is_wp_error($result)) {
            self::redirect_notice('error', $result->get_error_message());
        }
        self::redirect_notice('success', __('The previous title and description were restored.', 'clickguage'), 'clickguage-experiments');
    }

    public static function time_limit_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'clickguage' && strpos($page, 'clickguage-') !== 0) {
            return;
        }
        $warning = Clickgauge_Runtime::warning_text();
        if ($warning === '') {
            return;
        }
        $class = Clickgauge_Runtime::time_limit() < 60 ? 'notice-warning' : 'notice-info';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($warning) . ' ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=clickguage-settings&tab=rules')) . '">' . esc_html__('Review the PHP time limit.', 'clickguage') . '</a></p></div>';
    }

    public static function notice() {
        if (!current_user_can('manage_options') || empty($_GET['clickguage_notice']) || empty($_GET['clickguage_type'])) {
            return;
        }
        $type = sanitize_key(wp_unslash($_GET['clickguage_type']));
        if (!in_array($type, array('success', 'error'), true)) {
            $type = 'success';
        }
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['clickguage_notice']))) . '</p></div>';
    }

    private static function render_pending() {
        $rows = Clickgauge_Experiments::list_by_status('pending', 20);
        echo '<h2>' . esc_html__('Drafts waiting for approval', 'clickguage') . '</h2>';
        if (empty($rows)) {
            echo '<p>' . esc_html__('No drafts yet.', 'clickguage') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Page', 'clickguage') . '</th><th>' . esc_html__('Proposed title', 'clickguage') . '</th><th>' . esc_html__('Proposed description', 'clickguage') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html(get_the_title((int) $row->post_id)) . '</td>';
            echo '<td>' . esc_html($row->new_title) . '</td>';
            echo '<td>' . esc_html($row->new_description) . '</td><td>';
            self::action_form('clickguage_apply', (int) $row->id, __('Apply', 'clickguage'), 'primary');
            echo ' ';
            self::action_form('clickguage_dismiss', (int) $row->id, __('Dismiss', 'clickguage'), 'secondary');
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function experiment_table($heading, $status, $allow_rollback) {
        $rows = Clickgauge_Experiments::list_by_status($status, 50);
        echo '<h2>' . esc_html($heading) . '</h2>';
        if (empty($rows)) {
            echo '<p>' . esc_html__('None.', 'clickguage') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Page', 'clickguage') . '</th><th>' . esc_html__('Baseline position', 'clickguage') . '</th><th>' . esc_html__('Latest position', 'clickguage') . '</th><th>' . esc_html__('Baseline CTR', 'clickguage') . '</th><th>' . esc_html__('Latest CTR', 'clickguage') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><a href="' . esc_url($row->url) . '">' . esc_html(get_the_title((int) $row->post_id)) . '</a></td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row->baseline_position, 1)) . '</td>';
            echo '<td>' . esc_html($row->checked_at ? number_format_i18n((float) $row->followup_position, 1) : '—') . '</td>';
            echo '<td>' . esc_html(self::percent($row->baseline_ctr)) . '</td>';
            echo '<td>' . esc_html($row->checked_at ? self::percent($row->followup_ctr) : '—') . '</td><td>';
            if ($allow_rollback) {
                self::action_form('clickguage_rollback', (int) $row->id, __('Restore previous', 'clickguage'), 'secondary');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function action_form($action, $id, $label, $class) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field($action . '_' . $id);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        echo '<input type="hidden" name="experiment_id" value="' . esc_attr((string) $id) . '" />';
        submit_button($label, $class, 'submit', false);
        echo '</form>';
    }

    private static function text_row($key, $label, $value) {
        echo '<tr><th><label for="clickguage-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" id="clickguage-' . esc_attr($key) . '" name="clickguage_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" /></td></tr>';
    }

    private static function secret_row($key, $label, $value) {
        $placeholder = $value !== '' ? __('Saved. Leave blank to keep it.', 'clickguage') : '';
        echo '<tr><th><label for="clickguage-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" type="password" autocomplete="new-password" id="clickguage-' . esc_attr($key) . '" name="clickguage_settings[' . esc_attr($key) . ']" value="" placeholder="' . esc_attr($placeholder) . '" /></td></tr>';
    }

    private static function number_row($key, $label, $value) {
        echo '<tr><th><label for="clickguage-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="small-text" type="number" id="clickguage-' . esc_attr($key) . '" name="clickguage_settings[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" /></td></tr>';
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot do that.', 'clickguage'));
        }
        check_admin_referer($action);
    }

    private static function redirect_notice($type, $message, $page = 'clickguage') {
        wp_safe_redirect(add_query_arg(array(
            'page' => $page,
            'clickguage_type' => $type,
            'clickguage_notice' => $message,
        ), admin_url('admin.php')));
        exit;
    }

    private static function percent($ctr) {
        return number_format_i18n(((float) $ctr) * 100, 1) . '%';
    }
}
