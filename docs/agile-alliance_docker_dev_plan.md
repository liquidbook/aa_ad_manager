### Agile Alliance — Docker Dev Plan (using the LiquidBook WordPress Docker Lab)

### Goal
Use your existing Docker lab stack (WordPress + MariaDB + phpMyAdmin) as the **primary local dev/testing environment** for:
- The Agile Alliance theme (for integration testing)
- The extracted **Agile Alliance Ad Manager** plugin
- ACF + **ACF JSON load path** support

This plan is designed to replace Flywheel/Local for day-to-day plugin testing.

---

### Baseline stack (source)
Your current lab stack is defined in `d:\liquidbook-testor\docker-compose.yml` and uses:
- `wordpress:latest`
- `mariadb:11`
- `phpmyadmin:5`
- `./wp-content` bind-mounted into `/var/www/html/wp-content`
- An extra bind mount to a plugin (Liquidbook chatbot) at:
  - `../liquidbook-chatbot/plugin/liquidbook-chatbot:/var/www/html/wp-content/plugins/liquidbook-chatbot`

For Agile Alliance, we’ll create a **separate project folder** (new stack instance) so ports and volumes don’t collide.

---

### Recommended local project layout
Create a new Docker lab folder (example):
- `D:\agile-alliance-testor\`

Inside it:
- `docker-compose.yml`
- `.env`
- `wp-content/` (themes/plugins/uploads live here)

And keep your Agile Alliance repo separate:
- `D:\development\agile-alliance-dev\`

---

### Theme strategy in Docker (important)
Your theme build pipeline writes the deployed theme to:
- `local/app/public/wp-content/themes/agile-alliance-theme/`

**Recommendation (fastest, least intrusive):**
- Treat `D:\development\agile-alliance-dev\local\app\public\wp-content\themes\agile-alliance-theme\` as the “built theme folder”
- Bind mount that directory directly into the Docker WP container at:
  - `/var/www/html/wp-content/themes/agile-alliance-theme`

This avoids changing your `gulpfile.mjs` right now.

---

### Plugin strategy in Docker
The extracted plugin will live in its **own repo** (Option B) and be bind-mounted into:
- `/var/www/html/wp-content/plugins/agile-alliance-ad-manager`

Example local path (separate repo, recommended):
- `D:\development\agile-alliance-ad-manager\`

---

### Port strategy (avoid collisions)
Your Liquidbook stack uses:
- WordPress: `${WP_PORT}` (example 8085 in docs)
- phpMyAdmin: `9095`

Your current local sites:
- Base WordPress Docker project: `http://localhost:8085/`
- LiquidBook 2026 business dev: `http://localhost:8086/`

**Decision for Agile Alliance (this project)**:
- WordPress: **`http://localhost:8090/`**
- phpMyAdmin: **pick a free port** (recommended: `9097` if available)

---

### Docker compose changes (Agile Alliance variant)
Start from your existing `docker-compose.yml`, and adjust the WordPress service `volumes:`:

#### 1) Keep the `./wp-content` bind mount
This remains your container’s persistent wp-content (uploads, etc.):
- `- ./wp-content:/var/www/html/wp-content`

#### 2) Replace the Liquidbook chatbot mount with Agile Alliance mounts
Remove:
- `- ../liquidbook-chatbot/plugin/liquidbook-chatbot:/var/www/html/wp-content/plugins/liquidbook-chatbot`

Add (theme bind mount):
- `- D:/development/agile-alliance-dev/local/app/public/wp-content/themes/agile-alliance-theme:/var/www/html/wp-content/themes/agile-alliance-theme`

Add (plugin bind mount, once plugin exists):
- `- D:/development/agile-alliance-ad-manager:/var/www/html/wp-content/plugins/agile-alliance-ad-manager`

**Notes**
- On Windows Docker, use paths that Docker Desktop can access (drive sharing enabled).
- If you prefer relative paths, place the Docker lab folder adjacent to the repo and mount via `../agile-alliance-dev/...`.

#### 3) Update phpMyAdmin port to avoid collisions
Change:
- `- "9095:80"`
to:
- `- "9097:80"` (example)

---

### `.env` example for the Agile Alliance Docker stack
Create a dedicated `.env` in the Agile Alliance Docker lab folder (example values):

```bash
PROJECT_SLUG=agile-alliance
WP_PORT=8090

DB_NAME=wp_agile_alliance
DB_USER=agilealliance
DB_PASSWORD=change_me
DB_ROOT_PASSWORD=change_me_too
```

Notes:
- Use a unique `PROJECT_SLUG` so container names and volumes don’t collide with your other stacks.
- Ensure your chosen phpMyAdmin host port (e.g., `9097`) is not already in use.

---

### ACF requirement + JSON load path (plugin responsibility)
Because ads depend on ACF fields, the Docker WP environment must have:
- **Advanced Custom Fields** installed and active (Free or Pro depending on your setup)

And the extracted plugin must implement the ACF JSON load path (as documented in `docs/ads/ad_manager_extraction_plan.md`):
- Filter: `acf/settings/load_json` to include the plugin’s `acf-json/` directory
- Optional: `acf/settings/save_json` during development (handy in bind-mount Docker dev so field tweaks are written back into the repo)

Notes for this repo:
- `acf-json/` is the preferred source (ACF Local JSON)
- `acf-export/` is a shipped fallback export (used only if `acf-json/` is empty)

This ensures the ACF field group for `aa_ads` is auto-loaded in Docker.

---

### Suggested workflow (day-to-day)
1. **Run the Docker stack**
   - `docker compose up -d`
2. **Activate the theme**
   - WP Admin → Appearance → Themes → activate “Agile Alliance”
3. **Install and activate required plugins**
   - ACF (required)
   - Any other dependencies needed for your test pages
4. **Develop the plugin**
   - edits on host immediately reflect in container via bind mount
5. **Iterate**
   - refresh page to test ad injection (shortcode → AJAX → DOM)

---

### Testing focus for Ad Manager in Docker
- **Shortcode placeholder appears in View Source** (no injected markup in source)
- **AJAX injects the `<a class="aa-ad-click">...` markup into `.aa-ad-container`**
- **Impressions/clicks write into the same DB table schemas** (as defined in `docs/ads/wp_aa_ad_impressions.sql` and `docs/ads/wp_aa_ad_clicks.sql`)
- **Admin screens**
  - CPT list column “Copy”
  - Campaigns/Clients taxonomy admin
  - Reports/options pages

---

### Follow-up needed from you (to finalize exact setup steps)
- **Confirm desired ports** for Agile Alliance Docker stack (`WP_PORT`, phpMyAdmin port).
- **Confirm the plugin repo path** (default assumed in this doc):
  - `D:\development\agile-alliance-ad-manager\`
