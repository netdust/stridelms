<?php
/**
 * Dashboard Tab: Trajecten (Learning Paths)
 *
 * Shows user's trajectory enrollments with progress tracking.
 * Handles both cohort (fixed schedule) and self-paced (flexible) modes.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\RegistrationStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Edition\EditionService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get services
$registrationRepo   = ntdst_get(RegistrationRepository::class);
$trajectoryService  = ntdst_get(TrajectoryService::class);
$trajectoryRepo     = ntdst_get(TrajectoryRepository::class);
$editionService     = ntdst_get(EditionService::class);

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

    // Get trajectory courses
    $required_courses = $trajectoryRepo->getRequiredCourses($trajectory_id);
    $elective_groups  = $trajectoryRepo->getElectiveGroups($trajectory_id);

    // Calculate total courses count
    $total_courses = count($required_courses);
    $required_elective_count = 0;
    foreach ($elective_groups as $group) {
        $required_elective_count += (int) ($group['required'] ?? 0);
    }
    $total_required = count($required_courses) + $required_elective_count;

    // Get user's edition registrations linked to this trajectory
    $edition_registrations = $registrationRepo->findEditionsByTrajectory($user_id, $trajectory_id);

    // Get selections from enrollment (selected editions/courses for self-paced)
    $selections = $enrollment->selections ? json_decode($enrollment->selections, true) : [];

    // Calculate progress based on mode
    $mode = TrajectoryMode::tryFrom($trajectory['mode']) ?? TrajectoryMode::Cohort;
    $completed_courses = [];
    $in_progress_courses = [];

    // Check course completion for each required course
    foreach ($required_courses as $course) {
        $course_id = $course->ID;
        if (LearnDashHelper::isComplete($course_id, $user_id)) {
            $completed_courses[] = $course_id;
        } else {
            // Check if user has active registration for any edition of this course
            $has_active_registration = false;
            foreach ($edition_registrations as $edReg) {
                $ed_course_id = $editionService->getCourseId((int) $edReg->edition_id);
                if ($ed_course_id === $course_id) {
                    $has_active_registration = true;
                    break;
                }
            }
            if ($has_active_registration) {
                $in_progress_courses[] = $course_id;
            }
        }
    }

    // Check elective group completion
    foreach ($elective_groups as $group) {
        foreach ($group['courses'] as $course) {
            $course_id = $course->ID;
            if (LearnDashHelper::isComplete($course_id, $user_id)) {
                $completed_courses[] = $course_id;
            } else {
                foreach ($edition_registrations as $edReg) {
                    $ed_course_id = $editionService->getCourseId((int) $edReg->edition_id);
                    if ($ed_course_id === $course_id) {
                        $in_progress_courses[] = $course_id;
                        break;
                    }
                }
            }
        }
    }

    $completed_count = count(array_unique($completed_courses));
    $in_progress_count = count(array_unique($in_progress_courses));
    $is_trajectory_complete = $completed_count >= $total_required && $total_required > 0;

    $trajectory_data = [
        'enrollment_id'     => (int) $enrollment->id,
        'trajectory_id'     => $trajectory_id,
        'trajectory'        => $trajectory,
        'mode'              => $mode,
        'registered_at'     => $enrollment->registered_at,
        'status'            => $enrollment->status,
        'required_courses'  => $required_courses,
        'elective_groups'   => $elective_groups,
        'total_required'    => $total_required,
        'completed_count'   => $completed_count,
        'in_progress_count' => $in_progress_count,
        'is_complete'       => $is_trajectory_complete,
        'edition_registrations' => $edition_registrations,
        'selections'        => $selections,
    ];

    // Check enrollment status
    $status = RegistrationStatus::tryFrom($enrollment->status) ?? RegistrationStatus::Confirmed;

    if ($status === RegistrationStatus::Completed || $is_trajectory_complete) {
        $completed[] = $trajectory_data;
    } else {
        $active[] = $trajectory_data;
    }
}
?>

<div class="space-y-8">
    <!-- Active Trajectories -->
    <section>
        <h3 class="dash-subheading mb-3">
            <?php esc_html_e('Actieve trajecten', 'stridence'); ?>
        </h3>

        <?php if (!empty($active)) : ?>
            <div class="bg-surface-card rounded-xl border border-border shadow-sm">
                <?php foreach ($active as $traj) :
                    $progressPct = $traj['total_required'] > 0
                        ? (int) round(($traj['completed_count'] / $traj['total_required']) * 100)
                        : 0;

                    $trajectory_post = get_post($traj['trajectory_id']);
                    $trajectory_slug = $trajectory_post ? $trajectory_post->post_name : '';
                    $dashboard_url = $trajectory_slug
                        ? home_url('/mijn-account/trajecten/' . $trajectory_slug . '/')
                        : get_permalink($traj['trajectory_id']);
                ?>
                    <a href="<?php echo esc_url($dashboard_url); ?>"
                       class="flex items-center gap-4 px-4 py-3.5 border-b border-border/60 last:border-b-0 cursor-pointer hover:bg-surface-alt transition-colors">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-text text-sm truncate">
                                    <?php echo esc_html($traj['trajectory']['title']); ?>
                                </span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?php echo $traj['mode'] === TrajectoryMode::Cohort ? 'bg-primary/10 text-primary' : 'bg-accent/10 text-accent'; ?>">
                                    <?php echo $traj['mode'] === TrajectoryMode::Cohort
                                        ? esc_html__('Cohort', 'stridence')
                                        : esc_html__('Zelfgestuurd', 'stridence'); ?>
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-muted">
                                <span class="flex items-center gap-1">
                                    <?php echo stridence_icon('book-open', 'w-3.5 h-3.5'); ?>
                                    <?php printf(
                                        esc_html__('%d van %d cursussen', 'stridence'),
                                        $traj['completed_count'],
                                        $traj['total_required']
                                    ); ?>
                                </span>
                                <?php if ($traj['in_progress_count'] > 0) : ?>
                                    <span class="flex items-center gap-1 text-accent">
                                        <?php echo stridence_icon('clock', 'w-3.5 h-3.5'); ?>
                                        <?php printf(
                                            esc_html__('%d in uitvoering', 'stridence'),
                                            $traj['in_progress_count']
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <!-- Progress bar -->
                            <div class="flex items-center gap-2 mt-1.5">
                                <div class="flex-1 h-1 rounded-full bg-surface-alt overflow-hidden max-w-[120px]">
                                    <div class="h-full bg-primary rounded-full transition-all" style="width: <?php echo esc_attr((string) $progressPct); ?>%"></div>
                                </div>
                                <span class="text-xs text-text-muted"><?php echo esc_html(sprintf('%d%%', $progressPct)); ?></span>
                            </div>
                        </div>
                        <span class="btn-ghost btn-sm shrink-0">
                            <?php esc_html_e('Bekijk', 'stridence'); ?> &rarr;
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            stridence_template_part('partials/empty-state', null, [
                'icon'    => 'layers',
                'title'   => __('Geen actieve trajecten', 'stridence'),
                'message' => __('Je bent nog niet ingeschreven voor een leertraject. Ontdek onze trajecten en start je leerpad.', 'stridence'),
                'action'  => __('Bekijk trajecten', 'stridence'),
                'url'     => get_post_type_archive_link('vad_trajectory'),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Completed Trajectories -->
    <?php if (!empty($completed)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-3"
                    @click="open = !open">
                <h3 class="dash-subheading">
                    <?php
                    printf(
                        /* translators: %d: number of completed trajectories */
                        esc_html__('Afgeronde trajecten (%d)', 'stridence'),
                        count($completed)
                    );
                    ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="bg-surface-card rounded-xl border border-border shadow-sm">
                    <?php foreach ($completed as $traj) : ?>
                        <div class="flex items-center gap-4 px-4 py-3.5 border-b border-border/60 last:border-b-0 transition-colors">
                            <span class="shrink-0 w-8 h-8 rounded-full bg-success/10 flex items-center justify-center">
                                <?php echo stridence_icon('award', 'w-4 h-4 text-success'); ?>
                            </span>
                            <div class="flex-1 min-w-0">
                                <span class="font-medium text-text text-sm truncate block">
                                    <?php echo esc_html($traj['trajectory']['title']); ?>
                                </span>
                                <span class="text-xs text-text-muted">
                                    <?php printf(
                                        esc_html__('%d cursussen afgerond', 'stridence'),
                                        $traj['completed_count']
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
</div>
