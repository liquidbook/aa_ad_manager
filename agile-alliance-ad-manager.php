<?php
/**
 * Plugin Name: Agile Alliance Ad Manager
 * Description: Extracted Ad Manager (ads CPT, campaigns/clients taxonomies, AJAX injection, impression/click tracking).
 * Version: 0.1.0
 * Author: Agile Alliance
 * License: GPL-3.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

// Single source-of-truth feature flag for theme gating.
if (!defined('AA_AD_MANAGER_ACTIVE')) {
    define('AA_AD_MANAGER_ACTIVE', true);
}

define('AA_AD_MANAGER_VERSION', '0.1.0');
define('AA_AD_MANAGER_PLUGIN_FILE', __FILE__);
define('AA_AD_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AA_AD_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/db.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/cpt.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/taxonomies.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/shortcodes.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/ad-selection.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/ajax.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/admin-options.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/admin-reports.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/admin-performance.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/acf-json.php';
require_once AA_AD_MANAGER_PLUGIN_DIR . 'includes/compat.php';

register_activation_hook(__FILE__, 'aa_ad_manager_activate');

