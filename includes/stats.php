<?php
/**
 * Slim statistics. Maintains only the counters shown in the Statistics panel and
 * counts JS redirects via the lightweight devdredi_r pixel that the redirect JS fires.
 */

defined('ABSPATH') || exit;

/** Reset all stat counters for the CURRENT (active) rule. */
function devdredi_reset_stats()
{
    $keys = array(
        'visitor_count', 'page_view_count', 'ip_list', 'ua_list', 'uu_list', 'ip_link_index',
        'ip_redirected_once', 'last_redirects', 'user_redirects_count', 'user_bypass_count',
        'unique_visitor_count', 'unique_users_count',
        'rc_by_source', 'rc_by_dest', 'rc_by_referrer', 'rc_by_country', 'rc_by_found',
        'device_count_desktop', 'device_count_mobile', 'device_count_tablet',
    );
    foreach ($keys as $key) {
        if (strpos($key, 'count') !== false) {
            devdredi_update_setting($key, 0);
        } else {
            devdredi_update_setting($key, array());
        }
    }
    devdredi_update_setting('stats_daily', array());

    // Rotation state lives in plugin-wide options keyed "<rule_id>|<visitor>". Clear only THIS
    // rule's keys so its sequential ("First to last") counter and random queue restart on reset,
    // leaving other rules' rotation untouched. (The seq counter was previously never cleared, so
    // "First to last" wouldn't restart after a reset.)
    $rule_scope = devdredi_active_rule_id();
    $prefix = $rule_scope . '|';
    foreach (array('devdredi_user_seq_counters', 'devdredi_user_random_queue', 'devdredi_user_random_used') as $opt) {
        $vals = get_option($opt, array());
        if (!is_array($vals) || empty($vals)) {
            continue;
        }
        $changed = false;
        foreach (array_keys($vals) as $k) {
            if (strpos((string) $k, $prefix) === 0) {
                unset($vals[$k]);
                $changed = true;
            }
        }
        if ($changed) {
            update_option($opt, $vals, false);
        }
    }

    $rc = (int) devdredi_get_setting('reset_count', 0);
    devdredi_update_setting('reset_count', $rc + 1);
}

/**
 * Daily stat buckets for time-range filtering. One bucket per (site-timezone) day holding the
 * additive counts only — redirects/bypassed/bots/devices/country. Uniques are NOT bucketed (they
 * can't be summed across days). $incs: array like ['red'=>1,'dd'=>1]; $cc: optional country code.
 * Capped to ~1 year of days; country sub-map capped at 300 keys/day.
 */
function devdredi_bump_daily($incs, $cc = '')
{
    $map = devdredi_get_setting('stats_daily', array());
    if (!is_array($map)) { $map = array(); }
    $today = current_time('Y-m-d');
    if (!isset($map[$today]) || !is_array($map[$today])) {
        $map[$today] = array('red'=>0,'byp'=>0,'dd'=>0,'dm'=>0,'dt'=>0,'cc'=>array());
    }
    foreach ($incs as $k => $n) {
        $map[$today][$k] = (isset($map[$today][$k]) ? (int) $map[$today][$k] : 0) + (int) $n;
    }
    if ($cc !== '') {
        if (!isset($map[$today]['cc']) || !is_array($map[$today]['cc'])) { $map[$today]['cc'] = array(); }
        if (isset($map[$today]['cc'][$cc]) || count($map[$today]['cc']) < 300) {
            $map[$today]['cc'][$cc] = (isset($map[$today]['cc'][$cc]) ? (int) $map[$today]['cc'][$cc] : 0) + 1;
        }
    }
    if (count($map) > 370) { ksort($map); $map = array_slice($map, -365, null, true); }
    devdredi_update_setting('stats_daily', $map);
}

/** Increment count map[$key] in a setting, capping the number of distinct keys. */
function devdredi_bump_count($setting, $key, $cap = 5000)
{
    if ($key === '') {
        return;
    }
    $map = devdredi_get_setting($setting, array());
    if (!is_array($map)) {
        $map = array();
    }
    if (!isset($map[$key]) && count($map) >= $cap) {
        return; // don't grow past the cap with new keys
    }
    $map[$key] = (isset($map[$key]) ? (int) $map[$key] : 0) + 1;
    devdredi_update_setting($setting, $map);
}

/**
 * Record that $visitor_id was just redirected (for the run-once / revisit-delay suppression).
 * Keeps the last_redirects map bounded: prunes entries already past the revisit window (a 0
 * delay = "once forever", so those never expire) and hard-caps the total, dropping the oldest.
 * Without this the map grew on every redirect and bloated the settings row / memory indefinitely.
 */
function devdredi_record_last_redirect($visitor_id)
{
    if ($visitor_id === '') {
        return;
    }
    $map = devdredi_get_setting('last_redirects', array());
    if (!is_array($map)) {
        $map = array();
    }
    $revisit_delay = (int) devdredi_get_setting('revisit_delay', 0);
    $now = time();
    if ($revisit_delay > 0) {
        $cutoff = $now - ($revisit_delay * 60);
        foreach ($map as $k => $ts) {
            if ((int) $ts < $cutoff) {
                unset($map[$k]);
            }
        }
    }
    $map[$visitor_id] = $now;
    $cap = 20000;
    if (count($map) > $cap) {
        asort($map); // oldest timestamps first
        $map = array_slice($map, count($map) - $cap, null, true);
    }
    devdredi_update_setting('last_redirects', $map);
}

function devdredi_track_visit($link_label, $outgoing_url = null, $status_code = null, $redirect_chain = null, $force_404 = false, $ignore_referer = false, $redirect_method = '', $redirect_id = '', $provisional = false, $custom_referrer = null, $is_slc = 0){
    // Slim stats tracker (analytics removed). Maintains only the counters shown in the
    // Statistics panel: unique IPs, unique user-agents, unique users (IP+UA) and page views.
    $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
    if (preg_match('/\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|webp|mp4|pdf|zip)$/i', $request_uri)) {
        return;
    }
    $user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
    foreach (array('curl','wget','python','bot','crawler','spider','scraper','monitor','uptime') as $pattern) {
        if (stripos($user_agent, $pattern) !== false) {
            return;
        }
    }

    $ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    // Prefer the shared-suite beacon's session cookie as the visitor identity when present —
    // a far more accurate unique-visitor signal than the raw IP (distinguishes shared-IP
    // visitors, dedupes one person across changing IPs). Falls back to the IP with no beacon.
    if (!empty($_COOKIE['ddc_sid'])) {
        $sid = preg_replace('/[^a-f0-9]/', '', sanitize_text_field(wp_unslash($_COOKIE['ddc_sid'])));
        if (strlen($sid) >= 8) { $ip = 'sid:' . $sid; }
    }
    $ua  = sanitize_text_field($user_agent);
    $cap = 20000;

    if ($ip !== '') {
        $ip_list = devdredi_get_setting('ip_list', array());
        if (!is_array($ip_list)) { $ip_list = array(); }
        if (!in_array($ip, $ip_list, true) && count($ip_list) < $cap) {
            $ip_list[] = $ip;
            devdredi_update_setting('ip_list', $ip_list);
        }
    }

    if ($ua !== '') {
        $ua_list = devdredi_get_setting('ua_list', array());
        if (!is_array($ua_list)) { $ua_list = array(); }
        if (!in_array($ua, $ua_list, true) && count($ua_list) < $cap) {
            $ua_list[] = $ua;
            devdredi_update_setting('ua_list', $ua_list);
        }
    }

    // A "unique user" is a distinct IP+UA pair. The old test (the IP AND the UA each newly seen)
    // missed users sharing one dimension with an earlier visitor (same IP + new UA, or vice versa),
    // undercounting. Track the combined identity in its own capped list and count when it's new.
    if ($ip !== '' && $ua !== '') {
        $combo = substr(hash('sha256', $ip . "\n" . $ua), 0, 32);
        $uu_list = devdredi_get_setting('uu_list', array());
        if (!is_array($uu_list)) { $uu_list = array(); }
        if (!in_array($combo, $uu_list, true) && count($uu_list) < $cap) {
            $uu_list[] = $combo;
            devdredi_update_setting('uu_list', $uu_list);
            $uu = (int) devdredi_get_setting('unique_users_count', 0);
            devdredi_update_setting('unique_users_count', $uu + 1);
        }
    }

    if ($outgoing_url === null) {
        $pv = (int) devdredi_get_setting('page_view_count', 0);
        devdredi_update_setting('page_view_count', $pv + 1);
    }
}

// JS-redirect counter pixel: the redirect JS fires ...?devdredi_r=1&p=<payload>. This counts
// the redirect once per visitor (30s window) and enforces the run-once revisit delay, then 204s.
add_action('init', function(){
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public redirect-counter pixel hit by visitors; no nonce by design.
    if (isset($_GET['devdredi_r']) && $_GET['devdredi_r'] == '1') {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64 payload; validated + each field sanitized after decode.
        $payload = isset($_GET['p']) ? wp_unslash($_GET['p']) : '';
        $label = isset($_GET['link_label']) ? sanitize_text_field(wp_unslash($_GET['link_label'])) : '';
        $out   = isset($_GET['outgoing_url']) ? sanitize_text_field(wp_unslash($_GET['outgoing_url'])) : '';
        $rid   = isset($_GET['rid']) ? sanitize_text_field(wp_unslash($_GET['rid'])) : '';
        $ref   = isset($_GET['ref']) ? sanitize_text_field(wp_unslash($_GET['ref'])) : '';
        $is_slc = isset($_GET['slc']) ? (int)$_GET['slc'] : 0;
        $is_404 = isset($_GET['is_404']) ? (bool)$_GET['is_404'] : false;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($payload !== '') {
            $payload = str_replace(' ', '+', $payload);
            $json = base64_decode(strtr($payload, '-_', '+/'));
            $data = json_decode($json, true);
            if (is_array($data)) {
                $label = sanitize_text_field((string)($data['l'] ?? $label));
                $out = sanitize_text_field((string)($data['o'] ?? $out));
                $rid = sanitize_text_field((string)($data['rid'] ?? $rid));
                $ref = sanitize_text_field((string)($data['r'] ?? $ref));
                $is_slc = isset($data['slc']) ? (int)$data['slc'] : $is_slc;
                $is_404 = isset($data['f']) ? ((int)$data['f'] === 1) : $is_404;
                // Attribute this redirect's counts to the rule that actually ran it.
                if (!empty($data['ru'])) {
                    $ru = sanitize_text_field((string) $data['ru']);
                    $rule_ids = array_map(function ($r) { return $r['id']; }, devdredi_get_rules());
                    if (in_array($ru, $rule_ids, true)) {
                        $GLOBALS['devdredi_rule'] = $ru;
                    }
                }
            }
        }

        if ($label !== '' && $out !== '' && strpos($out, '/wp-admin/') === false) {
            $host = wp_parse_url($out, PHP_URL_HOST);
            $scheme = wp_parse_url($out, PHP_URL_SCHEME);
            if ($host && in_array($scheme, array('http','https'), true)) {
                // Identify the visitor the way track_visit does — the suite beacon's session cookie
                // when present, else REMOTE_ADDR — so the 30s dedupe doesn't fold distinct visitors
                // sharing one public IP into a single counted redirect.
                $dedupe_ident = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
                if (!empty($_COOKIE['ddc_sid'])) {
                    $dedupe_sid = preg_replace('/[^a-f0-9]/', '', sanitize_text_field(wp_unslash($_COOKIE['ddc_sid'])));
                    if (strlen($dedupe_sid) >= 8) { $dedupe_ident = 'sid:' . $dedupe_sid; }
                }
                $count_key = 'devdredi_redirect_count_' . md5(($rid ?: ($label . '|' . ($out ?: ''))) . '|' . $dedupe_ident);
                $is_first = (get_transient($count_key) === false);
                if ($is_first) {
                    $rc = (int) devdredi_get_setting('user_redirects_count', 0);
                    devdredi_update_setting('user_redirects_count', $rc + 1);
                    set_transient($count_key, 1, 30);

                    // Per-dimension redirect counts (feed the "Redirects: N" lists).
                    $src_path = devdredi_normalize_path($label); // label = source page full URL
                    if ($src_path !== '') { devdredi_bump_count('rc_by_source', $src_path); }
                    if ($out !== '') { devdredi_bump_count('rc_by_dest', $out); }
                    $ref_host = $ref ? strtolower((string) wp_parse_url($ref, PHP_URL_HOST)) : '';
                    $ref_host = preg_replace('#^www\.#', '', (string) $ref_host);
                    if ($ref_host !== '') { devdredi_bump_count('rc_by_referrer', $ref_host); }
                    if (isset($data['fp']) && $data['fp'] !== '') { devdredi_bump_count('rc_by_found', sanitize_text_field((string) $data['fp'])); }
                    // Country only when geo filtering is on (already resolved + cached for this IP, so no extra API call).
                    $daily_cc = '';
                    if (devdredi_get_setting('geo_filter_enabled', 0)) {
                        $cc = strtoupper((string) devdredi_get_user_country());
                        if ($cc !== '' && $cc !== 'UNKNOWN') { devdredi_bump_count('rc_by_country', $cc); $daily_cc = $cc; }
                    }

                    // Per-device redirect counts (desktop / mobile / tablet).
                    $daily_dev = '';
                    if (function_exists('devdredi_device_type')) {
                        $dtype = devdredi_device_type();
                        $dc = (int) devdredi_get_setting('device_count_' . $dtype, 0);
                        devdredi_update_setting('device_count_' . $dtype, $dc + 1);
                        $daily_dev = ($dtype === 'mobile') ? 'dm' : (($dtype === 'tablet') ? 'dt' : 'dd');
                    }
                    $daily_inc = array('red' => 1);
                    if ($daily_dev !== '') { $daily_inc[$daily_dev] = 1; }
                    devdredi_bump_daily($daily_inc, $daily_cc);

                    $run_once = devdredi_get_setting('run_once', 'never');
                    // Record the redirect for run-once / revisit suppression whenever run_once is
                    // set — a 0 revisit_delay means "once forever", so it must record too (gating on
                    // revisit_delay > 0 made "once per visitor" never suppress).
                    if ($run_once !== 'never') {
                        $visitor_key = devdredi_get_visitor_key();
                        $visitor_id = '';
                        if ($run_once === 'ip') {
                            $visitor_id = $visitor_key;
                        } elseif ($run_once === 'ip_ua') {
                            $user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
                            $visitor_id = hash('sha256', $visitor_key . "\n" . (string) $user_agent);
                        }
                        devdredi_record_last_redirect($visitor_id);
                    }

                    $ignore_ref = ($ref === '');
                    devdredi_track_visit($label, $out, null, null, $is_404, $ignore_ref, 'JS', $rid, false, ($ref !== '' ? $ref : null), $is_slc);
                }
                status_header(204);
                exit;
            }
        }

        status_header(204);
        exit;
    }
});
