<?php
/**
 * Dashboard Tab: Meldingen (Notifications)
 *
 * Displays user notifications derived from audit log events.
 * Grouped by "Vandaag" / "Eerder". Opening this tab is itself the
 * "I've seen these" signal: items render in their current read/unread
 * state this load, then all are marked read so the badge clears next load.
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

// Get notifications (snapshot their read/unread state BEFORE auto-marking,
// so this render still shows what was new on arrival).
$notificationService = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
$notifications       = $notificationService->getNotifications($user_id);

// Group by today / earlier
$today  = wp_date('Y-m-d');
$groups = ['today' => [], 'earlier' => []];

foreach ($notifications as $n) {
    $date = wp_date('Y-m-d', $n['timestamp']);
    $key  = ($date === $today) ? 'today' : 'earlier';
    $groups[$key][] = $n;
}

// Viewing the tab marks everything read — clears the sidebar badge on the
// next page load. Done after the snapshot above, so the current render keeps
// its unread accents.
$notificationService->markAllRead($user_id);
?>

<div class="space-y-6">
    <?php if (!empty($notifications)) : ?>

        <!-- Vandaag -->
        <?php if (!empty($groups['today'])) : ?>
            <section>
                <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-2">
                    <?php esc_html_e('Vandaag', 'stridence'); ?>
                </h3>
                <div class="space-y-2">
                    <?php foreach ($groups['today'] as $notification) : ?>
                        <?php stridence_template_part('templates/dashboard/partials/notification-item', null, [
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
                <div class="space-y-2">
                    <?php foreach ($groups['earlier'] as $notification) : ?>
                        <?php stridence_template_part('templates/dashboard/partials/notification-item', null, [
                            'notification' => $notification,
                        ]); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php else : ?>

        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon'    => 'bell',
            'title'   => __('Geen meldingen', 'stridence'),
            'message' => __('Je hebt momenteel geen meldingen. Zodra er iets is dat je aandacht nodig heeft, verschijnt het hier.', 'stridence'),
        ]);
        ?>

    <?php endif; ?>
</div>
