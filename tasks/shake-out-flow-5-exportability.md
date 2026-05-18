# Shake-out Flow #5: Data exportability sweep

**Date:** 2026-05-18
**Scope:** For every writeable column on `stride_vad_registrations` and adjacent tables, verify the value flows through to at least one sheet of `EditionRegistrationExporter`'s XLSX output.
**Status:** COMPLETE — 19 column checks, **3 export gaps found and fixed**. Regression: all phases + 5 flows green, 867 unit tests pass.

---

## Column inventory & destination

| Column | Source table | Where it goes in export | Status |
|---|---|---|---|
| `id` | registrations | implicit (row order) | ✅ |
| `user_id` | registrations | resolved to user object (first/last name + email) | ✅ |
| `edition_id` | registrations | Overzicht header | ✅ |
| `trajectory_id` | registrations | not exported — by design, Deelnemers is per-edition. Trajectory exports are a separate flow. | ✅ |
| `status` | registrations | Deelnemers "Status" column + Overzicht counts | ✅ |
| `enrollment_path` | registrations | Deelnemers "Inschrijfwijze" | ✅ |
| `selections` JSON | registrations | Deelnemers "Sessieselectie" + Taken "Sessies" | ✅ |
| `selections_locked_at` | registrations | not exported — internal lock-state marker | ✅ (intentional) |
| `enrolled_by` | registrations | **Deelnemers "Ingeschreven door"** (NEW after BUG-EX-3) | ✅ |
| `quote_id` | registrations | resolves to quote number + total + voucher + status on Facturatie | ✅ |
| `company_id` | registrations | propagated via user-meta; exported indirectly via billing_company | ✅ |
| `registered_at` | registrations | Deelnemers "Inschrijfdatum" | ✅ |
| `completed_at` | registrations | **Deelnemers "Voltooid op"** (NEW after BUG-EX-3) | ✅ |
| `cancelled_at` | registrations | **Deelnemers "Geannuleerd op"** (NEW after BUG-EX-3) | ✅ |
| `notes` | registrations | Deelnemers "Opmerking" | ✅ |
| `completion_tasks` JSON | registrations | Taken & Vragenlijst sheet, one row per task | ✅ (after BUG-EX-2) |
| `enrollment_data` JSON (`enrollment_personal`, `intake`, `evaluation`) | registrations | **Deelnemers "Extra gegevens"** (NEW after BUG-EX-1) | ✅ |
| `enrollment_data` JSON (`interest`, `waitlist`) | registrations | Interesse + Wachtlijst sheets via stage-keyed lookup | ✅ |
| attendance rows | `stride_vad_attendance` | Aanwezigheid sheet, attendance grid | ✅ |

---

## Bugs found and fixed

### BUG-EX-1: enrollment_data extra fields NEVER exported [FIXED]

**Symptom:** Whatever the user submitted as part of `enrollment_data.enrollment_personal` (and the same for `intake`/`evaluation` stages) was never visible in the export. Admins would have to read DB JSON to see what answers people gave to custom enrollment fields.

**Root cause:** `EditionRegistrationExporter::gatherData()` had a hard-coded `$extraFields = []` with a comment "Extra fields from the old enrollment field group system are no longer supported. Use the Questionnaire module for additional fields instead." But the new Questionnaire-module-driven export was never written.

**Fix:** Added `summarizeEnrollmentData()` helper + a new "Extra gegevens" column in the Deelnemers sheet. Walks `enrollment_data.enrollment_personal`, `intake`, `evaluation` and renders `key: value` lines, skipping fields already shown in their own columns (name, email, billing_*).

**Files:** `EditionRegistrationExporter.php:374-378` (new column), `EditionRegistrationExporter.php:851-901` (summarizer)

### BUG-EX-2: Tasks sheet rendered task rows with EMPTY label cells [FIXED]

**Symptom:** The "Taken & Vragenlijst" sheet had 5 columns including "Taak" (task name), but the second column was empty for every row. So admins saw "[blank] | Voltooid | 31/03/2026 14:11 | ervaring: bedreven" with no indication which task this was.

**Root cause:** `writeTasksSheet` iterated `foreach ($tasks as $task)` (drops the key) then tried `$task['type']` and `$task['label']` — but the stored shape is `[$taskType => ['status' => 'completed', 'phase' => 'enrollment', 'completed_at' => ..., 'data' => [...]]]`. There's no `type` or `label` inside the task body — the type is the array key, which was lost.

Also: session_selection's chosen IDs are stored under `data.session_ids` (per `RegistrationRepository::setSelections`), but the export read `data.selected_sessions` — wrong key, so the "Sessies:" line in Details was never populated either.

**Fix:**
- Iterate with `foreach ($tasks as $taskType => $task)`, fall back to `$task['type']` for forward-compat
- Map task types to human Dutch labels (Vragenlijst / Documenten / Goedkeuring / Sessiekeuze / Evaluatie etc.)
- Read `data.session_ids` first, fall back to `data.selected_sessions` for legacy data

**Files:** `EditionRegistrationExporter.php:692-757`

### BUG-EX-3: completed_at, cancelled_at, enrolled_by not exported [FIXED]

**Symptom:** The Deelnemers sheet didn't include completion timestamps, cancellation timestamps, or who-enrolled-this-person (for colleague enrollments). Admins couldn't answer "when did this person actually finish?" or "who registered this colleague?" without DB access.

**Root cause:** These columns simply weren't in the header list — the sheet stopped at Status / Inschrijfwijze / Inschrijfdatum / Sessieselectie / Opmerking.

**Fix:** Added three new columns to the Deelnemers sheet:
- `Ingeschreven door` — resolves `enrolled_by` user_id to a name (uses batchGetUsers; also added `enrolled_by` IDs to the batch fetch so we don't need a per-row query)
- `Voltooid op` — formatted `completed_at`
- `Geannuleerd op` — formatted `cancelled_at`

**Files:** `EditionRegistrationExporter.php:366-369` (headers), `EditionRegistrationExporter.php:434-454` (cell rendering), `EditionRegistrationExporter.php:177-181` (batch fetch enrolled_by)

---

## Test scenarios — all passing post-fix

| # | Coverage | Result |
|---|---|---|
| EX-1 | 13 basic Deelnemers columns (name, email, phone, billing_*, status, path, notes) | ✅ |
| EX-2 | Facturatie sheet quote columns (number, total, voucher, status) | ✅ |
| EX-3 | Taken sheet task labels render + answer payload (`TEST_ANSWER_X`) exported | ✅ (after BUG-EX-2 fix) |
| EX-4 | enrollment_data extra fields (`shoe_size`, `dietary`) export | ✅ (after BUG-EX-1 fix) |
| EX-5 | completed_at + cancelled_at + enrolled_by name + company_id all present | ✅ (after BUG-EX-3 fix) |
| EX-6 | Anonymous interest/waitlist rows in Interesse/Wachtlijst sheets (name + email) | ✅ |
| EX-7 | Overzicht status counts (Geannuleerd label present) | ✅ |

---

## Cumulative bug count this session

| Source | Bugs | Fixed |
|---|---|---|
| Phase A-H lifecycle | 6 (RL-1..6) | 6 |
| D4/E7/Withdrawn product changes | 0 + 3 changes | 3 |
| Flow #1 (Interest/Waitlist) | 4 (IW-1..4) | 4 |
| Flow #2 (Enrollment tasks) | 1 (ET-1) | 1 |
| Flow #3 (Cancellation cascade) | 0 | n/a |
| Flow #4 (Completion + certificate) | 1 real (CP-1) + 1 architectural finding | 1 |
| Flow #5 (Data exportability) | 3 (EX-1, EX-2, EX-3) | 3 |
| **Total** | **15 bugs + 3 changes + 1 architecture finding** | **all bugs fixed** |

---

## Files changed in Flow #5

- `Modules/Edition/Admin/EditionRegistrationExporter.php` — three fixes:
  - Tasks sheet: iterate by key, label map, correct session_ids read
  - Deelnemers sheet: new "Extra gegevens", "Ingeschreven door", "Voltooid op", "Geannuleerd op" columns + helper
  - Batch fetch enrolled_by user IDs alongside user_id

- `tests/manual/shake-flow-export.php` — 7 scenario groups covering 19+ column checks for regression

## What's still NOT in the export (by design, documented)

- `trajectory_id` — Deelnemers is per-edition; a separate trajectory exporter handles trajectory-scoped rows
- `selections_locked_at` — internal marker; not user-facing
- Raw JSON of `completion_tasks` / `enrollment_data` / `selections` — surfaced via the human-readable sheets, not raw

## What might be worth adding (deferred)

- An "Audit" sheet showing all `audit_log` entries for the edition (who confirmed/cancelled, when)
- A "Mailings" sheet showing which mails fired for which user (requires mail-log integration)
- Per-stage subsheets for interest/intake/evaluation when those have lots of structured data
