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
    echo '<a href="?post_type=aa_ads&page=aa-ad-reports&tab=ad-performance" class="nav-tab ' . ($current_tab === 'ad-performance' ? 'nav-tab-active' : '') . '">Ad Performance</a>';
    echo '</h2>';

    echo '<div class="aa-tab-content">';
    if ($current_tab === 'client-reports') {
        aa_ad_manager_display_client_reports();
    } elseif ($current_tab === 'ad-performance') {
        echo '<div class="aa-ad-performance"><h2>Ad Performance Analytics</h2><p>Coming soon...</p></div>';
    }
    echo '</div>';
    echo '</div>';
}

function aa_ad_manager_display_client_reports() {
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
    $per_page_options = array(10, 20, 50, 100);

    $clients = isset($_GET['clients']) ? (int) $_GET['clients'] : 0;
    $campaigns = isset($_GET['campaigns']) ? (int) $_GET['campaigns'] : 0;

    $results = aa_ad_manager_get_ad_report_data(
        $clients > 0 ? array($clients) : array(),
        $campaigns > 0 ? array($campaigns) : array(),
        $paged,
        $per_page
    );

    $total_items = aa_ad_manager_get_total_ad_report_items(
        $clients > 0 ? array($clients) : array(),
        $campaigns > 0 ? array($campaigns) : array()
    );
    $total_pages = max(1, (int) ceil($total_items / max(1, $per_page)));

    echo '<form method="get" action="" id="aa-ad-reports-filter-form">';
    echo '<input type="hidden" name="post_type" value="aa_ads">';
    echo '<input type="hidden" name="page" value="aa-ad-reports">';
    echo '<input type="hidden" name="tab" value="client-reports">';

    echo '<label for="clients">Client:</label>';
    echo '<select name="clients" id="clients">';
    echo '<option value="">Select ...</option>';
    $client_terms = get_terms(array('taxonomy' => 'aa_clients', 'hide_empty' => false));
    if (!is_wp_error($client_terms)) {
        foreach ($client_terms as $term) {
            $selected = ($term->term_id === $clients) ? 'selected' : '';
            echo '<option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html(wp_strip_all_tags($term->name)) . '</option>';
        }
    }
    echo '</select>';

    echo '<label for="campaigns">Campaign:</label>';
    echo '<select name="campaigns" id="campaigns">';
    echo '<option value="">Select ...</option>';
    $campaign_terms = get_terms(array('taxonomy' => 'aa_campaigns', 'hide_empty' => false));
    if (!is_wp_error($campaign_terms)) {
        foreach ($campaign_terms as $term) {
            $selected = ($term->term_id === $campaigns) ? 'selected' : '';
            echo '<option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html(wp_strip_all_tags($term->name)) . '</option>';
        }
    }
    echo '</select>';

    echo '<label for="per_page">Records per page:</label>';
    echo '<select name="per_page" id="per_page">';
    foreach ($per_page_options as $option) {
        $selected = ($per_page === $option) ? 'selected' : '';
        echo '<option value="' . esc_attr($option) . '" ' . $selected . '>' . esc_html($option) . '</option>';
    }
    echo '</select>';

    echo '<input type="submit" value="Filter" class="button button-primary">';
    echo '</form>';

    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead><tr><th>Ad Title</th><th>Client</th><th>Campaign</th><th>Page</th><th>Impressions</th><th>Clicks</th></tr></thead>';
    echo '<tbody>';

    if ($results) {
        foreach ($results as $row) {
            $ad_title = get_the_title($row->ad_id);
            $clients_terms = wp_get_post_terms($row->ad_id, 'aa_clients', array('fields' => 'names'));
            $campaigns_terms = wp_get_post_terms($row->ad_id, 'aa_campaigns', array('fields' => 'names'));

            $page_display = 'Unknown';
            if (!empty($row->page_id)) {
                $page_title = get_the_title($row->page_id);
                $page_url = get_permalink($row->page_id);
                if (!empty($page_url)) {
                    $page_display = '<a href="' . esc_url($page_url) . '" target="_blank">' . esc_html($page_title ? $page_title : ('#' . (int) $row->page_id)) . '</a>';
                } else {
                    $page_display = esc_html($page_title ? $page_title : ('#' . (int) $row->page_id));
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html($ad_title) . '</td>';
            echo '<td>' . esc_html(!empty($clients_terms) ? implode(', ', $clients_terms) : '') . '</td>';
            echo '<td>' . esc_html(!empty($campaigns_terms) ? implode(', ', $campaigns_terms) : '') . '</td>';
            echo '<td>' . $page_display . '</td>';
            echo '<td>' . (int) $row->impressions . '</td>';
            echo '<td>' . (int) $row->clicks . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No records found.</td></tr>';
    }
    echo '</tbody></table>';

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

        if ($pagination) {
            echo '<div class="tablenav-pages aa-ad-reports-pagination">' . $pagination . '</div>';
        }
    }

    $download_url = admin_url('admin-post.php?action=aa_ad_manager_download_reports_csv');
    if (!empty($clients)) {
        $download_url = add_query_arg('clients', $clients, $download_url);
    }
    if (!empty($campaigns)) {
        $download_url = add_query_arg('campaigns', $campaigns, $download_url);
    }
    $download_url = add_query_arg('per_page', $per_page, $download_url);
    $download_url = add_query_arg('tab', 'client-reports', $download_url);
    $download_url = wp_nonce_url($download_url, 'aa_ad_manager_download_reports_csv');

    echo '<p><a href="' . esc_url($download_url) . '" class="button button-primary">Download CSV</a></p>';
}

function aa_ad_manager_download_reports_csv() {
    if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'aa_ad_manager_download_reports_csv')) {
        wp_die('Security check failed');
    }
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }

    $clients = isset($_GET['clients']) ? array_filter(array_map('intval', (array) $_GET['clients'])) : array();
    $campaigns = isset($_GET['campaigns']) ? array_filter(array_map('intval', (array) $_GET['campaigns'])) : array();
    $per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : -1;

    $results = aa_ad_manager_get_ad_report_data($clients, $campaigns, 1, $per_page);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=ad_reports.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, array('Ad Title', 'Client', 'Campaign', 'Page', 'Impressions', 'Clicks'));

    foreach ($results as $row) {
        $ad_title = get_the_title($row->ad_id);
        $clients_terms = wp_get_post_terms($row->ad_id, 'aa_clients', array('fields' => 'names'));
        $campaigns_terms = wp_get_post_terms($row->ad_id, 'aa_campaigns', array('fields' => 'names'));
        $page_title = !empty($row->page_id) ? get_the_title($row->page_id) : '';

        fputcsv($output, array(
            $ad_title,
            !empty($clients_terms) ? implode(', ', $clients_terms) : '',
            !empty($campaigns_terms) ? implode(', ', $campaigns_terms) : '',
            $page_title ? $page_title : ($row->page_id ? ('#' . (int) $row->page_id) : 'Unknown'),
            (int) $row->impressions,
            (int) $row->clicks,
        ));
    }

    fclose($output);
    exit;
}
add_action('admin_post_aa_ad_manager_download_reports_csv', 'aa_ad_manager_download_reports_csv');

