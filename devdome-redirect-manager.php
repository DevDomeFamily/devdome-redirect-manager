<?php
/*
Plugin Name: DevDome Redirect Manager
Plugin URI: https://devdome.com/wp-plugins/redirect-manager/
Description: Manage redirects and rotate outgoing links with geo, device and schedule targeting. Part of the DevDome suite.
Version: 1.5.0
Author: DevDome
Author URI: https://devdome.com
Requires at least: 5.6
Requires PHP: 7.4
Text Domain: devdome-redirect-manager
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit;
}

// WordPress.org build marker: the wp.org packaging step adds wporg-build.php, which
// defines DEVDCOREV1_WPORG_BUILD (gates the self-hosted updater + DevDome feed calls).
if (file_exists(__DIR__ . '/wporg-build.php')) {
    require __DIR__ . '/wporg-build.php';
}

define('DEVDREDI_VERSION', '1.5.0');
define('DEVDREDI_DIR', plugin_dir_path(__FILE__));
define('DEVDREDI_URL', plugin_dir_url(__FILE__));

// Shared DevDome core (vendored, version-guarded — only the highest copy across all
// installed DevDome plugins loads). Provides the suite admin hub.
require_once DEVDREDI_DIR . 'lib/devdome-core/loader.php';

// Legacy shared-state copy (devdome_* -> devdcorev1_*) ships only in self-hosted builds:
// wp.org installs are fresh and have no old rows to move.
if (file_exists(DEVDREDI_DIR . 'includes/rm-core-ids.php')) {
    require_once DEVDREDI_DIR . 'includes/rm-core-ids.php';
}
// One-time devdome_rm_ -> devdredi_ data migration. Stripped from the
// WordPress.org build (fresh installs there have no legacy rows).
if (file_exists(DEVDREDI_DIR . 'includes/migrate.php')) {
    require_once DEVDREDI_DIR . 'includes/migrate.php';
}

require_once DEVDREDI_DIR . 'includes/settings.php';
require_once DEVDREDI_DIR . 'includes/helpers.php';
require_once DEVDREDI_DIR . 'includes/geo.php';
require_once DEVDREDI_DIR . 'includes/search.php';
require_once DEVDREDI_DIR . 'includes/stats.php';
require_once DEVDREDI_DIR . 'includes/engine.php';
require_once DEVDREDI_DIR . 'includes/install.php';
require_once DEVDREDI_DIR . 'includes/abilities.php';

if (is_admin()) {
    require_once DEVDREDI_DIR . 'includes/admin.php';
}

// DevDome Tools hub: register this plugin in the suite dashboard.
add_filter('devdcorev1_suite_register', function ($r) {
    $r['devdome-redirect-manager'] = array(
        'slug'     => 'devdome-redirect-manager',
        'name'     => 'Redirect Manager',
        'desc'     => 'Rule-based redirects &amp; link rotation with per-rule stats.',
        'icon'     => 'dashicons-randomize',
        'version'  => defined('DEVDREDI_VERSION') ? DEVDREDI_VERSION : '',
        'page'     => 'devdome-redirect-manager',
        'position' => 50,
        'schema'   => 1,
        'tiles'    => function () {
            $v = function_exists('devdredi_get_setting') ? (int) devdredi_get_setting('user_redirects_count', 0) : null;
            return array(array('label' => 'Redirects served', 'value' => $v, 'fmt' => 'int', 'state' => $v ? 'good' : 'idle', 'href' => 'admin.php?page=devdome-redirect-manager'));
        },
    );
    return $r;
});

// S3: Recent-activity digest section.
add_filter('devdcorev1_suite_report_sections', function ($s) {
    if (!function_exists('devdredi_get_setting')) { return $s; }
    $s[] = array('title' => 'Redirect Manager', 'lines' => array((int) devdredi_get_setting('user_redirects_count', 0) . ' redirects served'));
    return $s;
});

register_activation_hook(__FILE__, 'devdredi_activate');
register_deactivation_hook(__FILE__, 'devdredi_deactivate');

add_action('plugins_loaded', 'devdredi_check_version');
function devdredi_check_version()
{
    $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
    $current_version = $plugin_data['Version'];

    if (get_option('devdredi_plugin_version') !== $current_version) {

        if (strpos($current_version, "777") !== false) {
            devdredi_deactivate();
            devdredi_activate();
        }
        update_option('devdredi_plugin_version', $current_version);
    }
}
