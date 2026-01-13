<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Select an eligible ad and return WP_Post or null.
 *
 * NOTE: Impression max enforcement is done against the impressions table (not post meta).
 */
function aa_get_weighted_random_ad($ad_size = 'random', $campaign = '') {
    $args = array(
        'post_type'      => 'aa_ads',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => array(
            'relation' => 'AND',
            array(
                'key'     => 'ad_status',
                'value'   => 'active',
                'compare' => '=',
            ),
        ),
    );

    if ($ad_size !== 'random') {
        $args['meta_query'][] = array(
            'key'     => 'ad_size',
            'value'   => sanitize_text_field($ad_size),
            'compare' => '=',
        );
    }

    if (!empty($campaign)) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'aa_campaigns',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($campaign),
            ),
        );
    }

    $ads = get_posts($args);

    if (empty($ads)) {
        return null;
    }

    $today = current_time('Ymd');
    $valid_ads = array();

    foreach ($ads as $ad) {
        if (!function_exists('get_field')) {
            // Without ACF we can't enforce date windows etc; best-effort: allow.
            $valid_ads[] = $ad;
            continue;
        }

        // Use unformatted values for reliable comparison (ACF stores date picker values as Ymd).
        $start = get_field('ad_start_date', $ad->ID, false);
        $end   = get_field('ad_end_date', $ad->ID, false);

        // ACF date fields are often stored as Ymd; compare lexicographically.
        $start_cmp = is_string($start) ? preg_replace('/[^0-9]/', '', $start) : '';
        $end_cmp   = is_string($end) ? preg_replace('/[^0-9]/', '', $end) : '';

        if (!empty($start_cmp) && $start_cmp > $today) {
            continue;
        }
        if (!empty($end_cmp) && $end_cmp < $today) {
            continue;
        }

        $impression_max = get_field('impression_max', $ad->ID);
        if (!empty($impression_max)) {
            $count = aa_ad_get_impression_count($ad->ID);
            if ($count >= (int) $impression_max) {
                continue;
            }
        }

        $valid_ads[] = $ad;
    }

    if (empty($valid_ads)) {
        return null;
    }

    $weighted = array();
    foreach ($valid_ads as $ad) {
        $frequency = 1;
        if (function_exists('get_field')) {
            $f = get_field('display_frequency', $ad->ID);
            $frequency = $f ? (int) $f : 1;
        }
        $frequency = max(1, $frequency);
        for ($i = 0; $i < $frequency; $i++) {
            $weighted[] = $ad;
        }
    }

    return !empty($weighted) ? $weighted[array_rand($weighted)] : null;
}

/**
 * Preserve theme behavior: default `ad_start_date` to today if empty.
 */
function aa_ad_manager_set_default_ad_start_date($value, $post_id, $field) {
    if (empty($value)) {
        $value = date('Y-m-d');
    }
    return $value;
}
add_filter('acf/load_value/name=ad_start_date', 'aa_ad_manager_set_default_ad_start_date', 10, 3);

