<?php

declare(strict_types=1);

namespace Stride\Modules\Attendance;

use Stride\Domain\AttendanceStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\SessionRepository;
use WP_Error;

/**
 * Attendance business logic.
 */
final class AttendanceService extends AbstractService
{
    public function __construct(
        private readonly AttendanceRepository $repository,
        private readonly SessionRepository $sessions,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Attendance Service',
            'description' => 'Manages session attendance tracking',
            'priority' => 25,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'attendance';
    }

    protected function init(): void
    {
        // Register repository as shared singleton
        ntdst_set(AttendanceRepository::class, fn() => $this->repository);
    }

    /**
     * Get the attendance repository for batch queries.
     */
    public function getRepository(): AttendanceRepository
    {
        return $this->repository;
    }

    // === Mark Attendance ===

    /**
     * Mark user as present for a session.
     */
    public function markPresent(int $sessionId, int $userId, ?int $markedBy = null): int|WP_Error
    {
        return $this->mark($sessionId, $userId, AttendanceStatus::Present, $markedBy);
    }

    /**
     * Mark user as absent for a session.
     */
    public function markAbsent(int $sessionId, int $userId, ?int $markedBy = null): int|WP_Error
    {
        return $this->mark($sessionId, $userId, AttendanceStatus::Absent, $markedBy);
    }

    /**
     * Mark user as excused for a session.
     */
    public function markExcused(int $sessionId, int $userId, ?int $markedBy = null): int|WP_Error
    {
        return $this->mark($sessionId, $userId, AttendanceStatus::Excused, $markedBy);
    }

    /**
     * Internal: record attendance and dispatch event.
     */
    private function mark(int $sessionId, int $userId, AttendanceStatus $status, ?int $markedBy = null): int|WP_Error
    {
        $editionId = (int) $this->sessions->getField($sessionId, 'edition_id', 0);

        if ($editionId === 0) {
            return new WP_Error('invalid_session', 'Session not found or has no edition');
        }

        $actor = $markedBy ?? get_current_user_id();

        $result = $this->repository->record(
            $sessionId,
            $userId,
            $status,
            $editionId,
            $actor
        );

        if (!is_wp_error($result)) {
            do_action('stride/attendance/marked', [
                'attendance_id' => $result,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'status' => $status->value,
                'edition_id' => $editionId,
                'marked_by' => $actor,
            ]);
        }

        return $result;
    }

    // === Queries ===

    /**
     * Get attendance status for user at session.
     */
    public function getStatus(int $sessionId, int $userId): ?AttendanceStatus
    {
        $record = $this->repository->findBySessionAndUser($sessionId, $userId);

        if (!$record) {
            return null;
        }

        return AttendanceStatus::tryFrom($record->status);
    }

    /**
     * Get attendance records for a session.
     *
     * @return array<array<string, mixed>>
     */
    public function getSessionAttendance(int $sessionId): array
    {
        $records = $this->repository->getBySession($sessionId);

        return array_map(function ($record) {
            return [
                'id' => (int) $record->id,
                'user_id' => (int) $record->user_id,
                'status' => $record->status,
                'status_enum' => AttendanceStatus::tryFrom($record->status),
                'marked_by' => $record->marked_by ? (int) $record->marked_by : null,
                'marked_at' => $record->marked_at,
            ];
        }, $records);
    }

    /**
     * Get user's attendance for an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserEditionAttendance(int $userId, int $editionId): array
    {
        $records = $this->repository->getByUserAndEdition($userId, $editionId);

        return array_map(function ($record) {
            return [
                'id' => (int) $record->id,
                'session_id' => (int) $record->session_id,
                'status' => $record->status,
                'status_enum' => AttendanceStatus::tryFrom($record->status),
                'marked_by' => $record->marked_by ? (int) $record->marked_by : null,
                'marked_at' => $record->marked_at,
            ];
        }, $records);
    }

    // === Statistics ===

    /**
     * Get hours attended by user in edition.
     */
    public function getHoursAttended(int $userId, int $editionId): float
    {
        $attendance = $this->repository->getByUserAndEdition($userId, $editionId);

        if (empty($attendance)) {
            return 0.0;
        }

        // Get all session IDs that count as attended
        $sessionIds = [];
        foreach ($attendance as $record) {
            $status = AttendanceStatus::tryFrom($record->status);
            if ($status?->countsAsAttended()) {
                $sessionIds[] = (int) $record->session_id;
            }
        }

        if (empty($sessionIds)) {
            return 0.0;
        }

        // Batch fetch session durations using a single query
        return $this->sessions->sumDurationHours($sessionIds);
    }

    /**
     * Get attendance rate for user in edition.
     *
     * @return float Percentage (0-100)
     */
    public function getAttendanceRate(int $userId, int $editionId): float
    {
        $totalSessions = $this->sessions->countByEdition($editionId);

        if ($totalSessions === 0) {
            return 0.0;
        }

        $attended = $this->repository->countAttended($userId, $editionId);

        return ($attended / $totalSessions) * 100;
    }

    // === Bulk Operations ===

    /**
     * Mark multiple users present for a session.
     *
     * @param array<int> $userIds
     * @return array<int, int|WP_Error> Map of userId => result
     */
    public function markMultiplePresent(int $sessionId, array $userIds, ?int $markedBy = null): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            $results[$userId] = $this->markPresent($sessionId, $userId, $markedBy);
        }

        return $results;
    }
}
