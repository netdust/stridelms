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
        $entries = $this->auditService->getForSubjectUser($userId, 50, 30);

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
     */
    public function getUnreadCount(int $userId): int
    {
        return count(array_filter(
            $this->getNotifications($userId),
            fn(array $n): bool => !$n['read'],
        ));
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
        unset($this->cache[$userId]);
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
        }
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
