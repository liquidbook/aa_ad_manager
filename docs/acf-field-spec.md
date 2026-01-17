# ACF Field Group Spec

## Global conventions

### Naming + key conventions

* **Field Group title prefix:** `AA Ad Manager: <Entity>`
* **Field name prefix:**

  * Client fields: `aa_client_`
  * Campaign fields: `aa_campaign_`
  * Placement fields: `aa_placement_`
* **All “*_key” style identifiers should be slug-safe**

  * lowercase, numbers, underscores
  * example: `sidebar_primary_square`

### Recommended tab layout (for admin UX)

Use ACF “Tab” fields to keep edit screens calm:

* Overview
* Configuration
* Reporting
* Rules (Placement)
* Assignments

### Repeater vs multi-email text

Prefer **Repeater(email)** for report recipients (less error-prone).

### Relationship fields

Use ACF Relationship with filters and return format **Post Object** (store post IDs).

---

## Field Group 1: Client

**Field Group Title:** `AA Ad Manager: Client`
**Applies To:** Post Type = `aa_client`
**Purpose:** Account identity + reporting config (internal use now; portal-ready later)

### Tab: Overview

1. **Client Code**

   * Field Name: `aa_client_code`
   * Type: Text
   * Required: Yes
   * Instructions: “Stable identifier (e.g., PMI, AA). Do not change after launch.”
   * Wrapper: 50%

2. **Client Status**

   * Field Name: `aa_client_status`
   * Type: Select
   * Choices: `active`, `inactive`, `internal`
   * Default: `active`
   * Required: Yes
   * Wrapper: 50%

3. **Legal Name**

   * Field Name: `aa_client_legal_name`
   * Type: Text
   * Required: No
   * Wrapper: 50%

4. **Display Name**

   * Field Name: `aa_client_display_name`
   * Type: Text
   * Required: No
   * Wrapper: 50%

### Tab: Reporting

5. **Report Recipients**

   * Field Name: `aa_client_report_recipients`
   * Type: Repeater
   * Button Label: “Add Recipient”
   * Subfields:

     * Recipient Name

       * Name: `name`
       * Type: Text
       * Required: No
       * Wrapper: 50%
     * Recipient Email

       * Name: `email`
       * Type: Email
       * Required: Yes
       * Wrapper: 50%

6. **Preferred Report Formats**

   * Field Name: `aa_client_report_formats`
   * Type: Checkbox
   * Choices: `pdf`, `csv`
   * Default: `pdf`
   * Layout: Horizontal

7. **Default Report Range**

   * Field Name: `aa_client_default_report_range`
   * Type: Select
   * Choices: `7`, `30`, `90`, `custom`
   * Default: `30`

8. **Client Notes / Obligations**

   * Field Name: `aa_client_notes`
   * Type: Textarea
   * New Lines: `br`
   * Instructions: “Internal notes and any sponsor obligations.”

### Tab: Future (optional placeholder)

9. **Enable Client Contacts**

   * Field Name: `aa_client_enable_contacts`
   * Type: True/False
   * Default: 0
   * Instructions: “Reserved for future client portal use.”

10. **Client Contacts (Users)**

* Field Name: `aa_client_contacts`
* Type: User
* Multiple: Yes
* Conditional Logic: Show if `aa_client_enable_contacts == 1`
* Instructions: “Future: users who can view/edit this client’s ads and reports.”

---

## Field Group 2: Campaign

**Field Group Title:** `AA Ad Manager: Campaign`
**Applies To:** Post Type = `aa_campaign`
**Purpose:** Flight dates, status, defaults (UTM), goals, reporting recipients

### Tab: Overview

1. **Campaign Code**

   * Field Name: `aa_campaign_code`
   * Type: Text
   * Required: Yes
   * Instructions: “Stable identifier (e.g., PMI_SPRING_2026).”
   * Wrapper: 50%

2. **Client**

   * Field Name: `aa_campaign_client`
   * Type: Relationship
   * Post Type Filter: `aa_client`
   * Filters: Search
   * Max: 1
   * Required: Yes
   * Return Format: Post Object
   * Wrapper: 50%

3. **Campaign Status**

   * Field Name: `aa_campaign_status`
   * Type: Select
   * Choices: `draft`, `scheduled`, `live`, `paused`, `ended`, `archived`
   * Default: `draft`
   * Required: Yes
   * Wrapper: 50%

4. **Start Date**

   * Field Name: `aa_campaign_start_date`
   * Type: Date Picker
   * Display Format: `Y-m-d`
   * Return Format: `Y-m-d`
   * Wrapper: 25%

5. **End Date**

   * Field Name: `aa_campaign_end_date`
   * Type: Date Picker
   * Display Format: `Y-m-d`
   * Return Format: `Y-m-d`
   * Wrapper: 25%

### Tab: Goals

6. **Impression Target**

   * Field Name: `aa_campaign_impression_target`
   * Type: Number
   * Required: No
   * Min: 0
   * Wrapper: 33%

7. **Impression Cap**

   * Field Name: `aa_campaign_impression_cap`
   * Type: Number
   * Required: No
   * Min: 0
   * Wrapper: 33%

8. **Click Target**

   * Field Name: `aa_campaign_click_target`
   * Type: Number
   * Required: No
   * Min: 0
   * Wrapper: 33%

9. **Campaign Requirements / Notes**

   * Field Name: `aa_campaign_notes`
   * Type: Textarea
   * New Lines: `br`

### Tab: UTM Defaults

10. **Enable UTM Defaults**

* Field Name: `aa_campaign_enable_utm`
* Type: True/False
* Default: 1
* Instructions: “When enabled, ads in this campaign inherit these UTM values unless overridden.”

11. **UTM Source**

* Field Name: `aa_campaign_utm_source`
* Type: Text
* Conditional: `aa_campaign_enable_utm == 1`
* Placeholder: `agilealliance`
* Wrapper: 33%

12. **UTM Medium**

* Field Name: `aa_campaign_utm_medium`
* Type: Text
* Conditional: `aa_campaign_enable_utm == 1`
* Default Value: `display`
* Wrapper: 33%

13. **UTM Campaign**

* Field Name: `aa_campaign_utm_campaign`
* Type: Text
* Conditional: `aa_campaign_enable_utm == 1`
* Instructions: “Leave blank to default to Campaign Code.”
* Wrapper: 33%

14. **UTM Content Template**

* Field Name: `aa_campaign_utm_content_template`
* Type: Text
* Conditional: `aa_campaign_enable_utm == 1`
* Default Value: `ad-{ad_id}_{placement_key}`
* Instructions: “Tokens: {ad_id}, {ad_slug}, {campaign_code}, {placement_key}.”

### Tab: Reporting (optional now, useful later)

15. **Override Report Recipients**

* Field Name: `aa_campaign_override_recipients`
* Type: True/False
* Default: 0

16. **Campaign Report Recipients**

* Field Name: `aa_campaign_report_recipients`
* Type: Repeater
* Conditional: `aa_campaign_override_recipients == 1`
* Subfields:

  * Name (`name`, Text, 50%)
  * Email (`email`, Email, required, 50%)

17. **Report Notes**

* Field Name: `aa_campaign_report_notes`
* Type: Textarea
* Conditional: `aa_campaign_override_recipients == 1`

---

## Field Group 3: Placement

**Field Group Title:** `AA Ad Manager: Placement`
**Applies To:** Post Type = `aa_placement`
**Purpose:** Inventory slot identity, governance rules, assignments (campaigns + pinned ads)

### Tab: Overview

1. **Placement Key**

   * Field Name: `aa_placement_key`
   * Type: Text
   * Required: Yes
   * Instructions: “Stable machine key. Used in shortcode: [aa_slot key='…']”
   * Wrapper: 50%

2. **Active**

   * Field Name: `aa_placement_active`
   * Type: True/False
   * Default: 1
   * Wrapper: 25%

3. **Size Type**

   * Field Name: `aa_placement_size_type`
   * Type: Select
   * Choices: `wide`, `square`, `custom`
   * Default: `wide`
   * Wrapper: 25%

4. **Shortcode Helper**

   * Field Name: `aa_placement_shortcode`
   * Type: Text
   * Read Only: Yes (display-only)
   * Default Value (conceptual): `[aa_slot key="{placement_key}"]`
   * Instructions: “Copy/paste into Elementor Shortcode widget.”
   * Note: ACF doesn’t compute dynamic defaults by itself; you can populate this via `acf/load_value` filter or render it as a metabox outside ACF.

### Tab: Rules

5. **Allowed Post Types**

   * Field Name: `aa_placement_allowed_post_types`
   * Type: Checkbox
   * Choices: Dynamic (populate from `get_post_types(['public' => true], 'objects')`)
   * Layout: Vertical
   * Instructions: “If none selected, treat as ‘allowed everywhere’ OR ‘allowed nowhere’ (pick one behavior in code).”

6. **Allowed Contexts**

   * Field Name: `aa_placement_allowed_contexts`
   * Type: Checkbox
   * Choices:

     * `single`
     * `archive`
     * `search`
     * `home`
     * `front_page`
   * Layout: Horizontal

7. **Include Post IDs**

   * Field Name: `aa_placement_include_posts`
   * Type: Relationship
   * Post Type Filter: any public (or limit)
   * Filters: Search
   * Return: Post Object
   * Required: No
   * Instructions: “Optional allow-list. If set, only these posts/pages render this placement.”

8. **Exclude Post IDs**

   * Field Name: `aa_placement_exclude_posts`
   * Type: Relationship
   * Post Type Filter: any public (or limit)
   * Filters: Search
   * Return: Post Object

9. **Logged-in Only**

   * Field Name: `aa_placement_logged_in_only`
   * Type: True/False
   * Default: 0

10. **Members-only**

* Field Name: `aa_placement_members_only`
* Type: True/False
* Default: 0
* Notes: if membership rules exist, enforcement handled in code.

### Tab: Assignments

11. **Assigned Campaigns**

* Field Name: `aa_placement_campaigns`
* Type: Relationship
* Post Type Filter: `aa_campaign`
* Filters: Search
* Return: Post Object
* Instructions: “Campaigns eligible to serve in this placement.”

12. **Pinned Ads**

* Field Name: `aa_placement_pinned_ads`
* Type: Relationship
* Post Type Filter: `aa_ad` (your ad CPT)
* Filters: Search
* Return: Post Object
* Instructions: “Optional. These ads take priority if active and eligible.”

13. **Serving Strategy**

* Field Name: `aa_placement_serving_strategy`
* Type: Select
* Choices:

  * `pinned_first` (default)
  * `blend` (mix pinned + campaign ads)
  * `campaign_only`
  * `pinned_only`
* Default: `pinned_first`

### Tab: Delivery Defaults (optional)

14. **Max Ads in Rotation**

* Field Name: `aa_placement_max_ads`
* Type: Number
* Min: 0
* Instructions: “Optional limit on eligible ads before weighting selection.”

15. **Fallback Behavior**

* Field Name: `aa_placement_fallback`
* Type: Select
* Choices:

  * `none`
  * `house_ad`
* Default: `none`

---

## Field Group 4: Ad (updates to existing)

**Field Group Title:** `AA Ad Manager: Ad`
**Applies To:** Post Type = `aa_ad`
**Purpose:** connect ad to Client/Campaign (CPT-based), plus UTM overrides

### Tab: Associations

1. **Client**

   * Field Name: `aa_ad_client`
   * Type: Relationship
   * Post Type Filter: `aa_client`
   * Max: 1
   * Return: Post Object
   * Required: Yes (eventually)
   * Note: During migration, you can allow blank and derive from legacy taxonomy.

2. **Campaign**

   * Field Name: `aa_ad_campaign`
   * Type: Relationship
   * Post Type Filter: `aa_campaign`
   * Max: 1 (recommended v1)
   * Return: Post Object
   * Required: No (if you allow house ads)

### Tab: Destination and Tracking

3. **Destination URL (Base)**

   * Field Name: `aa_ad_destination_url`
   * Type: URL
   * Required: Yes

4. **Enable UTM Override**

   * Field Name: `aa_ad_enable_utm_override`
   * Type: True/False
   * Default: 0

5. **UTM Source Override**

   * Field Name: `aa_ad_utm_source`
   * Type: Text
   * Conditional: `aa_ad_enable_utm_override == 1`

6. **UTM Medium Override**

   * Field Name: `aa_ad_utm_medium`
   * Type: Text
   * Conditional: `aa_ad_enable_utm_override == 1`

7. **UTM Campaign Override**

   * Field Name: `aa_ad_utm_campaign`
   * Type: Text
   * Conditional: `aa_ad_enable_utm_override == 1`

8. **UTM Content Override**

   * Field Name: `aa_ad_utm_content`
   * Type: Text
   * Conditional: `aa_ad_enable_utm_override == 1`
   * Instructions: “If blank, generated from campaign template.”

9. **Preview Final URL**

   * Field Name: `aa_ad_utm_preview_url`
   * Type: Text
   * Read Only: Yes
   * Note: populate via code, not manual entry.

---

# Notes for Cursor Implementation

## 1) ACF JSON workflow

* Build field groups in WP admin first
* Ensure ACF Local JSON path is set to plugin `/acf-json`
* Commit JSON to repo

## 2) Dynamic “Allowed Post Types” choices

Implement an ACF filter so the checkbox choices reflect registered CPTs.

## 3) Read-only computed fields

Fields like `aa_placement_shortcode` and `aa_ad_utm_preview_url` should be populated via:

* ACF hooks (`acf/load_value`, `acf/prepare_field`) or
* a custom metabox outside ACF for more control

