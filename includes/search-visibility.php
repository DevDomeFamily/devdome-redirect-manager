<?php
/**
 * Hide From Search (1.5.7): two per-rule controls, both OFF by default, each doing one thing.
 *
 *  - Noindex header: every request a running rule targets answers `X-Robots-Tag: noindex, nofollow`, for crawlers
 *    and people alike, BEFORE the known-bot skip decides who is redirected. A crawler that is left on the page still
 *    sees the header, so the redirected address drops out of the index and the destination is not credited.
 *  - robots.txt Disallow: the virtual robots.txt WordPress serves lists the rule's Custom URLs / Selected existing
 *    URLs under `User-agent: *`. Rules for the entire website, all 404s or referring websites have no fixed path
 *    and add nothing here. Disallow stops crawling, not indexing (a disallowed address can stay listed without a
 *    snippet), which is why the two are separate controls. No file on disk is ever written.
 */

defined('ABSPATH') || exit;

/** Send the noindex header for the rule being handled, when its control is on and nothing was sent yet. */
function devdredi_send_noindex_header()
{
    if (!devdredi_get_bool_setting('search_noindex', 0) || headers_sent()) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
}

/**
 * The "Disallow: /path" lines for every RUNNING rule with the robots.txt control on. Raw reads per rule; when any
 * read fails the list is empty rather than a guess (a wrong Disallow can hide pages the admin never chose).
 */
function devdredi_search_disallow_lines()
{
    $lines = array();
    unset($GLOBALS['devdredi_read_failed']);
    $rules = devdredi_get_rules();
    if (devdredi_rules_index_unreadable()) {
        return array();
    }
    foreach ($rules as $rule) {
        $rid = isset($rule['id']) ? (string) $rule['id'] : '';
        if ($rid === '') {
            continue;
        }
        $pfx = 'rule__' . $rid . '__';
        if ((int) devdredi_raw_get_setting($pfx . 'search_disallow', 0) !== 1) {
            continue;
        }
        if ((string) devdredi_raw_get_setting($pfx . 'plugin_state', 'stopped') !== 'running') {
            continue; // a stopped rule redirects nobody: its paths stay crawlable
        }
        $mode = (string) devdredi_raw_get_setting($pfx . 'what_to_redirect', 'entire_website');
        if ($mode === 'custom_urls') {
            $raw = (string) devdredi_raw_get_setting($pfx . 'custom_links_list', '');
        } elseif ($mode === 'selected_existing') {
            $raw = (string) devdredi_raw_get_setting($pfx . 'selected_links_list', '');
        } else {
            continue;
        }
        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $item) {
            $line = devdredi_search_disallow_line($item);
            if ($line !== '') {
                $lines[$line] = true;
            }
        }
    }
    if (!empty($GLOBALS['devdredi_read_failed'])) {
        return array();
    }
    $lines = array_keys($lines);
    sort($lines);
    return $lines;
}

/**
 * One list entry -> its robots.txt line, or '' when robots.txt cannot say the same thing the rule matches. The rule treats
 * an entry ending in "/" as that address and everything under it (a robots prefix says exactly that) and any other entry
 * as that exact address (robots needs the "$" end anchor, else /offer would also hide /offer-details). An entry with a
 * query string, a host-only entry and a bare "/" give no line: robots.txt has no way to say them without hiding more.
 */
function devdredi_search_disallow_line($item)
{
    $item = trim((string) $item);
    if ($item === '' || strpos($item, '?') !== false || strpos($item, '#') !== false) {
        return '';
    }
    if (preg_match('#^https?://#i', $item)) {
        $path = (string) wp_parse_url($item, PHP_URL_PATH);
    } else {
        $first = explode('/', $item, 2)[0];
        if (strpos($first, '.') !== false && !preg_match('/^[0-9]+$/', $first)) {
            $path = strpos($item, '/') === false ? '' : '/' . explode('/', $item, 2)[1]; // host/path: the path part
        } else {
            $path = '/' . ltrim($item, '/');
        }
    }
    if ($path === '' || $path === '/' || !preg_match('#^/[^\s*$]*$#', $path)) {
        return '';
    }
    return 'Disallow: ' . $path . (substr($path, -1) === '/' ? '' : '$');
}

/** Append the Disallow block to the virtual robots.txt. */
function devdredi_robots_txt($output, $public)
{
    $lines = devdredi_search_disallow_lines();
    if (empty($lines)) {
        return $output;
    }
    $output .= "\n# DevDome Redirect Manager: redirected addresses kept out of search crawling\nUser-agent: *\n" . implode("\n", $lines) . "\n";
    return $output;
}
add_filter('robots_txt', 'devdredi_robots_txt', 10, 2);
