<?php

namespace stride\services\frontend;

defined('ABSPATH') || exit;

use ntdst\Stride\core\CourseService;
use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\sync\UserDataSync;
use WP_Error;
use WP_Post;

/**
 * Dashboard Service
 *
 * Provides aggregated data for user dashboard templates.
 * Orchestrates data from CourseService, QuoteService, and UserDataSync
 * to provide a unified dashboard experience.
 *
 * @package stride\services\frontend
 */
class DashboardService implements \NTDST_Service_Meta
{
    private ?CourseService $courseService;
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
        ?QuoteService $quoteService = null,
        ?UserDataSync $userDataSync = null
    ) {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
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
    // USER COURSE DATA
    // ========================================

    /**
     * Get all courses for a user with enriched data
     *
     * @param int $userId
     * @param array $filters Optional filters: type (online|in-person), status (enrolled|completed|in_progress)
     * @return array Array of course data
     */
    public function getUserCourses(int $userId, array $filters = []): array
    {
        if (!$this->courseService || !$this->courseService->isAvailable()) {
            return [];
        }

        // Get enrolled course IDs
        $enrolledCourses = $this->getEnrolledCourseIds($userId);

        if (empty($enrolledCourses)) {
            return [];
        }

        $courses = [];

        foreach ($enrolledCourses as $courseId) {
            $courseData = $this->buildCourseData($courseId, $userId);

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
     * Build course data array for a single course
     */
    private function buildCourseData(int $courseId, int $userId): ?array
    {
        $course = $this->courseService->getCourse($courseId);

        if (!$course) {
            return null;
        }

        $isCompleted = $this->courseService->isUserCompleted($userId, $courseId);
        $isInPerson = $this->courseService->isInPerson($courseId);
        $isOnline = !$isInPerson;
        $hasStarted = $this->courseService->hasStarted($courseId);

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

        return [
            'id' => $courseId,
            'title' => $course->post_title,
            'excerpt' => get_the_excerpt($course),
            'permalink' => get_permalink($courseId),
            'thumbnail' => get_the_post_thumbnail_url($courseId, 'stride_course_card'),
            'is_online' => $isOnline,
            'is_in_person' => $isInPerson,
            'is_trajectory' => $this->courseService->isTraject($courseId),
            'status' => $status,
            'progress' => $progress,
            'start_date' => $this->courseService->getStartDate($courseId),
            'next_date' => $this->courseService->getNextDate($courseId),
            'dates' => $this->courseService->getCourseDates($courseId),
            'location' => $this->courseService->getCourseAddress($courseId),
            'certificate_link' => $isCompleted ? $this->courseService->getCertificateLink($userId, $courseId) : null,
            'speakers' => $this->courseService->getCourseSpeakers($courseId),
        ];
    }

    /**
     * Get enrolled course IDs for a user
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
     * @param int $userId
     * @return array
     */
    public function getUserTrajectories(int $userId): array
    {
        if (!function_exists('learndash_get_users_group_ids')) {
            return [];
        }

        $groupIds = learndash_get_users_group_ids($userId, true);

        if (empty($groupIds)) {
            return [];
        }

        $trajectories = [];

        foreach ($groupIds as $groupId) {
            $group = get_post($groupId);

            if (!$group || $group->post_type !== 'groups') {
                continue;
            }

            // Get group courses
            $groupCourses = learndash_get_group_courses_list($groupId);

            if (empty($groupCourses)) {
                continue;
            }

            $totalCourses = count($groupCourses);
            $completedCourses = 0;
            $modules = [];

            foreach ($groupCourses as $courseId) {
                $isCompleted = $this->courseService->isUserCompleted($userId, $courseId);
                if ($isCompleted) {
                    $completedCourses++;
                }

                $modules[] = [
                    'id' => $courseId,
                    'title' => get_the_title($courseId),
                    'status' => $this->getModuleStatus($courseId, $userId),
                    'is_completed' => $isCompleted,
                    'next_date' => $this->courseService->getNextDate($courseId),
                ];
            }

            $progress = $totalCourses > 0
                ? round(($completedCourses / $totalCourses) * 100)
                : 0;

            $trajectories[] = [
                'id' => $groupId,
                'title' => $group->post_title,
                'excerpt' => get_the_excerpt($group),
                'permalink' => get_permalink($groupId),
                'total_modules' => $totalCourses,
                'completed_modules' => $completedCourses,
                'progress' => $progress,
                'modules' => $modules,
                'current_module' => $this->getCurrentModule($modules),
            ];
        }

        return $trajectories;
    }

    /**
     * Get single trajectory with full journey data
     *
     * @param int $trajectoryId Group ID
     * @param int $userId
     * @return array|null
     */
    public function getTrajectory(int $trajectoryId, int $userId): ?array
    {
        $group = get_post($trajectoryId);

        if (!$group || $group->post_type !== 'groups') {
            return null;
        }

        $groupCourses = learndash_get_group_courses_list($trajectoryId);

        if (empty($groupCourses)) {
            return null;
        }

        $mandatoryModules = [];
        $electiveModules = [];
        $completedCount = 0;
        $currentModuleIndex = -1;

        foreach ($groupCourses as $index => $courseId) {
            $isCompleted = $this->courseService->isUserCompleted($userId, $courseId);
            $isElective = $this->courseService->isModuleCourse($courseId);

            if ($isCompleted) {
                $completedCount++;
            }

            $moduleData = [
                'id' => $courseId,
                'title' => get_the_title($courseId),
                'permalink' => get_permalink($courseId),
                'status' => $this->getModuleStatus($courseId, $userId),
                'is_completed' => $isCompleted,
                'next_date' => $this->courseService->getNextDate($courseId),
                'location' => $this->courseService->getCourseAddress($courseId),
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

        $totalMandatory = count($mandatoryModules);
        $progress = $totalMandatory > 0
            ? round(($completedCount / count($groupCourses)) * 100)
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
            'title' => $group->post_title,
            'description' => apply_filters('the_content', $group->post_content),
            'progress' => $progress,
            'mandatory_modules' => $mandatoryModules,
            'elective_modules' => $electiveModules,
            'current_module_index' => $currentModuleIndex,
            'next_session' => $nextSession,
            'completed_count' => $completedCount,
            'total_count' => count($groupCourses),
        ];
    }

    /**
     * Get module status string
     */
    private function getModuleStatus(int $courseId, int $userId): string
    {
        if ($this->courseService->isUserCompleted($userId, $courseId)) {
            return 'completed';
        }

        if ($this->courseService->hasStarted($courseId)) {
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
     * Get upcoming course dates for user
     *
     * @param int $userId
     * @param int $limit Maximum dates to return
     * @return array
     */
    public function getUpcomingDates(int $userId, int $limit = 3): array
    {
        $courses = $this->getUserCourses($userId);
        $upcomingDates = [];

        foreach ($courses as $course) {
            if ($course['status'] === 'completed') {
                continue;
            }

            foreach ($course['dates'] as $timestamp) {
                // Only future dates
                if ($timestamp > time()) {
                    $upcomingDates[] = [
                        'timestamp' => $timestamp,
                        'course_id' => $course['id'],
                        'course_title' => $course['title'],
                        'location' => $course['location'],
                        'day' => date_i18n('j', $timestamp),
                        'month' => date_i18n('M', $timestamp),
                        'full_date' => date_i18n('j F Y', $timestamp),
                        'time' => date_i18n('H:i', $timestamp),
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
        $nonce = $_GET['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'stride_frontend')) {
            wp_die(__('Beveiligingsfout.', 'stride'));
        }

        $userId = get_current_user_id();

        if (!$userId || !$this->courseService->isUserEnrolled($userId, $courseId)) {
            wp_die(__('Geen toegang.', 'stride'));
        }

        $icalService = stride_service(ICalService::class);

        if ($icalService) {
            $icalService->downloadCourseEvent($courseId);
        } else {
            // Fallback simple iCal generation
            $this->generateSimpleIcal($courseId);
        }

        exit;
    }

    /**
     * Simple iCal generation fallback
     */
    private function generateSimpleIcal(int $courseId): void
    {
        $course = $this->courseService->getCourse($courseId);
        $startDate = $this->courseService->getStartDate($courseId);
        $location = $this->courseService->getCourseAddress($courseId);

        if (!$course || !$startDate) {
            wp_die(__('Geen datum gevonden.', 'stride'));
        }

        $dtStart = gmdate('Ymd\THis\Z', $startDate);
        $dtEnd = gmdate('Ymd\THis\Z', $startDate + 28800); // +8 hours default
        $dtstamp = gmdate('Ymd\THis\Z');
        $uid = 'stride-course-' . $courseId . '@' . wp_parse_url(home_url(), PHP_URL_HOST);

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Stride LMS//NONSGML v1.0//NL\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$dtstamp}\r\n";
        $ical .= "DTSTART:{$dtStart}\r\n";
        $ical .= "DTEND:{$dtEnd}\r\n";
        $ical .= "SUMMARY:" . $this->escapeIcal($course->post_title) . "\r\n";
        if ($location) {
            $ical .= "LOCATION:" . $this->escapeIcal($location) . "\r\n";
        }
        $ical .= "URL:" . get_permalink($courseId) . "\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="course-' . $courseId . '.ics"');
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
    // COURSE SIDEBAR HELPERS
    // ========================================

    /**
     * Get course action button data based on user status
     *
     * @param int $courseId
     * @param int|null $userId
     * @return array
     */
    public function getCourseActionButton(int $courseId, ?int $userId = null): array
    {
        $userId = $userId ?? get_current_user_id();

        if (!$userId) {
            return [
                'label' => __('Log in om in te schrijven', 'stride'),
                'style' => 'default',
                'url' => wp_login_url(get_permalink($courseId)),
                'disabled' => false,
            ];
        }

        // Check if enrolled
        if ($this->courseService->isUserEnrolled($userId, $courseId)) {
            if ($this->courseService->isUserCompleted($userId, $courseId)) {
                $certificateLink = $this->courseService->getCertificateLink($userId, $courseId);
                return [
                    'label' => __('Download Certificaat', 'stride'),
                    'style' => 'success',
                    'url' => $certificateLink ?: '#',
                    'disabled' => !$certificateLink,
                ];
            }

            if ($this->courseService->isOnline($courseId)) {
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

        // Check course status
        if ($this->courseService->isCancelled($courseId)) {
            return [
                'label' => __('Geannuleerd', 'stride'),
                'style' => 'danger',
                'url' => null,
                'disabled' => true,
            ];
        }

        if ($this->courseService->hasEnded($courseId)) {
            return [
                'label' => __('Afgelopen', 'stride'),
                'style' => 'muted',
                'url' => null,
                'disabled' => true,
            ];
        }

        if ($this->courseService->isFull($courseId)) {
            return [
                'label' => __('Volzet', 'stride'),
                'style' => 'warning',
                'url' => null,
                'disabled' => true,
            ];
        }

        if ($this->courseService->isAnnouncement($courseId)) {
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
            'url' => $this->getEnrollmentUrl($courseId),
            'disabled' => false,
        ];
    }

    /**
     * Get enrollment URL for a course
     */
    private function getEnrollmentUrl(int $courseId): string
    {
        // Check for custom form
        $customForm = $this->courseService->getCustomForm($courseId);

        if ($customForm) {
            // Link to custom FluentForms page
            return home_url('/inschrijven/?course=' . $courseId);
        }

        // Default enrollment page
        return home_url('/inschrijven/?course=' . $courseId);
    }

    /**
     * Get course info for sidebar display
     *
     * @param int $courseId
     * @return array
     */
    public function getCourseInfo(int $courseId): array
    {
        $price = $this->courseService->getCoursePrice($courseId);
        $dates = $this->courseService->getCourseDates($courseId);
        $location = $this->courseService->getCourseAddress($courseId);
        $speakers = $this->courseService->getCourseSpeakers($courseId);
        $dayCount = $this->courseService->getDayCount($courseId);
        $availableSpots = $this->courseService->getAvailableSpots($courseId);

        return [
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
