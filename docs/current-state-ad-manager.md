# Agile Alliance Ad Manager — Current-State Technical & Product Review

**Plugin:** Agile Alliance Ad Manager (`agile-alliance-ad-manager.php`)  
**Type:** Standalone WordPress plugin (not a theme)  
**Core purpose:** Manage ads (creative + rules), deliver them reliably on cached pages via AJAX injection, and track impressions/clicks with placement-aware analytics.

**Last updated:** 2026-01-22  
**Plugin version constant:** `AA_AD_MANAGER_VERSION = 0.1.0`  
**DB schema target version:** `aa_ad_manager_db_schema_version = 2026-01-17-placement-key`

---

## What this plugin does (end-to-end)

At runtime, the plugin provides:

- **Two ways to place ads on the frontend**
  - **Legacy/campaign-based shortcodes**
    - `[aa_display_wide_ad campaign="..."]`
    - `[aa_display_square_ad campaign="..."]`
  - **Placement v1 shortcode** (recommended)
    - `[aa_slot key="PLACEMENT_KEY"]`

- **A consistent delivery method**
  - Shortcodes render an empty **placeholder container** (`.aa-ad-container`) with metadata in `data-*` attributes.
  - A frontend loader (`assets/js/ads/aa-ad-loader.js`) replaces placeholders with the actual `<a><img></a>` creative by calling WordPress AJAX.
  - This design intentionally bypasses full-page caches: the HTML is loaded on the client for each view.

- **Tracking and analytics**
  - Every served creative logs an **impression** server-side into a custom DB table.
  - Every click logs a **click** server-side into a custom DB table.
  - When served via a placement, tracking records include the **`placement_key`** (critical for placement-aware reporting).
  - The frontend also emits **client-side analytics events** (`aa_ad_impression`, `aa_ad_click`) to `dataLayer` (GTM) and `gtag` (GA4-only sites).

- **A comprehensive wp-admin experience**
  - **Ads list table enhancements**: copyable shortcodes, thumbnails with modal preview, per-ad statistics, “Placements” column, sortable taxonomy columns.
  - **Placement management (Placements v1)**: CPT, list table with status + assigned ad counts + copy shortcode, key safety guardrails, key auto-generation and uniqueness validation.
  - **Ad performance dashboards**: per-ad metaboxes and a full “Performance” tab with charts, placement filters, and breakdowns.
  - **Reports page**: placement-aware reporting with filters, multiple group-by modes, KPIs, “Top” widgets, and a Placements drilldown (Chart.js).

---

## Core architecture: where functionality lives

Plugin bootstrap:

- `agile-alliance-ad-manager.php`: defines constants, includes subsystems, registers activation hook.

Primary subsystems:

- **DB + tracking**: `includes/db.php`
- **Ads CPT + list-table UX + per-ad stats meta box + admin assets**: `includes/cpt.php`
- **Placements v1 CPT + admin UX + key rules/validation**: `includes/placements.php`
- **Taxonomies (campaigns/clients)**: `includes/taxonomies.php`
- **Shortcodes (placeholders + placement resolution cache)**: `includes/shortcodes.php`
- **Ad selection (eligibility + weighting)**: `includes/ad-selection.php`
- **AJAX endpoints (serve ad HTML + log click)**: `includes/ajax.php`
- **Admin settings screen**: `includes/admin-options.php`
- **Admin reports screen**: `includes/admin-reports.php`
- **Ad edit “Performance” tab + charts API + placement breakdown**: `includes/admin-performance.php`
- **ACF integration (local JSON + export fallback)**: `includes/acf-json.php`
- **Legacy compatibility shim**: `includes/compat.php`

Frontend/admin assets:

- Frontend: `assets/js/ads/aa-ad-loader.js`, `assets/css/frontend.css`
- Admin: `assets/js/ads/aa-admin-scripts.js`, `assets/js/ads/aa-admin-performance.js`, `assets/js/ads/aa-admin-reports-placements.js`, `assets/css/admin.css`
- Vendor: `assets/js/vendor/chart.umd.min.js` (Chart.js bundle)

Release/packaging tooling:

- `scripts/stage_dist.py`: stage a clean plugin folder into `distribution/agile-alliance-ad-manager/`
- `scripts/zip_dist.py`: zip the staged folder into a WP-installable zip
- `scripts/build_release.py`: hybrid builder (prefers `git archive` when repo is clean; otherwise stages + zips)
- Build output: `distribution/` (gitignored)

---

## Data model: entities and relationships

### Ads (CPT): `aa_ads`

- **Post type**: `aa_ads`
- **Visibility**: admin UI only (not publicly queryable)
- **Purpose**: represents a single ad creative plus its eligibility rules.
- **Primary admin screen**: “Ad Manager → All Ads”

#### Ads fields (ACF group: “AA Ads Fields”)

Loaded from `acf-json/group_66f94f5437d40.json`.

- **Creative**
  - `ad_image` (required, image ID)
  - `ad_title` (required, used as `<img alt>`)
  - `ad_link` (required, URL)
  - `ad_new_tab` (optional, boolean; intended to control `target="_blank"` and `rel="noopener noreferrer"`)
    - **Important current behavior:** the frontend click handler in `assets/js/ads/aa-ad-loader.js` calls `e.preventDefault()` and redirects with `window.location.href`, which means the link currently opens in the **same tab** even if `target="_blank"` is present.

- **Eligibility + delivery rules**
  - `ad_status` (required: `active`/`inactive`)
  - `ad_size` (select: `wide` or `square`; affects which shortcode is generated and size filtering)
  - `display_frequency` (required number, default 1; used as weight in rotation)
  - `ad_start_date` (required date picker)
  - `ad_end_date` (optional date picker)
  - `impression_max` (optional number; enforced against the impressions table)

#### Ads taxonomies

Defined in `includes/taxonomies.php`.

- `aa_campaigns` (non-hierarchical)
- `aa_clients` (non-hierarchical)

These taxonomies:

- Provide organization and filtering in wp-admin.
- Are used for **legacy campaign-based delivery** (`campaign` shortcode attr) and **report filtering**.
- Are not required for placement-based delivery (placements bypass `campaign` in the delivery request).

---

### Placements v1 (CPT): `aa_placement`

Placements represent the “slot/shelf” where ads can appear. The core product value is:

- a stable, copyable shortcode
- an assigned pool of eligible ads
- placement-aware tracking (`placement_key`)

Defined in `includes/placements.php`.

- **Post type**: `aa_placement`
- **Visibility**: admin UI only; appears as a submenu under “Ad Manager” (`show_in_menu = edit.php?post_type=aa_ads`)
- **Supports**: title only (fields handled through ACF)

#### Placement fields (ACF group: “AA Placement Fields”)

Loaded from `acf-json/group_aa_placements_v1.json`.

- `placement_key` (required text; stable identifier used by shortcode + tracking)
- `placement_active` (true/false; inactive placements render nothing)
- `placement_size` (select: `wide`, `square`, `custom`)
  - Not “hard enforcement” of creative size; it only influences what size the shortcode requests.
- `placement_description` (optional internal notes)
- `assigned_ads` (relationship to `aa_ads`; stored as IDs; this is the eligibility pool)

#### Placement key safety and uniqueness

Placements v1 includes multiple layers to keep `placement_key` safe and stable:

- **ACF validation** (`acf/validate_value/name=placement_key`)
  - Requires non-empty keys and enforces uniqueness across `aa_placement` posts.
- **Admin JS auto-generation**
  - On placement edit screens, `aa-admin-scripts.js` can generate a slug-like key from the title.
  - It checks server availability via `wp_ajax_aa_validate_placement_key` and auto-suffixes (`_2`, `_3`, …) if needed.
- **Change warning**
  - If a placement’s key changes after it previously existed, a one-time admin warning is shown: changing the key can break shortcodes already embedded in pages/templates.

---

## Tracking and persistence: database tables

### Why custom tables exist

Tracking is designed to be:

- **fast to write** (simple inserts)
- **easy to aggregate** (COUNT with indexes)
- **independent from post meta** (scales better than “counter in meta”)
- **placement-aware** (key for placement reporting)

### Tables

Created/ensured by `includes/db.php` via `dbDelta()`:

#### `{$wpdb->prefix}aa_ad_impressions`

- `id` (PK)
- `ad_id` (indexed)
- `page_id` (indexed)
- `placement_key` (varchar(191), indexed, default '')
- `impressed_at` (datetime)

#### `{$wpdb->prefix}aa_ad_clicks`

- `id` (PK)
- `ad_id` (indexed)
- `page_id` (indexed)
- `placement_key` (varchar(191), indexed, default '')
- `referer_url` (varchar(255), default '')
- `clicked_at` (datetime)

### Schema management behavior

`includes/db.php` does not rely solely on activation:

- On activation: `aa_ad_manager_activate()` calls `aa_ad_manager_create_tables_if_missing()`.
- On every load: `plugins_loaded` checks `aa_ad_manager_db_schema_version`; if it differs from the target, it runs `dbDelta()` again and updates the version option.

This is important in real deployments because “updated plugin files without deactivating/reactivating” is common.

### What gets logged, when

- **Impression** is logged server-side in `aa_ad_manager_ajax_get_ad()` after an eligible ad is selected and validated, right before returning HTML:
  - `aa_ad_log_impression($ad->ID, $page_id, $placement_key)`
- **Click** is logged server-side in `aa_ad_manager_ajax_log_click()`:
  - `aa_ad_log_click($ad_id, $page_id, $referer_url, $placement_key)`

### Staff traffic exclusions (role-based)

To protect reporting integrity, tracking writes can exclude logged-in “staff” roles:

- **Setting**: `aa_ad_manager_options[exclude_tracking_roles]` (role slugs)
  - Default: `administrator`
  - Can be configured in wp-admin under **Ad Manager → Ad Manager Options**
- **Enforcement point**: `includes/db.php`
  - `aa_ad_log_impression()` / `aa_ad_log_click()` early-return when the current user’s role is excluded.
  - A filter hook `aa_ad_manager_should_log_tracking_event` can override the decision.

### Important: `page_type` and `page_context`

The frontend passes `page_type` and `page_context` through AJAX, but the server code explicitly treats them as “accepted but ignored” because production/staging schema did not include columns for them. They are still available in frontend analytics payloads (GTM/GA4), but not persisted in DB tables.

---

## Frontend delivery model (how ads render on the site)

### The placeholder contract

All shortcodes ultimately return the same kind of container:

- `<div class="aa-ad-container" ...data attributes...></div>`

Attributes include:

- `data-ad-size` (`wide`, `square`, or `random` for placements)
- `data-campaign` (legacy/campaign shortcodes only)
- `data-placement-key` (placements only)
- `data-page-id` (best-effort)
- `data-page-type` and `data-page-context` (computed by the plugin)
- `data-ajax-url` (admin-ajax.php; also available via localized settings)

The shortcode helper `aa_ad_manager_compute_page_context()` computes a coarse context:

- search: `page_type = search`, `page_context = search query`
- home/front page: `page_type = home`, `page_context = home_index`
- post type archive: `page_type = post_type_archive`, `page_context = post type name`
- other archives: `page_type = archive`, `page_context = general_archive`
- otherwise: `page_type = singular`

### The AJAX “get an ad” flow

`assets/js/ads/aa-ad-loader.js`:

1. Finds every `.aa-ad-container`.
2. Reads container metadata (size/campaign/placement key/page metadata).
3. Sends a **GET** request to `admin-ajax.php`:
   - `action=aa_get_ad`
   - includes nonces from `wp_localize_script` (`aaAdSettings.nonce_get_ad`)
4. On success, injects `response.data.ad_html` into the container.
5. Fires a frontend analytics event `aa_ad_impression` after injection.
6. Attaches a click handler that:
   - fires `aa_ad_click` analytics immediately
   - sends a **POST** to `action=aa_log_click`
   - redirects to the destination URL even if logging fails

This flow is intentional:

- Pages can be cached aggressively, but the AJAX call happens per view.
- Click logging uses `complete:` to ensure redirect reliability.

### HTML that is returned by the server

The server returns HTML like:

- `<a href="DEST" class="aa-ad-click" data-ad-id="..." data-page-id="..." data-placement-key="..."><img src="..." alt="..."></a>`

The click handler relies on `.aa-ad-click` and its `data-*` fields.

### Frontend CSS behavior

`assets/css/frontend.css` ensures ads are responsive:

- containers and images use `max-width: 100%` and `height: auto`.

---

## Selection and eligibility logic (how the server chooses an ad)

Selection happens inside the `aa_get_ad` AJAX action (`includes/ajax.php`) and depends on whether the request is placement-driven.

### Legacy path: campaign-based selection

If `placement_key` is empty:

- `aa_get_weighted_random_ad($ad_size, $campaign)` is used (`includes/ad-selection.php`)

That function:

1. Queries published `aa_ads` with `ad_status=active`.
2. If a concrete size is requested, also filters on `ad_size`.
3. If `campaign` is provided, restricts to ads in taxonomy term `aa_campaigns` with slug matching the campaign value.
4. Applies eligibility rules (ACF-aware):
   - date window check (`ad_start_date`/`ad_end_date` compared against today)
   - `impression_max` check enforced against the impressions table via `aa_ad_get_impression_count($ad_id)`
5. Builds a weighted list based on `display_frequency` and picks a random element.

### Placement path: selection from assigned ads

If `placement_key` is provided:

1. Resolve the placement post ID by key.
   - `aa_ad_manager_get_placement_id_by_key()` caches the mapping for 10 minutes in object cache:
     - cache key: `aa_placement_id_by_key:{placement_key}` in group `aa_ad_manager`
2. Confirm the placement is active.
3. Load `assigned_ads` relationship and normalize it to an array of ad IDs.
4. Run selection:
   - `aa_get_weighted_random_ad_from_ids($assigned_ids, $ad_size)`

Eligibility rules are the same as the legacy path:

- `ad_status=active`
- optional size filter (`wide`/`square`)
- date window (if ACF available)
- impression cap (if ACF available)
- weighted rotation via `display_frequency`

### Placement size behavior

`[aa_slot key="..."]` requests a size derived from the placement metadata:

- If placement size is `wide` or `square`, the shortcode requests that size.
- Otherwise it requests `random` (meaning “no size filter server-side”).

This matches the “v1 defaults” philosophy: allow mixed pools and avoid strict enforcement beyond request-level filtering.

---

## wp-admin UX: Ads (All Ads list + edit screen)

### Ads list table enhancements (`edit.php?post_type=aa_ads`)

Implemented in `includes/cpt.php`.

#### Custom columns added

- **Shortcode**
  - Generated based on the ad’s `ad_size` and the first campaign term slug (if any).
  - Includes a “Copy” button (uses `navigator.clipboard`).
- **Placements**
  - Shows the placements that currently reference this ad through `assigned_ads`.
  - Renders up to 2 placements (linked to edit screen) then shows “+N”.
  - Built to avoid per-row queries:
    - scans placement meta once and builds an in-memory mapping of `ad_id => placements[]`.
- **Statistics**
  - Shows:
    - impressions
    - clicks
    - CTR (computed)
    - a “Target link: Show” button that opens a modal with the destination URL + copy button.
  - Also optimized:
    - runs at most two grouped queries (impressions + clicks) for all ads in the current list page.
- **Ad Image**
  - Thumbnail button opens a lightweight modal with the full image (no thickbox dependency).

#### Sortable taxonomy columns

The ads list supports sorting by:

- Campaigns (`taxonomy-aa_campaigns`)
- Clients (`taxonomy-aa_clients`)

Sorting implementation:

- Flags the main admin query when `orderby` is `aa_campaigns` or `aa_clients`.
- Uses `posts_clauses` to add joins and order by `MIN(term.name)` for stable “first-term” sorting.

### Ads edit screen: tabs and metaboxes

The ad edit screen includes custom tabs controlled by the query param `aa_tab`:

- `aa_tab=fields` (default)
- `aa_tab=performance`

The UI is implemented in `includes/admin-performance.php` and styled in `assets/css/admin.css`:

- Tabs render under the title.
- CSS hides the inside contents of irrelevant panels per tab without triggering WP’s “metabox hidden” persistence.

#### Statistics metabox (side)

Implemented in `includes/cpt.php`:

- All-time impressions/clicks/CTR
- Last 30 days impressions/clicks/CTR

#### Placements metabox (side, Fields tab)

Implemented in `includes/admin-performance.php`:

- **Assigned placements**
  - Placements whose `assigned_ads` relationship contains this ad ID.
- **Delivered placements (Last 30 days)**
  - Aggregates tracking logs grouped by `placement_key`.
  - Resolves `placement_key -> placement post` best-effort (batched query).
  - Shows impressions/clicks/CTR per placement key.
  - If assigned placements exist but delivered placements are empty, it shows a helpful note (common during rollout).

#### Performance metabox (normal, Performance tab)

This is a full dashboard embedded on the ad edit screen.

**UI components:**

- Filters:
  - **Placement dropdown** (“All placements” + keys discovered from recent delivery; fallback to assigned placements)
  - **Range dropdown** (7/30/90/all-time)
  - **Top pages metric** (Clicks vs CTR)
- Charts (Chart.js):
  - Impressions & clicks over time (line chart)
  - CTR over time (line chart)
- Summary:
  - Total impressions, total clicks, average CTR
  - Top page label, top CTR page label
- Lists/tables:
  - Top pages list (supports sorting by clicks/CTR in UI)
  - Placement breakdown table (impressions/clicks/CTR by placement key)

**Data contract:**

- JS (`assets/js/ads/aa-admin-performance.js`) calls admin AJAX:
  - `action=aa_ad_manager_get_ad_performance`
  - `nonce` (nonce: `aa_ad_perf_nonce`)
  - `ad_id`
  - `range` (days or `all`)
  - `placement_key` (optional filter)

**Security/capabilities:**

- Nonce verification is required.
- User must have `edit_post` capability for the ad.

---

## wp-admin UX: Placements v1 (Placements list + edit screen)

### Placement list table (`edit.php?post_type=aa_placement`)

Implemented in `includes/placements.php`.

Columns are intentionally editorial and “copy/paste friendly”:

- **Placement Key**: `placement_key` (or em dash if missing)
- **Shortcode**: displays `[aa_slot key="..."]` with a “Copy” button
- **Assigned Ads**: count of related ad IDs
- **Status**: Active/Inactive badge

### Placement edit screen

Key UX features:

- A **Shortcode** metabox in the sidebar provides a persistent copyable shortcode preview.
- Safety notice appears after changing a key:
  - “Placement key changed. Changing the key may break existing pages that use the shortcode.”

### Placement key generation and validation

There are three coordinated mechanisms:

- **Client-side** (admin JS):
  - When the placement title changes, the script generates a slug-like key (lowercase, underscores, stripped punctuation).
  - It checks uniqueness by calling `wp_ajax_aa_validate_placement_key`.
  - If taken, it automatically tries `_2`, `_3`, etc.
- **Server-side ACF validation**:
  - ACF filter enforces required + unique.
- **Key-change tracking**:
  - The plugin stores `_aa_last_saved_placement_key`.
  - If a subsequent save changes the key, it sets a meta flag to show the warning notice once.
  - It also clears the object-cache mapping for the old/new key.

---

## Admin reports & settings (site-wide views)

### Ad Manager Options

Admin submenu: “Ad Manager Options” under Ad Manager.

Current behavior:

- The UI is intentionally minimal and WP-native (metabox look).
- It allows configuring **tracking exclusions** so selected logged-in roles (default: **Administrator**) do not write impressions/clicks into the tracking tables.

Underlying stored options:

- `aa_ad_manager_options[excluded_post_types]` exists but is not exposed in the UI yet.
- `aa_ad_manager_options[exclude_tracking_roles]` stores a list of role slugs to exclude from tracking writes.
- There is back-compat migration logic for a legacy `reportable_post_types` key:
  - if present, it is converted into an exclusion list then removed.

### Ad Manager Reports

Admin submenu: “Ad Manager Reports” under Ad Manager.

Current tabs:

- **Client Reports**
  - Purpose: site-wide reporting over impressions/clicks with placement-aware filtering and aggregation.
  - Filters:
    - Client (term) and Campaign (term)
    - Date range: `7`, `30`, `90`, `all`
    - Placement: All, “(none / legacy)” (empty `placement_key`), or a specific placement key
    - Group-by (report shape):
      - **Ad + Page** (legacy/default view)
      - **Placement + Ad**
      - **Placement only** (summary)
  - Includes:
    - Summary KPI strip (impressions, clicks, CTR, distinct ads, distinct placements)
    - “Top 10” widgets (placements by impressions/clicks, ads by clicks, pages by clicks)
    - Pagination
    - CSV export that matches the current filters + grouping
  - Query implementation notes:
    - Uses aggregated GROUP BY queries against the tracking tables (impressions/clicks) and left-joins aggregated clicks into impressions-aligned groups.
    - Avoids per-row placement lookups by using batched placement-key resolution.

- **Placements**
  - Purpose: placement-first reporting and drilldown.
  - Overview table grouped by `placement_key`:
    - Impressions, Clicks, CTR, Distinct Pages, Distinct Ads
  - Drilldown view (click a placement):
    - Trend charts (impressions/clicks/CTR) rendered with Chart.js.
    - Top Ads in placement (by clicks)
    - Top Pages in placement (by clicks)
  - Chart assets:
    - Uses `assets/js/vendor/chart.umd.min.js` and `assets/js/ads/aa-admin-reports-placements.js`
    - These scripts are enqueued only on the Placements drilldown view.

- **Ad Performance** (hidden)
  - The Reports UI no longer renders an “Ad Performance” tab.
  - Per-ad performance is implemented on the **ad edit screen** (Performance tab) and is the recommended place for deep ad-specific analytics.

---

## Analytics events (GTM / GA4)

Because ads are AJAX-injected, generic “link click” tracking can be unreliable. The loader emits explicit events:

- `aa_ad_impression` (after ad HTML is injected)
- `aa_ad_click` (immediately on click, before POST + redirect)

Event emission:

- Always pushes `{ event: EVENT_NAME, ...payload }` to `window.dataLayer` (creating it if missing).
- Also calls `gtag('event', EVENT_NAME, payload)` if `window.gtag` exists, using `transport_type: 'beacon'`.

Payload fields (best-effort):

- `ad_id`
- `page_id`
- `placement_key`
- `destination_url`
- `is_outbound` (computed via URL origin comparison)
- `creative_url` (image URL if present)
- `ad_size`
- `page_type`, `page_context`

This provides a clean bridge:

- GTM users can create “Custom Event” triggers for these names.
- GA4-only users can rely on `gtag` events directly.

---

## Performance & scaling considerations baked into the implementation

Several areas are explicitly optimized to keep wp-admin and delivery snappy:

- **Placement key resolution** is cached (object cache, 10 minutes).
- **Ads list table** avoids per-row queries for:
  - placement links (prefetched by scanning placement meta once)
  - impression/click counts (two grouped queries per page)
- **Performance charts**:
  - range parsing caps accidental huge scans (up to ~10 years when using day ranges)
  - daily series uses grouped queries and (when range is days-based) fills a continuous date axis for nicer charts.

Logging is lightweight:

- single insert per impression
- single insert per click
- indexes on `ad_id`, `page_id`, and `placement_key`

---

## Security model (what is protected and how)

### Frontend AJAX endpoints

- `aa_get_ad`
  - requires nonce: `aa_ad_nonce`
  - supports both logged-in and logged-out users
- `aa_log_click`
  - requires nonce: `aa_ad_click_nonce`
  - supports both logged-in and logged-out users

### Admin-only AJAX endpoints

- `aa_validate_placement_key`
  - requires login + `edit_posts`
  - requires nonce: `aa_admin_nonce`
- `aa_ad_manager_get_ad_performance`
  - requires nonce: `aa_ad_perf_nonce`
  - requires `edit_post` capability for the requested ad

---

## Backward compatibility and integration safety

### Theme gating / plugin identity

The plugin defines `AA_AD_MANAGER_ACTIVE` as a single source-of-truth flag intended to prevent legacy theme ad-manager paths from double-loading or conflicting.

### Legacy click logging shim

`includes/compat.php` defines `aa_log_ad_click()` if it doesn’t already exist. This protects against fatals if legacy theme templates call it while the plugin is active, and maps the call through to `aa_ad_log_click()` in this plugin.

---

## Practical “how to use it” (current workflow)

### Recommended: Placements v1 workflow (editors/admins)

1. Go to **Ad Manager → Placements**.
2. Create a Placement:
   - set title
   - confirm auto-generated `placement_key` (or edit it, understanding it’s a stable identifier)
   - set Active/Inactive
   - optionally set Placement Size (wide/square/custom)
   - assign eligible ads via “Assigned Ads”
3. Copy the shortcode from:
   - the Placements list table, or
   - the Placement “Shortcode” sidebar metabox
4. Paste the shortcode into Elementor/template/block:

```text
[aa_slot key="sidebar_blog_articles"]
```

### Legacy/campaign workflow (still supported)

If a site already uses campaign-based shortcodes:

```text
[aa_display_wide_ad campaign="top-banner-main"]
[aa_display_square_ad campaign="sidebar-campaign"]
```

Selection is based on:

- active ads
- size match
- campaign term slug match
- date window + impression cap + weight

Tracking still works, but `placement_key` is blank for these impressions/clicks.

---

## What changed with Placements v1 (impact summary)

Placements v1 is not just a new CPT—it changes the delivery and reporting model in meaningful ways:

- **Delivery source of truth shifts from campaign terms → placement assignments**
  - placements decide which ads are eligible (`assigned_ads`)
  - campaign filtering is bypassed for placement delivery
- **Tracking becomes placement-aware**
  - `placement_key` is written into both impressions and clicks tables
  - per-ad wp-admin performance UI can now filter by placement and show placement breakdowns
- **Admin UX becomes “slot-driven”**
  - list tables and metaboxes surface placement context:
    - Ads list shows which placements each ad is assigned to
    - Ad edit screen shows assigned + delivered placements
    - Placement list shows assigned ad counts and a stable shortcode
- **Front-end markup stays compatible**
  - regardless of shortcode type, the loader contract remains the same:
    - placeholder → AJAX → injected creative
  - placement metadata is an additive data attribute + request parameter.

---

## Known constraints and intentionally “out of scope” behaviors (current state)

This section captures what the plugin currently does *not* do, by design (or by current implementation stage):

- **No server-side storage of `page_type` / `page_context`**
  - They are passed to AJAX and emitted in frontend analytics payloads, but not persisted to DB schema.
- **`ad_new_tab` is not currently honored on click**
  - The field is stored and the server outputs `target="_blank"`, but the click handler forces same-tab navigation (see note in Ads fields).
- **No rule engine for placements beyond assigned ads**
  - No post-type targeting, taxonomy targeting, audience rules, etc. (v1 intentionally stays simple).
- **No auto-injection via theme hooks**
  - Placement is intended to be embedded via shortcode in Elementor/templates/blocks.

---

## Release packaging: producing a WP-installable ZIP

This repo includes Windows-friendly Python scripts to create a WordPress-installable zip with the correct folder structure.

Output:

- Staged folder: `distribution/agile-alliance-ad-manager/`
- Installable zip: `distribution/agile-alliance-ad-manager.zip`

Commands:

```bash
python scripts/stage_dist.py
python scripts/zip_dist.py
```

Hybrid (single command):

```bash
python scripts/build_release.py
```

**Important:** WordPress expects the zip root to contain a single folder named `agile-alliance-ad-manager/` (not a flat zip, and not `distribution/...` nested).

## Reference: important hooks, actions, and endpoints

### Public shortcodes

- `aa_display_wide_ad`
- `aa_display_square_ad`
- `aa_slot`

### Public AJAX actions

- `wp_ajax_nopriv_aa_get_ad` / `wp_ajax_aa_get_ad`
- `wp_ajax_nopriv_aa_log_click` / `wp_ajax_aa_log_click`

### Admin AJAX actions

- `wp_ajax_aa_validate_placement_key`
- `wp_ajax_aa_ad_manager_get_ad_performance`

### Key admin menus

- `edit.php?post_type=aa_ads` (All Ads)
- `edit.php?post_type=aa_placement` (Placements)
- `edit.php?post_type=aa_ads&page=aa-ad-manager-options` (Options)
- `edit.php?post_type=aa_ads&page=aa-ad-reports` (Reports)

