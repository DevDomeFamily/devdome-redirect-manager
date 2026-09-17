<?php
/**
 * Redirect engine: request matching, scheduling, bot/geo/run-once gating, link
 * rotation, the template_redirect handler + redirect-JS output, fallback, the WP
 * allowed_redirect_hosts filter, and the custom-domains allowlist helpers.
 */

defined('ABSPATH') || exit;

/**
 * The redirect's JavaScript is attached to enqueued handles rather than written into the page
 * as a <script> block (WordPress.org: use wp_add_inline_script). Two handles because the two
 * pieces belong in different places: the tracker guard must reach the head before any tracker
 * tag, while the redirect itself belongs in the footer, after the markup it drives.
 * Neither handle has a file: the script is built per request from the matching rule.
 */
function devdredi_enqueue_head_js($js)
{
    if (!wp_script_is('devdredi-redirect-head', 'registered')) {
        wp_register_script('devdredi-redirect-head', false, array(), DEVDREDI_VERSION, false);
    }
    wp_enqueue_script('devdredi-redirect-head');
    wp_add_inline_script('devdredi-redirect-head', $js);
}

/** Attach a piece of the redirect script to the footer handle, in call order. */
function devdredi_enqueue_footer_js($js)
{
    $js = trim((string) $js);
    if ($js === '') {
        return;
    }
    if (!wp_script_is('devdredi-redirect', 'registered')) {
        wp_register_script('devdredi-redirect', false, array(), DEVDREDI_VERSION, true);
    }
    wp_enqueue_script('devdredi-redirect');
    wp_add_inline_script('devdredi-redirect', $js);
}

/**
 * Buffer the JavaScript printed by $print and attach it to the footer handle.
 * ob_start() and ob_get_clean() are paired inside this one function scope, in a
 * try/finally, so the buffer is always closed here and can never be left open.
 */
function devdredi_footer_js_capture($print)
{
    ob_start();
    try {
        $print();
    } finally {
        devdredi_enqueue_footer_js(ob_get_clean());
    }
}

/** Classify the visitor's device from the User-Agent: 'mobile' | 'tablet' | 'desktop'. */
function devdredi_device_type()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
    if ($ua === '') {
        return 'desktop';
    }
    // Tablets first (an iPad / Android-without-"mobile" is a tablet, not a phone).
    if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false
        || strpos($ua, 'kindle') !== false || strpos($ua, 'silk') !== false
        || strpos($ua, 'playbook') !== false
        || (strpos($ua, 'android') !== false && strpos($ua, 'mobile') === false)) {
        return 'tablet';
    }
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'iphone') !== false
        || strpos($ua, 'ipod') !== false || strpos($ua, 'android') !== false
        || strpos($ua, 'blackberry') !== false || strpos($ua, 'opera mini') !== false
        || strpos($ua, 'windows phone') !== false) {
        return 'mobile';
    }
    return 'desktop';
}

function devdredi_check_status_and_schedule() {
    $plugin_state = devdredi_get_setting('plugin_state', 'stopped');
    $run_mode = devdredi_effective_run_mode();
    $now = current_time('timestamp');

    if ($plugin_state === 'running' && $run_mode === 'set_time') {
        $start_time = (int) devdredi_get_setting('start_time', 0);
        $runtime_minutes = (int) devdredi_get_setting('runtime_minutes', 0);

        if ($start_time > 0 && $runtime_minutes > 0) {
            $end_time = $start_time + ($runtime_minutes * 60);
            if ($now >= $end_time) {
                devdredi_update_setting('plugin_state', 'stopped');
                devdredi_update_setting('start_time', 0);
                return false;
            }
        }
    }

    return ($plugin_state === 'running');
}

/** Current weekday/hour/minute in the schedule timezone (plugin override, else WP site timezone). */
function devdredi_schedule_parts() {
    $tz = devdredi_get_setting('schedule_timezone', '');
    try {
        $zone = ($tz !== '') ? new DateTimeZone($tz) : wp_timezone();
    } catch (Exception $e) {
        $zone = wp_timezone();
    }
    $dt = new DateTime('now', $zone);
    return array('N' => (int) $dt->format('N'), 'H' => (int) $dt->format('H'), 'i' => (int) $dt->format('i'), 'Ymd' => $dt->format('Y-m-d'));
}

function devdredi_is_in_schedule() {
    $run_mode = devdredi_effective_run_mode();
    if ($run_mode !== 'set_time') {
        return true;
    }

    $p = devdredi_schedule_parts();

    // Campaign date range (optional, inclusive). Outside it => not in schedule.
    $start_date = (string) devdredi_get_setting('schedule_start_date', '');
    $end_date   = (string) devdredi_get_setting('schedule_end_date', '');
    if ($start_date !== '' && $p['Ymd'] < $start_date) {
        return false;
    }
    if ($end_date !== '' && $p['Ymd'] > $end_date) {
        return false;
    }

    $run_weekdays = maybe_unserialize(devdredi_get_setting('run_weekdays', []));
    if (is_array($run_weekdays) && count($run_weekdays) > 0) {
        $current_day = $p['N'];
        if (!in_array($current_day, $run_weekdays)) {
            return false;
        }
    }

    $specific_times = maybe_unserialize(devdredi_get_setting('specific_times', []));
    if (is_array($specific_times) && !empty($specific_times)) {
        $current_h = $p['H'];
        $current_m = $p['i'];
        $now_minutes = ($current_h * 60) + $current_m;

        $in_time_range = false;
        $at_least_one_enabled = false;
        foreach ($specific_times as $interval) {
            if (!empty($interval['enable'])) {
                $at_least_one_enabled = true;
                
                $start_parts = explode(':', $interval['start']);
                $start_h = isset($start_parts[0]) ? (int)$start_parts[0] : 0;
                $start_m = isset($start_parts[1]) ? (int)$start_parts[1] : 0;
                $start_minutes = ($start_h * 60) + $start_m;

                $end_parts = explode(':', $interval['end']);
                $end_h = isset($end_parts[0]) ? (int)$end_parts[0] : 0;
                $end_m = isset($end_parts[1]) ? (int)$end_parts[1] : 0;
                $end_minutes = ($end_h * 60) + $end_m;

                if ($start_minutes <= $end_minutes) {
                    if ($now_minutes >= $start_minutes && $now_minutes <= $end_minutes) {
                        $in_time_range = true;
                        break;
                    }
                } else {
                    if ($now_minutes >= $start_minutes || $now_minutes <= $end_minutes) {
                        $in_time_range = true;
                        break;
                    }
                }
            }
        }
        if ($at_least_one_enabled && !$in_time_range) {
            return false;
        }
    }

    return true;
}

/**
 * True when the current rule's schedule is permanently over (campaign end date passed): it can
 * never redirect again, so its pages are safe to cache. Weekday/time windows always recur and
 * are never "over".
 */
function devdredi_schedule_permanently_over() {
    if (devdredi_effective_run_mode() !== 'set_time') {
        return false;
    }
    $end_date = (string) devdredi_get_setting('schedule_end_date', '');
    return ($end_date !== '' && devdredi_schedule_parts()['Ymd'] > $end_date);
}

function devdredi_pick_link(array $links, $mode='sequential', $visitor_key='', $descending_spread=0.5, $descending_seed=0, $repeat=1) {
    $links = array_values(array_filter($links)); 
    if (!$links) return '';
    
    switch ($mode) {
        case 'random': 
            if ($repeat) {
                $count = count($links);
                if ($count === 1) return $links[0];

                $queues = get_option('devdredi_user_random_queue', []);
                if (!is_array($queues)) $queues = [];

                $queue = isset($queues[$visitor_key]) && is_array($queues[$visitor_key]) ? $queues[$visitor_key] : [];

                if (empty($queue)) {
                    $queue = range(0, $count - 1);
                    for ($i = $count - 1; $i > 0; $i--) {
                        $j = wp_rand(0, $i);
                        $tmp = $queue[$i];
                        $queue[$i] = $queue[$j];
                        $queue[$j] = $tmp;
                    }
                }

                $selected_index = array_shift($queue);
                $queues[$visitor_key] = $queue;
                update_option('devdredi_user_random_queue', $queues, false);

                return isset($links[$selected_index]) ? $links[$selected_index] : $links[0];
            }

            $user_random_used = get_option('devdredi_user_random_used', []);
            if (!is_array($user_random_used)) $user_random_used = [];
            
            $used_indices = isset($user_random_used[$visitor_key]) ? $user_random_used[$visitor_key] : [];
            if (!is_array($used_indices)) $used_indices = [];
            
            $available_indices = array_diff(array_keys($links), $used_indices);
            
            if (empty($available_indices)) {
                return '';
            }
            
            $available_indices = array_values($available_indices);
            $random_key = array_rand($available_indices);
            $selected_index = $available_indices[$random_key];
            
            $used_indices[] = $selected_index;
            $user_random_used[$visitor_key] = $used_indices;
            update_option('devdredi_user_random_used', $user_random_used, false);
            
            return $links[$selected_index];

        case 'sequential':
            $user_seq_counters = get_option('devdredi_user_seq_counters', []);
            if (!is_array($user_seq_counters)) $user_seq_counters = [];
            
            $user_seq_idx = isset($user_seq_counters[$visitor_key]) ? (int) $user_seq_counters[$visitor_key] : 0;
            
            if ($user_seq_idx >= count($links)) {
                if ($repeat) {
                    $user_seq_idx = 0;
                } else {
                    return '';
                }
            }
            
            $selected_link = $links[$user_seq_idx];
            $user_seq_counters[$visitor_key] = $user_seq_idx + 1;
            update_option('devdredi_user_seq_counters', $user_seq_counters, false);
            
            return $selected_link;

        case 'descending':
            $count = count($links);
            if ($count === 1) return $links[0];

            $spread = max(0.0, min(1.0, (float) $descending_spread));
            if (abs($spread - 0.5) < 0.000001) {
                $base = (int) floor(1000 / $count);
                $rem = 1000 - ($base * $count);
                $clicks = array();
                for ($i = 0; $i < $count; $i++) {
                    $clicks[$i] = $base + ($i < $rem ? 1 : 0);
                }
            } else {
                $t = abs($spread - 0.5) / 0.5;
                if ($t < 0) $t = 0;
                if ($t > 1) $t = 1;
                $r_min = 0.6;
                $r = 1.0 - (1.0 - $r_min) * pow($t, 2);
                $s_max = 0.9;
                $spike = $s_max * pow($t, 3);
                if ($spike < 0) $spike = 0;
                if ($spike > 0.98) $spike = 0.98;

                $weights = array();
                $sumW = 0.0;
                for ($i = 0; $i < $count; $i++) {
                    $pwr = ($spread < 0.5) ? $i : (($count - 1) - $i);
                    $val = pow($r, $pwr);
                    $weights[$i] = $val;
                    $sumW += $val;
                }
                if ($sumW <= 0) $sumW = 1.0;

                $probs = array();
                $peak = ($spread < 0.5) ? 0 : ($count - 1);
                for ($i = 0; $i < $count; $i++) {
                    $probs[$i] = (1.0 - $spike) * ($weights[$i] / $sumW);
                }
                $probs[$peak] += $spike;

                $clicks = array();
                $frac = array();
                $sumC = 0;
                for ($i = 0; $i < $count; $i++) {
                    $raw = $probs[$i] * 1000.0;
                    $b = (int) floor($raw);
                    if ($b < 1) $b = 1;
                    $clicks[$i] = $b;
                    $frac[$i] = $raw - $b;
                    $sumC += $b;
                }

                $rem = 1000 - $sumC;
                $idxs = range(0, $count - 1);
                usort($idxs, function($a, $b) use ($frac, $spread) {
                    if ($frac[$a] === $frac[$b]) {
                        return ($spread < 0.5) ? ($a <=> $b) : ($b <=> $a);
                    }
                    return ($frac[$a] < $frac[$b]) ? 1 : -1;
                });

                if ($rem > 0) {
                    for ($k = 0; $k < $rem; $k++) {
                        $clicks[$idxs[$k % $count]] += 1;
                    }
                } elseif ($rem < 0) {
                    $need = abs($rem);
                    for ($k = $count - 1; $k >= 0 && $need > 0; $k--) {
                        $id = $idxs[$k];
                        while ($need > 0 && $clicks[$id] > 1) {
                            $clicks[$id] -= 1;
                            $need--;
                        }
                    }
                }

                if ($spread < 0.5) {
                    for ($i = $count - 2; $i >= 0; $i--) {
                        while ($clicks[$i] <= $clicks[$i + 1] && $clicks[$i + 1] > 1) {
                            $clicks[$i] += 1;
                            $clicks[$i + 1] -= 1;
                        }
                    }
                } else {
                    for ($i = 0; $i < $count - 1; $i++) {
                        while ($clicks[$i] >= $clicks[$i + 1] && $clicks[$i] > 1) {
                            $clicks[$i] -= 1;
                            $clicks[$i + 1] += 1;
                        }
                    }
                }
            }

            $r = wp_rand(1, 1000);
            $acc = 0;
            for ($i = 0; $i < $count; $i++) {
                $acc += (int) $clicks[$i];
                if ($r <= $acc) return $links[$i];
            }
            return $links[$count - 1];

        case 'skip_domain':
            $base = trim((string)$links[0]);
            if ($base === '') return '';
            
            if (strpos($base, 'http') !== 0) {
                $base = 'https://' . $base;
            }
            
            $base_parts = wp_parse_url($base);
            $scheme = $base_parts['scheme'] ?? 'https';
            $host = $base_parts['host'] ?? '';
            $port = isset($base_parts['port']) ? (":" . $base_parts['port']) : '';
            $base_path = rtrim($base_parts['path'] ?? '', '/');
            
            $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
            $uri_parts = wp_parse_url($request_uri);
            $current_path = $uri_parts['path'] ?? '/';
            $query = isset($uri_parts['query']) ? ('?' . $uri_parts['query']) : '';
            $fragment = isset($uri_parts['fragment']) ? ('#' . $uri_parts['fragment']) : '';
            
            return $scheme . '://' . $host . $port . $base_path . $current_path . $query . $fragment;

        default: 
            return $links[0];
    }
}

function devdredi_resolve_redirect_target( $raw_url ) {
    $raw_trimmed = trim( (string)$raw_url );
    if ( empty( $raw_trimmed ) ) return '';

    $url = esc_url_raw( $raw_trimmed );

    $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
    if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
        // A scheme-less host (example.com/landing) becomes https; any other scheme (ftp:, javascript:) never redirects.
        if ( strpos( $raw_trimmed, ':' ) === false && strpos( $raw_trimmed, '.' ) !== false ) {
            $url    = esc_url_raw( 'https://' . ltrim( $raw_trimmed, '/' ) );
            $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        }
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return '';
        }
    }

    if ( function_exists('wp_http_validate_url') && ! wp_http_validate_url( $url ) ) return '';

    $parts = wp_parse_url($url);
    if (!empty($parts['host']) && function_exists('idn_to_ascii')) {
        $ascii_host = idn_to_ascii($parts['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii_host === false || $ascii_host === '') {
            return ''; // a host that cannot be converted never becomes a Location header
        }
        $parts['host'] = $ascii_host;
        $url = (isset($parts['scheme'])?$parts['scheme'].'://':'') . $parts['host']
             . (isset($parts['port'])?':'.$parts['port']:'')
             . (isset($parts['path'])?$parts['path']:'')
             . (isset($parts['query'])?'?'.$parts['query']:'')
             . (isset($parts['fragment'])?'#'.$parts['fragment']:'');
    }

    $here = ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
    if ( rtrim( $here, '/' ) === rtrim( $url, '/' ) ) return '';


    return $url;
}

/** The site's home path ('/' on a root install, '/blog' on a subdirectory install): where plain-permalink addresses live. */
function devdredi_home_path() {
    return '/' . trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
}

function devdredi_check_url_match($pattern, $current_full_url, $current_path, $current_host) {
    if (empty($pattern)) return false;
    $item = trim($pattern);
    if ($item === "") return false;

    // Plain permalinks put every page on the site root with a query (/?cat=5, /?page_id=2). Such an address matches only
    // a request carrying the same query values; by its path alone it would match every page of the site (1.5.0).
    if (strpos($item, '?') !== false) { // /?cat=5, /blog/?page_id=2, /landing?offer=1: the query values are part of the address
        $want = array();
        $have = array();
        parse_str((string) wp_parse_url($item, PHP_URL_QUERY), $want);
        parse_str((string) wp_parse_url($current_full_url, PHP_URL_QUERY), $have);
        foreach ($want as $k => $v) {
            if (!isset($have[$k]) || !is_scalar($v) || !is_scalar($have[$k]) || (string) $have[$k] !== (string) $v) {
                return false;
            }
        }
    }

    $is_category = (substr($item, -1) === '/');

     $norm_path = function($p) {
         if (empty($p)) return "/";
         $p = explode('?', $p)[0];
         $p = trim($p);
         $p = rtrim($p, "/,.;:");
         if ($p === "" || $p === "/") return "/";
         return "/" . trim(strtolower($p), "/");
     };

    $paths_match = function($item_p, $current_p) use ($is_category) {
        if ($is_category) {
            if ($item_p === "/") return true;
            return ($item_p === $current_p || strpos($current_p, $item_p . "/") === 0);
        }
        return ($item_p === $current_p);
    };

    $norm_host = function($h) {
        // Strip a leading "www." so a www / non-www difference between the list item and the
        // live request host never silently defeats a full-URL or bare-domain match.
        return preg_replace('#:\d+$#', '', preg_replace('#^www\.#i', '', strtolower(trim((string) $h)))); // no www, no port
    };

    $current_host = $norm_host($current_host);
    $current_path = $norm_path(wp_parse_url($current_full_url, PHP_URL_PATH) ?: $current_path);

    if (preg_match('~^https?://~i', $item)) {
        $item_parts = wp_parse_url($item);
        $item_host = $norm_host($item_parts['host'] ?? "");
        $item_path = $norm_path($item_parts['path'] ?? "/");
        
        return ($item_host === $current_host && $paths_match($item_path, $current_path));
    }

    if (strpos($item, '/') === 0) {
        return $paths_match($norm_path($item), $current_path);
    }

    if (strpos($item, '/') !== false) {
        $parts = explode('/', $item, 2);
        $first_part = $parts[0];
        $rest = $parts[1];

        if (strpos($first_part, '.') !== false && !preg_match('/^[0-9]+$/', $first_part)) {
            $item_host = $norm_host($first_part);
            $item_path = $norm_path("/" . $rest);
            return ($item_host === $current_host && $paths_match($item_path, $current_path));
        } else {
            return $paths_match($norm_path("/" . $item), $current_path);
        }
    }

    if (strpos($item, '.') !== false && !preg_match('/^[0-9]+$/', $item)) {
        return $norm_host($item) === $current_host;
    } else {
        return $paths_match($norm_path("/" . $item), $current_path);
    }
}

function devdredi_get_client_ip() {
    $candidates = array();

    // Forwarded headers are client-spoofable, so only trust them when the admin has
    // declared the site sits behind a proxy/CDN. Otherwise geo filtering could be bypassed.
    if (devdredi_get_bool_setting('trust_proxy', 0)) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ));
            foreach ($parts as $part) {
                $candidates[] = trim($part);
            }
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
        }
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
    }

    foreach ($candidates as $ip) {
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function devdredi_get_current_page_title() {
    $title = '';
    if (function_exists('wp_get_document_title')) {
        $title = wp_get_document_title();
    } else {
        $title = wp_title('', false);
    }
    
    if (empty($title)) {
        $title = get_bloginfo('name');
    }
    
    return esc_html($title);
}

function devdredi_get_visitor_key() {
    return sanitize_text_field(devdredi_get_client_ip());
}


// Hosts the plugin is configured to redirect to (links list, fallback, custom domains).
// Used to stop the scrub endpoint from being abused as an open redirect.
function devdredi_allowed_redirect_hosts() {
    $hosts = array();
    $add = function ($url) use (&$hosts) {
        $url = trim((string) $url);
        if ($url === '') return;
        if (!preg_match('~^https?://~i', $url)) { $url = 'http://' . $url; }
        $h = wp_parse_url($url, PHP_URL_HOST);
        if ($h) {
            $h = preg_replace('#^www\.#i', '', strtolower($h));
            $hosts[$h] = true;
        }
    };
    foreach (array_filter(array_map('trim', explode("\n", (string) devdredi_get_setting('links_list', '')))) as $l) { $add($l); }
    $add(devdredi_get_setting('fallback_url', ''));
    foreach (devdredi_get_custom_domains() as $d) { $add($d); }
    return $hosts;
}

function devdredi_is_allowed_redirect_target($url) {
    $scheme = wp_parse_url($url, PHP_URL_SCHEME);
    if (!in_array(strtolower((string) $scheme), array('http', 'https'), true)) { return false; }
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!$host) { return false; }
    $host = preg_replace('#^www\.#i', '', strtolower($host));
    $allowed = devdredi_allowed_redirect_hosts();
    return isset($allowed[$host]);
}

function devdredi_handle_fallback() {
    $fallback_mode = devdredi_get_setting('fallback_mode', 'leave');
    if ($fallback_mode === 'send') {
        $fallback_url = devdredi_get_setting('fallback_url', '');
        // Only honor a well-formed http(s) fallback target; esc_url_raw drops javascript:/data:/
        // relative values so a bad stored setting can't turn the fallback into an open/XSS vector.
        $fallback_url = esc_url_raw( trim( (string) $fallback_url ), array( 'http', 'https' ) );
        if (!empty($fallback_url)) {
            $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
            if (preg_match('/\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|webp|mp4|pdf|zip)$/i', $request_uri)) {
                return;
            }

            $bc = (int) devdredi_get_setting('user_bypass_count', 0);
            devdredi_update_setting('user_bypass_count', $bc + 1);
            devdredi_bump_daily(array('byp' => 1));

            $GLOBALS['devdredi_redirecting'] = true;
            wp_redirect($fallback_url, 302); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional off-site redirect; wp_safe_redirect would block external destinations.
    exit;
}
    }
}


/**
 * "Referring websites" (1.5.0): the rule's list, one entry per line, lower-cased, without scheme / www. / path.
 * An entry WITH a dot is a domain and matches that host and every subdomain (reddit.com: old.reddit.com too).
 * An entry WITHOUT a dot is a word and matches any referring host that contains it (reddit: reddit.com,
 * redditmedia.com, out.reddit.com) (owner 2026-09-15: "reddit has a lot of different links, we can't find all").
 */
function devdredi_referrer_list($raw = null)
{
    $raw = null === $raw ? (string) devdredi_get_setting('referrer_list', '') : (string) $raw;
    $out = array();
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $e = strtolower(trim($line));
        $e = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $e); // scheme
        $e = preg_replace('#[/?\#].*$#', '', $e);              // path / query
        $e = preg_replace('#^www\.#', '', $e);
        $e = preg_replace('#[^a-z0-9.\-]#', '', $e);
        $e = trim($e, '.');
        if ($e !== '') {
            $out[$e] = 1;
        }
    }
    return array_keys($out);
}

/** True when the referring host matches one list entry (domain = host or subdomain, word = contains). */
function devdredi_referrer_entry_matches($host, $entry)
{
    $host  = strtolower((string) $host);
    $entry = strtolower((string) $entry);
    if ($host === '' || $entry === '') {
        return false;
    }
    if (strpos($entry, '.') === false) {
        return strpos($host, $entry) !== false;
    }
    return $host === $entry || substr($host, -(strlen($entry) + 1)) === '.' . $entry;
}

/**
 * The referring host of this request, without www. The cache-immunity bootstrap (devdredi_print_referrer_bootstrap)
 * re-requests a page served from a full-page cache with ?dd_rm_ref=<host>; on that request the real HTTP_REFERER is the
 * page itself, so the carried host is honoured (it is validated against the list like any other referrer).
 * The site's own host never counts as a referrer: internal navigation must not trigger a "reddit" word.
 */
function devdredi_referrer_host()
{
    $forced = isset($_GET['dd_rm_ref']) ? strtolower(preg_replace('#[^a-z0-9.\-]#i', '', sanitize_text_field(wp_unslash($_GET['dd_rm_ref'])))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only redirect gate, host-validated and matched against the rule's own list
    if ($forced !== '') {
        $host = $forced;
    } else {
        $ref  = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : ''; // not esc_url_raw: app referrers (android-app://com.reddit.frontpage) must keep their host
        $host = $ref !== '' ? strtolower((string) wp_parse_url($ref, PHP_URL_HOST)) : '';
    }
    $host = preg_replace('#^www\.#', '', (string) $host);
    $own  = preg_replace('#^www\.#', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
    if ($host === '' || $host === $own || $host === 'none') { // 'none' = the footer script's marker for "no referrer at all" (1.5.2)
        return '';
    }
    return $host;
}

/**
 * Did this visitor ARRIVE from outside the site (1.5.2)? True with no referrer at all (typed, bookmark, a browser that
 * hides it) or a referrer on another host; false when the referrer is this site, i.e. the visitor is moving between
 * pages. The footer script's dd_rm_ref marker wins over the Referer header, because the reload it triggers carries the
 * page itself as referrer ('none' = the landing had no referrer).
 */
function devdredi_arrived_from_outside()
{
    $own = preg_replace('#^www\.#', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
    if (isset($_GET['dd_rm_ref'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public front-end marker, read only
        $forced = strtolower(preg_replace('#[^a-z0-9.\-]#i', '', sanitize_text_field(wp_unslash($_GET['dd_rm_ref'])))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($forced === 'none') {
            return true;
        }
        $forced = preg_replace('#^www\.#', '', $forced);
        return $forced !== '' && $forced !== $own;
    }
    $ref = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
    if ($ref === '') {
        return true;
    }
    $host = preg_replace('#^www\.#', '', strtolower((string) wp_parse_url($ref, PHP_URL_HOST)));
    return $host !== '' && $host !== $own;
}

/** Does the current request come from one of the rule's referring websites? */
function devdredi_referrer_matches()
{
    $host = devdredi_referrer_host();
    if ($host === '') {
        return false;
    }
    foreach (devdredi_referrer_list() as $entry) {
        if (devdredi_referrer_entry_matches($host, $entry)) {
            return true;
        }
    }
    return false;
}

/** True when the request matches one of the URLs picked under URLs To Redirect (selected_links_list). */
function devdredi_selected_links_match($current_full_url, $current_path, $current_host)
{
    foreach (array_filter(array_map('trim', explode("\n", (string) devdredi_get_setting('selected_links_list', '')))) as $item) {
        if (devdredi_check_url_match($item, $current_full_url, $current_path, $current_host)) {
            return true;
        }
    }
    // A picked category or shop archive also covers every post or product in it (1.5.0).
    return devdredi_selected_groups_match();
}

/**
 * An address of this site in comparable form: the lower-case path without the trailing slash ("/" for the root) and
 * the query arguments. Plain permalinks put everything on the root with a query (/?cat=5, /?post_type=product), so
 * the query is part of the identity.
 *
 * @return array{0: string, 1: array}
 */
function devdredi_group_address($url)
{
    $query = array();
    parse_str((string) wp_parse_url(trim((string) $url), PHP_URL_QUERY), $query);
    return array('/' . trim(strtolower((string) wp_parse_url(trim((string) $url), PHP_URL_PATH)), '/'), $query);
}

/**
 * The group behind one picked address, or null: array('type', <post type>) when it is a post type archive (the
 * WooCommerce shop), array('term', <taxonomy>, <term id>) when it is a term archive of a public taxonomy. Pages and
 * single items are no group and keep matching by their address only.
 */
function devdredi_resolve_one_group($url, $archives, $taxes)
{
    list($path, $query) = devdredi_group_address($url);
    foreach ($archives as $pt => $addr) {
        if ($addr[0] === $path && $addr[1] == $query) {
            return array('type', $pt);
        }
    }
    $candidates = array();
    if ($path !== '/') {
        foreach ($taxes as $tax) {
            $candidates[] = array($tax->name, get_term_by('slug', substr($path, strrpos($path, '/') + 1), $tax->name));
        }
    }
    foreach ($taxes as $tax) {
        if (!empty($tax->query_var) && isset($query[$tax->query_var]) && is_string($query[$tax->query_var])) {
            $candidates[] = array($tax->name, get_term_by('slug', $query[$tax->query_var], $tax->name));
        }
    }
    if (isset($query['cat']) && is_string($query['cat']) && ctype_digit($query['cat'])) {
        $candidates[] = array('category', get_term((int) $query['cat'], 'category'));
    }
    foreach ($candidates as $c) {
        if (!$c[1] || is_wp_error($c[1])) {
            continue;
        }
        $link = get_term_link($c[1]);
        if (is_wp_error($link)) {
            continue;
        }
        $addr = devdredi_group_address($link);
        if ($addr[0] === $path && $addr[1] == $query) {
            return array('term', $c[0], (int) $c[1]->term_id);
        }
    }
    return null;
}

/**
 * The groups behind picked addresses (owner 2026-09-15: "if you pick the category, all posts under the category, or
 * all products under the category"): a term archive stands for every item carrying that term or one of its child
 * terms, a post type archive for every item of that type. Resolved when the rule is saved; the engine reads the result.
 *
 * @return array{terms: array, types: array}
 */
function devdredi_resolve_selected_groups($urls)
{
    $out      = array('terms' => array(), 'types' => array());
    $archives = array();
    foreach (get_post_types(array('public' => true, '_builtin' => false)) as $pt) {
        $link = get_post_type_archive_link($pt);
        if ($link) {
            $archives[$pt] = devdredi_group_address($link);
        }
    }
    $taxes = get_taxonomies(array('public' => true), 'objects');
    foreach (array_unique(array_filter(array_map('trim', (array) $urls))) as $url) {
        $g = devdredi_resolve_one_group($url, $archives, $taxes);
        if ($g && $g[0] === 'type') {
            $out['types'][] = $g[1];
        } elseif ($g) {
            $out['terms'][] = array($g[1], $g[2]);
        }
    }
    $out['types'] = array_values(array_unique($out['types']));
    return $out;
}

/** The current rule's picked groups: saved with the rule, resolved in memory for a rule saved before 1.5.0. */
function devdredi_selected_groups()
{
    static $cache = array();
    $rid = isset($GLOBALS['devdredi_rule']) ? (string) $GLOBALS['devdredi_rule'] : '';
    if (isset($cache[$rid])) {
        return $cache[$rid];
    }
    $saved = json_decode((string) devdredi_get_setting('selected_links_groups', ''), true);
    if (is_array($saved) && isset($saved['terms'], $saved['types']) && is_array($saved['terms']) && is_array($saved['types'])) {
        $cache[$rid] = $saved;
        return $saved;
    }
    // Saved before 1.5.0: only the picks the picker marked as categories can be groups (a page never is).
    $urls = array();
    $meta = json_decode((string) devdredi_get_setting('selected_links_meta', ''), true);
    foreach (is_array($meta) ? $meta : array() as $row) {
        if (is_array($row) && isset($row['type'], $row['url']) && $row['type'] === 'category') {
            $urls[] = (string) $row['url'];
        }
    }
    $cache[$rid] = $urls ? devdredi_resolve_selected_groups($urls) : array('terms' => array(), 'types' => array());
    return $cache[$rid];
}

/**
 * True when the request is a single item inside a picked group: a post in a picked category or one of its child
 * categories, a product in a picked product category, an item of a picked archive.
 */
function devdredi_selected_groups_match()
{
    if (!is_singular()) {
        return false;
    }
    $post_id = (int) get_queried_object_id();
    if ($post_id <= 0) {
        return false;
    }
    $groups = devdredi_selected_groups();
    if (in_array(get_post_type($post_id), $groups['types'], true)) {
        return true;
    }
    foreach ($groups['terms'] as $t) {
        if (!is_array($t) || count($t) !== 2 || !taxonomy_exists((string) $t[0])) {
            continue;
        }
        $ids = array((int) $t[1]);
        if (is_taxonomy_hierarchical((string) $t[0])) {
            $kids = get_term_children((int) $t[1], (string) $t[0]);
            if (is_array($kids)) {
                $ids = array_merge($ids, array_map('intval', $kids));
            }
        }
        if (has_term($ids, (string) $t[0], $post_id)) {
            return true;
        }
    }
    return false;
}

/** True when one of the rule's Custom URLs covers this request. */
function devdredi_custom_urls_match($current_full_url, $current_path, $current_host)
{
    foreach (array_filter(array_map('trim', explode("\n", devdredi_get_setting('custom_links_list', '')))) as $item) {
        if (devdredi_check_url_match($item, $current_full_url, $current_path, $current_host)) {
            return true;
        }
    }
    return false;
}

/** Does the CURRENT (active) rule's "What To Redirect" condition match this request? (No side effects.) */
function devdredi_request_matches_rule($current_full_url, $current_path, $current_host)
{
    $what = devdredi_get_setting('what_to_redirect', 'entire_website');

    if ($what === 'referrer') {
        // "Only on selected pages" (1.5.0) narrows the rule to the URLs picked under URLs To Redirect.
        return devdredi_referrer_matches() && (!devdredi_get_bool_setting('referrer_only_selected', 0) || devdredi_selected_links_match($current_full_url, $current_path, $current_host));
    }

    // "Only visitors arriving from outside" (1.5.2): the page must be in scope AND the visitor must have landed here
    // from another site or with no referrer; moving between pages of this site never triggers the rule.
    if (devdredi_get_bool_setting('outside_only', 0) && !devdredi_arrived_from_outside()) {
        return false;
    }

    if ($what === 'selected_existing') {
        // The picked addresses, and the posts or products inside a picked category or shop archive (1.5.0).
        return devdredi_selected_links_match($current_full_url, $current_path, $current_host);
    }

    if ($what === 'custom_urls') {
        return devdredi_custom_urls_match($current_full_url, $current_path, $current_host);
    }

    if ($what === 'all_404') {
        return is_404();
    }

    return true; // entire_website (or unknown) — matches every page.
}

/**
 * Cache immunity: mark this render no-store so no full-page cache (WP Rocket, W3TC, LiteSpeed,
 * Super Cache, Cloudflare APO, proxies) ever stores it. Used on every render of a URL targeted
 * by a running rule — cached copies freeze one visitor's outcome and break the redirect for
 * everyone else until the cache expires.
 */
function devdredi_send_nocache_immunity() {
    $cs_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
    if (preg_match('/\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|webp|mp4|pdf|zip)$/i', $cs_uri)) {
        return;
    }
    nocache_headers();
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-suppression constant recognized by caching plugins.
    if (!defined('DONOTCACHEDB')) define('DONOTCACHEDB', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-suppression constant recognized by caching plugins.
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-suppression constant recognized by caching plugins.
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

add_action('template_redirect', function(){

    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    
    global $devdredi_redirect_tracking_done;
    if (isset($devdredi_redirect_tracking_done) && $devdredi_redirect_tracking_done) {
        return;
    }
    $devdredi_redirect_tracking_done = false;

    // Build the live URL from the request host + URI directly. home_url(add_query_arg(null,null))
    // re-prepends the site's home path, so on a subdirectory install it doubles the prefix
    // (/blog/blog/post) and Custom/Selected-page matches never fire.
    $current_path = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
    $current_host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
    $current_full_url = ( is_ssl() ? 'https://' : 'http://' ) . $current_host . $current_path;

    // Multi-rule: pick the first rule (by priority) that is running, in schedule, and
    // matches this request. That rule then runs below — each rule is fully independent.
    $rm_rules_eng = devdredi_get_rules();
    usort($rm_rules_eng, function ($a, $b) {
        return ((int) (isset($a['priority']) ? $a['priority'] : 0)) - ((int) (isset($b['priority']) ? $b['priority'] : 0));
    });
    $selected_rule = null;
    $targeted_off_schedule = false;
    foreach ($rm_rules_eng as $r) {
        $GLOBALS['devdredi_rule'] = $r['id'];
        if (devdredi_check_status_and_schedule()
            && devdredi_request_matches_rule($current_full_url, $current_path, $current_host)) {
            if (devdredi_is_in_schedule()) {
                $selected_rule = $r['id'];
                break;
            }
            // A running rule targets this URL but is out of schedule right now. Unless its
            // schedule is permanently over it WILL resume — a copy cached now would freeze the
            // plain page and break the redirect once it does, so this render must be no-store
            // too. Referrer mode is excluded (its client-side bootstrap keeps pages cacheable).
            // Referrer and outside-only modes keep their plain pages cacheable (the footer script re-runs the
            // engine), EXCEPT a render that already carries the script's dd_rm_ref marker: cached with the marker,
            // it would never reload again and the redirect would stay dead until the cache expired (1.5.2).
            $marker = isset($_GET['dd_rm_ref']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only
            if (($marker || (devdredi_get_setting('what_to_redirect', 'entire_website') !== 'referrer'
                && !devdredi_get_bool_setting('outside_only', 0)))
                && !devdredi_schedule_permanently_over()) {
                $targeted_off_schedule = true;
            }
        }
    }
    if ($selected_rule === null) {
        $GLOBALS['devdredi_rule'] = null;
        if ($targeted_off_schedule) {
            devdredi_send_nocache_immunity();
        }
        devdredi_track_visit($current_full_url);
        $devdredi_redirect_tracking_done = true;
        return;
    }
    $GLOBALS['devdredi_rule'] = $selected_rule;

    $what_to_redirect = devdredi_get_setting('what_to_redirect', 'entire_website');
    $current_path = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
    $current_host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );

    // Gate by "What To Redirect". ONLY the chosen mode's data is honored — lists saved for
    // the OTHER modes are ignored on this request.
    if ($what_to_redirect === 'selected_existing' || $what_to_redirect === 'custom_urls') {
        if ($what_to_redirect === 'selected_existing') {
            // The picked addresses, and the posts or products inside a picked category or shop archive (1.5.0).
            $matched = devdredi_selected_links_match($current_full_url, $current_path, $current_host);
        } else {
            $items = array_filter(array_map('trim', explode("\n", devdredi_get_setting('custom_links_list', ''))));
            $matched = false;
            foreach ($items as $item) {
                if (devdredi_check_url_match($item, $current_full_url, $current_path, $current_host)) {
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            return;
        }
    } elseif ($what_to_redirect === 'all_404') {
        // Only requests that resolve to a 404 / not-found page.
        if (!is_404()) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            return;
        }
    } elseif ($what_to_redirect === 'referrer') {
        // Only visitors arriving from one of the rule's referring websites (1.5.0), and with "Only on selected pages"
        // ticked only on the URLs picked under URLs To Redirect.
        if (!devdredi_referrer_matches() || (devdredi_get_bool_setting('referrer_only_selected', 0) && !devdredi_selected_links_match($current_full_url, $current_path, $current_host))) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            return;
        }
    }
    // 'entire_website' (or any unknown value): no path gating — act on every page.

    // Cache immunity: a running, in-schedule rule TARGETS this URL, but whether THIS visitor is
    // actually redirected depends on per-visitor signals (geo, device, run-once, revisit). A
    // full-page cache keys only on the URL, so without this it could store the plain
    // (non-redirected) render from one visitor and then serve that frozen copy to a visitor who
    // SHOULD be redirected, silently breaking the redirect until the cache expires. Mark every
    // render of a rule-targeted URL no-store so the page is never cached and PHP runs on every
    // hit, keeping the decision live. Static assets are excluded inside the helper.
    devdredi_send_nocache_immunity();

    if (!devdredi_is_in_schedule()) {
        devdredi_track_visit($current_full_url);
        $devdredi_redirect_tracking_done = true;
        devdredi_handle_fallback();
        return;
    }

    // Don't redirect known bots (1.5.1, on by default): crawlers, monitors and scrapers get the page
    // as usual - no redirect, no fallback - and never count as visitors: not for the N-th visitor or
    // once-per-visitor logic and not in the page-view / unique-user statistics. Only real visitors reach the redirect below.
    if (devdredi_get_bool_setting('skip_bots', 1) && devdredi_is_known_bot()) {
        $bs = (int) devdredi_get_setting('user_bot_skip_count', 0);
        if (empty($GLOBALS['devdredi_read_failed'])) { devdredi_update_setting('user_bot_skip_count', $bs + 1); }
        devdredi_bump_daily(array('bs' => 1));
        $devdredi_redirect_tracking_done = true; // not a visitor: no page view, no unique-user entry
        return;
    }

    $run_once = devdredi_get_setting('run_once', 'never');
    $visitor_key = devdredi_get_visitor_key();
    $visitor_id = '';
    if ($run_once === 'ip') {
        $visitor_id = $visitor_key;
    } else if ($run_once === 'ip_ua') {
        $user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
        $visitor_id = hash('sha256', $visitor_key . "\n" . (string) $user_agent);
    }

    $vc = (int) devdredi_get_setting('visitor_count', 0) + 1;
    devdredi_update_setting('visitor_count', $vc);

    $open_on_every = max(1, (int) devdredi_get_setting('open_on_every', 1));
    if ($run_once === 'never') {
        $open_on_every = 1;
    }
    if ($open_on_every > 1) {
        if ($visitor_id === '') {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }

        $visitor_hash = substr(hash('sha256', $visitor_id), 0, 32);
        $reset_count = (int) devdredi_get_setting('reset_count', 0);
        // Include the running rule id: reset_count can match across rules, so without it two rules
        // would share one Nth-visitor eligibility transient and contaminate each other's counting.
        $rule_scope = (string) (isset($GLOBALS['devdredi_rule']) ? $GLOBALS['devdredi_rule'] : '');
        $assign_key = 'devdredi_nth_' . $rule_scope . '_' . $reset_count . '_' . $visitor_hash . '_' . $open_on_every;
        $eligible = get_transient($assign_key);
        if ($eligible === false) {
            $unique_counter = (int) devdredi_get_setting('unique_visitor_count', 0) + 1;
            devdredi_update_setting('unique_visitor_count', $unique_counter);
            $eligible = ($unique_counter % $open_on_every === 0) ? 1 : 0;
            set_transient($assign_key, $eligible, YEAR_IN_SECONDS);
        }
        if ((int) $eligible !== 1) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }
    }

    // Device targeting: only redirect the enabled device types; others pass through (fallback).
    $dtype = devdredi_device_type();
    if (!devdredi_get_setting('device_' . $dtype, 1)) {
        devdredi_track_visit($current_full_url);
        $devdredi_redirect_tracking_done = true;
        devdredi_handle_fallback();
        return;
    }

    // Geo targeting: optional country include/exclude filter.
    $geo_filter_enabled = devdredi_get_setting('geo_filter_enabled', 0);

    if ($geo_filter_enabled) {
        // Geo resolution is keyless (DevDome MaxMind geo-resolve).
        $geo_mode = devdredi_get_setting('geo_filter_mode', 'whitelist');
        // Each mode has its own list. Whitelist falls back to the legacy shared setting.
        $codes_csv = ($geo_mode === 'blacklist')
            ? devdredi_get_setting('geo_filter_blacklist', '')
            : devdredi_get_setting('geo_filter_whitelist', devdredi_get_setting('geo_filter_country_codes', ''));
        $country_list = array_filter(array_map('trim', explode(',', strtoupper($codes_csv))));
        $user_country = devdredi_get_user_country();
        $known = ($user_country && $user_country !== 'Unknown');
        $in_list = ($known && in_array(strtoupper($user_country), $country_list, true));

        if ($geo_mode === 'blacklist') {
            // Redirect everyone EXCEPT the listed countries. Fail CLOSED on an unresolved country:
            // if we can't tell where the visitor is, don't redirect (an Unknown could be a
            // blacklisted country we just failed to resolve). A resolved country not on the list
            // still redirects, so an empty list redirects every visitor whose country resolved.
            $is_allowed = ($known && !$in_list);
        } else {
            // Whitelist: redirect ONLY the listed countries. Empty list => redirect no one.
            $is_allowed = $in_list;
        }

        if (!$is_allowed) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }
    }


    $revisit_delay = (int) devdredi_get_setting('revisit_delay', 0);
    $last_redirects = devdredi_get_setting('last_redirects', array());
    if (!is_array($last_redirects)) {
        $last_redirects = array();
    }
    // "Once per visitor": run_once set with revisit_delay 0 means redirect a given visitor once,
    // ever (an unbounded window). A positive delay re-allows the redirect after that many minutes.
    // Gating this on revisit_delay > 0 (as before) made "once" a no-op — the visitor got redirected
    // every visit because nothing was ever suppressed.
    if ($run_once !== 'never' && $visitor_id !== '') {
        $last_redirect = isset($last_redirects[$visitor_id]) ? (int) $last_redirects[$visitor_id] : 0;
        if ($last_redirect > 0 && ($revisit_delay <= 0 || time() < ($last_redirect + ($revisit_delay * 60)))) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }
    }




    if ($what_to_redirect !== 'selected_existing') {
        $req_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
        if (preg_match('/\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|webp|mp4|pdf|zip)$/i', $req_uri)) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }
    }

    $redirect_source = devdredi_get_setting('redirect_source', 'provided');
    if (!in_array($redirect_source, array('provided', 'transit', 'found'), true)) {
        $redirect_source = 'provided';
    }

    $found_mode = false;
    $target = '';

    if ($redirect_source === 'transit') {
        // Transit: keep the visitor's path, send it to the same path on another domain.
        // e.g. yoursite.com/post/123?x=1 -> otherdomain.com/post/123?x=1
        $transit_domain = trim((string) devdredi_get_setting('transit_domain', ''));
        if ($transit_domain === '') {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }
        $req = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
        if ($req === '') {
            $req = '/';
        }
        // Strip chain-internal params from the inherited query string (?dd_ref/_dd are hop-attribution
        // markers another DevDome site may have stamped on the way in) — they must not ride to the
        // destination, especially a merchant.
        $target = remove_query_arg(array('dd_ref', '_dd'), 'https://' . $transit_domain . $req);
    } elseif ($redirect_source === 'found') {
        // Found: the destination is a link/button on the page — resolved CLIENT-SIDE by the
        // redirect JS (no server-side target). Requires at least one pattern.
        $patterns = array_filter(array_map('trim', explode("\n", (string) devdredi_get_setting('page_links_contains', ''))));
        if (empty($patterns)) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }
        $found_mode = true;
    } else {
        // Provided: rotate through the destination URLs.
        $links_list = devdredi_get_setting('links_list', '');
        $links_array = array_filter(array_map('trim', explode("\n", $links_list)));
        if (empty($links_array)) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }

        $links_mode = devdredi_get_setting('links_mode', 'sequential');
        $links_repeat = (int) devdredi_get_setting('links_repeat', 1);
        $descending_spread = (float) devdredi_get_setting('descending_spread', 0.5);
        $descending_seed = (int) devdredi_get_setting('descending_seed', 0);

        // Rotation state lives in plugin-wide options keyed by this string. Prefer the suite beacon's
        // session cookie as the visitor identity (survives IP changes, distinguishes shared-IP
        // visitors), falling back to the IP. Prefix with the running rule id so two rules sharing a
        // visitor can't corrupt each other's sequential counter / random queue.
        $rotation_visitor = $visitor_key;
        if (!empty($_COOKIE['ddc_sid'])) {
            $rsid = preg_replace('/[^a-f0-9]/', '', sanitize_text_field(wp_unslash($_COOKIE['ddc_sid'])));
            if (strlen($rsid) >= 8) { $rotation_visitor = 'sid:' . $rsid; }
        }
        $rotation_key = (string) (isset($GLOBALS['devdredi_rule']) ? $GLOBALS['devdredi_rule'] : '') . '|' . $rotation_visitor;
        $link_to_open = devdredi_pick_link($links_array, $links_mode, $rotation_key, $descending_spread, $descending_seed, $links_repeat);
        if (empty($link_to_open)) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }

        $target = devdredi_resolve_redirect_target($link_to_open);
        if (empty($target)) {
            devdredi_track_visit($current_full_url);
            $devdredi_redirect_tracking_done = true;
            devdredi_handle_fallback();
            return;
        }
    }

    $devdredi_redirect_tracking_done = true;
    // This request is being handled server-side (and the response is no-store, so it's never
    // cached). Tell the cache-immunity bootstrap not to print on this render — the bootstrap
    // only belongs in the cacheable (non-matching) copy of the page.
    $GLOBALS['devdredi_matched'] = true;

    nocache_headers();
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-suppression constant recognized by caching plugins.
    if (!defined('DONOTCACHEDB')) define('DONOTCACHEDB', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-suppression constant recognized by caching plugins.
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-suppression constant recognized by caching plugins.
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    devdredi_track_visit($current_full_url);



    $redirect_type = devdredi_get_setting('redirect_type', 'js');
    $open_mode = devdredi_get_setting('open_mode', 'same_tab');

    // Found mode resolves the destination from the page in the browser, so it must use JS.
    if ($found_mode) {
        $redirect_type = 'js';
    }

    if ($redirect_type !== 'js') {
        $open_mode = 'same_tab';
    }
    
    // A rule-matched JS-mode page is a transit hop, not content: the exit beacon already logs the
    // hop server-side, so track.js pageviews/engagements on it double-count the visit under
    // ?dd_ref-tagged paths (the "4 visitors / 1 click" dashboard artifact). Set the tracker's own
    // load guard before track.js can run. Enqueued here, during template_redirect, so the handle
    // is in the queue before any tracker enqueues on wp_enqueue_scripts and its inline script
    // therefore prints ahead of the tracker tag in the head.
    if ($redirect_type === 'js') {
        devdredi_enqueue_head_js('window.__ddTrackerLoaded = 1;');
    }

    if (in_array($redirect_type, array('301', '302', '307', '308', 'meta'), true)) {
        $original_referer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
        $_SERVER['HTTP_REFERER'] = $current_full_url;

        devdredi_track_visit($current_full_url, $target, null, null, is_404(), true, $redirect_type);

        $_SERVER['HTTP_REFERER'] = $original_referer;

        if ($run_once !== 'never' && $visitor_id !== '') {
            devdredi_record_last_redirect($visitor_id);
        }

        $rc = (int) devdredi_get_setting('user_redirects_count', 0);
        devdredi_update_setting('user_redirects_count', $rc + 1);

        // Per-dimension + device counts. The JS pixel handler does this for JS redirects; mirror it
        // here so server-side 301/302/307/308/meta redirects feed the same breakdowns. ("found" mode
        // is JS-only, so rc_by_found stays in the pixel.)
        $src_path = devdredi_normalize_path($current_full_url);
        if ($src_path !== '') { devdredi_bump_count('rc_by_source', $src_path); }
        if ($target !== '') { devdredi_bump_count('rc_by_dest', $target); }
        $ref_host = $original_referer ? strtolower((string) wp_parse_url($original_referer, PHP_URL_HOST)) : '';
        $ref_host = preg_replace('#^www\.#', '', (string) $ref_host);
        if ($ref_host !== '') { devdredi_bump_count('rc_by_referrer', $ref_host); }
        $daily_cc = '';
        if (devdredi_get_setting('geo_filter_enabled', 0)) {
            $cc = strtoupper((string) devdredi_get_user_country());
            if ($cc !== '' && $cc !== 'UNKNOWN') { devdredi_bump_count('rc_by_country', $cc); $daily_cc = $cc; }
        }
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

        // Drop the inbound referrer so it can't ride this hop to the next site in the chain — a
        // redirect must never leak where the visitor came from (e.g. reddit) to its destination.
        if (!headers_sent()) { header('Referrer-Policy: no-referrer'); }

        if ($redirect_type === 'meta') {
            $page_title = devdredi_get_current_page_title();
            echo '<!DOCTYPE html><html><head><title>' . esc_html($page_title) . '</title><meta name="referrer" content="no-referrer"><meta http-equiv="refresh" content="0;url=' . esc_url($target) . '"></head><body style="font-family:sans-serif;text-align:center;padding-top:50px;"></body></html>';
            exit;
        }

        $status = (int) $redirect_type; // 301/302/307/308
        $GLOBALS['devdredi_redirecting'] = true;
        wp_redirect($target, $status); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional off-site redirect; wp_safe_redirect would block external destinations.
        exit;
    } else {
        $redirect_id = '';
        try {
            $redirect_id = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $redirect_id = substr(md5(uniqid('', true)), 0, 16);
        }

        $bfcache_reload_js = "\n            (function(){\n                try {\n                    window.addEventListener('pageshow', function(e){\n                        if (e && e.persisted) {\n                            location.reload();\n                        }\n                    });\n                    var nav = performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;\n                    if (nav && nav.type === 'back_forward') {\n                        location.reload();\n                    }\n                } catch (e) {}\n            })();\n        ";
        
        if ($open_mode === 'new_tab') {
            $delay_min = (float) devdredi_get_setting('new_tab_delay_min', 0);
            $delay_max = (float) devdredi_get_setting('new_tab_delay_max', 0);
        } else {
            $delay_min = (float) devdredi_get_setting('same_tab_delay_min', 0);
            $delay_max = (float) devdredi_get_setting('same_tab_delay_max', 0);
        }

        $zero_delay_config = ($delay_min == 0.0 && $delay_max == 0.0);

        if ($delay_max < $delay_min) $delay_max = $delay_min;
        $delay = ($delay_min === $delay_max) ? $delay_min : ($delay_min + (wp_rand(0, 1000000) / 1000000) * ($delay_max - $delay_min));

        // At least 1s before the hop so the page is committed to session history and the
        // browser Back button returns here normally.
        if ($delay < 1.0) {
            $delay = 1.0;
        }

        // Client-side randomized delay (ms): a fresh fractional value between min and max is
        // picked on EACH page load (so it isn't frozen by page caching, e.g. 1.34s, 2.45s).
        $delay_floor = ($delay_min < 1.0) ? 1.0 : $delay_min;
        if ($delay_max < $delay_floor) $delay_max = $delay_floor;
        $delay_js = 'Math.round((' . $delay_floor . ' + Math.random() * (' . ($delay_max - $delay_floor) . ')) * 1000)';

        // After Click Delay (optional): a fresh random value (ms) between min and max, each
        // capped at 4s. Disabled => 0 (redirect immediately on click). Mirrors $delay_js so it
        // isn't frozen by page caching.
        if ($open_mode === 'new_tab') {
            $ac_enabled = (int) devdredi_get_setting('after_click_enabled', 0);
            $ac_min = (float) devdredi_get_setting('after_click_min', 0);
            $ac_max = (float) devdredi_get_setting('after_click_max', 0);
        } else {
            $ac_enabled = (int) devdredi_get_setting('same_tab_after_click_enabled', 0);
            $ac_min = (float) devdredi_get_setting('same_tab_after_click_min', 0);
            $ac_max = (float) devdredi_get_setting('same_tab_after_click_max', 0);
        }
        if ($ac_min < 0) $ac_min = 0;
        if ($ac_max > 4) $ac_max = 4;
        if ($ac_min > 4) $ac_min = 4;
        if ($ac_max < $ac_min) $ac_max = $ac_min;
        $after_click_js = $ac_enabled
            ? 'Math.round((' . $ac_min . ' + Math.random() * (' . ($ac_max - $ac_min) . ')) * 1000)'
            : '0';

        // FOUND mode: scan the page in the browser for the Nth link/button matching each pattern
        // (priority = order) and redirect to it. No fixed server-side target.
        if ($found_mode) {
            $pat_lines = array_filter(array_map('trim', explode("\n", (string) devdredi_get_setting('page_links_contains', ''))));
            $found_patterns = array();
            foreach ($pat_lines as $ln) {
                $parts = explode('|', $ln);
                $frag = trim($parts[0]);
                $nth = isset($parts[1]) ? max(1, (int) $parts[1]) : 1;
                if ($frag !== '') {
                    $found_patterns[] = array('frag' => $frag, 'nth' => $nth);
                }
            }
            $found_patterns_json = wp_json_encode($found_patterns);
            $is_404_js = is_404() ? '1' : '0';
            add_action('wp_footer', function () use ($found_patterns_json, $delay_js, $after_click_js, $open_mode, $current_full_url, $bfcache_reload_js, $redirect_id, $is_404_js) {
                ?>
                <?php devdredi_footer_js_capture(function () use ($found_patterns_json, $delay_js, $after_click_js, $open_mode, $current_full_url, $bfcache_reload_js, $redirect_id, $is_404_js) { ?>
                    <?php echo $bfcache_reload_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline JS, no dynamic/user data. ?>
                    (function () {
                        var patterns = <?php echo $found_patterns_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output. ?>;
                        var rid = <?php echo wp_json_encode($redirect_id); ?>;
                        var currentUrl = <?php echo wp_json_encode($current_full_url); ?>;
                        function findUrl() {
                            for (var i = 0; i < patterns.length; i++) {
                                var frag = (patterns[i].frag || '').toLowerCase(), nth = patterns[i].nth || 1, c = 0;
                                var as = document.getElementsByTagName('a');
                                for (var j = 0; j < as.length; j++) {
                                    var a = as[j];
                                    if (!a.href) continue;
                                    var hay = (a.href + ' ' + (a.textContent || '')).toLowerCase();
                                    if (hay.indexOf(frag) !== -1) { c++; if (c === nth) return { url: a.href, frag: patterns[i].frag }; }
                                }
                            }
                            return null;
                        }
                        function countHit(url, fp) {
                            try {
                                var payload = btoa(unescape(encodeURIComponent(JSON.stringify({
                                    l: currentUrl, o: url, rid: rid, r: document.referrer || '', f: <?php echo (int) $is_404_js; ?>, ru: <?php echo wp_json_encode($GLOBALS['devdredi_rule']); ?>, fp: fp
}))));
                                // sendBeacon survives the imminent same-tab navigation; a raw Image()
                                // request is cancelled when location.replace/assign tears the page down,
                                // which dropped ~88% of hub exit events (analytics_audit_10). Fall back
                                // to Image only where sendBeacon is unavailable/refused.
                                var _b = <?php echo wp_json_encode( home_url('/') ); ?> + '?devdredi_r=1&p=' + encodeURIComponent(payload) + '&_t=' + Date.now();
                                if (!(navigator.sendBeacon && navigator.sendBeacon(_b))) { new Image().src = _b; }
                            } catch (_) {}
                        }
                        function go(win) {
                            var m = findUrl();
                            if (!m || !m.url) { if (win) { try { win.close(); } catch (_) {} } return; }
                            countHit(m.url, m.frag);
                            <?php if ($open_mode === 'new_tab'): ?>
                            try { if (win) { win.location = m.url; } else { window.open(m.url, '_blank'); } } catch (_) {}
                            <?php else: ?>
                            location.assign(m.url);
                            <?php endif; ?>
                        }
                        function init() {
                            setTimeout(function () {
                                <?php if ($open_mode === 'new_tab'): ?>
                                document.addEventListener('click', function () {
                                    var d = <?php echo $after_click_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS numeric expression built from float settings; no user input. ?>;
                                    // Open the tab synchronously in the gesture (pop-up blockers reject a
                                    // delayed window.open); navigate it once the after-click delay elapses.
                                    if (d > 0) { var w = window.open('about:blank', '_blank'); setTimeout(function () { go(w); }, d); } else { go(); }
                                }, { once: true, capture: true });
                                <?php else: ?>
                                go();
                                <?php endif; ?>
                            }, <?php echo $delay_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS numeric expression built from float settings; no user input. ?>);
                        }
                        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init, { once: true }); } else { init(); }
                    })();
                <?php });
            });
            return;
        }

        if ($open_mode === 'same_tab') {
            // Same-tab: optionally wait for a visitor click before navigating (instead of auto-redirect).
            $same_tab_require_click = (int) devdredi_get_setting('same_tab_require_click', 0);
            add_action('wp_footer', function() use ($target, $delay, $delay_js, $after_click_js, $same_tab_require_click, $current_full_url, $bfcache_reload_js, $zero_delay_config, $redirect_id) {
                ?>
                <a id="go" href="<?php echo esc_url($target); ?>" style="display:none;"></a>
                <?php devdredi_footer_js_capture(function () use ($target, $delay_js, $after_click_js, $same_tab_require_click, $current_full_url, $bfcache_reload_js, $zero_delay_config, $redirect_id) { ?>
                    <?php echo $bfcache_reload_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline JS, no dynamic/user data. ?>
                    document.addEventListener('DOMContentLoaded', function() {
                        var rid = <?php echo wp_json_encode($redirect_id); ?>;
                        <?php if ($zero_delay_config): ?>
                        (function(){
                            if (document.getElementById('devdredi-redirect-overlay')) return;
                            var overlay = document.createElement('div');
                            overlay.id = 'devdredi-redirect-overlay';
                            overlay.style.position = 'fixed';
                            overlay.style.top = '0';
                            overlay.style.left = '0';
                            overlay.style.right = '0';
                            overlay.style.bottom = '0';
                            overlay.style.background = '#ffffff';
                            overlay.style.zIndex = '99999';
                            overlay.style.display = 'flex';
                            overlay.style.alignItems = 'center';
                            overlay.style.justifyContent = 'center';
                            document.body.appendChild(overlay);
                        })();
                        <?php endif; ?>
                        function doSameTabNav() {
                            var link = document.getElementById('go');
                            if (!link) return;

                            setTimeout(function() {
                                try {
                                    var payload = btoa(unescape(encodeURIComponent(JSON.stringify({
                                        l: <?php echo wp_json_encode($current_full_url); ?>,
                                        o: <?php echo wp_json_encode($target); ?>,
                                        rid: rid,
                                        r: document.referrer || '',
                                        f: <?php echo is_404() ? '1' : '0'; ?>,
                                        ru: <?php echo wp_json_encode($GLOBALS['devdredi_rule']); ?>
                                    }))));
                                    // sendBeacon survives the same-tab navigation below; a raw Image()
                                    // is cancelled by location.replace/assign, dropping ~88% of hub exit
                                    // events (analytics_audit_10). Image is the no-sendBeacon fallback.
                                    var _b = <?php echo wp_json_encode( home_url('/') ); ?> + '?devdredi_r=1&p=' + encodeURIComponent(payload) + '&_t=' + Date.now();
                                    if (!(navigator.sendBeacon && navigator.sendBeacon(_b))) { new Image().src = _b; }
                                } catch (_) {}
                                location.assign(link.href);
                            }, 100);
                        }
                        setTimeout(function() {
                            <?php if ($same_tab_require_click): ?>
                            document.addEventListener('click', function() {
                                var acDelay = <?php echo $after_click_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS numeric expression built from float settings; no user input. ?>;
                                if (acDelay > 0) {
                                    setTimeout(doSameTabNav, acDelay);
                                } else {
                                    doSameTabNav();
                                }
                            }, { once: true, capture: true });
                            <?php else: ?>
                            doSameTabNav();
                            <?php endif; ?>
                        }, <?php echo $delay_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS numeric expression built from float settings; no user input. ?>);
                    });
                <?php });
            });
        } else {
            add_action('wp_footer', function() use ($target, $delay, $delay_js, $after_click_js, $current_full_url, $bfcache_reload_js, $zero_delay_config) {
                ?>
                <a id="go" style="display:none;" target="_blank"></a>
                <?php devdredi_footer_js_capture(function () use ($target, $delay_js, $after_click_js, $current_full_url, $bfcache_reload_js, $zero_delay_config) { ?>
                    <?php echo $bfcache_reload_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline JS, no dynamic/user data. ?>
                    (function() {
                        function initClickCapture() {
                            <?php if ($zero_delay_config): ?>
                            (function(){
                                if (document.getElementById('devdredi-redirect-overlay')) return;
                                var overlay = document.createElement('div');
                                overlay.id = 'devdredi-redirect-overlay';
                                overlay.style.position = 'fixed';
                                overlay.style.top = '0';
                                overlay.style.left = '0';
                                overlay.style.right = '0';
                                overlay.style.bottom = '0';
                                overlay.style.background = '#ffffff';
                                overlay.style.zIndex = '99999';
                                overlay.style.display = 'flex';
                                overlay.style.alignItems = 'center';
                                overlay.style.justifyContent = 'center';
                                document.body.appendChild(overlay);
                            })();
                            <?php endif; ?>
                            
                            var targetUrl = <?php echo wp_json_encode($target); ?>;
                            var currentUrl = <?php echo wp_json_encode($current_full_url); ?>;
                            var afterDelay = <?php echo $after_click_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS numeric expression built from float settings; no user input. ?>;

                            function handleFirstClick(e) {
                                try {
                                    var payload = btoa(unescape(encodeURIComponent(JSON.stringify({
                                        l: currentUrl,
                                        o: targetUrl,
                                        r: document.referrer || '',
                                        f: <?php echo is_404() ? '1' : '0'; ?>,
                                        ru: <?php echo wp_json_encode($GLOBALS['devdredi_rule']); ?>
                                    }))));
                                    // Consistent with the same-tab emitters: sendBeacon first, Image as
                                    // fallback. (New-tab keeps this page alive, but keep one code path.)
                                    var _b = <?php echo wp_json_encode( home_url('/') ); ?> + '?devdredi_r=1&p=' + encodeURIComponent(payload) + '&_t=' + Date.now();
                                    if (!(navigator.sendBeacon && navigator.sendBeacon(_b))) { new Image().src = _b; }
                                } catch (_) {}

                                // Open the new tab synchronously inside the click gesture. A
                                // window.open() fired later from setTimeout counts as an unsolicited
                                // pop-up and gets blocked; with an after-click delay we open about:blank
                                // now and navigate it once the delay elapses.
                                var win = (afterDelay > 0) ? window.open('about:blank', '_blank') : null;

                            function doOpen() {
                                try {
                                    if (win) { win.location = targetUrl; }
                                    else { window.open(targetUrl, '_blank'); }
                                } catch (_) {}
                                }

                                if (afterDelay > 0) {
                                    setTimeout(doOpen, afterDelay);
                                } else {
                                    doOpen();
                                }
                            }

                            setTimeout(function() {
                                document.addEventListener('click', handleFirstClick, { once: true, capture: true });
                            }, <?php echo $delay_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS numeric expression built from float settings; no user input. ?>);
                        }

                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', initClickCapture);
                        } else {
                            initClickCapture();
                        }
                    })();
                <?php });
            });
        }
    }
}, 5);



add_filter('allowed_redirect_hosts', function($hosts){
    if (empty($GLOBALS['devdredi_redirecting'])) {
        return $hosts; // only while THIS plugin issues its redirect: never widen other code's wp_safe_redirect()
    }
    $external_hosts = [
        'google.com', 'google.ru', 'yandex.ru', 'bing.com', 'duckduckgo.com',
        'yahoo.com', 'baidu.com', 'ask.com', 'aol.com', 'startpage.com',
        
        'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'linkedin.com',
        'tiktok.com', 'snapchat.com', 'pinterest.com', 'reddit.com', 'discord.com',
        
        'youtube.com', 'youtu.be', 'twitch.tv', 'vimeo.com', 'dailymotion.com',
        'netflix.com', 'hulu.com', 'amazon.com', 'disney.com', 'hbo.com',
        
        'telegram.org', 'whatsapp.com', 'viber.com', 'skype.com', 'signal.org',
        'wechat.com', 'line.me', 'kik.com', 'snapchat.com', 'discord.com',
        
        'cnn.com', 'bbc.com', 'reuters.com', 'nytimes.com', 'washingtonpost.com',
        'theguardian.com', 'bloomberg.com', 'forbes.com', 'techcrunch.com', 'mashable.com',
        
        'amazon.com', 'ebay.com', 'aliexpress.com', 'walmart.com', 'target.com',
        'etsy.com', 'shopify.com', 'magento.com', 'woocommerce.com', 'bigcommerce.com'
    ];
    
    return array_unique(array_merge($hosts, $external_hosts));
});

function devdredi_get_custom_domains(){
    $raw = devdredi_get_setting('custom_domains', array());
    if (is_string($raw)) {
        $list = array_filter(array_map('trim', explode("\n", $raw)));
    } else {
        $list = is_array($raw) ? $raw : array();
    }
    if (!is_array($list)) $list = array();
    $norm = array();
    foreach ($list as $d) {
        $d = sanitize_text_field($d);
        if ($d === '') continue;
        $d = preg_replace('#^https?://#i', '', $d);
        $d = preg_replace('#^www\.#i', '', $d);
        $d = preg_replace('#/{2,}#', '/', $d);
        $d = rtrim($d, '/');
        if ($d === '') continue;
        if (!in_array($d, $norm, true)) $norm[] = $d;
    }
    return $norm;
}

function devdredi_set_custom_domains($list){
    if (is_string($list)) {
        $list = array_filter(array_map('trim', explode("\n", $list)));
    }
    if (!is_array($list)) $list = array();
    $clean = array();
    foreach ($list as $d){
        $d = sanitize_text_field($d);
        $d = preg_replace('#^https?://#i', '', $d);
        $d = preg_replace('#^www\.#i', '', $d);
        $d = preg_replace('#/{2,}#', '/', $d);
        $d = rtrim($d, '/');
        if ($d === '') continue;
        if (!in_array($d, $clean, true)) $clean[] = $d;
    }
    devdredi_update_setting('custom_domains', $clean);
    return $clean;
}


/**
 * Cache immunity for "Referring websites" rules (1.5.0, ported from the fleet build): a page served from a full-page
 * cache never ran the engine, so a tiny footer script checks document.referrer against the running rules' lists and,
 * on a hit, reloads once with ?dd_rm_ref=<host>; that request bypasses the cache key and the engine decides. Pages
 * targeted this way stay cacheable (the engine's no-store immunity is not applied for referrer rules).
 * @return string[] the referrer entries of every running referrer rule (empty = nothing to print)
 */
function devdredi_referrer_engines_for_running_rules()
{
    $saved = isset($GLOBALS['devdredi_rule']) ? $GLOBALS['devdredi_rule'] : null;
    $out   = array();
    $host  = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
    $path  = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    $full  = (is_ssl() ? 'https://' : 'http://') . $host . $path;
    $GLOBALS['devdredi_outside_bootstrap'] = false;
    foreach (devdredi_get_rules() as $r) {
        if (empty($r['id'])) {
            continue;
        }
        $GLOBALS['devdredi_rule'] = $r['id'];
        if (devdredi_get_setting('plugin_state', 'stopped') !== 'running') {
            continue;
        }
        $what = devdredi_get_setting('what_to_redirect', 'entire_website');
        if ($what !== 'referrer') {
            // "Only visitors arriving from outside" (1.5.2): a cached copy of a page in this rule's scope must carry the
            // footer script so a landing (no referrer, or another site) reloads once and lets the engine decide live.
            if (devdredi_get_bool_setting('outside_only', 0) && !devdredi_schedule_permanently_over()) {
                $in_scope = ($what === 'selected_existing') ? devdredi_selected_links_match($full, $path, $host)
                    : (($what === 'all_404') ? is_404() : (($what === 'custom_urls') ? devdredi_custom_urls_match($full, $path, $host) : true));
                if ($in_scope) {
                    $GLOBALS['devdredi_outside_bootstrap'] = true;
                }
            }
            continue;
        }
        // Out-of-schedule rules still print it: a copy cached off-hours must carry it for the redirect to work once the
        // schedule resumes (the reload re-runs the engine, which checks the schedule live). Only an ended campaign is skipped.
        if (devdredi_schedule_permanently_over()) {
            continue;
        }
        if (devdredi_get_bool_setting('referrer_only_selected', 0) && !devdredi_selected_links_match($full, $path, $host)) {
            continue; // "Only on selected pages" and this page is not one of them
        }
        foreach (devdredi_referrer_list() as $e) {
            $out[$e] = 1;
        }
    }
    $GLOBALS['devdredi_rule'] = $saved;
    return array_keys($out);
}

function devdredi_print_referrer_bootstrap()
{
    if (is_admin() || is_feed() || is_robots() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (!empty($GLOBALS['devdredi_rule'])) {
        return; // the engine handled this render server-side (that response is never cached)
    }
    $engines = devdredi_referrer_engines_for_running_rules();
    $outside = !empty($GLOBALS['devdredi_outside_bootstrap']);
    if (empty($engines) && !$outside) {
        return;
    }
    // Enqueued as an inline script on an empty footer handle (the WordPress.org way), not a raw <script> tag.
    $js = <<<'JS'
(function(){try{
  var sp=new URLSearchParams(location.search);
  if(sp.has('dd_rm_ref')){sp.delete('dd_rm_ref');var c=location.pathname+(sp.toString()?('?'+sp.toString()):'')+location.hash;try{history.replaceState(null,document.title,c);}catch(e){}return;}
  var O=__DEVDREDI_OUTSIDE__;
  var ref=document.referrer||'';
  if(!ref){if(!O)return;var u0=new URL(location.href);u0.searchParams.set('dd_rm_ref','none');location.replace(u0.toString());return;}
  var h=new URL(ref).hostname.replace(/^www\./,'').toLowerCase();
  if(!h||h===location.hostname.replace(/^www\./,'').toLowerCase())return;
  var E=__DEVDREDI_ENGINES__;
  var hit=O||E.some(function(d){return d.indexOf('.')===-1?h.indexOf(d)!==-1:(h===d||h.slice(-(d.length+1))==='.'+d);});
  if(!hit)return;
  var u=new URL(location.href);u.searchParams.set('dd_rm_ref',h);
  location.replace(u.toString());
}catch(e){}})();
JS;
    $js = str_replace(array('__DEVDREDI_ENGINES__', '__DEVDREDI_OUTSIDE__'), array(wp_json_encode(array_values($engines)), $outside ? 'true' : 'false'), $js);
    wp_register_script('devdredi-referrer-bootstrap', false, array(), DEVDREDI_VERSION, true);
    wp_enqueue_script('devdredi-referrer-bootstrap');
    wp_add_inline_script('devdredi-referrer-bootstrap', $js);
}
add_action('wp_enqueue_scripts', 'devdredi_print_referrer_bootstrap', 99);
