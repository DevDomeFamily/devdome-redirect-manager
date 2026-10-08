<?php
/**
 * Trusted proxies (1.5.7). "Trust forwarded IP headers" used to believe CF-Connecting-IP / X-Forwarded-For /
 * X-Real-IP from ANY connection once ticked, so a direct visitor could hand the plugin any address (geo filter,
 * once-per-visitor, Visitor Check, the exclusion lists). A forwarded header now counts only when the connection
 * itself comes from a proxy: a Cloudflare edge address (published list, https://www.cloudflare.com/ips/) or a
 * private / loopback address (a reverse proxy on the same box or network). Everyone else is REMOTE_ADDR.
 */

defined('ABSPATH') || exit;

/** @return string[] Cloudflare edge ranges (fetched 2026-07-22, refreshed with plugin updates) + private and loopback ranges. */
function devdredi_proxy_cidrs()
{
    return array(
        // Cloudflare IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // Cloudflare IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        // Private and loopback: a reverse proxy in front of PHP on the same host or network
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', 'fc00::/7', 'fe80::/10', '::1/128',
    );
}

/** @return string[] Cloudflare edge ranges only (the CF-IPCountry header is believed from these connections alone). */
function devdredi_cloudflare_cidrs()
{
    return array_slice(devdredi_proxy_cidrs(), 0, 22);
}

/** True when $ip is inside one of the $cidrs. Uses the shared DevDome library's byte-wise CIDR math. */
function devdredi_ip_in_cidrs($ip, $cidrs)
{
    static $cache = array();
    $ip = (string) $ip;
    if ($ip === '' || !function_exists('devdcorev1_build_cidr_index') || !function_exists('devdcorev1_ip_in_cidr_index')) {
        return false;
    }
    $key = md5(implode(',', $cidrs));
    if (!isset($cache[$key])) {
        $cache[$key] = devdcorev1_build_cidr_index($cidrs);
    }
    return devdcorev1_ip_in_cidr_index($ip, $cache[$key]['v4'], $cache[$key]['v6']);
}

/** The address the connection really came from. */
function devdredi_remote_addr()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    return ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : '';
}

/** True when the connection comes from a proxy whose forwarded headers may be believed. */
function devdredi_remote_is_trusted_proxy()
{
    return devdredi_ip_in_cidrs(devdredi_remote_addr(), devdredi_proxy_cidrs());
}

/** True when the connection comes from a Cloudflare edge (CF-Connecting-IP and CF-IPCountry are Cloudflare's own). */
function devdredi_remote_is_cloudflare()
{
    return devdredi_ip_in_cidrs(devdredi_remote_addr(), devdredi_cloudflare_cidrs());
}

/**
 * The visitor's address behind a trusted proxy, '' when the headers give none. Only called when the connection itself
 * comes from a trusted proxy. CF-Connecting-IP is believed from a Cloudflare edge alone; X-Real-IP is what the nearest
 * proxy saw; X-Forwarded-For is walked from the RIGHT (the hop our proxy appended) past every trusted proxy address,
 * and the first address that is not a proxy is the visitor. A prefix the visitor supplied themselves sits further left
 * and is never reached (Codex 1.5.7 r1: the old code took the leftmost entry).
 */
function devdredi_forwarded_client_ip()
{
    $clean = function ($v) { return trim(sanitize_text_field(wp_unslash((string) $v))); };
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && devdredi_remote_is_cloudflare()) {
        $ip = $clean($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $clean($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP) && !devdredi_ip_in_cidrs($ip, devdredi_proxy_cidrs())) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_reverse(array_map('trim', explode(',', $clean($_SERVER['HTTP_X_FORWARDED_FOR']))));
        foreach ($parts as $ip) {
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return ''; // a chain with junk in it is not trusted at all
            }
            if (!devdredi_ip_in_cidrs($ip, devdredi_proxy_cidrs())) {
                return $ip;
            }
        }
    }
    return '';
}
