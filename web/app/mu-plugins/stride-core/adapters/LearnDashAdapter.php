<?php

namespace ntdst\Stride\adapters;

defined('ABSPATH') || exit;

use ntdst\Stride\contracts\LearnDashAdapterInterface;

/**
 * LearnDash Adapter - Production Implementation
 *
 * Wraps actual LearnDash functions. This adapter is injected into
 * CourseService and can be replaced with a mock for testing.
 *
 * @package stride
 */
class LearnDashAdapter implements LearnDashAdapterInterface
{
    /**
     * Check if LearnDash is available
     */
    public function isAvailable(): bool
    {
        return defined('LEARNDASH_VERSION');
    }

    /**
     * Get a course post object
     */
    public function getCourse(int $courseId): ?\WP_Post
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $post = get_post($courseId);

        if (!$post || $post->post_type !== 'sfwd-courses') {
            return null;
        }

        return $post;
    }

    /**
     * Get course meta setting
     */
    public function getCourseSetting(int $courseId, string $key): mixed
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if (function_exists('learndash_get_course_meta_setting')) {
            return learndash_get_course_meta_setting($courseId, $key);
        }

        // Fallback to raw meta
        $settings = get_post_meta($courseId, '_sfwd-courses', true);

        if (!is_array($settings)) {
            return null;
        }

        $fullKey = 'sfwd-courses_' . $key;

        return $settings[$fullKey] ?? null;
    }

    /**
     * Get all course settings
     */
    public function getCourseSettings(int $courseId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        if (function_exists('learndash_get_setting')) {
            $settings = get_post_meta($courseId, '_sfwd-courses', true);
            return is_array($settings) ? $settings : [];
        }

        return [];
    }

    /**
     * Check if user has access to course
     */
    public function hasAccess(int $courseId, int $userId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        if (function_exists('sfwd_lms_has_access')) {
            return sfwd_lms_has_access($courseId, $userId);
        }

        return false;
    }

    /**
     * Get course access timestamp for user
     */
    public function getAccessFrom(int $userId, int $courseId): ?int
    {
        $accessFrom = get_user_meta($userId, 'course_' . $courseId . '_access_from', true);

        return !empty($accessFrom) ? (int) $accessFrom : null;
    }

    /**
     * Get enrolled users for a course
     */
    public function getEnrolledUsers(int $courseId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        if (function_exists('learndash_get_course_users_access_from_meta')) {
            $users = learndash_get_course_users_access_from_meta($courseId);
            return is_array($users) ? array_keys($users) : [];
        }

        // Fallback: query user meta (required for LearnDash-specific meta structure)
        global $wpdb;

        // Explicitly validate course ID is an integer
        $courseId = absint($courseId);
        if ($courseId === 0) {
            return [];
        }

        $metaKey = 'course_' . $courseId . '_access_from';

        $userIds = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
            $metaKey
        ));

        return array_map('intval', $userIds);
    }

    /**
     * Enroll user in course
     */
    public function enrollUser(int $userId, int $courseId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        if (function_exists('ld_update_course_access')) {
            return ld_update_course_access($userId, $courseId, false);
        }

        return false;
    }

    /**
     * Unenroll user from course
     */
    public function unenrollUser(int $userId, int $courseId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        if (function_exists('ld_update_course_access')) {
            return ld_update_course_access($userId, $courseId, true);
        }

        return false;
    }

    /**
     * Check if course is in specific category
     */
    public function hasCategory(int $courseId, string $categoryName): bool
    {
        return has_term($categoryName, 'ld_course_category', $courseId);
    }

    /**
     * Check if user completed course
     */
    public function isCompleted(int $userId, int $courseId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        if (function_exists('learndash_course_completed')) {
            return learndash_course_completed($userId, $courseId);
        }

        return false;
    }

    /**
     * Mark course as complete for user
     *
     * Uses LearnDash's internal function to mark course complete.
     * This triggers all LearnDash completion hooks and enables certificate access.
     */
    public function markComplete(int $userId, int $courseId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        // LearnDash 3.x+ uses learndash_process_mark_complete
        if (function_exists('learndash_process_mark_complete')) {
            // Mark the course post as complete
            // Parameters: $user_id, $post_id, $autostart_complete, $parent_id
            learndash_process_mark_complete($userId, $courseId, false, 0);
            return true;
        }

        // Fallback: Direct activity update for older versions
        if (function_exists('learndash_update_user_activity')) {
            learndash_update_user_activity([
                'user_id' => $userId,
                'course_id' => $courseId,
                'post_id' => $courseId,
                'activity_type' => 'course',
                'activity_status' => 1, // 1 = complete
                'activity_completed' => time(),
            ]);
            return true;
        }

        return false;
    }

    /**
     * Get course certificate link for user
     */
    public function getCertificateLink(int $courseId, int $userId): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if (function_exists('learndash_get_course_certificate_link')) {
            $link = learndash_get_course_certificate_link($courseId, $userId);
            return !empty($link) ? $link : null;
        }

        return null;
    }
}
