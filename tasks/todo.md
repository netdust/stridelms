# Active Sprint — todo

Working scratchpad. Authoritative launch list lives in `docs/LAUNCH-CHECKLIST.md`.

---

## Hardening sprint — ACTIVE (started 2026-06-10)

Plan: `~/.claude/plans/glowing-roaming-wozniak.md` (approved 2026-06-10). Goal: launch confidence — no blocking bugs, no security issues, loose ends closed.

- [x] **Phase 0 — Housekeeping & green baseline** (2026-06-10): invariants bundle committed (`7f2ddce9`); stride-client-vad tracked in git — launch brand was NOT in version control (`bb1d052c`); kindred duplicate loader removed, VAD = single active brand (`7948e836`); debris deleted; stale worktree pruned; stale v3 `upload_path` option removed from dev DB (broke all uploads + 3 integration tests); **acceptance suite repaired** — was structurally unrunnable (prefix/URLs/admin-login/`$I->fail()`), 23/108 → 108/108 (`eb931fd1`, `d7911813`); voucher TZ deflake + third-party-warning scoping (`9edbbcc4`)
- [x] **Phase 1 — Verified-open bug fixes (TDD)** (2026-06-10): (A) `isEnrolled()` MODE_FREE gap — already fixed `1f35717a` 2026-05-20, 4 regression tests added; (B) dashboard nav single-source (`98094869`); (C) `validateSelections()` 4 field-shape bugs — rejected every elective selection (`e8561043`); (D) error_log→ntdst_log + INV-6 write bypass closed; **NEW bug found+fixed: edition-backed online courses rendered NO enrollment CTA** (`d7911813`)
- [x] **Phase 2 — Security hardening** (2026-06-10): test-login-helper WP_ENV gate + env-only secret + HMAC (`1304ed0a`); re-verification found H4/M1/M3/M4/M5/M6 already fixed in code (audit report status updated); wp_ajax controllers all do nonce + explicit capability; still-open = M2 + C2/L2 (post-launch modules, documented)
- [x] **Phase 3 — Targeted P0 edge-testing gate** (2026-06-10): 13 new edge tests — `EnrollmentEdgeCest` (6: empty fields, double-submit, capacity-full, colleague PII guard, voucher denials), `AttendanceCest` (4: mark/re-mark/empty-state/auth — was zero-coverage), `DashboardQuoteGdprEdgeCest` (3: nav consistency, quote lock, anonymise). Matrix+manifest `docs/architecture/acceptance-flows/p0-hardening-phase3.md`. 6 FEATURE-STATUS rows flipped to ✅. Also deflaked 2 pre-existing tests (canAddSession seed-accretion, registration rate-limit retry). **Suites: 924 unit + 369 integration + 121 acceptance green.** F5 (cert) + F6 (expired-access) left unit/integration-covered per targeted scope.
- [ ] **Phase 4 — Deploy readiness**: deploy-time list + final evidence refresh. ⚠ `site.yml` declares `make deploy-staging` but **no Makefile exists** — deploy tooling must be created/verified before launch. Production .env must NOT set STRIDE_TEST_LOGIN_SECRET. Standing list: deactivate netdust-lti, real SMTP, set stride_admin_email, replay 6 footer pages on staging/prod.
  - 2026-06-10 crash note: phase 4 was 1 minute in when the machine restarted. Pending decision it was about to ask: deploy method should likely be **git-push** (Ploi auto-deploy webhook runs `composer install --no-dev` on push) — the `makefile` method in site.yml is aspirational. **Stefan: Makefile/deploy-method decision parked, solve later.** Don't block other work on it.

---

## ✅ DONE — Audit remediation sprint (executed 2026-06-10, ~75 commits on staging)

**Executed via harnessed-development**: planner Class-B freshness review → plan `tasks/plans/2026-06-10-audit-remediation-plan.md` (threat model M1–M9 + invariants + AF-1..5 matrix) → 7 review clusters (A–G) each with a tiered gate (3× STANDARD, 4× FULL incl. security-sentinel + drift) → spec-close `/shakeout` (test-effectiveness manifest + AF-1..5 driven through real browser/wire + 6-persona FULL branch panel: **0 blockers**, all SHOULD-FIX remediated).

**Closed:** M0 entire (CI now runs 974 unit + 458 integration in GitHub Actions, seam-proven RED→GREEN; PHPStan L1 baselined; INV-5 grep blind spot), M1 minus parked 1.2 (dead columns H-1, partner ENUM M-4, PII-reveal audit row M-1, VAT consolidation H-5 + new blocking INV-8, date-helper move H-6 + INV-5 blocking, proof protection M-2 incl. nginx deny + private attachments), perf-critical M2 (dashboard memoization CR-2, bounded approvals H-8, batched export, audit-table generated column + badge cache H-4 — index-backed on 82k rows, catalog batch hydration CR-3 — 168→11 queries, Redis prep-only H-3 per Q5), riders 3.5 + 3.7. **Suites at close: 974 unit / 458 integration (1 pre-existing skip) / 128 acceptance — all green; invariants script exit 0.**

**Notable finds during execution:** staging DB is MySQL-family → the audit-table migration was made flavor-portable before it could brick a deploy; quote paths proven to AGREE (no live financial bug); the catalog endpoint shipped guest-broken once and was caught + wire-fixed at the G gate; protected proofs were still REST-enumerable until the final panel caught it (now `private`).

Original handoff (kept for reference):

**Source of truth: `docs/architecture/AUDIT-2026-06-10.md`** (grade B−; 3 Critical / 8 High / 12 Medium / 9 Low; contains the full milestone task plan with acceptance criteria, effort, and a `Deps` column). Entry point: `harnessed-development` → planner as a Class-B freshness review of that doc — reconcile against current source first; the audit ran concurrently with hardening Phase 3, so a few statuses predate it.

**Scope ruling (Stefan, 2026-06-10, ship-mode):**
- **In scope:** Milestone 0 (CI safety net — do FIRST, it protects everything else), Milestone 1 (launch blockers), and the perf-critical core of Milestone 2 (CR-2 dashboard, CR-3 catalog, H-3 object cache, H-4 audit-log badge) — read-path collapse at 4,000 users is launch-relevant.
- **Post-launch:** Milestone 3 (polish), AdminAPIController decomposition, Partner-API rate limiting, module cycles — all per the audit's own "Explicitly NOT fixing now".
- **Parked by Stefan:** task 1.2 (Makefile / deploy method) — he'll solve it himself. Skip it; task 2.3 (Redis) depends on 1.2 only for ops access, not code — prep the drop-in, defer enablement.

**Execute in the audit's dependency order** (Deps column is authoritative): 0.1→0.5 before 1.1; 0.5 before 1.1; 1.1 before 2.6. Quick-wins batch (audit §Quick wins, ~1 day): 0.1, 1.1, 1.3, 1.4, 1.6, 3.5, 3.7.

**Already resolved since the audit was written — do not re-do:**
- Open question 6 (stale memory entries re impersonation bypass) — corrected during hardening Phase 2.
- Acceptance-suite prefix mismatch — fixed (`eb931fd1`); suites green 924 unit + 369 integration + 121 acceptance (2026-06-10).
- H-2 is the parked Makefile item above.

**Open questions needing Stefan before the dependent task (audit §Open Questions):**
- Q2: what do users upload as completion proof? → decides if 1.7 (protect uploads) is launch-blocking or M3.
- Q3: perf budget — audit's ≤40 queries catalog / ≤60 dashboard are inferred targets; confirm or adjust.
- Q4: git history rewrite go/no-go for the 90 MB purge (3.1) — untrack now, rewrite later is the safe default.
- Q5: is Redis available on the Ploi production plan? → shapes H-3 mitigation.

---

## Follow-ups from the audit-remediation close-out (2026-06-10, panel review)

Consolidated from the six-persona branch review. None block the branch; each has a named owner-context.

1. **Cross-project sync — ntdst-core nosniff + Mailer & ntdst-audit v1.1.0**: propagate the universal file-response nosniff posture and the Mailer changes in ntdst-core, plus ntdst-audit v1.1.0 (schema v2, subject_user_id), to the canonical framework copy / Rossi reference. Fleet-level, do at next framework sync.
2. **Registrations UNIQUE key**: add the missing UNIQUE constraint (user_id, edition_id, status-class) on `wp_vad_registrations` at the next SCHEMA_VERSION bump — piggyback on the version-gated migrate() (now with failure backoff), never as a standalone ALTER.
3. **Dateless / self-paced catalog ruling** (product, Stefan): dateless editions are currently excluded from all catalog enumerations — the start_date orderby forces an EXISTS clause. Single canonical note: `stridence_catalog_date_window_meta_query()` docblock (helpers/catalog.php). Also covers the archive classroom teaser's missing date window (pre-existing divergence, noted inline).
4. **front-page.php status-literal convergence + missing audit grep**: front-page.php still hand-rolls active-status literals instead of `OfferingStatus::activeValues()`; the audit's "active-status grep" sweep was never run — do both together.
5. **F2 Redis enablement**: post-Q5 (Ploi plan confirmation) + parked task 1.2 (ops access). Drop-in is prepped; procedure + flush fleet-rule in site.yml notes.
6. **Dashboard LD-floor product question (Q3)**: 32 free e-learnings hydrate per user on the dashboard — also makes the "inschrijvingen" empty state unreachable (shake-out F3). Needs a product ruling on whether open-access courses belong on the dashboard at all.
7. **quote-admin.js taxRate localization**: the admin quote JS carries its own tax-rate assumption — localize it from QuoteCalculator (INV-8) via wp_localize_script so the JS preview can't drift from the server derivation.

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

## Dateless-editions sibling-site audit (2026-06-14, plan 2026-06-14-dateless-editions-catalog.md Task 7)

The `start_date` SQL-ordering pattern that excludes dateless editions lives in four query sites. This plan fixed three; the remaining are filed as teaser-only by design:

- `helpers/catalog.php` `stridence_catalog_klassikaal_items()` — FIXED (Task 2/3: orderby dropped, band-ordered in PHP).
- `helpers/catalog.php` `stridence_catalog_online_items()` — FIXED (Task 2: orderby dropped, flat list).
- `Admin/AdminAPIController.php` `getEditions` list view — FIXED (Task 6: LEFT JOIN + NULL-permit + NULL-last order).
- `archive-sfwd-courses.php` classroom teaser (`:66-76`) — LEFT-AS-TEASER: 6-item homepage strip; comment added. Canonical inclusion is /klassikaal. Follow-up only if product wants dateless in the teaser.
- `archive-sfwd-courses.php` online teaser (`:105-115`) — LEFT-AS-TEASER: same; comment added.
- `helpers/catalog.php` `stridence_prefetch_course_cards()` — N/A: no start_date orderby (default order), never excluded dateless; confirmed by read.

---

## Trajectory ⇄ Edition parity — ACTIVE (2026-06-16)

Branch: `feat/trajectory-edition-parity` (DDEV-served; off HEAD da528fd1). Dirty tree (LD upgrade + card-trajectory redesign) is unrelated, untouched.

Goal: trajectory enrolled-state works like editions on BOTH catalog card + detail page (context CTA + enrolled panels for child-editions/electives).

- [x] 1. `EnrollmentService::getEnrolledTrajectoryIds()` — bulk, mirrors getEnrolledEditionIds. **Tier A** unit test.
- [x] 2. Catalog: archive-vad_trajectory.php prefetches enrolled set → passes progress/dashboard_url opts to card builder.
- [x] 3. Detail CTA parity: port edition $enrolled_cta states to single-vad_trajectory.php.
- [x] 4. Detail enrolled panels: child-editions + elective choices inline (analogue of session panels), from getProgressData.
- [x] 5. Verify live (catalog+detail, enrolled+guest) + unit + trajectory integration green.

Committed already: basic enrolled CTA on detail + trajectory price in enrollment form (from fix/trajectory-detail-form).
