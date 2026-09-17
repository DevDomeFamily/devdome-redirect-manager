<?php
/**
 * Admin: settings page (UI + save handler + Statistics), menu registration,
 * admin styles, and the cache-purge handler.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/devdome-tools-menu.php';

function devdredi_menu()
{
    // Attach under the shared "DevDome Tools" menu (devdome-tools-menu.php). Position 4
    // (after Overview 0 / Analytics 1 / Product Importer 2 / Affiliate Manager 3).
    add_submenu_page(
        defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools',
        'DevDome Redirect Manager',
        'Redirect Manager',
        'manage_options',
        'devdome-redirect-manager',
        'devdredi_settings_page',
        4
    );
}
add_action('admin_menu', 'devdredi_menu');

// Best-effort persist of the active rule when the user switches client-side (no reload), so a
// later manual refresh shows the rule they were on. Save still carries rm_editing_rule for safety.
add_action('wp_ajax_devdredi_set_active', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
    check_ajax_referer('devdredi_rule_action', '_n');
    $id = isset($_POST['rule']) ? sanitize_text_field(wp_unslash($_POST['rule'])) : '';
    $ids = array_map(function ($r) { return $r['id']; }, devdredi_get_rules());
    if (devdredi_rules_index_unreadable()) { wp_send_json_error('the rule list could not be read (database error); nothing was changed'); }
    if (in_array($id, $ids, true)) {
        if (devdredi_raw_update_setting('active_rule', $id) === false) {
            wp_send_json_error('the active rule could not be saved (database error)');
        }
        wp_send_json_success();
    }
    wp_send_json_error('bad id');
});

/** Default values for every editable setting (mirrors the form's get_setting() defaults). */
function devdredi_rule_editable_defaults()
{
    return array(
        'what_to_redirect' => 'entire_website', 'outside_only' => 0, 'redirect_source' => 'provided', 'redirect_type' => 'js',
        'links_mode' => 'sequential', 'links_repeat' => 1, 'descending_spread' => 0.5, 'descending_seed' => 0,
        'open_mode' => 'same_tab', 'same_tab_delay_min' => 0, 'same_tab_delay_max' => 0, 'new_tab_delay_min' => 0, 'new_tab_delay_max' => 0,
        'after_click_enabled' => 0, 'after_click_min' => 0, 'after_click_max' => 0, 'same_tab_require_click' => 0,
        'same_tab_after_click_enabled' => 0, 'same_tab_after_click_min' => 0, 'same_tab_after_click_max' => 0,
        'run_once' => 'ip', 'open_on_every' => 1, 'revisit_delay' => 0, 'revisit_delay_unit' => 'minutes', 'skip_bots' => 1, 'runtime_minutes' => 0,
        'run_mode' => 'unlimited', 'schedule_timezone' => '', 'geo_filter_enabled' => 0, 'geo_filter_mode' => 'whitelist',
        'geo_filter_whitelist' => '', 'geo_filter_blacklist' => '', 'trust_proxy' => 0,
        'device_desktop' => 1, 'device_mobile' => 1, 'device_tablet' => 1, 'purge_cache_on_save' => 1,
        'fallback_mode' => 'leave',
        'links_list' => '', 'custom_links_list' => '', 'selected_links_list' => '', 'selected_links_meta' => '', 'referrer_list' => '', 'referrer_only_selected' => 0,
        'page_links_contains' => '', 'transit_domain' => '', 'fallback_url' => '', 'plugin_state' => 'stopped',
        'run_weekdays' => array(), 'specific_times' => array(),
        'schedule_start_date' => '', 'schedule_end_date' => '',
    );
}

/** A rule's editable settings with form-accurate defaults applied (for the no-reload switcher). */
function devdredi_rule_editable_settings($rid)
{
    $prev = isset($GLOBALS['devdredi_rule']) ? $GLOBALS['devdredi_rule'] : null;
    $GLOBALS['devdredi_rule'] = $rid;
    unset($GLOBALS['devdredi_read_failed']); // a form filled from defaults would overwrite the real settings on Save
    $out = array();
    foreach (devdredi_rule_editable_defaults() as $k => $def) {
        $out[$k] = devdredi_get_setting($k, $def);
    }
    $GLOBALS['devdredi_rule'] = $prev;
    if (!empty($GLOBALS['devdredi_read_failed'])) {
        if (wp_doing_ajax()) {
            wp_send_json_error('the rule settings could not be read (database error); reload the page');
        }
        wp_die(esc_html__('The rule settings could not be read (database error). Reload the page and try again; nothing was changed.', 'devdome-redirect-manager'));
    }
    return $out;
}

/** Render the rule-list rows (shared by the page and the AJAX "add rule" so markup stays in one place). */
function devdredi_render_rule_rows($rm_rules, $rm_active, $rm_base, $rm_nonce)
{
    ob_start();
    foreach ($rm_rules as $i => $r):
        $rid = $r['id'];
        $is_active = ($rid === $rm_active);
        $nick = isset($r['nickname']) ? $r['nickname'] : '';
        $running = (devdredi_raw_get_setting('rule__' . $rid . '__plugin_state', 'stopped') === 'running');
        $rcount = (int) devdredi_raw_get_setting('rule__' . $rid . '__user_redirects_count', 0);
    ?>
                        <?php
                        $su = function ($k, $d = 0) use ($rid) { return devdredi_raw_get_setting('rule__' . $rid . '__' . $k, $d); };
                        $ip_l = $su('ip_list', array());
                        $cc_map = $su('rc_by_country', array());
                        if (!is_array($cc_map)) { $cc_map = array(); }
                        arsort($cc_map);
                        $life = array(
                            'red' => (int) $su('user_redirects_count', 0), 'byp' => (int) $su('user_bypass_count', 0), 'bs' => (int) $su('user_bot_skip_count', 0),
                            'dd' => (int) $su('device_count_desktop', 0),
                            'dm' => (int) $su('device_count_mobile', 0), 'dt' => (int) $su('device_count_tablet', 0),
                            'uu' => (int) $su('unique_users_count', 0), 'ip' => (is_array($ip_l) ? count($ip_l) : 0), 'cc' => $cc_map,
                        );
                        $daily = $su('stats_daily', array()); if (!is_array($daily)) { $daily = array(); }
                        $stats_json = wp_json_encode(array('life' => $life, 'daily' => $daily));
                        $pm = 'color:#4f46e5;font-size:15px;';
                        ?>
                        <div class="dd-rule-row<?php echo $is_active ? ' is-active' : ''; ?>" draggable="true" data-rule="<?php echo esc_attr($rid); ?>" data-stats="<?php echo esc_attr($stats_json); ?>" style="cursor:pointer;">
                            <button type="button" class="dd-rule-iconbtn dd-rule-runstop" title="<?php echo $running ? 'Running — click to stop' : 'Stopped — click to run'; ?>" style="<?php echo $running ? 'color:#dc2626;' : 'color:#16a34a;'; ?>"><?php if ($running): ?><span style="display:inline-block;width:11px;height:11px;background:#dc2626;border-radius:2px;"></span><?php else: ?><span class="dashicons dashicons-controls-play"></span><?php endif; ?></button>
                            <span class="dashicons dashicons-menu dd-rule-handle" title="Drag to reorder" style="cursor:grab;"></span>
                            <a class="dd-rule-id" draggable="false" href="<?php echo esc_url($rm_base . '&rule=' . $rid . '&_rmn=' . $rm_nonce); ?>" style="text-decoration:none;color:inherit;">Rule #<?php echo (int) ( $i + 1 ); ?></a>
                            <span class="dd-rule-pri">Priority <?php echo (int) ( $i + 1 ); ?></span>
                            <input type="text" class="dd-rule-name" placeholder="Add rule nickname…" value="<?php echo esc_attr($nick); ?>"<?php echo $is_active ? '' : ' readonly'; ?> style="border:1px solid #d1d5db;border-radius:6px;padding:5px 9px;font-size:13px;min-width:220px;background:<?php echo $is_active ? '#fff' : '#f9fafb'; ?>;color:#374151;">
                            <span class="dd-rule-count">Total Redirects: <strong class="dd-rr-total"><?php echo (int) $life['red']; ?></strong></span>
                            <select class="dd-range" title="Stats time range" style="height:28px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-weight:600;color:#374151;background:#fff;padding:0 6px;">
                                <option value="all">All Time</option>
                                <option value="1">24h</option>
                                <option value="7">7 Days</option>
                                <option value="30">30 Days</option>
                                <option value="90">90 Days</option>
                                <option value="180">180 Days</option>
                                <option value="365">365 Days</option>
                            </select>
                            <span class="dd-rule-actions">
                                <button type="button" class="dd-rule-iconbtn" data-act="expand" title="Show stats"><span class="dashicons dashicons-chart-bar"></span></button>
                                <a class="dd-rule-iconbtn" href="<?php echo esc_url($rm_base . '&rm_rule_action=duplicate&rm_rule_id=' . $rid . '&_rmn=' . $rm_nonce); ?>" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></a>
                                <?php if (count($rm_rules) > 1): ?>
                                <button type="button" class="dd-rule-iconbtn is-del" data-act="delete" title="Delete"><span class="dashicons dashicons-trash"></span></button>
                                <span class="dd-rule-confirm">Delete? <a class="yes" href="<?php echo esc_url($rm_base . '&rm_rule_action=delete&rm_rule_id=' . $rid . '&_rmn=' . $rm_nonce); ?>">Yes</a><span class="text-gray-300">/</span><a class="no" data-act="confirm-no">No</a></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="dd-rule-stats" id="dd-rule-stats-<?php echo esc_attr($rid); ?>" style="display:none;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:4px 0 8px;font-size:13px;font-weight:600;color:#374151;line-height:1.4;">
                            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px 22px;">
                                <span class="dd-pm-uniq">Unique Users: <span class="dd-pm" data-m="uu" style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $life['uu']; ?></span></span>
                                <span class="dd-pm-uniq">Unique IPs: <span class="dd-pm" data-m="ip" style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $life['ip']; ?></span></span>
                                <span>Bypassed: <span class="dd-pm" data-m="byp" style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $life['byp']; ?></span></span>
                                <span>Bots Skipped: <span class="dd-pm" data-m="bs" style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $life['bs']; ?></span></span>
                                <span>Desktop: <span class="dd-pm" data-m="dd" style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $life['dd']; ?></span></span>
                                <span>Mobile: <span class="dd-pm" data-m="dm" style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $life['dm']; ?></span></span>
                                <span>Tablet: <span class="dd-pm" data-m="dt" style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $life['dt']; ?></span></span>
                                <a class="dd-removeall-btn" href="<?php echo esc_url($rm_base . '&rm_rule_action=resetstats&rm_rule_id=' . $rid . '&_rmn=' . $rm_nonce); ?>" onclick="return confirm('Reset this rule\'s statistics?');" style="margin-left:auto;">Reset this rule&rsquo;s stats</a>
                            </div>
                            <div class="dd-bycountry" style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb;display:<?php echo $cc_map ? 'flex' : 'none'; ?>;flex-wrap:wrap;align-items:center;gap:6px 22px;">
                                <span>By Country:</span>
                                <?php foreach ($cc_map as $code => $cnt): ?>
                                <span><?php echo esc_html($code); ?>: <span style="<?php echo esc_attr( $pm ); ?>"><?php echo (int) $cnt; ?></span></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
    <?php
    endforeach;
    return ob_get_clean();
}

// Create a rule and return the re-rendered rule list + the new rule's settings, so the admin can
// add a rule with no full page reload (mirrors the ?rm_rule_action=add path).
add_action('wp_ajax_devdredi_add_rule', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
    check_ajax_referer('devdredi_rule_action', '_n');
    $rules = devdredi_get_rules();
    $ids = array_map(function ($r) { return $r['id']; }, $rules);
    if (devdredi_rules_index_unreadable()) {
        if (wp_doing_ajax()) { wp_send_json_error('the rule list could not be read (database error); nothing was changed'); }
        wp_die(esc_html__('The rule list could not be read (database error); nothing was changed.', 'devdome-redirect-manager'));
    }
    $newid = devdredi_new_rule_id($ids);
    $nick = '';
    $rules_before = $rules;
    $rules[] = array('id' => $newid, 'nickname' => $nick, 'priority' => count($rules) + 1);
    if (!devdredi_save_rules($rules)) {
        wp_send_json_error('the new rule could not be saved (database error); nothing was added');
    }
    if (devdredi_raw_update_setting('active_rule', $newid) === false) {
        wp_send_json_error(devdredi_save_rules($rules_before) ? 'the new rule could not be saved (database error); nothing was added' : 'the new rule was listed but could not be made active AND could not be removed again (database error); check the rule list');
    }
    $rm_base = admin_url('admin.php?page=devdome-redirect-manager');
    $rm_nonce = wp_create_nonce('devdredi_rule_action');
    $settings = devdredi_rule_editable_settings($newid);
    $settings['nickname'] = $nick;
    wp_send_json_success(array(
        'id' => $newid,
        'listHtml' => devdredi_render_rule_rows($rules, $newid, $rm_base, $rm_nonce),
        'settings' => $settings,
    ));
});

// Toggle / duplicate / delete a rule with no full reload (mirrors the ?rm_rule_action= handler).
// Returns the re-rendered list + all rules' settings + which rule is active afterward.
add_action('wp_ajax_devdredi_rule_action', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
    check_ajax_referer('devdredi_rule_action', '_n');
    $action  = isset($_POST['rm_action']) ? sanitize_text_field(wp_unslash($_POST['rm_action'])) : '';
    $target  = isset($_POST['rule']) ? sanitize_text_field(wp_unslash($_POST['rule'])) : '';
    $editing = isset($_POST['editing']) ? sanitize_text_field(wp_unslash($_POST['editing'])) : '';

    $rules = devdredi_get_rules();
    $ids   = array_map(function ($r) { return $r['id']; }, $rules);
    if (devdredi_rules_index_unreadable()) {
        wp_send_json_error('the rule list could not be read (database error); nothing was changed');
    }

    // The rule the user is currently viewing is the source of truth for "active".
    $active = in_array($editing, $ids, true) ? $editing : devdredi_raw_get_setting('active_rule', $rules[0]['id']);
    if (devdredi_raw_update_setting('active_rule', $active) === false) {
        wp_send_json_error('the active rule could not be switched (database error); nothing was changed');
    }

    if ($action === 'duplicate' && in_array($target, $ids, true)) {
        $newid = devdredi_new_rule_id($ids);
        $nick = 'Rule';
        foreach ($rules as $r) { if ($r['id'] === $target) { $nick = (!empty($r['nickname']) ? $r['nickname'] : 'Rule') . ' copy'; } }
        $rules[] = array('id' => $newid, 'nickname' => $nick, 'priority' => count($rules) + 1);
        // Every write checked: a half-copied rule is removed again, never listed.
        if (!devdredi_copy_rule_settings($target, $newid) || !devdredi_save_rules($rules)) {
            $undone = devdredi_delete_rule_settings($newid);
            $undone = devdredi_save_rules(array_values(array_filter($rules, function ($r) use ($newid) { return $r['id'] !== $newid; }))) && $undone;
            wp_send_json_error($undone ? 'the copy could not be written completely (database error); nothing was created' : 'the copy could not be written completely AND could not be removed again (database error); check the rule list');
        }
        $active = $newid;
        if (devdredi_raw_update_setting('active_rule', $newid) === false) { wp_send_json_error('the copy was created but could not be made active (database error); reload the page'); }
    } elseif ($action === 'delete' && in_array($target, $ids, true) && count($rules) > 1) {
        $before = $rules;
        $rules = array_values(array_filter($rules, function ($r) use ($target) { return $r['id'] !== $target; }));
        foreach ($rules as $i => &$r) { $r['priority'] = $i + 1; }
        unset($r);
        // Index first, rows second; a failure at either step leaves the rule whole and listed.
        if (!devdredi_save_rules($rules)) { wp_send_json_error('the rule list could not be written (database error); nothing was deleted'); }
        if (!devdredi_delete_rule_settings($target)) { wp_send_json_error(devdredi_save_rules($before) ? 'the rule settings could not be deleted (database error); the rule was kept' : 'the rule settings could not be deleted AND the rule list could not be put back (database error); reload and check the rule list'); }
        if ($active === $target) { $active = $rules[0]['id']; if (devdredi_raw_update_setting('active_rule', $active) === false) { wp_send_json_error('the rule was deleted, but the active-rule pointer could not be moved (database error); reload the page'); } }
    } else {
        wp_send_json_error('bad action');
    }

    $rm_base = admin_url('admin.php?page=devdome-redirect-manager');
    $rm_nonce = wp_create_nonce('devdredi_rule_action');
    $fresh = devdredi_get_rules();
    $settings = array();
    foreach ($fresh as $rr) { $s = devdredi_rule_editable_settings($rr['id']); $s['nickname'] = isset($rr['nickname']) ? $rr['nickname'] : ''; $settings[$rr['id']] = $s; }
    wp_send_json_success(array(
        'listHtml' => devdredi_render_rule_rows($fresh, $active, $rm_base, $rm_nonce),
        'settings' => $settings,
        'active'   => $active,
    ));
});

// Stat/runtime keys that are per-site usage data, NOT portable config. Excluded from import/export.
function devdredi_io_excluded_suffixes_admin_unused()
{
    // Moved to settings.php (1.5.0): export runs outside wp-admin too. Kept only so nothing here references a missing name.
    return array(
        'visitor_count', 'page_view_count', 'ip_list', 'ua_list', 'ip_link_index', 'ip_redirected_once',
        'last_redirects', 'user_redirects_count', 'user_bypass_count', 'unique_visitor_count',
        'unique_users_count', 'rc_by_source', 'rc_by_dest', 'rc_by_referrer', 'rc_by_country', 'rc_by_found',
        'device_count_desktop', 'device_count_mobile', 'device_count_tablet',
        'reset_count', 'plugin_state', 'start_time', 'stats_daily',
    );
}

// Export: download every rule + its config (no stats, no run-state) as a JSON file.
add_action('admin_init', function () {
    if (!isset($_GET['rm_export']) || !current_user_can('manage_options')) {
        return;
    }
    if (!isset($_GET['_rmx']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_rmx'])), 'devdredi_io')) {
        return;
    }
    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';
    $excluded = devdredi_io_excluded_suffixes();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- export read of the plugin's own table; $t from $wpdb->prefix; static LIKE literal.
    $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM {$t} WHERE setting_name LIKE 'rule\\_\\_%'", ARRAY_A);
    $rows_failed = ($wpdb->last_error !== ''); // captured NOW: the next query resets last_error
    $rules_for_export = devdredi_get_rules();
    if ($rows_failed || devdredi_rules_index_unreadable()) {
        wp_die(esc_html__('The rules could not be read (database error); no export was produced. Try again.', 'devdome-redirect-manager'));
    }
    $settings = array();
    foreach ((array) $rows as $r) {
        $parts = explode('__', $r['setting_name'], 3); // rule | <id> | <suffix>
        if (count($parts) < 3 || $parts[0] !== 'rule') { continue; }
        if ($parts[2] === '' || in_array($parts[2], $excluded, true)) { continue; }
        $settings[$r['setting_name']] = $r['setting_value'];
    }
    $payload = array(
        'plugin'    => 'devdome-redirect-manager',
        'version'   => 1,
        'rules'     => $rules_for_export,
        'settings'  => $settings,
    );
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="devdome-redirect-manager-' . gmdate('Ymd-His') . '.json"');
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});

// Import (replace-all): wipe this site's rules + config, load the file's rules. Rules arrive stopped
// (plugin_state is never imported). Object-injection guard: serialized-object values are skipped.
add_action('wp_ajax_devdredi_import', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
    check_ajax_referer('devdredi_io', '_n');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON import payload; validated + each value sanitized below.
    $raw = (isset($_POST['payload']) && is_string($_POST['payload'])) ? wp_unslash($_POST['payload']) : '';
    if (strlen($raw) > 8 * MB_IN_BYTES) { wp_send_json_error('the file is larger than 8 MB; not a settings export'); }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['rules']) || !is_array($data['rules']) || !isset($data['settings']) || !is_array($data['settings'])) {
        wp_send_json_error('not a valid settings file');
    }

    $rules = array();
    $valid_ids = array();
    foreach ($data['rules'] as $r) {
        if (!is_array($r) || empty($r['id'])) { continue; }
        $id = sanitize_key($r['id']);
        if ($id === '') { continue; }
        if (isset($valid_ids[$id])) { wp_send_json_error('duplicate rule id in file: ' . $id); } // two rows would share one rule's settings
        $rules[] = array(
            'id'       => $id,
            'nickname' => isset($r['nickname']) ? sanitize_text_field($r['nickname']) : '',
            'priority' => isset($r['priority']) ? (int) $r['priority'] : (count($rules) + 1),
        );
        $valid_ids[$id] = true;
    }
    if (empty($rules)) { wp_send_json_error('no rules in file'); }
    // The stored order IS the priority: sort by the file's priority, then number 1..n so both agree.
    usort($rules, function ($a, $b) { return $a['priority'] <=> $b['priority']; });
    foreach ($rules as $i => &$rr) { $rr['priority'] = $i + 1; }
    unset($rr);

    global $wpdb;
    $t = $wpdb->prefix . 'devdredi_settings';

    // Validate and clean EVERYTHING first; nothing is deleted before the whole file is known to be usable.
    $excluded = devdredi_io_excluded_suffixes();
    $allowed  = devdredi_rule_editable_defaults(); // the only setting names an import may write
    $allowed['custom_domains'] = array(); // portable too (a serialized list, accepted only as such)
    $writes   = array();
    foreach ($data['settings'] as $name => $val) {
        if (!is_string($name) || !is_scalar($val)) { continue; }
        $val = (string) $val;
        if (preg_match('/(^|;)[OC]:\d+:"/i', $val)) { continue; } // block serialized objects: O: AND C: (Serializable) tokens
        $parts = explode('__', $name, 3);
        if (count($parts) < 3 || $parts[0] !== 'rule') { continue; }
        $rid = sanitize_key($parts[1]);
        $key = $parts[2];
        if (!isset($valid_ids[$rid]) || $key === '' || in_array($key, $excluded, true)) { continue; }
        // Allowlist + per-setting sanitization: the file only gets to write settings this
        // version actually has, and each value is cleaned for the type that setting stores.
        if (!array_key_exists($key, $allowed)) { continue; }
        $clean = devdredi_sanitize_imported_setting($key, $val, $allowed[$key]);
        if ($clean === null) { continue; }
        $writes['rule__' . $rid . '__' . $key] = $clean;
    }
    // Picked categories and archives cover their posts on THIS site: resolve the groups here, never trust the file's.
    foreach (array_keys($valid_ids) as $rid) {
        $sel = isset($writes['rule__' . $rid . '__selected_links_list']) ? (string) $writes['rule__' . $rid . '__selected_links_list'] : '';
        $picks = array_values(array_filter(array_map('trim', explode("\n", $sel))));
        $writes['rule__' . $rid . '__selected_links_groups'] = ($picks && function_exists('devdredi_resolve_selected_groups')) ? wp_json_encode(devdredi_resolve_selected_groups($picks)) : '';
    }

    // Replace inside one transaction: the old rules survive any failure in the middle.
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; $t from $wpdb->prefix; fixed LIKE; values prepared by the setting writer.
    // A copy of the old rows is kept in memory: if the storage engine cannot roll back, they are put back by hand.
    $old_rows = $wpdb->get_results("SELECT setting_name, setting_value FROM {$t} WHERE setting_name LIKE 'rule\\_\\_%'", ARRAY_A);
    if ($wpdb->last_error !== '' || devdredi_rules_index_unreadable()) {
        wp_send_json_error('the current rules could not be read (database error); nothing was changed');
    }
    unset($GLOBALS['devdredi_read_failed']);
    $old_index  = devdredi_raw_get_setting('rm_rules', array());
    $old_active = devdredi_raw_get_setting('active_rule', '');
    if (!empty($GLOBALS['devdredi_read_failed'])) {
        wp_send_json_error('the current rule list could not be read (database error); nothing was changed'); // a restore must never put a guess back
    }
    if ($wpdb->query('START TRANSACTION') === false) {
        wp_send_json_error('import failed: the database refused a transaction; nothing was changed');
    }
    $ok = $wpdb->query("DELETE FROM {$t} WHERE setting_name LIKE 'rule\\_\\_%'") !== false; // wipe all per-rule data (config + stats)
    $ok = $ok && devdredi_raw_update_setting('rm_rules', array_values($rules)) !== false;
    $ok = $ok && devdredi_raw_update_setting('active_rule', $rules[0]['id']) !== false;
    foreach ($writes as $name => $clean) {
        if (!$ok) { break; }
        $ok = devdredi_raw_update_setting($name, $clean) !== false;
    }
    if ($ok && $wpdb->query('COMMIT') === false) {
        $ok = false;
    }
    if (!$ok) {
        $wpdb->query('ROLLBACK');
        // Non-transactional storage rolls nothing back: put the old rows back by hand and say what happened.
        // The exact old row SET (names and values), not a count: an equal-sized replacement is not "unchanged".
        $old_map = array();
        foreach ((array) $old_rows as $row) { $old_map[$row['setting_name']] = $row['setting_value']; }
        $read_now = function () use ($wpdb, $t) {
            $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM {$t} WHERE setting_name LIKE 'rule\\_\\_%'", ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($rows)) { return null; }
            $map = array();
            foreach ($rows as $row) { $map[$row['setting_name']] = $row['setting_value']; }
            ksort($map);
            return $map;
        };
        ksort($old_map);
        $now_map = $read_now();
        $restored = true;
        if ($now_map !== $old_map || devdredi_raw_get_setting('rm_rules', array()) !== $old_index || devdredi_raw_get_setting('active_rule', '') !== $old_active) {
            // Anything but the exact old row set: wipe whatever landed and put every old row, the index and the pointer back.
            $restored = $wpdb->query("DELETE FROM {$t} WHERE setting_name LIKE 'rule\\_\\_%'") !== false;
            foreach ((array) $old_rows as $row) {
                $restored = $wpdb->insert($t, array('setting_name' => $row['setting_name'], 'setting_value' => $row['setting_value']), array('%s', '%s')) !== false && $restored;
            }
            $restored = devdredi_raw_update_setting('rm_rules', $old_index) !== false && $restored;
            $restored = devdredi_raw_update_setting('active_rule', $old_active) !== false && $restored;
            $restored = $restored && $read_now() === $old_map && devdredi_raw_get_setting('rm_rules', array()) === $old_index && devdredi_raw_get_setting('active_rule', '') === $old_active;
        }
        // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
        wp_send_json_error($restored ? 'import failed: a database write failed, nothing was changed' : 'import failed: a database write failed AND the previous rules could not be fully put back; export your rules from a backup and check the rule list');
    }
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB

    wp_send_json_success(array('rules' => count($rules)));
});

/**
 * Clean one imported setting value against the canonical default for that key. The default's
 * TYPE decides the treatment, so a setting added to devdredi_rule_editable_defaults() later is
 * covered automatically. Returns null when the value cannot be trusted (caller skips it).
 */
function devdredi_sanitize_imported_setting($key, $raw, $default)
{
    // Array settings (run_weekdays, specific_times) round-trip through the table as a
    // serialized array. Accept ONLY a serialized array, and never instantiate a class from it.
    if (is_array($default)) {
        if (!is_string($raw)) { return null; }
        $arr = @unserialize($raw, array('allowed_classes' => false));
        return is_array($arr) ? devdredi_sanitize_scalar_tree($arr) : null;
    }
    if (is_int($default))   { return (int) $raw; }
    if (is_float($default)) { return (float) $raw; }

    $raw = (string) $raw;
    if ($key === 'fallback_url') {
        return esc_url_raw($raw, array('http', 'https'));
    }
    if ($key === 'selected_links_meta') {
        // Per-item metadata travels as a JSON blob: decode, clean every scalar, re-encode.
        $meta = json_decode($raw, true);
        return is_array($meta) ? (string) wp_json_encode(devdredi_sanitize_scalar_tree($meta)) : '';
    }
    // Multi-line lists must keep their newlines; every other setting is a single-line value.
    $multiline = array('links_list', 'custom_links_list', 'selected_links_list', 'page_links_contains', 'referrer_list');
    return in_array($key, $multiline, true) ? sanitize_textarea_field($raw) : sanitize_text_field($raw);
}

/** Recursively clean an imported array down to sanitized scalars. Anything else is dropped. */
function devdredi_sanitize_scalar_tree($value, $depth = 0)
{
    if (is_array($value)) {
        if ($depth > 4) { return array(); } // imported settings are shallow; stop runaway nesting
        $out = array();
        foreach ($value as $k => $v) {
            $clean = devdredi_sanitize_scalar_tree($v, $depth + 1);
            if ($clean === null) { continue; }
            $out[is_int($k) ? $k : sanitize_key($k)] = $clean;
        }
        return $out;
    }
    if (is_bool($value) || is_int($value) || is_float($value)) { return $value; }
    if (is_string($value)) { return sanitize_text_field($value); }
    return null; // objects, resources and null are never stored
}


function devdredi_enqueue_assets($hook)
{
    if (strpos($hook, 'devdome-redirect-manager') === false) {
        return;
    }
    $css_path = DEVDREDI_DIR . 'assets/devdome-tools-tw.css';
    $ver = file_exists($css_path) ? filemtime($css_path) : DEVDREDI_VERSION;
    wp_enqueue_style('devdredi-tools-ui', DEVDREDI_URL . 'assets/devdome-tools-tw.css', array(), $ver);

    $admin_css = DEVDREDI_DIR . 'assets/admin.css';
    wp_enqueue_style(
        'devdredi-admin',
        DEVDREDI_URL . 'assets/admin.css',
        array('devdredi-tools-ui'),
        file_exists($admin_css) ? filemtime($admin_css) : DEVDREDI_VERSION
    );

    // Handle that carries the settings screen's JavaScript. It has no file of its own: the
    // page builds its script from the rule being edited, so every piece is attached with
    // wp_add_inline_script() (see devdredi_admin_inline_js) instead of being written into
    // the markup as a <script> block. Registered for the footer so the DOM exists first.
    wp_register_script('devdredi-admin', false, array(), DEVDREDI_VERSION, true);
    wp_enqueue_script('devdredi-admin');
}
add_action('admin_enqueue_scripts', 'devdredi_enqueue_assets');

/**
 * Attach a piece of the settings screen's JavaScript to the enqueued admin handle.
 * Call sites buffer their JS (ob_start / ob_get_clean) so the surrounding PHP that builds
 * it is untouched; the pieces print together, in order, in the admin footer.
 */
function devdredi_admin_inline_js($js)
{
    $js = trim((string) $js);
    if ($js === '') {
        return;
    }
    // Registered here as well as in devdredi_enqueue_assets(): wp_add_inline_script() silently
    // drops the script if the handle is unknown, and losing the settings screen's JavaScript
    // that way would be invisible. This makes the attach self-sufficient.
    if (!wp_script_is('devdredi-admin', 'registered')) {
        wp_register_script('devdredi-admin', false, array(), DEVDREDI_VERSION, true);
    }
    wp_enqueue_script('devdredi-admin');
    wp_add_inline_script('devdredi-admin', $js);
}

/**
 * Buffer the JavaScript printed by $print and attach it to the admin handle.
 * ob_start() and ob_get_clean() are paired inside this one function scope, in a
 * try/finally, so the buffer is always closed here and can never be left open.
 */
function devdredi_admin_js_capture($print)
{
    ob_start();
    try {
        $print();
    } finally {
        devdredi_admin_inline_js(ob_get_clean());
    }
}
add_action('admin_init', 'devdredi_handle_rule_actions');
function devdredi_handle_rule_actions()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!isset($_GET['page']) || $_GET['page'] !== 'devdome-redirect-manager') {
        return;
    }

    $rules = devdredi_get_rules();
    $ids = array_map(function ($r) { return $r['id']; }, $rules);
    if (devdredi_rules_index_unreadable()) {
        if (wp_doing_ajax()) { wp_send_json_error('the rule list could not be read (database error); nothing was changed'); }
        wp_die(esc_html__('The rule list could not be read (database error); nothing was changed.', 'devdome-redirect-manager'));
    }

    // Every branch below writes to the settings table, so the nonce is required up front.
    $nonce_ok = isset($_GET['_rmn'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_rmn'])), 'devdredi_rule_action');

    // Switch which rule is being edited. It only moves a pointer, but it IS a write, so it
    // is nonce-checked like the rest (the no-JS fallback for the rule switcher).
    if (isset($_GET['rule'])) {
        if (!$nonce_ok) {
            return;
        }
        $rid = sanitize_text_field(wp_unslash($_GET['rule']));
        if (in_array($rid, $ids, true)) {
            if (devdredi_raw_update_setting('active_rule', $rid) === false) {
                wp_die(esc_html__('The active rule could not be switched (database error); nothing was changed.', 'devdome-redirect-manager'));
            }
        }
    }

    if (!isset($_GET['rm_rule_action'])) {
        return;
    }
    if (!$nonce_ok) {
        return;
    }
    $action = sanitize_text_field(wp_unslash($_GET['rm_rule_action']));
    $target = isset($_GET['rm_rule_id']) ? sanitize_text_field(wp_unslash($_GET['rm_rule_id'])) : '';

    if ($action === 'add') {
        $newid = devdredi_new_rule_id($ids);
        $rules_before = $rules;
        $rules[] = array('id' => $newid, 'nickname' => '', 'priority' => count($rules) + 1);
        if (!devdredi_save_rules($rules)) {
            wp_die(esc_html__('The new rule could not be saved (database error); nothing was added.', 'devdome-redirect-manager'));
        }
        if (devdredi_raw_update_setting('active_rule', $newid) === false) {
            wp_die(devdredi_save_rules($rules_before) ? esc_html__('The new rule could not be saved (database error); nothing was added.', 'devdome-redirect-manager') : esc_html__('The new rule was listed but could not be made active AND could not be removed again (database error); check the rule list.', 'devdome-redirect-manager'));
        }
        } elseif ($action === 'duplicate' && in_array($target, $ids, true)) {
        $newid = devdredi_new_rule_id($ids);
        $nick = 'Rule ' . (count($rules) + 1);
        foreach ($rules as $r) {
            if ($r['id'] === $target) {
                $nick = (!empty($r['nickname']) ? $r['nickname'] : 'Rule') . ' copy';
            }
        }
        $rules[] = array('id' => $newid, 'nickname' => $nick, 'priority' => count($rules) + 1);
        // Every write checked: a half-copied rule is removed again, never listed.
        if (!devdredi_copy_rule_settings($target, $newid) || !devdredi_save_rules($rules)) {
            $undone = devdredi_delete_rule_settings($newid);
            $undone = devdredi_save_rules(array_values(array_filter($rules, function ($r) use ($newid) { return $r['id'] !== $newid; }))) && $undone;
            wp_die($undone ? esc_html__('The copy could not be written completely (database error); nothing was created.', 'devdome-redirect-manager') : esc_html__('The copy could not be written completely AND could not be removed again (database error); check the rule list.', 'devdome-redirect-manager'));
        }
        if (devdredi_raw_update_setting('active_rule', $newid) === false) {
            wp_die(esc_html__('The copy was created but could not be made active (database error); open the rule list again.', 'devdome-redirect-manager'));
        }
    } elseif ($action === 'delete' && in_array($target, $ids, true) && count($rules) > 1) {
        $before = $rules;
        $rules = array_values(array_filter($rules, function ($r) use ($target) { return $r['id'] !== $target; }));
        foreach ($rules as $i => &$r) {
            $r['priority'] = $i + 1;
        }
        unset($r);
        // Index first, rows second; a failure at either step leaves the rule whole and listed.
        if (!devdredi_save_rules($rules)) {
            wp_die(esc_html__('The rule list could not be written (database error); nothing was deleted.', 'devdome-redirect-manager'));
        }
        if (!devdredi_delete_rule_settings($target)) {
            wp_die(devdredi_save_rules($before) ? esc_html__('The rule settings could not be deleted (database error); the rule was kept.', 'devdome-redirect-manager') : esc_html__('The rule settings could not be deleted AND the rule list could not be put back (database error); reload and check the rule list.', 'devdome-redirect-manager'));
        }
        if (devdredi_raw_get_setting('active_rule', '') === $target) {
            if (devdredi_raw_update_setting('active_rule', $rules[0]['id']) === false) {
                wp_die(esc_html__('The rule was deleted, but the active-rule pointer could not be moved (database error); open the rule list again.', 'devdome-redirect-manager'));
            }
        }
    } elseif ($action === 'resetstats' && in_array($target, $ids, true)) {
        $prev = isset($GLOBALS['devdredi_rule']) ? $GLOBALS['devdredi_rule'] : null;
        $GLOBALS['devdredi_rule'] = $target;
        $reset_ok = devdredi_reset_stats();
        $GLOBALS['devdredi_rule'] = $prev;
        if ($reset_ok === false) {
            wp_die(esc_html__('Not every counter could be cleared (database error); the statistics were not reset.', 'devdome-redirect-manager'));
        }
    }

    // Clean URL so the action doesn't re-run on refresh.
    wp_safe_redirect(admin_url('admin.php?page=devdome-redirect-manager'));
    exit;
}

/** The rule index must be readable before the save block writes anything: settings are scoped to the active rule id. */
function devdredi_admin_index_readable()
{
    if (!empty($GLOBALS['devdredi_write_failed'])) {
        echo '<div class="error"><p>' . esc_html__('The active rule could not be switched (database error); nothing was saved. Reload and try again.', 'devdome-redirect-manager') . '</p></div>';
        return false;
    }
    $rules = devdredi_get_rules();
    if (devdredi_rules_index_unreadable()) {
        $GLOBALS['devdredi_read_failed'] = true;
        echo '<div class="error"><p>' . esc_html__('The rule list could not be read (database error); nothing was saved. Reload and try again.', 'devdome-redirect-manager') . '</p></div>';
        return false;
    }
    return $rules; // the verified index: the save block writes THIS, never a second read
}

function devdredi_settings_page()
{
    devdredi_check_status_and_schedule();

    // The client-side rule switcher posts the rule being edited so Save/Run/Stop/Reset target the
    // viewed rule even if active_rule in the DB lags behind (e.g. switched without a reload).
    if (isset($_POST['rm_editing_rule']) && isset($_POST['devdredi_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['devdredi_nonce'])), 'devdredi_save_settings')) {
        $edit_id = sanitize_text_field(wp_unslash($_POST['rm_editing_rule']));
        $valid_ids = array_map(function ($r) { return $r['id']; }, devdredi_get_rules());
        if (!devdredi_rules_index_unreadable() && in_array($edit_id, $valid_ids, true)) {
            if (devdredi_raw_update_setting('active_rule', $edit_id) === false) {
                $GLOBALS['devdredi_write_failed'] = true; // the save block refuses: scoped writes would land on the stale rule
                $GLOBALS['devdredi_switch_failed'] = true; // Stop and Reset refuse as well
            }
        }
    }

    $Post = false;
    
    // A purge-only POST (devdredi_purge_cache) never enters the save block: with the form fields absent it would reset the rule.
    if (isset($_POST['devdredi_save']) || isset($_POST['devdredi_run'])) {
        
        if (isset($_POST['devdredi_nonce'])) {
            $nonce_check = check_admin_referer('devdredi_save_settings', 'devdredi_nonce');
            if (!$nonce_check) {
                echo '<div class="error"><p><strong>Security Error:</strong> Invalid request. Please try again.</p></div>';
                $Post = false;
            } else {
                $Post = true;
            }
        } else {
            echo '<div class="error"><p><strong>Security Error:</strong> Missing security token. Please try again.</p></div>';
            $Post = false;
        }
    }


    if ($Post && ($rm_rules_verified = devdredi_admin_index_readable()) !== false) {

        // Rule metadata: nickname of the active rule + drag order (priorities).
        $rm_rules_save = $rm_rules_verified;
        $rm_active_save = devdredi_raw_get_setting('active_rule', $rm_rules_save[0]['id']);
        if (isset($_POST['rule_nickname'])) {
            $nick = sanitize_text_field(wp_unslash($_POST['rule_nickname']));
            foreach ($rm_rules_save as &$r) {
                if ($r['id'] === $rm_active_save) { $r['nickname'] = $nick; }
            }
            unset($r);
        }
        if (!empty($_POST['rule_order'])) {
            $rule_order_raw = sanitize_text_field(wp_unslash($_POST['rule_order']));
            $order = array_filter(array_map('trim', explode(',', $rule_order_raw)));
            if (!empty($order)) {
                $by_id = array();
                foreach ($rm_rules_save as $r) { $by_id[$r['id']] = $r; }
                $reordered = array();
                foreach ($order as $oid) {
                    if (isset($by_id[$oid])) { $reordered[] = $by_id[$oid]; unset($by_id[$oid]); }
                }
                foreach ($by_id as $r) { $reordered[] = $r; }
                foreach ($reordered as $i => &$r) { $r['priority'] = $i + 1; }
                unset($r);
                $rm_rules_save = $reordered;
            }
        }
        do { // one pass: a failed index write leaves this block before any scoped setting is written
        if (!devdredi_save_rules($rm_rules_save)) {
            $GLOBALS['devdredi_write_failed'] = true; // the notice below reports it; Run stays blocked
            break;
        }

        $old_run_mode = devdredi_get_setting('run_mode', 'unlimited');
        $run_mode = isset($_POST['run_mode']) ? sanitize_text_field(wp_unslash($_POST['run_mode'])) : 'unlimited';
        if (!in_array($run_mode, array('unlimited', 'set_time'), true)) {
            $run_mode = 'unlimited';
        }
        devdredi_update_setting('run_mode', $run_mode);

        $schedule_timezone = isset($_POST['schedule_timezone']) ? sanitize_text_field(wp_unslash($_POST['schedule_timezone'])) : '';
        if ($schedule_timezone !== '' && !in_array($schedule_timezone, timezone_identifiers_list(), true)) {
            $schedule_timezone = '';
        }
        devdredi_update_setting('schedule_timezone', $schedule_timezone);

        // Campaign date range (optional). Keep only valid YYYY-MM-DD, else blank.
        foreach (array('schedule_start_date', 'schedule_end_date') as $dk) {
            $dv = isset($_POST[$dk]) ? sanitize_text_field(wp_unslash($_POST[$dk])) : '';
            if ($dv !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dv)) { $dv = ''; }
            devdredi_update_setting($dk, $dv);
        }

        $plugin_state = devdredi_get_setting('plugin_state', 'stopped');
        $start_time = (int) devdredi_get_setting('start_time', 0);
        if ($plugin_state === 'running' && $start_time === 0) {
            devdredi_update_setting('start_time', current_time('timestamp'));
        }


        $geo_filter_enabled = isset($_POST['geo_filter_enabled']) ? 1 : 0;
        devdredi_update_setting('geo_filter_enabled', $geo_filter_enabled);

        $geo_filter_mode = (isset($_POST['geo_filter_mode']) && $_POST['geo_filter_mode'] === 'blacklist') ? 'blacklist' : 'whitelist';
        devdredi_update_setting('geo_filter_mode', $geo_filter_mode);

        $geo_filter_whitelist = isset($_POST['geo_filter_whitelist']) ? sanitize_text_field(wp_unslash($_POST['geo_filter_whitelist'])) : '';
        devdredi_update_setting('geo_filter_whitelist', $geo_filter_whitelist);
        $geo_filter_blacklist = isset($_POST['geo_filter_blacklist']) ? sanitize_text_field(wp_unslash($_POST['geo_filter_blacklist'])) : '';
        devdredi_update_setting('geo_filter_blacklist', $geo_filter_blacklist);

        $trust_proxy = isset($_POST['trust_proxy']) ? 1 : 0;
        devdredi_update_setting('trust_proxy', $trust_proxy);

        $links_mode = isset($_POST['links_mode']) ? sanitize_text_field(wp_unslash($_POST['links_mode'])) : 'sequential';
        if (!in_array($links_mode, ['sequential', 'random', 'descending', 'skip_domain'], true)) {
            $links_mode = 'sequential';
        }
        devdredi_update_setting('links_mode', $links_mode);

        $links_repeat = isset($_POST['links_repeat']) ? 1 : 0;
        devdredi_update_setting('links_repeat', $links_repeat);

        $descending_spread = isset($_POST['descending_spread']) ? floatval($_POST['descending_spread']) : 0.5;
        if ($descending_spread < 0) { $descending_spread = 0.0; }
        if ($descending_spread > 1) { $descending_spread = 1.0; }
        devdredi_update_setting('descending_spread', $descending_spread);

        $descending_seed = isset($_POST['descending_seed']) ? intval($_POST['descending_seed']) : 0;
        devdredi_update_setting('descending_seed', $descending_seed);

        $what_to_redirect = isset($_POST['what_to_redirect']) ? sanitize_text_field(wp_unslash($_POST['what_to_redirect'])) : 'entire_website';
        if (!in_array($what_to_redirect, array('entire_website', 'selected_existing', 'custom_urls', 'referrer', 'all_404'), true)) {
            $what_to_redirect = 'entire_website';
        }
        devdredi_update_setting('what_to_redirect', $what_to_redirect);

        // Referring websites (1.5.0): one entry per line, cleaned to a host or a word (engine devdredi_referrer_list).
        $referrer_raw = isset($_POST['referrer_list']) ? sanitize_textarea_field(wp_unslash($_POST['referrer_list'])) : '';
        devdredi_update_setting('referrer_list', implode("\n", devdredi_referrer_list($referrer_raw)));
        // "Only on selected pages": the picked URLs are the URLs To Redirect picker's list (selected_links_list).
        devdredi_update_setting('referrer_only_selected', isset($_POST['referrer_only_selected']) ? 1 : 0);
        devdredi_update_setting('outside_only', isset($_POST['outside_only']) ? 1 : 0);

        $redirect_type = isset($_POST['redirect_type']) ? sanitize_text_field(wp_unslash($_POST['redirect_type'])) : 'js';
        if (!in_array($redirect_type, array('301', '302', '307', '308', 'js', 'meta'), true)) {
            $redirect_type = 'js';
        }
        devdredi_update_setting('redirect_type', $redirect_type);

        if (isset($_POST['custom_domains_list'])) {
            $cd_list_raw = trim(sanitize_textarea_field(wp_unslash($_POST['custom_domains_list'])));
            devdredi_set_custom_domains($cd_list_raw);
        } else {
        }
        $links_list_raw = isset($_POST['links_list']) ? trim(sanitize_textarea_field(wp_unslash($_POST['links_list']))) : '';
        $links_array = array_filter(array_map('trim', explode("\n", $links_list_raw)));
        $links_list = implode("\n", $links_array);
        devdredi_update_setting('links_list', $links_list);

        $selected_list_raw = isset($_POST['selected_links_list']) ? trim(sanitize_textarea_field(wp_unslash($_POST['selected_links_list']))) : '';
        $selected_array = array_filter(array_map('trim', explode("\n", $selected_list_raw)));
        $selected_links_list = implode("\n", $selected_array);
        devdredi_update_setting('selected_links_list', $selected_links_list);
        // The categories and archives behind the picks, so the engine also redirects the posts and products in them.
        devdredi_update_setting('selected_links_groups', wp_json_encode(devdredi_resolve_selected_groups($selected_array)));

        // JSON blob from the picker. json_decode does NOT sanitize, so nothing from it is stored
        // directly: only the three known fields are kept, each cleaned for its own context below.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON container; every field it yields is sanitized below before storage.
        $meta_raw = isset($_POST['selected_links_meta']) ? wp_unslash($_POST['selected_links_meta']) : '';
        $meta_decoded = (is_string($meta_raw) && strlen($meta_raw) <= 512000) ? json_decode($meta_raw, true) : null;
        $meta_clean = array();
        if (is_array($meta_decoded)) {
            foreach ($meta_decoded as $row) {
                if (!is_array($row)) { continue; }
                $meta_clean[] = array(
                    'label' => isset($row['label']) ? sanitize_text_field($row['label']) : '',
                    'url'   => isset($row['url']) ? esc_url_raw($row['url']) : '',
                    'type'  => isset($row['type']) ? sanitize_key($row['type']) : '',
                );
            }
        }
        devdredi_update_setting('selected_links_meta', wp_json_encode($meta_clean));

        $custom_list_raw = isset($_POST['custom_links_list']) ? trim(sanitize_textarea_field(wp_unslash($_POST['custom_links_list']))) : '';
        $custom_array = array_filter(array_map('devdredi_normalize_path', array_map('trim', explode("\n", $custom_list_raw))));
        $custom_links_list = implode("\n", array_unique($custom_array));
        devdredi_update_setting('custom_links_list', $custom_links_list);

        $transit_domain = isset($_POST['transit_domain']) ? strtolower(trim(sanitize_text_field(wp_unslash($_POST['transit_domain'])))) : '';
        $transit_domain = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $transit_domain);
        $transit_domain = preg_replace('#^www\.#i', '', $transit_domain);
        $transit_domain = preg_replace('~[/?\#].*$~', '', $transit_domain);
        if ($transit_domain !== '' && !preg_match('/^([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $transit_domain)) {
            $transit_domain = '';
        }
        devdredi_update_setting('transit_domain', $transit_domain);

        $redirect_source = isset($_POST['redirect_source']) ? sanitize_text_field(wp_unslash($_POST['redirect_source'])) : 'provided';
        if (!in_array($redirect_source, array('provided', 'found', 'transit'), true)) { $redirect_source = 'provided'; }
        devdredi_update_setting('redirect_source', $redirect_source);
        devdredi_update_setting('page_links_contains', isset($_POST['page_links_contains']) ? sanitize_textarea_field(wp_unslash($_POST['page_links_contains'])) : '');



        $open_mode = (isset($_POST['open_mode']) && $_POST['open_mode'] === 'new_tab') ? 'new_tab' : 'same_tab';

        // Only JS redirects can open a new tab; every server/meta method (301/302/307/308/meta) is
        // forced to same-tab by the engine. Store that here too (was only 301/302) so the saved
        // value matches reality instead of leaving a "New Tab" that silently reverts on the next load.
        if ($redirect_type !== 'js') {
            $open_mode = 'same_tab';
        }

        devdredi_update_setting('open_mode', $open_mode);

        $same_tab_delay_min = isset($_POST['same_tab_delay_min']) ? max(0, floatval($_POST['same_tab_delay_min'])) : 0;
        $same_tab_delay_max = isset($_POST['same_tab_delay_max']) ? max(0, floatval($_POST['same_tab_delay_max'])) : 0;
        devdredi_update_setting('same_tab_delay_min', $same_tab_delay_min);
        devdredi_update_setting('same_tab_delay_max', $same_tab_delay_max);

        $new_tab_delay_min = isset($_POST['new_tab_delay_min']) ? max(0, floatval($_POST['new_tab_delay_min'])) : 0;
        $new_tab_delay_max = isset($_POST['new_tab_delay_max']) ? max(0, floatval($_POST['new_tab_delay_max'])) : 0;
        devdredi_update_setting('new_tab_delay_min', $new_tab_delay_min);
        devdredi_update_setting('new_tab_delay_max', $new_tab_delay_max);

        // After Click Delay (new tab): optional, random range, each value capped at 4s
        // (longer delays get blocked by the browser pop-up blocker).
        $after_click_enabled = isset($_POST['after_click_enabled']) ? 1 : 0;
        $after_click_min = isset($_POST['after_click_min']) ? max(0, floatval($_POST['after_click_min'])) : 0;
        $after_click_max = isset($_POST['after_click_max']) ? max(0, floatval($_POST['after_click_max'])) : 0;
        $after_click_min = max(0, min(4, $after_click_min));
        $after_click_max = max(0, min(4, $after_click_max));
        if ($after_click_max < $after_click_min) $after_click_max = $after_click_min;
        devdredi_update_setting('after_click_enabled', $after_click_enabled);
        devdredi_update_setting('after_click_min', $after_click_min);
        devdredi_update_setting('after_click_max', $after_click_max);


        
        $same_tab_require_click = isset($_POST['same_tab_require_click']) ? 1 : 0;
        devdredi_update_setting('same_tab_require_click', $same_tab_require_click);

        // After Click Delay (same tab): optional, random range, each value capped at 4s
        // (kept consistent with the new-tab cap).
        $same_tab_after_click_enabled = isset($_POST['same_tab_after_click_enabled']) ? 1 : 0;
        $same_tab_after_click_min = isset($_POST['same_tab_after_click_min']) ? max(0, floatval($_POST['same_tab_after_click_min'])) : 0;
        $same_tab_after_click_max = isset($_POST['same_tab_after_click_max']) ? max(0, floatval($_POST['same_tab_after_click_max'])) : 0;
        $same_tab_after_click_min = max(0, min(4, $same_tab_after_click_min));
        $same_tab_after_click_max = max(0, min(4, $same_tab_after_click_max));
        if ($same_tab_after_click_max < $same_tab_after_click_min) $same_tab_after_click_max = $same_tab_after_click_min;
        devdredi_update_setting('same_tab_after_click_enabled', $same_tab_after_click_enabled);
        devdredi_update_setting('same_tab_after_click_min', $same_tab_after_click_min);
        devdredi_update_setting('same_tab_after_click_max', $same_tab_after_click_max);


        $old_runtime_minutes = (int) devdredi_get_setting('runtime_minutes', 0);
        $run_time_days = isset($_POST['run_time_days']) ? max(0, intval($_POST['run_time_days'])) : 0;
        $run_time_hours = isset($_POST['run_time_hours']) ? max(0, intval($_POST['run_time_hours'])) : 0;
        $run_time_minutes = isset($_POST['run_time_minutes']) ? max(0, intval($_POST['run_time_minutes'])) : 0;
        $total_minutes = ($run_time_days * 24 * 60) + ($run_time_hours * 60) + $run_time_minutes;
        devdredi_update_setting('runtime_minutes', $total_minutes);

        if ($total_minutes !== $old_runtime_minutes || $run_mode !== $old_run_mode) {
            devdredi_update_setting('start_time', current_time('timestamp'));
        }

        $weekdays = [];
        for ($i = 1; $i <= 7; $i++) {
            if (!empty($_POST['weekday_' . $i])) {
                $weekdays[] = $i;
            }
        }
        devdredi_update_setting('run_weekdays', maybe_serialize($weekdays));

        $specific_times = [];
        for ($i = 1; $i <= 3; $i++) {
            $enabled = !empty($_POST["specific_time_{$i}_enable"]);
            $start = isset($_POST["specific_time_{$i}_start"]) ? sanitize_text_field(wp_unslash($_POST["specific_time_{$i}_start"])) : '00:00';
            $end = isset($_POST["specific_time_{$i}_end"]) ? sanitize_text_field(wp_unslash($_POST["specific_time_{$i}_end"])) : '23:59';
            $specific_times[] = [
                'enable' => $enabled ? 1 : 0,
                'start' => $start,
                'end' => $end,
            ];
        }
        devdredi_update_setting('specific_times', maybe_serialize($specific_times));

        $run_once = isset($_POST['run_once']) ? sanitize_text_field(wp_unslash($_POST['run_once'])) : 'never';
        if (!in_array($run_once, array('never', 'ip', 'ip_ua'), true)) {
            $run_once = 'never';
        }

        $open_on_every = isset($_POST['open_on_every']) ? intval($_POST['open_on_every']) : 1;
        if ($open_on_every < 1) $open_on_every = 1;

        $revisit_delay_raw = isset($_POST['revisit_delay']) ? intval($_POST['revisit_delay']) : 0;
        if ($revisit_delay_raw < 0) $revisit_delay_raw = 0;
        $revisit_delay_unit = isset($_POST['revisit_delay_unit']) ? sanitize_text_field(wp_unslash($_POST['revisit_delay_unit'])) : 'minutes';
        if (!in_array($revisit_delay_unit, array('minutes', 'hours', 'days'), true)) {
            $revisit_delay_unit = 'minutes';
        }
        $revisit_unit_mult = ($revisit_delay_unit === 'days') ? 1440 : (($revisit_delay_unit === 'hours') ? 60 : 1);
        $revisit_delay = $revisit_delay_raw * $revisit_unit_mult; // stored in minutes for the engine

        if ($run_once === 'never') {
            $open_on_every = 1;
            $revisit_delay = 0;
        }

        devdredi_update_setting('run_once', $run_once);
        devdredi_update_setting('open_on_every', $open_on_every);
        devdredi_update_setting('revisit_delay', $revisit_delay);
        devdredi_update_setting('revisit_delay_unit', $revisit_delay_unit);
        devdredi_update_setting('skip_bots', isset($_POST['skip_bots']) ? 1 : 0);

        foreach (array('device_desktop', 'device_mobile', 'device_tablet') as $dk) {
            devdredi_update_setting($dk, isset($_POST[$dk]) ? 1 : 0);
        }

        devdredi_update_setting('purge_cache_on_save', isset($_POST['purge_cache_on_save']) ? 1 : 0);

        $fallback_mode = isset($_POST['fallback_mode']) ? sanitize_text_field(wp_unslash($_POST['fallback_mode'])) : 'leave';
        if (!in_array($fallback_mode, array('leave', 'send'), true)) {
            $fallback_mode = 'leave';
        }
        devdredi_update_setting('fallback_mode', $fallback_mode);

        $fallback_url = isset($_POST['fallback_url']) ? esc_url_raw(wp_unslash($_POST['fallback_url']), array('http', 'https')) : '';
        devdredi_update_setting('fallback_url', $fallback_url);

        } while (false);
        if (!empty($GLOBALS['devdredi_write_failed']) || !empty($GLOBALS['devdredi_read_failed'])) {
            echo '<div class="error"><p>' . esc_html__('The settings could not be saved completely (database error). Reload the rule and check its settings before running it.', 'devdome-redirect-manager') . '</p></div>';
        } else {
            echo '<div class="updated"><p>Settings saved!</p></div>';
        }

        devdredi_check_status_and_schedule();

        // Purge third-party page caches so the saved rule isn't masked by a frozen cached copy
        // (also runs when Run posts through this block — starting a rule is exactly the moment
        // a previously rule-free page may still sit in a cache).
        if (devdredi_get_setting('purge_cache_on_save', 1)) {
            $purge_urls = devdredi_rule_target_urls();
            if ($purge_urls === null || !empty($purge_urls)) {
                devdredi_purge_page_caches($purge_urls);
            }
        }
    }

    if (!empty($_POST['devdredi_run'])) {
        check_admin_referer('devdredi_save_settings', 'devdredi_nonce');
        if (!empty($GLOBALS['devdredi_write_failed']) || !empty($GLOBALS['devdredi_read_failed'])) {
            // A half-saved rule must not go live: the error notice above says what to check.
            echo '<div class="error"><p>' . esc_html__('The rule was NOT started because its settings could not be saved completely.', 'devdome-redirect-manager') . '</p></div>';
        } else {
            // The rule id is fixed BEFORE any write: the recovery below must reach the same rule even if a later pointer read fails.
            unset($GLOBALS['devdredi_read_failed']);
            $run_rid = devdredi_active_rule_id();
            if (!empty($GLOBALS['devdredi_read_failed'])) {
                echo '<div class="error"><p>' . esc_html__('The rule was NOT started: the active rule could not be read (database error). Reload and try again.', 'devdome-redirect-manager') . '</p></div>';
            } else {
            $timer_ok = devdredi_raw_update_setting('rule__' . $run_rid . '__start_time', current_time('timestamp')) !== false;
            $state_ok = $timer_ok && devdredi_raw_update_setting('rule__' . $run_rid . '__plugin_state', 'running') !== false;
            $is_running = (string) devdredi_raw_get_setting('rule__' . $run_rid . '__plugin_state', 'stopped') === 'running';
            $has_start  = (int) devdredi_raw_get_setting('rule__' . $run_rid . '__start_time', 0) > 0;
            if (!$state_ok || !$is_running || !$has_start) {
                // Do not leave it half started: a checked, explicit-rule stop, and the notice says what really happened.
                $recovered = devdredi_raw_update_setting('rule__' . $run_rid . '__plugin_state', 'stopped') !== false
                    && (string) devdredi_raw_get_setting('rule__' . $run_rid . '__plugin_state', 'running') === 'stopped';
                echo '<div class="error"><p>' . esc_html($recovered
                    ? __('The rule could NOT be started (database write failed); it is stopped.', 'devdome-redirect-manager')
                    : __('The rule could NOT be started cleanly (database write failed) and its state could not be reset; reload and check whether it shows as running.', 'devdome-redirect-manager')) . '</p></div>';
            }
            }
        }
    }

    if (!empty($_POST['devdredi_stop'])) {
        check_admin_referer('devdredi_save_settings', 'devdredi_nonce');
        if (!empty($GLOBALS['devdredi_switch_failed']) || devdredi_update_setting('plugin_state', 'stopped') === false || (string) devdredi_get_setting('plugin_state', 'running') !== 'stopped') {
            echo '<div class="error"><p>' . esc_html__('The rule could NOT be stopped (database write failed); it is still running.', 'devdome-redirect-manager') . '</p></div>';
        } else {
            // Only a rule that really stopped keeps its remaining run time and loses its start time.
            $start_time = (int) devdredi_get_setting('start_time', 0);
            $runtime_minutes = (int) devdredi_get_setting('runtime_minutes', 0);

            $timer_ok = true;
            if ($start_time > 0 && $runtime_minutes > 0) {
                $now = current_time('timestamp');
                $end_time = $start_time + ($runtime_minutes * 60);
                $time_left_seconds = max($end_time - $now, 0);
                $timer_ok = devdredi_update_setting('runtime_minutes', ceil($time_left_seconds / 60)) !== false;
            }

            $timer_ok = devdredi_update_setting('start_time', 0) !== false && $timer_ok;
            if (!$timer_ok) {
                echo '<div class="error"><p>' . esc_html__('The rule stopped, but its remaining run time could not be saved (database error); check the run time before starting it again.', 'devdome-redirect-manager') . '</p></div>';
            }
        }
    }

    if (!empty($_POST['devdredi_reset_stats'])) {
        check_admin_referer('devdredi_save_settings', 'devdredi_nonce');

        if (!empty($GLOBALS['devdredi_switch_failed']) || devdredi_reset_stats() === false) {
            $GLOBALS['devdredi_write_failed'] = true;
            echo '<div class="error"><p>' . esc_html__('Not every counter could be cleared (database error); the statistics were not reset.', 'devdome-redirect-manager') . '</p></div>';
        }
        // (Rotation options are cleared per-rule inside devdredi_reset_stats(); the old global
        // delete_option() calls here wiped every rule's rotation state.)

        devdredi_purge_plugin_cache();

        if (empty($GLOBALS['devdredi_write_failed'])) {
            echo '<div class="updated"><p>All statistics and history have been reset!</p></div>';
        }
    }

    if (!empty($_POST['devdredi_return'])) {
        check_admin_referer('devdredi_save_settings', 'devdredi_nonce');

        devdredi_update_setting('links_list', '');
        devdredi_update_setting('selected_links_list', '');
        devdredi_update_setting('selected_links_meta', '');
        devdredi_update_setting('selected_links_groups', '');
        devdredi_update_setting('custom_links_list', '');
        devdredi_update_setting('referrer_list', '');
        devdredi_update_setting('referrer_only_selected', 0);

        $default_settings = array(
            'run_mode' => 'unlimited',
            'schedule_timezone' => '',
            'runtime_minutes' => 0,
            'run_once' => 'never',
            'start_time' => 0,
            'visitor_count' => 0,
            'page_view_count' => 0,
            'ip_list' => array(),
            'ua_list' => array(),
            'ip_redirected_once' => array(),
            'last_redirects' => array(),
            'links_mode' => 'sequential',
            'links_repeat' => 1,
            'open_mode' => 'same_tab',
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
            'run_weekdays' => array(1, 2, 3, 4, 5, 6, 7),
            'what_to_redirect' => 'entire_website',
            'outside_only' => 0,
            'redirect_type' => 'js',
            'device_desktop' => 1,
            'device_mobile' => 1,
            'device_tablet' => 1,
            'purge_cache_on_save' => 1,
            'revisit_delay' => 0,
            'skip_bots' => 1,
            'geo_filter_country_codes' => '',
            'geo_filter_mode' => 'whitelist',
            'geo_filter_whitelist' => '',
            'geo_filter_blacklist' => '',
            'fallback_mode' => 'leave',
            'fallback_url' => '',
            'track_redirect_chains' => 0,
            'descending_spread' => 0.5,
            'descending_seed' => 0,
            'user_redirects_count' => 0,
            'specific_times' => array(
                array('enable' => 0, 'start' => '00:00', 'end' => '23:59'),
                array('enable' => 0, 'start' => '00:00', 'end' => '23:59'),
                array('enable' => 0, 'start' => '00:00', 'end' => '23:59'),
            )
        );

        foreach ($default_settings as $name => $value) {
            devdredi_update_setting($name, $value);
        }

    }




    $what_to_redirect = devdredi_get_setting('what_to_redirect', 'entire_website');
    $redirect_type = devdredi_get_setting('redirect_type', 'js');
    
    $links_mode = devdredi_get_setting('links_mode', 'sequential');
    $links_repeat = devdredi_get_setting('links_repeat', 1);
    $links_list = devdredi_get_setting('links_list', '');
    $selected_links_list = devdredi_get_setting('selected_links_list', '');
    $selected_links_meta = devdredi_get_setting('selected_links_meta', '');
    $custom_links_list = devdredi_get_setting('custom_links_list', '');
    $open_on_every = (int) devdredi_get_setting('open_on_every', 1);
    $revisit_delay = (int) devdredi_get_setting('revisit_delay', 0);
    $allowed_countries = devdredi_get_setting('geo_filter_country_codes', '');
    $geo_filter_mode = devdredi_get_setting('geo_filter_mode', 'whitelist');
    // Each mode keeps its own list. Migrate the old shared value into the whitelist on first load.
    $geo_whitelist = devdredi_get_setting('geo_filter_whitelist', $allowed_countries);
    $geo_blacklist = devdredi_get_setting('geo_filter_blacklist', '');
    $descending_spread = (float) devdredi_get_setting('descending_spread', 0.5);
    $descending_seed = (int) devdredi_get_setting('descending_seed', 0);

    $open_mode = devdredi_get_setting('open_mode', 'same_tab');
    $same_tab_delay_min = (float) devdredi_get_setting('same_tab_delay_min', 0);
    $same_tab_delay_max = (float) devdredi_get_setting('same_tab_delay_max', 0);
    $new_tab_delay_min = (float) devdredi_get_setting('new_tab_delay_min', 0);
    $new_tab_delay_max = (float) devdredi_get_setting('new_tab_delay_max', 0);
    $after_click_enabled = (int) devdredi_get_setting('after_click_enabled', 0);
    $after_click_min = (float) devdredi_get_setting('after_click_min', 0);
    $after_click_max = (float) devdredi_get_setting('after_click_max', 0);
    $same_tab_require_click = (int) devdredi_get_setting('same_tab_require_click', 0);
    $same_tab_after_click_enabled = (int) devdredi_get_setting('same_tab_after_click_enabled', 0);
    $same_tab_after_click_min = (float) devdredi_get_setting('same_tab_after_click_min', 0);
    $same_tab_after_click_max = (float) devdredi_get_setting('same_tab_after_click_max', 0);
    $run_once = devdredi_get_setting('run_once', 'ip');
    $skip_bots = (int) devdredi_get_setting('skip_bots', 1);
    $plugin_state = devdredi_get_setting('plugin_state', 'stopped');

    if ($run_once === 'never') {
        $open_on_every = 1;
        $revisit_delay = 0;
    }

    $revisit_delay_unit = devdredi_get_setting('revisit_delay_unit', 'minutes');
    if (!in_array($revisit_delay_unit, array('minutes', 'hours', 'days'), true)) { $revisit_delay_unit = 'minutes'; }
    $revisit_unit_mult = ($revisit_delay_unit === 'days') ? 1440 : (($revisit_delay_unit === 'hours') ? 60 : 1);
    $revisit_delay_value = intval($revisit_delay / $revisit_unit_mult);

    $total_minutes = (int) devdredi_get_setting('runtime_minutes', 0);
    $run_time_days = intdiv($total_minutes, 60 * 24);
    $run_time_hours = intdiv($total_minutes, 60) % 24; // intdiv avoids the PHP 8.1 implicit float->int modulo deprecation
    $run_time_mins = $total_minutes % 60;

    $run_weekdays = maybe_unserialize(devdredi_get_setting('run_weekdays', ''));
    if (!is_array($run_weekdays)) {
        $run_weekdays = [];
    }
    $specific_times = maybe_unserialize(devdredi_get_setting('specific_times', ''));
    if (!is_array($specific_times)) {
        $specific_times = [
            ['enable' => 0, 'start' => '00:00', 'end' => '23:59'],
            ['enable' => 0, 'start' => '00:00', 'end' => '23:59'],
            ['enable' => 0, 'start' => '00:00', 'end' => '23:59']
        ];
    }

    $visitor_count = (int) devdredi_get_setting('visitor_count', 0);
    $ip_list = devdredi_get_setting('ip_list', []);
    if (!is_array($ip_list)) {
        $ip_list = [];
    }
    $unique_ips = count($ip_list);

    $plugin_state = devdredi_get_setting('plugin_state', 'stopped');
    $run_mode = devdredi_get_setting('run_mode', 'unlimited');
    $start_time = (int) devdredi_get_setting('start_time', 0);
    $time_left_seconds = 0;
    
    if ($plugin_state === 'running' && $run_mode === 'set_time') {
        if ($start_time > 0 && $total_minutes > 0) {
            $end_time = $start_time + ($total_minutes * 60);
            $time_left_seconds = max($end_time - current_time('timestamp'), 0);
        }
    }

    $links_count = $links_list ? count(array_filter(explode("\n", $links_list))) : 0;
    $selected_links_count = $selected_links_list ? count(array_filter(explode("\n", $selected_links_list))) : 0;
    $custom_links_count = $custom_links_list ? count(array_filter(explode("\n", $custom_links_list))) : 0;

    ?>
    <div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]">
        <div class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-5xl px-6 py-4 flex items-center gap-3">
            <div class="p-1.5 rounded text-white inline-flex items-center justify-center" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg>
            </div>
            <div>
                <h1 class="text-xl font-bold text-gray-800" style="margin:0;padding:0;line-height:1.25;">DevDome Redirect Manager</h1>
            </div>
            <div style="margin-left:auto;display:flex;align-items:center;gap:4px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=devdome-redirect-manager&rm_export=1&_rmx=' . wp_create_nonce('devdredi_io'))); ?>" class="dd-io-btn" title="Export settings (download all rules to a file)"><span class="dashicons dashicons-download"></span></a>
                <button type="button" id="dd-import-btn" class="dd-io-btn" title="Import settings (replace all rules from a file)"><span class="dashicons dashicons-upload"></span></button>
                <input type="file" id="dd-import-file" accept="application/json,.json" style="display:none;">
                <a class="rm-bug-btn" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-redirect-manager&v=' . (defined('DEVDREDI_VERSION') ? DEVDREDI_VERSION : '')); ?>" target="_blank" rel="noopener noreferrer" title="Report a bug" style="width:36px;height:36px;border-radius:50px;border:1px solid #dadce0;background:#fff;display:grid;place-items:center;color:#5f6368;text-decoration:none;margin-left:6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                </a>
            </div>
            </div>
        </div>
        <?php devdredi_admin_js_capture(function () { ?>
        (function(){
            var btn=document.getElementById('dd-import-btn'), inp=document.getElementById('dd-import-file');
            if(!btn||!inp) return;
            var NONCE=<?php echo wp_json_encode(wp_create_nonce('devdredi_io')); ?>;
            var AJAX=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var BACK=<?php echo wp_json_encode(admin_url('admin.php?page=devdome-redirect-manager')); ?>;
            btn.addEventListener('click',function(){ inp.value=''; inp.click(); });
            inp.addEventListener('change',function(){
                var f=inp.files&&inp.files[0]; if(!f) return;
                var rd=new FileReader();
                rd.onload=function(){
                    if(!confirm('Import settings? This REPLACES every rule on this site with the rules in the file, and deletes the statistics and history of the current rules. Imported rules arrive stopped.')) return;
                    var fd=new FormData(); fd.append('action','devdredi_import'); fd.append('_n',NONCE); fd.append('payload',rd.result);
                    btn.style.opacity='.5';
                    fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(res){
                        if(res&&res.success){ alert('Imported '+((res.data&&res.data.rules)||0)+' rule(s).'); window.location.href=BACK; }
                        else { alert('Import failed: '+((res&&res.data)||'invalid file')); btn.style.opacity=''; }
                    }).catch(function(){ alert('Import failed.'); btn.style.opacity=''; });
                };
                rd.readAsText(f);
            });
        })();
        <?php }); ?>
        <main>
<form method="post" id="devdredi-form">
        <?php devdredi_admin_js_capture(function () { ?>
        window.DEVDREDI_COUNTS = {
            source: <?php echo wp_json_encode((object) devdredi_get_setting('rc_by_source', array())); ?>,
            dest: <?php echo wp_json_encode((object) devdredi_get_setting('rc_by_dest', array())); ?>,
            referrer: <?php echo wp_json_encode((object) devdredi_get_setting('rc_by_referrer', array())); ?>,
            country: <?php echo wp_json_encode((object) devdredi_get_setting('rc_by_country', array())); ?>,
            found: <?php echo wp_json_encode((object) devdredi_get_setting('rc_by_found', array())); ?>
        };
        // Sum referrer counts for a domain: exact host + any subdomain of it.
        window.ddRefCount = function (domain) {
            var m = (window.DEVDREDI_COUNTS && window.DEVDREDI_COUNTS.referrer) || {}, t = 0, suffix = '.' + domain;
            for (var h in m) { if (h === domain || h.slice(-suffix.length) === suffix) { t += (parseInt(m[h], 10) || 0); } }
            return t;
        };
        // Source-page count: normalize a URL/slug to a path (mirrors devdredi_normalize_path) and look it up.
        window.ddSourceCount = function (u) {
            var m = (window.DEVDREDI_COUNTS && window.DEVDREDI_COUNTS.source) || {};
            u = (u || '').trim();
            if (u === '') return 0;
            u = u.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/^\/\//, '').replace(/^www\./i, '');
            if (/^[^\/]*\.[^\/]*\//.test(u) || /^[^\/]*\.[^\/]+$/.test(u)) { var s = u.indexOf('/'); u = (s === -1) ? '' : u.slice(s); }
            u = '/' + u.replace(/^\/+/, ''); if (u === '/') u = '';
            return (m[u] || 0);
        };
        <?php }); ?>
        <?php
        // Per-rule editable settings, preloaded so the rule switcher can repopulate the form
        // client-side with no page reload (form-accurate defaults for new/under-seeded rules).
        $ddrm_rules_data = array();
        foreach (devdredi_get_rules() as $rr2) {
            $vals = devdredi_rule_editable_settings($rr2['id']);
            $vals['nickname'] = isset($rr2['nickname']) ? $rr2['nickname'] : '';
            $ddrm_rules_data[$rr2['id']] = $vals;
        }
        ?>
        <?php devdredi_admin_js_capture(function () use ($ddrm_rules_data) { ?>
        window.DEVDREDI_REINIT = [];
        window.DEVDREDI_RULES = <?php echo wp_json_encode($ddrm_rules_data); ?>;
        <?php }); ?>
        <div class="max-w-5xl px-6 pt-3 pb-6 space-y-8">
            <?php wp_nonce_field('devdredi_save_settings', 'devdredi_nonce'); ?>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-randomize dd-ico"></span>
                    <h2 class="dd-h2">Redirect Rules</h2>
                    <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Rules run from top to bottom. The first matching rule is used, so place your most important rule first. Drag rules to change priority.</span></span>
                </div>
                <div class="dd-card">
                    <?php
                    $rm_rules = devdredi_get_rules();
                    $rm_active = devdredi_raw_get_setting('active_rule', $rm_rules[0]['id']);
                    $rm_nonce = wp_create_nonce('devdredi_rule_action');
                    $rm_base = admin_url('admin.php?page=devdome-redirect-manager');
                    $rm_active_nick = '';
                    $rm_active_pos = 0;
                    foreach ($rm_rules as $idx => $r) { if ($r['id'] === $rm_active) { $rm_active_nick = isset($r['nickname']) ? $r['nickname'] : ''; $rm_active_pos = $idx + 1; } }
                    ?>
                    <input type="hidden" name="rule_nickname" id="dd-rule-nickname" value="<?php echo esc_attr($rm_active_nick); ?>">
                    <input type="hidden" name="rm_editing_rule" id="dd-editing-rule" value="<?php echo esc_attr($rm_active); ?>">
                    <input type="hidden" name="rule_order" id="dd-rule-order" value="">
                    <div class="dd-rule-list" id="dd-rule-list">
                        <?php echo devdredi_render_rule_rows($rm_rules, $rm_active, $rm_base, $rm_nonce); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are built and escaped inside devdredi_render_rule_rows(). ?>
                    </div>
                    <a class="dd-rule-add" id="dd-rule-add" href="<?php echo esc_url($rm_base . '&rm_rule_action=add&_rmn=' . $rm_nonce); ?>"><span class="dashicons dashicons-plus-alt2" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span> Add Rule</a>
                </div>
            </section>
            <?php devdredi_admin_js_capture(function () { ?>
            (function(){
                var list = document.getElementById('dd-rule-list');
                if (!list) return;
                var nickStore = document.getElementById('dd-rule-nickname');
                var orderStore = document.getElementById('dd-rule-order');

                function syncOrder(){
                    if (!orderStore) return;
                    orderStore.value = [].map.call(list.querySelectorAll('.dd-rule-row'), function(r){ return r.getAttribute('data-rule'); }).join(',');
                }
                function renumber(){
                    list.querySelectorAll('.dd-rule-row').forEach(function(row, i){
                        var pri = row.querySelector('.dd-rule-pri'); if (pri) pri.textContent = 'Priority ' + (i + 1);
                    });
                }
                syncOrder();

                // Nickname input of the ACTIVE rule -> mirrored to the hidden field, persisted on Save.
                list.addEventListener('focusin', function(e){
                    var nameEl = e.target.closest ? e.target.closest('.dd-rule-name') : null;
                    if (!nameEl) return;
                    var row = nameEl.closest('.dd-rule-row'); if (row) row.draggable = false;
                });
                list.addEventListener('input', function(e){
                    var nameEl = e.target.closest ? e.target.closest('.dd-rule-name') : null;
                    if (nameEl && nickStore) nickStore.value = nameEl.value;
                });
                list.addEventListener('focusout', function(e){
                    var nameEl = e.target.closest ? e.target.closest('.dd-rule-name') : null;
                    if (!nameEl) return;
                    var row = nameEl.closest('.dd-rule-row'); if (row) row.draggable = true;
                    if (nickStore) nickStore.value = nameEl.value;
                });
                list.addEventListener('keydown', function(e){
                    var nameEl = e.target.closest ? e.target.closest('.dd-rule-name') : null;
                    if (nameEl && e.key === 'Enter') { e.preventDefault(); nameEl.blur(); }
                });

                // Only the JS actions (rename / delete-confirm) are handled here; switch / add /
                // duplicate / delete-Yes are real links the server handles.
                list.addEventListener('click', function(e){
                    var act = e.target.closest('[data-act]'); if (!act) return;
                    var a = act.getAttribute('data-act'); var row = act.closest('.dd-rule-row');
                    if (a === 'delete') { if (row) row.classList.add('is-confirming'); }
                    else if (a === 'confirm-no') { if (row) row.classList.remove('is-confirming'); }
                    else if (a === 'expand') {
                        var p = document.getElementById('dd-rule-stats-' + row.getAttribute('data-rule'));
                        if (p) p.style.display = (p.style.display === 'none' ? '' : 'none');
                    }
                });

                // Drag reorder (priority = position). Persisted on Save via #dd-rule-order.
                // Each row's stats panel is a sibling right after it; keep them glued together so an
                // open panel never jumps above its row when reordering.
                var dragEl = null, dragPanel = null;
                function gluePanel(){ if (dragEl && dragPanel) list.insertBefore(dragPanel, dragEl.nextSibling); }
                list.addEventListener('dragstart', function(e){
                    var row = e.target.closest('.dd-rule-row'); if (!row) return;
                    dragEl = row; dragPanel = document.getElementById('dd-rule-stats-' + row.getAttribute('data-rule'));
                    row.classList.add('dragging');
                    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
                });
                list.addEventListener('dragend', function(){
                    if (dragEl) dragEl.classList.remove('dragging');
                    gluePanel();
                    dragEl = null; dragPanel = null; renumber(); syncOrder();
                });
                list.addEventListener('dragover', function(e){
                    e.preventDefault(); if (!dragEl) return;
                    var els = [].slice.call(list.querySelectorAll('.dd-rule-row:not(.dragging)'));
                    var after = null, closest = -Infinity;
                    els.forEach(function(child){
                        var box = child.getBoundingClientRect();
                        var offset = e.clientY - box.top - box.height / 2;
                        if (offset < 0 && offset > closest) { closest = offset; after = child; }
                    });
                    if (after == null) list.appendChild(dragEl); else list.insertBefore(dragEl, after);
                    gluePanel(); // move the panel to stay directly below its row
                });
            })();
            <?php }); ?>
            <?php devdredi_admin_js_capture(function () { ?>
            // Per-rule time-range stats + footer "Running For" clock.
            (function(){
                var TODAY = <?php echo wp_json_encode(current_time('Y-m-d')); ?>;
                var RUNINFO = <?php
                    $ri = array();
                    foreach (devdredi_get_rules() as $rr) {
                        $rid2 = $rr['id'];
                        if (devdredi_raw_get_setting('rule__' . $rid2 . '__plugin_state', 'stopped') === 'running') {
                            $st = (int) devdredi_raw_get_setting('rule__' . $rid2 . '__start_time', 0);
                            $ri[$rid2] = $st > 0 ? max(0, (int) current_time('timestamp') - $st) : 0;
                        }
                    }
                    echo wp_json_encode($ri);
                ?>;
                // date string N days back from TODAY (inclusive window of N days)
                function cutoff(n){ var d=new Date(TODAY+'T00:00:00Z'); d.setUTCDate(d.getUTCDate()-(n-1)); return d.toISOString().slice(0,10); }
                function computeRange(stats, range){
                    if (range==='all' || !stats.daily) return Object.assign({}, stats.life);
                    var from=cutoff(parseInt(range,10)||1), o={red:0,byp:0,bs:0,dd:0,dm:0,dt:0,cc:{}};
                    Object.keys(stats.daily).forEach(function(day){
                        if (day < from) return;
                        var b=stats.daily[day]||{};
                        ['red','byp','bs','dd','dm','dt'].forEach(function(k){ o[k]+=(parseInt(b[k],10)||0); });
                        var cc=b.cc||{}; for (var c in cc){ o.cc[c]=(o.cc[c]||0)+(parseInt(cc[c],10)||0); }
                    });
                    o.uu=stats.life.uu; o.ip=stats.life.ip; // uniques are all-time only
                    return o;
                }
                function applyRange(row){
                    var stats; try{ stats=JSON.parse(row.getAttribute('data-stats')||'{}'); }catch(e){ return; }
                    if(!stats.life) return;
                    var sel=row.querySelector('.dd-range'), range=sel?sel.value:'all', isAll=(range==='all');
                    var o=computeRange(stats, range);
                    var tot=row.querySelector('.dd-rr-total'); if(tot) tot.textContent=o.red;
                    var panel=document.getElementById('dd-rule-stats-'+row.getAttribute('data-rule'));
                    if(!panel) return;
                    panel.querySelectorAll('.dd-pm').forEach(function(el){ var m=el.getAttribute('data-m'); el.textContent=(o[m]!=null?o[m]:0); });
                    // grey uniques when a range (not all-time) is selected
                    panel.querySelectorAll('.dd-pm-uniq').forEach(function(el){ el.style.opacity=isAll?'':'0.45'; el.title=isAll?'':'Unique IPs/Users are all-time only'; });
                    var bc=panel.querySelector('.dd-bycountry'); if(bc){
                        var codes=Object.keys(o.cc||{}).sort(function(a,b){return o.cc[b]-o.cc[a];});
                        if(!codes.length){ bc.style.display='none'; }
                        else { bc.style.display='flex'; var html='<span>By Country:</span>'; codes.forEach(function(c){ html+=' <span>'+c+': <span style="color:#4f46e5;font-size:15px;">'+o.cc[c]+'</span></span>'; }); bc.innerHTML=html; }
                    }
                }
                document.addEventListener('change', function(e){ var s=e.target.closest && e.target.closest('.dd-range'); if(!s) return; var row=s.closest('.dd-rule-row'); if(row) applyRange(row); });
                // prevent the range select from triggering a rule switch
                document.addEventListener('click', function(e){ if(e.target.closest && e.target.closest('.dd-range')) e.stopPropagation(); }, true);

                // Footer "Running For" clock — ticks for the currently active/edited rule.
                var loadMs=Date.now();
                function fmt(s){ s=Math.max(0,Math.floor(s)); var d=Math.floor(s/86400); s-=d*86400; var h=Math.floor(s/3600); s-=h*3600; var m=Math.floor(s/60); return d+'d '+h+'h '+m+'m'; }
                function tickClock(){
                    var el=document.getElementById('dd-running-for'); if(!el) return;
                    var ed=document.getElementById('dd-editing-rule'); var rid=ed?ed.value:'';
                    var base=(rid && RUNINFO[rid]!=null)?RUNINFO[rid]:null;
                    var val=el.querySelector('#dd-running-for-val');
                    if(base==null){ el.style.display='none'; }
                    else { el.style.display=''; if(val) val.textContent=fmt(base+(Date.now()-loadMs)/1000); }
                }
                setInterval(tickClock, 1000); tickClock();
            })();
            <?php }); ?>
            <?php devdredi_admin_js_capture(function () { ?>
            document.addEventListener('DOMContentLoaded', function () {
                var chips = document.getElementById('dd-pick-chips');
                if (chips) {
                    chips.addEventListener('click', function (e) {
                        var x = e.target.closest('.dd-chip-x');
                        if (x) { var chip = x.closest('.dd-chip'); if (chip) chip.remove(); }
                    });
                }

                /* Redirect Source radios: "Links found on page" -> Page Link Rules; otherwise -> Destination Links */
                var rsRadios = document.querySelectorAll('input[name="redirect_source"]');
                var rowDest = document.getElementById('dd-row-destlinks');
                var rowPage = document.getElementById('dd-row-pagelinks');
                var rsLabel = document.getElementById('dd-destlinks-label');
                var rsProvided = document.getElementById('rm-provided-block');
                var rsTransit = document.getElementById('rm-transit-block');
                function rsValue() { var v = 'provided'; rsRadios.forEach(function (r) { if (r.checked) v = r.value; }); return v; }
                function syncRedirectSource() {
                    var v = rsValue();
                    var found = (v === 'found');
                    var transit = (v === 'transit');
                    if (rowDest) rowDest.style.display = found ? 'none' : '';
                    if (rowPage) rowPage.style.display = found ? '' : 'none';
                    if (rsLabel) rsLabel.textContent = transit ? 'Domain URL' : 'Destination URLs';
                    var rsTip = document.getElementById('dd-destlinks-tip');
                    if (rsTip) rsTip.style.display = transit ? '' : 'none';
                    if (rsProvided) rsProvided.style.display = transit ? 'none' : '';
                    if (rsTransit) rsTransit.style.display = transit ? '' : 'none';
                    // Link Order + Repeat List only apply to a rotated list of provided URLs.
                    var rowOrder = document.getElementById('dd-row-linkorder');
                    var rowRepeat = document.getElementById('dd-row-repeat');
                    if (rowOrder) rowOrder.style.display = (v === 'provided') ? '' : 'none';
                    if (rowRepeat) rowRepeat.style.display = (v === 'provided') ? '' : 'none';
                }
                rsRadios.forEach(function (r) { r.addEventListener('change', syncRedirectSource); });
                syncRedirectSource();

                /* Pages To Redirect radios: "Selected pages or URL paths" -> show Target Pages row */
                var wtrRadios = document.querySelectorAll('input[name="what_to_redirect"]');
                var rowTarget = document.getElementById('dd-row-targetpages');
                function syncWhatToRedirect() {
                    var v = '';
                    wtrRadios.forEach(function (r) { if (r.checked) v = r.value; });
                    // Referring websites with "Only on selected pages" ticked opens the same URLs To Redirect picker (1.5.0).
                    var refOnly = document.getElementById('dd-ref-only-selected');
                    var refPick = (v === 'referrer') && !!(refOnly && refOnly.checked);
                    var showRow = (v === 'selected_existing' || v === 'custom_urls' || refPick);
                    if (rowTarget) rowTarget.style.display = showRow ? '' : 'none';
                    var rowRef = document.getElementById('dd-row-referrer');
                    if (rowRef) rowRef.style.display = (v === 'referrer') ? '' : 'none';
                    var rowOutside = document.getElementById('dd-row-outside');
                    if (rowOutside) rowOutside.style.display = (v === 'referrer') ? 'none' : '';
                    var picker = document.getElementById('rm-picker-block');
                    var custom = document.getElementById('devdredi-custom-block');
                    if (picker) picker.style.display = (v === 'selected_existing' || refPick) ? '' : 'none';
                    if (custom) custom.style.display = (v === 'custom_urls') ? '' : 'none';
                    var rsFound = document.querySelector('[data-rs="found"]');
                    if (rsFound) {
                        if (v === 'all_404') {
                            rsFound.style.display = 'none';
                            var f = rsFound.querySelector('input[name="redirect_source"]');
                            if (f && f.checked) {
                                var prov = document.querySelector('input[name="redirect_source"][value="provided"]');
                                if (prov) { prov.checked = true; prov.dispatchEvent(new Event('change', { bubbles: true })); }
                            }
                        } else {
                            rsFound.style.display = '';
                        }
                    }
                }
                wtrRadios.forEach(function (r) { r.addEventListener('change', syncWhatToRedirect); });
                var refOnlyBox = document.getElementById('dd-ref-only-selected');
                if (refOnlyBox) refOnlyBox.addEventListener('change', syncWhatToRedirect);
                syncWhatToRedirect();

                /* Live link-count: count only lines that look like a real path/URL (no spaces / free text) */
                var isLink = function (l) {
                    l = l.trim();
                    if (l === '' || /\s/.test(l)) return false;          // no blanks, no spaces
                    return /^\//.test(l) || /^https?:\/\//i.test(l) || /^[^\/]+\.[^\/]/.test(l); // path, URL, or domain
                };
                window.ddIsLink = isLink; // shared with the legacy Destination/Selected counter below

                /* Shared "Remove All" control (inline Yes/No confirm). onClear() empties the list. */
                var ddWireRemoveAll = function (wrapId, onClear) {
                    var wrap = document.getElementById(wrapId);
                    if (!wrap || wrap.dataset.wired) return;
                    wrap.dataset.wired = '1';
                    var btn = wrap.querySelector('[data-act="ask"]');
                    var confirm = wrap.querySelector('[data-act="confirm"]');
                    var reset = function () { btn.style.display = ''; confirm.style.display = 'none'; };
                    wrap.addEventListener('click', function (e) {
                        var a = e.target.getAttribute('data-act');
                        if (a === 'ask') { btn.style.display = 'none'; confirm.style.display = ''; }
                        else if (a === 'no') { reset(); }
                        else if (a === 'yes') { reset(); onClear(); }
                    });
                };
                var ddToggleRemoveAll = function (wrapId, show) {
                    var wrap = document.getElementById(wrapId);
                    if (!wrap) return;
                    wrap.style.display = show ? '' : 'none';
                    if (!show) { var b = wrap.querySelector('[data-act="ask"]'), c = wrap.querySelector('[data-act="confirm"]'); if (b) b.style.display = ''; if (c) c.style.display = 'none'; }
                };

                /* Custom URLs: input + Add -> list rows (same look/logic as the picker's selected list) */
                var cuInput = document.getElementById('dd-custom-input');
                var cuAdd = document.getElementById('dd-custom-add');
                var cuList = document.getElementById('dd-custom-selected');
                var cuStore = document.getElementById('dd-custom-store');
                var cuCount = document.getElementById('dd-custom-count');
                if (cuInput && cuAdd && cuList && cuStore) {
                    var cuItems = cuStore.value.split('\n').map(function (l) { return l.trim(); }).filter(isLink);
                    var cuNormalize = function (raw) {
                        var v = (raw || '').trim();
                        if (v === '' || /\s/.test(v)) return '';
                        v = v.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/^\/\//, '').replace(/^www\./i, '');
                        if (/^[^\/]*\.[^\/]*\//.test(v) || /^[^\/]*\.[^\/]+$/.test(v)) {
                            var s = v.indexOf('/'); v = (s === -1) ? '' : v.slice(s);
                        }
                        v = '/' + v.replace(/^\/+/, '');
                        return v === '/' ? '' : v;
                    };
                    var cuSync = function () {
                        cuStore.value = cuItems.join('\n');
                    };
                    var cuUpdateEntered = function () {
                        if (cuCount) cuCount.textContent = cuInput.value.split('\n').filter(isLink).length;
                    };
                    var cuRender = function () {
                        cuList.innerHTML = '';
                        if (!cuItems.length) { cuSync(); return; }
                        var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                        var head = document.createElement('div'); head.className = 'dd-sel-head'; head.textContent = 'Custom URLs (' + cuItems.length + ')';
                        wrap.appendChild(head);
                        cuItems.forEach(function (url) {
                            var row = document.createElement('div'); row.className = 'dd-sel-row';
                            var info = document.createElement('span'); info.textContent = url; info.style.minWidth = '0';
                            var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '12px'; actions.style.flexShrink = '0';
                            var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Redirects: '));
                            var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = String(window.ddSourceCount ? window.ddSourceCount(url) : 0); hits.appendChild(hitsNum);
                            var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                            x.addEventListener('click', function () { cuItems = cuItems.filter(function (u) { return u !== url; }); cuRender(); });
                            actions.appendChild(hits); actions.appendChild(x);
                            row.appendChild(info); row.appendChild(actions);
                            wrap.appendChild(row);
                        });
                        cuList.appendChild(wrap);
                        cuSync();
                        ddToggleRemoveAll('dd-custom-removeall', cuItems.length > 0);
                    };
                    ddWireRemoveAll('dd-custom-removeall', function () { cuItems = []; cuRender(); });
                    var cuAddNow = function () {
                        var added = false;
                        cuInput.value.split('\n').forEach(function (line) {
                            var v = cuNormalize(line);
                            if (v && cuItems.indexOf(v) === -1) { cuItems.push(v); added = true; }
                        });
                        if (added) cuRender();
                        cuInput.value = '';
                        cuUpdateEntered();
                        cuInput.focus();
                    };
                    cuAdd.addEventListener('click', cuAddNow);
                    cuInput.addEventListener('input', cuUpdateEntered);
                    cuRender();
                    cuUpdateEntered();
                    window.DEVDREDI_REINIT.push(function(){ cuItems = cuStore.value.split('\n').map(function(l){return l.trim();}).filter(isLink); cuRender(); cuUpdateEntered(); });
                }

                /* Referring websites: input + Add -> list rows (DESIGN.md 10, same look/logic as Custom URLs), store = referrer_list */
                var rfInput = document.getElementById('dd-ref-input');
                var rfAdd = document.getElementById('dd-ref-add');
                var rfList = document.getElementById('dd-ref-selected');
                var rfStore = document.getElementById('dd-referrer-list');
                var rfEntered = document.getElementById('dd-ref-entered');
                if (rfInput && rfAdd && rfList && rfStore) {
                    // Mirrors devdredi_referrer_list(): lower case, no scheme, no path or query, no www., letters digits dots and hyphens.
                    var rfNormalize = function (raw) {
                        var v = (raw || '').trim().toLowerCase();
                        v = v.replace(/^[a-z][a-z0-9+.-]*:\/\//, '').replace(/[\/?#].*$/, '').replace(/^www\./, '');
                        return v.replace(/[^a-z0-9.\-]/g, '').replace(/^\.+|\.+$/g, '');
                    };
                    var rfParse = function (text) {
                        var out = [];
                        (text || '').split('\n').forEach(function (l) { var v = rfNormalize(l); if (v && out.indexOf(v) === -1) out.push(v); });
                        return out;
                    };
                    var rfItems = rfParse(rfStore.value);
                    // Redirects per entry: a domain sums its host and subdomains, a word every referring host containing it.
                    var rfHits = function (entry) {
                        if (entry.indexOf('.') !== -1) return window.ddRefCount ? window.ddRefCount(entry) : 0;
                        var m = (window.DEVDREDI_COUNTS && window.DEVDREDI_COUNTS.referrer) || {}, t = 0;
                        for (var h in m) { if (h.indexOf(entry) !== -1) { t += (parseInt(m[h], 10) || 0); } }
                        return t;
                    };
                    var rfUpdateEntered = function () { if (rfEntered) rfEntered.textContent = rfParse(rfInput.value).length; };
                    var rfRender = function () {
                        rfList.innerHTML = '';
                        rfStore.value = rfItems.join('\n');
                        ddToggleRemoveAll('dd-ref-removeall', rfItems.length > 0);
                        if (!rfItems.length) return;
                        var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                        var head = document.createElement('div'); head.className = 'dd-sel-head'; head.textContent = 'Referring Websites (' + rfItems.length + ')';
                        wrap.appendChild(head);
                        rfItems.forEach(function (entry) {
                            var row = document.createElement('div'); row.className = 'dd-sel-row';
                            var info = document.createElement('span'); info.textContent = entry; info.style.minWidth = '0';
                            var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '12px'; actions.style.flexShrink = '0';
                            var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Redirects: '));
                            var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = String(rfHits(entry)); hits.appendChild(hitsNum);
                            var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                            x.addEventListener('click', function () { rfItems = rfItems.filter(function (e) { return e !== entry; }); rfRender(); });
                            actions.appendChild(hits); actions.appendChild(x);
                            row.appendChild(info); row.appendChild(actions);
                            wrap.appendChild(row);
                        });
                        rfList.appendChild(wrap);
                    };
                    ddWireRemoveAll('dd-ref-removeall', function () { rfItems = []; rfRender(); });
                    var rfAddNow = function () {
                        rfParse(rfInput.value).forEach(function (v) { if (rfItems.indexOf(v) === -1) rfItems.push(v); });
                        rfRender();
                        rfInput.value = '';
                        rfUpdateEntered();
                    };
                    rfAdd.addEventListener('click', function () { rfAddNow(); rfInput.focus(); });
                    rfInput.addEventListener('input', rfUpdateEntered);
                    // Typed but not added yet: Save keeps it instead of dropping it.
                    if (rfInput.form) { rfInput.form.addEventListener('submit', function () { if (rfInput.value.trim()) { rfAddNow(); } }); }
                    rfRender();
                    rfUpdateEntered();
                    window.DEVDREDI_REINIT.push(function () { rfItems = rfParse(rfStore.value); rfInput.value = ''; rfRender(); rfUpdateEntered(); });
                }

                /* Provided links: input + Add -> list rows (same look/logic as Custom URLs), store = links_list */
                var pvInput = document.getElementById('dd-prov-input');
                var pvAdd = document.getElementById('dd-prov-add');
                var pvList = document.getElementById('dd-prov-selected');
                var pvStore = document.getElementById('dd-destlinks-field');
                var pvEntered = document.getElementById('dd-prov-entered');
                if (pvInput && pvAdd && pvList && pvStore) {
                    var pvItems = pvStore.value.split('\n').map(function (l) { return l.trim(); }).filter(isLink);
                    var pvSync = function () { pvStore.value = pvItems.join('\n'); };
                    var pvUpdateEntered = function () { if (pvEntered) pvEntered.textContent = pvInput.value.split('\n').filter(isLink).length; };
                    var pvRender = function () {
                        pvList.innerHTML = '';
                        if (pvItems.length) {
                            var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                            var head = document.createElement('div'); head.className = 'dd-sel-head'; head.textContent = 'Destination URLs (' + pvItems.length + ')';
                            wrap.appendChild(head);
                            pvItems.forEach(function (url, idx) {
                                var row = document.createElement('div'); row.className = 'dd-sel-row'; row.setAttribute('draggable', 'true'); row.dataset.idx = idx;
                                var handle = document.createElement('span'); handle.className = 'dashicons dashicons-menu dd-sel-drag'; handle.title = 'Drag to reorder';
                                var info = document.createElement('span'); info.textContent = url; info.style.minWidth = '0'; info.style.flex = '1';
                                var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '12px'; actions.style.flexShrink = '0';
                                var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Redirects: '));
                                var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = String((window.DEVDREDI_COUNTS && window.DEVDREDI_COUNTS.dest && window.DEVDREDI_COUNTS.dest[url]) || 0); hits.appendChild(hitsNum);
                                var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                                x.addEventListener('click', function () { pvItems = pvItems.filter(function (u) { return u !== url; }); pvRender(); });
                                actions.appendChild(hits); actions.appendChild(x);
                                row.appendChild(handle); row.appendChild(info); row.appendChild(actions);
                                wrap.appendChild(row);
                            });
                            // HTML5 drag-drop reorder (reorders pvItems by row position).
                            var pvDrag = null;
                            wrap.addEventListener('dragstart', function (e) { var r = e.target.closest('.dd-sel-row'); if (!r) return; pvDrag = r; r.style.opacity = '0.5'; if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'; });
                            wrap.addEventListener('dragend', function () { if (pvDrag) pvDrag.style.opacity = ''; pvDrag = null; });
                            wrap.addEventListener('dragover', function (e) {
                                e.preventDefault(); if (!pvDrag) return;
                                var rows = [].slice.call(wrap.querySelectorAll('.dd-sel-row:not([style*="opacity"])'));
                                var after = null, closest = -Infinity;
                                rows.forEach(function (c) { var b = c.getBoundingClientRect(); var off = e.clientY - b.top - b.height / 2; if (off < 0 && off > closest) { closest = off; after = c; } });
                                if (after == null) wrap.appendChild(pvDrag); else wrap.insertBefore(pvDrag, after);
                            });
                            wrap.addEventListener('drop', function (e) {
                                e.preventDefault();
                                var order = [].slice.call(wrap.querySelectorAll('.dd-sel-row')).map(function (r) { return pvItems[parseInt(r.dataset.idx, 10)]; });
                                pvItems = order; pvRender();
                            });
                            pvList.appendChild(wrap);
                        }
                        pvSync();
                        ddToggleRemoveAll('dd-prov-removeall', pvItems.length > 0);
                    };
                    ddWireRemoveAll('dd-prov-removeall', function () { pvItems = []; pvRender(); });
                    pvAdd.addEventListener('click', function () {
                        var added = false;
                        pvInput.value.split('\n').forEach(function (line) {
                            var v = line.trim();
                            if (isLink(v) && pvItems.indexOf(v) === -1) { pvItems.push(v); added = true; }
                        });
                        if (added) pvRender();
                        pvInput.value = '';
                        pvUpdateEntered();
                        pvInput.focus();
                    });
                    pvInput.addEventListener('input', pvUpdateEntered);
                    pvRender();
                    pvUpdateEntered();
                    window.DEVDREDI_REINIT.push(function(){ pvItems = pvStore.value.split('\n').map(function(l){return l.trim();}).filter(isLink); pvRender(); pvUpdateEntered(); });
                }

                /* Link/Button Contains: input + Add -> list rows (same look/logic as Destination URLs). Entries are URL fragments. */
                var plcInput = document.getElementById('dd-plc-input');
                var plcAdd = document.getElementById('dd-plc-add');
                var plcList = document.getElementById('dd-plc-selected');
                var plcStore = document.getElementById('dd-plc-store');
                var plcEntered = document.getElementById('dd-plc-entered');
                if (plcInput && plcAdd && plcList && plcStore) {
                    var plcOk = function (s) { s = s.trim(); return s !== '' && !/\s/.test(s); };
                    // Each item = { frag, nth } stored per line as "frag|nth" (nth = which matching link on the page).
                    var plcParse = function (line) {
                        var parts = line.split('|');
                        var frag = (parts[0] || '').trim();
                        var nth = parseInt(parts[1], 10); if (!(nth >= 1)) nth = 1;
                        return { frag: frag, nth: nth };
                    };
                    var plcItems = plcStore.value.split('\n').map(plcParse).filter(function (i) { return plcOk(i.frag); });
                    var plcSync = function () { plcStore.value = plcItems.map(function (i) { return i.frag + '|' + i.nth; }).join('\n'); };
                    var plcUpdateEntered = function () { if (plcEntered) plcEntered.textContent = plcInput.value.split('\n').filter(plcOk).length; };
                    var plcRender = function () {
                        plcList.innerHTML = '';
                        if (plcItems.length) {
                            var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                            var head = document.createElement('div'); head.className = 'dd-sel-head'; head.textContent = 'Selected Link/Button Patterns (' + plcItems.length + ')';
                            wrap.appendChild(head);
                            plcItems.forEach(function (item, idx) {
                                var row = document.createElement('div'); row.className = 'dd-sel-row'; row.setAttribute('draggable', 'true'); row.dataset.idx = idx;
                                var handle = document.createElement('span'); handle.className = 'dashicons dashicons-menu dd-sel-drag'; handle.title = 'Drag to set priority';
                                var pri = document.createElement('span'); pri.className = 'dd-sel-pri'; pri.textContent = 'Priority #' + (idx + 1);
                                var info = document.createElement('span'); info.textContent = item.frag; info.style.minWidth = '0'; info.style.flex = '1';
                                var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '12px'; actions.style.flexShrink = '0';
                                var nthWrap = document.createElement('span'); nthWrap.className = 'dd-sel-nth';
                                nthWrap.appendChild(document.createTextNode('link #'));
                                var nthInput = document.createElement('input'); nthInput.type = 'number'; nthInput.min = '1'; nthInput.value = item.nth; nthInput.title = 'Which matching link/button on the page to use (1 = first)';
                                nthInput.addEventListener('change', function () { var n = parseInt(nthInput.value, 10); if (!(n >= 1)) n = 1; item.nth = n; nthInput.value = n; plcSync(); });
                                nthInput.addEventListener('mousedown', function (e) { e.stopPropagation(); });
                                nthInput.setAttribute('draggable', 'false');
                                nthWrap.appendChild(nthInput);
                                nthWrap.appendChild(document.createTextNode(' on the page'));
                                var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Redirects: '));
                                var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = String((window.DEVDREDI_COUNTS && window.DEVDREDI_COUNTS.found && window.DEVDREDI_COUNTS.found[item.frag]) || 0); hits.appendChild(hitsNum);
                                var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                                x.addEventListener('click', function () { plcItems.splice(idx, 1); plcRender(); });
                                actions.appendChild(nthWrap); actions.appendChild(hits); actions.appendChild(x);
                                row.appendChild(handle); row.appendChild(pri); row.appendChild(info); row.appendChild(actions);
                                wrap.appendChild(row);
                            });
                            // HTML5 drag-drop reorder = priority (top = #1, opened first).
                            var plcDrag = null;
                            wrap.addEventListener('dragstart', function (e) { var r = e.target.closest('.dd-sel-row'); if (!r) return; plcDrag = r; r.style.opacity = '0.5'; if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'; });
                            wrap.addEventListener('dragend', function () { if (plcDrag) plcDrag.style.opacity = ''; plcDrag = null; });
                            wrap.addEventListener('dragover', function (e) {
                                e.preventDefault(); if (!plcDrag) return;
                                var rows = [].slice.call(wrap.querySelectorAll('.dd-sel-row:not([style*="opacity"])'));
                                var after = null, closest = -Infinity;
                                rows.forEach(function (c) { var b = c.getBoundingClientRect(); var off = e.clientY - b.top - b.height / 2; if (off < 0 && off > closest) { closest = off; after = c; } });
                                if (after == null) wrap.appendChild(plcDrag); else wrap.insertBefore(plcDrag, after);
                            });
                            wrap.addEventListener('drop', function (e) {
                                e.preventDefault();
                                plcItems = [].slice.call(wrap.querySelectorAll('.dd-sel-row')).map(function (r) { return plcItems[parseInt(r.dataset.idx, 10)]; });
                                plcRender();
                            });
                            plcList.appendChild(wrap);
                        }
                        plcSync();
                        ddToggleRemoveAll('dd-plc-removeall', plcItems.length > 0);
                    };
                    ddWireRemoveAll('dd-plc-removeall', function () { plcItems = []; plcRender(); });
                    plcAdd.addEventListener('click', function () {
                        var added = false;
                        plcInput.value.split('\n').forEach(function (line) {
                            var v = line.trim();
                            if (plcOk(v) && !plcItems.some(function (i) { return i.frag === v; })) { plcItems.push({ frag: v, nth: 1 }); added = true; }
                        });
                        if (added) plcRender();
                        plcInput.value = '';
                        plcUpdateEntered();
                        plcInput.focus();
                    });
                    plcInput.addEventListener('input', plcUpdateEntered);
                    plcRender();
                    plcUpdateEntered();
                    window.DEVDREDI_REINIT.push(function(){ plcItems = plcStore.value.split('\n').map(plcParse).filter(function(i){return plcOk(i.frag);}); plcRender(); plcUpdateEntered(); });
                }

                /* Transit: one domain only. Add validates the domain, then shows Edit/Remove. Store = transit_domain */
                var trInput = document.getElementById('dd-transit-input');
                var trAdd = document.getElementById('dd-transit-add');
                var trEntry = document.getElementById('dd-transit-entry');
                var trSelected = document.getElementById('dd-transit-selected');
                var trStore = document.getElementById('dd-transit-store');
                var trError = document.getElementById('dd-transit-error');
                var trRedirects = <?php echo intval(devdredi_get_setting('user_redirects_count', 0)); ?>;
                if (trInput && trAdd && trEntry && trSelected && trStore) {
                    var trNormalize = function (raw) {
                        var v = (raw || '').trim().toLowerCase();
                        v = v.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/^\/\//, '').replace(/^www\./i, '');
                        v = v.split('/')[0].split('?')[0].split('#')[0];
                        return v;
                    };
                    var trValid = function (host) { return /^([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/.test(host); };
                    // Locked state = a styled card (domain + Redirects count + Edit + Remove); entry row hidden.
                    var trLock = function (domain) {
                        trEntry.style.display = 'none';
                        trSelected.innerHTML = '';
                        var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                        var row = document.createElement('div'); row.className = 'dd-sel-row';
                        var info = document.createElement('span'); info.textContent = domain; info.style.minWidth = '0'; info.style.fontWeight = '600';
                        var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '10px'; actions.style.flexShrink = '0';
                        var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Redirects: '));
                        var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = trRedirects; hits.appendChild(hitsNum);
                        var edit = document.createElement('span'); edit.className = 'dd-sel-edit'; edit.textContent = 'Edit';
                        edit.addEventListener('click', function () { trStore.value = ''; trUnlock(true); });
                        var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                        x.addEventListener('click', function () { trStore.value = ''; trInput.value = ''; trUnlock(true); });
                        actions.appendChild(hits); actions.appendChild(edit); actions.appendChild(x);
                        row.appendChild(info); row.appendChild(actions);
                        wrap.appendChild(row); trSelected.appendChild(wrap);
                    };
                    var trUnlock = function (focus) {
                        trSelected.innerHTML = '';
                        trEntry.style.display = 'flex';
                        if (focus) trInput.focus();
                    };
                    var trAddNow = function () {
                        var host = trNormalize(trInput.value);
                        if (!trValid(host)) {
                            if (trError) { trError.textContent = 'Enter a valid domain, e.g. otherdomain.com'; trError.style.display = 'block'; }
                            return;
                        }
                        if (trError) trError.style.display = 'none';
                        trInput.value = host;
                        trStore.value = host;
                        trLock(host);
                    };
                    trAdd.addEventListener('click', trAddNow);
                    trInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); trAddNow(); } });
                    if (trStore.value.trim()) { trInput.value = trStore.value.trim(); trLock(trStore.value.trim()); }
                    window.DEVDREDI_REINIT.push(function(){ var v = trStore.value.trim(); if (v) { trInput.value = v; trLock(v); } else { trInput.value = ''; trUnlock(false); } });
                }

                /* Bypass link: one URL only. Add validates it, then shows Edit/Remove — same as the transit Domain URL. Store = fallback_url */
                var byInput = document.getElementById('dd-bypass-input');
                var byAdd = document.getElementById('dd-bypass-add');
                var byEntry = document.getElementById('dd-bypass-entry');
                var bySelected = document.getElementById('dd-bypass-selected');
                var byStore = document.getElementById('dd-bypass-store');
                var byError = document.getElementById('dd-bypass-error');
                var byBypassed = <?php echo intval(devdredi_get_setting('user_bypass_count', 0)); ?>;
                if (byInput && byAdd && byEntry && bySelected && byStore) {
                    var byNormalize = function (raw) {
                        var v = (raw || '').trim();
                        if (v === '') return '';
                        if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(v)) v = 'https://' + v;
                        return v;
                    };
                    var byValid = function (u) {
                        try { var x = new URL(u); return (x.protocol === 'http:' || x.protocol === 'https:') && x.hostname.indexOf('.') !== -1; }
                        catch (e) { return false; }
                    };
                    var byLock = function (url) {
                        byEntry.style.display = 'none';
                        bySelected.innerHTML = '';
                        var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                        var row = document.createElement('div'); row.className = 'dd-sel-row';
                        var info = document.createElement('span'); info.textContent = url; info.style.minWidth = '0'; info.style.fontWeight = '600'; info.style.wordBreak = 'break-all';
                        var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '10px'; actions.style.flexShrink = '0';
                        var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Bypassed: '));
                        var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = byBypassed; hits.appendChild(hitsNum);
                        var edit = document.createElement('span'); edit.className = 'dd-sel-edit'; edit.textContent = 'Edit';
                        edit.addEventListener('click', function () { byStore.value = ''; byUnlock(true); });
                        var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                        x.addEventListener('click', function () { byStore.value = ''; byInput.value = ''; byUnlock(true); });
                        actions.appendChild(hits); actions.appendChild(edit); actions.appendChild(x);
                        row.appendChild(info); row.appendChild(actions);
                        wrap.appendChild(row); bySelected.appendChild(wrap);
                    };
                    var byUnlock = function (focus) {
                        bySelected.innerHTML = '';
                        byEntry.style.display = 'flex';
                        if (focus) byInput.focus();
                    };
                    var byAddNow = function () {
                        var u = byNormalize(byInput.value);
                        if (!byValid(u)) {
                            if (byError) { byError.textContent = 'Enter a valid URL, e.g. https://google.com'; byError.style.display = 'block'; }
                            return;
                        }
                        if (byError) byError.style.display = 'none';
                        byInput.value = u;
                        byStore.value = u;
                        byLock(u);
                    };
                    byAdd.addEventListener('click', byAddNow);
                    byInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); byAddNow(); } });
                    if (byStore.value.trim()) { byInput.value = byStore.value.trim(); byLock(byStore.value.trim()); }
                    window.DEVDREDI_REINIT.push(function(){ var v = byStore.value.trim(); if (v) { byInput.value = v; byLock(v); } else { byInput.value = ''; byUnlock(false); } });
                }

                /* Selected existing URLs: live search picker (tabs + REST + grouped selection) */
                var DDRM = { url: <?php echo wp_json_encode(rest_url('devdredi/v1/search-content')); ?>, nonce: <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?> };
                var pkSearch = document.getElementById('dd-pick-search');
                var pkResults = document.getElementById('dd-pick-results');
                var pkSelected = document.getElementById('dd-pick-selected');
                var pkStore = document.getElementById('dd-pick-store');
                var pkMeta = document.getElementById('dd-pick-meta');
                var pkTabs = document.querySelectorAll('.dd-pick-tab');
                if (pkSearch && pkResults && pkSelected && pkStore && pkMeta) {
                    var pkType = 'category';
                    var pkTimer = null;
                    var pkLast = [];
                    var items = [];
                    try { items = JSON.parse(pkMeta.value || '[]') || []; } catch (e) { items = []; }
                    var GROUPS = [{ type: 'category', title: 'Selected Categories' }, { type: 'page', title: 'Selected Pages' }, { type: 'post', title: 'Selected Posts' }];

                    function pkSync() {
                        pkStore.value = items.map(function (i) { return i.url; }).join('\n');
                        pkMeta.value = JSON.stringify(items);
                    }
                    function pkHas(url) { return items.some(function (i) { return i.url === url; }); }
                    function pkAdd(label, url, type) { if (!url || pkHas(url)) return; items.push({ label: label, url: url, type: type }); pkSync(); pkRenderSelected(); }
                    function pkRemove(url) { items = items.filter(function (i) { return i.url !== url; }); pkSync(); pkRenderSelected(); }

                    function pkRenderSelected() {
                        pkSelected.innerHTML = '';
                        GROUPS.forEach(function (g) {
                            var rows = items.filter(function (i) { return i.type === g.type; });
                            if (!rows.length) return;
                            var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                            var head = document.createElement('div'); head.className = 'dd-sel-head'; head.textContent = g.title + ' (' + rows.length + ')';
                            wrap.appendChild(head);
                            rows.forEach(function (i) {
                                var row = document.createElement('div'); row.className = 'dd-sel-row';
                                var info = document.createElement('span'); info.style.display = 'flex'; info.style.flexDirection = 'column'; info.style.minWidth = '0';
                                var title = document.createElement('span'); title.textContent = i.label; title.style.fontWeight = '600';
                                var slug = document.createElement('span'); slug.textContent = i.url; slug.style.color = '#4b5563'; slug.style.fontSize = '12px';
                                info.appendChild(title); info.appendChild(slug);
                                var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '12px'; actions.style.flexShrink = '0';
                                var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Redirects: '));
                                var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = String(window.ddSourceCount ? window.ddSourceCount(i.url) : (i.redirects || 0)); hits.appendChild(hitsNum);
                                var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                                x.addEventListener('click', function () { pkRemove(i.url); pkRenderResults(pkLast); });
                                actions.appendChild(hits); actions.appendChild(x);
                                row.appendChild(info); row.appendChild(actions);
                                wrap.appendChild(row);
                            });
                            pkSelected.appendChild(wrap);
                        });
                        ddToggleRemoveAll('dd-pick-removeall', items.length > 0);
                    }
                    ddWireRemoveAll('dd-pick-removeall', function () { items = []; pkSync(); pkRenderSelected(); pkRenderResults(pkLast); });
                    pkRenderSelected();

                    function pkResultsHead() {
                        var hdr = document.createElement('div');
                        hdr.className = 'dd-pick-head';
                        var label = document.createElement('span'); label.textContent = 'Click items to add — close when done';
                        var close = document.createElement('span'); close.className = 'dd-pick-close'; close.title = 'Close'; close.textContent = '×';
                        close.addEventListener('click', function (e) { e.stopPropagation(); pkResults.style.display = 'none'; });
                        hdr.appendChild(label); hdr.appendChild(close);
                        return hdr;
                    }
                    function pkMark(container, text, q) {
                        container.textContent = '';
                        if (!q) { container.textContent = text; return; }
                        var lower = text.toLowerCase(), ql = q.toLowerCase(), idx = 0, pos;
                        while ((pos = lower.indexOf(ql, idx)) !== -1) {
                            if (pos > idx) container.appendChild(document.createTextNode(text.slice(idx, pos)));
                            var m = document.createElement('mark'); m.className = 'dd-hl'; m.textContent = text.slice(pos, pos + q.length);
                            container.appendChild(m);
                            idx = pos + q.length;
                        }
                        if (idx < text.length) container.appendChild(document.createTextNode(text.slice(idx)));
                    }
                    function pkRenderResults(list) {
                        var q = pkSearch.value.trim();
                        pkResults.innerHTML = '';
                        pkResults.appendChild(pkResultsHead());
                        if (!list || !list.length) {
                            var empty = document.createElement('div'); empty.className = 'dd-pick-empty'; empty.textContent = 'No matches.';
                            pkResults.appendChild(empty); pkResults.style.display = 'block'; return;
                        }
                        list.forEach(function (it) {
                            var row = document.createElement('div');
                            row.className = 'dd-dd-opt';
                            var chosen = pkHas(it.value);
                            if (chosen) row.classList.add('is-chosen');
                            var left = document.createElement('span');
                            left.style.display = 'flex'; left.style.flexDirection = 'column'; left.style.minWidth = '0';
                            var title = document.createElement('span'); title.style.fontWeight = '600';
                            pkMark(title, it.label, q);
                            var path = document.createElement('span'); path.style.color = '#4b5563'; path.style.fontSize = '12px';
                            pkMark(path, it.value, q);
                            // A match from another tab says which one it belongs to (the shop page shows up while Categories is open).
                            if (it.other) { path.appendChild(document.createTextNode(' \u00b7 ' + (it.type === 'post' ? 'Post' : (it.type === 'page' ? 'Page' : 'Category')))); }
                            left.appendChild(title); left.appendChild(path);
                            var add = document.createElement('span'); add.className = 'dd-pick-add'; add.textContent = chosen ? 'Added ✓' : 'Add';
                            row.appendChild(left); row.appendChild(add);
                            row.addEventListener('click', function (e) {
                                e.stopPropagation();
                                if (pkHas(it.value)) { pkRemove(it.value); } else { pkAdd(it.label, it.value, it.type || pkType); }
                                pkRenderResults(list);
                            });
                            pkResults.appendChild(row);
                        });
                        pkResults.style.display = 'block';
                    }
                    var pkSeq = 0;
                    function pkFetch() {
                        var q = pkSearch.value.trim();
                        // Only the newest search may fill the list: a slower reply to an older query must not replace it.
                        var seq = ++pkSeq;
                        // Plain permalinks give rest_url() as index.php?rest_route=..., so the query joins with & (a second ? made a 404, 1.4.2).
                        fetch(DDRM.url + (DDRM.url.indexOf('?') === -1 ? '?' : '&') + 'type=' + encodeURIComponent(pkType) + '&q=' + encodeURIComponent(q), { headers: { 'X-WP-Nonce': DDRM.nonce } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) { if (seq !== pkSeq) { return; } pkLast = (d && d.items) ? d.items : []; pkRenderResults(pkLast); })
                            .catch(function () { pkResults.innerHTML = '<div class="dd-pick-empty">Search failed.</div>'; pkResults.style.display = 'block'; });
                    }
                    pkSearch.addEventListener('input', function () { clearTimeout(pkTimer); pkTimer = setTimeout(pkFetch, 250); });
                    pkSearch.addEventListener('focus', function () { if (pkLast.length) { pkResults.style.display = 'block'; } else { pkFetch(); } });
                    var pkBlock = document.getElementById('rm-picker-block');
                    document.addEventListener('mousedown', function (e) {
                        if (!pkBlock) return;
                        var path = typeof e.composedPath === 'function' ? e.composedPath() : [];
                        if (path.includes(pkBlock) || pkBlock.contains(e.target)) return;
                        pkResults.style.display = 'none';
                    });
                    var selectTab = function (t) {
                        pkTabs.forEach(function (x) { x.classList.remove('is-active'); });
                        t.classList.add('is-active');
                        pkType = t.getAttribute('data-type');
                        pkSearch.placeholder = 'Search ' + (pkType === 'post' ? 'Posts' : pkType === 'category' ? 'Categories' : 'Pages') + '…';
                        // Only refetch/show if the results panel is already open (search field focused).
                        if (pkResults.style.display !== 'none') { pkFetch(); }
                    };
                    pkTabs.forEach(function (t) {
                        // Switch on mousedown and preventDefault so the search field keeps focus
                        // (cursor + typed value) — a plain click handler would blur the input first.
                        t.addEventListener('mousedown', function (e) { e.preventDefault(); selectTab(t); });
                    });
                    window.DEVDREDI_REINIT.push(function(){ try { items = JSON.parse(pkMeta.value || '[]') || []; } catch (e) { items = []; } pkRenderSelected(); if (pkResults) pkResults.style.display = 'none'; });
                }
            });
            <?php }); ?>
            <?php devdredi_admin_js_capture(function () { ?>
            /* Shared DevDome custom dropdown (mirrors PI DumpStyledSelect). Works for any .dd-dd. */
            document.addEventListener('DOMContentLoaded', function () {
                var dds = document.querySelectorAll('.dd-dd');
                function closeAll(except) { dds.forEach(function (d) { if (d !== except) d.classList.remove('is-open'); }); }
                function applyValue(dd, value, fire) {
                    var input = dd.querySelector('input[type="hidden"]');
                    var label = dd.querySelector('.dd-dd-label');
                    var chosen = null;
                    dd.querySelectorAll('.dd-dd-opt').forEach(function (o) {
                        var sel = (o.getAttribute('data-value') === value);
                        o.classList.toggle('is-selected', sel);
                        if (sel) chosen = o;
                    });
                    if (input) input.value = value;
                    if (label && chosen) label.textContent = chosen.textContent;
                    if (fire && input) input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                dds.forEach(function (dd) {
                    var trigger = dd.querySelector('.dd-dd-trigger');
                    if (!trigger) return;
                    trigger.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var willOpen = !dd.classList.contains('is-open');
                        closeAll(dd);
                        dd.classList.toggle('is-open', willOpen);
                    });
                    dd.querySelectorAll('.dd-dd-opt').forEach(function (opt) {
                        opt.addEventListener('click', function (e) {
                            e.stopPropagation();
                            applyValue(dd, opt.getAttribute('data-value'), true);
                            dd.classList.remove('is-open');
                        });
                    });
                });
                document.addEventListener('click', function () { closeAll(null); });
                /* Programmatic setter so other scripts can drive a dropdown (e.g. force redirect_type = js). */
                window.ddSetSelect = function (name, value) {
                    var dd = document.querySelector('.dd-dd[data-name="' + name + '"]');
                    if (dd) applyValue(dd, value, true);
                };
            });
            <?php }); ?>
            

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-randomize dd-ico"></span>
                    <h2 class="dd-h2">Redirect Setup</h2>
                </div>
                <div class="dd-card">
            <table class="form-table" style="margin-top:8px;">
                <tr>
                    <th>What To Redirect</th>
                    <td>
                        <div class="flex flex-col gap-2.5 items-start" role="radiogroup" aria-label="What to redirect">
                            <div>
                                <label class="dd-opt"><input type="radio" name="what_to_redirect" value="entire_website" <?php checked(!in_array($what_to_redirect, array('selected_existing', 'custom_urls', 'all_404', 'referrer'), true)); ?>> Entire website</label>
                                <p class="dd-hint">Run this rule on every public page of your site.</p>
                            </div>
                            <div>
                                <label class="dd-opt"><input type="radio" name="what_to_redirect" value="selected_existing" <?php checked($what_to_redirect === 'selected_existing'); ?>> Selected existing URLs</label>
                                <p class="dd-hint">Search and add pages, posts, or categories that already exist on your site.</p>
                            </div>
                            <div>
                                <label class="dd-opt"><input type="radio" name="what_to_redirect" value="custom_urls" <?php checked($what_to_redirect === 'custom_urls'); ?>> Custom URLs</label>
                                <p class="dd-hint">Use this for exact URL paths, whether the page exists or not.</p>
                            </div>
                            <div>
                                <label class="dd-opt"><input type="radio" name="what_to_redirect" value="referrer" <?php checked($what_to_redirect === 'referrer'); ?>> Referring websites</label>
                                <p class="dd-hint">Redirect only visitors who arrive from the websites you list. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">A domain (reddit.com) covers that site and its subdomains; a word (reddit) covers every referring site whose address contains it. Works on cached pages too.</span></span></p>
                            </div>
                            <div>
                                <label class="dd-opt"><input type="radio" name="what_to_redirect" value="all_404" <?php checked($what_to_redirect === 'all_404'); ?>> All 404's</label>
                                <p class="dd-hint">Use this when you want to redirect every not-found page on your site.</p>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr id="dd-row-outside"<?php echo ($what_to_redirect === 'referrer') ? ' style="display:none;"' : ''; ?>>
                    <th>Arriving From Outside</th>
                    <td>
                        <label>
                            <input type="checkbox" name="outside_only" id="dd-outside-only" value="1" <?php checked((int) devdredi_get_setting('outside_only', 0), 1); ?>>
                            Only visitors arriving from outside
                        </label>
                        <p class="dd-hint">Redirect a visitor who lands here from another website or with no referrer; a visitor moving between pages of this site stays. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Arriving from outside means the referrer is another site, or there is none at all (typed address, bookmark, a browser that hides the referrer). Coming from a page of this site never counts. Works on cached pages through a small footer script that reloads the landing once. Referring websites rules already work this way.</span></span></p>
                    </td>
                </tr>
                <?php $ref_pick = ($what_to_redirect === 'referrer' && (int) devdredi_get_setting('referrer_only_selected', 0) === 1); ?>
                <tr id="dd-row-referrer"<?php echo ($what_to_redirect === 'referrer') ? '' : ' style="display:none;"'; ?>>
                    <th>Referring Websites</th>
                    <td>
                        <div style="display:flex; gap:8px; align-items:flex-start;">
                            <textarea id="dd-ref-input" rows="3" class="dd-textarea" placeholder="e.g. reddit or facebook.com, one per line" autocomplete="off" style="flex:1;"></textarea>
                            <button type="button" id="dd-ref-add" class="dd-list-addbtn">Add</button>
                        </div>
                        <small style="display:block;margin-top:8px;"><span id="dd-ref-entered">0</span> website(s) entered</small>
                        <div id="dd-ref-selected" style="margin-top:10px;"></div>
                        <div class="dd-removeall" id="dd-ref-removeall" style="display:none;">
                            <button type="button" class="dd-removeall-btn" data-act="ask">Remove All</button>
                            <span class="dd-removeall-confirm" data-act="confirm" style="display:none;">Remove all? <a class="yes" data-act="yes">Yes</a> / <a class="no" data-act="no">No</a></span>
                        </div>
                        <textarea name="referrer_list" id="dd-referrer-list" style="display:none;"><?php echo esc_textarea((string) devdredi_get_setting('referrer_list', '')); ?></textarea>
                        <p class="dd-hint">A domain (facebook.com) matches that site and its subdomains. A word (reddit) matches any referring site whose address contains it. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">The referrer is what the visitor's browser reports. Some sites and apps send none, and those visitors are never redirected by this rule. Visitors coming from your own site never count.</span></span></p>
                        <label style="display:block; margin-top:12px;">
                            <input type="checkbox" name="referrer_only_selected" id="dd-ref-only-selected" value="1" <?php checked((int) devdredi_get_setting('referrer_only_selected', 0), 1); ?>>
                            Only on selected pages
                        </label>
                        <p class="dd-hint">Pick the categories, pages or posts under URLs To Redirect. Visitors from these websites are redirected only there. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Unticked, the rule redirects these visitors on every page. A picked category covers every post in it and in its subcategories, a picked product category or shop archive every product in it.</span></span></p>
                    </td>
                </tr>
                <tr id="dd-row-targetpages"<?php echo (in_array($what_to_redirect, array('selected_existing', 'custom_urls'), true) || $ref_pick) ? '' : ' style="display:none;"'; ?>>
                    <th>URLs To Redirect</th>
                    <td>
                        <!-- Selected existing URLs: live search picker (tabs + REST + chips) -->
                        <div id="rm-picker-block" style="<?php echo ($what_to_redirect === 'selected_existing' || $ref_pick) ? '' : 'display:none;'; ?>">
                            <div style="display:flex; gap:6px; margin-bottom:8px;">
                                <button type="button" class="dd-pick-tab is-active" data-type="category">Categories</button>
                                <button type="button" class="dd-pick-tab" data-type="page">Pages</button>
                                <button type="button" class="dd-pick-tab" data-type="post">Posts</button>
                            </div>
                            <div style="position:relative;">
                                <input type="text" id="dd-pick-search" placeholder="Search Categories&hellip;" autocomplete="off" style="width:100%;">
                                <div class="dd-dd-panel" id="dd-pick-results" style="display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); z-index:20; max-height:274px; overflow:auto;"></div>
                            </div>
                            <div id="dd-pick-selected" style="margin-top:10px;"></div>
                            <div class="dd-removeall" id="dd-pick-removeall" style="display:none;">
                                <button type="button" class="dd-removeall-btn" data-act="ask">Remove All</button>
                                <span class="dd-removeall-confirm" data-act="confirm" style="display:none;">Remove all? <a class="yes" data-act="yes">Yes</a> / <a class="no" data-act="no">No</a></span>
                            </div>
                            <textarea name="selected_links_list" id="dd-pick-store" style="display:none;"><?php echo esc_textarea($selected_links_list); ?></textarea>
                            <textarea name="selected_links_meta" id="dd-pick-meta" style="display:none;"><?php echo esc_textarea($selected_links_meta); ?></textarea>
                        </div>
                        <!-- Custom URLs: input + Add -> list (same logic/style as the picker) -->
                        <div id="devdredi-custom-block" style="<?php echo ($what_to_redirect === 'custom_urls') ? '' : 'display:none;'; ?>">
                            <div style="display:flex; gap:8px; align-items:flex-start;">
                                <textarea id="dd-custom-input" rows="3" class="dd-textarea" placeholder="e.g. /blog/ or /blog/best-headphones/ — one per line" autocomplete="off" style="flex:1;"></textarea>
                                <button type="button" id="dd-custom-add">Add</button>
                            </div>
                            <small id="devdredi-custom-count" style="display:block;margin-top:8px;"><span id="dd-custom-count">0</span> link(s) entered</small>
                            <p class="dd-hint">A path ending with / also covers everything under it: /blog/ redirects /blog/ and every /blog/... page. Without the slash only that exact path redirects.</p>
                            <div id="dd-custom-selected" style="margin-top:10px;"></div>
                            <div class="dd-removeall" id="dd-custom-removeall" style="display:none;">
                                <button type="button" class="dd-removeall-btn" data-act="ask">Remove All</button>
                                <span class="dd-removeall-confirm" data-act="confirm" style="display:none;">Remove all? <a class="yes" data-act="yes">Yes</a> / <a class="no" data-act="no">No</a></span>
                            </div>
                            <textarea name="custom_links_list" id="dd-custom-store" style="display:none;"><?php echo esc_textarea($custom_links_list); ?></textarea>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Redirect Method</th>
                    <td>
                        <?php
                        $rt_labels = array(
                            'js'   => 'JavaScript Redirect',
                            '301'  => '301 Permanent Redirect',
                            '302'  => '302 Temporary Redirect',
                            '307'  => '307 Temporary Redirect',
                            '308'  => '308 Permanent Redirect',
                            'meta' => 'Meta Refresh Redirect',
                        );
                        $rt = isset($rt_labels[$redirect_type]) ? $redirect_type : 'js';
                        $rt_sel = function ($v) use ($rt) { return $rt === $v ? ' is-selected' : ''; };
                        ?>
                        <div class="dd-dd w-64" data-name="redirect_type">
                            <input type="hidden" name="redirect_type" value="<?php echo esc_attr($rt); ?>">
                            <div class="dd-dd-trigger" tabindex="0">
                                <span class="dd-dd-label"><?php echo esc_html($rt_labels[$rt]); ?></span>
                                <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                            <div class="dd-dd-panel">
                                <div class="dd-dd-group">Basic</div>
                                <div class="dd-dd-opt<?php echo esc_attr( $rt_sel('js') ); ?>" data-value="js">JavaScript Redirect</div>
                                <div class="dd-dd-opt<?php echo esc_attr( $rt_sel('301') ); ?>" data-value="301">301 Permanent Redirect</div>
                                <div class="dd-dd-opt<?php echo esc_attr( $rt_sel('302') ); ?>" data-value="302">302 Temporary Redirect</div>
                                <div class="dd-dd-group">Advanced</div>
                                <div class="dd-dd-opt<?php echo esc_attr( $rt_sel('307') ); ?>" data-value="307">307 Temporary Redirect</div>
                                <div class="dd-dd-opt<?php echo esc_attr( $rt_sel('308') ); ?>" data-value="308">308 Permanent Redirect</div>
                                <div class="dd-dd-opt<?php echo esc_attr( $rt_sel('meta') ); ?>" data-value="meta">Meta Refresh Redirect</div>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>


            <table class="form-table">

                <?php
                /* Redirect To — decides where the destination URLs come from (UI mockup; not yet wired). */
                $rs_labels = array(
                    'provided' => 'To provided links',
                    'found'    => 'To links or buttons on the page',
                    'transit'  => 'To same path on another domain',
                );
                $rs_hints = array(
                    'provided' => 'Send traffic to the destination URLs you enter below.',
                    'found'    => 'Finds your entered link or button already on the page and sends traffic there. Example: enter amazon.com to use the first Amazon link/button found on that page.',
                    'transit'  => 'Keep the same URL path, but send it to another domain. Example: yoursite.com/post/123 → otherdomain.com/post/123.',
                );
                $redirect_source = devdredi_get_setting('redirect_source', 'provided');
                if (!isset($rs_labels[$redirect_source])) { $redirect_source = 'provided'; }
                $rs_opt = function ($v) use ($redirect_source) { return $redirect_source === $v ? ' is-selected' : ''; };
                ?>
                <tr>
                    <th>Where To Send Traffic</th>
                    <td>
                        <div class="flex flex-col gap-2.5 items-start">
                            <?php foreach ($rs_labels as $rsv => $rslabel): ?>
                            <div data-rs="<?php echo esc_attr($rsv); ?>"<?php echo ($rsv === 'found' && $what_to_redirect === 'all_404') ? ' style="display:none;"' : ''; ?>>
                                <label class="dd-opt"><input type="radio" name="redirect_source" value="<?php echo esc_attr($rsv); ?>" <?php checked($redirect_source, $rsv); ?>> <?php echo esc_html($rslabel); ?></label>
                                <p class="dd-hint"><?php echo esc_html($rs_hints[$rsv]); ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>

                <tr id="dd-row-destlinks"<?php echo ($redirect_source === 'found') ? ' style="display:none;"' : ''; ?>>
                    <th>
                        <span id="dd-destlinks-label"><?php echo ($redirect_source === 'transit') ? 'Domain URL' : 'Destination URLs'; ?></span>
                        <span id="dd-destlinks-tip" class="dd-tip"<?php echo ($redirect_source === 'transit') ? '' : ' style="display:none;"'; ?>><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Keep the same URL path, but send it to this domain (e.g. yoursite.com/post/123 → otherdomain.com/post/123).</span></span>
                    </th>
                    <td>
                        <!-- Provided links: input + Add -> list (same logic/style as Custom URLs) -->
                        <div id="rm-provided-block" style="<?php echo ($redirect_source === 'transit') ? 'display:none;' : ''; ?>">
                            <div style="display:flex; gap:8px; align-items:flex-start;">
                                <textarea id="dd-prov-input" rows="3" class="dd-textarea" placeholder="e.g. example.com or https://example.com/landing — one per line" autocomplete="off" style="flex:1;"></textarea>
                                <button type="button" id="dd-prov-add" class="dd-list-addbtn">Add</button>
                            </div>
                            <small id="dd-prov-count" style="display:block;margin-top:8px;"><span id="dd-prov-entered">0</span> link(s) entered</small>
                            <div id="dd-prov-selected" style="margin-top:10px;"></div>
                            <div class="dd-removeall" id="dd-prov-removeall" style="display:none;">
                                <button type="button" class="dd-removeall-btn" data-act="ask">Remove All</button>
                                <span class="dd-removeall-confirm" data-act="confirm" style="display:none;">Remove all? <a class="yes" data-act="yes">Yes</a> / <a class="no" data-act="no">No</a></span>
                            </div>
                        </div>
                        <!-- Transit: one domain only, add + validate, then edit/remove -->
                        <div id="rm-transit-block" style="<?php echo ($redirect_source === 'transit') ? '' : 'display:none;'; ?>">
                            <div id="dd-transit-entry" style="display:flex; gap:8px; align-items:center;">
                                <input type="text" id="dd-transit-input" placeholder="otherdomain.com" autocomplete="off" style="flex:1;">
                                <button type="button" id="dd-transit-add" class="dd-list-addbtn">Add</button>
                            </div>
                            <small id="dd-transit-error" style="display:none;margin-top:6px;color:#ef4444;font-size:12px;"></small>
                            <div id="dd-transit-selected"></div>
                        </div>
                        <textarea name="links_list" id="dd-destlinks-field" style="display:none;"><?php echo esc_textarea($links_list); ?></textarea>
                        <input type="hidden" name="transit_domain" id="dd-transit-store" value="<?php echo esc_attr(devdredi_get_setting('transit_domain', '')); ?>">
                    </td>
                </tr>

                <tr id="dd-row-pagelinks"<?php echo ($redirect_source === 'found') ? '' : ' style="display:none;"'; ?>>
                    <th>Find Links/Buttons Containing</th>
                    <td>
                        <div style="display:flex; gap:8px; align-items:flex-start;">
                            <textarea id="dd-plc-input" rows="3" class="dd-textarea" placeholder="e.g. amazon.com or ebay.com — one per line" autocomplete="off" style="flex:1;"></textarea>
                            <button type="button" id="dd-plc-add" class="dd-list-addbtn">Add</button>
                        </div>
                        <small id="dd-plc-count" style="display:block;margin-top:8px;"><span id="dd-plc-entered">0</span> link(s) entered</small>
                        <div id="dd-plc-selected" style="margin-top:10px;"></div>
                        <div class="dd-removeall" id="dd-plc-removeall" style="display:none;">
                            <button type="button" class="dd-removeall-btn" data-act="ask">Remove All</button>
                            <span class="dd-removeall-confirm" data-act="confirm" style="display:none;">Remove all? <a class="yes" data-act="yes">Yes</a> / <a class="no" data-act="no">No</a></span>
                        </div>
                        <textarea name="page_links_contains" id="dd-plc-store" style="display:none;"><?php echo esc_textarea(devdredi_get_setting('page_links_contains', '')); ?></textarea>
                        <p class="dd-hint">A domain such as amazon.com. Only links pointing there are used. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Enter a domain (e.g. amazon.com or ebay.com). Only external links/buttons pointing to that domain are used — internal links on your own site are ignored.</span></span></p>
                    </td>
                </tr>

                <tr id="dd-row-linkorder"<?php echo ($redirect_source === 'provided') ? '' : ' style="display:none;"'; ?>>
                    <th>
                        Link Order
                        </th>
                    <td>
                        <?php
                        $lm_labels = array('sequential' => 'First to last', 'random' => 'Random', 'descending' => 'Weighted distribution');
                        $lm = isset($lm_labels[$links_mode]) ? $links_mode : 'sequential';
                        ?>
                        <div class="dd-dd w-64" data-name="links_mode">
                            <input type="hidden" name="links_mode" value="<?php echo esc_attr($lm); ?>">
                            <div class="dd-dd-trigger" tabindex="0">
                                <span class="dd-dd-label"><?php echo esc_html($lm_labels[$lm]); ?></span>
                                <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                            <div class="dd-dd-panel">
                                <div class="dd-dd-opt<?php echo ($lm === 'sequential') ? ' is-selected' : ''; ?>" data-value="sequential">First to last</div>
                                <div class="dd-dd-opt<?php echo ($lm === 'random') ? ' is-selected' : ''; ?>" data-value="random">Random</div>
                                <div class="dd-dd-opt<?php echo ($lm === 'descending') ? ' is-selected' : ''; ?>" data-value="descending">Weighted distribution</div>
                            </div>
                        </div>
                        <div id="wp-descending-settings" style="display:none;margin-top:14px;">
                            <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">Click Distribution</div>
                            <div class="dd-spread">
                                <div class="dd-spread-ends">
                                    <span>First link gets most</span>
                                    <span>Even split</span>
                                    <span>Last link gets most</span>
                                </div>
                                <div class="dd-spread-track">
                                    <span class="dd-spread-tick" style="left:25%;"></span>
                                    <span class="dd-spread-tick" style="left:50%;"></span>
                                    <span class="dd-spread-tick" style="left:75%;"></span>
                                    <input type="range" name="descending_spread" min="0" max="1" step="0.01" value="<?php echo esc_attr($descending_spread); ?>">
                                </div>
                            </div>
                            <input type="hidden" name="descending_seed" id="wp-descending-seed" value="<?php echo esc_attr($descending_seed); ?>">
                            <div id="wp-descending-preview" style="margin-top:14px;"></div>
                        </div>
                        <p class="dd-hint">How the destinations are used when the rule has more than one URL. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Choose how destination URLs are used when this rule has more than one URL.</span></span></p>
                    </td>
                </tr>
                <tr id="dd-row-repeat"<?php echo ($redirect_source === 'provided') ? '' : ' style="display:none;"'; ?>>
                    <th>
                        Repeat List
                        </th>
                    <td>
                        <label>
                            <input type="checkbox" name="links_repeat" value="1" <?php checked($links_repeat, 1); ?>>
                            Start again when the list ends
                        </label>
                        <p class="dd-hint">Start again from the first URL once the list is used up. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">When all destination URLs have been used, start again from the first URL.</span></span></p>
                    </td>
                </tr>
                <?php
                // Split UI -> existing backend: run_once (never/ip/ip_ua) + revisit_delay.
                //   always  = never           | once = ip/ip_ua, no delay | window = ip/ip_ua + delay
                $rf_freq    = ($run_once === 'never') ? 'always' : (($revisit_delay > 0) ? 'window' : 'once');
                $rf_matchby = ($run_once === 'ip_ua') ? 'ip_ua' : 'ip';
                $freq_labels = array('always' => 'Every visit', 'once' => 'Once per visitor', 'window' => 'After a delay');
                $match_labels = array('ip' => 'IP address', 'ip_ua' => 'IP + Browser/Device');
                ?>
                <tr>
                    <th>How often to redirect</th>
                    <td>
                        <input type="hidden" name="run_once" id="dd-run-once" value="<?php echo esc_attr($run_once); ?>">
                        <div class="flex items-center gap-2">
                            <div class="dd-dd w-64" data-name="rf_freq">
                                <input type="hidden" name="rf_freq" value="<?php echo esc_attr($rf_freq); ?>">
                                <div class="dd-dd-trigger" tabindex="0">
                                    <span class="dd-dd-label"><?php echo esc_html($freq_labels[$rf_freq]); ?></span>
                                    <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                                <div class="dd-dd-panel">
                                    <div class="dd-dd-opt<?php echo ($rf_freq === 'always') ? ' is-selected' : ''; ?>" data-value="always">Every visit</div>
                                    <div class="dd-dd-opt<?php echo ($rf_freq === 'once') ? ' is-selected' : ''; ?>" data-value="once">Once per visitor</div>
                                    <div class="dd-dd-opt<?php echo ($rf_freq === 'window') ? ' is-selected' : ''; ?>" data-value="window">After a delay</div>
                                </div>
                            </div>
                            <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box" id="rf-freq-tip"></span></span>
                        </div>
                    </td>
                </tr>
                <tr id="dd-row-matchby"<?php echo ($rf_freq !== 'always') ? '' : ' style="display:none;"'; ?>>
                    <th>
                        Identify visitor using
                        </th>
                    <td>
                        <div class="dd-dd w-64" data-name="rf_matchby">
                            <input type="hidden" name="rf_matchby" value="<?php echo esc_attr($rf_matchby); ?>">
                            <div class="dd-dd-trigger" tabindex="0">
                                <span class="dd-dd-label"><?php echo esc_html($match_labels[$rf_matchby]); ?></span>
                                <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                            <div class="dd-dd-panel">
                                <div class="dd-dd-opt<?php echo ($rf_matchby === 'ip') ? ' is-selected' : ''; ?>" data-value="ip">IP address</div>
                                <div class="dd-dd-opt<?php echo ($rf_matchby === 'ip_ua') ? ' is-selected' : ''; ?>" data-value="ip_ua">IP + Browser/Device</div>
                            </div>
                        </div>
                        <p class="dd-hint">IP alone is looser, IP plus browser and device is stricter. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">How a returning visitor is recognized. IP + Browser/Device is stricter (separate phones, browsers, and computers on the same network count as different visitors).</span></span></p>
                    </td>
                </tr>
                <tr id="dd-row-northuser"<?php echo ($rf_freq !== 'always') ? '' : ' style="display:none;"'; ?>>
                    <th>
                        Redirect On N-th User
                        </th>
                    <td>
                        <input type="number" name="open_on_every" id="wp-open-on-every" value="<?php echo esc_attr($open_on_every); ?>"
                            style="width:60px;" min="1">
                        <p class="dd-hint">Redirect only every N-th unique visitor. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Redirect only every N-th unique visitor. Example: set 3 to redirect every 3rd unique visitor.</span></span></p>
                    </td>
                </tr>
                <tr id="dd-row-repeatdelay"<?php echo ($rf_freq === 'window') ? '' : ' style="display:none;"'; ?>>
                    <th>
                        Delay Before Redirecting Again
                        </th>
                    <td>
                        <div class="flex items-center gap-2">
                            <input type="number" name="revisit_delay" id="wp-revisit-delay" value="<?php echo esc_attr($revisit_delay_value); ?>"
                                style="width:60px;" min="0">
                            <div class="dd-dd w-32" data-name="revisit_delay_unit">
                                <input type="hidden" name="revisit_delay_unit" value="<?php echo esc_attr($revisit_delay_unit); ?>">
                                <div class="dd-dd-trigger" tabindex="0">
                                    <span class="dd-dd-label"><?php echo esc_html( ucfirst( $revisit_delay_unit ) ); ?></span>
                                    <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                                <div class="dd-dd-panel">
                                    <div class="dd-dd-opt<?php echo ($revisit_delay_unit === 'minutes') ? ' is-selected' : ''; ?>" data-value="minutes">Minutes</div>
                                    <div class="dd-dd-opt<?php echo ($revisit_delay_unit === 'hours') ? ' is-selected' : ''; ?>" data-value="hours">Hours</div>
                                    <div class="dd-dd-opt<?php echo ($revisit_delay_unit === 'days') ? ' is-selected' : ''; ?>" data-value="days">Days</div>
                                </div>
                            </div>
                        </div>
                        <p class="dd-hint">Time before the same visitor can be redirected again. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Time gap before the same visitor can be redirected again.</span></span></p>
                    </td>
                </tr>
                <tr>
                    <th>Known Bots</th>
                    <td>
                        <label>
                            <input type="checkbox" name="skip_bots" value="1" <?php checked($skip_bots, 1); ?>>
                            Don't redirect known bots
                        </label>
                        <p class="dd-hint">Crawlers, monitors and scrapers see the page as usual; only real visitors are redirected. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Recognised by a built-in list of user-agent tokens (search engine crawlers, uptime monitors, scrapers, headless browsers), plus Spamhaus DROP addresses where the shared DevDome bot data is present on the site. A skipped bot is not redirected, not sent to the bypass link and not counted as a visitor. Keep it on so search engines keep indexing the page.</span></span></p>
                    </td>
                </tr>

            </table>



                </div>
            </section>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-external dd-ico"></span>
                    <h2 class="dd-h2">Open Link Settings</h2>
                </div>
                <div class="dd-card">
            <table class="form-table">
                <tr>
                    <th>Open Link In</th>
                    <td>
                        <div class="dd-dd w-64" data-name="open_mode">
                            <input type="hidden" name="open_mode" value="<?php echo esc_attr($open_mode); ?>">
                            <div class="dd-dd-trigger" tabindex="0">
                                <span class="dd-dd-label"><?php echo ($open_mode === 'new_tab') ? 'New Tab' : 'Same Tab'; ?></span>
                                <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                            <div class="dd-dd-panel">
                                <div class="dd-dd-opt<?php echo ($open_mode !== 'new_tab') ? ' is-selected' : ''; ?>" data-value="same_tab">Same Tab</div>
                                <div class="dd-dd-opt<?php echo ($open_mode === 'new_tab') ? ' is-selected' : ''; ?>" data-value="new_tab">New Tab</div>
                            </div>
                        </div>
                    </td>
                </tr>

                <tr id="wp-same-tab-delay" style="display:none;">
                    <th>Same Tab Link Delay</th>
                    <td>
                        <?php $same_tab_is_delay = ($same_tab_delay_min > 0 || $same_tab_delay_max > 0); ?>
                        <div class="flex flex-col gap-2.5 items-start">
                            <label style="margin-right:20px;">
                                <input type="radio" name="same_tab_delay_mode" value="instant" <?php checked(!$same_tab_is_delay); ?>>
                                Instant
                            </label>
                            <label style="margin-right:20px;">
                                <input type="radio" name="same_tab_delay_mode" value="delay" <?php checked($same_tab_is_delay); ?>>
                                Delay (seconds)
                                <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Random delay (seconds) between these two values before redirecting.</span></span>
                            </label>
                        </div>
                        <div id="wp-same-tab-delay-fields" style="margin-top:10px;<?php echo $same_tab_is_delay ? '' : 'display:none;'; ?>">
                            <input type="number" step="0.001" name="same_tab_delay_min"
                                value="<?php echo esc_attr($same_tab_delay_min); ?>" style="width:60px;">
                            —
                            <input type="number" step="0.001" name="same_tab_delay_max"
                                value="<?php echo esc_attr($same_tab_delay_max); ?>" style="width:60px;">
                        </div>
                    </td>
                </tr>

                <tr id="wp-same-tab-click" style="display:none;">
                    <th>Redirect On Click</th>
                    <td>
                        <label>
                            <input type="checkbox" name="same_tab_require_click" value="1" <?php checked($same_tab_require_click, 1); ?>>
                            Only redirect after the visitor clicks
                        </label>
                        <p class="dd-hint">Wait for a click or tap before redirecting. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Wait for the visitor to click/tap anywhere before redirecting, instead of redirecting automatically.</span></span></p>
                    </td>
                </tr>

                <tr id="wp-same-tab-after-click" style="display:none;">
                    <th>After Click Delay</th>
                    <td>
                        <label>
                            <input type="checkbox" name="same_tab_after_click_enabled" value="1" <?php checked($same_tab_after_click_enabled, 1); ?>>
                            Wait a delay after the click
                        </label>
                        <div id="wp-same-tab-after-click-fields" style="margin-top:10px;<?php echo $same_tab_after_click_enabled ? '' : 'display:none;'; ?>">
                            <input type="number" step="0.001" min="0" max="4" name="same_tab_after_click_min"
                                value="<?php echo esc_attr($same_tab_after_click_min); ?>" style="width:60px;">
                            —
                            <input type="number" step="0.001" min="0" max="4" name="same_tab_after_click_max"
                                value="<?php echo esc_attr($same_tab_after_click_max); ?>" style="width:60px;">
                        </div>
                        <p class="dd-hint">Random wait after the click, in seconds. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">After the visitor clicks, wait a random time (seconds) between these two values before redirecting. Max 4 seconds.</span></span></p>
                    </td>
                </tr>

                <tr id="wp-new-tab-delay" style="display:none;">
                    <th>New Tab Link Delay</th>
                    <td>
                        <?php $new_tab_is_delay = ($new_tab_delay_min > 0 || $new_tab_delay_max > 0); ?>
                        <div class="flex flex-col gap-2.5 items-start">
                            <label style="margin-right:20px;">
                                <input type="radio" name="new_tab_delay_mode" value="instant" <?php checked(!$new_tab_is_delay); ?>>
                                Instant
                            </label>
                            <label style="margin-right:20px;">
                                <input type="radio" name="new_tab_delay_mode" value="delay" <?php checked($new_tab_is_delay); ?>>
                                Delay (seconds)
                                <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">How it works, in order: 1) the page loads normally; 2) a timer counts down (a random wait between the two values below, so it looks natural); 3) once the timer ends, the next time the visitor clicks anywhere on the page, the new tab opens to your destination. Clicks before the timer ends do nothing. (A new tab can only open on a real click — browsers block tabs that open by themselves.)</span></span>
                            </label>
                        </div>
                        <div id="wp-new-tab-delay-fields" style="margin-top:10px;<?php echo $new_tab_is_delay ? '' : 'display:none;'; ?>">
                            <input type="number" step="0.001" min="0" name="new_tab_delay_min"
                                value="<?php echo esc_attr($new_tab_delay_min); ?>" style="width:60px;">
                            —
                            <input type="number" step="0.001" min="0" name="new_tab_delay_max"
                                value="<?php echo esc_attr($new_tab_delay_max); ?>" style="width:60px;">
                        </div>
                    </td>
                </tr>
                <tr id="wp-after-click-delay" style="display:none;">
                    <th>After Click Delay</th>
                    <td>
                        <label>
                            <input type="checkbox" name="after_click_enabled" value="1" <?php checked($after_click_enabled, 1); ?>>
                            Wait a delay after the click
                        </label>
                        <div id="wp-after-click-delay-fields" style="margin-top:10px;<?php echo $after_click_enabled ? '' : 'display:none;'; ?>">
                            <input type="number" step="0.001" min="0" max="4" name="after_click_min"
                                value="<?php echo esc_attr($after_click_min); ?>" style="width:60px;">
                            —
                            <input type="number" step="0.001" min="0" max="4" name="after_click_max"
                                value="<?php echo esc_attr($after_click_max); ?>" style="width:60px;">
                        </div>
                        <p class="dd-hint">Random wait after the click before the new tab opens, in seconds. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">After the visitor clicks, wait a random time (seconds) between these two values before the new tab opens. Max 4 seconds — longer gets blocked by the browser's pop-up blocker.</span></span></p>
                    </td>
                </tr>
            </table>




                </div>
            </section>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-admin-site-alt3 dd-ico"></span>
                    <h2 class="dd-h2">Geo Filter Settings</h2>
                </div>
                <div class="dd-card">
                <div>
            <table class="form-table">

                <tr>
                    <th>Geo Service</th>
                    <td>
                        <button type="button" class="dd-btn" id="wp-ipgeo-health" data-label="Test geo service">Test geo service</button>
                        <p class="dd-hint">Run the test once before enabling the geo filter. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Before setting up the geo filter below, run this test to confirm the service is reachable from your server.</span></span></p>
                    </td>
                </tr>

                <tr>
                    <th>Geo Filtering</th>
                    <td>
                        <label>
                            <input type="checkbox" name="geo_filter_enabled" id="geo_filter_enabled" value="1" <?php checked(devdredi_get_setting('geo_filter_enabled', 0), 1); ?>>
                            Enable
                        </label>
                    </td>
                </tr>

                <?php $geo_on = (int) devdredi_get_setting('geo_filter_enabled', 0); ?>
                <tr class="dd-geo-row"<?php echo $geo_on ? '' : ' style="display:none;"'; ?>>
                    <th>Filter mode</th>
                    <td>
                        <div class="flex flex-col gap-2.5 items-start">
                            <label style="margin-right:20px;">
                                <input type="radio" name="geo_filter_mode" value="whitelist" <?php checked($geo_filter_mode !== 'blacklist'); ?>>
                                Redirect selected countries only
                            </label>
                            <label style="margin-right:20px;">
                                <input type="radio" name="geo_filter_mode" value="blacklist" <?php checked($geo_filter_mode === 'blacklist'); ?>>
                                Redirect everyone except these countries
                            </label>
                        </div>
                    </td>
                </tr>

                <tr class="dd-geo-row"<?php echo $geo_on ? '' : ' style="display:none;"'; ?>>
                    <th>Countries</th>
                    <td>
                        <div style="position:relative; max-width:640px;">
                            <input type="text" id="geo_country_search" placeholder="Search countries&hellip;" autocomplete="off" style="width:100%;">
                            <div class="dd-dd-panel" id="geo_country_list" style="display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); z-index:20; max-height:274px; overflow:auto;"></div>
                        </div>
                        <div id="geo_selected_countries" style="margin-top:10px; max-width:640px;"></div>
                        <div class="dd-removeall" id="geo-removeall" style="display:none;">
                            <button type="button" class="dd-removeall-btn" data-act="ask">Remove All</button>
                            <span class="dd-removeall-confirm" data-act="confirm" style="display:none;">Remove all? <a class="yes" data-act="yes">Yes</a> / <a class="no" data-act="no">No</a></span>
                        </div>
                        <input type="hidden" id="geo_filter_whitelist" name="geo_filter_whitelist" value="<?php echo esc_attr($geo_whitelist); ?>">
                        <input type="hidden" id="geo_filter_blacklist" name="geo_filter_blacklist" value="<?php echo esc_attr($geo_blacklist); ?>">
                    </td>
                </tr>

                <tr class="dd-geo-row"<?php echo $geo_on ? '' : ' style="display:none;"'; ?>>
                    <th>Site Behind Proxy / CDN</th>
                    <td>
                        <label>
                            <input type="checkbox" name="trust_proxy" id="trust_proxy" value="1" <?php checked(devdredi_get_setting('trust_proxy', 0), 1); ?>>
                            Trust forwarded IP headers
                        </label>
                        <div style="margin-top:8px;">
                            <button type="button" class="dd-btn" id="wp-proxy-detect" data-label="Detect automatically">Detect automatically</button>
                        </div>
                        <p class="dd-hint">Behind Cloudflare or another CDN, tick this so real visitor IPs are used. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Not sure? Click Detect — it checks whether this site sits behind a proxy/CDN and ticks the box for you. Then Save.</span></span></p>
                    </td>
                </tr>

            </table>


                </div>
                </div>
            </section>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-smartphone dd-ico"></span>
                    <h2 class="dd-h2">Device Targeting</h2>
                </div>
                <div class="dd-card">
                <div>
            <table class="form-table">
                <tr>
                    <th>Devices to Redirect</th>
                    <td>
                        <label style="margin-right:20px;">
                            <input type="checkbox" name="device_desktop" value="1" <?php checked(devdredi_get_setting('device_desktop', 1), 1); ?>>
                            Desktop
                        </label>
                        <label style="margin-right:20px;">
                            <input type="checkbox" name="device_mobile" value="1" <?php checked(devdredi_get_setting('device_mobile', 1), 1); ?>>
                            Mobile
                        </label>
                        <label>
                            <input type="checkbox" name="device_tablet" value="1" <?php checked(devdredi_get_setting('device_tablet', 1), 1); ?>>
                            Tablet
                        </label>
                        <p class="dd-hint">Unchecked device types follow the Not Redirected Visitors setting. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Only redirect visitors on the checked device types; unchecked types pass through to your "Not Redirected Visitors" setting. Detected from the browser User-Agent.</span></span></p>
                    </td>
                </tr>
            </table>
                </div>
                </div>
            </section>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-admin-generic dd-ico"></span>
                    <h2 class="dd-h2">Optional Settings</h2>
                </div>
                <div class="dd-card">

            <table class="form-table">
                <tr>
                    <th>Purge Page Cache On Save</th>
                    <td>
                        <label>
                            <input type="checkbox" name="purge_cache_on_save" value="1" <?php checked(devdredi_get_setting('purge_cache_on_save', 1), 1); ?>>
                            Enable
                        </label>
                        <p class="description">Works with WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, WP-Optimize, Cache Enabler, Hummingbird and Breeze.</p>
                        <p class="dd-hint">Clears cached copies of the targeted pages so the redirect works at once. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Caching plugins keep serving a stored copy of a page, which can hide a new redirect until their cache expires. With this on, saving or starting a rule clears the stored copies of the pages this rule targets, so the redirect works right away. Rules that cover the entire website clear the whole page cache.</span></span></p>
                    </td>
                </tr>
                <?php $fm = devdredi_get_setting('fallback_mode', 'leave'); if ($fm !== 'send') { $fm = 'leave'; } ?>
                <tr>
                    <th>Not Redirected Visitors</th>
                    <td>
                        <div class="dd-dd w-64" data-name="fallback_mode">
                            <input type="hidden" name="fallback_mode" value="<?php echo esc_attr($fm); ?>">
                            <div class="dd-dd-trigger" tabindex="0">
                                <span class="dd-dd-label"><?php echo ($fm === 'send') ? 'Send To Bypass Link' : 'Leave On Page'; ?></span>
                                <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                            <div class="dd-dd-panel">
                                <div class="dd-dd-opt<?php echo ($fm !== 'send') ? ' is-selected' : ''; ?>" data-value="leave">Leave On Page</div>
                                <div class="dd-dd-opt<?php echo ($fm === 'send') ? ' is-selected' : ''; ?>" data-value="send">Send To Bypass Link</div>
                            </div>
                        </div>
                        <p class="dd-hint">What happens to visitors the rule does not match. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">What to do with visitors who do not meet the redirect criteria.</span></span></p>
                    </td>
                </tr>
                <tr id="dd-row-bypass"<?php echo ($fm === 'send') ? '' : ' style="display:none;"'; ?>>
                    <th>Bypass Link</th>
                    <td>
                        <div id="dd-bypass-entry" style="display:flex; gap:8px; align-items:center; max-width:480px;">
                            <input type="text" id="dd-bypass-input" placeholder="https://google.com" autocomplete="off" style="flex:1;">
                            <button type="button" id="dd-bypass-add" class="dd-list-addbtn">Add</button>
                        </div>
                        <small id="dd-bypass-error" style="display:none;margin-top:6px;color:#ef4444;font-size:12px;"></small>
                        <div id="dd-bypass-selected" style="max-width:480px;"></div>
                        <input type="hidden" name="fallback_url" id="dd-bypass-store" value="<?php echo esc_attr(devdredi_get_setting('fallback_url', '')); ?>">
                    </td>
                </tr>
            </table>


                </div>
            </section>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-clock dd-ico"></span>
                    <h2 class="dd-h2">Scheduling</h2>
                </div>
                <div class="dd-card">
                <div>
            <table class="form-table">
                <tr>
                    <th>Schedule Mode</th>
                    <td>
                        <?php $rm = devdredi_get_setting('run_mode', 'unlimited'); ?>
                        <div class="dd-dd w-64" data-name="run_mode">
                            <input type="hidden" name="run_mode" value="<?php echo esc_attr($rm); ?>">
                            <div class="dd-dd-trigger" tabindex="0">
                                <span class="dd-dd-label"><?php echo ($rm === 'set_time') ? 'Custom Schedule' : 'Always Active'; ?></span>
                                <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                            <div class="dd-dd-panel">
                                <div class="dd-dd-opt<?php echo ($rm !== 'set_time') ? ' is-selected' : ''; ?>" data-value="unlimited">Always Active</div>
                                <div class="dd-dd-opt<?php echo ($rm === 'set_time') ? ' is-selected' : ''; ?>" data-value="set_time">Custom Schedule</div>
                            </div>
                        </div>
                        <p class="dd-hint">Always active, or only at the days and times you set below. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Always Active runs the rule all the time. Custom Schedule lets you set exactly when it runs: timezone, campaign start/end dates, total run time, weekdays, and time-of-day windows.</span></span></p>
                    </td>
                </tr>
                <tbody id="run-settings-details"<?php echo (devdredi_get_setting('run_mode', 'unlimited') === 'set_time') ? '' : ' style="display:none;"'; ?>>
                <tr>
                    <th>Timezone</th>
                    <td>
                        <?php $sched_tz = devdredi_get_setting('schedule_timezone', ''); ?>
                        <select name="schedule_timezone" style="min-width:240px;height:34px;padding:0 12px;border:1px solid #9ca3af;border-radius:8px;box-shadow:0 1px 2px 0 rgba(0,0,0,.05);background:#fff;font-size:13px;font-weight:600;color:#374151;">
                            <option value="" <?php selected($sched_tz, ''); ?>>Site default (WordPress timezone)</option>
                            <?php foreach (timezone_identifiers_list() as $tz) : ?>
                                <option value="<?php echo esc_attr($tz); ?>" <?php selected($sched_tz, $tz); ?>><?php echo esc_html($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="dd-hint">The days and times below are read in this timezone. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Weekdays and times below are evaluated in this timezone. Default uses the site's WordPress timezone.</span></span></p>
                    </td>
                </tr>
                <tr>
                    <th>Campaign Dates</th>
                    <td>
                        <?php $sched_start = devdredi_get_setting('schedule_start_date', ''); $sched_end = devdredi_get_setting('schedule_end_date', ''); ?>
                        <label>From <input type="date" name="schedule_start_date" value="<?php echo esc_attr($sched_start); ?>" style="height:34px;padding:0 10px;border:1px solid #9ca3af;border-radius:8px;font-size:13px;"></label>
                        <label style="margin-left:10px;">To <input type="date" name="schedule_end_date" value="<?php echo esc_attr($sched_end); ?>" style="height:34px;padding:0 10px;border:1px solid #9ca3af;border-radius:8px;font-size:13px;"></label>
                        <p class="description">Leave blank for no date limit.</p>
                        <p class="dd-hint">Optional start and end dates, inclusive. Blank means no limit. <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">Optional. The rule only runs between these dates (inclusive), in the timezone above. Leave blank for no date limit.</span></span></p>
                    </td>
                </tr>
                <tr>
                    <th>Run Time</th>
                    <td>
                        <label>Days:
                            <input type="number" name="run_time_days" value="<?php echo esc_attr($run_time_days); ?>"
                                style="width:60px;">
                        </label>
                        <label>Hours:
                            <input type="number" name="run_time_hours" value="<?php echo esc_attr($run_time_hours); ?>"
                                style="width:60px;">
                        </label>
                        <label>Minutes:
                            <input type="number" name="run_time_minutes" value="<?php echo esc_attr($run_time_mins); ?>"
                                style="width:60px;">
                        </label><br>
                        <p class="description">If none set will run Unlimited time.</p>
                    </td>
                </tr>
                <tr>
                    <th>Run On Weekdays</th>
                    <td>
                        <?php
                        $days_labels = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];
                        foreach ($days_labels as $num => $label) {
                            ?>
                            <label style="margin-right:10px;">
                                <input type="checkbox" name="weekday_<?php echo esc_attr( $num ); ?>" value="1" <?php checked(in_array($num, $run_weekdays), true); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php
                        }
                        ?>
                        <p class="description">If none selected, runs every day.</p>
                    </td>
                </tr>
                <tr>
                    <th>Run On Specific Times</th>
                    <td>
                        <?php
                        for ($i = 0; $i < 3; $i++) {
                            $enable = !empty($specific_times[$i]['enable']);
                            $start = $specific_times[$i]['start'];
                            $end = $specific_times[$i]['end'];
                            ?>
                            <div style="margin-bottom:8px;">
                                <label>
                                    <input type="checkbox" name="specific_time_<?php echo (int) ( $i + 1 ); ?>_enable" value="1" <?php checked($enable, 1); ?>>
                                    Enable Interval <?php echo (int) ( $i + 1 ); ?>
                                </label><br>
                                <label>Start:
                                    <input type="time" name="specific_time_<?php echo (int) ( $i + 1 ); ?>_start"
                                        value="<?php echo esc_attr($start); ?>">
                                </label>
                                <label>End:
                                    <input type="time" name="specific_time_<?php echo (int) ( $i + 1 ); ?>_end"
                                        value="<?php echo esc_attr($end); ?>">
                                </label>
                            </div>
                            <?php
                        }
                        ?>
                        <p class="description">If none enabled, runs all day (within selected weekdays).</p>
                    </td>
                </tr>
                </tbody>
            </table>
                </div>
                </div>
            </section>


                <?php if ($run_mode === 'set_time' && $total_minutes > 0): ?>
                    <?php devdredi_admin_js_capture(function () use ($rm_active_pos) { ?>
                        document.addEventListener('DOMContentLoaded', function () {
                            var countdownEl = document.getElementById('devdredi-countdown');
                            if (!countdownEl) return;
                            var timeLeft = parseInt(countdownEl.getAttribute('data-time-left'), 10);
                            function formatTime(d, h, m, s) {
                                var parts = [];
                                if (d > 0) parts.push(d + 'd');
                                if (d > 0 || h > 0) parts.push(h + 'h');
                                parts.push(m + 'm');
                                parts.push(s + 's');
                                return parts.join(' ');
                            }
                            function updateCountdown() {
                                if (timeLeft < 0) timeLeft = 0;
                                var days = Math.floor(timeLeft / 86400);
                                var hours = Math.floor((timeLeft % 86400) / 3600);
                                var mins = Math.floor((timeLeft % 3600) / 60);
                                var secs = timeLeft % 60;
                                countdownEl.textContent = formatTime(days, hours, mins, secs);

                                if (timeLeft < 60) {
                                    countdownEl.style.color = 'red';
                                } else {
                                    countdownEl.style.color = '#0073aa';
                                }

                                if (timeLeft > 0) {
                                    timeLeft--;
                                    setTimeout(updateCountdown, 1000);
                                } else {
                                    var timerContainer = document.getElementById('devdredi-timer-container');
                                    if (timerContainer) {
                                        timerContainer.style.display = 'none';
                                    }

                                    var statusText = document.getElementById('devdredi-status-text');
                                    if (statusText) {
                                        statusText.textContent = 'Status: STOPPED';
                                        statusText.style.color = 'red';
                                    }

                                    var mainButton = document.getElementById('devdredi-main-button');
                                    if (mainButton) {
                                        mainButton.name = 'devdredi_run';
                                        mainButton.textContent = 'Save & Run Rule #<?php echo (int) $rm_active_pos; ?>';
                                        mainButton.className = 'button button-primary';
                                        mainButton.style.background = '';
                                        mainButton.style.color = '';
                                        mainButton.style.borderColor = '';
                                    }
                                }
                            }
                            updateCountdown();
                        });
                    document.addEventListener('click',function(e){if(e.target && e.target.dataset.copy){e.target.select(); document.execCommand('copy');}});
                    <?php }); ?>
                <?php endif; ?>

            <?php devdredi_admin_js_capture(function () { ?>
                document.addEventListener('DOMContentLoaded', function () {
                    var form = document.querySelector('form[action=""]') || document.querySelector('form');
                    if (!form) return;
                    
                    var field_links_list = form.querySelector('textarea[name="links_list"]');
                    var field_selected_links = form.querySelector('textarea[name="selected_links_list"]');
                    var field_links_mode_radios = form.querySelectorAll('input[name="links_mode"]');
                    var field_redirect_type = form.querySelector('[name="redirect_type"]');
                    var field_same_tab_delay_min = form.querySelector('input[name="same_tab_delay_min"]');
                    var field_same_tab_delay_max = form.querySelector('input[name="same_tab_delay_max"]');
                    var field_open_mode = form.querySelector('[name="open_mode"]');
                    var field_fallback_mode = form.querySelector('[name="fallback_mode"]');
                    var field_fallback_url = document.getElementById('dd-bypass-store'); // the hidden store that carries fallback_url
                    
                    function syncRedirectType() {
                        var selectedType = 'js';
                        if (field_redirect_type) selectedType = field_redirect_type.value;
                        
                        var isServerSide = (selectedType !== 'js');

                        var openModeDd = document.querySelector('.dd-dd[data-name="open_mode"]');
                        if (isServerSide) {
                            // 301/302 can only open in the same tab — lock the dropdown to Same Tab.
                            if (window.ddSetSelect) window.ddSetSelect('open_mode', 'same_tab');
                            if (openModeDd) openModeDd.classList.add('is-disabled');
                        } else {
                            if (openModeDd) openModeDd.classList.remove('is-disabled');
                        }

                        if (field_same_tab_delay_min) {
                            field_same_tab_delay_min.disabled = isServerSide;
                            if (isServerSide) field_same_tab_delay_min.value = '0';
                            field_same_tab_delay_min.style.backgroundColor = isServerSide ? '#eee' : '';
                        }
                        if (field_same_tab_delay_max) {
                            field_same_tab_delay_max.disabled = isServerSide;
                            if (isServerSide) field_same_tab_delay_max.value = '0';
                            field_same_tab_delay_max.style.backgroundColor = isServerSide ? '#eee' : '';
                        }

                    }
                    
                    if (field_redirect_type) {
                        field_redirect_type.addEventListener('change', syncRedirectType);
                    }

                    // Same Tab Link Delay: Instant vs Delay radios -> show/hide the min/max inputs.
                    var stModeRadios = form.querySelectorAll('input[name="same_tab_delay_mode"]');
                    var stFields = document.getElementById('wp-same-tab-delay-fields');
                    var stClickCheckbox = form.querySelector('input[name="same_tab_require_click"]');
                    var stAfterEnable = form.querySelector('input[name="same_tab_after_click_enabled"]');
                    var stAfterFields = document.getElementById('wp-same-tab-after-click-fields');
                    // After Click Delay row shows only when: same-tab delay active AND "Redirect On Click" checked.
                    function syncSameTabAfterClick() {
                        var mode = 'instant';
                        stModeRadios.forEach(function (r) { if (r.checked) mode = r.value; });
                        var show = (mode === 'delay') && stClickCheckbox && stClickCheckbox.checked;
                        var afterRow = document.getElementById('wp-same-tab-after-click');
                        if (afterRow) afterRow.style.display = show ? 'table-row' : 'none';
                    }
                    // Enable checkbox reveals the from–to fields.
                    function syncSameTabAfterFields() {
                        if (stAfterFields) stAfterFields.style.display = (stAfterEnable && stAfterEnable.checked) ? '' : 'none';
                    }
                    function syncSameTabDelayMode() {
                        var mode = 'instant';
                        stModeRadios.forEach(function (r) { if (r.checked) mode = r.value; });
                        var isDelay = (mode === 'delay');
                        if (stFields) stFields.style.display = isDelay ? '' : 'none';
                        // "Redirect On Click" only makes sense with a delay (instant = immediate, no click to wait for).
                        var clickRow = document.getElementById('wp-same-tab-click');
                        if (clickRow) clickRow.style.display = isDelay ? 'table-row' : 'none';
                        if (!isDelay) {
                            if (field_same_tab_delay_min) field_same_tab_delay_min.value = '0';
                            if (field_same_tab_delay_max) field_same_tab_delay_max.value = '0';
                            if (stClickCheckbox) stClickCheckbox.checked = false;
                        }
                        syncSameTabAfterClick();
                    }
                    stModeRadios.forEach(function (r) { r.addEventListener('change', syncSameTabDelayMode); });
                    if (stClickCheckbox) stClickCheckbox.addEventListener('change', syncSameTabAfterClick);
                    if (stAfterEnable) stAfterEnable.addEventListener('change', syncSameTabAfterFields);
                    syncSameTabDelayMode();
                    syncSameTabAfterFields();

                    // New Tab Link Delay: Instant vs Delay radios -> show/hide the min/max inputs (max 4s).
                    var ntModeRadios = form.querySelectorAll('input[name="new_tab_delay_mode"]');
                    var ntFields = document.getElementById('wp-new-tab-delay-fields');
                    var field_new_tab_delay_min = form.querySelector('input[name="new_tab_delay_min"]');
                    var field_new_tab_delay_max = form.querySelector('input[name="new_tab_delay_max"]');
                    function syncNewTabDelayMode() {
                        var mode = 'instant';
                        ntModeRadios.forEach(function (r) { if (r.checked) mode = r.value; });
                        var isDelay = (mode === 'delay');
                        if (ntFields) ntFields.style.display = isDelay ? '' : 'none';
                        // After Click Delay only applies when a delay is used (Instant = open immediately on click).
                        var acRow = document.getElementById('wp-after-click-delay');
                        if (acRow) acRow.style.display = isDelay ? 'table-row' : 'none';
                        if (!isDelay) {
                            if (field_new_tab_delay_min) field_new_tab_delay_min.value = '0';
                            if (field_new_tab_delay_max) field_new_tab_delay_max.value = '0';
                        }
                    }
                    ntModeRadios.forEach(function (r) { r.addEventListener('change', syncNewTabDelayMode); });
                    syncNewTabDelayMode();

                    // New Tab After Click Delay: enable checkbox reveals the from–to fields.
                    var ntAfterEnable = form.querySelector('input[name="after_click_enabled"]');
                    var ntAfterFields = document.getElementById('wp-after-click-delay-fields');
                    function syncNewTabAfterFields() {
                        if (ntAfterFields) ntAfterFields.style.display = (ntAfterEnable && ntAfterEnable.checked) ? '' : 'none';
                    }
                    if (ntAfterEnable) ntAfterEnable.addEventListener('change', syncNewTabAfterFields);
                    syncNewTabAfterFields();

                    // Clamp every After Click Delay value to 4s max (pop-up blocker window).
                    ['after_click_min', 'after_click_max', 'same_tab_after_click_min', 'same_tab_after_click_max'].forEach(function (n) {
                        var f = form.querySelector('input[name="' + n + '"]');
                        if (f) f.addEventListener('change', function () {
                            if (parseFloat(f.value) > 4) f.value = '4';
                            if (parseFloat(f.value) < 0 || isNaN(parseFloat(f.value))) f.value = '0';
                        });
                    });

                    syncRedirectType();

                    function syncFallback() {
                        var mode = field_fallback_mode ? field_fallback_mode.value : 'leave';
                        var send = (mode === 'send');
                        var row = document.getElementById('dd-row-bypass');
                        if (row) row.style.display = send ? '' : 'none';
                        if (field_fallback_url) field_fallback_url.disabled = !send; // disabled => not submitted when Leave
                    }

                    if (field_fallback_mode) field_fallback_mode.addEventListener('change', syncFallback);

                    syncFallback();

                    var field_run_mode = form.querySelector('[name="run_mode"]');
                    var runSettingsDetails = document.getElementById('run-settings-details');

                    function syncRunMode() {
                        var mode = field_run_mode ? field_run_mode.value : 'unlimited';
                        if (runSettingsDetails) {
                            runSettingsDetails.style.display = (mode === 'unlimited') ? 'none' : '';
                        }
                    }

                    if (field_run_mode) {
                        field_run_mode.addEventListener('change', syncRunMode);
                    }

                    syncRunMode();


                    // Redirect Frequency split: two visible dropdowns (rf_freq + rf_matchby) compute hidden run_once.
                    var field_rf_freq = form.querySelector('[name="rf_freq"]');
                    var field_rf_match = form.querySelector('[name="rf_matchby"]');
                    var field_run_once = document.getElementById('dd-run-once');
                    var field_open_on_every = document.getElementById('wp-open-on-every');
                    var field_revisit_delay = document.getElementById('wp-revisit-delay');
                    var rowMatchBy   = document.getElementById('dd-row-matchby');
                    var rowOpenEvery = document.getElementById('dd-row-northuser');
                    var rowRevisit   = document.getElementById('dd-row-repeatdelay');
                    var freqTip = document.getElementById('rf-freq-tip');
                    var RF_FREQ_HINTS = {
                        always: 'The visitor is redirected every time they match this rule.',
                        once:   'Redirect each visitor only once, ever.',
                        window: 'Redirect each visitor, then again only after the delay you set below.'
                    };

                    function syncFreq() {
                        var freq = field_rf_freq ? field_rf_freq.value : 'always';
                        var match = field_rf_match ? field_rf_match.value : 'ip';
                        // Map to the existing backend value.
                        if (field_run_once) field_run_once.value = (freq === 'always') ? 'never' : match;

                        var notAlways = (freq !== 'always');
                        if (rowMatchBy)   rowMatchBy.style.display   = notAlways ? '' : 'none';
                        if (rowOpenEvery) rowOpenEvery.style.display = notAlways ? '' : 'none';
                        if (rowRevisit)   rowRevisit.style.display   = (freq === 'window') ? '' : 'none';
                        if (!notAlways && field_open_on_every) field_open_on_every.value = '1';
                        if (freq !== 'window' && field_revisit_delay) field_revisit_delay.value = '0';
                        if (freqTip && RF_FREQ_HINTS[freq]) freqTip.textContent = RF_FREQ_HINTS[freq];
                    }

                    if (field_rf_freq) field_rf_freq.addEventListener('change', syncFreq);
                    if (field_rf_match) field_rf_match.addEventListener('change', syncFreq);
                    syncFreq();

                    var ddLinkOk = (typeof window.ddIsLink === 'function') ? window.ddIsLink : function (l) { return l.trim().length > 0; };
                    function updateLinkCounters() {
                        var linksCountEl = document.getElementById('devdredi-count');
                        if (field_links_list && linksCountEl) {
                            var lines = field_links_list.value.split("\n").filter(ddLinkOk);
                            linksCountEl.textContent = lines.length + ' link(s) added';
                        }
                        
                        var selectedLinksCountEl = document.getElementById('wp-selected-links-count');
                        if (field_selected_links && selectedLinksCountEl) {
                            var lines = field_selected_links.value.split("\n")
                                .map(function (l) { return l.trim(); })
                                .filter(function (l) { return l.length > 0; });
                            selectedLinksCountEl.textContent = lines.length + ' link(s) added';
                        }
                    }
                    
                    if (field_links_list) field_links_list.addEventListener('input', updateLinkCounters);
                    if (field_selected_links) field_selected_links.addEventListener('input', updateLinkCounters);
                    updateLinkCounters();
                    
                    var field_links_mode = form.querySelector('[name="links_mode"]');
                    var descRow = document.getElementById('wp-descending-settings');
                    var preview = document.getElementById('wp-descending-preview');
                    var seedInput = document.getElementById('wp-descending-seed');
                    var spreadInput = form.querySelector('input[name="descending_spread"]');
                    var linksTextarea = form.querySelector('textarea[name="links_list"]');
                    var ipgeoBtn = document.getElementById('wp-ipgeo-health');

                    function currentMode() {
                        return field_links_mode ? field_links_mode.value : 'sequential';
                    }
                    function parseLinks() {
                        if (!linksTextarea) return [];
                        return linksTextarea.value.split('\n').map(function(s){return s.trim();}).filter(Boolean);
                    }
                    function renderPreview() {
                        if (!preview) return;
                        var links = parseLinks();
                        var spread = 0.5;
                        if (spreadInput) {
                            var parsedSpread = parseFloat(spreadInput.value);
                            if (!isNaN(parsedSpread)) {
                                spread = parsedSpread;
                            }
                        }
                        if (spread < 0) spread = 0;
                        if (spread > 1) spread = 1;
                        var n = links.length;
                        if (n === 0) { preview.innerHTML = ''; return; }

                        // Weighted split: total ALWAYS exactly 1000, every link >=1, and all values
                        // strictly distinct (no ties). spread 0.5 = even; <0.5 first link heaviest; >0.5 last.
                        var clicks = (function () {
                            var baseSum = n * (n + 1) / 2;                   // staircase 1+2+...+n
                            if (n < 2 || baseSum >= 1000) {                  // not enough room for distinct values -> even-ish
                                var even = [], b = Math.floor(1000 / n), r0 = 1000 - b * n, o = [];
                                for (var i = 0; i < n; i++) o.push(b + (i < r0 ? 1 : 0));
                                return o;
                            }
                            // Dead center = exactly even split (only place ties are allowed).
                            if (Math.abs(spread - 0.5) < 1e-9) {
                                var b0 = Math.floor(1000 / n), r0 = 1000 - b0 * n, eq = [];
                                for (var i = 0; i < n; i++) eq.push(b0 + (i < r0 ? 1 : 0));
                                return eq;
                            }
                            var t = Math.abs(spread - 0.5) / 0.5;           // 0 (even) .. 1 (extreme)
                            var ratio = 1 - 0.85 * t;                        // 1 even .. 0.15 steep
                            if (ratio < 0.05) ratio = 0.05;
                            var w = [], sumW = 0;
                            for (var i = 0; i < n; i++) {
                                var pwr = (spread <= 0.5) ? i : (n - 1 - i); // which end is heaviest
                                var val = Math.pow(ratio, pwr);
                                w.push(val); sumW += val;
                            }
                            if (!sumW) sumW = 1;

                            // Rank order from lightest -> heaviest; give staircase base 1,2,...,n so
                            // every value is already strictly different, then add weighted share of the rest.
                            var rank = [];
                            for (var i = 0; i < n; i++) rank.push(i);
                            rank.sort(function (a, b) { return w[a] - w[b]; }); // ascending weight
                            var out = new Array(n);
                            for (var k = 0; k < n; k++) out[rank[k]] = k + 1;  // 1..n staircase

                            var pool = 1000 - baseSum;                        // remaining clicks to distribute
                            var raw = [], floored = [], used = 0;
                            for (var i = 0; i < n; i++) {
                                var rv = (w[i] / sumW) * pool;
                                raw.push(rv);
                                var fl = Math.floor(rv);
                                floored.push(fl);
                                out[i] += fl;
                                used += fl;
                            }
                            var leftover = pool - used;
                            var order = [];
                            for (var i = 0; i < n; i++) order.push(i);
                            order.sort(function (a, b) { return (raw[b] - floored[b]) - (raw[a] - floored[a]); });
                            for (var k2 = 0; k2 < leftover; k2++) out[order[k2 % n]]++;
                            return out;
                        })();
                        var html = '<table class="widefat striped" style="width:100%; max-width:720px; border-collapse: collapse;">'+
                                   '<thead><tr>'+
                                   '<th style="text-align:left; padding: 8px 10px;">#</th>'+
                                   '<th style="text-align:left; padding: 8px 10px;">Link</th>'+
                                   '<th style="text-align:center; padding: 8px 10px;">%</th>'+
                                   '<th style="text-align:center; padding: 8px 10px;">Clicks/1000</th>'+
                                   '</tr></thead><tbody>';
                        for (var i = 0; i < n; i++) {
                            var share = clicks[i] / 1000;
                            var pct = (share * 100).toFixed(2);
                            html += '<tr>'+
                                    '<td style="text-align:left; padding: 8px 10px;">'+(i+1)+'</td>'+
                                    '<td style="text-align:left; padding: 8px 10px;">'+String(links[i]).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; })+'</td>'+
                                    '<td style="text-align:center; padding: 8px 10px;">'+pct+'%</td>'+
                                    '<td style="text-align:center; padding: 8px 10px;">'+clicks[i]+'</td>'+
                                    '</tr>';
                        }
                        html += '</tbody></table>';
                        preview.innerHTML = html;
                    }
                    function syncVisibility() {
                        if (!descRow) return;
                        descRow.style.display = currentMode() === 'descending' ? '' : 'none';
                        if (currentMode() === 'descending') renderPreview();
                    }
                    if (field_links_mode) field_links_mode.addEventListener('change', syncVisibility);
                    if (spreadInput) {
                        spreadInput.addEventListener('input', renderPreview);
                        spreadInput.addEventListener('dblclick', function(){
                            this.value = '0.5';
                            renderPreview();
                        });
                    }
                    if (linksTextarea) linksTextarea.addEventListener('input', renderPreview);
                    if (ipgeoBtn) {
                        var ipgeoBusy = false;
                        ipgeoBtn.addEventListener('click', function () {
                            if (ipgeoBusy) return;
                            ipgeoBusy = true;
                            var orig = ipgeoBtn.getAttribute('data-label') || ipgeoBtn.textContent;
                            ipgeoBtn.disabled = true;
                            ipgeoBtn.textContent = 'Checking…';
                            ipgeoBtn.style.color = '';

                            var restUrl = <?php echo wp_json_encode( rest_url('devdredi/v1/ipgeo-health') ); ?>;
                            fetch(restUrl, {
                                method: 'GET',
                                headers: { 'Accept': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce('wp_rest') ); ?> },
                                credentials: 'same-origin'
                            }).then(function (r) {
                                return r.text().then(function (t) {
                                    try { return JSON.parse(t); } catch (e) { throw new Error('Bad response'); }
                                });
                            }).then(function (res) {
                                if (res && (res.success || res.status === 'ok')) {
                                    ipgeoBtn.textContent = '✓ Geo service OK';
                                    ipgeoBtn.style.color = '#065f46';
                                } else {
                                    ipgeoBtn.textContent = '✕ Geo service down — try again later';
                                    ipgeoBtn.style.color = '#b91c1c';
                                }
                            }).catch(function () {
                                ipgeoBtn.textContent = '✕ Geo service down — try again later';
                                ipgeoBtn.style.color = '#b91c1c';
                            }).then(function () {
                                // 30s cooldown. The server also caches the health result 30s, so the
                                // upstream geo service can't be hammered even by hitting the endpoint directly.
                                var resultText = ipgeoBtn.textContent;
                                var left = 30;
                                var tick = setInterval(function () {
                                    left--;
                                    if (left <= 0) {
                                        clearInterval(tick);
                                        ipgeoBtn.textContent = orig;
                                        ipgeoBtn.style.color = '';
                                        ipgeoBtn.disabled = false;
                                        ipgeoBusy = false;
                                    } else {
                                        ipgeoBtn.textContent = resultText + ' · ' + left + 's';
                                    }
                                }, 1000);
                            });
                        });
                    }

                    // "Detect automatically" for the proxy/CDN setting: inspect this request's
                    // forwarding headers server-side, then tick the box with the recommendation.
                    var proxyBtn = document.getElementById('wp-proxy-detect');
                    var trustCb = document.getElementById('trust_proxy');
                    if (proxyBtn) {
                        var proxyBusy = false;
                        proxyBtn.addEventListener('click', function () {
                            if (proxyBusy) return;
                            proxyBusy = true;
                            var orig = proxyBtn.getAttribute('data-label') || proxyBtn.textContent;
                            proxyBtn.disabled = true;
                            proxyBtn.textContent = 'Detecting…';
                            proxyBtn.style.color = '';
                            fetch(<?php echo wp_json_encode( rest_url('devdredi/v1/proxy-detect') ); ?>, {
                                method: 'GET',
                                headers: { 'Accept': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce('wp_rest') ); ?> },
                                credentials: 'same-origin'
                            }).then(function (r) { return r.json(); }).then(function (res) {
                                if (res && res.detected) {
                                    if (trustCb) trustCb.checked = true;
                                    proxyBtn.textContent = '✓ ' + res.label + ' detected — turned on (Save to keep)';
                                    proxyBtn.style.color = '#065f46';
                                } else {
                                    if (trustCb) trustCb.checked = false;
                                    proxyBtn.textContent = '• No proxy detected — leave this off';
                                    proxyBtn.style.color = '#6b7280';
                                }
                            }).catch(function () {
                                proxyBtn.textContent = '✕ Detection failed';
                                proxyBtn.style.color = '#b91c1c';
                            }).then(function () {
                                setTimeout(function () {
                                    proxyBtn.textContent = orig;
                                    proxyBtn.style.color = '';
                                    proxyBtn.disabled = false;
                                    proxyBusy = false;
                                }, 5000);
                            });
                        });
                    }

                    syncVisibility();
                });
            <?php }); ?>




            


        <?php
        $ip_list = devdredi_get_setting('ip_list', array());
        if (!is_array($ip_list)) { $ip_list = array(); }
        $ua_list = devdredi_get_setting('ua_list', array());
        if (!is_array($ua_list)) { $ua_list = array(); }
        $is_running = ($plugin_state === 'running');
        ?>
        <footer class="dd-footer">
            <div class="dd-footer-inner">

                <div class="dd-footer-actions">
                    <?php if ($is_running): ?>
                        <button type="submit" name="devdredi_stop" id="devdredi-main-button" class="dd-btn-danger" value="1">Stop Rule #<?php echo (int) $rm_active_pos; ?></button>
                    <?php else: ?>
                        <button type="submit" name="devdredi_run" id="devdredi-main-button" class="dd-btn-primary" value="1">Save &amp; Run Rule #<?php echo (int) $rm_active_pos; ?></button>
                    <?php endif; ?>
                    <button type="submit" name="devdredi_save" class="dd-save-btn" value="1" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;background:#4f46e5;color:#fff;padding:10px 24px;border-radius:8px;font-size:14px;font-weight:600;border:0;cursor:pointer;box-shadow:0 10px 15px -3px rgba(99,102,241,.3),0 4px 6px -4px rgba(99,102,241,.3);transition:all .15s;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>Save Settings</button>
                    <style>.dd-save-btn:hover{background:#4338ca !important;}</style>
                    <span id="dd-running-for" class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-gray-500" style="display:none;">Running For: <span id="dd-running-for-val" class="text-indigo-600">—</span></span>
                    <?php if ($is_running): ?>
                        <span id="devdredi-timer-container" class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-gray-500">Time Left:
                            <?php if ($run_mode === 'unlimited' || $total_minutes == 0): ?>
                                <span id="devdredi-countdown" class="text-indigo-600">Unlimited</span>
                            <?php else: ?>
                                <span id="devdredi-countdown" class="text-indigo-600" data-time-left="<?php echo esc_attr($time_left_seconds); ?>"></span>
                            <?php endif; ?>
                        </span>
                        <span id="devdredi-status-text" class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-600 whitespace-nowrap"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Running</span>
                    <?php else: ?>
                        <span id="devdredi-status-text" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 whitespace-nowrap"><span class="w-2 h-2 rounded-full bg-gray-400"></span>Stopped</span>
                    <?php endif; ?>
                    <button type="submit" name="devdredi_return" class="dd-btn" style="display:none;" value="1">Return to Default</button>
                </div>

            </div>
        </footer>
        </div><!-- /.max-w-5xl (page content) -->
        
    <?php devdredi_admin_js_capture(function () { ?>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('devdredi-form');

            var selectedBlock = document.getElementById('devdredi-selected-block');
            var selectedRow = selectedBlock ? selectedBlock.closest('tr') : null;

            var whatField = form ? form.querySelector('[name="what_to_redirect"]:checked') : null;
            function updateWhatToRedirectUI() {
                if (!selectedRow || !form) return;
                var value = whatField ? whatField.value : '';
                if (value === 'selected_existing' || value === 'custom_urls') {
                    selectedRow.style.display = 'table-row';
                } else {
                    selectedRow.style.display = 'none';
                }
            }

            if (form) {
                if (whatField) whatField.addEventListener('change', updateWhatToRedirectUI);
                var whatRadios = [];
                whatRadios.forEach(function (radio) {
                    radio.addEventListener('change', updateWhatToRedirectUI);
                });
                updateWhatToRedirectUI();
            }

            var openModeInput = form ? form.querySelector('[name="open_mode"]') : null;
            var sameTabRow = document.getElementById('wp-same-tab-delay');
            var newTabRow = document.getElementById('wp-new-tab-delay');
            var afterClickRow = document.getElementById('wp-after-click-delay');

            var sameTabClickRow = document.getElementById('wp-same-tab-click');
            function updateOpenModeUI() {
                if (!form) return;
                var mode = openModeInput ? openModeInput.value : 'same_tab';
                if (mode === 'same_tab') {
                    if (sameTabRow) sameTabRow.style.display = 'table-row';
                    // Click row only when a delay is active (instant = immediate, no click to wait for).
                    var stDelayFields = document.getElementById('wp-same-tab-delay-fields');
                    var stHasDelay = stDelayFields && stDelayFields.style.display !== 'none';
                    if (sameTabClickRow) sameTabClickRow.style.display = stHasDelay ? 'table-row' : 'none';
                    // Same-tab After Click Delay: only when delay active AND "Redirect On Click" checked.
                    var stAfterRow = document.getElementById('wp-same-tab-after-click');
                    var stClick = form.querySelector('input[name="same_tab_require_click"]');
                    if (stAfterRow) stAfterRow.style.display = (stHasDelay && stClick && stClick.checked) ? 'table-row' : 'none';
                    if (newTabRow) newTabRow.style.display = 'none';
                    if (afterClickRow) afterClickRow.style.display = 'none';
                } else {
                    if (sameTabRow) sameTabRow.style.display = 'none';
                    if (sameTabClickRow) sameTabClickRow.style.display = 'none';
                    var stAfterRowHide = document.getElementById('wp-same-tab-after-click');
                    if (stAfterRowHide) stAfterRowHide.style.display = 'none';
                    if (newTabRow) newTabRow.style.display = 'table-row';
                    // After Click Delay only when a new-tab delay is active.
                    var ntDelayFields = document.getElementById('wp-new-tab-delay-fields');
                    var ntHasDelay = ntDelayFields && ntDelayFields.style.display !== 'none';
                    if (afterClickRow) afterClickRow.style.display = ntHasDelay ? 'table-row' : 'none';
                }
            }
            if (openModeInput) openModeInput.addEventListener('change', updateOpenModeUI);
            updateOpenModeUI();



            ((Form) => {

                let Selected_Links = Form.querySelector("textarea[name=selected_links_list]");

                let Counter = document.getElementById("wp-selected-links-count");

                if (!Selected_Links || !Counter) return false;


            })(form);

            (() => {
                let Checkbox = document.getElementById("geo_filter_enabled");
                if (!Checkbox) return;
                let rows = document.querySelectorAll('.dd-geo-row');
                let Toggle = () => {
                    rows.forEach(function (r) { r.style.display = Checkbox.checked ? "table-row" : "none"; });
                };
                Checkbox.addEventListener("change", Toggle);
                Toggle();
            })();

        });
    <?php }); ?>

    <?php devdredi_admin_js_capture(function () { ?>
        document.addEventListener('DOMContentLoaded', function () {
            var countries = [
                { code: "AF", name: "Afghanistan" },
                { code: "AX", name: "Åland Islands" },
                { code: "AL", name: "Albania" },
                { code: "DZ", name: "Algeria" },
                { code: "AS", name: "American Samoa" },
                { code: "AD", name: "Andorra" },
                { code: "AO", name: "Angola" },
                { code: "AI", name: "Anguilla" },
                { code: "AQ", name: "Antarctica" },
                { code: "AG", name: "Antigua and Barbuda" },
                { code: "AR", name: "Argentina" },
                { code: "AM", name: "Armenia" },
                { code: "AW", name: "Aruba" },
                { code: "AU", name: "Australia" },
                { code: "AT", name: "Austria" },
                { code: "AZ", name: "Azerbaijan" },
                { code: "BS", name: "Bahamas" },
                { code: "BH", name: "Bahrain" },
                { code: "BD", name: "Bangladesh" },
                { code: "BB", name: "Barbados" },
                { code: "BY", name: "Belarus" },
                { code: "BE", name: "Belgium" },
                { code: "BZ", name: "Belize" },
                { code: "BJ", name: "Benin" },
                { code: "BM", name: "Bermuda" },
                { code: "BT", name: "Bhutan" },
                { code: "BO", name: "Bolivia, Plurinational State of" },
                { code: "BQ", name: "Bonaire, Sint Eustatius and Saba" },
                { code: "BA", name: "Bosnia and Herzegovina" },
                { code: "BW", name: "Botswana" },
                { code: "BV", name: "Bouvet Island" },
                { code: "BR", name: "Brazil" },
                { code: "IO", name: "British Indian Ocean Territory" },
                { code: "BN", name: "Brunei Darussalam" },
                { code: "BG", name: "Bulgaria" },
                { code: "BF", name: "Burkina Faso" },
                { code: "BI", name: "Burundi" },
                { code: "KH", name: "Cambodia" },
                { code: "CM", name: "Cameroon" },
                { code: "CA", name: "Canada" },
                { code: "CV", name: "Cape Verde" },
                { code: "KY", name: "Cayman Islands" },
                { code: "CF", name: "Central African Republic" },
                { code: "TD", name: "Chad" },
                { code: "CL", name: "Chile" },
                { code: "CN", name: "China" },
                { code: "CX", name: "Christmas Island" },
                { code: "CC", name: "Cocos (Keeling) Islands" },
                { code: "CO", name: "Colombia" },
                { code: "KM", name: "Comoros" },
                { code: "CG", name: "Congo" },
                { code: "CD", name: "Congo, the Democratic Republic of the" },
                { code: "CK", name: "Cook Islands" },
                { code: "CR", name: "Costa Rica" },
                { code: "CI", name: "Côte d'Ivoire" },
                { code: "HR", name: "Croatia" },
                { code: "CU", name: "Cuba" },
                { code: "CW", name: "Curaçao" },
                { code: "CY", name: "Cyprus" },
                { code: "CZ", name: "Czech Republic" },
                { code: "DK", name: "Denmark" },
                { code: "DJ", name: "Djibouti" },
                { code: "DM", name: "Dominica" },
                { code: "DO", name: "Dominican Republic" },
                { code: "EC", name: "Ecuador" },
                { code: "EG", name: "Egypt" },
                { code: "SV", name: "El Salvador" },
                { code: "GQ", name: "Equatorial Guinea" },
                { code: "ER", name: "Eritrea" },
                { code: "EE", name: "Estonia" },
                { code: "ET", name: "Ethiopia" },
                { code: "FK", name: "Falkland Islands (Malvinas)" },
                { code: "FO", name: "Faroe Islands" },
                { code: "FJ", name: "Fiji" },
                { code: "FI", name: "Finland" },
                { code: "FR", name: "France" },
                { code: "GF", name: "French Guiana" },
                { code: "PF", name: "French Polynesia" },
                { code: "TF", name: "French Southern Territories" },
                { code: "GA", name: "Gabon" },
                { code: "GM", name: "Gambia" },
                { code: "GE", name: "Georgia" },
                { code: "DE", name: "Germany" },
                { code: "GH", name: "Ghana" },
                { code: "GI", name: "Gibraltar" },
                { code: "GR", name: "Greece" },
                { code: "GL", name: "Greenland" },
                { code: "GD", name: "Grenada" },
                { code: "GP", name: "Guadeloupe" },
                { code: "GU", name: "Guam" },
                { code: "GT", name: "Guatemala" },
                { code: "GG", name: "Guernsey" },
                { code: "GN", name: "Guinea" },
                { code: "GW", name: "Guinea-Bissau" },
                { code: "GY", name: "Guyana" },
                { code: "HT", name: "Haiti" },
                { code: "HM", name: "Heard Island and McDonald Islands" },
                { code: "VA", name: "Holy See (Vatican City State)" },
                { code: "HN", name: "Honduras" },
                { code: "HK", name: "Hong Kong" },
                { code: "HU", name: "Hungary" },
                { code: "IS", name: "Iceland" },
                { code: "IN", name: "India" },
                { code: "ID", name: "Indonesia" },
                { code: "IR", name: "Iran, Islamic Republic of" },
                { code: "IQ", name: "Iraq" },
                { code: "IE", name: "Ireland" },
                { code: "IM", name: "Isle of Man" },
                { code: "IL", name: "Israel" },
                { code: "IT", name: "Italy" },
                { code: "JM", name: "Jamaica" },
                { code: "JP", name: "Japan" },
                { code: "JE", name: "Jersey" },
                { code: "JO", name: "Jordan" },
                { code: "KZ", name: "Kazakhstan" },
                { code: "KE", name: "Kenya" },
                { code: "KI", name: "Kiribati" },
                { code: "KP", name: "Korea, Democratic People's Republic of" },
                { code: "KR", name: "Korea, Republic of" },
                { code: "KW", name: "Kuwait" },
                { code: "KG", name: "Kyrgyzstan" },
                { code: "LA", name: "Lao People's Democratic Republic" },
                { code: "LV", name: "Latvia" },
                { code: "LB", name: "Lebanon" },
                { code: "LS", name: "Lesotho" },
                { code: "LR", name: "Liberia" },
                { code: "LY", name: "Libya" },
                { code: "LI", name: "Liechtenstein" },
                { code: "LT", name: "Lithuania" },
                { code: "LU", name: "Luxembourg" },
                { code: "MO", name: "Macao" },
                { code: "MK", name: "Macedonia, The Former Yugoslav Republic of" },
                { code: "MG", name: "Madagascar" },
                { code: "MW", name: "Malawi" },
                { code: "MY", name: "Malaysia" },
                { code: "MV", name: "Maldives" },
                { code: "ML", name: "Mali" },
                { code: "MT", name: "Malta" },
                { code: "MH", name: "Marshall Islands" },
                { code: "MQ", name: "Martinique" },
                { code: "MR", name: "Mauritania" },
                { code: "MU", name: "Mauritius" },
                { code: "YT", name: "Mayotte" },
                { code: "MX", name: "Mexico" },
                { code: "FM", name: "Micronesia, Federated States of" },
                { code: "MD", name: "Moldova, Republic of" },
                { code: "MC", name: "Monaco" },
                { code: "MN", name: "Mongolia" },
                { code: "ME", name: "Montenegro" },
                { code: "MS", name: "Montserrat" },
                { code: "MA", name: "Morocco" },
                { code: "MZ", name: "Mozambique" },
                { code: "MM", name: "Myanmar" },
                { code: "NA", name: "Namibia" },
                { code: "NR", name: "Nauru" },
                { code: "NP", name: "Nepal" },
                { code: "NL", name: "Netherlands" },
                { code: "NC", name: "New Caledonia" },
                { code: "NZ", name: "New Zealand" },
                { code: "NI", name: "Nicaragua" },
                { code: "NE", name: "Niger" },
                { code: "NG", name: "Nigeria" },
                { code: "NU", name: "Niue" },
                { code: "NF", name: "Norfolk Island" },
                { code: "MP", name: "Northern Mariana Islands" },
                { code: "NO", name: "Norway" },
                { code: "OM", name: "Oman" },
                { code: "PK", name: "Pakistan" },
                { code: "PW", name: "Palau" },
                { code: "PS", name: "Palestinian Territory, Occupied" },
                { code: "PA", name: "Panama" },
                { code: "PG", name: "Papua New Guinea" },
                { code: "PY", name: "Paraguay" },
                { code: "PE", name: "Peru" },
                { code: "PH", name: "Philippines" },
                { code: "PN", name: "Pitcairn" },
                { code: "PL", name: "Poland" },
                { code: "PT", name: "Portugal" },
                { code: "PR", name: "Puerto Rico" },
                { code: "QA", name: "Qatar" },
                { code: "RE", name: "Réunion" },
                { code: "RO", name: "Romania" },
                { code: "RU", name: "Russian Federation" },
                { code: "RW", name: "Rwanda" },
                { code: "BL", name: "Saint Barthélemy" },
                { code: "SH", name: "Saint Helena, Ascension and Tristan da Cunha" },
                { code: "KN", name: "Saint Kitts and Nevis" },
                { code: "LC", name: "Saint Lucia" },
                { code: "MF", name: "Saint Martin (French part)" },
                { code: "PM", name: "Saint Pierre and Miquelon" },
                { code: "VC", name: "Saint Vincent and the Grenadines" },
                { code: "WS", name: "Samoa" },
                { code: "SM", name: "San Marino" },
                { code: "ST", name: "Sao Tome and Principe" },
                { code: "SA", name: "Saudi Arabia" },
                { code: "SN", name: "Senegal" },
                { code: "RS", name: "Serbia" },
                { code: "SC", name: "Seychelles" },
                { code: "SL", name: "Sierra Leone" },
                { code: "SG", name: "Singapore" },
                { code: "SX", name: "Sint Maarten (Dutch part)" },
                { code: "SK", name: "Slovakia" },
                { code: "SI", name: "Slovenia" },
                { code: "SB", name: "Solomon Islands" },
                { code: "SO", name: "Somalia" },
                { code: "ZA", name: "South Africa" },
                { code: "GS", name: "South Georgia and the South Sandwich Islands" },
                { code: "SS", name: "South Sudan" },
                { code: "ES", name: "Spain" },
                { code: "LK", name: "Sri Lanka" },
                { code: "SD", name: "Sudan" },
                { code: "SR", name: "Suriname" },
                { code: "SJ", name: "Svalbard and Jan Mayen" },
                { code: "SZ", name: "Swaziland" },
                { code: "SE", name: "Sweden" },
                { code: "CH", name: "Switzerland" },
                { code: "SY", name: "Syrian Arab Republic" },
                { code: "TW", name: "Taiwan, Province of China" },
                { code: "TJ", name: "Tajikistan" },
                { code: "TZ", name: "Tanzania, United Republic of" },
                { code: "TH", name: "Thailand" },
                { code: "TL", name: "Timor-Leste" },
                { code: "TG", name: "Togo" },
                { code: "TK", name: "Tokelau" },
                { code: "TO", name: "Tonga" },
                { code: "TT", name: "Trinidad and Tobago" },
                { code: "TN", name: "Tunisia" },
                { code: "TR", name: "Turkey" },
                { code: "TM", name: "Turkmenistan" },
                { code: "TC", name: "Turks and Caicos Islands" },
                { code: "TV", name: "Tuvalu" },
                { code: "UG", name: "Uganda" },
                { code: "UA", name: "Ukraine" },
                { code: "AE", name: "United Arab Emirates" },
                { code: "GB", name: "United Kingdom" },
                { code: "US", name: "United States" },
                { code: "UM", name: "United States Minor Outlying Islands" },
                { code: "UY", name: "Uruguay" },
                { code: "UZ", name: "Uzbekistan" },
                { code: "VU", name: "Vanuatu" },
                { code: "VE", name: "Venezuela, Bolivarian Republic of" },
                { code: "VN", name: "Viet Nam" },
                { code: "VG", name: "Virgin Islands, British" },
                { code: "VI", name: "Virgin Islands, U.S." },
                { code: "WF", name: "Wallis and Futuna" },
                { code: "EH", name: "Western Sahara" },
                { code: "YE", name: "Yemen" },
                { code: "ZM", name: "Zambia" },
                { code: "ZW", name: "Zimbabwe" }
            ];

            var searchInput = document.getElementById('geo_country_search');
            var countryList = document.getElementById('geo_country_list');
            var selectedList = document.getElementById('geo_selected_countries');
            var storeWhite = document.getElementById('geo_filter_whitelist');
            var storeBlack = document.getElementById('geo_filter_blacklist');
            var geoRemoveAll = document.getElementById('geo-removeall');

            if (searchInput && countryList && selectedList && storeWhite && storeBlack) {
                // Each mode keeps its own separate list; the picker operates on the active mode's store.
                var activeStore = function () {
                    var m = document.querySelector('input[name="geo_filter_mode"]:checked');
                    return (m && m.value === 'blacklist') ? storeBlack : storeWhite;
                };
                var getCodes = function () {
                    return (activeStore().value || '').split(',').map(function (c) { return c.trim().toUpperCase(); }).filter(Boolean);
                };
                var setCodes = function (codes) { activeStore().value = codes.join(','); };
                var nameOf = function (code) { var c = countries.find(function (x) { return x.code === code; }); return c ? c.name : code; };

                var renderSelected = function () {
                    selectedList.innerHTML = '';
                    var codes = getCodes();
                    if (geoRemoveAll) {
                        geoRemoveAll.style.display = codes.length ? '' : 'none';
                        var ab = geoRemoveAll.querySelector('[data-act="ask"]'), cb = geoRemoveAll.querySelector('[data-act="confirm"]');
                        if (ab) ab.style.display = ''; if (cb) cb.style.display = 'none';
                    }
                    if (!codes.length) return;
                    var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
                    var head = document.createElement('div'); head.className = 'dd-sel-head'; head.textContent = 'Selected countries (' + codes.length + ')';
                    wrap.appendChild(head);
                    codes.forEach(function (code) {
                        var row = document.createElement('div'); row.className = 'dd-sel-row';
                        var info = document.createElement('span'); info.textContent = nameOf(code) + ' (' + code + ')'; info.style.minWidth = '0';
                        var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '12px'; actions.style.flexShrink = '0';
                        var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode('Redirects: '));
                        var hitsNum = document.createElement('span'); hitsNum.className = 'dd-sel-hits-num'; hitsNum.textContent = String((window.DEVDREDI_COUNTS && window.DEVDREDI_COUNTS.country && window.DEVDREDI_COUNTS.country[code]) || 0); hits.appendChild(hitsNum);
                        var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = 'Remove'; x.textContent = 'Remove';
                        x.addEventListener('click', function () { setCodes(getCodes().filter(function (c) { return c !== code; })); renderSelected(); renderResults(); });
                        actions.appendChild(hits); actions.appendChild(x);
                        row.appendChild(info); row.appendChild(actions);
                        wrap.appendChild(row);
                    });
                    selectedList.appendChild(wrap);
                };
                window.wpRenderSelectedCountries = renderSelected;

                var addCode = function (code) {
                    var codes = getCodes();
                    if (codes.indexOf(code) !== -1) return;
                    codes.push(code); setCodes(codes); renderSelected();
                };

                var renderResults = function () {
                    var q = (searchInput.value || '').trim().toLowerCase();
                    countryList.innerHTML = '';
                    if (q === '') { countryList.style.display = 'none'; return; }
                    var codes = getCodes(), shown = 0;
                    countries.forEach(function (c) {
                        if (shown >= 50) return;
                        if (c.name.toLowerCase().indexOf(q) === -1 && c.code.toLowerCase().indexOf(q) === -1) return;
                        if (codes.indexOf(c.code) !== -1) return; // already selected
                        shown++;
                        var opt = document.createElement('div'); opt.className = 'dd-geo-opt';
                        var label = document.createElement('span'); label.textContent = c.name + ' (' + c.code + ')';
                        var add = document.createElement('span'); add.className = 'dd-geo-add'; add.textContent = '+ Add';
                        opt.appendChild(label); opt.appendChild(add);
                        opt.addEventListener('click', function () { addCode(c.code); searchInput.value = ''; renderResults(); searchInput.focus(); });
                        countryList.appendChild(opt);
                    });
                    if (!shown) {
                        var empty = document.createElement('div'); empty.className = 'dd-geo-empty'; empty.textContent = 'No matches';
                        countryList.appendChild(empty);
                    }
                    countryList.style.display = 'block';
                };

                searchInput.addEventListener('input', renderResults);
                searchInput.addEventListener('focus', renderResults);
                document.addEventListener('click', function (e) {
                    if (!searchInput.parentNode.contains(e.target)) countryList.style.display = 'none';
                });

                // Switching mode swaps the picker to that mode's own list.
                document.querySelectorAll('input[name="geo_filter_mode"]').forEach(function (r) {
                    r.addEventListener('change', function () {
                        searchInput.value = '';
                        countryList.style.display = 'none';
                        renderSelected();
                    });
                });

                if (geoRemoveAll) {
                    geoRemoveAll.addEventListener('click', function (e) {
                        var a = e.target.getAttribute('data-act');
                        var ab = geoRemoveAll.querySelector('[data-act="ask"]'), cb = geoRemoveAll.querySelector('[data-act="confirm"]');
                        if (a === 'ask') { ab.style.display = 'none'; cb.style.display = ''; }
                        else if (a === 'no') { ab.style.display = ''; cb.style.display = 'none'; }
                        else if (a === 'yes') { setCodes([]); renderSelected(); renderResults(); }
                    });
                }

                renderSelected();
            }
        });

    <?php }); ?>


        </form>
        </main>
    </div>
    <?php devdredi_admin_js_capture(function () { ?>
    /* Preserve scroll position across the Save (full POST reload). */
    (function () {
        var KEY = 'devdredi_scroll';
        var form = document.getElementById('devdredi-form');
        if (form) form.addEventListener('submit', function () {
            try { sessionStorage.setItem(KEY, String(window.scrollY || window.pageYOffset || 0)); } catch (e) {}
        });
        window.addEventListener('load', function () {
            try {
                var y = sessionStorage.getItem(KEY);
                if (y !== null) { sessionStorage.removeItem(KEY); window.scrollTo(0, parseInt(y, 10) || 0); }
            } catch (e) {}
        });
    })();
    <?php }); ?>
    <?php devdredi_admin_js_capture(function () { ?>
    /* Instant (no-reload) rule switching: repopulate the whole form from preloaded per-rule
       settings. Falls back to the normal ?rule= navigation if anything is unavailable. */
    (function () {
        var SWITCH_NONCE = <?php echo wp_json_encode(wp_create_nonce('devdredi_rule_action')); ?>;
        var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        function q(n){ return document.querySelector('[name="'+n+'"]'); }
        function all(n){ return document.querySelectorAll('[name="'+n+'"]'); }
        function fire(el){ if(el) el.dispatchEvent(new Event('change', { bubbles:true })); }
        function setVal(n,v){ var e=q(n); if(e){ e.value = (v==null?'':v); } }
        function setNum(n,v){ var e=q(n); if(e){ e.value = (v===''||v==null)?'0':v; } }
        function setCheck(n,v){ var e=q(n); if(e){ e.checked = !!(parseInt(v,10)||0); fire(e); } }
        function setRadio(n,val){ all(n).forEach(function(r){ r.checked = (r.value===val); }); var c=document.querySelector('[name="'+n+'"]:checked'); fire(c); }
        function setStore(id,v){ var e=document.getElementById(id); if(e){ e.value = (v==null?'':v); } }
        function dd(n,v){ if(window.ddSetSelect) window.ddSetSelect(n, v==null?'':String(v)); }

        function applyRule(id, row){
            var s = window.DEVDREDI_RULES[id];
            var ro = s.run_once || 'never';
            var rev = parseInt(s.revisit_delay,10) || 0;
            var unit = s.revisit_delay_unit || 'minutes';
            var mult = unit==='days'?1440:(unit==='hours'?60:1);

            // list-widget stores first, then reinit them
            setStore('dd-destlinks-field', s.links_list);
            setStore('dd-custom-store', s.custom_links_list);
            var refList = document.getElementById('dd-referrer-list'); if (refList) { refList.value = s.referrer_list || ''; }
            setStore('dd-pick-store', s.selected_links_list);
            setStore('dd-pick-meta', s.selected_links_meta);
            setStore('dd-plc-store', s.page_links_contains);
            setStore('dd-transit-store', s.transit_domain);
            setStore('dd-bypass-store', s.fallback_url);
            setStore('geo_filter_whitelist', s.geo_filter_whitelist);
            setStore('geo_filter_blacklist', s.geo_filter_blacklist);
            var roEl = document.getElementById('dd-run-once'); if(roEl) roEl.value = ro;

            // checkboxes
            [['links_repeat',s.links_repeat],['after_click_enabled',s.after_click_enabled],
             ['same_tab_require_click',s.same_tab_require_click],['same_tab_after_click_enabled',s.same_tab_after_click_enabled],
             ['geo_filter_enabled',s.geo_filter_enabled],['trust_proxy',s.trust_proxy],
             ['device_desktop',s.device_desktop],['device_mobile',s.device_mobile],['device_tablet',s.device_tablet],
             ['purge_cache_on_save',s.purge_cache_on_save],['referrer_only_selected',s.referrer_only_selected],['skip_bots',s.skip_bots],['outside_only',s.outside_only]
            ].forEach(function(p){ setCheck(p[0],p[1]); });

            // numbers
            [['same_tab_delay_min',s.same_tab_delay_min],['same_tab_delay_max',s.same_tab_delay_max],
             ['new_tab_delay_min',s.new_tab_delay_min],['new_tab_delay_max',s.new_tab_delay_max],
             ['after_click_min',s.after_click_min],['after_click_max',s.after_click_max],
             ['same_tab_after_click_min',s.same_tab_after_click_min],['same_tab_after_click_max',s.same_tab_after_click_max],
             ['descending_seed',s.descending_seed]
            ].forEach(function(p){ setNum(p[0],p[1]); });

            // derived: runtime split, revisit value, open-on-every
            var rt = parseInt(s.runtime_minutes,10) || 0;
            setNum('run_time_days', Math.floor(rt/1440));
            setNum('run_time_hours', Math.floor((rt/60)%24));
            setNum('run_time_minutes', rt%60);
            setNum('revisit_delay', (ro==='never')?0:Math.floor(rev/mult));
            setNum('open_on_every', (ro==='never')?1:(parseInt(s.open_on_every,10)||1));

            // range (spread) — fire input so its distribution preview refreshes
            var sp = q('descending_spread'); if(sp){ sp.value = (s.descending_spread===''||s.descending_spread==null)?0.5:s.descending_spread; sp.dispatchEvent(new Event('input',{bubbles:true})); fire(sp); }

            // timezone select
            setVal('schedule_timezone', s.schedule_timezone); fire(q('schedule_timezone'));
            setVal('schedule_start_date', s.schedule_start_date); setVal('schedule_end_date', s.schedule_end_date);

            // weekdays
            var wd = Array.isArray(s.run_weekdays) ? s.run_weekdays.map(Number) : [];
            for(var d=1; d<=7; d++){ var w=q('weekday_'+d); if(w) w.checked = wd.indexOf(d)!==-1; }

            // specific times (3 rows)
            var st = Array.isArray(s.specific_times) ? s.specific_times : [];
            for(var i=1;i<=3;i++){ var r=st[i-1]||{}; var en=q('specific_time_'+i+'_enable'); if(en) en.checked = !!(parseInt(r.enable,10)||0); setVal('specific_time_'+i+'_start', r.start||'00:00'); setVal('specific_time_'+i+'_end', r.end||'23:59'); }

            // delay-mode radios (derived from min/max)
            var stDelay = (parseFloat(s.same_tab_delay_min)||0)>0 || (parseFloat(s.same_tab_delay_max)||0)>0;
            var ntDelay = (parseFloat(s.new_tab_delay_min)||0)>0 || (parseFloat(s.new_tab_delay_max)||0)>0;

            // radios (fire change to refresh conditional rows)
            setRadio('geo_filter_mode', (s.geo_filter_mode==='blacklist')?'blacklist':'whitelist');
            setRadio('same_tab_delay_mode', stDelay?'delay':'instant');
            setRadio('new_tab_delay_mode', ntDelay?'delay':'instant');
            setRadio('what_to_redirect', s.what_to_redirect||'entire_website');
            setRadio('redirect_source', s.redirect_source||'provided');

            // custom dropdowns (each fires change -> reveals refresh; rf_* recompute run_once)
            dd('redirect_type', s.redirect_type||'js');
            dd('links_mode', s.links_mode||'sequential');
            dd('open_mode', s.open_mode||'same_tab');
            dd('run_mode', s.run_mode||'unlimited');
            dd('fallback_mode', s.fallback_mode||'leave');
            dd('revisit_delay_unit', unit);
            dd('rf_matchby', (ro==='ip_ua')?'ip_ua':'ip');
            dd('rf_freq', (ro==='never')?'always':((rev>0)?'window':'once'));

            // rebuild stateful list widgets + geo chips
            (window.DEVDREDI_REINIT||[]).forEach(function(fn){ try{ fn(); }catch(e){} });
            if (window.wpRenderSelectedCountries) { try{ window.wpRenderSelectedCountries(); }catch(e){} }

            // footer + active row + the rule that Save/Run/Stop will target
            var ed = document.getElementById('dd-editing-rule'); if(ed) ed.value = id;
            var nk = document.getElementById('dd-rule-nickname'); if(nk) nk.value = s.nickname||'';
            var rows = [].slice.call(document.querySelectorAll('.dd-rule-row'));
            rows.forEach(function(r){ var on = (r===row); r.classList.toggle('is-active', on); var ni = r.querySelector('.dd-rule-name'); if (ni) { ni.readOnly = !on; ni.style.background = on ? '#fff' : '#f9fafb'; } });
            var pos = rows.indexOf(row)+1;
            var running = (s.plugin_state==='running');
            var btn = document.getElementById('devdredi-main-button');
            if(btn){
                btn.name = running ? 'devdredi_stop' : 'devdredi_run';
                btn.textContent = running ? ('Stop Rule #'+pos) : ('Save & Run Rule #'+pos);
                btn.className = running ? 'dd-btn-danger' : 'dd-btn-primary';
            }
            var stx = document.getElementById('devdredi-status-text');
            if(stx){
                stx.innerHTML = running ? '<span class="w-2 h-2 rounded-full bg-emerald-500"></span>Running'
                                        : '<span class="w-2 h-2 rounded-full bg-gray-400"></span>Stopped';
                stx.className = 'inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap ' + (running ? 'text-emerald-600' : 'text-gray-500');
            }

            // best-effort persist so a later manual refresh shows this rule
            try {
                var fd = new FormData(); fd.append('action','devdredi_set_active'); fd.append('rule', id); fd.append('_n', SWITCH_NONCE);
                fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body: fd });
            } catch(e){}
        }

        // Click anywhere on a rule row to switch (like tabs) — but leave the action buttons,
        // rename pencil, drag handle and delete-confirm to their own handlers.
        document.addEventListener('click', function(e){
            if (e.target.closest('.dd-rule-actions, .dd-rule-edit, .dd-rule-handle, .dd-rule-confirm, .dd-rule-runstop, .dd-range')) return;
            var row = e.target.closest('.dd-rule-row'); if (!row) return;
            // Clicking the nickname field of the already-active rule should let you type, not re-switch.
            if (e.target.closest('.dd-rule-name') && row.classList.contains('is-active')) return;
            var id = row.getAttribute('data-rule');
            var link = row.querySelector('.dd-rule-id');
            if (!id || !window.DEVDREDI_RULES || !window.DEVDREDI_RULES[id]) { if (link) window.location.href = link.href; return; }
            e.preventDefault();
            try { applyRule(id, row); }
            catch (err) { try { console.error('DDRM instant switch failed, reloading', err); } catch(_){} if (link) window.location.href = link.href; }
        });

        // Per-row play/stop: switch the form to that rule (so rm_editing_rule travels with the POST),
        // then trigger the main Save & Run / Stop button — keeping the row icon and that button in sync.
        document.addEventListener('click', function(e){
            var b = e.target.closest('.dd-rule-runstop'); if (!b) return;
            e.preventDefault();
            var row = b.closest('.dd-rule-row'); if (!row) return;
            var id = row.getAttribute('data-rule');
            var link = row.querySelector('.dd-rule-id');
            if (!row.classList.contains('is-active')) {
                if (!id || !window.DEVDREDI_RULES || !window.DEVDREDI_RULES[id]) { if (link) window.location.href = link.href; return; }
                try { applyRule(id, row); }
                catch (err) { if (link) window.location.href = link.href; return; }
            }
            var mb = document.getElementById('devdredi-main-button');
            if (mb) mb.click();
        });

        // Duplicate / delete with no full reload. The form is only re-applied when the
        // active (edited) rule actually changes, so unsaved edits aren't wiped by an other-delete.
        document.addEventListener('click', function(e){
            var a = e.target.closest('a[href*="rm_rule_action="]');
            if (!a) return;
            var m = (a.getAttribute('href')||'').match(/rm_rule_action=(duplicate|delete)/);
            if (!m) return;
            var idm = (a.getAttribute('href')||'').match(/rm_rule_id=([^&]+)/);
            var rid = idm ? decodeURIComponent(idm[1]) : '';
            if (!rid) return;
            e.preventDefault();
            var editing = (document.getElementById('dd-editing-rule')||{}).value || '';
            var fd = new FormData();
            fd.append('action','devdredi_rule_action'); fd.append('rm_action', m[1]);
            fd.append('rule', rid); fd.append('editing', editing); fd.append('_n', SWITCH_NONCE);
            fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res && res.data && res.data.code === 'limit') { devdredi_refreshLimitNote(); return; }
                    if (!res || !res.success || !res.data) { window.location.href = a.href; return; }
                    var d = res.data;
                    window.DEVDREDI_RULES = d.settings;
                    var list = document.getElementById('dd-rule-list');
                    if (list) list.innerHTML = d.listHtml;
                    if (d.active && d.active !== editing) {
                        var row = list ? list.querySelector('.dd-rule-row[data-rule="' + d.active + '"]') : null;
                        if (row) { try { applyRule(d.active, row); } catch(err){ window.location.href = a.href; } }
                    }
                    devdredi_refreshLimitNote();
                })
                .catch(function(){ window.location.href = a.href; });
        });

        // Rules are unlimited; kept as a no-op because list-refresh handlers call it.
        function devdredi_refreshLimitNote(){}

        // Add Rule with no full reload: create it server-side, re-render the list in place, switch to it.
        var addLink = document.getElementById('dd-rule-add');
        if (addLink) {
            addLink.addEventListener('click', function(e){
                e.preventDefault();
                if (addLink.dataset.busy) return; addLink.dataset.busy = '1';
                var fd = new FormData(); fd.append('action','devdredi_add_rule'); fd.append('_n', SWITCH_NONCE);
                fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res && res.data && res.data.code === 'limit') { addLink.dataset.busy = ''; devdredi_refreshLimitNote(); return; }
                        if (!res || !res.success || !res.data) { window.location.href = addLink.href; return; }
                        var d = res.data;
                        window.DEVDREDI_RULES[d.id] = d.settings;
                        var list = document.getElementById('dd-rule-list');
                        if (list) { list.innerHTML = d.listHtml; }
                        var row = list ? list.querySelector('.dd-rule-row[data-rule="' + d.id + '"]') : null;
                        if (row) { try { applyRule(d.id, row); } catch(err){ window.location.href = addLink.href; return; } try { row.scrollIntoView({ block:'nearest' }); } catch(_){} }
                        devdredi_refreshLimitNote();
                        addLink.dataset.busy = '';
                    })
                    .catch(function(){ window.location.href = addLink.href; });
            });
        }
    })();
    <?php }); ?>
    <?php
}




add_action('admin_init', 'devdredi_handle_purge_cache_debug');
function devdredi_handle_purge_cache_debug()
{
    if (isset($_POST['devdredi_purge_cache']) && current_user_can('manage_options') && check_admin_referer('devdredi_save_settings', 'devdredi_nonce')) {
        devdredi_purge_plugin_cache();
        add_action('admin_notices', function () {
            echo '<div class="updated"><p>Cache purged successfully.</p></div>';
        });
    }
}
