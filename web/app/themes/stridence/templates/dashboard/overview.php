<?php
/**
 * Dashboard Overview Page
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$current_page = 'overview';
$userId = get_current_user_id();

// Get user stats
$enrolledCourses = 0;
$completedCourses = 0;
$upcomingSessions = 0;
$pendingQuotes = 0;

// Get LearnDash course data if available
if (function_exists('learndash_user_get_enrolled_courses')) {
    $courses = learndash_user_get_enrolled_courses($userId);
    $enrolledCourses = count($courses);

    foreach ($courses as $courseId) {
        if (function_exists('learndash_course_completed') && learndash_course_completed($userId, $courseId)) {
            $completedCourses++;
        }
    }
}

// Get recent/in-progress courses (limit 3)
$recentCourses = [];
if (function_exists('learndash_user_get_enrolled_courses')) {
    $allCourses = learndash_user_get_enrolled_courses($userId);
    $count = 0;
    foreach ($allCourses as $courseId) {
        if ($count >= 3) break;
        $course = get_post($courseId);
        if (!$course) continue;

        $progress = 0;
        if (function_exists('learndash_course_progress')) {
            $progressData = learndash_course_progress(['course_id' => $courseId, 'user_id' => $userId]);
            $progress = $progressData['percentage'] ?? 0;
        }

        $recentCourses[] = [
            'id' => $courseId,
            'title' => $course->post_title,
            'url' => get_permalink($courseId),
            'progress' => $progress,
            'thumbnail' => get_the_post_thumbnail_url($courseId, 'thumbnail'),
        ];
        $count++;
    }
}

// Get upcoming sessions
$upcomingSessionsList = [];
// This would integrate with SessionService if available

include get_stylesheet_directory() . '/templates/partials/dashboard-layout.php';
?>

<header class="str-dashboard__header">
    <h1 class="str-dashboard__title">
        <?php printf(esc_html__('Welkom, %s', 'stridence'), esc_html(wp_get_current_user()->display_name)); ?>
    </h1>
    <p class="str-dashboard__subtitle">
        <?php esc_html_e('Hier vind je een overzicht van je leeractiviteiten.', 'stridence'); ?>
    </p>
</header>

<!-- Stats -->
<div class="str-stats">
    <div class="str-stat">
        <div class="str-stat__icon">
            <?php stridence_icon('book', '', 20); ?>
        </div>
        <div class="str-stat__value"><?php echo esc_html($enrolledCourses); ?></div>
        <div class="str-stat__label"><?php esc_html_e('Ingeschreven cursussen', 'stridence'); ?></div>
    </div>

    <div class="str-stat">
        <div class="str-stat__icon">
            <?php stridence_icon('check-circle', '', 20); ?>
        </div>
        <div class="str-stat__value"><?php echo esc_html($completedCourses); ?></div>
        <div class="str-stat__label"><?php esc_html_e('Voltooid', 'stridence'); ?></div>
    </div>

    <div class="str-stat">
        <div class="str-stat__icon">
            <?php stridence_icon('calendar', '', 20); ?>
        </div>
        <div class="str-stat__value"><?php echo esc_html($upcomingSessions); ?></div>
        <div class="str-stat__label"><?php esc_html_e('Komende sessies', 'stridence'); ?></div>
    </div>

    <div class="str-stat">
        <div class="str-stat__icon">
            <?php stridence_icon('file-text', '', 20); ?>
        </div>
        <div class="str-stat__value"><?php echo esc_html($pendingQuotes); ?></div>
        <div class="str-stat__label"><?php esc_html_e('Openstaande offertes', 'stridence'); ?></div>
    </div>
</div>

<!-- Continue Learning -->
<section class="str-dashboard-section">
    <header class="str-dashboard-section__header">
        <h2 class="str-dashboard-section__title"><?php esc_html_e('Doorgaan met leren', 'stridence'); ?></h2>
        <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>" class="str-btn str-btn--ghost str-btn--sm">
            <?php esc_html_e('Alles bekijken', 'stridence'); ?>
        </a>
    </header>

    <div class="str-dashboard-section__body">
        <?php if (!empty($recentCourses)): ?>
            <div class="str-course-list">
                <?php foreach ($recentCourses as $course): ?>
                    <div class="str-course-list__item">
                        <div class="str-course-list__thumb">
                            <?php if ($course['thumbnail']): ?>
                                <img src="<?php echo esc_url($course['thumbnail']); ?>" alt="">
                            <?php else: ?>
                                <?php stridence_icon('book', '', 24); ?>
                            <?php endif; ?>
                        </div>
                        <div class="str-course-list__content">
                            <h3 class="str-course-list__title">
                                <a href="<?php echo esc_url($course['url']); ?>">
                                    <?php echo esc_html($course['title']); ?>
                                </a>
                            </h3>
                            <div class="str-progress-group">
                                <div class="str-progress str-progress--sm">
                                    <div class="str-progress__bar" style="width: <?php echo esc_attr($course['progress']); ?>%;"></div>
                                </div>
                                <span class="str-course-list__progress-text">
                                    <?php echo esc_html($course['progress']); ?>% <?php esc_html_e('voltooid', 'stridence'); ?>
                                </span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url($course['url']); ?>" class="str-btn str-btn--primary str-btn--sm">
                            <?php stridence_icon('play', '', 16); ?>
                            <?php esc_html_e('Doorgaan', 'stridence'); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="str-dashboard-section__empty">
                <?php stridence_icon('book', '', 32); ?>
                <p><?php esc_html_e('Je bent nog niet ingeschreven voor cursussen.', 'stridence'); ?></p>
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="str-btn str-btn--primary">
                    <?php esc_html_e('Bekijk cursussen', 'stridence'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Upcoming Sessions -->
<section class="str-dashboard-section">
    <header class="str-dashboard-section__header">
        <h2 class="str-dashboard-section__title"><?php esc_html_e('Komende sessies', 'stridence'); ?></h2>
        <a href="<?php echo esc_url(home_url('/mijn-account/agenda/')); ?>" class="str-btn str-btn--ghost str-btn--sm">
            <?php esc_html_e('Agenda bekijken', 'stridence'); ?>
        </a>
    </header>

    <div class="str-dashboard-section__body">
        <?php if (!empty($upcomingSessionsList)): ?>
            <!-- Session list would go here -->
        <?php else: ?>
            <div class="str-dashboard-section__empty">
                <?php stridence_icon('calendar', '', 32); ?>
                <p><?php esc_html_e('Je hebt geen komende sessies gepland.', 'stridence'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
include get_stylesheet_directory() . '/templates/partials/dashboard-layout-close.php';
get_footer();
?>
