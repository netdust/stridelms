<?php

declare(strict_types=1);

namespace Stride\Integrations\LearnDash;

/**
 * LearnDash Template Helper
 *
 * Read-only presentation logic for LearnDash course data.
 * Handles access checks, CTAs, progress, lessons, certificates, and course metadata.
 *
 * For write operations (grant/revoke access), use LMSAdapterInterface.
 *
 * @see https://developers.learndash.com/function/sfwd_lms_has_access/
 * @see https://developers.learndash.com/function/learndash_get_setting/
 */
final class LearnDashHelper
{
    /**
     * Course access modes.
     */
    public const MODE_OPEN = 'open';
    public const MODE_FREE = 'free';
    public const MODE_PAYNOW = 'paynow';
    public const MODE_SUBSCRIBE = 'subscribe';
    public const MODE_CLOSED = 'closed';

    /**
     * Check if LearnDash is active.
     */
    public static function isActive(): bool
    {
        return defined('LEARNDASH_VERSION') && function_exists('sfwd_lms_has_access');
    }

    /**
     * Check if user has access to course.
     */
    public static function hasAccess(int $courseId, ?int $userId = null): bool
    {
        if (!self::isActive()) {
            return false;
        }

        // Guard against non-existent / deleted courses. `sfwd_lms_has_access`
        // returns true permissively for unknown IDs, which lets orphan
        // enrollment IDs render as if active.
        if (get_post_type($courseId) !== 'sfwd-courses') {
            return false;
        }

        $userId = $userId ?? get_current_user_id();

        // Open courses - everyone has access
        if (self::getAccessMode($courseId) === self::MODE_OPEN) {
            return true;
        }

        if (!$userId) {
            return false;
        }

        return sfwd_lms_has_access($courseId, $userId);
    }

    /**
     * Check if user is explicitly enrolled (not just "open" access).
     *
     * For open courses, hasAccess() returns true for everyone,
     * but isEnrolled() only returns true if the user has an actual enrollment record.
     */
    public static function isEnrolled(int $courseId, ?int $userId = null): bool
    {
        if (!self::isActive()) {
            return false;
        }

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return false;
        }

        // course_X_access_from is LD's universal enrollment marker — applies to
        // every access mode (open, free, paynow, subscribe). Access-window
        // expiry is a separate question from enrollment state, so don't defer
        // to sfwd_lms_has_access() here: a user who enrolled and then let their
        // access lapse is still enrolled.
        if (!empty(get_user_meta($userId, 'course_' . $courseId . '_access_from', true))) {
            return true;
        }
        if (self::getProgress($courseId, $userId) > 0) {
            return true;
        }

        return sfwd_lms_has_access($courseId, $userId);
    }

    /**
     * Get course access mode.
     *
     * @return string One of: open, free, paynow, subscribe, closed
     */
    public static function getAccessMode(int $courseId): string
    {
        if (!self::isActive()) {
            return self::MODE_CLOSED;
        }

        $mode = learndash_get_setting($courseId, 'course_price_type');

        // Fallback: single-key lookup can return empty; try full settings array
        if (empty($mode)) {
            $allSettings = learndash_get_setting($courseId);
            $mode = $allSettings['course_price_type'] ?? '';
        }

        return in_array($mode, [
            self::MODE_OPEN,
            self::MODE_FREE,
            self::MODE_PAYNOW,
            self::MODE_SUBSCRIBE,
            self::MODE_CLOSED,
        ], true) ? $mode : self::MODE_FREE;
    }

    /**
     * Get course price info.
     *
     * @return array{type: string, price: string, currency: string, billing_cycle: string}
     */
    public static function getCoursePrice(int $courseId): array
    {
        if (!self::isActive() || !function_exists('learndash_get_course_price')) {
            return [
                'type' => self::MODE_FREE,
                'price' => '',
                'currency' => '',
                'billing_cycle' => '',
            ];
        }

        $price = learndash_get_course_price($courseId);

        return [
            'type' => $price['type'] ?? self::MODE_FREE,
            'price' => $price['price'] ?? '',
            'currency' => $price['currency'] ?? get_option('learndash_settings_paypal_currency', 'EUR'),
            'billing_cycle' => $price['pricing_billing_p3'] ?? '',
        ];
    }

    /**
     * Get the closed course button URL.
     */
    public static function getClosedButtonUrl(int $courseId): string
    {
        if (!self::isActive()) {
            return '';
        }

        return learndash_get_setting($courseId, 'custom_button_url') ?: '';
    }

    /**
     * Determine what CTA (call-to-action) to show for a course.
     *
     * @return array{action: string, label: string, url: string, show_login: bool}
     */
    public static function getCourseAction(int $courseId, ?int $userId = null): array
    {
        $userId = $userId ?? get_current_user_id();
        $isLoggedIn = $userId > 0;
        $mode = self::getAccessMode($courseId);
        $hasAccess = self::hasAccess($courseId, $userId);

        // User has access - show start/continue/view
        if ($hasAccess) {
            $progress = self::getProgress($courseId, $userId);

            if ($progress >= 100) {
                return [
                    'action' => 'view',
                    'label' => __('Bekijk Cursus', 'stride'),
                    'url' => self::getResumeUrl($courseId, $userId),
                    'show_login' => false,
                ];
            }

            if ($progress > 0) {
                return [
                    'action' => 'continue',
                    'label' => __('Doorgaan', 'stride'),
                    'url' => self::getResumeUrl($courseId, $userId),
                    'show_login' => false,
                ];
            }

            return [
                'action' => 'start',
                'label' => __('Start Cursus', 'stride'),
                'url' => self::getFirstLessonUrl($courseId),
                'show_login' => false,
            ];
        }

        // No access - determine enrollment action based on mode
        switch ($mode) {
            case self::MODE_OPEN:
                return [
                    'action' => 'start',
                    'label' => __('Start Cursus', 'stride'),
                    'url' => self::getFirstLessonUrl($courseId),
                    'show_login' => false,
                ];

            case self::MODE_FREE:
                if (!$isLoggedIn) {
                    return [
                        'action' => 'login',
                        'label' => __('Log in om in te schrijven', 'stride'),
                        'url' => wp_login_url(get_permalink($courseId)),
                        'show_login' => true,
                    ];
                }
                // Logged in but not enrolled - show enroll button
                return [
                    'action' => 'enroll_free',
                    'label' => __('Gratis Inschrijven', 'stride'),
                    'url' => self::getEnrollUrl($courseId),
                    'show_login' => false,
                ];

            case self::MODE_PAYNOW:
                $price = self::getCoursePrice($courseId);
                return [
                    'action' => 'buy',
                    'label' => sprintf(__('Kopen - %s', 'stride'), self::formatPrice($price)),
                    'url' => self::getEnrollUrl($courseId),
                    'show_login' => !$isLoggedIn,
                ];

            case self::MODE_SUBSCRIBE:
                $price = self::getCoursePrice($courseId);
                return [
                    'action' => 'subscribe',
                    'label' => sprintf(__('Abonneren - %s', 'stride'), self::formatPrice($price)),
                    'url' => self::getEnrollUrl($courseId),
                    'show_login' => !$isLoggedIn,
                ];

            case self::MODE_CLOSED:
                $buttonUrl = self::getClosedButtonUrl($courseId);
                return [
                    'action' => 'closed',
                    'label' => __('Inschrijven', 'stride'),
                    'url' => $buttonUrl ?: get_permalink($courseId),
                    'show_login' => !$isLoggedIn && empty($buttonUrl),
                ];
        }

        return [
            'action' => 'none',
            'label' => '',
            'url' => '',
            'show_login' => false,
        ];
    }

    /**
     * Check if user has completed a course.
     */
    public static function isComplete(int $courseId, ?int $userId = null): bool
    {
        if (!self::isActive() || !function_exists('learndash_course_completed')) {
            return false;
        }

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return false;
        }

        return learndash_course_completed($userId, $courseId);
    }

    /**
     * Get user progress for a course.
     */
    public static function getProgress(int $courseId, ?int $userId = null): int
    {
        if (!self::isActive()) {
            return 0;
        }

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return 0;
        }

        $progress = learndash_course_progress([
            'user_id' => $userId,
            'course_id' => $courseId,
            'array' => true,
        ]);

        $pct = (int) ($progress['percentage'] ?? 0);
        if ($pct > 0) {
            return $pct;
        }

        // Fallback: learndash_course_progress can return 0 when user meta
        // has actual progress data. Calculate from _sfwd-course_progress meta.
        $meta = get_user_meta($userId, '_sfwd-course_progress', true);
        if (is_array($meta) && isset($meta[$courseId])) {
            $total = (int) ($meta[$courseId]['total'] ?? 0);
            $completed = (int) ($meta[$courseId]['completed'] ?? 0);
            if ($total > 0) {
                return (int) round(($completed / $total) * 100);
            }
        }

        return 0;
    }

    /**
     * Get all course IDs the user is enrolled in.
     *
     * @return int[]
     */
    public static function getEnrolledCourses(?int $userId = null): array
    {
        if (!self::isActive() || !function_exists('learndash_user_get_enrolled_courses')) {
            return [];
        }

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return [];
        }

        return learndash_user_get_enrolled_courses($userId);
    }

    /**
     * Get course completion timestamp.
     *
     * @return int|null Unix timestamp, or null if not completed
     */
    public static function getCompletionDate(int $courseId, ?int $userId = null): ?int
    {
        $userId = $userId ?? get_current_user_id();

        if (!self::isComplete($courseId, $userId)) {
            return null;
        }

        if (!function_exists('learndash_user_get_course_completed_date')) {
            return null;
        }

        $timestamp = learndash_user_get_course_completed_date($userId, $courseId);

        return $timestamp ? (int) $timestamp : null;
    }

    /**
     * Get URL to resume course (next incomplete lesson).
     */
    public static function getResumeUrl(int $courseId, ?int $userId = null): string
    {
        if (!self::isActive()) {
            return get_permalink($courseId);
        }

        $userId = $userId ?? get_current_user_id();

        // Get the first incomplete step (returns post ID)
        if (function_exists('learndash_user_progress_get_first_incomplete_step')) {
            $stepId = learndash_user_progress_get_first_incomplete_step($userId, $courseId);
            if ($stepId) {
                return get_permalink($stepId);
            }
        }

        return self::getFirstLessonUrl($courseId);
    }

    /**
     * Get URL to first lesson.
     */
    public static function getFirstLessonUrl(int $courseId): string
    {
        if (!self::isActive()) {
            return get_permalink($courseId);
        }

        $lessons = learndash_get_course_lessons_list($courseId);

        if (!empty($lessons)) {
            $firstLesson = reset($lessons);
            $lessonId = $firstLesson['post']->ID ?? $firstLesson->ID ?? null;
            if ($lessonId) {
                return get_permalink($lessonId);
            }
        }

        return get_permalink($courseId);
    }

    /**
     * Get enrollment URL for course.
     */
    public static function getEnrollUrl(int $courseId): string
    {
        // LearnDash handles enrollment via course page with payment buttons
        return get_permalink($courseId);
    }

    /**
     * Format price for display.
     */
    public static function formatPrice(array $priceInfo): string
    {
        if (empty($priceInfo['price'])) {
            return __('Gratis', 'stride');
        }

        $currency = $priceInfo['currency'] ?: 'EUR';
        $price = $priceInfo['price'];

        // Format with currency symbol
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format((float) $price, 2, ',', '.');
    }

    /**
     * Get course lessons with completion status.
     *
     * @return array<int, array{id: int, title: string, url: string, completed: bool}>
     */
    public static function getLessons(int $courseId, ?int $userId = null): array
    {
        if (!self::isActive()) {
            return [];
        }

        $userId = $userId ?? get_current_user_id();
        $lessons = learndash_get_course_lessons_list($courseId);
        $result = [];

        foreach ($lessons as $lesson) {
            $lessonPost = $lesson['post'] ?? $lesson;
            $lessonId = $lessonPost->ID ?? $lessonPost;

            $result[] = [
                'id' => $lessonId,
                'title' => is_object($lessonPost) ? $lessonPost->post_title : get_the_title($lessonId),
                'url' => get_permalink($lessonId),
                'completed' => $userId && learndash_is_lesson_complete($userId, $lessonId, $courseId),
            ];
        }

        return $result;
    }

    /**
     * Get certificate link if user completed course.
     */
    public static function getCertificateLink(int $courseId, ?int $userId = null): string
    {
        if (!self::isActive() || !function_exists('learndash_get_course_certificate_link')) {
            return '';
        }

        $userId = $userId ?? get_current_user_id();

        if (!$userId || !self::isComplete($courseId, $userId)) {
            return '';
        }

        return learndash_get_course_certificate_link($courseId, $userId) ?: '';
    }

    /**
     * Check if course has a certificate configured.
     */
    public static function hasCertificate(int $courseId): bool
    {
        if (!self::isActive()) {
            return false;
        }

        $settings = get_post_meta($courseId, '_sfwd-courses', true);
        return !empty($settings['sfwd-courses_certificate']);
    }

    /**
     * Get course materials/what you'll learn.
     */
    public static function getCourseMaterials(int $courseId): string
    {
        if (!self::isActive()) {
            return '';
        }

        $materials = learndash_get_setting($courseId, 'course_materials');
        return $materials ?: '';
    }

    // ──────────────────────────────────────────────────────────
    // Access Expiration
    // ──────────────────────────────────────────────────────────

    /**
     * Check if course has access expiration enabled.
     */
    public static function hasExpiration(int $courseId): bool
    {
        if (!self::isActive()) {
            return false;
        }

        return learndash_get_setting($courseId, 'expire_access') === 'on';
    }

    /**
     * Get access expiration timestamp for a user's course.
     *
     * @return int|null Expiration timestamp, or null if no expiration
     */
    public static function getAccessExpiration(int $courseId, ?int $userId = null): ?int
    {
        if (!self::isActive() || !self::hasExpiration($courseId)) {
            return null;
        }

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return null;
        }

        if (!function_exists('ld_course_access_expires_on')) {
            return null;
        }

        $expires = ld_course_access_expires_on($courseId, $userId);

        return $expires > 0 ? $expires : null;
    }

    /**
     * Get remaining access days for a user's course.
     *
     * @return int|null Days remaining, or null if no expiration
     */
    public static function getAccessDaysRemaining(int $courseId, ?int $userId = null): ?int
    {
        $expires = self::getAccessExpiration($courseId, $userId);
        if ($expires === null) {
            return null;
        }

        $remaining = $expires - time();

        return max(0, (int) ceil($remaining / DAY_IN_SECONDS));
    }

    // ──────────────────────────────────────────────────────────
    // Prerequisites
    // ──────────────────────────────────────────────────────────

    /**
     * Check if course has prerequisites configured.
     *
     * Checks both the enabled flag AND the prerequisite array,
     * because LD stores prerequisites even when the enabled flag is off.
     */
    public static function hasPrerequisites(int $courseId): bool
    {
        if (!self::isActive()) {
            return false;
        }

        // Check the enabled flag first
        if (function_exists('learndash_get_course_prerequisite_enabled')
            && learndash_get_course_prerequisite_enabled($courseId)) {
            return true;
        }

        // Also check if prerequisite array has entries (LD stores them independently)
        if (function_exists('learndash_get_course_prerequisite')) {
            $prereqs = learndash_get_course_prerequisite($courseId);
            return !empty($prereqs);
        }

        return false;
    }

    /**
     * Get prerequisite courses with their completion status for a user.
     *
     * @return array<int, array{id: int, title: string, url: string, completed: bool}>
     */
    public static function getPrerequisites(int $courseId, ?int $userId = null): array
    {
        if (!self::isActive() || !self::hasPrerequisites($courseId)) {
            return [];
        }

        if (!function_exists('learndash_get_course_prerequisite')) {
            return [];
        }

        $userId = $userId ?? get_current_user_id();
        $prerequisiteIds = learndash_get_course_prerequisite($courseId);

        if (empty($prerequisiteIds)) {
            return [];
        }

        $result = [];
        foreach ($prerequisiteIds as $preReqId) {
            $preReqId = (int) $preReqId;
            if (!$preReqId) {
                continue;
            }

            $completed = false;
            if ($userId && function_exists('learndash_course_completed')) {
                $completed = learndash_course_completed($userId, $preReqId);
            }

            $result[] = [
                'id' => $preReqId,
                'title' => get_the_title($preReqId),
                'url' => get_permalink($preReqId),
                'completed' => $completed,
            ];
        }

        return $result;
    }

    /**
     * Check if all prerequisites are met for a user.
     */
    public static function arePrerequisitesMet(int $courseId, ?int $userId = null): bool
    {
        if (!self::hasPrerequisites($courseId)) {
            return true;
        }

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return false;
        }

        if (function_exists('learndash_is_course_prerequities_completed')) {
            return learndash_is_course_prerequities_completed($courseId, $userId);
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────
    // Drip-Feed Lesson Availability
    // ──────────────────────────────────────────────────────────

    /**
     * Check if any lesson in the course uses drip-feed scheduling.
     */
    public static function hasDripFeed(int $courseId): bool
    {
        if (!self::isActive() || !function_exists('learndash_get_course_lessons_list')) {
            return false;
        }

        $lessons = learndash_get_course_lessons_list($courseId);

        foreach ($lessons as $lesson) {
            $lessonPost = $lesson['post'] ?? $lesson;
            $lessonId = $lessonPost->ID ?? 0;
            if (!$lessonId) {
                continue;
            }

            $visibleAfter = learndash_get_setting($lessonId, 'visible_after');
            $visibleAfterDate = learndash_get_setting($lessonId, 'visible_after_specific_date');

            if (!empty($visibleAfter) || !empty($visibleAfterDate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get lessons with availability dates (for drip-feed display).
     *
     * @return array<int, array{id: int, title: string, url: string, completed: bool, available_from: int|null, is_available: bool}>
     */
    public static function getLessonsWithAvailability(int $courseId, ?int $userId = null): array
    {
        if (!self::isActive() || !function_exists('learndash_get_course_lessons_list')) {
            return [];
        }

        $userId = $userId ?? get_current_user_id();
        $lessons = learndash_get_course_lessons_list($courseId);
        $result = [];

        foreach ($lessons as $lesson) {
            $lessonPost = $lesson['post'] ?? $lesson;
            $lessonId = is_object($lessonPost) ? $lessonPost->ID : (int) $lessonPost;
            if (!$lessonId) {
                continue;
            }

            $availableFrom = null;
            $isAvailable = true;

            if ($userId && function_exists('ld_lesson_access_from')) {
                $accessFrom = ld_lesson_access_from($lessonId, $userId, $courseId);
                if ($accessFrom && $accessFrom > time()) {
                    $availableFrom = (int) $accessFrom;
                    $isAvailable = false;
                }
            }

            $completed = false;
            if ($userId && function_exists('learndash_is_lesson_complete')) {
                $completed = learndash_is_lesson_complete($userId, $lessonId, $courseId);
            }

            $result[] = [
                'id' => $lessonId,
                'title' => is_object($lessonPost) ? $lessonPost->post_title : get_the_title($lessonId),
                'url' => get_permalink($lessonId),
                'completed' => $completed,
                'available_from' => $availableFrom,
                'is_available' => $isAvailable,
            ];
        }

        return $result;
    }

    /**
     * Get the user's last activity timestamp for a course.
     */
    public static function getLastActivityDate(int $courseId, ?int $userId = null): ?int
    {
        global $wpdb;

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return null;
        }

        $table = $wpdb->prefix . 'learndash_user_activity';
        $timestamp = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(activity_updated) FROM {$table} WHERE user_id = %d AND course_id = %d",
            $userId,
            $courseId
        ));

        return $timestamp ? (int) $timestamp : null;
    }

    // ──────────────────────────────────────────────────────────
    // Course Points
    // ──────────────────────────────────────────────────────────

    /**
     * Get course points value.
     *
     * @return int Points awarded for completing this course (0 = none)
     */
    public static function getCoursePoints(int $courseId): int
    {
        if (!self::isActive()) {
            return 0;
        }

        $points = learndash_get_setting($courseId, 'course_points');

        return (int) ($points ?: 0);
    }

    /**
     * Check if course requires points to enroll.
     */
    public static function hasPointsRequirement(int $courseId): bool
    {
        if (!self::isActive()) {
            return false;
        }

        return learndash_get_setting($courseId, 'course_points_enabled') === 'on';
    }

    /**
     * Get points required to access this course.
     */
    public static function getPointsRequired(int $courseId): int
    {
        if (!self::isActive() || !self::hasPointsRequirement($courseId)) {
            return 0;
        }

        $points = learndash_get_setting($courseId, 'course_points_access');

        return (int) ($points ?: 0);
    }

    // ──────────────────────────────────────────────────────────
    // Course Availability Window
    // ──────────────────────────────────────────────────────────

    /**
     * Get course start date (when content becomes available).
     *
     * @return int|null Unix timestamp, or null if not set
     */
    public static function getStartDate(int $courseId): ?int
    {
        if (!self::isActive()) {
            return null;
        }

        $date = learndash_get_setting($courseId, 'course_start_date');

        return (!empty($date) && $date !== '0') ? (int) $date : null;
    }

    /**
     * Get course end date (when content becomes unavailable).
     *
     * @return int|null Unix timestamp, or null if not set
     */
    public static function getEndDate(int $courseId): ?int
    {
        if (!self::isActive()) {
            return null;
        }

        $date = learndash_get_setting($courseId, 'course_end_date');

        return (!empty($date) && $date !== '0') ? (int) $date : null;
    }
}
