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
    public const PATH_PARTNER = 'partner';

    /** @var array<string, array<object>> Per-request cache for findByUser results */
    private array $findByUserCache = [];

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

        $status = $data['status'] ?? 'confirmed';
        $anonymousAllowedStatuses = [
            RegistrationStatus::Interest->value,
            RegistrationStatus::Waitlist->value,
        ];
        if (empty($data['user_id']) && !in_array($status, $anonymousAllowedStatuses, true)) {
            return new WP_Error('missing_field', 'Required: user_id (except for interest/waitlist registrations)');
        }

        // Check for duplicate
        $editionId = isset($data['edition_id']) ? absint($data['edition_id']) : null;
        $trajectoryId = isset($data['trajectory_id']) ? absint($data['trajectory_id']) : null;

        // Check for existing registration (unique constraint on user+edition)
        // Skip duplicate check for anonymous interest registrations (no user_id)
        $existing = null;
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        if ($userId && $editionId) {
            $existing = $this->findByUserAndEdition($userId, $editionId);
        } elseif ($userId && $trajectoryId) {
            $existing = $this->findByUserAndTrajectory($userId, $trajectoryId);
        }

        if ($existing) {
            $existingStatus = RegistrationStatus::tryFrom($existing->status);

            // Reactivate-eligible statuses:
            // - Cancelled: terminal-cancel state, re-enrolling reopens the row.
            // - Interest / Waitlist: pre-enrollment holding states, the user already
            //   expressed intent — enrolling promotes that row instead of blocking.
            // For Interest specifically, EnrollmentService::enroll() has a separate
            // upgrade path that merges enrollment_data when the row is anonymous
            // (user_id=0). That path runs BEFORE this method, so we only land here
            // when the existing Interest row already belongs to this user.
            $reactivatableStatuses = [
                RegistrationStatus::Cancelled,
                RegistrationStatus::Interest,
                RegistrationStatus::Waitlist,
            ];
            if (in_array($existingStatus, $reactivatableStatuses, true)) {
                // Preserve existing enrollment_data (interest/waitlist stage payloads
                // collected earlier) unless the caller passes new data to merge.
                $existingData = is_string($existing->enrollment_data ?? null) && $existing->enrollment_data !== ''
                    ? (json_decode($existing->enrollment_data, true) ?: [])
                    : (is_array($existing->enrollment_data ?? null) ? $existing->enrollment_data : []);
                $newData = is_array($data['enrollment_data'] ?? null) ? $data['enrollment_data'] : [];
                $mergedData = $existingData;
                foreach ($newData as $k => $v) {
                    $mergedData[$k] = $v;
                }

                $reactivate = [
                    'status' => $data['status'] ?? RegistrationStatus::Confirmed->value,
                    'enrollment_path' => $data['enrollment_path'] ?? ($existing->enrollment_path ?? 'individual'),
                    'registered_at' => current_time('mysql'),
                    'cancelled_at' => null,
                    'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
                    'enrollment_data' => $mergedData ? wp_json_encode($mergedData) : null,
                    'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
                    'selections' => isset($data['selections']) ? wp_json_encode($data['selections']) : null,
                    'completion_tasks' => null,
                    'completed_at' => null,
                ];

                $result = $wpdb->update($this->table(), $reactivate, ['id' => (int) $existing->id]);

                if ($result === false) {
                    return new WP_Error('db_error', 'Failed to reactivate registration');
                }

                $this->clearCache();

                return (int) $existing->id;
            }

            // Active registration exists — block duplicate
            if ($editionId) {
                return new WP_Error('duplicate', 'User already registered for this edition');
            }
            return new WP_Error('duplicate', 'User already enrolled in this trajectory');
        }

        $insert = [
            'user_id' => isset($data['user_id']) ? absint($data['user_id']) : null,
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

        $this->clearCache();

        $registrationId = (int) $wpdb->insert_id;

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
     * Find an interest registration by email and edition.
     *
     * Searches enrollment_data JSON for interest.email match.
     */
    public function findByEmailAndEdition(string $email, int $editionId): ?object
    {
        return $this->findByEmailAndEditionForStage($email, $editionId, RegistrationStatus::Interest);
    }

    /**
     * Find any anonymous row for this email + edition across interest + waitlist stages.
     *
     * Used to upsert when a user submits interest now and waitlist later (or vice
     * versa) for the same edition — we want a single row, not two, since both stages
     * are pre-enrollment intent on the same offering.
     */
    public function findAnonymousForEmailAndEdition(string $email, int $editionId): ?object
    {
        global $wpdb;
        $table = $this->table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE edition_id = %d
             AND user_id IS NULL
             AND status IN (%s, %s)
             AND (
                JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.interest.email')) = %s
                OR JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.waitlist.email')) = %s
             )
             LIMIT 1",
            $editionId,
            RegistrationStatus::Interest->value,
            RegistrationStatus::Waitlist->value,
            $email,
            $email
        ));
    }

    /**
     * Find a registration by email and edition for a given status/stage.
     *
     * Looks for the email inside enrollment_data.{stage}.email, where stage
     * matches the status value (e.g. 'interest' or 'waitlist').
     */
    public function findByEmailAndEditionForStage(string $email, int $editionId, RegistrationStatus $status): ?object
    {
        global $wpdb;

        $table = $this->table();
        $jsonPath = '$.' . $status->value . '.email';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE edition_id = %d
             AND status = %s
             AND JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, %s)) = %s
             LIMIT 1",
            $editionId,
            $status->value,
            $jsonPath,
            $email
        ));
    }

    /**
     * Upgrade an interest registration to a full enrollment.
     *
     * Sets user_id, status, enrollment_path, enrollment_data, and registered_at.
     *
     * @param array<string, mixed> $enrollmentData Merged enrollment_data to store
     */
    public function upgradeFromInterest(int $registrationId, int $userId, string $status, string $enrollmentPath, array $enrollmentData): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table(),
            [
                'user_id'         => $userId,
                'status'          => $status,
                'enrollment_path' => $enrollmentPath,
                'enrollment_data' => wp_json_encode($enrollmentData),
                'registered_at'   => current_time('mysql'),
            ],
            ['id' => $registrationId]
        );

        if ($result !== false) {
            $this->clearCache();
        }

        return $result !== false;
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

    /**
     * Count confirmed registrations with row-level lock (FOR UPDATE).
     * Must be called within a transaction.
     */
    public function countConfirmedForUpdate(int $editionId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE edition_id = %d AND status = 'confirmed' FOR UPDATE",
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

    /**
     * Batch-count enrollments for multiple trajectories.
     *
     * @param array<int> $trajectoryIds
     * @return array<int, int> Map of trajectory_id => count
     */
    public function countByTrajectoryIds(array $trajectoryIds): array
    {
        if (empty($trajectoryIds)) {
            return [];
        }

        global $wpdb;
        $ids = array_map('intval', $trajectoryIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT trajectory_id, COUNT(*) AS c FROM {$this->table()}
             WHERE trajectory_id IN ({$placeholders}) AND edition_id IS NULL
             GROUP BY trajectory_id",
            ...$ids
        ));

        $out = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $out[(int) $row->trajectory_id] = (int) $row->c;
        }
        return $out;
    }

    /**
     * Batch-count registrations for multiple editions, filtered by status.
     *
     * @param array<int>    $editionIds
     * @param array<string> $statuses   Status values to include (default: live statuses)
     * @return array<int, int> Map of edition_id => count (all input ids present, defaulting to 0)
     */
    public function countByEditions(
        array $editionIds,
        array $statuses = [
            RegistrationStatus::Confirmed->value,
            RegistrationStatus::Completed->value,
            RegistrationStatus::Pending->value,
        ]
    ): array {
        if (empty($editionIds) || empty($statuses)) {
            return array_fill_keys(array_map('intval', $editionIds), 0);
        }

        global $wpdb;
        $ids = array_map('intval', $editionIds);
        $idPlaceholders = implode(',', array_fill(0, count($ids), '%d'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, COUNT(*) AS c FROM {$this->table()}
             WHERE edition_id IN ({$idPlaceholders})
               AND status IN ({$statusPlaceholders})
             GROUP BY edition_id",
            ...array_merge($ids, $statuses)
        ));

        $out = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $out[(int) $row->edition_id] = (int) $row->c;
        }
        return $out;
    }

    /**
     * Batch-count registrations grouped by status across multiple editions.
     *
     * Returns one entry per status that actually occurs in the set; statuses
     * with zero rows are absent (callers can zero-fill as needed).
     *
     * @param array<int> $editionIds
     * @return array<string, int> Map of status => count
     */
    public function statusBreakdownByEditions(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;
        $ids = array_map('intval', $editionIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS c FROM {$this->table()}
             WHERE edition_id IN ({$placeholders})
             GROUP BY status",
            ...$ids
        ));

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->status] = (int) $row->c;
        }
        return $out;
    }

    /**
     * Batch-find trajectory enrollments grouped by trajectory_id.
     *
     * Returns up to $limitPerTrajectory rows per trajectory, newest first.
     *
     * @param array<int> $trajectoryIds
     * @return array<int, array<object>>
     */
    public function findByTrajectoryIds(array $trajectoryIds, int $limitPerTrajectory = 50): array
    {
        if (empty($trajectoryIds)) {
            return [];
        }

        global $wpdb;
        $ids = array_map('intval', $trajectoryIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, trajectory_id, user_id, status, registered_at FROM {$this->table()}
             WHERE trajectory_id IN ({$placeholders}) AND edition_id IS NULL
             ORDER BY trajectory_id, registered_at DESC",
            ...$ids
        ));

        $grouped = array_fill_keys($ids, []);
        foreach ($rows as $row) {
            $tid = (int) $row->trajectory_id;
            if (count($grouped[$tid]) < $limitPerTrajectory) {
                $grouped[$tid][] = $row;
            }
        }
        return $grouped;
    }

    // === User queries ===

    /**
     * Check if user has any active registrations (not cancelled).
     */
    public function hasActiveRegistrations(int $userId): bool
    {
        global $wpdb;
        $table = $this->table();
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND status != 'cancelled' LIMIT 1",
            $userId
        ));
        return (bool) $result;
    }

    /**
     * Check if user has any trajectory enrollments.
     */
    public function hasTrajectoryEnrollments(int $userId): bool
    {
        global $wpdb;
        $table = $this->table();
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND trajectory_id IS NOT NULL AND trajectory_id > 0 AND status != 'cancelled' LIMIT 1",
            $userId
        ));
        return (bool) $result;
    }

    /**
     * Get all registrations for a user.
     *
     * @return array<object>
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        $cacheKey = $userId . ':' . ($status ?? '*');
        if (isset($this->findByUserCache[$cacheKey])) {
            return $this->findByUserCache[$cacheKey];
        }

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

        $this->findByUserCache[$cacheKey] = $results;

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

        $result = $wpdb->update(
            $this->table(),
            ['selections' => wp_json_encode($selections)],
            ['id' => $registrationId]
        ) !== false;

        if ($result) {
            $this->clearCache();
        }

        return $result;
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

        $this->clearCache();

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

        // Snapshot before the write so the audit hook can record a field-level diff.
        // Compares against $data (not $update) for JSON fields so the diff captures
        // structural changes, not just "different encoded string."
        $before = $this->find($id);

        $result = $wpdb->update($this->table(), $update, ['id' => $id]) !== false;

        if ($result) {
            $this->clearCache();

            if ($before) {
                $diff = [];
                foreach ($update as $field => $newValue) {
                    $oldValue = $before->$field ?? null;
                    $compareNew = in_array($field, ['selections', 'completion_tasks', 'enrollment_data'], true)
                        ? ($data[$field] ?? null)
                        : $newValue;
                    if ($oldValue != $compareNew) {
                        $diff[$field] = ['old' => $oldValue, 'new' => $compareNew];
                    }
                }

                if ($diff) {
                    do_action('stride/registration/updated', [
                        'registration_id' => $id,
                        'diff' => $diff,
                        'actor_id' => get_current_user_id() ?: null,
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * Update registration status.
     *
     * `completed_at` and `cancelled_at` are set on the FIRST transition only.
     * Subsequent calls to updateStatus with the same terminal status are
     * idempotent and preserve the original timestamp.
     */
    public function updateStatus(int $id, RegistrationStatus $status): bool
    {
        $data = ['status' => $status->value];

        // Clear completion_tasks when moving to Cancelled — they're stage-specific
        // and don't apply to a terminated registration. On re-enroll the
        // reactivation path initializes fresh tasks anyway.
        if ($status === RegistrationStatus::Cancelled) {
            $data['completion_tasks'] = null;
        }

        if ($status === RegistrationStatus::Cancelled || $status === RegistrationStatus::Completed) {
            $existing = $this->find($id);
            if ($existing) {
                if ($status === RegistrationStatus::Cancelled && empty($existing->cancelled_at)) {
                    $data['cancelled_at'] = current_time('mysql');
                }
                if ($status === RegistrationStatus::Completed && empty($existing->completed_at)) {
                    $data['completed_at'] = current_time('mysql');
                }
            }
        }

        return $this->update($id, $data);
    }

    /**
     * Cancel a registration (data-only).
     *
     * Does NOT fire stride/registration/cancelled — callers must use
     * EnrollmentService::cancel() for the full lifecycle (LMS revoke, quote
     * cancel, audit, mail). This method is the raw data write; the event is
     * dispatched by the service so listeners see a fully consistent state.
     */
    public function cancel(int $id): bool
    {
        $registration = $this->find($id);
        if (!$registration) {
            return false;
        }

        return $this->updateStatus($id, RegistrationStatus::Cancelled);
    }

    // === Cache management ===

    /**
     * Clear per-request memoization cache.
     */
    public function clearCache(): void
    {
        $this->findByUserCache = [];
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
