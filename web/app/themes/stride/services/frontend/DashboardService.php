<?php

namespace stride\services\frontend;

defined('ABSPATH') || exit;

use ntdst\Stride\core\CourseService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\core\TrajectoryService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\core\TrajectoryEnrollmentRepository;
use ntdst\Stride\core\ProgressEngine;
use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\sync\UserDataSync;
use ntdst\Stride\FieldRegistry;
use WP_Error;
use WP_Post;

/**
 * Dashboard Service
 *
 * Provides aggregated data for user dashboard templates.
 * Orchestrates data from Edition/Session/Course services to provide
 * a unified dashboard experience.
 *
 * Phase 6 Refactor: Now uses Edition model for scheduled offerings
 * - User enrolls in EDITIONS (not courses directly)
 * - Upcoming dates come from SESSIONS (meeting days)
 * - Trajectories use TrajectoryService/ProgressEngine
 *
 * @package stride\services\frontend
 */
class DashboardService implements \NTDST_Service_Meta
{
    private ?CourseService $courseService;
    private ?EditionService $editionService;
    private ?SessionService $sessionService;
    private ?TrajectoryService $trajectoryService;
    private ?RegistrationRepository $registrationRepo;
    private ?TrajectoryEnrollmentRepository $trajectoryEnrollmentRepo;
    private ?ProgressEngine $progressEngine;
    private ?QuoteService $quoteService;
    private ?UserDataSync $userDataSync;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Dashboard Service',
            'description' => 'User dashboard data orchestration',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 15,
        ];
    }

    /**
     * Constructor
     */
    public function __construct(
        ?CourseService $courseService = null,
        ?EditionService $editionService = null,
        ?SessionService $sessionService = null,
        ?TrajectoryService $trajectoryService = null,
        ?RegistrationRepository $registrationRepo = null,
        ?TrajectoryEnrollmentRepository $trajectoryEnrollmentRepo = null,
        ?ProgressEngine $progressEngine = null,
        ?QuoteService $quoteService = null,
        ?UserDataSync $userDataSync = null
    ) {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->editionService = $editionService ?? $this->resolveService(EditionService::class);
        $this->sessionService = $sessionService ?? $this->resolveService(SessionService::class);
        $this->trajectoryService = $trajectoryService ?? $this->resolveService(TrajectoryService::class);
        $this->registrationRepo = $registrationRepo ?? $this->resolveService(RegistrationRepository::class);
        $this->trajectoryEnrollmentRepo = $trajectoryEnrollmentRepo ?? new TrajectoryEnrollmentRepository();
        $this->progressEngine = $progressEngine ?? $this->resolveService(ProgressEngine::class);
        $this->quoteService = $quoteService ?? $this->resolveService(QuoteService::class);
        $this->userDataSync = $userDataSync ?? $this->resolveService(UserDataSync::class);

        add_action('init', [$this, 'registerAjaxHandlers'], 20);
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): ?object
    {
        if (function_exists('ntdst_get')) {
            try {
                return ntdst_get($class);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Register AJAX handlers
     */
    public function registerAjaxHandlers(): void
    {
        add_action('wp_ajax_stride_update_profile', [$this, 'handleProfileUpdate']);
        add_action('wp_ajax_stride_download_ical', [$this, 'handleIcalDownload']);
    }

    // ========================================
    // USER EDITION REGISTRATIONS
    // ========================================

    /**
     * Get all editions/courses for a user with enriched data
     *
     * Now queries from RegistrationRepository (edition enrollments)
     * and enriches with edition/course data.
     *
     * @param int $userId
     * @param array $filters Optional filters: type (online|in-person), status (enrolled|completed|in_progress)
     * @return array Array of edition/course data
     */
    public function getUserCourses(int $userId, array $filters = []): array
    {
        if (!$this->registrationRepo || !$this->editionService) {
            return [];
        }

        // Get user's edition registrations (confirmed only)
        $registrations = $this->registrationRepo->getByUser($userId, RegistrationRepository::STATUS_CONFIRMED);

        if (empty($registrations)) {
            return [];
        }

        $courses = [];

        foreach ($registrations as $registration) {
            $editionId = $registration['edition_id'];
            $courseData = $this->buildEditionCourseData($editionId, $userId, $registration);

            if ($courseData === null) {
                continue;
            }

            // Apply filters
            if (!empty($filters['type'])) {
                if ($filters['type'] === 'online' && !$courseData['is_online']) {
                    continue;
                }
                if ($filters['type'] === 'in-person' && !$courseData['is_in_person']) {
                    continue;
                }
            }

            if (!empty($filters['status'])) {
                if ($filters['status'] !== $courseData['status']) {
                    continue;
                }
            }

            $courses[] = $courseData;
        }

        // Sort by status (in_progress first, then enrolled, then completed)
        usort($courses, function ($a, $b) {
            $statusOrder = ['in_progress' => 0, 'enrolled' => 1, 'completed' => 2];
            $aOrder = $statusOrder[$a['status']] ?? 3;
            $bOrder = $statusOrder[$b['status']] ?? 3;

            if ($aOrder !== $bOrder) {
                return $aOrder - $bOrder;
            }

            // Secondary sort by next date
            return ($a['next_date'] ?? PHP_INT_MAX) - ($b['next_date'] ?? PHP_INT_MAX);
        });

        return $courses;
    }

    /**
     * Build course data array from edition registration
     *
     * @param int $editionId Edition post ID
     * @param int $userId User ID
     * @param array $registration Registration data
     * @return array|null Course data or null if invalid
     */
    private function buildEditionCourseData(int $editionId, int $userId, array $registration): ?array
    {
        $edition = $this->editionService->getEdition($editionId);
        if (!$edition) {
            return null;
        }

        $courseId = $edition['course_id'];
        $course = $this->courseService ? $this->courseService->getCourse($courseId) : get_post($courseId);
        if (!$course) {
            return null;
        }

        // Determine if in-person or online based on edition venue
        $isInPerson = !empty($edition['venue']);
        $isOnline = !$isInPerson;

        // Check completion via CourseService (LearnDash)
        $isCompleted = $this->courseService && $this->courseService->isUserCompleted($userId, $courseId);

        // Check if edition has started
        $hasStarted = $this->editionService->hasStarted($editionId);

        // Determine status
        $status = 'enrolled';
        if ($isCompleted) {
            $status = 'completed';
        } elseif ($hasStarted && !$isCompleted) {
            $status = 'in_progress';
        }

        // Get progress for online courses
        $progress = 0;
        if ($isOnline && function_exists('learndash_course_progress')) {
            $progressData = learndash_course_progress([
                'course_id' => $courseId,
                'user_id' => $userId,
            ]);
            $progress = $progressData['percentage'] ?? 0;
        }

        // Get sessions for this edition
        $sessions = $this->sessionService ? $this->sessionService->getSessionsForEdition($editionId) : [];
        $sessionDates = array_map(function ($s) {
            return strtotime($s['date']);
        }, $sessions);
        sort($sessionDates);

        // Find next date (first future session)
        $now = time();
        $nextDate = null;
        foreach ($sessionDates as $date) {
            if ($date > $now) {
                $nextDate = $date;
                break;
            }
        }

        // If no sessions, use edition start date
        if (empty($sessionDates) && $edition['start_date']) {
            $sessionDates = [strtotime($edition['start_date'])];
            $nextDate = strtotime($edition['start_date']) > $now ? strtotime($edition['start_date']) : null;
        }

        return [
            'id' => $courseId, // Course ID for linking to LearnDash course
            'edition_id' => $editionId,
            'title' => $course->post_title ?? $edition['title'],
            'excerpt' => get_the_excerpt($course),
            'permalink' => get_permalink($courseId),
            'thumbnail' => get_the_post_thumbnail_url($courseId, 'stride_course_card'),
            'is_online' => $isOnline,
            'is_in_person' => $isInPerson,
            'is_trajectory' => false, // Trajectories are separate
            'status' => $status,
            'progress' => $progress,
            'start_date' => $edition['start_date'] ? strtotime($edition['start_date']) : null,
            'next_date' => $nextDate,
            'dates' => $sessionDates,
            'location' => $edition['venue'],
            'certificate_link' => $isCompleted && $this->courseService
                ? $this->courseService->getCertificateLink($userId, $courseId)
                : null,
            'speakers' => $this->editionService->getSpeakers($editionId),
            'registration' => $registration,
        ];
    }

    /**
     * Get enrolled course IDs for a user (LearnDash fallback for legacy)
     */
    private function getEnrolledCourseIds(int $userId): array
    {
        if (!function_exists('ld_get_mycourses')) {
            return [];
        }

        return ld_get_mycourses($userId) ?: [];
    }

    // ========================================
    // TRAJECTORIES
    // ========================================

    /**
     * Get user's trajectories with progress
     *
     * Uses TrajectoryService and TrajectoryEnrollmentRepository
     * instead of LearnDash groups.
     *
     * @param int $userId
     * @return array
     */
    public function getUserTrajectories(int $userId): array
    {
        if (!$this->trajectoryEnrollmentRepo || !$this->trajectoryService) {
            return [];
        }

        // Get user's trajectory enrollments
        $enrollments = $this->trajectoryEnrollmentRepo->getByUser($userId);

        if (empty($enrollments)) {
            return [];
        }

        $trajectories = [];

        foreach ($enrollments as $enrollment) {
            $trajectoryId = $enrollment['trajectory_id'];
            $trajectory = $this->trajectoryService->getTrajectory($trajectoryId);

            if (!$trajectory) {
                continue;
            }

            // Get progress from ProgressEngine
            $progress = $this->progressEngine
                ? $this->progressEngine->getProgress($trajectoryId, $userId)
                : ['percentage' => 0, 'is_complete' => false];

            $courses = $trajectory['courses'] ?? [];
            $totalCourses = 0;
            $completedCourses = 0;
            $modules = [];

            // Build modules list from trajectory courses
            foreach ($courses as $course) {
                $courseId = $course['course_id'] ?? 0;
                if (!$courseId) {
                    continue;
                }

                $totalCourses++;
                $isCompleted = in_array($courseId, $progress['completed_courses'] ?? [], true);
                if ($isCompleted) {
                    $completedCourses++;
                }

                // Get next session date for this course
                $nextDate = $this->getNextSessionDateForCourse($courseId, $trajectory);

                $modules[] = [
                    'id' => $courseId,
                    'title' => get_the_title($courseId),
                    'group' => $course['group'] ?? 'Modules',
                    'status' => $this->getModuleStatus($courseId, $userId, $progress),
                    'is_completed' => $isCompleted,
                    'next_date' => $nextDate,
                ];
            }

            $progressPercent = $totalCourses > 0
                ? round(($completedCourses / $totalCourses) * 100)
                : 0;

            $trajectories[] = [
                'id' => $trajectoryId,
                'title' => $trajectory['title'],
                'excerpt' => $trajectory['description'] ?? '',
                'permalink' => get_permalink($trajectoryId),
                'mode' => $trajectory['mode'] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED,
                'total_modules' => $totalCourses,
                'completed_modules' => $completedCourses,
                'progress' => $progressPercent,
                'modules' => $modules,
                'current_module' => $this->getCurrentModule($modules),
                'enrollment' => $enrollment,
            ];
        }

        return $trajectories;
    }

    /**
     * Get next session date for a course in a trajectory
     *
     * For cohort mode: looks at linked editions
     * For self-paced: looks at next available edition
     */
    private function getNextSessionDateForCourse(int $courseId, array $trajectory): ?int
    {
        // For cohort trajectories, check linked editions
        $linkedEditions = $trajectory['linked_editions'] ?? [];
        $editionId = null;

        foreach ($linkedEditions as $link) {
            if (($link['course_id'] ?? 0) == $courseId) {
                $editionId = $link['edition_id'] ?? 0;
                break;
            }
        }

        // If no linked edition, find next available edition for this course
        if (!$editionId && $this->editionService) {
            $upcomingEditions = $this->editionService->getUpcomingEditionsForCourse($courseId, 1);
            if (!empty($upcomingEditions)) {
                $editionId = $upcomingEditions[0]['id'];
            }
        }

        if (!$editionId || !$this->sessionService) {
            return null;
        }

        // Get first session date
        $sessions = $this->sessionService->getSessionsForEdition($editionId);
        if (empty($sessions)) {
            // Fall back to edition start date
            $startDate = $this->editionService->getStartDate($editionId);
            return $startDate ? strtotime($startDate) : null;
        }

        $now = time();
        foreach ($sessions as $session) {
            $sessionDate = strtotime($session['date']);
            if ($sessionDate > $now) {
                return $sessionDate;
            }
        }

        return null;
    }

    /**
     * Get single trajectory with full journey data
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $userId
     * @return array|null
     */
    public function getTrajectory(int $trajectoryId, int $userId): ?array
    {
        if (!$this->trajectoryService || !$this->progressEngine) {
            return null;
        }

        $trajectory = $this->trajectoryService->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return null;
        }

        $progress = $this->progressEngine->getProgress($trajectoryId, $userId);
        $courses = $trajectory['courses'] ?? [];

        $mandatoryModules = [];
        $electiveModules = [];
        $completedCount = 0;
        $currentModuleIndex = -1;

        // Group courses by type (mandatory vs elective)
        $coursesByGroup = [];
        foreach ($courses as $course) {
            $group = $course['group'] ?? 'Modules';
            if (!isset($coursesByGroup[$group])) {
                $coursesByGroup[$group] = [
                    'courses' => [],
                    'pick_count' => $course['pick_count'] ?? null,
                ];
            }
            $coursesByGroup[$group]['courses'][] = $course;
        }

        foreach ($courses as $index => $course) {
            $courseId = $course['course_id'] ?? 0;
            if (!$courseId) {
                continue;
            }

            $isCompleted = in_array($courseId, $progress['completed_courses'] ?? [], true);
            $group = $course['group'] ?? 'Modules';
            $isElective = isset($coursesByGroup[$group]['pick_count']);

            if ($isCompleted) {
                $completedCount++;
            }

            $moduleData = [
                'id' => $courseId,
                'title' => get_the_title($courseId),
                'permalink' => get_permalink($courseId),
                'group' => $group,
                'status' => $this->getModuleStatus($courseId, $userId, $progress),
                'is_completed' => $isCompleted,
                'next_date' => $this->getNextSessionDateForCourse($courseId, $trajectory),
                'location' => $this->getModuleLocation($courseId, $trajectory),
            ];

            if ($isElective) {
                $electiveModules[] = $moduleData;
            } else {
                $mandatoryModules[] = $moduleData;

                // Track current module (first incomplete mandatory)
                if (!$isCompleted && $currentModuleIndex === -1) {
                    $currentModuleIndex = count($mandatoryModules) - 1;
                }
            }
        }

        $totalCount = count($courses);
        $progressPercent = $totalCount > 0
            ? round(($completedCount / $totalCount) * 100)
            : 0;

        // Get next upcoming session
        $nextSession = null;
        foreach ($mandatoryModules as $module) {
            if (!$module['is_completed'] && $module['next_date']) {
                $nextSession = $module;
                break;
            }
        }

        return [
            'id' => $trajectoryId,
            'title' => $trajectory['title'],
            'description' => apply_filters('the_content', $trajectory['description'] ?? ''),
            'mode' => $trajectory['mode'] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED,
            'progress' => $progressPercent,
            'mandatory_modules' => $mandatoryModules,
            'elective_modules' => $electiveModules,
            'current_module_index' => $currentModuleIndex,
            'next_session' => $nextSession,
            'completed_count' => $completedCount,
            'total_count' => $totalCount,
        ];
    }

    /**
     * Get module location from linked edition or upcoming edition
     */
    private function getModuleLocation(int $courseId, array $trajectory): ?string
    {
        // Check linked editions first (cohort mode)
        $linkedEditions = $trajectory['linked_editions'] ?? [];
        $editionId = null;

        foreach ($linkedEditions as $link) {
            if (($link['course_id'] ?? 0) == $courseId) {
                $editionId = $link['edition_id'] ?? 0;
                break;
            }
        }

        if ($editionId && $this->editionService) {
            return $this->editionService->getVenue($editionId);
        }

        return null;
    }

    /**
     * Get module status string
     */
    private function getModuleStatus(int $courseId, int $userId, array $progress = []): string
    {
        $completedCourses = $progress['completed_courses'] ?? [];

        if (in_array($courseId, $completedCourses, true)) {
            return 'completed';
        }

        // Check if user has started this course via LearnDash
        if ($this->courseService && $this->courseService->hasStarted($courseId)) {
            return 'current';
        }

        return 'locked';
    }

    /**
     * Find current module from modules array
     */
    private function getCurrentModule(array $modules): ?array
    {
        foreach ($modules as $module) {
            if ($module['status'] === 'current') {
                return $module;
            }
        }

        // If none in progress, find first incomplete
        foreach ($modules as $module) {
            if (!$module['is_completed']) {
                return $module;
            }
        }

        return null;
    }

    // ========================================
    // UPCOMING DATES
    // ========================================

    /**
     * Get upcoming session dates for user
     *
     * Now queries sessions from user's registered editions.
     *
     * @param int $userId
     * @param int $limit Maximum dates to return
     * @return array
     */
    public function getUpcomingDates(int $userId, int $limit = 3): array
    {
        if (!$this->registrationRepo || !$this->sessionService) {
            return [];
        }

        // Get user's active registrations
        $registrations = $this->registrationRepo->getByUser($userId, RegistrationRepository::STATUS_CONFIRMED);

        if (empty($registrations)) {
            return [];
        }

        $upcomingDates = [];
        $now = time();

        foreach ($registrations as $registration) {
            $editionId = $registration['edition_id'];
            $edition = $this->editionService ? $this->editionService->getEdition($editionId) : null;

            if (!$edition) {
                continue;
            }

            $courseId = $edition['course_id'];
            $courseTitle = get_the_title($courseId) ?: $edition['title'];

            // Get sessions for this edition
            $sessions = $this->sessionService->getSessionsForEdition($editionId);

            if (empty($sessions)) {
                // Fall back to edition start date if no sessions
                $startTimestamp = $edition['start_date'] ? strtotime($edition['start_date']) : null;
                if ($startTimestamp && $startTimestamp > $now) {
                    $upcomingDates[] = [
                        'timestamp' => $startTimestamp,
                        'course_id' => $courseId,
                        'edition_id' => $editionId,
                        'session_id' => null,
                        'course_title' => $courseTitle,
                        'location' => $edition['venue'],
                        'day' => date_i18n('j', $startTimestamp),
                        'month' => date_i18n('M', $startTimestamp),
                        'full_date' => date_i18n('j F Y', $startTimestamp),
                        'time' => null,
                    ];
                }
                continue;
            }

            foreach ($sessions as $session) {
                $sessionDate = $session['date'];
                $timestamp = strtotime($sessionDate);

                // Only future dates
                if ($timestamp > $now) {
                    $upcomingDates[] = [
                        'timestamp' => $timestamp,
                        'course_id' => $courseId,
                        'edition_id' => $editionId,
                        'session_id' => $session['id'],
                        'course_title' => $courseTitle,
                        'location' => $session['location'] ?: $edition['venue'],
                        'day' => date_i18n('j', $timestamp),
                        'month' => date_i18n('M', $timestamp),
                        'full_date' => date_i18n('j F Y', $timestamp),
                        'time' => $session['start_time'] ?: null,
                    ];
                }
            }
        }

        // Sort by date
        usort($upcomingDates, fn($a, $b) => $a['timestamp'] - $b['timestamp']);

        return array_slice($upcomingDates, 0, $limit);
    }

    // ========================================
    // RECENT ACTIVITY
    // ========================================

    /**
     * Get recent activity for user
     *
     * @param int $userId
     * @param int $limit Maximum activities to return
     * @return array
     */
    public function getRecentActivity(int $userId, int $limit = 5): array
    {
        $activities = [];

        // Get recent enrollments from user activity table using reports API
        if (function_exists('learndash_reports_get_activity')) {
            $rawActivities = learndash_reports_get_activity([
                'user_ids' => $userId,
                'activity_types' => ['course', 'lesson', 'topic', 'quiz'],
                'activity_status' => ['IN_PROGRESS', 'COMPLETED'],
                'orderby_order' => 'GREATEST(ld_user_activity.activity_started, ld_user_activity.activity_completed) DESC',
                'per_page' => $limit * 2,
            ]);

            if (!empty($rawActivities['results'])) {
                foreach ($rawActivities['results'] as $activity) {
                    $timestamp = $activity->activity_started ?? $activity->activity_completed ?? 0;
                    $courseId = $activity->course_id ?? $activity->post_id ?? 0;

                    if (!$courseId || !$timestamp) {
                        continue;
                    }

                    $activityType = $activity->activity_type ?? 'course';
                    $icon = $this->getActivityIcon($activityType);
                    $message = $this->getActivityMessage($activityType, $courseId);

                    $activities[] = [
                        'type' => $activityType,
                        'icon' => $icon,
                        'message' => $message,
                        'course_id' => $courseId,
                        'course_title' => get_the_title($courseId),
                        'timestamp' => $timestamp,
                        'time_ago' => $this->getTimeAgo($timestamp),
                    ];
                }
            }
        }

        // Add recent quote activities
        $quotes = $this->getUserQuotes($userId, ['limit' => 3]);
        foreach ($quotes as $quote) {
            $createdAt = strtotime($quote['created_at'] ?? '');
            if ($createdAt) {
                $activities[] = [
                    'type' => 'quote_created',
                    'icon' => 'file-text',
                    'message' => sprintf(__('Offerte %s aangemaakt', 'stride'), $quote['quote_number']),
                    'quote_id' => $quote['id'],
                    'timestamp' => $createdAt,
                    'time_ago' => $this->getTimeAgo($createdAt),
                ];
            }
        }

        // Sort by timestamp desc
        usort($activities, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

        return array_slice($activities, 0, $limit);
    }

    /**
     * Get icon for activity type
     */
    private function getActivityIcon(string $type): string
    {
        return match ($type) {
            'course' => 'play-circle',
            'lesson' => 'bookmark',
            'topic' => 'check',
            'quiz' => 'question',
            'access' => 'sign-in',
            default => 'info',
        };
    }

    /**
     * Get message for activity type
     */
    private function getActivityMessage(string $type, int $courseId): string
    {
        $courseTitle = get_the_title($courseId);

        return match ($type) {
            'course' => sprintf(__('Cursus %s gestart', 'stride'), $courseTitle),
            'lesson' => sprintf(__('Les voltooid in %s', 'stride'), $courseTitle),
            'topic' => sprintf(__('Topic voltooid in %s', 'stride'), $courseTitle),
            'quiz' => sprintf(__('Quiz voltooid in %s', 'stride'), $courseTitle),
            'access' => sprintf(__('Ingeschreven voor %s', 'stride'), $courseTitle),
            default => sprintf(__('Activiteit in %s', 'stride'), $courseTitle),
        };
    }

    /**
     * Get human-readable time ago string
     */
    private function getTimeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('Zojuist', 'stride');
        }

        if ($diff < 3600) {
            $minutes = round($diff / 60);
            return sprintf(_n('%d minuut geleden', '%d minuten geleden', $minutes, 'stride'), $minutes);
        }

        if ($diff < 86400) {
            $hours = round($diff / 3600);
            return sprintf(_n('%d uur geleden', '%d uur geleden', $hours, 'stride'), $hours);
        }

        if ($diff < 604800) {
            $days = round($diff / 86400);
            return sprintf(_n('%d dag geleden', '%d dagen geleden', $days, 'stride'), $days);
        }

        return date_i18n('j F Y', $timestamp);
    }

    // ========================================
    // USER QUOTES
    // ========================================

    /**
     * Get user's quotes
     *
     * @param int $userId
     * @param array $args Optional args: limit, status
     * @return array
     */
    public function getUserQuotes(int $userId, array $args = []): array
    {
        $limit = $args['limit'] ?? 10;
        $status = $args['status'] ?? null;

        $queryArgs = [
            'post_type' => QuoteService::POST_TYPE,
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => QuoteService::FIELD_USER_ID,
                    'value' => $userId,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if ($status) {
            $queryArgs['meta_query'][] = [
                'key' => QuoteService::FIELD_STATUS,
                'value' => $status,
                'compare' => '=',
            ];
        }

        $query = new \WP_Query($queryArgs);
        $quotes = [];

        foreach ($query->posts as $post) {
            $quotes[] = $this->buildQuoteData($post);
        }

        return $quotes;
    }

    /**
     * Build quote data array
     */
    private function buildQuoteData(WP_Post $post): array
    {
        $quoteNumber = get_post_meta($post->ID, QuoteService::FIELD_QUOTE_NUMBER, true);
        $status = get_post_meta($post->ID, QuoteService::FIELD_STATUS, true);
        $total = get_post_meta($post->ID, QuoteService::FIELD_TOTAL, true);
        $validUntil = get_post_meta($post->ID, QuoteService::FIELD_VALID_UNTIL, true);
        $items = get_post_meta($post->ID, QuoteService::FIELD_ITEMS, true);

        return [
            'id' => $post->ID,
            'quote_number' => $quoteNumber ?: $post->ID,
            'status' => $status ?: QuoteService::STATUS_DRAFT,
            'status_label' => $this->getQuoteStatusLabel($status),
            'total' => (float) $total,
            'total_formatted' => $this->formatCurrency((float) $total),
            'valid_until' => $validUntil,
            'valid_until_formatted' => $validUntil ? date_i18n('j F Y', strtotime($validUntil)) : null,
            'is_expired' => $validUntil && strtotime($validUntil) < time(),
            'items' => is_array($items) ? $items : [],
            'item_count' => is_array($items) ? count($items) : 0,
            'created_at' => $post->post_date,
            'pdf_url' => $this->getQuotePdfUrl($post->ID),
        ];
    }

    /**
     * Get quote status label
     */
    private function getQuoteStatusLabel(string $status): string
    {
        return match ($status) {
            QuoteService::STATUS_DRAFT => __('Concept', 'stride'),
            QuoteService::STATUS_SENT => __('Verzonden', 'stride'),
            QuoteService::STATUS_EXPORTED => __('Betaald', 'stride'),
            default => __('Onbekend', 'stride'),
        };
    }

    /**
     * Get quote PDF download URL
     */
    private function getQuotePdfUrl(int $quoteId): ?string
    {
        $pdfPath = get_post_meta($quoteId, QuoteService::FIELD_PDF_PATH, true);

        if (empty($pdfPath)) {
            return null;
        }

        return home_url('/stride-quote-pdf/' . $quoteId . '/');
    }

    /**
     * Format currency value
     */
    private function formatCurrency(float $amount): string
    {
        return '€ ' . number_format($amount, 2, ',', '.');
    }

    // ========================================
    // USER PROFILE
    // ========================================

    /**
     * Get user profile data
     *
     * @param int $userId
     * @return array
     */
    public function getUserProfile(int $userId): array
    {
        $user = get_user_by('ID', $userId);

        if (!$user) {
            return [];
        }

        // Get synced data from UserDataSync
        $syncedData = [];
        if ($this->userDataSync) {
            $syncedData = $this->userDataSync->getFields($userId, [
                'first_name',
                'last_name',
                'email',
                'phone',
                'organization_name',
                'organization_id',
            ]);
        }

        return array_merge([
            'id' => $userId,
            'email' => $user->user_email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name,
        ], $syncedData);
    }

    /**
     * Handle profile update AJAX request
     */
    public function handleProfileUpdate(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_frontend')) {
            wp_send_json_error(['message' => __('Beveiligingsfout.', 'stride')], 403);
        }

        $userId = get_current_user_id();

        if (!$userId) {
            wp_send_json_error(['message' => __('Niet ingelogd.', 'stride')], 401);
        }

        // Sanitize input
        $data = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
        ];

        // Update WordPress user
        $result = wp_update_user([
            'ID' => $userId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'display_name' => trim($data['first_name'] . ' ' . $data['last_name']),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        // Sync to other backends
        if ($this->userDataSync) {
            $this->userDataSync->setFields($userId, $data);
        }

        wp_send_json_success(['message' => __('Profiel bijgewerkt.', 'stride')]);
    }

    // ========================================
    // ICAL DOWNLOADS
    // ========================================

    /**
     * Handle iCal download AJAX request
     */
    public function handleIcalDownload(): void
    {
        $courseId = (int) ($_GET['course_id'] ?? 0);
        $editionId = (int) ($_GET['edition_id'] ?? 0);
        $sessionId = (int) ($_GET['session_id'] ?? 0);
        $nonce = $_GET['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'stride_frontend')) {
            wp_die(__('Beveiligingsfout.', 'stride'));
        }

        $userId = get_current_user_id();

        // Verify user has access to this edition
        if ($editionId && $this->registrationRepo) {
            $registration = $this->registrationRepo->findByUserAndEdition($userId, $editionId);
            if (!$registration || $registration['status'] !== RegistrationRepository::STATUS_CONFIRMED) {
                wp_die(__('Geen toegang.', 'stride'));
            }
        } elseif ($courseId && $this->courseService && !$this->courseService->isUserEnrolled($userId, $courseId)) {
            wp_die(__('Geen toegang.', 'stride'));
        }

        $icalService = stride_service(ICalService::class);

        if ($icalService) {
            // Use session-aware iCal if session ID provided
            if ($sessionId) {
                $icalService->downloadSessionEvent($sessionId);
            } elseif ($editionId) {
                $icalService->downloadEditionEvent($editionId);
            } else {
                $icalService->downloadCourseEvent($courseId);
            }
        } else {
            // Fallback simple iCal generation
            $this->generateSimpleIcal($courseId, $editionId, $sessionId);
        }

        exit;
    }

    /**
     * Simple iCal generation fallback
     */
    private function generateSimpleIcal(int $courseId, int $editionId = 0, int $sessionId = 0): void
    {
        $title = get_the_title($courseId);
        $startDate = null;
        $location = null;

        // Get data from session or edition
        if ($sessionId && $this->sessionService) {
            $session = $this->sessionService->getSession($sessionId);
            if ($session) {
                $startDate = strtotime($session['date'] . ' ' . ($session['start_time'] ?: '09:00'));
                $location = $session['location'];
            }
        } elseif ($editionId && $this->editionService) {
            $startDateStr = $this->editionService->getStartDate($editionId);
            $startDate = $startDateStr ? strtotime($startDateStr) : null;
            $location = $this->editionService->getVenue($editionId);
        } elseif ($this->courseService) {
            $startDate = $this->courseService->getStartDate($courseId);
            $location = $this->courseService->getCourseAddress($courseId);
        }

        if (!$title || !$startDate) {
            wp_die(__('Geen datum gevonden.', 'stride'));
        }

        $dtStart = gmdate('Ymd\THis\Z', $startDate);
        $dtEnd = gmdate('Ymd\THis\Z', $startDate + 28800); // +8 hours default
        $dtstamp = gmdate('Ymd\THis\Z');
        $uid = 'stride-' . ($sessionId ?: $editionId ?: $courseId) . '@' . wp_parse_url(home_url(), PHP_URL_HOST);

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Stride LMS//NONSGML v1.0//NL\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$dtstamp}\r\n";
        $ical .= "DTSTART:{$dtStart}\r\n";
        $ical .= "DTEND:{$dtEnd}\r\n";
        $ical .= "SUMMARY:" . $this->escapeIcal($title) . "\r\n";
        if ($location) {
            $ical .= "LOCATION:" . $this->escapeIcal($location) . "\r\n";
        }
        $ical .= "URL:" . get_permalink($courseId) . "\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="event-' . ($sessionId ?: $editionId ?: $courseId) . '.ics"');
        echo $ical;
    }

    /**
     * Escape text for iCal format
     */
    private function escapeIcal(string $text): string
    {
        $text = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
        return $text;
    }

    // ========================================
    // DASHBOARD STATS
    // ========================================

    /**
     * Get dashboard statistics for user
     *
     * @param int $userId
     * @return array
     */
    public function getDashboardStats(int $userId): array
    {
        $courses = $this->getUserCourses($userId);
        $trajectories = $this->getUserTrajectories($userId);
        $quotes = $this->getUserQuotes($userId);

        $totalCourses = count($courses);
        $completedCourses = count(array_filter($courses, fn($c) => $c['status'] === 'completed'));
        $inProgressCourses = count(array_filter($courses, fn($c) => $c['status'] === 'in_progress'));

        return [
            'total_courses' => $totalCourses,
            'completed_courses' => $completedCourses,
            'in_progress_courses' => $inProgressCourses,
            'enrolled_courses' => $totalCourses - $completedCourses - $inProgressCourses,
            'total_trajectories' => count($trajectories),
            'total_quotes' => count($quotes),
            'pending_quotes' => count(array_filter($quotes, fn($q) => $q['status'] === QuoteService::STATUS_DRAFT)),
        ];
    }

    // ========================================
    // EDITION SIDEBAR HELPERS
    // ========================================

    /**
     * Get edition action button data based on user status
     *
     * @param int $editionId Edition post ID
     * @param int|null $userId
     * @return array
     */
    public function getEditionActionButton(int $editionId, ?int $userId = null): array
    {
        $userId = $userId ?? get_current_user_id();

        if (!$this->editionService) {
            return [
                'label' => __('Niet beschikbaar', 'stride'),
                'style' => 'muted',
                'url' => null,
                'disabled' => true,
            ];
        }

        $edition = $this->editionService->getEdition($editionId);
        if (!$edition) {
            return [
                'label' => __('Niet beschikbaar', 'stride'),
                'style' => 'muted',
                'url' => null,
                'disabled' => true,
            ];
        }

        $courseId = $edition['course_id'];

        if (!$userId) {
            return [
                'label' => __('Log in om in te schrijven', 'stride'),
                'style' => 'default',
                'url' => wp_login_url(get_permalink($courseId)),
                'disabled' => false,
            ];
        }

        // Check if enrolled in this edition
        if ($this->registrationRepo) {
            $registration = $this->registrationRepo->findByUserAndEdition($userId, $editionId);
            if ($registration && $registration['status'] === RegistrationRepository::STATUS_CONFIRMED) {
                // Check completion
                if ($this->courseService && $this->courseService->isUserCompleted($userId, $courseId)) {
                    $certificateLink = $this->courseService->getCertificateLink($userId, $courseId);
                    return [
                        'label' => __('Download Certificaat', 'stride'),
                        'style' => 'success',
                        'url' => $certificateLink ?: '#',
                        'disabled' => !$certificateLink,
                    ];
                }

                // Online course - can continue
                if (empty($edition['venue'])) {
                    return [
                        'label' => __('Ga verder', 'stride'),
                        'style' => 'primary',
                        'url' => get_permalink($courseId),
                        'disabled' => false,
                    ];
                }

                return [
                    'label' => __('U bent ingeschreven', 'stride'),
                    'style' => 'success',
                    'url' => null,
                    'disabled' => true,
                ];
            }
        }

        // Check edition status
        if ($this->editionService->isCancelled($editionId)) {
            return [
                'label' => __('Geannuleerd', 'stride'),
                'style' => 'danger',
                'url' => null,
                'disabled' => true,
            ];
        }

        if ($this->editionService->hasEnded($editionId)) {
            return [
                'label' => __('Afgelopen', 'stride'),
                'style' => 'muted',
                'url' => null,
                'disabled' => true,
            ];
        }

        if ($this->editionService->isFull($editionId)) {
            return [
                'label' => __('Volzet', 'stride'),
                'style' => 'warning',
                'url' => null,
                'disabled' => true,
            ];
        }

        if ($this->editionService->isAnnouncement($editionId)) {
            return [
                'label' => __('Binnenkort beschikbaar', 'stride'),
                'style' => 'muted',
                'url' => null,
                'disabled' => true,
            ];
        }

        // Can enroll
        return [
            'label' => __('Schrijf je in', 'stride'),
            'style' => 'primary',
            'url' => $this->getEnrollmentUrl($editionId, $courseId),
            'disabled' => false,
        ];
    }

    /**
     * Get course action button - legacy wrapper for edition-based enrollment
     *
     * @param int $courseId
     * @param int|null $userId
     * @return array
     */
    public function getCourseActionButton(int $courseId, ?int $userId = null): array
    {
        $userId = $userId ?? get_current_user_id();

        // Find next available edition for this course
        if ($this->editionService) {
            $upcomingEditions = $this->editionService->getUpcomingEditionsForCourse($courseId, 1);
            if (!empty($upcomingEditions)) {
                return $this->getEditionActionButton($upcomingEditions[0]['id'], $userId);
            }
        }

        // Fall back to course-level check
        if (!$userId) {
            return [
                'label' => __('Log in om in te schrijven', 'stride'),
                'style' => 'default',
                'url' => wp_login_url(get_permalink($courseId)),
                'disabled' => false,
            ];
        }

        // Check if enrolled via LearnDash
        if ($this->courseService && $this->courseService->isUserEnrolled($userId, $courseId)) {
            if ($this->courseService->isUserCompleted($userId, $courseId)) {
                $certificateLink = $this->courseService->getCertificateLink($userId, $courseId);
                return [
                    'label' => __('Download Certificaat', 'stride'),
                    'style' => 'success',
                    'url' => $certificateLink ?: '#',
                    'disabled' => !$certificateLink,
                ];
            }

            return [
                'label' => __('Ga verder', 'stride'),
                'style' => 'primary',
                'url' => get_permalink($courseId),
                'disabled' => false,
            ];
        }

        return [
            'label' => __('Schrijf je in', 'stride'),
            'style' => 'primary',
            'url' => $this->getEnrollmentUrl(0, $courseId),
            'disabled' => false,
        ];
    }

    /**
     * Get enrollment URL for an edition or course
     */
    private function getEnrollmentUrl(int $editionId, int $courseId): string
    {
        // Check for custom form
        $customForm = null;
        if ($editionId && $this->editionService) {
            $customForm = $this->editionService->getCustomForm($editionId);
        } elseif ($this->courseService) {
            $customForm = $this->courseService->getCustomForm($courseId);
        }

        if ($editionId) {
            return home_url('/inschrijven/?edition=' . $editionId);
        }

        // Default enrollment page
        return home_url('/inschrijven/?course=' . $courseId);
    }

    /**
     * Get edition info for sidebar display
     *
     * @param int $editionId
     * @return array
     */
    public function getEditionInfo(int $editionId): array
    {
        if (!$this->editionService) {
            return [];
        }

        $edition = $this->editionService->getEdition($editionId);
        if (!$edition) {
            return [];
        }

        $price = $edition['price'];
        $speakers = $this->editionService->getSpeakers($editionId);
        $sessions = $this->sessionService ? $this->sessionService->getSessionsForEdition($editionId) : [];
        $dayCount = count($sessions) ?: 1;

        $sessionDates = array_map(function ($s) {
            return date_i18n('j F Y', strtotime($s['date']));
        }, $sessions);

        $availableSpots = $this->editionService->getAvailableSpots($editionId);

        return [
            'id' => $editionId,
            'course_id' => $edition['course_id'],
            'title' => $edition['title'],
            'price' => $price,
            'price_formatted' => $price !== null && $price > 0 ? $this->formatCurrency($price) : __('Gratis', 'stride'),
            'price_non_member' => $edition['price_non_member'],
            'dates' => $sessionDates,
            'next_date' => !empty($sessionDates) ? $sessionDates[0] : ($edition['start_date'] ? date_i18n('j F Y', strtotime($edition['start_date'])) : null),
            'start_date' => $edition['start_date'],
            'end_date' => $edition['end_date'],
            'location' => $edition['venue'],
            'speakers' => $speakers,
            'day_count' => $dayCount,
            'is_in_person' => !empty($edition['venue']),
            'is_online' => empty($edition['venue']),
            'available_spots' => $availableSpots,
            'spots_text' => $availableSpots > 0
                ? sprintf(_n('%d plaats beschikbaar', '%d plaatsen beschikbaar', $availableSpots, 'stride'), $availableSpots)
                : ($availableSpots < 0 ? null : __('Volzet', 'stride')),
            'status' => $edition['status'],
        ];
    }

    /**
     * Get course info for sidebar display - legacy wrapper
     *
     * @param int $courseId
     * @return array
     */
    public function getCourseInfo(int $courseId): array
    {
        // Find next available edition for this course
        if ($this->editionService) {
            $upcomingEditions = $this->editionService->getUpcomingEditionsForCourse($courseId, 1);
            if (!empty($upcomingEditions)) {
                return $this->getEditionInfo($upcomingEditions[0]['id']);
            }
        }

        // Fall back to course-level data (legacy)
        if (!$this->courseService) {
            return [];
        }

        $price = $this->courseService->getCoursePrice($courseId);
        $dates = $this->courseService->getCourseDates($courseId);
        $location = $this->courseService->getCourseAddress($courseId);
        $speakers = $this->courseService->getCourseSpeakers($courseId);
        $dayCount = $this->courseService->getDayCount($courseId);
        $availableSpots = $this->courseService->getAvailableSpots($courseId);

        return [
            'id' => null,
            'course_id' => $courseId,
            'price' => $price,
            'price_formatted' => $price !== null ? $this->formatCurrency($price) : __('Gratis', 'stride'),
            'dates' => array_map(fn($ts) => date_i18n('j F Y', $ts), $dates),
            'next_date' => !empty($dates) ? date_i18n('j F Y', $dates[0]) : null,
            'location' => $location,
            'speakers' => $speakers,
            'day_count' => $dayCount,
            'is_in_person' => $this->courseService->isInPerson($courseId),
            'is_online' => $this->courseService->isOnline($courseId),
            'available_spots' => $availableSpots,
            'spots_text' => $availableSpots !== null
                ? sprintf(_n('%d plaats beschikbaar', '%d plaatsen beschikbaar', $availableSpots, 'stride'), $availableSpots)
                : null,
        ];
    }
}
