# Shake-out Flow #2: Enrollment-phase completion tasks

**Date:** 2026-05-18
**Scope:** Every enrollment-phase task type — questionnaire, documents, approval, session_selection. Including availability rules, locked-task guards, auto-confirm trigger, cancel mid-flow, re-enroll task reset.
**Status:** COMPLETE — 18 scenarios, 1 bug fixed (stale tasks on cancel), test-bugs corrected. Regression: all phases + Flow #1 + Flow #2 green, 867 unit tests pass.

---

## Task taxonomy (verified)

### Enrollment-phase tasks
| Task | Meta key on edition | Initial state | Availability rule |
|---|---|---|---|
| `questionnaire` | `requires_questionnaire` | pending | Always available |
| `documents` | `requires_documents` | pending | Always available |
| `approval` | `requires_approval` | pending | LOCKED until questionnaire+documents done |
| `session_selection` | `requires_session_selection` | pending | LOCKED until approval done AND `selection_open=true` AND deadline not past |

### Post-course tasks (covered in Flow #4, not this manifest)
- `post_evaluation`, `post_documents`, `post_approval`

### Task body shape (verified)
Each task lives in `completion_tasks` JSON column:
```json
{"questionnaire": {"status": "pending|completed", "phase": "enrollment", "completed_at": "...", "data": { ... }}}
```
Submitted data lands in `task.data` (verified for questionnaire). Documents handler stores file references in `task.data` similarly. Answers/evidence are NOT in `enrollment_data` — that's reserved for stage-keyed pre-enrollment data (interest, waitlist).

---

## Bugs found and fixed

### BUG-ET-1: Cancelled rows retained stale `completion_tasks` JSON [FIXED]
**Symptom:** When admin/user cancelled a registration mid-flow, the `completion_tasks` JSON column kept the old state (some tasks completed, some pending). Visible in admin dashboard slide-over and XLSX export. Misleading: row is cancelled but appears to have "questionnaire done, waiting for approval".

**Root cause:** `RegistrationRepository::updateStatus(Cancelled)` only updated `status` + `cancelled_at`. Task state was a stale fact about a registration that no longer existed.

**Fix:** `updateStatus(Cancelled)` now also writes `completion_tasks = NULL`. On re-enrollment, the reactivation path in `RegistrationRepository::create()` calls `initializeForRegistration()` which rebuilds the tasks from edition meta. End state: cancelled row = no tasks; re-enroll = fresh tasks.

**Files:** `RegistrationRepository.php:806-810`

### (No other bugs found in Flow #2.)

The other "findings" during the initial run turned out to be **test-script bugs**, not code bugs:
- "answers_persisted=N" was checking `task.answers` instead of `task.data.answers` (the real path). Code is correct.
- "session_selection always available" was because the test fixture already had `selection_open=true` set on edition 13311. The availability rules work correctly — confirmed by toggling the meta off and seeing the task lock.

Both test scripts updated to assert the real paths.

---

## Test scenarios — all passing

| # | Scenario | Result |
|---|---|---|
| ET-1 | Task init from edition meta builds correct JSON shape | ✅ |
| ET-2 | Availability rules: questionnaire+documents available, approval locked initially | ✅ |
| ET-3 | Complete questionnaire → status=completed + completed_at + data preserved at `task.data` | ✅ |
| ET-4 | Approval stays locked after only questionnaire complete | ✅ |
| ET-5 | Completing locked approval → `task_locked` error | ✅ |
| ET-6 | Complete documents → approval unlocks | ✅ |
| ET-7 | Complete approval → reg auto-confirms + quote auto-created | ✅ |
| ET-8 | Re-completing already-completed task is idempotent (returns true) | ✅ |
| ET-9 | `selection_open=false` → session_selection locked with reason "Sessiekeuze is nog niet geopend." | ✅ |
| ET-9b | Completing locked session_selection → `task_locked` | ✅ |
| ET-10 | `selection_open=true` → session_selection becomes available | ✅ |
| ET-11 | Complete session_selection → auto-confirm | ✅ |
| ET-12 | Re-edit session_selection allowed before course start | ✅ |
| ET-13 | Cancel mid-flow → `completion_tasks` cleared to NULL (after BUG-ET-1 fix) | ✅ |
| ET-14 | Re-enroll after cancel → fresh tasks initialized, all pending | ✅ |
| ET-15 | Unknown task type → `invalid_task` | ✅ |
| ET-16 | Complete task on non-existent reg → `not_found` | ✅ |
| ET-17 | Complete task not required for this registration → `task_not_required` | ✅ |
| ET-18 | Task data stored at `completion_tasks.<task>.data` (NOT `enrollment_data`) | ✅ |

---

## Observations (not bugs, worth recording)

### Auto-confirm trigger chain
When a user completes a task via the handler:
1. `CompletionTaskHandler::handleCompleteTask` (REST)
2. `EnrollmentCompletion::completeTask` writes to DB + fires `stride/enrollment/task_completed`
3. Three listeners:
   - `CompletionTaskHandler::onTaskCompleted` — checks `isFullyComplete`, calls `confirmRegistration` OR `processCompletionFinal`
   - `QuoteService::onSessionSelectionCompleted` — recalculates quote pricing when selections change
   - `StrideMailBridge::onTaskCompleted` — sends task-specific mail (e.g. documents-received-admin)

This chain is the single source of pending→confirmed promotion. If a custom task type is ever added, it'll need to integrate with the same chain or auto-confirm won't fire.

### Quote re-creation on re-enroll after cancel
ET-14 reactivation creates a fresh row with cleared tasks AND a fresh `quote_id` (the old quote was cancelled in E8 cascade). The user goes through tasks again, the approval-confirm creates a new quote. Old quote stays as cancelled history. Confirmed working.

### Approval task: admin-only by convention, not enforced at completeTask level
`completeTask()` itself doesn't check permission — the **handler** (`CompletionTaskHandler::handleCompleteTask` line 67) checks `(int) $reg->user_id !== $userId` so the registered user can call it, BUT the availability rule (`approval` locked until questionnaire+documents done) prevents users from reaching the approval task at all. Effectively admin-only because admins bypass `getTaskAvailability` and call `completeTask` directly via the admin AJAX (`stride_confirm_registration`). Worth knowing if a future task adds something users should genuinely never click.

---

## Cumulative bug count this session

| Source | Bugs found | Fixed |
|---|---|---|
| Phase A-H lifecycle shakeout | 6 (RL-1..6) | 6 |
| D4/E7/Withdrawn cleanup (Stefan's product decisions) | 0 bugs, 3 product changes | 3 |
| Flow #1 (Interest/Waitlist surface) | 4 (IW-1..4) | 4 |
| Flow #2 (Enrollment tasks) | 1 (ET-1) | 1 |
| **Total** | **11 bugs + 3 changes** | **all** |

---

## Files changed in Flow #2

- `Modules/Enrollment/RegistrationRepository.php` — `updateStatus(Cancelled)` clears `completion_tasks`
- `tests/manual/shake-flow-enrollment-tasks.php` — 18 scenarios for regression

## Next flows pending

- Flow #3: Cancellation cascade (quote, audit, attendance, mail downstream)
- Flow #4: Post-course completion + certificate flow
- Flow #5: Data exportability sweep (every column → at least one export sheet?)
