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
 * Same-module dependencies (EditionService, SessionService) are constructor-injected.
 * Cross-module dependencies (Attendance, Enrollment, LMS) are resolved lazily via ntdst_get().
 */
final class EditionCompletion
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editions,
        private readonly SessionService $sessionService,
    ) {}

    /**
     * Check if user has completed an edition.
     */
    public function isComplete(int $editionId, int $userId): bool
    {
        if (!$this->editionService->exists($editionId)) {
            return false;
        }

        $mode = $this->getCompletionMode($editionId);
        $threshold = $this->getCompletionThreshold($editionId);
        $totalSessions = $this->sessionService->getSessionCount($editionId);
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
        $totalSessions = $this->sessionService->getSessionCount($editionId);
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
        $modeValue = $this->editions->getField($editionId, 'completion_mode');

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
        $threshold = $this->editions->getField($editionId, 'completion_threshold');

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

        if (!$this->editionService->exists($editionId)) {
            return new WP_Error('invalid_edition', 'Edition not found');
        }

        $courseId = $this->editionService->getCourseId($editionId);
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

        if ($lmsAdapter->isComplete($userId, $courseId)) {
            return true;
        }

        // LMS enforces its own completion rules (lessons, quizzes, etc.) —
        // if the user has unfinished content, markComplete is a no-op and
        // the LMS's completion action never fires, so the Stride
        // registration stays at `confirmed`.
        //
        // Implication for in-person courses: their linked LMS course MUST
        // be content-free (no required lessons/quizzes), otherwise
        // attendance alone won't transition the registration to `completed`.
        $lmsAdapter->markComplete($userId, $courseId);

        do_action('stride/completion/completed', [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        return true;
    }

    /**
     * Mark course complete in the LMS (final step, no task check).
     *
     * Called after all post-course tasks are done.
     */
    public function processCompletionFinal(int $editionId, int $userId): true|WP_Error
    {
        $courseId = $this->editionService->getCourseId($editionId);
        if (!$courseId) {
            return new WP_Error('no_course', 'Edition has no linked course');
        }

        $lmsAdapter = ntdst_get(LMSAdapterInterface::class);
        if ($lmsAdapter->isComplete($userId, $courseId)) {
            return true;
        }

        $lmsAdapter->markComplete($userId, $courseId);

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
            $result = $this->processCompletion($editionId, $userId);
            if ($result instanceof WP_Error) {
                ntdst_log('enrollment')->error('Auto-completion after attendance failed', [
                    'edition_id' => $editionId,
                    'user_id'    => $userId,
                    'error'      => $result->get_error_code() . ': ' . $result->get_error_message(),
                ]);
            }
        }
    }

    /**
     * Handle LearnDash native course completion — sync back to Stride registration.
     *
     * @param array{user: \WP_User, course: \WP_Post, progress: array} $data
     */
    public function onLearnDashCourseCompleted(array $data): void
    {
        $userId = $data['user']->ID ?? 0;
        $courseId = $data['course']->ID ?? 0;

        if (!$userId || !$courseId) {
            return;
        }

        // Find editions linked to this course. EditionRepository returns
        // associative arrays with 'id' (lowercase), not WP_Post objects.
        $editions = $this->editions->findByCourse($courseId);

        if (empty($editions)) {
            return;
        }

        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);

        foreach ($editions as $edition) {
            $editionId = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
            if (!$editionId) {
                continue;
            }
            $reg = $repo->findByUserAndEdition($userId, $editionId);
            if ($reg && $reg->status === 'confirmed') {
                $repo->updateStatus((int) $reg->id, \Stride\Domain\RegistrationStatus::Completed);
            }
        }
    }

    /**
     * Set completion mode for edition.
     */
    public function setCompletionMode(int $editionId, CompletionMode $mode): void
    {
        $this->editions->updateMeta($editionId, ['completion_mode' => $mode->value]);
    }

    /**
     * Set completion threshold for edition.
     */
    public function setCompletionThreshold(int $editionId, int $threshold): void
    {
        $this->editions->updateMeta($editionId, ['completion_threshold' => $threshold]);
    }
}
