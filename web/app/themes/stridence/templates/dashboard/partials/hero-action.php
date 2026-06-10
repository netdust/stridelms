<?php
/**
 * Hero Action Partial
 *
 * Context-dependent hero card for the dashboard home screen.
 * Renders different content based on the hero type resolved by UserDashboardService.
 *
 * @param array $args {
 *     @type array $hero {
 *         @type string $type Hero type: upcoming_session, action_required, continue_course, active_enrollment, certificate_ready
 *         @type array  $data Type-specific data
 *     }
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$hero = $args['hero'] ?? null;
if (!$hero || empty($hero['type']) || empty($hero['data'])) {
    return;
}

$type = $hero['type'];
$data = $hero['data'];

// Hero badge config per type
$badge_config = match ($type) {
    'upcoming_session' => [
        'icon' => 'calendar',
        'bg'   => 'bg-info/10',
        'text' => 'text-info',
    ],
    'action_required' => [
        'icon' => 'alert-circle',
        'bg'   => 'bg-warning/10',
        'text' => 'text-warning',
    ],
    'continue_course' => [
        'icon' => 'trending-up',
        'bg'   => 'bg-primary/10',
        'text' => 'text-primary',
    ],
    'active_enrollment' => [
        'icon' => 'book-open',
        'bg'   => 'bg-primary/10',
        'text' => 'text-primary',
    ],
    'certificate_ready' => [
        'icon' => 'award',
        'bg'   => 'bg-success/10',
        'text' => 'text-success',
    ],
    default => ['icon' => 'info', 'bg' => 'bg-primary/10', 'text' => 'text-primary'],
};
?>

<?php if ($type === 'upcoming_session') : ?>
    <?php
    $isToday = ($data['date'] ?? '') === date('Y-m-d');
    $label   = $isToday ? __('Vandaag', 'stridence') : __('Binnenkort', 'stridence');
    ?>
    <div class="dash-card-hero">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo esc_attr($badge_config['bg'] . ' ' . $badge_config['text']); ?> mb-3">
            <?php echo stridence_icon($badge_config['icon'], 'w-3.5 h-3.5'); ?>
            <?php echo esc_html($label); ?>
        </span>
        <h3 class="font-heading text-lg font-bold text-text mb-2">
            <?php echo esc_html($data['course_title'] ?? ''); ?>
        </h3>
        <div class="flex flex-wrap gap-4 text-sm text-text-muted">
            <?php if (!empty($data['date'])) : ?>
                <span class="flex items-center gap-1.5">
                    <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                    <?php echo esc_html(stride_format_date($data['date'])); ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($data['start_time'])) : ?>
                <span class="flex items-center gap-1.5">
                    <?php echo stridence_icon('clock', 'w-4 h-4'); ?>
                    <?php echo esc_html($data['start_time']); ?>
                    <?php if (!empty($data['end_time'])) : ?>
                        – <?php echo esc_html($data['end_time']); ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($data['venue'])) : ?>
                <span class="flex items-center gap-1.5">
                    <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                    <?php echo esc_html($data['venue']); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($type === 'action_required') : ?>
    <div class="dash-card-hero">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo esc_attr($badge_config['bg'] . ' ' . $badge_config['text']); ?> mb-3">
            <?php echo stridence_icon($badge_config['icon'], 'w-3.5 h-3.5'); ?>
            <?php esc_html_e('Actie vereist', 'stridence'); ?>
        </span>
        <h3 class="font-heading text-lg font-bold text-text mb-1">
            <?php echo esc_html($data['course_title'] ?? ''); ?>
        </h3>
        <p class="text-sm text-text-muted mb-4">
            <?php echo esc_html($data['label'] ?? ''); ?>
        </p>
        <?php if (!empty($data['url'])) : ?>
            <a href="<?php echo esc_url($data['url']); ?>" class="btn-primary btn-sm">
                <?php echo esc_html($data['label'] ?? __('Voltooien', 'stridence')); ?>
            </a>
        <?php endif; ?>
    </div>

<?php elseif ($type === 'continue_course') : ?>
    <?php $progress = (int) ($data['progress'] ?? 0); ?>
    <div class="dash-card-hero">
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo esc_attr($badge_config['bg'] . ' ' . $badge_config['text']); ?> mb-3">
                    <?php echo stridence_icon($badge_config['icon'], 'w-3.5 h-3.5'); ?>
                    <?php esc_html_e('Ga verder', 'stridence'); ?>
                </span>
                <h3 class="font-heading text-lg font-bold text-text mb-1">
                    <?php echo esc_html($data['course_title'] ?? ''); ?>
                </h3>
                <p class="text-sm text-text-muted mb-4">
                    <?php echo esc_html($data['format_label'] ?? __('Online', 'stridence')); ?>
                    <?php if (($data['total_lessons'] ?? 0) > 0) : ?>
                        — <?php echo esc_html(sprintf(
                            __('%d van %d lessen', 'stridence'),
                            $data['completed_lessons'] ?? 0,
                            $data['total_lessons'],
                        )); ?>
                    <?php endif; ?>
                </p>
                <?php if (!empty($data['course_url'])) : ?>
                    <a href="<?php echo esc_url($data['course_url']); ?>" class="btn-primary btn-sm">
                        <?php esc_html_e('Verder leren', 'stridence'); ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($progress > 0) : ?>
                <?php
                stridence_template_part('templates/dashboard/partials/progress-ring', null, [
                    'progress' => $progress,
                    'size'     => 64,
                ]);
                ?>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($type === 'active_enrollment') : ?>
    <div class="dash-card-hero">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo esc_attr($badge_config['bg'] . ' ' . $badge_config['text']); ?> mb-3">
            <?php echo stridence_icon($badge_config['icon'], 'w-3.5 h-3.5'); ?>
            <?php esc_html_e('Actieve opleiding', 'stridence'); ?>
        </span>
        <h3 class="font-heading text-lg font-bold text-text mb-2">
            <?php echo esc_html($data['course_title'] ?? ''); ?>
        </h3>
        <?php if (!empty($data['start_date'])) : ?>
            <p class="text-sm text-text-muted flex items-center gap-1.5">
                <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                <?php echo esc_html(stride_format_date($data['start_date'])); ?>
            </p>
        <?php endif; ?>
    </div>

<?php elseif ($type === 'certificate_ready') : ?>
    <div class="dash-card-hero">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo esc_attr($badge_config['bg'] . ' ' . $badge_config['text']); ?> mb-3">
            <?php echo stridence_icon($badge_config['icon'], 'w-3.5 h-3.5'); ?>
            <?php esc_html_e('Gefeliciteerd!', 'stridence'); ?>
        </span>
        <h3 class="font-heading text-lg font-bold text-text mb-2">
            <?php echo esc_html($data['course_title'] ?? ''); ?>
        </h3>
        <?php $certUrl = $data['certificate_url'] ?? ''; ?>
        <?php if ($certUrl) : ?>
            <a href="<?php echo esc_url($certUrl); ?>"
               class="btn-primary btn-sm"
               target="_blank"
               rel="noopener">
                <?php echo stridence_icon('download', 'w-4 h-4 mr-1'); ?>
                <?php esc_html_e('Download certificaat', 'stridence'); ?>
            </a>
        <?php endif; ?>
    </div>

<?php endif; ?>
