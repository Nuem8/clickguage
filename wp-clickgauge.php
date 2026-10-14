<?php
/**
 * Plugin Name: Clickgauge
 * Description: Tests title and meta description changes against Google Search Console and rolls back the ones that lose clicks.
 * Version: 1.0.0
 * Author: NueM8
 * Author URI: https://nuem8.com
 * Plugin URI: https://nuem8.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-clickgauge
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLICKGAUGE_VERSION', '1.0.0');
define('CLICKGAUGE_FILE', __FILE__);
define('CLICKGAUGE_DIR', plugin_dir_path(__FILE__));

require_once CLICKGAUGE_DIR . 'includes/class-runtime.php';
require_once CLICKGAUGE_DIR . 'includes/class-activator.php';
require_once CLICKGAUGE_DIR . 'includes/class-search-console.php';
require_once CLICKGAUGE_DIR . 'includes/class-seo-meta.php';
require_once CLICKGAUGE_DIR . 'includes/class-ai.php';
require_once CLICKGAUGE_DIR . 'includes/class-experiments.php';
require_once CLICKGAUGE_DIR . 'includes/class-indexnow.php';
require_once CLICKGAUGE_DIR . 'includes/class-admin.php';

register_activation_hook(CLICKGAUGE_FILE, array('Clickgauge_Activator', 'activate'));
register_deactivation_hook(CLICKGAUGE_FILE, array('Clickgauge_Activator', 'deactivate'));

add_action('plugins_loaded', array('Clickgauge_Admin', 'init'));
add_action('init', array('Clickgauge_IndexNow', 'maybe_serve_key'), 0);
add_action('wp_clickgauge_recheck', array('Clickgauge_Experiments', 'recheck_due'));
add_filter('document_title_parts', array('Clickgauge_Seo_Meta', 'filter_document_title'));
add_action('wp_head', array('Clickgauge_Seo_Meta', 'print_description'), 1);
