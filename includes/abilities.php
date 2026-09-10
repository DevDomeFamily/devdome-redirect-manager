<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): the whole plugin exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Coverage = every feature of the Redirect Manager screen (audited against admin.php's save
 * handler and engine.php, 2026-09-10):
 *   rules (add, duplicate, rename, reorder, delete, run/stop), what to redirect (entire website,
 *   selected pages, custom paths, every 404), redirect method (301/302/307/308/js/meta),
 *   destination source (provided links, links found on the page, same path on another domain),
 *   rotation (sequential, random, weighted, skip domain, repeat), open mode and delays (js only),
 *   frequency (every visit, once per IP or IP+browser, every Nth visitor, revisit delay),
 *   schedule (always / custom: timezone, date range, weekdays, up to 3 time windows, run for a
 *   duration), geo targeting (allow/block country lists, trust proxy), device targeting, fallback
 *   for non-redirected visitors, allowed custom domains, cache purge, statistics and reset,
 *   export of the configuration, content search for the selected-pages picker, geo service and
 *   proxy status, and 404 candidates from DevDome Link Monitor.
 *
 * Every read goes through the same settings store the admin page and the engine use
 * (rule__<id>__<key> rows); every write uses the same keys, ranges and sanitizers the Save, Run,
 * Stop, Duplicate, Delete and Reset buttons use. Destructive abilities (delete a rule, reset its
 * statistics) are annotated destructive and say so in their descriptions. On WordPress older than
 * 6.9 the API does not exist and this file registers nothing.
 */

defined('ABSPATH') || exit;

function devdredi_ability_ids()
{
    return array(
        // reads
        'devdome-redirect-manager/list-redirects',
        'devdome-redirect-manager/get-redirect-details',
        'devdome-redirect-manager/get-redirect-stats',
        'devdome-redirect-manager/find-404-redirect-candidates',
        'devdome-redirect-manager/search-site-content',
        'devdome-redirect-manager/get-geo-status',
        'devdome-redirect-manager/export-redirects',
        // rule writes
        'devdome-redirect-manager/create-redirect',
        'devdome-redirect-manager/update-redirect',
        'devdome-redirect-manager/set-redirect-state',
        'devdome-redirect-manager/duplicate-redirect',
        'devdome-redirect-manager/reorder-redirects',
        'devdome-redirect-manager/delete-redirect',
        'devdome-redirect-manager/reset-redirect-stats',
        'devdome-redirect-manager/purge-redirect-cache',
    );
}

add_action('wp_abilities_api_categories_init', 'devdredi_register_ability_category');
function devdredi_register_ability_category()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    wp_register_ability_category('devdome-redirect-manager', array(
        'label'       => __('DevDome Redirect Manager', 'devdome-redirect-manager'),
        'description' => __('WordPress redirect rules with geo targeting, device targeting, link rotation, scheduling, frequency control and statistics: list, create, update, duplicate, reorder, start, stop, delete rules, read and reset statistics, export the configuration.', 'devdome-redirect-manager'),
    ));
}

function devdredi_ability_can()
{
    return current_user_can('manage_options');
}

/**
 * $kind: 'read' (no change), 'add' (additive only, not idempotent: create, duplicate),
 * 'modify' (changes existing state, safe to repeat: update, start/stop, reorder, purge),
 * 'destroy' (irreversible: delete, reset). destructive follows the WordPress meaning:
 * false = additive only, null = modifies, true = destructive.
 */
function devdredi_ability_meta($kind)
{
    $map = array(
        'read'    => array('readonly' => true,  'destructive' => false, 'idempotent' => true),
        'add'     => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        'modify'  => array('readonly' => false, 'destructive' => null,  'idempotent' => true),
        'destroy' => array('readonly' => false, 'destructive' => true,  'idempotent' => true),
    );
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => isset($map[$kind]) ? $map[$kind] : $map['modify'],
        'mcp'          => array('type' => 'tool'),
    );
}

/* ------------------------------ rule spec schema ------------------------------ */

/**
 * The JSON schema of a rule as an agent writes it (create-redirect: every key optional except
 * from/to for the default custom-paths rule; update-redirect: any subset). Mirrors the admin form.
 */
function devdredi_ability_rule_spec_properties()
{
    $delay = array('type' => 'array', 'items' => array('type' => 'number', 'minimum' => 0), 'minItems' => 2, 'maxItems' => 2, 'description' => '[min, max] seconds, a random value in the range is used.');
    return array(
        'name'     => array('type' => 'string', 'description' => 'Rule nickname shown in wp-admin.'),
        'what'     => array('type' => 'string', 'enum' => array('entire_website', 'selected_pages', 'custom_paths', 'all_404'), 'description' => 'What to redirect: every page of the site, selected existing pages/posts/categories (give their URLs in from), custom paths or URLs (from), or every 404 page.'),
        'from'     => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Source paths or URLs on this site (custom_paths) or existing page URLs (selected_pages). Ignored for entire_website and all_404.'),
        'method'   => array('type' => 'string', 'enum' => array('301', '302', '307', '308', 'js', 'meta'), 'description' => 'Redirect method. Server methods (301/302/307/308) and meta always open in the same tab.'),
        'destination_mode' => array('type' => 'string', 'enum' => array('provided', 'found', 'transit'), 'description' => 'provided = send to the URLs in to; found = send to a link or button found on the page whose URL or text contains one of found_link_contains; transit = send to the same path on transit_domain.'),
        'to'       => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Destination URL(s), http or https (destination_mode provided).'),
        'found_link_contains' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Patterns a link or button on the page must contain (destination_mode found).'),
        'transit_domain' => array('type' => 'string', 'description' => 'Domain that receives the same path, for example shop.example.com (destination_mode transit).'),
        'rotation' => array('type' => 'string', 'enum' => array('sequential', 'random', 'weighted', 'skip_domain'), 'description' => 'How several destinations are rotated per visitor: first to last, random, weighted distribution, or skip domain (same path on the first destination host).'),
        'rotation_repeat' => array('type' => 'boolean', 'description' => 'Start again from the first destination after the last one.'),
        'weighted_spread' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Weighted rotation: 0 favours the first destination, 1 the last, 0.5 even.'),
        'weighted_seed' => array('type' => 'integer', 'description' => 'Weighted rotation seed.'),
        'open_mode' => array('type' => 'string', 'enum' => array('same_tab', 'new_tab'), 'description' => 'JavaScript method only.'),
        'same_tab_delay' => $delay,
        'new_tab_delay'  => $delay,
        'same_tab_require_click' => array('type' => 'boolean', 'description' => 'Same tab: wait for the visitor to click before redirecting.'),
        'same_tab_after_click_delay' => array('type' => 'array', 'items' => array('type' => 'number', 'minimum' => 0, 'maximum' => 4), 'minItems' => 2, 'maxItems' => 2, 'description' => '[min, max] seconds after the click, 0 to 4; [0, 0] disables.'),
        'new_tab_after_click_delay'  => array('type' => 'array', 'items' => array('type' => 'number', 'minimum' => 0, 'maximum' => 4), 'minItems' => 2, 'maxItems' => 2, 'description' => 'New tab: [min, max] seconds after the click, 0 to 4; [0, 0] disables.'),
        'once_per' => array('type' => 'string', 'enum' => array('never', 'ip', 'ip_ua'), 'description' => 'never = redirect on every visit; ip = once per IP address; ip_ua = once per IP + browser/device.'),
        'every_nth_visitor' => array('type' => 'integer', 'minimum' => 1, 'description' => '1 = every eligible visitor, N = only every Nth unique visitor (needs once_per ip or ip_ua).'),
        'revisit_delay' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Redirect the same visitor again after this delay; 0 = once only (needs once_per ip or ip_ua).'),
        'revisit_delay_unit' => array('type' => 'string', 'enum' => array('minutes', 'hours', 'days')),
        'schedule_mode' => array('type' => 'string', 'enum' => array('always', 'custom'), 'description' => 'always = active whenever running; custom = only inside the schedule below.'),
        'schedule_timezone' => array('type' => 'string', 'description' => 'IANA timezone, for example Europe/Berlin; empty = the site timezone.'),
        'schedule_start_date' => array('type' => 'string', 'description' => 'YYYY-MM-DD, empty = no start date.'),
        'schedule_end_date'   => array('type' => 'string', 'description' => 'YYYY-MM-DD, empty = no end date.'),
        'schedule_weekdays'   => array('type' => 'array', 'items' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 7), 'description' => '1 = Monday ... 7 = Sunday; empty = every day.'),
        'schedule_times'      => array('type' => 'array', 'maxItems' => 3, 'items' => array('type' => 'object', 'properties' => array('start' => array('type' => 'string'), 'end' => array('type' => 'string')), 'required' => array('start', 'end'), 'additionalProperties' => false), 'description' => 'Up to 3 daily windows, HH:MM to HH:MM; empty = all day.'),
        'run_for_minutes'     => array('type' => 'integer', 'minimum' => 0, 'description' => 'Stop automatically this many minutes after the rule starts; 0 = no limit.'),
        'geo_enabled' => array('type' => 'boolean'),
        'geo_mode'    => array('type' => 'string', 'enum' => array('allow', 'block'), 'description' => 'allow = redirect only the listed countries; block = redirect everyone except the listed countries.'),
        'geo_countries' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'ISO 3166-1 alpha-2 codes, for example US, DE, GB.'),
        'trust_proxy' => array('type' => 'boolean', 'description' => 'Read the visitor IP from the proxy or CDN forwarding header (Cloudflare and similar).'),
        'devices' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => array('desktop', 'mobile', 'tablet')), 'description' => 'Device types that are redirected; the others pass through to the fallback.'),
        'fallback_mode' => array('type' => 'string', 'enum' => array('leave', 'send'), 'description' => 'Visitors not redirected (device, geo, frequency, schedule): leave = show the page; send = send them to fallback_url.'),
        'fallback_url'  => array('type' => 'string'),
        'custom_domains' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Extra domains allowed as redirect targets for this rule.'),
        'purge_cache_on_save' => array('type' => 'boolean', 'description' => 'Purge page caches (WP Rocket, LiteSpeed, W3TC, Super Cache and similar) whenever the rule is saved or started.'),
    );
}

/** Read one setting of a given rule without touching the active-rule pointer. */
function devdredi_ability_rs($rid, $key, $default = '')
{
    return devdredi_raw_get_setting('rule__' . $rid . '__' . $key, $default);
}
function devdredi_ability_ws($rid, $key, $value)
{
    devdredi_raw_update_setting('rule__' . $rid . '__' . $key, $value);
}

/** Every stored row of one rule (setting_name => raw stored value), taken before a write so it can be rolled back. */
function devdredi_ability_snapshot_rule($rid)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; $t from $wpdb->prefix; LIKE value prepared.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT setting_name, setting_value FROM $t WHERE setting_name LIKE %s",
        $wpdb->esc_like('rule__' . $rid . '__') . '%'
    ), ARRAY_A);
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
    $out = array();
    foreach ((array) $rows as $row) {
        $out[$row['setting_name']] = $row['setting_value'];
    }
    return $out;
}

/** Put a rule back exactly as devdredi_ability_snapshot_rule() saw it (rows and the rules index). */
function devdredi_ability_restore_rule($rid, $snapshot, $rules)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';
    devdredi_delete_rule_settings($rid);
    foreach ($snapshot as $name => $value) {
        // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; raw values written back unchanged.
        $wpdb->insert($t, array('setting_name' => $name, 'setting_value' => $value), array('%s', '%s'));
        // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
    }
    devdredi_save_rules($rules);
}

function devdredi_ability_lines($text)
{
    return array_values(array_filter(array_map('trim', explode("\n", (string) $text))));
}

function devdredi_ability_rules_sorted()
{
    $rules = devdredi_get_rules();
    usort($rules, function ($a, $b) {
        return ((int) (isset($a['priority']) ? $a['priority'] : 0)) - ((int) (isset($b['priority']) ? $b['priority'] : 0));
    });
    return $rules;
}

function devdredi_ability_find_rule($rid)
{
    foreach (devdredi_get_rules() as $r) {
        if ((string) $r['id'] === (string) $rid) {
            return $r;
        }
    }
    return null;
}

/** Run a callback with the given rule as the active rule (for helpers that use the scoped getter). */
function devdredi_ability_as_rule($rid, $fn)
{
    $prev = isset($GLOBALS['devdredi_rule']) ? $GLOBALS['devdredi_rule'] : null;
    $GLOBALS['devdredi_rule'] = $rid;
    try {
        return $fn();
    } finally {
        $GLOBALS['devdredi_rule'] = $prev;
    }
}

function devdredi_ability_purge_for_rule($rid)
{
    if (!function_exists('devdredi_purge_page_caches')) {
        return;
    }
    devdredi_ability_as_rule($rid, function () {
        $urls = function_exists('devdredi_rule_target_urls') ? devdredi_rule_target_urls() : null;
        if ($urls === null || !empty($urls)) {
            devdredi_purge_page_caches($urls);
        }
    });
}

/** The public shape of one rule: the full configuration, no visitor lists. */
function devdredi_ability_format_rule($r, $with_maps = false)
{
    $rid  = (string) $r['id'];
    $rs   = function ($k, $d = '') use ($rid) { return devdredi_ability_rs($rid, $k, $d); };
    $what = (string) $rs('what_to_redirect', 'entire_website');
    $what_map = array('entire_website' => 'entire_website', 'selected_existing' => 'selected_pages', 'custom_urls' => 'custom_paths', 'all_404' => 'all_404');
    $sources = array();
    if ($what === 'custom_urls') {
        $sources = devdredi_ability_lines($rs('custom_links_list', ''));
    } elseif ($what === 'selected_existing') {
        $sources = devdredi_ability_lines($rs('selected_links_list', ''));
    }
    $rot = (string) $rs('links_mode', 'sequential');
    $rot_map = array('sequential' => 'sequential', 'random' => 'random', 'descending' => 'weighted', 'skip_domain' => 'skip_domain');
    $geo_mode = (string) $rs('geo_filter_mode', 'whitelist');
    $geo_csv  = (string) ($geo_mode === 'blacklist' ? $rs('geo_filter_blacklist', '') : $rs('geo_filter_whitelist', $rs('geo_filter_country_codes', '')));
    $weekdays = maybe_unserialize($rs('run_weekdays', array()));
    $times    = maybe_unserialize($rs('specific_times', array()));
    $windows  = array();
    foreach (is_array($times) ? $times : array() as $t) {
        if (is_array($t) && !empty($t['enable'])) {
            $windows[] = array('start' => (string) $t['start'], 'end' => (string) $t['end']);
        }
    }
    $devices = array();
    foreach (array('desktop', 'mobile', 'tablet') as $d) {
        if ($rs('device_' . $d, 1)) {
            $devices[] = $d;
        }
    }
    $ip_list = $rs('ip_list', array());
    $rev = (int) $rs('revisit_delay', 0);
    $unit = (string) $rs('revisit_delay_unit', 'minutes');
    $div = $unit === 'days' ? 1440 : ($unit === 'hours' ? 60 : 1);
    $out = array(
        'id'       => $rid,
        'name'     => isset($r['nickname']) ? (string) $r['nickname'] : '',
        'priority' => (int) (isset($r['priority']) ? $r['priority'] : 0),
        'state'    => (string) $rs('plugin_state', 'stopped'),
        'started_at' => (int) $rs('start_time', 0) ? gmdate('c', (int) $rs('start_time', 0)) : '',
        'what'     => isset($what_map[$what]) ? $what_map[$what] : $what,
        'from'     => $sources,
        'method'   => (string) $rs('redirect_type', 'js'),
        'destination_mode' => (string) $rs('redirect_source', 'provided'),
        'to'       => devdredi_ability_lines($rs('links_list', '')),
        'found_link_contains' => devdredi_ability_lines($rs('page_links_contains', '')),
        'transit_domain' => (string) $rs('transit_domain', ''),
        'rotation' => isset($rot_map[$rot]) ? $rot_map[$rot] : $rot,
        'rotation_repeat' => (bool) $rs('links_repeat', 1),
        'weighted_spread' => (float) $rs('descending_spread', 0.5),
        'weighted_seed'   => (int) $rs('descending_seed', 0),
        'open_mode' => (string) $rs('open_mode', 'same_tab'),
        'same_tab_delay' => array((float) $rs('same_tab_delay_min', 0), (float) $rs('same_tab_delay_max', 0)),
        'new_tab_delay'  => array((float) $rs('new_tab_delay_min', 0), (float) $rs('new_tab_delay_max', 0)),
        'same_tab_require_click' => (bool) $rs('same_tab_require_click', 0),
        'same_tab_after_click_delay' => $rs('same_tab_after_click_enabled', 0) ? array((float) $rs('same_tab_after_click_min', 0), (float) $rs('same_tab_after_click_max', 0)) : array(0, 0),
        'new_tab_after_click_delay'  => $rs('after_click_enabled', 0) ? array((float) $rs('after_click_min', 0), (float) $rs('after_click_max', 0)) : array(0, 0),
        'once_per' => (string) $rs('run_once', 'never'),
        'every_nth_visitor' => max(1, (int) $rs('open_on_every', 1)),
        'revisit_delay' => (int) round($rev / $div),
        'revisit_delay_unit' => $unit,
        'schedule_mode' => (string) $rs('run_mode', 'unlimited') === 'set_time' ? 'custom' : 'always',
        'schedule_timezone' => (string) $rs('schedule_timezone', ''),
        'schedule_start_date' => (string) $rs('schedule_start_date', ''),
        'schedule_end_date'   => (string) $rs('schedule_end_date', ''),
        'schedule_weekdays'   => array_values(array_map('intval', is_array($weekdays) ? $weekdays : array())),
        'schedule_times'      => $windows,
        'run_for_minutes'     => (int) $rs('runtime_minutes', 0),
        'geo_enabled'   => (bool) $rs('geo_filter_enabled', 0),
        'geo_mode'      => $geo_mode === 'blacklist' ? 'block' : 'allow',
        'geo_countries' => array_values(array_filter(array_map('trim', explode(',', strtoupper($geo_csv))))),
        'trust_proxy'   => (bool) $rs('trust_proxy', 0),
        'devices'       => $devices,
        'fallback_mode' => (string) $rs('fallback_mode', 'leave'),
        'fallback_url'  => (string) $rs('fallback_url', ''),
        'custom_domains' => devdredi_ability_as_rule($rid, function () { return devdredi_get_custom_domains(); }),
        'purge_cache_on_save' => (bool) $rs('purge_cache_on_save', 1),
        'stats' => array(
            'redirects'       => (int) $rs('user_redirects_count', 0),
            'bypassed'        => (int) $rs('user_bypass_count', 0),
            'page_views'      => (int) $rs('page_view_count', 0),
            'unique_visitors' => is_array($ip_list) ? count($ip_list) : 0,
            'unique_users'    => (int) $rs('unique_users_count', 0),
            'desktop'         => (int) $rs('device_count_desktop', 0),
            'mobile'          => (int) $rs('device_count_mobile', 0),
            'tablet'          => (int) $rs('device_count_tablet', 0),
            'reset_count'     => (int) $rs('reset_count', 0),
        ),
    );
    if ($with_maps) {
        $by = function ($key) use ($rs) {
            $m = $rs($key, array());
            if (!is_array($m)) {
                return array();
            }
            arsort($m);
            $o = array();
            foreach (array_slice($m, 0, 50, true) as $k => $n) {
                $o[] = array('key' => (string) $k, 'count' => (int) $n);
            }
            return $o;
        };
        $daily = $rs('stats_daily', array());
        $days  = array();
        if (is_array($daily)) {
            krsort($daily);
            foreach (array_slice($daily, 0, 30, true) as $day => $d) {
                $cc = array();
                if (isset($d['cc']) && is_array($d['cc'])) {
                    arsort($d['cc']);
                    foreach (array_slice($d['cc'], 0, 10, true) as $k => $n) {
                        $cc[] = array('key' => (string) $k, 'count' => (int) $n);
                    }
                }
                $days[] = array('date' => (string) $day, 'redirects' => (int) ($d['red'] ?? 0), 'bypassed' => (int) ($d['byp'] ?? 0), 'desktop' => (int) ($d['dd'] ?? 0), 'mobile' => (int) ($d['dm'] ?? 0), 'tablet' => (int) ($d['dt'] ?? 0), 'countries' => $cc);
            }
        }
        $out['stats']['by_source']      = $by('rc_by_source');
        $out['stats']['by_destination'] = $by('rc_by_dest');
        $out['stats']['by_referrer']    = $by('rc_by_referrer');
        $out['stats']['by_country']     = $by('rc_by_country');
        $out['stats']['by_found_link']  = $by('rc_by_found');
        $out['stats']['daily']          = $days;
    }
    return $out;
}

/**
 * Validate and write a rule spec (the keys of devdredi_ability_rule_spec_properties()) onto rule
 * $rid. $all = true writes every key with the form's defaults (create); false writes only the keys
 * present (update). Returns true or WP_Error. Same sanitizers and ranges as the admin save handler.
 */
function devdredi_ability_apply_spec($rid, $spec, $all)
{
    $spec = is_array($spec) ? $spec : array();
    $has = function ($k) use ($spec, $all) { return $all || array_key_exists($k, $spec); };
    $get = function ($k, $d) use ($spec) { return array_key_exists($k, $spec) ? $spec[$k] : $d; };
    $ws  = function ($k, $v) use ($rid) { devdredi_ability_ws($rid, $k, $v); };
    $cur = function ($k, $d = '') use ($rid) { return devdredi_ability_rs($rid, $k, $d); };

    // what + from
    $what_map = array('entire_website' => 'entire_website', 'selected_pages' => 'selected_existing', 'custom_paths' => 'custom_urls', 'all_404' => 'all_404');
    $what_in = (string) $get('what', array_key_exists('from', $spec) ? 'custom_paths' : 'entire_website');
    if ($has('what') || ($all && !isset($what_map[$what_in]))) {
        if (!isset($what_map[$what_in])) {
            return new WP_Error('devdredi_bad_input', __('what must be entire_website, selected_pages, custom_paths or all_404.', 'devdome-redirect-manager'));
        }
        $ws('what_to_redirect', $what_map[$what_in]);
    }
    $what_now = (string) $cur('what_to_redirect', 'entire_website');
    if ($has('from')) {
        $from = array();
        foreach ((array) $get('from', array()) as $s) {
            $s = trim(sanitize_text_field((string) $s));
            if ($s !== '') {
                $from[] = $s;
            }
        }
        if ($what_now === 'custom_urls') {
            $norm = function_exists('devdredi_normalize_path') ? array_filter(array_map('devdredi_normalize_path', $from)) : $from;
            $ws('custom_links_list', implode("\n", array_unique($norm)));
        } elseif ($what_now === 'selected_existing') {
            $ws('selected_links_list', implode("\n", array_unique($from)));
            $meta = array();
            foreach (array_unique($from) as $u) {
                $meta[] = array('label' => $u, 'url' => esc_url_raw($u), 'type' => 'page');
            }
            $ws('selected_links_meta', wp_json_encode($meta));
        }
        if (in_array($what_now, array('custom_urls', 'selected_existing'), true) && !$from) {
            return new WP_Error('devdredi_bad_input', __('from needs at least one path or URL for custom_paths or selected_pages.', 'devdome-redirect-manager'));
        }
    } elseif ($all && in_array($what_now, array('custom_urls', 'selected_existing'), true)) {
        return new WP_Error('devdredi_bad_input', __('from is required for custom_paths and selected_pages.', 'devdome-redirect-manager'));
    }

    // method
    if ($has('method')) {
        $m = (string) $get('method', '301');
        if (!in_array($m, array('301', '302', '307', '308', 'js', 'meta'), true)) {
            return new WP_Error('devdredi_bad_input', __('method must be 301, 302, 307, 308, js or meta.', 'devdome-redirect-manager'));
        }
        $ws('redirect_type', $m);
    }
    $method_now = (string) $cur('redirect_type', 'js');

    // destination
    if ($has('destination_mode')) {
        $dm = (string) $get('destination_mode', 'provided');
        if (!in_array($dm, array('provided', 'found', 'transit'), true)) {
            return new WP_Error('devdredi_bad_input', __('destination_mode must be provided, found or transit.', 'devdome-redirect-manager'));
        }
        $ws('redirect_source', $dm);
    }
    $dm_now = (string) $cur('redirect_source', 'provided');
    if ($has('to')) {
        $to = array();
        foreach ((array) $get('to', array()) as $u) {
            $u = esc_url_raw(trim((string) $u), array('http', 'https'));
            if ($u !== '' && wp_parse_url($u, PHP_URL_HOST)) {
                $to[] = $u;
            }
        }
        if ($dm_now === 'provided' && !$to) {
            return new WP_Error('devdredi_bad_input', __('to needs at least one valid http(s) destination URL.', 'devdome-redirect-manager'));
        }
        $ws('links_list', implode("\n", $to));
    } elseif ($all && $dm_now === 'provided') {
        return new WP_Error('devdredi_bad_input', __('to is required for destination_mode provided.', 'devdome-redirect-manager'));
    }
    if ($has('found_link_contains')) {
        $ws('page_links_contains', implode("\n", array_filter(array_map(function ($p) { return trim(sanitize_text_field((string) $p)); }, (array) $get('found_link_contains', array())))));
    }
    if ($dm_now === 'found' && devdredi_ability_lines($cur('page_links_contains', '')) === array()) {
        return new WP_Error('devdredi_bad_input', __('found_link_contains needs at least one pattern for destination_mode found.', 'devdome-redirect-manager'));
    }
    if ($has('transit_domain')) {
        $td = strtolower(trim(sanitize_text_field((string) $get('transit_domain', ''))));
        $td = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $td);
        $td = preg_replace('#^www\.#i', '', $td);
        $td = preg_replace('~[/?\#].*$~', '', $td);
        if ($td !== '' && !preg_match('/^([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $td)) {
            return new WP_Error('devdredi_bad_input', __('transit_domain is not a valid domain.', 'devdome-redirect-manager'));
        }
        $ws('transit_domain', $td);
    }
    if ($dm_now === 'transit' && (string) $cur('transit_domain', '') === '') {
        return new WP_Error('devdredi_bad_input', __('transit_domain is required for destination_mode transit.', 'devdome-redirect-manager'));
    }

    // rotation
    if ($has('rotation')) {
        $rot_map = array('sequential' => 'sequential', 'random' => 'random', 'weighted' => 'descending', 'skip_domain' => 'skip_domain');
        $rot = (string) $get('rotation', 'sequential');
        if (!isset($rot_map[$rot])) {
            return new WP_Error('devdredi_bad_input', __('rotation must be sequential, random, weighted or skip_domain.', 'devdome-redirect-manager'));
        }
        $ws('links_mode', $rot_map[$rot]);
    }
    if ($has('rotation_repeat')) {
        $ws('links_repeat', !empty($get('rotation_repeat', true)) ? 1 : 0);
    }
    if ($has('weighted_spread')) {
        $ws('descending_spread', max(0.0, min(1.0, (float) $get('weighted_spread', 0.5))));
    }
    if ($has('weighted_seed')) {
        $ws('descending_seed', (int) $get('weighted_seed', 0));
    }

    // open mode and delays (js only for new_tab)
    if ($has('open_mode') || $has('method')) {
        $om = (string) $get('open_mode', $cur('open_mode', 'same_tab'));
        $om = ($om === 'new_tab' && $method_now === 'js') ? 'new_tab' : 'same_tab';
        $ws('open_mode', $om);
    }
    $range = function ($k, $cap) use ($get) {
        $v = (array) $get($k, array(0, 0));
        $min = max(0.0, (float) (isset($v[0]) ? $v[0] : 0));
        $max = max(0.0, (float) (isset($v[1]) ? $v[1] : 0));
        if ($cap !== null) {
            $min = min($cap, $min);
            $max = min($cap, $max);
        }
        if ($max < $min) {
            $max = $min;
        }
        return array($min, $max);
    };
    if ($has('same_tab_delay')) {
        list($a, $b) = $range('same_tab_delay', null);
        $ws('same_tab_delay_min', $a);
        $ws('same_tab_delay_max', $b);
    }
    if ($has('new_tab_delay')) {
        list($a, $b) = $range('new_tab_delay', null);
        $ws('new_tab_delay_min', $a);
        $ws('new_tab_delay_max', $b);
    }
    if ($has('same_tab_require_click')) {
        $ws('same_tab_require_click', !empty($get('same_tab_require_click', false)) ? 1 : 0);
    }
    if ($has('same_tab_after_click_delay')) {
        list($a, $b) = $range('same_tab_after_click_delay', 4.0);
        $ws('same_tab_after_click_enabled', ($a > 0 || $b > 0) ? 1 : 0);
        $ws('same_tab_after_click_min', $a);
        $ws('same_tab_after_click_max', $b);
    }
    if ($has('new_tab_after_click_delay')) {
        list($a, $b) = $range('new_tab_after_click_delay', 4.0);
        $ws('after_click_enabled', ($a > 0 || $b > 0) ? 1 : 0);
        $ws('after_click_min', $a);
        $ws('after_click_max', $b);
    }

    // frequency
    if ($has('once_per')) {
        $ro = (string) $get('once_per', 'never');
        if (!in_array($ro, array('never', 'ip', 'ip_ua'), true)) {
            return new WP_Error('devdredi_bad_input', __('once_per must be never, ip or ip_ua.', 'devdome-redirect-manager'));
        }
        $ws('run_once', $ro);
    }
    $ro_now = (string) $cur('run_once', 'never');
    if ($has('every_nth_visitor')) {
        $ws('open_on_every', $ro_now === 'never' ? 1 : max(1, (int) $get('every_nth_visitor', 1)));
    }
    if ($has('revisit_delay') || $has('revisit_delay_unit')) {
        $unit = (string) $get('revisit_delay_unit', $cur('revisit_delay_unit', 'minutes'));
        if (!in_array($unit, array('minutes', 'hours', 'days'), true)) {
            return new WP_Error('devdredi_bad_input', __('revisit_delay_unit must be minutes, hours or days.', 'devdome-redirect-manager'));
        }
        $mult = $unit === 'days' ? 1440 : ($unit === 'hours' ? 60 : 1);
        $cur_units = (int) round((int) $cur('revisit_delay', 0) / (($cur('revisit_delay_unit', 'minutes') === 'days') ? 1440 : (($cur('revisit_delay_unit', 'minutes') === 'hours') ? 60 : 1)));
        $raw = max(0, (int) $get('revisit_delay', $cur_units));
        $ws('revisit_delay', $ro_now === 'never' ? 0 : $raw * $mult);
        $ws('revisit_delay_unit', $unit);
    }
    if ($ro_now === 'never') {
        $ws('open_on_every', 1);
        $ws('revisit_delay', 0);
    }

    // schedule
    if ($has('schedule_mode')) {
        $sm = (string) $get('schedule_mode', 'always');
        if (!in_array($sm, array('always', 'custom'), true)) {
            return new WP_Error('devdredi_bad_input', __('schedule_mode must be always or custom.', 'devdome-redirect-manager'));
        }
        $ws('run_mode', $sm === 'custom' ? 'set_time' : 'unlimited');
    }
    if ($has('schedule_timezone')) {
        $tz = sanitize_text_field((string) $get('schedule_timezone', ''));
        if ($tz !== '' && !in_array($tz, timezone_identifiers_list(), true)) {
            return new WP_Error('devdredi_bad_input', __('schedule_timezone must be an IANA timezone such as Europe/Berlin.', 'devdome-redirect-manager'));
        }
        $ws('schedule_timezone', $tz);
    }
    foreach (array('schedule_start_date', 'schedule_end_date') as $dk) {
        if ($has($dk)) {
            $dv = sanitize_text_field((string) $get($dk, ''));
            if ($dv !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dv)) {
                return new WP_Error('devdredi_bad_input', sprintf(__('%s must be YYYY-MM-DD.', 'devdome-redirect-manager'), $dk));
            }
            $ws($dk, $dv);
        }
    }
    if ($has('schedule_weekdays')) {
        $wd = array();
        foreach ((array) $get('schedule_weekdays', array()) as $d) {
            $d = (int) $d;
            if ($d >= 1 && $d <= 7 && !in_array($d, $wd, true)) {
                $wd[] = $d;
            }
        }
        sort($wd);
        $ws('run_weekdays', maybe_serialize($wd));
    }
    if ($has('schedule_times')) {
        $slots = array();
        foreach (array_slice((array) $get('schedule_times', array()), 0, 3) as $t) {
            $t = (array) $t;
            $s = isset($t['start']) ? (string) $t['start'] : '';
            $e = isset($t['end']) ? (string) $t['end'] : '';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $e)) {
                return new WP_Error('devdredi_bad_input', __('schedule_times entries need start and end as HH:MM.', 'devdome-redirect-manager'));
            }
            $slots[] = array('enable' => 1, 'start' => $s, 'end' => $e);
        }
        while (count($slots) < 3) {
            $slots[] = array('enable' => 0, 'start' => '00:00', 'end' => '23:59');
        }
        $ws('specific_times', maybe_serialize($slots));
    }
    if ($has('run_for_minutes')) {
        $ws('runtime_minutes', max(0, (int) $get('run_for_minutes', 0)));
        if ((string) $cur('plugin_state', 'stopped') === 'running') {
            $ws('start_time', current_time('timestamp'));
        }
    }

    // geo
    if ($has('geo_enabled')) {
        $ws('geo_filter_enabled', !empty($get('geo_enabled', false)) ? 1 : 0);
    }
    if ($has('geo_mode')) {
        $gm = (string) $get('geo_mode', 'allow');
        if (!in_array($gm, array('allow', 'block'), true)) {
            return new WP_Error('devdredi_bad_input', __('geo_mode must be allow or block.', 'devdome-redirect-manager'));
        }
        $ws('geo_filter_mode', $gm === 'block' ? 'blacklist' : 'whitelist');
    }
    if ($has('geo_countries')) {
        $codes = array();
        foreach ((array) $get('geo_countries', array()) as $c) {
            $c = strtoupper(trim(sanitize_text_field((string) $c)));
            if (preg_match('/^[A-Z]{2}$/', $c) && !in_array($c, $codes, true)) {
                $codes[] = $c;
            }
        }
        $csv = implode(',', $codes);
        if ((string) $cur('geo_filter_mode', 'whitelist') === 'blacklist') {
            $ws('geo_filter_blacklist', $csv);
        } else {
            $ws('geo_filter_whitelist', $csv);
        }
    }
    if ($has('trust_proxy')) {
        $ws('trust_proxy', !empty($get('trust_proxy', false)) ? 1 : 0);
    }

    // devices
    if ($has('devices')) {
        $dv = array_map('strval', (array) $get('devices', array('desktop', 'mobile', 'tablet')));
        foreach (array('desktop', 'mobile', 'tablet') as $d) {
            $ws('device_' . $d, in_array($d, $dv, true) ? 1 : 0);
        }
    }

    // fallback
    if ($has('fallback_mode')) {
        $fm = (string) $get('fallback_mode', 'leave');
        if (!in_array($fm, array('leave', 'send'), true)) {
            return new WP_Error('devdredi_bad_input', __('fallback_mode must be leave or send.', 'devdome-redirect-manager'));
        }
        $ws('fallback_mode', $fm);
    }
    if ($has('fallback_url')) {
        $ws('fallback_url', esc_url_raw(trim((string) $get('fallback_url', '')), array('http', 'https')));
    }
    if ((string) $cur('fallback_mode', 'leave') === 'send' && (string) $cur('fallback_url', '') === '') {
        return new WP_Error('devdredi_bad_input', __('fallback_url is required when fallback_mode is send.', 'devdome-redirect-manager'));
    }

    // custom domains, cache
    if ($has('custom_domains')) {
        devdredi_ability_as_rule($rid, function () use ($get) { devdredi_set_custom_domains((array) $get('custom_domains', array())); });
    }
    if ($has('purge_cache_on_save')) {
        $ws('purge_cache_on_save', !empty($get('purge_cache_on_save', true)) ? 1 : 0);
    }
    return true;
}

/* ------------------------------- registration ------------------------------- */

add_action('wp_abilities_api_init', 'devdredi_register_abilities');
function devdredi_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    // An empty properties list must be a PHP array, not stdClass: WordPress validates input by array
    // access on it and an unexpected argument from a client would otherwise raise a type error
    // instead of a clean "invalid input" refusal.
    $empty_input = array('type' => 'object', 'properties' => array(), 'additionalProperties' => false);
    $rule_id_prop = array('rule_id' => array('type' => 'string', 'description' => 'The rule id from list-redirects (for example r1).'));
    $rule_id_input = array('type' => 'object', 'properties' => $rule_id_prop, 'required' => array('rule_id'), 'additionalProperties' => false);
    $confirm_input = array('type' => 'object', 'properties' => array_merge($rule_id_prop, array('confirm' => array('type' => 'boolean', 'description' => 'Must be true. This action cannot be undone; ask the user before passing it.'))), 'required' => array('rule_id', 'confirm'), 'additionalProperties' => false);
    $spec = devdredi_ability_rule_spec_properties();
    $kv = array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('key' => array('type' => 'string'), 'count' => array('type' => 'integer'))));
    $stats_props = array(
        'redirects' => array('type' => 'integer'), 'bypassed' => array('type' => 'integer'), 'page_views' => array('type' => 'integer'),
        'unique_visitors' => array('type' => 'integer'), 'unique_users' => array('type' => 'integer'),
        'desktop' => array('type' => 'integer'), 'mobile' => array('type' => 'integer'), 'tablet' => array('type' => 'integer'), 'reset_count' => array('type' => 'integer'),
        'by_source' => $kv, 'by_destination' => $kv, 'by_referrer' => $kv, 'by_country' => $kv, 'by_found_link' => $kv,
        'daily' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('date' => array('type' => 'string'), 'redirects' => array('type' => 'integer'), 'bypassed' => array('type' => 'integer'), 'desktop' => array('type' => 'integer'), 'mobile' => array('type' => 'integer'), 'tablet' => array('type' => 'integer'), 'countries' => $kv))),
    );
    $rule_props = array_merge(
        array(
            'id'         => array('type' => 'string'),
            'priority'   => array('type' => 'integer', 'description' => '1 = evaluated first.'),
            'state'      => array('type' => 'string', 'enum' => array('running', 'stopped')),
            'started_at' => array('type' => 'string', 'description' => 'ISO 8601 UTC when running, empty when stopped.'),
            'stats'      => array('type' => 'object', 'properties' => $stats_props),
        ),
        $spec
    );
    $rule_out = array('type' => 'object', 'properties' => $rule_props);

    $reg = function ($id, $label, $desc, $in, $out, $cb, $kind) {
        wp_register_ability($id, array(
            'label'               => $label,
            'description'         => $desc,
            'category'            => 'devdome-redirect-manager',
            'input_schema'        => $in,
            'output_schema'       => $out,
            'execute_callback'    => $cb,
            'permission_callback' => 'devdredi_ability_can',
            'meta'                => devdredi_ability_meta($kind),
        ));
    };

    $reg('devdome-redirect-manager/list-redirects', __('List redirect rules', 'devdome-redirect-manager'),
        __('List every redirect rule on this WordPress site in priority order with its full configuration: running or stopped, what it redirects (entire website, selected pages, custom paths, every 404), source paths, redirect method (301, 302, 307, 308, JavaScript, meta refresh), destination mode and URLs, rotation, open mode and delays, frequency (every visit, once per visitor, every Nth visitor, revisit delay), schedule (timezone, dates, weekdays, time windows, run duration), geo targeting, device targeting, fallback, allowed domains, and totals. Read only.', 'devdome-redirect-manager'),
        $empty_input, array('type' => 'object', 'properties' => array('total' => array('type' => 'integer'), 'items' => array('type' => 'array', 'items' => $rule_out))), 'devdredi_ability_list', 'read');

    $reg('devdome-redirect-manager/get-redirect-details', __('Get redirect rule details', 'devdome-redirect-manager'),
        __('Get one redirect rule by its id with its full configuration and detailed statistics: redirects, bypassed visits, page views, unique visitors, device split, redirects per source path, per destination URL, per referrer, per country and per found link, and the last 30 days per day with countries. Read only.', 'devdome-redirect-manager'),
        $rule_id_input, $rule_out, 'devdredi_ability_details', 'read');

    $reg('devdome-redirect-manager/get-redirect-stats', __('Get redirect statistics', 'devdome-redirect-manager'),
        __('Get redirect statistics for this WordPress site: total redirects, bypassed visits, page views, unique visitors and device split across all rules, the same numbers per rule, and how many rules are running. Read only.', 'devdome-redirect-manager'),
        $empty_input, array('type' => 'object', 'properties' => array('rules_total' => array('type' => 'integer'), 'rules_running' => array('type' => 'integer'), 'totals' => array('type' => 'object', 'properties' => array('redirects' => array('type' => 'integer'), 'bypassed' => array('type' => 'integer'), 'page_views' => array('type' => 'integer'), 'unique_visitors' => array('type' => 'integer', 'description' => 'Distinct visitors across all rules, deduplicated.'), 'desktop' => array('type' => 'integer'), 'mobile' => array('type' => 'integer'), 'tablet' => array('type' => 'integer'))), 'per_rule' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('id' => array('type' => 'string'), 'name' => array('type' => 'string'), 'state' => array('type' => 'string'), 'what' => array('type' => 'string'), 'method' => array('type' => 'string'), 'stats' => array('type' => 'object', 'properties' => $stats_props)))))), 'devdredi_ability_stats', 'read');

    $reg('devdome-redirect-manager/find-404-redirect-candidates', __('Find 404 paths worth redirecting', 'devdome-redirect-manager'),
        __('Find the missing pages (404 errors) on this WordPress site that real human visitors hit most, each with a suggested redirect target matched against the site\'s real page slugs, so you can create redirects for them. Needs DevDome Link Monitor active on the site (it records the 404 log). Read only.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array('limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20)), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('path' => array('type' => 'string'), 'human_hits' => array('type' => 'integer'), 'bot_hits' => array('type' => 'integer'), 'last_seen' => array('type' => 'string'), 'referrer' => array('type' => 'string'), 'suggested_url' => array('type' => 'string'), 'suggested_label' => array('type' => 'string')))))), 'devdredi_ability_404_candidates', 'read');

    $reg('devdome-redirect-manager/search-site-content', __('Search pages, posts or categories', 'devdome-redirect-manager'),
        __('Search this WordPress site\'s pages, posts or categories by title to get their URLs, for example to pick the existing pages a redirect rule should apply to (what = selected_pages) or a destination URL on this site. Read only.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array('type' => array('type' => 'string', 'enum' => array('page', 'post', 'category'), 'default' => 'page'), 'query' => array('type' => 'string', 'default' => '')), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('id' => array('type' => 'integer'), 'title' => array('type' => 'string'), 'url' => array('type' => 'string'), 'type' => array('type' => 'string')))))), 'devdredi_ability_search', 'read');

    $reg('devdome-redirect-manager/get-geo-status', __('Get geo targeting and proxy status', 'devdome-redirect-manager'),
        __('Check whether the geo targeting service (DevDome geo-resolve, no API key needed) is reachable from this WordPress site, and whether the site sits behind a proxy or CDN such as Cloudflare (in which case rules that use geo targeting or once-per-visitor should set trust_proxy). Read only; contacts api.devdome.com once.', 'devdome-redirect-manager'),
        $empty_input, array('type' => 'object', 'properties' => array('geo_service' => array('type' => 'object', 'properties' => array('success' => array('type' => 'boolean'), 'message' => array('type' => 'string'), 'source' => array('type' => 'string'))), 'proxy' => array('type' => 'object', 'properties' => array('detected' => array('type' => 'boolean'), 'label' => array('type' => 'string'))))), 'devdredi_ability_geo_status', 'read');

    $reg('devdome-redirect-manager/export-redirects', __('Export redirect rules', 'devdome-redirect-manager'),
        __('Export every redirect rule with its complete configuration (no statistics, no run state) as the same JSON the wp-admin Export button produces, for backup or for importing into another site. Read only.', 'devdome-redirect-manager'),
        $empty_input, array('type' => 'object', 'properties' => array('plugin' => array('type' => 'string'), 'version' => array('type' => 'integer'), 'rules' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('id' => array('type' => 'string'), 'nickname' => array('type' => 'string'), 'priority' => array('type' => 'integer')))), 'settings' => array('type' => 'object', 'description' => 'rule__<id>__<key> => stored value.'))), 'devdredi_ability_export', 'read');

    $reg('devdome-redirect-manager/create-redirect', __('Create a redirect rule', 'devdome-redirect-manager'),
        __('Create a new redirect rule on this WordPress site with any of the plugin\'s options: what to redirect (entire website, selected pages, custom paths, every 404) and the source paths, redirect method (301 permanent by default; 302, 307, 308, js, meta), destination mode (provided URLs with rotation, a link found on the page, or the same path on another domain), open mode and delays, frequency (every visit, once per IP or IP+browser, every Nth visitor, revisit delay), schedule (timezone, date range, weekdays, up to 3 time windows, run duration), geo targeting (allow or block countries), device targeting, fallback for non-redirected visitors, allowed custom domains and cache purge. Only from and to are required for a simple path-to-URL redirect. The rule is created STOPPED unless start is true, so nothing changes for visitors until it is started. Never edits or deletes existing rules.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array_merge($spec, array('start' => array('type' => 'boolean', 'default' => false, 'description' => 'true = the rule starts redirecting visitors immediately.'))), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('created' => array('type' => 'boolean'), 'rule' => $rule_out)), 'devdredi_ability_create', 'add');

    $reg('devdome-redirect-manager/update-redirect', __('Update a redirect rule', 'devdome-redirect-manager'),
        __('Change any settings of an existing redirect rule by its id: name, what to redirect and sources, method, destinations and rotation, open mode and delays, frequency, schedule, geo, devices, fallback, custom domains, cache purge. Only the keys you pass change; everything else keeps its value. The update is all or nothing: if any key is refused, the rule is left exactly as it was. Does not start or stop the rule (use set-redirect-state). Page caches are purged when the rule has purge on save enabled.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array_merge($rule_id_prop, $spec), 'required' => array('rule_id'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('updated' => array('type' => 'boolean'), 'rule' => $rule_out)), 'devdredi_ability_update', 'modify');

    $reg('devdome-redirect-manager/set-redirect-state', __('Start or stop a redirect rule', 'devdome-redirect-manager'),
        __('Start (state running) or stop (state stopped) one redirect rule by its id. Starting makes the rule redirect visitors and purges page caches; stopping leaves the rule and its statistics in place and keeps any remaining run duration. Same as the Run and Stop buttons in wp-admin.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array_merge($rule_id_prop, array('state' => array('type' => 'string', 'enum' => array('running', 'stopped')))), 'required' => array('rule_id', 'state'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('rule_id' => array('type' => 'string'), 'state' => array('type' => 'string'))), 'devdredi_ability_set_state', 'modify');

    $reg('devdome-redirect-manager/duplicate-redirect', __('Duplicate a redirect rule', 'devdome-redirect-manager'),
        __('Copy an existing redirect rule (all settings, no statistics) into a new rule at the end of the priority list, stopped, named "<name> copy" unless a name is given. Same as the Duplicate button in wp-admin.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array_merge($rule_id_prop, array('name' => array('type' => 'string', 'default' => ''))), 'required' => array('rule_id'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('created' => array('type' => 'boolean'), 'rule' => $rule_out)), 'devdredi_ability_duplicate', 'add');

    $reg('devdome-redirect-manager/reorder-redirects', __('Reorder redirect rules', 'devdome-redirect-manager'),
        __('Set the priority order of the redirect rules: pass the rule ids in the order they should be evaluated (the first running rule that matches a request wins). Rules not listed keep their relative order after the listed ones. Same as dragging rules in wp-admin.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array('order' => array('type' => 'array', 'items' => array('type' => 'string'), 'minItems' => 1)), 'required' => array('order'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('order' => array('type' => 'array', 'items' => array('type' => 'string')))), 'devdredi_ability_reorder', 'modify');

    $reg('devdome-redirect-manager/delete-redirect', __('Delete a redirect rule', 'devdome-redirect-manager'),
        __('Permanently delete one redirect rule and its statistics by its id. This cannot be undone; export-redirects first if you may need it back, and pass confirm: true only after the user agreed. The last remaining rule cannot be deleted. Same as the Delete button in wp-admin.', 'devdome-redirect-manager'),
        $confirm_input, array('type' => 'object', 'properties' => array('deleted' => array('type' => 'boolean'), 'rule_id' => array('type' => 'string'))), 'devdredi_ability_delete', 'destroy');

    $reg('devdome-redirect-manager/reset-redirect-stats', __('Reset a rule\'s statistics', 'devdome-redirect-manager'),
        __('Reset all statistics and visitor history of one redirect rule by its id (redirect counts, unique visitors, per-source, per-destination, per-country and daily counts, once-per-visitor memory and rotation position). This cannot be undone; pass confirm: true only after the user agreed. Same as the Reset button in wp-admin.', 'devdome-redirect-manager'),
        $confirm_input, array('type' => 'object', 'properties' => array('reset' => array('type' => 'boolean'), 'rule_id' => array('type' => 'string'))), 'devdredi_ability_reset_stats', 'destroy');

    $reg('devdome-redirect-manager/purge-redirect-cache', __('Purge page caches for a rule', 'devdome-redirect-manager'),
        __('Purge third-party page caches (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache and similar) for the pages one redirect rule targets, so a cached copy never masks the redirect. Pass a rule_id, or none to purge for every rule. Same as the Purge cache button in wp-admin.', 'devdome-redirect-manager'),
        array('type' => 'object', 'properties' => array('rule_id' => array('type' => 'string', 'default' => '')), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('purged' => array('type' => 'boolean'), 'rules' => array('type' => 'array', 'items' => array('type' => 'string')))), 'devdredi_ability_purge', 'modify');
}

/* ------------------------------- callbacks ------------------------------- */

function devdredi_ability_list($input = array())
{
    $items = array();
    foreach (devdredi_ability_rules_sorted() as $r) {
        $items[] = devdredi_ability_format_rule($r);
    }
    return array('total' => count($items), 'items' => $items);
}

function devdredi_ability_details($input = array())
{
    $input = is_array($input) ? $input : array();
    $rid = isset($input['rule_id']) ? sanitize_text_field((string) $input['rule_id']) : '';
    $r = $rid !== '' ? devdredi_ability_find_rule($rid) : null;
    if (!$r) {
        return new WP_Error('devdredi_not_found', __('Redirect rule not found.', 'devdome-redirect-manager'));
    }
    return devdredi_ability_format_rule($r, true);
}

function devdredi_ability_stats($input = array())
{
    $totals = array('redirects' => 0, 'bypassed' => 0, 'page_views' => 0, 'unique_visitors' => 0, 'desktop' => 0, 'mobile' => 0, 'tablet' => 0);
    $per = array();
    $running = 0;
    $seen = array();
    foreach (devdredi_ability_rules_sorted() as $r) {
        $f = devdredi_ability_format_rule($r);
        foreach ($totals as $k => $v) {
            if ($k !== 'unique_visitors') {
                $totals[$k] += (int) $f['stats'][$k];
            }
        }
        // One visitor who hit several rules is one visitor: union of the rules' visitor keys.
        $ips = devdredi_ability_rs((string) $r['id'], 'ip_list', array());
        foreach (is_array($ips) ? $ips : array() as $ip) {
            $seen[(string) $ip] = true;
        }
        $totals['unique_visitors'] = count($seen);
        if ($f['state'] === 'running') {
            $running++;
        }
        $per[] = array('id' => $f['id'], 'name' => $f['name'], 'state' => $f['state'], 'what' => $f['what'], 'method' => $f['method'], 'stats' => $f['stats']);
    }
    return array('rules_total' => count($per), 'rules_running' => $running, 'totals' => $totals, 'per_rule' => $per);
}

function devdredi_ability_404_candidates($input = array())
{
    if (!function_exists('devdlink_404_rows') && defined('DEVDLINK_DIR') && file_exists(DEVDLINK_DIR . 'includes/fourohfour-admin.php')) {
        require_once DEVDLINK_DIR . 'includes/fourohfour-admin.php';
    }
    if (!function_exists('devdlink_404_rows')) {
        return new WP_Error('devdredi_needs_link_monitor', __('DevDome Link Monitor is not active on this site; it records the 404 log this ability reads.', 'devdome-redirect-manager'));
    }
    $input = is_array($input) ? $input : array();
    $limit = isset($input['limit']) ? max(1, min(100, (int) $input['limit'])) : 20;
    $res = devdlink_404_rows(array('page' => 1, 'per_page' => $limit, 'humans_only' => 1, 'show_ignored' => 0, 'orderby' => 'human_hits', 'order' => 'DESC'));
    $items = array();
    foreach ((array) (isset($res['rows']) ? $res['rows'] : array()) as $row) {
        $row = (array) $row;
        $path = isset($row['path']) ? (string) $row['path'] : '';
        $sug = function_exists('devdlink_suggest_redirect') ? devdlink_suggest_redirect($path) : null;
        $items[] = array(
            'path'            => $path,
            'human_hits'      => isset($row['human_hits']) ? (int) $row['human_hits'] : 0,
            'bot_hits'        => isset($row['bot_hits']) ? (int) $row['bot_hits'] : 0,
            'last_seen'       => isset($row['last_seen']) ? (string) $row['last_seen'] : '',
            'referrer'        => (isset($row['last_referrer']) && function_exists('devdlink_redact_secrets')) ? devdlink_redact_secrets((string) $row['last_referrer']) : '',
            'suggested_url'   => is_array($sug) && !empty($sug['url']) ? (string) $sug['url'] : '',
            'suggested_label' => is_array($sug) && !empty($sug['label']) ? (string) $sug['label'] : '',
        );
    }
    return array('items' => $items);
}

function devdredi_ability_search($input = array())
{
    $input = is_array($input) ? $input : array();
    $type = isset($input['type']) ? sanitize_key((string) $input['type']) : 'page';
    $q    = isset($input['query']) ? sanitize_text_field((string) $input['query']) : '';
    $items = array();
    if ($type === 'category') {
        $terms = get_terms(array('taxonomy' => 'category', 'search' => $q, 'number' => 20, 'hide_empty' => false));
        foreach (is_wp_error($terms) ? array() : $terms as $term) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                $items[] = array('id' => (int) $term->term_id, 'title' => (string) $term->name, 'url' => (string) $link, 'type' => 'category');
            }
        }
    } else {
        $pt = $type === 'post' ? 'post' : 'page';
        $posts = get_posts(array('post_type' => $pt, 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 20, 'orderby' => 'title', 'order' => 'ASC'));
        foreach ($posts as $p) {
            $items[] = array('id' => (int) $p->ID, 'title' => (string) get_the_title($p), 'url' => (string) get_permalink($p), 'type' => $pt);
        }
    }
    return array('items' => $items);
}

function devdredi_ability_geo_status($input = array())
{
    return array(
        'geo_service' => function_exists('devdredi_geo_health') ? devdredi_geo_health() : array('success' => false, 'message' => 'unavailable'),
        'proxy'       => function_exists('devdredi_detect_proxy') ? devdredi_detect_proxy() : array('detected' => false, 'label' => 'unknown'),
    );
}

function devdredi_ability_export($input = array())
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';
    $excluded = function_exists('devdredi_io_excluded_suffixes') ? devdredi_io_excluded_suffixes() : array();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB -- plugin's own settings table, same query as the Export button.
    $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM {$t} WHERE setting_name LIKE 'rule\\_\\_%'", ARRAY_A);
    $settings = array();
    foreach ((array) $rows as $row) {
        $parts = explode('__', $row['setting_name'], 3);
        if (count($parts) < 3 || $parts[0] !== 'rule' || $parts[2] === '' || in_array($parts[2], $excluded, true)) {
            continue;
        }
        if (in_array($parts[2], array('ip_list', 'ua_list', 'uu_list', 'ip_link_index', 'ip_redirected_once', 'last_redirects'), true)) {
            continue;
        }
        $settings[$row['setting_name']] = $row['setting_value'];
    }
    return array('plugin' => 'devdome-redirect-manager', 'version' => 1, 'rules' => devdredi_get_rules(), 'settings' => $settings);
}

function devdredi_ability_create($input = array())
{
    $input = is_array($input) ? $input : array();
    $start = !empty($input['start']);
    unset($input['start']);
    $name  = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';

    $rules = devdredi_get_rules();
    $ids   = array_map(function ($r) { return $r['id']; }, $rules);
    $rid   = devdredi_new_rule_id($ids);

    // Form defaults first, then the spec on top, so a partial spec behaves like the admin form.
    $defaults = array(
        'what' => array_key_exists('from', $input) ? 'custom_paths' : 'entire_website', 'method' => '301', 'destination_mode' => 'provided',
        'rotation' => 'sequential', 'rotation_repeat' => true, 'weighted_spread' => 0.5, 'weighted_seed' => 0,
        'open_mode' => 'same_tab', 'same_tab_delay' => array(0, 0), 'new_tab_delay' => array(0, 0), 'same_tab_require_click' => false,
        'same_tab_after_click_delay' => array(0, 0), 'new_tab_after_click_delay' => array(0, 0),
        'once_per' => 'never', 'every_nth_visitor' => 1, 'revisit_delay' => 0, 'revisit_delay_unit' => 'minutes',
        'schedule_mode' => 'always', 'schedule_timezone' => '', 'schedule_start_date' => '', 'schedule_end_date' => '',
        'schedule_weekdays' => array(), 'schedule_times' => array(), 'run_for_minutes' => 0,
        'geo_enabled' => false, 'geo_mode' => 'allow', 'geo_countries' => array(), 'trust_proxy' => false,
        'devices' => array('desktop', 'mobile', 'tablet'), 'fallback_mode' => 'leave', 'fallback_url' => '',
        'custom_domains' => array(), 'purge_cache_on_save' => true,
        'found_link_contains' => array(), 'transit_domain' => '',
    );
    $spec = array_merge($defaults, $input);
    if (!empty($spec['geo_countries']) && !array_key_exists('geo_enabled', $input)) {
        $spec['geo_enabled'] = true;
    }
    if (!empty($spec['geo_enabled']) && empty($spec['geo_countries'])) {
        return new WP_Error('devdredi_bad_input', __('geo_countries is required when geo_enabled is true.', 'devdome-redirect-manager'));
    }

    $rules[] = array('id' => $rid, 'nickname' => $name, 'priority' => count($rules) + 1);
    devdredi_save_rules($rules);
    devdredi_ability_ws($rid, 'plugin_state', 'stopped');
    devdredi_ability_ws($rid, 'start_time', 0);

    $ok = devdredi_ability_apply_spec($rid, $spec, true);
    if (is_wp_error($ok)) {
        // Roll the half-written rule back so a refused spec leaves nothing behind.
        devdredi_delete_rule_settings($rid);
        devdredi_save_rules(array_values(array_filter(devdredi_get_rules(), function ($r) use ($rid) { return $r['id'] !== $rid; })));
        return $ok;
    }
    if ($start) {
        devdredi_ability_ws($rid, 'plugin_state', 'running');
        devdredi_ability_ws($rid, 'start_time', current_time('timestamp'));
        if (devdredi_ability_rs($rid, 'purge_cache_on_save', 1)) {
            devdredi_ability_purge_for_rule($rid);
        }
    }
    $r = devdredi_ability_find_rule($rid);
    return array('created' => true, 'rule' => $r ? devdredi_ability_format_rule($r) : array('id' => $rid));
}

function devdredi_ability_update($input = array())
{
    $input = is_array($input) ? $input : array();
    $rid = isset($input['rule_id']) ? sanitize_text_field((string) $input['rule_id']) : '';
    unset($input['rule_id']);
    $r = $rid !== '' ? devdredi_ability_find_rule($rid) : null;
    if (!$r) {
        return new WP_Error('devdredi_not_found', __('Redirect rule not found.', 'devdome-redirect-manager'));
    }
    // All or nothing: the spec is applied key by key, so keep a copy to put back if any key is refused.
    $before_rules = devdredi_get_rules();
    $snapshot     = devdredi_ability_snapshot_rule($rid);
    if (array_key_exists('name', $input)) {
        $rules = devdredi_get_rules();
        foreach ($rules as &$x) {
            if ($x['id'] === $rid) {
                $x['nickname'] = sanitize_text_field((string) $input['name']);
            }
        }
        unset($x);
        devdredi_save_rules($rules);
        unset($input['name']);
    }
    if (!empty($input['geo_countries']) && !array_key_exists('geo_enabled', $input) && !devdredi_ability_rs($rid, 'geo_filter_enabled', 0)) {
        $input['geo_enabled'] = true;
    }
    $ok = devdredi_ability_apply_spec($rid, $input, false);
    if (is_wp_error($ok)) {
        devdredi_ability_restore_rule($rid, $snapshot, $before_rules);
        return $ok;
    }
    if (devdredi_ability_rs($rid, 'purge_cache_on_save', 1)) {
        devdredi_ability_purge_for_rule($rid);
    }
    $r = devdredi_ability_find_rule($rid);
    return array('updated' => true, 'rule' => devdredi_ability_format_rule($r));
}

function devdredi_ability_set_state($input = array())
{
    $input = is_array($input) ? $input : array();
    $rid   = isset($input['rule_id']) ? sanitize_text_field((string) $input['rule_id']) : '';
    $state = isset($input['state']) && (string) $input['state'] === 'running' ? 'running' : 'stopped';
    $r = $rid !== '' ? devdredi_ability_find_rule($rid) : null;
    if (!$r) {
        return new WP_Error('devdredi_not_found', __('Redirect rule not found.', 'devdome-redirect-manager'));
    }
    if ($state === 'running') {
        devdredi_ability_ws($rid, 'plugin_state', 'running');
        devdredi_ability_ws($rid, 'start_time', current_time('timestamp'));
        if (devdredi_ability_rs($rid, 'purge_cache_on_save', 1)) {
            devdredi_ability_purge_for_rule($rid);
        }
    } else {
        // Same as the Stop button: keep whatever run duration is left for the next start.
        $start_time = (int) devdredi_ability_rs($rid, 'start_time', 0);
        $runtime    = (int) devdredi_ability_rs($rid, 'runtime_minutes', 0);
        devdredi_ability_ws($rid, 'plugin_state', 'stopped');
        if ($start_time > 0 && $runtime > 0) {
            $left = max(($start_time + $runtime * 60) - current_time('timestamp'), 0);
            devdredi_ability_ws($rid, 'runtime_minutes', (int) ceil($left / 60));
        }
        devdredi_ability_ws($rid, 'start_time', 0);
    }
    return array('rule_id' => $rid, 'state' => $state);
}

function devdredi_ability_duplicate($input = array())
{
    $input = is_array($input) ? $input : array();
    $rid = isset($input['rule_id']) ? sanitize_text_field((string) $input['rule_id']) : '';
    $src = $rid !== '' ? devdredi_ability_find_rule($rid) : null;
    if (!$src) {
        return new WP_Error('devdredi_not_found', __('Redirect rule not found.', 'devdome-redirect-manager'));
    }
    $rules = devdredi_get_rules();
    $ids   = array_map(function ($r) { return $r['id']; }, $rules);
    $newid = devdredi_new_rule_id($ids);
    devdredi_copy_rule_settings($rid, $newid);
    $name = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';
    if ($name === '') {
        $name = (!empty($src['nickname']) ? $src['nickname'] : 'Rule') . ' copy';
    }
    $rules[] = array('id' => $newid, 'nickname' => $name, 'priority' => count($rules) + 1);
    devdredi_save_rules($rules);
    // A copy never starts running and carries no statistics (same as the wp-admin Duplicate).
    devdredi_ability_ws($newid, 'plugin_state', 'stopped');
    devdredi_ability_ws($newid, 'start_time', 0);
    devdredi_ability_as_rule($newid, 'devdredi_reset_stats');
    $r = devdredi_ability_find_rule($newid);
    return array('created' => true, 'rule' => devdredi_ability_format_rule($r));
}

function devdredi_ability_reorder($input = array())
{
    $input = is_array($input) ? $input : array();
    $order = array_values(array_unique(array_filter(array_map(function ($x) { return sanitize_text_field((string) $x); }, (array) (isset($input['order']) ? $input['order'] : array())))));
    $rules = devdredi_get_rules();
    $by_id = array();
    foreach ($rules as $r) {
        $by_id[$r['id']] = $r;
    }
    $unknown = array_diff($order, array_keys($by_id));
    if ($unknown) {
        return new WP_Error('devdredi_not_found', sprintf(__('Unknown rule id(s): %s', 'devdome-redirect-manager'), implode(', ', $unknown)));
    }
    $re = array();
    foreach ($order as $oid) {
        $re[] = $by_id[$oid];
        unset($by_id[$oid]);
    }
    foreach ($by_id as $r) {
        $re[] = $r;
    }
    foreach ($re as $i => &$r) {
        $r['priority'] = $i + 1;
    }
    unset($r);
    devdredi_save_rules($re);
    return array('order' => array_map(function ($r) { return $r['id']; }, $re));
}

function devdredi_ability_delete($input = array())
{
    $input = is_array($input) ? $input : array();
    if (!isset($input['confirm']) || $input['confirm'] !== true) {
        return new WP_Error('devdredi_confirm_required', __('This cannot be undone. Pass confirm: true to proceed.', 'devdome-redirect-manager'));
    }
    $rid = isset($input['rule_id']) ? sanitize_text_field((string) $input['rule_id']) : '';
    $rules = devdredi_get_rules();
    $ids = array_map(function ($r) { return $r['id']; }, $rules);
    if ($rid === '' || !in_array($rid, $ids, true)) {
        return new WP_Error('devdredi_not_found', __('Redirect rule not found.', 'devdome-redirect-manager'));
    }
    if (count($rules) <= 1) {
        return new WP_Error('devdredi_last_rule', __('The last remaining rule cannot be deleted.', 'devdome-redirect-manager'));
    }
    devdredi_delete_rule_settings($rid);
    $rules = array_values(array_filter($rules, function ($r) use ($rid) { return $r['id'] !== $rid; }));
    foreach ($rules as $i => &$r) {
        $r['priority'] = $i + 1;
    }
    unset($r);
    devdredi_save_rules($rules);
    if (devdredi_raw_get_setting('active_rule', '') === $rid) {
        devdredi_raw_update_setting('active_rule', $rules[0]['id']);
    }
    return array('deleted' => true, 'rule_id' => $rid);
}

function devdredi_ability_reset_stats($input = array())
{
    $input = is_array($input) ? $input : array();
    if (!isset($input['confirm']) || $input['confirm'] !== true) {
        return new WP_Error('devdredi_confirm_required', __('This cannot be undone. Pass confirm: true to proceed.', 'devdome-redirect-manager'));
    }
    $rid = isset($input['rule_id']) ? sanitize_text_field((string) $input['rule_id']) : '';
    if ($rid === '' || !devdredi_ability_find_rule($rid)) {
        return new WP_Error('devdredi_not_found', __('Redirect rule not found.', 'devdome-redirect-manager'));
    }
    devdredi_ability_as_rule($rid, 'devdredi_reset_stats');
    if (function_exists('devdredi_purge_plugin_cache')) {
        devdredi_purge_plugin_cache();
    }
    return array('reset' => true, 'rule_id' => $rid);
}

function devdredi_ability_purge($input = array())
{
    $input = is_array($input) ? $input : array();
    $rid = isset($input['rule_id']) ? sanitize_text_field((string) $input['rule_id']) : '';
    $targets = array();
    if ($rid !== '') {
        if (!devdredi_ability_find_rule($rid)) {
            return new WP_Error('devdredi_not_found', __('Redirect rule not found.', 'devdome-redirect-manager'));
        }
        $targets[] = $rid;
    } else {
        foreach (devdredi_get_rules() as $r) {
            $targets[] = $r['id'];
        }
    }
    foreach ($targets as $t) {
        devdredi_ability_purge_for_rule($t);
    }
    return array('purged' => true, 'rules' => $targets);
}

/**
 * Official WordPress MCP Adapter: list our abilities as direct tools on its default server
 * (next to its discover / execute meta-tools). Harmless when the adapter is not installed.
 */
add_filter('mcp_adapter_default_server_config', 'devdredi_mcp_default_server_tools');
function devdredi_mcp_default_server_tools($config)
{
    if (!is_array($config)) {
        return $config;
    }
    $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : array();
    $config['tools'] = array_values(array_unique(array_merge($tools, devdredi_ability_ids())));
    return $config;
}
