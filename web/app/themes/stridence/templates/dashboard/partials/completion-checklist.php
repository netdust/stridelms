<?php
/**
 * Enrollment completion checklist partial.
 *
 * Shows pending tasks with progress bar for registrations that need completion.
 *
 * @var array $args {
 *     @type array  $task_summary  From EnrollmentCompletionService::getTaskSummary()
 *     @type string $complete_url  URL to the completion page
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$summary      = $args['task_summary'] ?? [];
$complete_url = $args['complete_url'] ?? '#';
$tasks        = $summary['tasks'] ?? [];
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
];

$task_actions = [
    'session_selection' => __('Kiezen', 'stridence'),
    'questionnaire'     => __('Invullen', 'stridence'),
    'documents'         => __('Uploaden', 'stridence'),
];
?>

<div class="mt-4 pt-4 border-t border-border">
    <div class="flex items-center gap-2 mb-3">
        <?= stridence_icon('alert-circle', 'w-4 h-4 text-amber-500') ?>
        <span class="text-sm font-medium text-text">
            <?= esc_html__('Inschrijving voltooien:', 'stridence') ?>
        </span>
    </div>

    <ul class="space-y-2 mb-4">
        <?php foreach ($tasks as $type => $task): ?>
            <?php
            $isDone = ($task['status'] ?? 'pending') === 'completed';
            $isApproval = $type === 'approval';
            $userTasksDone = $summary['ready_for_approval'] ?? false;
            ?>
            <li class="flex items-center gap-2 text-sm">
                <?php if ($isDone): ?>
                    <?= stridence_icon('check', 'w-4 h-4 text-emerald-500') ?>
                    <span class="text-text-muted line-through"><?= esc_html($task_labels[$type] ?? $type) ?></span>
                <?php elseif ($isApproval): ?>
                    <?= stridence_icon('info', 'w-4 h-4 text-text-muted') ?>
                    <span class="text-text-muted">
                        <?= esc_html($task_labels[$type]) ?>
                        <?php if (!$userTasksDone): ?>
                            <span class="text-xs">(<?= esc_html__('wacht op taken', 'stridence') ?>)</span>
                        <?php else: ?>
                            <span class="text-xs text-amber-600">(<?= esc_html__('wacht op beheerder', 'stridence') ?>)</span>
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
    <div class="h-2 bg-surface-alt rounded-full overflow-hidden">
        <div class="h-full bg-primary rounded-full transition-all"
             style="width: <?= esc_attr((string) $percentage) ?>%"></div>
    </div>
    <p class="text-xs text-text-muted mt-1">
        <?= esc_html(sprintf('%d%% voltooid', $percentage)) ?>
    </p>
</div>
