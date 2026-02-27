<?php
/**
 * Dashboard Tab: Inschrijvingen (Registrations)
 *
 * Shows user's course registrations grouped by status.
 * Uses NTDST services for data access.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Enrollment\EnrollmentCompletionService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get services
$registrationRepo  = ntdst_get(RegistrationRepository::class);
$editionService    = ntdst_get(EditionService::class);
$sessionService    = ntdst_get(SessionService::class);
$attendanceService = ntdst_get(AttendanceService::class);
$completionService = ntdst_get(CompletionService::class);
$enrollmentCompletion = ntdst_get(EnrollmentCompletionService::class);

// Get all user registrations
$registrations = $registrationRepo->findByUser($user_id);

// Group registrations by status
$active    = [];
$completed = [];
$cancelled = [];

foreach ($registrations as $reg) {
    // Skip trajectory-only enrollments (no edition_id)
    if (empty($reg->edition_id)) {
        continue;
    }

    $edition_id = (int) $reg->edition_id;
    $edition    = $editionService->getEdition($edition_id);

    if (is_wp_error($edition)) {
        continue;
    }

    // Get edition data
    $editionModel = ntdst_data()->get('vad_edition');
    $course_id    = $editionService->getCourseId($edition_id);
    $course       = $course_id ? get_post($course_id) : null;

    $reg_data = [
        'id'               => (int) $reg->id,
        'edition_id'       => $edition_id,
        'edition'          => $edition,
        'course'           => $course,
        'course_title'     => $course ? $course->post_title : $edition->post_title,
        'start_date'       => $editionModel->getMeta($edition_id, 'start_date', ''),
        'venue'            => $editionModel->getMeta($edition_id, 'venue', ''),
        'status'           => $reg->status,
        'registered_at'    => $reg->registered_at,
        'sessions'         => $sessionService->getSessionsForEdition($edition_id),
        'progress'         => $completionService->getProgress($edition_id, $user_id),
        'completion_tasks' => $reg->completion_tasks ?? null,
    ];

    // Add attendance for each session
    foreach ($reg_data['sessions'] as &$session) {
        $attendance_status = $attendanceService->getStatus((int) $session['id'], $user_id);
        $session['attendance'] = $attendance_status?->value;
    }
    unset($session);

    $status = RegistrationStatus::tryFrom($reg->status) ?? RegistrationStatus::Confirmed;

    switch ($status) {
        case RegistrationStatus::Completed:
            $completed[] = $reg_data;
            break;
        case RegistrationStatus::Cancelled:
            $cancelled[] = $reg_data;
            break;
        default:
            $active[] = $reg_data;
    }
}

// Get upcoming sessions from active registrations
$upcoming_sessions = [];
$today = date('Y-m-d');

foreach ($active as $reg) {
    foreach ($reg['sessions'] as $session) {
        if (!empty($session['date']) && $session['date'] >= $today) {
            $upcoming_sessions[] = array_merge($session, [
                'course_title' => $reg['course_title'],
                'edition_id'   => $reg['edition_id'],
            ]);
        }
    }
}

// Sort by date and take first 3
usort($upcoming_sessions, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
$upcoming_sessions = array_slice($upcoming_sessions, 0, 3);
?>

<div class="space-y-8">
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

    <!-- Active Registrations -->
    <section>
        <h2 class="font-heading text-xl font-bold text-text mb-4">
            <?php esc_html_e('Actieve inschrijvingen', 'stridence'); ?>
        </h2>

        <?php if (!empty($active)) : ?>
            <div class="space-y-4">
                <?php foreach ($active as $reg) : ?>
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
                            get_template_part('partials/badge-status', null, [
                                'status' => $reg['status'],
                            ]);
                            ?>
                            <span class="shrink-0 text-text-muted transition-transform duration-200"
                                  :class="{ 'rotate-180': open }">
                                <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                            </span>
                        </button>

                        <div x-show="open" x-collapse class="border-t border-border">
                            <div class="p-4 space-y-4">
                                <?php if ($reg['status'] === 'pending' && !empty($reg['completion_tasks'])): ?>
                                    <!-- Completion Checklist -->
                                    <?php
                                    $taskSummary = $enrollmentCompletion->getTaskSummary($reg['id']);
                                    $edition_slug = get_post_field('post_name', $reg['edition_id']);
                                    $complete_url = home_url('/vormingen/' . $edition_slug . '/voltooien/');

                                    get_template_part('templates/dashboard/partials/completion-checklist', null, [
                                        'task_summary' => $taskSummary,
                                        'complete_url' => $complete_url,
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

                                <!-- Session List -->
                                <?php if (!empty($reg['sessions'])) : ?>
                                    <div class="divide-y divide-border rounded-lg border border-border">
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
                                <div class="flex flex-wrap gap-3 pt-2">
                                    <a href="<?php echo esc_url(get_permalink($reg['edition_id'])); ?>"
                                       class="btn-ghost text-sm">
                                        <?php esc_html_e('Bekijk details', 'stridence'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            get_template_part('partials/empty-state', null, [
                'icon'    => 'calendar',
                'title'   => __('Geen actieve inschrijvingen', 'stridence'),
                'message' => __('Je hebt momenteel geen actieve inschrijvingen. Bekijk ons aanbod en schrijf je in voor een opleiding.', 'stridence'),
                'action'  => __('Bekijk opleidingen', 'stridence'),
                'url'     => get_post_type_archive_link('sfwd-courses'),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Completed Registrations -->
    <?php if (!empty($completed)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-4"
                    @click="open = !open">
                <h2 class="font-heading text-xl font-bold text-text">
                    <?php
                    printf(
                        /* translators: %d: number of completed courses */
                        esc_html__('Afgerond (%d)', 'stridence'),
                        count($completed)
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
                    <?php foreach ($completed as $reg) : ?>
                        <div class="p-4 flex items-center justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <h3 class="font-medium text-text truncate">
                                    <?php echo esc_html($reg['course_title']); ?>
                                </h3>
                                <p class="text-sm text-text-muted">
                                    <?php
                                    if ($reg['start_date']) {
                                        echo esc_html(stride_format_date($reg['start_date']));
                                    }
                                    ?>
                                </p>
                            </div>
                            <?php if ($reg['course']) : ?>
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
    <?php if (!empty($cancelled)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-4"
                    @click="open = !open">
                <h2 class="font-heading text-xl font-bold text-text-muted">
                    <?php
                    printf(
                        /* translators: %d: number of cancelled registrations */
                        esc_html__('Geannuleerd (%d)', 'stridence'),
                        count($cancelled)
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
                    <?php foreach ($cancelled as $reg) : ?>
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
