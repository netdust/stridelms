<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;
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
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionRepository $editions,
        private readonly TrajectoryRepository $trajectories,
    ) {}

    /**
     * Resolve the repository for a given CPT slug.
     * Used by getRequirements/getPostCourseRequirements where the type is
     * decided by the caller (edition OR trajectory).
     */
    private function repoFor(string $postType): AbstractRepository
    {
        return match ($postType) {
            'vad_trajectory' => $this->trajectories,
            default          => $this->editions, // vad_edition
        };
    }

    /**
     * Default instruction shown for the "Documenten uploaden" task when the
     * admin has not authored a per-offering instruction. Single source of truth
     * for the fallback string — the admin pre-fill and the theme both defer here.
     */
    public const DEFAULT_DOCUMENTS_INSTRUCTION
        = 'Upload de gevraagde bewijsstukken (bv. diploma of attest). Toegestane formaten: PDF, JPG, PNG — max. 10 MB.';

    /**
     * Resolve the admin-authored documents instruction for an offering, with a
     * fallback to DEFAULT_DOCUMENTS_INSTRUCTION when unset/cleared.
     *
     * Schema-registered fields never return getField() defaults (the field reads
     * as null/'' when unset), so the default is applied here, not by the schema.
     * This is a real transform layer, not a repository pass-through.
     *
     * @param bool $postCourse Read the post-course key (post_documents_instruction).
     */
    public function documentsInstruction(int $postId, string $postType, bool $postCourse = false): string
    {
        $key   = $postCourse ? 'post_documents_instruction' : 'documents_instruction';
        $value = trim((string) $this->repoFor($postType)->getField($postId, $key));

        return $value !== '' ? $value : self::DEFAULT_DOCUMENTS_INSTRUCTION;
    }

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
        $repo = $this->repoFor($postType);
        $result = [];

        foreach (self::META_KEYS as $task => $metaKey) {
            $result[$task] = (bool) $repo->getField($postId, $metaKey);
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
     * questionnaire/documents/post_evaluation/post_documents additionally carry
     * an 'overdue' flag (D3: past gate_deadline/post_gate_deadline is flag-only —
     * it never locks or cancels the task).
     *
     * @return array<string, array{state: string, reason: string, overdue?: bool}>
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
        $deadline = null;
        if ($editionId && isset($tasks['session_selection'])) {
            $isOpen = (bool) $this->editions->getField($editionId, 'selection_open');
            $deadline = $this->editions->getField($editionId, 'selection_deadline');
            $pastDeadline = $deadline && strtotime($deadline) < time();

            if (!$isOpen) {
                $selectionReason = __('Sessiekeuze is nog niet geopend.', 'stride');
            } elseif ($pastDeadline) {
                $selectionReason = __('De deadline voor sessiekeuze is verstreken.', 'stride');
            } else {
                $selectionOpen = true;
            }
        }

        // Gate deadlines (enrollment-phase + post-course-phase). Flag-only (D3):
        // past deadline never locks/cancels the task, it only annotates the reason
        // + overdue flag. This is the sole read site for these two fields.
        $gateDeadline = $editionId ? $this->editions->getField($editionId, 'gate_deadline') : null;
        $postGateDeadline = $editionId ? $this->editions->getField($editionId, 'post_gate_deadline') : null;
        $gateInfo = $this->deadlineInfo($gateDeadline);
        $postGateInfo = $this->deadlineInfo($postGateDeadline);

        foreach ($tasks as $type => $task) {
            $status = $task['status'] ?? 'pending';

            // Session selection: allow re-editing even when completed
            if ($status === 'completed' && $type === 'session_selection' && $selectionOpen) {
                $startDate = $editionId ? $this->editions->getField($editionId, 'start_date') : null;
                $courseStarted = $startDate && strtotime($startDate) < time();

                if (!$courseStarted) {
                    $availability[$type] = ['state' => 'available', 'reason' => __('Je kunt je keuze nog wijzigen.', 'stride')];
                    continue;
                }
            }

            if ($status === 'completed') {
                $availability[$type] = ['state' => 'completed', 'reason' => ''];
                continue;
            }

            switch ($type) {
                case 'questionnaire':
                case 'documents':
                    $availability[$type] = [
                        'state' => 'available',
                        'reason' => $gateInfo['reason'],
                        'overdue' => $gateInfo['overdue'],
                    ];
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
                        if ($deadline) {
                            $availability[$type]['reason'] = sprintf(
                                __('Kies voor %s', 'stride'),
                                date_i18n('d M Y', strtotime($deadline)),
                            );
                        }
                    }
                    break;

                    // Post-course tasks
                case 'post_evaluation':
                case 'post_documents':
                    $availability[$type] = [
                        'state' => 'available',
                        'reason' => $postGateInfo['reason'],
                        'overdue' => $postGateInfo['overdue'],
                    ];
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
     * Resolve reason + overdue flag for a gate deadline value (gate_deadline
     * or post_gate_deadline). Flag-only (D3): the caller must NOT use the
     * overdue flag to lock/cancel — it only annotates the reason.
     *
     * @return array{reason: string, overdue: bool}
     */
    private function deadlineInfo(?string $deadline): array
    {
        if (!$deadline) {
            return ['reason' => '', 'overdue' => false];
        }

        if (strtotime($deadline) < time()) {
            return ['reason' => __('De deadline is verstreken.', 'stride'), 'overdue' => true];
        }

        return [
            'reason' => sprintf(
                __('Voltooien voor %s', 'stride'),
                date_i18n('d M Y', strtotime($deadline)),
            ),
            'overdue' => false,
        ];
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

        $repo = $this->registrations;
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
        $repo = $this->repoFor($postType);
        $result = [];

        foreach (self::POST_COURSE_META_KEYS as $task => $metaKey) {
            $result[$task] = (bool) $repo->getField($postId, $metaKey);
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
    public function initializePostCourseTasks(int $registrationId, int $editionId, string $postType = 'vad_edition'): void
    {
        $postCourseTasks = $this->buildPostCourseTasks($editionId, $postType);

        if (empty($postCourseTasks)) {
            return;
        }

        $repo = $this->registrations;
        $registration = $repo->find($registrationId);

        if (!$registration) {
            return;
        }

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

        $repo = $this->registrations;
        $registration = $repo->find($registrationId);

        if (!$registration) {
            return new WP_Error('not_found', 'Registration not found');
        }

        $tasks = $registration->completion_tasks ?? [];

        if (!isset($tasks[$taskType])) {
            return new WP_Error('task_not_required', 'This task is not required for this registration');
        }

        if ($tasks[$taskType]['status'] === 'completed') {
            // Session selection allows re-submission to update quote pricing
            if ($taskType === 'session_selection') {
                $tasks = $this->markTaskComplete($tasks, $taskType, $data);
                $repo->updateCompletionTasks($registrationId, $tasks);

                do_action('stride/enrollment/task_completed', [
                    'registration_id' => $registrationId,
                    'task_type' => $taskType,
                    'tasks' => $tasks,
                ]);
            }
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
     * Find the first user-completable task that's still open.
     * Used to surface "Waarop wachten we?" in the admin stale-pending view.
     *
     * @return string|null Task type (e.g. 'session_selection') or null if all user tasks done.
     */
    public function getFirstOpenUserTask(array $tasks): ?string
    {
        foreach ($tasks as $type => $task) {
            if ($type === 'approval' || $type === 'post_approval') {
                continue;
            }
            if (($task['status'] ?? 'pending') !== 'completed') {
                return $type;
            }
        }

        return null;
    }

    /**
     * Resolve who a still-pending registration is waiting on, for the admin
     * dossier hint. Mirrors the approvals-queue bucketing (AdminAPIController):
     * if a user task is still open → waiting on the user (named by the first
     * open task); otherwise all user tasks are done → waiting on admin approval.
     * An empty task set means nothing is known outstanding on the user side, so
     * it reads as a neutral "waiting on approval".
     *
     * @param  array<string, array> $tasks  The registration's completion_tasks.
     * @return array{actor: string, label: string}  actor is 'user' or 'admin'.
     */
    public function pendingReason(array $tasks): array
    {
        if (empty($tasks)) {
            return ['actor' => 'admin', 'label' => __('Wacht op goedkeuring.', 'stride')];
        }

        if (!$this->areUserTasksComplete($tasks)) {
            return [
                'actor' => 'user',
                'label' => sprintf(
                    __('Wacht op gebruiker: %s', 'stride'),
                    self::taskTypeLabel($this->getFirstOpenUserTask($tasks) ?? ''),
                ),
            ];
        }

        return [
            'actor' => 'admin',
            'label' => __('Wacht op jouw goedkeuring — de gebruiker heeft alle taken afgerond.', 'stride'),
        ];
    }

    /**
     * Human label for a task type (Dutch, admin-facing).
     */
    public static function taskTypeLabel(string $type): string
    {
        return match ($type) {
            'session_selection' => __('Sessiekeuze', 'stride'),
            'questionnaire'     => __('Intakevragen', 'stride'),
            'documents'         => __('Documenten uploaden', 'stride'),
            'approval'          => __('Goedkeuring', 'stride'),
            'post_evaluation'   => __('Evaluatie na opleiding', 'stride'),
            'post_documents'    => __('Documenten na opleiding', 'stride'),
            'post_approval'     => __('Aftekening', 'stride'),
            default             => $type,
        };
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
     * @return array{tasks: array, availability: array, descriptions: array<string, string>, total: int, completed: int, has_approval: bool, ready_for_approval: bool}
     */
    public function getTaskSummary(int $registrationId): array
    {
        $repo = $this->registrations;
        $registration = $repo->find($registrationId);

        if (!$registration) {
            return ['tasks' => [], 'descriptions' => [], 'total' => 0, 'completed' => 0, 'percentage' => 0];
        }

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

        // Resolve the offering this registration belongs to. Edition
        // registrations (and cascade children) carry edition_id; a trajectory
        // PARENT registration carries trajectory_id with edition_id NULL. The
        // documents instruction must be read from the CORRECT host CPT — do not
        // assume edition_id is always the offering.
        $trajectoryId = (int) ($registration->trajectory_id ?? 0);
        if ($editionId > 0) {
            $postId   = $editionId;
            $postType = 'vad_edition';
        } else {
            $postId   = $trajectoryId;
            $postType = 'vad_trajectory';
        }

        // Per-offering instruction for the document tasks present on this
        // registration. This is the single plugin->theme channel: the theme
        // reads $task_summary['descriptions'], never a repository.
        $descriptions = [];
        if ($postId > 0 && isset($tasks['documents'])) {
            $descriptions['documents'] = $this->documentsInstruction($postId, $postType, false);
        }
        if ($postId > 0 && isset($tasks['post_documents'])) {
            $descriptions['post_documents'] = $this->documentsInstruction($postId, $postType, true);
        }

        return [
            'tasks' => $tasks,
            'availability' => $availability,
            'descriptions' => $descriptions,
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
            RegistrationStatus::Confirmed->value,
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
