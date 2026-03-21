<?php

declare(strict_types=1);

namespace Stride\Modules\Attendance;

use Stride\Domain\AttendanceStatus;
use WP_Error;

/**
 * Repository for attendance data access.
 */
final class AttendanceRepository
{
    private function table(): string
    {
        return AttendanceTable::getTableName();
    }

    /**
     * Record attendance for a user at a session.
     *
     * @return int|WP_Error Attendance record ID or error
     */
    public function record(int $sessionId, int $userId, AttendanceStatus $status, ?int $editionId = null, ?int $markedBy = null): int|WP_Error
    {
        global $wpdb;

        // If edition_id not provided, look it up from session via Data Manager
        if ($editionId === null) {
            $editionId = (int) ntdst_data()->get('vad_session')->getMeta($sessionId, 'edition_id');
            if ($editionId === 0) {
                return new WP_Error('missing_edition', 'Could not determine edition for session');
            }
        }

        // Check for existing record
        $existing = $this->findBySessionAndUser($sessionId, $userId);

        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $this->table(),
                [
                    'status' => $status->value,
                    'marked_by' => $markedBy,
                    'marked_at' => current_time('mysql'),
                ],
                ['id' => $existing->id]
            );

            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update attendance');
            }

            return (int) $existing->id;
        }

        // Insert new record
        $result = $wpdb->insert($this->table(), [
            'edition_id' => $editionId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => $status->value,
            'marked_by' => $markedBy,
        ]);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to record attendance');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find attendance record by ID.
     */
    public function find(int $id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ));
    }

    /**
     * Find attendance record by session and user.
     */
    public function findBySessionAndUser(int $sessionId, int $userId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE session_id = %d AND user_id = %d",
            $sessionId,
            $userId
        ));
    }

    /**
     * Get all attendees for a session.
     *
     * @return array<object>
     */
    public function getBySession(int $sessionId, ?AttendanceStatus $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE session_id = %d";
        $params = [$sessionId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status->value;
        }

        $sql .= " ORDER BY marked_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get attendance records for a user in an edition.
     *
     * @return array<object>
     */
    public function getByUserAndEdition(int $userId, int $editionId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND edition_id = %d ORDER BY session_id ASC",
            $userId,
            $editionId
        ));
    }

    /**
     * Get attendance records for multiple users.
     *
     * @param array<int> $userIds
     * @return array<object>
     */
    public function getByUsers(array $userIds, ?int $editionId = null): array
    {
        if (empty($userIds)) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        $params = $userIds;

        $sql = "SELECT * FROM {$this->table()} WHERE user_id IN ({$placeholders})";

        if ($editionId !== null) {
            $sql .= " AND edition_id = %d";
            $params[] = $editionId;
        }

        $sql .= " ORDER BY marked_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get all attendance records for a user.
     *
     * @return array<object>
     */
    public function getByUser(int $userId, ?AttendanceStatus $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE user_id = %d";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status->value;
        }

        $sql .= " ORDER BY marked_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count attended sessions for user in edition.
     */
    public function countAttended(int $userId, int $editionId): int
    {
        global $wpdb;

        $statuses = AttendanceStatus::attendedValues();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE user_id = %d AND edition_id = %d AND status IN ($statuses)",
            $userId,
            $editionId
        ));
    }

    /**
     * Count total attendance records for user in edition.
     */
    public function countRecords(int $userId, int $editionId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE user_id = %d AND edition_id = %d",
            $userId,
            $editionId
        ));
    }

    /**
     * Check if user has any attendance record for session.
     */
    public function hasRecord(int $sessionId, int $userId): bool
    {
        return $this->findBySessionAndUser($sessionId, $userId) !== null;
    }

    /**
     * Check if user is marked present for session.
     */
    public function isPresent(int $sessionId, int $userId): bool
    {
        $record = $this->findBySessionAndUser($sessionId, $userId);

        if (!$record) {
            return false;
        }

        $status = AttendanceStatus::tryFrom($record->status);
        return $status?->countsAsAttended() ?? false;
    }

    /**
     * Get user IDs marked present for a session.
     *
     * @return array<int>
     */
    public function getPresentUserIds(int $sessionId): array
    {
        global $wpdb;

        $statuses = AttendanceStatus::attendedValues();

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$this->table()} WHERE session_id = %d AND status IN ($statuses)",
            $sessionId
        ));

        return array_map('intval', $results);
    }

    /**
     * Delete attendance record.
     */
    public function delete(int $id): bool
    {
        global $wpdb;

        return $wpdb->delete($this->table(), ['id' => $id]) !== false;
    }

    /**
     * Delete all attendance records for a session.
     */
    public function deleteBySession(int $sessionId): bool
    {
        global $wpdb;

        return $wpdb->delete($this->table(), ['session_id' => $sessionId]) !== false;
    }
}
