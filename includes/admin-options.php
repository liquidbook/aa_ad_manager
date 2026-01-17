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
}
add_action('admin_init', 'aa_ad_manager_register_settings');

function aa_ad_manager_sanitize_options($options) {
    if (!is_array($options)) {
        $options = array();
    }

    $valid_post_types = get_post_types(array('public' => true), 'names');
    $excluded = array();

    // NOTE: This option is currently not exposed in the UI (kept for future reporting work).
    if (isset($options['excluded_post_types']) && is_array($options['excluded_post_types'])) {
        foreach ($options['excluded_post_types'] as $pt) {
            if (in_array($pt, $valid_post_types, true)) {
                $excluded[] = $pt;
            }
        }
    }

    // Back-compat migration: older installs stored an inclusion list `reportable_post_types`.
    // If an old inclusion list exists and the new exclusion list is empty, infer exclusions
    // as "all public post types" minus "reportable post types".
    if (empty($excluded) && isset($options['reportable_post_types']) && is_array($options['reportable_post_types'])) {
        $reportable = array();
        foreach ($options['reportable_post_types'] as $pt) {
            if (in_array($pt, $valid_post_types, true)) {
                $reportable[] = $pt;
            }
        }
        $excluded = array_values(array_diff($valid_post_types, $reportable));
    }

    $options['excluded_post_types'] = array_values(array_unique($excluded));

    // Stop persisting the legacy key once saved.
    if (isset($options['reportable_post_types'])) {
        unset($options['reportable_post_types']);
    }

    return $options;
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

    // Enable classic WP postbox UI behavior on our options screen (collapse/expand).
    if ($page === 'aa-ad-manager-options') {
        wp_enqueue_script('postbox');
        wp_add_inline_script('postbox', "jQuery(function($){ if (typeof postbox !== 'undefined') { postbox.add_postbox_toggles('aa_ad_manager_options'); } });");
    }
}
add_action('admin_enqueue_scripts', 'aa_ad_manager_enqueue_admin_assets');

function aa_ad_manager_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap aa-ad-manager-wrap aa-settings-page">';

    echo '<div class="aa-ad-manager-header">';
    echo '  <div class="aa-header-left">';
    echo '    <img src="' . esc_url(AA_AD_MANAGER_PLUGIN_URL . 'assets/images/ad-manage-icon.png') . '" alt="Ad Manager Logo" class="aa-logo">';
    echo '    <div>';
    echo '      <h1 style="margin: 0;">Ad Manager Settings</h1>';
    echo '      <p class="description" style="margin: 4px 0 0;">Configure reporting behavior for pages where ads are displayed.</p>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<form method="post" action="options.php">';
    settings_fields('aa_ad_manager_options_group');
    settings_errors();

    // Classic WP metabox layout (similar to ACF screens).
    echo '<div id="poststuff">';
    echo '  <div id="post-body" class="metabox-holder columns-1">';
    echo '    <div id="post-body-content">';

    echo '      <div class="postbox">';
    echo '        <div class="postbox-header">';
    echo '          <h2 class="hndle ui-sortable-handle"><span>General Settings</span></h2>';
    echo '        </div>';
    echo '        <div class="inside">';
    echo '          <div class="notice notice-info inline" style="margin: 0;">';
    echo '            <p><strong>No settings available yet.</strong> This screen will be used for reporting configuration once the reporting model is finalized.</p>';
    echo '            <p class="description" style="margin: 0;">Ad impressions/clicks are logged automatically when ads are served/clicked.</p>';
    echo '          </div>';

    echo '        </div>';
    echo '      </div>';

    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}

