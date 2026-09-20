<?php
/*
Plugin Name: DevDome Redirect Manager
Plugin URI: https://devdome.com/wp-plugins/redirect-manager/
Description: Manage redirects and rotate outgoing links with geo, device and schedule targeting. Part of the DevDome suite.
Version: 1.5.5
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

define('DEVDREDI_VERSION', '1.5.5');
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
require_once DEVDREDI_DIR . 'includes/bots.php';
require_once DEVDREDI_DIR . 'includes/never-redirect.php';
require_once DEVDREDI_DIR . 'includes/visitor-check.php';
require_once DEVDREDI_DIR . 'includes/daily-limit.php';
require_once DEVDREDI_DIR . 'includes/geo.php';
require_once DEVDREDI_DIR . 'includes/search.php';
require_once DEVDREDI_DIR . 'includes/stats.php';
require_once DEVDREDI_DIR . 'includes/engine.php';
require_once DEVDREDI_DIR . 'includes/install.php';
require_once DEVDREDI_DIR . 'includes/abilities.php';

if (is_admin()) {
    require_once DEVDREDI_DIR . 'includes/admin.php';
}

/** Bots Skipped across every rule (1.5.4): known bots, outdated browsers and refused Visitor Check passes. Not redirected, not proof of fraud. */
function devdredi_bots_skipped_total()
{
    $n = 0;
    unset($GLOBALS['devdredi_read_failed']);
    $rules = devdredi_get_rules();
    if (devdredi_rules_index_unreadable()) {
        return null;
    }
    foreach ($rules as $x) {
        if (!empty($x['id'])) {
            $n += (int) devdredi_raw_get_setting('rule__' . $x['id'] . '__user_bot_skip_count', 0);
        }
    }
    return empty($GLOBALS['devdredi_read_failed']) ? $n : null; // a count that could not be read is "unknown", never a number
}

// The same count for DevDome Bot Protection's aggregate dashboard, when that plugin is installed (it applies the filter).
add_filter('devdome_click_fraud_sources', function ($sources) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- shared suite hook owned by DevDome Bot Protection
    $n = devdredi_bots_skipped_total();
    if ($n !== null) {
        $sources[] = array('key' => 'redirect', 'label' => 'Redirects skipped for bots', 'blocked' => $n);
    }
    return $sources;
});

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
            $b = function_exists('devdredi_bots_skipped_total') ? devdredi_bots_skipped_total() : null;
            return array(
                array('label' => 'Redirects served', 'value' => $v, 'fmt' => 'int', 'state' => $v ? 'good' : 'idle', 'href' => 'admin.php?page=devdome-redirect-manager'),
                array('label' => 'Bots skipped', 'value' => $b, 'fmt' => 'int', 'state' => $b ? 'good' : 'idle', 'href' => 'admin.php?page=devdome-redirect-manager'),
            );
        },
    );
    return $r;
});

// S3: Recent-activity digest section.
add_filter('devdcorev1_suite_report_sections', function ($s) {
    if (!function_exists('devdredi_get_setting')) { return $s; }
    $lines = array((int) devdredi_get_setting('user_redirects_count', 0) . ' redirects served');
    $bots  = devdredi_bots_skipped_total();
    if ($bots !== null) {
        $lines[] = $bots . ' bots skipped';
    }
    $s[] = array('title' => 'Redirect Manager', 'lines' => $lines);
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

    // 1.5.4: the Visitor Check passes table. Its own marker, not the version above: right after an update a stale
    // opcache copy of the OLD file can run this function, store the new version number and create nothing.
    if (get_option('devdredi_passes_db') !== '1' && !get_transient('devdredi_passes_wait')) {
        set_transient('devdredi_passes_wait', 1, HOUR_IN_SECONDS); // a database that refuses CREATE is asked once an hour, not on every request
        devdredi_pass_install();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time existence probe of the plugin's own table.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(devdredi_pass_table()))) === devdredi_pass_table()) {
            update_option('devdredi_passes_db', '1');
            delete_transient('devdredi_passes_wait');
        }
    }
}
