### AI Cheat Sheet — Browser MCP / Playwright for WordPress Admin Work

### Why this exists
This is a **practical prompt cheat sheet** for using an AI agent with Browser MCP / Playwright-style tools to do **backend WordPress admin** testing, debugging, and content/admin operations.

Key constraint:
- **Login is human-only** in many setups (credentials guardrail). The AI should navigate to `/wp-login.php`, then **pause** until you confirm you’re logged in.

---

### “Trigger words” (phrases that reliably steer the agent)
Use these phrases explicitly in your prompts:

- **“browser_snapshot first”**
  - Forces the agent to snapshot the accessibility tree before interactions.
- **“click by role/name (not CSS selectors)”**
  - Prevents brittle selectors; works best in WP admin.
- **“take fullPage screenshot + brief UI/UX review”**
  - Produces visual verification + a concise critique.
- **“collect console + network”**
  - Triggers `browser_console_messages` and `browser_network_requests` for JS/AJAX debugging.
- **“wait_for text ‘…’ before continuing”**
  - Reduces flakiness after save/update/navigation.
- **“verify admin-ajax.php request status + payload”**
  - Focuses debugging on the key request/response.
- **“evaluate JS to verify state”**
  - Uses `browser_evaluate` to check DOM/state instead of guessing.
- **“stop at login and wait for my confirmation”**
  - Enforces the human-login step.

---

### Browser automation SOP (copy/paste block)

```text
Browser automation SOP (WordPress admin):

- Set viewport to 1400x900.
- After every navigation: run browser_snapshot.
- Before every click/type: use the latest snapshot and click by role + accessible name (avoid CSS selectors).
- For UI validation: take a fullPage screenshot and include a brief UI/UX review.
- For debugging: after reproducing, collect browser_console_messages and browser_network_requests.
- For AJAX: find admin-ajax.php calls and verify status + response JSON.
- For verification: use browser_evaluate to confirm expected DOM/text/classes.
- Login: I will log in manually. Stop at wp-login and wait for my “logged in” message.
```

---

### Tool actions that are most useful in WP admin (what to ask for)
These are the highest-leverage tool categories for backend work:

- **Navigation**
  - “open new tab”, “resize viewport”, “navigate to …”
- **Targeting**
  - “snapshot first”, “click by role/name”
- **Debugging**
  - “collect console + network”, “verify request/response”
- **Verification**
  - “evaluate JS to check DOM/state”
- **Evidence**
  - “fullPage screenshot”, “before/after screenshots”

---

### Copy/paste prompt templates

#### Template A — Login (human), then open a specific admin page
```text
1) Open a new tab; set viewport 1400x900.
2) Navigate to http://localhost:8090/wp-login.php
3) Take a fullPage screenshot named wp-login.png.
4) STOP. I will log in manually in that tab. Wait for me to say “logged in”.
5) After I confirm, navigate to http://localhost:8090/wp-admin/
6) browser_snapshot
7) Take a fullPage screenshot named wp-admin-dashboard.png + brief UI/UX review.
```

#### Template B — Validate the Ads list UI + open a specific ad
```text
Precondition: I’m already logged in (same browser session).

1) Navigate to http://localhost:8090/wp-admin/edit.php?post_type=aa_ads
2) browser_snapshot
3) Take fullPage screenshot aa-ads-list.png + brief UI/UX review.
4) Click the ad title link “AA PMI Training” (Edit).
5) Wait for the title “Edit Ad”.
6) Take fullPage screenshot aa-pmi-training-edit.png + brief UI/UX review.
```

#### Template C — Debug an AJAX flow (admin-ajax.php)
```text
Precondition: logged in (if needed) and you’re on the page where the issue reproduces.

1) Reproduce the issue (click the UI element that triggers the behavior).
2) Collect browser_console_messages.
3) Collect browser_network_requests.
4) Identify relevant admin-ajax.php requests; report:
   - URL, method, status code
   - response payload (JSON)
5) Take a screenshot of the final UI state + brief summary of what’s wrong.
```

#### Template D — Verify something via JS (no guessing)
```text
1) browser_snapshot
2) Use browser_evaluate to confirm:
   - page title/URL is correct
   - expected DOM exists (e.g., a table column header, a class, or text)
3) Provide the evaluated result + a short interpretation.
```

---

### Notes (WordPress-specific tips)
- **Prefer stable targets**: in WP admin, links/buttons usually have strong accessible names; clicking by role/name is far more reliable than selectors.
- **Use “wait_for text”** after saves/updates:
  - Examples: “Updated.”, “Post updated.”, “Settings saved.”
- **Sorting/pagination**: after clicking a column header, confirm the URL query params changed (e.g., `orderby=...&order=...`) and take a screenshot for proof.

