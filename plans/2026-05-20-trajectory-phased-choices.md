# Trajectory: Phased Choice Groups

**Date drafted:** 2026-05-20
**Author:** Stefan + Claude (Opus 4.7)
**Status:** Planned, not started
**Prerequisite:** This plan assumes the work from the 2026-05-20 trajectory-admin session is in place (sidebar parity via `OfferingSidebarPartial`, schema fields on `TrajectoryCPT`, compact group rows in `renderElectiveGroup`).

---

## Goal

A trajectory can require its participants to make choices **at multiple moments in time**, not just at enrollment. Each elective group becomes a mini "choice phase" with its own open-date and deadline. When a phase opens, an active participant sees a new completion task on their dashboard. After they pick, they're auto-enrolled into the chosen course(s).

This unlocks: cohort programmes where year-1 students pick a specialisation track in month 1, a verdieping in month 6, an eindfase in month 12.

## Why this is small (not a "shift")

Trajectory already has the infrastructure:

- `EnrollmentCompletion` builds task lists on a registration; works for trajectories via `repoFor`
- `TrajectoryService::isChoiceWindowOpen()` already checks `choice_available_date` + `choice_deadline` at trajectory level
- `UserDashboardService` renders pending tasks from registrations
- Admin metabox already groups courses into `elective_groups`

What's missing: per-group `opens_at` / `deadline` overrides, and a per-group task type (today there's one `session_selection`-like task per trajectory enrollment; we need one task **per group** that becomes available when that group opens).

## Out of scope (deferred)

- **Cascade enrollment** (parent trajectory registration → child edition/course registrations). The choice → "you've now chosen, you're enrolled" step is its own work — there's a separate question about whether enrollment is automatic at choice time or requires admin approval, and whether children inherit the parent's `paid` state.
- **Quote-lock per trajectory** — orthogonal.
- **"Phase" as a first-class container** (with title, description, ordering). Not needed if `opens_at` per group answers "which fase am I in." If product wants named phases later, we can add a derived "phase label" on the group, but data model stays flat.

---

## Data model changes

### `elective_group` entry — add two optional fields

Current shape (from `TrajectoryAdminController::handleSave`):

```php
[
    'name' => 'Specialisatie',
    'pick_count' => 2,
    'courses' => [...]
]
```

New shape:

```php
[
    'name' => 'Specialisatie',
    'pick_count' => 2,
    'courses' => [...],
    'opens_at' => '2026-09-01',   // NEW — optional, ISO date
    'deadline' => '2026-09-30',   // NEW — optional, ISO date
]
```

Both fields are optional. Semantics:

- `opens_at` empty → group is open from registration creation (fase 1)
- `opens_at` set → group is open from that date onwards
- `deadline` empty → group stays open indefinitely (or until trajectory-level `choice_deadline` if set)
- `deadline` set → group closes at that date

Trajectory-level `choice_available_date` / `choice_deadline` remain as the **default fallback** when the group has no `opens_at` / `deadline`. Existing trajectories keep working unchanged.

### Registration `tasks` JSON — add per-group task entries

Today, a trajectory enrollment can have a `session_selection` task. After this change it has `choice_group_<groupIndex>` tasks, one per group that's relevant to this user.

`tasks` shape becomes:

```json
{
  "questionnaire": { "status": "pending", "phase": "enrollment" },
  "choice_group_0": { "status": "pending", "phase": "enrollment", "group_index": 0 },
  "choice_group_1": { "status": "pending", "phase": "phase_2", "group_index": 1 }
}
```

The `phase` field is informational (used for sectioning in the dashboard); the source of truth for "is this task available now?" is the group's `opens_at`/`deadline` on the trajectory.

---

## Files to change

### 1. `Modules/Enrollment/EnrollmentCompletion.php`

- Add `buildInitialTrajectoryTasks(int $trajectoryId): array` — returns tasks for any `elective_group` that has no `opens_at` or `opens_at` already passed. These are the fase-1 choices created at registration time.
- Extend `getTaskAvailability()` to handle `choice_group_*` task keys: look up the group by `group_index`, evaluate `opens_at` + `deadline`, return `available` / `locked` / `completed`.
- Add `ensureLatentTasksForRegistration(int $registrationId): void` — for an existing registration, iterate the trajectory's elective_groups; for any group where `opens_at <= now` but no `choice_group_<idx>` task exists on the registration, create it. This is the "phase 2 opens" trigger.

### 2. `Modules/Enrollment/EnrollmentService.php`

Wherever a trajectory registration gets created today, call `buildInitialTrajectoryTasks` instead of (or alongside) `buildInitialTasks`. Find this in:

- `EnrollmentService::enrol(...)` — the path that takes `trajectory_id` (around line 363–457 today)

### 3. `Modules/Trajectory/TrajectoryService.php`

- `isChoiceWindowOpen(int $trajectoryId, ?int $groupIndex = null): bool` — if `groupIndex` given, check that group's `opens_at`/`deadline` first, fall back to trajectory-level fields. If null, keep current behaviour (trajectory-level only) for back-compat.
- Maybe also: `getOpenGroups(int $trajectoryId): array` — returns indices of groups whose window is currently open. Useful for the dashboard render + the latent-task ensure step.

### 4. `Modules/User/UserDashboardService.php`

- When loading a user's tasks, before rendering, call `EnrollmentCompletion::ensureLatentTasksForRegistration()` on each of their open trajectory registrations. **Lazy creation** — no cron needed. The task only "exists" once a user views their dashboard after the phase has opened, which is fine.
- Render `choice_group_*` tasks with a clear group name + deadline.
- Section by phase: tasks whose underlying group has `opens_at > registration_date` get a "Fase 2" heading.

### 5. `Modules/Trajectory/Admin/TrajectoryAdminController.php`

In `renderElectiveGroup()` (inside the `.stride-group-edit` panel), add two date fields:

```html
<div class="stride-field">
    <label>Opent op</label>
    <input type="date" name="ntdst_fields[elective_groups][{$index}][opens_at]" ...>
</div>
<div class="stride-field">
    <label>Deadline</label>
    <input type="date" name="ntdst_fields[elective_groups][{$index}][deadline]" ...>
</div>
```

Update `handleSave()` to persist both fields on each `elective_groups` entry. Update the summary header in the compact view to show "Fase: dd MMM" when `opens_at` is in the future (so admin sees the phase structure at a glance).

### 6. `Modules/Trajectory/TrajectoryCPT.php`

No schema change to the CPT meta — `elective_groups` is already stored inside `courses` as JSON. The new fields live inside that JSON. Just make sure `handleSave()` whitelists them on the entry sanitization step.

### 7. New unit test: `tests/Unit/TrajectoryPhasedChoicesTest.php`

Cover at minimum:

- Group with no `opens_at` → task built at registration
- Group with future `opens_at` → no task built at registration
- After `ensureLatentTasksForRegistration` when `opens_at <= now` → task appears
- Group with past `deadline` → task availability = `locked` with deadline-passed reason
- Trajectory-level fallback when group has no fields of its own

---

## UX details

### Admin metabox

In the compact group summary line, when `opens_at` is in the future, append a clear phase marker:

```
Specialisatie · Kies 2 · 5 cursussen · 📅 Opent 1 sep 2026   ✏️  🗑
```

Groups stay in their save-order. **No automatic phase grouping/ordering in the admin UI** — admin manages order manually, same as today. Optional later: a header that auto-labels "Fase 1 / 2 / 3" derived from `opens_at` ordering. Skip for v1.

In the expanded edit panel, the two new date fields slot between the existing `Kies` row and the course list:

```
Groepnaam: [_______]   Kies: [_]
Opent op:  [_______]   Deadline: [_______]

[course list]
[search/add]
```

### User dashboard

For an enrolled trajectory user with multiple phases, tasks render in two sections:

```
Inschrijfvereisten
  ☐ Vragenlijst invullen
  ☐ Kies je specialisatie (deadline 30 sep)        ← open now

Komt eraan
  Fase 2 opent op 1 jan 2027                       ← preview only, not actionable
  Fase 3 opent op 1 jun 2027
```

The "komt eraan" preview is optional v1.1 polish. v1 can simply skip rendering future tasks until they're created by the lazy ensure step.

---

## Risks & open questions

1. **Group-index identity.** The plan keys tasks by `group_index` (position in the array). If admin reorders or deletes groups, the index of existing tasks shifts. **Fix:** add an explicit `id` field (UUID or auto-increment) per group at save time, key tasks by that. Plan stays the same, but the migration matters — see step below.

2. **Existing trajectory enrollments.** Today's enrollments don't have phased tasks. After deploy, the lazy ensure step picks them up next time the user loads their dashboard. No backfill needed, but verify the lazy logic doesn't double-create.

3. **What does "completed" mean for a choice task?** Today's choice = user picked → tasks marked complete → enrollment in chosen courses kicks off. This plan assumes that pipeline exists somewhere. **Check before starting**: search for `markAsCompleted` calls on `session_selection`-like tasks and verify the same hook fires for `choice_group_*`. If the enrollment-in-chosen-course step is missing entirely, that's a separate piece of work (the deferred "cascade enrollment" item).

4. **Trajectory-level `choice_deadline` vs group-level.** Decide: is trajectory-level the absolute floor (no group can stay open longer), or just a default? Cleanest: group-level always wins if set; trajectory-level applies only when group is silent. Document this in `TrajectoryService::isChoiceWindowOpen()`.

5. **Migration of existing elective_groups.** Today's groups have no `opens_at`/`deadline`. They keep working as fase 1 (no `opens_at` → open from registration). Zero migration needed *unless* you adopt the "explicit `id` per group" suggestion in risk 1 — then a one-time `wp eval` to inject IDs into existing trajectory `courses` arrays.

---

## Execution order

Do it in this order, each step verifiable independently:

1. **Schema additions** in `handleSave()` — fields persist correctly (test by saving + reloading)
2. **Admin UI** — show + edit the new date fields
3. **`TrajectoryService::isChoiceWindowOpen()` per-group variant** + tests
4. **`EnrollmentCompletion::buildInitialTrajectoryTasks` + `ensureLatentTasksForRegistration`** + tests
5. **Wire into `EnrollmentService`** for new registrations
6. **Wire `ensureLatent…` into `UserDashboardService`** for existing registrations
7. **Dashboard rendering polish** — sectioning, deadline display
8. **Manual flow test**: create trajectory with 2 phases (one open, one future) → enroll a test user → verify fase-1 task visible, fase-2 not → move fase-2's `opens_at` to past → reload dashboard → verify fase-2 task appears
9. **Acceptance test** for the above flow (Cest) — optional but recommended given the test-infrastructure gap noted in the 2026-05-20 session

---

## Definition of done

- [ ] Admin can add `opens_at` + `deadline` to any elective group
- [ ] Group with future `opens_at` creates no task on enrollment
- [ ] When `opens_at` passes, next dashboard load creates the task
- [ ] Task respects `deadline` (becomes locked when past)
- [ ] Trajectory-level `choice_deadline` still works as fallback
- [ ] Existing trajectories (no phased data) still work as today
- [ ] Unit + acceptance tests green
- [ ] One real trajectory configured + tested end-to-end in DDEV

---

## What this plan deliberately does NOT do

- Add a separate "phases" container to trajectory schema
- Build a cron job
- Build the cascade-enrollment step (parent → children); that's its own plan
- Build trajectory quote-lock; orthogonal
- Visual unification of edition slots and trajectory groups (post-launch polish)
