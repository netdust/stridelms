<?php
/**
 * Trajectory Tab: Berichten (Messages)
 *
 * Read-only announcement board from supervisors.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$trajectory = $args['trajectory'];
$dashboardService = $args['dashboard_service'];

// Get messages
$messages = $dashboardService->getMessages($trajectory->ID);

// Message type config
$messageTypes = [
    'announcement' => [
        'label' => __('Aankondiging', 'stridence'),
        'icon' => 'bell',
        'class' => 'bg-primary/10 text-primary',
    ],
    'faq' => [
        'label' => __('FAQ', 'stridence'),
        'icon' => 'help-circle',
        'class' => 'bg-accent/10 text-accent',
    ],
    'update' => [
        'label' => __('Update', 'stridence'),
        'icon' => 'info',
        'class' => 'bg-success/10 text-success',
    ],
];
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-text">
            <?php esc_html_e('Berichten', 'stridence'); ?>
        </h2>
        <?php if (!empty($messages)) : ?>
            <span class="text-sm text-text-muted">
                <?php printf(esc_html__('%d berichten', 'stridence'), count($messages)); ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (empty($messages)) : ?>
        <?php
        get_template_part('partials/empty-state', null, [
            'icon' => 'bell',
            'title' => __('Geen berichten', 'stridence'),
            'message' => __('Er zijn nog geen berichten geplaatst voor dit traject.', 'stridence'),
        ]);
        ?>
    <?php else : ?>
        <div class="space-y-4">
            <?php foreach ($messages as $message) :
                $type = $message['type'] ?? 'announcement';
                $typeConfig = $messageTypes[$type] ?? $messageTypes['announcement'];
                $authorId = (int) ($message['author'] ?? 0);
                $author = $authorId ? get_userdata($authorId) : null;
                $authorName = $author ? ($author->display_name ?: $author->user_login) : __('Beheerder', 'stridence');
                $date = $message['date'] ?? '';
            ?>
                <article class="card p-4">
                    <header class="flex items-start justify-between gap-4 mb-3">
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 w-10 h-10 rounded-full <?php echo esc_attr($typeConfig['class']); ?> flex items-center justify-center">
                                <?php echo stridence_icon($typeConfig['icon'], 'w-5 h-5'); ?>
                            </span>
                            <div>
                                <span class="text-xs font-medium <?php echo esc_attr($typeConfig['class']); ?> px-2 py-0.5 rounded">
                                    <?php echo esc_html($typeConfig['label']); ?>
                                </span>
                                <p class="text-sm text-text-muted mt-1">
                                    <?php echo esc_html($authorName); ?>
                                    <?php if ($date) : ?>
                                        <span class="mx-1">&middot;</span>
                                        <?php echo esc_html(date_i18n('j F Y', strtotime($date))); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </header>

                    <div class="prose prose-sm max-w-none text-text">
                        <?php echo wp_kses_post(nl2br(esc_html($message['content'] ?? ''))); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
