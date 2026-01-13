<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility / safety:
 * - If the legacy theme code is loaded (shouldn't happen with gating), avoid fatals on redeclare.
 * - This file is intentionally light; theme gating should be the primary protection.
 */

/**
 * Legacy compat: theme template `templates/page-ad-redirect.php` calls `aa_log_ad_click($ad_id, wp_get_referer())`.
 * When the plugin is active, the theme Ad Manager files are gated off, so this shim prevents fatals.
 */
if (!function_exists('aa_log_ad_click')) {
    function aa_log_ad_click($ad_id, $maybe_page_id_or_referer = 0, $maybe_referer = '') {
        $ad_id = (int) $ad_id;
        $page_id = 0;
        $referer = '';

        if (is_int($maybe_page_id_or_referer) || ctype_digit((string) $maybe_page_id_or_referer)) {
            $page_id = (int) $maybe_page_id_or_referer;
            $referer = is_string($maybe_referer) ? $maybe_referer : '';
        } else {
            $referer = is_string($maybe_page_id_or_referer) ? $maybe_page_id_or_referer : '';
        }

        aa_ad_log_click($ad_id, $page_id, $referer);
    }
}

