<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use WP_Error;

final class ContextRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'netdust_lti_contexts';
    }

    public function find(int $id): array|WP_Error
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('not_found', 'Context not found');
        }

        $row['settings'] = json_decode($row['settings'] ?? '{}', true);
        return $row;
    }

    public function findByLtiContext(int $platformId, string $ltiContextId, ?string $resourceLinkId = null): array|null
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE platform_id = %d AND lti_context_id = %s",
            $platformId,
            $ltiContextId
        );

        if ($resourceLinkId) {
            $sql .= $this->wpdb->prepare(" AND resource_link_id = %s", $resourceLinkId);
        }

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['settings'] = json_decode($row['settings'] ?? '{}', true);
        return $row;
    }

    public function findByCourseId(int $courseId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE ld_course_id = %d",
                $courseId
            ),
            ARRAY_A
        );

        return array_map(function($row) {
            $row['settings'] = json_decode($row['settings'] ?? '{}', true);
            return $row;
        }, $rows);
    }

    public function create(array $data): int|WP_Error
    {
        $insert = [
            'platform_id' => $data['platform_id'],
            'lti_context_id' => $data['lti_context_id'],
            'ld_course_id' => $data['ld_course_id'],
            'resource_link_id' => $data['resource_link_id'] ?? null,
            'line_item_url' => $data['line_item_url'] ?? null,
            'settings' => json_encode($data['settings'] ?? []),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $result = $this->wpdb->insert($this->table, $insert);

        if ($result === false) {
            return new WP_Error('insert_failed', $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, array $data): bool|WP_Error
    {
        $update = [
            'line_item_url' => $data['line_item_url'] ?? null,
            'settings' => json_encode($data['settings'] ?? []),
            'updated_at' => current_time('mysql'),
        ];

        $result = $this->wpdb->update($this->table, $update, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('update_failed', $this->wpdb->last_error);
        }

        return true;
    }
}
