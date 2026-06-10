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
        // Create table on first use (checked via option to avoid SHOW TABLES on every request)
        if (!get_option('ntdst_audit_table_created')) {
            if (!AuditTable::exists()) {
                AuditTable::create();
            }
            update_option('ntdst_audit_table_created', true, true);
        }

        // Versioned schema upgrades (option-gated; no-op once stamped).
        AuditTable::migrate();

        // Clear orphaned cron from old hook name (one-time cleanup)
        if (wp_next_scheduled('stride_audit_cleanup')) {
            wp_clear_scheduled_hook('stride_audit_cleanup');
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

            /**
             * Fires after an audit entry is recorded.
             *
             * Lets consumers react to new events without polling — e.g.
             * Stride invalidates its cached unread-notification count for
             * the subject user (context.user_id).
             *
             * @param string   $action     Recorded action slug.
             * @param string   $entityType Entity type.
             * @param int      $entityId   Entity ID.
             * @param array    $context    Context payload as passed in.
             * @param int|null $actorId    Resolved actor (null = system).
             */
            do_action('ntdst/audit/recorded', $action, $entityType, $entityId, $context, $actorId);
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
     * Get audit entries where user is the subject (not actor).
     *
     * @param string[] $excludeActions Action slugs that must never count as
     *                                 subject-targeted (consumer policy, e.g.
     *                                 Stride excludes 'mail.sent').
     */
    public function getForSubjectUser(int $userId, int $limit = 50, int $daysBack = 30, array $excludeActions = []): array
    {
        return $this->repository->findBySubjectUser($userId, $limit, $daysBack, $excludeActions);
    }

    /**
     * Get milestone events (registration + completion + certificate) for a user.
     *
     * The default action set covers the canonical "positive progress" moments;
     * callers can pass their own slugs to widen or narrow.
     *
     * @param string[]|null $actions
     */
    public function getMilestonesForUser(
        int $userId,
        ?array $actions = null,
        int $limit = 20,
        int $daysBack = 365
    ): array {
        $actions ??= [
            'registration.created',
            'completion.course_completed',
            'completion.certificate_issued',
        ];

        return $this->repository->findMilestonesForUser($userId, $actions, $limit, $daysBack);
    }

    /**
     * Get session note updates for editions.
     *
     * @param int[] $editionIds
     */
    public function getSessionNoteUpdates(array $editionIds, int $daysBack = 30): array
    {
        return $this->repository->findSessionNoteUpdates($editionIds, $daysBack);
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
