<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-only performance UI + metrics for aa_ads edit screen.
 *
 * This file is intentionally scoped to wp-admin screens for the aa_ads post type.
 */

/**
 * Get the currently selected aa_ads edit-screen tab.
 *
 * @return string 'fields'|'performance'
 */
function aa_ad_manager_get_current_ad_edit_tab() {
    $tab = isset($_GET['aa_tab']) ? sanitize_key((string) $_GET['aa_tab']) : '';
    if ($tab === 'performance') {
        return 'performance';
    }
    return 'fields';
}

/**
 * Determine whether we're on a single aa_ads post edit screen (post.php/post-new.php).
 *
 * @return bool
 */
function aa_ad_manager_is_ads_post_edit_screen() {
    if (!is_admin()) {
        return false;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) {
        return false;
    }

    if ($screen->post_type !== 'aa_ads') {
        return false;
    }

    // post.php (edit) and post-new.php (create).
    return ($screen->base === 'post');
}

/**
 * Render nav tabs on the aa_ads edit screen (below the title).
 */
function aa_ad_manager_render_ad_edit_tabs($post) {
    if (!($post instanceof WP_Post)) {
        return;
    }

    if (!aa_ad_manager_is_ads_post_edit_screen()) {
        return;
    }

    // Only render tabs for the aa_ads post type.
    if ($post->post_type !== 'aa_ads') {
        return;
    }

    // Only show tabs once the ad exists (post.php). Avoid post-new.php where ID isn't stable yet.
    if ((int) $post->ID <= 0 || !isset($_GET['post'])) {
        return;
    }

    $current = aa_ad_manager_get_current_ad_edit_tab();

    $base_args = array(
        'post'   => (int) $post->ID,
        'action' => 'edit',
    );

    $fields_url = add_query_arg(array_merge($base_args, array('aa_tab' => 'fields')), admin_url('post.php'));
    $perf_url   = add_query_arg(array_merge($base_args, array('aa_tab' => 'performance')), admin_url('post.php'));

    echo '<h2 class="nav-tab-wrapper aa-ad-manager-edit-tabs" style="margin-top: 12px;">';
    echo '<a class="nav-tab ' . ($current === 'fields' ? 'nav-tab-active' : '') . '" href="' . esc_url($fields_url) . '">AA Ads Fields</a>';
    echo '<a class="nav-tab ' . ($current === 'performance' ? 'nav-tab-active' : '') . '" href="' . esc_url($perf_url) . '">Performance</a>';
    echo '</h2>';
}
add_action('edit_form_after_title', 'aa_ad_manager_render_ad_edit_tabs', 20);

/**
 * Add a body class that reflects the current tab.
 */
function aa_ad_manager_admin_body_class_for_ad_tabs($classes) {
    if (!aa_ad_manager_is_ads_post_edit_screen()) {
        return $classes;
    }

    $tab = aa_ad_manager_get_current_ad_edit_tab();
    $classes .= ' aa-ad-tab--' . $tab;
    return $classes;
}
add_filter('admin_body_class', 'aa_ad_manager_admin_body_class_for_ad_tabs');

/**
 * Ensure the Performance metabox isn't hidden via Screen Options.
 *
 * WP stores hidden metabox IDs in a per-user option `metaboxhidden_{screen_id}`.
 * We remove our metabox from that list once per user so the default experience
 * includes the Performance panel without manual Screen Options changes.
 */
function aa_ad_manager_auto_unhide_performance_metabox($screen) {
    if (!($screen instanceof WP_Screen)) {
        return;
    }

    if ($screen->base !== 'post' || $screen->post_type !== 'aa_ads') {
        return;
    }

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return;
    }

    // One-time per user to avoid overriding a user's later choice to hide it again.
    if (get_user_meta($user_id, 'aa_ad_manager_perf_box_auto_enabled', true)) {
        return;
    }

    $option_key = 'metaboxhidden_' . $screen->id;
    $hidden = get_user_option($option_key, $user_id);
    if (!is_array($hidden)) {
        $hidden = array();
    }

    $idx = array_search('aa_ad_manager_performance', $hidden, true);
    if ($idx !== false) {
        unset($hidden[$idx]);
        $hidden = array_values($hidden);
        update_user_option($user_id, $option_key, $hidden, true);
    }

    update_user_meta($user_id, 'aa_ad_manager_perf_box_auto_enabled', '1');
}
add_action('current_screen', 'aa_ad_manager_auto_unhide_performance_metabox');

/**
 * Register the Performance metabox for aa_ads edit screen.
 */
function aa_ad_manager_add_ad_performance_meta_box() {
    add_meta_box(
        'aa_ad_manager_performance',
        'Performance',
        'aa_ad_manager_render_ad_performance_meta_box',
        'aa_ads',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_aa_ads', 'aa_ad_manager_add_ad_performance_meta_box');

/**
 * Render the Performance metabox.
 */
function aa_ad_manager_render_ad_performance_meta_box($post) {
    if (!($post instanceof WP_Post)) {
        return;
    }

    $ad_id = (int) $post->ID;

    // Range UI is controlled by JS; server-side default is last 30 days.
    echo '<div class="aa-perf" data-ad-id="' . (int) $ad_id . '">';

    echo '<div class="aa-perf__header">';
    echo '  <div class="aa-perf__title">';
    echo '    <strong>Impressions &amp; Clicks Over Time</strong> <span class="aa-perf__subtitle">(Last 30 Days)</span>';
    echo '  </div>';
    echo '  <div class="aa-perf__filters">';
    echo '    <label class="aa-perf__placement">';
    echo '      <span class="screen-reader-text">Select placement</span>';
    echo '      <select id="aa-perf-placement" name="aa_perf_placement" class="aa-perf__mini-select" aria-label="Placement filter">';
    echo '        <option value="" selected>All placements</option>';
    echo '      </select>';
    echo '    </label>';
    echo '  <label class="aa-perf__range">';
    echo '    <span class="screen-reader-text">Select date range</span>';
    echo '    <select id="aa-perf-range" name="aa_perf_range">';
    echo '      <option value="7">Last 7 Days</option>';
    echo '      <option value="30" selected>Last 30 Days</option>';
    echo '      <option value="90">Last 90 Days</option>';
    echo '      <option value="all">All-time</option>';
    echo '    </select>';
    echo '  </label>';
    echo '  </div>';
    echo '</div>';

    echo '<div class="aa-perf__grid aa-perf__grid--top">';
    echo '  <div class="aa-perf__card aa-perf__card--chart">';
    echo '    <canvas id="aa-perf-ic-chart" height="110" aria-label="Impressions and clicks chart" role="img"></canvas>';
    echo '    <div class="aa-perf__totals">';
    echo '      <div class="aa-perf__total"><span class="aa-perf__total-label">Total Impressions</span> <span id="aa-perf-total-impressions" class="aa-perf__total-value">—</span></div>';
    echo '      <div class="aa-perf__total"><span class="aa-perf__total-label">Total Clicks</span> <span id="aa-perf-total-clicks" class="aa-perf__total-value">—</span></div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<div class="aa-perf__grid aa-perf__grid--bottom">';
    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header">';
    echo '      <strong>CTR (Click-Through Rate) Over Time</strong> <span class="aa-perf__subtitle">(Last 30 Days)</span>';
    echo '    </div>';
    echo '    <canvas id="aa-perf-ctr-chart" height="140" aria-label="CTR chart" role="img"></canvas>';
    echo '    <div class="aa-perf__summary-row">';
    echo '      <div class="aa-perf__summary"><span class="aa-perf__summary-label">Average CTR</span> <span id="aa-perf-avg-ctr" class="aa-perf__summary-value">—</span></div>';
    echo '    </div>';
    echo '  </div>';

    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header aa-perf__card-header--with-select">';
    echo '      <strong>Top Pages by Clicks</strong> <span class="aa-perf__subtitle">(Last 30 Days)</span>';
    echo '      <select id="aa-perf-top-metric" class="aa-perf__mini-select" aria-label="Top pages metric">';
    echo '        <option value="clicks" selected>Clicks</option>';
    echo '        <option value="ctr">CTR</option>';
    echo '      </select>';
    echo '    </div>';
    echo '    <ol id="aa-perf-top-pages" class="aa-perf__list" aria-label="Top pages list"></ol>';
    echo '    <div class="aa-perf__mini-cards">';
    echo '      <div class="aa-perf__mini-card"><span class="aa-perf__mini-label">Top Page</span> <span id="aa-perf-top-page" class="aa-perf__mini-value">—</span></div>';
    echo '      <div class="aa-perf__mini-card"><span class="aa-perf__mini-label">Top CTR Page</span> <span id="aa-perf-top-ctr-page" class="aa-perf__mini-value">—</span></div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<div class="aa-perf__grid aa-perf__grid--placements">';
    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header">';
    echo '      <strong>Placement Breakdown</strong> <span class="aa-perf__subtitle">(Selected range)</span>';
    echo '    </div>';
    echo '    <div id="aa-perf-placement-breakdown" class="aa-perf__placement-breakdown" aria-label="Placement breakdown"></div>';
    echo '  </div>';
    echo '</div>';

    echo '<div class="aa-perf__status" id="aa-perf-status" aria-live="polite"></div>';

    echo '</div>';
}

/**
 * Register a Placements metabox for aa_ads edit screen (Fields tab only).
 */
function aa_ad_manager_add_ad_placements_meta_box() {
    add_meta_box(
        'aa_ad_manager_ad_placements',
        'Placements',
        'aa_ad_manager_render_ad_placements_meta_box',
        'aa_ads',
        'side',
        'high'
    );
}
add_action('add_meta_boxes_aa_ads', 'aa_ad_manager_add_ad_placements_meta_box');

/**
 * Get placements that have this ad assigned via ACF relationship field `assigned_ads`.
 *
 * @param int $ad_id
 * @return array<int,array{id:int,title:string,key:string,edit_url:string}>
 */
function aa_ad_manager_get_assigned_placements_for_ad($ad_id) {
    $ad_id = (int) $ad_id;
    if ($ad_id <= 0) {
        return array();
    }

    // ACF relationship values are stored serialized; search for quoted ID to avoid partial matches.
    $like = '"' . $ad_id . '"';

    $placements = get_posts(array(
        'post_type'      => 'aa_placement',
        'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => array(
            array(
                'key'     => 'assigned_ads',
                'value'   => $like,
                'compare' => 'LIKE',
            ),
        ),
    ));

    $out = array();
    if (is_array($placements)) {
        foreach ($placements as $p) {
            if (!($p instanceof WP_Post)) {
                continue;
            }
            $key = function_exists('get_field') ? (string) get_field('placement_key', $p->ID) : (string) get_post_meta($p->ID, 'placement_key', true);
            $key = trim($key);
            $out[] = array(
                'id'       => (int) $p->ID,
                'title'    => (string) get_the_title($p->ID),
                'key'      => $key,
                'edit_url' => (string) get_edit_post_link($p->ID, 'raw'),
            );
        }
    }

    return $out;
}

/**
 * Get delivered placements for an ad for the last N days (grouped by placement_key).
 *
 * @param int $ad_id
 * @param int $days
 * @param int $limit
 * @return array<int,array{placement_key:string,placement_id:int,placement_title:string,edit_url:string,impressions:int,clicks:int,ctr:(float|null)}>
 */
function aa_ad_manager_get_delivered_placements_for_ad($ad_id, $days = 30, $limit = 10) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $ad_id = (int) $ad_id;
    $days = max(1, (int) $days);
    $limit = max(1, (int) $limit);

    if ($ad_id <= 0) {
        return array();
    }

    $cutoff = date('Y-m-d H:i:s', (int) current_time('timestamp') - ($days * DAY_IN_SECONDS));

    $imp_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT placement_key, COUNT(*) AS impressions
             FROM {$tables['impressions']}
             WHERE ad_id = %d
               AND impressed_at >= %s
               AND placement_key <> ''
             GROUP BY placement_key
             ORDER BY impressions DESC
             LIMIT %d",
            $ad_id,
            $cutoff,
            $limit
        ),
        ARRAY_A
    );

    $clk_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT placement_key, COUNT(*) AS clicks
             FROM {$tables['clicks']}
             WHERE ad_id = %d
               AND clicked_at >= %s
               AND placement_key <> ''
             GROUP BY placement_key",
            $ad_id,
            $cutoff
        ),
        ARRAY_A
    );

    $clicks_by_key = array();
    if (is_array($clk_rows)) {
        foreach ($clk_rows as $row) {
            $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
            if ($k === '') {
                continue;
            }
            $clicks_by_key[$k] = isset($row['clicks']) ? (int) $row['clicks'] : 0;
        }
    }

    $ordered_keys = array();
    $by_key = array();
    if (is_array($imp_rows)) {
        foreach ($imp_rows as $row) {
            $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
            if ($k === '') {
                continue;
            }
            $ordered_keys[] = $k;
            $imps = isset($row['impressions']) ? (int) $row['impressions'] : 0;
            $clks = isset($clicks_by_key[$k]) ? (int) $clicks_by_key[$k] : 0;
            $ctr = null;
            if ($imps > 0) {
                $ctr = ($clks / $imps) * 100;
            }
            $by_key[$k] = array(
                'placement_key'   => $k,
                'placement_id'    => 0,
                'placement_title' => '',
                'edit_url'        => '',
                'impressions'     => $imps,
                'clicks'          => $clks,
                'ctr'             => $ctr,
            );
        }
    }

    // Resolve keys to placement posts (best-effort; batched).
    $keys = array_values(array_filter(array_unique($ordered_keys)));
    if (!empty($keys)) {
        $meta_query = array('relation' => 'OR');
        foreach ($keys as $k) {
            $meta_query[] = array(
                'key'   => 'placement_key',
                'value' => $k,
            );
        }

        $ids = get_posts(array(
            'post_type'      => 'aa_placement',
            'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ));

        if (is_array($ids)) {
            foreach ($ids as $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) {
                    continue;
                }
                $k = function_exists('get_field') ? (string) get_field('placement_key', $pid) : (string) get_post_meta($pid, 'placement_key', true);
                $k = trim($k);
                if ($k !== '' && isset($by_key[$k])) {
                    $by_key[$k]['placement_id'] = $pid;
                    $by_key[$k]['placement_title'] = (string) get_the_title($pid);
                    $by_key[$k]['edit_url'] = (string) get_edit_post_link($pid, 'raw');
                }
            }
        }
    }

    $out = array();
    foreach ($keys as $k) {
        if (isset($by_key[$k])) {
            $out[] = $by_key[$k];
        }
    }
    return $out;
}

/**
 * Render the Placements metabox.
 */
function aa_ad_manager_render_ad_placements_meta_box($post) {
    if (!($post instanceof WP_Post)) {
        return;
    }

    $ad_id = (int) $post->ID;
    if ($ad_id <= 0) {
        echo '<p>&mdash;</p>';
        return;
    }

    $assigned = aa_ad_manager_get_assigned_placements_for_ad($ad_id);
    $delivered = aa_ad_manager_get_delivered_placements_for_ad($ad_id, 30, 10);

    echo '<p style="margin-top:0;"><strong>Assigned placements</strong></p>';
    if (empty($assigned)) {
        echo '<p class="description" style="margin-top:0;">None</p>';
    } else {
        echo '<ul class="aa-placements-list">';
        foreach ($assigned as $pl) {
            $title = isset($pl['title']) ? (string) $pl['title'] : '';
            $url = isset($pl['edit_url']) ? (string) $pl['edit_url'] : '';
            $key = isset($pl['key']) ? (string) $pl['key'] : '';
            $label = $title ?: ($key ?: 'Placement');
            if ($url) {
                echo '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a>' . ($key ? ' <span class="description">(' . esc_html($key) . ')</span>' : '') . '</li>';
            } else {
                echo '<li>' . esc_html($label) . ($key ? ' <span class="description">(' . esc_html($key) . ')</span>' : '') . '</li>';
            }
        }
        echo '</ul>';
    }

    echo '<hr style="margin:10px 0;">';
    echo '<p style="margin-top:0;"><strong>Delivered placements (Last 30 days)</strong></p>';
    if (empty($delivered)) {
        echo '<p class="description" style="margin-top:0;">No placement activity in the last 30 days.</p>';
        if (!empty($assigned)) {
            echo '<p class="description" style="margin-top:0;"><em>Note:</em> This ad is assigned to one or more placements, but has not recorded placement delivery recently.</p>';
        }
    } else {
        echo '<ul class="aa-placements-delivered">';
        foreach ($delivered as $row) {
            $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
            $title = isset($row['placement_title']) ? (string) $row['placement_title'] : '';
            $url = isset($row['edit_url']) ? (string) $row['edit_url'] : '';
            $imps = isset($row['impressions']) ? (int) $row['impressions'] : 0;
            $clks = isset($row['clicks']) ? (int) $row['clicks'] : 0;
            $ctr = isset($row['ctr']) ? $row['ctr'] : null;

            $name = $title ?: ($k ?: 'Unknown');
            $meta = 'Imps: ' . (int) $imps . ' / Clicks: ' . (int) $clks;
            if ($ctr !== null) {
                $meta .= ' / CTR: ' . esc_html(number_format_i18n((float) $ctr, 2) . '%');
            }

            if ($url) {
                echo '<li><a href="' . esc_url($url) . '">' . esc_html($name) . '</a> <span class="description">(' . esc_html($k) . ')</span><br><span class="description">' . esc_html($meta) . '</span></li>';
            } else {
                echo '<li>' . esc_html($name) . ' <span class="description">(' . esc_html($k) . ')</span><br><span class="description">' . esc_html($meta) . '</span></li>';
            }
        }
        echo '</ul>';
    }
}

/**
 * Parse a range selector value.
 *
 * @param string $raw
 * @return array{type:string,days?:int}
 */
function aa_ad_manager_perf_parse_range($raw) {
    $raw = is_string($raw) ? sanitize_key($raw) : '';
    if ($raw === 'all') {
        return array('type' => 'all');
    }

    $days = (int) $raw;
    if ($days <= 0) {
        $days = 30;
    }

    // Cap to a sane window to avoid accidental huge scans in wp-admin.
    $days = min($days, 3650); // ~10 years

    return array(
        'type' => 'days',
        'days' => $days,
    );
}

/**
 * Build a MySQL datetime cutoff string in WP timezone.
 *
 * @param int $days
 * @return string
 */
function aa_ad_manager_perf_cutoff_mysql($days) {
    $days = max(1, (int) $days);
    $cutoff_ts = (int) current_time('timestamp') - ($days * DAY_IN_SECONDS);
    return date('Y-m-d H:i:s', $cutoff_ts);
}

/**
 * Get daily time series for impressions and clicks.
 *
 * @param int $ad_id
 * @param array{type:string,days?:int} $range
 * @param string $placement_key
 * @return array{labels:string[],impressions:int[],clicks:int[],ctr:(float|null)[]}
 */
function aa_ad_manager_perf_get_daily_series($ad_id, $range, $placement_key = '') {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $ad_id = (int) $ad_id;
    if ($ad_id <= 0) {
        return array('labels' => array(), 'impressions' => array(), 'clicks' => array(), 'ctr' => array());
    }

    $imp_where = "WHERE ad_id = %d";
    $clk_where = "WHERE ad_id = %d";
    $imp_params = array($ad_id);
    $clk_params = array($ad_id);

    $placement_key = is_string($placement_key) ? trim($placement_key) : '';
    if ($placement_key !== '') {
        $imp_where .= " AND placement_key = %s";
        $clk_where .= " AND placement_key = %s";
        $imp_params[] = $placement_key;
        $clk_params[] = $placement_key;
    }

    $start_date = null;
    $end_date = date('Y-m-d', (int) current_time('timestamp'));

    if (isset($range['type']) && $range['type'] === 'days') {
        $days = isset($range['days']) ? (int) $range['days'] : 30;
        $cutoff = aa_ad_manager_perf_cutoff_mysql($days);
        $imp_where .= " AND impressed_at >= %s";
        $clk_where .= " AND clicked_at >= %s";
        $imp_params[] = $cutoff;
        $clk_params[] = $cutoff;

        $start_date = date('Y-m-d', strtotime($cutoff));
    }

    $imp_sql = "
        SELECT DATE(impressed_at) AS d, COUNT(*) AS cnt
        FROM {$tables['impressions']}
        {$imp_where}
        GROUP BY DATE(impressed_at)
        ORDER BY d ASC
    ";

    $clk_sql = "
        SELECT DATE(clicked_at) AS d, COUNT(*) AS cnt
        FROM {$tables['clicks']}
        {$clk_where}
        GROUP BY DATE(clicked_at)
        ORDER BY d ASC
    ";

    $imp_rows = $wpdb->get_results($wpdb->prepare($imp_sql, $imp_params), ARRAY_A);
    $clk_rows = $wpdb->get_results($wpdb->prepare($clk_sql, $clk_params), ARRAY_A);

    $imp_map = array();
    if (is_array($imp_rows)) {
        foreach ($imp_rows as $row) {
            $d = isset($row['d']) ? (string) $row['d'] : '';
            if ($d) {
                $imp_map[$d] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
            }
        }
    }

    $clk_map = array();
    if (is_array($clk_rows)) {
        foreach ($clk_rows as $row) {
            $d = isset($row['d']) ? (string) $row['d'] : '';
            if ($d) {
                $clk_map[$d] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
            }
        }
    }

    // If there's no date window (all-time), only include dates present in either series.
    if (!$start_date) {
        $all_dates = array_unique(array_merge(array_keys($imp_map), array_keys($clk_map)));
        sort($all_dates);
        $labels = $all_dates;
    } else {
        // Build a continuous date range [start_date..end_date] inclusive.
        $labels = array();
        $start_ts = strtotime($start_date . ' 00:00:00');
        $end_ts = strtotime($end_date . ' 00:00:00');
        if ($start_ts !== false && $end_ts !== false && $start_ts <= $end_ts) {
            for ($ts = $start_ts; $ts <= $end_ts; $ts += DAY_IN_SECONDS) {
                $labels[] = date('Y-m-d', $ts);
            }
        }
    }

    $impressions = array();
    $clicks = array();
    $ctr = array();
    foreach ($labels as $d) {
        $i = isset($imp_map[$d]) ? (int) $imp_map[$d] : 0;
        $c = isset($clk_map[$d]) ? (int) $clk_map[$d] : 0;
        $impressions[] = $i;
        $clicks[] = $c;
        $ctr[] = $i > 0 ? (($c / $i) * 100) : null;
    }

    return array(
        'labels'      => $labels,
        'impressions' => $impressions,
        'clicks'      => $clicks,
        'ctr'         => $ctr,
    );
}

/**
 * Get totals for a range.
 *
 * @param int $ad_id
 * @param array{type:string,days?:int} $range
 * @param string $placement_key
 * @return array{impressions:int,clicks:int,ctr:(float|null)}
 */
function aa_ad_manager_perf_get_totals($ad_id, $range, $placement_key = '') {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $ad_id = (int) $ad_id;
    if ($ad_id <= 0) {
        return array('impressions' => 0, 'clicks' => 0, 'ctr' => null);
    }

    $imp_where = "WHERE ad_id = %d";
    $clk_where = "WHERE ad_id = %d";
    $imp_params = array($ad_id);
    $clk_params = array($ad_id);

    $placement_key = is_string($placement_key) ? trim($placement_key) : '';
    if ($placement_key !== '') {
        $imp_where .= " AND placement_key = %s";
        $clk_where .= " AND placement_key = %s";
        $imp_params[] = $placement_key;
        $clk_params[] = $placement_key;
    }

    if (isset($range['type']) && $range['type'] === 'days') {
        $days = isset($range['days']) ? (int) $range['days'] : 30;
        $cutoff = aa_ad_manager_perf_cutoff_mysql($days);
        $imp_where .= " AND impressed_at >= %s";
        $clk_where .= " AND clicked_at >= %s";
        $imp_params[] = $cutoff;
        $clk_params[] = $cutoff;
    }

    $imp = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['impressions']} {$imp_where}", $imp_params));
    $clk = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['clicks']} {$clk_where}", $clk_params));

    $ctr = null;
    if ($imp > 0) {
        $ctr = ($clk / $imp) * 100;
    }

    return array('impressions' => $imp, 'clicks' => $clk, 'ctr' => $ctr);
}

/**
 * Get top pages for an ad in a range.
 *
 * Hybrid keying:\n+ * - If `page_id` > 0: treat as a WP page/post and display title + permalink.\n+ * - Else: fall back to click `referer_url`.\n+ *
 * @param int $ad_id
 * @param array{type:string,days?:int} $range
 * @param int $limit
 * @param string $placement_key
 * @return array{items:array<int,array{label:string,url:string,clicks:int,impressions:int,ctr:(float|null)}>,top_page:string,top_ctr_page:string}
 */
function aa_ad_manager_perf_get_top_pages($ad_id, $range, $limit = 5, $placement_key = '') {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $ad_id = (int) $ad_id;
    $limit = max(1, (int) $limit);

    if ($ad_id <= 0) {
        return array('items' => array(), 'top_page' => '', 'top_ctr_page' => '');
    }

    $clk_where = "WHERE ad_id = %d";
    $clk_params = array($ad_id);
    $imp_where = "WHERE ad_id = %d";
    $imp_params = array($ad_id);

    $placement_key = is_string($placement_key) ? trim($placement_key) : '';
    if ($placement_key !== '') {
        $clk_where .= " AND placement_key = %s";
        $imp_where .= " AND placement_key = %s";
        $clk_params[] = $placement_key;
        $imp_params[] = $placement_key;
    }

    if (isset($range['type']) && $range['type'] === 'days') {
        $days = isset($range['days']) ? (int) $range['days'] : 30;
        $cutoff = aa_ad_manager_perf_cutoff_mysql($days);
        $clk_where .= " AND clicked_at >= %s";
        $imp_where .= " AND impressed_at >= %s";
        $clk_params[] = $cutoff;
        $imp_params[] = $cutoff;
    }

    $clk_sql = "
        SELECT page_id, referer_url, COUNT(*) AS clicks
        FROM {$tables['clicks']}
        {$clk_where}
        GROUP BY page_id, referer_url
        ORDER BY clicks DESC
        LIMIT %d
    ";
    $clk_params_with_limit = array_merge($clk_params, array($limit * 4)); // fetch more to support CTR sort client-side
    $clk_rows = $wpdb->get_results($wpdb->prepare($clk_sql, $clk_params_with_limit), ARRAY_A);

    $imp_sql = "
        SELECT page_id, COUNT(*) AS impressions
        FROM {$tables['impressions']}
        {$imp_where}
        GROUP BY page_id
    ";
    $imp_rows = $wpdb->get_results($wpdb->prepare($imp_sql, $imp_params), ARRAY_A);

    $imp_by_page = array();
    if (is_array($imp_rows)) {
        foreach ($imp_rows as $row) {
            $pid = isset($row['page_id']) ? (int) $row['page_id'] : 0;
            $cnt = isset($row['impressions']) ? (int) $row['impressions'] : 0;
            $imp_by_page[$pid] = $cnt;
        }
    }

    // Aggregate click groups so page_id>0 isn't fragmented by referer_url differences.
    $click_agg = array();
    if (is_array($clk_rows)) {
        foreach ($clk_rows as $row) {
            $page_id = isset($row['page_id']) ? (int) $row['page_id'] : 0;
            $referer = isset($row['referer_url']) ? (string) $row['referer_url'] : '';
            $clicks  = isset($row['clicks']) ? (int) $row['clicks'] : 0;

            $key = $page_id > 0 ? ('page:' . $page_id) : ('url:' . $referer);
            if (!isset($click_agg[$key])) {
                $click_agg[$key] = array(
                    'page_id' => $page_id,
                    'referer' => $referer,
                    'clicks'  => 0,
                );
            }
            $click_agg[$key]['clicks'] += $clicks;
        }
    }

    $items = array();
    foreach ($click_agg as $entry) {
        $page_id = isset($entry['page_id']) ? (int) $entry['page_id'] : 0;
        $referer = isset($entry['referer']) ? (string) $entry['referer'] : '';
        $clicks  = isset($entry['clicks']) ? (int) $entry['clicks'] : 0;

        $label = '';
        $url = '';
        $impressions = 0;

        if ($page_id > 0) {
            $title = get_the_title($page_id);
            $label = $title ? (string) $title : ('#' . $page_id);
            $url = (string) get_permalink($page_id);
            $impressions = isset($imp_by_page[$page_id]) ? (int) $imp_by_page[$page_id] : 0;
        } else {
            $url = $referer;
            if ($referer) {
                $parts = wp_parse_url($referer);
                if (is_array($parts)) {
                    $host = isset($parts['host']) ? (string) $parts['host'] : '';
                    $path = isset($parts['path']) ? (string) $parts['path'] : '';
                    $label = trim($host . $path);
                }
            }
            if (!$label) {
                $label = 'Unknown';
            }
            // Impressions for referer-only rows are unknown (impressions table has no referer_url).
            $impressions = 0;
        }

        $ctr = null;
        if ($impressions > 0) {
            $ctr = ($clicks / $impressions) * 100;
        }

        $items[] = array(
            'label'       => $label,
            'url'         => $url,
            'clicks'      => $clicks,
            'impressions' => $impressions,
            'ctr'         => $ctr,
        );
    }

    usort($items, static function ($a, $b) {
        return ((int) $b['clicks']) <=> ((int) $a['clicks']);
    });

    // Trim to requested limit for initial display; we fetched extra for optional client-side sorting.
    $display_items = array_slice($items, 0, $limit);

    $top_page = '';
    if (!empty($display_items)) {
        $top_page = (string) $display_items[0]['label'];
    }

    $min_impressions_for_ctr = 10;
    $top_ctr_page = '';
    $best_ctr = null;
    foreach ($items as $it) {
        $imp = isset($it['impressions']) ? (int) $it['impressions'] : 0;
        $c = isset($it['ctr']) ? $it['ctr'] : null;
        if ($imp < $min_impressions_for_ctr || $c === null) {
            continue;
        }
        if ($best_ctr === null || (float) $c > (float) $best_ctr) {
            $best_ctr = (float) $c;
            $top_ctr_page = (string) $it['label'];
        }
    }

    return array(
        'items'        => $items,
        'top_page'     => $top_page,
        'top_ctr_page' => $top_ctr_page,
    );
}

/**
 * Get placement breakdown for an ad (grouped by placement_key) in a range.
 *
 * This intentionally ignores any placement filter (it shows all placements for the ad).
 *
 * @param int $ad_id
 * @param array{type:string,days?:int} $range
 * @param int $limit
 * @return array<int,array{placement_key:string,placement_id:int,placement_title:string,edit_url:string,impressions:int,clicks:int,ctr:(float|null)}>
 */
function aa_ad_manager_perf_get_placement_breakdown($ad_id, $range, $limit = 10) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $ad_id = (int) $ad_id;
    $limit = max(1, (int) $limit);
    if ($ad_id <= 0) {
        return array();
    }

    $imp_where = "WHERE ad_id = %d AND placement_key <> ''";
    $clk_where = "WHERE ad_id = %d AND placement_key <> ''";
    $imp_params = array($ad_id);
    $clk_params = array($ad_id);

    if (isset($range['type']) && $range['type'] === 'days') {
        $days = isset($range['days']) ? (int) $range['days'] : 30;
        $cutoff = aa_ad_manager_perf_cutoff_mysql($days);
        $imp_where .= " AND impressed_at >= %s";
        $clk_where .= " AND clicked_at >= %s";
        $imp_params[] = $cutoff;
        $clk_params[] = $cutoff;
    }

    $imp_sql = "
        SELECT placement_key, COUNT(*) AS impressions
        FROM {$tables['impressions']}
        {$imp_where}
        GROUP BY placement_key
        ORDER BY impressions DESC
        LIMIT %d
    ";
    $imp_rows = $wpdb->get_results($wpdb->prepare($imp_sql, array_merge($imp_params, array($limit))), ARRAY_A);

    $clk_sql = "
        SELECT placement_key, COUNT(*) AS clicks
        FROM {$tables['clicks']}
        {$clk_where}
        GROUP BY placement_key
    ";
    $clk_rows = $wpdb->get_results($wpdb->prepare($clk_sql, $clk_params), ARRAY_A);

    $clicks_by_key = array();
    if (is_array($clk_rows)) {
        foreach ($clk_rows as $row) {
            $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
            if ($k === '') {
                continue;
            }
            $clicks_by_key[$k] = isset($row['clicks']) ? (int) $row['clicks'] : 0;
        }
    }

    $ordered_keys = array();
    $by_key = array();
    if (is_array($imp_rows)) {
        foreach ($imp_rows as $row) {
            $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
            if ($k === '') {
                continue;
            }
            $ordered_keys[] = $k;
            $imps = isset($row['impressions']) ? (int) $row['impressions'] : 0;
            $clks = isset($clicks_by_key[$k]) ? (int) $clicks_by_key[$k] : 0;
            $ctr = null;
            if ($imps > 0) {
                $ctr = ($clks / $imps) * 100;
            }
            $by_key[$k] = array(
                'placement_key'   => $k,
                'placement_id'    => 0,
                'placement_title' => '',
                'edit_url'        => '',
                'impressions'     => $imps,
                'clicks'          => $clks,
                'ctr'             => $ctr,
            );
        }
    }

    $keys = array_values(array_filter(array_unique($ordered_keys)));
    if (!empty($keys)) {
        $meta_query = array('relation' => 'OR');
        foreach ($keys as $k) {
            $meta_query[] = array(
                'key'   => 'placement_key',
                'value' => $k,
            );
        }

        $ids = get_posts(array(
            'post_type'      => 'aa_placement',
            'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ));

        if (is_array($ids)) {
            foreach ($ids as $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) {
                    continue;
                }
                $k = function_exists('get_field') ? (string) get_field('placement_key', $pid) : (string) get_post_meta($pid, 'placement_key', true);
                $k = trim($k);
                if ($k !== '' && isset($by_key[$k])) {
                    $by_key[$k]['placement_id'] = $pid;
                    $by_key[$k]['placement_title'] = (string) get_the_title($pid);
                    $by_key[$k]['edit_url'] = (string) get_edit_post_link($pid, 'raw');
                }
            }
        }
    }

    $out = array();
    foreach ($keys as $k) {
        if (isset($by_key[$k])) {
            $out[] = $by_key[$k];
        }
    }
    return $out;
}

/**
 * Build the full performance payload for an ad.
 *
 * @param int $ad_id
 * @param array{type:string,days?:int} $range
 * @param string $placement_key
 * @return array<string,mixed>
 */
function aa_ad_manager_get_ad_performance_data($ad_id, $range, $placement_key = '') {
    $ad_id = (int) $ad_id;
    $placement_key = is_string($placement_key) ? trim($placement_key) : '';

    // Dropdown options are based on recent delivered placements, with assigned placements as fallback.
    $placement_options = array();
    if (function_exists('aa_ad_manager_get_delivered_placements_for_ad')) {
        $delivered = aa_ad_manager_get_delivered_placements_for_ad($ad_id, 30, 50);
        if (is_array($delivered)) {
            foreach ($delivered as $row) {
                $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
                if ($k === '') {
                    continue;
                }
                $label = isset($row['placement_title']) && $row['placement_title'] ? (string) $row['placement_title'] : $k;
                $placement_options[$k] = $label;
            }
        }
    }
    if (empty($placement_options) && function_exists('aa_ad_manager_get_assigned_placements_for_ad')) {
        $assigned = aa_ad_manager_get_assigned_placements_for_ad($ad_id);
        if (is_array($assigned)) {
            foreach ($assigned as $pl) {
                $k = isset($pl['key']) ? (string) $pl['key'] : '';
                if ($k === '') {
                    continue;
                }
                $label = isset($pl['title']) && $pl['title'] ? (string) $pl['title'] : $k;
                $placement_options[$k] = $label;
            }
        }
    }

    $totals = aa_ad_manager_perf_get_totals($ad_id, $range, $placement_key);
    $series = aa_ad_manager_perf_get_daily_series($ad_id, $range, $placement_key);
    $top = aa_ad_manager_perf_get_top_pages($ad_id, $range, 5, $placement_key);
    $breakdown = aa_ad_manager_perf_get_placement_breakdown($ad_id, $range, 10);

    $avg_ctr = null;
    if (isset($totals['ctr'])) {
        $avg_ctr = $totals['ctr'];
    }

    return array(
        'ad_id'   => $ad_id,
        'range'   => $range,
        'placement_key' => $placement_key,
        'placement_options' => $placement_options,
        'placement_breakdown' => $breakdown,
        'totals'  => $totals,
        'series'  => $series,
        'top'     => $top,
        'avg_ctr' => $avg_ctr,
    );
}

/**
 * Admin AJAX: fetch performance payload for a single ad + range.
 */
function aa_ad_manager_ajax_get_ad_performance() {
    $nonce = isset($_REQUEST['nonce']) ? (string) $_REQUEST['nonce'] : '';
    if (!wp_verify_nonce($nonce, 'aa_ad_perf_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'), 403);
    }

    $ad_id = isset($_REQUEST['ad_id']) ? (int) $_REQUEST['ad_id'] : 0;
    if ($ad_id <= 0) {
        wp_send_json_error(array('message' => 'Missing ad_id'), 400);
    }

    if (!current_user_can('edit_post', $ad_id)) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }

    $range_raw = isset($_REQUEST['range']) ? (string) $_REQUEST['range'] : '30';
    $range = aa_ad_manager_perf_parse_range($range_raw);

    $placement_key = isset($_REQUEST['placement_key']) ? sanitize_text_field((string) $_REQUEST['placement_key']) : '';
    $placement_key = trim($placement_key);

    $payload = aa_ad_manager_get_ad_performance_data($ad_id, $range, $placement_key);
    wp_send_json_success($payload);
}
add_action('wp_ajax_aa_ad_manager_get_ad_performance', 'aa_ad_manager_ajax_get_ad_performance');

