<?php
/**
 * Activation / deactivation lifecycle.
 * (register_activation_hook / register_deactivation_hook stay in the main plugin file
 *  because they must reference the main file path via __FILE__.)
 */

defined('ABSPATH') || exit;

function devdredi_activate()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'devdredi_settings';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_name varchar(191) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_name (setting_name)
    ) $charset_collate;";

    // Self-hosted builds: move the legacy-prefix table first. Activation runs after plugins_loaded, so
    // without this the empty new table would be created first and the rename skipped for good.
    if (function_exists('devdredi_maybe_migrate')) {
        devdredi_maybe_migrate();
    }

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    devdredi_pass_install();

    $default_settings = array(
        'run_mode' => 'unlimited',
        'schedule_timezone' => '',
        'runtime_minutes' => 0,
        'run_once' => 'ip',
        'start_time' => current_time('timestamp'),
        'visitor_count' => 0,
		'page_view_count' => 0,
        'user_redirects_count' => 0,
        'ua_list' => array(),
        'ip_list' => array(),
        'ip_redirected_once' => array(),
        'last_redirects' => array(),
        'geo_filter_enabled' => 0,
        'geo_filter_country_codes' => '',
        'geo_filter_mode' => 'whitelist',
        'geo_filter_whitelist' => '',
        'geo_filter_blacklist' => '',
        'fallback_mode' => 'leave',
        'fallback_url' => '',
        'links_mode' => 'sequential',
        'open_mode' => 'same_tab',
        'what_to_redirect' => 'entire_website',
        'redirect_type' => 'js',
        'device_desktop' => 1,
        'device_mobile' => 1,
        'device_tablet' => 1,
        'same_tab_delay_min' => 0,
        'same_tab_delay_max' => 0,
        'same_tab_after_click_enabled' => 0,
        'same_tab_after_click_min' => 0,
        'same_tab_after_click_max' => 0,
        'new_tab_delay_min' => 0,
        'new_tab_delay_max' => 0,
        'after_click_enabled' => 0,
        'after_click_min' => 0,
        'after_click_max' => 0,
        'open_on_every' => 1,
        'run_weekdays' => array(),
        'specific_times' => array(
            array('enable' => 0, 'start' => '00:00', 'end' => '23:59'),
            array('enable' => 0, 'start' => '00:00', 'end' => '23:59'),
            array('enable' => 0, 'start' => '00:00', 'end' => '23:59'),
        ),
    );

    // Seed defaults only when missing so reactivation/upgrade never wipes existing settings.
    foreach ($default_settings as $name => $value) {
        // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- activation seed into the plugin's own table; $table_name from $wpdb->prefix; values prepared.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table_name (setting_name, setting_value) VALUES (%s, %s)",
            $name,
            is_array($value) ? serialize($value) : $value
        ));
        // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
    }

    // Activation is non-destructive (matching deactivate): we deliberately do NOT wipe the plugin's
    // own devdredi_* options here. The old wildcard DELETE ran on every (re)activation and erased
    // the rotation counters, the rules-migration guards and the version marker, needlessly resetting
    // visitor rotation and re-running migrations. Full removal happens only on delete (uninstall.php).
    wp_cache_flush();
}

function devdredi_deactivate()
{
    // Deactivation is non-destructive: settings + stats are preserved so the plugin can be
    // toggled off/on safely. Full data removal happens only on delete (see uninstall.php).
    wp_clear_scheduled_hook('devdredi_refresh_bots');
    wp_cache_flush();
}
