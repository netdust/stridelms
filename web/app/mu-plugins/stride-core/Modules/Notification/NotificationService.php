<?php

declare(strict_types=1);

namespace Stride\Modules\Notification;

use NTDST\Audit\AuditService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Notification Service — derives notifications from audit log events.
 *
 * Queries wp_audit_log for events where the user is the subject
 * (context.user_id) but not the actor (excludes self-actions).
 * Also includes session note updates for editions the user is enrolled in.
 * Read state persisted in user meta.
 */
final class NotificationService implements \NTDST_Service_Meta
{
    private const META_KEY = '_stride_notifications_read';

    /**
     * Per-user transient for the unread badge count (audit H-4): the
     * dashboard reads it on every page view, so the audit-table query runs
     * at most once per TTL per user. Event-driven invalidation via the
     * ntdst/audit/recorded hook keeps it correct within the TTL window.
     * Upgrades to Redis automatically when a persistent object cache lands.
     */
    private const COUNT_TRANSIENT_PREFIX = 'stride_unread_count_';

    private const COUNT_TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Actions that must never surface as notifications. mail.sent is an
     * operational send-log (audit H-4 recommendation): current AuditBridge
     * rows omit context.user_id, but historical/drifted rows may carry it —
     * the exclusion makes the policy explicit either way.
     *
     * @var string[]
     */
    public const EXCLUDED_ACTIONS = ['mail.sent'];

    /** @var array<int, array> Per-request notification cache */
    private array $cache = [];

    public static function metadata(): array
    {
        return [
            'name'        => 'Notification Service',
            'description' => 'Event notifications from audit log',
            'priority'    => 20,
        ];
    }

    public function __construct(
        private readonly AuditService $auditService,
        private readonly RegistrationRepository $registrationRepo,
        private readonly NotificationMapper $mapper,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_mark_notifications_read', [$this, 'handleMarkAllRead'], 10, 2);

        // Event-driven cache invalidation: every new subject-targeted audit
        // entry (fired by ntdst-audit's AuditService::record) busts that
        // user's cached unread count. 5 args: the 5th ($actorId) is required
        // for completion.* events, whose subject IS the actor (CR-F2).
        add_action('ntdst/audit/recorded', [$this, 'onAuditRecorded'], 10, 5);
    }

    /**
     * Get notifications for a user from audit log.
     *
     * @return array<int, array{id: string, type: string, title: string, body: string, url: string, timestamp: int, read: bool}>
     */
    public function getNotifications(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $readMap = $this->getReadMap($userId);

        // 1. Get audit entries where user is the subject (not actor)
        $entries = $this->auditService->getForSubjectUser($userId, 50, 30, self::EXCLUDED_ACTIONS);

        // 2. Get session note updates for editions user is enrolled in
        $editionIds = $this->getEnrolledEditionIds($userId);
        $sessionNotes = $this->auditService->getSessionNoteUpdates($editionIds, 30);

        // 3. Merge and deduplicate
        $allEntries = array_merge($entries, $sessionNotes);

        // 4. Map to notification format
        $notifications = [];
        $seenIds = [];

        foreach ($allEntries as $entry) {
            $notification = $this->mapper->fromAuditEntry($entry);
            $id = $notification['id'];

            // Deduplicate
            if (isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            $notification['read'] = isset($readMap[$id]);
            $notifications[] = $notification;
        }

        // Sort newest first
        usort($notifications, fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        $this->cache[$userId] = $notifications;

        return $notifications;
    }

    /**
     * Count unread notifications.
     *
     * Cached in a per-user transient (audit H-4): the dashboard calls this
     * on every page view. TTL bounds staleness for events the listener
     * can't target (e.g. session.note_updated fans out to all enrollees);
     * onAuditRecorded() busts it immediately for subject-targeted events.
     */
    public function getUnreadCount(int $userId): int
    {
        $cached = get_transient(self::COUNT_TRANSIENT_PREFIX . $userId);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count = count(array_filter(
            $this->getNotifications($userId),
            fn(array $n): bool => !$n['read'],
        ));

        set_transient(self::COUNT_TRANSIENT_PREFIX . $userId, $count, self::COUNT_TTL);

        return $count;
    }

    /**
     * Audit listener: a new event targeting a subject user invalidates that
     * user's cached count — busts it for events recorded before the next
     * read (the delete-then-set race means an event landing between a
     * delete and the subsequent re-prime can still be cached stale for one
     * TTL; accepted tradeoff).
     *
     * Two subject shapes, mirroring findBySubjectUser()'s two UNION branches:
     *  - context.user_id → the explicit subject (admin acts on a user);
     *  - completion.* events carry no context.user_id — their subject IS the
     *    actor (LearnDash records the completing user as actor), so the
     *    actor's cache is invalidated for those (CR-F2).
     */
    public function onAuditRecorded(string $action, string $entityType, int $entityId, array $context, ?int $actorId = null): void
    {
        $subjectId = $context['user_id'] ?? null;

        if (is_numeric($subjectId) && (int) $subjectId > 0) {
            $this->invalidateCountCache((int) $subjectId);
        }

        if ($actorId !== null && $actorId > 0 && str_starts_with($action, 'completion.')) {
            $this->invalidateCountCache($actorId);
        }
    }

    /**
     * Mark all current notifications as read.
     */
    public function markAllRead(int $userId): void
    {
        $notifications = $this->getNotifications($userId);
        $readMap = $this->getReadMap($userId);
        $now = time();

        foreach ($notifications as $notification) {
            if (!isset($readMap[$notification['id']])) {
                $readMap[$notification['id']] = $now;
            }
        }

        update_user_meta($userId, self::META_KEY, wp_json_encode($readMap));
        $this->invalidateCountCache($userId);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(int $userId, string $notificationId): void
    {
        $readMap = $this->getReadMap($userId);

        if (!isset($readMap[$notificationId])) {
            $readMap[$notificationId] = time();
            update_user_meta($userId, self::META_KEY, wp_json_encode($readMap));
            $this->invalidateCountCache($userId);
        }
    }

    /**
     * Drop both cache layers for a user: the count transient and the
     * per-request notification list.
     */
    private function invalidateCountCache(int $userId): void
    {
        delete_transient(self::COUNT_TRANSIENT_PREFIX . $userId);
        unset($this->cache[$userId]);
    }

    /**
     * API handler: mark all notifications read.
     */
    public function handleMarkAllRead(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je bent niet ingelogd.', 'stride'));
        }

        $this->markAllRead($userId);

        return ['success' => true];
    }

    /**
     * @return array<string, int> notification_id => timestamp
     */
    private function getReadMap(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);

        if (empty($raw) || !is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get edition IDs the user is currently enrolled in.
     *
     * @return int[]
     */
    private function getEnrolledEditionIds(int $userId): array
    {
        $registrations = $this->registrationRepo->findByUser($userId);

        $ids = [];
        foreach ($registrations as $reg) {
            if (!empty($reg->edition_id)) {
                $ids[] = (int) $reg->edition_id;
            }
        }

        return array_unique($ids);
    }
}
