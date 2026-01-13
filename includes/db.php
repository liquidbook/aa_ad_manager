<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IMPORTANT: Production/staging dumps show these tables do NOT include page_type/page_context.
 * This plugin treats that schema as authoritative and writes only real columns.
 */

function aa_ad_manager_tables() {
    global $wpdb;
    return array(
        'impressions' => $wpdb->prefix . 'aa_ad_impressions',
        'clicks'      => $wpdb->prefix . 'aa_ad_clicks',
    );
}

function aa_ad_manager_activate() {
    aa_ad_manager_create_tables_if_missing();
}

function aa_ad_manager_create_tables_if_missing() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $tables = aa_ad_manager_tables();

    $impressions_sql = "CREATE TABLE {$tables['impressions']} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        ad_id mediumint(9) NOT NULL,
        page_id mediumint(9) NOT NULL,
        impressed_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY ad_id (ad_id),
        KEY page_id (page_id)
    ) $charset_collate;";

    $clicks_sql = "CREATE TABLE {$tables['clicks']} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        ad_id mediumint(9) NOT NULL,
        page_id mediumint(9) NOT NULL,
        referer_url varchar(255) NOT NULL DEFAULT '',
        clicked_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY ad_id (ad_id),
        KEY page_id (page_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($impressions_sql);
    dbDelta($clicks_sql);
}

function aa_ad_log_impression($ad_id, $page_id) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $wpdb->insert(
        $tables['impressions'],
        array(
            'ad_id'        => (int) $ad_id,
            'page_id'      => (int) $page_id,
            'impressed_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%s')
    );
}

function aa_ad_log_click($ad_id, $page_id, $referer_url) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $wpdb->insert(
        $tables['clicks'],
        array(
            'ad_id'       => (int) $ad_id,
            'page_id'     => (int) $page_id,
            'referer_url' => is_string($referer_url) ? substr($referer_url, 0, 255) : '',
            'clicked_at'  => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s')
    );
}

function aa_ad_get_impression_count($ad_id) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['impressions']} WHERE ad_id = %d",
            (int) $ad_id
        )
    );

    return (int) $count;
}

function aa_ad_get_click_count($ad_id) {
    global $wpdb;
    $tables = aa_ad_manager_tables();

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['clicks']} WHERE ad_id = %d",
            (int) $ad_id
        )
    );

    return (int) $count;
}

