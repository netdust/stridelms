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
$editionId   = (int) ($args['edition_id'] ?? 0);
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
$imminence         = $meta['imminence'] ?? null;
$editionCount      = $meta['edition_count'] ?? null;   // overview only
$statusLabel       = $meta['status_label'] ?? null;    // overview only

$excerpt          = $body['excerpt'] ?? null;
$progressPct      = $body['progress_pct'] ?? null;
$sessions         = $body['sessions'] ?? [];
$upcomingEditions = $body['upcoming_editions'] ?? [];
$taskSummary      = $body['task_summary'] ?? null;
$primaryCta       = $body['primary_cta'] ?? null;
$secondaryCta     = $body['secondary_cta'] ?? null;
$courseUrl        = $body['course_url'] ?? null;       // overview only

// Overview (trajectory) mode uses a distinct header/body layout: a read-only
// course preview with no per-edition enrol action. Other modes keep their
// existing compact layout.
$isOverview = ($type === 'public');

$statusLabelToneClasses = [
    'success' => 'text-status-success',
    'muted'   => 'text-text-muted',
];

// Pill tone → Tailwind classes
$pillToneClasses = [
    'primary' => 'bg-primary/10 text-primary',
    'accent'  => 'bg-accent/10 text-accent',
    'muted'   => 'bg-surface-alt text-text-muted',
];
$pillClass = $statusPill ? ($pillToneClasses[$statusPill['tone'] ?? 'muted'] ?? $pillToneClasses['muted']) : '';
?>
<div class="bg-surface-card rounded-[14px] shadow-card"
     x-data="expandable(<?php echo $initialOpen ? 'true' : 'false'; ?>)"
     <?php if ($courseId) : ?>data-course-id="<?php echo (int) $courseId; ?>"<?php endif; ?>
     <?php if ($editionId) : ?>data-edition-id="<?php echo (int) $editionId; ?>"<?php endif; ?>>
    <?php if ($isOverview) : ?>
    <!-- ── Overview (trajectory) header ───────────────────────────────────
         Read-only course preview. Badge sits on its own line under the title;
         "N editie(s) · date" meta; informational planning label on the right;
         circular chevron. No enrol action anywhere on this surface. -->
    <button type="button"
            class="w-full p-5 flex items-start gap-4 text-left cursor-pointer"
            @click="toggle()">
        <!-- Icon -->
        <div class="w-14 h-14 rounded-xl overflow-hidden flex-shrink-0">
            <?php if ($thumbnailId) : ?>
                <?php echo wp_get_attachment_image($thumbnailId, 'thumbnail', false, ['class' => 'w-full h-full object-cover']); ?>
            <?php else : ?>
                <div class="w-full h-full bg-primary/10 flex items-center justify-center">
                    <?php echo stridence_icon('book-open', 'w-6 h-6 text-primary'); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Title + badge + meta -->
        <div class="flex-1 min-w-0">
            <h4 class="font-bold text-text text-lg leading-snug">
                <?php echo esc_html($courseTitle); ?>
            </h4>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1.5 text-[13px] text-text-muted">
                <?php if ($statusPill) : ?>
                    <span class="text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1 <?php echo esc_attr($pillClass); ?>">
                        <?php echo esc_html($statusPill['label']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($editionCount !== null && $editionCount > 0) : ?>
                    <span>
                        <?php echo esc_html(sprintf(
                            _n('%d editie', '%d edities', (int) $editionCount, 'stridence'),
                            (int) $editionCount,
                        )); ?>
                    </span>
                    <?php if ($startDate) : ?>
                        <span aria-hidden="true">·</span>
                        <span><?php echo esc_html(stride_format_date($startDate)); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status label + chevron (right) -->
        <div class="flex items-center gap-3 shrink-0 self-center">
            <?php if ($statusLabel && !empty($statusLabel['text'])) :
                $slClass = $statusLabelToneClasses[$statusLabel['tone'] ?? 'muted'] ?? $statusLabelToneClasses['muted'];
                ?>
                <span class="hidden sm:inline-flex items-center gap-1 text-[13px] font-medium <?php echo esc_attr($slClass); ?>">
                    <?php if (!empty($statusLabel['icon'])) : ?>
                        <?php echo stridence_icon($statusLabel['icon'], 'w-4 h-4'); ?>
                    <?php endif; ?>
                    <?php echo esc_html($statusLabel['text']); ?>
                </span>
            <?php endif; ?>
            <span class="w-9 h-9 rounded-full bg-surface-alt flex items-center justify-center text-text-muted transition-transform duration-200"
                  :class="{ 'rotate-180': open }">
                <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
            </span>
        </div>
    </button>
    <?php else : ?>
    <button type="button"
            class="w-full p-4 flex items-center gap-4 text-left cursor-pointer"
            @click="toggle()">
        <!-- Thumbnail / imminence badge -->
        <?php if ($imminence === 'today' || $imminence === 'tomorrow') : ?>
            <span class="w-14 h-14 rounded bg-primary/10 flex items-center justify-center shrink-0">
                <span class="text-xs font-bold text-primary leading-none uppercase">
                    <?php echo $imminence === 'today' ? esc_html__('Vandaag', 'stridence') : esc_html__('Morgen', 'stridence'); ?>
                </span>
            </span>
        <?php else : ?>
            <div class="w-14 h-14 rounded overflow-hidden flex-shrink-0">
                <?php if ($thumbnailId) : ?>
                    <?php echo wp_get_attachment_image($thumbnailId, 'thumbnail', false, ['class' => 'w-full h-full object-cover']); ?>
                <?php else : ?>
                    <div class="w-full h-full bg-surface-alt flex items-center justify-center">
                        <?php echo stridence_icon('book-open', 'w-6 h-6 text-text-muted'); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Title + meta line -->
        <div class="flex-1 min-w-0">
            <h4 class="font-bold text-text truncate">
                <?php echo esc_html($courseTitle); ?>
            </h4>
            <div class="flex flex-wrap gap-3 mt-1 text-[13px] text-text-muted">
                <?php if ($statusPill) : ?>
                    <span class="text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1 <?php echo esc_attr($pillClass); ?>">
                        <?php echo esc_html($statusPill['label']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($startDate) : ?>
                    <span class="flex items-center gap-1">
                        <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                        <strong class="text-text font-semibold"><?php echo esc_html(stride_format_date($startDate)); ?></strong>
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
                    $pendingTasksCount,
                ));
            ?>"></span>
        <?php endif; ?>

        <!-- Chevron -->
        <span class="shrink-0 text-text-muted transition-transform duration-200"
              :class="{ 'rotate-180': open }">
            <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
        </span>
    </button>
    <?php endif; ?>

    <!-- Expanded body -->
    <div x-show="open" x-collapse class="border-t border-border">
        <div class="<?php echo $isOverview ? 'p-5' : 'p-4'; ?> space-y-4">
            <?php if ($excerpt) : ?>
                <?php if ($isOverview) : ?>
                    <p class="text-sm text-text-muted leading-relaxed">
                        <?php echo esc_html($excerpt); ?>
                        <?php if ($courseUrl) : ?>
                            <a href="<?php echo esc_url($courseUrl); ?>"
                               class="inline text-primary font-semibold hover:underline whitespace-nowrap">
                                <?php esc_html_e('Bekijk de volledige cursus', 'stridence'); ?> →
                            </a>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="text-sm text-text-muted">
                        <?php echo esc_html($excerpt); ?>
                    </p>
                <?php endif; ?>
            <?php elseif ($isOverview && $courseUrl) : ?>
                <p class="text-sm">
                    <a href="<?php echo esc_url($courseUrl); ?>"
                       class="text-primary font-semibold hover:underline">
                        <?php esc_html_e('Bekijk de volledige cursus', 'stridence'); ?> →
                    </a>
                </p>
            <?php endif; ?>

            <?php // Progress bar: online (LD %) only. Edition attendance is shown
                  // per-session via the attendance badge in the session list below.
                  if ($progressPct !== null && $type === 'online') : ?>
                <div>
                    <div class="flex items-center justify-between text-[12px] font-bold text-text-muted mb-1.5">
                        <?php if ($progressLabel) : ?>
                            <span><?php echo esc_html($progressLabel); ?></span>
                        <?php else : ?>
                            <span><?php esc_html_e('Voortgang', 'stridence'); ?></span>
                        <?php endif; ?>
                        <span class="tabular-nums"><?php echo (int) $progressPct; ?>%</span>
                    </div>
                    <div class="h-[7px] rounded-full bg-surface-alt overflow-hidden">
                        <div class="h-full bg-primary rounded-full" style="width: <?php echo (int) $progressPct; ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($sessions)) :
                // Attendance badge config — mirrors partials/session-row.php so the
                // dashboard's inline session list matches the edition-page idiom.
                $attendanceConfig = [
                    'present' => ['icon' => 'check-circle', 'class' => 'text-success',    'label' => __('Aanwezig', 'stridence')],
                    'absent'  => ['icon' => 'x-circle',     'class' => 'text-error',      'label' => __('Afwezig', 'stridence')],
                    'excused' => ['icon' => 'clock',        'class' => 'text-text-muted', 'label' => __('Gewettigd afwezig', 'stridence')],
                ];
                ?>
                <div class="space-y-2">
                    <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                        <?php esc_html_e('Sessies', 'stridence'); ?>
                    </p>
                    <div class="divide-y divide-border rounded-[12px] border border-border">
                        <?php foreach ($sessions as $s) :
                            $sDate       = $s['date'] ?? '';
                            $sStart      = $s['start_time'] ?? '';
                            $sEnd        = $s['end_time'] ?? '';
                            $sAttendance = $s['attendance'] ?? null;
                            $att         = $sAttendance ? ($attendanceConfig[$sAttendance] ?? null) : null;
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
                                <?php if ($att) : ?>
                                    <span class="ml-auto inline-flex items-center gap-1 <?php echo esc_attr($att['class']); ?>" title="<?php echo esc_attr($att['label']); ?>">
                                        <?php echo stridence_icon($att['icon'], 'w-4 h-4'); ?>
                                        <span class="text-xs font-medium"><?php echo esc_html($att['label']); ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($upcomingEditions) && $isOverview) : ?>
                <!-- Read-only edition preview. No enrol CTA: the learner enrols
                     in the trajectory; edition choice is a gated post-form task. -->
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-text-muted uppercase tracking-wider">
                        <?php esc_html_e('Beschikbare edities', 'stridence'); ?>
                    </p>
                    <div class="space-y-3">
                        <?php foreach ($upcomingEditions as $ed) :
                            $edStartTs = !empty($ed['start_date']) ? strtotime((string) $ed['start_date']) : 0;
                            $timeRange = '';
                            if (!empty($ed['start_time'])) {
                                $timeRange = (string) $ed['start_time'];
                                if (!empty($ed['end_time'])) {
                                    $timeRange .= ' – ' . (string) $ed['end_time'];
                                }
                            }
                            ?>
                            <div class="flex items-center gap-4 p-3 rounded-xl border border-border bg-surface-alt/50">
                                <!-- Date block -->
                                <?php if ($edStartTs) : ?>
                                    <div class="shrink-0 w-14 text-center rounded-lg bg-surface-card border border-border py-1.5">
                                        <div class="text-xl font-bold text-text leading-none">
                                            <?php echo esc_html(date_i18n('j', $edStartTs)); ?>
                                        </div>
                                        <div class="text-[10px] uppercase text-text-muted tracking-wide mt-0.5">
                                            <?php echo esc_html(date_i18n('M', $edStartTs)); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Day · date · time + venue -->
                                <div class="flex-1 min-w-0">
                                    <?php if ($edStartTs) : ?>
                                        <div class="text-sm font-semibold text-text">
                                            <?php
                                            echo esc_html(stride_format_date((string) $ed['start_date'], 'l j F Y'));
                                        if ($timeRange) {
                                            echo ' · ' . esc_html($timeRange);
                                        }
                                        ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($ed['venue'])) : ?>
                                        <div class="text-sm text-text-muted truncate">
                                            <?php echo esc_html($ed['venue']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Places remaining (informational) -->
                                <?php if ($ed['places_remaining'] !== null) : ?>
                                    <div class="shrink-0 text-sm font-medium text-text-muted">
                                        <?php echo esc_html(sprintf(
                                            _n('Nog %d plaats', 'Nog %d plaatsen', (int) $ed['places_remaining'], 'stridence'),
                                            (int) $ed['places_remaining'],
                                        )); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif (!empty($upcomingEditions)) : ?>
                <div class="space-y-2">
                    <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                        <?php esc_html_e('Beschikbare edities', 'stridence'); ?>
                    </p>
                    <div class="divide-y divide-border rounded-[12px] border border-border">
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
                            $tsTotal,
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
