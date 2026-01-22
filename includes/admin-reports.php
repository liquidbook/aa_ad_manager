<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reports rewritten to match the real DB schema (no page_type/page_context columns).
 */

function aa_ad_manager_add_reports_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    add_submenu_page(
        'edit.php?post_type=aa_ads',
        'Ad Manager Reports',
        'Ad Manager Reports',
        'manage_options',
        'aa-ad-reports',
        'aa_ad_manager_display_reports',
        30
    );
}
add_action('admin_menu', 'aa_ad_manager_add_reports_page');

function aa_ad_manager_get_ad_report_data($clients = array(), $campaigns = array(), $paged = 1, $per_page = 10) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $term_relationships = $wpdb->prefix . 'term_relationships';
    $term_taxonomy = $wpdb->prefix . 'term_taxonomy';
    $posts = $wpdb->prefix . 'posts';

    $offset = ($paged - 1) * $per_page;

    $query = "
        SELECT
            ai.ad_id,
            ai.page_id,
            COUNT(DISTINCT ai.id) AS impressions,
            COUNT(DISTINCT ac.id) AS clicks
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        LEFT JOIN {$tables['clicks']} ac
            ON ai.ad_id = ac.ad_id AND ai.page_id = ac.page_id
        WHERE p.post_status = 'publish'
    ";

    $where = '';

    if (!empty($clients)) {
        $clients_in = implode(',', array_map('intval', $clients));
        $where .= " AND p.ID IN (
            SELECT object_id FROM {$term_relationships} tr
            INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'aa_clients' AND tt.term_id IN ({$clients_in})
        )";
    }

    if (!empty($campaigns)) {
        $campaigns_in = implode(',', array_map('intval', $campaigns));
        $where .= " AND p.ID IN (
            SELECT object_id FROM {$term_relationships} tr
            INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'aa_campaigns' AND tt.term_id IN ({$campaigns_in})
        )";
    }

    $query .= $where;
    $query .= " GROUP BY ai.ad_id, ai.page_id";
    $query .= " ORDER BY impressions DESC";
    $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

    return $wpdb->get_results($query);
}

function aa_ad_manager_get_total_ad_report_items($clients = array(), $campaigns = array()) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $posts_table = $wpdb->posts;
    $term_relationships = $wpdb->term_relationships;
    $term_taxonomy = $wpdb->term_taxonomy;

    $query = "
        SELECT COUNT(DISTINCT CONCAT(ai.ad_id, '-', ai.page_id)) as total_items
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts_table} p ON p.ID = ai.ad_id
        WHERE p.post_status = 'publish'
    ";

    $where = '';

    if (!empty($clients)) {
        $clients_in = implode(',', array_map('intval', $clients));
        $where .= " AND p.ID IN (
            SELECT object_id FROM {$term_relationships} tr
            INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'aa_clients' AND tt.term_id IN ({$clients_in})
        )";
    }

    if (!empty($campaigns)) {
        $campaigns_in = implode(',', array_map('intval', $campaigns));
        $where .= " AND p.ID IN (
            SELECT object_id FROM {$term_relationships} tr
            INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'aa_campaigns' AND tt.term_id IN ({$campaigns_in})
        )";
    }

    $query .= $where;
    $total_items = $wpdb->get_var($query);
    return (int) $total_items;
}

/**
 * -----------------------------
 * Reports v2 helpers (placement-aware)
 * -----------------------------
 */

/**
 * Parse the reports range selector.
 *
 * @param string $raw
 * @return array{type:string,days?:int}
 */
function aa_ad_manager_reports_parse_range($raw) {
    $raw = is_string($raw) ? sanitize_key($raw) : '';
    if ($raw === 'all' || $raw === '') {
        return array('type' => 'all');
    }

    $days = (int) $raw;
    if (!in_array((string) $days, array('7', '30', '90'), true)) {
        // Default to all-time if something unexpected comes in.
        return array('type' => 'all');
    }

    return array('type' => 'days', 'days' => $days);
}

/**
 * Build a MySQL datetime cutoff string in WP timezone.
 *
 * @param int $days
 * @return string
 */
function aa_ad_manager_reports_cutoff_mysql($days) {
    $days = max(1, (int) $days);
    $cutoff_ts = (int) current_time('timestamp') - ($days * DAY_IN_SECONDS);
    return date('Y-m-d H:i:s', $cutoff_ts);
}

/**
 * Normalize filters structure.
 *
 * placement_key conventions:
 * - ''            => all placements
 * - '__legacy__'  => legacy (empty placement_key in logs)
 * - other string  => exact placement_key match
 *
 * @param array<string,mixed> $filters
 * @return array{client_id:int,campaign_id:int,range:array{type:string,days?:int},placement_key:string}
 */
function aa_ad_manager_reports_normalize_filters($filters) {
    $client_id = isset($filters['client_id']) ? (int) $filters['client_id'] : 0;
    $campaign_id = isset($filters['campaign_id']) ? (int) $filters['campaign_id'] : 0;

    $range_raw = isset($filters['range']) ? (string) $filters['range'] : 'all';
    $range = aa_ad_manager_reports_parse_range($range_raw);

    $placement_key = isset($filters['placement_key']) ? (string) $filters['placement_key'] : '';
    $placement_key = trim(sanitize_text_field($placement_key));

    return array(
        'client_id'     => max(0, $client_id),
        'campaign_id'   => max(0, $campaign_id),
        'range'         => $range,
        'placement_key' => $placement_key,
    );
}

/**
 * Build SQL and params for impressions/clicks subqueries.
 *
 * @param string $alias Table alias in SQL (ai/ac).
 * @param string $timestamp_col impressed_at|clicked_at
 * @param array{client_id:int,campaign_id:int,range:array{type:string,days?:int},placement_key:string} $filters
 * @return array{sql:string,params:array<int,mixed>}
 */
function aa_ad_manager_reports_build_where_sql($alias, $timestamp_col, $filters) {
    global $wpdb;

    $sql = " WHERE p.post_status = 'publish' ";
    $params = array();

    if (!empty($filters['client_id'])) {
        $sql .= " AND p.ID IN (
            SELECT object_id FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'aa_clients' AND tt.term_id = %d
        )";
        $params[] = (int) $filters['client_id'];
    }

    if (!empty($filters['campaign_id'])) {
        $sql .= " AND p.ID IN (
            SELECT object_id FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'aa_campaigns' AND tt.term_id = %d
        )";
        $params[] = (int) $filters['campaign_id'];
    }

    $placement_key = isset($filters['placement_key']) ? (string) $filters['placement_key'] : '';
    if ($placement_key === '__legacy__') {
        $sql .= " AND {$alias}.placement_key = ''";
    } elseif ($placement_key !== '') {
        $sql .= " AND {$alias}.placement_key = %s";
        $params[] = $placement_key;
    }

    if (isset($filters['range']['type']) && $filters['range']['type'] === 'days') {
        $days = isset($filters['range']['days']) ? (int) $filters['range']['days'] : 30;
        $cutoff = aa_ad_manager_reports_cutoff_mysql($days);
        $sql .= " AND {$alias}.{$timestamp_col} >= %s";
        $params[] = $cutoff;
    }

    return array('sql' => $sql, 'params' => $params);
}

/**
 * Return the grouped report rows.
 *
 * @param array<string,mixed> $filters
 * @param string $group_by ad_page|placement_ad|placement
 * @param int $paged
 * @param int $per_page Use <=0 for unlimited.
 * @return array<int,object>
 */
function aa_ad_manager_get_report_rows($filters, $group_by, $paged = 1, $per_page = 10) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $group_by = is_string($group_by) ? sanitize_key($group_by) : 'ad_page';
    if (!in_array($group_by, array('ad_page', 'placement_ad', 'placement'), true)) {
        $group_by = 'ad_page';
    }

    $paged = max(1, (int) $paged);
    $per_page = (int) $per_page;

    $posts = $wpdb->posts;

    // Build dimensions for SELECT/GROUP BY.
    $dim_select = array();
    $dim_group = array();
    $dim_join = array();

    if ($group_by === 'ad_page') {
        $dim_select = array('ad_id', 'page_id');
    } elseif ($group_by === 'placement_ad') {
        $dim_select = array('placement_key', 'ad_id');
    } else { // placement
        $dim_select = array('placement_key');
    }

    foreach ($dim_select as $col) {
        $dim_group[] = $col;
        $dim_join[] = "i.{$col} = c.{$col}";
    }

    $imp_where = aa_ad_manager_reports_build_where_sql('ai', 'impressed_at', $filters);
    $clk_where = aa_ad_manager_reports_build_where_sql('ac', 'clicked_at', $filters);

    $imp_dim_sql = array();
    foreach ($dim_select as $col) {
        $imp_dim_sql[] = "ai.{$col} AS {$col}";
    }
    $clk_dim_sql = array();
    foreach ($dim_select as $col) {
        $clk_dim_sql[] = "ac.{$col} AS {$col}";
    }

    $imp_sub = "
        SELECT " . implode(', ', $imp_dim_sql) . ", COUNT(*) AS impressions
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        {$imp_where['sql']}
        GROUP BY " . implode(', ', array_map(static function ($c) { return 'ai.' . $c; }, $dim_group)) . "
    ";

    $clk_sub = "
        SELECT " . implode(', ', $clk_dim_sql) . ", COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
        GROUP BY " . implode(', ', array_map(static function ($c) { return 'ac.' . $c; }, $dim_group)) . "
    ";

    $outer = "
        SELECT i." . implode(', i.', $dim_select) . ",
               i.impressions,
               COALESCE(c.clicks, 0) AS clicks
        FROM ({$imp_sub}) i
        LEFT JOIN ({$clk_sub}) c
          ON " . implode(' AND ', $dim_join) . "
        ORDER BY i.impressions DESC
    ";

    $params = array_merge($imp_where['params'], $clk_where['params']);

    if ($per_page > 0) {
        $offset = ($paged - 1) * $per_page;
        $outer .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
    }

    return $wpdb->get_results($wpdb->prepare($outer, $params));
}

/**
 * Count grouped rows for pagination.
 *
 * @param array<string,mixed> $filters
 * @param string $group_by
 * @return int
 */
function aa_ad_manager_get_report_total_items($filters, $group_by) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $group_by = is_string($group_by) ? sanitize_key($group_by) : 'ad_page';
    if (!in_array($group_by, array('ad_page', 'placement_ad', 'placement'), true)) {
        $group_by = 'ad_page';
    }

    $posts = $wpdb->posts;

    if ($group_by === 'ad_page') {
        $dims = array('ad_id', 'page_id');
    } elseif ($group_by === 'placement_ad') {
        $dims = array('placement_key', 'ad_id');
    } else {
        $dims = array('placement_key');
    }

    $imp_where = aa_ad_manager_reports_build_where_sql('ai', 'impressed_at', $filters);

    $group_sql = implode(', ', array_map(static function ($c) { return 'ai.' . $c; }, $dims));

    $inner = "
        SELECT 1
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        {$imp_where['sql']}
        GROUP BY {$group_sql}
    ";

    $sql = "SELECT COUNT(*) FROM ({$inner}) t";
    return (int) $wpdb->get_var($wpdb->prepare($sql, $imp_where['params']));
}

/**
 * Totals strip: impressions, clicks, ctr, distinct ads, distinct placements.
 *
 * @param array<string,mixed> $filters
 * @return array{impressions:int,clicks:int,ctr:(float|null),distinct_ads:int,distinct_placements:int}
 */
function aa_ad_manager_get_report_totals($filters) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $posts = $wpdb->posts;

    $imp_where = aa_ad_manager_reports_build_where_sql('ai', 'impressed_at', $filters);
    $clk_where = aa_ad_manager_reports_build_where_sql('ac', 'clicked_at', $filters);

    $imp_sql = "
        SELECT
            COUNT(*) AS impressions,
            COUNT(DISTINCT ai.ad_id) AS distinct_ads,
            COUNT(DISTINCT NULLIF(ai.placement_key, '')) AS distinct_placements
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        {$imp_where['sql']}
    ";

    $clk_sql = "
        SELECT COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
    ";

    $imp_row = $wpdb->get_row($wpdb->prepare($imp_sql, $imp_where['params']), ARRAY_A);
    $clk_row = $wpdb->get_row($wpdb->prepare($clk_sql, $clk_where['params']), ARRAY_A);

    $impressions = isset($imp_row['impressions']) ? (int) $imp_row['impressions'] : 0;
    $clicks = isset($clk_row['clicks']) ? (int) $clk_row['clicks'] : 0;
    $ctr = null;
    if ($impressions > 0) {
        $ctr = ($clicks / $impressions) * 100;
    }

    return array(
        'impressions'        => $impressions,
        'clicks'             => $clicks,
        'ctr'                => $ctr,
        'distinct_ads'       => isset($imp_row['distinct_ads']) ? (int) $imp_row['distinct_ads'] : 0,
        'distinct_placements'=> isset($imp_row['distinct_placements']) ? (int) $imp_row['distinct_placements'] : 0,
    );
}

/**
 * Top widgets (arrays for rendering).
 *
 * @param array<string,mixed> $filters
 * @return array<string,array<int,array<string,mixed>>>
 */
function aa_ad_manager_get_report_top_widgets($filters) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $posts = $wpdb->posts;

    $imp_where = aa_ad_manager_reports_build_where_sql('ai', 'impressed_at', $filters);
    $clk_where = aa_ad_manager_reports_build_where_sql('ac', 'clicked_at', $filters);

    // Top placements by impressions (exclude legacy empty placement_key).
    $top_placements_imp_sql = "
        SELECT ai.placement_key AS placement_key, COUNT(*) AS impressions
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        {$imp_where['sql']}
          AND ai.placement_key <> ''
        GROUP BY ai.placement_key
        ORDER BY impressions DESC
        LIMIT 10
    ";

    // Top placements by clicks (exclude legacy empty placement_key).
    $top_placements_clk_sql = "
        SELECT ac.placement_key AS placement_key, COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
          AND ac.placement_key <> ''
        GROUP BY ac.placement_key
        ORDER BY clicks DESC
        LIMIT 10
    ";

    // Top ads by clicks.
    $top_ads_clk_sql = "
        SELECT ac.ad_id AS ad_id, COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
        GROUP BY ac.ad_id
        ORDER BY clicks DESC
        LIMIT 10
    ";

    // Top pages by clicks.
    $top_pages_clk_sql = "
        SELECT ac.page_id AS page_id, COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
        GROUP BY ac.page_id
        ORDER BY clicks DESC
        LIMIT 10
    ";

    return array(
        'top_placements_by_impressions' => $wpdb->get_results($wpdb->prepare($top_placements_imp_sql, $imp_where['params']), ARRAY_A),
        'top_placements_by_clicks'      => $wpdb->get_results($wpdb->prepare($top_placements_clk_sql, $clk_where['params']), ARRAY_A),
        'top_ads_by_clicks'             => $wpdb->get_results($wpdb->prepare($top_ads_clk_sql, $clk_where['params']), ARRAY_A),
        'top_pages_by_clicks'           => $wpdb->get_results($wpdb->prepare($top_pages_clk_sql, $clk_where['params']), ARRAY_A),
    );
}

/**
 * Build known placement keys for the reports filter dropdown.
 *
 * @param string $range_raw
 * @return array<int,string>
 */
function aa_ad_manager_reports_get_known_placement_keys($range_raw = 'all') {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $range = aa_ad_manager_reports_parse_range($range_raw);

    // 1) From placements CPT meta.
    $keys = array();
    $ids = get_posts(array(
        'post_type'      => 'aa_placement',
        'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));
    if (is_array($ids)) {
        foreach ($ids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $k = function_exists('get_field') ? (string) get_field('placement_key', $pid) : (string) get_post_meta($pid, 'placement_key', true);
            $k = trim($k);
            if ($k !== '') {
                $keys[] = $k;
            }
        }
    }

    // 2) From logs (keys discovered in range).
    $sql = "SELECT DISTINCT placement_key FROM {$tables['impressions']} WHERE placement_key <> ''";
    $params = array();
    if (isset($range['type']) && $range['type'] === 'days') {
        $days = isset($range['days']) ? (int) $range['days'] : 30;
        $sql .= " AND impressed_at >= %s";
        $params[] = aa_ad_manager_reports_cutoff_mysql($days);
    }
    $sql .= " ORDER BY placement_key ASC LIMIT 500";

    $rows = empty($params) ? $wpdb->get_col($sql) : $wpdb->get_col($wpdb->prepare($sql, $params));
    if (is_array($rows)) {
        foreach ($rows as $k) {
            $k = is_string($k) ? trim($k) : '';
            if ($k !== '') {
                $keys[] = $k;
            }
        }
    }

    $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
    sort($keys, SORT_NATURAL);
    return $keys;
}

/**
 * Resolve placement_key => placement post metadata in one batched query.
 *
 * @param array<int,string> $keys
 * @return array<string,array{id:int,title:string,edit_url:string}>
 */
function aa_ad_manager_reports_resolve_placements_by_keys($keys) {
    $keys = is_array($keys) ? $keys : array();
    $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
    if (empty($keys)) {
        return array();
    }

    $meta_query = array('relation' => 'OR');
    foreach ($keys as $k) {
        $k = is_string($k) ? trim($k) : '';
        if ($k === '') {
            continue;
        }
        $meta_query[] = array(
            'key'   => 'placement_key',
            'value' => $k,
        );
    }

    if (count($meta_query) <= 1) {
        return array();
    }

    $ids = get_posts(array(
        'post_type'      => 'aa_placement',
        'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => $meta_query,
    ));

    $out = array();
    if (is_array($ids)) {
        foreach ($ids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $k = function_exists('get_field') ? (string) get_field('placement_key', $pid) : (string) get_post_meta($pid, 'placement_key', true);
            $k = trim($k);
            if ($k === '') {
                continue;
            }
            $out[$k] = array(
                'id'       => $pid,
                'title'    => (string) get_the_title($pid),
                'edit_url' => (string) get_edit_post_link($pid, 'raw'),
            );
        }
    }

    return $out;
}

function aa_ad_manager_display_reports() {
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'client-reports';

    echo '<div class="wrap aa-ad-manager-wrap">';
    echo '<div class="aa-ad-manager-header">';
    echo '<div class="aa-header-left">';
    echo '<img src="' . esc_url(AA_AD_MANAGER_PLUGIN_URL . 'assets/images/ad-manage-icon.png') . '" alt="Ad Manager Logo" class="aa-logo">';
    echo '<h1>Ad Manager Tools</h1>';
    echo '</div>';
    echo '</div>';

    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?post_type=aa_ads&page=aa-ad-reports&tab=client-reports" class="nav-tab ' . ($current_tab === 'client-reports' ? 'nav-tab-active' : '') . '">Client Reports</a>';
    echo '<a href="?post_type=aa_ads&page=aa-ad-reports&tab=placements" class="nav-tab ' . ($current_tab === 'placements' ? 'nav-tab-active' : '') . '">Placements</a>';
    echo '</h2>';

    echo '<div class="aa-tab-content">';
    if ($current_tab === 'client-reports') {
        aa_ad_manager_display_client_reports();
    } elseif ($current_tab === 'placements') {
        aa_ad_manager_display_placements_reports();
    } elseif ($current_tab === 'ad-performance') {
        // This tab is intentionally hidden for now; keep the route for back-compat.
        echo '<div class="notice notice-info inline" style="margin-top:12px;"><p><strong>Ad Performance is currently hidden.</strong> Use the per-ad Performance panel when editing an ad.</p></div>';
    }
    echo '</div>';
    echo '</div>';
}

/**
 * Get placement overview rows (grouped by placement_key) for Placements tab.
 *
 * @param array<string,mixed> $filters
 * @param int $paged
 * @param int $per_page
 * @return array<int,array<string,mixed>>
 */
function aa_ad_manager_reports_get_placements_overview_rows($filters, $paged = 1, $per_page = 20) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    // Overview ignores placement filter.
    $filters['placement_key'] = '';

    $paged = max(1, (int) $paged);
    $per_page = max(1, (int) $per_page);
    $offset = ($paged - 1) * $per_page;

    $posts = $wpdb->posts;
    $imp_where = aa_ad_manager_reports_build_where_sql('ai', 'impressed_at', $filters);
    $clk_where = aa_ad_manager_reports_build_where_sql('ac', 'clicked_at', $filters);

    $imp_sub = "
        SELECT
            ai.placement_key AS placement_key,
            COUNT(*) AS impressions,
            COUNT(DISTINCT ai.ad_id) AS distinct_ads,
            COUNT(DISTINCT NULLIF(ai.page_id, 0)) AS distinct_pages
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        {$imp_where['sql']}
        GROUP BY ai.placement_key
    ";

    $clk_sub = "
        SELECT
            ac.placement_key AS placement_key,
            COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
        GROUP BY ac.placement_key
    ";

    $sql = "
        SELECT
            i.placement_key,
            i.impressions,
            COALESCE(c.clicks, 0) AS clicks,
            i.distinct_ads,
            i.distinct_pages
        FROM ({$imp_sub}) i
        LEFT JOIN ({$clk_sub}) c
          ON i.placement_key = c.placement_key
        ORDER BY i.impressions DESC
        LIMIT %d OFFSET %d
    ";

    $params = array_merge($imp_where['params'], $clk_where['params'], array($per_page, $offset));
    return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
}

/**
 * Count placement overview rows for pagination.
 *
 * @param array<string,mixed> $filters
 * @return int
 */
function aa_ad_manager_reports_get_placements_overview_total_items($filters) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $filters['placement_key'] = '';

    $posts = $wpdb->posts;
    $imp_where = aa_ad_manager_reports_build_where_sql('ai', 'impressed_at', $filters);

    $inner = "
        SELECT 1
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        {$imp_where['sql']}
        GROUP BY ai.placement_key
    ";

    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ({$inner}) t", $imp_where['params']));
}

/**
 * Get daily series for a placement_key.
 *
 * @param string $placement_key
 * @param array{client_id:int,campaign_id:int,range:array{type:string,days?:int},placement_key:string} $filters
 * @return array{labels:string[],impressions:int[],clicks:int[],ctr:(float|null)[]}
 */
function aa_ad_manager_reports_get_placement_daily_series($placement_key, $filters) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $placement_key = is_string($placement_key) ? trim(sanitize_text_field($placement_key)) : '';
    if ($placement_key === '') {
        return array('labels' => array(), 'impressions' => array(), 'clicks' => array(), 'ctr' => array());
    }

    $filters['placement_key'] = $placement_key;

    $posts = $wpdb->posts;
    $imp_where = aa_ad_manager_reports_build_where_sql('ai', 'impressed_at', $filters);
    $clk_where = aa_ad_manager_reports_build_where_sql('ac', 'clicked_at', $filters);

    $imp_sql = "
        SELECT DATE(ai.impressed_at) AS d, COUNT(*) AS cnt
        FROM {$tables['impressions']} ai
        INNER JOIN {$posts} p ON ai.ad_id = p.ID
        {$imp_where['sql']}
        GROUP BY DATE(ai.impressed_at)
        ORDER BY d ASC
    ";

    $clk_sql = "
        SELECT DATE(ac.clicked_at) AS d, COUNT(*) AS cnt
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
        GROUP BY DATE(ac.clicked_at)
        ORDER BY d ASC
    ";

    $imp_rows = $wpdb->get_results($wpdb->prepare($imp_sql, $imp_where['params']), ARRAY_A);
    $clk_rows = $wpdb->get_results($wpdb->prepare($clk_sql, $clk_where['params']), ARRAY_A);

    $imp_map = array();
    if (is_array($imp_rows)) {
        foreach ($imp_rows as $row) {
            $d = isset($row['d']) ? (string) $row['d'] : '';
            if ($d !== '') {
                $imp_map[$d] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
            }
        }
    }
    $clk_map = array();
    if (is_array($clk_rows)) {
        foreach ($clk_rows as $row) {
            $d = isset($row['d']) ? (string) $row['d'] : '';
            if ($d !== '') {
                $clk_map[$d] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
            }
        }
    }

    $start_date = null;
    $end_date = date('Y-m-d', (int) current_time('timestamp'));
    if (isset($filters['range']['type']) && $filters['range']['type'] === 'days') {
        $days = isset($filters['range']['days']) ? (int) $filters['range']['days'] : 30;
        $cutoff = aa_ad_manager_reports_cutoff_mysql($days);
        $start_date = date('Y-m-d', strtotime($cutoff));
    }

    if (!$start_date) {
        $labels = array_unique(array_merge(array_keys($imp_map), array_keys($clk_map)));
        sort($labels);
    } else {
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
 * Top ads by clicks for a placement.
 *
 * @param string $placement_key
 * @param array<string,mixed> $filters
 * @param int $limit
 * @return array<int,array{ad_id:int,clicks:int}>
 */
function aa_ad_manager_reports_get_top_ads_for_placement($placement_key, $filters, $limit = 10) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $placement_key = is_string($placement_key) ? trim(sanitize_text_field($placement_key)) : '';
    if ($placement_key === '') {
        return array();
    }
    $filters['placement_key'] = $placement_key;

    $posts = $wpdb->posts;
    $clk_where = aa_ad_manager_reports_build_where_sql('ac', 'clicked_at', $filters);

    $sql = "
        SELECT ac.ad_id AS ad_id, COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
        GROUP BY ac.ad_id
        ORDER BY clicks DESC
        LIMIT %d
    ";

    $params = array_merge($clk_where['params'], array(max(1, (int) $limit)));
    return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
}

/**
 * Top pages by clicks for a placement.
 *
 * @param string $placement_key
 * @param array<string,mixed> $filters
 * @param int $limit
 * @return array<int,array{page_id:int,clicks:int}>
 */
function aa_ad_manager_reports_get_top_pages_for_placement($placement_key, $filters, $limit = 10) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $filters = aa_ad_manager_reports_normalize_filters(is_array($filters) ? $filters : array());
    $placement_key = is_string($placement_key) ? trim(sanitize_text_field($placement_key)) : '';
    if ($placement_key === '') {
        return array();
    }
    $filters['placement_key'] = $placement_key;

    $posts = $wpdb->posts;
    $clk_where = aa_ad_manager_reports_build_where_sql('ac', 'clicked_at', $filters);

    $sql = "
        SELECT ac.page_id AS page_id, COUNT(*) AS clicks
        FROM {$tables['clicks']} ac
        INNER JOIN {$posts} p ON ac.ad_id = p.ID
        {$clk_where['sql']}
        GROUP BY ac.page_id
        ORDER BY clicks DESC
        LIMIT %d
    ";

    $params = array_merge($clk_where['params'], array(max(1, (int) $limit)));
    return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
}

/**
 * Render Placements tab.
 */
function aa_ad_manager_display_placements_reports() {
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
    $per_page_options = array(10, 20, 50, 100);

    $clients = isset($_GET['clients']) ? (int) $_GET['clients'] : 0;
    $campaigns = isset($_GET['campaigns']) ? (int) $_GET['campaigns'] : 0;

    $range = isset($_GET['range']) ? sanitize_key((string) $_GET['range']) : '30';
    if (!in_array($range, array('7', '30', '90', 'all'), true)) {
        $range = '30';
    }

    $placement_key = isset($_GET['placement_key']) ? sanitize_text_field((string) $_GET['placement_key']) : '';
    $placement_key = trim($placement_key);

    $filters = array(
        'client_id'     => $clients > 0 ? $clients : 0,
        'campaign_id'   => $campaigns > 0 ? $campaigns : 0,
        'range'         => $range,
        'placement_key' => '',
    );

    // Filter UI.
    $reset_url = add_query_arg(
        array(
            'post_type' => 'aa_ads',
            'page'      => 'aa-ad-reports',
            'tab'       => 'placements',
        ),
        admin_url('edit.php')
    );

    echo '<form method="get" action="" id="aa-placements-reports-filter-form" class="aa-filters-form">';
    echo '<input type="hidden" name="post_type" value="aa_ads">';
    echo '<input type="hidden" name="page" value="aa-ad-reports">';
    echo '<input type="hidden" name="tab" value="placements">';

    echo '<div class="aa-filters-card">';
    echo '  <div class="aa-filters-row">';

    echo '    <div class="aa-filter">';
    echo '      <label for="clients">Client</label>';
    echo '      <select name="clients" id="clients">';
    echo '        <option value="">Select ...</option>';
    $client_terms = get_terms(array('taxonomy' => 'aa_clients', 'hide_empty' => false));
    if (!is_wp_error($client_terms)) {
        foreach ($client_terms as $term) {
            $selected = ($term->term_id === $clients) ? 'selected' : '';
            echo '        <option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html(wp_strip_all_tags($term->name)) . '</option>';
        }
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter">';
    echo '      <label for="campaigns">Campaign</label>';
    echo '      <select name="campaigns" id="campaigns">';
    echo '        <option value="">Select ...</option>';
    $campaign_terms = get_terms(array('taxonomy' => 'aa_campaigns', 'hide_empty' => false));
    if (!is_wp_error($campaign_terms)) {
        foreach ($campaign_terms as $term) {
            $selected = ($term->term_id === $campaigns) ? 'selected' : '';
            echo '        <option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html(wp_strip_all_tags($term->name)) . '</option>';
        }
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter">';
    echo '      <label for="range">Date range</label>';
    echo '      <select name="range" id="range">';
    $ranges = array(
        '7'   => 'Last 7 days',
        '30'  => 'Last 30 days',
        '90'  => 'Last 90 days',
        'all' => 'All time',
    );
    foreach ($ranges as $val => $label) {
        $selected = ($range === $val) ? 'selected' : '';
        echo '        <option value="' . esc_attr($val) . '" ' . $selected . '>' . esc_html($label) . '</option>';
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter aa-filter--small">';
    echo '      <label for="per_page">Per page</label>';
    echo '      <select name="per_page" id="per_page">';
    foreach ($per_page_options as $option) {
        $selected = ($per_page === $option) ? 'selected' : '';
        echo '        <option value="' . esc_attr($option) . '" ' . $selected . '>' . esc_html($option) . '</option>';
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter-actions">';
    echo '      <input type="submit" value="Filter" class="button button-primary">';
    echo '      <a class="button" href="' . esc_url($reset_url) . '">Reset</a>';
    echo '    </div>';

    echo '  </div>';
    echo '</div>';
    echo '</form>';

    // If a placement is selected, render drilldown.
    if ($placement_key !== '') {
        $series = aa_ad_manager_reports_get_placement_daily_series($placement_key, $filters);
        $top_ads = aa_ad_manager_reports_get_top_ads_for_placement($placement_key, $filters, 10);
        $top_pages = aa_ad_manager_reports_get_top_pages_for_placement($placement_key, $filters, 10);

        $known_map = aa_ad_manager_reports_resolve_placements_by_keys(array($placement_key));
        $title = isset($known_map[$placement_key]['title']) ? (string) $known_map[$placement_key]['title'] : '';
        $display = $title ?: $placement_key;

        $back_url = add_query_arg(
            array_filter(array(
                'post_type' => 'aa_ads',
                'page'      => 'aa-ad-reports',
                'tab'       => 'placements',
                'clients'   => $clients > 0 ? $clients : null,
                'campaigns' => $campaigns > 0 ? $campaigns : null,
                'range'     => $range,
                'per_page'  => $per_page,
            )),
            admin_url('edit.php')
        );

        echo '<p style="margin-top:0;"><a href="' . esc_url($back_url) . '">&larr; Back to placements</a></p>';
        echo '<h2 style="margin-top:0;">Placement: ' . esc_html($display) . ' <span class="description">(' . esc_html($placement_key) . ')</span></h2>';

        echo '<div class="aa-perf__grid aa-perf__grid--bottom">';
        echo '  <div class="aa-perf__card aa-perf__card--chart">';
        echo '    <div class="aa-perf__card-header"><strong>Impressions &amp; Clicks Over Time</strong><span class="description">(Selected range)</span></div>';
        echo '    <canvas id="aa-reports-placement-ic-chart" height="110" aria-label="Placement impressions and clicks chart" role="img"></canvas>';
        echo '  </div>';
        echo '  <div class="aa-perf__card aa-perf__card--chart">';
        echo '    <div class="aa-perf__card-header"><strong>CTR Over Time</strong><span class="description">(Selected range)</span></div>';
        echo '    <canvas id="aa-reports-placement-ctr-chart" height="110" aria-label="Placement CTR chart" role="img"></canvas>';
        echo '  </div>';
        echo '</div>';

        // Provide series data for the JS renderer (enqueued conditionally).
        echo '<script type="application/json" id="aa-reports-placement-series">' . wp_json_encode($series) . '</script>';

        echo '<div class="aa-perf__grid aa-perf__grid--bottom">';
        echo '  <div class="aa-perf__card">';
        echo '    <div class="aa-perf__card-header"><strong>Top Ads in this Placement</strong><span class="description">(by clicks)</span></div>';
        if (empty($top_ads)) {
            echo '    <p class="description" style="margin:0;">No data.</p>';
        } else {
            echo '    <ol class="aa-perf__list">';
            foreach ($top_ads as $row) {
                $ad_id = isset($row['ad_id']) ? (int) $row['ad_id'] : 0;
                $clks = isset($row['clicks']) ? (int) $row['clicks'] : 0;
                $label = $ad_id > 0 ? get_the_title($ad_id) : '';
                if ($label === '') {
                    $label = $ad_id > 0 ? ('#' . $ad_id) : 'Unknown';
                }
                $edit = $ad_id > 0 ? get_edit_post_link($ad_id, 'raw') : '';
                $left = $edit ? ('<a href="' . esc_url($edit) . '">' . esc_html($label) . '</a>') : esc_html($label);
                echo '      <li><span>' . $left . '</span><span>' . esc_html(number_format_i18n($clks)) . '</span></li>';
            }
            echo '    </ol>';
        }
        echo '  </div>';

        echo '  <div class="aa-perf__card">';
        echo '    <div class="aa-perf__card-header"><strong>Top Pages in this Placement</strong><span class="description">(by clicks)</span></div>';
        if (empty($top_pages)) {
            echo '    <p class="description" style="margin:0;">No data.</p>';
        } else {
            echo '    <ol class="aa-perf__list">';
            foreach ($top_pages as $row) {
                $page_id = isset($row['page_id']) ? (int) $row['page_id'] : 0;
                $clks = isset($row['clicks']) ? (int) $row['clicks'] : 0;
                $label = $page_id > 0 ? get_the_title($page_id) : '';
                if ($label === '') {
                    $label = $page_id > 0 ? ('#' . $page_id) : 'Unknown';
                }
                $url = $page_id > 0 ? get_permalink($page_id) : '';
                $left = $url ? ('<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>') : esc_html($label);
                echo '      <li><span>' . $left . '</span><span>' . esc_html(number_format_i18n($clks)) . '</span></li>';
            }
            echo '    </ol>';
        }
        echo '  </div>';
        echo '</div>';
        return;
    }

    // Overview table.
    $rows = aa_ad_manager_reports_get_placements_overview_rows($filters, $paged, $per_page);
    $total_items = aa_ad_manager_reports_get_placements_overview_total_items($filters);
    $total_pages = max(1, (int) ceil($total_items / max(1, $per_page)));

    $keys = array();
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $k = isset($r['placement_key']) ? (string) $r['placement_key'] : '';
            if ($k !== '') {
                $keys[] = $k;
            }
        }
    }
    $placement_map = aa_ad_manager_reports_resolve_placements_by_keys($keys);

    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead><tr><th>Placement</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Distinct Pages</th><th>Distinct Ads</th></tr></thead>';
    echo '<tbody>';

    if (!empty($rows)) {
        foreach ($rows as $r) {
            $k = isset($r['placement_key']) ? (string) $r['placement_key'] : '';
            $imps = isset($r['impressions']) ? (int) $r['impressions'] : 0;
            $clks = isset($r['clicks']) ? (int) $r['clicks'] : 0;
            $ctr = $imps > 0 ? (($clks / $imps) * 100) : null;
            $distinct_pages = isset($r['distinct_pages']) ? (int) $r['distinct_pages'] : 0;
            $distinct_ads = isset($r['distinct_ads']) ? (int) $r['distinct_ads'] : 0;

            $name = $k === '' ? '(legacy / no placement_key)' : $k;
            if ($k !== '' && isset($placement_map[$k]['title']) && $placement_map[$k]['title'] !== '') {
                $name = (string) $placement_map[$k]['title'];
            }

            $detail_url = $k === '' ? '' : add_query_arg(
                array_filter(array(
                    'post_type'     => 'aa_ads',
                    'page'          => 'aa-ad-reports',
                    'tab'           => 'placements',
                    'clients'       => $clients > 0 ? $clients : null,
                    'campaigns'     => $campaigns > 0 ? $campaigns : null,
                    'range'         => $range,
                    'per_page'      => $per_page,
                    'placement_key' => $k,
                )),
                admin_url('edit.php')
            );

            echo '<tr>';
            if ($detail_url) {
                echo '<td><a href="' . esc_url($detail_url) . '">' . esc_html($name) . '</a><div class="description">' . esc_html($k) . '</div></td>';
            } else {
                echo '<td>' . esc_html($name) . '</td>';
            }
            echo '<td>' . esc_html(number_format_i18n($imps)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($clks)) . '</td>';
            echo '<td>' . esc_html($ctr === null ? '—' : (number_format_i18n((float) $ctr, 2) . '%')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($distinct_pages)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($distinct_ads)) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No records found.</td></tr>';
    }

    echo '</tbody></table>';

    $pagination = '';
    if ($total_pages > 1) {
        $base_args = array(
            'post_type' => 'aa_ads',
            'page'      => 'aa-ad-reports',
            'tab'       => 'placements',
            'per_page'  => $per_page,
            'range'     => $range,
        );
        if ($clients > 0) {
            $base_args['clients'] = $clients;
        }
        if ($campaigns > 0) {
            $base_args['campaigns'] = $campaigns;
        }

        $base = add_query_arg($base_args, admin_url('edit.php'));
        $base = add_query_arg('paged', '%#%', $base);

        $pagination = paginate_links(array(
            'base'      => $base,
            'format'    => '',
            'prev_text' => __('« Previous'),
            'next_text' => __('Next »'),
            'total'     => $total_pages,
            'current'   => $paged,
            'type'      => 'list',
        ));
    }

    if ($pagination) {
        echo '<div class="aa-ad-reports-footer">';
        echo '  <div class="aa-ad-reports-footer__left"></div>';
        echo '  <div class="aa-ad-reports-footer__right"><div class="tablenav-pages aa-ad-reports-pagination">' . $pagination . '</div></div>';
        echo '</div>';
    }
}

function aa_ad_manager_display_client_reports() {
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
    $per_page_options = array(10, 20, 50, 100);

    $clients = isset($_GET['clients']) ? (int) $_GET['clients'] : 0;
    $campaigns = isset($_GET['campaigns']) ? (int) $_GET['campaigns'] : 0;

    // New v1 reporting filters (persist selected range from querystring).
    $range = isset($_GET['range']) ? sanitize_key((string) $_GET['range']) : 'all';
    if (!in_array($range, array('7', '30', '90', 'all'), true)) {
        $range = 'all';
    }

    $group_by = isset($_GET['group_by']) ? sanitize_key((string) $_GET['group_by']) : 'ad_page';
    if (!in_array($group_by, array('ad_page', 'placement_ad', 'placement'), true)) {
        $group_by = 'ad_page';
    }

    // placement_key UI uses a sentinel value for legacy (empty placement_key).
    $placement_key = isset($_GET['placement_key']) ? sanitize_text_field((string) $_GET['placement_key']) : '';
    $placement_key = trim($placement_key);

    $filters = array(
        'client_id'     => $clients > 0 ? $clients : 0,
        'campaign_id'   => $campaigns > 0 ? $campaigns : 0,
        'range'         => $range,
        'placement_key' => $placement_key,
    );

    $results = aa_ad_manager_get_report_rows($filters, $group_by, $paged, $per_page);
    $total_items = aa_ad_manager_get_report_total_items($filters, $group_by);
    $total_pages = max(1, (int) ceil($total_items / max(1, $per_page)));

    $totals = aa_ad_manager_get_report_totals($filters);
    $widgets = aa_ad_manager_get_report_top_widgets($filters);

    $reset_url = add_query_arg(
        array(
            'post_type' => 'aa_ads',
            'page'      => 'aa-ad-reports',
            'tab'       => 'client-reports',
        ),
        admin_url('edit.php')
    );

    echo '<form method="get" action="" id="aa-ad-reports-filter-form" class="aa-filters-form">';
    echo '<input type="hidden" name="post_type" value="aa_ads">';
    echo '<input type="hidden" name="page" value="aa-ad-reports">';
    echo '<input type="hidden" name="tab" value="client-reports">';

    echo '<div class="aa-filters-card">';
    echo '  <div class="aa-filters-row">';

    echo '    <div class="aa-filter">';
    echo '      <label for="clients">Client</label>';
    echo '      <select name="clients" id="clients">';
    echo '        <option value="">Select ...</option>';
    $client_terms = get_terms(array('taxonomy' => 'aa_clients', 'hide_empty' => false));
    if (!is_wp_error($client_terms)) {
        foreach ($client_terms as $term) {
            $selected = ($term->term_id === $clients) ? 'selected' : '';
            echo '        <option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html(wp_strip_all_tags($term->name)) . '</option>';
        }
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter">';
    echo '      <label for="campaigns">Campaign</label>';
    echo '      <select name="campaigns" id="campaigns">';
    echo '        <option value="">Select ...</option>';
    $campaign_terms = get_terms(array('taxonomy' => 'aa_campaigns', 'hide_empty' => false));
    if (!is_wp_error($campaign_terms)) {
        foreach ($campaign_terms as $term) {
            $selected = ($term->term_id === $campaigns) ? 'selected' : '';
            echo '        <option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html(wp_strip_all_tags($term->name)) . '</option>';
        }
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter">';
    echo '      <label for="range">Date range</label>';
    echo '      <select name="range" id="range">';
    $ranges = array(
        '7'   => 'Last 7 days',
        '30'  => 'Last 30 days',
        '90'  => 'Last 90 days',
        'all' => 'All time',
    );
    foreach ($ranges as $val => $label) {
        $selected = ($range === $val) ? 'selected' : '';
        echo '        <option value="' . esc_attr($val) . '" ' . $selected . '>' . esc_html($label) . '</option>';
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter">';
    echo '      <label for="placement_key">Placement</label>';
    echo '      <select name="placement_key" id="placement_key">';
    echo '        <option value="" ' . ($placement_key === '' ? 'selected' : '') . '>All placements</option>';
    echo '        <option value="__legacy__" ' . ($placement_key === '__legacy__' ? 'selected' : '') . '>(none / legacy)</option>';
    $known_keys = function_exists('aa_ad_manager_reports_get_known_placement_keys') ? aa_ad_manager_reports_get_known_placement_keys($range) : array();
    if (is_array($known_keys) && !empty($known_keys)) {
        foreach ($known_keys as $k) {
            $k = is_string($k) ? trim($k) : '';
            if ($k === '') {
                continue;
            }
            $selected = ($placement_key === $k) ? 'selected' : '';
            echo '        <option value="' . esc_attr($k) . '" ' . $selected . '>' . esc_html($k) . '</option>';
        }
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter">';
    echo '      <label for="group_by">Group by</label>';
    echo '      <select name="group_by" id="group_by">';
    $groups = array(
        'ad_page'      => 'Ad + Page',
        'placement_ad' => 'Placement + Ad',
        'placement'    => 'Placement only',
    );
    foreach ($groups as $val => $label) {
        $selected = ($group_by === $val) ? 'selected' : '';
        echo '        <option value="' . esc_attr($val) . '" ' . $selected . '>' . esc_html($label) . '</option>';
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter aa-filter--small">';
    echo '      <label for="per_page">Per page</label>';
    echo '      <select name="per_page" id="per_page">';
    foreach ($per_page_options as $option) {
        $selected = ($per_page === $option) ? 'selected' : '';
        echo '        <option value="' . esc_attr($option) . '" ' . $selected . '>' . esc_html($option) . '</option>';
    }
    echo '      </select>';
    echo '    </div>';

    echo '    <div class="aa-filter-actions">';
    echo '      <input type="submit" value="Filter" class="button button-primary">';
    echo '      <a class="button" href="' . esc_url($reset_url) . '">Reset</a>';
    echo '    </div>';

    echo '  </div>';
    echo '</div>';
    echo '</form>';

    // KPI strip (summary totals).
    $imp_total = isset($totals['impressions']) ? (int) $totals['impressions'] : 0;
    $clk_total = isset($totals['clicks']) ? (int) $totals['clicks'] : 0;
    $ctr_total = isset($totals['ctr']) ? $totals['ctr'] : null;
    $distinct_ads = isset($totals['distinct_ads']) ? (int) $totals['distinct_ads'] : 0;
    $distinct_placements = isset($totals['distinct_placements']) ? (int) $totals['distinct_placements'] : 0;

    echo '<div class="aa-perf__grid aa-perf__grid--top" style="margin-top:12px;">';
    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header"><strong>Summary</strong><span class="description">(Current filters)</span></div>';
    echo '    <div class="aa-perf__totals" style="grid-template-columns: repeat(5, minmax(0, 1fr));">';
    echo '      <div class="aa-perf__total"><span class="aa-perf__total-label">Total Impressions</span><span class="aa-perf__total-value">' . esc_html(number_format_i18n($imp_total)) . '</span></div>';
    echo '      <div class="aa-perf__total"><span class="aa-perf__total-label">Total Clicks</span><span class="aa-perf__total-value">' . esc_html(number_format_i18n($clk_total)) . '</span></div>';
    echo '      <div class="aa-perf__total"><span class="aa-perf__total-label">CTR</span><span class="aa-perf__total-value">' . esc_html($ctr_total === null ? '—' : (number_format_i18n((float) $ctr_total, 2) . '%')) . '</span></div>';
    echo '      <div class="aa-perf__total"><span class="aa-perf__total-label">Distinct Ads Served</span><span class="aa-perf__total-value">' . esc_html(number_format_i18n($distinct_ads)) . '</span></div>';
    echo '      <div class="aa-perf__total"><span class="aa-perf__total-label">Distinct Placements Served</span><span class="aa-perf__total-value">' . esc_html(number_format_i18n($distinct_placements)) . '</span></div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    // Top 10 widgets.
    $top_placements_imps = isset($widgets['top_placements_by_impressions']) && is_array($widgets['top_placements_by_impressions']) ? $widgets['top_placements_by_impressions'] : array();
    $top_placements_clicks = isset($widgets['top_placements_by_clicks']) && is_array($widgets['top_placements_by_clicks']) ? $widgets['top_placements_by_clicks'] : array();
    $top_ads_clicks = isset($widgets['top_ads_by_clicks']) && is_array($widgets['top_ads_by_clicks']) ? $widgets['top_ads_by_clicks'] : array();
    $top_pages_clicks = isset($widgets['top_pages_by_clicks']) && is_array($widgets['top_pages_by_clicks']) ? $widgets['top_pages_by_clicks'] : array();

    $placement_keys_to_resolve = array();
    foreach (array_merge($top_placements_imps, $top_placements_clicks) as $row) {
        if (isset($row['placement_key']) && is_string($row['placement_key']) && $row['placement_key'] !== '') {
            $placement_keys_to_resolve[] = $row['placement_key'];
        }
    }
    if ($group_by === 'placement' || $group_by === 'placement_ad') {
        if (is_array($results)) {
            foreach ($results as $r) {
                if (isset($r->placement_key) && is_string($r->placement_key) && $r->placement_key !== '') {
                    $placement_keys_to_resolve[] = $r->placement_key;
                }
            }
        }
    }
    $placement_keys_to_resolve = array_values(array_unique(array_filter(array_map('strval', $placement_keys_to_resolve))));
    $placement_map = function_exists('aa_ad_manager_reports_resolve_placements_by_keys') ? aa_ad_manager_reports_resolve_placements_by_keys($placement_keys_to_resolve) : array();

    $render_placement_label = static function ($key) use ($placement_map, $range, $clients, $campaigns) {
        $key = is_string($key) ? $key : '';
        if ($key === '') {
            return '<span class="description">(legacy / no placement_key)</span>';
        }

        $title = '';
        $edit_url = '';
        if (isset($placement_map[$key]) && is_array($placement_map[$key])) {
            $title = isset($placement_map[$key]['title']) ? (string) $placement_map[$key]['title'] : '';
            $edit_url = isset($placement_map[$key]['edit_url']) ? (string) $placement_map[$key]['edit_url'] : '';
        }

        $reports_url = add_query_arg(
            array_filter(array(
                'post_type'     => 'aa_ads',
                'page'          => 'aa-ad-reports',
                'tab'           => 'placements',
                'range'         => $range !== 'all' ? $range : null,
                'clients'       => $clients > 0 ? $clients : null,
                'campaigns'     => $campaigns > 0 ? $campaigns : null,
                'placement_key' => $key,
            )),
            admin_url('edit.php')
        );

        $name = $title ?: $key;
        $out = '<a href="' . esc_url($reports_url) . '">' . esc_html($name) . '</a>';
        if ($edit_url) {
            $out .= ' <a class="description" href="' . esc_url($edit_url) . '">(edit)</a>';
        }
        $out .= '<div class="description">' . esc_html($key) . '</div>';
        return $out;
    };

    echo '<div class="aa-perf__grid aa-perf__grid--bottom">';

    // Top placements by impressions.
    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header"><strong>Top Placements by Impressions</strong><span class="description">(Top 10)</span></div>';
    if (empty($top_placements_imps)) {
        echo '    <p class="description" style="margin:0;">No data.</p>';
    } else {
        echo '    <ol class="aa-perf__list">';
        foreach ($top_placements_imps as $row) {
            $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
            $imps = isset($row['impressions']) ? (int) $row['impressions'] : 0;
            echo '      <li><span>' . $render_placement_label($k) . '</span><span>' . esc_html(number_format_i18n($imps)) . '</span></li>';
        }
        echo '    </ol>';
    }
    echo '  </div>';

    // Top placements by clicks.
    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header"><strong>Top Placements by Clicks</strong><span class="description">(Top 10)</span></div>';
    if (empty($top_placements_clicks)) {
        echo '    <p class="description" style="margin:0;">No data.</p>';
    } else {
        echo '    <ol class="aa-perf__list">';
        foreach ($top_placements_clicks as $row) {
            $k = isset($row['placement_key']) ? (string) $row['placement_key'] : '';
            $clks = isset($row['clicks']) ? (int) $row['clicks'] : 0;
            echo '      <li><span>' . $render_placement_label($k) . '</span><span>' . esc_html(number_format_i18n($clks)) . '</span></li>';
        }
        echo '    </ol>';
    }
    echo '  </div>';

    echo '</div>';

    echo '<div class="aa-perf__grid aa-perf__grid--bottom">';

    // Top ads by clicks.
    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header"><strong>Top Ads by Clicks</strong><span class="description">(Top 10)</span></div>';
    if (empty($top_ads_clicks)) {
        echo '    <p class="description" style="margin:0;">No data.</p>';
    } else {
        echo '    <ol class="aa-perf__list">';
        foreach ($top_ads_clicks as $row) {
            $ad_id = isset($row['ad_id']) ? (int) $row['ad_id'] : 0;
            $clks = isset($row['clicks']) ? (int) $row['clicks'] : 0;
            $label = $ad_id > 0 ? get_the_title($ad_id) : '';
            if ($label === '') {
                $label = $ad_id > 0 ? ('#' . $ad_id) : 'Unknown';
            }
            $edit = $ad_id > 0 ? get_edit_post_link($ad_id, 'raw') : '';
            $left = $edit ? ('<a href="' . esc_url($edit) . '">' . esc_html($label) . '</a>') : esc_html($label);
            echo '      <li><span>' . $left . '</span><span>' . esc_html(number_format_i18n($clks)) . '</span></li>';
        }
        echo '    </ol>';
    }
    echo '  </div>';

    // Top pages by clicks.
    echo '  <div class="aa-perf__card">';
    echo '    <div class="aa-perf__card-header"><strong>Top Pages by Clicks</strong><span class="description">(Top 10)</span></div>';
    if (empty($top_pages_clicks)) {
        echo '    <p class="description" style="margin:0;">No data.</p>';
    } else {
        echo '    <ol class="aa-perf__list">';
        foreach ($top_pages_clicks as $row) {
            $page_id = isset($row['page_id']) ? (int) $row['page_id'] : 0;
            $clks = isset($row['clicks']) ? (int) $row['clicks'] : 0;
            $label = $page_id > 0 ? get_the_title($page_id) : '';
            if ($label === '') {
                $label = $page_id > 0 ? ('#' . $page_id) : 'Unknown';
            }
            $url = $page_id > 0 ? get_permalink($page_id) : '';
            $left = $url ? ('<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>') : esc_html($label);
            echo '      <li><span>' . $left . '</span><span>' . esc_html(number_format_i18n($clks)) . '</span></li>';
        }
        echo '    </ol>';
    }
    echo '  </div>';

    echo '</div>';

    echo '<table class="widefat fixed" cellspacing="0">';
    if ($group_by === 'placement') {
        echo '<thead><tr><th>Placement</th><th>Impressions</th><th>Clicks</th><th>CTR</th></tr></thead>';
    } elseif ($group_by === 'placement_ad') {
        echo '<thead><tr><th>Placement</th><th>Ad Title</th><th>Client</th><th>Campaign</th><th>Impressions</th><th>Clicks</th><th>CTR</th></tr></thead>';
    } else {
        echo '<thead><tr><th>Ad Title</th><th>Client</th><th>Campaign</th><th>Page</th><th>Impressions</th><th>Clicks</th></tr></thead>';
    }
    echo '<tbody>';

    if ($results) {
        foreach ($results as $row) {
            $imps = isset($row->impressions) ? (int) $row->impressions : 0;
            $clks = isset($row->clicks) ? (int) $row->clicks : 0;
            $ctr = $imps > 0 ? (($clks / $imps) * 100) : null;

            echo '<tr>';
            if ($group_by === 'placement') {
                $k = isset($row->placement_key) ? (string) $row->placement_key : '';
                echo '<td>' . $render_placement_label($k) . '</td>';
                echo '<td>' . (int) $imps . '</td>';
                echo '<td>' . (int) $clks . '</td>';
                echo '<td>' . esc_html($ctr === null ? '—' : (number_format_i18n((float) $ctr, 2) . '%')) . '</td>';
            } elseif ($group_by === 'placement_ad') {
                $k = isset($row->placement_key) ? (string) $row->placement_key : '';
                $ad_id = isset($row->ad_id) ? (int) $row->ad_id : 0;
                $ad_title = $ad_id > 0 ? get_the_title($ad_id) : '';
                $clients_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_clients', array('fields' => 'names')) : array();
                $campaigns_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_campaigns', array('fields' => 'names')) : array();

                echo '<td>' . $render_placement_label($k) . '</td>';
                echo '<td>' . esc_html($ad_title) . '</td>';
                echo '<td>' . esc_html(!empty($clients_terms) ? implode(', ', $clients_terms) : '') . '</td>';
                echo '<td>' . esc_html(!empty($campaigns_terms) ? implode(', ', $campaigns_terms) : '') . '</td>';
                echo '<td>' . (int) $imps . '</td>';
                echo '<td>' . (int) $clks . '</td>';
                echo '<td>' . esc_html($ctr === null ? '—' : (number_format_i18n((float) $ctr, 2) . '%')) . '</td>';
            } else {
                $ad_id = isset($row->ad_id) ? (int) $row->ad_id : 0;
                $page_id = isset($row->page_id) ? (int) $row->page_id : 0;

                $ad_title = $ad_id > 0 ? get_the_title($ad_id) : '';
                $clients_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_clients', array('fields' => 'names')) : array();
                $campaigns_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_campaigns', array('fields' => 'names')) : array();

                $page_display = 'Unknown';
                if (!empty($page_id)) {
                    $page_title = get_the_title($page_id);
                    $page_url = get_permalink($page_id);
                    if (!empty($page_url)) {
                        $page_display = '<a href="' . esc_url($page_url) . '" target="_blank">' . esc_html($page_title ? $page_title : ('#' . (int) $page_id)) . '</a>';
                    } else {
                        $page_display = esc_html($page_title ? $page_title : ('#' . (int) $page_id));
                    }
                }

                echo '<td>' . esc_html($ad_title) . '</td>';
                echo '<td>' . esc_html(!empty($clients_terms) ? implode(', ', $clients_terms) : '') . '</td>';
                echo '<td>' . esc_html(!empty($campaigns_terms) ? implode(', ', $campaigns_terms) : '') . '</td>';
                echo '<td>' . $page_display . '</td>';
                echo '<td>' . (int) $imps . '</td>';
                echo '<td>' . (int) $clks . '</td>';
            }
            echo '</tr>';
        }
    } else {
        $colspan = 6;
        if ($group_by === 'placement') {
            $colspan = 4;
        } elseif ($group_by === 'placement_ad') {
            $colspan = 7;
        }
        echo '<tr><td colspan="' . (int) $colspan . '">No records found.</td></tr>';
    }
    echo '</tbody></table>';

    $pagination = '';
    if ($total_pages > 1) {
        $base_args = array(
            'post_type' => 'aa_ads',
            'page'      => 'aa-ad-reports',
            'tab'       => 'client-reports',
            'per_page'  => $per_page,
        );
        if ($clients > 0) {
            $base_args['clients'] = $clients;
        }
        if ($campaigns > 0) {
            $base_args['campaigns'] = $campaigns;
        }
        if ($range !== 'all') {
            $base_args['range'] = $range;
        }
        if ($placement_key !== '') {
            $base_args['placement_key'] = $placement_key;
        }
        if ($group_by !== 'ad_page') {
            $base_args['group_by'] = $group_by;
        }

        $base = add_query_arg($base_args, admin_url('edit.php'));
        $base = add_query_arg('paged', '%#%', $base);

        $pagination = paginate_links(array(
            'base'      => $base,
            'format'    => '',
            'prev_text' => __('« Previous'),
            'next_text' => __('Next »'),
            'total'     => $total_pages,
            'current'   => $paged,
            'type'      => 'list',
        ));
    }

    $download_url = admin_url('admin-post.php?action=aa_ad_manager_download_reports_csv');
    if (!empty($clients)) {
        $download_url = add_query_arg('clients', $clients, $download_url);
    }
    if (!empty($campaigns)) {
        $download_url = add_query_arg('campaigns', $campaigns, $download_url);
    }
    if ($range !== 'all') {
        $download_url = add_query_arg('range', $range, $download_url);
    }
    if ($placement_key !== '') {
        $download_url = add_query_arg('placement_key', $placement_key, $download_url);
    }
    if ($group_by !== 'ad_page') {
        $download_url = add_query_arg('group_by', $group_by, $download_url);
    }
    $download_url = add_query_arg('per_page', $per_page, $download_url);
    $download_url = add_query_arg('tab', 'client-reports', $download_url);
    $download_url = wp_nonce_url($download_url, 'aa_ad_manager_download_reports_csv');

    echo '<div class="aa-ad-reports-footer">';
    echo '  <div class="aa-ad-reports-footer__left"><a href="' . esc_url($download_url) . '" class="button button-primary">Download CSV</a></div>';
    echo '  <div class="aa-ad-reports-footer__right">' . ($pagination ? '<div class="tablenav-pages aa-ad-reports-pagination">' . $pagination . '</div>' : '') . '</div>';
    echo '</div>';
}

function aa_ad_manager_download_reports_csv() {
    if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'aa_ad_manager_download_reports_csv')) {
        wp_die('Security check failed');
    }
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }

    $client_id = isset($_GET['clients']) ? (int) $_GET['clients'] : 0;
    $campaign_id = isset($_GET['campaigns']) ? (int) $_GET['campaigns'] : 0;

    $range = isset($_GET['range']) ? sanitize_key((string) $_GET['range']) : 'all';
    if (!in_array($range, array('7', '30', '90', 'all'), true)) {
        $range = 'all';
    }

    $placement_key = isset($_GET['placement_key']) ? sanitize_text_field((string) $_GET['placement_key']) : '';
    $placement_key = trim($placement_key);

    $group_by = isset($_GET['group_by']) ? sanitize_key((string) $_GET['group_by']) : 'ad_page';
    if (!in_array($group_by, array('ad_page', 'placement_ad', 'placement'), true)) {
        $group_by = 'ad_page';
    }

    $filters = array(
        'client_id'     => $client_id > 0 ? $client_id : 0,
        'campaign_id'   => $campaign_id > 0 ? $campaign_id : 0,
        'range'         => $range,
        'placement_key' => $placement_key,
    );

    // Unlimited export.
    $results = aa_ad_manager_get_report_rows($filters, $group_by, 1, -1);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=ad_reports.csv');

    $output = fopen('php://output', 'w');
    if ($group_by === 'placement') {
        fputcsv($output, array('Placement Key', 'Placement', 'Impressions', 'Clicks', 'CTR'));
    } elseif ($group_by === 'placement_ad') {
        fputcsv($output, array('Placement Key', 'Placement', 'Ad Title', 'Client', 'Campaign', 'Impressions', 'Clicks', 'CTR'));
    } else {
        fputcsv($output, array('Ad Title', 'Client', 'Campaign', 'Page', 'Impressions', 'Clicks'));
    }

    foreach ($results as $row) {
        $imps = isset($row->impressions) ? (int) $row->impressions : 0;
        $clks = isset($row->clicks) ? (int) $row->clicks : 0;
        $ctr = $imps > 0 ? (($clks / $imps) * 100) : null;

        if ($group_by === 'placement') {
            $k = isset($row->placement_key) ? (string) $row->placement_key : '';
            $label = $k === '' ? '(legacy / no placement_key)' : $k;
            fputcsv($output, array(
                $k,
                $label,
                $imps,
                $clks,
                $ctr === null ? '' : number_format((float) $ctr, 4, '.', ''),
            ));
            continue;
        }

        if ($group_by === 'placement_ad') {
            $k = isset($row->placement_key) ? (string) $row->placement_key : '';
            $label = $k === '' ? '(legacy / no placement_key)' : $k;
            $ad_id = isset($row->ad_id) ? (int) $row->ad_id : 0;
            $ad_title = $ad_id > 0 ? get_the_title($ad_id) : '';
            $clients_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_clients', array('fields' => 'names')) : array();
            $campaigns_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_campaigns', array('fields' => 'names')) : array();

            fputcsv($output, array(
                $k,
                $label,
                $ad_title,
                !empty($clients_terms) ? implode(', ', $clients_terms) : '',
                !empty($campaigns_terms) ? implode(', ', $campaigns_terms) : '',
                $imps,
                $clks,
                $ctr === null ? '' : number_format((float) $ctr, 4, '.', ''),
            ));
            continue;
        }

        // Default: ad + page
        $ad_id = isset($row->ad_id) ? (int) $row->ad_id : 0;
        $page_id = isset($row->page_id) ? (int) $row->page_id : 0;
        $ad_title = $ad_id > 0 ? get_the_title($ad_id) : '';
        $clients_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_clients', array('fields' => 'names')) : array();
        $campaigns_terms = $ad_id > 0 ? wp_get_post_terms($ad_id, 'aa_campaigns', array('fields' => 'names')) : array();
        $page_title = $page_id > 0 ? get_the_title($page_id) : '';

        fputcsv($output, array(
            $ad_title,
            !empty($clients_terms) ? implode(', ', $clients_terms) : '',
            !empty($campaigns_terms) ? implode(', ', $campaigns_terms) : '',
            $page_title ? $page_title : ($page_id ? ('#' . (int) $page_id) : 'Unknown'),
            $imps,
            $clks,
        ));
    }

    fclose($output);
    exit;
}
add_action('admin_post_aa_ad_manager_download_reports_csv', 'aa_ad_manager_download_reports_csv');

