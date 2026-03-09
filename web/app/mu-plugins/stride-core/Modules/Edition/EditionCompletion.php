<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\CompletionMode;
use Stride\Modules\Attendance\AttendanceService;
use WP_Error;

/**
 * Edition completion business logic.
 *
 * Determines if a user has completed an edition based on attendance
 * and triggers LearnDash course completion when requirements are met.
 *
 * Plain class — owned by EditionService, not a standalone service.
 */
final class EditionCompletion
{
    /**
     * Check if user has completed an edition.
     */
    public function isComplete(int $editionId, int $userId): bool
    {
        $editionService = ntdst_get(EditionService::class);
        if (!$editionService->exists($editionId)) {
            return false;
        }

        $mode = $this->getCompletionMode($editionId);
        $threshold = $this->getCompletionThreshold($editionId);
        $totalSessions = ntdst_get(SessionService::class)->getSessionCount($editionId);
        $attended = ntdst_get(AttendanceService::class)->countAttended($userId, $editionId);

        return match ($mode) {
            CompletionMode::AttendAll => $attended >= $totalSessions,
            CompletionMode::Percentage => $totalSessions > 0 && ($attended / $totalSessions * 100) >= $threshold,
            CompletionMode::Count => $attended >= $threshold,
        };
    }

    /**
     * Get completion progress for user in edition.
     *
     * @return array<string, mixed>
     */
    public function getProgress(int $editionId, int $userId): array
    {
        $totalSessions = ntdst_get(SessionService::class)->getSessionCount($editionId);
        $attended = ntdst_get(AttendanceService::class)->countAttended($userId, $editionId);
        $mode = $this->getCompletionMode($editionId);
        $threshold = $this->getCompletionThreshold($editionId);
        $isComplete = $this->isComplete($editionId, $userId);

        $required = match ($mode) {
            CompletionMode::AttendAll => $totalSessions,
            CompletionMode::Percentage => (int) ceil($totalSessions * $threshold / 100),
            CompletionMode::Count => $threshold,
        };

        return [
            'total_sessions' => $totalSessions,
            'attended' => $attended,
            'required' => $required,
            'remaining' => max(0, $required - $attended),
            'percentage' => $totalSessions > 0 ? round($attended / $totalSessions * 100, 1) : 0,
            'is_complete' => $isComplete,
            'mode' => $mode->value,
            'threshold' => $threshold,
        ];
    }

    /**
     * Get completion mode for edition.
     */
    public function getCompletionMode(int $editionId): CompletionMode
    {
        $modeValue = ntdst_data()->get('vad_edition')->getMeta($editionId, 'completion_mode');

        if ($modeValue === null || $modeValue === '') {
            return CompletionMode::AttendAll;
        }

        return CompletionMode::tryFrom($modeValue) ?? CompletionMode::AttendAll;
    }

    /**
     * Get completion threshold for edition.
     *
     * For percentage mode: 0-100
     * For count mode: minimum sessions
     * For attend_all: ignored
     */
    public function getCompletionThreshold(int $editionId): int
    {
        $threshold = ntdst_data()->get('vad_edition')->getMeta($editionId, 'completion_threshold');

        return $threshold ? (int) $threshold : 100;
    }

    /**
     * Process completion for user in edition.
     *
     * Checks attendance and triggers LearnDash course completion if requirements met.
     * If post-course tasks are configured, initializes them and defers LD completion.
     */
    public function processCompletion(int $editionId, int $userId): true|WP_Error
    {
        if (!$this->isComplete($editionId, $userId)) {
            return new WP_Error('not_complete', 'User has not met completion requirements');
        }

        $editionService = ntdst_get(EditionService::class);
        if (!$editionService->exists($editionId)) {
            return new WP_Error('invalid_edition', 'Edition not found');
        }

        $courseId = $editionService->getCourseId($editionId);
        if (!$courseId) {
            return new WP_Error('no_course', 'Edition has no linked course');
        }

        // Check if post-course tasks are configured — defer LD completion if so
        $enrollmentCompletion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
        if ($enrollmentCompletion->hasPostCourseRequirements($editionId, 'vad_edition')) {
            $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
            $reg = $repo->findByUserAndEdition($userId, $editionId);
            if ($reg) {
                $enrollmentCompletion->initializePostCourseTasks((int) $reg->id, $editionId);
                do_action('stride/completion/attendance_complete', [
                    'edition_id' => $editionId,
                    'user_id' => $userId,
                    'registration_id' => (int) $reg->id,
                ]);
            }
            return true; // Defer LD completion — will happen when all post-course tasks done
        }

        $lmsAdapter = ntdst_get(LMSAdapterInterface::class);

        // Check if already complete in LearnDash
        if ($lmsAdapter->isComplete($userId, $courseId)) {
            return true;
        }

        // Mark complete in LearnDash
        if (function_exists('learndash_process_mark_complete')) {
            learndash_process_mark_complete($userId, $courseId);
        }

        do_action('stride/completion/completed', [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        return true;
    }

    /**
     * Mark course complete in LearnDash (final step, no task check).
     *
     * Called after all post-course tasks are done.
     */
    public function processCompletionFinal(int $editionId, int $userId): true|WP_Error
    {
        $editionService = ntdst_get(EditionService::class);
        $courseId = $editionService->getCourseId($editionId);
        if (!$courseId) {
            return new WP_Error('no_course', 'Edition has no linked course');
        }

        $lmsAdapter = ntdst_get(LMSAdapterInterface::class);
        if ($lmsAdapter->isComplete($userId, $courseId)) {
            return true;
        }

        if (function_exists('learndash_process_mark_complete')) {
            learndash_process_mark_complete($userId, $courseId);
        }

        do_action('stride/completion/completed', [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        return true;
    }

    /**
     * Handle attendance marked event — auto-complete if threshold met.
     *
     * @param array<string, mixed> $data
     */
    public function onAttendanceMarked(array $data): void
    {
        $editionId = $data['edition_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;

        if ($editionId && $userId && $this->isComplete($editionId, $userId)) {
            $this->processCompletion($editionId, $userId);
        }
    }

    /**
     * Set completion mode for edition.
     */
    public function setCompletionMode(int $editionId, CompletionMode $mode): void
    {
        ntdst_data()->get('vad_edition')->updateMetaBatch($editionId, ['completion_mode' => $mode->value]);
    }

    /**
     * Set completion threshold for edition.
     */
    public function setCompletionThreshold(int $editionId, int $threshold): void
    {
        ntdst_data()->get('vad_edition')->updateMetaBatch($editionId, ['completion_threshold' => $threshold]);
    }
}
