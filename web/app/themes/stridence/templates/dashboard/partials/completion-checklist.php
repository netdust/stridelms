<?php
/**
 * Enrollment completion checklist partial.
 *
 * Shows pending tasks with progress bar for registrations that need completion.
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
$completed    = $summary['completed'] ?? 0;

if (empty($tasks) || $total === 0) {
    return;
}

$percentage = (int) round(($completed / $total) * 100);

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

<div class="space-y-3">
    <span class="text-sm font-medium text-text">
        <?= $has_post_course
            ? esc_html__('Opleiding afronden:', 'stridence')
            : esc_html__('Inschrijving voltooien:', 'stridence') ?>
    </span>

    <ul class="space-y-1.5 pl-6">
        <?php foreach ($tasks as $type => $task): ?>
            <?php
            $state  = $availability[$type]['state'] ?? 'available';
            $reason = $availability[$type]['reason'] ?? '';
            ?>
            <li class="flex items-center gap-2 text-sm">
                <?php if ($state === 'completed'): ?>
                    <?= stridence_icon('check', 'w-4 h-4 text-status-success') ?>
                    <span class="text-text-muted line-through"><?= esc_html($task_labels[$type] ?? $type) ?></span>
                <?php elseif ($state === 'locked'): ?>
                    <?= stridence_icon('info', 'w-4 h-4 text-text-muted') ?>
                    <span class="text-text-muted">
                        <?= esc_html($task_labels[$type] ?? $type) ?>
                        <?php if ($reason): ?>
                            <span class="text-xs">(<?= esc_html(mb_strtolower(rtrim($reason, '.'))) ?>)</span>
                        <?php endif; ?>
                    </span>
                <?php else: ?>
                    <span class="w-4 h-4 rounded-full border-2 border-border inline-block shrink-0"></span>
                    <span><?= esc_html($task_labels[$type] ?? $type) ?></span>
                    <a href="<?= esc_url($complete_url) ?>"
                       class="text-primary text-xs hover:underline ml-auto">
                        <?= esc_html($task_actions[$type] ?? __('Invullen', 'stridence')) ?> &rarr;
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Progress bar -->
    <div class="pt-2">
        <div class="h-2 bg-surface-alt rounded-full overflow-hidden">
            <div class="h-full bg-primary rounded-full transition-all"
                 style="width: <?= esc_attr((string) $percentage) ?>%"></div>
        </div>
        <p class="text-xs text-text-muted mt-1">
            <?= esc_html(sprintf('%d%% voltooid', $percentage)) ?>
        </p>
    </div>
</div>
