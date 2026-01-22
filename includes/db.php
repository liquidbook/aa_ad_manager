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
    update_option('aa_ad_manager_db_schema_version', aa_ad_manager_get_target_db_schema_version());
}

function aa_ad_manager_get_target_db_schema_version() {
    // Bump this string when dbDelta definitions change.
    return '2026-01-17-placement-key';
}

/**
 * Ensure required columns exist even if plugin was updated without reactivation.
 */
function aa_ad_manager_maybe_upgrade_db_schema() {
    $current = (string) get_option('aa_ad_manager_db_schema_version', '');
    $target = aa_ad_manager_get_target_db_schema_version();
    if ($current === $target) {
        return;
    }

    aa_ad_manager_create_tables_if_missing();
    update_option('aa_ad_manager_db_schema_version', $target);
}
add_action('plugins_loaded', 'aa_ad_manager_maybe_upgrade_db_schema');

function aa_ad_manager_create_tables_if_missing() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $tables = aa_ad_manager_tables();

    $impressions_sql = "CREATE TABLE {$tables['impressions']} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        ad_id mediumint(9) NOT NULL,
        page_id mediumint(9) NOT NULL,
        placement_key varchar(191) NOT NULL DEFAULT '',
        impressed_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY ad_id (ad_id),
        KEY page_id (page_id),
        KEY placement_key (placement_key)
    ) $charset_collate;";

    $clicks_sql = "CREATE TABLE {$tables['clicks']} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        ad_id mediumint(9) NOT NULL,
        page_id mediumint(9) NOT NULL,
        placement_key varchar(191) NOT NULL DEFAULT '',
        referer_url varchar(255) NOT NULL DEFAULT '',
        clicked_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY ad_id (ad_id),
        KEY page_id (page_id),
        KEY placement_key (placement_key)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($impressions_sql);
    dbDelta($clicks_sql);
}

/**
 * Determine whether the current request should be logged for tracking.
 *
 * Default: exclude Administrators from tracking to avoid polluting reporting with staff activity.
 * Configurable via Ad Manager Options: `aa_ad_manager_options[exclude_tracking_roles]`.
 */
function aa_ad_manager_should_log_tracking_event($event_type = '') {
    $event_type = is_string($event_type) ? sanitize_key($event_type) : '';

    $should_log = true;

    if (is_user_logged_in()) {
        $options = get_option('aa_ad_manager_options', array());
        $excluded_roles = isset($options['exclude_tracking_roles']) && is_array($options['exclude_tracking_roles'])
            ? array_values(array_unique(array_map('sanitize_key', $options['exclude_tracking_roles'])))
            : array('administrator');

        $user = wp_get_current_user();
        $user_roles = (is_object($user) && isset($user->roles) && is_array($user->roles)) ? $user->roles : array();

        if (!empty($excluded_roles) && !empty($user_roles)) {
            $intersect = array_intersect($excluded_roles, $user_roles);
            if (!empty($intersect)) {
                $should_log = false;
            }
        }
    }

    /**
     * Filter: allow overriding the tracking decision.
     *
     * @param bool   $should_log Whether to log this event.
     * @param string $event_type Event type (e.g. 'impression' or 'click').
     */
    return (bool) apply_filters('aa_ad_manager_should_log_tracking_event', $should_log, $event_type);
}

function aa_ad_log_impression($ad_id, $page_id, $placement_key = '') {
    if (!aa_ad_manager_should_log_tracking_event('impression')) {
        return;
    }

    global $wpdb;
    $tables = aa_ad_manager_tables();

    $placement_key = is_string($placement_key) ? substr(sanitize_text_field($placement_key), 0, 191) : '';

    $wpdb->insert(
        $tables['impressions'],
        array(
            'ad_id'        => (int) $ad_id,
            'page_id'      => (int) $page_id,
            'placement_key'=> $placement_key,
            'impressed_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s')
    );
}

function aa_ad_log_click($ad_id, $page_id, $referer_url, $placement_key = '') {
    if (!aa_ad_manager_should_log_tracking_event('click')) {
        return;
    }

    global $wpdb;
    $tables = aa_ad_manager_tables();

    $placement_key = is_string($placement_key) ? substr(sanitize_text_field($placement_key), 0, 191) : '';

    $wpdb->insert(
        $tables['clicks'],
        array(
            'ad_id'       => (int) $ad_id,
            'page_id'     => (int) $page_id,
            'placement_key'=> $placement_key,
            'referer_url' => is_string($referer_url) ? substr($referer_url, 0, 255) : '',
            'clicked_at'  => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s', '%s')
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

