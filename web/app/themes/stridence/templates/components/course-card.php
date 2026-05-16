<?php
/**
 * Course Card — unified expandable component
 *
 * Renders a collapsible course card. Three modes via $args['type']:
 *   - 'edition'  : enrolled classroom edition (sessions, tasks, CTA)
 *   - 'online'   : enrolled online course (progress bar, resume CTA)
 *   - 'public'   : course in trajectory context (excerpt, upcoming editions)
 *
 * Contract: see docs/superpowers/specs/2026-05-16-unified-course-card-design.md
 *
 * @param array $args See spec for full shape.
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$args = $args ?? [];

$courseId    = (int) ($args['course_id'] ?? 0);
$courseTitle = (string) ($args['course_title'] ?? '');
$thumbnailId = $args['thumbnail_id'] ?? null;
$type        = (string) ($args['type'] ?? 'public');
$statusPill  = $args['status_pill'] ?? null;
$enrolled    = (bool) ($args['enrolled'] ?? false);
$initialOpen = (bool) ($args['initial_open'] ?? false);

$meta = $args['meta'] ?? [];
$body = $args['body'] ?? [];

$startDate         = $meta['start_date'] ?? null;
$venue             = $meta['venue'] ?? null;
$progressLabel     = $meta['progress_label'] ?? null;
$daysRemaining     = $meta['days_remaining'] ?? null;
$pendingTasksCount = (int) ($meta['pending_tasks_count'] ?? 0);

$excerpt          = $body['excerpt'] ?? null;
$progressPct      = $body['progress_pct'] ?? null;
$sessions         = $body['sessions'] ?? [];
$upcomingEditions = $body['upcoming_editions'] ?? [];
$taskSummary      = $body['task_summary'] ?? null;
$primaryCta       = $body['primary_cta'] ?? null;
$secondaryCta     = $body['secondary_cta'] ?? null;

// Pill tone → Tailwind classes
$pillToneClasses = [
    'primary' => 'bg-primary/10 text-primary',
    'accent'  => 'bg-accent/10 text-accent',
    'muted'   => 'bg-surface-alt text-text-muted',
];
$pillClass = $statusPill ? ($pillToneClasses[$statusPill['tone'] ?? 'muted'] ?? $pillToneClasses['muted']) : '';
?>
<div class="card" x-data="expandable(<?php echo $initialOpen ? 'true' : 'false'; ?>)">
    <button type="button"
            class="w-full p-4 flex items-center gap-4 text-left"
            @click="toggle()">
        <!-- Thumbnail -->
        <div class="w-14 h-14 rounded overflow-hidden flex-shrink-0">
            <?php if ($thumbnailId) : ?>
                <?php echo wp_get_attachment_image($thumbnailId, 'thumbnail', false, ['class' => 'w-full h-full object-cover']); ?>
            <?php else : ?>
                <div class="w-full h-full bg-surface-alt flex items-center justify-center">
                    <?php echo stridence_icon('book-open', 'w-6 h-6 text-text-muted'); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Title + meta line -->
        <div class="flex-1 min-w-0">
            <h4 class="font-semibold text-text truncate">
                <?php echo esc_html($courseTitle); ?>
            </h4>
            <div class="flex flex-wrap gap-3 mt-1 text-sm text-text-muted">
                <?php if ($statusPill) : ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?php echo esc_attr($pillClass); ?>">
                        <?php echo esc_html($statusPill['label']); ?>
                    </span>
                <?php endif; ?>
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
                <?php if ($progressLabel) : ?>
                    <span><?php echo esc_html($progressLabel); ?></span>
                <?php endif; ?>
                <?php if ($daysRemaining !== null && $daysRemaining > 0 && $daysRemaining <= 30) : ?>
                    <span class="text-status-warning">
                        <?php echo esc_html(sprintf(__('Nog %d dagen', 'stridence'), $daysRemaining)); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending tasks dot -->
        <?php if ($pendingTasksCount > 0) : ?>
            <span class="w-2 h-2 rounded-full bg-warning shrink-0" title="<?php
                echo esc_attr(sprintf(
                    _n('%d openstaande taak', '%d openstaande taken', $pendingTasksCount, 'stridence'),
                    $pendingTasksCount
                ));
            ?>"></span>
        <?php endif; ?>

        <!-- Chevron -->
        <span class="shrink-0 text-text-muted transition-transform duration-200"
              :class="{ 'rotate-180': open }">
            <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
        </span>
    </button>

    <!-- Expanded body -->
    <div x-show="open" x-collapse class="border-t border-border">
        <div class="p-4 space-y-4">
            <?php if ($excerpt) : ?>
                <p class="text-sm text-text-muted">
                    <?php echo esc_html($excerpt); ?>
                </p>
            <?php endif; ?>

            <?php if ($progressPct !== null) : ?>
                <div>
                    <div class="flex items-center justify-between text-xs text-text-muted mb-1">
                        <?php if ($progressLabel) : ?>
                            <span><?php echo esc_html($progressLabel); ?></span>
                        <?php else : ?>
                            <span><?php esc_html_e('Voortgang', 'stridence'); ?></span>
                        <?php endif; ?>
                        <span><?php echo (int) $progressPct; ?>%</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-surface-alt overflow-hidden">
                        <div class="h-full bg-primary rounded-full" style="width: <?php echo (int) $progressPct; ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($sessions)) : ?>
                <div class="space-y-2">
                    <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                        <?php esc_html_e('Sessies', 'stridence'); ?>
                    </p>
                    <div class="divide-y divide-border rounded-lg border border-border">
                        <?php foreach ($sessions as $s) :
                            $sDate  = $s['date'] ?? '';
                            $sStart = $s['start_time'] ?? '';
                            $sEnd   = $s['end_time'] ?? '';
                        ?>
                            <div class="p-3 flex items-center gap-3 text-sm text-text-muted">
                                <?php if ($sDate) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                        <?php echo esc_html(stride_format_date($sDate)); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($sStart || $sEnd) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('clock', 'w-4 h-4'); ?>
                                        <?php echo esc_html(trim($sStart . ' – ' . $sEnd, ' –')); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($upcomingEditions)) : ?>
                <div class="space-y-2">
                    <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                        <?php esc_html_e('Beschikbare edities', 'stridence'); ?>
                    </p>
                    <div class="divide-y divide-border rounded-lg border border-border">
                        <?php foreach ($upcomingEditions as $ed) : ?>
                            <div class="p-3 flex items-center gap-3 text-sm text-text-muted">
                                <?php if (!empty($ed['start_date'])) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                        <?php echo esc_html(stride_format_date($ed['start_date'])); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($ed['venue'])) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                                        <?php echo esc_html($ed['venue']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($type === 'public') : ?>
                <p class="text-sm text-text-muted italic">
                    <?php esc_html_e('Nog geen edities gepland', 'stridence'); ?>
                </p>
            <?php endif; ?>

            <?php if ($taskSummary) :
                $tsTotal = (int) ($taskSummary['total'] ?? 0);
                $tsDone  = (int) ($taskSummary['completed'] ?? 0);
                if ($tsTotal > 0) : ?>
                    <p class="text-sm text-text-muted">
                        <?php echo esc_html(sprintf(
                            __('Taken: %d van %d voltooid', 'stridence'),
                            $tsDone,
                            $tsTotal
                        )); ?>
                    </p>
                <?php endif;
            endif; ?>

            <?php if ($primaryCta || $secondaryCta) : ?>
                <div class="flex flex-wrap gap-3 pt-2">
                    <?php if ($primaryCta) : ?>
                        <a href="<?php echo esc_url($primaryCta['url']); ?>" class="btn-primary text-sm">
                            <?php echo esc_html($primaryCta['label']); ?>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 ml-1'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($secondaryCta) : ?>
                        <a href="<?php echo esc_url($secondaryCta['url']); ?>" class="btn-secondary text-sm">
                            <?php echo esc_html($secondaryCta['label']); ?>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 ml-1'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
