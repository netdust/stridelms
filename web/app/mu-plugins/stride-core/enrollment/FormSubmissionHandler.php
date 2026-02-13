<?php

namespace ntdst\Stride\enrollment;

defined('ABSPATH') || exit;

use ntdst\Stride\core\EditionService;
use ntdst\Stride\sync\UserDataSync;
use WP_Error;

/**
 * Form Submission Handler
 *
 * Handles FluentForms enrollment form submissions and routes them
 * to the appropriate enrollment path in EnrollmentService.
 *
 * This is a handler class initialized by EnrollmentService, NOT a service.
 * It should not be registered directly with the DI container.
 *
 * Supports 4 enrollment paths:
 * - individual: Single user enrolling in an edition
 * - colleague: Manager enrolling multiple colleagues
 * - trajectory: User enrolling in a trajectory edition
 * - interest: User expressing interest (no enrollment)
 *
 * Supports multiple form field naming conventions (Dutch/English)
 * to handle legacy forms and new standardized forms.
 *
 * @package stride\services\enrollment
 */
class FormSubmissionHandler
{
    private EnrollmentService $enrollmentService;
    private UserDataSync $userDataSync;

    /**
     * Constructor - requires dependencies from parent service
     */
    public function __construct(
        EnrollmentService $enrollmentService,
        UserDataSync $userDataSync
    ) {
        $this->enrollmentService = $enrollmentService;
        $this->userDataSync = $userDataSync;

        $this->register();
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
     * 1. Trajectory if group_id is present (deprecated) or trajectory edition
     * 2. Colleague if repeater field is present
     * 3. Interest if no edition_id but interest field present
     * 4. Individual if edition_id is present
     * 5. null if not an enrollment form
     *
     * @param int $formId Form ID
     * @param array $data Form data
     * @return string|null Enrollment path or null
     */
    private function detectEnrollmentPath(int $formId, array $data): ?string
    {
        // Check for trajectory (group_id in hidden field - deprecated path)
        if (!empty($data['group_id']) || !empty($data['traject_id'])) {
            return 'trajectory';
        }

        // Check for colleague enrollment (repeater field with colleague data)
        if (!empty($data['collegas']) || !empty($data['repeater_field_collegas'])) {
            return 'colleague';
        }

        // Check for interest form (no edition_id, but has interest indicator)
        $editionId = $this->extractEditionId($data);
        if (!$editionId) {
            if (!empty($data['interesse']) || !empty($data['interest_edition_id']) || !empty($data['interest_course_id'])) {
                return 'interest';
            }
            return null; // Not an enrollment form
        }

        // Default: individual enrollment
        return 'individual';
    }

    /**
     * Handle individual edition enrollment
     *
     * @param array $formData Form field data
     * @param int $formId FluentForms form ID
     * @return int|true|WP_Error Registration ID or error
     */
    private function handleIndividual(array $formData, int $formId): int|true|WP_Error
    {
        $editionId = $this->extractEditionId($formData);
        if (!$editionId) {
            return new WP_Error('missing_edition', __('Geen editie gevonden.', 'stride'));
        }

        // Security: Validate enrollment is allowed for this form/edition
        $validationResult = $this->validateEnrollmentAllowed($formId, $editionId, 'edition');
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

        return $this->enrollmentService->enrollInEdition($userId, $editionId, $enrollmentData);
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
        $editionId = $this->extractEditionId($formData);
        if (!$editionId) {
            return new WP_Error('missing_edition', __('Geen editie gevonden.', 'stride'));
        }

        // Security: Validate enrollment is allowed for this form/edition
        $validationResult = $this->validateEnrollmentAllowed($formId, $editionId, 'edition');
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        // Get colleagues from repeater field
        $colleagues = $formData['collegas'] ?? $formData['repeater_field_collegas'] ?? [];
        if (empty($colleagues) || !is_array($colleagues)) {
            return new WP_Error('no_colleagues', __('Geen collega\'s opgegeven.', 'stride'));
        }

        // Security: Hard limit on colleagues per submission to prevent abuse
        $maxColleagues = apply_filters('stride/enrollment/max_colleagues', 50);
        if (count($colleagues) > $maxColleagues) {
            return new WP_Error(
                'too_many_colleagues',
                sprintf(__('Maximum %d collega\'s per inschrijving.', 'stride'), $maxColleagues)
            );
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
        $allowed = apply_filters('stride/enrollment/validate_colleague_enrollment', true, $managerId, $editionId, $colleagues);
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
                $this->logEnrollmentError('user_creation_failed', $colleagueUserId->get_error_code(), $editionId);
                $errors[] = 'user_creation_failed';
                continue;
            }

            // Build enrollment data with manager tracking and colleague's name
            $enrollmentData = $this->buildEnrollmentData($formData, 'colleague');
            $enrollmentData['enrolled_by_user_id'] = $managerId;
            $enrollmentData['first_name'] = $colleague['voornaam'] ?? $colleague['first_name'] ?? '';
            $enrollmentData['last_name'] = $colleague['achternaam'] ?? $colleague['last_name'] ?? '';

            $result = $this->enrollmentService->enrollInEdition($colleagueUserId, $editionId, $enrollmentData);

            if (is_wp_error($result)) {
                $this->logEnrollmentError('enrollment_failed', $result->get_error_code(), $editionId);
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
            do_action('stride/enrollment/colleague_partial_failure', $successes, count($errors), $editionId, $managerId);
        }

        return true;
    }

    /**
     * Handle trajectory enrollment (deprecated - use edition-based enrollment)
     *
     * @param array $formData Form field data
     * @param int $formId FluentForms form ID
     * @return true|WP_Error
     */
    private function handleTrajectory(array $formData, int $formId): true|WP_Error
    {
        // Check if we have an edition_id (new flow) or group_id (legacy)
        $editionId = $this->extractEditionId($formData);

        if ($editionId) {
            // New flow: trajectory editions
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
            $result = $this->enrollmentService->enrollInEdition($userId, $editionId, $enrollmentData);

            return is_wp_error($result) ? $result : true;
        }

        // Legacy flow: direct LearnDash group enrollment
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

        // Use deprecated group enrollment method
        return $this->enrollmentService->enrollUserInGroup($userId, $groupId, $enrollmentData);
    }

    /**
     * Handle interest/waitlist form
     *
     * @param array $formData Form field data
     * @return int|true|WP_Error
     */
    private function handleInterest(array $formData): int|true|WP_Error
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

        // Get edition ID for interest registration
        $editionId = $this->extractEditionId($formData)
            ?: absint($formData['interest_edition_id'] ?? $formData['interest_course_id'] ?? 0);

        if ($editionId) {
            // Register interest via EnrollmentService
            return $this->enrollmentService->registerInterest($userId, $editionId);
        }

        // Legacy: Fire hook for interest tracking without specific edition
        do_action('stride/enrollment/interest_registered', $userId, 0, $formData);

        return true;
    }

    /**
     * Extract edition ID from form data
     * Supports multiple field naming conventions
     *
     * @param array $formData Form field data
     * @return int|null Edition ID or null
     */
    private function extractEditionId(array $formData): ?int
    {
        // New standardized field
        $editionId = $formData['edition_id']
            ?? $formData['editie_id']
            // Legacy course_id fields - will be mapped to editions
            ?? $formData['course_id']
            ?? $formData['cursus_id']
            ?? $formData['vorming_id']
            ?? $formData['hidden_course_id']
            ?? $formData['hidden_edition_id']
            ?? null;

        return $editionId ? absint($editionId) : null;
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

            // Voucher (if provided)
            'voucher_code' => sanitize_text_field($formData['voucher_code'] ?? $formData['voucher'] ?? ''),

            // Context
            'enrollment_path' => $path,
            'enrolled_by_user_id' => get_current_user_id() ?: null,
        ];
    }

    /**
     * Validate that enrollment is allowed for this form/target combination
     *
     * SECURITY: Checks that:
     * 1. The target (edition/group) exists and is published
     * 2. External validation via filter passes (allows form-edition restrictions)
     *
     * @param int $formId FluentForms form ID
     * @param int $targetId Edition or group ID
     * @param string $type 'edition' or 'group'
     * @return true|WP_Error
     */
    private function validateEnrollmentAllowed(int $formId, int $targetId, string $type): true|WP_Error
    {
        // Check target exists and is published
        $post = get_post($targetId);

        if (!$post) {
            return new WP_Error(
                'invalid_target',
                __('De gevraagde editie of traject bestaat niet.', 'stride')
            );
        }

        // Verify correct post type
        $expectedType = match ($type) {
            'edition' => EditionService::POST_TYPE,
            'group' => 'groups',
            default => 'sfwd-courses', // Legacy
        };

        if ($post->post_type !== $expectedType) {
            return new WP_Error(
                'invalid_target_type',
                __('Ongeldige editie of traject.', 'stride')
            );
        }

        // Verify published status (no enrollment in drafts/private)
        if ($post->post_status !== 'publish') {
            return new WP_Error(
                'target_not_published',
                __('Deze editie of traject is niet beschikbaar voor inschrijving.', 'stride')
            );
        }

        /**
         * Filter to restrict which forms can enroll in which editions/groups.
         * Return WP_Error to block enrollment, or true to allow.
         *
         * @param true|WP_Error $allowed Whether enrollment is allowed
         * @param int $formId FluentForms form ID
         * @param int $targetId Edition or group ID
         * @param string $type 'edition' or 'group'
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
     * @param string $type Error type
     * @param string $code WP_Error code
     * @param int $editionId Edition ID for context
     */
    private function logEnrollmentError(string $type, string $code, int $editionId): void
    {
        if (function_exists('ntdst_log')) {
            ntdst_log()->warning('Colleague enrollment issue', [
                'type' => $type,
                'error_code' => $code,
                'edition_id' => $editionId,
            ]);
        }
    }
}
