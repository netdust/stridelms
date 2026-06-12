# Post-Course Completion Tasks

**Date:** 2026-03-09
**Status:** Approved

## Problem

After an in-person course ends and attendance is recorded, users may need to complete follow-up tasks: an evaluation questionnaire, document uploads, or admin approval. Currently there's no mechanism for this — only pre-enrollment completion tasks exist.

## Decision

Extend the existing `EnrollmentCompletion` system with a `phase` concept. Same task types, same storage, same handler, same UI — but tasks are tagged as either `enrollment` or `post_course` phase.

## Data Model

### Task JSON (on registration.completion_tasks)

```json
{
  "questionnaire": { "status": "completed", "phase": "enrollment", "completed_at": "..." },
  "documents": { "status": "pending", "phase": "enrollment" },
  "post_evaluation": { "status": "locked", "phase": "post_course" },
  "post_documents": { "status": "locked", "phase": "post_course" },
  "post_approval": { "status": "locked", "phase": "post_course" }
}
```

- Enrollment-phase tasks: unlock at registration (existing behavior)
- Post-course-phase tasks: locked until `EditionCompletion::isComplete()` returns true
- Post-course task types use `post_` prefix: `post_evaluation`, `post_documents`, `post_approval`

### Edition Meta Fields

New fields in `EditionCPT::getFields()`:

| Field | Type | Purpose |
|-------|------|---------|
| `post_requires_evaluation` | boolean | Evaluation questionnaire after course |
| `post_requires_documents` | boolean | Document upload after course |
| `post_requires_approval` | boolean | Admin sign-off after course |

### Status Flow

```
pending     → enrollment tasks needed (existing)
confirmed   → enrolled, attending course (existing)
confirmed   → + has post-course tasks: "Rond af" state
completed   → all done, certificate available
```

Key change: `confirmed` registrations can now have `completion_tasks` — not just `pending` ones.

## Trigger & Flow

### When do post-course tasks initialize?

At attendance completion, not at enrollment:

1. Instructor marks attendance → `EditionCompletion::onAttendanceMarked()`
2. If `isComplete()` returns true → fire `stride/completion/attendance_complete`
3. Listener calls `EnrollmentCompletion::initializePostCourseTasks()`
4. Adds `post_evaluation`, `post_documents`, `post_approval` to existing `completion_tasks` JSON
5. Triggers email: "Je opleiding is afgelopen, rond je dossier af"

### Certificate Deferral

- Attendance met + NO post-course tasks → mark LD complete immediately (current behavior)
- Attendance met + HAS post-course tasks → initialize tasks, defer LD completion
- All post-course tasks complete → mark LD complete → certificate unlocks → status = `completed`

### Auto-completion

Same `CompletionTaskHandler` pattern:
- All enrollment tasks done → confirm registration (existing)
- All post-course tasks done → mark LD complete + set status to `completed`

## Routes & UI

### Route

Reuse `/vormingen/{slug}/voltooien/`:
- Pending registration + enrollment tasks → "Inschrijving voltooien" (existing)
- Confirmed registration + post-course tasks → "Opleiding afronden" (new)
- Router finds both `pending` and `confirmed` registrations with incomplete tasks

### Dashboard "Acties" Section

New section at top of "Mijn opleidingen", before "Komende sessies":

```
┌──────────────────────────────────────────────────┐
│ ⚠ Acties                                         │
│                                                  │
│ Bijscholing Ambulante — Voltooi inschrijving   → │
│ Erkenningstraject — Rond opleiding af          → │
└──────────────────────────────────────────────────┘
```

- All registrations with pending tasks (both phases)
- Each links to `/voltooien/`
- Disappears when no actions pending
- Data from extended `EnrollmentCompletion::getPendingForUser()`

### Badge

New `completing` status: "Rond af" with alert icon.

## Email

Register `stride/completion/attendance_complete` trigger in mail plugin. Admins configure email template via mail template CPT.

SmartCodes: `{user.first_name}`, `{course.title}`, `{edition.title}`, `{dashboard.url}`

## Scope

### In
- Phase field on tasks (`enrollment` / `post_course`)
- Edition meta fields + admin metabox for post-course config
- Initialize post-course tasks when attendance threshold met
- Defer LD completion when post-course tasks exist
- Auto-complete registration when all post-course tasks done
- Dashboard "Acties" section
- Phase-aware `/voltooien/` page
- `completing` badge status
- Mail trigger for attendance complete
- Seed data for testing

### Out (future)
- Admin review UI for submitted documents/evaluations
- Additional mail triggers (enrollment confirmed, etc.)
