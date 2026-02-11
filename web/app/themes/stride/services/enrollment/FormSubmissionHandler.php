<?php

namespace stride\services\enrollment;

defined('ABSPATH') || exit;

use stride\services\sync\UserDataSync;
use WP_Error;

/**
 * Form Submission Handler
 *
 * Handles FluentForms enrollment form submissions and routes them
 * to the appropriate enrollment path in EnrollmentService.
 *
 * Supports 4 enrollment paths:
 * - individual: Single user enrolling in a course
 * - colleague: Manager enrolling multiple colleagues
 * - trajectory: User enrolling in a LearnDash group
 * - interest: User expressing interest (no enrollment)
 *
 * Supports multiple form field naming conventions (Dutch/English)
 * to handle legacy forms and new standardized forms.
 *
 * @package stride\services\enrollment
 */
class FormSubmissionHandler implements \NTDST_Service_Meta
{
    private EnrollmentService $enrollmentService;
    private UserDataSync $userDataSync;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Form Handler',
            'description' => 'Handles FluentForms enrollment submissions',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 15,
        ];
    }

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(
        ?EnrollmentService $enrollmentService = null,
        ?UserDataSync $userDataSync = null
    ) {
        $this->enrollmentService = $enrollmentService ?? $this->resolveService(EnrollmentService::class);
        $this->userDataSync = $userDataSync ?? $this->resolveService(UserDataSync::class);

        $this->register();
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
     * Register FluentForms hook
     */
    private function register(): void
    {
        add_action('fluentform/before_insert_submission', [$this, 'handleSubmission'], 10, 3);
    }

    /**
     * Handle FluentForms submission
     *
     * @param array $insertData Data to be inserted into submissions table
     * @param array $data Form field data
     * @param object $form Form object
     */
    public function handleSubmission($insertData, $data, $form): void
    {
        $path = $this->detectEnrollmentPath($form->id, $data);

        if (!$path) {
            return; // Not an enrollment form
        }

        $formId = (int) $form->id;

        $result = match ($path) {
            'individual' => $this->handleIndividual($data, $formId),
            'colleague' => $this->handleColleague($data, $formId),
            'trajectory' => $this->handleTrajectory($data, $formId),
            'interest' => $this->handleInterest($data),
            default => null,
        };

        // Log errors for debugging (form will show its configured confirmation/error)
        if (is_wp_error($result)) {
            if (function_exists('ntdst_log')) {
                ntdst_log()->error('Enrollment failed', [
                    'error' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                    'form_id' => $form->id,
                    'path' => $path,
                ]);
            }
        }
    }

    /**
     * Detect enrollment path from form data
     *
     * Priority:
     * 1. Trajectory if group_id is present
     * 2. Colleague if repeater field is present
     * 3. Interest if no course_id but interest field present
     * 4. Individual if course_id is present
     * 5. null if not an enrollment form
     *
     * @param int $formId Form ID
     * @param array $data Form data
     * @return string|null Enrollment path or null
     */
    private function detectEnrollmentPath(int $formId, array $data): ?string
    {
        // Check for trajectory (group_id in hidden field or URL param)
        if (!empty($data['group_id']) || !empty($data['traject_id'])) {
            return 'trajectory';
        }

        // Check for colleague enrollment (repeater field with colleague data)
        if (!empty($data['collegas']) || !empty($data['repeater_field_collegas'])) {
            return 'colleague';
        }

        // Check for interest form (no course_id, but has interest indicator)
        if (empty($data['course_id']) && empty($data['cursus_id']) && empty($data['vorming_id'])) {
            // Only treat as interest if there's an interest-specific field
            if (!empty($data['interesse']) || !empty($data['interest_course_id'])) {
                return 'interest';
            }
            return null; // Not an enrollment form
        }

        // Default: individual enrollment
        return 'individual';
    }

    /**
     * Handle individual course enrollment
     *
     * @param array $formData Form field data
     * @param int $formId FluentForms form ID
     * @return true|WP_Error
     */
    private function handleIndividual(array $formData, int $formId): true|WP_Error
    {
        $courseId = $this->extractCourseId($formData);
        if (!$courseId) {
            return new WP_Error('missing_course', __('Geen cursus gevonden.', 'stride'));
        }

        // Security: Validate enrollment is allowed for this form/course
        $validationResult = $this->validateEnrollmentAllowed($formId, $courseId, 'course');
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        $email = $this->extractEmail($formData);
        if (!$email) {
            return new WP_Error('missing_email', __('E-mailadres is verplicht.', 'stride'));
        }

        // Find or create user
        $userId = $this->userDataSync->findOrCreateUser(
            $email,
            $formData['voornaam'] ?? $formData['first_name'] ?? '',
            $formData['achternaam'] ?? $formData['last_name'] ?? ''
        );

        if (is_wp_error($userId)) {
            return $userId;
        }

        // Build enrollment data from form
        $enrollmentData = $this->buildEnrollmentData($formData, 'individual');

        return $this->enrollmentService->enrollUser($userId, $courseId, $enrollmentData);
    }

    /**
     * Handle colleague/group enrollment (repeater field)
     *
     * @param array $formData Form field data
     * @param int $formId FluentForms form ID
     * @return true|WP_Error
     */
    private function handleColleague(array $formData, int $formId): true|WP_Error
    {
        $courseId = $this->extractCourseId($formData);
        if (!$courseId) {
            return new WP_Error('missing_course', __('Geen cursus gevonden.', 'stride'));
        }

        // Security: Validate enrollment is allowed for this form/course
        $validationResult = $this->validateEnrollmentAllowed($formId, $courseId, 'course');
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        // Get colleagues from repeater field
        $colleagues = $formData['collegas'] ?? $formData['repeater_field_collegas'] ?? [];
        if (empty($colleagues) || !is_array($colleagues)) {
            return new WP_Error('no_colleagues', __('Geen collega\'s opgegeven.', 'stride'));
        }

        // Validate unique emails in repeater
        $emails = array_filter(array_column($colleagues, 'email'));
        if (count($emails) !== count(array_unique($emails))) {
            return new WP_Error('duplicate_emails', __('Dubbele e-mailadressen gevonden.', 'stride'));
        }

        // Get/create manager (form submitter)
        $managerEmail = $this->extractEmail($formData);
        if (!$managerEmail) {
            return new WP_Error('missing_email', __('E-mailadres van beheerder is verplicht.', 'stride'));
        }

        $managerId = $this->userDataSync->findOrCreateUser(
            $managerEmail,
            $formData['voornaam'] ?? $formData['first_name'] ?? '',
            $formData['achternaam'] ?? $formData['last_name'] ?? ''
        );

        if (is_wp_error($managerId)) {
            return $managerId;
        }

        // Allow custom validation/rate limiting for colleague enrollments
        $allowed = apply_filters('stride/enrollment/validate_colleague_enrollment', true, $managerId, $courseId, $colleagues);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        if ($allowed === false) {
            return new WP_Error('enrollment_blocked', __('Inschrijving geblokkeerd.', 'stride'));
        }

        $errors = [];
        $successes = 0;

        // Enroll each colleague
        foreach ($colleagues as $colleague) {
            if (!is_array($colleague)) {
                continue;
            }

            $colleagueEmail = $colleague['email'] ?? '';
            if (empty($colleagueEmail) || !is_email($colleagueEmail)) {
                continue;
            }

            // Find or create colleague user
            $colleagueUserId = $this->userDataSync->findOrCreateUser(
                $colleagueEmail,
                $colleague['voornaam'] ?? $colleague['first_name'] ?? '',
                $colleague['achternaam'] ?? $colleague['last_name'] ?? ''
            );

            if (is_wp_error($colleagueUserId)) {
                // Log detailed error server-side only (prevent user enumeration)
                $this->logEnrollmentError('user_creation_failed', $colleagueUserId->get_error_code(), $courseId);
                $errors[] = 'user_creation_failed';
                continue;
            }

            // Build enrollment data with manager tracking and colleague's name
            $enrollmentData = $this->buildEnrollmentData($formData, 'colleague');
            $enrollmentData['enrolled_by_user_id'] = $managerId;
            $enrollmentData['first_name'] = $colleague['voornaam'] ?? $colleague['first_name'] ?? '';
            $enrollmentData['last_name'] = $colleague['achternaam'] ?? $colleague['last_name'] ?? '';

            $result = $this->enrollmentService->enrollUser($colleagueUserId, $courseId, $enrollmentData);

            if (is_wp_error($result)) {
                // Log detailed error server-side only (prevent user enumeration)
                $this->logEnrollmentError('enrollment_failed', $result->get_error_code(), $courseId);
                $errors[] = 'enrollment_failed';
            } else {
                $successes++;
            }
        }

        // Return generic error message (prevent user enumeration via specific errors)
        if ($successes === 0 && !empty($errors)) {
            return new WP_Error(
                'enrollment_failed',
                __('Inschrijvingen konden niet worden verwerkt. Controleer de gegevens en probeer opnieuw.', 'stride')
            );
        }

        // Partial success - some enrolled, some failed
        if (!empty($errors)) {
            // Fire hook for admin notification of partial failures
            do_action('stride/enrollment/colleague_partial_failure', $successes, count($errors), $courseId, $managerId);
        }

        return true;
    }

    /**
     * Handle trajectory enrollment
     *
     * @param array $formData Form field data
     * @param int $formId FluentForms form ID
     * @return true|WP_Error
     */
    private function handleTrajectory(array $formData, int $formId): true|WP_Error
    {
        $groupId = absint($formData['group_id'] ?? $formData['traject_id'] ?? 0);
        if (!$groupId) {
            return new WP_Error('missing_group', __('Geen traject gevonden.', 'stride'));
        }

        // Security: Validate enrollment is allowed for this form/group
        $validationResult = $this->validateEnrollmentAllowed($formId, $groupId, 'group');
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        $email = $this->extractEmail($formData);
        if (!$email) {
            return new WP_Error('missing_email', __('E-mailadres is verplicht.', 'stride'));
        }

        $userId = $this->userDataSync->findOrCreateUser(
            $email,
            $formData['voornaam'] ?? $formData['first_name'] ?? '',
            $formData['achternaam'] ?? $formData['last_name'] ?? ''
        );

        if (is_wp_error($userId)) {
            return $userId;
        }

        $enrollmentData = $this->buildEnrollmentData($formData, 'trajectory');

        return $this->enrollmentService->enrollUserInGroup($userId, $groupId, $enrollmentData);
    }

    /**
     * Handle interest/waitlist form (no enrollment)
     *
     * @param array $formData Form field data
     * @return true|WP_Error
     */
    private function handleInterest(array $formData): true|WP_Error
    {
        $email = $this->extractEmail($formData);
        if (!$email) {
            return new WP_Error('missing_email', __('E-mailadres is verplicht.', 'stride'));
        }

        // Find or create user for tracking
        $userId = $this->userDataSync->findOrCreateUser(
            $email,
            $formData['voornaam'] ?? $formData['first_name'] ?? '',
            $formData['achternaam'] ?? $formData['last_name'] ?? ''
        );

        if (is_wp_error($userId)) {
            return $userId;
        }

        // Get course ID if present (for interest tracking)
        $courseId = $this->extractCourseId($formData) ?: (int) ($formData['interest_course_id'] ?? 0);

        // Fire hook for interest tracking - no actual enrollment
        do_action('stride/enrollment/interest_registered', $userId, $courseId, $formData);

        return true;
    }

    /**
     * Extract course ID from form data
     * Supports multiple field naming conventions
     *
     * @param array $formData Form field data
     * @return int|null Course ID or null
     */
    private function extractCourseId(array $formData): ?int
    {
        $courseId = $formData['course_id']
            ?? $formData['cursus_id']
            ?? $formData['vorming_id']
            ?? $formData['hidden_course_id']
            ?? null;

        return $courseId ? absint($courseId) : null;
    }

    /**
     * Extract email from form data
     * Supports multiple field naming conventions
     *
     * @param array $formData Form field data
     * @return string|null Email or null
     */
    private function extractEmail(array $formData): ?string
    {
        $email = $formData['email']
            ?? $formData['e-mail']
            ?? $formData['email_address']
            ?? $formData['e_mail']
            ?? null;

        return $email && is_email($email) ? sanitize_email($email) : null;
    }

    /**
     * Build enrollment data array from form data
     * Maps form fields to enrollment data structure
     *
     * @param array $formData Form field data
     * @param string $path Enrollment path identifier
     * @return array Enrollment data
     */
    private function buildEnrollmentData(array $formData, string $path): array
    {
        // Parse organization field - numeric = company ID, string = new org name
        $orgField = $formData['organisations'] ?? $formData['organisatie'] ?? $formData['organization'] ?? '';
        $companyId = is_numeric($orgField) ? absint($orgField) : null;
        $isNewOrg = !$companyId && !empty($orgField);

        return [
            // Profile fields
            'first_name' => sanitize_text_field($formData['voornaam'] ?? $formData['first_name'] ?? ''),
            'last_name' => sanitize_text_field($formData['achternaam'] ?? $formData['last_name'] ?? ''),
            'phone' => sanitize_text_field($formData['telefoon'] ?? $formData['phone'] ?? ''),
            'profile_type' => sanitize_text_field($formData['profiel_type'] ?? $formData['profile_type'] ?? ''),
            'department' => sanitize_text_field($formData['afdeling'] ?? $formData['department'] ?? ''),

            // Organization
            'company_id' => $companyId,
            'invoice_org_name' => sanitize_text_field($isNewOrg ? $orgField : ($formData['facturatie_naam'] ?? $formData['invoice_org_name'] ?? '')),
            'invoice_address' => sanitize_text_field($formData['facturatie_adres'] ?? $formData['invoice_address'] ?? ''),
            'invoice_city' => sanitize_text_field($formData['facturatie_gemeente'] ?? $formData['invoice_city'] ?? ''),
            'invoice_postal_code' => sanitize_text_field($formData['facturatie_postcode'] ?? $formData['invoice_postal_code'] ?? ''),
            'invoice_vat' => sanitize_text_field($formData['btw_nummer'] ?? $formData['vat_number'] ?? $formData['invoice_vat'] ?? ''),
            'invoice_gln' => sanitize_text_field($formData['gln_nummer'] ?? $formData['gln_number'] ?? $formData['invoice_gln'] ?? ''),
            'invoice_email' => sanitize_email($formData['facturatie_email'] ?? $formData['invoice_email'] ?? ''),

            // Context
            'enrollment_path' => $path,
            'enrolled_by_user_id' => get_current_user_id() ?: null,
        ];
    }

    /**
     * Validate that enrollment is allowed for this form/target combination
     *
     * SECURITY: Checks that:
     * 1. The target (course/group) exists and is published
     * 2. External validation via filter passes (allows form-course restrictions)
     *
     * @param int $formId FluentForms form ID
     * @param int $targetId Course or group ID
     * @param string $type 'course' or 'group'
     * @return true|WP_Error
     */
    private function validateEnrollmentAllowed(int $formId, int $targetId, string $type): true|WP_Error
    {
        // Check target exists and is published
        $post = get_post($targetId);

        if (!$post) {
            return new WP_Error(
                'invalid_target',
                __('De gevraagde cursus of traject bestaat niet.', 'stride')
            );
        }

        // Verify correct post type
        $expectedType = $type === 'group' ? 'groups' : 'sfwd-courses';
        if ($post->post_type !== $expectedType) {
            return new WP_Error(
                'invalid_target_type',
                __('Ongeldige cursus of traject.', 'stride')
            );
        }

        // Verify published status (no enrollment in drafts/private)
        if ($post->post_status !== 'publish') {
            return new WP_Error(
                'target_not_published',
                __('Deze cursus of traject is niet beschikbaar voor inschrijving.', 'stride')
            );
        }

        /**
         * Filter to restrict which forms can enroll in which courses/groups
         *
         * SECURITY: Use this filter to implement form-course authorization.
         * Return WP_Error to block enrollment, or true to allow.
         *
         * Example usage (in theme functions.php or plugin):
         * ```php
         * add_filter('stride/enrollment/validate_form_target', function($allowed, $formId, $targetId, $type) {
         *     // Only allow form 5 to enroll in courses 100, 101, 102
         *     $allowedCourses = [
         *         5 => [100, 101, 102],
         *     ];
         *     if (isset($allowedCourses[$formId]) && !in_array($targetId, $allowedCourses[$formId])) {
         *         return new WP_Error('form_course_mismatch', 'This form cannot enroll in this course.');
         *     }
         *     return $allowed;
         * }, 10, 4);
         * ```
         *
         * @param true|WP_Error $allowed Whether enrollment is allowed
         * @param int $formId FluentForms form ID
         * @param int $targetId Course or group ID
         * @param string $type 'course' or 'group'
         */
        $allowed = apply_filters('stride/enrollment/validate_form_target', true, $formId, $targetId, $type);

        if (is_wp_error($allowed)) {
            return $allowed;
        }

        if ($allowed === false) {
            return new WP_Error(
                'enrollment_not_allowed',
                __('Inschrijving via dit formulier is niet toegestaan.', 'stride')
            );
        }

        return true;
    }

    /**
     * Log enrollment error securely (no PII in logs)
     *
     * SECURITY: Logs error codes only, not email addresses or personal data.
     * This prevents information disclosure while maintaining audit trail.
     *
     * @param string $type Error type (user_creation_failed, enrollment_failed)
     * @param string $code WP_Error code
     * @param int $courseId Course ID for context
     */
    private function logEnrollmentError(string $type, string $code, int $courseId): void
    {
        if (function_exists('ntdst_log')) {
            ntdst_log()->warning('Colleague enrollment issue', [
                'type' => $type,
                'error_code' => $code,
                'course_id' => $courseId,
                // Note: No email or PII logged to prevent information disclosure
            ]);
        }
    }
}
