<?php

if (!defined('ABSPATH')) {
    exit;
}

function aa_ad_manager_enqueue_loader() {
    wp_enqueue_style(
        'aa-ad-manager-frontend',
        AA_AD_MANAGER_PLUGIN_URL . 'assets/css/frontend.css',
        array(),
        AA_AD_MANAGER_VERSION
    );

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

function aa_ad_manager_shortcode_placeholder($ad_size, $campaign, $placement_key = '') {
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
                data-placement-key="' . esc_attr($placement_key) . '"
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

/**
 * Placements v1 shortcode: [aa_slot key="PLACEMENT_KEY"].
 */
function aa_ad_manager_get_placement_id_by_key($placement_key) {
    $placement_key = is_string($placement_key) ? trim($placement_key) : '';
    if ($placement_key === '') {
        return 0;
    }

    $cache_key = 'aa_placement_id_by_key:' . $placement_key;
    $cached = wp_cache_get($cache_key, 'aa_ad_manager');
    if ($cached !== false) {
        return (int) $cached;
    }

    $ids = get_posts(array(
        'post_type'      => 'aa_placement',
        'post_status'    => array('publish', 'draft', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'   => 'placement_key',
                'value' => $placement_key,
            ),
        ),
    ));

    $id = !empty($ids) ? (int) $ids[0] : 0;
    wp_cache_set($cache_key, $id, 'aa_ad_manager', 10 * MINUTE_IN_SECONDS);
    return $id;
}

function aa_slot_shortcode($atts) {
    $atts = shortcode_atts(array(
        'key' => '',
    ), $atts, 'aa_slot');

    $placement_key = is_string($atts['key']) ? trim($atts['key']) : '';
    if ($placement_key === '') {
        return (defined('WP_DEBUG') && WP_DEBUG) ? '<!-- aa_slot: missing key -->' : '';
    }

    $placement_id = aa_ad_manager_get_placement_id_by_key($placement_key);
    if ($placement_id <= 0) {
        return (defined('WP_DEBUG') && WP_DEBUG) ? '<!-- aa_slot: placement not found -->' : '';
    }

    $active = function_exists('get_field')
        ? (bool) get_field('placement_active', $placement_id)
        : (bool) get_post_meta($placement_id, 'placement_active', true);
    if (!$active) {
        return (defined('WP_DEBUG') && WP_DEBUG) ? '<!-- aa_slot: placement inactive -->' : '';
    }

    $placement_size = function_exists('get_field')
        ? (string) get_field('placement_size', $placement_id)
        : (string) get_post_meta($placement_id, 'placement_size', true);
    $placement_size = trim($placement_size);

    $ad_size = 'random';
    if ($placement_size === 'wide' || $placement_size === 'square') {
        $ad_size = $placement_size;
    }

    // Campaign stays blank for placements; placement_key drives server-side selection.
    return aa_ad_manager_shortcode_placeholder($ad_size, '', $placement_key);
}
add_shortcode('aa_slot', 'aa_slot_shortcode');

