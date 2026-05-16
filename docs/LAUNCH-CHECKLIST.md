# Stride — Launch Checklist

**Authoritative list of what must be true before Phase 1 production launch.**
**Companion to:** `memory/STATE.md` (current-state snapshot) and `tasks/todo.md` (active sprint scratchpad).

Last updated: 2026-05-14 (post audit phase — codebase feature-complete)

---

## 🎯 What's next (read this first)

**Stride codebase is feature-complete.** All P0 + P1 launch code items are shipped. Next phase: **deep testing**, starting in the coming days.

**Recommended fixes BEFORE deep testing begins** (otherwise testers re-discover them, wasting re-run cycles). Full details in `tasks/audit-2026-05-14-{security,performance}.md`:

| # | What | File | Effort |
|---|---|---|---|
| 1 | 🚨 Verify + fix C3 colleague-enrolment PII overwrite | `EnrollmentService.php:591-657` | medium |
| 2 | 🚨 Fix C1 CSV injection in admin export | `AdminAPIController.php:3040-3074` | 5 min |
| 3 | 🚨 Fix H1 perf — drop eager PDF render + async-ify mail in enrollment | `QuoteService.php:408` + `StrideMailBridge.php:77` | 30 min |
| 4 | ⚠ Fix H1 sec — anonymisation gate needs `stride_manage` check | `UserLifecycleService.php:282-303` | 15 min |
| 5 | ⚠ Fix H3 sec — impersonation audit writes wrong columns | `AdminAPIController.php:2865-2878` | 10 min |
| 6 | ⚠ Fix H2/H3 perf — batch `searchUsers` + `getUserDetail` quotes | `AdminAPIController.php:2367, 2520` | 1 hr |
| 7 | ⚠ Fix H4 perf — one-line CAST in taxonomy join | `AdminAPIController.php:1123-1156` | 5 min |

**Deploy-time tasks (NOT code, do at staging/prod push):**
- Deactivate `netdust-lti` plugin in WP admin
- Configure real SMTP credentials in Fluent SMTP (currently routes to mailpit)
- Set `stride_admin_email` option to real VAD admin inbox
- Recreate 6 footer pages on staging/prod (see commit `d85c7eba`)
- Decide whether to hide Trajectory admin UI for v1

---

## Goals

1. **Production-ready** — ship Phase 1: e-learning, blended learning, enrollment flow, enrollment tasks, completion tasks, attendance, invoicing, user dashboard.
2. **Multi-brand demo** — 2–3 distinct brand scaffolds (BWEEG + 1–2 more) + a swap demo for sales.

**Explicitly out of scope (post-launch):** Trajectories, Partner API, LTI.

---

## Status Legend

- `[ ]` open / not started
- `[~]` in progress
- `[x]` done
- `(P0)` blocks launch
- `(P1)` should fix before launch
- `(P2)` nice-to-have

---

## A. Admin Dashboard — DONE for launch (P0)

**Source:** `tasks/shake-out-dashboard-manifest.md` (re-swept 2026-05-13, all bugs resolved 2026-05-13)
**Why P0:** Admin can't operate the platform without this. Largest single blocker.

**Status (2026-05-14):**
- **A.1 — Functional bugs** — ✅ ALL 23 fixed (2026-05-13, `8a54c475`).
- **A.2 — Visual & UX repair** — ✅ All launch items shipped (`8a54c475`). Enrollment-timeline view deferred post-launch (user decision 2026-05-13). Density modes P1 — nice-to-have, not blocking.

### A.1 — Real bugs to fix (5 verified open)

- [x] BUG-007 (P0) — Settings notification thresholds don't persist after save. **FIXED 2026-05-13** — extracted `ntdstAPI` to shared mu-plugin asset; was a broken nonce fallback that swallowed errors as "saved".
- [x] BUG-009 (P0) — Quote totals stored in cents but API/dashboard treat as euros. **FIXED 2026-05-13** — `AdminAPIController` converts via `Money::cents()->amount()` at API edge; OFF-2026-0161 now renders €272,25.
- [x] BUG-021 (P1) — Activity feed shows raw event strings instead of human Dutch. **FIXED 2026-05-13** — extended `AdminActivityMapper` with all missing event cases + controller resolves `entity_id` to target user name. 11 new unit tests.
- [x] BUG-022 (P2) — Course tag filter dropdown empty. **FIXED 2026-05-13** — replaced single `course_tag` with three filters (theme/format/tag). Theme=17, format=6, tag=0 in BWEEG; tag dropdown auto-hides when empty.
- [x] BUG-023 (P2) — Quote slide-over BTW/subtotal breakdown. **VERIFIED 2026-05-13** — Subtotaal/BTW/Totaal already in template (dashboard.php:535-541). Renders Subtotaal €225,00, BTW €47,25, Totaal €272,25 on OFF-2026-0161.

### A.1b — Verify (verified resolved 2026-05-13)
- [x] BUG-019 (P2) — "Alles bekijken" / "Meer bekijken" dead links. **VERIFIED** — `@click.prevent` handlers work; click test confirmed view switch.
- [x] BUG-020 (P2) — "Bewerk in WP" `post=undefined` in hidden slide-overs. **FIXED 2026-05-13** — replaced inline concat with `selectedX?.editUrl || '#'` for edition/quote/trajectory + conditional href for user slide-over. 0 undefined anchors in DOM.

### Resolved since 2026-03-25 (no action needed — kept for evidence)

18 bugs already fixed in code:
- Cluster A (8): BUG-001, 005, 006, 008, 010, 011, 012, 013 — JS field bridging done
- Cluster C (1): BUG-002 slide-over positioning — fixed overlay confirmed
- Cluster E (2): BUG-015 Geannuleerd in filter, BUG-016 draft value alignment
- BUG-004 trajectory duplicates, BUG-014/017 trajectory labels (RESOLVED but trajectory UI to be hidden anyway), BUG-018 impersonation button label

> **Trajectory bugs (BUG-003, BUG-004, BUG-014, BUG-017):** Trajectory UI **stays visible for v1** (user decision 2026-05-13). BUG-004/014/017 already resolved. BUG-003 (trajectory detail 404 route) still needs fix — re-add to A.1 if reproducible.

### A.2 — Visual & UX repair (P0) — all launch items done

**Status:** 6 of 9 items shipped 2026-05-13 in commit `8a54c475` ("sprint 1 + track 2 — all 23 bugs + neutral UX pass"). Enrollment-timeline view deferred post-launch (user decision 2026-05-13). 1 P1 item still open (density modes) — nice-to-have, not a launch blocker.

**Goal:** dashboard should feel stable, professional, fast. Designed for daily admin tasks — speed up routine work, find things fast, understand what happened and why.

**Real use cases driving the redesign:**
- "Someone calls in — they subscribed, now they can't find the invoice. They're sure they paid." → admin needs to find a user, see their enrollments + quotes + payment events in one place, fast.
- "Admin is going through enrollments and sees one with missing data. What happened?" → admin needs to see the chronological story of a single enrollment: when created, what changed, who changed it, why.

**Repair items (NOT a full redesign — controlled visual + UX pass):**

- [x] (P0) **Color system** — dropped "Soft Violet"; neutral slate base + single blue accent; all hardcoded violet hex removed. **DONE 2026-05-13** (`8a54c475`).
- [x] (P0) **Layout stability** — buttons normalized (32px height, unified padding via tokens), inputs/selects matched, KPI row CSS grid (5→3→1), card padding tightened, table headers normal-case with subtle alt bg. **DONE 2026-05-13** (`8a54c475`).
- [x] (P0) **Slide-over redesign** — fixed missing `sd-slideout__tab` class on three slide-overs; positioning + content audit complete. **DONE 2026-05-13** (`8a54c475`).
- [x] (P0) **User detail view = the "call center" view** — Inschrijvingen / Aanwezigheid / Offertes tables, per-registration attendance summary, per-quote `sent_at`+`paid_at`, batch queries (no N+1). **DONE 2026-05-13** (`8a54c475`).
- [~] (Deferred — user decision 2026-05-13) **Enrollment detail = the "what happened" view** — timeline view of a single enrollment (created, task X done, missing field Y, status changes). Dropped from v1 scope; revisit post-launch.
- [x] (P0) **Activity feed redesign** — `AdminActivityMapper` extended with all event cases; controller batch-resolves `entity_id` → display name; graceful "(account niet meer beschikbaar)" fallback for deleted users; 11 new unit tests. **DONE 2026-05-13** (`8a54c475`, also resolves BUG-021).
- [x] (P1) **Empty states** — `.sd-empty` pattern (circular icon + title + hint) applied across lists/tables. **DONE 2026-05-13** (`8a54c475`).
- [x] (P1) **Loading / error states** — `.sd-skeleton` with pulse animation in KPI + tables; `.sd-error` blocks with retry buttons; stats default `null` so no flash-of-zero. **DONE 2026-05-13** (`8a54c475`).
- [~] (Deferred — user decision 2026-05-14) **Density modes** — comfortable default works fine for launch. Optional compact mode can be added post-launch if power users ask.

**Constraints:**
- No new dashboard framework. Existing Alpine.js + template structure stays.
- No new API endpoints unless absolutely required (e.g. the timeline view might need one aggregator endpoint).
- Tokens.css + dashboard.css + dashboard.php are the main edit surface.
- Test on real seeded data, not empty DB.

---

## B. Phase 3 Tail — Quote Locking (P0)

**Source:** `plans/finish-phase-3.md` (simplified per 2026-05-13 decisions)
**Why P0:** Stop customers editing billing details when admin has decided the quote is final.

- [x] Bulk lock/unlock — single toggle button on the edition sidebar; reads current state and inverts. **DONE 2026-05-13** — `QuoteService::bulkSetLockedByEdition()` + AJAX endpoint + `EditionActionsMetabox` UI. Verified on edition 13190.
- [x] Per-quote lock stays as admin escape hatch (already existed before).
- [x] Billing edit restriction — `validateQuoteAccess()` rejects updates/voucher with `WP_Error('locked', …)` when `locked=true`. **DONE 2026-05-13.**
- [x] Tests: 9 new integration tests across bulk-lock + lock-rejection scenarios. Full unit (674) + integration (221) suites pass.

**Out of scope for v1 (post-launch):**
- ~~Auto-lock cron at T-14d~~ — admin-driven instead. Admin decides when to lock the edition's quotes; no cron (decision 2026-05-13).
- ~~OGM payment reference generator~~ — Stride only creates quotes, not invoices. OGM belongs on the actual invoice, generated by Exact Online (decision 2026-05-13).

---

## C. Phase 4 — Voucher Scope + Per-Session Apply Mode (P0) — DONE

**Source:** `plans/phase-4-voucher-scope-and-prorating.md` (supersedes `plans/phase-4-voucher-completion.md`, 2026-05-14 decision)
**Why P0:** Voucher infra is generic; VAD needs (a) the ability to *exclude* certain editions from a voucher (instead of only restricting to one) and (b) prorating for multi-session editions when a voucher should only cover one session. Original 5-category plan dropped — adds density without solving real admin problems.

- [x] **Bidirectional scope** — `scope_mode` radio: *Alle / Alleen / Behalve*. Replaces single `edition_id` dropdown. Existing vouchers (no `scope_mode`) auto-detected as "alleen" via legacy back-compat. **DONE 2026-05-14** (`ae970344`).
- [x] **`apply_mode` dropdown** — *Volledige editie / Eén sessie (pro rata)*. When "Eén sessie" + multi-session edition: subtotal divided by session_count before discount applied. 0-session editions silently fall back to full. **DONE 2026-05-14** (`ae970344`).
- [x] **`VoucherScopeValidator` helper** — pure class, no hooks. Resolves legacy + handles `only`/`except` branching. **DONE 2026-05-14** (`ae970344`).
- [x] **`VoucherProrater` helper** — pure math `Money::cents(subtotal / max(N, 1))`. **DONE 2026-05-14** (`ae970344`).
- [x] **`VoucherService::validateVoucher()`** — delegates to scope validator (1 line). Keeps existing `wrong_edition` error code. **DONE 2026-05-14** (`ae970344`).
- [x] **`VoucherService::calculateDiscount()`** — optional `?int $editionId` parameter; prorates subtotal when `apply_mode='single_session'`. Backwards-compatible default `null`. **DONE 2026-05-14** (`ae970344`).
- [x] **Admin form** — 3-way scope radio with show/hide UI, multi-select for "Behalve", apply-mode dropdown. Vanilla JS toggle (no Alpine import). **DONE 2026-05-14** (`ae970344`).
- [x] **Admin list column** — shows "Alleen: X" / "Behalve: A, B +N meer" / "Alle edities" instead of single edition link. **DONE 2026-05-14** (`ae970344`).
- [x] **Tests:** 6 new integration tests (excluded-edition rejection, non-excluded acceptance, legacy back-compat, prorate Full, prorate Percentage, 0-session fallback). 26 voucher integration tests + 674 unit + 227 integration all green. **DONE 2026-05-14** (`ae970344`).

**Dropped from original plan (no longer in scope):**
- ~~5 voucher categories (member/action/speaker/day/social)~~ — admin uses scope + discount type to express same policies without baking categories into code
- ~~Edition `is_multi_year_training` field~~ — admin adds tweejarige editions to "Behalve" list on member vouchers
- ~~`VoucherTypeValidator` category dispatcher~~ — replaced by scope validator (narrower, simpler)
- ~~Social voucher hardcoded 50%~~ — admin sets `discount_type=Percentage value=50`

**Still deferred to Phase 8 (post-launch):** member voucher auto-generation, voucher reversal on cancellation, annual renewal cron.

**Shake-out 2026-05-14:** 0 CRITICAL, 0 IMPORTANT, 1 MINOR (`tasks/shake-out-voucher-manifest.md`). M1 — blank-title edition appears in pickers — deferred to post-launch polish (logged in `tasks/todo.md`).

---

## D. Deferred Bugs in Launch Modules — Refreshed 2026-05-14

**Refresh source:** `tasks/d-audit-2026-05-14.md`

The original framing was "11 deferred bugs." Code-level audit on 2026-05-14 shows **7 were already fixed** in code since the shake-out manifests were written (Feb–Mar 2026); 3 should be dropped from launch scope; **4 real launch items remain**.

### D.1 — Resolved since shake-out (no action)

These 7 from the original lists were verified fixed in current code:

- [x] (P0) LearnDash `course_completed` sync — `EditionCompletion.php:154-155, 184-185` call `learndash_process_mark_complete()`
- [x] (P1) Cache cleared on completion task update — `RegistrationRepository.php:619` calls `clearCache()` after `updateCompletionTasks()`
- [x] (P0) `Withdrawn` enum aligned — `RegistrationStatus.php:18` + DB enum match (`'withdrawn'` / `'Uitgetrokken'`)
- [x] (P0) Cascade delete on edition/session delete — `EditionService.php:301-341` cascades to sessions + registrations + attendance
- [x] (P1) Orphan `session_registrations` rows — N/A, table doesn't exist in DB; zero code refs
- [x] (P1) Attendance count semantic inconsistency — both `countAttended()` and `getPresentUserIds()` use `AttendanceStatus::attendedValues()`

### D.2 — Dropped from launch scope (deferred or non-actionable)

- ~~(P1) Completion module DI coupling refactor~~ — architectural debt, not a bug; matches NTDST thin-handler pattern. Drop.
- ~~(P1) LearnDash ProPanel script notice~~ — zero ProPanel refs in stride theme; if it appears it's plugin-side and not in our code surface. Drop until reproduced in an env we control.
- ~~(P1) 11 shortcodes not yet implemented~~ — list was never enumerated; vague. Drop and re-file as a specific spec if/when needed.

### D.3 — Real launch items (4)

- [x] (P1) **Deprecated `current_time('timestamp')` calls** — 3 calls (UserDashboardService.php:728-729 + notification-item.php:51) replaced with `time()`. **DONE 2026-05-14** (`5fa9ea92`).
- [x] (P0) **6 footer pages return 404** — 5 placeholder pages created with Dutch H1 + short body (`/agenda/`, `/contact/`, `/faq/`, `/over-ons/`, `/voorwaarden/`); existing `/privacy-policy` draft re-slugged to `/privacy/` and published. All 6 footer URLs now return 200. **DONE 2026-05-14** (dev DB).
    - **⚠️ Staging/prod follow-up:** content lives in WP DB, not git. Replay via `wp post create` on staging + prod before launch, or copy via DB migration. Stub copy can be edited in WP admin before going live.
- [x] (P0) **GDPR anonymisation bundle** — D-G1 + D-G2 + D-G3. **DONE 2026-05-14** (`1f087cb9`).
    - **D-G1** ✅ `UserLifecycleService::anonymise($userId)` — strips wp_users core + 24 user-meta keys (the 13-key mapping + 11 identity/preference keys). Keeps the wp_users row + foreign keys intact. Admin row action replaced with "Anonimiseer"; nuclear `wp_delete_user()` stays available for spam accounts. `delete_user` hook audits hard-deletes (no block).
    - **D-G2** ✅ `EditionRegistrationMetabox` renders anonymised users as faded rows with "Geanonimiseerd op YYYY-MM-DD" subtitle. Also handles hard-deleted orphans ("Gebruiker #N (verwijderd)"). No actions on either.
    - **D-G3** ✅ `wp stride anonymise-orphans` scans both active FK tables, dry-run by default, `--commit` flags rows. Dev DB sweep: 190 registration + 55 attendance rows referencing 200 deleted users.
    - Bonus: 3 new user-meta fields (`national_id` = rijksregisternummer, `date_of_birth`, `professional_license_number`) wired through the existing 4-stage Questionnaire form builder. Admins add them per-edition + mark required-or-not. No new mechanism invented.
    - Bonus: `EnrollmentService::getUserMetaMapping()` is now a single source of truth — `QuestionnaireSettingsPage::getUserMetaFieldNames()` delegates to it. Eliminates the duplicate-array drift hazard.
    - 9 new integration tests + end-to-end shake-out (form save → anonymise → metabox render → roll back) all passed.
- [x] (P1) **Pending registrations hold capacity indefinitely** — Decision 2026-05-14: don't auto-cancel; surface to admin for per-case review. Stale pendings (≥7d idle, user tasks open) now appear in a new "Inschrijvingen — actie vereist" dashboard card alongside admin approvals + post-course aftekening, with sub-tabs and counts. **DONE 2026-05-14** (`c3ca3d5f`). Capacity stays held until admin acts; full visibility prevents abandoned slots going unnoticed.

### D.4 — Volzet edge case (P2, defer)

- [ ] (P2) **`EditionService::recomputeStatus()` missing** — capacity edit or seed/import doesn't fire `stride/registration/created`, so status stays stale. Add method + `wp stride recompute-edition-status` CLI. ~30 LOC. Lower priority than D.3 items.

**Why this exists at all:** the original §D was last refreshed before the Sprint 1 / Phase 3 work, which incidentally cleaned up many of the listed module bugs. The 4 real items survived because no commit explicitly targeted them.

---

## E. Untested Components (P0–P1)

- [x] (P1) Admin Dashboard shake-out — covered by section A (Sprint 1 + Track 2 done 2026-05-13)
- [~] (P2) Trajectory module shake-out — *deferred (post-launch feature)*

---

## F. Design System — Multi-Brand Demo (P1) — DONE for launch

**Goal:** flip a switch and show the same platform looking radically different. Sales demo material.

**Status (2026-05-14):** brand-switching mechanism tested by user — works. BWEEG scaffold + the proven swap capability satisfy the launch requirement. Additional brand scaffolds for sales pitches will be created on-demand when needed.

**Foundation (DONE):**
- [x] Client customization system via mu-plugin pattern
- [x] `stridence_template_part()` + `stridence_template_path` filter
- [x] 40+ CSS tokens in `src/css/tokens.css`
- [x] Reference scaffold: `web/app/mu-plugins/stride-client-example/`
- [x] Editorial rebrand (design shell) — `docs/plans/2026-03-26-editorial-rebrand-design.md`
- [x] BWEEG demo (1st brand) — `docs/superpowers/specs/2026-03-27-bweeg-homepage-content-design.md`

**Open:**
- [~] (Deferred — user decision 2026-05-14) Brand scaffold #2 + #3 + swap mechanic doc + sales screenshots. Brand-switching mechanism tested + works; additional brand scaffolds for the sales demo can be created when actually needed for a pitch. The BWEEG scaffold + the proven swap capability are sufficient evidence for launch.

**Note:** Brand scaffolds are mu-plugin scaffolds. No core changes needed — the swap capability is already proven by BWEEG.

---

## G. Design Drafts to Decide (P2) — audited 2026-05-14

- [x] **session-price-modifiers** — **already shipped**. `price_modifier` field on `vad_session` CPT, admin UI in `EditionSessionsMetabox` + `EditionAdminController`, applied as quote line items by `QuoteService:268-295`. Tests: `tests/Unit/QuoteServiceModifierTest.php` + `tests/frontend/admin/session-price-modifiers.spec.ts`. Checklist drift only.
- [x] **stride-mail-integration** — **already shipped**. Re-verified 2026-05-14: 655 LOC `StrideMailBridge` registers all smartcodes + triggers + conditional dispatch. 12 Stride templates seeded (option `stride_mail_templates_seeded = 2`). 281 emails in mailpit confirm the system fires during normal use. Test send via `ndmail_send('stride-enrollment-created-user', ...)` returns true + arrives. Earlier audit conclusion was wrong because `get_posts(['post_type' => 'ndmail_template'])` was missing `post_status => 'any'`. Real remaining work tracked separately below.
- [x] **roles-capabilities** — **already shipped**. `stride_coordinator` + `stride_supervisor` + `partner` roles all registered with the designed caps. Spec at `docs/superpowers/specs/2026-03-18-roles-capabilities-design.md` (note: path differs from checklist).

---

## H. Planned / Vision Work (Post-Launch)

Tracked but NOT in Phase 1 scope. Keep in memory, surface after launch.

- Assistant CSV/Excel/DOCX exports — `memory/project_assistant_exports.md`
- Assistant evolution: headless mode, WP-CLI, event-triggered AI — `memory/project_assistant_vision.md`
- Trajectory module — never shake-out tested
- Partner API — 5 deferred bugs, foundational design done. Also: **extract into own plugin** as the pathfinder for the capability-plugin architecture below.
- LTI plugin — in progress on staging branch, not for launch
- Phase 8 voucher automations — auto-generation, renewal cron, reversal on cancellation
- **Capability-plugin architecture + Conference (headless) plugin** — split outward-facing capabilities out of `stride-core`. Two pieces: (1) extract Partner API to its own plugin (~2 days, validates pattern), (2) new `stride-conference` plugin exposing public `stride/v1/public/*` API + structured editorial content (speakers, sponsors, hero, FAQ) for conference frontends like `studiedag.stride.be` (~1-2 weeks). Design: `docs/superpowers/specs/2026-05-16-post-launch-capability-plugins-design.md`.

---

## Pre-Launch Cleanup

- [x] (P0) **Stale-database-read sweep** — Swept every `\$wpdb` usage on `vad_*` tables across stride-core + stridence. 7 hits total, only 1 was stale: `AdminAPIController.php:1655` (trajectory dashboard counts). Now swapped to canonical `RegistrationRepository::countByTrajectoryIds()` + `findByTrajectoryIds()`. 3 new integration tests guard the contract. **DONE 2026-05-14** (`0f47f48f`). Legacy tables themselves retire post-launch (task #21). Memory: `gotcha_stale_database_reads.md`.
- [x] **LTI WIP** — clarified 2026-05-14: all LTI work is already committed to `staging` (15+ feat commits, nothing uncommitted). Real concern is feature-incomplete — needs to be deactivated for v1 deploy.
- [ ] **Deactivate LTI plugin for v1 deploy** — `netdust-lti` plugin is feature-incomplete. User will handle plugin deactivation manually at deploy time. Reactivate when LTI work is finished post-launch.
- [x] **Move stray PNGs** — moved `bento-section`, `debug-outlines`, `stridelms-fullpage` to `screenshots/` with `.png` extensions. **DONE 2026-05-14** (`aca392eb`).
- [x] **`tests/_output/` ignored + untracked** — added to `.gitignore`, 211 existing files (47MB) removed from the index via `git rm --cached`. **DONE 2026-05-14** (`aca392eb`).
- [x] (P1) **Mail smartcode quality audit** — verified 2026-05-14 (`a515d1f5`). End-to-end test confirmed all smartcodes resolve: `{{edition.title}}`, `{{edition.start_date}}`, `{{edition.venue}}`, `{{completion.url}}`, `{{quote.number}}`. Added `{{user.first_name|klant}}` fallback to 7 user-facing templates so empty first_name renders "Beste klant," instead of "Beste ,". Earlier audit miss: I grep'd `do_action('stride/` directly and missed `$this->dispatch()` wrapper — all 11 expected events DO fire.
- [ ] (P0) **Configure production SMTP** — fluent-smtp is currently routing to mailpit (DDEV's dev capture). Production needs real credentials for VAD's mail provider. Done at deploy time, not in code.
- [ ] (P1) **Set `stride_admin_email` for prod** — currently falls back to `admin@stride.local`. Set to the actual VAD admin inbox via WP admin or `wp option update`.
- [~] **Hide Trajectory admin UI for v1** — Trajectory is unfinished. Deferred per user decision 2026-05-14 — can be hidden manually at deploy time or left visible (admin-only). Partner API does NOT have an admin UI; only REST endpoints + role. Nothing to hide there.

---

## How to Use This File

- **One source of truth** for "what's left before launch." Update it as scope changes, not as a journal.
- For **active sprint work**, use `tasks/todo.md` (small, ephemeral).
- For **current project state** (decisions, risks, recent context), see `memory/STATE.md`.
- For **deep design docs**, see `docs/plans/` and `docs/superpowers/specs/`.
- For **per-module bug history**, see `tasks/shake-out-*.md`.

When an item is done, mark `[x]` and link the PR/commit in a one-line note. Don't delete completed items — they become the launch evidence trail.
