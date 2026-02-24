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
    get_template_part('partials/empty-state', null, [
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

// Get courses via service
$required_courses   = $trajectoryService->getRequiredCourses($trajectory_id);
$elective_groups    = $trajectoryService->getElectiveGroups($trajectory_id);

// Flatten elective groups for template (course-groups.php expects flat array)
$elective_courses = [];
$electives_required = 0;
foreach ($elective_groups as $group) {
    $electives_required += (int) ($group['required'] ?? 0);
    $elective_courses = array_merge($elective_courses, $group['courses'] ?? []);
}

// Map trajectory status to badge status
$badge_status_map = [
    'open'      => 'open',
    'ongoing'   => 'pending',
    'completed' => 'completed',
    'draft'     => 'pending',
    'closed'    => 'cancelled',
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
            get_template_part('partials/breadcrumb', null, [
                'items' => $breadcrumbs,
            ]);
            ?>

            <div class="flex flex-wrap items-start gap-4 mb-4">
                <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text flex-1">
                    <?php the_title(); ?>
                </h1>
                <?php
                get_template_part('partials/badge-status', null, [
                    'status' => $badge_status,
                ]);
                ?>
            </div>

            <?php if ($deadline) : ?>
                <div class="flex items-center gap-2 text-text-muted">
                    <?php echo stridence_icon('clock', 'w-5 h-5'); ?>
                    <span>Deadline: <?php echo esc_html(stride_format_date($deadline)); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="container py-8 lg:py-12">
        <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
            <!-- Main Content (2/3) -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Trajectory Description -->
                <?php if (get_the_content()) : ?>
                    <section>
                        <div class="prose-stride max-w-none">
                            <?php the_content(); ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Course Groups Section -->
                <section>
                    <h2 class="font-heading text-2xl font-bold text-text mb-6">
                        <?php esc_html_e('Cursussen in dit traject', 'stridence'); ?>
                    </h2>

                    <?php if (!empty($required_courses) || !empty($elective_courses)) : ?>
                        <?php
                        get_template_part('templates/trajectory/course-groups', null, [
                            'required_courses'   => $required_courses,
                            'elective_courses'   => $elective_courses,
                            'electives_required' => $electives_required,
                        ]);
                        ?>
                    <?php else : ?>
                        <!-- Empty state when no courses configured -->
                        <div class="card p-6 text-center text-text-muted">
                            <?php esc_html_e('Cursussen worden binnenkort toegevoegd.', 'stridence'); ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Sidebar (1/3) - Contact Card -->
            <div class="lg:col-span-1">
                <div class="card p-6 sticky top-24">
                    <h3 class="font-heading font-semibold text-lg mb-4">
                        <?php esc_html_e('Interesse?', 'stridence'); ?>
                    </h3>
                    <p class="text-sm text-text-muted mb-6">
                        <?php esc_html_e('Neem contact met ons op voor meer informatie over dit traject of om je interesse kenbaar te maken.', 'stridence'); ?>
                    </p>
                    <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-primary w-full text-center block">
                        <?php esc_html_e('Contact opnemen', 'stridence'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</article>

<?php get_footer(); ?>
