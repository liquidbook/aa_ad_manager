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

    // Tracking exclusions (roles).
    // Default: exclude Administrators from impression/click logging to protect reporting integrity.
    $editable_roles = function_exists('get_editable_roles') ? get_editable_roles() : array();
    $valid_roles = is_array($editable_roles) ? array_keys($editable_roles) : array();

    $excluded_roles = array('administrator');
    // Important: allow saving an intentionally-empty exclusion list (exclude nobody).
    // Checkboxes submit nothing when all are unchecked, so the UI adds a hidden empty
    // value to ensure the key is present on save.
    if (array_key_exists('exclude_tracking_roles', $options)) {
        $excluded_roles = array();
        $raw = $options['exclude_tracking_roles'];
        if (!is_array($raw)) {
            $raw = array($raw);
        }
        foreach ($raw as $role_key) {
            $role_key = sanitize_key((string) $role_key);
            if ($role_key === '') {
                continue;
            }
            // If roles are available, only accept valid roles. If not, accept the sanitized value.
            if (empty($valid_roles) || in_array($role_key, $valid_roles, true)) {
                $excluded_roles[] = $role_key;
            }
        }
        $excluded_roles = array_values(array_unique($excluded_roles));
    }
    $options['exclude_tracking_roles'] = $excluded_roles;

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

    // Reports: only load charts on Placements drilldown view.
    if ($page === 'aa-ad-reports') {
        $tab = isset($_GET['tab']) ? sanitize_text_field((string) $_GET['tab']) : '';
        $placement_key = isset($_GET['placement_key']) ? sanitize_text_field((string) $_GET['placement_key']) : '';
        $placement_key = trim($placement_key);

        if ($tab === 'placements' && $placement_key !== '') {
            $chart_js_path = AA_AD_MANAGER_PLUGIN_DIR . 'assets/js/vendor/chart.umd.min.js';
            $chart_js_ver = file_exists($chart_js_path) ? (string) filemtime($chart_js_path) : AA_AD_MANAGER_VERSION;

            wp_enqueue_script(
                'aa-ad-manager-chartjs',
                AA_AD_MANAGER_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js',
                array(),
                $chart_js_ver,
                true
            );

            $reports_js_path = AA_AD_MANAGER_PLUGIN_DIR . 'assets/js/ads/aa-admin-reports-placements.js';
            $reports_js_ver = file_exists($reports_js_path) ? (string) filemtime($reports_js_path) : AA_AD_MANAGER_VERSION;

            wp_enqueue_script(
                'aa-ad-manager-admin-reports-placements',
                AA_AD_MANAGER_PLUGIN_URL . 'assets/js/ads/aa-admin-reports-placements.js',
                array('jquery', 'aa-ad-manager-chartjs'),
                $reports_js_ver,
                true
            );
        }
    }
}
add_action('admin_enqueue_scripts', 'aa_ad_manager_enqueue_admin_assets');

function aa_ad_manager_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $options = get_option('aa_ad_manager_options', array());
    $excluded_roles = isset($options['exclude_tracking_roles']) && is_array($options['exclude_tracking_roles'])
        ? array_values(array_unique(array_map('sanitize_key', $options['exclude_tracking_roles'])))
        : array('administrator');

    $editable_roles = function_exists('get_editable_roles') ? get_editable_roles() : array();

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
    echo '          <p class="description" style="margin: 0 0 10px;">Ad impressions/clicks are logged automatically when ads are served/clicked. Use the settings below to exclude staff traffic from reporting.</p>';
    echo '          <table class="form-table" role="presentation">';
    echo '            <tbody>';
    echo '              <tr>';
    echo '                <th scope="row"><label>Exclude roles from tracking</label></th>';
    echo '                <td>';
    echo '                  <fieldset>';
    echo '                    <legend class="screen-reader-text"><span>Exclude roles from tracking</span></legend>';
    echo '                    <p class="description" style="margin: 0 0 8px;">Users with the selected roles will still see ads, but their impressions and clicks will not be written to the tracking tables.</p>';
    // Ensure the option key is present on save even if all boxes are unchecked.
    echo '                    <input type="hidden" name="aa_ad_manager_options[exclude_tracking_roles][]" value="" />';

    if (is_array($editable_roles) && !empty($editable_roles)) {
        // Render roles in a stable, admin-friendly order: Administrator, Editor, Author, Contributor, Subscriber, then others.
        $preferred_order = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
        $role_keys = array_keys($editable_roles);
        usort($role_keys, function($a, $b) use ($preferred_order) {
            $ai = array_search($a, $preferred_order, true);
            $bi = array_search($b, $preferred_order, true);
            $ai = ($ai === false) ? 999 : (int) $ai;
            $bi = ($bi === false) ? 999 : (int) $bi;
            if ($ai === $bi) {
                return strcmp((string) $a, (string) $b);
            }
            return $ai - $bi;
        });

        foreach ($role_keys as $role_key) {
            $role = $editable_roles[$role_key];
            $label = isset($role['name']) ? (string) $role['name'] : $role_key;
            $is_checked = in_array($role_key, $excluded_roles, true);

            echo '                    <label style="display:block; margin: 0 0 6px;">';
            echo '                      <input type="checkbox" name="aa_ad_manager_options[exclude_tracking_roles][]" value="' . esc_attr($role_key) . '" ' . checked($is_checked, true, false) . ' />';
            echo '                      <span>' . esc_html($label) . ' <span class="description">(' . esc_html($role_key) . ')</span></span>';
            echo '                    </label>';
        }
    } else {
        echo '                    <div class="notice notice-warning inline" style="margin: 0;">';
        echo '                      <p>Roles could not be loaded. Tracking exclusions are not configurable on this site.</p>';
        echo '                    </div>';
    }

    echo '                    <p class="description" style="margin: 8px 0 0;">Default recommendation: exclude <strong>Administrator</strong>. Optionally exclude <strong>Editor</strong> if staff members use that role. (For testing: uncheck all roles to include staff traffic.)</p>';
    echo '                  </fieldset>';
    echo '                </td>';
    echo '              </tr>';
    echo '            </tbody>';
    echo '          </table>';

    echo '        </div>';
    echo '      </div>';

    submit_button('Save Settings');

    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}

