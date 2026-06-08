# Stride — Feature Status Matrix

**The overview doc.** One row per user-facing feature, three honest axes, graded against evidence (not vibes). This is the answer to "what's fully built, detailed, and edge-tested — and where's it thin?"

- **Built** = code exists, boots, core path works.
- **Detailed** = has the polish/edge-handling the feature *intends* (error states, empty states, status-awareness).
- **Edge-tested** = ✅ ONLY if intended flows + edge cases (empty / denied / concurrent / boundary / mid-flow failure) were actually **driven through real flows** (acceptance Cest, shake-out walk, or manual). Green unit tests alone ≠ ✅. **Most features land ⚠️ — that is the point; it's the map of where to work.**

Legend: ✅ solid · ⚠️ partial/known-thin · ❌ missing/broken · — n/a

_Last assessed: 2026-06-08. Evidence: 17 acceptance Cests (126 tests), 35 shake-out manifests, LAUNCH-CHECKLIST, gap memories, 913 unit + 261 integration green. Sources cross-checked by 3 independent readers._

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
| **Enrollment (individual)** | ✅ | ✅ | ⚠️ | Shaken out clean (10/10 fixed) + 19 Cests, but happy-path-only — no concurrent double-submit, capacity-full boundary, or validation-error flows driven. |
| **Online / self-paced enrollment** | ✅ | ⚠️ | ⚠️ | Shaken out clean, but `isEnrolled()` MODE_FREE gap (`bug_isenrolled_free_mode_gap`) + pure-LD CTA loop (`bug_pureld_open_cta_loop`) — both real post-launch bugs in shipped code. |
| **Enrollment tasks / completion tasks** | ✅ | ✅ | ⚠️ | Flows 1–5 thoroughly shaken (15/15 fixed). Open: certificate strategy for in-person courses with required LD lessons (architectural, `shake-out-flow-4`). |
| **Trajectory / cascade enrollment** | ✅ | ⚠️ | ❌ | **The big one.** Cascade shipped + 60+ integration tests, but only 3 thin acceptance tests (no happy-path walk). Phased choices NOT started. Pure-LD electives skipped silently. `validateSelections()` has 4 field-shape bugs. **Most remaining work lives here.** |
| **Attendance** | ✅ | ✅ | ❌ | Events fire + no bypass (verified 2026-06-08 — BUG-A1/A2 stale). Real gap is **edge-testing: zero acceptance coverage** — only a DB fixture in the E2E journey. No UX flow driven (mark/view/opt-in). |
| **Invoicing / quotes** | ✅ | ✅ | ⚠️ | Shaken clean, quote-locking done, async-PDF perf fixed. 13 admin Cests but happy-path (no proration math, concurrent redemption). Minor: quote slide-over missing BTW breakdown (BUG-023). |
| **Vouchers** | ✅ | ✅ | ⚠️ | Scope + per-session apply-mode done, shaken clean (1 cosmetic). Edge gaps: expiration/limit enforcement, concurrent redemption not driven. |
| **User dashboard** | ✅ | ⚠️ | ⚠️ | Admin dashboard: 23 bugs → 18 fixed, **~5 P0–P2 may remain open** (BUG-023 + nav flicker `bug_dashboard_nav_inconsistent`). Card patterns tested; empty/no-enrollment states not driven. |
| **Profile** | ✅ | ✅ | ⚠️ | 9 Cests covering personal/billing/notification sections + anon-denied. Missing: validation-error paths (bad email/VAT), concurrent edits. |
| **Admin edition management** | ✅ | ✅ | ⚠️ | 16 Cests (CRUD, sessions, notes, deelnemers modals, duplicate) + shaken clean. Happy-path; no edit-conflict / capacity-exceeded / concurrent-delete. |
| **Edition status (frontend display)** | ✅ | ✅ | ⚠️ | CTA now gated on OfferingStatus + pure-LD loop closed (verified 2026-06-08 — manifest stale). Edge: cancelled/closed/postponed display states not acceptance-driven. |
| **Catalog / public pages** | ✅ | ⚠️ | ⚠️ | Past-edition leak FIXED (verified — `page-klassikaal:29` date-filters). Remaining: 7 footer links 404, 11 spec'd shortcodes unregistered (BUG-T1/T4) — verify separately. |
| **Auth / login / registration** | ✅ | ✅ | ✅ | **Only feature that earns ✅ edge-tested.** 19 Cests: denial cases, rate-limiting increments, invalid-token errors, redirects. Gap: brute-force actually blocking not asserted. |
| **Audit / impersonation** | ✅ | ✅ | ⚠️ | Audit logging shaken clean (4/4). Impersonation bypass **fixed 2026-06-08**. But 9 Cests are smoke/DB-only — impersonation *flow* not driven through browser. |
| **Questionnaire / enrollment form** | ✅ | ✅ | ⚠️ | GDPR fields done (9 integ tests), shaken clean. 6 manual UX checks deferred (drag-reorder, Select2). Colleague-enrollment-optional toggle deferred. |
| **Partner API** | ✅ | ⚠️ | ❌ | Post-launch by design. **0 acceptance tests.** 7 shake-out bugs incl. company_id loss + attendance-hours-0 (BUG-P1–P4). Pagination unsanitized. |
| **Multi-brand / client scaffolds** | ✅ | ✅ | ⚠️ | Swap mechanic shipped + tested for launch. Drift: ~125-line duplicated loader across kindred/vad; carecommunity orphaned (no loader). |
| **GDPR / data lifecycle** | ✅ | ✅ | ⚠️ | Anonymise bundle done (9 integ tests), stale-pending dashboard card done. Anonymise flow not driven through browser. |
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
