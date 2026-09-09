<?php
/**
 * Generic helpers.
 */

defined('ABSPATH') || exit;

function devdredi_clean_url($url) {
    if (empty($url)) return $url;

    $url = preg_replace('/^https?:\/\//', '', $url);

    $url = preg_replace('/^www\./', '', $url);

    $url = rtrim($url, '/');

    return $url;
}

/**
 * Normalize a user-entered URL to a site-relative path so Custom URLs match no
 * matter how they're typed (http/https, with/without www, full URL, or bare path).
 * "https://www.site.com/foo/bar?x=1" / "site.com/foo/bar" / "foo/bar" -> "/foo/bar?x=1".
 * The real scheme (http/https) is whatever the site root uses at redirect time.
 */
function devdredi_normalize_path($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $url = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url); // scheme://
    $url = preg_replace('#^//#', '', $url);                     // protocol-relative
    $url = preg_replace('#^www\.#i', '', $url);
    // If a host precedes the first slash (host contains a dot), strip the host.
    if (preg_match('#^[^/]*\.[^/]*/#', $url) || preg_match('#^[^/]*\.[^/]+$#', $url)) {
        $slash = strpos($url, '/');
        $url = ($slash === false) ? '' : substr($url, $slash);
    }
    if (preg_match('/\s/', $url)) {
        return ''; // free text, not a link/path
    }
    $url = '/' . ltrim($url, '/');
    return $url === '/' ? '' : $url;
}

/**
 * Normalize a user-entered referring website to a bare host (no scheme, no www, no path),
 * so "https://www.google.com/search?q=x" / "google.com/foo" / "Google.com" -> "google.com".
 * Returns '' for free text or anything that doesn't look like a domain.
 */
function devdredi_normalize_domain($url)
{
    $url = strtolower(trim((string) $url));
    if ($url === '') {
        return '';
    }
    $url = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url); // scheme://
    $url = preg_replace('#^//#', '', $url);                     // protocol-relative
    $url = preg_replace('#^www\.#i', '', $url);
    $slash = strpos($url, '/');                                 // drop any path/query
    if ($slash !== false) {
        $url = substr($url, 0, $slash);
    }
    if (preg_match('/\s/', $url) || strpos($url, '.') === false) {
        return ''; // free text or not a domain
    }
    return $url;
}
