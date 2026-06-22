# Stride — Feature Status Matrix

**The overview doc.** One row per user-facing feature, three honest axes, graded against evidence (not vibes). This is the answer to "what's fully built, detailed, and edge-tested — and where's it thin?"

> **Granular companion:** `USER-STORIES.csv` (+ `USER-STORIES.md`) explodes these 19 rows into 154 individual code-derived user stories, each with a per-phase status column for the verify→fix→re-verify loop. Use the CSV to track individual behaviours; use this matrix for the overview.

- **Built** = code exists, boots, core path works.
- **Detailed** = has the polish/edge-handling the feature *intends* (error states, empty states, status-awareness).
- **Edge-tested** = ✅ ONLY if intended flows + edge cases (empty / denied / concurrent / boundary / mid-flow failure) were actually **driven through real flows** (acceptance Cest, shake-out walk, or manual). Green unit tests alone ≠ ✅. **Most features land ⚠️ — that is the point; it's the map of where to work.**

Legend: ✅ solid · ⚠️ partial/known-thin · ❌ missing/broken · — n/a

_Last assessed: 2026-06-08; hardening-sprint addendum 2026-06-10. Evidence: 17 acceptance Cests, 35 shake-out manifests, LAUNCH-CHECKLIST, gap memories. Sources cross-checked by 3 independent readers._

## 2026-06-10 hardening sprint — status changes

**The acceptance suite is real now.** The committed suite could never have run against this environment (hardcoded `stride_` table prefixes vs live `ckqp_`, pre-rename `/vormingen/` URLs, a nonexistent `admin` login, `$I->fail()` undefined). After repair: **107/108 → 108/108 green** (was 23/108 executable). Suite counts: **924 unit + 369 integration + 108 acceptance**.

Fixed this sprint (each with regression tests):
- `TrajectorySelection::validateSelections()` — 4 field-shape bugs; rejected EVERY elective selection (`e8561043`)
- Dashboard nav single-source derivation — sidebar no longer flickers between tabs (`98094869`)
- **Edition-backed online courses had NO enrollment CTA** — `/opleidingen/<slug>/` rendered info-only with no path to enroll when an active edition existed (`d7911813`)
- `isEnrolled()` MODE_FREE gap: confirmed fixed since `1f35717a` (2026-05-20); 4 separate-process regression tests added
- INV-6 write bypass closed (CourseEnrollHandler → adapter), error_log → ntdst_log (2 sites)
- test-login-helper: WP_ENV production gate + env-only secret (old hardcoded secret retired)
- stride-client-vad (the launch brand) was NOT in version control — now tracked; kindred duplicate loader deactivated
- Deferred-MEDIUM audit items re-verified: M1/M3/M4/M5/M6 + H4 were already fixed in code; only M2 + C2/L2 (post-launch modules) remain

Row updates: **Online / self-paced** weakest-link bugs are resolved (gap + CTA loop + missing edition CTA). **User dashboard** nav inconsistency resolved. **Trajectory** validateSelections fixed; cascade child/parent dashboard rendering now acceptance-verified (TrajectoryCascadeCest 2/2) — phased choices + pure-LD electives stay post-launch.

**Phase 3 (targeted P0 edge-testing) — DONE 2026-06-10.** 13 new edge tests across `EnrollmentEdgeCest` (6), `AttendanceCest` (4), `DashboardQuoteGdprEdgeCest` (3); matrix + manifest at `docs/architecture/acceptance-flows/p0-hardening-phase3.md`. Six rows flipped ⚠️/❌→✅: Enrollment-individual, Attendance (was zero-coverage), Invoicing/quotes, Vouchers, User-dashboard, GDPR. Suites: **924 unit + 369 integration + 121 acceptance green.** Now **7 of 19 rows clear the strict edge-tested bar** (was 1 — only Auth). Still ⚠️: trajectory deep-dive, profile validation paths, admin-edition conflict cases, catalog footer/shortcodes — all post-launch or lower-priority.

---

## ✅ Buried CRITICALs — VERIFIED against current code 2026-06-08

These were flagged in shake-out manifests but absent from STATE/checklist. Verified against the tree today — **all the launch-relevant ones were already fixed; the manifests were stale.** This is the value of the matrix: it forced the re-check.

1. **Attendance events don't fire** — ❌ CLAIM STALE / **FIXED.** `AttendanceService::mark()` fires `do_action('stride/attendance/marked', …)` on success (`AttendanceService.php`), and all 3 record call sites (`AdminAPIController:1443`, `EditionAdminController:677,714`) go through `markPresent()` — no raw-table bypass. The BUG-A1/A2 condition no longer exists.
2. **Past editions leak into catalogs** — ❌ CLAIM STALE / **FIXED.** `page-klassikaal.php:29` filters out editions whose `_ntdst_end_date` is >2 days past (start_date fallback), and reads through `getEffectiveStatus()`/`isPast()` (`EditionService.php:266,301`). The effective-status pattern is applied.
3. **Edition-status frontend CTA + pure-LD loop** — ❌ CLAIM STALE / **FIXED.** `sidebar-online.php` gates the CTA on `$primary_edition_status` (OfferingStatus) via `allowsInterest()`/`allowsWaitlist()`, and the pure-LD open/free branch (line 278) precedes the raw `$ld_buttons` branch — routing to a Stride-owned `$pureLdEnrollUrl`, closing the `/opleidingen/` loop (`bug_pureld_open_cta_loop` resolved).

> **Still genuinely open (not re-verified, lower priority):** Partner API `company_id` loss + attendance-hours-0 (`shake-out-partnerapi` BUG-P1/P2) — Partner API is post-launch, not a launch blocker. Theme: 7 footer 404s + 11 unregistered shortcodes (BUG-T1/T4) — verify separately.

**Lesson:** the three scary CRITICALs were ghosts of fixed bugs that STATE never recorded as closed. The overview problem wasn't unfinished work — it was *unrecorded completion*. Keeping FEATURE-STATUS current via `compounding` prevents this exact false alarm.

---

## The matrix

| Feature | Built | Detailed | Edge-tested | Weakest link |
|---|:---:|:---:|:---:|---|
| **Enrollment (individual)** | ✅ | ✅ | ✅ | Edge-driven 2026-06-10 (`EnrollmentEdgeCest`): empty-required-fields, double-submit + re-entry → 1 row, capacity-full refusal, colleague PII guard, voucher denials. |
| **Online / self-paced enrollment** | ✅ | ✅ | ⚠️ | `isEnrolled()` MODE_FREE gap fixed (`1f35717a`, 4 regression unit tests); edition-backed online courses now get a CTA (`d7911813`); 7 OnlineEnrollmentCest. Remaining: expired-access boundary is unit-covered, not browser-driven. |
| **Enrollment tasks / completion tasks** | ✅ | ✅ | ⚠️ | Flows 1–5 thoroughly shaken (15/15 fixed). Open: certificate strategy for in-person courses with required LD lessons (architectural, `shake-out-flow-4`). |
| **Trajectory / cascade enrollment** | ✅ | ⚠️ | ❌ | **The big one.** Cascade shipped + 60+ integration tests, but only 3 thin acceptance tests (no happy-path walk). Phased choices NOT started. Pure-LD electives skipped silently. `validateSelections()` has 4 field-shape bugs. **Most remaining work lives here.** |
| **Attendance** | ✅ | ✅ | ✅ | Edge-driven 2026-06-10 (`AttendanceCest`): mark-present → row, re-mark updates-not-duplicates, empty-state (no physical sessions), unauth-refused. Was zero-coverage. |
| **Invoicing / quotes** | ✅ | ✅ | ✅ | Quote-locking edge-driven 2026-06-10 (`DashboardQuoteGdprEdgeCest`): locked rejects billing edit, unlocked accepts. + 13 admin Cests. Minor cosmetic BTW breakdown (BUG-023) deferred. |
| **Vouchers** | ✅ | ✅ | ✅ | Denial states edge-driven 2026-06-10 (`EnrollmentEdgeCest`): unknown/expired/exhausted/wrong-scope all refused, valid passes, validation doesn't consume a use. Concurrent redemption transaction-locked (integration). |
| **User dashboard** | ✅ | ✅ | ✅ | Nav consistency edge-driven 2026-06-10 (`DashboardQuoteGdprEdgeCest`): identical nav-tab set across home/profiel/inschrijvingen (1B regression net). `bug_dashboard_nav_inconsistent` fixed `98094869`. |
| **Profile** | ✅ | ✅ | ⚠️ | 9 Cests covering personal/billing/notification sections + anon-denied. Missing: validation-error paths (bad email/VAT), concurrent edits. |
| **Admin edition management** | ✅ | ✅ | ⚠️ | 16 Cests (CRUD, sessions, notes, deelnemers modals, duplicate) + shaken clean. Happy-path; no edit-conflict / capacity-exceeded / concurrent-delete. |
| **Edition status (frontend display)** | ✅ | ✅ | ⚠️ | CTA now gated on OfferingStatus + pure-LD loop closed (verified 2026-06-08 — manifest stale). Edge: cancelled/closed/postponed display states not acceptance-driven. |
| **Catalog / public pages** | ✅ | ⚠️ | ⚠️ | Past-edition leak FIXED (verified — `page-klassikaal:29` date-filters). Remaining: 7 footer links 404, 11 spec'd shortcodes unregistered (BUG-T1/T4) — verify separately. |
| **Auth / login / registration** | ✅ | ✅ | ✅ | **Only feature that earns ✅ edge-tested.** 19 Cests: denial cases, rate-limiting increments, invalid-token errors, redirects. Gap: brute-force actually blocking not asserted. |
| **Audit / impersonation** | ✅ | ✅ | ⚠️ | Audit logging shaken clean (4/4). Impersonation bypass **fixed 2026-06-08**. But 9 Cests are smoke/DB-only — impersonation *flow* not driven through browser. |
| **Questionnaire / enrollment form** | ✅ | ✅ | ⚠️ | GDPR fields done (9 integ tests), shaken clean. 6 manual UX checks deferred (drag-reorder, Select2). Colleague-enrollment-optional toggle deferred. |
| **Partner API** | ✅ | ⚠️ | ❌ | Post-launch by design. **0 acceptance tests.** 7 shake-out bugs incl. company_id loss + attendance-hours-0 (BUG-P1–P4). Pagination unsanitized. |
| **Multi-brand / client scaffolds** | ✅ | ✅ | ⚠️ | Swap mechanic shipped + tested for launch. Drift: ~125-line duplicated loader across kindred/vad; carecommunity orphaned (no loader). |
| **GDPR / data lifecycle** | ✅ | ✅ | ✅ | Anonymise edge-driven 2026-06-10 (`DashboardQuoteGdprEdgeCest`): real users.php row action strips PII (national_id/email/marker), registration survives, UI-idempotent ("Geanonimiseerd op" label, no re-action). + 9 integ tests. |
| **Annual report (Jaarrapport)** | ✅ | ✅ | ⚠️ | Done 2026-05-16 (KPIs, PDF, CSV injection-safe), 5 integ + 6 unit. Not acceptance-driven. |
| **LTI** | ⚠️ | ❌ | ❌ | Incomplete by design — deactivate at deploy. 15+ commits, not v1-complete. |

---

## What this tells you (the overview, restored)

**Launch-blocking unknowns (verify first):** attendance events firing, past-edition catalog leak, edition-status frontend. All three are CRITICAL-flagged in manifests but absent from STATE/checklist — the cost of scattered overview.

**Where the real remaining work is, ranked:**
1. **Trajectory** — your own read, confirmed by evidence. Built but not detailed, barely acceptance-driven, phased-choices + pure-LD electives unbuilt. The single biggest cluster.
2. **Attendance** — built but possibly broken (events) and entirely un-acceptance-tested.
3. **Edge-testing across the board** — only Auth clears the strict bar. Every other feature is happy-path-driven. The "deep testing cycle" you're in *is* the work to move ⚠️→✅.

**Solid and trustworthy:** Auth, Invoicing, Vouchers, Edition admin, Audit, Annual report, GDPR — built, detailed, shaken clean. Edge-coverage is the only gap and it's known.

**Post-launch deferred (not gaps — decisions):** Partner API, phased choices, enrollment-form-on-course, timeline view, density modes, LTI, unified-API extraction. All have memory entries; none block launch.

---

## How to keep this current (so you don't lose the overview again)

This doc is the convergence point for "where are we." Don't reconstruct it by feel each time — **update it at spec-close via `netdust-core:compounding`** (Stage 3 step 6), alongside CODE-MAP. When a feature's flows get edge-driven, flip its column ⚠️→✅ *with the Cest/manifest as evidence*. The matrix is only trustworthy if every ✅ points at a real driven flow.
