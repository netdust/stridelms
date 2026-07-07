<?php
/**
 * Course Card — unified expandable component
 *
 * Renders a collapsible course card. Three modes via $args['type']:
 *   - 'edition'  : enrolled classroom edition (sessions, tasks, CTA)
 *   - 'online'   : enrolled online course (progress bar, resume CTA)
 *   - 'public'   : course in trajectory context — EDITION-first preview
 *                  (excerpt + the one resolved edition's date/venue/session
 *                  programme, deep-linking straight to that edition)
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
$statusLabel       = $meta['status_label'] ?? null;    // overview only
$isOnline          = (bool) ($meta['is_online'] ?? false); // overview only

$excerpt         = $body['excerpt'] ?? null;
$progressPct     = $body['progress_pct'] ?? null;
$sessions        = $body['sessions'] ?? [];
$taskSummary     = $body['task_summary'] ?? null;
$primaryCta      = $body['primary_cta'] ?? null;
$secondaryCta    = $body['secondary_cta'] ?? null;
// Overview (trajectory) only — the single resolved edition this course means,
// its session programme, and the deep-link (edition permalink, or the
// course's edition picker in the rare multi-edition case).
$edition               = $body['edition'] ?? null;
$editionSessions       = $body['edition_sessions'] ?? [];
$hasMultipleEditions   = (bool) ($body['has_multiple_editions'] ?? false);
$detailUrl             = $body['detail_url'] ?? null;

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
    <!-- ── Overview (trajectory) header — EDITION-first ───────────────────
         Icon reflects the COURSE type (book-open for online/e-learning,
         map-pin for in-person/classroom) — same idiom as session-row.php's
         per-type icon. The date lives on the session rows inside, not here
         (a course can have several sessions; one date on the header would
         misrepresent multi-day editions). Expanding shows the excerpt +
         that edition's session programme. No enrol action anywhere on this
         surface — the learner enrols in the trajectory as a whole. -->
    <button type="button"
            class="w-full p-5 flex items-center gap-4 text-left cursor-pointer"
            @click="toggle()">
        <!-- Type icon: 50px square, session-row idiom -->
        <div class="w-[50px] h-[50px] rounded-[11px] bg-badge-online-bg text-badge-online-text flex items-center justify-center shrink-0">
            <?php echo stridence_icon($isOnline ? 'book-open' : 'map-pin', 'w-5 h-5'); ?>
        </div>

        <!-- Title + venue -->
        <div class="flex-1 min-w-0">
            <h4 class="font-bold text-text text-[15px] leading-snug truncate">
                <?php echo esc_html($courseTitle); ?>
            </h4>
            <?php if (!empty($edition['venue'])) : ?>
                <div class="text-[13px] text-text-muted truncate mt-0.5">
                    <?php echo esc_html($edition['venue']); ?>
                </div>
            <?php endif; ?>
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
            <span class="shrink-0 text-text-muted transition-transform duration-200"
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
                        <?php if ($detailUrl) : ?>
                            <a href="<?php echo esc_url($detailUrl); ?>"
                               class="inline text-primary font-semibold hover:underline whitespace-nowrap">
                                <?php echo $hasMultipleEditions
                                    ? esc_html__('Bekijk alle edities', 'stridence')
                                    : esc_html__('Bekijk de volledige editie', 'stridence'); ?> →
                            </a>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="text-sm text-text-muted">
                        <?php echo esc_html($excerpt); ?>
                    </p>
                <?php endif; ?>
            <?php elseif ($isOverview && $detailUrl) : ?>
                <p class="text-sm">
                    <a href="<?php echo esc_url($detailUrl); ?>"
                       class="text-primary font-semibold hover:underline">
                        <?php echo $hasMultipleEditions
                            ? esc_html__('Bekijk alle edities', 'stridence')
                            : esc_html__('Bekijk de volledige editie', 'stridence'); ?> →
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

            <?php if ($isOverview && !empty($editionSessions)) : ?>
                <!-- Session programme — same idiom as klassikaal's edition page
                     (partials/session-row.php), mini panel version. Read-only:
                     no enrol CTA here, the learner enrols in the trajectory. -->
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-text-muted uppercase tracking-wider">
                        <?php esc_html_e('Programma', 'stridence'); ?>
                    </p>
                    <div class="space-y-2">
                        <?php foreach ($editionSessions as $s) :
                            stridence_template_part('partials/session-row', null, [
                                'session' => (object) $s,
                            ]);
                        endforeach; ?>
                    </div>
                </div>
            <?php elseif ($isOverview) : ?>
                <p class="text-sm text-text-muted italic">
                    <?php esc_html_e('Nog geen data gepland', 'stridence'); ?>
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
