## Ad Manager Enhancement Plan

*A high-level, implementation-minded blueprint for evolving the Agile Alliance Ad Manager into a more commercially viable standalone plugin, while preserving continuity with the current taxonomy-based MVP.*

---

# 1. Goals and Philosophy

### Primary goal

Evolve the current MVP ad manager (working delivery + click/impression tracking + basic reporting) into a **durable, scalable, operator-friendly system** that supports:

* reliable ad delivery across many sections and custom post types
* meaningful reporting (fast, complete, filterable, client-shareable)
* real-world operational concepts (clients, campaigns, placements, obligations)
* analytics alignment (UTM tagging / GA visibility)
* guardrails that reduce “human chaos” (accidental deletions, mislabeling, junk-drawer taxonomies)

### Guiding principle

Shift from “labels” to “business objects”:

* **Taxonomies are good labels**
* **Clients, campaigns, and placements are business objects** with rules, defaults, lifecycles, and accountability

---

# 2. Current State Summary

The ad system currently supports:

* an Ad entity (CPT) with parameters, media, destination link
* client + campaign dimensions currently implemented as **taxonomies**
* shortcode-based placement (Elementor inserts shortcodes into layouts)
* AJAX-based delivery to avoid caching problems
* tracking for impressions and clicks
* backend reporting filtered by client/campaign, with known limitations (example: record limits like 100-row returns, performance constraints)

Key pressure points uncovered:

* “Campaign” was misused to represent **location/placement**
* taxonomy interfaces are easy to misuse (including accidental deletion)
* reporting needs to become faster, deeper, and more visual
* stakeholders need exports (PDF/CSV) and analytics-friendly attribution

---

# 3. Target Model Overview

This plan proposes three first-class entities (CPTs), with Ads as the creative unit:

* **Client (CPT)**: the organization/account (PMI, Agile Alliance, future sponsors)
* **Campaign (CPT)**: the marketing initiative/flight (Spring 2026 Membership Push)
* **Placement (CPT)**: the inventory shelf/slot (Top Banner Wide, Sidebar Square)

Ads remain the “creative” and are selected for display based on Placement + rules + weights.

### Core mental model

* **Placement** is where something can show.
* **Campaign** is why it’s running.
* **Client** is who owns/benefits/pays (even if $0 internal).
* **Ad** is what is shown (creative + destination + tracking rules).

---

# 4. Client System (CPT)

### Why move away from client taxonomy

Taxonomies are easy for humans to casually edit/delete, and they do not naturally carry structured operational data. A Client needs durable metadata and workflow affordances.

### Client CPT responsibilities (v1 internal-focused)

Client fields should support accountability and reporting, even without invoicing:

**Identity**

* client_code (stable unique ID, e.g., `PMI`, `AA`)
* legal name / display name

**Reporting**

* report recipient emails (one or many)
* reporting notes/obligations (internal)
* status (active / inactive / internal)

**Future-ready hooks (optional placeholders)**

* “Contacts” section for linking WP users later (client portal, permissions)

### Near-term use case

PMI: internal AA staff generates periodic reports (PDF/CSV) sent to PMI recipients.

---

# 5. Campaign System (CPT)

### Why campaign should become a CPT (and not a taxonomy)

Campaigns are more than a label. In professional systems, campaigns have:

* lifecycles (scheduled/live/paused/ended)
* flight dates
* goals/obligations
* defaults (UTMs, reporting recipients)
* internal notes and accountability

Also: your practical concern is valid. Taxonomy deletion and casual changes are common.

### Campaign CPT responsibilities

**Identity**

* campaign_code (stable ID)
* title / description

**Lifecycle**

* start date / end date
* status (draft, scheduled, live, paused, ended, archived)

**Defaults**

* UTM defaults (source/medium/campaign)
* optional destination rules

**Performance targets**

* impression goal / cap (optional)
* notes on sponsor requirements (optional)

**Relationships**

* belongs to a Client
* has many Ads (typical)
* assigned to Placements (recommended model)

---

# 6. Placement System (CPT)

### Why placement is a CPT (the “shelf” model)

Elementor shortcodes are explicit insertion points. There is no single theme hook for “sidebar/header/footer” across a site with many CPTs and templates. Therefore placement must be:

* an intentional “slot” editors place
* governed by rules so ads don’t appear everywhere

### Placement CPT responsibilities

**Identity**

* placement_key (stable machine key, used in shortcodes)
* label/title
* optional size type (wide/square/etc.)
* active toggle

**Rules (governance)**

* allowed post types (checkbox list from registered CPTs)
* allowed contexts (single/archive/search/home etc.)
* include/exclude post IDs (optional later)
* taxonomy constraints (optional later)
* logged-in/member-only constraints (optional later)

**Delivery configuration**

* how many ads rotate in this placement (optional)
* frequency caps (future)
* fallback behavior (show nothing, house ad, etc.)

**How placement is used**
Editors insert:

* `[aa_slot key="sidebar_primary_square"]`

Placement is the stable anchor; the system decides what to show.

---

# 7. What Gets Assigned to a Placement

A key design choice explored: do placements “hold ads” or “hold campaigns”?

### Recommended direction: campaign-driven placement with optional pinned ads (hybrid)

To align with operational reality (“run this campaign in these site areas”), the placement should primarily be stocked with **campaigns**, while allowing **pinned ads** for special cases.

**Placement can include:**

* campaign assignments (primary)
* direct/pinned ad assignments (override)

**Selection logic (high level)**

1. Gather eligible ads from:

   * pinned ads (if present and active)
   * campaign ads (from assigned campaigns)
2. Apply eligibility filters:

   * ad status, flight dates, impression caps
   * placement rules (post type/context validation)
3. Select one ad by weight
4. Render + log impression/click including placement_key + campaign_id + client_id + ad_id

This produces clean reporting:

* by placement (inventory performance)
* by campaign (marketing performance)
* by client (account performance)

---

# 8. Analytics Integration: UTMs and Google Analytics Alignment

### Purpose

Internal click tracking answers “did they click.”
Analytics alignment answers “what happened after the click.”

### Recommended approach

Do not store final destination URLs with UTMs baked in. Instead:

* store base destination URL on the Ad
* store UTM rules as defaults across Client/Campaign/Ad
* generate the final URL **at click redirect time** (your logging endpoint is the ideal chokepoint)

### UTM strategy (practical default mapping)

* `utm_source`: client_code or site code (e.g., `agilealliance`)
* `utm_medium`: `display` (or `banner`)
* `utm_campaign`: campaign_code
* `utm_content`: ad identifier (optionally include placement_key)

Example:

* `utm_content=ad-123_sidebar_primary_square`

### Guardrails

* preserve existing query params
* avoid double-adding UTMs (detect existing `utm_` params)
* encode values safely
* provide a “preview final URL” on the Ad screen

### Optional reconciliation parameter

Add an internal click id parameter (non-UTM) for debugging and reconciliation:

* `aa_click_id=...`

---

# 9. Reporting and Visualization Improvements

### Reporting must become “trustworthy, explainable, connectable”

* **trustworthy**: complete totals, no arbitrary row limits, consistent time filtering
* **explainable**: charts that instantly show trends and relative performance
* **connectable**: UTMs allow downstream outcome attribution in GA

### Quick-win charts for an individual Ad screen

These are the “Most Useful 3” recommended for the Ad edit view:

1. **Impressions + Clicks over time** (daily line chart)

   * confirms delivery and activity
   * highlights spikes/drops quickly

2. **CTR trend over time** (daily line chart)

   * quality indicator independent of volume

3. **Top Pages performance** (bar chart + table)

   * reveals which pages/sections are driving engagement
   * helps placement decisions and troubleshooting

### PDF report design (client-ready export)

A “1-page executive snapshot + optional appendix” format:

**Page 1**

* header: branding + ad + date range
* KPI cards: impressions, clicks, CTR, top page
* charts: impressions/clicks trend, CTR trend, top pages
* footer: metric definitions

**Page 2 (optional)**

* configuration summary (campaign, placements, dates)
* full top-pages table
* notes/change log

---

# 10. Data Layer and Performance Strategy

### Fix known reporting constraints

Address issues like “only first 100 records” and improve query performance by:

* adding date-range filtering everywhere
* grouping/aggregation queries rather than pulling raw logs
* indexing by ad_id, timestamp, placement_key, campaign_id/client_id (where stored)

### Recommended scaling move: daily rollups

Keep raw events for audit, but run dashboards from rollups:

* raw tables: impressions/clicks events
* rollup table: daily totals per (date, ad_id, placement_key, campaign_id, client_id)

This makes reporting fast and reliable even as volume grows.

---

# 11. Migration Strategy from Taxonomies to CPTs

### Non-breaking transition

You explicitly want transitional support:

* keep taxonomies temporarily for compatibility and legacy data
* migrate toward canonical CPT IDs (client_id, campaign_id, placement_key)

### Steps (recommended)

1. Introduce new CPTs (Client, Campaign, Placement)
2. Add mapping layer:

   * taxonomy client term → client CPT
   * taxonomy campaign term → campaign CPT (or flagged as legacy)
3. Identify and migrate “location-ish campaigns”

   * campaign terms that are really placements get moved into Placement
4. Update rendering and tracking to use new canonical IDs:

   * log placement_key, client_id, campaign_id, ad_id
5. Update admin UI to discourage legacy taxonomy usage:

   * hide, restrict deletion, or mark as deprecated
6. Eventually: freeze legacy taxonomy creation and phase it out

---

# 12. Guardrails Against Human Error

Because humans will do chaotic things:

* use CPTs with custom capabilities to limit who can delete/edit
* implement “Archive” instead of delete for Campaign/Client
* warn or block deletion when:

  * entity has active assignments
  * entity has historical tracking data
* show references: “This campaign is used in 8 placements.”

These controls are much harder to enforce cleanly with taxonomies alone.

---

# 13. Suggested Implementation Phases

### Phase 1: Placement foundation + reporting wins

* Placement CPT + slot shortcode
* store placement_key in tracking
* add Ad-level charts (the useful 3)
* fix reporting query limits and pagination

### Phase 2: Client and Campaign as CPTs (internal operational model)

* Client CPT and Campaign CPT
* link Ads to Campaign + Client
* campaign-level UTM defaults

### Phase 3: Campaign-to-Placement assignments (hybrid model)

* placements can include campaigns (+ optional pinned ads)
* render selection logic from campaigns
* expanded reporting filters (client/campaign/placement)

### Phase 4: PDF exports + scheduled delivery

* build standardized PDF reports
* internal admin workflow for sending to PMI
* later: scheduling automation

### Phase 5: Future optional portal and contacts

* client contacts (WP users linked to Client)
* report-only access (then expanded permissions later)

---

# 14. Outcomes Expected from This Plan

By moving to CPT-based Client/Campaign/Placement and adding UTM support, the system becomes:

* easier to operate across many CPTs and Elementor layouts
* safer from accidental admin missteps
* more credible to stakeholders and sponsors
* analytics-aligned (internal stats + external outcomes)
* scalable in performance via rollups and better querying
* structurally ready for future features without committing to them now

