# Enrollment Data Namespacing + Selection Snapshot

**Date:** 2026-05-24
**Status:** Approved — ready for plan
**Scope:** `wp_vad_registrations.enrollment_data` JSON column shape, writers, readers, and selection history snapshot.

---

## Problem

`wp_vad_registrations.enrollment_data` is a single JSON column whose shape is inconsistent across stages:

- `interest` flow nests under `$.interest`
- `waitlist` flow nests under `$.waitlist`
- Frontend enrollment handler already produces stage-keyed `enrollment_personal` / `enrollment_billing`
- `EnrollmentService::processEnrollment` (lines 776-806) has a fallback path that writes `extra_fields` **flat at the root** — dead code from the form handler today, but live for direct callers (admin tools, AdminAPIController, tests)
- Status transitions (`Confirmed → Cancelled`, `Pending → Confirmed`, etc.) don't touch `enrollment_data` — so the merge-on-reactivation path can mix flat + stage-keyed shapes

Separately: the user's **original** session and elective choices are not preserved. The `selections` column is live and mutable — admin session swaps, completion-task edits, and phased trajectory picks all overwrite it. There's no audit of what the user picked at enrollment time.

## Goal

1. Every key inside `enrollment_data` lives under a known top-level namespace. No root-level questionnaire keys, ever.
2. Original session / edition selections are snapshotted into `enrollment_data.initial_selection` at enrollment time and are append-only thereafter.
3. Admins can see the original selection in the registration modal and CSV/Excel export.

## Non-goals

- No schema change to `wp_vad_registrations`.
- No backfill of existing rows (production has zero non-null `enrollment_data` rows).
- Local seed scripts (`scripts/seed.php`) and any test fixtures that pre-populate `enrollment_data` need updating to the new shape as part of this work. Stale local data is acceptable; readers tolerate missing stages, they just won't show the new `submitted_at` / `submitted_by` until the row is rewritten.
- No metabox UI in this pass (modal + export only).
- No name-resolution stored in JSON — names are resolved at view time from `vad_session` / `vad_edition`.

---

## Canonical shape

Every stage entry is wrapped with metadata so the submission timestamp lives next to the data:

```json
{
  "interest": {
    "submitted_at": "2026-05-24T14:32:11+00:00",
    "submitted_by": null,
    "data": { "name": "...", "email": "...", "...other questionnaire fields...": "..." }
  },
  "waitlist": {
    "submitted_at": "2026-05-24T14:32:11+00:00",
    "submitted_by": null,
    "data": { "...": "..." }
  },
  "enrollment_personal": {
    "submitted_at": "2026-05-24T14:32:11+00:00",
    "submitted_by": 42,
    "data": { "...": "..." }
  },
  "enrollment_billing": {
    "submitted_at": "2026-05-24T14:32:11+00:00",
    "submitted_by": 42,
    "data": { "...": "..." }
  },
  "intake": {
    "submitted_at": "2026-05-24T14:32:11+00:00",
    "submitted_by": 42,
    "data": { "...": "..." }
  },
  "evaluation": {
    "submitted_at": "2026-05-24T14:32:11+00:00",
    "submitted_by": 42,
    "data": { "...": "..." }
  },

  "initial_selection": {
    "type": "edition",
    "phases": [
      {
        "phase": "enrollment",
        "captured_at": "2026-05-24T14:32:11+00:00",
        "captured_by": 42,
        "session_ids": [123, 456]
      }
    ]
  }
}
```

**Stage entry contract:**
- Every stage value is an object with exactly three keys: `submitted_at` (ISO-8601 UTC), `submitted_by` (int WP user ID, or `null` for anonymous submissions like interest/waitlist before account creation), and `data` (the form payload).
- Each time the stage is (re-)submitted, all three keys overwrite (last-write-wins for the stage). The previous submission is not preserved — if full history is needed later, that's a separate audit-log concern.
- `data` may be empty (`{}`) but never absent.
- `submitted_at` is required; missing it is a write-time error.
- `submitted_by` comes from `get_current_user_id()` at write time. For interest/waitlist (anonymous), it's explicitly `null`. For admin-acting-on-behalf-of (e.g. enrolling a colleague), it's the admin's ID — the registration row's `user_id` already records the *participant*; `submitted_by` records the *actor* and may differ.

**Phase entry contract (`initial_selection.phases[]`):**
- Each phase entry carries `phase`, `captured_at`, `captured_by` (int WP user ID, may be `null` for system-triggered captures), and the IDs picked.
- Same actor-vs-participant distinction: `captured_by` is the actor, not necessarily the registration's `user_id`.

### Allowlist (7 keys)

```php
private const ALLOWED_KEYS = [
    'interest',
    'waitlist',
    'enrollment_personal',
    'enrollment_billing',
    'intake',
    'evaluation',
    'initial_selection',
];
```

Stage keys are user-submitted questionnaire data. `initial_selection` is a system-captured snapshot, treated separately by readers.

### `initial_selection` rules

- Written when the row is created (post-`setSelections`).
- **Append-only.** Existing phase entries never mutate; later phased picks add new entries.
- `type` = `"edition"` (sessions) | `"trajectory"` (edition picks) | `"none"` (no selection step ran).
- Each phase entry carries `phase` label, `captured_at` ISO-8601 UTC, and the IDs picked in that phase (`session_ids` or `edition_ids`).
- For editions: one phase entry, `phase: "enrollment"`, holds `session_ids`.
- For trajectories at enrollment: one phase entry, `phase: "enrollment"`, holds upfront `edition_ids`.
- Later phased picks (when the phased-choices feature ships) append entries with phase labels matching the trajectory definition (e.g. `phase_1`, `phase_2`).

---

## Writer changes

### `RegistrationRepository`

**New private method** `normalizeEnrollmentData(array $data): array`:
- Iterates top-level keys
- Keys in `ALLOWED_KEYS` pass through with shape enforcement:
  - **Stage keys** (`interest`, `waitlist`, `enrollment_personal`, `enrollment_billing`, `intake`, `evaluation`): value must be an array with `data` (array), `submitted_at` (non-empty string), and `submitted_by` (int or null). Missing `submitted_at` is filled with `gmdate('c')` and logged as a warning (defensive — writers should always set it). Missing `submitted_by` is filled with `null` and logged. Missing `data` defaults to `[]`. Any other keys on the stage object are dropped + logged.
  - **`initial_selection`**: value must be an array with `type` (string) and `phases` (array). Passed through structurally.
- Anything else at the top level is logged via `ntdst_log('enrollment')->warning('enrollment_data: dropped unknown root key', [...])` and dropped
- Returns the cleaned array

**New helper** `wrapStage(array $data, ?int $submittedBy = null, ?string $submittedAt = null): array`:
- Returns `['submitted_at' => $submittedAt ?? gmdate('c'), 'submitted_by' => $submittedBy ?? (get_current_user_id() ?: null), 'data' => $data]`
- `submitted_by` defaults to the current WP user, falling back to `null` for anonymous contexts (interest / waitlist forms).
- Callers can override `$submittedBy` to record on-behalf-of writes (e.g. colleague enrolment: pass the enroller's ID, not the participant's).
- Used by all writers to construct the wrapped stage shape consistently

**Called from:**
- `create()` — line 141 area, before `wp_json_encode`
- `update()` — line 1011 area, when `enrollment_data` is in the allowed fields list
- `upgradeFromInterest()` — line 299 area, after the merge

**New public method** `appendInitialSelectionPhase(int $registrationId, array $phase): bool`:
- Reads current row's `enrollment_data`
- Initializes `initial_selection` if missing (with `type` derived from caller-provided phase shape)
- Enriches `$phase` with `captured_at` (defaults to `gmdate('c')`) and `captured_by` (defaults to `get_current_user_id() ?: null`) if not already set by caller — same actor-override pattern as `wrapStage`
- Appends the enriched phase to `initial_selection.phases[]`
- Writes back via `update()` (which normalizes again — idempotent and safe)
- Returns false + logs warning if row not found
- The append-only contract is enforced inside this method — no caller can mutate existing phases

### `EnrollmentService::processEnrollment`

**Lines 776-806** (the `extra_fields` flat fallback): instead of writing `$enrollOptions['enrollment_data'] = $courseFields`, wrap as `$enrollOptions['enrollment_data'] = ['enrollment_personal' => RegistrationRepository::wrapStage($courseFields)]`. Direct callers (admin tools) get stage-shaped, timestamped data without changing their interface.

**After successful `setSelections`** (around line 819): call `$this->registrations->appendInitialSelectionPhase($registrationId, ['phase' => 'enrollment', 'captured_at' => gmdate('c'), 'session_ids' => $sessionIds])`. The repo method handles the `type: 'edition'` initialization on first call.

If no sessions are selected (no selection step ran), still write a `type: 'none'` initial_selection record with an empty `phases[0]` so the absence is explicit — distinguishes "no selection needed" from "selection lost".

### `QuestionnaireHandler` (interest / waitlist / intake / evaluation)

All four submit paths currently write `$existingData[$stage] = $stageData;` (flat). Update each to use the wrap helper:

```php
$existingData[$stage] = RegistrationRepository::wrapStage($stageData);
```

`submitted_by` resolution per handler:
- `handleSubmitInterest` / `handleSubmitWaitlist`: pass `get_current_user_id() ?: null` — anonymous submissions store `null`.
- `handleSubmitStage` (intake / evaluation): always logged-in by the existing `not_logged_in` guard at line 176-178; helper default (`get_current_user_id()`) is correct.

Call sites:
- `handleSubmitInterest` (line 64, line 83)
- `handleSubmitWaitlist` (line 131, line 149)
- `handleSubmitStage` (line 211 — covers `intake` and `evaluation`)

Each re-submit refreshes `submitted_at` (last-write-wins, per the stage contract).

### `EnrollmentFormHandler`

`splitExtraFieldsByStage` returns `['enrollment_personal' => [...], 'enrollment_billing' => [...]]` flat (lines 622-624). Wrap before handing to `processEnrollment`, passing the *actor* (the user pressing submit), which may differ from the participant in colleague enrolments:

```php
$actorId = get_current_user_id() ?: null;
$stageData['enrollment_personal'] = RegistrationRepository::wrapStage($stageData['enrollment_personal'] ?? [], $actorId);
$stageData['enrollment_billing']  = RegistrationRepository::wrapStage($stageData['enrollment_billing']  ?? [], $actorId);
```

Single `submitted_at` per stage at form-submission time. Both stages submit together via one form, so both get the same timestamp and the same `submitted_by` — that's fine; they're separate stages with the same submission moment from the same actor.

### `TrajectorySelection`

**In `enroll()`**: after the registration row is created with mandatory editions, call `appendInitialSelectionPhase($registrationId, ['phase' => 'enrollment', 'captured_at' => gmdate('c'), 'edition_ids' => $mandatoryEditionIds])` — with `type: 'trajectory'`.

**In `setSelections()`** (electives picked at enrollment time): call `appendInitialSelectionPhase` with `phase: 'enrollment'`. The repo's append-only contract means a second call with the same phase label will still append a new entry rather than overwrite — that's intentional: re-selecting electives produces a new audit entry, never mutates the original. The phased-choices feature (when built) calls the same method with its own phase labels (`phase_1`, `phase_2`, …); each call is one new entry.

---

## Reader changes

### `RegistrationModalController`

**Stage panels:** read each stage as `$stage['data']` (form payload), and display `$stage['submitted_at']` + `$stage['submitted_by']` as a small metadata header per stage (e.g. "Ingediend op 24/05/2026 14:32 door Stefan Vandermeulen"). Resolve `submitted_by` → display name via `get_userdata()`; show "(anoniem)" if `null`.

**Initial selection panel:** add an "Originele keuze" panel above the questionnaire-stage panels. Iterates `initial_selection.phases[]`:
- For each phase, show the phase label (`Inschrijving`, `Fase 1`, …), `captured_at`, and `captured_by` (resolved to display name, or "(systeem)" if `null`)
- Resolve `session_ids` → session titles + dates via `SessionRepository`
- Resolve `edition_ids` → edition titles via `EditionRepository`
- Fallback: if IDs no longer resolve (session deleted), show the raw ID with a "(verwijderd)" marker

### `EditionRegistrationExporter`

**Stage-data reading:** the existing stage-summary code (`EditionRegistrationExporter.php:847-913`) currently reads `enrollment_data[$stage]` directly. Update to read `enrollment_data[$stage]['data']` (the form payload), so the column values stay identical for end users. Treat missing/malformed `data` as `[]`.

**Initial selection column:** add one "Originele keuze" column per export (not per phase). Value is a phase-prefixed, comma-joined string:

```
Inschrijving: Sessie A (2026-03-01), Sessie B (2026-03-08)
```

If multiple phases exist, separate with ` | `:

```
Inschrijving: … | Fase 1: …
```

Empty string when `initial_selection` is missing or `type: 'none'`.

### Stage-reader audit

Verify these readers iterate only the 6 stage keys, never the root, and read `[stage]['data'][field]` (not `[stage][field]`):
- `EditionRegistrationExporter.php:847-913` — already stage-aware (`$stagesToShow`); update to read `[stage]['data']` payload, add `enrollment_billing` to the visible list, confirm no root-fallback path.
- `EditionRegistrationMetabox.php:633` — check the template renders by stage, update to read `[stage]['data']`.
- `RegistrationModalController.php:132` — confirm iteration is stage-keyed, update to read `[stage]['data']`.
- `AdminAPIController.php:1335-1347` — comment says "name/email captured in enrollment_data"; verify it reads `$.interest.data.email` / `$.enrollment_personal.data.email` (after spec lands), not root or pre-wrap path.
- Also search for any JSON_EXTRACT SQL paths: `RegistrationRepository.php:244-245` uses `$.interest.email` and `$.waitlist.email` — update to `$.interest.data.email` and `$.waitlist.data.email`.

Any reader doing root-level iteration gets the same drop-with-log treatment for unknown keys (defensive — should be impossible after writes normalize).

---

## Test plan

### Unit — `RegistrationRepositoryTest`

- `wrapStage` builds the 3-key envelope (`submitted_at`, `submitted_by`, `data`)
- `wrapStage` defaults `submitted_by` to `get_current_user_id()` and accepts explicit override
- `wrapStage` defaults `submitted_by` to `null` when no user is logged in
- `normalizeEnrollmentData` drops unknown root keys and logs a warning
- `normalizeEnrollmentData` passes well-formed stage entries through unchanged
- `normalizeEnrollmentData` fills missing `submitted_at` / `submitted_by` with defaults and logs
- `normalizeEnrollmentData` drops unknown keys *inside* a stage object
- `normalizeEnrollmentData` passes `initial_selection` through unchanged
- `create()` with mixed (stage + unknown root) input persists only stage keys
- `update()` with unknown root keys persists only stage keys
- `upgradeFromInterest()` merge produces stage-shaped output even when input is flat
- `appendInitialSelectionPhase` initializes the structure on first call with `captured_by` from current user
- `appendInitialSelectionPhase` appends a second phase without mutating the first
- `appendInitialSelectionPhase` is idempotent on the row-not-found path (returns false + logs)

### Unit — `EnrollmentServiceTest`

- `processEnrollment` with `extra_fields` from a direct caller writes to `enrollment_personal.data`, not root
- `processEnrollment` writes `submitted_by` matching the acting user
- `processEnrollment` calls `appendInitialSelectionPhase` after successful `setSelections`
- `processEnrollment` writes `type: 'none'` initial_selection when no sessions are selected

### Unit — `QuestionnaireHandlerTest`

- Interest submission persists `submitted_by: null` for anonymous users, actor ID when logged in
- Waitlist submission same as above
- Intake / evaluation submission persists `submitted_by` = the participant (logged-in guard ensures non-null)
- Re-submitting a stage updates `submitted_at` + `submitted_by` (last-write-wins)

### Unit — Exporter

- Stage-summary column reads `[stage]['data']` payload, not the wrapper
- "Originele keuze" column renders single-phase correctly
- Column renders multi-phase correctly with ` | ` separator
- Column shows empty string when `initial_selection` is missing
- Column shows "(verwijderd)" marker when a session ID no longer resolves

### Acceptance

- Full edition form submission → row has stage-keyed `enrollment_data`, no root-level keys, each stage has `submitted_at` / `submitted_by` / `data`, `initial_selection.phases[0].session_ids` matches submitted sessions
- Anonymous interest submission → `interest.submitted_by` is `null`
- Full trajectory form submission → row has `initial_selection.type: 'trajectory'`, phase 0 has the mandatory editions + actor in `captured_by`
- Colleague enrolment → `enrollment_personal.submitted_by` is the enroller's ID, registration row's `user_id` is the participant — they differ

---

## Out of scope

- Schema migration / backfill
- CLI normalizer command
- Metabox UI for `initial_selection` (modal + export only this pass)
- Snapshot of billing or personal fields at confirm time (separate concern)
- Phased-choices feature itself — `appendInitialSelectionPhase` is the seam it will plug into when built
- Pure-LD elective gap in trajectory cascade (tracked separately: `project_pure_ld_electives_gap`)

---

## Files touched

- `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php` (writers + new methods)
- `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php` (lines 776-806 + post-setSelections hook)
- `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectorySelection.php` (enroll + setSelections)
- `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php` (panel)
- `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php` (column + stage audit)
- `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php` (stage audit)
- `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` (stage audit at line 1335-1347)
- Tests under `tests/Unit/` + `tests/Integration/` + `tests/acceptance/`
