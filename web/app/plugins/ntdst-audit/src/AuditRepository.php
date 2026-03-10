<?php

declare(strict_types=1);

namespace NTDST\Audit;

use DateTime;
use WP_Error;

class AuditRepository
{
    private function table(): string
    {
        return AuditTable::getTableName();
    }

    /**
     * Insert an audit entry. Returns entry ID or WP_Error.
     */
    public function insert(array $data): int|WP_Error
    {
        global $wpdb;

        $required = ['entity_type', 'entity_id', 'action'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Required field: {$field}");
            }
        }

        // Sanitize action - allow dots for namespacing (e.g., 'registration.created')
        $action = preg_replace('/[^a-z0-9._-]/', '', strtolower($data['action']));

        $insert = [
            'entity_type' => sanitize_key($data['entity_type']),
            'entity_id' => absint($data['entity_id']),
            'action' => $action,
            'actor_id' => isset($data['actor_id']) ? absint($data['actor_id']) : null,
            'actor_type' => sanitize_key($data['actor_type'] ?? 'user'),
            'context' => isset($data['context']) ? wp_json_encode($data['context']) : null,
        ];

        $result = $wpdb->insert($this->table(), $insert);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to insert audit entry');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find audit entries by entity.
     */
    public function findByEntity(string $type, int $id): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE entity_type = %s AND entity_id = %d ORDER BY created_at DESC",
            $type,
            $id
        ));
    }

    /**
     * Find audit entries by actor (user).
     */
    public function findByActor(int $actorId, ?string $entityType = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE actor_id = %d";
        $params = [$actorId];

        if ($entityType !== null) {
            $sql .= " AND entity_type = %s";
            $params[] = $entityType;
        }

        $sql .= " ORDER BY created_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Find audit entries within a date range with optional filters.
     */
    public function findByDateRange(
        DateTime $from,
        DateTime $to,
        array $filters = [],
        int $limit = 100,
        int $offset = 0
    ): array {
        global $wpdb;

        // Set "to" to end of day to include all entries from that day
        $toEndOfDay = clone $to;
        $toEndOfDay->setTime(23, 59, 59);

        $sql = "SELECT * FROM {$this->table()} WHERE created_at BETWEEN %s AND %s";
        $params = [$from->format('Y-m-d H:i:s'), $toEndOfDay->format('Y-m-d H:i:s')];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = %s";
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['actor_id'])) {
            $sql .= " AND actor_id = %d";
            $params[] = (int) $filters['actor_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = %s";
            $params[] = $filters['action'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count entries matching filters (for pagination).
     */
    public function countByDateRange(DateTime $from, DateTime $to, array $filters = []): int
    {
        global $wpdb;

        $toEndOfDay = clone $to;
        $toEndOfDay->setTime(23, 59, 59);

        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE created_at BETWEEN %s AND %s";
        $params = [$from->format('Y-m-d H:i:s'), $toEndOfDay->format('Y-m-d H:i:s')];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = %s";
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['actor_id'])) {
            $sql .= " AND actor_id = %d";
            $params[] = (int) $filters['actor_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = %s";
            $params[] = $filters['action'];
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get distinct entity types for filter dropdown.
     */
    public function getDistinctEntityTypes(): array
    {
        global $wpdb;

        return $wpdb->get_col("SELECT DISTINCT entity_type FROM {$this->table()} ORDER BY entity_type");
    }

    /**
     * Find audit entries where a user is the subject (in context),
     * excluding entries where the user was the actor (self-actions).
     */
    public function findBySubjectUser(int $userId, int $limit = 50, int $daysBack = 30): array
    {
        global $wpdb;

        $since = (new \DateTime("-{$daysBack} days"))->format('Y-m-d H:i:s');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE JSON_EXTRACT(context, '$.user_id') = %d
               AND (actor_id IS NULL OR actor_id != %d)
               AND created_at >= %s
             ORDER BY created_at DESC
             LIMIT %d",
            $userId,
            $userId,
            $since,
            $limit
        ));
    }

    /**
     * Delete entries older than retention period. For cron cleanup only.
     */
    public function deleteOlderThan(DateTime $before): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE created_at < %s",
            $before->format('Y-m-d H:i:s')
        ));
    }
}
