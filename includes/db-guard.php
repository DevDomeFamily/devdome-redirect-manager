<?php
/**
 * Request-wide database guard (DESIGN.md 24, Redirect Manager 1.5.7).
 *
 * $wpdb reports a failed query only through last_error, and the NEXT query clears it. A failed read that a
 * call site took for an empty answer ("no rows" = nothing to reset, "no rule" = already gone) was invisible by
 * the time the action reported success. The guard records every failed query as it happens, and inside an
 * action window (AJAX handler, ability, the settings page) the plugin's own write helper refuses once a query
 * failed ("no write after a failed read") while the response boundary answers a database error instead of
 * "done". Outside a window (front-end redirects, other plugins calling in, the test suite) nothing changes.
 *
 * Reference: Malware Scanner 1.2.3 includes/findings.php, copied with this plugin's prefix.
 */

defined('ABSPATH') || exit;

$GLOBALS['devdredi_db_guard'] = array('errors' => array(), 'count' => 0, 'last' => '', 'mark' => 0, 'open' => 0, 'pending' => false);

/** Record the pending $wpdb->last_error once (internal). */
function devdredi_db_guard_sync()
{
    global $wpdb;
    $g = &$GLOBALS['devdredi_db_guard'];
    if (isset($wpdb->last_error) && (string) $wpdb->last_error !== '' && empty($g['pending'])) {
        $g['count'] = (int) $g['count'] + 1; // the counter never saturates; the list below is diagnostic
        $g['last']  = (string) $wpdb->last_error;
        if (count($g['errors']) < 50) {
            $g['errors'][] = (string) $wpdb->last_error;
        }
        $g['pending'] = true;
    }
}

/** 'query' filter (priority 1): the previous query's error is recorded before wpdb::query() flushes it. */
function devdredi_db_guard_record($query)
{
    devdredi_db_guard_sync();
    $GLOBALS['devdredi_db_guard']['pending'] = false; // a new query starts; its own error is fresh
    return $query;
}

/** Clear $wpdb->last_error the honest way: record it first. No code in this plugin clears it by hand. */
function devdredi_db_reset_error()
{
    global $wpdb;
    devdredi_db_guard_sync();
    $GLOBALS['devdredi_db_guard']['pending'] = false;
    if (isset($wpdb->last_error)) {
        $wpdb->last_error = '';
    }
}

/** True when the query run since devdredi_db_reset_error() failed. */
function devdredi_db_failed()
{
    global $wpdb;
    return isset($wpdb->last_error) && (string) $wpdb->last_error !== '';
}

/** Open a guard window. Nested windows share the outer list; only the outermost begin() clears it. */
function devdredi_db_guard_begin()
{
    global $wpdb;
    devdredi_db_guard_sync(); // a nested window inherits what is pending
    $g = &$GLOBALS['devdredi_db_guard'];
    $g['open'] = (int) $g['open'] + 1;
    if ($g['open'] === 1) {
        $g['errors']  = array();
        $g['count']   = 0;
        $g['last']    = '';
        $g['mark']    = 0;
        $g['pending'] = false;
        if (isset($wpdb->last_error)) {
            $wpdb->last_error = ''; // an error from before this action is not this action's
        }
    }
}

function devdredi_db_guard_end()
{
    $g = &$GLOBALS['devdredi_db_guard'];
    $g['open'] = max(0, (int) $g['open'] - 1);
}

/** True while an action window is open. */
function devdredi_db_guard_open()
{
    return (int) $GLOBALS['devdredi_db_guard']['open'] > 0;
}

/** Move the mark to now: errors before it are handled (reported by their own step). */
function devdredi_db_guard_rebase()
{
    devdredi_db_guard_sync();
    $GLOBALS['devdredi_db_guard']['mark'] = (int) $GLOBALS['devdredi_db_guard']['count'];
}

/** True when a query failed since the mark. */
function devdredi_db_guard_failed()
{
    devdredi_db_guard_sync();
    $g = $GLOBALS['devdredi_db_guard'];
    return (int) $g['count'] > (int) $g['mark'];
}

/** True when a window is open AND a query failed since its mark: the write helper and the boundaries refuse on this. */
function devdredi_db_guard_active()
{
    return devdredi_db_guard_open() && devdredi_db_guard_failed();
}

/** The last recorded error text ('' when none). */
function devdredi_db_guard_error()
{
    devdredi_db_guard_sync();
    return (string) $GLOBALS['devdredi_db_guard']['last'];
}

/**
 * Mask what must never travel in an error text or an agent answer: credentials in URLs (user:pass@host),
 * secret-looking query values (key, token, secret, pass, auth, sig, ...) and email addresses.
 */
function devdredi_redact_text($text)
{
    $text = (string) $text;
    $text = preg_replace('~(https?://)[^\s/@:]+:[^\s/@]+@~i', '$1[redacted]@', $text);
    $text = preg_replace('~([?&;][^=&;\s]*(?:key|token|secret|pass|pwd|auth|sig|signature|credential|session)[^=&;\s]*=)[^&;\s]*~i', '$1[redacted]', $text);
    $text = preg_replace('~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}~i', '[email redacted]', $text);
    return $text;
}

/** The one text every boundary uses. */
function devdredi_db_guard_message()
{
    $err = devdredi_redact_text(devdredi_db_guard_error()); // a query text inside the error can carry a credential: redact BEFORE the cut
    if (function_exists('mb_substr')) {
        $err = mb_substr($err, 0, 160);
    } else {
        $err = substr($err, 0, 160);
    }
    return 'A database query failed during this action' . ($err !== '' ? ' (' . $err . ')' : '') . '. The result is not trusted and nothing more was changed: reload the page and check the current state before trying again.';
}

/** AJAX boundary: "done" only when no query failed inside the handler's window. */
function devdredi_json_success($data = null)
{
    if (devdredi_db_guard_active()) {
        wp_send_json_error(devdredi_db_guard_message(), 500);
    }
    wp_send_json_success($data);
}
