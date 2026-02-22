<?php

declare(strict_types=1);

namespace NTDST\Audit;

use WP_Error;

final class AuditService implements \NTDST_Service_Meta
{
    private AuditRepository $repository;

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Service',
            'description' => 'Generic audit logging for compliance',
            'priority' => 99,
        ];
    }

    public function __construct()
    {
        $this->repository = new AuditRepository();
        $this->init();
    }

    private function init(): void
    {
        // Ensure table exists
        if (!AuditTable::exists()) {
            AuditTable::create();
        }

        // Retention cleanup cron
        add_action('ntdst_audit_cleanup', [$this, 'runCleanup']);

        // Schedule cleanup if not scheduled
        if (!wp_next_scheduled('ntdst_audit_cleanup')) {
            wp_schedule_event(time(), 'weekly', 'ntdst_audit_cleanup');
        }
    }

    /**
     * Record an audit entry.
     */
    public function record(
        string $entityType,
        int $entityId,
        string $action,
        ?int $actorId = null,
        array $context = []
    ): int|WP_Error {
        $actorType = 'user';

        if ($actorId === null) {
            $actorId = get_current_user_id() ?: null;
            if ($actorId === null || $actorId === 0) {
                $actorType = 'system';
                $actorId = null;
            }
        }

        $result = $this->repository->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'context' => $context,
        ]);

        if (!is_wp_error($result)) {
            ntdst_log('audit')->info("Audit: {$action}", [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        }

        return $result;
    }

    /**
     * Get audit entries for an entity.
     */
    public function getForEntity(string $entityType, int $entityId): array
    {
        return $this->repository->findByEntity($entityType, $entityId);
    }

    /**
     * Get audit entries for a user (as actor).
     */
    public function getForUser(int $userId): array
    {
        return $this->repository->findByActor($userId);
    }

    /**
     * Get repository for advanced queries.
     */
    public function getRepository(): AuditRepository
    {
        return $this->repository;
    }

    /**
     * Run retention cleanup. Called by cron.
     */
    public function runCleanup(): void
    {
        $retentionYears = apply_filters('ntdst/audit/retention_years', 7);
        $before = new \DateTime("-{$retentionYears} years");

        $deleted = $this->repository->deleteOlderThan($before);

        ntdst_log('audit')->info('Audit cleanup completed', [
            'deleted_count' => $deleted,
            'retention_years' => $retentionYears,
        ]);
    }
}
