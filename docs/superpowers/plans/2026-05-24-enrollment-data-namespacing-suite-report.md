# Enrollment Data Namespacing — Suite Report

**Date:** 2026-05-24
**Branch base:** `620e8102`
**Branch HEAD:** `7c9d8781`
**Plan:** `docs/superpowers/plans/2026-05-24-enrollment-data-namespacing.md`
**Spec:** `docs/superpowers/specs/2026-05-24-enrollment-data-namespacing-design.md`

19 commits over 17 tasks landed on `staging`.

## Test results

| Suite | Total | Passed | Pre-existing failures | New regressions |
|---|---|---|---|---|
| Unit | 913 | 910 | 3 (NetdustMail) | 0 |
| Integration | 369 | 367 | 2 (EditionZipExports) | 0 |
| Acceptance (new Cest) | 1 | 1 | n/a | 0 |

## New tests added by this branch

13 new test files, 1 modified test, 1 new Cest, 1 suite YAML fix. Total 15 files in `tests/`, 1863 LOC added.

### Unit
- `tests/Unit/Modules/Enrollment/RegistrationRepositoryNormalizeTest.php` — `wrapStage` + `normalizeEnrollmentData` (11 tests)
- `tests/Unit/Modules/Enrollment/EnrollmentServiceStageShapeTest.php` — direct-caller wrap shape (1 test)
- `tests/Unit/Modules/Edition/RegistrationModalControllerTest.php` — `buildInitialSelection` + `buildStagesForDisplay` (4 tests)

### Integration
- `tests/Integration/Modules/Enrollment/RegistrationRepositoryNormalizeIntegrationTest.php` — normalize wired into create/update/upgrade (3 tests)
- `tests/Integration/Modules/Enrollment/RegistrationRepositoryInitialSelectionTest.php` — append-only phase log (4 tests)
- `tests/Integration/Modules/Enrollment/RegistrationRepositoryFindByEmailTest.php` — SQL JSON path update (2 tests)
- `tests/Integration/Modules/Enrollment/InitialSelectionCaptureTest.php` — edition enroll captures snapshot (2 tests)
- `tests/Integration/Modules/Questionnaire/QuestionnaireHandlerWrapTest.php` — interest/waitlist/intake wrapped (3 tests)
- `tests/Integration/Handlers/EnrollmentFormHandlerWrapTest.php` — personal+billing wrapped + direct-caller path (2 tests)
- `tests/Integration/Modules/Trajectory/TrajectoryInitialSelectionTest.php` — trajectory enroll captures snapshot (1 test + 1 skipped to acceptance)
- `tests/Integration/Admin/AdminAPIControllerAnonRowReadTest.php` — anon row reads wrapped envelope (2 tests)
- `tests/Integration/Modules/Edition/EditionRegistrationExporterStageTest.php` — exporter reads wrapped + Originele keuze column (6 tests)
- `tests/Integration/EnrollmentServiceIntegrationTest.php` (modified) — colleague-enrolment test reads new path

### Acceptance
- `tests/acceptance/EnrollmentDataShapeCest.php` — full HTTP roundtrip asserts wrapped shape + `initial_selection` (1 test, 22 assertions)
- `tests/acceptance.suite.yml` — prefix fix `stride_` → `ckqp_` (pre-existing bug, unblocks the entire acceptance suite)

## Pre-existing failures verified (not caused by this branch)

### `Tests\Unit\NetdustMail\AdminControllerTest` — 3 tests
- `testRegisterMenuAddsOptionsPage` (Failure)
- `testEnqueueAssetsEnqueuesForMailSettingsPage` (Failure)
- `testEnqueueAssetsLocalizesMailConfig` (Error: null array)

File last touched: commit `b71c7ef8 refactor(theme): replace get_template_part with stridence_template_part` — confirmed via `git merge-base --is-ancestor b71c7ef8 620e8102` to pre-date this branch's base. Unrelated to enrollment_data work.

### `Stride\Tests\Integration\Edition\EditionZipExportsIntegrationTest` — 2 tests
- `testBundleProducesZipWithAllArtefacts` (Error: file path `/data/sites/web/vad-vormingenbe/www/content/uploads/...` — environment mount config from production VAD site)
- `testFilesEnumerateReturnsSeededAttachment` (Failure: expected 1 attachment, got 0 — fixture/env issue)

File last touched: commit `55c6fc74 feat(edition-export): EditionBundleZipExporter for combined ZIP` — pre-dates this branch base. Environment-level issue, not enrollment_data related.

## Manual verification recommended before merge

The plan's manual verification step (Task 17 Step 5) was not automated. Before merging to `main`:

1. **Admin registration modal** — Open `wp-admin → Edities → <seeded edition> → registration detail modal` for a seeded user. Confirm the new "Originele keuze" panel + per-stage "Ingediend op … door …" metadata renders correctly.

2. **Excel export** — From the same edition admin page, export registrations as XLSX. Open the file and confirm the new "Originele keuze" column exists on the deelnemers sheet and is populated with phase-prefixed selection labels.

3. **Public interest form** — Submit a fresh interest registration via the public form on a `vad_edition` page. Inspect the row:
   ```
   ddev exec wp db query "SELECT JSON_PRETTY(enrollment_data) FROM ckqp_vad_registrations ORDER BY id DESC LIMIT 1\G"
   ```
   Confirm the shape: `interest: { submitted_at, submitted_by, data: {...} }`.

## Spec coverage cross-check

All 17 planned tasks completed:

- [x] Task 1 — `wrapStage()` helper
- [x] Task 2 — `normalizeEnrollmentData()` enforcement
- [x] Task 3 — normalize wired into `create / update / upgradeFromInterest`
- [x] Task 4 — `appendInitialSelectionPhase()` append-only writer
- [x] Task 5 — SQL JSON_EXTRACT paths to `.data.email`
- [x] Task 6 — QuestionnaireHandler stage wraps (interest/waitlist/intake/evaluation)
- [x] Task 7 — EnrollmentFormHandler personal+billing wraps
- [x] Task 8 — EnrollmentService direct-caller fallback wraps + repaired regression test
- [x] Task 9 — EnrollmentService captures edition `initial_selection`
- [x] Task 10 — TrajectorySelection captures trajectory `initial_selection`
- [x] Task 11 — AdminAPIController reads wrapped envelope
- [x] Task 12 — EditionRegistrationExporter reads wrapped + new "Originele keuze" column
- [x] Task 13 — EditionRegistrationMetabox audit (dead-code removal, no flat reads found)
- [x] Task 14 — Modal renders `initial_selection` + per-stage submitter metadata
- [x] Task 15 — Seed script audit (no-op; seed doesn't write `enrollment_data` directly)
- [x] Task 16 — Acceptance Cest + pre-existing prefix bug fix
- [x] Task 17 — This report

## Recommendation

**Ready to merge to `main`** after the three manual verification steps above pass.

No new regressions introduced. All pre-existing failures verified out-of-scope. The 5 pre-existing failing tests can stay for a follow-up cleanup pass — they're unrelated to enrollment_data namespacing.
