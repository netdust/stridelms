<?php
/**
 * Trajectory Detail Template
 *
 * Single template for learning trajectories (vad_trajectory post type).
 * Two-column layout with course groups and sticky contact card.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;

$trajectory_id = get_the_ID();

// Get trajectory service
$trajectoryService = ntdst_get(TrajectoryService::class);
$trajectoryRepo    = ntdst_get(TrajectoryRepository::class);

// Get trajectory data via service
$trajectory = $trajectoryService->getTrajectory($trajectory_id);
if (!$trajectory) {
    stridence_template_part('partials/empty-state', null, [
        'icon'    => 'alert-circle',
        'title'   => __('Traject niet gevonden', 'stridence'),
        'message' => __('Dit traject bestaat niet of is verwijderd.', 'stridence'),
        'action'  => __('Naar trajecten', 'stridence'),
        'url'     => get_post_type_archive_link('vad_trajectory'),
    ]);
    return;
}

// Extract fields from trajectory array
$status             = $trajectory['status'];
$status_enum        = $trajectory['status_enum'];
$deadline           = $trajectory['enrollment_deadline'];
$can_enroll         = $trajectoryService->isEnrollmentOpen($trajectory_id);
$price              = $trajectory['price'] ?? 0;
$capacity           = $trajectory['capacity'] ?? 0;

// Already-enrolled check. Trajectory enrollment is a PARENT registration row
// (trajectory_id set, edition_id NULL) — hasActiveRegistration() routes that
// through RegistrationRepository::existsForTrajectory(). Without this the CTA
// always shows "Nu inschrijven" even for enrolled users (mirrors the
// is_enrolled guard in single-vad_edition.php).
$enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$is_enrolled       = is_user_logged_in()
    && $enrollmentService->hasActiveRegistration(get_current_user_id(), null, $trajectory_id);
$account_url       = home_url('/mijn-account/trajecten/' . get_post_field('post_name', $trajectory_id) . '/');

// Enrolled CTA. Unlike an edition, a trajectory parent row carries no
// completion_tasks (cascade children + the account /voortgang view own
// task/progress), so the CTA is progress-derived rather than task-derived:
// completed-all → "Traject voltooid", otherwise → "Naar mijn traject". The
// per-course "✓ Afgerond / vanaf <date> / Nog in te plannen" planning state
// on the overview cards is computed independently in the card-args builder
// (helpers/templates.php) from LearnDashHelper::isComplete — no extra plumbing.
$enrolled_cta = null;
if ($is_enrolled) {
    $progressData = ntdst_get(\Stride\Modules\Trajectory\TrajectoryDashboardService::class)
        ->getProgressData(get_current_user_id(), $trajectory_id);
    $total     = (int) ($progressData['total_required'] ?? 0);
    $completed = (int) ($progressData['completed_count'] ?? 0);
    $enrolled_cta = ($total > 0 && $completed >= $total)
        ? ['label' => __('Traject voltooid', 'stridence'), 'url' => $account_url]
        : ['label' => __('Naar mijn traject', 'stridence'), 'url' => $account_url];
}

// Descriptive sidebar fields (shared with editions), render-when-present.
$price_includes     = trim((string) ($trajectory['price_includes'] ?? ''));
$enrollment_info    = trim((string) ($trajectory['enrollment_info'] ?? ''));
$cta_benefits       = array_values(array_filter(array_map(
    'trim',
    preg_split('/\R/', (string) ($trajectory['cta_benefits'] ?? '')) ?: [],
)));

// Capacity bar — mirrors the edition sidebar (single-vad_edition.php:105-230).
// Only when a finite capacity is set; self-paced trajectories use capacity 0
// (unlimited) → no bar. $spots is remaining seats; fill is the used fraction.
$registrationRepo  = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$spots             = $capacity > 0
    ? max(0, $capacity - count($registrationRepo->findByTrajectory($trajectory_id, 'confirmed')))
    : null;
$show_capacity_bar = $spots !== null && $can_enroll;
$capacity_fill     = $show_capacity_bar
    ? max(0, min(100, (int) round((($capacity - $spots) / $capacity) * 100)))
    : 0;
$spots_few         = $spots !== null && $spots > 0 && $spots <= 5;

// Get courses via repository (returns WP_Post objects)
$required_courses   = $trajectoryRepo->getRequiredCourses($trajectory_id);
$elective_groups    = $trajectoryRepo->getElectiveGroups($trajectory_id);

$has_courses = !empty($required_courses) || !empty($elective_groups);

// Map trajectory status to badge status
$badge_status_map = [
    'open'         => 'open',
    'announcement' => 'announcement',
    'ongoing'      => 'pending',
    'completed'    => 'completed',
    'draft'        => 'pending',
    'closed'       => 'cancelled',
];
$badge_status = $badge_status_map[$status] ?? 'open';

// Breadcrumb items
$breadcrumbs = [
    ['label' => __('Trajecten', 'stridence'), 'url' => get_post_type_archive_link('vad_trajectory')],
    ['label' => get_the_title()],
];

get_header();
?>

<article <?php post_class('pb-12 lg:pb-16'); ?>>
    <!-- Header Section -->
    <div class="bg-surface-alt border-b border-border">
        <div class="container py-8 lg:py-12">
            <?php
            stridence_template_part('partials/breadcrumb', null, [
                'items' => $breadcrumbs,
            ]);
?>

            <!-- Format badge -->
            <div class="flex items-center gap-2 mb-4">
                <?php
                stridence_template_part('partials/badge-status', null, [
                    'status' => 'trajectory',
                ]);
                ?>
            </div>

            <div class="flex flex-wrap items-start gap-4 mb-4">
                <h1 class="font-serif font-normal text-[clamp(1.75rem,3.5vw,2.5rem)] leading-[1.12] text-text flex-1">
                    <?php the_title(); ?>
                </h1>
                <?php
    stridence_template_part('partials/badge-status', null, [
        'status' => $badge_status,
    ]);
?>
            </div>

            <div class="flex flex-wrap gap-6 text-sm text-text-muted">
                <?php if ($deadline) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('calendar', 'w-5 h-5'); ?>
                        <?php printf(esc_html__('Deadline: %s', 'stridence'), esc_html(stride_format_date($deadline))); ?>
                    </span>
                <?php endif; ?>

                <?php if ($has_courses) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('book-open', 'w-5 h-5'); ?>
                        <?php
        $total_courses = count($required_courses);
                    foreach ($elective_groups as $group) {
                        $total_courses += count($group['courses'] ?? []);
                    }
                    printf(
                        esc_html(_n('%d cursus', '%d cursussen', $total_courses, 'stridence')),
                        $total_courses,
                    );
                    ?>
                    </span>
                <?php endif; ?>

                <?php if ($price > 0) : ?>
                    <span class="flex items-center gap-2 font-semibold text-text">
                        <?php echo stridence_icon('receipt', 'w-5 h-5 text-text-muted'); ?>
                        <?php echo esc_html(stride_format_money((int) ($price * 100))); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sticky Tab Bar -->
    <?php
    stridence_template_part('templates/trajectory/tabs', null, [
        'has_courses' => $has_courses,
    ]);
?>

    <!-- Two Column Layout -->
    <div class="container py-8 lg:py-12">
        <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
            <!-- Main Content (2/3) -->
            <div class="lg:col-span-2 space-y-12">
                <?php
            stridence_template_part('templates/trajectory/content', null, [
                'trajectory_id'     => $trajectory_id,
                'required_courses'  => $required_courses,
                'elective_groups'   => $elective_groups,
                'trajectory'        => $trajectory,
            ]);
?>
            </div>

            <!-- Sidebar (1/3) — sticky enrollment card, mirrors the edition
                 sidebar design (single-vad_edition.php:585-684): price hero,
                 capacity bar, slim info rows, CTA, descriptive fields. -->
            <div class="lg:col-span-1">
                <aside class="bg-surface-card rounded-[16px] shadow-elevated p-7 sticky top-24">
                    <!-- Price hero -->
                    <?php if ($price > 0) : ?>
                        <div class="flex items-baseline gap-2">
                            <span class="text-[32px] font-extrabold tracking-[-0.01em] text-text"><?php echo esc_html(stride_format_money((int) ($price * 100))); ?></span>
                            <span class="text-[13px] text-text-faint"><?php esc_html_e('per deelnemer', 'stridence'); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="text-[32px] font-extrabold tracking-[-0.01em] text-badge-free-text"><?php esc_html_e('Gratis', 'stridence'); ?></div>
                    <?php endif; ?>
                    <?php if ($price_includes !== '') : ?>
                        <div class="text-[13px] text-text-muted mt-1"><?php echo esc_html($price_includes); ?></div>
                    <?php endif; ?>

                    <!-- Capacity bar -->
                    <?php if ($show_capacity_bar) : ?>
                        <div class="mt-5">
                            <div class="h-2 rounded-full bg-surface-alt overflow-hidden">
                                <div class="h-full rounded-full bg-primary" style="width: <?php echo esc_attr((string) $capacity_fill); ?>%"></div>
                            </div>
                            <div class="text-[13px] font-bold mt-2 <?php echo $spots_few ? 'text-badge-few-text' : 'text-text-muted'; ?>">
                                <?php
                                /* translators: 1: spots remaining, 2: total capacity */
                                echo esc_html(sprintf(__('Nog %1$d van %2$d plaatsen vrij', 'stridence'), (int) $spots, (int) $capacity));
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Deadline info row -->
                    <?php if ($deadline) : ?>
                        <div class="flex justify-between text-[13px] mt-5">
                            <span class="text-text-muted"><?php esc_html_e('Inschrijven tot', 'stridence'); ?></span>
                            <span class="font-semibold text-text"><?php echo esc_html(stride_format_date($deadline)); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- CTA -->
                    <div class="mt-[22px]">
                        <?php if ($is_enrolled && $enrolled_cta) : ?>
                            <a href="<?php echo esc_url($enrolled_cta['url']); ?>" class="btn-primary w-full text-center block">
                                <?php echo esc_html($enrolled_cta['label']); ?>
                            </a>
                            <p class="text-xs text-text-muted mt-3 text-center">
                                <?php esc_html_e('Je bent ingeschreven voor dit traject.', 'stridence'); ?>
                            </p>
                        <?php elseif ($can_enroll) : ?>
                            <a href="<?php echo esc_url(stride_enrollment_url($trajectory_id, 'trajectory')); ?>" class="btn-primary w-full text-center block">
                                <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
                            </a>
                        <?php elseif ($status_enum->allowsInterest()) : ?>
                            <a href="<?php echo esc_url(stride_enrollment_url($trajectory_id, 'trajectory')); ?>" class="btn-primary w-full text-center block">
                                <?php esc_html_e('Interesse melden', 'stridence'); ?>
                            </a>
                            <p class="text-xs text-text-muted mt-3 text-center">
                                <?php esc_html_e('Dit traject is nog in voorbereiding. Meld je interesse en we houden je op de hoogte.', 'stridence'); ?>
                            </p>
                        <?php else : ?>
                            <button type="button" class="btn-secondary w-full text-center opacity-50 cursor-not-allowed" disabled>
                                <?php esc_html_e('Niet beschikbaar', 'stridence'); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($enrollment_info !== '') : ?>
                        <p class="text-[13px] text-text-muted leading-relaxed mt-4"><?php echo esc_html($enrollment_info); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($cta_benefits)) : ?>
                        <div class="border-t border-border-soft mt-5 pt-4">
                            <ul class="flex flex-col gap-2 text-[13px] text-text-muted">
                                <?php foreach ($cta_benefits as $benefit) : ?>
                                    <li class="flex items-center gap-2">
                                        <span class="text-badge-open-text font-extrabold" aria-hidden="true">&check;</span>
                                        <?php echo esc_html($benefit); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>
        </div>
    </div>

    <!-- Mobile Sticky CTA (hidden on lg+) -->
    <?php if ($is_enrolled && $enrolled_cta) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url($enrolled_cta['url']); ?>" class="btn-primary w-full text-center">
                <?php echo esc_html($enrolled_cta['label']); ?>
            </a>
        </div>
    <?php elseif ($can_enroll) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(stride_enrollment_url($trajectory_id, 'trajectory')); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
            </a>
        </div>
    <?php elseif ($status_enum->allowsInterest()) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(stride_enrollment_url($trajectory_id, 'trajectory')); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Interesse melden', 'stridence'); ?>
            </a>
        </div>
    <?php endif; ?>
</article>

<?php get_footer(); ?>
