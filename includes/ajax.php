<?php

if (!defined('ABSPATH')) {
    exit;
}

function aa_ad_manager_ajax_get_ad() {
    if (!isset($_REQUEST['security']) || !wp_verify_nonce($_REQUEST['security'], 'aa_ad_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'), 403);
    }

    $ad_size  = isset($_REQUEST['ad_size']) ? sanitize_text_field($_REQUEST['ad_size']) : 'wide';
    $campaign = isset($_REQUEST['campaign']) ? sanitize_text_field($_REQUEST['campaign']) : '';
    $placement_key = isset($_REQUEST['placement_key']) ? sanitize_text_field($_REQUEST['placement_key']) : '';
    $page_id  = isset($_REQUEST['page_id']) ? (int) $_REQUEST['page_id'] : 0;

    // Accept but ignore (schema out of scope).
    // $page_type = isset($_REQUEST['page_type']) ? sanitize_text_field($_REQUEST['page_type']) : '';
    // $page_context = isset($_REQUEST['page_context']) ? sanitize_text_field($_REQUEST['page_context']) : '';

    $ad = null;

    if (!empty($placement_key)) {
        $placement_id = function_exists('aa_ad_manager_get_placement_id_by_key')
            ? aa_ad_manager_get_placement_id_by_key($placement_key)
            : 0;

        if ($placement_id > 0) {
            $active = function_exists('get_field')
                ? (bool) get_field('placement_active', $placement_id)
                : (bool) get_post_meta($placement_id, 'placement_active', true);

            if ($active) {
                $assigned = function_exists('get_field')
                    ? get_field('assigned_ads', $placement_id)
                    : get_post_meta($placement_id, 'assigned_ads', true);

                $assigned_ids = array();
                if (is_array($assigned)) {
                    foreach ($assigned as $v) {
                        if (is_object($v) && isset($v->ID)) {
                            $assigned_ids[] = (int) $v->ID;
                        } else {
                            $assigned_ids[] = (int) $v;
                        }
                    }
                }
                $assigned_ids = array_values(array_filter(array_unique(array_map('intval', $assigned_ids))));

                if (!empty($assigned_ids) && function_exists('aa_get_weighted_random_ad_from_ids')) {
                    $ad = aa_get_weighted_random_ad_from_ids($assigned_ids, $ad_size);
                }
            }
        }
    } else {
        $ad = aa_get_weighted_random_ad($ad_size, $campaign);
    }

    if (!$ad) {
        wp_send_json_error(array('message' => 'No eligible ad found'), 404);
    }

    if (function_exists('get_field')) {
        $ad_image_id = get_field('ad_image', $ad->ID);
        $ad_title    = get_field('ad_title', $ad->ID);
        $ad_link     = get_field('ad_link', $ad->ID);
        $new_tab     = (bool) get_field('ad_new_tab', $ad->ID);
    } else {
        $ad_image_id = 0;
        $ad_title = get_the_title($ad->ID);
        $ad_link = '';
        $new_tab = false;
    }

    if (empty($ad_link) || !filter_var($ad_link, FILTER_VALIDATE_URL)) {
        wp_send_json_error(array('message' => 'Ad link invalid'), 500);
    }

    $img_src = '';
    if (!empty($ad_image_id)) {
        $img = wp_get_attachment_image_src((int) $ad_image_id, 'full');
        if (is_array($img) && !empty($img[0])) {
            $img_src = $img[0];
        }
    }

    if (empty($img_src)) {
        wp_send_json_error(array('message' => 'Ad image missing'), 500);
    }

    aa_ad_log_impression($ad->ID, $page_id, $placement_key);

    $target_attr = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

    $ad_html = sprintf(
        '<a href="%s" class="aa-ad-click" data-ad-id="%d" data-page-id="%d" data-placement-key="%s"%s><img src="%s" alt="%s"></a>',
        esc_url($ad_link),
        (int) $ad->ID,
        (int) $page_id,
        esc_attr($placement_key),
        $target_attr,
        esc_url($img_src),
        esc_attr($ad_title ? $ad_title : get_the_title($ad->ID))
    );

    wp_send_json_success(array('ad_html' => $ad_html));
}
add_action('wp_ajax_nopriv_aa_get_ad', 'aa_ad_manager_ajax_get_ad');
add_action('wp_ajax_aa_get_ad', 'aa_ad_manager_ajax_get_ad');

function aa_ad_manager_ajax_log_click() {
    if (!isset($_REQUEST['security']) || !wp_verify_nonce($_REQUEST['security'], 'aa_ad_click_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'), 403);
    }

    $ad_id      = isset($_REQUEST['ad_id']) ? (int) $_REQUEST['ad_id'] : 0;
    $page_id    = isset($_REQUEST['page_id']) ? (int) $_REQUEST['page_id'] : 0;
    $placement_key = isset($_REQUEST['placement_key']) ? sanitize_text_field($_REQUEST['placement_key']) : '';
    $referer_url = isset($_REQUEST['referer_url']) ? esc_url_raw($_REQUEST['referer_url']) : '';

    // Accept but ignore (schema out of scope).
    // $page_type = isset($_REQUEST['page_type']) ? sanitize_text_field($_REQUEST['page_type']) : '';
    // $page_context = isset($_REQUEST['page_context']) ? sanitize_text_field($_REQUEST['page_context']) : '';

    if ($ad_id <= 0) {
        wp_send_json_error(array('message' => 'Missing ad_id'), 400);
    }

    aa_ad_log_click($ad_id, $page_id, $referer_url, $placement_key);

    wp_send_json_success(array('ok' => true));
}
add_action('wp_ajax_nopriv_aa_log_click', 'aa_ad_manager_ajax_log_click');
add_action('wp_ajax_aa_log_click', 'aa_ad_manager_ajax_log_click');

