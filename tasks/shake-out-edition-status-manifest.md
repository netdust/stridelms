# Shake-out: Edition Status Frontend Display

**Date:** 2026-05-18
**Scope:** Verify every `OfferingStatus` value drives a correct CTA, message, badge, and form on the public frontend.
**Method:** Pinned existing editions to each of the 9 statuses, fetched single-edition page, online-course detail page, and catalog grid; parsed CTA / badge / sidebar / mobile-sticky for each. State restored after sweep.

**Iron Law:** This is a manifest. No fixes applied.

---

## Status Coverage Matrix — Single Edition Page (`single-vad_edition.php`)

Sweep results for a logged-out visitor on a future-dated edition (date overrides isolated separately):

| Status | Header Badge | Sidebar Header | Sidebar CTA | Mobile Sticky | Verdict |
|--------|---|---|---|---|---|
| draft | n/a (post_status=draft → 404) | n/a | n/a | n/a | OK — hidden |
| announcement | "Vooraankondiging" (`badge-few`) | "Inschrijven" | "Interesse melden" → `/interesse/?editie=…` | "Interesse melden" | OK |
| open (>5 spots) | "Open voor inschrijving" | "Inschrijven" | "Nu inschrijven" → `…/inschrijving/` | "Nu inschrijven" | OK |
| open (≤5 spots) | "Open voor inschrijving" ❌ | "Inschrijven" | "Nu inschrijven" | "Nu inschrijving" | **BUG #1** |
| full | "Volzet" (`badge-full`) | "Inschrijven" | "Op wachtlijst plaatsen" → `/wachtlijst/?editie=…` | "Op wachtlijst plaatsen" | OK |
| in_progress | "Lopend" (`badge-open` ⚠ green) | "Inschrijven" ❌ | "Niet beschikbaar" disabled | none | **BUG #2** |
| postponed | "Uitgesteld" (`badge-cancelled` red) | "Inschrijven" ❌ | "Niet beschikbaar" disabled | none | **BUG #2** |
| cancelled (future-dated) | "Geannuleerd" (`badge-cancelled`) | "Inschrijven" ❌ | "Niet beschikbaar" disabled | none | **BUG #2** |
| completed (future-dated end_date) | "Afgerond" (`badge-online` ⚠ blue) | "Inschrijven" ❌ | "Niet beschikbaar" disabled | none | **BUG #2, BUG #3** |
| archived (future-dated end_date) | "Gearchiveerd" (`badge-cancelled`) | "Inschrijven" ❌ | "Niet beschikbaar" disabled | none | **BUG #2** |
| any non-terminal with past end_date | "Afgelopen" (overrides) | "Deze editie is afgelopen" | "Editie is afgelopen" disabled | none | OK |

---

## Bugs

### BUG #1 — CRITICAL — "Nog X plaatsen" few-spots badge is dead code

**Symptom:** An edition with `status=open`, `capacity=5`, `spots_remaining=3` shows badge `Open voor inschrijving` instead of `Nog 3 plaatsen` on both the single-edition page and the catalog grid (`card-edition.php`). The auto-detect logic in `partials/badge-status.php` (lines 18-21) never fires.

**Reproduction:**
- Edition 13311 has raw meta `_ntdst_spots_remaining = 3` set via `update_post_meta`.
- `$editionModel->getMeta(13311, 'spots_remaining')` returns `NULL`.
- Both `single-vad_edition.php:91` and `partials/card-edition.php:41` read this field; both get null; `badge-status` never converts `open` → `few_spots`.

**Root cause:** `spots_remaining` is **not declared as a field** in `EditionCPT::getFields()` (`stride-core/Modules/Edition/EditionCPT.php`). The data manager only exposes registered fields. Raw `_ntdst_spots_remaining` meta exists in the DB (legacy?) but the model layer can't read it.

**Affected surfaces:**
- `single-vad_edition.php:188` (header badge)
- `partials/card-edition.php:106` (catalog card badge)
- `templates/course/editions-list.php` does NOT use this — it just uses `$status->label()`, so safe there.

**Two possible fixes:**
- Register `spots_remaining` as a derived field (read-only, computed from `capacity − registered_count`)
- Or replace the template's `getMeta('spots_remaining')` call with `EditionService::getCapacity() − EditionService::getRegisteredCount()`

---

### BUG #2 — HIGH — Sidebar header reads "Inschrijven" for non-enrollable, non-past statuses

**Symptom:** On `single-vad_edition.php`, when an edition is `in_progress`, `postponed`, `cancelled`, `completed`, or `archived` with a future or unset end_date, the sidebar card header reads **"Inschrijven"** while the CTA is the disabled "Niet beschikbaar". Header copy contradicts the CTA state.

**Reproduction:** Pin any edition to one of those statuses, leave end_date in the future, load `/vormingen/<slug>/`. Sidebar shows: header = "Inschrijven", button = "Niet beschikbaar" disabled.

**Root cause:** `single-vad_edition.php:442-449` switches the header only on `$is_past`. There's no branch for terminal statuses.

```php
if ($is_past) { esc_html_e('Deze editie is afgelopen'); }
else { esc_html_e('Inschrijven'); }
```

**Expected:**
- `cancelled` → "Editie geannuleerd"
- `postponed` → "Editie uitgesteld"
- `completed` / `archived` → "Editie afgelopen" (consistent with past-date override)
- `in_progress` → "Editie is bezig" (or similar)

---

### BUG #3 — HIGH — `completed` status header badge says "Afgerond" with `badge-online` (blue) styling

**Symptom:** For a `completed` edition, the header badge renders as "Afgerond" with class `badge-online` (presented in the platform's online/blue accent). The status enum's `label()` returns "Afgelopen" — but `badge-status.php`'s lookup table at line 33 maps `completed => ['class' => 'badge-online', 'label' => 'Afgerond']`.

**Two divergent sources of truth:**
- `OfferingStatus::label()` (Domain): `Completed => 'Afgelopen'`
- `partials/badge-status.php`: `'completed' => 'Afgerond'`

"Afgerond" implies a personal completion (cf. card-edition.php's enrolled badge "Afgerond" at 100% LD progress). "Afgelopen" implies the edition is over. The badge-status partial is using the wrong word AND wrong color tone for a public-status indicator.

**Affected surfaces:**
- `single-vad_edition.php:188` — header badge when `is_past=false` (otherwise overridden to "Afgelopen")
- `partials/card-edition.php:104` — catalog card if a `completed` edition ever leaks past the listing filter

Note: catalog listings filter `completed` out (`page-klassikaal.php:36`, `archive-sfwd-courses.php:38`), so this only manifests on the single page or `editions-list.php` (course detail) where filtering is looser.

---

### BUG #4 — CRITICAL — Online-course sidebar bypasses edition status entirely

**Symptom:** On an online course detail page (`/opleidingen/<slug>/`), the sidebar (`templates/course/sidebar-online.php`) and mobile sticky CTA do not consider the underlying edition's `OfferingStatus`. When the edition behind the course is `announcement`, `full`, `in_progress`, `postponed`, `cancelled`, `completed`, or `archived`, the sidebar falls back to LearnDash's `learndash_payment_buttons()` showing **"Enroll in this course"** — bypassing the entire Stride status model.

**Reproduction:** Pin edition `13190` (behind course `13186` "E-learning: Eetproblemen…") to each status, fetch `/opleidingen/e-learning-eetproblemen-herkennen-en-bespreekbaar-maken/`. Sidebar CTA results:
- `open` → "Inschrijven" → `/vormingen/<edition-slug>/inschrijving/` ✅
- everything else → LearnDash button "Enroll in this course" ❌

**Root cause:** `sidebar-online.php:236-252` decides between:
1. `is_open && has_access` → "Direct starten" (LD open mode)
2. `$enrollment_url` → "Inschrijven" (only set when `canEnroll() === true`)
3. `$ld_buttons` non-empty → LD payment buttons fallback
4. else → login redirect with `$cta_label`

Branch (1) only runs when LD course access mode is "open" AND the user is enrolled. For an anonymous visitor on a paid course with a non-Open edition, `$enrollment_url` is empty (because `canEnroll === false`) → falls through to `$ld_buttons`.

**Severity rationale:** Visitor can complete a LearnDash enrollment for a course whose Stride edition is cancelled/archived/etc., bypassing Stride's registration flow and producing inconsistent state (LD says enrolled, Stride says no registration). For paid courses with Stripe configured this is also a billing issue.

**Affected surfaces:**
- `templates/course/sidebar-online.php` (desktop + lg+)
- `templates/course/mobile-cta.php` (mobile sticky bar — confirmed same behaviour in sweep)

---

### BUG #5 — IMPORTANT — Catalog grid (`page-klassikaal.php`) does not exclude `cancelled`, `in_progress`, `postponed` editions

**Symptom:** The classroom catalog at `/klassikaal/` shows edition cards for editions whose status is `cancelled` (badge "Geannuleerd"), `in_progress` (badge "Lopend"), or `postponed` (badge "Uitgesteld"). These editions are not enrollable — clicking the card lands the visitor on a disabled CTA.

**Reproduction:** Visit `/klassikaal/` — observe (from sweep):
- "Workshop Yoga en Mindfulness voor Jongeren" → Geannuleerd
- "Sportblessures Voorkomen…" → Lopend
- "Sportblessures Voorkomen…" → Uitgesteld

**Root cause:** `page-klassikaal.php:33-39` filters out only `['draft', 'completed', 'archived']`. Same filter shape in `archive-sfwd-courses.php:38-40`.

**Question for product:** Should `cancelled` / `in_progress` / `postponed` editions appear in the public discovery feed? Showing `Lopend` is debatable (informs visitors a recent cohort started). Showing `Geannuleerd` and `Uitgesteld` in the catalog is misleading — they look enrollable until the card is clicked.

---

### BUG #6 — IMPORTANT — `postponed` and `archived` use `badge-cancelled` (red) styling

**Symptom:** Both `postponed` and `archived` statuses render with the `badge-cancelled` class. Visually: a postponed (= delayed but not killed) edition looks identical to a cancelled one (red/destructive tone). Same for archived (a passive, historical state, not a destructive one).

**Source:** `partials/badge-status.php`:
- Line 31: `'postponed' => ['class' => 'badge-cancelled', 'label' => 'Uitgesteld']`
- Line 34: `'archived' => ['class' => 'badge-cancelled', 'label' => 'Gearchiveerd']`

**Expected:** Distinct neutral/muted tone for `archived`; warning/amber tone for `postponed`. The admin badge config in `OfferingStatus::badgeConfig()` already distinguishes them (postponed = `#dba617` amber, archived = `#787c82` grey, cancelled = `#a7aaad` grey) — the frontend partial collapses these into one red class.

---

### BUG #7 — MINOR — `in_progress` uses `badge-open` (green) styling

**Symptom:** `in_progress` status renders with `badge-open` class — the same green tone as "Open voor inschrijving". Visually conflates a running cohort with an enrollable one.

**Source:** `partials/badge-status.php:30`: `'in_progress' => ['class' => 'badge-open', 'label' => 'Lopend']`

**Expected:** Neutral or "in-flight" tone (blue/info) — consistent with `OfferingStatus::badgeConfig()` which uses `#2271b1` blue (`badge-online` would be closer).

---

### BUG #8 — IMPORTANT — Enrollment form route renders the form for `closed` mode

**Symptom:** Visiting `/vormingen/<slug>/inschrijving/` for an edition whose status is `cancelled` / `postponed` / `in_progress` / `completed` / `archived` enters `EnrollmentRouter::handleCourseEnrollment()` with `enrollment_mode='closed'`. The form template at `templates/forms/enrollment.php` does not branch on the mode — it renders the full multi-step form. The Alpine config receives `enrollmentMode: 'closed'`, but `enrollment.js` has no `closed` branch (only `interest`, `waitlist`, `pending_approval`, default).

**Impact:** Low blast radius from normal navigation — the edition single page correctly shows "Niet beschikbaar" disabled CTA, so users don't reach the URL through the UI. The risk is stale bookmarks, indexed URLs, or direct manipulation: visitor lands on a fully-functional form, fills it, hits submit — the server (presumably) rejects, but the visitor wastes effort.

**Source:** `templates/forms/enrollment.php` (lines 23, 133 — mode is passed to Alpine but no PHP-side branch).
`templates/forms/enrollment.js:18` — `closed` falls through to default `enrollment` mode in JS.

**Expected:** When `mode === 'closed'`, render a static "Inschrijving niet beschikbaar" panel with a return-to-edition link, similar to the existing 404 path. Or 302-redirect from the router back to the edition page.

---

### BUG #9 — MINOR — Course detail (`editions-list.php`) doesn't filter editions by status

**Symptom:** `EditionRepository::findByCourse()` (line 22-30) and the consuming `templates/course/editions-list.php` (used on in-person course detail pages) pass **all** editions through regardless of `_ntdst_status`. A `draft` edition pinned to a published course would show in the upcoming list. A `cancelled` edition appears with its status label.

**Reproduction:** I couldn't directly hit an in-person course detail page in the sweep (URL collisions with single-edition slugs caused redirects), but the code path is clear:
- `single-sfwd-courses.php:31` → `getEditionsForCourse()` → `findByCourse()` with NO status filter
- `editions-list.php:53` reads `getStatus()` to render the badge but doesn't skip

**Severity:** Minor only because in-person course detail pages may not be a primary discovery surface for the current site — but inconsistent with the catalog grid's filtering.

---

### Observations (not bugs, but flagged)

#### Asymmetric desktop vs. mobile CTA fallback
The single-edition page's desktop sidebar (lines 487-521) has a fallback `elseif ($is_enrolled)` → "Ingeschreven" span at line 491-494. The mobile sticky bar (lines 528-553) does NOT — when `$enrolled_cta` is null AND the user is enrolled AND the edition isn't past, the mobile sticky bar shows nothing. Probably intentional (mobile doesn't need a status confirmation), but worth confirming.

#### `badge-status` partial mixes registration-status keys with edition-status keys
Lines 36-41 add `confirmed`, `pending`, `enrolled`, `action_required`, `awaiting_approval`, `completing`. These are registration-status values being rendered through the same partial as edition-status values. Not a bug — but a sign that the partial is doing two jobs.

---

## Severity Summary

| # | Severity | Bug |
|---|----------|-----|
| 1 | **CRITICAL** | Few-spots badge never fires — `spots_remaining` field not in schema |
| 4 | **CRITICAL** | Online-course sidebar bypasses edition status, falls back to LD "Enroll" |
| 2 | HIGH | Sidebar header "Inschrijven" for non-enrollable terminal statuses |
| 3 | HIGH | `completed` badge says "Afgerond" with blue tone (should be "Afgelopen") |
| 5 | IMPORTANT | Catalog shows `cancelled` / `in_progress` / `postponed` editions |
| 6 | IMPORTANT | `postponed` and `archived` styled red like `cancelled` |
| 8 | IMPORTANT | `/inschrijving/` form renders for `closed` mode |
| 9 | MINOR | `editions-list.php` on course detail doesn't filter by status |
| 7 | MINOR | `in_progress` styled green like enrollable |

## Clustering (likely shared fixes)

- **Cluster A — Badge mapping divergence (Bugs #3, #6, #7):** `partials/badge-status.php` has its own label + class table that contradicts `OfferingStatus::label()` and `OfferingStatus::badgeConfig()`. Likely fixed by making the partial source from the enum (or by extending the enum with a `frontendBadgeClass()` method).
- **Cluster B — Status-aware UX gaps (Bugs #2, #4, #5, #8, #9):** Multiple templates fail to consider terminal statuses. Each is an independent fix but they share a thinking pattern.
- **Cluster C — Few-spots (Bug #1):** Independent; schema fix.

---

## What's NOT in this manifest (out of scope for this sweep)

- Logged-in / enrolled user experience on each status (test data only had logged-out sweeps; cookie-based curl login failed)
- Trajectory status display (same enum, but different templates: `templates/trajectory/`)
- Admin-side status switching, save flow, validation
- LMS adapter behavior (`LearnDashService::grantAccess` on a Cancelled edition)
- Email content per status (confirmation / waitlist / interest emails)
- Status transitions other than `Open ↔ Full` (no other auto-transitions exist — confirmed)
- Status-aware indexing/sitemap behavior
