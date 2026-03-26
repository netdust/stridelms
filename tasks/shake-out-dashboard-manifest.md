# Bug Manifest — Admin Dashboard Redesign

**Generated:** 2026-03-25
**Plan:** `docs/plans/2026-03-25-admin-dashboard-plan.md`
**Build status:** Unit tests pass (663/663), spec complete
**Sweep status:** Automated 23 checks + browser sweep

---

## Summary

23 issues found: 7 critical, 7 important, 9 minor

---

## Root Cause Clusters

### Cluster A: JS property name mismatches (template ≠ API response)
- BUG-001, BUG-005, BUG-006, BUG-008, BUG-010, BUG-011, BUG-012, BUG-013
- Fix at: `admin-dashboard.js` — bridge API response keys to what template expects (same pattern as the editions/quotes fix already done)

### Cluster B: Currency unit inconsistency (cents vs euros)
- BUG-009, BUG-014
- Fix at: `admin-dashboard.js` `formatCurrency()` — or normalize values per CPT type

### Cluster C: Slide-over CSS positioning
- BUG-002
- Fix at: `admin-dashboard.css` or `dashboard.php` — panel must be `position: fixed` or nested inside overlay correctly

### Cluster D: Missing API route
- BUG-003
- Fix at: `AdminAPIController.php` — register trajectory detail route

### Cluster E: Filter value mismatches
- BUG-015, BUG-016
- Fix at: `dashboard.php` — filter option values must match API status strings

### Standalone
- BUG-004 (trajectory duplicates)
- BUG-007 (settings save not persisting)
- BUG-017, BUG-018, BUG-019, BUG-020, BUG-021, BUG-022, BUG-023

---

## Bug List

### BUG-001 [CRITICAL] — KPI "Acties nodig" shows "—" instead of count
- **Found by:** Automated
- **What happened:** Card shows "—" despite API returning `actionCount: 152`
- **Expected:** Should show "152" (or the count)
- **Where:** `admin-dashboard.js` — stats key mismatch
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-002 [CRITICAL] — Slide-over renders below fold, not as fixed overlay
- **Found by:** Automated
- **What happened:** Panel renders at ~1530px in document flow. User sees dimmed overlay but no panel.
- **Expected:** Fixed overlay on right side of viewport (600px wide)
- **Where:** `admin-dashboard.css` / `dashboard.php` — panel is sibling of overlay, CSS expects child
- **Cluster:** C
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-003 [CRITICAL] — Trajectory detail route missing (404)
- **Found by:** Automated
- **What happened:** `GET /admin/trajectories/{id}` returns "Geen route gevonden"
- **Expected:** Should return trajectory detail with courses, students
- **Where:** `AdminAPIController.php` — route not registered
- **Cluster:** D
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-004 [CRITICAL] — Trajectory table shows duplicate rows
- **Found by:** Automated
- **What happened:** Same trajectories appear twice in table
- **Expected:** Each trajectory should appear once
- **Where:** `admin-dashboard.js` `loadTrajectories()`
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-005 [CRITICAL] — User detail view shows blank name/email/avatar
- **Found by:** Automated
- **What happened:** Template uses `selectedUser.name` but API returns `{user: {display_name: ...}}`
- **Expected:** User header should show name, email, org, avatar initials
- **Where:** `admin-dashboard.js` `selectUser()`
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-006 [CRITICAL] — User audit timeline shows empty
- **Found by:** Automated
- **What happened:** "Geen audit items" despite `audit_trail_total: 30`
- **Expected:** Should show chronological audit entries
- **Where:** `admin-dashboard.js` getter uses `audit_log` but API key is `audit_trail`
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-007 [CRITICAL] — Settings notification thresholds don't persist
- **Found by:** Automated
- **What happened:** Save returns 200, but page reload reverts to defaults
- **Expected:** Changed thresholds should persist across page loads
- **Where:** `StrideSettingsService.php` / `settings.js`
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-008 [IMPORTANT] — Quote slide-over "Regels" tab empty
- **Found by:** Automated
- **What happened:** Template uses `selectedQuote?.items` but API returns `lineItems`
- **Expected:** Line items list with title, type, price
- **Where:** `admin-dashboard.js` / `dashboard.php`
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-009 [IMPORTANT] — Currency amounts divided by 100 incorrectly
- **Found by:** Automated
- **What happened:** Quote €29,645 shows as €296.45. `formatCurrency()` divides by 100 but values may be euros not cents.
- **Expected:** Correct formatted amounts
- **Where:** `admin-dashboard.js` `formatCurrency()`
- **Cluster:** B
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-010 [IMPORTANT] — User registration dates blank
- **Found by:** Automated
- **What happened:** Template uses `reg.date`, API has `reg.registered_at`
- **Expected:** Registration date should display
- **Where:** `admin-dashboard.js` or `dashboard.php`
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-011 [IMPORTANT] — Status labels missing across multiple views
- **Found by:** Automated
- **What happened:** Registration/quote status badges show color but no text. Template uses `reg.status_label` / `quote.status_label` but API returns only `reg.status` / `quote.status`
- **Expected:** Dutch status labels (Bevestigd, Concept, etc.)
- **Where:** `admin-dashboard.js`
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-012 [IMPORTANT] — User quotes table empty (no number, no edition)
- **Found by:** Automated
- **What happened:** User-level quote objects lack `number` and `edition_title` fields
- **Expected:** Quote number and edition name should display
- **Where:** `AdminAPIController.php` `getUserDetail()` quote query
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-013 [IMPORTANT] — Activity feed avatars show "?" instead of initials
- **Found by:** Automated
- **What happened:** Template uses `entry.user` for initials but API returns `entry.actor_name`
- **Expected:** First letter of actor name as avatar
- **Where:** `dashboard.php` template
- **Cluster:** A
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-014 [IMPORTANT] — Trajectory prices wrong (euros treated as cents)
- **Found by:** Automated
- **What happened:** €595 shows as €5.95. `formatCurrency(595)` divides by 100.
- **Expected:** €595,00
- **Where:** `admin-dashboard.js` — trajectory `price` is in euros, not cents
- **Cluster:** B
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-015 [MINOR] — Edities filter missing "Geannuleerd" status option
- **Found by:** Automated
- **What happened:** Dropdown has Open/Gesloten/Vol/Concept but no Geannuleerd
- **Expected:** Include cancelled status option
- **Where:** `dashboard.php` editions filter
- **Cluster:** E
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-016 [MINOR] — Offertes filter status values don't match API
- **Found by:** Automated
- **What happened:** Filter uses `value="concept"` but API status is `"draft"`
- **Expected:** Filter values match API status strings
- **Where:** `dashboard.php` quotes filter
- **Cluster:** E
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-017 [MINOR] — Trajectory mode label not translated to Dutch
- **Found by:** Automated
- **What happened:** Shows "Self_paced" instead of Dutch label
- **Expected:** "Zelfgestuurd" or similar Dutch label
- **Where:** `admin-dashboard.js` trajectory mapping
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-018 [MINOR] — Impersonation button label says "Inloggen als"
- **Found by:** Automated
- **What happened:** Button says "Inloggen als" instead of "Bekijk als gebruiker"
- **Expected:** "Bekijk als gebruiker" per spec
- **Where:** `dashboard.php` gebruikers view
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-019 [MINOR] — "Alles bekijken" and "Meer bekijken" are dead links
- **Found by:** Automated
- **What happened:** Links go to `#` with no navigation
- **Expected:** Navigate to relevant tab
- **Where:** `dashboard.php` dashboard home
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-020 [MINOR] — "Bewerk in WP" links contain undefined when no item selected
- **Found by:** Automated
- **What happened:** Hidden slide-over panels have `post=undefined` in href
- **Expected:** Links only render when item is selected
- **Where:** `dashboard.php` slide-over templates
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-021 [MINOR] — Activity feed shows raw audit events
- **Found by:** Automated
- **What happened:** Shows "Systeem: usermeta.deleted" instead of human-readable text
- **Expected:** Admin-perspective Dutch text from AdminActivityMapper
- **Where:** `AdminAPIController.php` `getActivityFeed()` or mapping
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-022 [MINOR] — Course tag filter dropdown always empty
- **Found by:** Automated
- **What happened:** 0 tags returned from `/admin/course-tags` endpoint
- **Expected:** Should show course categories if they exist
- **Where:** Data issue — possibly no ld_course_tag terms defined
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

### BUG-023 [MINOR] — No BTW/subtotal breakdown in quote slide-over
- **Found by:** Automated
- **What happened:** Only total shown, no subtotal + BTW 21% lines
- **Expected:** Subtotal, BTW 21%, Total as per spec
- **Where:** `dashboard.php` quote slide-over details tab
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:**
- **Fix:**

---

## Fix Log

| Bug | Attempts | Root Cause | Fix | Re-sweep |
|-----|----------|-----------|-----|----------|
| | | | | |

---

## Final Status

**Resolved:** 0
**Deferred:** 0
**New bugs found during fix:** 0
**Final sweep:** PENDING
