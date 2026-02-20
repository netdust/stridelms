<?php
/**
 * Dashboard Home Template
 *
 * Redesigned dashboard with:
 * - Greeting section
 * - Navigation panel (desktop) / Bottom navbar (mobile)
 * - Continue learning hero
 * - Upcoming sessions
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
$totalCourses = 0;
$includedCourseIds = []; // Track courses already added via editions

// 1. First, add edition-based enrollments (scheduled courses with sessions)
foreach ($enrollments as $enrollment) {
    $editionId = (int) $enrollment->edition_id;
    $edition = $editionService->getEdition($editionId);

    if (is_wp_error($edition)) {
        continue;
    }

    // Get course info
    $courseId = $editionService->getCourseId($editionId);
    if ($courseId) {
        $includedCourseIds[] = $courseId;
    }
    $courseTitle = $courseId ? get_the_title($courseId) : ($edition->post_title ?? __('Onbekende cursus', 'stride'));

    // Get progress data
    $progress = $completionService->getProgress($editionId, $userId);
    $isComplete = $progress['is_complete'] ?? false;
    $percentage = $progress['percentage'] ?? 0;

    // Get session info
    $sessions = $sessionService->getSessionsForEdition($editionId);
    $totalSessions = count($sessions);
    $attendedCount = $attendanceService->countAttended($userId, $editionId);

    $totalCourses++;

    // Determine if online by checking session types
    $isOnline = true;
    foreach ($sessions as $session) {
        $sessionType = $session['type'] ?? 'online';
        if (in_array($sessionType, ['in_person', 'webinar'], true)) {
            $isOnline = false;
            break;
        }
    }

    // Determine URL
    $url = $isOnline && $courseId
        ? get_permalink($courseId)
        : get_permalink($editionId);

    // Get thumbnail
    $thumbnail = $courseId ? get_the_post_thumbnail_url($courseId, 'stride_course_card') : null;

    // Find next session date
    $nextSession = null;
    $today = wp_date('Y-m-d');
    foreach ($sessions as $session) {
        $sessionDate = $session['date'] ?? '';
        if ($sessionDate && $sessionDate >= $today) {
            $nextSession = $session;
            break;
        }
    }

    // Build course data (only non-complete courses for "continue learning")
    if (!$isComplete) {
        $activeCourses[] = [
            'edition_id' => $editionId,
            'course_id' => $courseId,
            'title' => $courseTitle,
            'url' => $url,
            'is_online' => $isOnline,
            'percentage' => $percentage,
            'total_sessions' => $totalSessions,
            'attended' => $attendedCount,
            'thumbnail' => $thumbnail,
            'next_session' => $nextSession,
        ];
    }

    // Collect upcoming sessions
    foreach ($sessions as $session) {
        $sessionDate = $session['date'] ?? '';
        if ($sessionDate && $sessionDate >= $today) {
            $upcomingSessions[] = [
                'session_id' => $session['id'],
                'edition_id' => $editionId,
                'course_title' => $courseTitle,
                'course_url' => $url,
                'date' => $sessionDate,
                'start_time' => $session['start_time'] ?? '',
                'end_time' => $session['end_time'] ?? '',
                'location' => $session['location'] ?? '',
                'is_online' => $isOnline,
            ];
        }
    }
}

// 2. Add LearnDash direct enrollments (online courses without editions)
if (function_exists('learndash_user_get_enrolled_courses')) {
    $ldCourses = learndash_user_get_enrolled_courses($userId);

    foreach ($ldCourses as $courseId) {
        // Skip if already included via edition
        if (in_array($courseId, $includedCourseIds, true)) {
            continue;
        }

        $courseTitle = get_the_title($courseId);
        if (empty($courseTitle)) {
            continue;
        }

        // Skip LTI test courses (test data)
        if (str_starts_with($courseTitle, 'LTI Test Course')) {
            continue;
        }

        // Get LearnDash progress
        $ldProgress = learndash_course_progress([
            'user_id' => $userId,
            'course_id' => $courseId,
            'array' => true,
        ]);
        $percentage = $ldProgress['percentage'] ?? 0;
        $isComplete = ($ldProgress['status'] ?? '') === 'completed' || $percentage >= 100;

        $totalCourses++;

        // Get course thumbnail
        $thumbnail = get_the_post_thumbnail_url($courseId, 'stride_course_card');

        // Build course data (only non-complete courses for "continue learning")
        if (!$isComplete) {
            $activeCourses[] = [
                'edition_id' => null,
                'course_id' => $courseId,
                'title' => $courseTitle,
                'url' => get_permalink($courseId),
                'is_online' => true,
                'percentage' => $percentage,
                'total_sessions' => 0,
                'attended' => 0,
                'thumbnail' => $thumbnail,
                'next_session' => null,
            ];
        }
    }
}

// Sort upcoming sessions by date and limit to 3
usort($upcomingSessions, fn($a, $b) => strcmp($a['date'], $b['date']));
$upcomingSessions = array_slice($upcomingSessions, 0, 3);

// Get the most recent active course for "Continue Learning"
$continueCourse = !empty($activeCourses) ? $activeCourses[0] : null;

// Count active courses
$activeCount = count($activeCourses);

// Dutch month names
$dutchMonths = [
    1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
    7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec'
];
?>

<div class="stride-dashboard-home">
    <div class="stride-dashboard-layout">
        <!-- Greeting Section -->
        <section class="stride-dashboard-layout__greeting stride-greeting">
            <h1 class="stride-greeting__title">
                <?php echo esc_html($greeting . ', ' . $firstName . '!'); ?>
            </h1>
            <p class="stride-greeting__subtitle">
                <?php
                if ($activeCount > 0) {
                    printf(
                        esc_html(_n(
                            'Je hebt %d actieve cursus',
                            'Je hebt %d actieve cursussen',
                            $activeCount,
                            'stride'
                        )),
                        $activeCount
                    );
                } else {
                    esc_html_e('Welkom op je persoonlijke dashboard.', 'stride');
                }
                ?>
            </p>
        </section>

        <!-- Navigation Panel (Desktop) -->
        <div class="stride-dashboard-layout__nav">
            <?php include locate_template('templates/dashboard/partials/nav-panel.php'); ?>
        </div>

        <!-- Continue Learning Hero -->
        <section class="stride-dashboard-layout__hero">
            <?php if ($continueCourse) : ?>
                <div class="stride-continue-hero">
                    <div class="stride-continue-hero__image">
                        <?php if ($continueCourse['thumbnail']) : ?>
                            <img src="<?php echo esc_url($continueCourse['thumbnail']); ?>" alt="<?php echo esc_attr($continueCourse['title']); ?>">
                        <?php else : ?>
                            <span class="stride-continue-hero__placeholder" uk-icon="icon: play-circle; ratio: 3"></span>
                        <?php endif; ?>
                    </div>
                    <div class="stride-continue-hero__body">
                        <h2 class="stride-continue-hero__title"><?php echo esc_html($continueCourse['title']); ?></h2>
                        <div class="stride-continue-hero__meta">
                            <?php if ($continueCourse['next_session']) :
                                $nextDate = strtotime($continueCourse['next_session']['date']);
                                $nextDay = wp_date('j', $nextDate);
                                $nextMonthNum = (int) wp_date('n', $nextDate);
                                $nextMonth = $dutchMonths[$nextMonthNum];
                            ?>
                                <span class="stride-continue-hero__meta-item">
                                    <span uk-icon="icon: calendar; ratio: 0.8"></span>
                                    <?php echo esc_html("Volgende sessie: {$nextDay} {$nextMonth}"); ?>
                                    <?php if ($continueCourse['next_session']['start_time']) : ?>
                                        <?php echo esc_html(', ' . $continueCourse['next_session']['start_time']); ?>
                                    <?php endif; ?>
                                </span>
                            <?php elseif ($continueCourse['is_online']) : ?>
                                <span class="stride-continue-hero__meta-item">
                                    <span uk-icon="icon: laptop; ratio: 0.8"></span>
                                    <?php esc_html_e('Online cursus', 'stride'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="stride-continue-hero__progress">
                            <progress class="uk-progress" value="<?php echo esc_attr($continueCourse['percentage']); ?>" max="100"></progress>
                            <span class="stride-continue-hero__progress-text"><?php echo esc_html($continueCourse['percentage'] . '%'); ?></span>
                        </div>
                        <div class="stride-continue-hero__action">
                            <a href="<?php echo esc_url($continueCourse['url']); ?>" class="uk-button uk-button-primary">
                                <?php esc_html_e('Doorgaan', 'stride'); ?>
                                <span uk-icon="icon: arrow-right"></span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <!-- Empty state: No active courses -->
                <div class="stride-continue-hero stride-continue-hero--empty">
                    <div class="stride-continue-hero__body">
                        <span uk-icon="icon: book; ratio: 2" class="stride-text-muted"></span>
                        <h2 class="stride-continue-hero__title uk-margin-small-top"><?php esc_html_e('Nog geen cursussen', 'stride'); ?></h2>
                        <p class="stride-text-muted"><?php esc_html_e('Ontdek ons aanbod en start met leren.', 'stride'); ?></p>
                        <div class="stride-continue-hero__action">
                            <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary">
                                <?php esc_html_e('Ontdek cursussen', 'stride'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- Upcoming Sessions Section -->
        <section class="stride-dashboard-layout__content">
            <h3 class="uk-h4 uk-margin-medium-bottom"><?php esc_html_e('Aankomende sessies', 'stride'); ?></h3>

            <?php if (!empty($upcomingSessions)) : ?>
                <div class="stride-sessions-grid">
                    <?php foreach ($upcomingSessions as $session) :
                        $date = strtotime($session['date']);
                        $day = wp_date('j', $date);
                        $monthNum = (int) wp_date('n', $date);
                        $month = $dutchMonths[$monthNum];
                    ?>
                        <a href="<?php echo esc_url($session['course_url']); ?>" class="stride-session-card">
                            <div class="stride-session-card__date">
                                <span class="stride-session-card__day"><?php echo esc_html($day); ?></span>
                                <span class="stride-session-card__month"><?php echo esc_html($month); ?></span>
                            </div>
                            <div class="stride-session-card__info">
                                <h4 class="stride-session-card__title"><?php echo esc_html($session['course_title']); ?></h4>
                                <div class="stride-session-card__meta">
                                    <?php if ($session['start_time']) : ?>
                                        <span class="stride-session-card__meta-item">
                                            <span uk-icon="icon: clock; ratio: 0.7"></span>
                                            <?php
                                            echo esc_html($session['start_time']);
                                            if ($session['end_time']) {
                                                echo ' - ' . esc_html($session['end_time']);
                                            }
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($session['is_online']) : ?>
                                        <span class="stride-session-card__badge"><?php esc_html_e('Online', 'stride'); ?></span>
                                    <?php elseif ($session['location']) : ?>
                                        <span class="stride-session-card__meta-item">
                                            <span uk-icon="icon: location; ratio: 0.7"></span>
                                            <?php echo esc_html($session['location']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="stride-text-muted"><?php esc_html_e('Geen sessies gepland', 'stride'); ?></p>
            <?php endif; ?>
        </section>
    </div>
</div>
