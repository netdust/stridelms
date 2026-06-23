<?php

declare(strict_types=1);

namespace Stride\Admin;

use NTDST\Audit\AuditService;
use NTDST\Audit\AuditTable;
use Stride\Admin\Support\AdminBatchHelpers;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Enrollment\RegistrationTable;

/**
 * Read-model assembly for the admin activity feed, dashboard health checks, and
 * the notifications read/mark-read pair (pairs the existing AdminActivityMapper,
 * which shapes a single audit row into a display line).
 *
 * Thin service — owns only read-model assembly: actor/target enrichment
 * (BatchQueryHelper / get_userdata), context hydration (the shared
 * AdminBatchHelpers::enrichAuditContexts), and the mapper invocation. The
 * cross-domain health-check counts route through their owning concerns
 * (RegistrationTable / EditionCPT post-meta / the audit table) and the
 * verdict is delegated to HealthCheckService.
 *
 * Moved VERBATIM from AdminAPIController::getActivityFeed / getHealthChecks /
 * getNotifications / markNotificationsRead (Task D4, behavior-preserving
 * strangle) — same SELECTs, same param order, same DESC + LIMIT, same
 * allowlists, same envelopes, same read/unread arithmetic.
 *
 * INV-3 note on the raw $wpdb audit reads: the audit_log table is a
 * cross-cutting NTDST table. Its owning repository, NTDST\Audit\AuditRepository,
 * exposes entity/actor/date-range finders only — none match the shapes these
 * endpoints need ("latest N across ALL actions" for the feed, "latest N for a
 * notification action-set", MAX(id) for the read-cursor, MAX(created_at) per
 * action for the mail-stale check). The freshest sibling
 * (AdminUserService::getUserDetail, §12.4 / S2) already keeps a prepared
 * audit-log read in the service rather than widening the cross-cutting repo;
 * this service mirrors that accepted-zone judgment (INV-3's actively-draining
 * controller rationale). Every read is $wpdb->prepare()d with each dynamic
 * value a placeholder. Routing these shapes through AuditRepository is a
 * documented follow-up (N3: trim the SELECT * to needed columns at the same
 * time).
 *
 * Registered in plugin-config.php.
 */
final class AdminActivityService
{
    // Shared audit-context hydration (enrichAuditContexts + fetchPostTitles)
    // — cross-domain, also used by AdminAPIController + AdminUserService (S2).
    use AdminBatchHelpers;

    /**
     * Recent activity feed from the audit log.
     *
     * @param array{limit?:int} $args  Pre-sanitised args (the route caps limit at 50).
     * @return array<int,array<string,mixed>>  Mapper-shaped activity lines, newest first.
     */
    public function getActivityFeed(array $args): array
    {
        global $wpdb;

        $limit = min((int) ($args['limit'] ?? 0), 50);
        if ($limit <= 0) {
            $limit = 10;
        }

        if (!AuditTable::exists()) {
            return [];
        }

        $auditTable = AuditTable::getTableName();
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$auditTable} ORDER BY created_at DESC LIMIT %d",
            $limit,
        ));

        if (empty($entries)) {
            return [];
        }

        // Collect actor IDs AND target user IDs (entity_type=user) for one batch fetch
        $userIdsToResolve = [];
        foreach ($entries as $entry) {
            if (!empty($entry->actor_id)) {
                $userIdsToResolve[] = (int) $entry->actor_id;
            }
            if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                $userIdsToResolve[] = (int) $entry->entity_id;
            }
        }

        $usersMap = !empty($userIdsToResolve)
            ? BatchQueryHelper::batchGetUsers(array_unique($userIdsToResolve))
            : [];

        $entries = $this->enrichAuditContexts($entries);

        $feed = [];
        foreach ($entries as $entry) {
            // Skip raw/system events that don't have a user-friendly label
            if (!AdminActivityMapper::isKnownAction($entry)) {
                continue;
            }

            $actorId = (int) ($entry->actor_id ?? 0);
            $actor = $usersMap[$actorId] ?? null;
            $actorName = $actor ? $actor->display_name : __('Systeem', 'stride');

            // Resolve target name from entity_id for user.* events
            $targetName = '';
            if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                $targetUser = $usersMap[(int) $entry->entity_id] ?? null;
                if ($targetUser) {
                    $targetName = $targetUser->display_name;
                }
            }

            $feed[] = AdminActivityMapper::fromAuditEntry($entry, $actorName, $targetName);
        }

        return $feed;
    }

    /**
     * System health indicators for the dashboard.
     *
     * @return array{registration: string, mail: string, audit: string}
     */
    public function getHealthChecks(): array
    {
        global $wpdb;

        $registrationTable = RegistrationTable::getTableName();
        $registrationTableExists = RegistrationTable::exists();
        $today = current_time('Y-m-d');

        // Last registration timestamp
        $lastRegistration = 0;
        if ($registrationTableExists) {
            $lastRegDate = $wpdb->get_var(
                "SELECT MAX(registered_at) FROM {$registrationTable}",
            );
            if ($lastRegDate) {
                $lastRegistration = (int) strtotime($lastRegDate);
            }
        }

        // Last mail send timestamp — check audit log for quote.sent action
        $lastMailSend = 0;
        if (AuditTable::exists()) {
            $auditTable = AuditTable::getTableName();
            $lastMailDate = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(created_at) FROM {$auditTable} WHERE action = %s",
                'quote.sent',
            ));
            if ($lastMailDate) {
                $lastMailSend = (int) strtotime($lastMailDate);
            }
        }

        // Any open editions with future start date?
        $hasOpenEditions = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ntdst_status'
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm_status.meta_value = 'open'
             AND pm_date.meta_value >= %s
             LIMIT 1",
            EditionCPT::POST_TYPE,
            $today,
        ));

        $service = new HealthCheckService();

        return $service->evaluate(
            $lastRegistration,
            $lastMailSend,
            $hasOpenEditions,
            // AF-2 residual: PII-reveal audit trail inactive = red flag.
            class_exists(AuditService::class),
        );
    }

    /**
     * Recent notifications from the audit log, with per-admin read/unread state.
     *
     * @return array{notifications: array<int,array<string,mixed>>, unread_count: int}
     */
    public function getNotifications(): array
    {
        $userId = get_current_user_id();
        $lastReadId = (int) get_user_meta($userId, 'stride_last_read_notification_id', true);

        global $wpdb;
        $table = $wpdb->prefix . 'audit_log';

        // Only notification-worthy events
        $actions = [
            'registration.created',
            'registration.cancelled',
            'quote.created',
            'completion.course_completed',
        ];
        $placeholders = implode(',', array_fill(0, count($actions), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE action IN ({$placeholders}) ORDER BY created_at DESC LIMIT 10",
            ...$actions,
        ));

        $entries = $this->enrichAuditContexts($entries ?: []);

        $notifications = array_map(function ($entry) use ($lastReadId) {
            $actorName = '';
            if (!empty($entry->actor_id)) {
                $user = get_userdata((int) $entry->actor_id);
                $actorName = $user ? $user->display_name : 'Onbekend';
            }
            $mapped = AdminActivityMapper::fromAuditEntry($entry, $actorName);
            $mapped['read'] = $mapped['id'] <= $lastReadId;

            return $mapped;
        }, $entries);

        $unread = count(array_filter($notifications, fn($n) => !$n['read']));

        return [
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ];
    }

    /**
     * Mark all notifications as read by storing the latest audit log ID against
     * the CURRENT user's meta only (no cross-user write — a security property).
     */
    public function markNotificationsRead(): bool
    {
        $userId = get_current_user_id();

        global $wpdb;
        $table = $wpdb->prefix . 'audit_log';
        $latestId = (int) $wpdb->get_var("SELECT MAX(id) FROM {$table}");

        update_user_meta($userId, 'stride_last_read_notification_id', $latestId);

        return true;
    }
}
