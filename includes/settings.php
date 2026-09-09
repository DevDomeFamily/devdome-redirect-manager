<?php
/**
 * Settings store (custom key/value table) + small setting/session helpers.
 *
 * Rules: every rule keeps its OWN full copy of the settings, stored namespaced as
 * "rule__<id>__<name>". devdredi_get_setting()/update_setting() transparently read/write the
 * ACTIVE rule's copy, so all existing call sites become per-rule with no changes. A small set
 * of keys (the rules index, the editing pointer, the shared bot feed) stay global.
 */

defined('ABSPATH') || exit;

/** Keys that are NOT per-rule (shared across the whole plugin). */
function devdredi_global_setting_keys()
{
    return array('rm_rules', 'active_rule');
}

/** Effective schedule mode for the active rule. */
function devdredi_effective_run_mode()
{
    return devdredi_get_setting('run_mode', 'unlimited');
}

/** The rule whose settings get/update currently operate on (engine sets this per rule in its loop). */
function devdredi_active_rule_id()
{
    if (!empty($GLOBALS['devdredi_rule'])) {
        return $GLOBALS['devdredi_rule'];
    }
    return devdredi_raw_get_setting('active_rule', 'r1');
}

/** Map a logical setting name to its stored (rule-scoped or global) name. */
function devdredi_scoped_name($name)
{
    if (in_array($name, devdredi_global_setting_keys(), true)) {
        return $name;
    }
    if (strpos($name, 'rule__') === 0) {
        return $name; // already scoped
    }
    return 'rule__' . devdredi_active_rule_id() . '__' . $name;
}

/** Direct (un-scoped) table read. */
function devdredi_raw_get_setting($name, $default = '')
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'devdredi_settings';

    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; table name from $wpdb->prefix (no user input); values are prepared.
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM $table_name WHERE setting_name = %s",
        $name
    ));
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB

    if ($value === null) {
        return $default;
    }

    // allowed_classes=false: stored settings are only scalar arrays (run_weekdays, ip_list, …);
    // never instantiate an object from stored data, so a poisoned value can't trigger PHP object
    // injection on the front-end read path even if a bad value slipped past the import guard.
    $unserialized = @unserialize($value, array('allowed_classes' => false));
    return $unserialized === false ? $value : $unserialized;
}

/** Direct (un-scoped) table write. */
function devdredi_raw_update_setting($name, $value)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'devdredi_settings';

    $stored = is_array($value) ? serialize($value) : $value;
    // INSERT ... ON DUPLICATE KEY UPDATE rather than $wpdb->replace(): REPLACE is a DELETE+INSERT
    // that can deadlock under the concurrent writes the front-end counters generate (and it churns
    // the row's auto-increment id). The upsert updates in place on the UNIQUE setting_name key.
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; $table_name from $wpdb->prefix; values prepared.
    return $wpdb->query($wpdb->prepare(
        "INSERT INTO $table_name (setting_name, setting_value) VALUES (%s, %s)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $name,
        $stored
    ));
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
}

function devdredi_get_setting($name, $default = '')
{
    return devdredi_raw_get_setting(devdredi_scoped_name($name), $default);
}

function devdredi_update_setting($name, $value)
{
    return devdredi_raw_update_setting(devdredi_scoped_name($name), $value);
}

/** The rules index: list of array(id, nickname, priority). Always at least one rule. */
function devdredi_get_rules()
{
    $rules = devdredi_raw_get_setting('rm_rules', array());
    if (!is_array($rules) || empty($rules)) {
        $rules = array(array('id' => 'r1', 'nickname' => 'Rule 1', 'priority' => 1));
    }
    return $rules;
}

function devdredi_save_rules($rules)
{
    devdredi_raw_update_setting('rm_rules', array_values($rules));
}

/** Generate a rule id not already in $existing_ids. */
function devdredi_new_rule_id($existing_ids)
{
    do {
        $id = 'r' . substr(md5(uniqid('', true)), 0, 8);
    } while (in_array($id, $existing_ids, true));
    return $id;
}

/** Copy every rule__<from>__* setting row to rule__<to>__*. */
function devdredi_copy_rule_settings($from, $to)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';
    $prefix = 'rule__' . $from . '__';
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; $t from $wpdb->prefix; LIKE value prepared.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT setting_name, setting_value FROM $t WHERE setting_name LIKE %s",
        $wpdb->esc_like($prefix) . '%'
    ), ARRAY_A);
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
    if (!is_array($rows)) {
        return;
    }
    foreach ($rows as $row) {
        $suffix = substr($row['setting_name'], strlen($prefix));
        devdredi_raw_update_setting('rule__' . $to . '__' . $suffix, $row['setting_value']);
    }
}

/** Delete all settings rows for a rule. */
function devdredi_delete_rule_settings($id)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; $t from $wpdb->prefix; LIKE value prepared.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $t WHERE setting_name LIKE %s",
        $wpdb->esc_like('rule__' . $id . '__') . '%'
    ));
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
}

/**
 * One-time migration: copy the existing single config into rule "r1" and seed the rules index.
 * Generic — copies every non-global, not-yet-scoped row, so no hardcoded setting list is needed.
 */
function devdredi_maybe_migrate_rules()
{
    if (get_option('devdredi_rules_migrated')) {
        return;
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'devdredi_settings';
    $globals = devdredi_global_setting_keys();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration read of the plugin's own table; $table_name from $wpdb->prefix.
    $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM $table_name", ARRAY_A);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $n = $row['setting_name'];
            if (in_array($n, $globals, true) || strpos($n, 'rule__') === 0) {
                continue;
            }
            // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- one-time migration into the plugin's own table; $table_name from $wpdb->prefix; values prepared.
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table_name (setting_name, setting_value) VALUES (%s, %s)",
                'rule__r1__' . $n,
                $row['setting_value']
            ));
            // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
        }
    }

    if (devdredi_raw_get_setting('rm_rules', '') === '') {
        devdredi_raw_update_setting('rm_rules', array(array('id' => 'r1', 'nickname' => 'Rule 1', 'priority' => 1)));
    }
    if (devdredi_raw_get_setting('active_rule', '') === '') {
        devdredi_raw_update_setting('active_rule', 'r1');
    }
    update_option('devdredi_rules_migrated', 1);
}
add_action('plugins_loaded', 'devdredi_maybe_migrate_rules', 1);

/**
 * One-time migration: drop the obsolete per-rule "enabled" flag from the rules index. Run state is
 * now controlled solely by each rule's plugin_state (the row play/stop + Save & Run button).
 */
function devdredi_maybe_drop_enabled_field()
{
    if (get_option('devdredi_enabled_field_dropped')) {
        return;
    }
    $rules = devdredi_raw_get_setting('rm_rules', '');
    if (is_array($rules)) {
        foreach ($rules as &$r) {
            unset($r['enabled']);
        }
        unset($r);
        devdredi_raw_update_setting('rm_rules', array_values($rules));
    }
    update_option('devdredi_enabled_field_dropped', 1);
}
add_action('plugins_loaded', 'devdredi_maybe_drop_enabled_field', 2);

function devdredi_purge_plugin_cache()
{
    global $wpdb;
    $option_table = $wpdb->options;
    $patterns = array(
        '_transient_devdredi_nth_%',
        '_transient_timeout_devdredi_nth_%',
        '_transient_devdredi_redirect_%',
        '_transient_timeout_devdredi_redirect_%',
        '_transient_devdredi_redirect_count_%',
        '_transient_timeout_devdredi_redirect_count_%',
        '_transient_devdredi_geo_error',
        '_transient_timeout_devdredi_geo_error'
    );
    foreach ($patterns as $like) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$option_table} WHERE option_name LIKE %s", $like)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- transient cleanup on the options table; value prepared.
    }
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party LiteSpeed Cache hook.
}

/**
 * Purge third-party PAGE caches so a just-saved rule takes effect immediately instead of being
 * masked by a frozen cached copy (page caches keep serving stored HTML until their TTL runs
 * out — see the cache-immunity notes in engine.php; no-store only protects pages the engine
 * has already rendered, not copies cached before the rule existed).
 *
 * $urls = array of absolute URLs to purge, or null to purge the whole page cache. Supported:
 * WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround
 * Optimizer, WP-Optimize, Cache Enabler, Hummingbird, Breeze. Absent plugins are no-ops
 * (function/class guards; do_action on an unregistered hook does nothing).
 */
function devdredi_purge_page_caches($urls = null)
{
    if (is_array($urls) && !count($urls)) {
        return; // empty list = nothing to purge; only null means "purge everything"
    }
    $all = ($urls === null);

    // WP Rocket
    if ($all) {
        if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
    } elseif (function_exists('rocket_clean_files')) {
        rocket_clean_files($urls);
    }

    // LiteSpeed Cache
    if ($all) {
        do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party LiteSpeed Cache hook.
    } else {
        foreach ($urls as $u) { do_action('litespeed_purge_url', $u); } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party LiteSpeed Cache hook.
    }

    // W3 Total Cache
    if ($all) {
        if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); }
    } elseif (function_exists('w3tc_flush_url')) {
        foreach ($urls as $u) { w3tc_flush_url($u); }
    }

    // WP Super Cache
    if (!$all && function_exists('wpsc_delete_url_cache')) {
        foreach ($urls as $u) { wpsc_delete_url_cache($u); }
    } elseif (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // WP Fastest Cache (no public per-URL purge by URL: clear all)
    if (function_exists('wpfc_clear_all_cache')) {
        wpfc_clear_all_cache();
    } elseif (isset($GLOBALS['wp_fastest_cache']) && is_object($GLOBALS['wp_fastest_cache'])
        && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
        $GLOBALS['wp_fastest_cache']->deleteCache(true);
    }

    // SiteGround Optimizer
    if (function_exists('sg_cachepress_purge_cache')) {
        if ($all) {
            sg_cachepress_purge_cache();
        } else {
            foreach ($urls as $u) { sg_cachepress_purge_cache($u); }
        }
    }

    // WP-Optimize
    if (class_exists('WPO_Page_Cache')) {
        if (!$all && method_exists('WPO_Page_Cache', 'delete_cache_by_url')) {
            foreach ($urls as $u) { WPO_Page_Cache::delete_cache_by_url($u, true); }
        } elseif (function_exists('WP_Optimize') && method_exists(WP_Optimize(), 'get_page_cache')) {
            $wpo_pc = WP_Optimize()->get_page_cache();
            if ($wpo_pc && method_exists($wpo_pc, 'purge')) { $wpo_pc->purge(); }
        }
    }

    // Cache Enabler
    if (class_exists('Cache_Enabler')) {
        if (!$all && method_exists('Cache_Enabler', 'clear_page_cache_by_url')) {
            foreach ($urls as $u) { Cache_Enabler::clear_page_cache_by_url($u); }
        } elseif (method_exists('Cache_Enabler', 'clear_complete_cache')) {
            Cache_Enabler::clear_complete_cache();
        }
    }

    // Hummingbird (page cache; no public per-URL API: clear all)
    do_action('wphb_clear_page_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party Hummingbird cache hook.

    // Breeze (Cloudways; clear all)
    do_action('breeze_clear_all_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party Breeze cache hook.
}

/**
 * Absolute URLs the CURRENT rule targets, for cache purging. List modes (Custom URLs /
 * Selected pages) return just those URLs; every other mode (entire website, 404, referrer)
 * returns null = the whole cache. An empty list returns array() = nothing to purge.
 *
 * Pattern items also return null: devdredi_check_url_match() treats a trailing-slash item
 * as a prefix that matches every sub-page and a bare-domain item as matching the whole host,
 * so neither maps to a finite purgeable URL list. Exact items are purged in both
 * trailing-slash variants because page caches key on the exact URL.
 */
function devdredi_rule_target_urls()
{
    $what = devdredi_get_setting('what_to_redirect', 'entire_website');
    if ($what !== 'selected_existing' && $what !== 'custom_urls') {
        return null;
    }
    $list_key = ($what === 'custom_urls') ? 'custom_links_list' : 'selected_links_list';
    $items = array_filter(array_map('trim', explode("\n", devdredi_get_setting($list_key, ''))));
    $urls = array();
    foreach ($items as $item) {
        if (substr($item, -1) === '/') {
            return null;
        }
        if (preg_match('#^https?://#i', $item)) {
            $url = $item;
        } else {
            $first = explode('/', $item, 2)[0];
            $is_host = (strpos($first, '.') !== false && !preg_match('/^[0-9]+$/', $first));
            if ($is_host && strpos($item, '/') === false) {
                return null;
            }
            $url = $is_host
                ? (wp_parse_url(home_url(), PHP_URL_SCHEME) ?: 'https') . '://' . $item
                : home_url('/' . ltrim($item, '/'));
        }
        // The matcher ignores query strings and fragments (norm_path strips them) — so must the purge URL.
        $url = explode('#', explode('?', $url, 2)[0], 2)[0];
        $urls[] = rtrim($url, '/');
        $urls[] = rtrim($url, '/') . '/';
    }
    return array_values(array_unique($urls));
}

function devdredi_get_bool_setting($name, $default=0){
    $v = devdredi_get_setting($name, $default);
    return $v ? 1 : 0;
}
function devdredi_set_session_flag($flag){
    if (!session_id()) { @session_start(); }
    $_SESSION[$flag] = 1;
}
function devdredi_get_session_flag($flag){
    if (!session_id()) { @session_start(); }
    return !empty($_SESSION[$flag]);
}
function devdredi_clear_session_flag($flag){
    if (!session_id()) { @session_start(); }
    unset($_SESSION[$flag]);
}
