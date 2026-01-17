# Cursor Plan Seed: Placement-Aware Performance UI (First Wins)

Goal: Now that Placements are implemented, surface “where this ad ran” in the admin UI and make existing performance charts filterable by placement, with minimal UI change and maximum practical value.

## Scope (First Wins Only)
1) Add a Placements surface on the **Ad edit screen** (right sidebar metabox).
2) Add a **Placement filter** to the existing **Performance tab** that reuses the same 3 charts.
3) Add a compact **Placement Breakdown** card (last 30 days) on the Performance tab.
4) Add a **Placements column** to the Ads list table (optional in this pass, but recommended if easy).

Avoid: new report pages, major DB migrations, complex rules engines.

---

## Preconditions / Assumptions to Verify
- Impression and click tracking records include a `placement_key` (or equivalent) for events served via `[aa_slot key="..."]`.
- There is a Placement CPT with `placement_key` and a relationship field `assigned_ads`.
- There are existing queries powering:
  - Ad edit “Statistics” box (all-time + last 30 days)
  - Performance tab charts (impressions/clicks over time, CTR over time, top pages)
- Existing chart rendering is already working (Chart.js or similar).

If `placement_key` is not stored for events, add it first (required for everything below).

---

## Deliverable 1: Ad Edit Screen “Placements” Metabox (Right Sidebar)

### UX requirements
- New metabox title: **Placements**
- Show:
  - **Assigned placements**: list of placement titles (linked to placement edit), derived from Placement CPT relationship `assigned_ads` containing this ad.
  - **Delivered placements (Last 30 Days)**: list of placement_keys (or titles if resolvable), derived from tracking logs grouped by placement_key for last 30 days.
  - Optional: a short warning if assigned > 0 but delivered = 0 last 30 days.

### Implementation notes
- Add metabox on `aa_ads` edit screen.
- For “Assigned placements”: query `aa_placement` posts where relationship field contains current ad ID.
- For “Delivered placements”: query tracking data `WHERE ad_id = X AND date >= now-30days GROUP BY placement_key ORDER BY impressions DESC LIMIT N`.
- Resolve placement_key → placement post title when possible (lookup by meta placement_key).
- Keep it read-only (no editing here in v1).

Acceptance:
- Metabox renders fast and never throws errors if no data.
- Links work.

---

## Deliverable 2: Performance Tab Placement Filter (Reuse Existing Charts)

### UX requirements
- Add a dropdown at top-right of Performance box:
  - Label: **Placement**
  - Default: **All placements**
  - Options: placements where this ad has either:
    - delivered events in last 30 days (preferred), OR
    - assigned placements (fallback if no delivery data)
- When a placement is selected, the 3 existing performance visuals update:
  - Impressions & Clicks Over Time
  - CTR Over Time
  - Top Pages by Clicks (and/or CTR)

### Implementation notes
- Prefer server-side endpoint returning chart JSON for:
  - `ad_id`, `date_range`, optional `placement_key`
- If existing endpoint already returns chart data, extend it with `placement_key` param and add `WHERE placement_key = ?` when present.
- Ensure “All placements” uses current behavior (no filter).
- Date range selector (Last 30/90/etc) should also apply (if you have it).

Acceptance:
- Selecting a placement refreshes charts without full page reload (AJAX).
- All placements remains identical to current output.

---

## Deliverable 3: Placement Breakdown Card (Last 30 Days)

### UX requirements
Add a compact card/grid on the Performance tab:

Title: **Placement Breakdown (Last 30 Days)**

Rows (sorted by impressions desc):
- Placement Name (fallback to placement_key)
- Impressions
- Clicks
- CTR

Optional small enhancement:
- Make placement name clickable to set the placement filter (same dropdown).

### Implementation notes
- New endpoint query (or extend same performance endpoint) to return:
  - `SELECT placement_key, SUM(impressions) AS impr, SUM(clicks) AS clicks FROM … WHERE ad_id = ? AND date>=… GROUP BY placement_key`
- CTR computed as clicks/impressions (guard divide-by-zero).
- If impressions and clicks are in separate tables:
  - Use two grouped queries and merge in PHP (map by placement_key).
- Display top N (e.g., 10) to avoid huge tables.

Acceptance:
- Card renders with zero placements gracefully (show “No placement activity in selected range.”).
- Values match totals when summing across placements.

---

## Deliverable 4 (Optional): Ads List Table “Placements” Column

### UX requirements
- Add column **Placements** to `All Ads` list table.
- Display: a short list of placement titles this ad is assigned to (or delivered in last 30 days), truncated with “+N” if many.
- Keep it lightweight (no heavy per-row queries).

### Implementation notes
- If implementing, do it efficiently:
  - Preload placement assignments for all ads in view using one query (or cached mapping).
  - Avoid per-row DB calls.
- Prefer “assigned placements” for list table (fast and stable).
- Delivered placements can be v2.

Acceptance:
- No noticeable slowdown in list view.

---

## Data / Query Requirements (Cursor should implement)
Implement functions that can be reused across UI:

1) `aa_get_assigned_placements_for_ad($ad_id): array`
- returns placement posts (id, title, placement_key)

2) `aa_get_delivered_placements_for_ad($ad_id, $days=30): array`
- returns placement_key + impressions + clicks (merged)

3) `aa_get_ad_timeseries($ad_id, $range, $placement_key=null): {labels[], impressions[], clicks[], ctr[]}`
- returns data for charts with optional placement filter

4) `aa_get_top_pages($ad_id, $range, $placement_key=null): array`
- returns top pages by clicks/ctr with optional placement filter

---

## Minimal UI/Code Touchpoints (Likely Files)
Cursor should search and update where appropriate:
- Admin UI for ad edit screen and performance tab rendering
- Existing AJAX endpoints for performance data
- Tracking/logging query helpers
- List table rendering for ads and placements

---

## Acceptance Test Checklist
- ✅ Ad edit screen shows “Assigned placements” and “Delivered placements (Last 30 days)”
- ✅ Performance tab dropdown filters all three existing visuals
- ✅ Placement Breakdown matches totals and handles empty states
- ✅ No fatal errors if placement_key missing/unknown
- ✅ No major performance regression in wp-admin

---

## Out of Scope (Explicit)
- Campaign refactor to CPT
- Client CPT changes
- Auto-injection of placements via theme hooks
- New PDF report generator changes
- Permission model changes

