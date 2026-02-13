<?php

namespace ntdst\Stride\contracts;

defined('ABSPATH') || exit;

/**
 * LearnDash Adapter Interface
 *
 * Abstraction layer for LearnDash functions to enable testing.
 * The production implementation wraps actual LearnDash functions.
 * Tests can provide mock implementations.
 *
 * @package stride
 */
interface LearnDashAdapterInterface
{
    /**
     * Check if LearnDash is available
     */
    public function isAvailable(): bool;

    /**
     * Get a course post object
     *
     * @param int $courseId
     * @return \WP_Post|null
     */
    public function getCourse(int $courseId): ?\WP_Post;

    /**
     * Get course meta setting
     *
     * @param int $courseId
     * @param string $key
     * @return mixed
     */
    public function getCourseSetting(int $courseId, string $key): mixed;

    /**
     * Get all course settings
     *
     * @param int $courseId
     * @return array
     */
    public function getCourseSettings(int $courseId): array;

    /**
     * Check if user has access to course
     *
     * @param int $courseId
     * @param int $userId
     * @return bool
     */
    public function hasAccess(int $courseId, int $userId): bool;

    /**
     * Get course access timestamp for user
     *
     * @param int $userId
     * @param int $courseId
     * @return int|null
     */
    public function getAccessFrom(int $userId, int $courseId): ?int;

    /**
     * Get enrolled users for a course
     *
     * @param int $courseId
     * @return array Array of user IDs
     */
    public function getEnrolledUsers(int $courseId): array;

    /**
     * Enroll user in course
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function enrollUser(int $userId, int $courseId): bool;

    /**
     * Unenroll user from course
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function unenrollUser(int $userId, int $courseId): bool;

    /**
     * Check if course is in specific category
     *
     * @param int $courseId
     * @param string $categoryName
     * @return bool
     */
    public function hasCategory(int $courseId, string $categoryName): bool;

    /**
     * Check if user completed course
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function isCompleted(int $userId, int $courseId): bool;

    /**
     * Mark course as complete for user
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function markComplete(int $userId, int $courseId): bool;

    /**
     * Get course certificate link for user
     *
     * @param int $courseId
     * @param int $userId
     * @return string|null
     */
    public function getCertificateLink(int $courseId, int $userId): ?string;
}
