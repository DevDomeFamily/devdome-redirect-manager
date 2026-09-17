<?php
/**
 * Geo resolution via the DevDome MaxMind "geo-resolve" service (the same backend
 * Analytics uses). No third-party API key required — resolution happens server-side
 * (local MaxMind GeoLite2 with an ip-api fallback) at api.devdome.com/geo-resolve.
 */

defined('ABSPATH') || exit;

if (!defined('DEVDREDI_GEO_ENDPOINT')) {
    define('DEVDREDI_GEO_ENDPOINT', 'https://api.devdome.com/geo-resolve');
}

/** Resolve an IP to an ISO-3166-1 alpha-2 country code (or false). Cached per IP. */
/** A country is exactly two letters (ISO 3166-1 alpha-2); anything else the service returns counts as Unknown. */
function devdredi_geo_code($raw) {
    $c = strtoupper(trim((string) $raw));
    return preg_match('/^[A-Z]{2}$/', $c) ? $c : 'Unknown';
}

function devdredi_get_country_by_ip($ip)
{
    $ip = trim((string) $ip);
    if ($ip === '') {
        return false;
    }

    $cache_key = 'devdredi_cc2_' . md5($ip); // cc2: the /resolve-era keys cached a miss for every IP, they must not survive the upgrade (1.5.3)
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached === '0' ? false : $cached;
    }

    if (get_transient('devdredi_geo_error')) {
        return false; // the service failed within the last 5 minutes: no repeated 5-second waits per visitor
    }

    // /classify answers IP -> country for any caller (1.5.3). The /resolve endpoint used before is the
    // Affiliate Manager's store-routing call and answers country null without a connected account, which
    // silently turned every country rule into "redirect nobody".
    $resp = wp_remote_post(DEVDREDI_GEO_ENDPOINT . '/classify', array(
        'timeout' => 5,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array('ips' => array($ip))),
    ));

    if (is_wp_error($resp)) {
        set_transient('devdredi_geo_error', 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $row  = (is_array($data) && isset($data['results'][$ip]) && is_array($data['results'][$ip])) ? $data['results'][$ip] : array();
    $country = !empty($row['country']) ? devdredi_geo_code(sanitize_text_field($row['country'])) : false;

    if ($country) {
        delete_transient('devdredi_geo_error');
    }
    // Cache misses as '0' too, so a failed lookup doesn't hammer the service every request.
    set_transient($cache_key, $country ?: '0', HOUR_IN_SECONDS);
    return $country;
}

function devdredi_get_user_country()
{
    $user_ip = devdredi_get_client_ip();
    // Treat empty, private, reserved and loopback addresses as Unknown. The old strpos checks both
    // over-matched (any address containing "10.") and under-matched (missed 172.16/12, fc00::/7,
    // ::1, …); filter_var with the no-private/no-reserved flags classifies them exactly.
    if ($user_ip === '' || !filter_var($user_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return 'Unknown';
    }
    return devdredi_get_country_by_ip($user_ip);
}

function devdredi_check_clofilter_api($campaign_id)
{
    return null;
}

/**
 * Ping the geo-resolve service (used by the "Test geo service" button). No key needed.
 * Result is cached for 30s so repeated checks can't hammer api.devdome.com — the upstream
 * is hit at most once per 30s per site, no matter how often the button/endpoint is called.
 */
function devdredi_geo_health()
{
    $cached = get_transient('devdredi_geo_health');
    if (is_array($cached)) {
        return $cached;
    }

    $resp = wp_remote_get(DEVDREDI_GEO_ENDPOINT . '/health', array('timeout' => 8));
    if (is_wp_error($resp)) {
        $result = array('success' => false, 'message' => 'Cannot reach geo service: ' . $resp->get_error_message());
    } else {
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($data) && !empty($data['ok'])) {
            $result = array('success' => true, 'message' => 'OK', 'source' => $data['geo_source'] ?? 'unknown');
        } else {
            $result = array('success' => false, 'message' => 'Geo service not healthy');
        }
    }

    set_transient('devdredi_geo_health', $result, 30);
    return $result;
}

function devdredi_ipgeo_health()
{
    // The screen calls the REST route below; this admin-ajax twin is the fallback. It reaches
    // out to the geo service, so it takes the same nonce + capability check as everything else.
    check_ajax_referer('wp_rest', '_wpnonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'forbidden'), 403);
    }
    $h = devdredi_geo_health();
    if (!empty($h['success'])) {
        wp_send_json_success($h);
    }
    wp_send_json_error($h);
}
add_action('init', function () {
    add_action('wp_ajax_devdredi_ipgeo_health', 'devdredi_ipgeo_health');
});

add_action('rest_api_init', function () {
    register_rest_route('devdredi/v1', '/ipgeo-health', array(
        'methods'             => array('GET', 'POST'),
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => function () {
            $h = devdredi_geo_health();
            return new WP_REST_Response($h, !empty($h['success']) ? 200 : 400);
        },
    ));
});

/**
 * Detect whether THIS request reached the server through a proxy/CDN, by inspecting the
 * forwarding headers it carries. The admin's request to the site's own domain travels the
 * same path real visitors take, so the headers present here reflect the live setup.
 */
function devdredi_detect_proxy()
{
    // Strong, unambiguous CDN signals.
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_CF_RAY'])) {
        return array('detected' => true, 'label' => 'Cloudflare');
    }
    $cdn_headers = array('HTTP_X_SUCURI_CLIENTIP', 'HTTP_FASTLY_CLIENT_IP', 'HTTP_X_AKAMAI_EDGESCAPE', 'HTTP_X_CDN');
    foreach ($cdn_headers as $h) {
        if (!empty($_SERVER[$h])) {
            return array('detected' => true, 'label' => 'a CDN');
        }
    }

    // Generic forwarding header is only meaningful when the CONNECTION IP isn't public —
    // i.e. a real reverse proxy / load balancer sits in front. On a direct site the connection
    // IP is public, so a bare X-Forwarded-For there is spoofable and must NOT be trusted.
    $has_fwd = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP']) || !empty($_SERVER['HTTP_TRUE_CLIENT_IP']);
    $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    $remote_is_public = $remote && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($has_fwd && !$remote_is_public) {
        return array('detected' => true, 'label' => 'a reverse proxy / load balancer');
    }

    return array('detected' => false, 'label' => 'No proxy detected');
}

add_action('rest_api_init', function () {
    register_rest_route('devdredi/v1', '/proxy-detect', array(
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => function () {
            return new WP_REST_Response(devdredi_detect_proxy(), 200);
        },
    ));
});
