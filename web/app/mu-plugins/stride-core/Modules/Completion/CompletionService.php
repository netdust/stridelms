<?php

declare(strict_types=1);

namespace Stride\Modules\Completion;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\CompletionMode;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use WP_Error;

/**
 * Edition completion business logic.
 *
 * Determines if a user has completed an edition based on attendance.
 */
final class CompletionService extends AbstractService
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly EditionService $editionService,
        private readonly SessionService $sessionService,
        private readonly LMSAdapterInterface $lmsAdapter,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Completion Service',
            'description' => 'Manages edition completion based on attendance',
            'priority' => 30,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'completion';
    }

    protected function init(): void
    {
        // Listen for attendance events to check completion
        add_action('stride/attendance/marked', [$this, 'onAttendanceMarked']);
    }

    // === Completion Checks ===

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
        $attended = $this->attendanceService->countAttended($userId, $editionId);

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
        $attended = $this->attendanceService->countAttended($userId, $editionId);
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

    // === Process Completion ===

    /**
     * Process completion for user in edition.
     *
     * Checks attendance and triggers LearnDash course completion if requirements met.
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

        // Check if already complete in LearnDash
        if ($this->lmsAdapter->isComplete($userId, $courseId)) {
            return true;
        }

        // Mark complete in LearnDash
        // Note: LearnDash uses learndash_process_mark_complete() for this
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
     * Handle attendance marked event.
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

    // === Configuration ===

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
