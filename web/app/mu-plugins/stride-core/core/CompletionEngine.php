<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use ntdst\Stride\FieldRegistry;
use WP_Error;

/**
 * Completion Engine
 *
 * Determines if a user has completed an edition based on attendance.
 * Triggers LearnDash course completion when attendance requirements are met.
 *
 * Completion Modes:
 * - attend_all: User must attend 100% of sessions
 * - attend_percentage: User must attend X% of sessions (e.g., 80%)
 * - attend_count: User must attend at least X sessions
 *
 * Available hooks:
 * - stride/completion/before_process (filter) - Override completion check
 * - stride/edition/completed (action) - After edition marked complete
 * - stride/course/marked_complete (action) - After LearnDash course completed
 *
 * @package stride\services\core
 */
class CompletionEngine implements \NTDST_Service_Meta
{
    // Completion modes
    public const MODE_ATTEND_ALL = 'attend_all';
    public const MODE_ATTEND_PERCENTAGE = 'attend_percentage';
    public const MODE_ATTEND_COUNT = 'attend_count';

    private SessionService $sessionService;
    private EditionService $editionService;
    private CourseService $courseService;
    private RegistrationRepository $registrationRepo;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Completion Engine',
            'description' => 'Edition completion based on attendance',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 15,
        ];
    }

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        ?SessionService $sessionService = null,
        ?EditionService $editionService = null,
        ?CourseService $courseService = null,
        ?RegistrationRepository $registrationRepo = null
    ) {
        $this->sessionService = $sessionService ?? $this->resolveService(SessionService::class);
        $this->editionService = $editionService ?? $this->resolveService(EditionService::class);
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->registrationRepo = $registrationRepo ?? $this->resolveService(RegistrationRepository::class);

        // Hook into attendance marking to auto-check completion
        add_action('stride/session/attendance_marked', [$this, 'onAttendanceMarked'], 10, 3);
        add_action('stride/session/batch_attendance_marked', [$this, 'onBatchAttendanceMarked'], 10, 2);
    }

    /**
     * Resolve service from DI container or create new instance
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        return new $class();
    }

    // ========================================
    // COMPLETION CHECKING
    // ========================================

    /**
     * Check if user has completed an edition based on attendance rules
     *
     * @param int $editionId Edition post ID
     * @param int $userId WordPress user ID
     * @return bool True if completed
     */
    public function isEditionComplete(int $editionId, int $userId): bool
    {
        // Allow filter to override
        $preCheck = apply_filters('stride/completion/before_check', null, $editionId, $userId);
        if ($preCheck !== null) {
            return (bool) $preCheck;
        }

        $sessions = $this->sessionService->getSessionsForEdition($editionId);
        $totalSessions = count($sessions);

        if ($totalSessions === 0) {
            // No sessions defined - consider complete by default
            return true;
        }

        $attended = $this->sessionService->countAttendedSessions($userId, $editionId);

        // Get completion mode and threshold
        $mode = $this->getCompletionMode($editionId);
        $threshold = $this->getCompletionThreshold($editionId);

        return match ($mode) {
            self::MODE_ATTEND_ALL => $attended >= $totalSessions,
            self::MODE_ATTEND_PERCENTAGE => ($attended / $totalSessions * 100) >= $threshold,
            self::MODE_ATTEND_COUNT => $attended >= $threshold,
            default => $attended >= $totalSessions,
        };
    }

    /**
     * Get completion mode for edition
     *
     * @param int $editionId Edition post ID
     * @return string Completion mode constant
     */
    public function getCompletionMode(int $editionId): string
    {
        $mode = get_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_MODE, true);
        return $mode ?: self::MODE_ATTEND_ALL;
    }

    /**
     * Get completion threshold for edition
     *
     * @param int $editionId Edition post ID
     * @return int Threshold value (percentage or count based on mode)
     */
    public function getCompletionThreshold(int $editionId): int
    {
        $threshold = get_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_THRESHOLD, true);
        return $threshold ? (int) $threshold : 100;
    }

    /**
     * Get completion status details for a user
     *
     * @param int $editionId Edition post ID
     * @param int $userId WordPress user ID
     * @return array Status details
     */
    public function getCompletionStatus(int $editionId, int $userId): array
    {
        $sessions = $this->sessionService->getSessionsForEdition($editionId);
        $totalSessions = count($sessions);
        $attended = $this->sessionService->countAttendedSessions($userId, $editionId);
        $mode = $this->getCompletionMode($editionId);
        $threshold = $this->getCompletionThreshold($editionId);

        $required = match ($mode) {
            self::MODE_ATTEND_ALL => $totalSessions,
            self::MODE_ATTEND_PERCENTAGE => (int) ceil($totalSessions * $threshold / 100),
            self::MODE_ATTEND_COUNT => $threshold,
            default => $totalSessions,
        };

        return [
            'total_sessions' => $totalSessions,
            'attended' => $attended,
            'required' => $required,
            'mode' => $mode,
            'threshold' => $threshold,
            'percentage' => $totalSessions > 0 ? round($attended / $totalSessions * 100, 1) : 0,
            'is_complete' => $this->isEditionComplete($editionId, $userId),
            'hours_attended' => $this->sessionService->getHoursAttended($userId, $editionId),
            'total_hours' => $this->sessionService->getTotalHours($editionId),
        ];
    }

    // ========================================
    // COMPLETION PROCESSING
    // ========================================

    /**
     * Process completion for a user in an edition
     *
     * Called after attendance is marked. Triggers LearnDash course completion
     * if the user has met attendance requirements.
     *
     * @param int $editionId Edition post ID
     * @param int $userId WordPress user ID
     * @return bool True if marked complete, false if not complete or already complete
     */
    public function processCompletion(int $editionId, int $userId): bool
    {
        // Allow filter to override processing
        $preProcess = apply_filters('stride/completion/before_process', null, $editionId, $userId);
        if ($preProcess !== null) {
            return (bool) $preProcess;
        }

        // Check if already complete in LearnDash
        $courseId = $this->editionService->getLinkedCourseId($editionId);
        if (!$courseId) {
            return false;
        }

        if ($this->courseService->isUserCompleted($userId, $courseId)) {
            return false; // Already complete
        }

        // Check completion requirements
        if (!$this->isEditionComplete($editionId, $userId)) {
            return false;
        }

        // Mark LearnDash course complete
        $result = $this->courseService->markComplete($userId, $courseId);
        if (is_wp_error($result)) {
            return false;
        }

        // Update registration status
        $registration = $this->registrationRepo->findByUserAndEdition($userId, $editionId);
        if ($registration) {
            $this->registrationRepo->update($registration['id'], [
                'status' => RegistrationRepository::STATUS_COMPLETED,
            ]);
        }

        // Fire completion action
        do_action('stride/edition/completed', $editionId, $userId, $courseId);

        return true;
    }

    /**
     * Process completion for all users in an edition
     *
     * Called when admin marks bulk attendance or edition ends.
     *
     * @param int $editionId Edition post ID
     * @return array Map of user_id => completion_result
     */
    public function processEditionCompletions(int $editionId): array
    {
        $registrations = $this->registrationRepo->getByEdition($editionId, [
            'status' => [
                RegistrationRepository::STATUS_CONFIRMED,
                RegistrationRepository::STATUS_PENDING,
            ],
        ]);

        $results = [];
        foreach ($registrations as $reg) {
            $userId = $reg['user_id'];
            $results[$userId] = $this->processCompletion($editionId, $userId);
        }

        return $results;
    }

    // ========================================
    // HOOKS
    // ========================================

    /**
     * Hook: After single attendance is marked
     */
    public function onAttendanceMarked(int $sessionId, int $userId, bool|string $status): void
    {
        // Only process if marked present
        if ($status !== true && $status !== 'present') {
            return;
        }

        // Get edition from session
        $session = $this->sessionService->getSession($sessionId);
        if (!$session || empty($session['edition_id'])) {
            return;
        }

        // Process completion (non-blocking)
        $this->processCompletion($session['edition_id'], $userId);
    }

    /**
     * Hook: After batch attendance is marked
     */
    public function onBatchAttendanceMarked(int $sessionId, array $userStatuses): void
    {
        // Get edition from session
        $session = $this->sessionService->getSession($sessionId);
        if (!$session || empty($session['edition_id'])) {
            return;
        }

        // Process completion for all users marked present
        foreach ($userStatuses as $userId => $status) {
            if ($status === AttendanceRepository::STATUS_PRESENT) {
                $this->processCompletion($session['edition_id'], (int) $userId);
            }
        }
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Get available completion modes with labels
     *
     * @return array Mode constant => label
     */
    public static function getCompletionModes(): array
    {
        return [
            self::MODE_ATTEND_ALL => __('Alle sessies bijwonen (100%)', 'stride'),
            self::MODE_ATTEND_PERCENTAGE => __('Percentage sessies bijwonen', 'stride'),
            self::MODE_ATTEND_COUNT => __('Minimaal aantal sessies bijwonen', 'stride'),
        ];
    }

    /**
     * Get threshold label based on mode
     *
     * @param string $mode Completion mode
     * @return string Label for threshold field
     */
    public static function getThresholdLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_ATTEND_PERCENTAGE => __('Minimum percentage (%)', 'stride'),
            self::MODE_ATTEND_COUNT => __('Minimum aantal sessies', 'stride'),
            default => __('Drempel', 'stride'),
        };
    }
}
