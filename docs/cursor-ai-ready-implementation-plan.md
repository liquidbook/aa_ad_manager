## 0) North Star for Reporting v1

**Goal:** Make the site-wide Reports page align with the new “slot-driven truth” by supporting `placement_key` in filtering + aggregation, while staying fast and WP-native.

**Non-goals (v1):**

* No new dimensions stored (page_type/page_context remain not persisted). 
* No refactor into a whole REST app; keep the Reports page PHP-first (optionally sprinkle Chart.js later).

---

## 1) Current state (baseline to modify)

### What exists now

* Reports page: “Client Reports” tab shows a table grouped by `(ad_id, page_id)` and counts impressions/clicks, with filters for client + campaign + pagination + CSV export.  
* Data query: joins impressions → posts, left-joins clicks on `(ad_id,page_id)` and filters via taxonomy subqueries. 

### What’s missing

* Reports is **not placement-aware** (no placement filter, no grouping by placement_key). 
* But tracking tables already include `placement_key` (indexed), so we can build reporting directly on existing data. 

---

## 2) Reporting v1 UX changes (what to build)

### 2.1 Filters (Reports → Client Reports tab)

Add two new controls to the existing filter row:

1. **Date range** dropdown:

* Last 7 days
* Last 30 days
* Last 90 days
* All time

2. **Placement** dropdown:

* “All placements”
* “(none / legacy)” option that maps to `placement_key = ''` (campaign shortcodes) 
* List of known placement keys:

  * Prefer pulling from the placements CPT (`aa_placement`) meta `placement_key`
  * Optionally include keys discovered from logs in the selected date range (in case keys exist in logs but placement post was deleted)

**Preserve:** existing Client, Campaign, Records-per-page, Filter button, CSV export. 

---

### 2.2 Grouping mode (Reports table “shape”)

Add a “Group by” dropdown (default should be the current behavior to avoid surprise):

* **Ad + Page** (current v1 behavior)
* **Placement + Ad** (new)
* **Placement only** (new, summary)

Why these three:

* Keeps the current report intact.
* Adds a “placement-first truth” view without requiring new pages yet.
* Enables a clean “Placement Summary CSV” later.

---

### 2.3 Summary strip (top KPI bar)

Above the table, show:

* Total impressions (within current filter set)
* Total clicks
* CTR (clicks / impressions)
* Distinct ads served
* Distinct placements served (count distinct placement_key, optionally excluding empty)

Implementation should reuse the same WHERE logic as the table query, but aggregated.

---

### 2.4 Top 10 widgets (right under summary strip)

Add 3–4 small “Top 10” boxes (WP metabox style is fine):

* Top placements by impressions
* Top placements by clicks
* Top ads by clicks
* Top pages by clicks

**Important:** For CTR-based “Top” later, enforce a minimum impressions threshold (e.g., `impressions >= 100`) to avoid “1/1 = 100% champion” nonsense. (Keep threshold as a constant in PHP for now.)

---

## 3) Data/Query plan (how to implement safely + fast)

### 3.1 Tables and columns you should use

Use the plugin table helper and the actual schema:

* impressions: `aa_ad_impressions` includes `placement_key`, `impressed_at` 
* clicks: `aa_ad_clicks` includes `placement_key`, `clicked_at` 

Reports currently join clicks on `(ad_id,page_id)` only. That must become placement-aware when appropriate. 

---

### 3.2 Filter WHERE conditions (shared across all queries)

Build a reusable “filters → SQL” helper so Cursor can apply it consistently to:

* table query
* totals query
* top widgets
* CSV export query

**Filters:**

* client term id (optional)
* campaign term id (optional)
* date range (optional)

  * apply to impressions timestamp (primary driver)
  * and apply to clicks timestamp independently (so clicks don’t drift outside range)
* placement_key (optional)

  * special case: “legacy” means `placement_key = ''`

---

### 3.3 Grouping SQL patterns

#### A) Current mode: Ad + Page (but now optionally placement-filtered)

Return rows grouped by `(ad_id, page_id)` as now, but:

* apply placement filter if set
* apply date range filter if set
* clicks join must include placement_key if filtering by placement or grouping by placement

**Join rule guidance:**

* If grouping includes placement_key: join clicks on `(ad_id,page_id,placement_key)`
* If grouping does not include placement_key but placement filter is active: same as above
* If neither: keep current join `(ad_id,page_id)` for backward compatibility behavior

This avoids “clicks from other placements leak into this placement-filtered report.”

#### B) Placement + Ad

Group by `(placement_key, ad_id)`; page_id is not part of the group.

#### C) Placement only

Group by `placement_key` with totals.

---

### 3.4 Placement key resolution (labels/links)

In placement-based views, show a friendly label:

* If `placement_key` matches an `aa_placement` post’s `placement_key`, display the placement title and link to edit screen (like you already do elsewhere). 
* If not found: show the raw key.
* If empty string: show “(legacy / no placement_key)”

Do this in a **batched lookup** (no per-row queries). This is explicitly consistent with your “avoid per-row DB calls” guidance. 

---

## 4) UI implementation plan (what Cursor should change)

### 4.1 Update `includes/admin-reports.php`

In `aa_ad_manager_display_client_reports()`:

* Parse new GET params:

  * `range` (7/30/90/all)
  * `placement_key` (string; allow empty sentinel)
  * `group_by` (enum)
* Add these fields to the `<form>` that already renders filters for client/campaign/per_page.  
* Ensure filter form preserves current tab and pagination behavior.

### 4.2 Add new query functions (keep old ones, but route through new)

Create “v2” functions and keep the old ones as wrappers (minimizes break risk):

* `aa_ad_manager_get_report_rows($filters, $group_by, $paged, $per_page)`
* `aa_ad_manager_get_report_totals($filters)`
* `aa_ad_manager_get_report_top_widgets($filters)` returning arrays for the four widgets
* `aa_ad_manager_get_report_total_items($filters, $group_by)` for pagination

Then:

* replace calls to `aa_ad_manager_get_ad_report_data()` and `aa_ad_manager_get_total_ad_report_items()` with the new generalized versions. 

### 4.3 CSV export (if already present)

Ensure export uses the **same filters + grouping** as the onscreen report. (Cursor should avoid a “CSV uses different WHERE” bug.)

---

## 5) Placement drilldown page (phase 2, but spec it now)

### 5.1 Where it lives

Add a new subpage under Ad Manager (same menu group) or a new tab under Reports:

* `edit.php?post_type=aa_ads&page=aa-ad-reports&tab=placements`

### 5.2 Drilldown UX

* Table of placements (within range):

  * Placement, Impressions, Clicks, CTR, Distinct pages, Distinct ads
* Clicking a placement opens a detail view:

  * trends over time (impressions/clicks/CTR) using Chart.js (already bundled) 
  * “Top Ads in this placement”
  * “Top Pages in this placement”

### 5.3 Data contract reuse

Pattern it after the existing per-ad performance dashboard approach:

* Range dropdown
* Placement dropdown (here: locked to selected placement)
* Chart.js line charts
  This matches your existing “Performance tab” conventions and reduces new mental models. 

---

## 6) Acceptance criteria (so Cursor knows when it’s done)

### Placement-aware Reports page

* [ ] Placement filter works:

  * Selecting a placement_key only counts impressions/clicks for that placement_key
  * Selecting legacy shows only rows where `placement_key = ''` 
* [ ] Date range filter works (7/30/90/all)
* [ ] Group by modes work:

  * Ad+Page (existing look)
  * Placement+Ad
  * Placement only
* [ ] Summary strip totals match the table (same filter set)
* [ ] Top 10 widgets reflect the same filter set
* [ ] No per-row placement lookups (batched mapping)
* [ ] CSV export matches the current filter/grouping

### Performance

* [ ] One request should execute a small number of aggregated queries (not loops of queries)
* [ ] Pagination works without doing “COUNT(*) of the entire world” in a slow way

---

## 7) Notes for Cursor (implementation style guardrails)

* Keep changes **scoped**, avoid refactoring unrelated systems. 
* Use **aggregated GROUP BY queries** instead of per-placement loops. 
* Remember: `page_type`/`page_context` are intentionally not in DB. Don’t “fix” that in this reporting step. 


