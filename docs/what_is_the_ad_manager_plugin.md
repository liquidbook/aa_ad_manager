## Agile Alliance Ad Manager (Plugin) — Stakeholder Overview / Sell Sheet

### What it is
**Agile Alliance Ad Manager** is a standalone WordPress plugin that manages ads end-to-end: **create ads, place them on the site, rotate them intelligently, and track performance (impressions + clicks)**.

It replaces the older ad system that lived inside theme code with a **more portable, maintainable, and measurable** product.

---

### Why moving from “theme code” to a plugin matters
- **More deliverable & maintainable**: It’s now a self-contained product with clear boundaries, upgrades, and ownership—independent of theme changes.
- **Safer site evolution**: Theme redesigns/refactors don’t risk breaking ad delivery and tracking.
- **Consistent admin workflow**: The ad experience lives in WP Admin as a dedicated system (not hidden in templates).
- **Cleaner reporting foundation**: Tracking and reporting are core features, not bolt-ons.

---

### The major improvement: Placements (the missing feature)
The biggest product upgrade is a first-class **Placement** feature.

- **Placement = a named slot on the site** (example: “Sidebar – Blog Articles”, “Top Banner – Home”)
- Each placement has a **stable key** and a **copyable shortcode**
  - Example: `[aa_slot key="sidebar_blog_articles"]`
- Each placement has an **assigned pool of eligible ads** (the ads allowed to rotate in that slot)
- Tracking now captures **which placement** served the ad, enabling reporting like:
  - “Which placements are driving clicks?”
  - “What’s the CTR by placement?”
  - “Which ads perform best in which placement?”

This is important because it aligns the system with how marketing teams think: **slot-driven delivery** rather than overloading “campaign labels” to mean both “campaign” and “location.”

---

### What components make it work (plain English)
- **Ads**
  - The creative (image), destination URL, and rules that govern eligibility:
    - active/inactive
    - scheduling (start/end dates)
    - frequency/weight (how often it should appear vs other eligible ads)
    - optional impression cap (max times it should be shown)
- **Placements**
  - The “shelves” where ads can appear:
    - stable placement key (used by shortcodes and reporting)
    - active/inactive toggle
    - assigned ads list (eligibility pool)
- **Delivery that works with caching**
  - The system is designed to stay accurate even when pages are cached (common on production sites).
- **Tracking + reporting**
  - Every view logs an **impression**
  - Every click logs a **click**
  - Reports support placement-aware filtering, grouping, and CSV export

---

### How it works (simple walkthrough)
1. **Create Ads** in WP Admin (creative + link + rules).
2. **Create a Placement** (the slot), assign which ads are eligible there.
3. **Copy/paste the placement shortcode** into Elementor/content/templates.
4. On each page view:
   - The ad is served for that placement
   - An **impression** is logged
5. On each click:
   - A **click** is logged
   - The visitor is redirected to the destination URL
6. In WP Admin, you can review:
   - per-ad performance (including placement breakdowns)
   - placement performance and drilldowns

---

### Why this produces better marketing outcomes
- **Control**: Rotation is governed by weights/frequency and eligibility rules (dates, active status, caps).
- **Accuracy**: Impression/click tracking stays reliable in caching-heavy environments.
- **Optimization**: Placement-aware reporting supports better decisions:
  - improve or replace underperforming slots
  - match creatives to the placements where they work best
  - measure real delivery and real engagement (CTR) consistently
- **Operational clarity**: Teams can manage “where ads run” directly through placements, without relying on fragile theme logic.

---

### Client + Campaign today (and what we should do next)
**Today:** “Client” and “Campaign” exist as taxonomies attached to ads. This already supports organization and reporting filters.

**Future improvement (recommended): Convert Client + Campaign into Custom Post Types**
This is a natural product evolution because CPTs can hold richer business data and workflow:
- **Client CPT**: contacts, agreements, brand assets, notes, status
- **Campaign CPT**: goals/KPIs, flight dates, budgets, approvals, ownership, lifecycle state

**Why CPTs are better than taxonomies for this**
- Taxonomies are great for lightweight categorization.
- CPTs are better for real operational objects that need **metadata, workflow, and deeper reporting**.

The key point: **Placements already solve “where ads go.”** That allows Client/Campaign to become clearer “who/why” dimensions (instead of being overloaded as location labels).

---

### One-sentence positioning
**This plugin turns ad delivery into a placement-based, trackable product—giving marketing clear control over where ads run, how they rotate, and what performance they deliver—without being tied to theme code.**
