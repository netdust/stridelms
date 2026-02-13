<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

/**
 * Attendance Repository
 *
 * Manages attendance data in a dedicated database table for:
 * - Concurrent check-ins without race conditions
 * - Audit trails (who marked, when)
 * - Efficient queries (indexed by session, user, edition)
 *
 * This is a repository class - instantiated where needed, no hook registration.
 *
 * @package stride\services\core
 */
class AttendanceRepository
{
    public const TABLE = 'vad_attendance';

    // Status constants
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_EXCUSED = 'excused';

    /**
     * Get the full table name with prefix
     */
    public function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Check if the table exists
     */
    public function tableExists(): bool
    {
        global $wpdb;
        $table = $this->getTableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Create the attendance table
     */
    public function createTable(): void
    {
        global $wpdb;

        $table = $this->getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            edition_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('present','absent','excused') DEFAULT 'present',
            marked_by BIGINT UNSIGNED NULL,
            marked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_user (session_id, user_id),
            INDEX idx_user_edition (user_id, edition_id),
            INDEX idx_edition (edition_id),
            UNIQUE KEY unique_session_user (session_id, user_id)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Mark attendance for a user in a session
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert.
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @param string $status One of STATUS_PRESENT, STATUS_ABSENT, STATUS_EXCUSED
     * @param int|null $markedBy User ID who marked (null for current user)
     * @return bool True on success
     */
    public function mark(int $sessionId, int $userId, string $status = self::STATUS_PRESENT, ?int $markedBy = null): bool
    {
        global $wpdb;

        // Validate status
        if (!in_array($status, [self::STATUS_PRESENT, self::STATUS_ABSENT, self::STATUS_EXCUSED], true)) {
            return false;
        }

        // Get edition_id from session
        $editionId = $this->getEditionIdFromSession($sessionId);
        if (!$editionId) {
            return false;
        }

        $markedBy = $markedBy ?? get_current_user_id();
        $table = $this->getTableName();

        // Atomic upsert
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (edition_id, session_id, user_id, status, marked_by, marked_at)
             VALUES (%d, %d, %d, %s, %d, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by), marked_at = NOW()",
            $editionId,
            $sessionId,
            $userId,
            $status,
            $markedBy
        ));

        return $result !== false;
    }

    /**
     * Mark multiple users for a session in a single operation
     *
     * @param int $sessionId Session post ID
     * @param array $userStatuses Array of [user_id => status]
     * @param int|null $markedBy User ID who marked
     * @return int Number of rows affected
     */
    public function batchMark(int $sessionId, array $userStatuses, ?int $markedBy = null): int
    {
        if (empty($userStatuses)) {
            return 0;
        }

        global $wpdb;

        $editionId = $this->getEditionIdFromSession($sessionId);
        if (!$editionId) {
            return 0;
        }

        $markedBy = $markedBy ?? get_current_user_id();
        $table = $this->getTableName();

        // Build multi-row INSERT
        $values = [];
        $placeholders = [];

        foreach ($userStatuses as $userId => $status) {
            if (!in_array($status, [self::STATUS_PRESENT, self::STATUS_ABSENT, self::STATUS_EXCUSED], true)) {
                continue;
            }
            $placeholders[] = '(%d, %d, %d, %s, %d, NOW())';
            $values[] = $editionId;
            $values[] = $sessionId;
            $values[] = (int) $userId;
            $values[] = $status;
            $values[] = $markedBy;
        }

        if (empty($placeholders)) {
            return 0;
        }

        $placeholderStr = implode(', ', $placeholders);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (edition_id, session_id, user_id, status, marked_by, marked_at)
             VALUES {$placeholderStr}
             ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by), marked_at = NOW()",
            ...$values
        ));

        return $result !== false ? (int) $result : 0;
    }

    /**
     * Get attendance status for a user in a session
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return string|null Status or null if not recorded
     */
    public function getStatus(int $sessionId, int $userId): ?string
    {
        global $wpdb;
        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE session_id = %d AND user_id = %d",
            $sessionId,
            $userId
        ));

        return $status ?: null;
    }

    /**
     * Check if a user is present for a session
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return bool True if marked present
     */
    public function isPresent(int $sessionId, int $userId): bool
    {
        return $this->getStatus($sessionId, $userId) === self::STATUS_PRESENT;
    }

    /**
     * Get all attendees (user IDs) for a session
     *
     * @param int $sessionId Session post ID
     * @param string|null $status Filter by status (null for all present)
     * @return array Array of user IDs
     */
    public function getAttendeesForSession(int $sessionId, ?string $status = self::STATUS_PRESENT): array
    {
        global $wpdb;
        $table = $this->getTableName();

        if ($status === null) {
            // Return all users with any status
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE session_id = %d",
                $sessionId
            ));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE session_id = %d AND status = %s",
                $sessionId,
                $status
            ));
        }

        return array_map('intval', $results);
    }

    /**
     * Get attendance records for a session with full details
     *
     * @param int $sessionId Session post ID
     * @return array Array of attendance records
     */
    public function getSessionAttendance(int $sessionId): array
    {
        global $wpdb;
        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, status, marked_by, marked_at FROM {$table} WHERE session_id = %d",
            $sessionId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Get all attendance records for a user in an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return array Array of [session_id => status]
     */
    public function getAttendanceForUser(int $userId, int $editionId): array
    {
        global $wpdb;
        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, status FROM {$table} WHERE user_id = %d AND edition_id = %d",
            $userId,
            $editionId
        ), ARRAY_A);

        $attendance = [];
        foreach ($results as $row) {
            $attendance[(int) $row['session_id']] = $row['status'];
        }

        return $attendance;
    }

    /**
     * Count sessions attended by a user in an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return int Number of sessions with present status
     */
    public function countAttendedSessions(int $userId, int $editionId): int
    {
        global $wpdb;
        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND edition_id = %d AND status = %s",
            $userId,
            $editionId,
            self::STATUS_PRESENT
        ));

        return (int) $count;
    }

    /**
     * Batch get attendees for multiple sessions (prevents N+1 queries)
     *
     * @param array $sessionIds Array of session post IDs
     * @param string|null $status Filter by status (null for present only)
     * @return array Map of session_id => array of user IDs
     */
    public function batchGetAttendees(array $sessionIds, ?string $status = self::STATUS_PRESENT): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        global $wpdb;
        $table = $this->getTableName();

        $placeholders = implode(',', array_fill(0, count($sessionIds), '%d'));

        if ($status === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT session_id, user_id FROM {$table} WHERE session_id IN ({$placeholders})",
                ...$sessionIds
            ), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT session_id, user_id FROM {$table} WHERE session_id IN ({$placeholders}) AND status = %s",
                ...array_merge($sessionIds, [$status])
            ), ARRAY_A);
        }

        // Build map
        $attendeesMap = array_fill_keys($sessionIds, []);
        foreach ($results as $row) {
            $attendeesMap[(int) $row['session_id']][] = (int) $row['user_id'];
        }

        return $attendeesMap;
    }

    /**
     * Delete attendance record
     *
     * @param int $sessionId Session post ID
     * @param int $userId WordPress user ID
     * @return bool True on success
     */
    public function delete(int $sessionId, int $userId): bool
    {
        global $wpdb;
        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            ['session_id' => $sessionId, 'user_id' => $userId],
            ['%d', '%d']
        );

        return $result !== false;
    }

    /**
     * Delete all attendance for a session (when session is deleted)
     *
     * @param int $sessionId Session post ID
     * @return int Number of rows deleted
     */
    public function deleteForSession(int $sessionId): int
    {
        global $wpdb;
        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            ['session_id' => $sessionId],
            ['%d']
        );

        return $result !== false ? (int) $result : 0;
    }

    /**
     * Get edition ID from session
     */
    private function getEditionIdFromSession(int $sessionId): ?int
    {
        $editionId = get_post_meta($sessionId, 'edition_id', true);
        return $editionId ? (int) $editionId : null;
    }

    /**
     * Migrate attendance data from postmeta to table
     *
     * @param int $sessionId Session to migrate
     * @return int Number of records migrated
     */
    public function migrateFromPostmeta(int $sessionId): int
    {
        // Get legacy attendees from postmeta
        $attendees = get_post_meta($sessionId, 'attendees', true);
        if (!$attendees) {
            return 0;
        }

        // Handle JSON string
        if (is_string($attendees)) {
            $attendees = json_decode($attendees, true) ?: [];
        }

        if (!is_array($attendees) || empty($attendees)) {
            return 0;
        }

        // Convert to status array (legacy only tracked present)
        $userStatuses = [];
        foreach ($attendees as $userId) {
            $userStatuses[(int) $userId] = self::STATUS_PRESENT;
        }

        return $this->batchMark($sessionId, $userStatuses, 0); // 0 = system migration
    }
}
