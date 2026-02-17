<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use WP_Error;

/**
 * Repository for trajectory enrollment records.
 */
final class TrajectoryEnrollmentRepository
{
    /**
     * Find enrollment by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Find enrollment by user and trajectory.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserAndTrajectory(int $userId, int $trajectoryId): ?array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND trajectory_id = %d",
            $userId,
            $trajectoryId
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Find all enrollments for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function findByUser(int $userId): array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY enrolled_at DESC",
            $userId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Find active enrollments for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function findActiveByUser(int $userId): array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND status IN ('enrolled', 'completed') ORDER BY enrolled_at DESC",
            $userId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Find all enrollments for a trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function findByTrajectory(int $trajectoryId): array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE trajectory_id = %d ORDER BY enrolled_at DESC",
            $trajectoryId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Count enrollments for a trajectory.
     */
    public function countByTrajectory(int $trajectoryId): int
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE trajectory_id = %d AND status IN ('enrolled', 'completed')",
            $trajectoryId
        ));
    }

    /**
     * Create enrollment.
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        // Check for existing enrollment
        $existing = $this->findByUserAndTrajectory($data['user_id'], $data['trajectory_id']);
        if ($existing) {
            return new WP_Error('already_enrolled', 'User is already enrolled in this trajectory');
        }

        $insertData = [
            'user_id' => $data['user_id'],
            'trajectory_id' => $data['trajectory_id'],
            'status' => $data['status'] ?? 'enrolled',
            'elective_choices' => isset($data['elective_choices']) ? json_encode($data['elective_choices']) : null,
            'enrolled_at' => $data['enrolled_at'] ?? current_time('mysql'),
            'notes' => $data['notes'] ?? null,
        ];

        $formats = ['%d', '%d', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($table, $insertData, $formats);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create enrollment');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update enrollment.
     */
    public function update(int $id, array $data): true|WP_Error
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $updateData = [];
        $formats = [];

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            $formats[] = '%s';
        }

        if (array_key_exists('elective_choices', $data)) {
            $updateData['elective_choices'] = $data['elective_choices'] !== null
                ? json_encode($data['elective_choices'])
                : null;
            $formats[] = '%s';
        }

        if (isset($data['choices_locked_at'])) {
            $updateData['choices_locked_at'] = $data['choices_locked_at'];
            $formats[] = '%s';
        }

        if (isset($data['completed_at'])) {
            $updateData['completed_at'] = $data['completed_at'];
            $formats[] = '%s';
        }

        if (isset($data['cancelled_at'])) {
            $updateData['cancelled_at'] = $data['cancelled_at'];
            $formats[] = '%s';
        }

        if (isset($data['notes'])) {
            $updateData['notes'] = $data['notes'];
            $formats[] = '%s';
        }

        if (empty($updateData)) {
            return true;
        }

        $result = $wpdb->update($table, $updateData, ['id' => $id], $formats, ['%d']);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update enrollment');
        }

        return true;
    }

    /**
     * Get elective choices for enrollment.
     *
     * @return array<string, array<int>>
     */
    public function getElectiveChoices(int $enrollmentId): array
    {
        $enrollment = $this->find($enrollmentId);

        if (!$enrollment || empty($enrollment['elective_choices'])) {
            return [];
        }

        $choices = json_decode($enrollment['elective_choices'], true);

        return is_array($choices) ? $choices : [];
    }

    /**
     * Check if user is enrolled in trajectory.
     */
    public function isEnrolled(int $userId, int $trajectoryId): bool
    {
        $enrollment = $this->findByUserAndTrajectory($userId, $trajectoryId);

        return $enrollment !== null && in_array($enrollment['status'], ['enrolled', 'completed'], true);
    }
}
