# Enrollment Completion System — Design

**Date:** 2026-02-27
**Status:** Approved

## Summary

After enrollment, users may need to complete additional tasks before their registration is confirmed. The system tracks these tasks as JSON on the registration row, keeps the enrollment in `Pending` status until all tasks are done, and provides a dashboard checklist UX for users to complete their requirements.

## Design Decisions

- **Reuse `Pending` status** — no new registration statuses. Pending means "tasks remain" or "awaiting approval."
- **Requirements configured per edition/trajectory** — simple boolean meta fields toggled in the admin metabox.
- **Task tracking as JSON on registration** — `completion_tasks` column on `wp_vad_registrations`. No extra tables.
- **Dashboard checklist UX** — enrollment card shows task progress, links to a focused completion page.
- **Quotes deferred** — no quote created until registration transitions to `Confirmed`.

## Data Model

### Edition/Trajectory Meta

| Meta key | Type | Default | Description |
|----------|------|---------|-------------|
| `_stride_requires_session_selection` | bool | false | User must pick sessions from slots |
| `_stride_requires_questionnaire` | bool | false | User must fill in assigned field groups |
| `_stride_requires_documents` | bool | false | User must upload required documents |
| `_stride_requires_approval` | bool | false | Admin must manually approve |

### Registration Table — New Column

`completion_tasks` JSON column on `wp_vad_registrations`:

```json
{
  "session_selection": { "status": "completed", "completed_at": "2026-02-27T14:00:00" },
  "questionnaire": { "status": "pending", "data": {} },
  "documents": { "status": "pending", "files": [] },
  "approval": { "status": "pending" }
}
```

Task statuses: `pending` | `completed` | `skipped`

### Flow Logic

- Requirements enabled → enrollment starts as `Pending` with `completion_tasks` JSON.
- No requirements → enrollment starts as `Confirmed` (unchanged behavior).
- All non-approval tasks `completed` AND `needs_approval` is false → auto-confirm.
- All non-approval tasks `completed` AND `needs_approval` is true → stays `Pending`, admin sees "Klaar voor goedkeuring" badge.
- Quotes created only when status transitions to `Confirmed`.

## Service Architecture

### New: `EnrollmentCompletionService`

Location: `Modules/Enrollment/EnrollmentCompletionService.php`

```
EnrollmentCompletionService
├── initializeTasksForRegistration(int $regId, int $postId, string $postType)
│   → Reads requirements from edition/trajectory meta
│   → Writes completion_tasks JSON to registration
│
├── getTaskStatus(int $regId): array
│   → Returns completion_tasks with computed overall progress
│
├── completeTask(int $regId, string $taskType, array $data = []): bool|WP_Error
│   → Marks task completed, stores data
│   → Checks if all tasks done → auto-confirms if no approval needed
│   → Fires stride/enrollment/task_completed action
│
├── isComplete(int $regId): bool
│   → All tasks completed (excluding approval if applicable)
│
├── getRequirements(int $postId, string $postType): array
│   → Which requirements are enabled for an edition/trajectory
│
└── getPendingTasksForUser(int $userId): array
    → All registrations with incomplete tasks (powers dashboard)
```

### Modified Services

- **`EnrollmentService::enroll()`** — if requirements exist, set status to `Pending` and call `initializeTasksForRegistration()`.
- **`EnrollmentQuoteHandler::onRegistrationCreated`** — skip quote creation for `Pending` registrations with incomplete tasks. Create quote when registration transitions to `Confirmed` instead.
- **Listen on `stride/registration/confirmed`** — create quotes for previously-pending registrations.

## User-Facing UX

### Dashboard Checklist

Enrollment card in "Mijn Inschrijvingen" expands for `Pending` registrations:

```
┌─────────────────────────────────────────────────────┐
│ Cannabis & Farmacologie — Editie Maart 2026          │
│ Actie vereist                                        │
│                                                       │
│ Inschrijving voltooien:                              │
│  [done]  Sessies kiezen           (2 van 2 gekozen)  │
│  [todo]  Vragenlijst invullen     → Invullen          │
│  [todo]  Documenten uploaden      → Uploaden          │
│  [lock]  Goedkeuring beheerder    (wacht op taken)    │
│                                                       │
│ ━━━━━━━━━━━━━░░░░░░░░  25% voltooid                 │
└─────────────────────────────────────────────────────┘
```

### Completion Page

Route: `/vormingen/{slug}/voltooien/` or `/trajecten/{slug}/voltooien/`

Lightweight page showing pending tasks as collapsible cards. Each task is independently completable:

- **Session selection** — reuses existing session picker UI.
- **Questionnaire** — renders assigned field groups (from Formuliervelden settings page). Answers stored in `completion_tasks.questionnaire.data`.
- **Documents** — file upload via WordPress media library. Attachment IDs stored in `completion_tasks.documents.files`.
- **Approval** — not actionable by user. Shows lock icon until other tasks done, then "Wacht op goedkeuring beheerder."

## Admin Side

### Metabox: "Inschrijfvereisten"

Added to edition and trajectory admin screens. Four checkboxes:

```
Inschrijfvereisten
[ ] Sessiekeuze vereist
[ ] Vragenlijst invullen
[ ] Documenten uploaden
[ ] Goedkeuring beheerder
```

### Admin Dashboard

- Pending registrations with all user tasks done → "Klaar voor goedkeuring" badge.
- One-click approve button → `EnrollmentService::confirmRegistration()`.
- Bulk approve for multiple registrations.
- View questionnaire answers and uploaded documents inline.
- Override: manually confirm even if tasks aren't complete.

## Scope

### In scope (v1)

- `completion_tasks` JSON column on registrations table
- `EnrollmentCompletionService`
- Four requirement toggles on edition/trajectory admin
- Dashboard checklist UI with progress bar
- Completion page for session selection, questionnaire, document upload
- Admin approval flow in Stride dashboard
- Questionnaire uses existing field groups system

### Not in scope (future)

- Automated email reminders (ntdst-mail plugin)
- Deadline enforcement (auto-cancel if not completed by date X)
- Custom task types beyond the four built-in ones
- Payment as a completion step
