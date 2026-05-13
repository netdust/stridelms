# Bug Manifest — Admin Dashboard Redesign

**Generated:** 2026-03-25
**Re-swept:** 2026-05-13 (against current code + seeded BWEEG DB)
**Plan:** `docs/plans/2026-03-25-admin-dashboard-plan.md`
**Build status:** Unit tests pass (663/663), spec complete
**Sweep status:** 23 bugs originally found; re-sweep evidence in `tests/_output/resweep/`

---

## Re-sweep Summary (2026-05-13)

Of the original 23 bugs:
- **18 RESOLVED** since 2026-03-25 (Cluster A mostly fixed, slide-over positioning fixed, filter values aligned)
- **5 STILL OPEN** — see "Verified Open" below
- **Visual / UX complaints** added per LAUNCH-CHECKLIST §A.2 (purple, layout density, slide-over content quality, activity feed UX)

---

## Verified OPEN (P0–P2)

### BUG-007 (P0) — Settings notification thresholds don't persist — **FIXED 2026-05-13**
- **What happened:** Save returned 200; reload reverted to defaults.
- **Root cause:** `ntdstAPI` only lived in the theme bundle (frontend). On wp-admin pages it was undefined. `settings.js` fell back to direct REST without the `X-WP-Nonce: wp_rest` header → `/wp-json/ntdst/v1/get_nonce` returned 401 → action POST went through with empty nonce → REST returned `{code: rest_missing_callback_param}`. The fallback misdetected that as success (checked for `result.success === false || result.error`, but REST errors use `code` + `data.status`) → toast said "saved", but params never reached the PHP handler → `update_option` ran with all defaults.
- **Fix:**
  - Extracted `ntdstAPI` to shared mu-plugin asset `web/app/mu-plugins/ntdst-core/assets/js/ntdst-api.js`
  - Added `ntdst_enqueue_api_client()` helper in `ntdst-coreloader.php` that enqueues the script and localizes `window.ntdstAPIConfig.restNonce`
  - `StrideSettingsService::enqueueAssets()` now calls it, and `settings.js` declares `ntdst-api` as dependency
  - Removed the broken fallback in `settings.js` `apiCall()` — now a hard call to `ntdstAPI.call()`
  - Theme keeps its inline copy for now (no theme rebuild needed; the shared file no-ops if `window.ntdstAPI` is already defined). Post-launch tech debt: remove theme's inline copy.
- **Verified:** Saved `capacity_threshold=42, stale_quote=10` → `wp option get stride_notification_rules` returns those values → reload shows them in form. 663 unit tests pass.
- **Status:** RESOLVED

### BUG-009 (P0) — Currency stored as cents, displayed as euros — **FIXED 2026-05-13**
- **What happened:** Quote stored `total=27225` cents (= €272,25) displayed as `€ 27.225,00` in dashboard.
- **Fix:** `AdminAPIController` now converts cents → euros via `Money::cents($n)->amount()` at the API edge for: quote list (subtotal, tax, total, line items) + user-detail quote totals. Added `lineItemsToEuros()` helper for line items.
- **Verified:** OFF-2026-0161 renders €272,25 (was €27.225,00). Line items render €225,00. All 663 unit tests pass.
- **Note on inconsistent storage conventions found:** Quote = cents; Edition = euros (`Money::eur` in EditionService); Trajectory = cents (admin × 100). Documented for post-launch unification.
- **Cluster:** B
- **Status:** RESOLVED

### BUG-014 (P1) — Trajectory price unit ambiguous
- **Re-sweep evidence:** Seeded trajectories have empty `price` meta (API falls back to default 500). Can't reproduce against real data. Will become real once trajectory pricing is populated. Same canonical-unit fix as BUG-009.
- **Status:** OPEN but blocked on trajectory data; **defer** with BUG-009 fix.
- **Cluster:** B

### BUG-021 (P1) — Activity feed shows raw audit events — **FIXED 2026-05-13**
- **Root cause:** `AdminActivityMapper` had no cases for `user.created/updated/deleted/role_changed/profile_updated`, `auth.login/logout`, `impersonation.started`, `edition.created/updated`, or `usermeta.*` — all fell through to `"{$name}: {$action}"` default. Additionally, controller didn't resolve `entity_id` → display name, so user-target events showed actor name instead of target.
- **Fix:**
  - Added all missing event cases to `AdminActivityMapper::resolve()` with proper Dutch text + actor/target distinction
  - `usermeta.*` events collapsed to single "Profielgegevens van X bijgewerkt" line
  - Added `targetName` parameter to `fromAuditEntry()` for controller-resolved entity names
  - `AdminAPIController` (activity feed + user-detail audit_trail) now batch-resolves `entity_id` for `entity_type='user'` events
  - Graceful fallback "(account niet meer beschikbaar)" when target user has been deleted
  - 11 new unit tests covering the new mappings
- **Verified:**
  - Dashboard feed shows "Gebruiker aangemaakt (account niet meer beschikbaar)" instead of "Systeem: user.created"
  - User detail shows "Pieter Janssen uitgelogd", "Profielgegevens van Pieter Janssen bijgewerkt", "Rol van Pieter Janssen gewijzigd naar subscriber", "Pieter Janssen aangemaakt door Systeem"
  - 674 unit tests pass
- **Note:** §A.2 "activity feed redesign" (group by entity, filter by entity type) is still open — that's a UX change beyond the text fix.
- **Status:** RESOLVED

### BUG-022 (P2) — Course tag filter dropdown empty — **FIXED 2026-05-13**
- **Root cause:** `/admin/course-tags` queried `ld_course_tag` (0 terms in BWEEG). Editions are actually filterable via `stride_theme` (17 terms — content area) and `stride_format` (6 terms — delivery format) on the linked course. `ld_course_tag` remains as ad-hoc admin tagging.
- **Fix:**
  - `AdminAPIController::getCourseTags()` now returns `{theme: [...], format: [...], tag: [...]}` (each `hide_empty:false`).
  - Editions endpoint accepts `theme`, `format`, `tag` params (replaces single `course_tag`).
  - New `buildCourseTaxonomyJoin()` helper builds the per-term JOIN with aliased term_relationships+term_taxonomy joins so filters AND-combine.
  - Template: three dropdowns — "Alle onderwerpen", "Alle vormen", "Alle tags" (last hidden when empty).
  - JS: `editionFilters.{theme,format,tag}`, `editionTaxonomies.{theme,format,tag}`, `loadEditionTaxonomies()` replaces `loadCourseTags()`.
- **Verified:**
  - theme=Beweging (id 29) filters 18 → 10 editions
  - format=Klassikaal (id 17) filters 18 → 14 editions
  - Combined Beweging + Klassikaal → 10 editions (AND)
  - Tag dropdown auto-hides because BWEEG seed has 0 `ld_course_tag` terms
  - 674 unit tests pass
- **Status:** RESOLVED

### BUG-023 (P2) — Quote slide-over missing BTW/subtotal breakdown
- **Re-sweep evidence:** Slide-over template still shows only total. (Not visually re-verified in sweep — kept from original.)
- **Where:** `dashboard.php` quote slide-over details tab.

---

## Trajectory-Specific (P0 by manifest, but Trajectory is DEFERRED for v1)

Per LAUNCH-CHECKLIST: hide Trajectory UI for v1, ship clean. Bugs only matter if UI stays visible:

### BUG-003 — Trajectory detail route missing
- **Re-sweep:** Not retested. If we hide trajectory tab, this is moot.

### BUG-004 — Trajectory duplicate rows
- **Re-sweep evidence:** `duplicateIds: []` — **RESOLVED**.

### BUG-017 — Trajectory mode label not Dutch
- **Re-sweep evidence:** `mode: "cohort"`, `modeLabel: "Cohort"` — **RESOLVED** (API now returns localized label).

---

## RESOLVED (verified 2026-05-13)

| Bug | Resolution evidence |
|-----|---------------------|
| BUG-001 KPI Acties nodig | `stats.actionsNeeded=160`, renders "160" — JS maps `actionCount → actionsNeeded` |
| BUG-002 Slide-over positioning | Panel renders at x=674, y=0, w=600 fixed overlay, z=100000 ✓ |
| BUG-005 User detail blank | Pieter Janssen / student1@seed.test / BWEEG vzw all populate |
| BUG-006 User audit timeline empty | 10 entries return (was 0) |
| BUG-008 Quote "Regels" tab empty | JS now maps `lineItems → items` with `description/price/total` |
| BUG-010 User registration dates blank | `date: "2026-03-31 14:51:22"` → renders `"di 31 mrt 2026"` |
| BUG-011 Status labels missing | `status_label: "Bevestigd"` (and equivalents) across all views |
| BUG-012 User quotes missing number/edition | `number: "OFF-2026-0158", edition_title: "Keuzecursus: Groepsdynamiek..."` |
| BUG-013 Activity avatars "?" | API returns `actor_name`, JS uses `(entry.actor_name || '?')[0]` |
| BUG-015 Edities filter missing Geannuleerd | Dropdown contains `{value:"cancelled", text:"Geannuleerd"}` |
| BUG-016 Offertes filter draft/concept | Dropdown uses `value="draft"` matching API |
| BUG-018 Impersonation button label | Renders "Bekijk als gebruiker" ✓ |

### BUG-019 Dead "Alles bekijken" links — VERIFIED RESOLVED 2026-05-13
Anchors at template:139, 180 have `href="#"` + `@click.prevent="switchView(...)"`. Click test on dashboard "Alles bekijken →" switched view dashboard → edities.

### BUG-020 `post=undefined` in hidden slide-overs — FIXED 2026-05-13
- **Root cause:** Slide-over anchors used inline string concat `'post.php?post=' + selectedX?.id` so href rendered as `post=undefined` when nothing selected, even with `x-show` guard.
- **Fix:** Use API-provided `editUrl` field with null-safe fallback: `:href="selectedX?.editUrl || '#'"` for edition/quote/trajectory slide-overs (template:412, 542, 682). User slide-over uses conditional ternary + x-show guard (template:770).
- **Verified:** `document.querySelectorAll('a[href*="undefined"]')` returns 0. Selected slide-over renders real URL `post.php?post=13615&action=edit`.

---

## Fix Plan (revised priority)

### P0 — must fix before launch (3 bugs)
1. **BUG-007** Settings persistence — debug option-key/cap-check
2. **BUG-009** Currency cents/euros — fix at API edge (`AdminAPIController::quotes`), divide by 100 before send
3. **BUG-021** Activity feed text — Dutch human-readable mapper

### P2 — fix after P0s done (2 bugs)
4. **BUG-022** Course tag dropdown — verify taxonomy + data
5. **BUG-023** Quote BTW/subtotal breakdown — template change

### Verify (not re-tested in sweep, may already be resolved)
6. **BUG-019** Dead "Alles bekijken" / "Meer bekijken" links
7. **BUG-020** "Bewerk in WP" `post=undefined` in hidden slide-overs

### Trajectory-related (3 bugs)
- **BUG-003** route — only fix if trajectory UI stays in v1
- **BUG-004, BUG-017** already resolved

**Recommended:** Hide trajectory UI for v1 per LAUNCH-CHECKLIST §H. Decision: **hide it**.

---

## Original Cluster Analysis (kept for history)

### Cluster A: JS property name mismatches (template ≠ API response)
Original: BUG-001, BUG-005, BUG-006, BUG-008, BUG-010, BUG-011, BUG-012, BUG-013
**Result:** All 8 RESOLVED. JS now does field bridging in `openQuote()`, `selectUser()`, `loadEditions()`, `loadDashboard()`.

### Cluster B: Currency unit inconsistency
Original: BUG-009, BUG-014. **Result:** Still open — root cause moved from "JS divides too aggressively" to "API returns cents but labels them as final amount". One fix needed.

### Cluster C: Slide-over CSS
BUG-002 RESOLVED. Panel renders correctly fixed-overlay.

### Cluster D: Missing API route (BUG-003)
Open or moot pending trajectory v1 decision.

### Cluster E: Filter value mismatches
BUG-015, BUG-016 RESOLVED.

---

## Visual / UX Repair (NEW — LAUNCH-CHECKLIST §A.2)

Not bugs, but launch-blocking quality issues observed during re-sweep:

1. **Color** — purple branding (`color:#7a00df` references in WP global styles, `--accent-color` in dashboard token) feels wrong; pick stable neutral + 1 accent + status colors.
2. **Layout density** — current dashboard has 3-then-2 KPI grid with awkward gaps, large empty whitespace below tables. Doesn't feel like a daily-use tool.
3. **User detail = call center** — current shows enrollments only; needs quotes/invoices + payment status + chronological events in one screen.
4. **Enrollment detail = "what happened"** — current edition slide-over shows roster only, not per-enrollment timeline.
5. **Activity feed UX** — even after BUG-021 fix, raw event stream isn't useful; group by entity (user/enrollment/quote), filter by entity type.
6. **Empty states** — when sparse, current layout breaks visually. Need designed empty states.

---

## Final Status (re-sweep)

**Originally Resolved (March 31 ish):** Cluster A (8), Cluster C (1), Cluster E (2), BUG-004, BUG-017, BUG-018 = 13
**Re-sweep additional resolutions:** None — all already in code
**Still OPEN:** BUG-007, BUG-009, BUG-014 (deferred), BUG-021, BUG-022, BUG-023 (+ verify BUG-019, BUG-020)
**Trajectory-deferred:** BUG-003 (decide v1 visibility)

**Bugs needing fix-work:** 5 (BUG-007, BUG-009, BUG-021, BUG-022, BUG-023) + verify 2 = 7

Re-sweep screenshots: `tests/_output/resweep/01-dashboard-home.png`, `02-dashboard-mid.png`, `03-edition-slideover.png`, `04-user-detail.png`
