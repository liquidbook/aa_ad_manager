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
    return $columns;
}
add_filter('manage_aa_ads_posts_columns', 'aa_ad_manager_ads_custom_columns');

function aa_ad_manager_ads_custom_column_content($column, $post_id) {
    if ($column !== 'shortcode') {
        return;
    }

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
}
add_action('manage_aa_ads_posts_custom_column', 'aa_ad_manager_ads_custom_column_content', 10, 2);

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

