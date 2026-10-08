<?php
/**
 * Click reports to DevDome Analytics (1.5.7, per rule, OFF by default).
 *
 * A redirect has no link click the Analytics tracker could record, so the dashboard sees the visitor arrive and
 * never learns where the rule sent them. With "Report redirects to DevDome Analytics" ticked on a rule, two things
 * happen, both described in the readme under External services:
 *
 *  1. Every redirect the rule makes is reported server-side to the DevDome Analytics event endpoint as one
 *     `redirect` event (source page, destination, referrer, visitor IP address, browser string, country when the
 *     site sits behind Cloudflare, the DevDome visitor and session ids when the Analytics tracker set them).
 *  2. When the destination is ANOTHER site connected to the same DevDome account, an opaque site token
 *     (`?d=<token>`) is added to the destination so that site's arrival is credited to this one without naming
 *     this site in the address. The token comes from the hop-config endpoint and is cached for six hours.
 *
 * Consent is the rule's checkbox AND a connected DevDome account, checked when the redirect is decided and AGAIN
 * at send time; the cached token is dropped on disconnect and when the checkbox is cleared. The visitor's address
 * is read through the plugin's own trusted-proxy rules (never a forwarded header from an unknown connection).
 *
 * Port of the private hub add-on (devdome-redirect-manager-fleet 1.2.6 stats.php hop/exit block) with the plugin's
 * own prefix and the consent, disclosure and proxy changes the wp.org audit asked for (2026-09-19).
 */

defined('ABSPATH') || exit;

define('DEVDREDI_HOP_CFG_TRANSIENT', 'devdredi_hop_cfg');
define('DEVDREDI_ANALYTICS_DEFAULT_ENDPOINT', 'https://analytics.devdome.com/api/event');

/** The DevDome Analytics event endpoint (the Analytics plugin's setting when present, else the default). */
function devdredi_analytics_endpoint()
{
    $ep = (string) get_option('devdalyt_api_endpoint', DEVDREDI_ANALYTICS_DEFAULT_ENDPOINT);
    return (preg_match('~^https://[a-z0-9.\-]+/~i', $ep)) ? $ep : DEVDREDI_ANALYTICS_DEFAULT_ENDPOINT;
}

/**
 * The connected site's identity: site id (domain), site token, account id. Empty when the account is not connected
 * (the DevDome connect card is the consent for the account; the rule checkbox is the consent for the reports).
 */
function devdredi_analytics_identity()
{
    $site  = (string) get_option('devdcorev1_site_id', '');
    $token = (string) get_option('devdcorev1_site_token', '');
    $acct  = (string) get_option('devdcorev1_account_id', '');
    if ($site === '' || $token === '' || $acct === '') {
        return null;
    }
    return array('site' => $site, 'token' => $token, 'account' => $acct);
}

/** Headers that let the ingest trust this server as the sender (fleet relay secret when provisioned, else the site token). */
function devdredi_analytics_auth_headers($identity)
{
    $relay = (string) get_option('devdalyt_relay_secret', '');
    if ($relay !== '') {
        return array('X-DD-Relay' => $relay);
    }
    return array('X-DD-Site-Token' => (string) $identity['token']);
}

/**
 * Consent for ONE rule, evaluated now: the rule's checkbox is on, the account is connected and (when the DevDome
 * Analytics plugin is installed) its outbound-click switch is not off. A failed read of the checkbox = no consent.
 */
function devdredi_analytics_allowed($rid)
{
    $rid = (string) $rid;
    if ($rid === '') {
        return false;
    }
    unset($GLOBALS['devdredi_read_failed']);
    $on = (int) devdredi_raw_get_setting('rule__' . $rid . '__analytics_report', 0) === 1;
    if (!empty($GLOBALS['devdredi_read_failed']) || !$on) {
        return false;
    }
    if (devdredi_analytics_identity() === null) {
        return false;
    }
    if (devdredi_analytics_outbound_blocked()) {
        return false;
    }
    return true;
}

/**
 * True when the DevDome Analytics plugin's outbound-click switch is OFF, or when that read failed: a switch that could
 * not be read may be off, so nothing is sent (Codex 1.5.7 r1). A site without the Analytics plugin has no row = not blocked.
 */
function devdredi_analytics_outbound_blocked()
{
    devdredi_db_reset_error();
    $v = get_option('devdalyt_outbound_tracking_enabled', null);
    if (devdredi_db_failed()) {
        return true;
    }
    return null !== $v && !$v;
}

/** The rule the engine is handling right now ('' outside a rule). */
function devdredi_analytics_current_rule()
{
    return !empty($GLOBALS['devdredi_rule']) ? (string) $GLOBALS['devdredi_rule'] : '';
}

/** Drop the cached hop token (disconnect, checkbox cleared, deactivation). */
function devdredi_hop_config_clear()
{
    delete_transient(DEVDREDI_HOP_CFG_TRANSIENT);
}
add_action('delete_option_devdcorev1_account_id', 'devdredi_hop_config_clear');
add_action('delete_option_devdcorev1_site_token', 'devdredi_hop_config_clear');
add_action('update_option_devdcorev1_site_token', 'devdredi_hop_config_clear');

/**
 * Cached hop config for THIS site: array('token' => opaque id, 'hosts' => array(host => true)) from the analytics
 * backend. $allow_fetch is true only off the visitor path (the deferred send, the settings save), so a redirect never
 * waits for the network. A refused or failed fetch is remembered for ten minutes as "nothing to stamp".
 */
function devdredi_hop_config($allow_fetch = false)
{
    $cfg = get_transient(DEVDREDI_HOP_CFG_TRANSIENT);
    if (is_array($cfg)) {
        return (!empty($cfg['token']) && !empty($cfg['hosts'])) ? $cfg : null;
    }
    if (!$allow_fetch) {
        return null;
    }
    $id = devdredi_analytics_identity();
    if ($id === null) {
        return null;
    }
    $base = preg_replace('~/api/event/?$~', '', devdredi_analytics_endpoint());
    $resp = wp_remote_get(
        $base . '/api/plugin/hop-config?site=' . rawurlencode($id['site']),
        array('timeout' => 3, 'headers' => devdredi_analytics_auth_headers($id))
    );
    $empty = array('token' => '', 'hosts' => array());
    if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
        set_transient(DEVDREDI_HOP_CFG_TRANSIENT, $empty, 10 * MINUTE_IN_SECONDS);
        return null;
    }
    $data  = json_decode((string) wp_remote_retrieve_body($resp), true);
    $token = (is_array($data) && isset($data['token']) && is_string($data['token'])) ? preg_replace('/[^a-z0-9]/', '', strtolower($data['token'])) : '';
    $hosts = array();
    if (is_array($data) && !empty($data['hosts']) && is_array($data['hosts'])) {
        foreach (array_slice($data['hosts'], 0, 1000) as $h) {
            $h = strtolower(preg_replace('~[^a-z0-9.\-]~', '', (string) $h));
            if ($h !== '') {
                $hosts[$h] = true;
            }
        }
    }
    if (strlen($token) < 6 || strlen($token) > 16 || empty($hosts)) {
        set_transient(DEVDREDI_HOP_CFG_TRANSIENT, $empty, 10 * MINUTE_IN_SECONDS);
        return null;
    }
    $cfg = array('token' => $token, 'hosts' => $hosts);
    set_transient(DEVDREDI_HOP_CFG_TRANSIENT, $cfg, 6 * HOUR_IN_SECONDS);
    return $cfg;
}

/**
 * Stamp the opaque token (?d=<token>) on a destination that is ANOTHER site connected to the same account. Merchants
 * and unknown hosts stay clean. Reads the warmed cache only; consent is checked here too, a warm cache is not consent.
 */
function devdredi_stamp_hop($url)
{
    if (!is_string($url) || $url === '' || !devdredi_analytics_allowed(devdredi_analytics_current_rule())) {
        return $url;
    }
    $cfg = devdredi_hop_config(false);
    if (!is_array($cfg)) {
        return $url;
    }
    $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($url, PHP_URL_HOST)));
    $own  = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
    if ($host === '' || $host === $own || empty($cfg['hosts'][$host]) || preg_match('~[?&]d=~', $url)) {
        return $url;
    }
    return add_query_arg('d', $cfg['token'], $url);
}

/**
 * Destination filter: with the reports on, a transit hop drops the inbound ?d= (it names the PREVIOUS site) and the
 * destination gets this site's token when it is another connected site. With the reports off nothing changes.
 */
add_filter('devdredi_redirect_target', function ($target, $source = '') {
    if (!is_string($target) || $target === '' || !devdredi_analytics_allowed(devdredi_analytics_current_rule())) {
        return $target;
    }
    if ($source === 'transit') {
        $target = remove_query_arg('d', $target);
    }
    return devdredi_stamp_hop($target);
}, 10, 2);

/** A redirect happened: queue the exit report (sent after the response, consent re-checked then). */
add_action('devdredi_redirected', function ($target, $source_url = '', $referrer = '', $type = '') {
    $rid = devdredi_analytics_current_rule();
    if (!devdredi_analytics_allowed($rid)) {
        return;
    }
    if ($type === 'js') {
        devdredi_emit_exit_event($rid, $target, $source_url, ($referrer !== '' ? $referrer : null));
    } else {
        devdredi_emit_exit_event($rid, $target);
    }
}, 10, 4);

/** Build the `redirect` event and hand it to the deferred sender. Never blocks the redirect. */
function devdredi_emit_exit_event($rid, $target_url, $source_url = null, $referrer_fallback = null)
{
    $id = devdredi_analytics_identity();
    if ($id === null || !is_string($target_url) || $target_url === '') {
        return;
    }
    $ua     = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $prof   = devdredi_ua_profile($ua);
    $ua_bot = ($ua === '') || (bool) preg_match('~bot|crawl|spider|slurp|curl|wget|python|headless|phantom|monitor|preview|fetch~i', $ua);
    // The destination goes out clean: chain-internal markers never reach the report.
    $target_url = remove_query_arg(array('dd_ref', '_dd', 'd'), $target_url);
    $site = (string) $id['site'];
    if (is_string($source_url) && $source_url !== '') {
        // JS redirects report from the counter pixel or the Visitor Check verifier: the SOURCE page is the URL given.
        $path  = (string) wp_parse_url($source_url, PHP_URL_PATH);
        $query = (string) wp_parse_url($source_url, PHP_URL_QUERY);
        $host  = (string) wp_parse_url($source_url, PHP_URL_HOST);
        $https = (stripos($source_url, 'https://') === 0) || is_ssl();
        $qs    = array();
        if ($query !== '') {
            parse_str($query, $qs);
        }
        $dd_ref  = isset($qs['dd_ref']) && is_string($qs['dd_ref']) ? preg_replace('~[^a-z0-9.\-]~', '', strtolower($qs['dd_ref'])) : '';
        $hop_tok = isset($qs['d']) && is_string($qs['d']) ? preg_replace('/[^a-z0-9]/', '', strtolower($qs['d'])) : '';
        $raw_ref = (is_string($referrer_fallback) && $referrer_fallback !== '') ? $referrer_fallback : null;
    } else {
        $req   = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $path  = (string) wp_parse_url($req, PHP_URL_PATH);
        $host  = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $https = is_ssl();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- hop markers on a public front-end request, read only.
        $dd_ref  = (isset($_GET['dd_ref']) && is_string($_GET['dd_ref'])) ? preg_replace('~[^a-z0-9.\-]~', '', strtolower(sanitize_text_field(wp_unslash($_GET['dd_ref'])))) : '';
        $hop_tok = (isset($_GET['d']) && is_string($_GET['d'])) ? preg_replace('/[^a-z0-9]/', '', strtolower(sanitize_text_field(wp_unslash($_GET['d'])))) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $raw_ref = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : null;
    }
    if ($host === '') {
        $host = $site;
    }
    if ($path === '') {
        $path = '/';
    }
    $referrer = $dd_ref !== '' ? 'https://' . $dd_ref . '/' : $raw_ref;
    if (is_string($referrer) && !preg_match('~^https?://~i', $referrer)) {
        $referrer = null;
    }
    $asin = '';
    if (preg_match('~/(?:go|i|p|r|check|price|amazon|link)/([A-Za-z0-9]{10})/?$~i', $path, $m)) {
        $asin = strtoupper($m[1]);
    }
    $payload = array(
        'event_type'   => 'redirect',
        'site_id'      => $site,
        'site_domain'  => $site,
        'account_id'   => (string) $id['account'],
        'page_url'     => ($https ? 'https' : 'http') . '://' . $host . $path,
        'page_path'    => $path,
        'target_url'   => $target_url,
        'referrer'     => $referrer,
        'asin'         => ($asin !== '' ? $asin : null),
        'user_agent'   => $ua,
        'via'          => 'transit',
        'capture'      => 'server-rm',
        'is_known_bot' => $ua_bot,
    );
    if ($hop_tok !== '' && strlen($hop_tok) >= 6 && strlen($hop_tok) <= 16) {
        $payload['hop_token'] = $hop_tok;
    }
    if (is_array($prof)) {
        $payload['browser']     = $prof['browser'];
        $payload['os']          = $prof['os'];
        $payload['device_type'] = $prof['device'];
    }
    // Country only from Cloudflare's own header, and only when the connection really comes from a Cloudflare edge.
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && function_exists('devdredi_remote_is_cloudflare') && devdredi_remote_is_cloudflare()) {
        $cc = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY']))), 0, 2));
        if (strlen($cc) === 2 && $cc !== 'XX' && $cc !== 'T1') {
            $payload['country'] = $cc;
        }
    }
    // The visitor's address through the plugin's own trusted-proxy rules (the POST itself comes from this server).
    $ip = function_exists('devdredi_get_client_ip') ? (string) devdredi_get_client_ip() : '';
    if ($ip !== '') {
        $payload['ip'] = $ip;
    }
    // Same journey as the tracker's page view when its first-party ids are present.
    $vid = isset($_COOKIE['dd_vid']) ? preg_replace('/[^A-Za-z0-9\-]/', '', sanitize_text_field(wp_unslash($_COOKIE['dd_vid']))) : '';
    $sid = '';
    if (!empty($_COOKIE['ddc_sid'])) {
        $sid = preg_replace('/[^a-f0-9]/', '', sanitize_text_field(wp_unslash($_COOKIE['ddc_sid'])));
        if (strlen($sid) < 8) {
            $sid = '';
        }
    }
    if ($vid !== '') {
        $payload['visitor_id'] = $vid;
    }
    if ($sid !== '') {
        $payload['session_id'] = $sid;
        if ($vid === '') {
            $payload['visitor_id'] = $sid;
        }
    } elseif ($vid !== '') {
        $payload['session_id'] = $vid;
    }
    $GLOBALS['devdredi_pending_exit'] = array('rule' => (string) $rid, 'payload' => $payload);
    add_action('shutdown', 'devdredi_flush_exit_event', 0);
}

/** Shutdown: consent is checked once more, then the event is sent after the visitor's response has been flushed. */
function devdredi_flush_exit_event()
{
    $pending = isset($GLOBALS['devdredi_pending_exit']) ? $GLOBALS['devdredi_pending_exit'] : null;
    $GLOBALS['devdredi_pending_exit'] = null;
    if (!is_array($pending) || empty($pending['payload'])) {
        return;
    }
    if (!devdredi_analytics_allowed($pending['rule'])) {
        return; // the checkbox was cleared or the account disconnected while this request ran: nothing leaves
    }
    $id = devdredi_analytics_identity();
    if ($id === null) {
        return;
    }
    $deferred = function_exists('fastcgi_finish_request');
    if ($deferred) {
        fastcgi_finish_request();
        devdredi_hop_config(true); // the response is out: warm the token off the visitor path so the next redirect can stamp it
    }
    // Without fastcgi_finish_request() the visitor is still waiting: no hop fetch here (the settings save warms it) and the
    // event goes out non-blocking.
    $headers = array_merge(array('Content-Type' => 'application/json'), devdredi_analytics_auth_headers($id));
    wp_remote_post(devdredi_analytics_endpoint(), array(
        'timeout'  => $deferred ? 5 : 1,
        'blocking' => $deferred,
        'headers'  => $headers,
        'body'     => wp_json_encode($pending['payload']),
    ));
}

/** {browser, os, device} from a User-Agent, null for bots and unknown browsers. Mirrors the tracker's own parsing. */
function devdredi_ua_profile($ua)
{
    if ($ua === '' || preg_match('~bot|crawl|spider|slurp|curl|wget|python|headless|phantom|monitor|preview|fetch~i', $ua)) {
        return null;
    }
    if (preg_match('~Edg/~', $ua)) {
        $browser = 'edge';
    } elseif (preg_match('~Chrome/~', $ua)) {
        $browser = 'chrome';
    } elseif (preg_match('~Firefox/~', $ua)) {
        $browser = 'firefox';
    } elseif (preg_match('~Safari/~', $ua)) {
        $browser = 'safari';
    } else {
        return null;
    }
    $ios = (bool) preg_match('~iPad|iPhone|iPod~', $ua);
    $and = (bool) preg_match('~Android~', $ua);
    if ($ios) {
        $os = 'ios';
    } elseif ($and) {
        $os = 'android';
    } elseif (preg_match('~Windows~', $ua)) {
        $os = 'windows';
    } elseif (preg_match('~Mac OS~', $ua)) {
        $os = 'macos';
    } elseif (preg_match('~Linux~', $ua)) {
        $os = 'linux';
    } else {
        $os = 'other';
    }
    return array('browser' => $browser, 'os' => $os, 'device' => ($ios || $and || preg_match('~Mobile~', $ua)) ? 'mobile' : 'desktop');
}
