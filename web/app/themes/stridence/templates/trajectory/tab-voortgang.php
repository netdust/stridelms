<?php
/**
 * Trajectory Tab: Voortgang (Progress)
 *
 * Shows overall progress bar and course completion status.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\TrajectoryMode;
use stridence\services\frontend\TrajectoryDashboardService;

$trajectory = $args['trajectory'];
$enrollment = $args['enrollment'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

// Get progress data
$progress = $dashboardService->getProgressData($user->ID, $trajectory->ID);
$lmsAdapter = ntdst_get(LMSAdapterInterface::class);

$completedCount = $progress['completed_count'];
$totalRequired = $progress['total_required'];
$progressPercent = $totalRequired > 0 ? round(($completedCount / $totalRequired) * 100) : 0;
?>

<div class="space-y-8">
    <!-- Progress Overview Card -->
    <div class="card p-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-lg font-semibold text-text">
                    <?php esc_html_e('Voortgang', 'stridence'); ?>
                </h2>
                <p class="text-sm text-text-muted">
                    <?php
                    printf(
                        esc_html__('%d van %d cursussen afgerond', 'stridence'),
                        $completedCount,
                        $totalRequired
                    );
                    ?>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?php echo $progress['mode'] === TrajectoryMode::Cohort ? 'bg-primary/10 text-primary' : 'bg-accent/10 text-accent'; ?>">
                    <?php echo $progress['mode'] === TrajectoryMode::Cohort
                        ? esc_html__('Cohort', 'stridence')
                        : esc_html__('Zelfgestuurd', 'stridence'); ?>
                </span>
            </div>
        </div>

        <!-- Progress Bar -->
        <?php
        get_template_part('partials/progress-bar', null, [
            'attended' => $completedCount,
            'required' => $totalRequired,
            'label' => __('Totale voortgang', 'stridence'),
        ]);
        ?>
    </div>

    <!-- Required Courses -->
    <?php if (!empty($progress['required_courses'])) : ?>
        <section>
            <h3 class="text-sm font-medium text-text-muted uppercase tracking-wide mb-3">
                <?php esc_html_e('Verplichte cursussen', 'stridence'); ?>
            </h3>
            <div class="card divide-y divide-border">
                <?php foreach ($progress['required_courses'] as $course) :
                    $isComplete = $lmsAdapter->isComplete($user->ID, $course->ID);
                    $isInProgress = in_array($course->ID, $progress['in_progress_courses'], true);
                ?>
                    <div class="p-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <?php if ($isComplete) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-success/10 flex items-center justify-center">
                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center">
                                    <?php echo stridence_icon('clock', 'w-4 h-4 text-accent'); ?>
                                </span>
                            <?php else : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-border flex items-center justify-center">
                                    <span class="w-2 h-2 rounded-full bg-text-muted"></span>
                                </span>
                            <?php endif; ?>

                            <div class="min-w-0">
                                <h4 class="font-medium truncate <?php echo $isComplete ? 'text-text-muted line-through' : 'text-text'; ?>">
                                    <?php echo esc_html($course->post_title); ?>
                                </h4>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if ($isComplete) : ?>
                                <span class="text-xs text-success font-medium">
                                    <?php esc_html_e('Afgerond', 'stridence'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <a href="<?php echo esc_url(get_permalink($course)); ?>"
                                   class="btn-primary text-xs">
                                    <?php esc_html_e('Verder', 'stridence'); ?>
                                </a>
                            <?php else : ?>
                                <span class="text-xs text-text-muted">
                                    <?php esc_html_e('Nog te starten', 'stridence'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Elective Groups -->
    <?php foreach ($progress['elective_groups'] as $group) :
        $groupName = $group['name'] ?? __('Keuzecursussen', 'stridence');
        $groupRequired = (int) ($group['required'] ?? 0);
        $courses = $group['courses'] ?? [];

        if (empty($courses)) {
            continue;
        }

        // Count completed in this group
        $groupCompleted = 0;
        foreach ($courses as $course) {
            if ($lmsAdapter->isComplete($user->ID, $course->ID)) {
                $groupCompleted++;
            }
        }
    ?>
        <section>
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium text-text-muted uppercase tracking-wide">
                    <?php echo esc_html($groupName); ?>
                </h3>
                <?php if ($groupRequired > 0) : ?>
                    <span class="text-sm font-medium <?php echo $groupCompleted >= $groupRequired ? 'text-success' : 'text-text'; ?>">
                        <?php printf(esc_html__('%d/%d vereist', 'stridence'), $groupCompleted, $groupRequired); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="card divide-y divide-border">
                <?php foreach ($courses as $course) :
                    $isComplete = $lmsAdapter->isComplete($user->ID, $course->ID);
                    $isInProgress = in_array($course->ID, $progress['in_progress_courses'], true);
                ?>
                    <div class="p-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <?php if ($isComplete) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-success/10 flex items-center justify-center">
                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center">
                                    <?php echo stridence_icon('clock', 'w-4 h-4 text-accent'); ?>
                                </span>
                            <?php else : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-border flex items-center justify-center">
                                    <span class="w-2 h-2 rounded-full bg-text-muted"></span>
                                </span>
                            <?php endif; ?>

                            <div class="min-w-0">
                                <h4 class="font-medium truncate <?php echo $isComplete ? 'text-text-muted line-through' : 'text-text'; ?>">
                                    <?php echo esc_html($course->post_title); ?>
                                </h4>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if ($isComplete) : ?>
                                <span class="text-xs text-success font-medium">
                                    <?php esc_html_e('Afgerond', 'stridence'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <a href="<?php echo esc_url(get_permalink($course)); ?>"
                                   class="btn-primary text-xs">
                                    <?php esc_html_e('Verder', 'stridence'); ?>
                                </a>
                            <?php else : ?>
                                <span class="text-xs text-text-muted">
                                    <?php esc_html_e('Keuze', 'stridence'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
