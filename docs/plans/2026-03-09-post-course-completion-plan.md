# Post-Course Completion Tasks — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow users to complete post-course tasks (evaluation, documents, approval) after attendance is met, deferring certificate until all tasks are done.

**Architecture:** Extend `EnrollmentCompletion` with a `phase` field on tasks. Post-course tasks are initialized when attendance threshold is met. `CompletionTaskHandler` auto-completes registration when all post-course tasks are done. Reuse existing `/voltooien/` route and UI with phase-aware rendering.

**Tech Stack:** PHP 8.3 (Stride mu-plugin), Alpine.js (frontend), NTDST Data Manager, WordPress hooks

**Design doc:** `docs/plans/2026-03-09-post-course-completion-design.md`

---

## Phase 1: Data Model & Business Logic

### Task 1: Add phase support to EnrollmentCompletion

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php`
- Test: `tests/Unit/EnrollmentCompletionTest.php`

**Context:** Currently tasks have no phase. Add `phase` field to task JSON and new constants/methods for post-course tasks.

**Step 1: Write failing tests**

```php
// tests/Unit/EnrollmentCompletionTest.php — add these test methods

public function test_build_post_course_tasks_returns_phase_field(): void
{
    // Arrange: edition with post_requires_evaluation + post_requires_documents
    // Act: $completion->buildPostCourseTasks($editionId, 'vad_edition')
    // Assert: tasks have phase='post_course', keys are 'post_evaluation', 'post_documents'
}

public function test_post_course_tasks_locked_when_attendance_not_met(): void
{
    // Arrange: tasks with post_course phase, edition not complete
    // Act: $completion->getTaskAvailability($tasks, $editionId)
    // Assert: post_course tasks have state='locked'
}

public function test_post_course_tasks_available_when_attendance_met(): void
{
    // Arrange: tasks with post_course phase, edition complete
    // Act: $completion->getTaskAvailability($tasks, $editionId)
    // Assert: post_evaluation and post_documents have state='available'
}

public function test_is_enrollment_phase_complete(): void
{
    // Arrange: all enrollment-phase tasks completed
    // Act: $completion->isEnrollmentPhaseComplete($tasks)
    // Assert: true
}

public function test_is_post_course_phase_complete(): void
{
    // Arrange: all post_course-phase tasks completed
    // Act: $completion->isPostCoursePhaseComplete($tasks)
    // Assert: true
}
```

**Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --filter=EnrollmentCompletionTest --testsuite Unit`
Expected: FAIL — methods don't exist yet

**Step 3: Implement changes to EnrollmentCompletion**

Add new constants:
```php
private const POST_COURSE_TASK_TYPES = ['post_evaluation', 'post_documents', 'post_approval'];

private const POST_COURSE_META_KEYS = [
    'post_evaluation' => 'post_requires_evaluation',
    'post_documents'  => 'post_requires_documents',
    'post_approval'   => 'post_requires_approval',
];
```

Update `TASK_TYPES` to include post-course types. Update `META_KEYS` to merge both.

Add methods:
- `getPostCourseRequirements(int $postId, string $postType): array` — reads post_requires_* meta
- `hasPostCourseRequirements(int $postId, string $postType): bool`
- `buildPostCourseTasks(int $postId, string $postType): array` — returns tasks with `phase => 'post_course'`
- `initializePostCourseTasks(int $registrationId, int $editionId): void` — appends post-course tasks to existing completion_tasks JSON
- `isEnrollmentPhaseComplete(array $tasks): bool` — checks only enrollment-phase tasks
- `isPostCoursePhaseComplete(array $tasks): bool` — checks only post_course-phase tasks
- `getTasksForPhase(array $tasks, string $phase): array` — filter helper

Update `buildInitialTasks()` to add `'phase' => 'enrollment'` to each task.

Update `getTaskAvailability()` to handle post-course tasks:
- `post_evaluation` and `post_documents`: available when attendance met, locked otherwise
- `post_approval`: locked until post_evaluation + post_documents done

Update `completeTask()` to accept post-course task types in validation.

**Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter=EnrollmentCompletionTest --testsuite Unit`
Expected: PASS

**Step 5: Commit**

```
feat: add phase support to EnrollmentCompletion for post-course tasks
```

---

### Task 2: Add edition meta fields for post-course config

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionActionsMetabox.php`

**Step 1: Add fields to EditionCPT::getFields()**

Add after the existing `requires_*` fields:
```php
'post_requires_evaluation' => [
    'type' => 'boolean',
    'label' => 'Evaluatie vereist na afloop',
],
'post_requires_documents' => [
    'type' => 'boolean',
    'label' => 'Documenten vereist na afloop',
],
'post_requires_approval' => [
    'type' => 'boolean',
    'label' => 'Goedkeuring vereist na afloop',
],
```

**Step 2: Add admin UI to EditionActionsMetabox**

Add a "Na afloop" (After completion) section below the existing enrollment requirements checkboxes. Three checkboxes for post-course tasks, similar to the enrollment ones.

**Step 3: Verify in browser**

Visit an edition admin page, check that the new checkboxes appear and save correctly.

**Step 4: Commit**

```
feat: add edition meta fields for post-course completion config
```

---

### Task 3: Modify attendance trigger to initialize post-course tasks

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionCompletion.php`
- Modify: `web/app/mu-plugins/stride-core/Handlers/CompletionTaskHandler.php`
- Test: `tests/Unit/EditionCompletionTest.php`

**Context:** When attendance threshold is met, check if post-course tasks are configured. If yes, initialize them instead of immediately marking LD complete.

**Step 1: Write failing test**

```php
// test that processCompletion defers LD completion when post-course tasks exist
public function test_process_completion_defers_when_post_course_tasks_exist(): void
{
    // Arrange: edition with post_requires_evaluation=true, attendance met
    // Act: processCompletion($editionId, $userId)
    // Assert: LD NOT marked complete, post-course tasks initialized on registration
}

public function test_process_completion_marks_ld_complete_when_no_post_course_tasks(): void
{
    // Arrange: edition with NO post-course requirements, attendance met
    // Act: processCompletion($editionId, $userId)
    // Assert: LD marked complete (existing behavior)
}
```

**Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --filter=EditionCompletionTest --testsuite Unit`

**Step 3: Modify EditionCompletion::processCompletion()**

Before calling `learndash_process_mark_complete()`, check:
```php
$enrollmentCompletion = ntdst_get(EnrollmentCompletion::class);
if ($enrollmentCompletion->hasPostCourseRequirements($editionId, 'vad_edition')) {
    // Find user's confirmed registration and initialize post-course tasks
    $repo = ntdst_get(RegistrationRepository::class);
    $reg = $repo->findByUserAndEdition($userId, $editionId);
    if ($reg) {
        $enrollmentCompletion->initializePostCourseTasks((int) $reg->id, $editionId);
        do_action('stride/completion/attendance_complete', [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'registration_id' => (int) $reg->id,
        ]);
    }
    return true; // Defer LD completion
}
```

**Step 4: Modify CompletionTaskHandler::onTaskCompleted()**

Currently auto-confirms when all tasks done. Add logic for post-course phase:
```php
// If all tasks are fully complete:
$reg = $repo->find($registrationId);

if ($completion->isFullyComplete($tasks)) {
    // Check what phase we're in
    $hasPostCourse = !empty(array_filter($tasks, fn($t) => ($t['phase'] ?? 'enrollment') === 'post_course'));

    if ($hasPostCourse) {
        // All post-course tasks done — mark LD complete + set status to completed
        $editionCompletion = ntdst_get(\Stride\Modules\Edition\EditionCompletion::class);
        $editionCompletion->processCompletionFinal((int) $reg->edition_id, (int) $reg->user_id);
        $repo->updateStatus($registrationId, 'completed');
    } else {
        // All enrollment tasks done — confirm registration (existing)
        $enrollmentService->confirmRegistration($registrationId);
    }
}
```

Add `processCompletionFinal()` to EditionCompletion — same as processCompletion but skips the post-course task check (it's already done).

**Step 5: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter=EditionCompletionTest --testsuite Unit`
Expected: PASS

**Step 6: Commit**

```
feat: defer LD completion when post-course tasks exist, auto-complete on task finish
```

---

### Task 4: Update getPendingForUser to include confirmed registrations

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php`

**Context:** `getPendingForUser()` currently only finds `pending` status registrations. Post-course tasks live on `confirmed` registrations.

**Step 1: Update query**

Change from:
```php
AND status = %s  -- (pending only)
```
To:
```php
AND status IN (%s, %s)  -- (pending, confirmed)
AND completion_tasks IS NOT NULL
```

Then filter in PHP: only return registrations where at least one task is not completed.

**Step 2: Commit**

```
fix: include confirmed registrations with post-course tasks in pending query
```

---

## Phase 2: Routes & UI

### Task 5: Make EnrollmentRouter phase-aware

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouter.php`

**Context:** `handleCompletionRoute()` currently only finds `pending` registrations. It needs to also find `confirmed` registrations with incomplete post-course tasks.

**Step 1: Update handleCompletionRoute()**

Change the registration lookup from only `pending` to:
1. First check for `pending` registration with tasks (enrollment phase)
2. If not found, check for `confirmed` registration with incomplete post-course tasks

Pass a `phase` variable to the template: `'enrollment'` or `'post_course'`.

**Step 2: Commit**

```
feat: make /voltooien/ route support both enrollment and post-course phases
```

---

### Task 6: Make completion page phase-aware

**Files:**
- Modify: `web/app/themes/stridence/forms/completion.php`

**Context:** The completion page template needs to show different header text based on phase.

**Step 1: Update template**

Add phase detection from template variables:
```php
$phase = 'enrollment'; // default
foreach ($tasks as $task) {
    if (($task['phase'] ?? 'enrollment') === 'post_course') {
        $phase = 'post_course';
        break;
    }
}
```

Conditionally render:
- Enrollment: "Inschrijving voltooien" / "Voltooi onderstaande stappen om je inschrijving te bevestigen."
- Post-course: "Opleiding afronden" / "Voltooi onderstaande stappen om je opleiding af te ronden."

Update task labels for post-course types:
```php
$task_labels['post_evaluation'] = __('Evaluatie invullen', 'stridence');
$task_labels['post_documents']  = __('Documenten uploaden', 'stridence');
$task_labels['post_approval']   = __('Goedkeuring beheerder', 'stridence');
```

Filter tasks to only show the active phase.

**Step 2: Add task partials for post-course types**

The `post_evaluation` type can reuse `task-questionnaire.php` template. The `post_documents` type can reuse `task-documents.php`. Map post-course types to existing templates:

```php
$template_map = [
    'post_evaluation' => 'task-questionnaire',
    'post_documents'  => 'task-documents',
];
$template = $template_map[$taskType] ?? 'task-' . $taskType;
get_template_part('templates/forms/completion/' . $template, null, [...]);
```

**Step 3: Commit**

```
feat: phase-aware completion page with post-course headers and labels
```

---

### Task 7: Add dashboard "Acties" section

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/User/UserDashboardService.php`
- Create: `web/app/themes/stridence/templates/dashboard/partials/action-items.php`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`

**Step 1: Add getActionItems() to UserDashboardService**

```php
/**
 * Get all pending action items for a user.
 *
 * @return array{label: string, url: string, type: string}[]
 */
public function getActionItems(int $userId): array
{
    $completion = ntdst_get(EnrollmentCompletion::class);
    $pending = $completion->getPendingForUser($userId);
    $items = [];

    foreach ($pending as $reg) {
        $editionId = (int) $reg->edition_id;
        $tasks = json_decode($reg->completion_tasks, true) ?: [];
        $hasIncomplete = false;
        $phase = 'enrollment';

        foreach ($tasks as $task) {
            if (($task['status'] ?? 'pending') !== 'completed') {
                $hasIncomplete = true;
                if (($task['phase'] ?? 'enrollment') === 'post_course') {
                    $phase = 'post_course';
                }
            }
        }

        if (!$hasIncomplete) continue;

        $course = get_post($this->editionService->getCourseId($editionId));
        $slug = get_post_field('post_name', $editionId);

        $items[] = [
            'course_title' => $course ? $course->post_title : get_the_title($editionId),
            'label' => $phase === 'post_course'
                ? __('Rond opleiding af', 'stride')
                : __('Voltooi inschrijving', 'stride'),
            'url' => home_url('/vormingen/' . $slug . '/voltooien/'),
            'type' => $phase,
        ];
    }

    return $items;
}
```

Add `action_items` to `getEnrollmentData()` return array.

**Step 2: Create action-items partial**

```php
// templates/dashboard/partials/action-items.php
<?php
$items = $args['items'] ?? [];
if (empty($items)) return;
?>
<section class="mb-8">
    <div class="card divide-y divide-border border-amber-200 bg-amber-50/50">
        <?php foreach ($items as $item) : ?>
            <a href="<?= esc_url($item['url']) ?>"
               class="p-4 flex items-center justify-between gap-4 hover:bg-amber-50 transition-colors">
                <div class="flex items-center gap-3">
                    <?= stridence_icon('alert-circle', 'w-5 h-5 text-amber-500 shrink-0') ?>
                    <div>
                        <span class="font-medium text-text"><?= esc_html($item['course_title']) ?></span>
                        <span class="text-sm text-text-muted ml-2"><?= esc_html($item['label']) ?></span>
                    </div>
                </div>
                <?= stridence_icon('chevron-right', 'w-5 h-5 text-text-muted shrink-0') ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
```

**Step 3: Add to tab-inschrijvingen.php**

Before the "Komende sessies" section, render:
```php
<?php
get_template_part('templates/dashboard/partials/action-items', null, [
    'items' => $data['action_items'],
]);
?>
```

**Step 4: Commit**

```
feat: add dashboard Acties section showing pending enrollment and post-course tasks
```

---

### Task 8: Add "completing" badge status

**Files:**
- Modify: `web/app/themes/stridence/partials/badge-status.php`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`

**Step 1: Add badge**

In badge-status.php:
```php
'completing' => ['class' => 'badge-few', 'label' => 'Rond af'],
```

Add alert icon for `completing` status (same as `action_required`).

**Step 2: Update dashboard badge logic**

In the klassikale opleidingen section, add check for confirmed + post-course tasks:
```php
$hasPostCourseTasks = !empty($reg['completion_tasks']) &&
    array_filter($reg['completion_tasks'], fn($t) =>
        ($t['phase'] ?? 'enrollment') === 'post_course' &&
        ($t['status'] ?? 'pending') !== 'completed'
    );

if ($reg['status'] === 'confirmed' && $hasPostCourseTasks) {
    get_template_part('partials/badge-status', null, ['status' => 'completing']);
}
```

**Step 3: Commit**

```
feat: add completing badge for registrations with pending post-course tasks
```

---

## Phase 3: Mail Trigger & Seed Data

### Task 9: Register mail trigger for attendance complete

**Files:**
- Modify: `web/app/mu-plugins/stride-core/stride-core.php`

**Step 1: Register trigger**

In stride-core.php, add filter:
```php
add_filter('ndmail_triggers', function (array $triggers): array {
    $triggers['stride/completion/attendance_complete'] = [
        'label'   => __('Opleiding: aanwezigheid voltooid', 'stride'),
        'source'  => 'Stride',
        'context' => ['user_id', 'edition_id', 'registration_id'],
    ];
    return $triggers;
});
```

**Step 2: Verify in mail admin**

Visit the mail template admin — the new trigger should appear in the dropdown.

**Step 3: Commit**

```
feat: register mail trigger for attendance complete event
```

---

### Task 10: Seed data with post-course tasks

**Files:**
- Modify: `scripts/seed.php`

**Step 1: Add post-course requirements to existing seeded editions**

Pick 1-2 existing seeded editions and add post-course meta:
```php
update_post_meta($editionId, '_ntdst_post_requires_evaluation', '1');
update_post_meta($editionId, '_ntdst_post_requires_documents', '1');
update_post_meta($editionId, '_ntdst_post_requires_approval', '1');
```

Create a registration for the admin user that is `confirmed` with attendance met, so the post-course tasks can be tested immediately after seeding.

**Step 2: Run seed**

```bash
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php' && ddev exec wp eval-file scripts/seed.php
```

**Step 3: Commit**

```
test: seed post-course completion tasks for manual testing
```

---

## Phase 4: Integration Testing & Verification

### Task 11: Integration test — full post-course flow

**Files:**
- Test: `tests/Integration/PostCourseCompletionTest.php`

**Scenarios:**

1. **Attendance met → tasks initialized:**
   - Create edition with post_requires_evaluation
   - Create confirmed registration
   - Mark attendance complete
   - Assert: registration has post_course tasks in completion_tasks JSON

2. **All post-course tasks complete → LD complete + status completed:**
   - Complete all post-course tasks
   - Assert: LD course marked complete
   - Assert: registration status = completed

3. **No post-course tasks → immediate LD completion (regression):**
   - Create edition WITHOUT post-course requirements
   - Mark attendance complete
   - Assert: LD marked complete immediately (existing behavior preserved)

Run: `ddev exec vendor/bin/phpunit --filter=PostCourseCompletion --testsuite Integration`

### Task 12: Manual smoke test

**Smoke Test Checklist:**

- [ ] Admin: Edit an edition → "Na afloop" section shows 3 checkboxes → save works
- [ ] Seed data: run seed → editions have post-course config
- [ ] Dashboard: admin user sees "Acties" section at top with "Rond opleiding af"
- [ ] Dashboard: expanded card shows "Rond af" badge for confirmed + post-course tasks
- [ ] Click "Rond opleiding af" → lands on /voltooien/ with "Opleiding afronden" header
- [ ] Complete evaluation → task marked done, progress updates
- [ ] Upload documents → task marked done
- [ ] All tasks done → status changes to completed, certificate available
- [ ] Mail admin: "Opleiding: aanwezigheid voltooid" trigger appears in dropdown
- [ ] Edition without post-course tasks: attendance met → immediate completion (no regression)

---

## Summary

| Phase | Tasks | What's verified |
|-------|-------|-----------------|
| 1: Data Model | Tasks 1-4 | Phase field, meta fields, attendance trigger, query updates |
| 2: Routes & UI | Tasks 5-8 | Phase-aware route, completion page, dashboard actions, badge |
| 3: Mail & Seed | Tasks 9-10 | Email trigger, test data |
| 4: Testing | Tasks 11-12 | Integration flow, manual verification |
