<?php
/**
 * Dashboard Tab: Meldingen (Notifications)
 *
 * Displays user notifications derived from dashboard actions.
 * Grouped by "Vandaag" / "Eerder" with mark-all-read capability.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get notifications
$notificationService = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
$notifications       = $notificationService->getNotifications($user_id);
$unreadCount         = $notificationService->getUnreadCount($user_id);

// Group by today / earlier
$today  = wp_date('Y-m-d');
$groups = ['today' => [], 'earlier' => []];

foreach ($notifications as $n) {
    $date = wp_date('Y-m-d', $n['timestamp']);
    $key  = ($date === $today) ? 'today' : 'earlier';
    $groups[$key][] = $n;
}
?>

<div class="space-y-6">
    <?php if (!empty($notifications)) : ?>

        <!-- Header with mark-all-read -->
        <div class="flex items-center justify-between">
            <h2 class="dash-heading">
                <?php esc_html_e('Meldingen', 'stridence'); ?>
            </h2>
            <?php if ($unreadCount > 0) : ?>
                <button type="button"
                        class="text-sm text-primary hover:underline"
                        onclick="(async () => { await ntdstAPI.call('stride_mark_notifications_read'); window.location.reload(); })()">
                    <?php esc_html_e('Alles gelezen', 'stridence'); ?>
                </button>
            <?php endif; ?>
        </div>

        <!-- Vandaag -->
        <?php if (!empty($groups['today'])) : ?>
            <section>
                <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-2">
                    <?php esc_html_e('Vandaag', 'stridence'); ?>
                </h3>
                <div class="space-y-1">
                    <?php foreach ($groups['today'] as $notification) : ?>
                        <?php get_template_part('templates/dashboard/partials/notification-item', null, [
                            'notification' => $notification,
                        ]); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Eerder -->
        <?php if (!empty($groups['earlier'])) : ?>
            <section>
                <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-2">
                    <?php esc_html_e('Eerder', 'stridence'); ?>
                </h3>
                <div class="space-y-1">
                    <?php foreach ($groups['earlier'] as $notification) : ?>
                        <?php get_template_part('templates/dashboard/partials/notification-item', null, [
                            'notification' => $notification,
                        ]); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php else : ?>

        <?php
        get_template_part('partials/empty-state', null, [
            'icon'    => 'bell',
            'title'   => __('Geen meldingen', 'stridence'),
            'message' => __('Je hebt momenteel geen meldingen. Zodra er iets is dat je aandacht nodig heeft, verschijnt het hier.', 'stridence'),
        ]);
        ?>

    <?php endif; ?>
</div>
