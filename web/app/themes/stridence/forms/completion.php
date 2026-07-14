<?php
/**
 * Enrollment Completion Page (router-rendered).
 *
 * Shows pending tasks as collapsible cards for users to complete.
 * Rendered by EnrollmentRouter via ntdst_response()->render().
 *
 * Available variables (from ntdst_response()->with()):
 * @var WP_Post $post           The edition or trajectory post
 * @var string  $type           'edition' or 'trajectory'
 * @var object  $registration   Registration row from DB
 * @var array   $task_summary   From EnrollmentCompletion::getTaskSummary()
 * @var string  $phase          'enrollment' or 'post_course'
 * @var bool    $is_enroller    Viewer is the enrolled_by actor, not the participant
 * @var string  $participant_name  Participant display name (enroller view only)
 */

defined('ABSPATH') || exit;

get_header();

$post_id       = $post->ID ?? 0;
$reg_id        = (int) ($registration->id ?? 0);
$tasks         = $task_summary['tasks'] ?? [];
$availability  = $task_summary['availability'] ?? [];
$active_phase  = $phase ?? 'enrollment';
$is_enroller   = !empty($is_enroller);
$participant_name = (string) ($participant_name ?? '');

// Strictly personal tasks (form-identity rule 4): the enroller sees them but
// cannot act — the server denies them too (CompletionTaskHandler allow-list).
$personal_tasks = ['questionnaire', 'post_evaluation'];

// Filter tasks to active phase only
$phase_tasks = array_filter($tasks, fn($t) => ($t['phase'] ?? 'enrollment') === $active_phase);
$phase_availability = array_intersect_key($availability, $phase_tasks);

$total         = count($phase_tasks);
$completed     = count(array_filter($phase_tasks, fn($t) => ($t['status'] ?? 'pending') === 'completed'));
$percentage    = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

$task_labels = [
    'session_selection' => __('Sessies kiezen', 'stridence'),
    'questionnaire'     => __('Vragenlijst invullen', 'stridence'),
    'documents'         => __('Documenten uploaden', 'stridence'),
    'approval'          => __('Goedkeuring beheerder', 'stridence'),
    'post_evaluation'   => __('Evaluatie invullen', 'stridence'),
    'post_documents'    => __('Documenten uploaden', 'stridence'),
    'post_approval'     => __('Goedkeuring beheerder', 'stridence'),
];

$task_descriptions = [
    'session_selection' => __('Kies de sessies waar je aan wilt deelnemen.', 'stridence'),
    'questionnaire'     => __('Vul de vereiste vragenlijst in.', 'stridence'),
    'documents'         => __('Upload de gevraagde documenten.', 'stridence'),
    'approval'          => __('Een beheerder zal je inschrijving beoordelen.', 'stridence'),
    'post_evaluation'   => __('Vul de evaluatie in over de opleiding.', 'stridence'),
    'post_documents'    => __('Upload de gevraagde documenten.', 'stridence'),
    'post_approval'     => __('Een beheerder zal je dossier beoordelen.', 'stridence'),
];

// Overlay admin-supplied instructions (from EnrollmentCompletion::getTaskSummary()).
// Admin instruction wins for document tasks; generic strings stay for every other
// task type and as the last-resort fallback. Read ONLY $task_summary['descriptions'] —
// never a stride-core repository or helper (mu-plugin-no-theme-calls invariant).
$admin_descriptions = $task_summary['descriptions'] ?? [];
$task_descriptions  = array_merge($task_descriptions, array_filter($admin_descriptions, 'strlen'));

// Map post-course task types to existing templates
$template_map = [
    'post_evaluation' => 'task-questionnaire',
    'post_documents'  => 'task-documents',
    'post_approval'   => 'task-approval',
];

$is_post_course = ($active_phase === 'post_course');
?>

<main class="max-w-2xl mx-auto px-4 py-12" x-data="completionPage(<?= esc_attr(wp_json_encode([
    'registrationId' => $reg_id,
    'tasks' => $phase_tasks,
])) ?>)">

    <!-- Back link -->
    <a href="<?= esc_url(get_permalink($post_id)) ?>"
       class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-text mb-6">
        <?= stridence_icon('arrow-left', 'w-4 h-4') ?>
        <?= esc_html($post->post_title) ?>
    </a>

    <!-- Header -->
    <div class="mb-8">
        <h1 class="font-heading text-2xl font-bold text-text mb-2">
            <?= $is_post_course
                ? esc_html__('Opleiding afronden', 'stridence')
                : esc_html__('Inschrijving voltooien', 'stridence') ?>
        </h1>
        <p class="text-text-muted">
            <?php if ($is_enroller && $participant_name !== ''): ?>
                <?= esc_html(sprintf(
                    /* translators: %s: participant display name */
                    __('Je voltooit deze stappen voor %s.', 'stridence'),
                    $participant_name,
                )) ?>
            <?php else: ?>
                <?= $is_post_course
                    ? esc_html__('Voltooi onderstaande stappen om je opleiding af te ronden.', 'stridence')
                    : esc_html__('Voltooi onderstaande stappen om je inschrijving te bevestigen.', 'stridence') ?>
            <?php endif; ?>
        </p>

        <!-- Progress bar -->
        <div class="mt-4">
            <div class="flex justify-between text-xs text-text-muted mb-1">
                <span x-text="progressLabel"><?= esc_html(sprintf('%d van %d voltooid', $completed, $total)) ?></span>
                <span x-text="progressPercent + '%'"><?= esc_html($percentage) ?>%</span>
            </div>
            <div class="h-2 bg-surface-alt rounded-full overflow-hidden">
                <div class="h-full bg-primary rounded-full transition-all duration-500"
                     :style="{ width: progressPercent + '%' }"></div>
            </div>
        </div>
    </div>

    <!-- Task cards -->
    <div class="space-y-4">
        <?php foreach ($phase_tasks as $taskType => $task): ?>
            <?php
            $state   = $phase_availability[$taskType]['state'] ?? 'available';
            $reason  = $phase_availability[$taskType]['reason'] ?? '';
            $overdue = $phase_availability[$taskType]['overdue'] ?? false;
            $isLocked    = $state === 'locked';
            $isCompleted = $state === 'completed';
            $isAvailable = $state === 'available';
            ?>
            <div class="card overflow-hidden <?= $isLocked ? 'opacity-60' : '' ?>"
                 x-data="{ open: <?= $isAvailable && !$isCompleted ? 'true' : 'false' ?> }">
                <!-- Card header -->
                <button type="button"
                        class="w-full p-4 flex items-center gap-3 text-left"
                        @click="open = !open"
                        <?php if ($isLocked): ?>disabled<?php endif; ?>>
                    <?php if ($isCompleted): ?>
                        <span class="w-8 h-8 rounded-full bg-status-success-subtle flex items-center justify-center shrink-0">
                            <?= stridence_icon('check', 'w-4 h-4 text-status-success') ?>
                        </span>
                    <?php elseif ($isLocked): ?>
                        <span class="w-8 h-8 rounded-full bg-surface-container flex items-center justify-center shrink-0">
                            <?= stridence_icon('info', 'w-4 h-4 text-text-muted') ?>
                        </span>
                    <?php else: ?>
                        <span class="w-8 h-8 rounded-full border-2 border-primary flex items-center justify-center shrink-0">
                            <span class="w-2 h-2 rounded-full bg-primary"></span>
                        </span>
                    <?php endif; ?>

                    <div class="flex-1 min-w-0">
                        <span class="font-medium text-text <?= $isCompleted ? 'line-through text-text-muted' : '' ?>">
                            <?= esc_html($task_labels[$taskType] ?? $taskType) ?>
                        </span>
                        <?php if ($isLocked && $reason): ?>
                            <span class="text-xs text-text-muted ml-2">
                                <?= esc_html($reason) ?>
                            </span>
                        <?php elseif ($isAvailable && $reason): ?>
                            <span class="text-xs <?= $overdue ? 'text-status-error font-semibold' : 'text-status-warning' ?> ml-2">
                                <?= esc_html($reason) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$isLocked): ?>
                        <span class="shrink-0 text-text-muted transition-transform duration-200"
                              :class="{ 'rotate-180': open }">
                            <?= stridence_icon('chevron-down', 'w-5 h-5') ?>
                        </span>
                    <?php endif; ?>
                </button>

                <!-- Card body -->
                <?php if (!$isLocked): ?>
                    <div x-show="open" x-collapse class="border-t border-border">
                        <div class="p-4">
                            <?php if ($isCompleted): ?>
                                <p class="text-sm text-status-success">
                                    <?= stridence_icon('check', 'w-4 h-4 inline-block mr-1') ?>
                                    <?= esc_html__('Voltooid', 'stridence') ?>
                                    <?php if (!empty($task['completed_at'])): ?>
                                        — <?= esc_html(stride_format_date($task['completed_at'])) ?>
                                    <?php endif; ?>
                                </p>
                            <?php elseif ($is_enroller && in_array($taskType, $personal_tasks, true)): ?>
                                <div class="flex items-center gap-3 p-3 rounded-lg bg-surface-alt/50">
                                    <?= stridence_icon('info', 'w-5 h-5 text-text-muted shrink-0') ?>
                                    <p class="text-sm text-text-muted">
                                        <?= esc_html(sprintf(
                                            /* translators: %s: participant display name */
                                            __('Deze stap is persoonlijk — %s vult dit zelf in.', 'stridence'),
                                            $participant_name !== '' ? $participant_name : __('de deelnemer', 'stridence'),
                                        )) ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-text-muted mb-4">
                                    <?= esc_html($task_descriptions[$taskType] ?? '') ?>
                                </p>
                                <?php
                                $template = $template_map[$taskType] ?? ('task-' . $taskType);
                                stridence_template_part('templates/forms/completion/' . $template, null, [
                                    'registration' => $registration,
                                    'task'         => $task,
                                    'task_type'    => $taskType,
                                    'post'         => $post,
                                ]);
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Dashboard link -->
    <div class="mt-8 text-center">
        <a href="<?= esc_url(home_url('/mijn-account/')) ?>"
           class="text-sm text-text-muted hover:text-text">
            &larr; <?= esc_html__('Terug naar dashboard', 'stridence') ?>
        </a>
    </div>
</main>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('completionPage', (config) => ({
        registrationId: config.registrationId,
        tasks: config.tasks,
        loading: false,
        error: '',

        get completedCount() {
            return Object.values(this.tasks).filter(t => t.status === 'completed').length;
        },
        get totalCount() {
            return Object.keys(this.tasks).length;
        },
        get progressPercent() {
            return this.totalCount > 0 ? Math.round((this.completedCount / this.totalCount) * 100) : 0;
        },
        get progressLabel() {
            return `${this.completedCount} van ${this.totalCount} voltooid`;
        },

        async completeTask(taskType, data = {}) {
            this.loading = true;
            this.error = '';

            try {
                const result = await ntdstAPI.call('stride_complete_task', {
                    registration_id: this.registrationId,
                    task_type: taskType,
                    task_data: data,
                });

                if (result.completed) {
                    this.tasks[taskType] = { status: 'completed', completed_at: new Date().toISOString() };

                    if (this.completedCount === this.totalCount) {
                        window.location.href = '<?= esc_url(home_url('/mijn-account/?tab=inschrijvingen')) ?>';
                    } else {
                        // Reload to refresh server-rendered task availability (e.g. unlock approval)
                        window.location.reload();
                    }
                } else {
                    this.error = result.data?.message || 'Er ging iets mis.';
                }
            } catch (e) {
                this.error = 'Verbindingsfout. Probeer opnieuw.';
            } finally {
                this.loading = false;
            }
        }
    }));
});
</script>

<?php get_footer(); ?>
