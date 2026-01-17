<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Placements v1.
 *
 * - CPT: aa_placement (admin-managed slot objects)
 * - Shortcode UX: list table shows copyable [aa_slot key="..."]
 *
 * Delivery, AJAX, and tracking are implemented elsewhere.
 */

function aa_ad_manager_register_placements_cpt() {
    $labels = array(
        'name'               => 'Placements',
        'singular_name'      => 'Placement',
        'menu_name'          => 'Placements',
        'name_admin_bar'     => 'Placement',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Placement',
        'new_item'           => 'New Placement',
        'edit_item'          => 'Edit Placement',
        'view_item'          => 'View Placement',
        'all_items'          => 'All Placements',
        'search_items'       => 'Search Placements',
        'not_found'          => 'No placements found.',
        'not_found_in_trash' => 'No placements found in Trash.',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => 'edit.php?post_type=aa_ads',
        'query_var'          => true,
        'rewrite'            => array('slug' => 'aa_placement'),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'supports'           => array('title'),
        'menu_position'      => 71,
    );

    register_post_type('aa_placement', $args);
}
add_action('init', 'aa_ad_manager_register_placements_cpt');

function aa_ad_manager_placements_custom_columns($columns) {
    // Keep title first.
    $new = array();
    if (isset($columns['cb'])) {
        $new['cb'] = $columns['cb'];
    }
    $new['title'] = isset($columns['title']) ? $columns['title'] : 'Title';

    $new['placement_key'] = 'Placement Key';
    $new['shortcode'] = 'Shortcode';
    $new['assigned_ads'] = 'Assigned Ads';
    $new['status'] = 'Status';

    // Preserve modified/date if present.
    if (isset($columns['date'])) {
        $new['date'] = $columns['date'];
    }

    return $new;
}
add_filter('manage_aa_placement_posts_columns', 'aa_ad_manager_placements_custom_columns');

function aa_ad_manager_placements_custom_column_content($column, $post_id) {
    if ($column === 'placement_key') {
        $key = function_exists('get_field') ? (string) get_field('placement_key', $post_id) : (string) get_post_meta($post_id, 'placement_key', true);
        echo $key ? esc_html($key) : '&mdash;';
        return;
    }

    if ($column === 'shortcode') {
        $key = function_exists('get_field') ? (string) get_field('placement_key', $post_id) : (string) get_post_meta($post_id, 'placement_key', true);
        $shortcode = $key ? '[aa_slot key="' . $key . '"]' : '';
        echo '<span class="shortcode-text">' . esc_html($shortcode) . '</span>';
        echo ' <button type="button" class="button button-small copy-shortcode" ' . ($shortcode ? '' : 'disabled') . '>Copy</button>';
        return;
    }

    if ($column === 'assigned_ads') {
        $assigned = function_exists('get_field') ? get_field('assigned_ads', $post_id) : get_post_meta($post_id, 'assigned_ads', true);
        $count = 0;
        if (is_array($assigned)) {
            $count = count(array_filter(array_map('intval', $assigned)));
        } elseif (is_string($assigned) && $assigned !== '') {
            // Best-effort if stored as CSV.
            $parts = array_filter(array_map('trim', explode(',', $assigned)));
            $count = count($parts);
        }
        echo (int) $count;
        return;
    }

    if ($column === 'status') {
        $active = function_exists('get_field') ? (bool) get_field('placement_active', $post_id) : (bool) get_post_meta($post_id, 'placement_active', true);
        echo $active ? '<span class="aa-status aa-status--active">Active</span>' : '<span class="aa-status aa-status--inactive">Inactive</span>';
        return;
    }
}
add_action('manage_aa_placement_posts_custom_column', 'aa_ad_manager_placements_custom_column_content', 10, 2);

function aa_ad_manager_add_placement_shortcode_meta_box() {
    add_meta_box(
        'aa_ad_manager_placement_shortcode',
        'Shortcode',
        'aa_ad_manager_render_placement_shortcode_meta_box',
        'aa_placement',
        'side',
        'high'
    );
}
add_action('add_meta_boxes_aa_placement', 'aa_ad_manager_add_placement_shortcode_meta_box');

function aa_ad_manager_render_placement_shortcode_meta_box($post) {
    if (!($post instanceof WP_Post)) {
        return;
    }

    $key = function_exists('get_field') ? (string) get_field('placement_key', $post->ID) : (string) get_post_meta($post->ID, 'placement_key', true);
    $key = trim($key);
    $shortcode = $key ? '[aa_slot key="' . $key . '"]' : '';

    echo '<p style="margin-top:0;">Use this shortcode in Elementor/templates to render this placement.</p>';
    echo '<p><span class="shortcode-text" style="display:inline-block;max-width:100%;word-break:break-word;">' . esc_html($shortcode) . '</span></p>';
    echo '<p style="margin-bottom:0;"><button type="button" class="button button-small copy-shortcode" ' . ($shortcode ? '' : 'disabled') . '>Copy</button></p>';
}

/**
 * Show a warning notice after the placement key has changed.
 */
function aa_ad_manager_placement_key_change_notice() {
    if (!is_admin()) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'aa_placement' || $screen->base !== 'post') {
        return;
    }

    $post_id = 0;
    if (isset($_GET['post'])) {
        $post_id = (int) $_GET['post'];
    }
    if ($post_id <= 0) {
        return;
    }

    $flag = get_post_meta($post_id, '_aa_placement_key_changed_notice', true);
    if (!$flag) {
        return;
    }

    delete_post_meta($post_id, '_aa_placement_key_changed_notice');

    echo '<div class="notice notice-warning is-dismissible"><p><strong>Placement key changed.</strong> Changing the key may break existing pages that use the shortcode.</p></div>';
}
add_action('admin_notices', 'aa_ad_manager_placement_key_change_notice');

/**
 * Mark when placement_key is edited after initial set.
 */
function aa_ad_manager_track_placement_key_changes($post_id, $post, $update) {
    if (!($post instanceof WP_Post) || $post->post_type !== 'aa_placement') {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // ACF writes meta before save_post runs; read current value.
    $key = function_exists('get_field') ? (string) get_field('placement_key', $post_id) : (string) get_post_meta($post_id, 'placement_key', true);
    $key = trim($key);

    $prev = (string) get_post_meta($post_id, '_aa_last_saved_placement_key', true);
    $prev = trim($prev);

    if ($prev !== '' && $key !== '' && $key !== $prev) {
        update_post_meta($post_id, '_aa_placement_key_changed_notice', '1');
    }

    if ($key !== '') {
        update_post_meta($post_id, '_aa_last_saved_placement_key', $key);
    }

    // Clear cached placement-by-key resolution.
    if ($key !== '') {
        wp_cache_delete('aa_placement_id_by_key:' . $key, 'aa_ad_manager');
    }
    if ($prev !== '' && $prev !== $key) {
        wp_cache_delete('aa_placement_id_by_key:' . $prev, 'aa_ad_manager');
    }
}
add_action('save_post_aa_placement', 'aa_ad_manager_track_placement_key_changes', 10, 3);

/**
 * Enforce unique placement_key in ACF validation.
 */
function aa_ad_manager_acf_validate_unique_placement_key($valid, $value, $field, $input) {
    if ($valid !== true) {
        return $valid;
    }

    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return 'Placement Key is required.';
    }

    $post_id = 0;
    if (isset($_POST['post_ID'])) {
        $post_id = (int) $_POST['post_ID'];
    } elseif (is_string($input) && preg_match('/post_(\\d+)/', $input, $m)) {
        $post_id = (int) $m[1];
    }

    $existing = get_posts(array(
        'post_type'      => 'aa_placement',
        'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'post__not_in'   => $post_id > 0 ? array($post_id) : array(),
        'meta_query'     => array(
            array(
                'key'   => 'placement_key',
                'value' => $value,
            ),
        ),
    ));

    if (!empty($existing)) {
        return 'Placement Key must be unique.';
    }

    return true;
}
add_filter('acf/validate_value/name=placement_key', 'aa_ad_manager_acf_validate_unique_placement_key', 10, 4);

/**
 * Admin helper: check whether a placement_key is available.
 *
 * Used by the placement editor JS to auto-suffix keys on generation.
 */
function aa_ad_manager_ajax_validate_placement_key() {
    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Forbidden'), 403);
    }

    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'aa_admin_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'), 403);
    }

    $key = isset($_POST['placement_key']) ? sanitize_text_field((string) $_POST['placement_key']) : '';
    $key = trim($key);
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    if ($key === '') {
        wp_send_json_success(array('available' => false, 'message' => 'Missing placement_key'));
    }

    $existing = get_posts(array(
        'post_type'      => 'aa_placement',
        'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'post__not_in'   => $post_id > 0 ? array($post_id) : array(),
        'meta_query'     => array(
            array(
                'key'   => 'placement_key',
                'value' => $key,
            ),
        ),
    ));

    wp_send_json_success(array('available' => empty($existing)));
}
add_action('wp_ajax_aa_validate_placement_key', 'aa_ad_manager_ajax_validate_placement_key');

