<?php
/**
 * Dashboard Tab: Mijn opleidingen (My Courses)
 *
 * Shows user's classroom edition registrations AND online LearnDash courses.
 * Three main sections: Klassikale opleidingen, Online cursussen, Afgerond (merged).
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\User\UserDashboardService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get all enrollment data from service
$dashboardService = ntdst_get(UserDashboardService::class);
$data = $dashboardService->getEnrollmentData($user_id);

$upcoming_sessions  = $data['upcoming_sessions'];
$active_editions    = $data['active_editions'];
$active_online      = $data['active_online'];
$completed_items    = $data['completed_items'];
$cancelled_editions = $data['cancelled_editions'];
$action_items       = $data['action_items'] ?? [];
?>

<div class="space-y-8">
    <!-- Action Items (pending enrollment + post-course tasks) -->
    <?php
    get_template_part('templates/dashboard/partials/action-items', null, [
        'items' => $action_items,
    ]);
    ?>

    <!-- Upcoming Sessions -->
    <?php if (!empty($upcoming_sessions)) : ?>
        <section>
            <h3 class="dash-subheading mb-3">
                <?php esc_html_e('Komende sessies', 'stridence'); ?>
            </h3>
            <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                <?php foreach ($upcoming_sessions as $session) : ?>
                    <div class="list-item-static">
                        <div class="flex-1 min-w-0">
                            <?php
                            get_template_part('partials/session-row', null, [
                                'session'    => (object) $session,
                                'attendance' => $session['attendance'] ?? null,
                            ]);
                            ?>
                        </div>
                        <a href="<?php echo esc_url(get_permalink($session['edition_id'])); ?>"
                           class="btn-ghost btn-sm shrink-0">
                            <?php echo esc_html($session['course_title']); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Klassikale opleidingen (Classroom Editions) -->
    <section>
        <h3 class="dash-subheading mb-3">
            <?php esc_html_e('Klassikale opleidingen', 'stridence'); ?>
        </h3>

        <?php if (!empty($active_editions)) : ?>
            <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                <?php foreach ($active_editions as $reg) : ?>
                    <?php
                    $hasTasks = !empty($reg['completion_tasks']);
                    $tasksComplete = $hasTasks && ($reg['task_summary']['completed'] ?? 0) === ($reg['task_summary']['total'] ?? 0);
                    $awaitingApproval = $hasTasks && $tasksComplete && !empty($reg['completion_tasks']['approval']);

                    // Check for post-course tasks on confirmed registrations
                    $hasPostCourseTasks = false;
                    if ($reg['status'] === 'confirmed' && $hasTasks) {
                        $taskArr = is_string($reg['completion_tasks']) ? json_decode($reg['completion_tasks'], true) : $reg['completion_tasks'];
                        if (is_array($taskArr)) {
                            foreach ($taskArr as $t) {
                                if (($t['phase'] ?? 'enrollment') === 'post_course' && ($t['status'] ?? 'pending') !== 'completed') {
                                    $hasPostCourseTasks = true;
                                    break;
                                }
                            }
                        }
                    }

                    // Progress info
                    $attended = $reg['progress']['attended'] ?? 0;
                    $required = $reg['progress']['required'] ?? 0;
                    $progressPct = $required > 0 ? (int) round(($attended / $required) * 100) : 0;
                    ?>
                    <a href="<?php echo esc_url(get_permalink($reg['edition_id'])); ?>"
                       class="list-item">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="font-medium text-text text-sm truncate">
                                    <?php echo esc_html($reg['course_title']); ?>
                                </span>
                                <?php
                                if ($reg['status'] === 'confirmed' && $hasPostCourseTasks) {
                                    get_template_part('partials/badge-status', null, ['status' => 'completing']);
                                } elseif ($reg['status'] === 'pending' && $awaitingApproval) {
                                    get_template_part('partials/badge-status', null, ['status' => 'awaiting_approval']);
                                } elseif ($reg['status'] === 'pending' && $hasTasks) {
                                    get_template_part('partials/badge-status', null, ['status' => 'action_required']);
                                } elseif ($reg['status'] !== 'confirmed') {
                                    get_template_part('partials/badge-status', null, ['status' => $reg['status']]);
                                }
                                ?>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-muted">
                                <?php if ($reg['start_date']) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                                        <?php echo esc_html(stride_format_date($reg['start_date'])); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($reg['venue']) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('map-pin', 'w-3.5 h-3.5'); ?>
                                        <?php echo esc_html($reg['venue']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($required > 0) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('check-square', 'w-3.5 h-3.5'); ?>
                                        <?php echo esc_html(sprintf('%d/%d sessies', $attended, $required)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($reg['task_summary']) && in_array($reg['status'], ['pending', 'confirmed'], true)): ?>
                                <!-- Inline completion progress -->
                                <?php
                                $taskTotal = $reg['task_summary']['total'] ?? 0;
                                $taskDone = $reg['task_summary']['completed'] ?? 0;
                                $taskPct = $taskTotal > 0 ? (int) round(($taskDone / $taskTotal) * 100) : 0;
                                ?>
                                <div class="flex items-center gap-2 mt-1.5">
                                    <div class="flex-1 h-1.5 rounded-full bg-border overflow-hidden max-w-[120px]">
                                        <div class="h-full bg-accent rounded-full transition-all" style="width: <?php echo esc_attr((string) $taskPct); ?>%"></div>
                                    </div>
                                    <span class="text-xs text-text-muted"><?php echo esc_html(sprintf('%d%%', $taskPct)); ?></span>
                                </div>
                            <?php elseif ($required > 0) : ?>
                                <!-- Attendance progress bar -->
                                <div class="flex items-center gap-2 mt-1.5">
                                    <div class="flex-1 h-1.5 rounded-full bg-border overflow-hidden max-w-[120px]">
                                        <div class="h-full bg-accent rounded-full transition-all" style="width: <?php echo esc_attr((string) $progressPct); ?>%"></div>
                                    </div>
                                    <span class="text-xs text-text-muted"><?php echo esc_html(sprintf('%d%%', $progressPct)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <span class="btn-ghost btn-sm shrink-0">
                            <?php esc_html_e('Bekijk', 'stridence'); ?> &rarr;
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            get_template_part('partials/empty-state', null, [
                'icon'    => 'calendar',
                'title'   => __('Geen klassikale inschrijvingen', 'stridence'),
                'message' => __('Je hebt momenteel geen klassikale inschrijvingen. Bekijk ons aanbod en schrijf je in voor een opleiding.', 'stridence'),
                'action'  => __('Bekijk opleidingen', 'stridence'),
                'url'     => home_url('/klassikaal/'),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Online cursussen -->
    <?php if (!empty($active_online)) : ?>
        <section>
            <h3 class="dash-subheading mb-3">
                <?php esc_html_e('Online cursussen', 'stridence'); ?>
            </h3>
            <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                <?php foreach ($active_online as $course) : ?>
                    <a href="<?php echo esc_url($course['course_url']); ?>"
                       class="list-item">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="font-medium text-text text-sm truncate">
                                    <?php echo esc_html($course['course_title']); ?>
                                </span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-accent/10 text-accent">
                                    <?php echo esc_html($course['format_label']); ?>
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-muted">
                                <?php if ($course['last_activity']) : ?>
                                    <span>
                                        <?php echo esc_html(sprintf(
                                            __('Laatst actief: %s', 'stridence'),
                                            date_i18n('j M', $course['last_activity'])
                                        )); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($course['next_drip']) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('clock', 'w-3 h-3'); ?>
                                        <?php echo esc_html(sprintf(
                                            __('Volgende les: %s', 'stridence'),
                                            date_i18n('j M', $course['next_drip']['available_from'])
                                        )); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($course['days_remaining'] !== null && $course['days_remaining'] <= 30) : ?>
                                    <span class="text-warning flex items-center gap-1">
                                        <?php echo stridence_icon('alert-circle', 'w-3 h-3'); ?>
                                        <?php echo esc_html(sprintf(
                                            _n('%d dag over', '%d dagen over', $course['days_remaining'], 'stridence'),
                                            $course['days_remaining']
                                        )); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <!-- Progress bar -->
                            <div class="flex items-center gap-2 mt-1.5">
                                <div class="flex-1 h-1.5 rounded-full bg-border overflow-hidden max-w-[120px]">
                                    <div class="h-full bg-accent rounded-full transition-all"
                                         style="width: <?php echo esc_attr($course['progress']); ?>%"></div>
                                </div>
                                <span class="text-xs text-text-muted whitespace-nowrap">
                                    <?php if ($course['total_lessons'] > 0) : ?>
                                        <?php echo esc_html($course['completed_lessons'] . '/' . $course['total_lessons']); ?>
                                    <?php else : ?>
                                        <?php echo esc_html($course['progress']); ?>%
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <span class="btn-ghost btn-sm shrink-0">
                            <?php echo $course['progress'] > 0
                                ? esc_html__('Verder', 'stridence')
                                : esc_html__('Start', 'stridence'); ?> &rarr;
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Afgerond (Completed - merged editions + online courses) -->
    <?php if (!empty($completed_items)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-3"
                    @click="open = !open">
                <h3 class="dash-subheading">
                    <?php printf(
                        esc_html__('Afgerond (%d)', 'stridence'),
                        count($completed_items)
                    ); ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                    <?php foreach ($completed_items as $item) : ?>
                        <div class="list-item-static">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-text text-sm truncate">
                                        <?php echo esc_html($item['course_title']); ?>
                                    </span>
                                    <?php if (($item['type'] ?? '') === 'online') : ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-accent/10 text-accent">
                                            <?php echo esc_html($item['format_label'] ?? __('Online', 'stridence')); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                            <?php esc_html_e('Klassikaal', 'stridence'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-text-muted mt-0.5">
                                    <?php
                                    $date = $item['completed_at'] ?? $item['start_date'] ?? '';
                                    if ($date) {
                                        echo esc_html(stride_format_date($date));
                                    }
                                    ?>
                                </p>
                            </div>
                            <?php
                            $cert_url = $item['certificate_url'] ?? '';
                            if (!$cert_url && !empty($item['course_id'])) {
                                $cert_url = LearnDashHelper::getCertificateLink((int) $item['course_id'], $user_id);
                            }
                            ?>
                            <?php if ($cert_url) : ?>
                                <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', get_permalink())); ?>"
                                   class="btn-ghost btn-sm shrink-0">
                                    <?php echo stridence_icon('award', 'w-4 h-4 mr-1'); ?>
                                    <?php esc_html_e('Certificaat', 'stridence'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Cancelled Registrations -->
    <?php if (!empty($cancelled_editions)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-3"
                    @click="open = !open">
                <h3 class="dash-subheading text-text-muted">
                    <?php
                    printf(
                        /* translators: %d: number of cancelled registrations */
                        esc_html__('Geannuleerd (%d)', 'stridence'),
                        count($cancelled_editions)
                    );
                    ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                    <?php foreach ($cancelled_editions as $reg) : ?>
                        <div class="list-item-static text-text-muted">
                            <div class="flex-1 min-w-0">
                                <span class="font-medium text-sm truncate block">
                                    <?php echo esc_html($reg['course_title']); ?>
                                </span>
                                <span class="text-xs">
                                    <?php
                                    if ($reg['start_date']) {
                                        echo esc_html(stride_format_date($reg['start_date']));
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
