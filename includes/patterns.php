<?php
/**
 * Pattern items in a rule's Custom URLs list (1.5.10): a line starting with ^ is a regular expression, a line with
 * * is a wildcard. Both can carry what they matched into the Provided Link ($1 .. $9). Pure functions, no WordPress
 * calls, so the CLI suite proves every shape without a site.
 *
 *   ^/blog/(\d+)$         regular expression, matched against the request path and query (e.g. /blog/12?x=1)
 *   /docs/*               wildcard, * = anything, matched against the path only; every * is a group
 *   https://x.com/new/$1  a Provided Link reusing the first group
 *
 * A pattern that does not compile never matches and is dropped when the rule is saved (said on the screen).
 */

defined('ABSPATH') || exit;

/** Longest pattern accepted (a longer one is refused: a pathological expression must not stall every request). */
define('DEVDREDI_PATTERN_MAX', 200);

/** 'regex' (starts with ^), 'wildcard' (contains *), else 'plain'. */
function devdredi_pattern_kind($item)
{
    $item = trim((string) $item);
    if ($item === '') {
        return 'plain';
    }
    if ($item[0] === '^') {
        return 'regex';
    }
    if (strpos($item, '*') !== false) {
        return 'wildcard';
    }
    return 'plain';
}

/**
 * The compiled expression for a pattern item, or '' when it is plain or does not compile.
 * Regular expressions are case-insensitive; a ~ inside the item is escaped (it is the delimiter).
 */
function devdredi_pattern_regex($item)
{
    $item = trim((string) $item);
    $kind = devdredi_pattern_kind($item);
    if ($kind === 'plain' || strlen($item) > DEVDREDI_PATTERN_MAX) {
        return '';
    }
    if ($kind === 'regex') {
        $re = '~' . str_replace('~', '\~', $item) . '~i';
    } else {
        // Wildcard: quote every literal piece, each * becomes one capturing group; anchored to the whole path.
        $parts = array_map(function ($p) { return preg_quote($p, '~'); }, explode('*', $item));
        $re = '~^' . implode('(.*?)', $parts) . '$~i';
    }
    // preg_match() on an invalid expression returns false and raises a warning; the @ keeps the warning off the
    // page while the false is read. A catastrophic expression is also refused by the size cap above.
    if (@preg_match($re, '') === false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern is a user error, answered as "invalid"
        return '';
    }
    return $re;
}

/** True when the item is plain, or a pattern that compiles. */
function devdredi_pattern_valid($item)
{
    return devdredi_pattern_kind($item) === 'plain' || devdredi_pattern_regex($item) !== '';
}

/**
 * Match a pattern item against a request.
 *
 * @param string $item  The list line.
 * @param string $path  The request path, e.g. /blog/12 (normalised by the caller, no host).
 * @param string $query The query string without ?, '' when none.
 * @return array|false The preg groups (0 = the whole match) or false.
 */
function devdredi_pattern_match($item, $path, $query = '')
{
    $re = devdredi_pattern_regex($item);
    if ($re === '') {
        return false;
    }
    $path    = '/' . ltrim((string) $path, '/');
    $subject = devdredi_pattern_kind($item) === 'regex' && (string) $query !== '' ? $path . '?' . $query : $path;
    $m       = array();
    if (@preg_match($re, $subject, $m) === 1) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compiled above; the @ only covers a backtrack limit
        return $m;
    }
    return false;
}

/**
 * Put the matched groups into a Provided Link: $1 .. $9 (and $0 for the whole match). Groups are URL pieces already;
 * line breaks and control characters can never reach the Location header.
 */
function devdredi_pattern_apply($target, $groups)
{
    $target = (string) $target;
    if (!is_array($groups) || !$groups || strpos($target, '$') === false) {
        return $target;
    }
    return preg_replace_callback('/\$(\d)/', function ($hit) use ($groups) {
        $i = (int) $hit[1];
        $v = isset($groups[$i]) ? (string) $groups[$i] : '';
        return preg_replace('/[\x00-\x1f\x7f]/', '', $v);
    }, $target);
}
