<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Trajectory Enrollment Repository
 *
 * Manages user-trajectory enrollments in a custom table.
 * Tracks enrollment status, deadlines, and completion.
 *
 * Table: wp_vad_trajectory_enrollments
 *
 * AUTHORIZATION: This repository layer does not perform user authorization checks.
 * Calling code (controllers, API endpoints) MUST verify that the current user
 * has permission to access/modify enrollment data before calling these methods.
 *
 * Examples of required authorization in calling code:
 * - Admin: current_user_can('manage_options')
 * - User's own enrollment: get_current_user_id() === $enrollment['user_id']
 * - Group leader: user can manage the trajectory's group
 *
 * @package stride\services\core
 */
class TrajectoryEnrollmentRepository
{
    public const TABLE_NAME = 'vad_trajectory_enrollments';

    // Enrollment statuses
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Valid status values (ENUM whitelist)
     */
    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Request-level cache for lookups
     * @var array<string, array|null>
     */
    private static array $lookupCache = [];

    /**
     * Validate status is a valid ENUM value
     *
     * @param string $status Status to validate
     * @return true|WP_Error
     */
    private function validateStatus(string $status): true|WP_Error
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return new WP_Error(
                'invalid_status',
                sprintf(
                    __('Ongeldige status "%s". Geldige waarden: %s', 'stride'),
                    $status,
                    implode(', ', self::VALID_STATUSES)
                )
            );
        }
        return true;
    }

    // ========================================
    // TABLE SETUP
    // ========================================

    /**
     * Get full table name with prefix
     */
    public function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Check if table exists
     */
    public function tableExists(): bool
    {
        global $wpdb;
        $table = $this->getTableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Create the trajectory enrollments table
     */
    public function createTable(): void
    {
        global $wpdb;

        $table = $this->getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trajectory_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('active','completed','expired','cancelled') DEFAULT 'active',
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deadline_at DATETIME NULL,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            notes TEXT NULL,
            elective_choices TEXT NULL,
            UNIQUE KEY unique_trajectory_user (trajectory_id, user_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_trajectory_status (trajectory_id, status),
            INDEX idx_status_deadline (status, deadline_at)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ========================================
    // CRUD OPERATIONS
    // ========================================

    /**
     * Create a new trajectory enrollment
     *
     * @param array{
     *   trajectory_id: int,
     *   user_id: int,
     *   status?: string,
     *   deadline_at?: string|null,
     *   notes?: string|null
     * } $data Enrollment data
     * @return int|WP_Error Enrollment ID or error
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;

        // Validate required fields
        if (empty($data['trajectory_id']) || empty($data['user_id'])) {
            return new WP_Error('missing_fields', __('trajectory_id en user_id zijn verplicht.', 'stride'));
        }

        // Check for existing enrollment
        $existing = $this->findByUserAndTrajectory((int) $data['user_id'], (int) $data['trajectory_id']);
        if ($existing) {
            return new WP_Error('already_exists', __('Traject-inschrijving bestaat al.', 'stride'), [
                'existing_id' => $existing['id'],
                'existing_status' => $existing['status'],
            ]);
        }

        // Validate status ENUM
        $status = sanitize_text_field($data['status'] ?? self::STATUS_ACTIVE);
        $statusValidation = $this->validateStatus($status);
        if (is_wp_error($statusValidation)) {
            return $statusValidation;
        }

        $table = $this->getTableName();

        // Handle elective choices
        $electiveChoices = null;
        if (!empty($data['elective_choices']) && is_array($data['elective_choices'])) {
            $electiveChoices = wp_json_encode($data['elective_choices']);
        }

        $insertData = [
            'trajectory_id' => absint($data['trajectory_id']),
            'user_id' => absint($data['user_id']),
            'status' => $status,
            'enrolled_at' => current_time('mysql'),
            'deadline_at' => $data['deadline_at'] ?? null,
            'notes' => !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
            'elective_choices' => $electiveChoices,
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert($table, $insertData, [
            '%d', '%d', '%s', '%s', '%s', '%s', '%s'
        ]);

        if ($result === false) {
            // Log actual error for debugging, return generic message to user
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('TrajectoryEnrollmentRepository::create - DB error: ' . $wpdb->last_error);
            }
            return new WP_Error('db_error', __('Kon inschrijving niet aanmaken.', 'stride'));
        }

        $enrollmentId = (int) $wpdb->insert_id;

        // Invalidate cache
        $this->invalidateCache((int) $data['trajectory_id'], (int) $data['user_id']);

        do_action('stride/trajectory_enrollment/created', $enrollmentId, $insertData);

        return $enrollmentId;
    }

    /**
     * Get an enrollment by ID
     *
     * @param int $enrollmentId Enrollment ID
     * @return array|null Enrollment data or null
     */
    public function get(int $enrollmentId): ?array
    {
        global $wpdb;

        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $enrollmentId),
            ARRAY_A
        );

        return $row ? $this->formatRow($row) : null;
    }

    /**
     * Update an enrollment
     *
     * @param int $enrollmentId Enrollment ID
     * @param array $data Data to update
     * @return true|WP_Error
     */
    public function update(int $enrollmentId, array $data): true|WP_Error
    {
        global $wpdb;

        // Check exists
        $existing = $this->get($enrollmentId);
        if (!$existing) {
            return new WP_Error('not_found', __('Traject-inschrijving niet gevonden.', 'stride'));
        }

        $table = $this->getTableName();

        // Build update data
        $updateData = [];
        $format = [];

        if (isset($data['status'])) {
            $status = sanitize_text_field($data['status']);
            $statusValidation = $this->validateStatus($status);
            if (is_wp_error($statusValidation)) {
                return $statusValidation;
            }
            $updateData['status'] = $status;
            $format[] = '%s';
        }

        if (array_key_exists('deadline_at', $data)) {
            $updateData['deadline_at'] = $data['deadline_at'];
            $format[] = '%s';
        }

        if (array_key_exists('completed_at', $data)) {
            $updateData['completed_at'] = $data['completed_at'];
            $format[] = '%s';
        }

        if (array_key_exists('cancelled_at', $data)) {
            $updateData['cancelled_at'] = $data['cancelled_at'];
            $format[] = '%s';
        }

        if (array_key_exists('notes', $data)) {
            $updateData['notes'] = $data['notes'] ? sanitize_textarea_field($data['notes']) : null;
            $format[] = '%s';
        }

        if (array_key_exists('elective_choices', $data)) {
            if (is_array($data['elective_choices'])) {
                $updateData['elective_choices'] = wp_json_encode($data['elective_choices']);
            } else {
                $updateData['elective_choices'] = $data['elective_choices'];
            }
            $format[] = '%s';
        }

        if (empty($updateData)) {
            return true; // Nothing to update
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table,
            $updateData,
            ['id' => $enrollmentId],
            $format,
            ['%d']
        );

        if ($result === false) {
            // Log actual error for debugging, return generic message to user
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('TrajectoryEnrollmentRepository::update - DB error: ' . $wpdb->last_error);
            }
            return new WP_Error('db_error', __('Kon inschrijving niet bijwerken.', 'stride'));
        }

        // Invalidate cache
        $this->invalidateCache((int) $existing['trajectory_id'], (int) $existing['user_id']);

        do_action('stride/trajectory_enrollment/updated', $enrollmentId, $updateData);

        return true;
    }

    /**
     * Delete an enrollment
     *
     * @param int $enrollmentId Enrollment ID
     * @return true|WP_Error
     */
    public function delete(int $enrollmentId): true|WP_Error
    {
        global $wpdb;

        // Get enrollment data for cache invalidation
        $existing = $this->get($enrollmentId);

        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table, ['id' => $enrollmentId], ['%d']);

        if ($result === false) {
            // Log actual error for debugging, return generic message to user
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('TrajectoryEnrollmentRepository::delete - DB error: ' . $wpdb->last_error);
            }
            return new WP_Error('db_error', __('Kon inschrijving niet verwijderen.', 'stride'));
        }

        // Invalidate cache
        if ($existing) {
            $this->invalidateCache((int) $existing['trajectory_id'], (int) $existing['user_id']);
        }

        do_action('stride/trajectory_enrollment/deleted', $enrollmentId);

        return true;
    }

    // ========================================
    // QUERY METHODS
    // ========================================

    /**
     * Find enrollment by user and trajectory
     *
     * Uses both request-level and persistent object caching for performance.
     *
     * @param int $userId WordPress user ID
     * @param int $trajectoryId Trajectory post ID
     * @return array|null Enrollment data or null
     */
    public function findByUserAndTrajectory(int $userId, int $trajectoryId): ?array
    {
        $cacheKey = "lookup_{$userId}_{$trajectoryId}";

        // Check request-level cache first
        if (array_key_exists($cacheKey, self::$lookupCache)) {
            return self::$lookupCache[$cacheKey];
        }

        // Check object cache
        $objectCacheKey = "stride_traj_enroll_{$userId}_{$trajectoryId}";
        $cached = wp_cache_get($objectCacheKey, 'stride');
        if ($cached !== false) {
            self::$lookupCache[$cacheKey] = $cached === 'NULL' ? null : $cached;
            return self::$lookupCache[$cacheKey];
        }

        global $wpdb;

        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE user_id = %d AND trajectory_id = %d",
                $userId,
                $trajectoryId
            ),
            ARRAY_A
        );

        $result = $row ? $this->formatRow($row) : null;

        // Store in both caches (use 'NULL' string for null values in object cache)
        self::$lookupCache[$cacheKey] = $result;
        wp_cache_set($objectCacheKey, $result ?? 'NULL', 'stride', 300);

        return $result;
    }

    /**
     * Get all enrollments for a trajectory
     *
     * @param int $trajectoryId Trajectory post ID
     * @param string|null $status Filter by status (null = all)
     * @return array Array of enrollment data
     */
    public function getByTrajectory(int $trajectoryId, ?string $status = null): array
    {
        global $wpdb;

        $table = $this->getTableName();

        if ($status !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE trajectory_id = %d AND status = %s ORDER BY enrolled_at ASC",
                    $trajectoryId,
                    $status
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE trajectory_id = %d ORDER BY enrolled_at ASC",
                    $trajectoryId
                ),
                ARRAY_A
            );
        }

        return array_map([$this, 'formatRow'], $rows ?: []);
    }

    /**
     * Get all enrollments for a user
     *
     * @param int $userId WordPress user ID
     * @param string|null $status Filter by status (null = all)
     * @return array Array of enrollment data
     */
    public function getByUser(int $userId, ?string $status = null): array
    {
        global $wpdb;

        $table = $this->getTableName();

        if ($status !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE user_id = %d AND status = %s ORDER BY enrolled_at DESC",
                    $userId,
                    $status
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE user_id = %d ORDER BY enrolled_at DESC",
                    $userId
                ),
                ARRAY_A
            );
        }

        return array_map([$this, 'formatRow'], $rows ?: []);
    }

    /**
     * Get active enrollments for a user
     *
     * @param int $userId WordPress user ID
     * @return array Array of active enrollment data
     */
    public function getActiveByUser(int $userId): array
    {
        return $this->getByUser($userId, self::STATUS_ACTIVE);
    }

    /**
     * Get enrollments with expired deadlines
     *
     * @return array Array of expired enrollment data
     */
    public function getExpiredEnrollments(): array
    {
        global $wpdb;

        $table = $this->getTableName();
        $now = current_time('mysql');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s AND deadline_at IS NOT NULL AND deadline_at < %s",
                self::STATUS_ACTIVE,
                $now
            ),
            ARRAY_A
        );

        return array_map([$this, 'formatRow'], $rows ?: []);
    }

    /**
     * Count enrollments for a trajectory
     *
     * @param int $trajectoryId Trajectory post ID
     * @param string|null $status Filter by status (null = all)
     * @return int Count
     */
    public function countByTrajectory(int $trajectoryId, ?string $status = null): int
    {
        global $wpdb;

        $table = $this->getTableName();

        if ($status !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE trajectory_id = %d AND status = %s",
                    $trajectoryId,
                    $status
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE trajectory_id = %d",
                    $trajectoryId
                )
            );
        }

        return (int) $count;
    }

    /**
     * Get all status counts for a trajectory in a single query
     *
     * More efficient than calling countByTrajectory multiple times.
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array<string, int> Counts keyed by status
     */
    public function getStatusCounts(int $trajectoryId): array
    {
        global $wpdb;

        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM $table WHERE trajectory_id = %d GROUP BY status",
                $trajectoryId
            ),
            ARRAY_A
        );

        // Initialize all statuses to 0
        $counts = array_fill_keys(self::VALID_STATUSES, 0);

        // Fill in actual counts
        foreach ($results ?: [] as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['count'];
            }
        }

        return $counts;
    }

    // ========================================
    // STATUS CHANGES
    // ========================================

    /**
     * Mark enrollment as completed
     *
     * @param int $enrollmentId Enrollment ID
     * @return true|WP_Error
     */
    public function complete(int $enrollmentId): true|WP_Error
    {
        $result = $this->update($enrollmentId, [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => current_time('mysql'),
        ]);

        if ($result === true) {
            do_action('stride/trajectory_enrollment/completed', $enrollmentId);
        }

        return $result;
    }

    /**
     * Mark enrollment as expired
     *
     * @param int $enrollmentId Enrollment ID
     * @return true|WP_Error
     */
    public function expire(int $enrollmentId): true|WP_Error
    {
        $result = $this->update($enrollmentId, [
            'status' => self::STATUS_EXPIRED,
        ]);

        if ($result === true) {
            do_action('stride/trajectory_enrollment/expired', $enrollmentId);
        }

        return $result;
    }

    /**
     * Cancel an enrollment
     *
     * @param int $enrollmentId Enrollment ID
     * @return true|WP_Error
     */
    public function cancel(int $enrollmentId): true|WP_Error
    {
        $result = $this->update($enrollmentId, [
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => current_time('mysql'),
        ]);

        if ($result === true) {
            do_action('stride/trajectory_enrollment/cancelled', $enrollmentId);
        }

        return $result;
    }

    /**
     * Reactivate a cancelled enrollment
     *
     * @param int $enrollmentId Enrollment ID
     * @return true|WP_Error
     */
    public function reactivate(int $enrollmentId): true|WP_Error
    {
        $existing = $this->get($enrollmentId);
        if (!$existing) {
            return new WP_Error('not_found', __('Traject-inschrijving niet gevonden.', 'stride'));
        }

        if ($existing['status'] !== self::STATUS_CANCELLED) {
            return new WP_Error('not_cancelled', __('Inschrijving is niet geannuleerd.', 'stride'));
        }

        $result = $this->update($enrollmentId, [
            'status' => self::STATUS_ACTIVE,
            'cancelled_at' => null,
        ]);

        if ($result === true) {
            do_action('stride/trajectory_enrollment/reactivated', $enrollmentId);
        }

        return $result;
    }

    // ========================================
    // CACHE MANAGEMENT
    // ========================================

    /**
     * Invalidate cache for a trajectory and user
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $userId WordPress user ID
     */
    private function invalidateCache(int $trajectoryId, int $userId): void
    {
        // Clear request-level cache
        $lookupKey = "lookup_{$userId}_{$trajectoryId}";
        unset(self::$lookupCache[$lookupKey]);

        // Clear object cache
        $objectCacheKey = "stride_traj_enroll_{$userId}_{$trajectoryId}";
        wp_cache_delete($objectCacheKey, 'stride');
    }

    /**
     * Clear all caches (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$lookupCache = [];
        // Note: Cannot clear all object cache entries without knowing all keys
        // Individual keys should be invalidated via invalidateCache()
    }

    // ========================================
    // FORMATTING
    // ========================================

    /**
     * Format database row to clean array
     */
    private function formatRow(array $row): array
    {
        $electiveChoices = $row['elective_choices'] ?? null;
        if (is_string($electiveChoices) && !empty($electiveChoices)) {
            $electiveChoices = json_decode($electiveChoices, true, 32) ?: [];
        }

        return [
            'id' => (int) $row['id'],
            'trajectory_id' => (int) $row['trajectory_id'],
            'user_id' => (int) $row['user_id'],
            'status' => $row['status'],
            'enrolled_at' => $row['enrolled_at'],
            'deadline_at' => $row['deadline_at'],
            'completed_at' => $row['completed_at'],
            'cancelled_at' => $row['cancelled_at'],
            'notes' => $row['notes'],
            'elective_choices' => $electiveChoices ?: [],
        ];
    }

    // ========================================
    // ELECTIVE CHOICES
    // ========================================

    /**
     * Set elective choices for an enrollment
     *
     * Elective choices format:
     * [
     *   ['course_id' => 456, 'group' => 'Keuzemodules Jaar 1'],
     *   ['course_id' => 789, 'group' => 'Keuzemodules Jaar 1'],
     * ]
     *
     * @param int $enrollmentId Enrollment ID
     * @param array $choices Array of elective choices
     * @return true|WP_Error
     */
    public function setElectiveChoices(int $enrollmentId, array $choices): true|WP_Error
    {
        global $wpdb;

        // Check exists
        $existing = $this->get($enrollmentId);
        if (!$existing) {
            return new WP_Error('not_found', __('Traject-inschrijving niet gevonden.', 'stride'));
        }

        // Validate and sanitize choices
        $sanitizedChoices = [];
        foreach ($choices as $choice) {
            if (empty($choice['course_id'])) {
                continue;
            }
            $sanitizedChoices[] = [
                'course_id' => absint($choice['course_id']),
                'group' => sanitize_text_field($choice['group'] ?? ''),
            ];
        }

        $table = $this->getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table,
            ['elective_choices' => wp_json_encode($sanitizedChoices)],
            ['id' => $enrollmentId],
            ['%s'],
            ['%d']
        );

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('TrajectoryEnrollmentRepository::setElectiveChoices - DB error: ' . $wpdb->last_error);
            }
            return new WP_Error('db_error', __('Kon keuzevakken niet opslaan.', 'stride'));
        }

        // Invalidate cache
        $this->invalidateCache((int) $existing['trajectory_id'], (int) $existing['user_id']);

        do_action('stride/trajectory_enrollment/elective_choices_updated', $enrollmentId, $sanitizedChoices);

        return true;
    }

    /**
     * Get elective choices for an enrollment
     *
     * @param int $enrollmentId Enrollment ID
     * @return array Array of elective choices
     */
    public function getElectiveChoices(int $enrollmentId): array
    {
        $enrollment = $this->get($enrollmentId);
        if (!$enrollment) {
            return [];
        }

        return $enrollment['elective_choices'] ?? [];
    }

    /**
     * Check if user has completed all required elective choices for a trajectory
     *
     * @param int $enrollmentId Enrollment ID
     * @param int $trajectoryId Trajectory post ID
     * @return bool True if all required choices are made
     */
    public function hasCompletedChoices(int $enrollmentId, int $trajectoryId): bool
    {
        $trajectoryService = $this->getTrajectoryService();
        if (!$trajectoryService) {
            return true; // Can't validate without service, assume complete
        }

        $trajectory = $trajectoryService->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return true;
        }

        $electiveCourses = $trajectoryService->getElectiveCourses($trajectoryId);
        if (empty($electiveCourses)) {
            return true; // No electives to choose
        }

        $choices = $this->getElectiveChoices($enrollmentId);
        $chosenCourseIds = array_column($choices, 'course_id');

        // Group electives by group name and check pick counts
        $groupedElectives = [];
        foreach ($electiveCourses as $elective) {
            $group = $elective['group'] ?? 'default';
            if (!isset($groupedElectives[$group])) {
                $groupedElectives[$group] = [
                    'courses' => [],
                    'pick_count' => $elective['pick_count'] ?? 1,
                ];
            }
            $groupedElectives[$group]['courses'][] = $elective['course_id'];
        }

        // Check each group has enough choices
        foreach ($groupedElectives as $group => $data) {
            $requiredCount = $data['pick_count'];
            $chosenInGroup = array_intersect($data['courses'], $chosenCourseIds);

            if (count($chosenInGroup) < $requiredCount) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get TrajectoryService (lazy loaded to avoid circular dependencies)
     */
    private function getTrajectoryService(): ?TrajectoryService
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get(TrajectoryService::class);
                if ($service instanceof TrajectoryService) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        return null;
    }
}
