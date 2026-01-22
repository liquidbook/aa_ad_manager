# Agile Alliance Ad Manager

A standalone WordPress plugin extracted from the Agile Alliance child theme to manage and track weighted advertising.

## Overview

The **Agile Alliance Ad Manager** provides a complete subsystem for managing banner advertisements. It features a weighted selection engine, impression gating, and AJAX-driven tracking to ensure accurate statistics even on sites with aggressive page caching.

## Features

- **Ad Management (CPT):** Manage ads as a custom post type (`aa_ads`) with specialized fields for creatives, links, and display rules.
- **Placements (CPT):** Manage placements/slots as a custom post type (`aa_placement`) and assign eligible ads directly to each placement.
- **Campaign Tracking:** Organize ads into campaigns (`aa_campaigns`) and clients (`aa_clients`).
- **Weighted Selection:** Control display frequency using a weighting system and enforce impression limits.
- **AJAX Delivery:** Ads are injected into pages via AJAX to bypass cache and ensure tracking triggers on every view.
- **Comprehensive Tracking:** Custom database tables track every impression and click with precision, including `placement_key` when served via a placement.
- **Staff Traffic Exclusions:** Optionally exclude selected logged-in roles from impression/click tracking (default: **Administrator**) via **Ad Manager → Ad Manager Options**.
- **Admin Reports:** Placement-aware Reports page with filters (client/campaign/date range/placement), multiple group-by modes, KPIs, Top lists, and CSV export.
- **Per-ad Performance (wp-admin):** Detailed charts and placement breakdown live on the individual ad edit screen (Performance tab).
- **Shortcode Integration:** Simple shortcodes for easy placement in Elementor or standard WordPress content.

## Technical Architecture

### 1. Data Model
The plugin manages two custom database tables:
- `wp_aa_ad_impressions`: Logs every time an ad is rendered.
- `wp_aa_ad_clicks`: Logs every time a user clicks on an ad creative.

Both tables include a `placement_key` column (empty for legacy campaign-based delivery, populated for `[aa_slot]` placements).

### 2. AJAX Contract
To maintain compatibility with cached environments, the plugin exposes two primary AJAX actions:
- `aa_get_ad`: Fetches an eligible ad based on size and campaign, logs the impression, and returns the HTML creative.
- `aa_log_click`: Logs the click event before the user is redirected to the destination URL.

### 3. ACF Integration
The plugin uses a **JSON load path** strategy for Advanced Custom Fields. Field definitions are shipped with the plugin and loaded via the `acf/settings/load_json` filter (see `includes/acf-json.php`).

This repo uses:
- `acf-json/`: ACF Local JSON (preferred, loaded via `acf/settings/load_json`)
- `acf-export/`: a shipped ACF export JSON used as a fallback via `acf_add_local_field_group()` when `acf-json/` is empty

## Usage

### Shortcodes
Place ads anywhere using the following shortcodes:

**Placements (recommended):**
```shortcode
[aa_slot key="sidebar_blog_articles"]
```

Notes:
- `key` must match a Placement’s **Placement Key**.
- The Placement’s **Assigned Ads** define the eligible pool; ad rotation still respects each ad’s `display_frequency` weight and eligibility rules.

**Wide Ads:**
```shortcode
[aa_display_wide_ad campaign="top-banner-main"]
```

**Square Ads:**
```shortcode
[aa_display_square_ad campaign="sidebar-campaign"]
```

### Theme Integration
The plugin defines a constant `AA_AD_MANAGER_ACTIVE`. When active, the Agile Alliance child theme is configured to skip its legacy ad manager code paths to avoid conflicts.

## Installation

1. Upload the `agile-alliance-ad-manager` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. On activation (and on subsequent plugin loads if needed), the plugin will automatically create/upgrade the necessary database tables if they do not exist.
4. Ensure **Advanced Custom Fields (Pro)** is installed and active, as it is a required dependency for the ad management interface.

## Developer Workflow

### Build a WordPress-installable ZIP (Windows-friendly)

This repo includes Python scripts that stage a clean plugin folder and then zip it in a WordPress-compatible format.

**Requirements**
- Python 3.12+ (tested with 3.12.6)

**Two-step build (recommended)**
1) Stage runtime files into `distribution/agile-alliance-ad-manager/`:

```bash
python scripts/stage_dist.py
```

2) Create `distribution/agile-alliance-ad-manager.zip`:

```bash
python scripts/zip_dist.py
```

**One-command build (hybrid)**
If the git working tree is clean, this prefers `git archive`. Otherwise it falls back to stage + zip:

```bash
python scripts/build_release.py
```

**Important**
- The resulting ZIP must contain a single top-level folder named `agile-alliance-ad-manager/` (WordPress expects this structure).
- `distribution/` is a build artifact and is intentionally gitignored.

## Analytics events (GTM/GA4)

Because ads are **AJAX-injected**, GA4 “Enhanced measurement” link-click tracking may not reliably detect ad clicks. The frontend loader emits analytics signals that work with both **Google Tag Manager** and **GA4-only** setups:

- **GTM**: pushes events to `window.dataLayer`
- **GA4-only**: if `window.gtag` exists, also calls `gtag('event', ...)` (with `transport_type: 'beacon'`)

Emitted events:
- **`aa_ad_impression`**: fired after the ad HTML is injected into `.aa-ad-container`
- **`aa_ad_click`**: fired on ad click (before redirect)

Event payload fields:
- `ad_id`, `placement_key`, `page_id`
- `destination_url`, `is_outbound`
- `creative_url` (image URL when available)
- `ad_size`, `page_type`, `page_context`

On a GTM-enabled site, create **Custom Event** triggers for `aa_ad_impression` and `aa_ad_click` and route them to GA4 events (e.g. `ad_impression`, `ad_click`) while mapping the payload fields as parameters.

### Docker test environment (quick start)
This repo includes a `docker-compose.yml` that spins up WordPress + MariaDB + phpMyAdmin and bind-mounts this plugin into the container.

1. Copy `env.example` to `.env` and adjust ports/DB credentials as needed.
2. Start the stack:

```bash
docker compose up -d
```

3. Visit WordPress at `http://localhost:${WP_PORT}` (default `8090`) and complete the installer.
4. In WP Admin, install/activate **Advanced Custom Fields** (required), then activate **Agile Alliance Ad Manager**.

### Asset Compilation
JavaScript and CSS assets are managed within the plugin structure:
- `assets/js/ads/aa-ad-loader.js`: The frontend engine that handles AJAX injection and click logging.
- `assets/js/ads/aa-admin-scripts.js`: Admin-side helpers (e.g., the "Copy Shortcode" button).
- `assets/js/ads/aa-admin-reports-placements.js`: Admin reports placement drilldown charts (uses bundled Chart.js).

## License

This plugin is licensed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html).

---
*Developed for Agile Alliance by Liquidbook.*
