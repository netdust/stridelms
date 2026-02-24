<?php
/**
 * Trajectories Template
 *
 * Displays user's enrolled learning trajectories (multi-course paths).
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Trajectory\TrajectoryService;

$userId = get_current_user_id();

// Get services
$enrollmentService = ntdst_get(EnrollmentService::class);
$trajectoryService = ntdst_get(TrajectoryService::class);

// Get user's trajectory enrollments
// For now, get all enrollments and filter by enrollment_path
$allEnrollments = $enrollmentService->getUserEnrollments($userId);
$trajectoryEnrollments = array_filter($allEnrollments, function ($enrollment) {
    // Handle both object and array formats
    $path = is_object($enrollment) ? ($enrollment->enrollment_path ?? '') : ($enrollment['enrollment_path'] ?? '');
    return $path === 'trajectory';
});

// Group by trajectory
$trajectories = [];
foreach ($trajectoryEnrollments as $enrollment) {
    // Handle both object and array formats
    $trajectoryId = is_object($enrollment) ? ($enrollment->trajectory_id ?? null) : ($enrollment['trajectory_id'] ?? null);
    if ($trajectoryId) {
        if (!isset($trajectories[$trajectoryId])) {
            $trajectory = $trajectoryService->getTrajectory($trajectoryId);
            if ($trajectory) {
                $trajectories[$trajectoryId] = [
                    'info' => $trajectory,
                    'courses' => [],
                ];
            }
        }
        if (isset($trajectories[$trajectoryId])) {
            // Convert object to array for consistent template usage
            $enrollmentData = is_object($enrollment) ? (array) $enrollment : $enrollment;
            $trajectories[$trajectoryId]['courses'][] = $enrollmentData;
        }
    }
}
?>

<div class="stride-my-trajectories stride-dashboard-trajectories stride-dashboard-page">
    <!-- Page Header -->
    <div class="stride-page-header uk-margin-medium-bottom">
        <h1 class="uk-heading-medium"><?php esc_html_e('Mijn Trajecten', 'stride'); ?></h1>
        <p class="uk-text-meta">
            <?php esc_html_e('Volg je voortgang in je leertrajecten.', 'stride'); ?>
        </p>
    </div>

    <?php if (empty($trajectories)) : ?>
        <!-- Empty State -->
        <div class="uk-card uk-card-default uk-card-body uk-text-center uk-padding-large">
            <span uk-icon="icon: git-branch; ratio: 3" class="uk-text-muted"></span>
            <h3 class="uk-margin-small-top"><?php esc_html_e('Geen trajecten', 'stride'); ?></h3>
            <p class="uk-text-muted">
                <?php esc_html_e('Je bent nog niet ingeschreven voor een leertraject.', 'stride'); ?>
            </p>
            <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="uk-button uk-button-primary uk-margin-top">
                <?php esc_html_e('Bekijk trajecten', 'stride'); ?>
            </a>
        </div>
    <?php else : ?>
        <!-- Trajectories List -->
        <div class="uk-grid uk-grid-medium uk-child-width-1-1" uk-grid>
            <?php foreach ($trajectories as $trajectoryId => $data) :
                $trajectory = $data['info'];
                $courses = $data['courses'];
                $totalCourses = count($courses);
                $completedCourses = count(array_filter($courses, fn($c) => !empty($c['is_complete'])));
                $progressPercent = $totalCourses > 0 ? round(($completedCourses / $totalCourses) * 100) : 0;
            ?>
                <div>
                    <div class="uk-card uk-card-default">
                        <div class="uk-card-header">
                            <h3 class="uk-card-title uk-margin-remove-bottom">
                                <?php echo esc_html($trajectory['title'] ?? __('Onbekend traject', 'stride')); ?>
                            </h3>
                            <p class="uk-text-meta uk-margin-remove-top">
                                <?php
                                printf(
                                    esc_html__('%d van %d cursussen voltooid', 'stride'),
                                    $completedCourses,
                                    $totalCourses
                                );
                                ?>
                            </p>
                        </div>

                        <div class="uk-card-body">
                            <!-- Progress Bar -->
                            <div class="uk-margin-bottom">
                                <progress class="uk-progress" value="<?php echo esc_attr($progressPercent); ?>" max="100"></progress>
                            </div>

                            <!-- Course List -->
                            <ul class="uk-list uk-list-divider">
                                <?php foreach ($courses as $course) : ?>
                                    <li class="uk-flex uk-flex-between uk-flex-middle">
                                        <div>
                                            <?php if (!empty($course['is_complete'])) : ?>
                                                <span uk-icon="icon: check; ratio: 0.8" class="uk-text-success uk-margin-small-right"></span>
                                            <?php else : ?>
                                                <span uk-icon="icon: clock; ratio: 0.8" class="uk-text-muted uk-margin-small-right"></span>
                                            <?php endif; ?>
                                            <span><?php echo esc_html($course['course_title'] ?? ''); ?></span>
                                        </div>
                                        <?php if (!empty($course['url'])) : ?>
                                            <a href="<?php echo esc_url($course['url']); ?>" class="uk-button uk-button-text uk-button-small">
                                                <?php echo $course['is_complete'] ? esc_html__('Bekijken', 'stride') : esc_html__('Verder', 'stride'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <?php if ($progressPercent === 100) : ?>
                            <div class="uk-card-footer uk-background-primary uk-light uk-text-center">
                                <span uk-icon="icon: check; ratio: 0.9"></span>
                                <?php esc_html_e('Traject voltooid!', 'stride'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Navigation (Desktop nav panel + Mobile bottom navbar) -->
    <?php include locate_template('templates/dashboard/partials/nav-panel.php'); ?>
</div>
