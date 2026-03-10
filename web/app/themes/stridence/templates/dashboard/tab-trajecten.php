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
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Edition\EditionService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get services
$registrationRepo   = ntdst_get(RegistrationRepository::class);
$trajectoryService  = ntdst_get(TrajectoryService::class);
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
    $required_courses = $trajectoryService->getRequiredCourses($trajectory_id);
    $elective_groups  = $trajectoryService->getElectiveGroups($trajectory_id);

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
        <h2 class="font-heading text-xl font-bold text-text mb-4">
            <?php esc_html_e('Actieve trajecten', 'stridence'); ?>
        </h2>

        <?php if (!empty($active)) : ?>
            <div class="space-y-4">
                <?php foreach ($active as $traj) : ?>
                    <div class="dash-card" x-data="expandable()">
                        <button type="button"
                                class="w-full p-4 flex items-center justify-between gap-4 text-left"
                                @click="toggle()">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <h3 class="font-semibold text-text truncate">
                                        <?php echo esc_html($traj['trajectory']['title']); ?>
                                    </h3>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?php echo $traj['mode'] === TrajectoryMode::Cohort ? 'bg-primary/10 text-primary' : 'bg-accent/10 text-accent'; ?>">
                                        <?php echo $traj['mode'] === TrajectoryMode::Cohort
                                            ? esc_html__('Cohort', 'stridence')
                                            : esc_html__('Zelfgestuurd', 'stridence'); ?>
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-4 text-sm text-text-muted">
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('book-open', 'w-4 h-4'); ?>
                                        <?php printf(
                                            esc_html__('%d van %d cursussen', 'stridence'),
                                            $traj['completed_count'],
                                            $traj['total_required']
                                        ); ?>
                                    </span>
                                    <?php if ($traj['in_progress_count'] > 0) : ?>
                                        <span class="flex items-center gap-1 text-accent">
                                            <?php echo stridence_icon('clock', 'w-4 h-4'); ?>
                                            <?php printf(
                                                esc_html__('%d in uitvoering', 'stridence'),
                                                $traj['in_progress_count']
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="shrink-0 text-text-muted transition-transform duration-200"
                                  :class="{ 'rotate-180': open }">
                                <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                            </span>
                        </button>

                        <div x-show="open" x-collapse class="border-t border-border">
                            <div class="p-4 space-y-6">
                                <!-- Progress Bar -->
                                <?php
                                get_template_part('partials/progress-bar', null, [
                                    'attended' => $traj['completed_count'],
                                    'required' => $traj['total_required'],
                                    'label'    => __('Voortgang', 'stridence'),
                                ]);
                                ?>

                                <!-- Course List by Group -->
                                <div class="space-y-4">
                                    <!-- Required Courses -->
                                    <?php if (!empty($traj['required_courses'])) : ?>
                                        <div>
                                            <h4 class="text-xs font-medium text-text-muted uppercase tracking-wide mb-2">
                                                <?php esc_html_e('Verplichte cursussen', 'stridence'); ?>
                                            </h4>
                                            <div class="divide-y divide-border rounded-lg border border-border">
                                                <?php foreach ($traj['required_courses'] as $course) :
                                                    $is_course_complete = LearnDashHelper::isComplete($course->ID, $user_id);
                                                    $is_in_progress = in_array($course->ID, array_unique($in_progress_courses ?? []));
                                                ?>
                                                    <div class="p-3 flex items-center justify-between gap-3">
                                                        <div class="flex items-center gap-3 min-w-0">
                                                            <?php if ($is_course_complete) : ?>
                                                                <span class="shrink-0 w-6 h-6 rounded-full bg-success/10 flex items-center justify-center">
                                                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                                                </span>
                                                            <?php elseif ($is_in_progress) : ?>
                                                                <span class="shrink-0 w-6 h-6 rounded-full bg-accent/10 flex items-center justify-center">
                                                                    <?php echo stridence_icon('clock', 'w-4 h-4 text-accent'); ?>
                                                                </span>
                                                            <?php else : ?>
                                                                <span class="shrink-0 w-6 h-6 rounded-full bg-border flex items-center justify-center">
                                                                    <span class="w-2 h-2 rounded-full bg-text-muted"></span>
                                                                </span>
                                                            <?php endif; ?>
                                                            <span class="truncate <?php echo $is_course_complete ? 'text-text-muted line-through' : 'text-text'; ?>">
                                                                <?php echo esc_html($course->post_title); ?>
                                                            </span>
                                                        </div>
                                                        <?php if ($is_course_complete) : ?>
                                                            <span class="text-xs text-success font-medium">
                                                                <?php esc_html_e('Afgerond', 'stridence'); ?>
                                                            </span>
                                                        <?php elseif ($is_in_progress) : ?>
                                                            <span class="text-xs text-accent font-medium">
                                                                <?php esc_html_e('Bezig', 'stridence'); ?>
                                                            </span>
                                                        <?php else : ?>
                                                            <a href="<?php echo esc_url(get_permalink($course)); ?>"
                                                               class="text-xs text-primary hover:underline">
                                                                <?php esc_html_e('Bekijk', 'stridence'); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Elective Groups -->
                                    <?php foreach ($traj['elective_groups'] as $group) :
                                        $group_name = $group['name'] ?? __('Keuzecursussen', 'stridence');
                                        $group_required = (int) ($group['required'] ?? 0);
                                        $courses = $group['courses'] ?? [];
                                        if (empty($courses)) continue;

                                        // Count completed in this group
                                        $group_completed = 0;
                                        foreach ($courses as $course) {
                                            if (LearnDashHelper::isComplete($course->ID, $user_id)) {
                                                $group_completed++;
                                            }
                                        }
                                    ?>
                                        <div>
                                            <h4 class="text-xs font-medium text-text-muted uppercase tracking-wide mb-2 flex items-center justify-between">
                                                <span><?php echo esc_html($group_name); ?></span>
                                                <?php if ($group_required > 0) : ?>
                                                    <span class="text-text font-semibold">
                                                        <?php printf(
                                                            esc_html__('%d/%d vereist', 'stridence'),
                                                            $group_completed,
                                                            $group_required
                                                        ); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </h4>
                                            <div class="divide-y divide-border rounded-lg border border-border">
                                                <?php foreach ($courses as $course) :
                                                    $is_course_complete = LearnDashHelper::isComplete($course->ID, $user_id);
                                                    $is_in_progress = false;
                                                    foreach ($traj['edition_registrations'] as $edReg) {
                                                        if ($editionService->getCourseId((int) $edReg->edition_id) === $course->ID) {
                                                            $is_in_progress = true;
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                    <div class="p-3 flex items-center justify-between gap-3">
                                                        <div class="flex items-center gap-3 min-w-0">
                                                            <?php if ($is_course_complete) : ?>
                                                                <span class="shrink-0 w-6 h-6 rounded-full bg-success/10 flex items-center justify-center">
                                                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                                                </span>
                                                            <?php elseif ($is_in_progress) : ?>
                                                                <span class="shrink-0 w-6 h-6 rounded-full bg-accent/10 flex items-center justify-center">
                                                                    <?php echo stridence_icon('clock', 'w-4 h-4 text-accent'); ?>
                                                                </span>
                                                            <?php else : ?>
                                                                <span class="shrink-0 w-6 h-6 rounded-full bg-border flex items-center justify-center">
                                                                    <span class="w-2 h-2 rounded-full bg-text-muted"></span>
                                                                </span>
                                                            <?php endif; ?>
                                                            <span class="truncate <?php echo $is_course_complete ? 'text-text-muted line-through' : 'text-text'; ?>">
                                                                <?php echo esc_html($course->post_title); ?>
                                                            </span>
                                                        </div>
                                                        <?php if ($is_course_complete) : ?>
                                                            <span class="text-xs text-success font-medium">
                                                                <?php esc_html_e('Afgerond', 'stridence'); ?>
                                                            </span>
                                                        <?php elseif ($is_in_progress) : ?>
                                                            <span class="text-xs text-accent font-medium">
                                                                <?php esc_html_e('Bezig', 'stridence'); ?>
                                                            </span>
                                                        <?php else : ?>
                                                            <a href="<?php echo esc_url(get_permalink($course)); ?>"
                                                               class="text-xs text-primary hover:underline">
                                                                <?php esc_html_e('Bekijk', 'stridence'); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Actions -->
                                <div class="flex flex-wrap gap-3 pt-2">
                                    <?php
                                    $trajectory_post = get_post($traj['trajectory_id']);
                                    $trajectory_slug = $trajectory_post ? $trajectory_post->post_name : '';
                                    $dashboard_url = $trajectory_slug
                                        ? home_url('/mijn-account/trajecten/' . $trajectory_slug . '/')
                                        : get_permalink($traj['trajectory_id']);
                                    ?>
                                    <a href="<?php echo esc_url($dashboard_url); ?>"
                                       class="btn-primary text-sm">
                                        <?php echo stridence_icon('layout-dashboard', 'w-4 h-4 mr-1'); ?>
                                        <?php esc_html_e('Mijn dashboard', 'stridence'); ?>
                                    </a>
                                    <a href="<?php echo esc_url(get_permalink($traj['trajectory_id'])); ?>"
                                       class="btn-secondary text-sm">
                                        <?php esc_html_e('Bekijk traject', 'stridence'); ?>
                                        <?php echo stridence_icon('chevron-right', 'w-4 h-4 ml-1'); ?>
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
                    class="w-full flex items-center justify-between gap-4 mb-4"
                    @click="open = !open">
                <h2 class="font-heading text-xl font-bold text-text">
                    <?php
                    printf(
                        /* translators: %d: number of completed trajectories */
                        esc_html__('Afgeronde trajecten (%d)', 'stridence'),
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
                <div class="dash-card divide-y divide-border">
                    <?php foreach ($completed as $traj) : ?>
                        <div class="p-4 flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="shrink-0 w-10 h-10 rounded-full bg-success/10 flex items-center justify-center">
                                    <?php echo stridence_icon('award', 'w-5 h-5 text-success'); ?>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="font-medium text-text truncate">
                                        <?php echo esc_html($traj['trajectory']['title']); ?>
                                    </h3>
                                    <p class="text-sm text-text-muted">
                                        <?php printf(
                                            esc_html__('%d cursussen afgerond', 'stridence'),
                                            $traj['completed_count']
                                        ); ?>
                                    </p>
                                </div>
                            </div>
                            <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', get_permalink())); ?>"
                               class="btn-ghost text-sm">
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
