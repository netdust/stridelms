<?php
/**
 * Trajectory Tab: Berichten (Messages) — Helder Tij
 *
 * Read-only announcement board from supervisors.
 *
 * Restyle only: white bordered rows with a 34px initials avatar, bold
 * author name + type chip, faint date on the right and relaxed body
 * text. Data source and rendering logic unchanged — the data carries
 * no read/unread state, so every row uses the neutral card style.
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
        'class' => 'bg-accent-subtle text-accent-hover',
    ],
    'faq' => [
        'label' => __('FAQ', 'stridence'),
        'class' => 'bg-surface-alt text-text-muted',
    ],
    'update' => [
        'label' => __('Update', 'stridence'),
        'class' => 'bg-success/10 text-success',
    ],
];
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold text-text">
            <?php esc_html_e('Berichten', 'stridence'); ?>
        </h2>
        <?php if (!empty($messages)) : ?>
            <span class="text-[13px] text-text-faint">
                <?php printf(esc_html__('%d berichten', 'stridence'), count($messages)); ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (empty($messages)) : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon' => 'bell',
            'title' => __('Geen berichten', 'stridence'),
            'message' => __('Er zijn nog geen berichten geplaatst voor dit traject.', 'stridence'),
        ]);
        ?>
    <?php else : ?>
        <div class="flex flex-col gap-3">
            <?php foreach ($messages as $message) :
                $type = $message['type'] ?? 'announcement';
                $typeConfig = $messageTypes[$type] ?? $messageTypes['announcement'];
                $authorId = (int) ($message['author'] ?? 0);
                $author = $authorId ? get_userdata($authorId) : null;
                $authorName = $author ? ($author->display_name ?: $author->user_login) : __('Beheerder', 'stridence');
                $date = $message['date'] ?? '';

                // Avatar initials: first + last word of the author name (presentation only)
                $nameParts = preg_split('/\s+/', trim($authorName)) ?: [];
                $initials = mb_strtoupper(mb_substr($nameParts[0] ?? '', 0, 1));
                if (count($nameParts) > 1) {
                    $initials .= mb_strtoupper(mb_substr(end($nameParts), 0, 1));
                }
                ?>
                <article class="bg-surface-card border border-border-soft rounded-[12px] p-4 flex items-start gap-3.5">
                    <span class="shrink-0 w-[34px] h-[34px] rounded-full bg-accent-subtle text-accent-hover flex items-center justify-center text-[13px] font-extrabold">
                        <?php echo esc_html($initials); ?>
                    </span>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-2.5 gap-y-0.5">
                            <span class="text-[13px] font-bold text-text">
                                <?php echo esc_html($authorName); ?>
                                <span class="ml-1.5 inline-block align-middle text-[11px] font-bold px-1.5 py-px rounded-[6px] <?php echo esc_attr($typeConfig['class']); ?>">
                                    <?php echo esc_html($typeConfig['label']); ?>
                                </span>
                            </span>
                            <?php if ($date) : ?>
                                <span class="text-[12px] text-text-faint">
                                    <?php echo esc_html(date_i18n('j F Y', strtotime($date))); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="text-[14px] text-text leading-relaxed mt-1">
                            <?php echo wp_kses_post(nl2br(esc_html($message['content'] ?? ''))); ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
