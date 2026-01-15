<?php

if (!defined('ABSPATH')) {
    exit;
}

function aa_ad_manager_register_ads_cpt() {
    $labels = array(
        'name'               => 'Ad Manager',
        'singular_name'      => 'Ad',
        'menu_name'          => 'Ad Manager',
        'name_admin_bar'     => 'Ad',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Ad',
        'new_item'           => 'New Ad',
        'edit_item'          => 'Edit Ad',
        'view_item'          => 'View Ad',
        'all_items'          => 'All Ads',
        'search_items'       => 'Search Ads',
        'not_found'          => 'No ads found.',
        'not_found_in_trash' => 'No ads found in Trash.',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'aa_ads'),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => 70,
        'supports'           => array('title'),
        'menu_icon'          => 'dashicons-megaphone',
    );

    register_post_type('aa_ads', $args);
}
add_action('init', 'aa_ad_manager_register_ads_cpt');

function aa_ad_manager_ads_custom_columns($columns) {
    $columns['shortcode'] = 'Shortcode';
    $columns['ad_image'] = 'Ad Image';
    return $columns;
}
add_filter('manage_aa_ads_posts_columns', 'aa_ad_manager_ads_custom_columns');

function aa_ad_manager_ads_custom_column_content($column, $post_id) {
    if ($column === 'shortcode') {
        $campaign_terms = get_the_terms($post_id, 'aa_campaigns');
        $campaign = $campaign_terms && !is_wp_error($campaign_terms) ? $campaign_terms[0]->slug : '';

        $ad_size = function_exists('get_field') ? get_field('ad_size', $post_id) : '';
        $shortcode = '';

        if ($ad_size === 'wide') {
            $shortcode = '[aa_display_wide_ad campaign="' . esc_attr($campaign) . '"]';
        } elseif ($ad_size === 'square') {
            $shortcode = '[aa_display_square_ad campaign="' . esc_attr($campaign) . '"]';
        }

        echo '<span class="shortcode-text">' . esc_html($shortcode) . '</span>';
        echo ' <button type="button" class="button button-small copy-shortcode">Copy</button>';
        return;
    }

    if ($column === 'ad_image') {
        $image_id = 0;
        if (function_exists('get_field')) {
            $image_id = (int) get_field('ad_image', $post_id);
        }
        if (!$image_id) {
            $image_id = (int) get_post_meta($post_id, 'ad_image', true);
        }

        if ($image_id > 0) {
            echo wp_get_attachment_image(
                $image_id,
                'medium',
                false,
                array(
                    'style' => 'max-width:300px;max-height:300px;height:auto;display:block;',
                    'alt'   => '',
                )
            );
        } else {
            echo '&mdash;';
        }
        return;
    }
}
add_action('manage_aa_ads_posts_custom_column', 'aa_ad_manager_ads_custom_column_content', 10, 2);

/**
 * Make taxonomy columns sortable on the aa_ads list table.
 *
 * WP adds taxonomy columns as `taxonomy-{taxonomy}` when `show_admin_column` is true.
 * We map those to orderby keys (`aa_campaigns` / `aa_clients`) and handle the SQL.
 */
function aa_ad_manager_ads_sortable_columns($columns) {
    // Campaigns
    $columns['taxonomy-aa_campaigns'] = 'aa_campaigns';
    // Clients
    $columns['taxonomy-aa_clients'] = 'aa_clients';

    // Back-compat in case columns are customized elsewhere with different keys.
    if (isset($columns['campaigns'])) {
        $columns['campaigns'] = 'aa_campaigns';
    }
    if (isset($columns['clients'])) {
        $columns['clients'] = 'aa_clients';
    }

    return $columns;
}
add_filter('manage_edit-aa_ads_sortable_columns', 'aa_ad_manager_ads_sortable_columns');

/**
 * Flag main aa_ads list query when sorting by taxonomy term name.
 */
function aa_ad_manager_ads_pre_get_posts_tax_sort($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    $post_type = $query->get('post_type');
    if ($post_type !== 'aa_ads') {
        return;
    }

    $orderby = $query->get('orderby');
    if ($orderby === 'aa_campaigns' || $orderby === 'aa_clients') {
        $query->set('aa_tax_sort', $orderby);
        // Let posts_clauses provide the ORDER BY.
        $query->set('orderby', '');
    }
}
add_action('pre_get_posts', 'aa_ad_manager_ads_pre_get_posts_tax_sort');

/**
 * Apply SQL join/order to sort by the first taxonomy term name alphabetically.
 *
 * This sorts by MIN(term.name), which effectively uses the alphabetically-first term
 * for posts with multiple terms.
 */
function aa_ad_manager_ads_posts_clauses_tax_sort($clauses, $query) {
    if (!is_admin() || !$query->is_main_query()) {
        return $clauses;
    }

    $post_type = $query->get('post_type');
    if ($post_type !== 'aa_ads') {
        return $clauses;
    }

    $tax_key = $query->get('aa_tax_sort');
    $taxonomy = '';
    $alias_suffix = '';
    if ($tax_key === 'aa_campaigns') {
        $taxonomy = 'aa_campaigns';
        $alias_suffix = 'campaigns';
    } elseif ($tax_key === 'aa_clients') {
        $taxonomy = 'aa_clients';
        $alias_suffix = 'clients';
    } else {
        return $clauses;
    }

    global $wpdb;

    $order = strtoupper((string) $query->get('order')) === 'DESC' ? 'DESC' : 'ASC';

    $tr = "tr_aa_{$alias_suffix}";
    $tt = "tt_aa_{$alias_suffix}";
    $t  = "t_aa_{$alias_suffix}";

    $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} {$tr} ON ({$wpdb->posts}.ID = {$tr}.object_id)";
    $clauses['join'] .= $wpdb->prepare(
        " LEFT JOIN {$wpdb->term_taxonomy} {$tt} ON ({$tr}.term_taxonomy_id = {$tt}.term_taxonomy_id AND {$tt}.taxonomy = %s)",
        $taxonomy
    );
    $clauses['join'] .= " LEFT JOIN {$wpdb->terms} {$t} ON ({$tt}.term_id = {$t}.term_id)";

    // Ensure aggregation works deterministically.
    if (empty($clauses['groupby'])) {
        $clauses['groupby'] = "{$wpdb->posts}.ID";
    } elseif (strpos($clauses['groupby'], "{$wpdb->posts}.ID") === false) {
        $clauses['groupby'] .= ", {$wpdb->posts}.ID";
    }

    // Primary sort: alphabetically-first term name; secondary sort: post title (stable).
    $clauses['orderby'] = " MIN({$t}.name) {$order}, {$wpdb->posts}.post_title ASC ";

    return $clauses;
}
add_filter('posts_clauses', 'aa_ad_manager_ads_posts_clauses_tax_sort', 10, 2);

function aa_ad_manager_enqueue_admin_scripts($hook) {
    // Only on aa_ads list/edit screens.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) {
        return;
    }

    if ($screen->post_type !== 'aa_ads') {
        return;
    }

    wp_enqueue_script(
        'aa-admin-scripts',
        AA_AD_MANAGER_PLUGIN_URL . 'assets/js/ads/aa-admin-scripts.js',
        array('jquery'),
        AA_AD_MANAGER_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'aa_ad_manager_enqueue_admin_scripts');

