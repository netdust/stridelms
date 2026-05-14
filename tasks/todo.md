# Active Sprint — todo

Working scratchpad. Authoritative launch list lives in `docs/LAUNCH-CHECKLIST.md`.

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

## Deferred polish (post-launch nice-to-haves)

- **M1 (voucher shake-out)** — edition pickers render blank entry for vad_edition #5088 (empty post_title). Pre-existing data quality issue. Cosmetic.
- **Density modes** (§A.2 P1) — CSS compact mode for dashboard tables. Cheap if done at CSS level.
- **Anonymise UX polish** — toast persistence, bulk anonymise UI

---

## Next session — pick from LAUNCH-CHECKLIST

P1 items remaining for launch:

### §F — Multi-brand demo (P1)
- Brand scaffold #2 (corporate training or university CPD — pick one)
- Brand scaffold #3 (wellness or public sector for max contrast)
- Swap mechanic doc (1-page guide for sales/dev)
- Side-by-side comparison screenshots

### Pre-launch cleanup (mixed P)
- Stash uncommitted LTI work on `staging`
- Drop stray PNGs (`bento-section`, `debug-outlines`, `stridelms-fullpage`)
- Add `tests/_output/` to `.gitignore`
- Decide stale design drafts (session-price-modifiers, stride-mail-integration, roles-capabilities)
- Decide: hide Trajectory + Partner API admin UI for v1, or leave visible?

### Post-launch tracking (NOT for v1)
- Task #21 (workspace task list): drop dead `stride_vad_session_registrations` table + migrate 2 rows from `stride_vad_trajectory_enrollments` + remove the stale AdminAPI reference path entirely
- D.4 (P2): `EditionService::recomputeStatus()` + `wp stride recompute-edition-status` CLI for capacity edits / bulk imports
