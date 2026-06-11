<?php
/**
 * Enrollment completion checklist partial — Helder Tij well.
 *
 * Soft-surface well listing pending tasks for registrations that need
 * completion: ✓ rows for done tasks, locked rows with reason, hollow-circle
 * open rows with an "Invullen →" link to the completion page. Task labels,
 * availability states and links unchanged.
 *
 * @var array $args {
 *     @type array  $task_summary  From EnrollmentCompletion::getTaskSummary()
 *     @type string $complete_url  URL to the completion page
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$summary      = $args['task_summary'] ?? [];
$complete_url = $args['complete_url'] ?? '#';
$tasks        = $summary['tasks'] ?? [];
$availability = $summary['availability'] ?? [];
$total        = $summary['total'] ?? 0;

if (empty($tasks) || $total === 0) {
    return;
}

$task_labels = [
    'session_selection' => __('Sessies kiezen', 'stridence'),
    'questionnaire'     => __('Vragenlijst invullen', 'stridence'),
    'documents'         => __('Documenten uploaden', 'stridence'),
    'approval'          => __('Goedkeuring beheerder', 'stridence'),
    'post_evaluation'   => __('Evaluatie invullen', 'stridence'),
    'post_documents'    => __('Documenten uploaden', 'stridence'),
    'post_approval'     => __('Goedkeuring beheerder', 'stridence'),
];

$task_actions = [
    'session_selection' => __('Kiezen', 'stridence'),
    'questionnaire'     => __('Invullen', 'stridence'),
    'documents'         => __('Uploaden', 'stridence'),
    'post_evaluation'   => __('Invullen', 'stridence'),
    'post_documents'    => __('Uploaden', 'stridence'),
];

// Determine phase from tasks
$has_post_course = false;
foreach ($tasks as $task) {
    if (($task['phase'] ?? 'enrollment') === 'post_course') {
        $has_post_course = true;
        break;
    }
}
?>

<div class="bg-surface rounded-[12px] p-4 flex flex-col gap-2">
    <span class="text-[13px] font-bold text-text">
        <?= $has_post_course
            ? esc_html__('Opleiding afronden:', 'stridence')
            : esc_html__('Inschrijving voltooien:', 'stridence') ?>
    </span>

    <?php foreach ($tasks as $type => $task): ?>
        <?php
        $state  = $availability[$type]['state'] ?? 'available';
        $reason = $availability[$type]['reason'] ?? '';
        ?>
        <div class="flex items-center gap-2.5">
            <?php if ($state === 'completed'): ?>
                <span class="w-5 h-5 rounded-full bg-badge-open-bg grid place-items-center shrink-0">
                    <span class="text-[12px] font-extrabold text-badge-open-text leading-none">✓</span>
                </span>
                <span class="text-[13px] text-text-muted"><?= esc_html($task_labels[$type] ?? $type) ?></span>
            <?php elseif ($state === 'locked'): ?>
                <?= stridence_icon('info', 'w-4 h-4 text-text-faint shrink-0') ?>
                <span class="text-[13px] text-text-muted">
                    <?= esc_html($task_labels[$type] ?? $type) ?>
                    <?php if ($reason): ?>
                        <span class="text-[12px]">(<?= esc_html(mb_strtolower(rtrim($reason, '.'))) ?>)</span>
                    <?php endif; ?>
                </span>
            <?php else: ?>
                <span class="w-5 h-5 rounded-full border-[1.5px] border-border bg-surface-card shrink-0"></span>
                <span class="text-[13px] font-semibold text-text flex-1"><?= esc_html($task_labels[$type] ?? $type) ?></span>
                <a href="<?= esc_url($complete_url) ?>"
                   class="text-[12px] font-bold text-primary hover:text-primary-hover shrink-0">
                    <?= esc_html($task_actions[$type] ?? __('Invullen', 'stridence')) ?> &rarr;
                </a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
