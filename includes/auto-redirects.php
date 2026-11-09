<?php
/**
 * Automatic 301 redirects (1.5.10): when a published post or page gets a new slug, its old address is remembered and
 * answered with a 301 to the new one; when a post or page is trashed, its address goes to the parent page, the first
 * category or the home page. Only a request that would otherwise be a 404 is redirected, so a real page at the old
 * address always wins. The entries live in one option (newest first, capped) and are listed on the rules page with a
 * Remove button. The map helpers are pure so the CLI suite proves them; the WordPress hooks are at the bottom.
 */

defined('ABSPATH') || exit;

/** Most entries kept; the oldest fall off (a 500-entry option stays well under 100 KB). */
define('DEVDREDI_AUTO_MAX', 500);

/** The request path normalised the way the map keys are: leading slash, no trailing slash, no query, lower-case. */
function devdredi_auto_norm_path($path)
{
    $p = (string) $path;
    $q = strpos($p, '?');
    if ($q !== false) {
        $p = substr($p, 0, $q);
    }
    $p = '/' . trim(strtolower(trim($p)), '/');
    return $p === '//' ? '/' : $p;
}

/** The map: path => array(kind post|url, to int|string, why slug|trash, at int, title string). */
function devdredi_auto_map()
{
    $m = get_option('devdredi_auto_redirects', array());
    return is_array($m) ? $m : array();
}

/** Add or replace one entry (pure on the array; the caller stores it). Newest first, capped. */
function devdredi_auto_map_put(array $map, $path, array $entry)
{
    $path = devdredi_auto_norm_path($path);
    if ($path === '/' || $path === '') {
        return $map; // the home address is never redirected away
    }
    unset($map[$path]);
    $map = array($path => $entry) + $map;
    if (count($map) > DEVDREDI_AUTO_MAX) {
        $map = array_slice($map, 0, DEVDREDI_AUTO_MAX, true);
    }
    return $map;
}

/** Drop every entry made for this post id, slug or trash kind (its slug came back, or it was restored from the trash). */
function devdredi_auto_map_forget_post(array $map, $post_id)
{
    foreach ($map as $path => $e) {
        $owner = is_array($e) ? (isset($e['post']) ? (int) $e['post'] : ((isset($e['kind'], $e['to']) && $e['kind'] === 'post') ? (int) $e['to'] : 0)) : 0;
        if ($owner && $owner === (int) $post_id) {
            unset($map[$path]);
        }
    }
    return $map;
}

/**
 * Where a request path should go, or '' when the map has nothing for it.
 * $permalink resolves a post id to its current address ('' when the post is not published any more).
 */
function devdredi_auto_target(array $map, $path, $permalink)
{
    $key = devdredi_auto_norm_path($path);
    if (!isset($map[$key]) || !is_array($map[$key])) {
        return '';
    }
    $e = $map[$key];
    if (!isset($e['kind'], $e['to'])) {
        return '';
    }
    $to = $e['kind'] === 'post' ? (string) call_user_func($permalink, (int) $e['to']) : (string) $e['to'];
    if ($to === '' || devdredi_auto_norm_path((string) wp_parse_url($to, PHP_URL_PATH)) === $key) {
        return ''; // nothing to go to, or it would loop
    }
    return $to;
}

/** The old address of a post whose slug changed: the current path with its last segment swapped back. */
function devdredi_auto_old_path($new_path, $new_slug, $old_slug)
{
    $p = devdredi_auto_norm_path($new_path);
    $new_slug = strtolower((string) $new_slug);
    $old_slug = strtolower((string) $old_slug);
    if ($new_slug === '' || $old_slug === '' || $new_slug === $old_slug) {
        return '';
    }
    $tail = '/' . $new_slug;
    if (substr($p, -strlen($tail)) !== $tail) {
        return '';
    }
    return substr($p, 0, -strlen($tail)) . '/' . $old_slug;
}

/* ------------------------------ WordPress hooks ------------------------------ */

/** A slug change on a published, public post: remember the old address. */
function devdredi_auto_on_post_updated($post_id, $after, $before)
{
    if (!($after instanceof WP_Post) || !($before instanceof WP_Post)) {
        return;
    }
    if ($after->post_name === $before->post_name || $after->post_status !== 'publish' || $before->post_status !== 'publish') {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    $type = get_post_type_object($after->post_type);
    if (!$type || empty($type->public)) {
        return;
    }
    $new_path = (string) wp_parse_url(get_permalink($post_id), PHP_URL_PATH);
    $old_path = devdredi_auto_old_path($new_path, $after->post_name, $before->post_name);
    if ($old_path === '') {
        return;
    }
    $map = devdredi_auto_map_forget_post(devdredi_auto_map(), $post_id); // an older address of the same post is replaced
    $map = devdredi_auto_map_put($map, $old_path, array(
        'kind'  => 'post',
        'to'    => (int) $post_id,
        'why'   => 'slug',
        'at'    => time(),
        'title' => wp_strip_all_tags((string) $after->post_title),
    ));
    update_option('devdredi_auto_redirects', $map, false);
}
add_action('post_updated', 'devdredi_auto_on_post_updated', 10, 3);

/** A published, public post goes to the trash: its address goes to the parent, the first category, or home. */
function devdredi_auto_on_trash($post_id)
{
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    $type = get_post_type_object($post->post_type);
    if (!$type || empty($type->public)) {
        return;
    }
    $path = (string) wp_parse_url(get_permalink($post_id), PHP_URL_PATH);
    $to   = '';
    if ($post->post_parent && get_post_status($post->post_parent) === 'publish') {
        $to = (string) get_permalink($post->post_parent);
    } elseif ($post->post_type === 'post') {
        $cats = get_the_category($post_id);
        if (is_array($cats) && $cats && !is_wp_error($link = get_category_link($cats[0]->term_id))) {
            $to = (string) $link;
        }
    }
    if ($to === '') {
        $to = (string) home_url('/');
    }
    $map = devdredi_auto_map_put(devdredi_auto_map(), $path, array(
        'kind'  => 'url',
        'to'    => $to,
        'post'  => (int) $post_id,
        'why'   => 'trash',
        'at'    => time(),
        'title' => wp_strip_all_tags((string) $post->post_title),
    ));
    update_option('devdredi_auto_redirects', $map, false);
}
add_action('wp_trash_post', 'devdredi_auto_on_trash', 10, 1);

/** Restored from the trash: its address is a real page again, the entry goes. */
function devdredi_auto_on_untrash($post_id)
{
    $map  = devdredi_auto_map();
    $path = devdredi_auto_norm_path((string) wp_parse_url(get_permalink($post_id), PHP_URL_PATH));
    $next = devdredi_auto_map_forget_post($map, $post_id);
    unset($next[$path]);
    if ($next !== $map) {
        update_option('devdredi_auto_redirects', $next, false);
    }
}
add_action('untrashed_post', 'devdredi_auto_on_untrash', 10, 1);

/** A 404 whose path is in the map: 301 to the current address. Runs before the rules engine (priority 1). */
function devdredi_auto_redirect_404()
{
    if (!is_404()) {
        return;
    }
    $map = devdredi_auto_map();
    if (!$map) {
        return;
    }
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed to a path and only looked up as a key
    $to   = devdredi_auto_target($map, $path, function ($id) {
        return get_post_status($id) === 'publish' ? (string) get_permalink($id) : '';
    });
    if ($to === '') {
        return;
    }
    wp_safe_redirect($to, 301);
    exit;
}
add_action('template_redirect', 'devdredi_auto_redirect_404', 1);
