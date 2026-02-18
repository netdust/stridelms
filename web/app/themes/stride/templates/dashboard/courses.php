<?php
/**
 * My Courses Template
 *
 * User's enrolled courses with tabs: Active, Completed, All.
 * Displays course cards with progress, session counts, and status.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Services - lazy loaded from DI container
$enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
$completionService = ntdst_get(\Stride\Modules\Completion\CompletionService::class);
$attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);

// Current user
$user = wp_get_current_user();
$userId = $user->ID;

// Get user enrollments (confirmed registrations)
$enrollments = $enrollmentService->getUserEnrollments($userId);

// Build courses data
$allCourses = [];
$activeCourses = [];
$completedCourses = [];

foreach ($enrollments as $enrollment) {
    $editionId = (int) $enrollment->edition_id;
    $edition = $editionService->getEdition($editionId);

    if (is_wp_error($edition)) {
        continue;
    }

    // Get course info
    $courseId = $editionService->getCourseId($editionId);
    $courseTitle = $courseId ? get_the_title($courseId) : ($edition->post_title ?? __('Onbekende cursus', 'stride'));

    // Get progress data
    $progress = $completionService->getProgress($editionId, $userId);
    $isComplete = $progress['is_complete'] ?? false;
    $percentage = $progress['percentage'] ?? 0;

    // Get session info
    $sessions = $sessionService->getSessionsForEdition($editionId);
    $totalSessions = count($sessions);
    $attendedCount = $attendanceService->countAttended($userId, $editionId);

    // Get edition meta
    $startDate = get_post_meta($editionId, '_vad_start_date', true);
    $endDate = get_post_meta($editionId, '_vad_end_date', true);
    $venue = get_post_meta($editionId, '_vad_venue', true);
    $isOnline = get_post_meta($editionId, '_vad_is_online', true);

    // Get course thumbnail
    $thumbnail = $courseId ? get_the_post_thumbnail_url($courseId, 'stride_course_card') : null;

    // Build course data
    $courseData = [
        'edition_id' => $editionId,
        'course_id' => $courseId,
        'title' => $courseTitle,
        'progress' => $progress,
        'is_complete' => $isComplete,
        'percentage' => $percentage,
        'total_sessions' => $totalSessions,
        'attended' => $attendedCount,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'venue' => $venue,
        'is_online' => $isOnline,
        'thumbnail' => $thumbnail,
        'registration_date' => $enrollment->created_at ?? '',
    ];

    $allCourses[] = $courseData;

    if ($isComplete) {
        $completedCourses[] = $courseData;
    } else {
        $activeCourses[] = $courseData;
    }
}

// Sort by start date (most recent first)
usort($allCourses, fn($a, $b) => strcmp($b['start_date'] ?: '0', $a['start_date'] ?: '0'));
usort($activeCourses, fn($a, $b) => strcmp($b['start_date'] ?: '0', $a['start_date'] ?: '0'));
usort($completedCourses, fn($a, $b) => strcmp($b['start_date'] ?: '0', $a['start_date'] ?: '0'));

// Stats
$totalCount = count($allCourses);
$activeCount = count($activeCourses);
$completedCount = count($completedCourses);
?>

<div class="stride-my-courses">
    <!-- Page Header -->
    <header class="stride-page-header">
        <div class="stride-page-header__content">
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="stride-page-header__back">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Dashboard', 'stride'); ?>
            </a>
            <h1 class="stride-page-header__title"><?php esc_html_e('Mijn cursussen', 'stride'); ?></h1>
            <p class="stride-page-header__subtitle">
                <?php
                if ($totalCount > 0) {
                    printf(
                        esc_html(_n(
                            '%d cursus',
                            '%d cursussen',
                            $totalCount,
                            'stride'
                        )),
                        $totalCount
                    );
                } else {
                    esc_html_e('Je hebt nog geen cursussen.', 'stride');
                }
                ?>
            </p>
        </div>
    </header>

    <?php if ($totalCount > 0) : ?>
        <!-- Tabs -->
        <ul class="uk-subnav uk-subnav-pill stride-tabs" uk-switcher="animation: uk-animation-fade">
            <li class="uk-active">
                <a href="#">
                    <?php esc_html_e('Actief', 'stride'); ?>
                    <span class="stride-tabs__count"><?php echo esc_html($activeCount); ?></span>
                </a>
            </li>
            <li>
                <a href="#">
                    <?php esc_html_e('Voltooid', 'stride'); ?>
                    <span class="stride-tabs__count"><?php echo esc_html($completedCount); ?></span>
                </a>
            </li>
            <li>
                <a href="#">
                    <?php esc_html_e('Alle', 'stride'); ?>
                    <span class="stride-tabs__count"><?php echo esc_html($totalCount); ?></span>
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <ul class="uk-switcher uk-margin-medium-top">
            <!-- Active Courses Tab -->
            <li>
                <?php if (!empty($activeCourses)) : ?>
                    <div class="uk-grid uk-grid-match uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-3@l" uk-grid>
                        <?php foreach ($activeCourses as $course) : ?>
                            <?php stride_render_course_card($course); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="stride-empty-state stride-empty-state--compact">
                        <div class="stride-empty-state__icon">
                            <span uk-icon="icon: check; ratio: 1.5"></span>
                        </div>
                        <h3 class="stride-empty-state__title"><?php esc_html_e('Geen actieve cursussen', 'stride'); ?></h3>
                        <p class="stride-empty-state__description">
                            <?php esc_html_e('Je hebt momenteel geen lopende cursussen.', 'stride'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </li>

            <!-- Completed Courses Tab -->
            <li>
                <?php if (!empty($completedCourses)) : ?>
                    <div class="uk-grid uk-grid-match uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-3@l" uk-grid>
                        <?php foreach ($completedCourses as $course) : ?>
                            <?php stride_render_course_card($course); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="stride-empty-state stride-empty-state--compact">
                        <div class="stride-empty-state__icon">
                            <span uk-icon="icon: star; ratio: 1.5"></span>
                        </div>
                        <h3 class="stride-empty-state__title"><?php esc_html_e('Nog geen voltooide cursussen', 'stride'); ?></h3>
                        <p class="stride-empty-state__description">
                            <?php esc_html_e('Voltooi je eerste cursus om hier terug te komen.', 'stride'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </li>

            <!-- All Courses Tab -->
            <li>
                <div class="uk-grid uk-grid-match uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-3@l" uk-grid>
                    <?php foreach ($allCourses as $course) : ?>
                        <?php stride_render_course_card($course); ?>
                    <?php endforeach; ?>
                </div>
            </li>
        </ul>

    <?php else : ?>
        <!-- Empty State -->
        <section class="stride-empty-state uk-margin-large-top">
            <div class="stride-empty-state__icon">
                <span uk-icon="icon: book; ratio: 2"></span>
            </div>
            <h2 class="stride-empty-state__title"><?php esc_html_e('Nog geen cursussen', 'stride'); ?></h2>
            <p class="stride-empty-state__description">
                <?php esc_html_e('Je bent nog niet ingeschreven voor een cursus. Ontdek ons aanbod en start met leren.', 'stride'); ?>
            </p>
            <div class="stride-empty-state__action">
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary uk-button-large">
                    <?php esc_html_e('Ontdek cursussen', 'stride'); ?>
                </a>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php
/**
 * Render a course card
 */
function stride_render_course_card(array $course): void
{
    ?>
    <div>
        <div class="stride-course-card">
            <div class="stride-course-card__image">
                <?php if ($course['thumbnail']) : ?>
                    <img src="<?php echo esc_url($course['thumbnail']); ?>" alt="<?php echo esc_attr($course['title']); ?>">
                <?php else : ?>
                    <div class="stride-course-placeholder">
                        <span uk-icon="icon: album; ratio: 2" class="stride-course-placeholder__icon"></span>
                    </div>
                <?php endif; ?>

                <?php if ($course['is_complete']) : ?>
                    <span class="stride-course-card__badge uk-label uk-label-success">
                        <?php esc_html_e('Voltooid', 'stride'); ?>
                    </span>
                <?php elseif ($course['is_online']) : ?>
                    <span class="stride-course-card__badge stride-label-soft-primary">
                        <?php esc_html_e('Online', 'stride'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="stride-course-card__body">
                <h3 class="stride-course-card__title">
                    <?php if ($course['course_id']) : ?>
                        <a href="<?php echo esc_url(get_permalink($course['course_id'])); ?>">
                            <?php echo esc_html($course['title']); ?>
                        </a>
                    <?php else : ?>
                        <?php echo esc_html($course['title']); ?>
                    <?php endif; ?>
                </h3>

                <div class="stride-course-card__meta">
                    <?php if ($course['total_sessions'] > 0) : ?>
                        <span class="stride-course-card__meta-item">
                            <span uk-icon="icon: calendar; ratio: 0.8"></span>
                            <?php
                            printf(
                                esc_html__('%d van %d sessies', 'stride'),
                                $course['attended'],
                                $course['total_sessions']
                            );
                            ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($course['start_date']) : ?>
                        <span class="stride-course-card__meta-item">
                            <span uk-icon="icon: clock; ratio: 0.8"></span>
                            <?php echo esc_html(date_i18n('j M Y', strtotime($course['start_date']))); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!$course['is_complete']) : ?>
                    <div class="stride-course-card__progress">
                        <progress class="uk-progress" value="<?php echo esc_attr($course['percentage']); ?>" max="100"></progress>
                        <span class="uk-text-small stride-text-muted">
                            <?php echo esc_html($course['percentage'] . '%'); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($course['course_id']) : ?>
                <div class="stride-course-card__footer">
                    <?php if ($course['is_complete']) : ?>
                        <a href="<?php echo esc_url(get_permalink($course['course_id'])); ?>" class="uk-button uk-button-default uk-button-small">
                            <?php esc_html_e('Bekijken', 'stride'); ?>
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url(get_permalink($course['course_id'])); ?>" class="uk-button uk-button-primary uk-button-small">
                            <?php esc_html_e('Doorgaan', 'stride'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
