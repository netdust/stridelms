<?php
/**
 * Dashboard Home Template
 *
 * User dashboard home page with greeting, progress, upcoming sessions, and active courses.
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
$firstName = $user->first_name ?: $user->display_name;

// Time-based greeting
$hour = (int) wp_date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = __('Goedemorgen', 'stride');
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = __('Goedemiddag', 'stride');
} else {
    $greeting = __('Goedenavond', 'stride');
}

// Get user enrollments (confirmed registrations)
$enrollments = $enrollmentService->getUserEnrollments($userId);

// Build active courses list with progress data
$activeCourses = [];
$upcomingSessions = [];
$totalProgress = 0;
$totalCourses = 0;
$completedCourses = 0;

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

    // Track overall stats
    $totalCourses++;
    $totalProgress += $percentage;
    if ($isComplete) {
        $completedCourses++;
    }

    // Build course data
    $activeCourses[] = [
        'edition_id' => $editionId,
        'course_id' => $courseId,
        'title' => $courseTitle,
        'progress' => $progress,
        'is_complete' => $isComplete,
        'percentage' => $percentage,
        'total_sessions' => $totalSessions,
        'attended' => $attendedCount,
        'start_date' => $edition instanceof \WP_Post
            ? get_post_meta($editionId, '_vad_start_date', true)
            : ($edition['start_date'] ?? ''),
    ];

    // Collect upcoming sessions
    $today = wp_date('Y-m-d');
    foreach ($sessions as $session) {
        $sessionDate = $session['date'] ?? '';
        if ($sessionDate && $sessionDate >= $today) {
            $upcomingSessions[] = [
                'session_id' => $session['id'],
                'edition_id' => $editionId,
                'course_title' => $courseTitle,
                'date' => $sessionDate,
                'start_time' => $session['start_time'] ?? '',
                'end_time' => $session['end_time'] ?? '',
                'location' => $session['location'] ?? '',
            ];
        }
    }
}

// Sort upcoming sessions by date
usort($upcomingSessions, fn($a, $b) => strcmp($a['date'], $b['date']));

// Limit to next 3 sessions
$upcomingSessions = array_slice($upcomingSessions, 0, 3);

// Calculate average progress
$averageProgress = $totalCourses > 0 ? round($totalProgress / $totalCourses, 1) : 0;

// Dutch month names for date formatting
$dutchMonths = [
    1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
    7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec'
];
?>

<div class="stride-dashboard-home">
    <!-- Greeting Section -->
    <section class="stride-greeting">
        <h1 class="stride-greeting__title">
            <?php echo esc_html($greeting . ', ' . $firstName . '!'); ?>
        </h1>
        <?php if ($totalCourses > 0) : ?>
            <p class="stride-greeting__subtitle">
                <?php
                printf(
                    esc_html(_n(
                        'Je volgt momenteel %d cursus.',
                        'Je volgt momenteel %d cursussen.',
                        $totalCourses,
                        'stride'
                    )),
                    $totalCourses
                );
                ?>
            </p>
        <?php else : ?>
            <p class="stride-greeting__subtitle">
                <?php esc_html_e('Welkom op je persoonlijke dashboard.', 'stride'); ?>
            </p>
        <?php endif; ?>
    </section>

    <?php if ($totalCourses > 0) : ?>
        <!-- Progress & Upcoming Grid -->
        <div class="uk-grid uk-grid-match uk-child-width-1-1 uk-child-width-1-2@m" uk-grid>
            <!-- Progress Card -->
            <div>
                <div class="stride-progress-card">
                    <div class="stride-progress-card__header">
                        <div>
                            <p class="stride-progress-card__title"><?php esc_html_e('Totale voortgang', 'stride'); ?></p>
                            <p class="stride-progress-card__value"><?php echo esc_html($averageProgress . '%'); ?></p>
                        </div>
                        <div class="stride-progress-card__icon">
                            <span uk-icon="icon: star; ratio: 1.5"></span>
                        </div>
                    </div>
                    <div class="stride-progress-card__bar">
                        <div class="stride-progress-card__bar-fill" style="width: <?php echo esc_attr($averageProgress); ?>%"></div>
                    </div>
                    <p class="stride-progress-card__footer">
                        <?php
                        printf(
                            esc_html(_n(
                                '%d van %d cursus voltooid',
                                '%d van %d cursussen voltooid',
                                $totalCourses,
                                'stride'
                            )),
                            $completedCourses,
                            $totalCourses
                        );
                        ?>
                    </p>
                </div>
            </div>

            <!-- Upcoming Sessions Card -->
            <div>
                <div class="uk-card uk-card-default">
                    <div class="uk-card-header">
                        <h3 class="uk-card-title uk-margin-remove">
                            <?php esc_html_e('Komende sessies', 'stride'); ?>
                        </h3>
                    </div>
                    <div class="uk-card-body uk-padding-remove">
                        <?php if (!empty($upcomingSessions)) : ?>
                            <div class="uk-padding-small uk-padding-remove-horizontal">
                                <?php foreach ($upcomingSessions as $session) :
                                    $date = strtotime($session['date']);
                                    $day = date('j', $date);
                                    $monthNum = (int) date('n', $date);
                                    $month = $dutchMonths[$monthNum];
                                ?>
                                    <div class="stride-session-item uk-padding-small">
                                        <div class="stride-session-date">
                                            <span class="stride-session-date__day"><?php echo esc_html($day); ?></span>
                                            <span class="stride-session-date__month"><?php echo esc_html($month); ?></span>
                                        </div>
                                        <div class="stride-session-info">
                                            <h4 class="stride-session-info__title"><?php echo esc_html($session['course_title']); ?></h4>
                                            <div class="stride-session-info__meta">
                                                <?php if ($session['start_time']) : ?>
                                                    <span class="stride-session-info__meta-item">
                                                        <span uk-icon="icon: clock; ratio: 0.8"></span>
                                                        <?php
                                                        echo esc_html($session['start_time']);
                                                        if ($session['end_time']) {
                                                            echo ' - ' . esc_html($session['end_time']);
                                                        }
                                                        ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($session['location']) : ?>
                                                    <span class="stride-session-info__meta-item">
                                                        <span uk-icon="icon: location; ratio: 0.8"></span>
                                                        <?php echo esc_html($session['location']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div class="uk-padding-small uk-text-center stride-text-muted">
                                <span uk-icon="icon: calendar; ratio: 1.5" class="uk-margin-small-bottom uk-display-block"></span>
                                <p class="uk-margin-remove"><?php esc_html_e('Geen komende sessies gepland.', 'stride'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($upcomingSessions)) : ?>
                        <div class="uk-card-footer">
                            <a href="<?php echo esc_url(home_url('/mijn-account/kalender/')); ?>" class="uk-button uk-button-text">
                                <?php esc_html_e('Bekijk volledige agenda', 'stride'); ?>
                                <span uk-icon="icon: arrow-right"></span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Active Courses Section -->
        <section class="uk-margin-large-top">
            <div class="stride-section-title">
                <span><?php esc_html_e('Mijn cursussen', 'stride'); ?></span>
                <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>" class="stride-section-title__link">
                    <?php esc_html_e('Alles bekijken', 'stride'); ?>
                </a>
            </div>

            <div class="uk-grid uk-grid-match uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-3@l" uk-grid>
                <?php foreach (array_slice($activeCourses, 0, 6) as $course) : ?>
                    <div>
                        <div class="stride-course-card">
                            <div class="stride-course-card__image">
                                <?php
                                $thumbnail = $course['course_id'] ? get_the_post_thumbnail_url($course['course_id'], 'stride_course_card') : null;
                                if ($thumbnail) :
                                ?>
                                    <img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr($course['title']); ?>">
                                <?php else : ?>
                                    <div class="stride-course-placeholder">
                                        <span uk-icon="icon: album; ratio: 2" class="stride-course-placeholder__icon"></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($course['is_complete']) : ?>
                                    <span class="stride-course-card__badge uk-label uk-label-success">
                                        <?php esc_html_e('Voltooid', 'stride'); ?>
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
                                </div>
                                <div class="stride-course-card__progress">
                                    <progress class="uk-progress" value="<?php echo esc_attr($course['percentage']); ?>" max="100"></progress>
                                    <span class="uk-text-small stride-text-muted">
                                        <?php echo esc_html($course['percentage'] . '%'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

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
