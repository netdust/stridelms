# Trajectory: Cascade Enrollment (parent → children)

**Date drafted:** 2026-05-20
**Author:** Stefan + Claude (Opus 4.7)
**Status:** Planned, not started
**Prerequisite for:** `plans/2026-05-20-trajectory-phased-choices.md`

---

## Goal

When a user enrolls in a trajectory, they should be automatically enrolled in the editions that make up that trajectory — both the mandatory ones (at enrollment time) and the elective ones (when they pick). Pure-LD courses (no edition) should get LD access plus a meta-marker showing the access came from a trajectory.

Today, a trajectory enrollment creates **one** registration row (`trajectory_id` set, `edition_id` null) and saves chosen edition-IDs as JSON in the `selections` column. **No child registration rows are created.** That means: edition admin doesn't see the user, attendance can't be tracked per edition, certificates don't flow, capacity isn't decremented. This plan closes that gap.

## Why this matters

This unblocks the phased-choices plan (`plans/2026-05-20-trajectory-phased-choices.md`) — phased choices is "half a feature" until cascade-enrollment exists, because making a fase-2 choice has nothing to react to.

It also fixes existing latent issues for any trajectory that's already live:
- Attendance tab on the edition shows no trajectory enrollees
- Certificate generation can't find the user via the edition
- Edition capacity counts don't include trajectory deelnemers (over-booking risk)
- LearnDash access for pure-LD courses in a trajectory never gets granted

---

## Decisions (locked in with Stefan, 2026-05-20)

| Topic | Decision |
|---|---|
| **Parent-child link** | New column `parent_registration_id BIGINT UNSIGNED NULL` on `wp_vad_registrations` |
| **Mandatory courses** | Children created immediately when parent registration is created |
| **Elective courses** | Children created in `setSelections()` for each chosen edition |
| **Pure-LD course (no edition)** | `grantAccess()` + user-meta `_stride_trajectory_courses` = `[{course_id, trajectory_id, parent_registration_id, granted_at}, …]`. No row in registrations table. |
| **Trajectory types** | Reuse existing `TrajectoryMode` enum (`cohort` / `self_paced`) — no new schema field |
| **Status inheritance** | `cohort` → children inherit parent status (cancel parent cascades). `self_paced` → children independent (parent cancel does nothing to children). |
| **Capacity on elective choice** | If the chosen edition is full, `setSelections()` returns `WP_Error('edition_full', …)`. |
| **Parent cancellation (cohort)** | Cascade-cancel children + `revokeAccess()` for pure-LD courses. The edition itself is untouched — only this user's enrollment in it. |
| **Parent cancellation (self_paced)** | No cascade. User can still cancel each child independently via normal channels. |
| **Children skip enrollment requirements** | Children created by the cascade bypass per-edition `requires_questionnaire`, `requires_documents`, `requires_approval`. Assumption: those gates were already satisfied at trajectory-enrollment level. `completion_tasks` on children = empty array. |
| **Child quote** | Default: `quote_id = NULL` on children — parent trajectory quote covers everything. EXCEPTION: when trajectory price = €0 AND child edition price > €0, cascade auto-generates a child quote inheriting the parent's billing info. |
| **Payment never blocks enrollment** | Quotes are invoices, not gates. Capacity reservation happens at child creation, regardless of payment state. Access-denial for unpaid children is a manual admin decision (not enforced by cascade). |

---

## Data model changes

### 1. New column on `wp_vad_registrations`

```sql
parent_registration_id BIGINT UNSIGNED NULL,
INDEX idx_parent (parent_registration_id)
```

Semantics:
- `parent_registration_id IS NULL` on rows that are NOT children of a trajectory (covers all existing rows, plus future direct edition + parent trajectory rows).
- `parent_registration_id = <parent.id>` on rows created by the cascade. These rows have `edition_id` set, `trajectory_id` NULL, `enrollment_path = 'trajectory'`.

The parent row keeps its current shape: `trajectory_id` set, `edition_id` NULL, `enrollment_path = 'trajectory'`, `parent_registration_id` NULL.

Existing trajectories with `selections` JSON keep working (we render from selections OR child rows during migration — see "Migration" below).

### 2. New user meta key `_stride_trajectory_courses`

Stored as a serialized array (WP default). One entry per pure-LD-via-trajectory grant:

```php
[
    [
        'course_id' => 123,
        'trajectory_id' => 7,
        'parent_registration_id' => 100,
        'granted_at' => '2026-05-20 14:32:11',
    ],
    …
]
```

Cleared individually on cascade-cancel (find entry with matching `parent_registration_id`, remove).

### 3. No changes to TrajectoryCPT meta schema

`mode` already exists. No new fields.

---

## Files to change

### Core cascade

#### `Modules/Enrollment/RegistrationTable.php`
- Add `parent_registration_id BIGINT UNSIGNED NULL` and `INDEX idx_parent (parent_registration_id)` to the `CREATE TABLE` statement.
- `dbDelta` handles the ALTER on existing databases.

#### `Modules/Enrollment/RegistrationRepository.php`
- Add `'parent_registration_id'` to the allowed-fields list in `create()` and `update()`.
- New `findByParent(int $parentRegistrationId): array` — returns child rows.
- New `cancelChildren(int $parentRegistrationId): int` — bulk-cancel all children of a parent (returns count cancelled). Updates `status` to `cancelled`, sets `cancelled_at`.

#### `Modules/Trajectory/TrajectoryCascadeService.php` (NEW)
The dedicated service for cascade logic. Single responsibility: given a parent registration + trajectory config, build/cancel the right children.

Public methods:

```php
public function cascadeOnEnrollment(int $parentRegistrationId): void
```
Called after a trajectory registration is created. For each course in the trajectory:
- If `required` AND has an edition (linked via `linked_editions` or course->edition map) → create child registration on that edition. Child row is created with `completion_tasks = []` (bypassing per-edition questionnaire/documents/approval — those were satisfied at the parent level). If the parent's trajectory price is €0 AND the edition's price > €0, also create a child quote inheriting the parent's billing info (see `maybeCreateChildQuote()` below).
- If `required` AND NO edition (pure LD) → call `$lms->grantAccess($userId, $courseId)` and append to user-meta `_stride_trajectory_courses`. No quote (no edition to bill against).
- If elective → skip (handled in `cascadeOnSelection`).
- Idempotent: if a child for this `(parent, edition_id)` already exists, skip.

```php
public function cascadeOnSelection(int $parentRegistrationId, array $editionIds): WP_Error|true
```
Called from `TrajectorySelection::setSelections()` after the JSON selections are saved. For each chosen edition:
- Capacity check via `EditionService::hasCapacity()` — if full, return `WP_Error('edition_full', "Editie X is volzet", ['edition_id' => $id])`. No rollback needed because selections JSON is already saved; user gets the error and can pick another. (Open question: should we roll back the JSON too? See Risks.)
- Skip if a child for this `(parent, edition_id)` already exists (re-edit of selections, same edition kept).
- Remove children whose edition is no longer in `editionIds` (user changed their mind before lock). For removed children that had a generated child-quote, also cancel that quote.
- For each new edition: create child registration (`edition_id = $id, parent_registration_id = $parent, trajectory_id = NULL, enrollment_path = 'trajectory', user_id = parent.user_id, company_id = parent.company_id, status = parent.status, completion_tasks = []`).
- For each new edition: call `maybeCreateChildQuote()` (see below).

```php
public function cascadeOnCancellation(int $parentRegistrationId): void
```
Called when a trajectory registration is cancelled. Behaviour depends on `TrajectoryMode`:
- `cohort`: cancel all children via `cancelChildren()`, then for each pure-LD entry in `_stride_trajectory_courses` linked to this parent → `revokeAccess()`, then remove the entry.
- `self_paced`: no-op. Children stay enrolled; user manages them individually.

```php
public function cascadeOnStatusChange(int $parentRegistrationId, string $newStatus): void
```
Called when parent status changes (e.g. pending → confirmed after admin approval).
- `cohort`: propagate `newStatus` to all children.
- `self_paced`: no-op (children have independent status).

```php
private function maybeCreateChildQuote(int $childRegistrationId, int $parentRegistrationId): void
```
Internal helper. Decides whether the child needs its own quote:
- Load parent registration, its trajectory, and the child's edition.
- If trajectory price > 0 → return early (parent quote covers everything).
- If trajectory price = 0 AND edition price = 0 → return early (nothing to bill).
- If trajectory price = 0 AND edition price > 0 → call `QuoteService->create()` for this child:
  - User: parent.user_id
  - Billing info: copied from parent's quote (or from `enrollment_data` if parent quote doesn't exist)
  - Items: single line for this edition's price
  - Link: `quote_id` on the child registration
- Quotes do NOT block enrollment. The child is created and confirmed regardless; the quote is for admin/billing follow-up.

#### `Modules/Trajectory/TrajectorySelection.php`
- In `enroll()`: after `$registrationId = $this->registrations->create($data)`, call `ntdst_get(TrajectoryCascadeService::class)->cascadeOnEnrollment($registrationId)`.
- In `setSelections()`: after `$this->registrations->setSelections(...)` succeeds and BEFORE `do_action('stride/trajectory/choices_updated', …)`, call `$cascade->cascadeOnSelection($registrationId, $editionIds)`. If it returns `WP_Error`, return that error (do NOT fire the action — keeps it consistent with "selection failed").
- Document in PHPDoc: setSelections is now both "save selections JSON" AND "create child registrations".

#### `Modules/Enrollment/EnrollmentService.php`
- Find the cancellation paths (`cancel(int $registrationId)`) — after the existing parent-cancel logic, if the registration is a trajectory parent (`trajectory_id IS NOT NULL && parent_registration_id IS NULL`), call `cascadeOnCancellation($registrationId)`.
- Find the status-change paths (e.g. `confirm()`, `approve()`) — after parent status change, call `cascadeOnStatusChange($registrationId, $newStatus)`.

### Service registration

#### `web/app/mu-plugins/stride-core/plugin-config.php`
Add `\Stride\Modules\Trajectory\TrajectoryCascadeService::class` to `services`.

### Reads (so child rows are visible everywhere)

#### `Modules/Edition/Admin/EditionAdminController.php` (enrolled-users list)
Should automatically pick up child rows because they have `edition_id` set. **Verify** during shake-out — no code change expected.

#### `Modules/Edition/SessionRepository.php` / attendance queries
Same — child rows have `edition_id`, attendance should find them. Verify during shake-out.

#### `Modules/Trajectory/TrajectoryDashboardService.php`
When showing the user's trajectory progress, prefer reading from child rows (now authoritative for "is this user on this edition?") rather than from `selections` JSON. The JSON stays as the "what did the user pick" record; the child rows are the "what are they actually enrolled in" record. Update the dashboard logic to JOIN children for status display.

#### `Modules/User/UserDashboardService.php`
- Filter out child registrations from the "my enrollments" list — they should NOT appear as standalone enrollments alongside their parent. Either: (a) skip rows where `parent_registration_id IS NOT NULL`, OR (b) show them nested under the parent trajectory card. Decision: **(a) skip in the flat list** (v1). Nested rendering is polish for later.
- Pure-LD courses granted via trajectory: read from `_stride_trajectory_courses` user meta, render alongside the parent trajectory card.

#### `Modules/PartnerAPI/PartnerAPIController.php`
- `setSelections()` call already exists for partner-driven enrollment (around line 594). With this plan it gets the cascade for free, BUT: capacity-block via `WP_Error` is new behaviour for partners. Make sure the API returns 409 (or appropriate error code) with the `edition_full` error info instead of 500. Verify in tests.
- Listing endpoints (users, enrollments): same flat-list question. Decision: API returns the parent + a nested `child_registrations: [...]` array for trajectory enrollments. Document in API contract update.

### Tests

#### `tests/Unit/TrajectoryCascadeServiceTest.php` (NEW)
- Mandatory course with edition → child row created
- Mandatory course without edition (pure LD) → grantAccess called + meta updated
- Elective course in selections → child row created
- Elective edition full → setSelections returns WP_Error, no child created
- Selection removed → child cancelled (not deleted)
- Cohort cancel parent → all children cancelled + LD access revoked
- Self-paced cancel parent → no change to children
- Idempotency: calling cascadeOnEnrollment twice doesn't duplicate children
- Children have `completion_tasks = []` (no questionnaire/documents/approval inherited)
- Paid trajectory (>€0) + paid child → child has `quote_id = NULL`
- Free trajectory (€0) + paid child → child has its own `quote_id`, inherited billing
- Free trajectory + free child → no child quote
- Removed elective (re-edit) with generated quote → quote cancelled too

#### `tests/Integration/TrajectoryCascadeIntegrationTest.php` (NEW)
- Full flow: create cohort trajectory with 2 mandatory editions + 1 elective group (3 courses, pick 1). Enroll user. Verify: 2 child rows for mandatory. Set selection. Verify: 3rd child row appears. Cancel parent. Verify: all 3 children cancelled.
- Self-paced variant: same setup, cancel parent, verify children stay confirmed.
- Mixed: course in trajectory has no edition. Verify user meta + LD access.

#### `tests/manual/shake-cascade.php` (NEW)
A test script that seeds a trajectory with each mode, walks the full flow, asserts at each step, and prints a pass/fail report. Same shape as existing `tests/manual/shake-*.php`. Run from CLI to verify in real DDEV before shipping.

---

## Migration (existing data)

There are two questions about existing data:

### a) Existing trajectory enrollments with `selections` JSON

These have a parent row, no children, but the JSON contains edition IDs. Two options:

1. **Lazy migration** — leave existing data alone. The user's selections JSON keeps working for "what did they pick". New enrollments get the cascade. Read paths (UserDashboardService, etc.) fall back to JSON when no children exist for this parent.
2. **Backfill on deploy** — a one-time WP-CLI script `wp stride trajectory backfill-cascade` that walks all confirmed trajectory registrations, reads their selections JSON, creates child rows + LD access.

**Recommended: backfill.** A consistent data model is worth the one-time script. The fallback in read paths makes the system more complex forever.

### b) `_ntdst_` meta key for `parent_registration_id`?

No — this is a column on the registrations table, not post meta. Nothing to migrate post-side.

---

## Risks & open questions

1. **`setSelections` re-edit before lock.** If a user already picked edition A and changes to edition B before locking, the cascade should: cancel child for A, create child for B. The plan handles this via "remove children whose edition is no longer in editionIds". **Edge case**: what if A is already partially attended (impossible normally, since selections lock at first session — but verify)? **Decision**: trust the existing `lockSelections` gate; we only cascade when not locked.

2. **Capacity check race condition.** Two users picking the last spot simultaneously. Both pass capacity check, both create child. **Mitigation**: do the capacity check + insert inside a transaction, with `SELECT … FOR UPDATE` on the edition's confirmed-count. Add this in the implementation step; not blocking design.

3. **Selections JSON vs child rows — which is source of truth?** After this plan, child rows are authoritative for "user is enrolled in edition X". Selections JSON becomes a historical record of "what the user picked". Document this clearly. Future cleanup: stop reading from selections JSON anywhere except for the lock-state UI; rewrite that to query children instead. Out of scope for v1.

4. **Self-paced trajectories without `setSelections`.** Self-paced today: user picks editions one at a time, each through normal edition enrollment. Question: should self-paced still use the cascade path (parent + children with `parent_registration_id`), or should self-paced enrollments be standalone edition rows that just happen to have `trajectory_id` set? **Decision**: keep self-paced flowing through `setSelections` if the admin pre-configured the elective group, OR through direct edition enrollment if they pick from the catalogue. Either way, when a self-paced user enrolls in an edition that is part of their trajectory, set `parent_registration_id` so the relationship is visible. The cancellation rules still differ by mode.

5. **Quote-lock per child?** No — children inherit nothing financial. The parent trajectory's quote covers everything; children have `quote_id = NULL`. Edition-level admin sees the user but knows from `parent_registration_id` that this is a trajectory enrollment, not a paid-direct one.

6. **Reporting/exports.** `EditionRegistrationExporter` may double-count trajectory parents + children if a future feature exports trajectories too. For now, parents don't have `edition_id`, so existing edition-scoped exports already exclude them. Children show up under their edition with `enrollment_path = 'trajectory'` — that's the desired behaviour.

7. **Bypass of per-edition enrollment requirements.** Children created by the cascade get `completion_tasks = []` — they don't go through their edition's questionnaire/documents/approval flow. Assumption: those gates were satisfied at trajectory enrollment. **Edge case**: if admin configures both a trajectory-level questionnaire AND per-edition questionnaires that ask different things, the per-edition ones are silently skipped. **Decision**: trust the admin to configure requirements at the right level. Document this clearly in the admin UI ("Inschrijving via traject slaat per-editie vereisten over.").

8. **Trajectory price changes mid-flight.** If admin changes trajectory price from >€0 to €0 after enrollments exist, existing children don't retroactively get quotes. New children created after the change do. **Decision**: don't backfill on price change. Document.

9. **Billing info for child quotes when parent has no quote yet.** Rare but possible — parent enrollment failed quote creation but cascade still ran (current code rolls back the enrollment on quote failure, so this shouldn't happen, but defensive fallback: read billing from `enrollment_data` JSON on parent registration).

---

## Execution order

Each step independently verifiable:

1. **Schema** — add `parent_registration_id` column + index. Verify via `wp db query "DESCRIBE wp_vad_registrations"`.
2. **Repository methods** — `findByParent`, `cancelChildren`, allowed-fields whitelist. Unit tests pass.
3. **TrajectoryCascadeService skeleton** — empty methods, registered in plugin-config.
4. **`cascadeOnEnrollment`** for mandatory editions (no electives, no pure-LD yet). Unit + integration tests pass.
5. **`cascadeOnEnrollment`** pure-LD branch (user meta + grantAccess). Tests pass.
6. **`cascadeOnSelection`** with capacity check + add/remove children. Tests pass.
7. **`cascadeOnCancellation`** for cohort. Tests pass.
8. **`cascadeOnStatusChange`** for cohort. Tests pass.
9. **Wire into `TrajectorySelection`** (enroll + setSelections). Integration tests pass.
10. **Wire into `EnrollmentService`** (cancel + status change). Integration tests pass.
11. **Read paths** — `UserDashboardService` skips children in flat list, renders pure-LD from meta. `TrajectoryDashboardService` reads from children for status.
12. **Backfill script** — `wp stride trajectory backfill-cascade`. Dry-run mode first. Tested on a copy of staging DB.
13. **PartnerAPI integration** — verify endpoints handle 409 on edition_full, return nested children. Update API doc.
14. **Manual shake-out** via `tests/manual/shake-cascade.php`. Both modes, both enrollment paths, full lifecycle.
15. **Acceptance test** (Cest) covering one end-to-end cohort + one self-paced flow.

---

## Definition of done

- [ ] `parent_registration_id` column exists, indexed, and is populated on cascade
- [ ] Mandatory editions get a child row when user enrolls in trajectory
- [ ] Pure-LD mandatory courses: user meta entry + LD access granted
- [ ] Elective choice creates child row via `setSelections`
- [ ] Full elective edition blocks the choice with a clear error
- [ ] Re-edit of selections cancels removed children, adds new ones
- [ ] Cohort cancel cascades to all children + revokes LD access
- [ ] Self-paced cancel does NOT touch children
- [ ] Status changes on cohort parent propagate to children
- [ ] Children have empty `completion_tasks` (no questionnaire/documents/approval per child)
- [ ] Paid trajectory: children have no quote
- [ ] Free trajectory + paid child: child gets auto-generated quote with parent's billing
- [ ] Free trajectory + free child: no quote
- [ ] UserDashboard does not show children as standalone enrollments
- [ ] Edition admin lists trajectory enrollees alongside direct enrollees
- [ ] Existing trajectory enrollments backfilled (or fallback-read works, depending on Risk 3 decision)
- [ ] PartnerAPI behaves correctly (409 on edition_full, nested children in responses)
- [ ] Unit + integration + manual + acceptance tests green
- [ ] One real trajectory of each mode tested end-to-end in DDEV

---

## What this plan deliberately does NOT do

- Add a "phases" concept to trajectory (that's the next plan: `phased-choices.md`)
- Build per-child quotes or payment splits
- Add trajectory-level capacity awareness across multiple editions
- Add a UI for admins to see the parent→children tree (read paths use `parent_registration_id` internally; UI can be added later if needed)
- Refactor `selections` JSON away entirely — kept as historical "what did the user pick" record
- Touch the questionnaire/documents/approval task flow for trajectory enrollments — orthogonal
