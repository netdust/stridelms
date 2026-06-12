<?php
/**
 * Enrollment Panel Partial — Helder Tij home-tab grid card.
 *
 * Server-rendered white panel per active enrollment, used in the dashboard
 * home "Opleidingen" grid. Two variants from the existing enrollment shape
 * (UserDashboardService::getHomeData()['active_enrollments']):
 *
 *  - edition: badges row, title, next-session block, completion-checklist
 *             well (existing task_summary/complete_url args), CTA only when
 *             no open checklist rows remain (e.g. "Sessiekeuze wijzigen").
 *  - online:  badges row, title, progress label row + bar (existing
 *             progress-bar partial), "Ga verder"/"Start cursus" CTA.
 *
 * Replaces the previous Alpine slide-in quick-view, which had no remaining
 * openers after the home redesign.
 *
 * @param array $args {
 *     @type array $enrollment One item from getHomeData()['active_enrollments']
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$enrollment = $args['enrollment'] ?? [];
if (empty($enrollment)) {
    return;
}

$type  = $enrollment['type'] ?? 'edition';
$title = (string) ($enrollment['course_title'] ?? '');
?>

<div class="bg-surface-card rounded-[16px] shadow-card p-6 flex flex-col gap-[14px]">

    <?php if ($type === 'online') : ?>
        <?php $progressPct = min(100, (int) ($enrollment['progress'] ?? 0)); ?>

        <!-- Badges -->
        <div class="flex flex-wrap gap-1.5">
            <?php stridence_template_part('partials/badge-status', null, ['status' => 'online', 'size' => 'sm']); ?>
            <?php if ($progressPct > 0) : ?>
                <?php stridence_template_part('partials/badge-status', null, ['status' => 'enrolled', 'size' => 'sm']); ?>
            <?php endif; ?>
        </div>

        <!-- Title -->
        <div class="text-[16px] font-bold text-text leading-snug">
            <?php echo esc_html($title); ?>
        </div>

        <!-- Progress -->
        <?php if ($progressPct > 0) : ?>
            <div>
                <div class="flex justify-between text-[12px] font-bold text-text-muted">
                    <span><?php echo esc_html(sprintf(__('%d%% voltooid', 'stridence'), $progressPct)); ?></span>
                    <?php if (($enrollment['total_lessons'] ?? 0) > 0) : ?>
                        <span class="tabular-nums"><?php echo esc_html(sprintf(
                            __('%d van %d lessen', 'stridence'),
                            (int) ($enrollment['completed_lessons'] ?? 0),
                            (int) $enrollment['total_lessons'],
                        )); ?></span>
                    <?php endif; ?>
                </div>
                <?php stridence_template_part('templates/dashboard/partials/progress-bar', null, ['percentage' => $progressPct]); ?>
            </div>
        <?php endif; ?>

        <!-- CTA -->
        <?php if (!empty($enrollment['course_url'])) : ?>
            <a href="<?php echo esc_url($enrollment['course_url']); ?>"
               class="btn-primary w-full justify-center mt-auto">
                <?php echo $progressPct > 0
                    ? esc_html__('Ga verder', 'stridence')
                    : esc_html__('Start cursus', 'stridence'); ?>
            </a>
        <?php endif; ?>

    <?php else : ?>
        <?php
        $next      = $enrollment['next_session'] ?? null;
        $startDate = $enrollment['start_date'] ?? '';
        $venue     = $enrollment['venue'] ?? '';

        $taskSummary = $enrollment['task_summary'] ?? null;
        $pendingTasks = 0;
        if ($taskSummary) {
            $pendingTasks = max(0, (int) ($taskSummary['total'] ?? 0) - (int) ($taskSummary['completed'] ?? 0));
        }
        $cta = $enrollment['cta'] ?? null;
        ?>

        <!-- Badges -->
        <div class="flex flex-wrap gap-1.5">
            <span class="text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1 bg-badge-online-bg text-badge-online-text">
                <?php esc_html_e('Klassikaal', 'stridence'); ?>
            </span>
            <?php stridence_template_part('partials/badge-status', null, ['status' => 'enrolled', 'size' => 'sm']); ?>
        </div>

        <!-- Title -->
        <div class="text-[16px] font-bold text-text leading-snug">
            <?php echo esc_html($title); ?>
        </div>

        <!-- Next session / start date -->
        <?php if ($next && !empty($next['date'])) : ?>
            <div class="text-[13px] text-text-muted">
                <strong class="text-text font-semibold"><?php esc_html_e('Volgende sessie:', 'stridence'); ?></strong>
                <strong class="text-text font-semibold"><?php echo esc_html(stride_format_date($next['date'])); ?></strong>
                <?php if (!empty($next['start_time'])) : ?>
                    · <?php
                        echo esc_html($next['start_time']);
                    if (!empty($next['end_time'])) {
                        echo ' – ' . esc_html($next['end_time']);
                    }
                    ?>
                <?php endif; ?>
                <?php if ($venue) : ?>
                    <br><?php echo esc_html($venue); ?>
                <?php endif; ?>
            </div>
        <?php elseif ($startDate) : ?>
            <div class="text-[13px] text-text-muted">
                <strong class="text-text font-semibold"><?php echo esc_html(stride_format_date($startDate)); ?></strong>
                <?php if ($venue) : ?>
                    <br><?php echo esc_html($venue); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Completion checklist well -->
        <?php if ($taskSummary) : ?>
            <?php stridence_template_part('templates/dashboard/partials/completion-checklist', null, [
                'task_summary' => $taskSummary,
                'complete_url' => $enrollment['complete_url'] ?? '#',
            ]); ?>
        <?php endif; ?>

        <!-- CTA — only when the checklist has no open rows carrying links
             (e.g. all tasks done but session selection is still editable) -->
        <?php if ($cta && $pendingTasks === 0) : ?>
            <a href="<?php echo esc_url($cta['url']); ?>" class="btn-primary btn-sm self-start mt-auto">
                <?php echo esc_html($cta['label']); ?>
            </a>
        <?php endif; ?>

    <?php endif; ?>
</div>
