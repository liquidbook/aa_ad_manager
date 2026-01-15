### Cursor Rules — Agile Alliance Ad Manager

### Project identity (read this first)
- **What this repo is**: A standalone **WordPress plugin** named **Agile Alliance Ad Manager** (CPT + taxonomies + ACF fields + AJAX delivery + impression/click tracking + admin reports).
- **What it is not**: Not a theme, not a full WordPress site. Avoid assumptions that theme code exists here.
- **Primary local workflow**: Develop in this repo on the host machine and test by running WordPress in Docker with this repo bind-mounted as a plugin.

---

### Core architecture overview
- **Plugin bootstrap**: `agile-alliance-ad-manager.php`
- **Key subsystems**
  - **CPT**: `includes/cpt.php` (`aa_ads`)
  - **Taxonomies**: `includes/taxonomies.php` (`aa_campaigns`, `aa_clients`)
  - **Shortcodes + loader enqueue**: `includes/shortcodes.php`
  - **Weighted selection + eligibility**: `includes/ad-selection.php`
  - **AJAX**: `includes/ajax.php` (`aa_get_ad`, `aa_log_click`)
  - **DB tables + logging**: `includes/db.php` (`aa_ad_impressions`, `aa_ad_clicks`)
  - **Admin options + reports**: `includes/admin-options.php`, `includes/admin-reports.php`
  - **ACF integration**: `includes/acf-json.php`, `acf-json/`, `acf-export/`

---

### ACF rules
- **ACF is a required dependency** for the intended admin UX (image/title/link/etc.).
- **Local JSON is preferred**
  - `acf-json/` should be treated as the primary field group source.
  - `acf-export/` is a fallback and should only be used if `acf-json/` is empty or during transitions.
- **Do not rename ACF field keys lightly**. Field names like `ad_image` are used by PHP and JS logic.

---

### Docker dev/test workflow
- **Compose file**: `docker-compose.yml` (WordPress + MariaDB + phpMyAdmin).
- **Env config**: copy `env.example` → `.env` (do not commit `.env`).
- **Bind mount**: This repo mounts into `/var/www/html/wp-content/plugins/agile-alliance-ad-manager`.

When making changes:
- Prefer changes that work correctly in **bind-mount** development (no build steps required unless explicitly added).
- Assume WordPress in Docker will run a stock admin and ACF plugin.

---

### Engineering style / change philosophy
- **Prefer targeted fixes** over large refactors.
- Keep changes scoped to the smallest number of files that make the behavior correct.
- Avoid introducing new dependencies unless clearly justified.
- Favor WordPress-native patterns (hooks, sanitization, capabilities checks, nonces).

---

### Admin UI work (list tables, sortable columns, previews)
When changing the `aa_ads` list table:
- Use standard hooks (`manage_*_posts_columns`, `manage_*_posts_custom_column`, `manage_edit-* _sortable_columns`).
- If custom sorting is needed (e.g., taxonomy sort), scope SQL changes to:
  - **admin only**
  - **main query only**
  - **post_type=aa_ads only**
- Keep list-table rendering fast (avoid expensive per-row queries; use WP APIs and limit image sizes).

---

### Browser MCP / Playwright usage (admin validation)
Use browser automation tools for **verification**, not as a replacement for good code review.

- **Default SOP**
  - Resize viewport (e.g., 1400×900)
  - `browser_snapshot` after every navigation
  - Click by **role + accessible name** (avoid brittle selectors)
  - Take **fullPage screenshots** for before/after validation
  - Collect **console + network** when debugging AJAX/UI issues

- **Login constraint**
  - The AI **must not** type or store credentials from chat.
  - The human must complete login manually; the AI can continue in the authenticated session afterward.

---

### Context7 usage (docs lookups)
- Use Context7 for **up-to-date library/framework docs** (WordPress hooks patterns, PHP APIs, etc.) when uncertain.
- Prefer repo code as the source of truth for this plugin’s behavior; use Context7 to confirm best practices and edge cases.

---

### Safety / “do not do this”
- **Do not** introduce large-scale rewrites or reorganize the plugin without explicit instruction.
- **Do not** commit secrets (passwords, API keys, `.env`).
- **Do not** automate credential entry in browser flows.
- **Do not** assume production DB schema includes fields unless verified in `includes/db.php` and the repo docs.

---

### When in doubt
- Ask 1–2 clarifying questions about UX expectations, sorting rules, and data source of truth (ACF vs meta vs DB).
- Implement the smallest safe change, then validate quickly in WP admin (screenshots + short notes).
