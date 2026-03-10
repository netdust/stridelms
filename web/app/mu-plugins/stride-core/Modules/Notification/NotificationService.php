<?php

declare(strict_types=1);

namespace Stride\Modules\Notification;

use Stride\Modules\User\UserDashboardService;
use WP_Error;

/**
 * Notification Service — derives notifications from dashboard action data.
 *
 * Does NOT store notifications. It transforms the action list from
 * UserDashboardService into a notification format with read/unread state.
 * Read state is persisted in user meta.
 */
final class NotificationService implements \NTDST_Service_Meta
{
    private const META_KEY = '_stride_notifications_read';

    public static function metadata(): array
    {
        return [
            'name'        => 'Notification Service',
            'description' => 'Derives user notifications from dashboard action data',
            'priority'    => 20,
        ];
    }

    public function __construct(
        private readonly UserDashboardService $dashboardService,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_mark_notifications_read', [$this, 'handleMarkAllRead'], 10, 2);
    }

    /**
     * Get all notifications for a user, derived from dashboard actions.
     *
     * @return array<int, array{id: string, type: string, title: string, body: string, url: string, timestamp: int, read: bool}>
     */
    public function getNotifications(int $userId): array
    {
        $homeData = $this->dashboardService->getHomeData($userId);
        $actions  = $homeData['actions'] ?? [];
        $readMap  = $this->getReadMap($userId);
        $now      = time();

        $notifications = [];

        foreach ($actions as $index => $action) {
            $id    = 'action_' . md5($action['label'] ?? (string) $index);
            $type  = $this->resolveType($action);
            $title = $this->resolveTitle($action);
            $body  = $this->resolveBody($action);

            $notifications[] = [
                'id'        => $id,
                'type'      => $type,
                'title'     => $title,
                'body'      => $body,
                'url'       => $action['url'] ?? '',
                'timestamp' => $this->resolveTimestamp($action, $now, $index),
                'read'      => isset($readMap[$id]),
            ];
        }

        // Sort newest first
        usort($notifications, fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return $notifications;
    }

    /**
     * Count unread notifications for a user.
     */
    public function getUnreadCount(int $userId): int
    {
        $notifications = $this->getNotifications($userId);

        return count(array_filter($notifications, fn(array $n): bool => !$n['read']));
    }

    /**
     * Mark all current notifications as read.
     */
    public function markAllRead(int $userId): void
    {
        $notifications = $this->getNotifications($userId);
        $readMap       = $this->getReadMap($userId);
        $now           = time();

        foreach ($notifications as $notification) {
            if (!isset($readMap[$notification['id']])) {
                $readMap[$notification['id']] = $now;
            }
        }

        update_user_meta($userId, self::META_KEY, wp_json_encode($readMap));
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
     * API handler: mark all notifications read for the current user.
     *
     * @param mixed              $data   Existing filter data (unused)
     * @param array<string,mixed> $params Request parameters (unused)
     * @return array<string,bool>|WP_Error
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
     * Get the read-state map from user meta.
     *
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
     * Map action type to notification type.
     */
    private function resolveType(array $action): string
    {
        $actionType = $action['type'] ?? '';

        return match ($actionType) {
            'upcoming_session'              => 'session',
            'certificate'                   => 'certificate',
            'unsigned_quote'                => 'quote',
            'action_item', 'enrollment', 'post_course' => 'action',
            default                         => 'action',
        };
    }

    /**
     * Extract a clean title from the action label.
     *
     * Action labels use " -- " separators. The first segment is the title.
     */
    private function resolveTitle(array $action): string
    {
        $label = $action['label'] ?? '';

        // Labels often use " — " (em-dash) as separator, e.g. "Cursus A — 15 maart 2026"
        $parts = explode(' — ', $label, 2);

        return trim($parts[0]);
    }

    /**
     * Extract secondary text from the action label.
     */
    private function resolveBody(array $action): string
    {
        $label = $action['label'] ?? '';
        $parts = explode(' — ', $label, 2);

        return isset($parts[1]) ? trim($parts[1]) : '';
    }

    /**
     * Compute a stable timestamp for sorting.
     *
     * Session actions have a date embedded; others get a recent timestamp
     * spread across seconds so ordering is stable.
     */
    private function resolveTimestamp(array $action, int $now, int $index): int
    {
        $type = $action['type'] ?? '';

        // Upcoming sessions: try to parse the date from the label
        if ($type === 'upcoming_session') {
            $label = $action['label'] ?? '';
            // Label format: "Course Title — 2026-03-15"
            $parts = explode(' — ', $label, 2);
            if (isset($parts[1])) {
                $parsed = strtotime(trim($parts[1]));
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        // Spread remaining items across recent seconds for stable ordering
        return $now - ($index * 60);
    }
}
