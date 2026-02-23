<?php
/**
 * Trajectory Detail Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$trajectoryId = get_the_ID();
$trajectory = get_post($trajectoryId);

// Get trajectory meta
$courses = get_post_meta($trajectoryId, '_courses', true) ?: [];
$price = get_post_meta($trajectoryId, '_price', true) ?: 0;
$duration = get_post_meta($trajectoryId, '_duration', true) ?: '';

// Get user progress if logged in
$userProgress = 0;
$completedCourses = 0;
if (is_user_logged_in() && !empty($courses)) {
    $userId = get_current_user_id();
    foreach ($courses as $courseId) {
        if (function_exists('learndash_course_completed') && learndash_course_completed($userId, $courseId)) {
            $completedCourses++;
        }
    }
    $userProgress = count($courses) > 0 ? round(($completedCourses / count($courses)) * 100) : 0;
}

$totalCourses = count($courses);

$features = [
    __('Gestructureerd leerpad', 'stridence'),
    __('Gecombineerd certificaat', 'stridence'),
    __('Voordelige bundelprijs', 'stridence'),
    __('Flexibel plannen', 'stridence'),
];
?>

<main class="str-main">
    <div class="str-container">
        <article class="str-detail">
            <div class="str-detail__grid">
                <div class="str-detail__main">
                    <!-- Hero -->
                    <div class="str-detail__hero">
                        <?php if (has_post_thumbnail()): ?>
                            <?php the_post_thumbnail('large', ['class' => 'str-detail__hero-image']); ?>
                        <?php else: ?>
                            <div class="str-detail__hero-placeholder">
                                <?php stridence_icon('gift', '', 64); ?>
                            </div>
                        <?php endif; ?>

                        <div class="str-detail__badges">
                            <span class="str-type-badge str-type-badge--trajectory">
                                <?php esc_html_e('Traject', 'stridence'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Header -->
                    <header class="str-detail__header">
                        <h1 class="str-detail__title"><?php the_title(); ?></h1>

                        <div class="str-detail__meta">
                            <span class="str-detail__meta-item">
                                <?php stridence_icon('book', '', 20); ?>
                                <?php printf(esc_html(_n('%d cursus', '%d cursussen', $totalCourses, 'stridence')), $totalCourses); ?>
                            </span>

                            <?php if ($duration): ?>
                                <span class="str-detail__meta-item">
                                    <?php stridence_icon('clock', '', 20); ?>
                                    <?php echo esc_html($duration); ?>
                                </span>
                            <?php endif; ?>

                            <span class="str-detail__meta-item">
                                <?php stridence_icon('award', '', 20); ?>
                                <?php esc_html_e('Trajectcertificaat', 'stridence'); ?>
                            </span>
                        </div>
                    </header>

                    <!-- User Progress (if logged in and enrolled) -->
                    <?php if (is_user_logged_in() && $completedCourses > 0): ?>
                        <div class="str-trajectory-progress">
                            <div class="str-trajectory-progress__header">
                                <span><?php esc_html_e('Jouw voortgang', 'stridence'); ?></span>
                                <span class="str-trajectory-progress__value"><?php echo esc_html($userProgress); ?>%</span>
                            </div>
                            <div class="str-progress str-progress--lg">
                                <div class="str-progress__bar" style="width: <?php echo esc_attr($userProgress); ?>%;"></div>
                            </div>
                            <div class="str-trajectory-progress__detail">
                                <?php printf(
                                    esc_html__('%d van %d cursussen voltooid', 'stridence'),
                                    $completedCourses,
                                    $totalCourses
                                ); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Content -->
                    <div class="str-detail__content">
                        <?php the_content(); ?>
                    </div>

                    <!-- Courses in trajectory -->
                    <?php if (!empty($courses)): ?>
                        <section class="str-trajectory-courses">
                            <h2><?php esc_html_e('Cursussen in dit traject', 'stridence'); ?></h2>
                            <div class="str-trajectory-courses__list">
                                <?php foreach ($courses as $index => $courseId):
                                    $course = get_post($courseId);
                                    if (!$course) continue;

                                    $isCompleted = false;
                                    if (is_user_logged_in() && function_exists('learndash_course_completed')) {
                                        $isCompleted = learndash_course_completed(get_current_user_id(), $courseId);
                                    }
                                ?>
                                    <div class="str-trajectory-course <?php echo $isCompleted ? 'str-trajectory-course--completed' : ''; ?>">
                                        <div class="str-trajectory-course__number">
                                            <?php if ($isCompleted): ?>
                                                <?php stridence_icon('check', '', 20); ?>
                                            <?php else: ?>
                                                <?php echo esc_html($index + 1); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="str-trajectory-course__content">
                                            <h3 class="str-trajectory-course__title">
                                                <a href="<?php echo esc_url(get_permalink($courseId)); ?>">
                                                    <?php echo esc_html($course->post_title); ?>
                                                </a>
                                            </h3>
                                            <?php if ($course->post_excerpt): ?>
                                                <p class="str-trajectory-course__excerpt">
                                                    <?php echo esc_html($course->post_excerpt); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="str-trajectory-course__action">
                                            <?php if ($isCompleted): ?>
                                                <span class="str-badge str-badge--success"><?php esc_html_e('Voltooid', 'stridence'); ?></span>
                                            <?php else: ?>
                                                <a href="<?php echo esc_url(get_permalink($courseId)); ?>" class="str-btn str-btn--ghost str-btn--sm">
                                                    <?php esc_html_e('Bekijk', 'stridence'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <aside class="str-detail__sidebar">
                    <div class="str-sidebar-card">
                        <div class="str-sidebar-card__price">
                            €<?php echo esc_html(number_format((float)$price, 2, ',', '.')); ?>
                            <span class="str-sidebar-card__price-note"><?php esc_html_e('voor het hele traject', 'stridence'); ?></span>
                        </div>

                        <div class="str-sidebar-card__cta">
                            <a href="<?php echo esc_url(home_url('/inschrijven/?trajectory=' . $trajectoryId)); ?>" class="str-btn str-btn--primary str-btn--block str-btn--lg">
                                <?php esc_html_e('Inschrijven voor traject', 'stridence'); ?>
                            </a>
                        </div>

                        <div class="str-sidebar-card__info">
                            <div class="str-sidebar-card__info-item">
                                <?php stridence_icon('book', '', 18); ?>
                                <span><?php printf(esc_html__('%d cursussen', 'stridence'), $totalCourses); ?></span>
                            </div>
                            <?php if ($duration): ?>
                                <div class="str-sidebar-card__info-item">
                                    <?php stridence_icon('clock', '', 18); ?>
                                    <span><?php echo esc_html($duration); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <ul class="str-sidebar-card__features">
                            <?php foreach ($features as $feature): ?>
                                <li class="str-sidebar-card__feature">
                                    <?php stridence_icon('check', '', 18); ?>
                                    <?php echo esc_html($feature); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </aside>
            </div>
        </article>
    </div>
</main>

<?php get_footer(); ?>
