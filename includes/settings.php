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
        if ($wpdb->last_error !== '') {
            $GLOBALS['devdredi_read_failed'] = true; // a failed read is not a missing row; the save screens refuse to write on it
        }
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
    $result = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table_name (setting_name, setting_value) VALUES (%s, %s)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $name,
        $stored
    ));
    if ($result === false) {
        $GLOBALS['devdredi_write_failed'] = true; // the save screens report this instead of "Settings saved!"
    }
    return $result;
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
}

function devdredi_get_setting($name, $default = '')
{
    return devdredi_raw_get_setting(devdredi_scoped_name($name), $default);
}

function devdredi_update_setting($name, $value)
{
    // Resolve the scope FIRST (that read is the one that matters): a failed read of the active-rule pointer would scope
    // this write to the fallback rule, so it is refused instead.
    $explicit = !empty($GLOBALS['devdredi_rule']);
    if (!$explicit) {
        unset($GLOBALS['devdredi_read_failed']);
    }
    $scoped = devdredi_scoped_name($name);
    if (!$explicit && !empty($GLOBALS['devdredi_read_failed'])) {
        $GLOBALS['devdredi_write_failed'] = true;
        return false;
    }
    return devdredi_raw_update_setting($scoped, $value);
}

/** The rules index: list of array(id, nickname, priority). Always at least one rule. */
/** True when the last devdredi_get_rules() could not read the index (its fallback is then a guess, never a base for a write). */
function devdredi_rules_index_unreadable()
{
    return !empty($GLOBALS['devdredi_rules_read_failed']);
}

function devdredi_get_rules()
{
    global $wpdb;
    $GLOBALS['devdredi_rules_read_failed'] = false;
    $rules = devdredi_raw_get_setting('rm_rules', array());
    if ($wpdb->last_error !== '') {
        $GLOBALS['devdredi_rules_read_failed'] = true;
    }
    if (!is_array($rules) || empty($rules)) {
        $rules = array(array('id' => 'r1', 'nickname' => 'Rule 1', 'priority' => 1));
    }
    return $rules;
}

function devdredi_save_rules($rules)
{
    return devdredi_raw_update_setting('rm_rules', array_values($rules)) !== false;
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
    if (!is_array($rows) || $wpdb->last_error !== '') {
        return false;
    }
    $ok = true;
    foreach ($rows as $row) {
        $suffix = substr($row['setting_name'], strlen($prefix));
        $ok = devdredi_raw_update_setting('rule__' . $to . '__' . $suffix, $row['setting_value']) !== false && $ok;
    }
    if (!$ok) {
        devdredi_delete_rule_settings($to); // no half-copied rule left behind
    }
    return $ok;
}

/** Delete all settings rows for a rule. */
function devdredi_delete_rule_settings($id)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; $t from $wpdb->prefix; LIKE value prepared.
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM $t WHERE setting_name LIKE %s",
        $wpdb->esc_like('rule__' . $id . '__') . '%'
    ));
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
    return $deleted !== false;
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
    if ($wpdb->last_error !== '') {
        return; // nothing read = nothing migrated; retried next load, never marked done
    }
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $n = $row['setting_name'];
            if (in_array($n, $globals, true) || strpos($n, 'rule__') === 0) {
                continue;
            }
            // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- one-time migration into the plugin's own table; $table_name from $wpdb->prefix; values prepared.
            $migrated_ok = ($wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table_name (setting_name, setting_value) VALUES (%s, %s)",
                'rule__r1__' . $n,
                $row['setting_value']
            )) !== false) && (!isset($migrated_ok) || $migrated_ok);
            // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
        }
    }

    $migrated_ok = !isset($migrated_ok) || $migrated_ok;
    // The index and the pointer are seeded only when their reads PROVED them missing: a failed read never seeds over them.
    unset($GLOBALS['devdredi_read_failed']);
    $idx_now = devdredi_raw_get_setting('rm_rules', '');
    $act_now = devdredi_raw_get_setting('active_rule', '');
    if (!empty($GLOBALS['devdredi_read_failed'])) {
        return; // unknown state: retried next load
    }
    if ($idx_now === '') {
        $migrated_ok = devdredi_raw_update_setting('rm_rules', array(array('id' => 'r1', 'nickname' => 'Rule 1', 'priority' => 1))) !== false && $migrated_ok;
    }
    if ($act_now === '') {
        $migrated_ok = devdredi_raw_update_setting('active_rule', 'r1') !== false && $migrated_ok;
    }
    if ($migrated_ok && $wpdb->last_error === '') {
        update_option('devdredi_rules_migrated', 1); // a partial migration is retried on the next load, never marked done
    }
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
    unset($GLOBALS['devdredi_read_failed']);
    $rules = devdredi_raw_get_setting('rm_rules', '');
    if (!empty($GLOBALS['devdredi_read_failed'])) {
        return; // unknown: retried next load
    }
    if (is_array($rules)) {
        foreach ($rules as &$r) {
            unset($r['enabled']);
        }
        unset($r);
        if (devdredi_raw_update_setting('rm_rules', array_values($rules)) === false) {
            return; // retried next load
        }
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
    // A picked category or archive covers every post in it (1.5.0): no finite URL list, purge everything.
    if ($what === 'selected_existing') {
        unset($GLOBALS['devdredi_read_failed']);
        $groups = json_decode((string) devdredi_get_setting('selected_links_groups', ''), true);
        if (!empty($groups) || !empty($GLOBALS['devdredi_read_failed'])) {
            return null; // groups present, or unknown: purge everything rather than a guessed list
        }
    }
    unset($GLOBALS['devdredi_read_failed']);
    $items = array_filter(array_map('trim', explode("\n", devdredi_get_setting($list_key, ''))));
    if (!empty($GLOBALS['devdredi_read_failed'])) {
        return null; // the list could not be read: purge everything rather than nothing
    }
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

/**
 * Per-rule setting suffixes that are statistics or run state: never exported, never imported. Lives here (not in
 * admin.php) because export runs from REST, WP-CLI and the abilities too, where admin.php is not loaded.
 */
function devdredi_io_excluded_suffixes()
{
    return array(
        'visitor_count', 'page_view_count', 'ip_list', 'ua_list', 'ip_link_index', 'ip_redirected_once',
        'last_redirects', 'user_redirects_count', 'user_bypass_count', 'unique_visitor_count',
        'unique_users_count', 'rc_by_source', 'rc_by_dest', 'rc_by_referrer', 'rc_by_country', 'rc_by_found',
        'device_count_desktop', 'device_count_mobile', 'device_count_tablet',
        'reset_count', 'plugin_state', 'start_time', 'stats_daily', 'uu_list',
    );
}
