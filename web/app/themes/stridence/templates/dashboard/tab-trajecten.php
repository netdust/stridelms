<?php
/**
 * Dashboard Tab: Trajecten (Learning Paths) — Helder Tij redesign
 *
 * Card per trajectory: white rounded-[16px] shadow-card p-6.
 * Header row: 72px progress ring + Traject badge + 16px/700 title +
 * "X van Y onderdelen · keuze status" 13px muted + "Open traject" primary sm.
 * Parts checklist: bg-surface rounded-[12px] wells:
 *   done     → ✓ icon green / text-badge-open-text
 *   active   → bg-badge-online-bg tinted well
 *   elective → outlined bg-surface + "Maak je keuze" mini-CTA
 * Empty state via partials/empty-state.
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
    $required_elective_count = 0;
    foreach ($elective_groups as $group) {
        $required_elective_count += (int) ($group['required'] ?? 0);
    }
    $total_required = count($required_courses) + $required_elective_count;

    // Get user's edition registrations linked to this trajectory
    $edition_registrations = $registrationRepo->findEditionsByTrajectory($user_id, $trajectory_id);

    // Get selections from enrollment
    $selections = $enrollment->selections ?? [];
    if (is_string($selections)) {
        $selections = json_decode($selections, true) ?? [];
    }

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
        'enrollment_id'      => (int) $enrollment->id,
        'trajectory_id'      => $trajectory_id,
        'trajectory'         => $trajectory,
        'mode'               => $mode,
        'registered_at'      => $enrollment->registered_at,
        'status'             => $enrollment->status,
        'required_courses'   => $required_courses,
        'elective_groups'    => $elective_groups,
        'total_required'     => $total_required,
        'completed_count'    => $completed_count,
        'in_progress_count'  => $in_progress_count,
        'in_progress_courses' => array_unique($in_progress_courses),
        'completed_courses'  => array_unique($completed_courses),
        'is_complete'        => $is_trajectory_complete,
        'edition_registrations' => $edition_registrations,
        'selections'         => $selections,
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

                    // Elective choice status text
                    $has_electives       = !empty($traj['elective_groups']);
                    $electives_chosen    = !empty($traj['selections']) && is_array($traj['selections']);
                    $elective_status_txt = $has_electives
                        ? ($electives_chosen
                            ? __('keuze gemaakt', 'stridence')
                            : __('keuzemodule nog te kiezen', 'stridence'))
                        : '';

                    // Build course→edition map for "bezig" detection
                    $editionByCourse = [];
                    foreach ($traj['edition_registrations'] as $edReg) {
                        $edId = (int) $edReg->edition_id;
                        $cId  = $editionService->getCourseId($edId);
                        if ($cId !== null && !isset($editionByCourse[$cId])) {
                            $editionByCourse[$cId] = $edId;
                        }
                    }
                    ?>
                    <div class="bg-white rounded-[16px] shadow-card p-6">
                        <!-- Header row: ring + title/meta + CTA -->
                        <div class="flex gap-5 items-start flex-wrap mb-5">
                            <!-- 72px progress ring -->
                            <?php stridence_template_part('templates/dashboard/partials/progress-ring', null, [
                                'progress' => $progressPct,
                                'size'     => 72,
                            ]); ?>

                            <!-- Title + meta -->
                            <div class="flex-1 min-w-[220px]">
                                <!-- Traject badge -->
                                <div class="flex flex-wrap gap-[6px] mb-2">
                                    <?php stridence_template_part('partials/badge-status', null, ['status' => 'trajectory', 'size' => 'sm']); ?>
                                </div>
                                <!-- 16px/700 title -->
                                <div class="text-[16px] font-bold text-text leading-snug">
                                    <?php echo esc_html($traj['trajectory']['title'] ?? ''); ?>
                                </div>
                                <!-- 13px muted: X van Y onderdelen · keuze status -->
                                <div class="text-[13px] text-text-muted mt-1">
                                    <?php echo esc_html(sprintf(
                                        /* translators: %1$d completed, %2$d total */
                                        __('%1$d van %2$d onderdelen afgerond', 'stridence'),
                                        $traj['completed_count'],
                                        $traj['total_required'],
                                    )); ?>
                                    <?php if ($elective_status_txt) : ?>
                                        <span class="mx-1 opacity-40">&middot;</span>
                                        <?php echo esc_html($elective_status_txt); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- "Open traject" primary sm -->
                            <a href="<?php echo esc_url($dashboard_url); ?>"
                               class="btn-primary btn-sm shrink-0">
                                <?php esc_html_e('Open traject', 'stridence'); ?>
                            </a>
                        </div>

                        <!-- Parts checklist rows -->
                        <?php
                        $completedIds  = $traj['completed_courses'];
                        $inProgressIds = $traj['in_progress_courses'];
                        $allParts      = [];

                        // Required courses
                        foreach ($traj['required_courses'] as $course) {
                            $cId = (int) $course->ID;
                            $isDone       = in_array($cId, $completedIds, true);
                            $isActive     = !$isDone && in_array($cId, $inProgressIds, true);
                            $allParts[] = [
                                'type'     => 'required',
                                'course'   => $course,
                                'is_done'  => $isDone,
                                'is_active' => $isActive,
                            ];
                        }

                        // Elective groups
                        foreach ($traj['elective_groups'] as $group) {
                            $groupName     = $group['name'] ?? __('Keuzemodule', 'stridence');
                            $requiredCount = (int) ($group['required'] ?? 1);
                            $groupChosen   = $electives_chosen;

                            $allParts[] = [
                                'type'          => 'elective',
                                'group_name'    => $groupName,
                                'required'      => $requiredCount,
                                'courses_count' => count($group['courses'] ?? []),
                                'is_done'       => $groupChosen,
                                'choice_url'    => add_query_arg('tab', 'keuzes', $dashboard_url),
                            ];
                        }
                        ?>
                        <?php if (!empty($allParts)) : ?>
                            <div class="flex flex-col gap-2">
                                <?php foreach ($allParts as $part) :
                                    if ($part['type'] === 'required') :
                                        $course = $part['course'];
                                        $isDone   = $part['is_done'];
                                        $isActive = $part['is_active'];
                                        $cId      = (int) $course->ID;
                                        $cTitle   = esc_html($course->post_title);

                                        // Edition link for active course
                                        $editionLink = null;
                                        if ($isActive && isset($editionByCourse[$cId])) {
                                            $editionLink = get_permalink($editionByCourse[$cId]) ?: null;
                                        }
                                        ?>
                                        <?php if ($isDone) : ?>
                                            <!-- Done row: bg-surface, green check -->
                                            <div class="bg-surface rounded-[12px] px-4 py-[13px] flex gap-3 items-center">
                                                <span class="w-[22px] h-[22px] rounded-full bg-badge-open-bg flex items-center justify-center shrink-0">
                                                    <span class="text-[12px] font-extrabold text-badge-open-text leading-none">✓</span>
                                                </span>
                                                <span class="text-[14px] font-semibold text-text flex-1"><?php echo $cTitle; ?></span>
                                            </div>
                                        <?php elseif ($isActive) : ?>
                                            <!-- Active row: tinted bg-badge-online-bg -->
                                            <div class="bg-badge-online-bg rounded-[12px] px-4 py-[13px] flex gap-3 items-center">
                                                <span class="w-[22px] h-[22px] rounded-full bg-primary flex items-center justify-center shrink-0">
                                                    <span class="w-2 h-2 rounded-full bg-white inline-block"></span>
                                                </span>
                                                <span class="text-[14px] font-bold text-text flex-1"><?php echo $cTitle; ?></span>
                                                <?php if ($editionLink) : ?>
                                                    <a href="<?php echo esc_url($editionLink); ?>"
                                                       class="text-[12px] font-bold text-badge-online-text shrink-0">
                                                        <?php esc_html_e('Bekijk editie', 'stridence'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php else : ?>
                                            <!-- Upcoming row: plain bg-surface -->
                                            <div class="bg-surface rounded-[12px] px-4 py-[13px] flex gap-3 items-center">
                                                <span class="w-[22px] h-[22px] rounded-full bg-white shadow-[inset_0_0_0_1.5px_theme(colors.border)] shrink-0"></span>
                                                <span class="text-[14px] font-semibold text-text flex-1"><?php echo $cTitle; ?></span>
                                            </div>
                                        <?php endif; ?>

                                    <?php elseif ($part['type'] === 'elective') :
                                        $groupName     = esc_html($part['group_name']);
                                        $requiredCount = $part['required'];
                                        $coursesCount  = $part['courses_count'];
                                        $isGroupDone   = $part['is_done'];
                                        $choiceUrl     = $part['choice_url'];
                                    ?>

                                        <?php if ($isGroupDone) : ?>
                                            <!-- Elective done row -->
                                            <div class="bg-surface rounded-[12px] px-4 py-[13px] flex gap-3 items-center">
                                                <span class="w-[22px] h-[22px] rounded-full bg-badge-open-bg flex items-center justify-center shrink-0">
                                                    <span class="text-[12px] font-extrabold text-badge-open-text leading-none">✓</span>
                                                </span>
                                                <span class="text-[14px] font-semibold text-text flex-1">
                                                    <?php echo esc_html(sprintf(
                                                        /* translators: %s: group name */
                                                        __('%s — keuze gemaakt', 'stridence'),
                                                        $groupName,
                                                    )); ?>
                                                </span>
                                            </div>
                                        <?php else : ?>
                                            <!-- Elective pending row: outlined + mini-CTA -->
                                            <div class="bg-surface rounded-[12px] shadow-[inset_0_0_0_1.5px_theme(colors.border)] px-4 py-[13px] flex flex-wrap gap-3 items-center">
                                                <span class="w-[22px] h-[22px] rounded-full bg-white shadow-[inset_0_0_0_1.5px_theme(colors.border)] shrink-0"></span>
                                                <span class="text-[14px] font-semibold text-text flex-1 min-w-[180px]">
                                                    <?php echo esc_html(sprintf(
                                                        /* translators: %1$d required choices, %2$d total options */
                                                        __('Keuze %1$s — kies %2$d van %3$d', 'stridence'),
                                                        $groupName,
                                                        $requiredCount,
                                                        $coursesCount,
                                                    )); ?>
                                                </span>
                                                <a href="<?php echo esc_url($choiceUrl); ?>"
                                                   class="btn-primary btn-sm shrink-0">
                                                    <?php esc_html_e('Maak je keuze', 'stridence'); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
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
