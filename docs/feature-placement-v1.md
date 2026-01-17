# Feature Spec: Placements v1 (Slots / Locations)

**Doc:** `docs/feature-placement-v1.md`  
**Status:** Draft  
**Owner:** Ad Manager (WordPress plugin)  
**Primary goal:** Introduce a first-class “Placement” concept (where an ad appears) without forcing a full migration away from the existing Campaign taxonomy.

---

## 1. Why this exists

Today, “Campaign” terms are doing double-duty:
- Real campaigns (ex: “Agile2025 Community”, “PMI Training”)
- Location/slot labels (ex: “Top Banner Main”, “Side Bar Main”, “PMI Sidebar”)

That creates confusion in reporting and makes ad governance harder. We need a clean “shelf” model:

- **Placement** = where the ad shows up (slot/shelf)
- **Ad** = the creative and its rules (active, dates, weight, target link, etc.)
- **Campaign** (for now) = kept as taxonomy (but de-emphasized as a location container)

**Placements v1** adds a slot system that matches how WordPress/Elementor admins work:
1) Create a Placement in admin  
2) Copy the Placement shortcode  
3) Paste it into Elementor/template/block  
4) The placement decides what ad(s) can appear there

---

## 2. Goals and non-goals

### Goals (v1)
- Add a **Placement CPT** (admin-managed “slot” objects).
- Each Placement generates a **copyable shortcode** for Elementor/templates.
- Placement can be **assigned Ads** directly (relationship).
- Delivery uses the existing ad eligibility rules (active, date window, impression max, weight/frequency).
- Tracking logs include `placement_key` (critical for future reporting).
- Provide a low-risk **compatibility path** for legacy “campaign-as-location” usage (optional but recommended).

### Non-goals (v1)
- No full migration of Campaign from taxonomy to CPT.
- No complex placement rules engine (post-type targeting, taxonomy targeting, audience targeting, geo, etc.).
- No programmatic “auto-inject” placements via theme hooks (Elementor shortcode placement remains the primary mechanism).
- No client portal / permission model beyond existing admin roles.
- No “Placement contains Campaigns” (campaign-to-placement assignment can be v2).

---

## 3. Terminology

- **Placement**: A defined slot/shelf, referenced via a stable key.
- **Placement Key**: Stable identifier used by shortcodes and logging. Title can change; key should remain stable.
- **Assigned Ads**: List of ads eligible to render in a placement.
- **Legacy Campaign Location Term**: A campaign taxonomy term that is actually a placement label.

---

## 4. Data model (v1)

### 4.1 Placement (CPT)
- **Post type:** `aa_placement` (name can be adjusted; keep consistent prefix)
- **Fields (ACF suggested):**
  - `placement_key` (string, required, unique)
  - `placement_active` (true/false, default true)
  - `placement_description` (textarea, optional)
  - `placement_size` (select: `wide`, `square`, `custom`, optional)
  - `assigned_ads` (relationship to `aa_ads`, allow multiple)
  - `shortcode_preview` (read-only text; generated)
  - `legacy_campaign_term_map` (optional, string/term selector for compat)

**Key rules:**
- `placement_key` is the canonical identifier.
- Title is display-only and can change freely.
- If `placement_key` changes, existing Elementor shortcodes break. Treat this as “dangerous.”

### 4.2 Tracking additions (recommended)
Add `placement_key` to tracking events:
- **Impression logging**: include placement_key
- **Click logging**: include placement_key

If DB table changes are heavy, v1 can store placement_key in an existing “context” field if present, but best practice is a dedicated column.

---

## 5. Admin UX

### 5.1 Menu
Add a new menu item:
- **Ad Manager → Placements**

### 5.2 Placements list table
Columns:
- Title
- Placement Key
- Shortcode (with **Copy** button, consistent with Ads list UX)
- Assigned Ads count
- Status (Active/Inactive)
- Updated date

Actions:
- Edit
- Trash
- (Optional) Duplicate

### 5.3 Placement edit screen
Metabox / fields:
- Title
- Placement Key (required)
- Active toggle
- Size (optional)
- Assigned Ads (relationship selector)
- Shortcode Preview + Copy button
  - Example: `[aa_slot key="sidebar_blog_articles"]`

**Guardrails:**
- If placement_key is edited after publish, show a warning:
  - “Changing the key may break existing pages that use the shortcode.”

### 5.4 Ads edit screen (v1 impact)
No required changes, but optional enhancements:
- Show “Used in Placements” (read-only list) for visibility.
- Eventually add a quick-link: “View Placement performance.”

---

## 6. Front-end behavior

### 6.1 Primary shortcode
New shortcode:
- `[aa_slot key="PLACEMENT_KEY"]`

Behavior:
1) Resolve Placement by `placement_key`.
2) If inactive or not found, return empty string (optionally return HTML comment in debug mode).
3) Determine eligible ads from Placement → Assigned Ads.
4) Choose a winning ad using existing selection logic (frequency/weight + eligibility).
5) Render the same container markup the current AJAX loader expects (preferred), including:
   - ad size (if needed)
   - placement_key (new)
   - any campaign param (optional)
   - page context (existing)

### 6.2 Selection rules (v1)
Use existing ad rules:
- Active status
- Start/end dates
- Impression max
- Weight/display frequency
- Ad size compatibility (if placement_size is used)

If multiple ads are assigned:
- Use weighted rotation (existing “display frequency” value on ad is the weight).

If no eligible ads:
- Render nothing (silent fail), or (in debug mode) show a small placeholder.

---

## 7. Tracking and reporting impact

### 7.1 Logging requirements
When an ad is served from a placement:
- Impression event should include:
  - ad_id
  - page_id (if available)
  - placement_key
  - timestamp

When an ad is clicked:
- Click event should include:
  - ad_id
  - placement_key
  - referer_url (already used today)
  - timestamp

### 7.2 Reporting (v1 scope)
No major report UI changes required immediately, but placement_key unlocks future improvements:
- “Performance by placement”
- “This ad broken down by placement”
- “Top placements by CTR”

Optional quick win:
- Add Placement filter dropdown to existing reports page (v1.1).

---

## 8. Backward compatibility (recommended)

We want to avoid breaking existing Elementor placements that currently pass `campaign=` where campaign was actually a location.

### 8.1 Compatibility strategy
- Keep existing shortcodes functioning.
- Add a mapping layer:
  - If a request includes `campaign=<term_slug>` and that slug is in a known “location-ish campaign term” list, translate it to a placement_key.

Two options:
1) **Manual mapping**: store legacy term slug on the Placement (`legacy_campaign_term_map`)
2) **Heuristic mapping**: detect by naming pattern (not recommended as the only method)

### 8.2 Rollout mode
- “Compat Mode” ON by default for the first release that includes placements.
- Provide admin setting to disable once templates are migrated.

---

## 9. Security and permissions

- Placements should be manageable by the same roles that manage ads (usually admins).
- Shortcode rendering is front-end safe; it should not expose admin-only data.
- Ensure all AJAX endpoints that log or retrieve ads remain nonce-protected where applicable.

---

## 10. Performance considerations

- Placement resolution by key should be efficient:
  - Cache placement ID by placement_key (transient/object cache)
- Assigned Ads lookup:
  - If ACF relationship is used, cache the list of ad IDs for a placement.
- Logging:
  - Keep writes lean and indexed (placement_key should be indexable if a column).

---

## 11. Acceptance criteria

### Admin
- [ ] Admin can create a Placement with a stable key.
- [ ] Admin can copy the generated shortcode from Placement list and edit screen.
- [ ] Admin can assign multiple Ads to a Placement.
- [ ] Placement list shows basic health (active, assigned ads count).

### Front-end
- [ ] `[aa_slot key="..."]` renders an eligible ad when available.
- [ ] If no eligible ad exists, the shortcode renders nothing (no fatal errors).
- [ ] Rotation respects existing ad weighting logic (display frequency).

### Tracking
- [ ] Impression logs include placement_key for placement-served ads.
- [ ] Click logs include placement_key for placement-served ads.

### Compatibility
- [ ] Legacy shortcodes continue functioning.
- [ ] Optional: legacy “location campaigns” can be mapped to placements without breaking pages.

---

## 12. Implementation checklist (suggested work order)

1) Add Placement CPT registration (`aa_placement`)
2) Add ACF field group for Placements
3) Admin list table columns + Copy button for shortcode
4) Implement `[aa_slot key="..."]` shortcode
5) Pass placement_key through to ad selection + AJAX payload
6) Add placement_key to impression/click logging
7) (Optional) Compat Mode mapping for legacy location campaign terms
8) Smoke test in Elementor and in classic template contexts
9) Document migration steps for editors (how to replace old shortcodes with new placement shortcodes)

---

## 13. Defaults (v1)

- **placement_key generation:** Auto-generate from title on create; allow editing later with a warning that changing the key can break existing shortcodes.
- **empty placement render:** Return empty string (render nothing). If debug mode is enabled, optionally return an HTML comment explaining why nothing rendered.
- **placement_size:** Optional metadata only in v1; do not hard-enforce size matching.


---
