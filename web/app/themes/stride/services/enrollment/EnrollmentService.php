<?php

namespace stride\services\enrollment;

defined('ABSPATH') || exit;

use stride\services\core\CourseService;
use stride\services\core\SubscriberService;
use stride\services\sync\UserDataSync;
use stride\services\FieldRegistry;
use WP_Error;

/**
 * Enrollment Service
 *
 * Main orchestrator for course and trajectory enrollments.
 * Handles validation, profile sync, organization sync, and CRM audit notes.
 *
 * Delegates validation to CourseService::canUserEnroll() to avoid duplication.
 * Uses inline sync methods rather than separate handler classes for simplicity.
 *
 * Available hooks:
 * - stride/enrollment/before_enroll (filter) - Modify data or abort (return WP_Error)
 * - stride/enrollment/completed (action) - Post-enrollment (for Phase 3 quotes)
 * - stride/enrollment/group_completed (action) - Post-trajectory enrollment
 * - stride/enrollment/unenrolled (action) - After unenrollment
 *
 * @package stride\services\enrollment
 */
class EnrollmentService implements \NTDST_Service_Meta
{
    private CourseService $courseService;
    private SubscriberService $subscriberService;
    private UserDataSync $userDataSync;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Service',
            'description' => 'Handles course and trajectory enrollments',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(
        ?CourseService $courseService = null,
        ?SubscriberService $subscriberService = null,
        ?UserDataSync $userDataSync = null
    ) {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->subscriberService = $subscriberService ?? $this->resolveService(SubscriberService::class);
        $this->userDataSync = $userDataSync ?? $this->resolveService(UserDataSync::class);
    }

    /**
     * Resolve service from DI container or create new instance
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through to create new instance
            }
        }
        return new $class();
    }

    /**
     * Enroll a user in a course
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @param array{
     *   first_name?: string,
     *   last_name?: string,
     *   phone?: string,
     *   profile_type?: string,
     *   department?: string,
     *   company_id?: int,
     *   invoice_org_name?: string,
     *   invoice_address?: string,
     *   invoice_city?: string,
     *   invoice_postal_code?: string,
     *   invoice_vat?: string,
     *   invoice_gln?: string,
     *   invoice_email?: string,
     *   enrolled_by_user_id?: int,
     *   enrollment_path?: string
     * } $data Enrollment data
     * @return true|WP_Error
     */
    public function enrollUser(int $userId, int $courseId, array $data = []): true|WP_Error
    {
        // 1. Validate via existing CourseService (avoids duplicate validation logic)
        $canEnroll = $this->courseService->canUserEnroll($userId, $courseId);
        if (is_wp_error($canEnroll)) {
            return $canEnroll;
        }

        // 2. Allow pre-enrollment modification or abort via filter
        $data = apply_filters('stride/enrollment/before_enroll', $data, $userId, $courseId);
        if (is_wp_error($data)) {
            return $data;
        }

        // 3. Sync profile fields from enrollment data
        $this->syncProfile($userId, $data);

        // 4. Sync organization (link to company or store invoice data)
        $this->syncOrganization($userId, $data);

        // 5. Perform LearnDash enrollment via CourseService
        $result = $this->courseService->enrollUser($userId, $courseId);
        if (is_wp_error($result)) {
            return $result;
        }

        // 6. Track manager relationship if enrolled by someone else
        $this->trackManagedEnrollment($userId, $courseId, $data);

        // 7. Create CRM audit note
        $this->createEnrollmentNote($userId, $courseId, $data);

        // 8. Fire completion hook for Phase 3/4 extensions (quotes, vouchers)
        do_action('stride/enrollment/completed', $userId, $courseId, $data);

        return true;
    }

    /**
     * Enroll a user in a LearnDash group (trajectory)
     *
     * @param int $userId WordPress user ID
     * @param int $groupId LearnDash group ID
     * @param array $data Enrollment data (same structure as enrollUser)
     * @return true|WP_Error
     */
    public function enrollUserInGroup(int $userId, int $groupId, array $data = []): true|WP_Error
    {
        // Validate group exists and is correct post type
        $group = get_post($groupId);
        if (!$group || $group->post_type !== 'groups') {
            return new WP_Error('invalid_group', __('Ongeldig traject.', 'stride'));
        }

        // Allow pre-enrollment modification or abort via filter
        $data = apply_filters('stride/enrollment/before_group_enroll', $data, $userId, $groupId);
        if (is_wp_error($data)) {
            return $data;
        }

        // Sync profile and organization
        $this->syncProfile($userId, $data);
        $this->syncOrganization($userId, $data);

        // Perform LearnDash group enrollment
        if (function_exists('ld_update_group_access')) {
            ld_update_group_access($userId, $groupId);
        } else {
            return new WP_Error('learndash_unavailable', __('LearnDash is niet beschikbaar.', 'stride'));
        }

        // Track manager relationship
        $this->trackManagedEnrollment($userId, $groupId, $data, 'group');

        // Create CRM note
        $this->createGroupEnrollmentNote($userId, $groupId, $data);

        // Fire completion hook
        do_action('stride/enrollment/group_completed', $userId, $groupId, $data);

        return true;
    }

    /**
     * Unenroll a user from a course
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return true|WP_Error
     */
    public function unenrollUser(int $userId, int $courseId): true|WP_Error
    {
        $result = $this->courseService->unenrollUser($userId, $courseId);

        if (is_wp_error($result)) {
            return $result;
        }

        // Clean up manager tracking
        delete_user_meta($userId, "stride_enrolled_by_{$courseId}");

        do_action('stride/enrollment/unenrolled', $userId, $courseId);

        return true;
    }

    /**
     * Unenroll a user from a group (trajectory)
     *
     * @param int $userId WordPress user ID
     * @param int $groupId LearnDash group ID
     * @return true|WP_Error
     */
    public function unenrollUserFromGroup(int $userId, int $groupId): true|WP_Error
    {
        if (!function_exists('ld_update_group_access')) {
            return new WP_Error('learndash_unavailable', __('LearnDash is niet beschikbaar.', 'stride'));
        }

        ld_update_group_access($userId, $groupId, true); // true = remove

        // Clean up manager tracking
        delete_user_meta($userId, "stride_enrolled_by_group_{$groupId}");

        do_action('stride/enrollment/group_unenrolled', $userId, $groupId);

        return true;
    }

    /**
     * Sync profile fields from enrollment data to user backends
     */
    private function syncProfile(int $userId, array $data): void
    {
        $fields = array_filter([
            FieldRegistry::FIELD_FIRST_NAME => $data['first_name'] ?? null,
            FieldRegistry::FIELD_LAST_NAME => $data['last_name'] ?? null,
            FieldRegistry::FIELD_PHONE => $data['phone'] ?? null,
            FieldRegistry::SUBSCRIBER_PROFILE_TYPE => $data['profile_type'] ?? null,
            FieldRegistry::SUBSCRIBER_DEPARTMENT => $data['department'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        if (!empty($fields)) {
            $this->userDataSync->setFields($userId, $fields);
        }
    }

    /**
     * Sync organization data - either link to existing company or store invoice data
     */
    private function syncOrganization(int $userId, array $data): void
    {
        if (!empty($data['company_id'])) {
            // Link to existing FluentCRM company
            $this->subscriberService->linkToCompany($userId, (int) $data['company_id']);
        } elseif (!empty($data['invoice_org_name'])) {
            // Store invoice data on subscriber (new/typed organization)
            $invoiceFields = array_filter([
                FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME => $data['invoice_org_name'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS => $data['invoice_address'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_CITY => $data['invoice_city'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE => $data['invoice_postal_code'] ?? null,
                FieldRegistry::SUBSCRIBER_VAT_NUMBER => $data['invoice_vat'] ?? null,
                FieldRegistry::SUBSCRIBER_GLN_NUMBER => $data['invoice_gln'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_EMAIL => $data['invoice_email'] ?? null,
            ], fn($v) => $v !== null && $v !== '');

            if (!empty($invoiceFields)) {
                $this->userDataSync->setFields($userId, $invoiceFields);
            }
        }
    }

    /**
     * Track who enrolled whom (for colleague enrollments)
     * Stores simple user meta for the relationship
     */
    private function trackManagedEnrollment(int $userId, int $targetId, array $data, string $type = 'course'): void
    {
        $enrolledByUserId = $data['enrolled_by_user_id'] ?? get_current_user_id();

        // Only track if enrolled by someone else
        if ($enrolledByUserId && $enrolledByUserId !== $userId) {
            $metaKey = $type === 'group'
                ? "stride_enrolled_by_group_{$targetId}"
                : "stride_enrolled_by_{$targetId}";

            update_user_meta($userId, $metaKey, $enrolledByUserId);
        }
    }

    /**
     * Create CRM audit note for course enrollment
     */
    private function createEnrollmentNote(int $userId, int $courseId, array $data): void
    {
        $courseTitle = get_the_title($courseId);
        $note = sprintf(__('Ingeschreven voor: %s', 'stride'), $courseTitle);

        // Add manager info if enrolled by someone else
        $enrolledByUserId = $data['enrolled_by_user_id'] ?? null;
        if ($enrolledByUserId && $enrolledByUserId !== $userId) {
            $manager = get_userdata($enrolledByUserId);
            $note .= sprintf(' (door %s)', $manager->user_email ?? 'onbekend');
        }

        // Add enrollment path for audit trail
        $path = $data['enrollment_path'] ?? 'individual';
        $note .= sprintf(' [%s]', $path);

        $this->subscriberService->createNote($userId, $note);
    }

    /**
     * Create CRM audit note for group/trajectory enrollment
     */
    private function createGroupEnrollmentNote(int $userId, int $groupId, array $data): void
    {
        $groupTitle = get_the_title($groupId);
        $note = sprintf(__('Ingeschreven voor traject: %s', 'stride'), $groupTitle);

        // Add manager info if enrolled by someone else
        $enrolledByUserId = $data['enrolled_by_user_id'] ?? null;
        if ($enrolledByUserId && $enrolledByUserId !== $userId) {
            $manager = get_userdata($enrolledByUserId);
            $note .= sprintf(' (door %s)', $manager->user_email ?? 'onbekend');
        }

        $this->subscriberService->createNote($userId, $note);
    }

    // ========================================
    // QUERY METHODS
    // ========================================

    /**
     * Get who enrolled a user in a course (manager tracking)
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return int|null Manager user ID or null
     */
    public function getEnrollingManager(int $userId, int $courseId): ?int
    {
        $managerId = get_user_meta($userId, "stride_enrolled_by_{$courseId}", true);
        return $managerId ? (int) $managerId : null;
    }

    /**
     * Get who enrolled a user in a group (manager tracking)
     *
     * @param int $userId WordPress user ID
     * @param int $groupId LearnDash group ID
     * @return int|null Manager user ID or null
     */
    public function getEnrollingManagerForGroup(int $userId, int $groupId): ?int
    {
        $managerId = get_user_meta($userId, "stride_enrolled_by_group_{$groupId}", true);
        return $managerId ? (int) $managerId : null;
    }

    /**
     * Check if user was enrolled by someone else (managed enrollment)
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return bool
     */
    public function isManaged(int $userId, int $courseId): bool
    {
        return $this->getEnrollingManager($userId, $courseId) !== null;
    }

    /**
     * Check if user was enrolled in group by someone else
     *
     * @param int $userId WordPress user ID
     * @param int $groupId LearnDash group ID
     * @return bool
     */
    public function isManagedGroup(int $userId, int $groupId): bool
    {
        return $this->getEnrollingManagerForGroup($userId, $groupId) !== null;
    }
}
