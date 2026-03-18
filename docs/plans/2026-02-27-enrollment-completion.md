# Enrollment Completion System — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a post-enrollment completion system where users must complete tasks (session selection, questionnaire, document upload) before their enrollment is confirmed, with optional admin approval.

**Architecture:** New `EnrollmentCompletionService` tracks tasks as JSON on the registration row (`completion_tasks` column). Enrollments with requirements start as `Pending` and auto-confirm when all tasks are done. Requirements are configured per edition/trajectory via meta checkboxes.

**Tech Stack:** PHP 8.3, WordPress, Alpine.js, Tailwind CSS, NTDST framework

**Design doc:** `docs/plans/2026-02-27-enrollment-completion-design.md`

---

## Phase 1: Data Layer + Service

### Task 1: Add `completion_tasks` column to registrations table

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php:73-122` (migrate method)

**Step 1: Add migration for completion_tasks column**

In `RegistrationTable::migrate()`, add after the existing ENUM expansion block (line ~121):

```php
// Add completion_tasks JSON column
$hasCompletionTasks = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'completion_tasks'");
if (!$hasCompletionTasks) {
    $wpdb->query("ALTER TABLE {$table} ADD COLUMN completion_tasks JSON NULL AFTER notes");
}
```

**Step 2: Run migration**

```bash
ddev exec wp eval "Stride\Modules\Enrollment\RegistrationTable::migrate();"
```

**Step 3: Verify column exists**

```bash
ddev exec wp eval "global \$wpdb; echo \$wpdb->get_var(\"SHOW COLUMNS FROM \" . \$wpdb->prefix . \"vad_registrations LIKE 'completion_tasks'\") . PHP_EOL;"
```

Expected: `completion_tasks`

**Step 4: Update RegistrationRepository::find() to decode completion_tasks**

In `RegistrationRepository::find()` (~line 105-109), add completion_tasks decoding alongside the existing selections decoding:

```php
if ($row && $row->completion_tasks) {
    $row->completion_tasks = json_decode($row->completion_tasks, true);
}
```

Also add to `findByUserAndEdition()` (~line 128).

**Step 5: Add update method for completion_tasks**

Add to `RegistrationRepository`:

```php
/**
 * Update completion_tasks JSON for a registration.
 */
public function updateCompletionTasks(int $registrationId, array $tasks): bool
{
    global $wpdb;

    $result = $wpdb->update(
        $this->table(),
        ['completion_tasks' => wp_json_encode($tasks)],
        ['id' => $registrationId],
        ['%s'],
        ['%d']
    );

    return $result !== false;
}
```

**Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php
git commit -m "feat(enrollment): add completion_tasks JSON column to registrations"
```

**Unit test:** Verify `updateCompletionTasks` stores and `find` retrieves decoded JSON correctly.

---

### Task 2: Create EnrollmentCompletionService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletionService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (register service)
- Create: `tests/Unit/EnrollmentCompletionServiceTest.php`

**Step 1: Write failing tests**

Create `tests/Unit/EnrollmentCompletionServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Enrollment\EnrollmentCompletionService;
use Stride\Tests\TestCase;

class EnrollmentCompletionServiceTest extends TestCase
{
    private EnrollmentCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnrollmentCompletionService();
    }

    /** @test */
    public function testGetRequirementsReturnsEnabledFlags(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'requires_session_selection' => '1',
            'requires_questionnaire' => '1',
            'requires_documents' => '0',
            'requires_approval' => '0',
        ]);

        $reqs = $this->service->getRequirements(100, 'vad_edition');

        $this->assertTrue($reqs['session_selection']);
        $this->assertTrue($reqs['questionnaire']);
        $this->assertFalse($reqs['documents']);
        $this->assertFalse($reqs['approval']);
    }

    /** @test */
    public function testGetRequirementsAllFalseByDefault(): void
    {
        $reqs = $this->service->getRequirements(999, 'vad_edition');

        $this->assertFalse($reqs['session_selection']);
        $this->assertFalse($reqs['questionnaire']);
        $this->assertFalse($reqs['documents']);
        $this->assertFalse($reqs['approval']);
    }

    /** @test */
    public function testHasRequirementsReturnsTrueWhenAnyEnabled(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'requires_questionnaire' => '1',
        ]);

        $this->assertTrue($this->service->hasRequirements(100, 'vad_edition'));
    }

    /** @test */
    public function testBuildInitialTasksCreatesCorrectStructure(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'requires_session_selection' => '1',
            'requires_approval' => '1',
        ]);

        $tasks = $this->service->buildInitialTasks(100, 'vad_edition');

        $this->assertArrayHasKey('session_selection', $tasks);
        $this->assertArrayHasKey('approval', $tasks);
        $this->assertArrayNotHasKey('questionnaire', $tasks);
        $this->assertArrayNotHasKey('documents', $tasks);
        $this->assertEquals('pending', $tasks['session_selection']['status']);
        $this->assertEquals('pending', $tasks['approval']['status']);
    }

    /** @test */
    public function testIsCompleteReturnsTrueWhenAllUserTasksDone(): void
    {
        $tasks = [
            'session_selection' => ['status' => 'completed', 'completed_at' => '2026-02-27T14:00:00'],
            'questionnaire' => ['status' => 'completed', 'completed_at' => '2026-02-27T15:00:00'],
            'approval' => ['status' => 'pending'],
        ];

        // All user tasks done, but approval pending — isComplete checks user tasks only
        $this->assertTrue($this->service->areUserTasksComplete($tasks));
    }

    /** @test */
    public function testIsFullyCompleteRequiresApproval(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed'],
            'approval' => ['status' => 'pending'],
        ];

        $this->assertFalse($this->service->isFullyComplete($tasks));

        $tasks['approval']['status'] = 'completed';
        $this->assertTrue($this->service->isFullyComplete($tasks));
    }

    /** @test */
    public function testCompleteTaskUpdatesStatus(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'pending'],
        ];

        $updated = $this->service->markTaskComplete($tasks, 'questionnaire', ['answers' => ['big' => '123']]);

        $this->assertEquals('completed', $updated['questionnaire']['status']);
        $this->assertNotNull($updated['questionnaire']['completed_at']);
        $this->assertEquals(['answers' => ['big' => '123']], $updated['questionnaire']['data']);
    }
}
```

**Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit --filter EnrollmentCompletionServiceTest --testsuite Unit
```

Expected: FAIL — class not found.

**Step 3: Implement EnrollmentCompletionService**

Create `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletionService.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
use WP_Error;

/**
 * Manages post-enrollment completion tasks.
 *
 * Tracks requirements (session selection, questionnaire, documents, approval)
 * as JSON on the registration row. Auto-confirms when all tasks complete.
 */
final class EnrollmentCompletionService extends AbstractService
{
    private const TASK_TYPES = ['session_selection', 'questionnaire', 'documents', 'approval'];

    private const META_KEYS = [
        'session_selection' => 'requires_session_selection',
        'questionnaire'     => 'requires_questionnaire',
        'documents'         => 'requires_documents',
        'approval'          => 'requires_approval',
    ];

    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Completion',
            'description' => 'Post-enrollment task tracking and auto-confirmation',
            'priority' => 16,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'enrollment_completion';
    }

    protected function init(): void
    {
        // Listen for task completion to auto-confirm
        add_action('stride/enrollment/task_completed', [$this, 'onTaskCompleted']);
    }

    /**
     * Get which requirements are enabled for an edition/trajectory.
     *
     * @return array{session_selection: bool, questionnaire: bool, documents: bool, approval: bool}
     */
    public function getRequirements(int $postId, string $postType): array
    {
        $model = ntdst_data()->get($postType);
        $result = [];

        foreach (self::META_KEYS as $task => $metaKey) {
            $result[$task] = (bool) $model->getMeta($postId, $metaKey);
        }

        return $result;
    }

    /**
     * Check if any requirements are enabled.
     */
    public function hasRequirements(int $postId, string $postType): bool
    {
        return in_array(true, array_values($this->getRequirements($postId, $postType)), true);
    }

    /**
     * Build initial completion_tasks JSON for a new registration.
     *
     * @return array<string, array{status: string}> Only includes enabled tasks
     */
    public function buildInitialTasks(int $postId, string $postType): array
    {
        $reqs = $this->getRequirements($postId, $postType);
        $tasks = [];

        foreach ($reqs as $task => $enabled) {
            if ($enabled) {
                $tasks[$task] = ['status' => 'pending'];
            }
        }

        return $tasks;
    }

    /**
     * Initialize completion tasks for a registration.
     *
     * Called by EnrollmentService after creating a Pending registration.
     */
    public function initializeForRegistration(int $registrationId, int $postId, string $postType): void
    {
        $tasks = $this->buildInitialTasks($postId, $postType);

        if (empty($tasks)) {
            return;
        }

        $repo = ntdst_get(RegistrationRepository::class);
        $repo->updateCompletionTasks($registrationId, $tasks);
    }

    /**
     * Complete a task for a registration.
     *
     * @return true|WP_Error
     */
    public function completeTask(int $registrationId, string $taskType, array $data = []): true|WP_Error
    {
        if (!in_array($taskType, self::TASK_TYPES, true)) {
            return new WP_Error('invalid_task', 'Unknown task type: ' . $taskType);
        }

        $repo = ntdst_get(RegistrationRepository::class);
        $registration = $repo->find($registrationId);

        if (!$registration) {
            return new WP_Error('not_found', 'Registration not found');
        }

        $tasks = $registration->completion_tasks ?? [];

        if (!isset($tasks[$taskType])) {
            return new WP_Error('task_not_required', 'This task is not required for this registration');
        }

        if ($tasks[$taskType]['status'] === 'completed') {
            return true; // Already done
        }

        $tasks = $this->markTaskComplete($tasks, $taskType, $data);
        $repo->updateCompletionTasks($registrationId, $tasks);

        // Fire event
        do_action('stride/enrollment/task_completed', [
            'registration_id' => $registrationId,
            'task_type' => $taskType,
            'tasks' => $tasks,
        ]);

        return true;
    }

    /**
     * Mark a task as complete in the tasks array (pure function).
     */
    public function markTaskComplete(array $tasks, string $taskType, array $data = []): array
    {
        $tasks[$taskType]['status'] = 'completed';
        $tasks[$taskType]['completed_at'] = current_time('c');

        if (!empty($data)) {
            $tasks[$taskType]['data'] = $data;
        }

        return $tasks;
    }

    /**
     * Check if all user-completable tasks are done (excludes approval).
     */
    public function areUserTasksComplete(array $tasks): bool
    {
        foreach ($tasks as $type => $task) {
            if ($type === 'approval') {
                continue;
            }
            if (($task['status'] ?? 'pending') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if all tasks including approval are complete.
     */
    public function isFullyComplete(array $tasks): bool
    {
        foreach ($tasks as $task) {
            if (($task['status'] ?? 'pending') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get task status summary for a registration.
     *
     * @return array{tasks: array, total: int, completed: int, has_approval: bool, ready_for_approval: bool}
     */
    public function getTaskSummary(int $registrationId): array
    {
        $repo = ntdst_get(RegistrationRepository::class);
        $registration = $repo->find($registrationId);

        $tasks = $registration->completion_tasks ?? [];
        $total = count($tasks);
        $completed = 0;

        foreach ($tasks as $task) {
            if (($task['status'] ?? 'pending') === 'completed') {
                $completed++;
            }
        }

        return [
            'tasks' => $tasks,
            'total' => $total,
            'completed' => $completed,
            'has_approval' => isset($tasks['approval']),
            'ready_for_approval' => isset($tasks['approval']) && $this->areUserTasksComplete($tasks),
        ];
    }

    /**
     * Get all registrations with pending tasks for a user.
     *
     * @return array<object>
     */
    public function getPendingForUser(int $userId): array
    {
        global $wpdb;

        $table = RegistrationTable::getTableName();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE user_id = %d
               AND status = %s
               AND completion_tasks IS NOT NULL
             ORDER BY registered_at DESC",
            $userId,
            RegistrationStatus::Pending->value
        ));
    }

    /**
     * Handle task completion — auto-confirm if all tasks done.
     */
    public function onTaskCompleted(array $data): void
    {
        $registrationId = $data['registration_id'] ?? 0;
        $tasks = $data['tasks'] ?? [];

        if (!$registrationId || empty($tasks)) {
            return;
        }

        // If approval is required, don't auto-confirm — wait for admin
        if (isset($tasks['approval'])) {
            if ($this->areUserTasksComplete($tasks) && $tasks['approval']['status'] !== 'completed') {
                ntdst_log('enrollment')->info('All user tasks complete, awaiting admin approval', [
                    'registration_id' => $registrationId,
                ]);
            }
            return;
        }

        // No approval needed — if all tasks complete, auto-confirm
        if ($this->isFullyComplete($tasks)) {
            $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
            $result = $enrollmentService->confirmRegistration($registrationId);

            if (is_wp_error($result)) {
                ntdst_log('enrollment')->error('Auto-confirm failed', [
                    'registration_id' => $registrationId,
                    'error' => $result->get_error_message(),
                ]);
            } else {
                ntdst_log('enrollment')->info('Registration auto-confirmed after task completion', [
                    'registration_id' => $registrationId,
                ]);
            }
        }
    }
}
```

**Step 4: Register service in plugin-config.php**

Add after `EnrollmentFieldGroupService`:

```php
\Stride\Modules\Enrollment\EnrollmentCompletionService::class,
```

**Step 5: Run tests**

```bash
ddev exec vendor/bin/phpunit --filter EnrollmentCompletionServiceTest --testsuite Unit
```

Expected: All pass.

**Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletionService.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git add tests/Unit/EnrollmentCompletionServiceTest.php
git commit -m "feat(enrollment): add EnrollmentCompletionService with task tracking"
```

**Unit test:** Requirements reading, task building, completion logic, auto-confirm conditions.

---

### Task 3: Hook completion into enrollment flow

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php:150-253` (enroll method)
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php:29-47` (skip pending with tasks)

**Step 1: Modify `EnrollmentService::enroll()` to initialize tasks**

After the current `requires_approval` check (~line 196), replace the initial status logic:

```php
// Determine initial status
$completionService = ntdst_get(EnrollmentCompletionService::class);
$hasRequirements = $completionService->hasRequirements($editionId, 'vad_edition');

$initialStatus = ($hasRequirements || $this->editions->requiresApproval($editionId))
    ? RegistrationStatus::Pending
    : RegistrationStatus::Confirmed;
```

After the registration is created and events fired (~line 243), add task initialization:

```php
// Initialize completion tasks for pending registrations with requirements
if ($hasRequirements) {
    $completionService->initializeForRegistration($registrationId, $editionId, 'vad_edition');
}
```

**Step 2: Modify `EnrollmentQuoteHandler::onRegistrationCreated()` to skip pending-with-tasks**

After the interest check (~line 47), add:

```php
// Skip quote for pending registrations with completion tasks
// Quote will be created when registration is confirmed
if ($status === 'pending') {
    ntdst_log('invoicing')->info('Deferring quote: pending registration with completion tasks', [
        'registration_id' => $registrationId,
    ]);
    return;
}
```

**Step 3: Create quote on confirmation**

Add to `EnrollmentQuoteHandler::__construct()`:

```php
add_action('stride/registration/confirmed', [$this, 'onRegistrationConfirmed']);
```

Add new method:

```php
/**
 * Create quote when a pending registration is confirmed.
 */
public function onRegistrationConfirmed(array $data): void
{
    $registrationId = $data['registration_id'] ?? 0;
    $userId = $data['user_id'] ?? 0;
    $editionId = $data['edition_id'] ?? 0;

    if (!$registrationId || !$userId || !$editionId) {
        return;
    }

    // Delegate to the same creation logic
    $this->onRegistrationCreated([
        'registration_id' => $registrationId,
        'user_id' => $userId,
        'edition_id' => $editionId,
        'status' => 'confirmed',
    ]);
}
```

**Step 4: Verify syntax**

```bash
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
ddev exec php -l web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php
```

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php
git commit -m "feat(enrollment): hook completion tasks into enrollment + defer quotes for pending"
```

**Unit test:** Enrollment with requirements creates Pending status. Quote handler skips pending registrations. Quote created on confirmation.

---

### Phase 1 Integration Gate

Run full test suite:

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Verify: All tests pass, no regressions. EnrollmentCompletionService correctly reads meta, builds tasks, and marks completion.

---

## Phase 2: Admin Configuration

### Task 4: Add requirement checkboxes to Edition admin metabox

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionActionsMetabox.php:152-171`

**Step 1: Add requirements section**

Add a new method `renderRequirementsSection()` and call it from `render()` before the approval section (~line 71):

```php
<?php $this->renderRequirementsSection($post); ?>
```

The method:

```php
private function renderRequirementsSection(WP_Post $post): void
{
    if ($post->post_status === 'auto-draft') {
        return;
    }

    $model = ntdst_data()->get('vad_edition');
    $requirements = [
        'requires_session_selection' => __('Sessiekeuze vereist', 'stride'),
        'requires_questionnaire'     => __('Vragenlijst invullen', 'stride'),
        'requires_documents'         => __('Documenten uploaden', 'stride'),
    ];
    ?>
    <div class="stride-sidebar-section">
        <h4><?php esc_html_e('Inschrijfvereisten', 'stride'); ?></h4>
        <p class="description" style="margin-bottom: 8px; font-size: 11px;">
            <?php esc_html_e('Deelnemers moeten deze stappen voltooien na inschrijving.', 'stride'); ?>
        </p>
        <?php foreach ($requirements as $key => $label): ?>
            <?php $checked = (bool) $model->getMeta($post->ID, $key); ?>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 6px;">
                <input type="hidden" name="ntdst_fields[<?= esc_attr($key) ?>]" value="0">
                <input type="checkbox" name="ntdst_fields[<?= esc_attr($key) ?>]" value="1"
                       <?php checked($checked); ?>>
                <span style="font-size: 12px;"><?= esc_html($label) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}
```

Note: `requires_approval` already exists in its own section, so we keep that separate.

**Step 2: Verify meta saves correctly**

The `ntdst_fields` naming convention means the NTDST Data Manager auto-saves these on post save. No additional save handler needed.

**Step 3: Syntax check**

```bash
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionActionsMetabox.php
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionActionsMetabox.php
git commit -m "feat(admin): add enrollment requirement checkboxes to edition metabox"
```

---

### Task 5: Add requirement checkboxes to Trajectory admin

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/Admin/TrajectoryAdminController.php`

**Step 1: Check current metabox structure**

Read `TrajectoryAdminController.php` to find where metaboxes are registered and rendered. Add the same requirements checkboxes as Task 4, but for `vad_trajectory` post type.

Use the same `ntdst_fields[]` naming pattern so Data Manager auto-saves.

**Step 2: Add requirements section**

Add a method similar to Task 4's `renderRequirementsSection` but using `vad_trajectory` model. Include the same 3 checkboxes + the approval checkbox (trajectories don't have the separate approval section that editions have).

**Step 3: Syntax check and commit**

```bash
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Trajectory/Admin/TrajectoryAdminController.php
git add web/app/mu-plugins/stride-core/Modules/Trajectory/Admin/TrajectoryAdminController.php
git commit -m "feat(admin): add enrollment requirement checkboxes to trajectory metabox"
```

---

### Phase 2 Integration Gate

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Manual check: Edit an edition in WP admin → verify checkboxes appear, save, reload, values persist.

---

## Phase 3: User Dashboard Checklist

### Task 6: Add completion checklist to enrollment cards

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`
- Create: `web/app/themes/stridence/templates/dashboard/partials/completion-checklist.php`

**Step 1: Create checklist partial**

Create `templates/dashboard/partials/completion-checklist.php`:

```php
<?php
/**
 * Enrollment completion checklist partial.
 *
 * @var array $args {
 *     @type object $registration  Registration row
 *     @type array  $task_summary  From EnrollmentCompletionService::getTaskSummary()
 *     @type string $complete_url  URL to the completion page
 * }
 */
$registration = $args['registration'] ?? null;
$summary      = $args['task_summary'] ?? [];
$complete_url = $args['complete_url'] ?? '#';
$tasks        = $summary['tasks'] ?? [];
$total        = $summary['total'] ?? 0;
$completed    = $summary['completed'] ?? 0;

if (empty($tasks) || $total === 0) {
    return;
}

$percentage = round(($completed / $total) * 100);

$task_labels = [
    'session_selection' => __('Sessies kiezen', 'stride'),
    'questionnaire'     => __('Vragenlijst invullen', 'stride'),
    'documents'         => __('Documenten uploaden', 'stride'),
    'approval'          => __('Goedkeuring beheerder', 'stride'),
];
?>

<div class="mt-4 pt-4 border-t border-border">
    <p class="text-sm font-medium text-text-secondary mb-3">
        <?= esc_html__('Inschrijving voltooien:', 'stride') ?>
    </p>

    <ul class="space-y-2 mb-4">
        <?php foreach ($tasks as $type => $task): ?>
            <?php
            $isDone = ($task['status'] ?? 'pending') === 'completed';
            $isApproval = $type === 'approval';
            $userTasksDone = $summary['ready_for_approval'] ?? false;
            ?>
            <li class="flex items-center gap-2 text-sm">
                <?php if ($isDone): ?>
                    <?= stridence_icon('check', 'w-4 h-4 text-emerald-500') ?>
                    <span class="text-text-muted line-through"><?= esc_html($task_labels[$type] ?? $type) ?></span>
                <?php elseif ($isApproval): ?>
                    <?= stridence_icon('info', 'w-4 h-4 text-text-muted') ?>
                    <span class="text-text-muted">
                        <?= esc_html($task_labels[$type]) ?>
                        <?php if (!$userTasksDone): ?>
                            <span class="text-xs">(<?= esc_html__('wacht op taken', 'stride') ?>)</span>
                        <?php else: ?>
                            <span class="text-xs text-amber-600">(<?= esc_html__('wacht op beheerder', 'stride') ?>)</span>
                        <?php endif; ?>
                    </span>
                <?php else: ?>
                    <span class="w-4 h-4 rounded-full border-2 border-border inline-block"></span>
                    <span><?= esc_html($task_labels[$type] ?? $type) ?></span>
                    <a href="<?= esc_url($complete_url) ?>" class="text-primary text-xs hover:underline ml-auto">
                        <?= esc_html__('Invullen', 'stride') ?> &rarr;
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Progress bar -->
    <div class="h-2 bg-surface-alt rounded-full overflow-hidden">
        <div class="h-full bg-primary rounded-full transition-all"
             style="width: <?= esc_attr($percentage) ?>%"></div>
    </div>
    <p class="text-xs text-text-muted mt-1"><?= esc_html(sprintf('%d%% voltooid', $percentage)) ?></p>
</div>
```

**Step 2: Include checklist in tab-inschrijvingen.php**

In the active registrations loop, after the enrollment card content, add for pending registrations with completion tasks:

```php
<?php
if ($reg->status === 'pending' && !empty($reg->completion_tasks)) {
    $completionService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletionService::class);
    $taskSummary = $completionService->getTaskSummary((int) $reg->id);

    // Build completion URL
    $edition_slug = get_post_field('post_name', $edition_id);
    $complete_url = home_url('/vormingen/' . $edition_slug . '/voltooien/');

    get_template_part('templates/dashboard/partials/completion-checklist', null, [
        'registration' => $reg,
        'task_summary' => $taskSummary,
        'complete_url' => $complete_url,
    ]);
}
?>
```

**Step 3: Syntax check and commit**

```bash
ddev exec php -l web/app/themes/stridence/templates/dashboard/partials/completion-checklist.php
ddev exec php -l web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php
git add web/app/themes/stridence/templates/dashboard/partials/completion-checklist.php
git add web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php
git commit -m "feat(dashboard): add enrollment completion checklist to registration cards"
```

---

### Task 7: Add completion page route and template

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouterService.php`
- Create: `web/app/themes/stridence/templates/forms/completion.php`
- Create: `web/app/themes/stridence/templates/forms/completion/task-questionnaire.php`
- Create: `web/app/themes/stridence/templates/forms/completion/task-documents.php`
- Create: `web/app/themes/stridence/templates/forms/completion/task-sessions.php`

**Step 1: Add rewrite rules for completion URLs**

In `EnrollmentRouterService::addRewriteRules()`, add:

```php
// Edition completion: /vormingen/{slug}/voltooien/
add_rewrite_rule(
    '^vormingen/([^/]+)/voltooien/?$',
    'index.php?stride_completion=edition&stride_enrollment_slug=$matches[1]',
    'top'
);

// Trajectory completion: /trajecten/{slug}/voltooien/
add_rewrite_rule(
    '^trajecten/([^/]+)/voltooien/?$',
    'index.php?stride_completion=trajectory&stride_enrollment_slug=$matches[1]',
    'top'
);
```

In `addQueryVars()`, add `stride_completion`.

In `handleEnrollmentRoutes()`, add handling for `stride_completion` query var before the enrollment handling — route to a completion page renderer.

**Step 2: Create completion page template**

`templates/forms/completion.php` — receives the registration, shows collapsible cards per pending task:

- **Session selection card:** Reuses session picker UI from existing session selection feature.
- **Questionnaire card:** Loads field groups via `EnrollmentFieldGroupService::getFieldGroupsForPost()`, renders as form, saves via AJAX to `completeTask('questionnaire', data)`.
- **Documents card:** File upload dropzone, saves attachment IDs via AJAX to `completeTask('documents', {files: [...]})`.
- **Approval card:** Read-only status display.

Each card has its own partial in `templates/forms/completion/`.

**Step 3: Add AJAX handlers for task completion**

Add to `EnrollmentCompletionService::init()`:

```php
add_action('wp_ajax_stride_complete_questionnaire', [$this, 'ajaxCompleteQuestionnaire']);
add_action('wp_ajax_stride_complete_documents', [$this, 'ajaxCompleteDocuments']);
```

Each handler validates nonce, sanitizes input, calls `completeTask()`, returns JSON.

**Step 4: Flush rewrite rules**

```bash
ddev exec wp rewrite flush
```

**Step 5: Syntax check and commit**

```bash
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouterService.php
ddev exec php -l web/app/themes/stridence/templates/forms/completion.php
git add -A
git commit -m "feat(enrollment): add completion page with task cards for questionnaire, documents, sessions"
```

---

### Phase 3 Integration Gate

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Verify: All tests pass. Manual smoke test:

- [ ] Visit: Dashboard → Mijn Inschrijvingen
      Expected: Pending enrollment with completion checklist shows
- [ ] Visit: /vormingen/{slug}/voltooien/
      Expected: Completion page loads with task cards
- [ ] Action: Fill in questionnaire → submit
      Expected: Task marked complete, progress bar updates
- [ ] Action: Upload document → submit
      Expected: Task marked complete, file saved
- [ ] Console: DevTools > Console
      Expected: No red errors

---

## Phase 4: Admin Approval Flow

### Task 8: Add approval UI to Stride admin dashboard

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` (add endpoints)
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js` (add approval view)
- Modify: `web/app/mu-plugins/stride-core/templates/admin/dashboard.php` (add approval section)

**Step 1: Add API endpoints for pending approvals**

In `AdminAPIController`, add:

```php
// GET /stride/v1/admin/pending-approvals
// Returns registrations where all user tasks complete, awaiting admin approval

// POST /stride/v1/admin/approve-registration
// Calls EnrollmentService::confirmRegistration() + marks approval task complete
```

**Step 2: Add approval section to dashboard template**

In the Alpine dashboard, add a "Wachtend op goedkeuring" section that:
- Fetches pending approvals via API
- Shows registration details (user name, edition, completed tasks)
- Approve button per registration
- Bulk approve checkbox

**Step 3: Commit**

```bash
git add -A
git commit -m "feat(admin): add pending approval view and approve action to dashboard"
```

---

### Phase 4 Integration Gate

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Full smoke test:

- [ ] Admin: Stride Dashboard shows "Wachtend op goedkeuring" for registrations with all tasks done
- [ ] Admin: Click approve → registration confirmed, quote created
- [ ] User: Dashboard shows enrollment as "Bevestigd" after admin approval
- [ ] API: Quote created after confirmation (not during pending enrollment)

---

## File Summary

| File | Action |
|------|--------|
| `Modules/Enrollment/RegistrationTable.php` | Modify (add column migration) |
| `Modules/Enrollment/RegistrationRepository.php` | Modify (decode + update methods) |
| `Modules/Enrollment/EnrollmentCompletionService.php` | Create |
| `Modules/Enrollment/EnrollmentService.php` | Modify (hook completion into enroll) |
| `Handlers/EnrollmentQuoteHandler.php` | Modify (defer quotes, create on confirm) |
| `Modules/Edition/Admin/EditionActionsMetabox.php` | Modify (add checkboxes) |
| `Modules/Trajectory/Admin/TrajectoryAdminController.php` | Modify (add checkboxes) |
| `Modules/Enrollment/EnrollmentRouterService.php` | Modify (add completion routes) |
| `Admin/AdminAPIController.php` | Modify (approval endpoints) |
| `assets/js/admin-dashboard.js` | Modify (approval UI) |
| `templates/admin/dashboard.php` | Modify (approval section) |
| `plugin-config.php` | Modify (register service) |
| `themes/stridence/templates/dashboard/partials/completion-checklist.php` | Create |
| `themes/stridence/templates/dashboard/tab-inschrijvingen.php` | Modify |
| `themes/stridence/templates/forms/completion.php` | Create |
| `themes/stridence/templates/forms/completion/task-questionnaire.php` | Create |
| `themes/stridence/templates/forms/completion/task-documents.php` | Create |
| `themes/stridence/templates/forms/completion/task-sessions.php` | Create |
| `tests/Unit/EnrollmentCompletionServiceTest.php` | Create |
