# Active Sprint — todo

Working scratchpad. Authoritative launch list lives in `docs/LAUNCH-CHECKLIST.md`.

---

## Hardening sprint — ACTIVE (started 2026-06-10)

Plan: `~/.claude/plans/glowing-roaming-wozniak.md` (approved 2026-06-10). Goal: launch confidence — no blocking bugs, no security issues, loose ends closed.

- [x] **Phase 0 — Housekeeping & green baseline**: invariants bundle committed (`7f2ddce9`); stride-client-vad tracked in git — launch brand was NOT in version control (`bb1d052c`); kindred duplicate loader removed, VAD = single active brand (`7948e836`); debris deleted (.debug-enrolled2.php, repro-save-venue.php); stale worktree pruned; todo.md/checklist truth-up; suites green (see below)
- [ ] **Phase 1 — Verified-open bug fixes (TDD)**: (A) `isEnrolled()` MODE_FREE gap `LearnDashHelper.php:73-97`; (B) dashboard nav consistency `UserDashboardService.php:94-125`; (C) `TrajectorySelection::validateSelections()` 4 field-shape bugs `:218-223`; (D) error_log→ntdst_log (2 sites)
- [ ] **Phase 2 — Security hardening**: test-login-helper.php env-gate + secret rotation; audit deferred items H4 (PII redaction for stride_view), M3 (session cross-edition validation), M5 (upload whitelist), M6 (reserved meta-key guard), M1 (interest-merge guard), M4 (verify closed), L3 (export throttle); explicit capability checks in 16 wp_ajax handlers
- [ ] **Phase 3 — Targeted P0 edge-testing gate**: drive enrollment edges / attendance / completion→certificate / online states / dashboard empty states / quote locking / anonymise through real browser; new Cests as regression net; flip FEATURE-STATUS rows with evidence
- [ ] **Phase 4 — Deploy readiness**: deploy-time list + evidence-trail refresh. ⚠ NEW: `site.yml` declares `make deploy-staging` but **no Makefile exists** — deploy tooling must be created/verified before launch.

---

## Pre-existing test failure — investigate post-launch

`WPDataConnectorTest::canUpdateExistingPlatform` (Integration) fails on staging — **not** caused by the 2026-05-18 ntdst-core port from PR #2 (verified: same failure with both the old and new Data.php).

Symptom: platform name update doesn't stick — reload returns the original "Test Platform XXX" instead of "Updated Platform Name".

Likely lives in `web/app/plugins/netdust-lti/src/ToolProvider/PlatformRepository.php::update()` which maps `name → title` and calls `$model->update($id, ['title' => ...])`. Either the title write or the find-after-update is reading stale data.

Not blocking launch — LTI is not on the Phase 1 launch checklist.

---

## Sprint 1 — Admin Dashboard ✅ DONE (2026-05-13)

- Track 1 — all 23 bugs verified resolved (5 fixed, 18 already in code)
- Track 2 — neutral UX pass, user-detail rework, empty/loading/error states
- Commit `8a54c475`

## Phase 3 tail ✅ DONE (2026-05-13)

- Bulk lock/unlock from edition + customer-facing edit restriction
- Commit `01b9a346`

## §C — Voucher scope + apply-mode ✅ DONE (2026-05-14)

Supersedes the original 5-category plan. Cleaner, admin-tunable shape.
- 3-way `scope_mode` radio: alle/alleen/behalve
- `apply_mode` dropdown: volledige editie / één sessie (pro rata)
- `VoucherScopeValidator` + `VoucherProrater` helpers (NTDST DI)
- Plan: `plans/phase-4-voucher-scope-and-prorating.md`
- Commits `ae970344` + `95065b4f` + `4709fef3`
- Shake-out: 0/0/1 — 1 MINOR deferred (blank-title edition in picker)

## §D — Launch-module bugs ✅ DONE (2026-05-14)

The original "11 deferred bugs" framing turned out misleading after audit. Refresh:
- 7 already fixed in code (LD sync, cache clear, Withdrawn enum, cascade delete, etc.)
- 3 dropped from launch (DI debt, ProPanel notice, vague 11-shortcodes)
- 4 real items shipped: D-C2 deprecated `time()` calls, D-T1 6 footer pages,
  D-G GDPR bundle, D-Cap1 stale-pending dashboard widget
- Commits `5fa9ea92` `d85c7eba` `1f087cb9` `c3ca3d5f` + checklist syncs
- Audit notes: `tasks/d-audit-2026-05-14.md`

### §D-G — GDPR anonymisation bundle
- `UserLifecycleService::anonymise()` strips PII, keeps registrations intact
- Replaces "Verwijderen" with "Anonimiseer" row action; nuclear delete stays for admins
- `EditionRegistrationMetabox` renders anonymised users as faded rows
- `wp stride anonymise-orphans` CLI scans for orphan FKs
- 3 new user-meta fields wired via existing Questionnaire form builder:
  `national_id` (rijksregisternummer), `date_of_birth`, `professional_license_number`
- Systemfields help panel on Formuliervelden page + handleiding entry (commit `37ae2bae`)
- 9 new integration tests

## Pre-launch P0 sweep ✅ DONE (2026-05-14)

- Stale-DB-read sweep — 1 offender (AdminAPIController.php:1655 reading legacy
  stride_vad_trajectory_enrollments) replaced with canonical RegistrationRepository batch methods
- 3 new integration tests guard the contract
- Commits `0f47f48f` + `53a7a604`
- Memory entry: `gotcha_stale_database_reads.md`

## §D-Cap1 — Unified "Acties nodig" dashboard ✅ DONE (2026-05-14)

Merged 4 separate panels/concerns into one card with 3 tabs:
- **Wacht op mij** = admin approval (approval + post_approval merged — same UX bucket)
- **Wacht op gebruiker** = stale pendings ≥7d (per user reframe: no auto-cancel,
  capacity stays held, admin reviews per case)
- **Meldingen** = existing rule-driven action queue (capacity warnings, stale quotes)

Per-row primary action (Keur goed / Teken af / Bekijk editie) + secondary
"Gebruiker →" with smart "← Terug naar dashboard" return.
Action-queue links use #action-required-<bucket> hash to deep-link tabs.
Commits `a871033e` `15a6db00` `2ccebcbd`

## Drift scanner ✅ DONE (2026-05-14)

`scripts/audit-drift.sh` + `composer audit:drift` — catches the class of bug we
found this session (stale DB reads, duplicate hardcoded constants, legacy table refs).
Commit `37ae2bae`

## Theme: keuzecursus visibility ✅ DONE (2026-05-14)

Edition page now groups sessions: mandatory + per-slot ("Kies N uit M").
Visitors see the keuzecursus model before enrolling.
Commit `dfb1465f`

---

## Mail integration ✅ VERIFIED WORKING (2026-05-14)

The mail bridge is 655 LOC + 12 templates seeded + fluent-smtp delivering. End-to-end test confirmed:
- Enrollment fires user + admin notifications ✅
- Quote auto-created on enrollment fires customer mail ✅
- Smartcodes resolve: `{{edition.title}}`, `{{edition.start_date}}`, `{{edition.venue}}`, `{{user.first_name|klant}}` fallback, `{{completion.url}}`, `{{quote.number}}`
- Commit `a515d1f5` added `|klant` fallback to 7 user-facing templates so empty first_name never produces "Beste ,"

Earlier audit mistake corrected: I grep'd for `do_action('stride/` but missed `$this->dispatch('event/name')` which wraps it. All 11 expected events DO fire.

## Pre-launch cleanup ✅ DONE (2026-05-14, commit `aca392eb`)

- Moved stray PNGs to `screenshots/` with `.png` extensions
- `tests/_output/` added to `.gitignore`, 211 files (47MB) untracked

---

## Deferred polish (post-launch nice-to-haves)

- **M1 (voucher shake-out)** — edition pickers render blank entry for vad_edition #5088 (empty post_title). Pre-existing data quality issue. Cosmetic.
- **Density modes** (deferred per user 2026-05-14) — CSS compact mode for dashboard tables.
- **Multi-brand demo** (deferred per user 2026-05-14) — additional brand scaffolds created on-demand when needed for sales pitch. BWEEG + proven swap is enough.
- **Trajectory admin UI hiding** (deferred per user 2026-05-14) — can be done manually at deploy time.
- **Anonymise UX polish** — toast persistence, bulk anonymise UI
- **Enrollment form 'Voor wie' step — make optional per edition** (2026-05-18). Today step 0 (type picker) is always shown for long-form enrollment, even though everyone enrolls themselves. Add an edition-level setting (something like `_ntdst_allow_colleague_enrollment` bool) that controls whether the picker is shown. When false: skip step 0, default `form.enrollment_type='werknemer'`, drop `'Type'` from progress bar. The picker code already exists scoped to `currentStep===0`, so re-enabling is just toggling the include in `enrollment.php` + adding `0` back into the stepMap in `enrollment.js`. See commit `304a4e87` for the visible-selected-state fix that's already in place.

---

## Trajectory cascade + phased choices (started 2026-05-20, planning done)

**Two plans, sequential. Do cascade first — phased-choices needs it.**

### Plan 1: Cascade-enrollment ✅ DONE (2026-05-20, verified in code 2026-06-09)

All 15 steps shipped in `09c28ab9` (schema + repo queries) + `b712c8c6` (steps 4–15).
Verified against current code 2026-06-09: backfill CLI exists (`TrajectoryService` registers
`stride trajectory backfill-cascade`), PartnerAPI maps `edition_full → 409` + nests
`child_registrations`, `tests/manual/shake-cascade.php` exists, `TrajectoryCascadeCest` exists.
This checklist was stale — the code shipped the same day the plan was written.

Known follow-up (post-launch, lives in phased-choices plan): pure-LD electives without
edition_id are not selectable/cascadable (`memory/project_pure_ld_electives_gap`).

### Plan 2: Phased choices — `plans/2026-05-20-trajectory-phased-choices.md`

DO NOT START until cascade above is shipped + tested. Phased-choices' Risk #3 was YES; cascade resolves it.

Plan has its own 9-step execution order — see file.

---

## Deep-testing phase — STARTS HERE

Stride codebase is feature-complete. User is starting deep testing in the coming days.

### Pre-deep-testing audit findings (2026-05-14)

Full reports: `tasks/audit-2026-05-14-security.md` + `tasks/audit-2026-05-14-performance.md` (commit `5a3b4490`).

**All top fixes DONE & re-verified in current code 2026-06-09** (read-only review agents, file:line evidence):

- ✅ C3 colleague-PII overwrite — guard at `EnrollmentService.php:730-799` (existing colleagues never get `updateUserProfile()` with PII)
- ✅ C1 CSV injection — `sanitizeCsvCell()` at `AdminAPIController:3498-3507`
- ✅ H1 anonymisation gate — `stride_manage` checks at `UserLifecycleService:182,301`
- ✅ H2/H3 impersonation — caller≠target check + symmetric audit via `AuditService::record()` (`b91fbbdf`)
- ✅ Perf H1–H4 — async mail (`StrideMailBridge:85-86`), batched searchUsers/getUserDetail, taxonomy CAST (`AdminAPIController:1175`)

Deferred MEDIUM/LOW from the audit: launch-surface subset (H4, M1, M3, M5, M6, L3) being fixed in **hardening sprint Phase 2** (top of this file); the rest stays post-launch.

### Deploy-time tasks (NOT code changes)

- ⚠ **Create/verify deploy tooling** — `site.yml` says `deploy.method: makefile` + `make deploy-staging`, but no Makefile exists in the repo. Ploi git-pull or a deploy script must exist before anything can ship.
- Deactivate `netdust-lti` plugin in WP admin
- Configure production SMTP credentials in Fluent SMTP (currently routing to mailpit)
- Set `stride_admin_email` option to real admin inbox
- Recreate the 6 footer pages on staging + prod (currently dev-DB only — see commit `d85c7eba`)
- Trajectory admin UI stays visible for v1 (standing decision 2026-05-13)
- `web/app/mu-plugins/test-login-helper.php` is untracked (local-only) — after Phase-2 hardening it gets tracked; verify it's inert on staging/prod (`WP_ENV` guard + no env secret set)

### Post-launch backlog (NOT for v1)

- Task #21: drop dead `stride_vad_session_registrations` table + retire legacy `stride_vad_trajectory_enrollments`
- D.4 (P2): `EditionService::recomputeStatus()` + `wp stride recompute-edition-status` CLI
- 6 MEDIUM + 4 LOW security findings (see audit report)
- 5 MEDIUM perf findings (see audit report)
- All P2 polish items deferred during this sprint
