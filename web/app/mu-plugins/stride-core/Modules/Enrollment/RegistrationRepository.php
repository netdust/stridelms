<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use WP_Error;

/**
 * Repository for registration data access.
 *
 * Unified table for edition and trajectory enrollments.
 */
final class RegistrationRepository
{
    public const PATH_INDIVIDUAL = 'individual';
    public const PATH_COLLEAGUE = 'colleague';
    public const PATH_TRAJECTORY = 'trajectory';

    private function table(): string
    {
        return RegistrationTable::getTableName();
    }

    // === Create ===

    /**
     * Create a new registration.
     *
     * @param array<string, mixed> $data
     * @return int|WP_Error Registration ID or error
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;

        // Must have at least edition_id or trajectory_id
        if (empty($data['edition_id']) && empty($data['trajectory_id'])) {
            return new WP_Error('missing_field', 'Required: edition_id or trajectory_id');
        }

        if (empty($data['user_id'])) {
            return new WP_Error('missing_field', 'Required: user_id');
        }

        // Check for duplicate
        $editionId = isset($data['edition_id']) ? absint($data['edition_id']) : null;
        $trajectoryId = isset($data['trajectory_id']) ? absint($data['trajectory_id']) : null;

        // Check for existing registration (unique constraint on user+edition)
        $existing = null;
        if ($editionId) {
            $existing = $this->findByUserAndEdition((int) $data['user_id'], $editionId);
        } elseif ($trajectoryId) {
            $existing = $this->findByUserAndTrajectory((int) $data['user_id'], $trajectoryId);
        }

        if ($existing) {
            $existingStatus = RegistrationStatus::tryFrom($existing->status);

            // If cancelled (or withdrawn — DB enum value not in PHP enum), reactivate
            if ($existingStatus === RegistrationStatus::Cancelled || $existing->status === 'withdrawn') {
                $reactivate = [
                    'status' => $data['status'] ?? RegistrationStatus::Confirmed->value,
                    'registered_at' => current_time('mysql'),
                    'cancelled_at' => null,
                    'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
                    'enrollment_data' => isset($data['enrollment_data']) ? wp_json_encode($data['enrollment_data']) : null,
                    'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
                    'selections' => isset($data['selections']) ? wp_json_encode($data['selections']) : null,
                    'completion_tasks' => null,
                    'completed_at' => null,
                ];

                $result = $wpdb->update($this->table(), $reactivate, ['id' => (int) $existing->id]);

                if ($result === false) {
                    return new WP_Error('db_error', 'Failed to reactivate registration');
                }

                do_action('stride/registration/created', [
                    'registration_id' => (int) $existing->id,
                    'user_id' => (int) $data['user_id'],
                    'edition_id' => $editionId,
                    'trajectory_id' => $trajectoryId,
                    'enrollment_path' => $data['enrollment_path'] ?? self::PATH_INDIVIDUAL,
                ]);

                return (int) $existing->id;
            }

            // Active registration exists — block duplicate
            if ($editionId) {
                return new WP_Error('duplicate', 'User already registered for this edition');
            }
            return new WP_Error('duplicate', 'User already enrolled in this trajectory');
        }

        $insert = [
            'user_id' => absint($data['user_id']),
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'company_id' => isset($data['company_id']) ? absint($data['company_id']) : null,
            'status' => $data['status'] ?? RegistrationStatus::Confirmed->value,
            'enrollment_path' => $data['enrollment_path'] ?? self::PATH_INDIVIDUAL,
            'selections' => isset($data['selections']) ? wp_json_encode($data['selections']) : null,
            'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
            'enrolled_by' => isset($data['enrolled_by']) ? absint($data['enrolled_by']) : null,
            'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
            'enrollment_data' => isset($data['enrollment_data']) ? wp_json_encode($data['enrollment_data']) : null,
        ];

        $result = $wpdb->insert($this->table(), $insert);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create registration');
        }

        $registrationId = (int) $wpdb->insert_id;

        do_action('stride/registration/created', [
            'registration_id' => $registrationId,
            'user_id' => $insert['user_id'],
            'edition_id' => $insert['edition_id'],
            'trajectory_id' => $insert['trajectory_id'],
            'enrollment_path' => $insert['enrollment_path'],
        ]);

        return $registrationId;
    }

    // === Find by ID ===

    /**
     * Find registration by ID.
     */
    public function find(int $id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ));

        if ($row && $row->selections) {
            $row->selections = json_decode($row->selections, true);
        }

        if ($row && $row->completion_tasks) {
            $row->completion_tasks = json_decode($row->completion_tasks, true);
        }

        if ($row && isset($row->enrollment_data) && $row->enrollment_data) {
            $row->enrollment_data = json_decode($row->enrollment_data, true);
        }

        return $row;
    }

    // === Edition queries ===

    /**
     * Find registration by user and edition.
     */
    public function findByUserAndEdition(int $userId, int $editionId): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND edition_id = %d",
            $userId,
            $editionId
        ));

        if ($row && $row->selections) {
            $row->selections = json_decode($row->selections, true);
        }

        if ($row && $row->completion_tasks) {
            $row->completion_tasks = json_decode($row->completion_tasks, true);
        }

        if ($row && isset($row->enrollment_data) && $row->enrollment_data) {
            $row->enrollment_data = json_decode($row->enrollment_data, true);
        }

        return $row;
    }

    /**
     * Check if user is registered for edition.
     */
    public function existsForEdition(int $userId, int $editionId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table()} WHERE user_id = %d AND edition_id = %d LIMIT 1",
            $userId,
            $editionId
        ));
    }

    /**
     * Get all registrations for an edition.
     *
     * @return array<object>
     */
    public function findByEdition(int $editionId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE edition_id = %d";
        $params = [$editionId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count confirmed registrations for edition.
     */
    public function countConfirmedForEdition(int $editionId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE edition_id = %d AND status = 'confirmed'",
            $editionId
        ));
    }

    // === Trajectory queries ===

    /**
     * Find trajectory enrollment (no edition_id).
     */
    public function findByUserAndTrajectory(int $userId, int $trajectoryId): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND trajectory_id = %d AND edition_id IS NULL",
            $userId,
            $trajectoryId
        ));

        if ($row && $row->selections) {
            $row->selections = json_decode($row->selections, true);
        }

        return $row;
    }

    /**
     * Check if user is enrolled in trajectory.
     */
    public function existsForTrajectory(int $userId, int $trajectoryId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table()} WHERE user_id = %d AND trajectory_id = %d AND edition_id IS NULL LIMIT 1",
            $userId,
            $trajectoryId
        ));
    }

    /**
     * Get all enrollments for a trajectory.
     *
     * @return array<object>
     */
    public function findByTrajectory(int $trajectoryId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE trajectory_id = %d AND edition_id IS NULL";
        $params = [$trajectoryId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get edition registrations linked to a trajectory.
     *
     * @return array<object>
     */
    public function findEditionsByTrajectory(int $userId, int $trajectoryId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND trajectory_id = %d AND edition_id IS NOT NULL ORDER BY registered_at ASC",
            $userId,
            $trajectoryId
        ));
    }

    /**
     * Count enrollments for a trajectory.
     */
    public function countByTrajectory(int $trajectoryId, ?string $status = null): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE trajectory_id = %d AND edition_id IS NULL";
        $params = [$trajectoryId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    // === User queries ===

    /**
     * Get all registrations for a user.
     *
     * @return array<object>
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE user_id = %d";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at DESC";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        foreach ($results as $row) {
            if (!empty($row->selections) && is_string($row->selections)) {
                $row->selections = json_decode($row->selections, true);
            }
            if (!empty($row->completion_tasks) && is_string($row->completion_tasks)) {
                $row->completion_tasks = json_decode($row->completion_tasks, true);
            }
        }

        return $results;
    }

    /**
     * Get user's trajectory enrollments.
     *
     * @return array<object>
     */
    public function findTrajectoryEnrollmentsByUser(int $userId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND trajectory_id IS NOT NULL AND edition_id IS NULL AND status != 'cancelled' ORDER BY registered_at DESC",
            $userId
        ));
    }

    // === Company queries ===

    /**
     * Get enrollments for a company.
     *
     * @param int $companyId Company ID
     * @param array<string, mixed> $filters Optional filters: status, edition_id, user_id, page, per_page
     * @return array{data: array<object>, total: int}
     */
    public function findByCompany(int $companyId, array $filters = []): array
    {
        global $wpdb;

        $status = $filters['status'] ?? null;
        $editionId = isset($filters['edition_id']) ? absint($filters['edition_id']) : null;
        $userId = isset($filters['user_id']) ? absint($filters['user_id']) : null;
        $page = max(1, absint($filters['page'] ?? 1));
        $perPage = min(100, max(1, absint($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        // Build WHERE clause
        $where = ["company_id = %d"];
        $params = [$companyId];

        if ($status !== null) {
            $where[] = "status = %s";
            $params[] = sanitize_text_field($status);
        }

        if ($editionId !== null) {
            $where[] = "edition_id = %d";
            $params[] = $editionId;
        }

        if ($userId !== null) {
            $where[] = "user_id = %d";
            $params[] = $userId;
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countSql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$whereClause}";
        $total = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params));

        // Get data
        $dataSql = "SELECT * FROM {$this->table()} WHERE {$whereClause} ORDER BY registered_at DESC LIMIT %d OFFSET %d";
        $params[] = $perPage;
        $params[] = $offset;

        $data = $wpdb->get_results($wpdb->prepare($dataSql, ...$params));

        foreach ($data as $row) {
            if ($row->selections) {
                $row->selections = json_decode($row->selections, true);
            }
        }

        return ['data' => $data, 'total' => $total];
    }

    // === Selections ===

    /**
     * Set selections (sessions or elective editions).
     *
     * @param array<int> $selections
     */
    public function setSelections(int $registrationId, array $selections): bool
    {
        global $wpdb;

        return $wpdb->update(
            $this->table(),
            ['selections' => wp_json_encode($selections)],
            ['id' => $registrationId]
        ) !== false;
    }

    /**
     * Get selections for a registration.
     *
     * @return array<int>
     */
    public function getSelections(int $registrationId): array
    {
        $registration = $this->find($registrationId);

        if (!$registration) {
            return [];
        }

        return $registration->selections ?? [];
    }

    /**
     * Lock selections (prevent further changes).
     */
    public function lockSelections(int $registrationId): bool
    {
        global $wpdb;

        return $wpdb->update(
            $this->table(),
            ['selections_locked_at' => current_time('mysql')],
            ['id' => $registrationId]
        ) !== false;
    }

    /**
     * Check if selections are locked.
     */
    public function areSelectionsLocked(int $registrationId): bool
    {
        $registration = $this->find($registrationId);

        return $registration && !empty($registration->selections_locked_at);
    }

    // === Completion tasks ===

    /**
     * Update completion_tasks JSON for a registration.
     */
    public function updateCompletionTasks(int $registrationId, array $tasks): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table(),
            ['completion_tasks' => wp_json_encode($tasks)],
            ['id' => $registrationId],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    // === Status updates ===

    /**
     * Update registration.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $allowed = ['status', 'selections', 'selections_locked_at', 'quote_id', 'completed_at', 'cancelled_at', 'notes', 'completion_tasks', 'enrollment_data'];
        $update = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (in_array($field, ['selections', 'completion_tasks', 'enrollment_data'], true) && is_array($value)) {
                    $value = wp_json_encode($value);
                }
                $update[$field] = $value;
            }
        }

        if (empty($update)) {
            return true;
        }

        return $wpdb->update($this->table(), $update, ['id' => $id]) !== false;
    }

    /**
     * Update registration status.
     */
    public function updateStatus(int $id, RegistrationStatus $status): bool
    {
        $data = ['status' => $status->value];

        if ($status === RegistrationStatus::Cancelled) {
            $data['cancelled_at'] = current_time('mysql');
        }

        if ($status === RegistrationStatus::Completed) {
            $data['completed_at'] = current_time('mysql');
        }

        return $this->update($id, $data);
    }

    /**
     * Cancel a registration.
     */
    public function cancel(int $id): bool
    {
        $registration = $this->find($id);
        if (!$registration) {
            return false;
        }

        $result = $this->updateStatus($id, RegistrationStatus::Cancelled);

        if ($result) {
            do_action('stride/registration/cancelled', [
                'registration_id' => $id,
                'user_id' => $registration->user_id,
                'edition_id' => $registration->edition_id,
                'trajectory_id' => $registration->trajectory_id,
            ]);
        }

        return $result;
    }

    // === Legacy aliases ===

    /** @deprecated Use existsForEdition() */
    public function exists(int $userId, int $editionId): bool
    {
        return $this->existsForEdition($userId, $editionId);
    }

    /** @deprecated Use countConfirmedForEdition() */
    public function countConfirmed(int $editionId): int
    {
        return $this->countConfirmedForEdition($editionId);
    }
}
