<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use WP_Error;

/**
 * Post-enrollment completion task logic.
 *
 * Tracks requirements (session selection, questionnaire, documents, approval)
 * as JSON on the registration row. Pure business logic — no hooks or AJAX.
 *
 * Plain class — owned by EnrollmentService, not a standalone service.
 */
final class EnrollmentCompletion
{
    private const TASK_TYPES = [
        'session_selection', 'questionnaire', 'documents', 'approval',
        'post_evaluation', 'post_documents', 'post_approval',
    ];

    private const META_KEYS = [
        'session_selection' => 'requires_session_selection',
        'questionnaire'     => 'requires_questionnaire',
        'documents'         => 'requires_documents',
        'approval'          => 'requires_approval',
    ];

    private const POST_COURSE_TASK_TYPES = ['post_evaluation', 'post_documents', 'post_approval'];

    private const POST_COURSE_META_KEYS = [
        'post_evaluation' => 'post_requires_evaluation',
        'post_documents'  => 'post_requires_documents',
        'post_approval'   => 'post_requires_approval',
    ];

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
                $tasks[$task] = ['status' => 'pending', 'phase' => 'enrollment'];
            }
        }

        return $tasks;
    }

    /**
     * Compute availability for each task in a registration.
     *
     * Returns 'available', 'locked', or 'completed' per task, plus lock reason.
     *
     * @return array<string, array{state: string, reason: string}>
     */
    public function getTaskAvailability(array $tasks, int $editionId): array
    {
        $availability = [];

        // Pre-compute: are immediate tasks (questionnaire + documents) done?
        $immediateDone = true;
        foreach (['questionnaire', 'documents'] as $type) {
            if (isset($tasks[$type]) && ($tasks[$type]['status'] ?? 'pending') !== 'completed') {
                $immediateDone = false;
            }
        }

        $approvalDone = !isset($tasks['approval']) || ($tasks['approval']['status'] ?? 'pending') === 'completed';

        // Pre-compute: are post-course immediate tasks (post_evaluation + post_documents) done?
        $postImmediateDone = true;
        foreach (['post_evaluation', 'post_documents'] as $type) {
            if (isset($tasks[$type]) && ($tasks[$type]['status'] ?? 'pending') !== 'completed') {
                $postImmediateDone = false;
            }
        }

        // Selection window from edition meta
        $selectionOpen = false;
        $selectionReason = '';
        if ($editionId && isset($tasks['session_selection'])) {
            $model = ntdst_data()->get('vad_edition');
            $isOpen = (bool) $model->getMeta($editionId, 'selection_open');
            $deadline = $model->getMeta($editionId, 'selection_deadline');
            $pastDeadline = $deadline && strtotime($deadline) < current_time('timestamp');

            if (!$isOpen) {
                $selectionReason = __('Sessiekeuze is nog niet geopend.', 'stride');
            } elseif ($pastDeadline) {
                $selectionReason = __('De deadline voor sessiekeuze is verstreken.', 'stride');
            } else {
                $selectionOpen = true;
            }
        }

        foreach ($tasks as $type => $task) {
            $status = $task['status'] ?? 'pending';

            if ($status === 'completed') {
                $availability[$type] = ['state' => 'completed', 'reason' => ''];
                continue;
            }

            switch ($type) {
                case 'questionnaire':
                case 'documents':
                    $availability[$type] = ['state' => 'available', 'reason' => ''];
                    break;

                case 'approval':
                    if ($immediateDone) {
                        $availability[$type] = ['state' => 'available', 'reason' => __('Klaar voor beoordeling.', 'stride')];
                    } else {
                        $availability[$type] = ['state' => 'locked', 'reason' => __('Wacht op vragenlijst en documenten.', 'stride')];
                    }
                    break;

                case 'session_selection':
                    if (!$approvalDone) {
                        $availability[$type] = ['state' => 'locked', 'reason' => __('Wacht op goedkeuring.', 'stride')];
                    } elseif (!$selectionOpen) {
                        $availability[$type] = ['state' => 'locked', 'reason' => $selectionReason];
                    } else {
                        $availability[$type] = ['state' => 'available', 'reason' => ''];
                        if ($deadline = ntdst_data()->get('vad_edition')->getMeta($editionId, 'selection_deadline')) {
                            $availability[$type]['reason'] = sprintf(
                                __('Kies voor %s', 'stride'),
                                date_i18n('d M Y', strtotime($deadline))
                            );
                        }
                    }
                    break;

                // Post-course tasks
                case 'post_evaluation':
                case 'post_documents':
                    $availability[$type] = ['state' => 'available', 'reason' => ''];
                    break;

                case 'post_approval':
                    if ($postImmediateDone) {
                        $availability[$type] = ['state' => 'available', 'reason' => __('Klaar voor beoordeling.', 'stride')];
                    } else {
                        $availability[$type] = ['state' => 'locked', 'reason' => __('Wacht op evaluatie en documenten.', 'stride')];
                    }
                    break;

                default:
                    $availability[$type] = ['state' => 'available', 'reason' => ''];
            }
        }

        return $availability;
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

    // === Post-course phase ===

    /**
     * Get which post-course requirements are enabled for an edition/trajectory.
     *
     * @return array{post_evaluation: bool, post_documents: bool, post_approval: bool}
     */
    public function getPostCourseRequirements(int $postId, string $postType): array
    {
        $model = ntdst_data()->get($postType);
        $result = [];

        foreach (self::POST_COURSE_META_KEYS as $task => $metaKey) {
            $result[$task] = (bool) $model->getMeta($postId, $metaKey);
        }

        return $result;
    }

    /**
     * Check if any post-course requirements are enabled.
     */
    public function hasPostCourseRequirements(int $postId, string $postType): bool
    {
        return in_array(true, array_values($this->getPostCourseRequirements($postId, $postType)), true);
    }

    /**
     * Build post-course completion tasks for an edition/trajectory.
     *
     * @return array<string, array{status: string, phase: string}> Only includes enabled tasks
     */
    public function buildPostCourseTasks(int $postId, string $postType): array
    {
        $reqs = $this->getPostCourseRequirements($postId, $postType);
        $tasks = [];

        foreach ($reqs as $task => $enabled) {
            if ($enabled) {
                $tasks[$task] = ['status' => 'pending', 'phase' => 'post_course'];
            }
        }

        return $tasks;
    }

    /**
     * Initialize post-course tasks for a registration.
     *
     * Appends post-course tasks to existing completion_tasks JSON.
     */
    public function initializePostCourseTasks(int $registrationId, int $editionId): void
    {
        $postCourseTasks = $this->buildPostCourseTasks($editionId, 'vad_edition');

        if (empty($postCourseTasks)) {
            return;
        }

        $repo = ntdst_get(RegistrationRepository::class);
        $registration = $repo->find($registrationId);

        $existingTasks = $registration->completion_tasks ?? [];
        $mergedTasks = array_merge($existingTasks, $postCourseTasks);

        $repo->updateCompletionTasks($registrationId, $mergedTasks);
    }

    /**
     * Check if all enrollment-phase tasks are complete.
     */
    public function isEnrollmentPhaseComplete(array $tasks): bool
    {
        $phaseTasks = $this->getTasksForPhase($tasks, 'enrollment');

        foreach ($phaseTasks as $task) {
            if (($task['status'] ?? 'pending') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if all post-course-phase tasks are complete.
     */
    public function isPostCoursePhaseComplete(array $tasks): bool
    {
        $phaseTasks = $this->getTasksForPhase($tasks, 'post_course');

        foreach ($phaseTasks as $task) {
            if (($task['status'] ?? 'pending') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter tasks by phase.
     *
     * @return array<string, array>
     */
    public function getTasksForPhase(array $tasks, string $phase): array
    {
        return array_filter($tasks, fn(array $task) => ($task['phase'] ?? 'enrollment') === $phase);
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

        // Check availability — don't allow completing locked tasks
        $editionId = (int) ($registration->edition_id ?? 0);
        $availability = $this->getTaskAvailability($tasks, $editionId);
        if (($availability[$taskType]['state'] ?? 'available') === 'locked') {
            return new WP_Error('task_locked', $availability[$taskType]['reason'] ?? 'Task is not yet available');
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
     * Check if all user-completable tasks are done (excludes approval types).
     */
    public function areUserTasksComplete(array $tasks): bool
    {
        foreach ($tasks as $type => $task) {
            if ($type === 'approval' || $type === 'post_approval') {
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
     * @return array{tasks: array, availability: array, total: int, completed: int, has_approval: bool, ready_for_approval: bool}
     */
    public function getTaskSummary(int $registrationId): array
    {
        $repo = ntdst_get(RegistrationRepository::class);
        $registration = $repo->find($registrationId);

        $tasks = $registration->completion_tasks ?? [];
        $editionId = (int) ($registration->edition_id ?? 0);
        $availability = $this->getTaskAvailability($tasks, $editionId);
        $total = count($tasks);
        $completed = 0;

        foreach ($tasks as $task) {
            if (($task['status'] ?? 'pending') === 'completed') {
                $completed++;
            }
        }

        return [
            'tasks' => $tasks,
            'availability' => $availability,
            'total' => $total,
            'completed' => $completed,
            'has_approval' => isset($tasks['approval']),
            'ready_for_approval' => isset($tasks['approval'])
                && ($availability['approval']['state'] ?? '') === 'available'
                && ($tasks['approval']['status'] ?? 'pending') !== 'completed',
        ];
    }

    /**
     * Get all registrations with pending tasks for a user.
     *
     * Includes both pending (enrollment phase) and confirmed (post-course phase) registrations.
     *
     * @return array<object>
     */
    public function getPendingForUser(int $userId): array
    {
        global $wpdb;

        $table = RegistrationTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE user_id = %d
               AND status IN (%s, %s)
               AND completion_tasks IS NOT NULL
             ORDER BY registered_at DESC",
            $userId,
            RegistrationStatus::Pending->value,
            RegistrationStatus::Confirmed->value
        ));

        // Filter: only return registrations where at least one task is incomplete
        return array_filter($results, function (object $reg): bool {
            $tasks = json_decode($reg->completion_tasks, true) ?: [];
            foreach ($tasks as $task) {
                if (($task['status'] ?? 'pending') !== 'completed') {
                    return true;
                }
            }
            return false;
        });
    }
}
