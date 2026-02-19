<?php
/**
 * Course Single Template
 *
 * Custom landing page for LearnDash courses with hero, progress, and lesson accordion.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Course data
$courseId = get_the_ID();
$course = get_post($courseId);
$courseTitle = get_the_title();
$courseDescription = get_the_excerpt() ?: wp_trim_words(get_the_content(), 30);
$thumbnail = get_the_post_thumbnail_url($courseId, 'large');

// User data
$userId = get_current_user_id();
$isLoggedIn = is_user_logged_in();
$hasAccess = $isLoggedIn && sfwd_lms_has_access($courseId, $userId);

// Course meta
$lessons = learndash_get_course_lessons_list($courseId);
$lessonCount = count($lessons);

// Progress (only for enrolled users)
$progress = 0;
$courseStatus = '';
if ($hasAccess) {
    $progressData = learndash_course_progress([
        'user_id' => $userId,
        'course_id' => $courseId,
        'array' => true,
    ]);
    $progress = $progressData['percentage'] ?? 0;
    $courseStatus = learndash_course_status($courseId, $userId);
}

// Certificate
$certificateLink = '';
if ($hasAccess && $progress === 100) {
    $certificateLink = learndash_get_course_certificate_link($courseId, $userId);
}

// Determine CTA
$ctaText = __('Start Cursus', 'stride');
$ctaUrl = '';
if ($hasAccess && $progress > 0 && $progress < 100) {
    $ctaText = __('Doorgaan', 'stride');
} elseif ($progress === 100) {
    $ctaText = __('Bekijk Cursus', 'stride');
}

// Get first lesson or resume point
if (!empty($lessons)) {
    $firstLesson = reset($lessons);
    $ctaUrl = get_permalink($firstLesson['post']->ID ?? $firstLesson->ID);

    // If in progress, find next incomplete lesson
    if ($hasAccess && $progress > 0 && $progress < 100) {
        foreach ($lessons as $lesson) {
            $lessonId = $lesson['post']->ID ?? $lesson->ID;
            if (!learndash_is_lesson_complete($userId, $lessonId, $courseId)) {
                $ctaUrl = get_permalink($lessonId);
                break;
            }
        }
    }
}

// Course type detection (check if has sessions - classroom vs online)
$isOnline = true; // Default to online/e-learning
$editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$editions = $editionService->getEditionsForCourse($courseId);
if (!empty($editions)) {
    $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
    foreach ($editions as $edition) {
        $sessions = $sessionService->getSessionsForEdition($edition['id'] ?? $edition->ID);
        foreach ($sessions as $session) {
            if (in_array($session['type'] ?? '', ['in_person', 'webinar'], true)) {
                $isOnline = false;
                break 2;
            }
        }
    }
}
$courseTypeLabel = $isOnline ? __('E-learning', 'stride') : __('Klassikaal', 'stride');
?>

<div class="stride-course-single">
    <!-- Hero Section -->
    <section class="stride-course-hero">
        <?php if ($thumbnail): ?>
            <div class="stride-course-hero__image">
                <img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr($courseTitle); ?>">
            </div>
        <?php endif; ?>

        <div class="stride-course-hero__content">
            <div class="stride-course-hero__meta">
                <span class="stride-course-hero__badge stride-course-hero__badge--<?php echo $isOnline ? 'online' : 'classroom'; ?>">
                    <?php echo esc_html($courseTypeLabel); ?>
                </span>
                <?php if ($lessonCount > 0): ?>
                    <span class="stride-course-hero__lessons">
                        <span uk-icon="icon: clock; ratio: 0.8"></span>
                        <?php printf(_n('%d les', '%d lessen', $lessonCount, 'stride'), $lessonCount); ?>
                    </span>
                <?php endif; ?>
            </div>

            <h1 class="stride-course-hero__title"><?php echo esc_html($courseTitle); ?></h1>

            <?php if ($courseDescription): ?>
                <p class="stride-course-hero__description"><?php echo esc_html($courseDescription); ?></p>
            <?php endif; ?>

            <?php if ($hasAccess && $progress > 0): ?>
                <!-- Progress Card -->
                <div class="stride-course-progress">
                    <div class="stride-course-progress__header">
                        <span class="stride-course-progress__label"><?php esc_html_e('Je voortgang', 'stride'); ?></span>
                        <span class="stride-course-progress__percentage"><?php echo (int) $progress; ?>%</span>
                    </div>
                    <div class="stride-course-progress__bar">
                        <div class="stride-course-progress__fill" style="width: <?php echo (int) $progress; ?>%;"></div>
                    </div>
                    <?php if ($certificateLink): ?>
                        <a href="<?php echo esc_url($certificateLink); ?>" class="stride-course-progress__certificate" target="_blank">
                            <span uk-icon="icon: file-pdf; ratio: 0.9"></span>
                            <?php esc_html_e('Download Certificaat', 'stride'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($ctaUrl): ?>
                <a href="<?php echo esc_url($ctaUrl); ?>" class="uk-button uk-button-primary uk-button-large stride-course-hero__cta">
                    <span uk-icon="icon: play-circle; ratio: 0.9"></span>
                    <?php echo esc_html($ctaText); ?>
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Content Accordions -->
    <section class="stride-course-content uk-container uk-container-small">
        <ul uk-accordion="multiple: true">
            <!-- Lessons -->
            <li class="uk-open">
                <a class="uk-accordion-title" href>
                    <?php printf(esc_html__('Lessen (%d)', 'stride'), $lessonCount); ?>
                </a>
                <div class="uk-accordion-content">
                    <?php if (!empty($lessons)): ?>
                        <ul class="stride-lesson-list">
                            <?php foreach ($lessons as $index => $lesson):
                                $lessonPost = $lesson['post'] ?? $lesson;
                                $lessonId = $lessonPost->ID ?? $lessonPost;
                                $lessonTitle = is_object($lessonPost) ? $lessonPost->post_title : get_the_title($lessonId);
                                $lessonUrl = get_permalink($lessonId);
                                $isComplete = $hasAccess && learndash_is_lesson_complete($userId, $lessonId, $courseId);
                            ?>
                                <li class="stride-lesson-list__item <?php echo $isComplete ? 'stride-lesson-list__item--complete' : ''; ?>">
                                    <a href="<?php echo esc_url($lessonUrl); ?>">
                                        <span class="stride-lesson-list__status">
                                            <?php if ($isComplete): ?>
                                                <span uk-icon="icon: check; ratio: 0.8"></span>
                                            <?php else: ?>
                                                <span class="stride-lesson-list__number"><?php echo $index + 1; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="stride-lesson-list__title"><?php echo esc_html($lessonTitle); ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="uk-text-muted"><?php esc_html_e('Geen lessen beschikbaar.', 'stride'); ?></p>
                    <?php endif; ?>
                </div>
            </li>

            <!-- What You'll Learn -->
            <?php
            $learningObjectives = get_post_meta($courseId, '_learndash_course_materials', true);
            if (empty($learningObjectives)) {
                $learningObjectives = get_post_meta($courseId, 'course_materials', true);
            }
            if ($learningObjectives):
            ?>
                <li>
                    <a class="uk-accordion-title" href>
                        <?php esc_html_e('Wat je leert', 'stride'); ?>
                    </a>
                    <div class="uk-accordion-content">
                        <div class="stride-course-materials">
                            <?php echo wp_kses_post($learningObjectives); ?>
                        </div>
                    </div>
                </li>
            <?php endif; ?>

            <!-- Certificate -->
            <?php
            $hasCertificate = get_post_meta($courseId, '_sfwd-courses', true);
            $certificateId = $hasCertificate['sfwd-courses_certificate'] ?? 0;
            if ($certificateId):
            ?>
                <li>
                    <a class="uk-accordion-title" href>
                        <?php esc_html_e('Certificaat', 'stride'); ?>
                    </a>
                    <div class="uk-accordion-content">
                        <div class="stride-certificate-info">
                            <span uk-icon="icon: file-pdf; ratio: 1.2"></span>
                            <div>
                                <p><?php esc_html_e('Na succesvolle afronding ontvang je een certificaat.', 'stride'); ?></p>
                                <?php if ($certificateLink): ?>
                                    <a href="<?php echo esc_url($certificateLink); ?>" class="uk-button uk-button-secondary uk-button-small" target="_blank">
                                        <?php esc_html_e('Download Certificaat', 'stride'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </li>
            <?php endif; ?>
        </ul>
    </section>
</div>
