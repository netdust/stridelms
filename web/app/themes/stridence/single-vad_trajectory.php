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

use Stride\Modules\Trajectory\TrajectoryService;

$trajectory_id = get_the_ID();

// Get trajectory service
$trajectoryService = ntdst_get(TrajectoryService::class);

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
$price_non_member   = $trajectory['price_non_member'] ?? 0;
$capacity           = $trajectory['capacity'] ?? 0;

// Get courses via service (now returns WP_Post objects)
$required_courses   = $trajectoryService->getRequiredCourses($trajectory_id);
$elective_groups    = $trajectoryService->getElectiveGroups($trajectory_id);

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
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-accent text-text-inverse">
                    <?php echo stridence_icon('layers', 'w-3 h-3'); ?>
                    <?php esc_html_e('Leertraject', 'stridence'); ?>
                </span>
            </div>

            <div class="flex flex-wrap items-start gap-4 mb-4">
                <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text flex-1">
                    <?php the_title(); ?>
                </h1>
                <?php
                stridence_template_part('partials/badge-status', null, [
                    'status' => $badge_status,
                ]);
                ?>
            </div>

            <div class="flex flex-wrap gap-6 text-text-muted">
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
                            $total_courses
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

            <!-- Sidebar (1/3) - Enrollment Card -->
            <div class="lg:col-span-1">
                <div class="card p-6 sticky top-24">
                    <h3 class="font-heading font-semibold text-lg mb-4">
                        <?php esc_html_e('Inschrijven', 'stridence'); ?>
                    </h3>

                    <div class="space-y-3 mb-6">
                        <div class="flex justify-between">
                            <span class="text-text-muted"><?php esc_html_e('Prijs (leden)', 'stridence'); ?></span>
                            <span class="font-semibold">
                                <?php
                                if ($price > 0) {
                                    echo esc_html(stride_format_money((int) ($price * 100)));
                                } else {
                                    esc_html_e('Op aanvraag', 'stridence');
                                }
                                ?>
                            </span>
                        </div>
                        <?php if ($price_non_member > 0 && $price_non_member !== $price) : ?>
                            <div class="flex justify-between">
                                <span class="text-text-muted"><?php esc_html_e('Prijs (niet-leden)', 'stridence'); ?></span>
                                <span class="font-semibold">
                                    <?php echo esc_html(stride_format_money((int) ($price_non_member * 100))); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if ($deadline) : ?>
                            <div class="flex justify-between">
                                <span class="text-text-muted"><?php esc_html_e('Inschrijven tot', 'stridence'); ?></span>
                                <span><?php echo esc_html(stride_format_date($deadline)); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($capacity > 0) : ?>
                            <div class="flex justify-between">
                                <span class="text-text-muted"><?php esc_html_e('Maximaal', 'stridence'); ?></span>
                                <span>
                                    <?php
                                    printf(
                                        esc_html(_n('%d deelnemer', '%d deelnemers', $capacity, 'stridence')),
                                        $capacity
                                    );
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($can_enroll) : ?>
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
            </div>
        </div>
    </div>

    <!-- Mobile Sticky CTA (hidden on lg+) -->
    <?php if ($can_enroll) : ?>
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
