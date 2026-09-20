<?php
/**
 * Known-bot detection for the "Don't redirect known bots" switch (1.5.1). A built-in list of
 * User-Agent tokens, so the switch works on every install with no external service; the shared
 * DevDome core matchers only EXTEND it where that library already holds a list (it never
 * downloads one on this build). Fail-open: an empty or unknown User-Agent is a real visitor.
 */

defined('ABSPATH') || exit;

/** Built-in User-Agent tokens (lowercase substring match). 'bot' is matched separately so CUBOT phone UAs are not flagged. */
function devdredi_bot_ua_tokens()
{
    return array(
        'spider', 'crawl', 'scrape', 'slurp', 'curl/', 'wget/', 'python-', 'httpclient',
        'headlesschrome', 'phantomjs', 'lighthouse', 'pingdom', 'gtmetrix',
        'facebookexternalhit', 'bingpreview', 'semrush', 'ahrefs', 'mj12',
    );
}

/** True when the current request looks like a bot: a crawler, monitor or scraper User-Agent, or a Spamhaus DROP address. */
function devdredi_is_known_bot()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $lc = strtolower(trim($ua));
    if ($lc !== '') {
        foreach (devdredi_bot_ua_tokens() as $token) {
            if (strpos($lc, $token) !== false) {
                return true;
            }
        }
        if (strpos($lc, 'bot') !== false && strpos($lc, 'cubot') === false) {
            return true;
        }
    }
    if (function_exists('devdcorev1_ua_is_bot') && devdcorev1_ua_is_bot($ua)) {
        return true;
    }
    $ip = function_exists('devdredi_get_client_ip') ? (string) devdredi_get_client_ip() : '';
    if ($ip !== '' && function_exists('devdcorev1_ip_in_drop') && devdcorev1_ip_in_drop($ip)) {
        return true;
    }
    return false;
}

/**
 * True for a desktop browser whose version is years behind (1.5.4, "Don't redirect outdated browsers").
 * Desktop Chrome, Edge and Firefox update themselves, so a real visitor is almost never on one of these,
 * while automated traffic often wears an old, copied User-Agent. Fixed floors, never raised on their own;
 * Chrome and Edge 109 (last for Windows 7/8) and Firefox 115 ESR stay allowed. Phones and tablets are never judged.
 */
function devdredi_is_outdated_browser()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if ($ua === '' || preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
        return false;
    }
    if (preg_match('#Firefox/(\d+)#', $ua, $m)) {
        return (int) $m[1] < 125 && (int) $m[1] !== 115;
    }
    if (preg_match('#(?:Chrome|Chromium)/(\d+)#', $ua, $m)) {
        return (int) $m[1] < 125 && (int) $m[1] !== 109;
    }
    return false;
}
