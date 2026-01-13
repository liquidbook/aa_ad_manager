<?php

if (!defined('ABSPATH')) {
    exit;
}

function aa_ad_manager_enqueue_loader() {
    wp_enqueue_script(
        'aa-ad-loader',
        AA_AD_MANAGER_PLUGIN_URL . 'assets/js/ads/aa-ad-loader.js',
        array('jquery'),
        AA_AD_MANAGER_VERSION,
        true
    );

    wp_localize_script('aa-ad-loader', 'aaAdSettings', array(
        'ajax_url'        => admin_url('admin-ajax.php'),
        'nonce_get_ad'    => wp_create_nonce('aa_ad_nonce'),
        'nonce_log_click' => wp_create_nonce('aa_ad_click_nonce'),
    ));
}

function aa_ad_manager_compute_page_context() {
    $page_type = 'singular';
    $page_context = '';

    if (is_search()) {
        $page_type = 'search';
        $page_context = get_search_query();
    } elseif (is_home() || is_front_page()) {
        $page_type = 'home';
        $page_context = 'home_index';
    } else {
        $all_public_post_types = get_post_types(array('public' => true), 'names');
        foreach ($all_public_post_types as $pt) {
            if (is_post_type_archive($pt)) {
                $page_type = 'post_type_archive';
                $page_context = $pt;
                break;
            }
        }

        if ($page_type === 'singular' && is_archive()) {
            $page_type = 'archive';
            $page_context = 'general_archive';
        }
    }

    return array($page_type, $page_context);
}

function aa_ad_manager_shortcode_placeholder($ad_size, $campaign) {
    aa_ad_manager_enqueue_loader();

    $container_id = 'aa-ad-container-' . uniqid();

    $page_id = get_the_ID();
    if (!$page_id) {
        $page_id = get_queried_object_id();
    }

    list($page_type, $page_context) = aa_ad_manager_compute_page_context();

    return '<div id="' . esc_attr($container_id) . '" class="aa-ad-container"
                data-ad-size="' . esc_attr($ad_size) . '"
                data-campaign="' . esc_attr($campaign) . '"
                data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"
                data-page-id="' . esc_attr($page_id) . '"
                data-page-type="' . esc_attr($page_type) . '"
                data-page-context="' . esc_attr($page_context) . '"></div>';
}

function aa_display_wide_ad_shortcode($atts) {
    $atts = shortcode_atts(array(
        'campaign' => '',
    ), $atts, 'aa_display_wide_ad');

    return aa_ad_manager_shortcode_placeholder('wide', $atts['campaign']);
}
add_shortcode('aa_display_wide_ad', 'aa_display_wide_ad_shortcode');

function aa_display_square_ad_shortcode($atts) {
    $atts = shortcode_atts(array(
        'campaign' => '',
    ), $atts, 'aa_display_square_ad');

    return aa_ad_manager_shortcode_placeholder('square', $atts['campaign']);
}
add_shortcode('aa_display_square_ad', 'aa_display_square_ad_shortcode');

