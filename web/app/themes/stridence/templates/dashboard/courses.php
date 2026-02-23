<?php
/**
 * My Courses Dashboard Page
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$current_page = 'courses';
$userId = get_current_user_id();

// Get all enrolled courses
$courses = [];
if (function_exists('learndash_user_get_enrolled_courses')) {
    $enrolledCourses = learndash_user_get_enrolled_courses($userId);

    foreach ($enrolledCourses as $courseId) {
        $course = get_post($courseId);
        if (!$course) continue;

        $progress = 0;
        $completed = false;
        if (function_exists('learndash_course_progress')) {
            $progressData = learndash_course_progress(['course_id' => $courseId, 'user_id' => $userId]);
            $progress = $progressData['percentage'] ?? 0;
        }
        if (function_exists('learndash_course_completed')) {
            $completed = learndash_course_completed($userId, $courseId);
        }

        $courses[] = [
            'id' => $courseId,
            'title' => $course->post_title,
            'url' => get_permalink($courseId),
            'progress' => $progress,
            'completed' => $completed,
            'thumbnail' => get_the_post_thumbnail_url($courseId, 'medium'),
        ];
    }
}

include get_stylesheet_directory() . '/templates/partials/dashboard-layout.php';
?>

<header class="str-dashboard__header">
    <h1 class="str-dashboard__title"><?php esc_html_e('Mijn cursussen', 'stridence'); ?></h1>
    <p class="str-dashboard__subtitle">
        <?php printf(esc_html__('%d cursussen', 'stridence'), count($courses)); ?>
    </p>
</header>

<?php if (!empty($courses)): ?>
    <div class="str-grid str-grid--courses">
        <?php foreach ($courses as $course): ?>
            <article class="str-card str-card--hover">
                <div class="str-course-card__image-wrapper">
                    <?php if ($course['thumbnail']): ?>
                        <img src="<?php echo esc_url($course['thumbnail']); ?>" alt="" class="str-card__image" loading="lazy">
                    <?php else: ?>
                        <div class="str-card__image str-course-card__placeholder">
                            <?php stridence_icon('book', '', 48); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($course['completed']): ?>
                        <span class="str-card__badge" style="background: var(--str-success);">
                            <?php esc_html_e('Voltooid', 'stridence'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="str-card__body">
                    <h3 class="str-card__title">
                        <a href="<?php echo esc_url($course['url']); ?>">
                            <?php echo esc_html($course['title']); ?>
                        </a>
                    </h3>

                    <div class="str-progress-group">
                        <div class="str-progress-label">
                            <span><?php esc_html_e('Voortgang', 'stridence'); ?></span>
                            <span class="str-progress-label__value"><?php echo esc_html($course['progress']); ?>%</span>
                        </div>
                        <div class="str-progress">
                            <div class="str-progress__bar" style="width: <?php echo esc_attr($course['progress']); ?>%;"></div>
                        </div>
                    </div>

                    <div class="str-card__footer">
                        <a href="<?php echo esc_url($course['url']); ?>" class="str-btn str-btn--primary str-btn--sm str-btn--block">
                            <?php if ($course['completed']): ?>
                                <?php esc_html_e('Opnieuw bekijken', 'stridence'); ?>
                            <?php else: ?>
                                <?php stridence_icon('play', '', 16); ?>
                                <?php esc_html_e('Doorgaan', 'stridence'); ?>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="str-empty-state">
        <?php stridence_icon('book', '', 48); ?>
        <h2><?php esc_html_e('Nog geen cursussen', 'stridence'); ?></h2>
        <p><?php esc_html_e('Je bent nog niet ingeschreven voor cursussen.', 'stridence'); ?></p>
        <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="str-btn str-btn--primary">
            <?php esc_html_e('Bekijk cursussen', 'stridence'); ?>
        </a>
    </div>
<?php endif; ?>

<?php
include get_stylesheet_directory() . '/templates/partials/dashboard-layout-close.php';
get_footer();
?>
