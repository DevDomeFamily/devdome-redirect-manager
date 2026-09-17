<?php
/**
 * Admin content-search REST endpoint for the "Selected existing URLs" picker.
 * Returns the site's pages, posts, or categories matching a query. Admin only.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('devdredi/v1', '/search-content', array(
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => 'devdredi_search_content',
        'args'                => array(
            'type' => array('default' => 'page'),
            'q'    => array('default' => ''),
        ),
    ));
});

/**
 * The taxonomies the "Categories" picker offers: every public, admin-visible taxonomy of a public post type, not only
 * the blog "category". WooCommerce product categories, brands and tags, blog tags and any custom taxonomy appear next
 * to the blog categories (owner 2026-09-15: heatad showed blog categories only; then "all categories must show up in
 * the hints, woo and articles and all the rest"). nav_menu, post_format and the like are not public or have no admin
 * screen, so they never enter.
 *
 * @return array<string, string> taxonomy name => label
 */
function devdredi_category_taxonomies()
{
    $out = array('category' => __('Categories', 'devdome-redirect-manager'));
    foreach (get_taxonomies(array('public' => true, 'show_ui' => true), 'objects') as $tax) {
        if ($tax->name === 'category' || empty($tax->object_type)) {
            continue;
        }
        $public_owner = false;
        foreach ((array) $tax->object_type as $pt) {
            $o = get_post_type_object($pt);
            if ($o && !empty($o->public)) {
                $public_owner = true;
                break;
            }
        }
        if ($public_owner) {
            $out[$tax->name] = (string) (isset($tax->labels->name) ? $tax->labels->name : $tax->label);
        }
    }
    return $out;
}

/**
 * The query in the forms a search needs. A pasted address or path keeps only its last part, so
 * "https://example.com/product-reviews/" and "/product-reviews/" search like "product-reviews". Hyphens and
 * underscores also match spaces: "product-reviews" finds the title "Product Reviews", "product reviews" finds the slug,
 * and "product-" still finds "Products".
 *
 * @return array{last: string, words: string, slug: string}
 */
function devdredi_search_query_parts($q)
{
    $q = trim((string) $q);
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $q)) {
        $path = wp_parse_url($q, PHP_URL_PATH);
        $q    = is_string($path) ? $path : '';
    }
    $q     = trim($q, "/ \t");
    $pos   = strrpos($q, '/');
    $last  = $pos === false ? $q : substr($q, $pos + 1);
    $words = trim((string) preg_replace('/[-_\s]+/', ' ', $last));
    $slug  = sanitize_title($words);
    return array(
        'last'  => $last,
        'words' => $words !== '' ? $words : $last,
        'slug'  => $slug !== '' ? $slug : $last,
    );
}

/** True when one of the texts contains the query as typed, as words or as a slug (case-insensitive). */
function devdredi_search_hit($texts, $parts)
{
    foreach ((array) $texts as $text) {
        $text = strtolower((string) $text);
        foreach (array($parts['last'], $parts['words'], $parts['slug']) as $needle) {
            if ($needle !== '' && strpos($text, strtolower($needle)) !== false) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Post type archives matching $q: the WooCommerce shop at its product base (heatad: /product-reviews/) and any custom
 * post type archive. A picked archive URL ends in a slash, so the rule covers every item under it.
 */
function devdredi_search_archives($q, $limit = 20)
{
    $parts = devdredi_search_query_parts($q);
    $label = __('Archive', 'devdome-redirect-manager');
    $rows  = array();
    foreach (get_post_types(array('public' => true, '_builtin' => false), 'objects') as $pt) {
        $link = get_post_type_archive_link($pt->name);
        if (!$link) {
            continue;
        }
        $name = (string) (isset($pt->labels->name) ? $pt->labels->name : $pt->label);
        if ($parts['last'] !== '' && !devdredi_search_hit(array($name, wp_make_link_relative($link)), $parts)) {
            continue;
        }
        $rows[] = array(
            'id'             => 0,
            'name'           => $name,
            'taxonomy'       => '',
            'taxonomy_label' => $label,
            'label'          => $name . ' (' . $label . ')',
            'link'           => (string) $link,
        );
    }
    return array_slice($rows, 0, max(1, (int) $limit));
}

/**
 * Terms matching $q across every picker taxonomy, blog categories first, then the others in taxonomy order, at most
 * $limit in total. Each row: name, taxonomy, taxonomy_label, label (the name, with the taxonomy label after it when the
 * site has more than one such taxonomy, so "Heaters" from the shop is told from "Heaters" on the blog), link.
 */
function devdredi_search_category_terms($q, $limit = 20)
{
    $parts   = devdredi_search_query_parts($q);
    $taxes   = devdredi_category_taxonomies();
    $multi   = count($taxes) > 1;
    $needles = $parts['last'] === '' ? array('') : array_values(array_unique(array($parts['last'], $parts['words'], $parts['slug'])));
    $rows_by_tax = array(); // per taxonomy, merged round-robin below so blog categories cannot crowd the others out
    foreach ($taxes as $tax => $tax_label) {
        $seen = array();
        foreach ($needles as $needle) {
            $terms = get_terms(array('taxonomy' => $tax, 'search' => $needle, 'number' => $limit, 'hide_empty' => false));
            if (is_wp_error($terms) || !is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (isset($seen[$term->term_id])) {
                    continue;
                }
                $seen[$term->term_id] = true;
                $link = get_term_link($term);
                if (is_wp_error($link)) {
                    continue;
                }
                $rows_by_tax[$tax][] = array(
                    'id'             => (int) $term->term_id,
                    'name'           => (string) $term->name,
                    'taxonomy'       => $tax,
                    'taxonomy_label' => $tax_label,
                    'label'          => $multi && $tax !== 'category' ? $term->name . ' (' . $tax_label . ')' : (string) $term->name,
                    'link'           => (string) $link,
                );
            }
        }
    }
    // Fair share: one term from each taxonomy in turn until the cap, so WooCommerce categories, brands and tags
    // show next to blog categories instead of being cut off after them.
    $rows = array();
    $cap  = max(1, (int) $limit);
    while (count($rows) < $cap && $rows_by_tax) {
        foreach (array_keys($rows_by_tax) as $tax) {
            if (!$rows_by_tax[$tax]) {
                unset($rows_by_tax[$tax]);
                continue;
            }
            $rows[] = array_shift($rows_by_tax[$tax]);
            if (count($rows) >= $cap) {
                break;
            }
        }
    }
    return $rows;
}

/**
 * Published items whose title or slug contains the query as typed, as words or as a slug. $post_type 'page' searches
 * pages; 'post' searches blog posts and every other public post type (WooCommerce products, custom types), each
 * non-post item labelled with its type, so a single product review can be picked too.
 */
function devdredi_search_posts($post_type, $q, $limit = 20)
{
    $parts = devdredi_search_query_parts($q);
    $types = array('page');
    if ($post_type !== 'page') {
        $types = array_values(array_diff(get_post_types(array('public' => true)), array('page', 'attachment')));
        $types = $types ? $types : array('post');
    }
    $args  = array(
        'post_type'      => $types,
        'posts_per_page' => max(1, (int) $limit),
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'orderby'        => 'title',
        'order'          => 'ASC',
    );
    // Substring match on the title and the slug (not WordPress' fuzzy keyword search).
    $where_cb = null;
    if ($parts['last'] !== '') {
        $where_cb = function ($where) use ($parts) {
            global $wpdb;
            $typed = '%' . $wpdb->esc_like($parts['last']) . '%';
            $words = '%' . $wpdb->esc_like($parts['words']) . '%';
            $slug  = '%' . $wpdb->esc_like($parts['slug']) . '%';
            return $where . $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_name LIKE %s OR {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_name LIKE %s)", $typed, $typed, $words, $slug);
        };
        add_filter('posts_where', $where_cb);
    }
    $query = new WP_Query($args);
    if ($where_cb) {
        remove_filter('posts_where', $where_cb);
    }
    $rows = array();
    foreach ($query->posts as $p) {
        $label = (string) get_the_title($p);
        if ($p->post_type !== 'post' && $p->post_type !== 'page') {
            $pto   = get_post_type_object($p->post_type);
            $label .= ' (' . ($pto && isset($pto->labels->singular_name) ? $pto->labels->singular_name : $p->post_type) . ')';
        }
        $rows[] = array('id' => (int) $p->ID, 'label' => $label, 'link' => (string) get_permalink($p));
    }
    wp_reset_postdata();
    return $rows;
}

/** Picker rows of one tab: categories = archives + terms of every taxonomy; page and post = those post types. */
function devdredi_search_rows($type, $q, $limit)
{
    if ($type === 'category') {
        return array_slice(array_merge(devdredi_search_archives($q, $limit), devdredi_search_category_terms($q, $limit)), 0, max(1, (int) $limit));
    }
    return devdredi_search_posts($type === 'post' ? 'post' : 'page', $q, $limit);
}

/**
 * The picker search. The open tab's matches come first; with a query, matches from the other two tabs follow, marked
 * with their type, so a page shows up while the Categories tab is open (owner 2026-09-15: "product-reviews" showed
 * nothing under Categories because /product-reviews/ is the shop page). One row per URL.
 */
function devdredi_search_content($request)
{
    $type  = sanitize_key($request->get_param('type'));
    $type  = in_array($type, array('category', 'page', 'post'), true) ? $type : 'page';
    $q     = sanitize_text_field($request->get_param('q'));
    $parts = devdredi_search_query_parts($q);
    $items = array();
    $seen  = array();
    foreach (array_values(array_unique(array($type, 'category', 'page', 'post'))) as $tab) {
        if ($tab !== $type && $parts['last'] === '') {
            break; // an empty search lists the open tab only
        }
        $limit = $tab !== $type ? 10 : ($tab === 'category' ? 100 : 30);
        foreach (devdredi_search_rows($tab, $q, $limit) as $row) {
            $value = wp_make_link_relative($row['link']);
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $items[] = array(
                'label' => $row['label'],
                'value' => $value,
                'type'  => $tab,
                'other' => $tab !== $type,
            );
        }
    }

    return new WP_REST_Response(array('items' => $items), 200);
}
