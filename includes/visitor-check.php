<?php
/**
 * Visitor Check (1.5.4): a JavaScript redirect continues only from the IP address and browser that
 * opened the page. The page carries a single-use pass instead of the destination; the browser
 * returns the pass to this site, and only a matching, unexpired, unused pass is sent on to the
 * destination. A hidden bot that loads the page on one address and continues from another (rotating
 * proxies) is not redirected. The pass is printed in two halves inside the redirect script and joined only
 * when the script navigates, so a reader that does not run JavaScript finds no address to follow.
 * Everything stays on this site: no external service.
 *
 * Applies to JavaScript redirects with a provided or transit destination. "Found on page"
 * destinations are picked in the browser, so there is no server-side target to protect.
 */

defined('ABSPATH') || exit;

function devdredi_pass_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdredi_passes';
}

/** Create the passes table. Runs on activation and once after an update (devdredi_check_version). */
function devdredi_pass_install()
{
    global $wpdb;
    $table = devdredi_pass_table();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE $table (
        token_hash char(64) NOT NULL,
        rule_id varchar(64) NOT NULL DEFAULT '',
        bind char(64) NOT NULL,
        target text NOT NULL,
        meta text NOT NULL,
        expires int(10) unsigned NOT NULL,
        PRIMARY KEY  (token_hash),
        KEY expires (expires)
    ) $charset_collate;");
}

/** True when the current rule's redirect is one Visitor Check can protect. */
function devdredi_visitor_check_active()
{
    if (!devdredi_get_bool_setting('visitor_check', 0)) {
        return false;
    }
    if ((string) devdredi_get_setting('redirect_source', 'provided') === 'found') {
        return false;
    }
    return !in_array((string) devdredi_get_setting('redirect_type', 'js'), array('301', '302', '307', '308', 'meta'), true);
}

/** Keyed digest of the visitor's IP address and browser: what a pass is bound to. Never stored raw. */
function devdredi_pass_bind()
{
    $ip = (string) devdredi_get_client_ip();
    $packed = ($ip !== '') ? @inet_pton($ip) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid address yields false, handled below
    $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $address = ($packed !== false) ? bin2hex($packed) : $ip;
    /**
     * Filters the address part of the Visitor Check binding. A pass is tied to this value plus the browser, so the
     * request that returns the pass must produce the same value as the request that received it. A site behind a
     * network that changes the visitor's address between two requests (privacy relays, carrier NAT) can return a
     * coarser, stable value here, for example the visitor's country.
     *
     * @param string $address The visitor's address (hex of the packed address, or the raw value when it is not an IP).
     * @param string $ip      The visitor's address as text.
     */
    $address = (string) apply_filters('devdredi_pass_bind_address', $address, $ip);
    return hash_hmac('sha256', $address . "\n" . $ua, wp_salt('nonce'));
}

/**
 * Issue a pass for the current visitor and return the URL the browser must open instead of the
 * destination. Returns '' when the pass could not be stored (the engine then does not redirect at all).
 */
function devdredi_pass_issue($target, $ttl, array $meta)
{
    global $wpdb;
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        return '';
    }
    $table = devdredi_pass_table();
    $row = array(
        'token_hash' => hash('sha256', $token),
        'rule_id'    => (string) (isset($GLOBALS['devdredi_rule']) ? $GLOBALS['devdredi_rule'] : ''),
        'bind'       => devdredi_pass_bind(),
        'target'     => (string) $target,
        'meta'       => (string) wp_json_encode($meta),
        'expires'    => time() + max(30, (int) $ttl),
    );
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- the plugin's own short-lived passes table; never cached by design.
    $suppress = $wpdb->suppress_errors(true);
    $ok = $wpdb->insert($table, $row);
    if (!$ok && !get_transient('devdredi_passes_wait')) {
        // The table is missing (updated by file copy, no version hook yet): create it and try once more. Same one-hour
        // back-off as devdredi_check_version, so a database that refuses CREATE is not asked on every page view.
        set_transient('devdredi_passes_wait', 1, HOUR_IN_SECONDS);
        devdredi_pass_install();
        $ok = $wpdb->insert($table, $row);
        if ($ok) {
            delete_transient('devdredi_passes_wait');
        }
    }
    $wpdb->suppress_errors($suppress);
    if ($ok && wp_rand(1, 50) === 1) {
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE expires < %d LIMIT 500", time())); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
    return $ok ? add_query_arg('devdredi_v', $token, home_url('/')) : '';
}

/** A refused pass: no redirect, a plain message, never cached. */
function devdredi_pass_refuse()
{
    nocache_headers();
    if (!headers_sent()) {
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }
    wp_die(
        esc_html__('This link has expired. Please go back and open the page again.', 'devdome-redirect-manager'),
        '',
        array('response' => 403, 'back_link' => true)
    );
}

// The browser returns the pass here: ?devdredi_v=<pass>&r=<referrer of the page that issued it>.
add_action('init', function () {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public single-use visitor pass, validated against the passes table; no login nonce by design.
    if (!isset($_GET['devdredi_v'])) {
        return;
    }
    global $wpdb;
    $token = is_string($_GET['devdredi_v']) ? sanitize_text_field(wp_unslash($_GET['devdredi_v'])) : '';
    $ref = (isset($_GET['r']) && is_string($_GET['r'])) ? esc_url_raw(wp_unslash($_GET['r'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        devdredi_pass_refuse();
    }
    $table = devdredi_pass_table();
    $hash = hash('sha256', $token);
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own passes table (name from $wpdb->prefix); a pass must never be read from a cache.
    $row = $wpdb->get_row($wpdb->prepare("SELECT rule_id, bind, target, meta, expires FROM $table WHERE token_hash = %s", $hash), ARRAY_A);
    if (!is_array($row)) {
        devdredi_pass_refuse(); // unknown or already used: nothing to count, a random pass must not touch any rule's statistics
    }
    // Single use: whoever deletes the row owns the pass. Two requests racing on one pass cannot both win.
    $won = ((int) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE token_hash = %s", $hash)) === 1);
    // phpcs:enable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if (!$won) {
        devdredi_pass_refuse();
    }

    $rule = (string) $row['rule_id'];
    $rule_ids = array_map(function ($r) { return $r['id']; }, devdredi_get_rules());
    if ($rule !== '') {
        if (devdredi_rules_index_unreadable() || !in_array($rule, $rule_ids, true) || (string) devdredi_raw_get_setting('rule__' . $rule . '__plugin_state', 'stopped') !== 'running') {
            devdredi_pass_refuse();
        }
        $GLOBALS['devdredi_rule'] = $rule;
    } elseif ((string) devdredi_get_setting('plugin_state', 'stopped') !== 'running') {
        devdredi_pass_refuse();
    }
    $meta = json_decode((string) $row['meta'], true);
    if (!is_array($meta)) { $meta = array(); }

    // The rule must still be allowed to redirect NOW: a pass issued before the run time or the schedule ended is worth nothing.
    if (!devdredi_check_status_and_schedule() || !devdredi_is_in_schedule()) {
        devdredi_pass_refuse();
    }

    // Never Redirect lists (1.5.5): a pass issued before the visitor was put on a list is worth nothing either. Counted with the
    // skipped bots, no slot taken; the visitor goes back to the page, which no longer issues them a pass.
    if (devdredi_never_redirect_reason() !== '') {
        $bs = (int) devdredi_get_setting('user_bot_skip_count', 0);
        if (empty($GLOBALS['devdredi_read_failed'])) { devdredi_update_setting('user_bot_skip_count', $bs + 1); }
        devdredi_bump_daily(array('bs' => 1));
        $back = isset($meta['l']) ? esc_url_raw((string) $meta['l']) : '';
        if ($back === '') {
            devdredi_pass_refuse();
        }
        nocache_headers();
        wp_safe_redirect($back, 302);
        exit;
    }

    $target = (string) $row['target'];
    $scheme = wp_parse_url($target, PHP_URL_SCHEME);
    if ((int) $row['expires'] < time() || !hash_equals((string) $row['bind'], devdredi_pass_bind())
        || !wp_parse_url($target, PHP_URL_HOST) || !in_array($scheme, array('http', 'https'), true)) {
        // An issued pass that came back late, from another address or another browser: counted once, with the skipped bots.
        $bs = (int) devdredi_get_setting('user_bot_skip_count', 0);
        if (empty($GLOBALS['devdredi_read_failed'])) { devdredi_update_setting('user_bot_skip_count', $bs + 1); }
        devdredi_bump_daily(array('bs' => 1));
        devdredi_pass_refuse();
    }

    // Daily Redirect Limit: THIS is the moment a protected redirect takes its slot (one conditional UPDATE). The day filled
    // up while the visitor was on the page = no redirect; they go back to the page, which no longer issues passes.
    if (!devdredi_daily_limit_take()) {
        $back = isset($meta['l']) ? esc_url_raw((string) $meta['l']) : '';
        if ($back === '') {
            devdredi_pass_refuse();
        }
        nocache_headers();
        wp_safe_redirect($back, 302);
        exit;
    }

    devdredi_count_js_redirect(
        sanitize_text_field((string) ($meta['l'] ?? '')),
        $target,
        sanitize_text_field((string) ($meta['rid'] ?? '')),
        $ref,
        0,
        !empty($meta['f']),
        ''
    );

    nocache_headers();
    if (!headers_sent()) { header('Referrer-Policy: no-referrer'); } // the pass URL must not ride to the destination
    $GLOBALS['devdredi_redirecting'] = true;
    wp_redirect($target, 302); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the rule's own stored destination; wp_safe_redirect would block external destinations.
    exit;
}, 0);
