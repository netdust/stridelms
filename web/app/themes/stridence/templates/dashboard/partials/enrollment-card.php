<?php
/**
 * Enrollment Card Partial
 *
 * Compact card for the home screen overview grid.
 * Supports both edition (classroom) and online enrollment types.
 *
 * @param array $args {
 *     @type array $enrollment Enrollment data from UserDashboardService::getHomeData()
 *                             with 'type' field: 'edition' or 'online'
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$enrollment = $args['enrollment'] ?? [];
if (empty($enrollment)) {
    return;
}

$type     = $enrollment['type'] ?? 'edition';
$title    = $enrollment['course_title'] ?? '';
$progress = (int) ($enrollment['progress']['attended'] ?? $enrollment['progress'] ?? 0);

// Edition-specific fields
$startDate = $enrollment['start_date'] ?? '';
$venue     = $enrollment['venue'] ?? '';
$editionId = $enrollment['edition_id'] ?? 0;

// Online-specific fields
$courseUrl        = $enrollment['course_url'] ?? '';
$formatLabel      = $enrollment['format_label'] ?? __('Online', 'stridence');
$totalLessons     = (int) ($enrollment['total_lessons'] ?? 0);
$completedLessons = (int) ($enrollment['completed_lessons'] ?? 0);

// Badge label
$badgeLabel = $type === 'edition'
    ? __('Klassikaal', 'stridence')
    : $formatLabel;

$badgeClass = $type === 'edition'
    ? 'bg-primary/10 text-primary'
    : 'bg-accent/10 text-accent';

// Determine progress percentage for the ring
$progressPercent = 0;
if ($type === 'online') {
    $progressPercent = (int) ($enrollment['progress'] ?? 0);
} elseif ($type === 'edition' && is_array($enrollment['progress'] ?? null)) {
    $required = (int) ($enrollment['progress']['required'] ?? 0);
    $attended = (int) ($enrollment['progress']['attended'] ?? 0);
    $progressPercent = $required > 0 ? (int) round(($attended / $required) * 100) : 0;
}
?>

<div class="dash-card-interactive relative">
    <!-- Type badge -->
    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo esc_attr($badgeClass); ?> mb-2">
        <?php echo esc_html($badgeLabel); ?>
    </span>

    <!-- Progress ring (top-right) -->
    <?php if ($progressPercent > 0) : ?>
        <div class="absolute top-5 right-5">
            <?php
            get_template_part('templates/dashboard/partials/progress-ring', null, [
                'progress' => $progressPercent,
                'size'     => 48,
            ]);
            ?>
        </div>
    <?php endif; ?>

    <!-- Course title -->
    <h3 class="font-semibold text-text truncate pr-16 mb-2">
        <?php echo esc_html($title); ?>
    </h3>

    <!-- Type-specific details -->
    <?php if ($type === 'edition') : ?>
        <div class="flex flex-wrap gap-3 text-sm text-text-muted">
            <?php if ($startDate) : ?>
                <span class="flex items-center gap-1">
                    <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                    <?php echo esc_html(stride_format_date($startDate)); ?>
                </span>
            <?php endif; ?>
            <?php if ($venue) : ?>
                <span class="flex items-center gap-1">
                    <?php echo stridence_icon('map-pin', 'w-3.5 h-3.5'); ?>
                    <?php echo esc_html($venue); ?>
                </span>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <?php if ($totalLessons > 0) : ?>
            <p class="text-sm text-text-muted">
                <?php echo esc_html(sprintf(
                    __('%d van %d lessen', 'stridence'),
                    $completedLessons,
                    $totalLessons
                )); ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <!-- CTA link -->
    <div class="mt-3">
        <?php if ($type === 'online' && $courseUrl) : ?>
            <a href="<?php echo esc_url($courseUrl); ?>"
               class="text-sm font-medium text-primary hover:underline">
                <?php esc_html_e('Ga verder', 'stridence'); ?>
                <?php echo stridence_icon('chevron-right', 'w-3.5 h-3.5 inline-block'); ?>
            </a>
        <?php elseif ($editionId) : ?>
            <a href="<?php echo esc_url(get_permalink($editionId)); ?>"
               class="text-sm font-medium text-primary hover:underline">
                <?php esc_html_e('Bekijk', 'stridence'); ?>
                <?php echo stridence_icon('chevron-right', 'w-3.5 h-3.5 inline-block'); ?>
            </a>
        <?php endif; ?>
    </div>
</div>
