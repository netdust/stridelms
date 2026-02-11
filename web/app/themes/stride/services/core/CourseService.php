<?php

namespace stride\services\core;

defined('ABSPATH') || exit;

use stride\services\contracts\LearnDashAdapterInterface;
use stride\services\adapters\LearnDashAdapter;
use stride\services\FieldRegistry;
use WP_Error;

/**
 * Course Service
 *
 * LearnDash wrapper providing a clean API for course operations.
 * Handles course type detection, dates, status, capacity, and enrollment.
 *
 * Available filters:
 * - netdust_course_config - Customize service configuration
 * - stride/course/in_person_category - Override category name for in-person courses
 *
 * @package stride
 */
class CourseService implements \NTDST_Service_Meta
{
    private array $config;
    private LearnDashAdapterInterface $learndash;

    /**
     * Request-level cache for course settings
     * Avoids repeated meta queries for the same course
     */
    private array $settingsCache = [];

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Course Service',
            'description' => 'LearnDash course management and operations',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor - dependencies injected by DI container
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
            'cache_ttl' => 300, // 5 minutes
        ]);
    }

    /**
     * Initialize service hooks
     */
    private function init(): void
    {
        // Register hooks after init to ensure LearnDash is loaded
        add_action('init', [$this, 'registerHooks'], 20);
    }

    /**
     * Register WordPress hooks
     */
    public function registerHooks(): void
    {
        // Fire action when service is ready
        do_action('stride/course_service_ready', $this);
    }

    /**
     * Check if LearnDash is available
     */
    public function isAvailable(): bool
    {
        return $this->learndash->isAvailable();
    }

    /**
     * Get course setting with request-level caching
     * PERFORMANCE: Avoids repeated meta queries for the same course
     *
     * @param int $courseId
     * @param string $key
     * @return mixed
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
     * Clear settings cache for a course (useful after updates)
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
    // COURSE TYPE DETECTION
    // ========================================

    /**
     * Check if course is in-person (has VAD vormingen category)
     *
     * @param int $courseId
     * @return bool
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
     *
     * @param int $courseId
     * @return bool
     */
    public function isOnline(int $courseId): bool
    {
        return !$this->isInPerson($courseId);
    }

    /**
     * Check if course is a trajectory (has modules)
     *
     * @param int $courseId
     * @return bool
     */
    public function isTraject(int $courseId): bool
    {
        $modules = $this->getCourseModules($courseId);
        return !empty($modules);
    }

    // ========================================
    // COURSE DATES
    // ========================================

    /**
     * Get course dates as timestamps
     *
     * @param int $courseId
     * @return array Array of Unix timestamps
     */
    public function getCourseDates(int $courseId): array
    {
        $dates = $this->getCachedSetting($courseId, FieldRegistry::COURSE_DATES);

        if (!is_array($dates)) {
            return [];
        }

        // Filter and sort valid timestamps
        $validDates = array_filter($dates, fn($d) => is_numeric($d) && $d > 0);
        sort($validDates);

        return array_values(array_map('intval', $validDates));
    }

    /**
     * Get course start date (first date)
     *
     * @param int $courseId
     * @return int|null Unix timestamp or null
     */
    public function getStartDate(int $courseId): ?int
    {
        $dates = $this->getCourseDates($courseId);

        return !empty($dates) ? $dates[0] : null;
    }

    /**
     * Get course end date (last date)
     *
     * @param int $courseId
     * @return int|null Unix timestamp or null
     */
    public function getEndDate(int $courseId): ?int
    {
        $dates = $this->getCourseDates($courseId);

        return !empty($dates) ? end($dates) : null;
    }

    /**
     * Get next upcoming date
     *
     * @param int $courseId
     * @return int|null Unix timestamp or null
     */
    public function getNextDate(int $courseId): ?int
    {
        $dates = $this->getCourseDates($courseId);
        $now = time();

        foreach ($dates as $date) {
            if ($date > $now) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Check if course has started
     *
     * @param int $courseId
     * @return bool
     */
    public function hasStarted(int $courseId): bool
    {
        $startDate = $this->getStartDate($courseId);

        if ($startDate === null) {
            return false;
        }

        return $startDate <= time();
    }

    /**
     * Check if course has ended
     *
     * @param int $courseId
     * @return bool
     */
    public function hasEnded(int $courseId): bool
    {
        $endDate = $this->getEndDate($courseId);

        if ($endDate === null) {
            return false;
        }

        // Consider ended at end of day
        $endOfDay = strtotime('tomorrow', $endDate) - 1;

        return $endOfDay < time();
    }

    /**
     * Get number of course days
     *
     * @param int $courseId
     * @return int
     */
    public function getDayCount(int $courseId): int
    {
        return count($this->getCourseDates($courseId));
    }

    // ========================================
    // COURSE STATUS
    // ========================================

    /**
     * Check if course is cancelled
     *
     * @param int $courseId
     * @return bool
     */
    public function isCancelled(int $courseId): bool
    {
        return $this->getCachedSetting($courseId, FieldRegistry::COURSE_STATUS_CANCELLED) === 'on';
    }

    /**
     * Check if course is postponed
     *
     * @param int $courseId
     * @return bool
     */
    public function isPostponed(int $courseId): bool
    {
        return $this->getCachedSetting($courseId, FieldRegistry::COURSE_STATUS_POSTPONED) === 'on';
    }

    /**
     * Check if course is full
     *
     * @param int $courseId
     * @return bool
     */
    public function isFull(int $courseId): bool
    {
        return $this->getCachedSetting($courseId, FieldRegistry::COURSE_STATUS_FULL) === 'on';
    }

    /**
     * Check if course is announcement (coming soon)
     *
     * @param int $courseId
     * @return bool
     */
    public function isAnnouncement(int $courseId): bool
    {
        return $this->getCachedSetting($courseId, FieldRegistry::COURSE_STATUS_ANNOUNCEMENT) === 'on';
    }

    /**
     * Check if course is upcoming (not started, not cancelled)
     *
     * @param int $courseId
     * @return bool
     */
    public function isUpcoming(int $courseId): bool
    {
        if ($this->isCancelled($courseId)) {
            return false;
        }

        return !$this->hasStarted($courseId);
    }

    /**
     * Check if enrollment is open
     *
     * @param int $courseId
     * @return bool
     */
    public function isEnrollmentOpen(int $courseId): bool
    {
        // Cannot enroll if cancelled, ended, or announcement-only
        if ($this->isCancelled($courseId)) {
            return false;
        }

        if ($this->hasEnded($courseId)) {
            return false;
        }

        if ($this->isAnnouncement($courseId)) {
            return false;
        }

        if ($this->isFull($courseId)) {
            return false;
        }

        return true;
    }

    // ========================================
    // CAPACITY
    // ========================================

    /**
     * Get course capacity
     *
     * @param int $courseId
     * @return int|null Capacity or null if unlimited
     */
    public function getCapacity(int $courseId): ?int
    {
        $maxParticipants = $this->getCachedSetting($courseId, FieldRegistry::COURSE_MAX_PARTICIPANTS);

        if (empty($maxParticipants)) {
            return null;
        }

        // Parse numeric value from string like "20" or "20 participants"
        $parts = explode(' ', (string) $maxParticipants);
        $capacity = (int) ($parts[0] ?? 0);

        return $capacity > 0 ? $capacity : null;
    }

    /**
     * Get enrolled user count
     *
     * @param int $courseId
     * @return int
     */
    public function getEnrolledCount(int $courseId): int
    {
        return count($this->learndash->getEnrolledUsers($courseId));
    }

    /**
     * Check if course has available spots
     *
     * @param int $courseId
     * @return bool
     */
    public function hasAvailableSpots(int $courseId): bool
    {
        $capacity = $this->getCapacity($courseId);

        // Unlimited capacity
        if ($capacity === null) {
            return true;
        }

        return $this->getEnrolledCount($courseId) < $capacity;
    }

    /**
     * Get number of available spots
     *
     * @param int $courseId
     * @return int|null Available spots or null if unlimited
     */
    public function getAvailableSpots(int $courseId): ?int
    {
        $capacity = $this->getCapacity($courseId);

        if ($capacity === null) {
            return null;
        }

        $available = $capacity - $this->getEnrolledCount($courseId);

        return max(0, $available);
    }

    // ========================================
    // SPEAKERS/SUPERVISORS
    // ========================================

    /**
     * Get course speakers/supervisors
     *
     * @param int $courseId
     * @return array Array of ['name' => string, 'role' => string|null]
     */
    public function getCourseSpeakers(int $courseId): array
    {
        $supervisors = $this->getCachedSetting($courseId, FieldRegistry::COURSE_SPEAKERS);

        if (empty($supervisors)) {
            return [];
        }

        $speakers = [];
        $entries = explode(';', (string) $supervisors);

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if (empty($entry)) {
                continue;
            }

            $parts = explode(',', $entry, 2);
            $name = trim($parts[0]);
            $role = isset($parts[1]) ? trim($parts[1]) : null;

            if (!empty($name)) {
                $speakers[] = [
                    'name' => $name,
                    'role' => $role,
                ];
            }
        }

        return $speakers;
    }

    // ========================================
    // USER ENROLLMENT
    // ========================================

    /**
     * Check if user is enrolled in course
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function isUserEnrolled(int $userId, int $courseId): bool
    {
        return $this->learndash->hasAccess($courseId, $userId);
    }

    /**
     * Check if user has direct enrollment (not via group)
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function hasDirectEnrollment(int $userId, int $courseId): bool
    {
        return $this->learndash->getAccessFrom($userId, $courseId) !== null;
    }

    /**
     * Get all enrolled users for course
     *
     * @param int $courseId
     * @return array Array of user IDs
     */
    public function getEnrolledUsers(int $courseId): array
    {
        return $this->learndash->getEnrolledUsers($courseId);
    }

    /**
     * Enroll user in course
     *
     * SECURITY: Requires admin capability OR current user enrolling themselves.
     *
     * @param int $userId
     * @param int $courseId
     * @return true|WP_Error
     */
    public function enrollUser(int $userId, int $courseId): true|WP_Error
    {
        // Authorization check
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
            return new WP_Error('already_enrolled', 'User is already enrolled in this course');
        }

        $result = $this->learndash->enrollUser($userId, $courseId);

        if (!$result) {
            return new WP_Error('enrollment_failed', 'Failed to enroll user');
        }

        do_action('stride/user_enrolled', $userId, $courseId);

        return true;
    }

    /**
     * Unenroll user from course
     *
     * SECURITY: Requires admin capability. Regular users should go through
     * proper cancellation workflow, not direct unenrollment.
     *
     * @param int $userId
     * @param int $courseId
     * @return true|WP_Error
     */
    public function unenrollUser(int $userId, int $courseId): true|WP_Error
    {
        // Authorization check - admin only for unenrollment
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to unenroll users.', 'stride'),
                ['status' => 403]
            );
        }

        if (!$this->isAvailable()) {
            return new WP_Error('learndash_unavailable', 'LearnDash is not available');
        }

        if (!$this->isUserEnrolled($userId, $courseId)) {
            return new WP_Error('not_enrolled', 'User is not enrolled in this course');
        }

        $result = $this->learndash->unenrollUser($userId, $courseId);

        if (!$result) {
            return new WP_Error('unenrollment_failed', 'Failed to unenroll user');
        }

        do_action('stride/user_unenrolled', $userId, $courseId);

        return true;
    }

    // ========================================
    // MODULES (TRAJECTORIES)
    // ========================================

    /**
     * Get course modules (for trajectory courses)
     *
     * @param int $courseId
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
     *
     * @param int $courseId
     * @return bool
     */
    public function isModuleCourse(int $courseId): bool
    {
        return $this->getCachedSetting($courseId, FieldRegistry::COURSE_MODULES_ENABLED) === 'on';
    }

    // ========================================
    // PRICING
    // ========================================

    /**
     * Get course price (member price)
     *
     * @param int $courseId
     * @return float|null
     */
    public function getCoursePrice(int $courseId): ?float
    {
        $price = $this->getCachedSetting($courseId, FieldRegistry::COURSE_PRICE);

        if ($price === null || $price === '') {
            return null;
        }

        return (float) $price;
    }

    /**
     * Get invoice item ID
     *
     * @param int $courseId
     * @return int|null
     */
    public function getInvoiceItem(int $courseId): ?int
    {
        // Try primary field
        $itemId = $this->getCachedSetting($courseId, FieldRegistry::COURSE_INVOICE_ITEM);

        // Fallback to legacy location (V3 used different key)
        if (empty($itemId)) {
            $legacyKey = FieldRegistry::newToLegacy(FieldRegistry::COURSE_INVOICE_ITEM, 'course');
            $itemId = $this->learndash->getCourseSetting($courseId, $legacyKey);
        }

        return !empty($itemId) ? (int) $itemId : null;
    }

    // ========================================
    // SETTINGS
    // ========================================

    /**
     * Get any course setting
     *
     * @param int $courseId
     * @param string $key
     * @return mixed
     */
    public function getCourseSetting(int $courseId, string $key): mixed
    {
        return $this->learndash->getCourseSetting($courseId, $key);
    }

    /**
     * Check if invoicing is enabled for course
     *
     * @param int $courseId
     * @return bool
     */
    public function isInvoiceEnabled(int $courseId): bool
    {
        $setting = $this->getCachedSetting($courseId, FieldRegistry::COURSE_INVOICE_ENABLED);

        // Default to enabled if not set
        return $setting !== '' && $setting !== null ? $setting === 'on' : true;
    }

    /**
     * Check if certificate is enabled for course
     *
     * @param int $courseId
     * @return bool
     */
    public function isCertificateEnabled(int $courseId): bool
    {
        return $this->getCachedSetting($courseId, FieldRegistry::COURSE_CERTIFICATE_ENABLED) === 'on';
    }

    /**
     * Get custom form for course (FluentForms title)
     *
     * @param int $courseId
     * @return string|null
     */
    public function getCustomForm(int $courseId): ?string
    {
        // Try primary field
        $form = $this->getCachedSetting($courseId, FieldRegistry::COURSE_CUSTOM_FORM);

        // Fallback to legacy locations (V3 used different keys)
        if (empty($form)) {
            $legacyKey = FieldRegistry::newToLegacy(FieldRegistry::COURSE_CUSTOM_FORM, 'course');
            $form = $this->learndash->getCourseSetting($courseId, $legacyKey);
        }

        return !empty($form) ? (string) $form : null;
    }

    /**
     * Get course location/address
     *
     * @param int $courseId
     * @return string|null
     */
    public function getCourseAddress(int $courseId): ?string
    {
        $address = $this->getCachedSetting($courseId, FieldRegistry::COURSE_ADDRESS);

        return !empty($address) ? (string) $address : null;
    }

    // ========================================
    // COMPLETION & CERTIFICATES
    // ========================================

    /**
     * Check if user completed course
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function isUserCompleted(int $userId, int $courseId): bool
    {
        return $this->learndash->isCompleted($userId, $courseId);
    }

    /**
     * Get certificate link for user
     *
     * @param int $userId
     * @param int $courseId
     * @return string|null
     */
    public function getCertificateLink(int $userId, int $courseId): ?string
    {
        if (!$this->isCertificateEnabled($courseId)) {
            return null;
        }

        if (!$this->isUserCompleted($userId, $courseId)) {
            return null;
        }

        return $this->learndash->getCertificateLink($courseId, $userId);
    }

    // ========================================
    // ENROLLMENT VALIDATION
    // ========================================

    /**
     * Check if user can enroll in course
     *
     * @param int $userId
     * @param int $courseId
     * @return true|WP_Error
     */
    public function canUserEnroll(int $userId, int $courseId): true|WP_Error
    {
        if ($this->isUserEnrolled($userId, $courseId)) {
            return new WP_Error(
                'already_enrolled',
                __('U bent al ingeschreven voor deze cursus.', 'stride')
            );
        }

        if ($this->isCancelled($courseId)) {
            return new WP_Error(
                'course_cancelled',
                __('Deze cursus is geannuleerd.', 'stride')
            );
        }

        if ($this->hasEnded($courseId)) {
            return new WP_Error(
                'course_ended',
                __('Deze cursus is reeds afgelopen.', 'stride')
            );
        }

        if ($this->isFull($courseId) || !$this->hasAvailableSpots($courseId)) {
            return new WP_Error(
                'course_full',
                __('Deze cursus is volzet.', 'stride')
            );
        }

        if ($this->isAnnouncement($courseId)) {
            return new WP_Error(
                'course_announcement',
                __('Inschrijvingen voor deze cursus zijn nog niet geopend.', 'stride')
            );
        }

        // Allow external validation
        $externalCheck = apply_filters('stride/can_user_enroll', true, $userId, $courseId);

        if (is_wp_error($externalCheck)) {
            return $externalCheck;
        }

        if ($externalCheck !== true) {
            return new WP_Error(
                'enrollment_blocked',
                __('U heeft geen toegang tot deze vorming.', 'stride')
            );
        }

        return true;
    }

    // ========================================
    // AUTHORIZATION HELPERS
    // ========================================

    /**
     * Check if current user can manage courses/enrollments
     *
     * @return bool
     */
    private function currentUserCanManage(): bool
    {
        return current_user_can('manage_options') || current_user_can('edit_others_courses');
    }

    /**
     * Check if current user can modify another user's enrollment
     *
     * Allows operation if:
     * - Current user has admin/management capability, OR
     * - Current user is the target user (self-enrollment)
     *
     * @param int $targetUserId The user being modified
     * @param string $capability Optional specific capability to check
     * @return true|WP_Error
     */
    private function canModifyUser(int $targetUserId, string $capability = 'manage_options'): true|WP_Error
    {
        $currentUserId = get_current_user_id();

        // Allow admins
        if (current_user_can($capability) || current_user_can('manage_options')) {
            return true;
        }

        // Allow self-modification
        if ($currentUserId > 0 && $currentUserId === $targetUserId) {
            return true;
        }

        // Allow hook to grant access (for custom enrollment workflows)
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
}
