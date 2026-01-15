### Working AI Template — Local WordPress Admin Testing (Agile Alliance Ad Manager)

### Purpose
This document is a **copy/paste template** for using an AI coding agent with browser automation tools to validate the WordPress admin UI for the **Agile Alliance Ad Manager** plugin in a **local Docker WordPress** environment.

It captures a proven sequence:
- Open the WP login page
- **Human logs in** (AI does not handle credentials)
- AI navigates to **All Ads**
- AI opens a specific ad (example: **“AA PMI Training”**) in the edit screen
- AI takes screenshots and provides a brief UI/UX review

---

### Preconditions (human)
- WordPress is running locally (example): `http://localhost:8090/`
- You can access `/wp-admin/` in a normal browser
- The **Agile Alliance Ad Manager** plugin is active

---

### Tooling assumptions
The AI agent has access to browser automation tools (e.g. MCP `cursor-browser-extension` / Playwright-backed tools) that support:
- Navigate to a URL
- Snapshot page accessibility tree (optional)
- Take screenshots
- Click links/buttons by accessible name/role

---

### Important security constraint
- **Do not paste passwords into the prompt.**
- **The AI will not fill the login form with credentials.**
- The **human must complete login** manually in the same browser session/tab that the AI opened.

---

### Human-assisted login flow (one-time per session)
1. AI navigates to the login page.
2. Human completes login.
3. Human tells the AI: **“you are now logged into wordpress continue the process”**.

---

### Copy/Paste Prompt Template (AI)

Use this prompt block as-is, editing only the placeholders.

```text
You are assisting with WordPress admin UI testing via browser automation tools.

Target site (local):
- WP login URL: http://localhost:8090/wp-login.php
- Ads list URL: http://localhost:8090/wp-admin/edit.php?post_type=aa_ads

Constraints:
- Do NOT ask me for or type credentials.
- You may only proceed past login after I confirm I’m logged in.

Tasks:
1) Open a new browser tab and resize viewport to 1400x900.
2) Navigate to http://localhost:8090/wp-login.php
3) Take a full-page screenshot named `wp-login.png`.
4) STOP and ask me to log in manually in that same tab. Do not proceed until I say I’m logged in.
5) After I confirm login, navigate to http://localhost:8090/wp-admin/edit.php?post_type=aa_ads
6) Take a full-page screenshot named `aa-ads-list.png` and give a brief UI/UX review of the page.
7) Click the ad title link for: "AA PMI Training" (Edit) which should go to:
   http://localhost:8090/wp-admin/post.php?post=7&action=edit
8) Take a full-page screenshot named `aa-pmi-training-edit.png` and give a brief UI/UX review of the edit screen.
```

---

### Variations (common edits)
- **Different WP port**: replace `8090` with your port.
- **Different ad to open**:
  - Change the link text target from **“AA PMI Training”** to your ad title.
  - Or directly navigate to a known edit URL:
    - `http://localhost:8090/wp-admin/post.php?post=<ID>&action=edit`
- **Add a quick sort test (Campaigns/Clients)**:
  - After loading the Ads list, click the **Campaigns** header once (ASC) and again (DESC), capturing screenshots like:
    - `aa-ads-list-sort-campaigns-asc.png`
    - `aa-ads-list-sort-campaigns-desc.png`

---

### Notes (what “success” looks like)
- The AI reaches the Ads list without being redirected to login.
- The Ads list shows expected columns (e.g., Campaigns/Clients sortable links, Ad Image previews if enabled).
- The ad edit screen loads and the ACF fields/metaboxes are present.

