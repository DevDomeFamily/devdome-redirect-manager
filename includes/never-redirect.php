<?php
/**
 * "Never redirect" lists (1.5.5), per rule: IP addresses and ranges, browser strings (User-Agent) and
 * WordPress user roles that the rule never redirects. A match is handled exactly like a known bot: the
 * visitor sees the page as usual, gets no redirect and no bypass link, and is counted with the skipped bots.
 * Stored as plain per-rule settings: never_ips and never_uas (one entry per line), never_roles (comma list), each with its
 * own never_*_enabled switch (the Enable checkbox); a list that is switched off keeps its entries and matches nobody.
 */

defined('ABSPATH') || exit;

/** Packed address with an IPv4-mapped IPv6 address (::ffff:203.0.113.7) turned into its IPv4 form, so both spellings match. */
function devdredi_never_unmap($bin)
{
    return (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") ? substr($bin, 12) : $bin;
}

/** One IP address (IPv4 / IPv6) or CIDR range, normalised; '' when it is neither. */
function devdredi_never_clean_ip_entry($entry)
{
    $entry = trim((string) $entry);
    if ($entry === '') {
        return '';
    }
    $parts = explode('/', $entry);
    if (count($parts) > 2) {
        return '';
    }
    $bin = @inet_pton($parts[0]);
    if ($bin === false) {
        return '';
    }
    if (count($parts) === 1) {
        return inet_ntop(devdredi_never_unmap($bin));
    }
    if (!ctype_digit($parts[1]) || strlen($parts[1]) > 3 || (int) $parts[1] > strlen($bin) * 8) {
        return '';
    }
    $bits = (int) $parts[1];
    if ($bits >= 96 && devdredi_never_unmap($bin) !== $bin) { // a mapped range (::ffff:203.0.113.0/120) is the IPv4 range /24
        $bin = devdredi_never_unmap($bin);
        $bits -= 96;
    }
    return inet_ntop($bin) . '/' . $bits;
}

/** Clean an IP list (text, one per line, or an array): valid entries only, no duplicates, at most 500. */
function devdredi_never_clean_ips($raw)
{
    $lines = is_array($raw) ? $raw : preg_split('/[\r\n,]+/', (string) $raw);
    $out = array();
    foreach ($lines as $line) {
        $e = is_scalar($line) ? devdredi_never_clean_ip_entry($line) : '';
        if ($e !== '' && !in_array($e, $out, true)) {
            $out[] = $e;
        }
        if (count($out) >= 500) {
            break;
        }
    }
    return implode("\n", $out);
}

/**
 * Clean a browser string list (text, one per line, or an array). Entries are matched as a case-insensitive part of the
 * User-Agent, so an entry shorter than 5 characters ("a", "Moz") would match nearly everybody and is dropped. At most 200.
 */
function devdredi_never_clean_uas($raw)
{
    $lines = is_array($raw) ? $raw : preg_split('/[\r\n]+/', (string) $raw);
    $out = array();
    foreach ($lines as $line) {
        $e = is_scalar($line) ? substr(trim(sanitize_text_field((string) $line)), 0, 300) : '';
        if (strlen($e) >= 5 && !in_array($e, $out, true)) {
            $out[] = $e;
        }
        if (count($out) >= 200) {
            break;
        }
    }
    return implode("\n", $out);
}

/** Clean a role list (array or comma text): only roles that exist on this site. */
function devdredi_never_clean_roles($raw)
{
    $list = is_array($raw) ? $raw : explode(',', (string) $raw);
    $known = array_keys(wp_roles()->roles);
    $out = array();
    foreach ($list as $role) {
        $role = is_scalar($role) ? sanitize_key((string) $role) : '';
        if ($role !== '' && in_array($role, $known, true) && !in_array($role, $out, true)) {
            $out[] = $role;
        }
    }
    return implode(',', $out);
}

/** True when $ip is one of the entries (single addresses and CIDR ranges, IPv4 and IPv6). */
function devdredi_never_ip_matches($ip, array $entries)
{
    $bin = @inet_pton((string) $ip);
    if ($bin === false) {
        return false;
    }
    $bin = devdredi_never_unmap($bin);
    foreach ($entries as $entry) {
        $parts = explode('/', $entry);
        $net = @inet_pton($parts[0]);
        if ($net === false || strlen($net) !== strlen($bin)) {
            continue;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($net) * 8;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            continue;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rest)) & 0xff);
        if ((($bin[$bytes] ^ $net[$bytes]) & $mask) === "\0") {
            return true;
        }
    }
    return false;
}

/** Why the current rule never redirects this request: 'ip', 'ua', 'role', or '' when no list matches. */
function devdredi_never_redirect_reason()
{
    $ips = devdredi_get_bool_setting('never_ips_enabled', 0) ? array_filter(explode("\n", (string) devdredi_get_setting('never_ips', ''))) : array();
    if ($ips && devdredi_never_ip_matches((string) devdredi_get_client_ip(), $ips)) {
        return 'ip';
    }
    $uas = devdredi_get_bool_setting('never_uas_enabled', 0) ? array_filter(explode("\n", (string) devdredi_get_setting('never_uas', ''))) : array();
    if ($uas) {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        foreach ($uas as $needle) {
            if ($ua !== '' && stripos($ua, $needle) !== false) {
                return 'ua';
            }
        }
    }
    $roles = devdredi_get_bool_setting('never_roles_enabled', 0) ? array_filter(explode(',', (string) devdredi_get_setting('never_roles', ''))) : array();
    if ($roles && is_user_logged_in() && array_intersect($roles, (array) wp_get_current_user()->roles)) {
        return 'role';
    }
    return '';
}
