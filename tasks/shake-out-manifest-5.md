# Shake-out Round 5 — Public-Facing Pages

**Date:** 2026-05-16
**Scope:** All anonymous + authenticated public routes — footer pages, content pages, course/edition single views, dashboard tabs, auth flow.
**Baseline:** Unit 706, Integration 261, Acceptance 99 — all green before sweep.

## Summary

**14 bugs found across 6 clusters; 7 fixed (all CRITICAL + all IMPORTANT authorized), 1 new found while fixing, 6 MINOR deferred.**

| Bug | Status | Notes |
|---|---|---|
| B5-001 (CRITICAL) | ✅ Fixed 2026-05-16 | 3 orphan stub pages trashed |
| B5-002 (CRITICAL) | ✅ Fixed 2026-05-16 | 4 footer pages rewritten with neutral Dutch copy + stale page-template meta removed |
| B5-003 (CRITICAL) | ✅ Fixed 2026-05-16 | Cluster fix: 4 ntdst-auth templates lost the duplicate `<title>` line |
| B5-004 (IMPORTANT) | ✅ Fixed 2026-05-16 | Minimal scope: routes Dutchified, password reset uses native WP flow |
| B5-005 (IMPORTANT) | ✅ Resolved by B5-001 | `/trajecten-page/` trashed |
| B5-006 (IMPORTANT) | ✅ Resolved by B5-001 | Single canonical route restored |
| B5-007 (IMPORTANT) | ✅ Fixed 2026-05-16 | Admin bar hidden for learner roles |
| B5-008 (IMPORTANT) | ✅ Fixed 2026-05-16 | 3 JS + 4 CSS handles dequeued from public pages |
| B5-009 to B5-014 (MINOR) | ⏸️ Deferred | Per round-5 handoff |
| B5-015 (IMPORTANT, found-during-fix) | ⏸️ Deferred | ntdst-auth i18n — separate task |

**Test status after fixes:** Unit 706/706 green. Integration 261/261 green. Acceptance: 5 failures + 3 errors caused by B5-004 route rename (`/login` → `/aanmelden`). Test suite has 21 hardcoded `/login` and `/register` references. Updating tests now — fix the test, not the code (Dutch routes were authorized).

---

## CRITICAL (3)

### B5-001 — Three frontend shortcodes are not registered (raw `[shortcode]` text rendered to users) — RESOLVED 2026-05-16
**Severity:** CRITICAL
**Pages affected:** `/offerte/`, `/sessiekeuze/`, `/trajecten-page/`
**Status:** ✅ **RESOLVED** — three stub pages trashed (IDs 2545, 1334, 1330). All three now return 404.
**Root cause:** Orphan stub pages from an early scaffold. The three shortcodes were never written; no code/menu/flow links to these pages. Real flows: session selection lives inside `/vormingen/{slug}/voltooien/` (completion route, handles keuzecursus); quote viewing lives in the dashboard `/mijn-account/?tab=offertes`.
**Fix:** `ddev wp post delete 2545 1334 1330` (moved to trash).
**Also resolves:** B5-005 (same `/trajecten-page/` page).
**Partially resolves:** B5-006 (orphan trajectory page gone; CPT archive `/trajecten/` still needs the post-launch decision).

### B5-002 — Footer pages contain leftover SafeAndSound English placeholder content — RESOLVED 2026-05-16
**Severity:** CRITICAL
**Pages affected:** `/agenda/` (14542), `/contact/` (14543), `/faq/` (14544), `/over-ons/` (14545)
**Status:** ✅ **RESOLVED** — all four pages updated with neutral Dutch copy (H1 + short paragraph, no brand-specific classes). Also removed stale `_wp_page_template = safeandsound-page-stub.php` meta on all four (root cause of SAS-style residue). Verified pages render clean Dutch H1 with no English/SAS markers.
**What users see:**
- Agenda: "AGENDA · FESTIVAL SEASON 2026 — Pick a night. Get on the list. Three workshop formats, one peer-educator track. Free for…"
- Contact: "BOOK · ASK · BRING US TO YOUR VENUE — Drop us a line. We'll work it out. Schools, youth clubs, festival pre-events…"
- FAQ: "FAQ · STILL UNSURE? · ASK A REAL HUMAN — Things people always ask. Eight questions we get most weeks…"
- Over ons: "Safe & Sound began in 2024 after a teenage…"
**Root cause:** LAUNCH-CHECKLIST §D.3 created placeholder pages with Dutch H1 + short body, but the actual content currently in those posts is leftover SafeAndSound demo content (not the placeholders). Something replayed/overwrote them — possibly when the Kindred client mu-plugin landed 2026-05-15.
**Risk:** Soft-launch blocker. These are linked from the global footer.
**Note:** `/voorwaarden/` (14546) is fine — has real Dutch terms. `/privacy/` (3) has WP-default boilerplate ("Suggested text: Our website address is…") but no English narrative.

### B5-003 — Login page renders two `<title>` tags — RESOLVED 2026-05-16
**Severity:** CRITICAL (low impact, high visibility — SEO/UX issue)
**Page affected:** `/login/` (plus cluster: `/register/`, `/activate/`, `/error/`)
**Status:** ✅ **RESOLVED** — removed hardcoded `<title>` line from all 4 ntdst-auth templates (`login.php`, `register.php`, `error.php`, `activate.php`). `wp_head()` already prints one because the theme declares `add_theme_support('title-tag')`. Verified `/login/` now renders single title.
**Root cause:** Templates printed `<title>` literally on line 19, then called `wp_head()` which printed another via WP's `_wp_render_title_tag()`. Pre-2014 pattern that should've been removed when theme adopted `title-tag` support.
**Cluster fix:** Same one-liner removed from 3 sibling auth templates.

---

## IMPORTANT (5)

### B5-004 — Auth routes `/registreren/` and `/wachtwoord-vergeten/` return 404 — RESOLVED 2026-05-16
**Severity:** IMPORTANT
**Status:** ✅ **RESOLVED** — minimal-scope Dutchification per user decision (build a custom reset flow rejected as out of ship-mode scope).
**Fix:**
- `Config::defaults()` URLs flipped to Dutch: `login_url=/aanmelden`, `register_url=/registreren`, `activate_url=/account-activeren`, `redirect_after_logout=/aanmelden`.
- All 11 hardcoded English fallback strings in `Config::get('login_url', '/login')`-style calls updated to Dutch fallbacks (consistency only — defaults always merge, so fallbacks are dead code in practice).
- Added "Wachtwoord vergeten?" link in `login.php` password-mode form pointing to `wp_lostpassword_url()` (native WP reset; user gets Dutch UI to enter email then English wp-login.php for the actual reset form — acceptable for v1).
- `ddev wp rewrite flush`. Verified: `/aanmelden/` 200, `/registreren/` 200, `/login/` 302 → `/aanmelden`.
- `/wachtwoord-vergeten/` 404 by design — nothing in the codebase links to that slug; the "Forgot your password?" link goes through WP's native lostpassword URL.

**New issue found while fixing (B5-015 — defer):** ntdst-auth UI is all English ("Sign In", "Register", "Email", "Forgot your password?") on a Dutch site. Plugin has no `languages/` directory, no `.po`/`.mo` files. Pre-existing issue, not caused by these changes.

### B5-005 — `/trajecten-page/` raw shortcode `[stride_trajectory_catalog]` (clustered with B5-001) — RESOLVED 2026-05-16
**Severity:** IMPORTANT
**Status:** ✅ **RESOLVED** — page trashed in B5-001 fix. Now 404.

### B5-006 — Duplicate Trajectory landing pages: `/trajecten/` (CPT archive) + `/trajecten-page/` (WP page) — RESOLVED 2026-05-16
**Severity:** IMPORTANT
**Status:** ✅ **RESOLVED** — `/trajecten-page/` trashed in B5-001. `/trajecten/` CPT archive is now the single canonical route, renders title "Trajecten" with 4 published trajectories. Confirmed acceptable per user.
**Note:** Whether to fully hide trajectory pre-launch (per LAUNCH-CHECKLIST §H) is a deploy-time decision, not a shake-out scope item.

### B5-007 — WordPress admin toolbar visible to logged-in students on frontend — RESOLVED 2026-05-16
**Severity:** IMPORTANT
**Status:** ✅ **RESOLVED** — added `show_admin_bar` filter in `BrowserHooks::hideAdminBarForLearners()`. Rule: admin bar only shown to users with `stride_view` capability (administrator, stride_coordinator, stride_supervisor). All learner-facing roles (subscriber, instructor, group_leader, partner) lose it.
**Verified:** seed_student1 dashboard markup no longer contains `id="wpadminbar"`, `wp-admin/profile.php`, `wp-admin/about.php`. Admin login still has full admin bar.
**Note:** Filter respects existing impersonation hook which sets `__return_true` at priority 999 — impersonation override still works.

### B5-008 — Tin-Canny LearnDash reporting JS loads on every public page — RESOLVED 2026-05-16
**Severity:** IMPORTANT (performance)
**Status:** ✅ **RESOLVED** — added `LearnDashHooks::dequeueTinCannyOutsideLDContext()` hooked on `wp_enqueue_scripts` priority 999. Dequeues 3 JS handles (`tc_runtime`, `tc_vendors`, `wp-h5p-xapi`) + 4 CSS handles (`wp-h5p-xapi`, `datatables-styles`, `uotc-group-quiz-report`, `snc-style`) when NOT on a LearnDash CPT (`sfwd-courses`, `sfwd-lessons`, `sfwd-topic`, `sfwd-quiz`, `sfwd-assignment`, `sfwd-certificates`).
**Root-cause subtlety:** Tin-Canny's `Init.php:88` hooks at priority 100. Initial attempt at priority 100 was a no-op because hook-execution order within the same priority depends on registration order, and theme bootstrap ran first. Bumped to 999 — now reliably runs last.
**Verified:** 9 public pages (`/`, `/agenda/`, `/contact/`, `/faq/`, `/aanmelden/`, `/klassikaal/`, `/online/`, `/cursussen/`, `/mijn-account/`) show zero tin-canny references. Course page still loads all 7 assets.
**Perf gain:** ~70KB CSS + 3 JS bundles removed from every public page load.

---

## NEW (found during fixes)

### B5-015 — ntdst-auth UI is English on Dutch-locale site
**Severity:** IMPORTANT (pre-launch polish)
**Found while fixing:** B5-004.
**Evidence:** `/aanmelden/` renders "Sign In", "Email", "Password", "Forgot your password?", "Register" labels despite WP locale being nl_BE.
**Root cause:** ntdst-auth plugin has no `languages/` directory — no `.po`/`.mo` files for `ntdst-auth` text domain. All `__('...', 'ntdst-auth')` calls fall through to English source strings.
**Fix scope:** ~30 min — create `languages/ntdst-auth-nl_BE.po` with translations for the ~20 user-facing strings (login.php, register.php, error.php, activate.php). Compile to `.mo`. Pure i18n work.
**Defer?** Yes — separate bug, not blocking other shake-out work.

---

## MINOR (6)

### B5-009 — `/privacy/` page is WP-generated boilerplate ("Suggested text:" markers visible)
**Severity:** MINOR
**Evidence:** Page contains "Our website address is: https://stride.ddev.site" and `<strong class="privacy-policy-tutorial">Suggested text:</strong>`.
**Note:** §D.3 says privacy was "re-slugged to /privacy/ and published". Content still needs final copy before launch.

### B5-010 — `/wp-login.php` returns 404 (intentional via ntdst-auth redirect)
**Severity:** MINOR
**Evidence:** `/wp-login.php` and `/wp-login.php?action=register` both 404. Settings show `redirect_wp_login` is enabled; expected behavior per ntdst-auth design.
**Risk:** None — design choice. Listed only because `wp_login_url()` correctly resolves to `/login`, so admin emergency login must use `?admin=1` query per settings docs.

### B5-011 — `/sitemap.xml` redirects to `/wp-sitemap.xml` (default WP)
**Severity:** MINOR
**Evidence:** 301 redirect. Default WP sitemap active. No custom sitemap.
**Note:** Acceptable for v1, but SEO may want a curated sitemap (courses, editions, trajectories) post-launch.

### B5-012 — `/robots.txt` returns 404
**Severity:** MINOR
**Evidence:** `curl /robots.txt` → 404. WP normally serves a virtual robots.txt; something is preventing it.
**Risk:** Search engines fall back to crawl-everything default — fine for dev, not ideal for prod.

### B5-013 — All LearnDash course-grid cards show fallback thumbnail (`includes/course-grid/assets/img/thumbnail.jpg`)
**Severity:** MINOR
**Evidence:** Every course on `/cursussen/` shows the LD default placeholder. No featured images set.
**Note:** Content problem, not code. Mark for content team — does not block launch but looks generic.

### B5-014 — `/cursussen/` rendering uses LearnDash `[ld_course_list]` shortcode (not Stride-themed)
**Severity:** MINOR (theming)
**Evidence:** Page content is `[ld_course_list col="3" progress_bar="true"]`. Rendered with LD default grid + ribbons ("Enrolled") in English, classes `ld_course_grid col-sm-8 col-md-4`.
**Risk:** UI inconsistency with rest of Stride theme. The /online/ and /klassikaal/ pages have a custom Stride layout (theme filter tabs); /cursussen/ does not.
**Fix scope:** Either skin the LD grid via CSS, or replace with a Stride-rendered catalogue matching /online/.

---

## Clusters (suggested fix order)

| Cluster | Bugs | Root cause | Effort |
|---|---|---|---|
| **C1 Shortcodes** | B5-001, B5-005 | Three shortcodes not registered | 30–60 min (depends on whether classes exist) |
| **C2 Footer content** | B5-002, B5-009 | Leftover SafeAndSound English content + WP privacy boilerplate | 30 min (WP admin edits, no code) |
| **C3 Login template** | B5-003 | ntdst-auth template prints duplicate `<title>` | 15 min |
| **C4 Auth routes** | B5-004 | Decide spec: do we ship register/forgot-pw URLs? | spec decision, then 30 min |
| **C5 Trajectory routing** | B5-005, B5-006 | Post-launch feature with two leaky routes | 15 min (hide/unpublish, or 404 the archive) |
| **C6 Admin/perf hygiene** | B5-007, B5-008, B5-012 | Frontend hygiene — admin bar leak + tin-canny bundle leak + robots.txt | 20 min |
| **C7 Theming polish** | B5-013, B5-014 | LD course-grid not Stride-skinned | post-launch nice-to-have |
| **C8 Sitemap** | B5-011 | Default WP sitemap, no custom mapping | post-launch SEO |

---

## What was tested (sweep evidence)

- **Existing test suites:** Unit 706/706, Integration 261/261, Acceptance 99/99 — ALL GREEN before this sweep.
- **HTTP smoke:** 16 public routes hit (footer × 6, content × 10), all 200 or expected redirect.
- **Content render check:** title, h1, raw-shortcode scan, PHP error scan on 12 pages.
- **Auth routes:** `/login/`, `/registreren/`, `/wachtwoord-vergeten/`, `/wp-login.php`, plus `/auth/logout`.
- **Single views:** Course (`/opleidingen/{slug}/`), Edition (`/vormingen/{slug}/`) — both render with hero, sessions table, enrollment CTA.
- **Dashboard tabs (logged in as student):** all 8 tabs (home, inschrijvingen, offertes, certificaten, meldingen, profiel, trajecten, downloads) — no errors, no raw shortcodes, content present.
- **Browser sweep (chrome-devtools):** home, /cursussen/, /opleidingen/{slug}/, /agenda/, /sessiekeuze/, /mijn-account/ — no JS console errors, Alpine loaded.
- **Asset pipeline:** Stride theme dist CSS/JS (`stridence-0-css`) loads correctly.
- **Feed / sitemap / robots:** `/feed/` 200, `/sitemap.xml` 301→/wp-sitemap.xml, `/robots.txt` 404.

---

## Out of scope for Round 5

- Multi-brand client mu-plugin visual regression (Kindred/CareCommunity/BWEEG)
- LTI integration (post-launch)
- Trajectory module functional testing (post-launch)
- Mobile/responsive visual checks (human-eye required)
