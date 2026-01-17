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
    $columns['placements'] = 'Placements';
    $columns['aa_stats'] = 'Statistics';
    $columns['ad_image'] = 'Ad Image';
    return $columns;
}
add_filter('manage_aa_ads_posts_columns', 'aa_ad_manager_ads_custom_columns');

/**
 * Prefetch placements assigned to ads shown on the aa_ads list table.
 *
 * Avoids per-row queries by scanning the placements relationship meta once per
 * list page and building an in-memory mapping of ad_id => placements[].
 *
 * @return array{by_ad:array<int,array<int,array{id:int,title:string,edit_url:string}>>}
 */
function aa_ad_manager_ads_list_table_placements_cache() {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = array('by_ad' => array());

    if (!is_admin()) {
        return $cache;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'aa_ads' || $screen->base !== 'edit') {
        return $cache;
    }

    global $wp_query, $wpdb;
    if (empty($wp_query) || empty($wp_query->posts) || !is_array($wp_query->posts)) {
        return $cache;
    }

    $ad_ids = array();
    foreach ($wp_query->posts as $post) {
        if (is_object($post) && isset($post->ID)) {
            $ad_ids[] = (int) $post->ID;
        }
    }
    $ad_ids = array_values(array_filter(array_unique($ad_ids)));
    if (empty($ad_ids)) {
        return $cache;
    }

    // Find placements that reference any of these ad IDs.
    $like_clauses = array();
    $params = array();
    foreach ($ad_ids as $id) {
        $like_clauses[] = "pm.meta_value LIKE %s";
        $params[] = '%"' . (int) $id . '"%';
    }

    $where_like = implode(' OR ', $like_clauses);

    $sql = "
        SELECT p.ID AS placement_id, pm.meta_value
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
           AND pm.meta_key = 'assigned_ads'
        WHERE p.post_type = 'aa_placement'
          AND p.post_status IN ('publish','draft','pending','future','private')
          AND ($where_like)
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
        return $cache;
    }

    $ad_set = array_fill_keys($ad_ids, true);
    $placements_seen = array();

    foreach ($rows as $row) {
        $pid = isset($row['placement_id']) ? (int) $row['placement_id'] : 0;
        if ($pid <= 0) {
            continue;
        }

        $raw = isset($row['meta_value']) ? $row['meta_value'] : '';
        $assigned = maybe_unserialize($raw);
        if (!is_array($assigned)) {
            continue;
        }

        foreach ($assigned as $aid) {
            $aid = (int) $aid;
            if ($aid <= 0 || !isset($ad_set[$aid])) {
                continue;
            }
            if (!isset($cache['by_ad'][$aid])) {
                $cache['by_ad'][$aid] = array();
            }
            // We'll attach title/edit_url later; store placement IDs for now.
            $cache['by_ad'][$aid][$pid] = array('id' => $pid, 'title' => '', 'edit_url' => '');
            $placements_seen[$pid] = true;
        }
    }

    if (empty($placements_seen)) {
        return $cache;
    }

    foreach (array_keys($placements_seen) as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        $title = (string) get_the_title($pid);
        $edit_url = (string) get_edit_post_link($pid, 'raw');

        foreach ($cache['by_ad'] as $aid => $placements) {
            if (isset($cache['by_ad'][$aid][$pid])) {
                $cache['by_ad'][$aid][$pid]['title'] = $title;
                $cache['by_ad'][$aid][$pid]['edit_url'] = $edit_url;
            }
        }
    }

    // Normalize to numeric arrays per ad.
    foreach ($cache['by_ad'] as $aid => $placements) {
        $cache['by_ad'][$aid] = array_values($placements);
    }

    return $cache;
}

/**
 * Prefetch impression/click counts for ads shown on the aa_ads list table.
 *
 * Avoids per-row DB queries by running at most 2 grouped queries for the current
 * list page (impressions + clicks) and caching results in-memory for render.
 */
function aa_ad_manager_ads_list_table_stats_cache() {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = array(
        'impressions' => array(),
        'clicks'      => array(),
    );

    if (!is_admin()) {
        return $cache;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'aa_ads' || $screen->base !== 'edit') {
        return $cache;
    }

    global $wp_query, $wpdb;
    if (empty($wp_query) || empty($wp_query->posts) || !is_array($wp_query->posts)) {
        return $cache;
    }

    $ad_ids = array();
    foreach ($wp_query->posts as $post) {
        if (is_object($post) && isset($post->ID)) {
            $ad_ids[] = (int) $post->ID;
        }
    }
    $ad_ids = array_values(array_filter(array_unique($ad_ids)));
    if (empty($ad_ids)) {
        return $cache;
    }

    $tables = function_exists('aa_ad_manager_tables')
        ? aa_ad_manager_tables()
        : array(
            'impressions' => $wpdb->prefix . 'aa_ad_impressions',
            'clicks'      => $wpdb->prefix . 'aa_ad_clicks',
        );

    $placeholders = implode(',', array_fill(0, count($ad_ids), '%d'));

    $impressions_sql = "SELECT ad_id, COUNT(*) AS cnt
        FROM {$tables['impressions']}
        WHERE ad_id IN ($placeholders)
        GROUP BY ad_id";
    $impressions_rows = $wpdb->get_results($wpdb->prepare($impressions_sql, $ad_ids), ARRAY_A);
    if (is_array($impressions_rows)) {
        foreach ($impressions_rows as $row) {
            $ad_id = isset($row['ad_id']) ? (int) $row['ad_id'] : 0;
            $cnt   = isset($row['cnt']) ? (int) $row['cnt'] : 0;
            if ($ad_id > 0) {
                $cache['impressions'][$ad_id] = $cnt;
            }
        }
    }

    $clicks_sql = "SELECT ad_id, COUNT(*) AS cnt
        FROM {$tables['clicks']}
        WHERE ad_id IN ($placeholders)
        GROUP BY ad_id";
    $clicks_rows = $wpdb->get_results($wpdb->prepare($clicks_sql, $ad_ids), ARRAY_A);
    if (is_array($clicks_rows)) {
        foreach ($clicks_rows as $row) {
            $ad_id = isset($row['ad_id']) ? (int) $row['ad_id'] : 0;
            $cnt   = isset($row['cnt']) ? (int) $row['cnt'] : 0;
            if ($ad_id > 0) {
                $cache['clicks'][$ad_id] = $cnt;
            }
        }
    }

    return $cache;
}

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

    if ($column === 'placements') {
        $cache = aa_ad_manager_ads_list_table_placements_cache();
        $placements = isset($cache['by_ad'][$post_id]) ? (array) $cache['by_ad'][$post_id] : array();
        if (empty($placements)) {
            echo '&mdash;';
            return;
        }

        // Render up to 2 placements, then +N.
        $max_show = 2;
        $shown = array_slice($placements, 0, $max_show);
        $extra = count($placements) - count($shown);

        $parts = array();
        foreach ($shown as $pl) {
            $title = isset($pl['title']) ? (string) $pl['title'] : '';
            $url = isset($pl['edit_url']) ? (string) $pl['edit_url'] : '';
            $label = $title ?: 'Placement';
            if ($url) {
                $parts[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            } else {
                $parts[] = esc_html($label);
            }
        }

        echo implode(', ', $parts);
        if ($extra > 0) {
            echo ' <span class="description">+' . (int) $extra . '</span>';
        }
        return;
    }

    if ($column === 'aa_stats') {
        $cache = aa_ad_manager_ads_list_table_stats_cache();
        $impressions = isset($cache['impressions'][$post_id]) ? (int) $cache['impressions'][$post_id] : 0;
        $clicks      = isset($cache['clicks'][$post_id]) ? (int) $cache['clicks'][$post_id] : 0;

        $ctr_display = '&mdash;';
        if ($impressions > 0) {
            $ctr = ($clicks / $impressions) * 100;
            $ctr_display = esc_html(number_format_i18n($ctr, 2) . '%');
        }

        $ad_link = '';
        if (function_exists('get_field')) {
            $ad_link = (string) get_field('ad_link', $post_id);
        }
        if (!$ad_link) {
            $ad_link = (string) get_post_meta($post_id, 'ad_link', true);
        }

        echo '<div class="aa-ad-stats">';
        echo '<div><strong>Impressions:</strong> ' . (int) $impressions . '</div>';
        echo '<div><strong>Clicks:</strong> ' . (int) $clicks . '</div>';
        echo '<div><strong>CTR:</strong> ' . $ctr_display . '</div>';
        echo '<div><strong>Target link:</strong> ';
        if (!empty($ad_link)) {
            echo '<button type="button" class="button button-small aa-open-ad-link-modal" data-url="' . esc_attr($ad_link) . '">Show</button>';
        } else {
            echo '&mdash;';
        }
        echo '</div>';
        echo '</div>';
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
            $alt = '';
            if (function_exists('get_field')) {
                $alt = (string) get_field('ad_title', $post_id);
            }
            if (!$alt) {
                $alt = get_the_title($post_id);
            }

            $preview_src = '';
            // Use the full asset in the modal, but constrain via CSS so it never overflows.
            $preview = wp_get_attachment_image_src($image_id, 'full');
            if (is_array($preview) && !empty($preview[0])) {
                $preview_src = (string) $preview[0];
            }

            echo '<button type="button" class="aa-ad-thumb-link aa-open-ad-image-modal" data-image-url="' . esc_attr($preview_src) . '" data-alt="' . esc_attr($alt) . '">';
            echo wp_get_attachment_image(
                $image_id,
                'thumbnail',
                false,
                array(
                    'class' => 'aa-ad-thumb',
                    'alt'   => esc_attr($alt),
                )
            );
            echo '</button>';
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
    // Only on aa_ads / aa_placement list/edit screens.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) {
        return;
    }

    $supported_post_types = array('aa_ads', 'aa_placement');

    // Only on supported list table (edit.php) and edit screen (post.php).
    if (!in_array($screen->post_type, $supported_post_types, true) || !in_array($screen->base, array('edit', 'post'), true)) {
        return;
    }

    // Shared admin styles (re-used by options/reports pages).
    $admin_css_path = AA_AD_MANAGER_PLUGIN_DIR . 'assets/css/admin.css';
    $admin_css_ver = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : AA_AD_MANAGER_VERSION;

    wp_enqueue_style(
        'aa-ad-manager-admin',
        AA_AD_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        $admin_css_ver
    );

    // List table scripts.
    if ($screen->base === 'edit') {
        $list_js_path = AA_AD_MANAGER_PLUGIN_DIR . 'assets/js/ads/aa-admin-scripts.js';
        $list_js_ver = file_exists($list_js_path) ? (string) filemtime($list_js_path) : AA_AD_MANAGER_VERSION;

        wp_enqueue_script(
            'aa-admin-scripts',
            AA_AD_MANAGER_PLUGIN_URL . 'assets/js/ads/aa-admin-scripts.js',
            array('jquery'),
            $list_js_ver,
            true
        );

        wp_localize_script(
            'aa-admin-scripts',
            'aaAdminSettings',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('aa_admin_nonce'),
            )
        );
        return;
    }

    // Placement edit screen: load lightweight helpers (copy button, key auto-fill).
    if ($screen->base === 'post' && $screen->post_type === 'aa_placement') {
        $edit_js_path = AA_AD_MANAGER_PLUGIN_DIR . 'assets/js/ads/aa-admin-scripts.js';
        $edit_js_ver = file_exists($edit_js_path) ? (string) filemtime($edit_js_path) : AA_AD_MANAGER_VERSION;

        wp_enqueue_script(
            'aa-admin-scripts',
            AA_AD_MANAGER_PLUGIN_URL . 'assets/js/ads/aa-admin-scripts.js',
            array('jquery'),
            $edit_js_ver,
            true
        );

        wp_localize_script(
            'aa-admin-scripts',
            'aaAdminSettings',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('aa_admin_nonce'),
            )
        );
        return;
    }

    // Edit screen (aa_ads only): only load performance assets when Performance tab is active.
    if ($screen->base === 'post' && $screen->post_type === 'aa_ads' && function_exists('aa_ad_manager_get_current_ad_edit_tab')) {
        if (aa_ad_manager_get_current_ad_edit_tab() !== 'performance') {
            return;
        }
    }

    $post_id = 0;
    if (isset($_GET['post'])) {
        $post_id = (int) $_GET['post'];
    } elseif (isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post) {
        $post_id = (int) $GLOBALS['post']->ID;
    }

    // Performance assets are only relevant to ads.
    if ($screen->post_type !== 'aa_ads') {
        return;
    }

    // Chart.js vendor bundle (stored in-plugin).
    $chart_js_path = AA_AD_MANAGER_PLUGIN_DIR . 'assets/js/vendor/chart.umd.min.js';
    $chart_js_ver = file_exists($chart_js_path) ? (string) filemtime($chart_js_path) : AA_AD_MANAGER_VERSION;

    wp_enqueue_script(
        'aa-ad-manager-chartjs',
        AA_AD_MANAGER_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js',
        array(),
        $chart_js_ver,
        true
    );

    $perf_js_path = AA_AD_MANAGER_PLUGIN_DIR . 'assets/js/ads/aa-admin-performance.js';
    $perf_js_ver = file_exists($perf_js_path) ? (string) filemtime($perf_js_path) : AA_AD_MANAGER_VERSION;

    wp_enqueue_script(
        'aa-ad-manager-admin-performance',
        AA_AD_MANAGER_PLUGIN_URL . 'assets/js/ads/aa-admin-performance.js',
        array('jquery', 'aa-ad-manager-chartjs'),
        $perf_js_ver,
        true
    );

    $initial_data = null;
    if ($post_id > 0 && function_exists('aa_ad_manager_get_ad_performance_data') && function_exists('aa_ad_manager_perf_parse_range')) {
        $initial_data = aa_ad_manager_get_ad_performance_data($post_id, aa_ad_manager_perf_parse_range('30'), '');
    }

    wp_localize_script(
        'aa-ad-manager-admin-performance',
        'aaAdPerfSettings',
        array(
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('aa_ad_perf_nonce'),
            'adId'        => (int) $post_id,
            'initialData' => $initial_data,
        )
    );
}
add_action('admin_enqueue_scripts', 'aa_ad_manager_enqueue_admin_scripts');

/**
 * Render a lightweight modal container for the aa_ads list table.
 */
function aa_ad_manager_render_ads_list_modal_markup() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'aa_ads' || $screen->base !== 'edit') {
        return;
    }

    echo '<div class="aa-admin-modal" id="aa-admin-modal" aria-hidden="true">';
    echo '  <div class="aa-admin-modal__overlay" data-aa-modal-close></div>';
    echo '  <div class="aa-admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="aa-admin-modal-title">';
    echo '    <button type="button" class="aa-admin-modal__close" aria-label="Close" data-aa-modal-close>&times;</button>';
    echo '    <h2 class="aa-admin-modal__title" id="aa-admin-modal-title"></h2>';
    echo '    <div class="aa-admin-modal__body" id="aa-admin-modal-body"></div>';
    echo '  </div>';
    echo '</div>';
}
add_action('admin_footer', 'aa_ad_manager_render_ads_list_modal_markup');

/**
 * Get per-ad statistics from the tracking tables.
 *
 * Returns all-time and last-30-day counts for impressions/clicks and computes CTR.
 */
function aa_ad_manager_get_ad_stats($ad_id, $days = 30) {
    global $wpdb;

    $ad_id = (int) $ad_id;
    if ($ad_id <= 0) {
        return array(
            'all_time' => array('impressions' => 0, 'clicks' => 0, 'ctr' => null),
            'recent'   => array('impressions' => 0, 'clicks' => 0, 'ctr' => null),
        );
    }

    $days = (int) $days;
    if ($days <= 0) {
        $days = 30;
    }

    $tables = function_exists('aa_ad_manager_tables')
        ? aa_ad_manager_tables()
        : array(
            'impressions' => $wpdb->prefix . 'aa_ad_impressions',
            'clicks'      => $wpdb->prefix . 'aa_ad_clicks',
        );

    // Timestamps are written using current_time('mysql'), i.e. WP timezone.
    $cutoff = date('Y-m-d H:i:s', (int) current_time('timestamp') - ($days * DAY_IN_SECONDS));

    $imp_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(impressed_at >= %s) AS recent
             FROM {$tables['impressions']}
             WHERE ad_id = %d",
            $cutoff,
            $ad_id
        ),
        ARRAY_A
    );

    $clk_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(clicked_at >= %s) AS recent
             FROM {$tables['clicks']}
             WHERE ad_id = %d",
            $cutoff,
            $ad_id
        ),
        ARRAY_A
    );

    $imp_total = isset($imp_row['total']) ? (int) $imp_row['total'] : 0;
    $imp_recent = isset($imp_row['recent']) ? (int) $imp_row['recent'] : 0;
    $clk_total = isset($clk_row['total']) ? (int) $clk_row['total'] : 0;
    $clk_recent = isset($clk_row['recent']) ? (int) $clk_row['recent'] : 0;

    $ctr_total = null;
    if ($imp_total > 0) {
        $ctr_total = ($clk_total / $imp_total) * 100;
    }

    $ctr_recent = null;
    if ($imp_recent > 0) {
        $ctr_recent = ($clk_recent / $imp_recent) * 100;
    }

    return array(
        'all_time' => array(
            'impressions' => $imp_total,
            'clicks'      => $clk_total,
            'ctr'         => $ctr_total,
        ),
        'recent' => array(
            'days'        => $days,
            'impressions' => $imp_recent,
            'clicks'      => $clk_recent,
            'ctr'         => $ctr_recent,
            'cutoff'      => $cutoff,
        ),
    );
}

function aa_ad_manager_add_ad_stats_meta_box() {
    add_meta_box(
        'aa_ad_manager_ad_stats',
        'Statistics',
        'aa_ad_manager_render_ad_stats_meta_box',
        'aa_ads',
        'side',
        'high'
    );
}
add_action('add_meta_boxes_aa_ads', 'aa_ad_manager_add_ad_stats_meta_box');

function aa_ad_manager_render_ad_stats_meta_box($post) {
    if (!($post instanceof WP_Post)) {
        return;
    }

    $stats = aa_ad_manager_get_ad_stats((int) $post->ID, 30);
    $all = isset($stats['all_time']) ? (array) $stats['all_time'] : array();
    $recent = isset($stats['recent']) ? (array) $stats['recent'] : array();

    $all_imp = isset($all['impressions']) ? (int) $all['impressions'] : 0;
    $all_clk = isset($all['clicks']) ? (int) $all['clicks'] : 0;
    $all_ctr = isset($all['ctr']) ? $all['ctr'] : null;

    $days = isset($recent['days']) ? (int) $recent['days'] : 30;
    $r_imp = isset($recent['impressions']) ? (int) $recent['impressions'] : 0;
    $r_clk = isset($recent['clicks']) ? (int) $recent['clicks'] : 0;
    $r_ctr = isset($recent['ctr']) ? $recent['ctr'] : null;

    $format_ctr = static function ($ctr) {
        if ($ctr === null) {
            return '&mdash;';
        }
        return esc_html(number_format_i18n((float) $ctr, 2) . '%');
    };

    echo '<p><strong>All-time</strong></p>';
    echo '<p style="margin:0 0 8px;">Impressions: <strong>' . (int) $all_imp . '</strong><br>';
    echo 'Clicks: <strong>' . (int) $all_clk . '</strong><br>';
    echo 'CTR: <strong>' . $format_ctr($all_ctr) . '</strong></p>';

    echo '<hr style="margin:10px 0;">';
    echo '<p><strong>Last ' . (int) $days . ' days</strong></p>';
    echo '<p style="margin:0;">Impressions: <strong>' . (int) $r_imp . '</strong><br>';
    echo 'Clicks: <strong>' . (int) $r_clk . '</strong><br>';
    echo 'CTR: <strong>' . $format_ctr($r_ctr) . '</strong></p>';
}

