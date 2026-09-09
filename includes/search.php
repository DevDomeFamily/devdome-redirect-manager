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

function devdredi_search_content($request)
{
    $type = sanitize_key($request->get_param('type'));
    $q    = sanitize_text_field($request->get_param('q'));
    $items = array();

    if ($type === 'category') {
        $terms = get_terms(array(
            'taxonomy'   => 'category',
            'search'     => $q,
            'number'     => 20,
            'hide_empty' => false,
        ));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (is_wp_error($link)) {
                    continue;
                }
                $items[] = array(
                    'label' => $term->name,
                    'value' => wp_make_link_relative($link),
                );
            }
        }
    } else {
        $post_type = ($type === 'post') ? 'post' : 'page';
        $args = array(
            'post_type'      => $post_type,
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        // Exact substring match on the title (not WordPress' fuzzy keyword search).
        $where_cb = null;
        if ($q !== '') {
            $where_cb = function ($where) use ($q) {
                global $wpdb;
                $like = '%' . $wpdb->esc_like($q) . '%';
                return $where . $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_name LIKE %s)", $like, $like);
            };
            add_filter('posts_where', $where_cb);
        }
        $query = new WP_Query($args);
        if ($where_cb) {
            remove_filter('posts_where', $where_cb);
        }
        foreach ($query->posts as $p) {
            $items[] = array(
                'label' => get_the_title($p),
                'value' => wp_make_link_relative(get_permalink($p)),
            );
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response(array('items' => $items), 200);
}
