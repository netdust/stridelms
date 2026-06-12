<?php
/**
 * Dashboard Tab: Trajecten (Learning Paths).
 *
 * Renders the shared `partials/card-trajectory` (dashboard mode) per active
 * enrollment — progress badge + "Open traject" — and a collapsible list of
 * completed trajectories. The per-course detail lives on the trajectory
 * dashboard page, not this card. Empty state via partials/empty-state.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\RegistrationStatus;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get services
$registrationRepo  = ntdst_get(RegistrationRepository::class);
$trajectoryService = ntdst_get(TrajectoryService::class);
$trajectoryRepo    = ntdst_get(TrajectoryRepository::class);

// Get user's trajectory enrollments
$enrollments = $registrationRepo->findTrajectoryEnrollmentsByUser($user_id);

// Group by status
$active    = [];
$completed = [];

foreach ($enrollments as $enrollment) {
    $trajectory_id = (int) $enrollment->trajectory_id;
    $trajectory = $trajectoryService->getTrajectory($trajectory_id);

    if (!$trajectory) {
        continue;
    }

    // Progress = completed courses / total parts. The shared card consumes
    // only the count + total (for the "X% voltooid" badge and the
    // active/completed split); the per-course detail lives on the trajectory
    // dashboard page, not this card.
    $required_courses = $trajectoryRepo->getRequiredCourses($trajectory_id);
    $elective_groups  = $trajectoryRepo->getElectiveGroups($trajectory_id);

    $required_elective_count = 0;
    foreach ($elective_groups as $group) {
        $required_elective_count += (int) ($group['required'] ?? 0);
    }
    $total_required = count($required_courses) + $required_elective_count;

    $completed_courses = [];
    foreach ($required_courses as $course) {
        if (LearnDashHelper::isComplete((int) $course->ID, $user_id)) {
            $completed_courses[] = (int) $course->ID;
        }
    }
    foreach ($elective_groups as $group) {
        foreach ($group['courses'] as $course) {
            if (LearnDashHelper::isComplete((int) $course->ID, $user_id)) {
                $completed_courses[] = (int) $course->ID;
            }
        }
    }

    $completed_count = count(array_unique($completed_courses));
    $is_trajectory_complete = $completed_count >= $total_required && $total_required > 0;

    $trajectory_data = [
        'trajectory_id'  => $trajectory_id,
        'trajectory'     => $trajectory,
        'registered_at'  => $enrollment->registered_at,
        'status'         => $enrollment->status,
        'total_required' => $total_required,
        'completed_count' => $completed_count,
        'is_complete'    => $is_trajectory_complete,
    ];

    $status = RegistrationStatus::tryFrom($enrollment->status) ?? RegistrationStatus::Confirmed;

    if ($status === RegistrationStatus::Completed || $is_trajectory_complete) {
        $completed[] = $trajectory_data;
    } else {
        $active[] = $trajectory_data;
    }
}
?>

<div class="space-y-6">

    <?php if (empty($active) && empty($completed)) : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon'    => 'layers',
            'title'   => __('Geen actieve trajecten', 'stridence'),
            'message' => __('Je bent nog niet ingeschreven voor een leertraject. Ontdek onze trajecten en start je leerpad.', 'stridence'),
            'action'  => __('Bekijk trajecten', 'stridence'),
            'url'     => get_post_type_archive_link('vad_trajectory'),
        ]);
        ?>
    <?php else : ?>

        <!-- Active Trajectories -->
        <?php if (!empty($active)) : ?>
            <div class="flex flex-col gap-6">
                <?php foreach ($active as $traj) :
                    $progressPct = $traj['total_required'] > 0
                        ? (int) round(($traj['completed_count'] / $traj['total_required']) * 100)
                        : 0;

                    $trajectory_post = get_post($traj['trajectory_id']);
                    $trajectory_slug = $trajectory_post ? $trajectory_post->post_name : '';
                    $dashboard_url = $trajectory_slug
                        ? home_url('/mijn-account/trajecten/' . $trajectory_slug . '/')
                        : get_permalink($traj['trajectory_id']);

                    // Shared card — dashboard mode: progress drives the
                    // "X% voltooid" badge, registered_at the "Gestart …" line.
                    // The per-course checklist is intentionally gone (too many
                    // courses); the trajectory dashboard page holds the detail.
                    stridence_template_part(
                        'partials/card-trajectory',
                        null,
                        stridence_build_trajectory_card_args((int) $traj['trajectory_id'], [
                            'progress'      => $progressPct,
                            'started_at'    => (string) ($traj['registered_at'] ?? ''),
                            'dashboard_url' => $dashboard_url,
                        ]),
                    );
                endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Completed Trajectories — collapsible -->
        <?php if (!empty($completed)) : ?>
            <section x-data="{ open: false }">
                <button type="button"
                        class="w-full flex items-center justify-between gap-4 mb-3"
                        @click="open = !open">
                    <h3 class="text-base font-semibold text-text">
                        <?php printf(
                            /* translators: %d: number of completed trajectories */
                            esc_html__('Afgeronde trajecten (%d)', 'stridence'),
                            count($completed),
                        ); ?>
                    </h3>
                    <span class="text-text-muted transition-transform duration-200"
                          :class="{ 'rotate-180': open }">
                        <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                    </span>
                </button>

                <div x-show="open" x-collapse>
                    <div class="bg-surface-card rounded-[16px] border border-border shadow-sm">
                        <?php foreach ($completed as $traj) : ?>
                            <div class="flex items-center gap-4 px-4 py-3.5 border-b border-border/60 last:border-b-0 transition-colors">
                                <span class="shrink-0 w-8 h-8 rounded-full bg-success/10 flex items-center justify-center">
                                    <?php echo stridence_icon('award', 'w-4 h-4 text-success'); ?>
                                </span>
                                <div class="flex-1 min-w-0">
                                    <span class="font-medium text-text text-sm truncate block">
                                        <?php echo esc_html($traj['trajectory']['title'] ?? ''); ?>
                                    </span>
                                    <span class="text-xs text-text-muted">
                                        <?php printf(
                                            esc_html__('%d cursussen afgerond', 'stridence'),
                                            $traj['completed_count'],
                                        ); ?>
                                    </span>
                                </div>
                                <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', get_permalink())); ?>"
                                   class="btn-ghost btn-sm shrink-0">
                                    <?php echo stridence_icon('award', 'w-4 h-4 mr-1'); ?>
                                    <?php esc_html_e('Certificaat', 'stridence'); ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

    <?php endif; ?>

</div>
