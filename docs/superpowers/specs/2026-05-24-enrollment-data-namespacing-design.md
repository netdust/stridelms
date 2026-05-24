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
- No metabox UI in this pass (modal + export only).
- No name-resolution stored in JSON — names are resolved at view time from `vad_session` / `vad_edition`.

---

## Canonical shape

```json
{
  "interest":            { "...questionnaire stage answers..." },
  "waitlist":            { "..." },
  "enrollment_personal": { "..." },
  "enrollment_billing":  { "..." },
  "intake":              { "..." },
  "evaluation":          { "..." },

  "initial_selection": {
    "type": "edition",
    "phases": [
      {
        "phase": "enrollment",
        "captured_at": "2026-05-24T14:32:11+00:00",
        "session_ids": [123, 456]
      }
    ]
  }
}
```

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
- Keys in `ALLOWED_KEYS` with array values pass through
- Anything else is logged via `ntdst_log('enrollment')->warning('enrollment_data: dropped unknown root key', [...])` and dropped
- Returns the cleaned array

**Called from:**
- `create()` — line 141 area, before `wp_json_encode`
- `update()` — line 1011 area, when `enrollment_data` is in the allowed fields list
- `upgradeFromInterest()` — line 299 area, after the merge

**New public method** `appendInitialSelectionPhase(int $registrationId, array $phase): bool`:
- Reads current row's `enrollment_data`
- Initializes `initial_selection` if missing (with `type` derived from caller-provided phase shape)
- Appends `$phase` to `initial_selection.phases[]`
- Writes back via `update()` (which normalizes again — idempotent and safe)
- Returns false + logs warning if row not found
- The append-only contract is enforced inside this method — no caller can mutate existing phases

### `EnrollmentService::processEnrollment`

**Lines 776-806** (the `extra_fields` flat fallback): instead of writing `$enrollOptions['enrollment_data'] = $courseFields`, wrap as `$enrollOptions['enrollment_data'] = ['enrollment_personal' => $courseFields]`. Direct callers (admin tools) get stage-shaped data without changing their interface.

**After successful `setSelections`** (around line 819): call `$this->registrations->appendInitialSelectionPhase($registrationId, ['phase' => 'enrollment', 'captured_at' => gmdate('c'), 'session_ids' => $sessionIds])`. The repo method handles the `type: 'edition'` initialization on first call.

If no sessions are selected (no selection step ran), still write a `type: 'none'` initial_selection record with an empty `phases[0]` so the absence is explicit — distinguishes "no selection needed" from "selection lost".

### `TrajectorySelection`

**In `enroll()`**: after the registration row is created with mandatory editions, call `appendInitialSelectionPhase($registrationId, ['phase' => 'enrollment', 'captured_at' => gmdate('c'), 'edition_ids' => $mandatoryEditionIds])` — with `type: 'trajectory'`.

**In `setSelections()`** (electives picked at enrollment time): call `appendInitialSelectionPhase` with `phase: 'enrollment'`. The repo's append-only contract means a second call with the same phase label will still append a new entry rather than overwrite — that's intentional: re-selecting electives produces a new audit entry, never mutates the original. The phased-choices feature (when built) calls the same method with its own phase labels (`phase_1`, `phase_2`, …); each call is one new entry.

---

## Reader changes

### `RegistrationModalController`

Add an "Originele keuze" panel above the questionnaire-stage panels. Iterates `initial_selection.phases[]`:
- For each phase, show the phase label (`Inschrijving`, `Fase 1`, …) and `captured_at`
- Resolve `session_ids` → session titles + dates via `SessionRepository`
- Resolve `edition_ids` → edition titles via `EditionRepository`
- Fallback: if IDs no longer resolve (session deleted), show the raw ID with a "(verwijderd)" marker

### `EditionRegistrationExporter`

Add one "Originele keuze" column per export (not per phase). Value is a phase-prefixed, comma-joined string:

```
Inschrijving: Sessie A (2026-03-01), Sessie B (2026-03-08)
```

If multiple phases exist, separate with ` | `:

```
Inschrijving: … | Fase 1: …
```

Empty string when `initial_selection` is missing or `type: 'none'`.

### Stage-reader audit

Verify these readers iterate only the 6 stage keys, never the root:
- `EditionRegistrationExporter.php:847-913` — already stage-aware (`$stagesToShow`); add `enrollment_billing` to the visible list, confirm no root-fallback path.
- `EditionRegistrationMetabox.php:633` — check the template renders by stage.
- `RegistrationModalController.php:132` — confirm iteration is stage-keyed.
- `AdminAPIController.php:1335-1347` — comment says "name/email captured in enrollment_data"; verify it reads `$.interest.email` / `$.enrollment_personal.email`, not root.

Any reader doing root-level iteration gets the same drop-with-log treatment for unknown keys (defensive — should be impossible after writes normalize).

---

## Test plan

### Unit — `RegistrationRepositoryTest`

- `normalizeEnrollmentData` drops unknown root keys and logs a warning
- `normalizeEnrollmentData` passes stage keys through unchanged
- `normalizeEnrollmentData` passes `initial_selection` through unchanged
- `create()` with mixed (stage + unknown root) input persists only stage keys
- `update()` with unknown root keys persists only stage keys
- `upgradeFromInterest()` merge produces stage-shaped output even when input is flat
- `appendInitialSelectionPhase` initializes the structure on first call
- `appendInitialSelectionPhase` appends a second phase without mutating the first
- `appendInitialSelectionPhase` is idempotent on the row-not-found path (returns false + logs)

### Unit — `EnrollmentServiceTest`

- `processEnrollment` with `extra_fields` from a direct caller writes to `enrollment_personal`, not root
- `processEnrollment` calls `appendInitialSelectionPhase` after successful `setSelections`
- `processEnrollment` writes `type: 'none'` initial_selection when no sessions are selected

### Unit — Exporter

- "Originele keuze" column renders single-phase correctly
- Column renders multi-phase correctly with ` | ` separator
- Column shows empty string when `initial_selection` is missing
- Column shows "(verwijderd)" marker when a session ID no longer resolves

### Acceptance

- Full edition form submission → row has stage-keyed `enrollment_data`, no root-level keys, `initial_selection.phases[0].session_ids` matches submitted sessions
- Full trajectory form submission → row has `initial_selection.type: 'trajectory'`, phase 0 has the mandatory editions

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
