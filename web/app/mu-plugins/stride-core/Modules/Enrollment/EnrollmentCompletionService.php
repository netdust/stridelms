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
            return true;
        }

        $tasks = $this->markTaskComplete($tasks, $taskType, $data);
        $repo->updateCompletionTasks($registrationId, $tasks);

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
     * Handle task completion -- auto-confirm if all tasks done.
     */
    public function onTaskCompleted(array $data): void
    {
        $registrationId = $data['registration_id'] ?? 0;
        $tasks = $data['tasks'] ?? [];

        if (!$registrationId || empty($tasks)) {
            return;
        }

        if (isset($tasks['approval'])) {
            if ($this->areUserTasksComplete($tasks) && $tasks['approval']['status'] !== 'completed') {
                ntdst_log('enrollment')->info('All user tasks complete, awaiting admin approval', [
                    'registration_id' => $registrationId,
                ]);
            }
            return;
        }

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
