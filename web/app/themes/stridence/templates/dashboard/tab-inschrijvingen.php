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
            <h2 class="font-heading text-xl font-bold text-text mb-4">
                <?php esc_html_e('Komende sessies', 'stridence'); ?>
            </h2>
            <div class="card divide-y divide-border">
                <?php foreach ($upcoming_sessions as $session) : ?>
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <?php
                                get_template_part('partials/session-row', null, [
                                    'session'    => (object) $session,
                                    'attendance' => $session['attendance'] ?? null,
                                ]);
                                ?>
                            </div>
                            <a href="<?php echo esc_url(get_permalink($session['edition_id'])); ?>"
                               class="text-sm text-primary hover:underline shrink-0">
                                <?php echo esc_html($session['course_title']); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Klassikale opleidingen (Classroom Editions) -->
    <section>
        <h2 class="font-heading text-xl font-bold text-text mb-4">
            <?php esc_html_e('Klassikale opleidingen', 'stridence'); ?>
        </h2>

        <?php if (!empty($active_editions)) : ?>
            <div class="space-y-4">
                <?php foreach ($active_editions as $reg) : ?>
                    <div class="card" x-data="expandable()">
                        <button type="button"
                                class="w-full p-4 flex items-center justify-between gap-4 text-left"
                                @click="toggle()">
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-text truncate">
                                    <?php echo esc_html($reg['course_title']); ?>
                                </h3>
                                <div class="flex flex-wrap gap-4 mt-1 text-sm text-text-muted">
                                    <?php if ($reg['start_date']) : ?>
                                        <span class="flex items-center gap-1">
                                            <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                            <?php echo esc_html(stride_format_date($reg['start_date'])); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($reg['venue']) : ?>
                                        <span class="flex items-center gap-1">
                                            <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                                            <?php echo esc_html($reg['venue']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
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

                            if ($reg['status'] === 'confirmed' && $hasPostCourseTasks) {
                                get_template_part('partials/badge-status', null, ['status' => 'completing']);
                            } elseif ($reg['status'] === 'confirmed') {
                                // Fully enrolled — no badge needed
                            } elseif ($reg['status'] === 'pending' && $awaitingApproval) {
                                get_template_part('partials/badge-status', null, ['status' => 'awaiting_approval']);
                            } elseif ($reg['status'] === 'pending' && $hasTasks) {
                                get_template_part('partials/badge-status', null, ['status' => 'action_required']);
                            } else {
                                get_template_part('partials/badge-status', null, ['status' => $reg['status']]);
                            }
                            ?>
                            <span class="shrink-0 text-text-muted transition-transform duration-200"
                                  :class="{ 'rotate-180': open }">
                                <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                            </span>
                        </button>

                        <div x-show="open" x-collapse class="border-t border-border">
                            <!-- Status + Actions -->
                            <div class="p-4 space-y-4">
                                <?php if (!empty($reg['task_summary']) && in_array($reg['status'], ['pending', 'confirmed'], true)): ?>
                                    <!-- Completion Checklist -->
                                    <?php
                                    get_template_part('templates/dashboard/partials/completion-checklist', null, [
                                        'task_summary' => $reg['task_summary'],
                                        'complete_url' => $reg['complete_url'],
                                    ]);
                                    ?>
                                <?php else: ?>
                                    <!-- Progress -->
                                    <?php
                                    get_template_part('partials/progress-bar', null, [
                                        'attended' => $reg['progress']['attended'],
                                        'required' => $reg['progress']['required'],
                                        'label'    => __('Aanwezigheid', 'stridence'),
                                    ]);
                                    ?>
                                <?php endif; ?>
                            </div>

                            <!-- Session List -->
                            <?php if (!empty($reg['sessions'])) : ?>
                                <div class="divide-y divide-border border-t border-border">
                                    <?php foreach ($reg['sessions'] as $session) : ?>
                                        <?php
                                        get_template_part('partials/session-row', null, [
                                            'session'    => (object) $session,
                                            'attendance' => $session['attendance'] ?? null,
                                        ]);
                                        ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Actions -->
                            <div class="p-4 border-t border-border">
                                <a href="<?php echo esc_url(get_permalink($reg['edition_id'])); ?>"
                                   class="btn-ghost text-sm">
                                    <?php esc_html_e('Bekijk details', 'stridence'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
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
            <h2 class="font-heading text-xl font-bold text-text mb-4">
                <?php esc_html_e('Online cursussen', 'stridence'); ?>
            </h2>
            <div class="space-y-3">
                <?php foreach ($active_online as $course) : ?>
                    <div class="card p-4">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <h3 class="font-semibold text-text truncate">
                                        <?php echo esc_html($course['course_title']); ?>
                                    </h3>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-accent/10 text-accent">
                                        <?php echo esc_html($course['format_label']); ?>
                                    </span>
                                </div>
                                <div class="flex items-center gap-3 mt-2">
                                    <div class="flex-1 h-2 bg-border rounded-full overflow-hidden">
                                        <div class="h-full bg-accent rounded-full transition-all"
                                             style="width: <?php echo esc_attr($course['progress']); ?>%"></div>
                                    </div>
                                    <span class="text-sm text-text-muted whitespace-nowrap">
                                        <?php if ($course['total_lessons'] > 0) : ?>
                                            <?php echo esc_html($course['completed_lessons'] . '/' . $course['total_lessons']); ?>
                                        <?php else : ?>
                                            <?php echo esc_html($course['progress']); ?>%
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-3 mt-2 text-xs text-text-muted">
                                    <?php if ($course['last_activity']) : ?>
                                        <span>
                                            <?php echo esc_html(sprintf(
                                                __('Laatst actief: %s', 'stridence'),
                                                date_i18n('j M', $course['last_activity'])
                                            )); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($course['next_drip']) : ?>
                                        <span>
                                            <?php echo stridence_icon('clock', 'w-3 h-3 inline-block mr-0.5'); ?>
                                            <?php echo esc_html(sprintf(
                                                __('Volgende les: %s', 'stridence'),
                                                date_i18n('j M', $course['next_drip']['available_from'])
                                            )); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($course['days_remaining'] !== null && $course['days_remaining'] <= 30) : ?>
                                        <span class="text-warning">
                                            <?php echo stridence_icon('alert-circle', 'w-3 h-3 inline-block mr-0.5'); ?>
                                            <?php echo esc_html(sprintf(
                                                _n('%d dag toegang over', '%d dagen toegang over', $course['days_remaining'], 'stridence'),
                                                $course['days_remaining']
                                            )); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="<?php echo esc_url($course['course_url']); ?>"
                               class="btn-primary text-sm shrink-0">
                                <?php echo $course['progress'] > 0
                                    ? esc_html__('Verder leren', 'stridence')
                                    : esc_html__('Start cursus', 'stridence'); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Afgerond (Completed - merged editions + online courses) -->
    <?php if (!empty($completed_items)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-4"
                    @click="open = !open">
                <h2 class="font-heading text-xl font-bold text-text">
                    <?php printf(
                        esc_html__('Afgerond (%d)', 'stridence'),
                        count($completed_items)
                    ); ?>
                </h2>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="card divide-y divide-border">
                    <?php foreach ($completed_items as $item) : ?>
                        <div class="p-4 flex items-center justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-medium text-text truncate">
                                        <?php echo esc_html($item['course_title']); ?>
                                    </h3>
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
                                <p class="text-sm text-text-muted">
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
                                   class="btn-ghost text-sm">
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
                    class="w-full flex items-center justify-between gap-4 mb-4"
                    @click="open = !open">
                <h2 class="font-heading text-xl font-bold text-text-muted">
                    <?php
                    printf(
                        /* translators: %d: number of cancelled registrations */
                        esc_html__('Geannuleerd (%d)', 'stridence'),
                        count($cancelled_editions)
                    );
                    ?>
                </h2>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="card divide-y divide-border">
                    <?php foreach ($cancelled_editions as $reg) : ?>
                        <div class="p-4 text-text-muted">
                            <h3 class="font-medium truncate">
                                <?php echo esc_html($reg['course_title']); ?>
                            </h3>
                            <p class="text-sm">
                                <?php
                                if ($reg['start_date']) {
                                    echo esc_html(stride_format_date($reg['start_date']));
                                }
                                ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
