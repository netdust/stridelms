<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use ntdst\Stride\contracts\LearnDashAdapterInterface;
use ntdst\Stride\adapters\LearnDashAdapter;
use ntdst\Stride\FieldRegistry;
use WP_Error;

/**
 * Course Service (Thin Version)
 *
 * LearnDash wrapper providing a clean API for course content operations.
 * This is the CONTENT layer - handles course structure, modules, completion.
 *
 * For scheduled offerings (dates, pricing, capacity), use EditionService.
 * For user registrations, use RegistrationRepository.
 *
 * Available filters:
 * - netdust_course_config - Customize service configuration
 * - stride/course/in_person_category - Override category name for in-person courses
 *
 * @package stride\services\core
 */
class CourseService implements \NTDST_Service_Meta
{
    private array $config;
    private LearnDashAdapterInterface $learndash;

    /**
     * Request-level cache for course settings
     */
    private array $settingsCache = [];

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Course Service',
            'description' => 'LearnDash course content and access management',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor
     */
    public function __construct(?LearnDashAdapterInterface $learndash = null)
    {
        $this->learndash = $learndash ?? new LearnDashAdapter();
        $this->config = $this->getDefaultConfig();
        $this->init();
    }

    /**
     * Get configuration with filter for customization
     */
    private function getDefaultConfig(): array
    {
        return apply_filters('netdust_course_config', [
            'in_person_category' => FieldRegistry::CATEGORY_IN_PERSON,
            'cache_ttl' => 300,
        ]);
    }

    /**
     * Initialize service hooks
     */
    private function init(): void
    {
        add_action('init', [$this, 'registerHooks'], 20);
    }

    /**
     * Register WordPress hooks
     */
    public function registerHooks(): void
    {
        do_action('stride/course_service_ready', $this);
    }

    // ========================================
    // LEARNDASH AVAILABILITY
    // ========================================

    /**
     * Check if LearnDash is available
     */
    public function isAvailable(): bool
    {
        return $this->learndash->isAvailable();
    }

    // ========================================
    // COURSE TYPE DETECTION
    // ========================================

    /**
     * Check if course is in-person (has VAD vormingen category)
     */
    public function isInPerson(int $courseId): bool
    {
        $category = apply_filters(
            'stride/course/in_person_category',
            $this->config['in_person_category']
        );

        return $this->learndash->hasCategory($courseId, $category);
    }

    /**
     * Check if course is online (no VAD vormingen category)
     */
    public function isOnline(int $courseId): bool
    {
        return !$this->isInPerson($courseId);
    }

    /**
     * Check if course is a trajectory (has modules)
     */
    public function isTraject(int $courseId): bool
    {
        $modules = $this->getCourseModules($courseId);
        return !empty($modules);
    }

    // ========================================
    // COURSE DATA
    // ========================================

    /**
     * Get course post object
     */
    public function getCourse(int $courseId): ?\WP_Post
    {
        return $this->learndash->getCourse($courseId);
    }

    /**
     * Get course title
     */
    public function getCourseTitle(int $courseId): ?string
    {
        $course = $this->getCourse($courseId);
        return $course ? $course->post_title : null;
    }

    /**
     * Check if course exists and is valid
     */
    public function validateCourse(int $courseId): true|WP_Error
    {
        if ($courseId <= 0) {
            return new WP_Error('invalid_course_id', __('Ongeldige cursus ID.', 'stride'));
        }

        $course = $this->getCourse($courseId);
        if (!$course) {
            return new WP_Error('course_not_found', __('Cursus niet gevonden.', 'stride'), ['course_id' => $courseId]);
        }

        return true;
    }

    /**
     * Get any course setting
     */
    public function getCourseSetting(int $courseId, string $key): mixed
    {
        return $this->learndash->getCourseSetting($courseId, $key);
    }

    // ========================================
    // MODULES (TRAJECTORIES)
    // ========================================

    /**
     * Get course modules (for trajectory courses)
     *
     * @return array Array of course IDs
     */
    public function getCourseModules(int $courseId): array
    {
        $modules = $this->getCachedSetting($courseId, FieldRegistry::COURSE_MODULES);

        if (!is_array($modules)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $modules)));
    }

    /**
     * Check if course is a module (part of another course)
     */
    public function isModuleCourse(int $courseId): bool
    {
        return $this->getCachedSetting($courseId, FieldRegistry::COURSE_MODULES_ENABLED) === 'on';
    }

    // ========================================
    // GROUPS (LEARNDASH)
    // ========================================

    /**
     * Get LearnDash group post object
     */
    public function getGroup(int $groupId): ?\WP_Post
    {
        $post = get_post($groupId);

        if (!$post || $post->post_type !== 'groups') {
            return null;
        }

        return $post;
    }

    /**
     * Get group title
     */
    public function getGroupTitle(int $groupId): ?string
    {
        $group = $this->getGroup($groupId);
        return $group ? $group->post_title : null;
    }

    /**
     * Check if group exists and is valid
     */
    public function validateGroup(int $groupId): true|WP_Error
    {
        if ($groupId <= 0) {
            return new WP_Error('invalid_group_id', __('Ongeldige groep ID.', 'stride'));
        }

        $group = $this->getGroup($groupId);
        if (!$group) {
            return new WP_Error('group_not_found', __('Groep niet gevonden.', 'stride'), ['group_id' => $groupId]);
        }

        return true;
    }

    // ========================================
    // USER ENROLLMENT (LEARNDASH ACCESS)
    // ========================================

    /**
     * Check if user has LearnDash access to course
     */
    public function isUserEnrolled(int $userId, int $courseId): bool
    {
        return $this->learndash->hasAccess($courseId, $userId);
    }

    /**
     * Check if user has direct enrollment (not via group)
     */
    public function hasDirectEnrollment(int $userId, int $courseId): bool
    {
        return $this->learndash->getAccessFrom($userId, $courseId) !== null;
    }

    /**
     * Get all users with LearnDash access to course
     *
     * @return array Array of user IDs
     */
    public function getEnrolledUsers(int $courseId): array
    {
        return $this->learndash->getEnrolledUsers($courseId);
    }

    /**
     * Grant LearnDash access to user
     *
     * Note: This grants LMS access only. For full enrollment workflow
     * including registration tracking, use EnrollmentService.
     *
     * @param int $userId
     * @param int $courseId
     * @return true|WP_Error
     */
    public function grantAccess(int $userId, int $courseId): true|WP_Error
    {
        $authCheck = $this->canModifyUser($userId, 'enroll_users');
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        if (!$this->isAvailable()) {
            return new WP_Error('learndash_unavailable', 'LearnDash is not available');
        }

        $course = $this->learndash->getCourse($courseId);
        if (!$course) {
            return new WP_Error('course_not_found', 'Course not found', ['course_id' => $courseId]);
        }

        if ($this->isUserEnrolled($userId, $courseId)) {
            return new WP_Error('already_enrolled', 'User already has access to this course');
        }

        $result = $this->learndash->enrollUser($userId, $courseId);
        if (!$result) {
            return new WP_Error('enrollment_failed', 'Failed to grant access');
        }

        do_action('stride/course/access_granted', $userId, $courseId);

        return true;
    }

    /**
     * Revoke LearnDash access from user
     *
     * @param int $userId
     * @param int $courseId
     * @return true|WP_Error
     */
    public function revokeAccess(int $userId, int $courseId): true|WP_Error
    {
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to revoke access.', 'stride'),
                ['status' => 403]
            );
        }

        if (!$this->isAvailable()) {
            return new WP_Error('learndash_unavailable', 'LearnDash is not available');
        }

        if (!$this->isUserEnrolled($userId, $courseId)) {
            return new WP_Error('not_enrolled', 'User does not have access to this course');
        }

        $result = $this->learndash->unenrollUser($userId, $courseId);
        if (!$result) {
            return new WP_Error('unenrollment_failed', 'Failed to revoke access');
        }

        do_action('stride/course/access_revoked', $userId, $courseId);

        return true;
    }

    // ========================================
    // COMPLETION & CERTIFICATES
    // ========================================

    /**
     * Check if user completed course
     */
    public function isUserCompleted(int $userId, int $courseId): bool
    {
        return $this->learndash->isCompleted($userId, $courseId);
    }

    /**
     * Mark course as complete for user
     *
     * Called by CompletionEngine when edition attendance requirements are met.
     * This triggers LearnDash's course completion which enables certificate access.
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function markComplete(int $userId, int $courseId): true|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error('learndash_unavailable', __('LearnDash is niet beschikbaar.', 'stride'));
        }

        // Validate course
        $validation = $this->validateCourse($courseId);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Check if user has access
        if (!$this->isUserEnrolled($userId, $courseId)) {
            return new WP_Error('not_enrolled', __('Gebruiker heeft geen toegang tot deze cursus.', 'stride'));
        }

        // Already complete?
        if ($this->isUserCompleted($userId, $courseId)) {
            return true; // Already done, success
        }

        // Mark complete via LearnDash
        $result = $this->learndash->markComplete($userId, $courseId);
        if (!$result) {
            return new WP_Error('completion_failed', __('Kon cursus niet als voltooid markeren.', 'stride'));
        }

        do_action('stride/course/marked_complete', $userId, $courseId);

        return true;
    }

    /**
     * Get certificate link for user
     *
     * Note: Certificate availability should be checked via EditionService
     * (isCertificateEnabled). This method returns the LearnDash link if
     * the user has completed the course.
     */
    public function getCertificateLink(int $userId, int $courseId): ?string
    {
        if (!$this->isUserCompleted($userId, $courseId)) {
            return null;
        }

        return $this->learndash->getCertificateLink($courseId, $userId);
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Get user email by ID
     */
    public function getUserDisplayInfo(int $userId): ?string
    {
        $user = get_user_by('ID', $userId);
        return $user ? $user->user_email : null;
    }

    /**
     * Get course setting with request-level caching
     */
    private function getCachedSetting(int $courseId, string $key): mixed
    {
        $cacheKey = $courseId . '_' . $key;

        if (!isset($this->settingsCache[$cacheKey])) {
            $this->settingsCache[$cacheKey] = $this->learndash->getCourseSetting($courseId, $key);
        }

        return $this->settingsCache[$cacheKey];
    }

    /**
     * Clear settings cache for a course
     */
    public function clearSettingsCache(?int $courseId = null): void
    {
        if ($courseId === null) {
            $this->settingsCache = [];
        } else {
            foreach (array_keys($this->settingsCache) as $key) {
                if (str_starts_with($key, $courseId . '_')) {
                    unset($this->settingsCache[$key]);
                }
            }
        }
    }

    // ========================================
    // AUTHORIZATION
    // ========================================

    /**
     * Check if current user can manage courses/enrollments
     */
    public function currentUserCanManage(): bool
    {
        return current_user_can('manage_options') || current_user_can('edit_others_courses');
    }

    /**
     * Check if current user can modify another user's enrollment
     */
    private function canModifyUser(int $targetUserId, string $capability = 'manage_options'): true|WP_Error
    {
        $currentUserId = get_current_user_id();

        if (current_user_can($capability) || current_user_can('manage_options')) {
            return true;
        }

        if ($currentUserId > 0 && $currentUserId === $targetUserId) {
            return true;
        }

        $allowed = apply_filters('stride/course/can_modify_enrollment', false, $currentUserId, $targetUserId);
        if ($allowed === true) {
            return true;
        }

        return new WP_Error(
            'unauthorized',
            __('You do not have permission to perform this action.', 'stride'),
            ['status' => 403]
        );
    }

    // ========================================
    // DEPRECATED METHODS
    // Delegate to EditionService - will be removed in future version
    // ========================================

    /**
     * @deprecated Use EditionService::getStartDate()
     */
    public function getStartDate(int $courseId): ?int
    {
        _doing_it_wrong(__METHOD__, 'Use EditionService::getStartDate() instead.', '4.0.0');
        return null;
    }

    /**
     * @deprecated Use EditionService::getEndDate()
     */
    public function getEndDate(int $courseId): ?int
    {
        _doing_it_wrong(__METHOD__, 'Use EditionService::getEndDate() instead.', '4.0.0');
        return null;
    }

    /**
     * @deprecated Use EditionService::isCancelled()
     */
    public function isCancelled(int $courseId): bool
    {
        _doing_it_wrong(__METHOD__, 'Use EditionService::isCancelled() instead.', '4.0.0');
        return false;
    }

    /**
     * @deprecated Use EditionService::isEnrollmentOpen()
     */
    public function isEnrollmentOpen(int $courseId): bool
    {
        _doing_it_wrong(__METHOD__, 'Use EditionService::isEnrollmentOpen() instead.', '4.0.0');
        return true;
    }

    /**
     * @deprecated Use EditionService::getCapacity()
     */
    public function getCapacity(int $courseId): ?int
    {
        _doing_it_wrong(__METHOD__, 'Use EditionService::getCapacity() instead.', '4.0.0');
        return null;
    }

    /**
     * @deprecated Use EditionService::getPrice()
     */
    public function getCoursePrice(int $courseId): ?float
    {
        _doing_it_wrong(__METHOD__, 'Use EditionService::getPrice() instead.', '4.0.0');
        return null;
    }

    /**
     * @deprecated Use EditionService::canUserEnroll()
     */
    public function canUserEnroll(int $userId, int $courseId): true|WP_Error
    {
        _doing_it_wrong(__METHOD__, 'Use EditionService::canUserEnroll() instead.', '4.0.0');
        return true;
    }

    /**
     * @deprecated Use grantAccess() instead
     */
    public function enrollUser(int $userId, int $courseId): true|WP_Error
    {
        _doing_it_wrong(__METHOD__, 'Use CourseService::grantAccess() or EnrollmentService for full workflow.', '4.0.0');
        return $this->grantAccess($userId, $courseId);
    }

    /**
     * @deprecated Use revokeAccess() instead
     */
    public function unenrollUser(int $userId, int $courseId): true|WP_Error
    {
        _doing_it_wrong(__METHOD__, 'Use CourseService::revokeAccess() instead.', '4.0.0');
        return $this->revokeAccess($userId, $courseId);
    }
}
