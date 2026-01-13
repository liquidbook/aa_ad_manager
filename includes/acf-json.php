<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ACF integration.
 *
 * We support two paths:
 * - Local JSON load path (`acf-json/`) if you later convert exports to ACF local JSON files.
 * - Programmatic registration from the ACF Export JSON shipped in `acf-export/`.
 */

function aa_ad_manager_acf_load_json_paths($paths) {
    $paths[] = AA_AD_MANAGER_PLUGIN_DIR . 'acf-json';
    return $paths;
}
add_filter('acf/settings/load_json', 'aa_ad_manager_acf_load_json_paths');

/**
 * During local development (including bind-mount Docker dev), write ACF Local JSON
 * into this plugin so field changes are tracked in git.
 *
 * To enable: set WP_DEBUG=true (recommended for local) OR define AA_AD_MANAGER_ACF_SAVE_JSON=true.
 */
function aa_ad_manager_acf_save_json_path($path) {
    return AA_AD_MANAGER_PLUGIN_DIR . 'acf-json';
}
$save_json_enabled =
    (defined('AA_AD_MANAGER_ACF_SAVE_JSON') && constant('AA_AD_MANAGER_ACF_SAVE_JSON')) ||
    (defined('WP_DEBUG') && constant('WP_DEBUG'));

if ($save_json_enabled) {
    add_filter('acf/settings/save_json', 'aa_ad_manager_acf_save_json_path');
}

function aa_ad_manager_register_acf_field_groups_from_export() {
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    // Prefer true ACF Local JSON files when present.
    $local_json_dir = AA_AD_MANAGER_PLUGIN_DIR . 'acf-json';
    if (is_dir($local_json_dir)) {
        $local_json_files = glob($local_json_dir . '/*.json');
        if (!empty($local_json_files)) {
            return;
        }
    }

    $export_path = AA_AD_MANAGER_PLUGIN_DIR . 'acf-export/acf-export-2026-01-09.json';
    if (!file_exists($export_path)) {
        return;
    }

    $raw = file_get_contents($export_path);
    if (!$raw) {
        return;
    }

    $groups = json_decode($raw, true);
    if (!is_array($groups)) {
        return;
    }

    foreach ($groups as $group) {
        if (is_array($group) && isset($group['key'])) {
            acf_add_local_field_group($group);
        }
    }
}
add_action('acf/init', 'aa_ad_manager_register_acf_field_groups_from_export');

