<?php

if (!defined('ABSPATH')) {
    exit;
}

function aa_ad_manager_save_ad_redirect_page_id() {
    $redirect_page = get_page_by_path('ad-redirect');
    if ($redirect_page) {
        update_option('aa_ad_redirect_page_id', $redirect_page->ID);
    }
}
add_action('init', 'aa_ad_manager_save_ad_redirect_page_id');

function aa_ad_manager_register_settings() {
    register_setting('aa_ad_manager_options_group', 'aa_ad_manager_options', array(
        'sanitize_callback' => 'aa_ad_manager_sanitize_options',
    ));

    add_settings_section(
        'aa_ad_manager_general_section',
        'General Settings',
        'aa_ad_manager_general_section_callback',
        'aa_ad_manager_options'
    );

    add_settings_field(
        'reportable_post_types',
        'Reportable Post Types',
        'aa_ad_manager_reportable_post_types_field_callback',
        'aa_ad_manager_options',
        'aa_ad_manager_general_section'
    );
}
add_action('admin_init', 'aa_ad_manager_register_settings');

function aa_ad_manager_general_section_callback() {
    echo '<p>Select which public post types should be treated as "reportable archives" by the Ad Manager tool.</p>';
}

function aa_ad_manager_sanitize_options($options) {
    if (!is_array($options)) {
        $options = array();
    }

    $valid_post_types = get_post_types(array('public' => true), 'names');
    $clean = array();

    if (isset($options['reportable_post_types']) && is_array($options['reportable_post_types'])) {
        foreach ($options['reportable_post_types'] as $pt) {
            if (in_array($pt, $valid_post_types, true)) {
                $clean[] = $pt;
            }
        }
    }

    $options['reportable_post_types'] = $clean;
    return $options;
}

function aa_ad_manager_reportable_post_types_field_callback() {
    $options = get_option('aa_ad_manager_options');
    $selected = isset($options['reportable_post_types']) ? (array) $options['reportable_post_types'] : array();

    $post_types = get_post_types(array('public' => true), 'objects');
    echo '<select name="aa_ad_manager_options[reportable_post_types][]" multiple style="height:150px;">';
    foreach ($post_types as $pt_name => $pt_obj) {
        $is_selected = in_array($pt_name, $selected, true) ? 'selected' : '';
        echo '<option value="' . esc_attr($pt_name) . '" ' . $is_selected . '>' . esc_html($pt_obj->labels->name) . '</option>';
    }
    echo '</select>';
}

function aa_ad_manager_add_options_page() {
    add_submenu_page(
        'edit.php?post_type=aa_ads',
        'Ad Manager Options',
        'Ad Manager Options',
        'manage_options',
        'aa-ad-manager-options',
        'aa_ad_manager_options_page_html'
    );
}
add_action('admin_menu', 'aa_ad_manager_add_options_page');

function aa_ad_manager_enqueue_admin_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    if ($page !== 'aa-ad-manager-options' && $page !== 'aa-ad-reports') {
        return;
    }

    wp_enqueue_style(
        'aa-ad-manager-admin',
        AA_AD_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        AA_AD_MANAGER_VERSION
    );
}
add_action('admin_enqueue_scripts', 'aa_ad_manager_enqueue_admin_assets');

function aa_ad_manager_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap aa-ad-manager-wrap">';
    echo '<div class="aa-ad-manager-header">';
    echo '<div class="aa-header-left">';
    echo '<img src="' . esc_url(AA_AD_MANAGER_PLUGIN_URL . 'assets/images/ad-manage-icon.png') . '" alt="Ad Manager Logo" class="aa-logo">';
    echo '<h1>Ad Manager Settings</h1>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wrap">';
    echo '<form method="post" action="options.php">';
    settings_fields('aa_ad_manager_options_group');
    do_settings_sections('aa_ad_manager_options');
    submit_button();
    echo '</form>';
    echo '</div>';
}

