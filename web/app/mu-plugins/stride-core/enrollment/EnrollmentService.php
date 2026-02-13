<?php

namespace ntdst\Stride\enrollment;

defined('ABSPATH') || exit;

use ntdst\Stride\core\CourseService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\core\SubscriberService;
use ntdst\Stride\sync\UserDataSync;
use ntdst\Stride\FieldRegistry;
use WP_Error;

/**
 * Enrollment Service
 *
 * Main orchestrator for edition enrollments.
 * Coordinates validation, registration tracking, LMS access, and CRM updates.
 *
 * Flow:
 * 1. Validate via EditionService::canUserEnroll()
 * 2. Create registration in wp_vad_registrations
 * 3. Grant LearnDash access via CourseService::grantAccess()
 * 4. Sync profile and CRM data
 *
 * Available hooks:
 * - stride/enrollment/before_enroll (filter) - Modify data or abort (return WP_Error)
 * - stride/enrollment/completed (action) - Post-enrollment (for quotes, vouchers)
 * - stride/enrollment/cancelled (action) - After cancellation
 *
 * @package stride\services\enrollment
 */
class EnrollmentService implements \NTDST_Service_Meta
{
    /** @var CourseService|null Lazy-loaded */
    private ?CourseService $courseService = null;

    /** @var EditionService|null Lazy-loaded */
    private ?EditionService $editionService = null;

    /** @var RegistrationRepository|null Lazy-loaded */
    private ?RegistrationRepository $registrationRepository = null;

    /** @var SubscriberService|null Lazy-loaded */
    private ?SubscriberService $subscriberService = null;

    /** @var UserDataSync|null Lazy-loaded */
    private ?UserDataSync $userDataSync = null;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Service',
            'description' => 'Handles edition enrollments and cancellations',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 15, // After EditionService (5) and RegistrationRepository (3)
        ];
    }

    /**
     * Constructor with optional dependency injection for testing
     *
     * Services are lazy-loaded on first access for better performance.
     * Pass explicit instances for testing.
     */
    public function __construct(
        ?CourseService $courseService = null,
        ?EditionService $editionService = null,
        ?RegistrationRepository $registrationRepository = null,
        ?SubscriberService $subscriberService = null,
        ?UserDataSync $userDataSync = null
    ) {
        // Store injected dependencies (for testing) - these bypass lazy loading
        $this->courseService = $courseService;
        $this->editionService = $editionService;
        $this->registrationRepository = $registrationRepository;
        $this->subscriberService = $subscriberService;
        $this->userDataSync = $userDataSync;

        add_action('init', [$this, 'initHandlers'], 30);
    }

    // ========================================
    // LAZY-LOADED SERVICE GETTERS
    // ========================================

    private function getCourseService(): CourseService
    {
        if ($this->courseService === null) {
            $this->courseService = $this->resolveService(CourseService::class);
        }
        return $this->courseService;
    }

    private function getEditionService(): EditionService
    {
        if ($this->editionService === null) {
            $this->editionService = $this->resolveService(EditionService::class);
        }
        return $this->editionService;
    }

    private function getRegistrationRepository(): RegistrationRepository
    {
        if ($this->registrationRepository === null) {
            $this->registrationRepository = $this->resolveService(RegistrationRepository::class);
        }
        return $this->registrationRepository;
    }

    private function getSubscriberService(): SubscriberService
    {
        if ($this->subscriberService === null) {
            $this->subscriberService = $this->resolveService(SubscriberService::class);
        }
        return $this->subscriberService;
    }

    private function getUserDataSync(): UserDataSync
    {
        if ($this->userDataSync === null) {
            $this->userDataSync = $this->resolveService(UserDataSync::class);
        }
        return $this->userDataSync;
    }

    /**
     * Initialize handler classes
     */
    public function initHandlers(): void
    {
        try {
            new FormSubmissionHandler($this, $this->getUserDataSync());
            new FluentFormsFieldHandler();
        } catch (\Exception $e) {
            if (function_exists('ntdst_log')) {
                ntdst_log()->error('Failed to initialize enrollment handlers', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
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

    // ========================================
    // ENROLLMENT (EDITION-BASED)
    // ========================================

    /**
     * Enroll a user in an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
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
     *   enrollment_path?: string,
     *   voucher_code?: string,
     *   notes?: string
     * } $data Enrollment data
     * @return int|WP_Error Registration ID or error
     */
    public function enrollInEdition(int $userId, int $editionId, array $data = []): int|WP_Error
    {
        // 1. Validate via EditionService
        $canEnroll = $this->getEditionService()->canUserEnroll($userId, $editionId);
        if (is_wp_error($canEnroll)) {
            return $canEnroll;
        }

        // 2. Allow pre-enrollment modification or abort via filter
        $data = apply_filters('stride/enrollment/before_enroll', $data, $userId, $editionId);
        if (is_wp_error($data)) {
            return $data;
        }

        // 3. Get course ID from edition for LearnDash access
        $courseId = $this->getEditionService()->getCourseId($editionId);
        if (!$courseId) {
            return new WP_Error('no_course', __('Editie heeft geen gekoppelde cursus.', 'stride'));
        }

        // 4. Sync profile fields from enrollment data
        $this->syncProfile($userId, $data);

        // 5. Sync organization (link to company or store invoice data)
        $this->syncOrganization($userId, $data);

        // 6. Create registration record
        $enrollmentPath = $data['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL;
        $enrolledBy = $data['enrolled_by_user_id'] ?? null;

        $registrationId = $this->getRegistrationRepository()->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationRepository::STATUS_CONFIRMED,
            'enrollment_path' => $enrollmentPath,
            'enrolled_by' => $enrolledBy,
            'voucher_code' => $data['voucher_code'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // 7. Grant LearnDash access via CourseService
        $accessResult = $this->getCourseService()->grantAccess($userId, $courseId);
        if (is_wp_error($accessResult)) {
            // Rollback registration on LearnDash failure
            $this->getRegistrationRepository()->delete($registrationId);
            return $accessResult;
        }

        // 8. Create CRM audit note
        $this->createEnrollmentNote($userId, $editionId, $data);

        // 9. Fire completion hook for quotes, vouchers, etc.
        do_action('stride/enrollment/completed', $userId, $editionId, $registrationId, $data);

        return $registrationId;
    }

    /**
     * Add user to waitlist for an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @param array $data Enrollment data
     * @return int|WP_Error Registration ID or error
     */
    public function addToWaitlist(int $userId, int $editionId, array $data = []): int|WP_Error
    {
        // Check edition exists
        $edition = $this->getEditionService()->getEdition($editionId);
        if (!$edition) {
            return new WP_Error('edition_not_found', __('Editie niet gevonden.', 'stride'));
        }

        // Check not already registered
        $existing = $this->getRegistrationRepository()->findByUserAndEdition($userId, $editionId);
        if ($existing) {
            return new WP_Error('already_registered', __('U bent al geregistreerd voor deze editie.', 'stride'));
        }

        // Sync profile data
        $this->syncProfile($userId, $data);

        // Create waitlist registration
        $registrationId = $this->getRegistrationRepository()->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationRepository::STATUS_WAITLIST,
            'enrollment_path' => $data['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
            'enrolled_by' => $data['enrolled_by_user_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Create CRM note
        $editionTitle = $edition['title'] ?? __('Onbekende editie', 'stride');
        $this->getSubscriberService()->createNote($userId, sprintf(
            __('Op wachtlijst geplaatst voor: %s', 'stride'),
            $editionTitle
        ));

        do_action('stride/enrollment/waitlisted', $userId, $editionId, $registrationId, $data);

        return $registrationId;
    }

    /**
     * Register interest in an edition (for announcements)
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return int|WP_Error Registration ID or error
     */
    public function registerInterest(int $userId, int $editionId): int|WP_Error
    {
        $edition = $this->getEditionService()->getEdition($editionId);
        if (!$edition) {
            return new WP_Error('edition_not_found', __('Editie niet gevonden.', 'stride'));
        }

        $existing = $this->getRegistrationRepository()->findByUserAndEdition($userId, $editionId);
        if ($existing) {
            return new WP_Error('already_registered', __('U bent al geregistreerd voor deze editie.', 'stride'));
        }

        $registrationId = $this->getRegistrationRepository()->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationRepository::STATUS_INTEREST,
            'enrollment_path' => RegistrationRepository::PATH_INTEREST,
        ]);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        do_action('stride/enrollment/interest_registered', $userId, $editionId, $registrationId);

        return $registrationId;
    }

    // ========================================
    // CANCELLATION
    // ========================================

    /** Cancellation policy: free cancellation threshold in days */
    public const CANCELLATION_FREE_DAYS = 14;

    /**
     * Get cancellation policy for a registration
     *
     * Business rules:
     * - >14 days before edition start: free cancellation, quote cancelled
     * - ≤14 days before edition start: can swap to colleague, quote still invoiced
     *
     * @param int $registrationId Registration ID
     * @return array{
     *   can_cancel: bool,
     *   free_cancellation: bool,
     *   can_swap: bool,
     *   days_until_start: int|null,
     *   message: string
     * }
     */
    public function getCancellationPolicy(int $registrationId): array
    {
        $registration = $this->getRegistrationRepository()->get($registrationId);
        if (!$registration) {
            return [
                'can_cancel' => false,
                'free_cancellation' => false,
                'can_swap' => false,
                'days_until_start' => null,
                'message' => __('Registratie niet gevonden.', 'stride'),
            ];
        }

        $edition = $this->getEditionService()->getEdition($registration['edition_id']);
        if (!$edition) {
            return [
                'can_cancel' => false,
                'free_cancellation' => false,
                'can_swap' => false,
                'days_until_start' => null,
                'message' => __('Editie niet gevonden.', 'stride'),
            ];
        }

        // Calculate days until edition start
        $startDate = $edition['start_date'] ?? '';
        $daysUntilStart = null;

        if ($startDate) {
            $start = strtotime($startDate);
            $now = strtotime(wp_date('Y-m-d'));
            $daysUntilStart = (int) floor(($start - $now) / DAY_IN_SECONDS);
        }

        // Edition already started or ended
        if ($daysUntilStart !== null && $daysUntilStart < 0) {
            return [
                'can_cancel' => false,
                'free_cancellation' => false,
                'can_swap' => false,
                'days_until_start' => $daysUntilStart,
                'message' => __('Deze editie is al gestart. Annuleren is niet meer mogelijk.', 'stride'),
            ];
        }

        // Free cancellation period (>14 days before)
        if ($daysUntilStart === null || $daysUntilStart > self::CANCELLATION_FREE_DAYS) {
            return [
                'can_cancel' => true,
                'free_cancellation' => true,
                'can_swap' => true,
                'days_until_start' => $daysUntilStart,
                'message' => __('Gratis annulering mogelijk. Offerte wordt geannuleerd.', 'stride'),
            ];
        }

        // Within 14 days - swap only, quote still invoiced
        return [
            'can_cancel' => true,
            'free_cancellation' => false,
            'can_swap' => true,
            'days_until_start' => $daysUntilStart,
            'message' => __('Annuleren binnen 14 dagen. De offerte wordt nog gefactureerd. U kunt wel een collega in uw plaats laten deelnemen.', 'stride'),
        ];
    }

    /**
     * Cancel a registration
     *
     * @param int $registrationId Registration ID
     * @param bool $forceFreeCancellation Force free cancellation (admin override)
     * @return true|WP_Error
     */
    public function cancelRegistration(int $registrationId, bool $forceFreeCancellation = false): true|WP_Error
    {
        $registration = $this->getRegistrationRepository()->get($registrationId);
        if (!$registration) {
            return new WP_Error('not_found', __('Registratie niet gevonden.', 'stride'));
        }

        if ($registration['status'] === RegistrationRepository::STATUS_CANCELLED) {
            return new WP_Error('already_cancelled', __('Registratie is al geannuleerd.', 'stride'));
        }

        // Check cancellation policy
        $policy = $this->getCancellationPolicy($registrationId);
        if (!$policy['can_cancel']) {
            return new WP_Error('cannot_cancel', $policy['message']);
        }

        // Cancel registration in repository
        $result = $this->getRegistrationRepository()->cancel($registrationId);
        if (is_wp_error($result)) {
            return $result;
        }

        // Revoke LearnDash access if confirmed registration
        if ($registration['status'] === RegistrationRepository::STATUS_CONFIRMED) {
            $courseId = $this->getEditionService()->getCourseId($registration['edition_id']);
            if ($courseId) {
                $this->getCourseService()->revokeAccess($registration['user_id'], $courseId);
            }
        }

        // Create CRM note
        $edition = $this->getEditionService()->getEdition($registration['edition_id']);
        $editionTitle = $edition['title'] ?? __('Onbekende editie', 'stride');
        $noteMessage = sprintf(__('Inschrijving geannuleerd: %s', 'stride'), $editionTitle);

        if (!$policy['free_cancellation'] && !$forceFreeCancellation) {
            $noteMessage .= ' ' . __('(binnen 14 dagen, offerte blijft gefactureerd)', 'stride');
        }

        $this->getSubscriberService()->createNote($registration['user_id'], $noteMessage);

        // Fire cancellation hook with policy info
        do_action('stride/enrollment/cancelled', $registration['user_id'], $registration['edition_id'], $registrationId, [
            'free_cancellation' => $policy['free_cancellation'] || $forceFreeCancellation,
            'quote_id' => $registration['quote_id'],
        ]);

        return true;
    }

    /**
     * Swap a registration to a colleague
     *
     * Creates new registration for colleague, links to same quote.
     * Original registration is cancelled.
     *
     * @param int $registrationId Original registration ID
     * @param int $colleagueUserId Colleague's WordPress user ID
     * @param array $colleagueData Optional profile data for colleague
     * @return int|WP_Error New registration ID or error
     */
    public function swapToColleague(int $registrationId, int $colleagueUserId, array $colleagueData = []): int|WP_Error
    {
        $registration = $this->getRegistrationRepository()->get($registrationId);
        if (!$registration) {
            return new WP_Error('not_found', __('Registratie niet gevonden.', 'stride'));
        }

        // Check swap is allowed
        $policy = $this->getCancellationPolicy($registrationId);
        if (!$policy['can_swap']) {
            return new WP_Error('cannot_swap', $policy['message']);
        }

        $editionId = $registration['edition_id'];

        // Check colleague not already enrolled
        $existingColleague = $this->getRegistrationRepository()->findByUserAndEdition($colleagueUserId, $editionId);
        if ($existingColleague && $existingColleague['status'] === RegistrationRepository::STATUS_CONFIRMED) {
            return new WP_Error('colleague_already_enrolled', __('De collega is al ingeschreven voor deze editie.', 'stride'));
        }

        // Create new registration for colleague
        $newRegistrationData = array_merge($colleagueData, [
            'enrollment_path' => RegistrationRepository::PATH_COLLEAGUE,
            'enrolled_by_user_id' => $registration['user_id'],
            'notes' => sprintf(__('Overgenomen van registratie #%d', 'stride'), $registrationId),
        ]);

        $newRegistrationId = $this->enrollInEdition($colleagueUserId, $editionId, $newRegistrationData);

        if (is_wp_error($newRegistrationId)) {
            return $newRegistrationId;
        }

        // Link same quote to new registration if exists
        if ($registration['quote_id']) {
            $this->getRegistrationRepository()->linkQuote($newRegistrationId, $registration['quote_id']);
        }

        // Cancel original registration (with note about swap)
        $this->getRegistrationRepository()->update($registrationId, [
            'status' => RegistrationRepository::STATUS_CANCELLED,
            'cancelled_at' => current_time('mysql'),
            'notes' => sprintf(__('Overgedragen aan collega (registratie #%d)', 'stride'), $newRegistrationId),
        ]);

        // Revoke LearnDash access from original user
        $courseId = $this->getEditionService()->getCourseId($editionId);
        if ($courseId) {
            $this->getCourseService()->revokeAccess($registration['user_id'], $courseId);
        }

        // Create CRM notes
        $edition = $this->getEditionService()->getEdition($editionId);
        $editionTitle = $edition['title'] ?? __('Onbekende editie', 'stride');

        $originalUser = get_userdata($registration['user_id']);
        $colleagueUser = get_userdata($colleagueUserId);

        $this->getSubscriberService()->createNote($registration['user_id'], sprintf(
            __('Inschrijving overgedragen aan %s: %s', 'stride'),
            $colleagueUser ? $colleagueUser->display_name : $colleagueUserId,
            $editionTitle
        ));

        $this->getSubscriberService()->createNote($colleagueUserId, sprintf(
            __('Inschrijving overgenomen van %s: %s', 'stride'),
            $originalUser ? $originalUser->display_name : $registration['user_id'],
            $editionTitle
        ));

        do_action('stride/enrollment/swapped', $registration['user_id'], $colleagueUserId, $editionId, $registrationId, $newRegistrationId);

        return $newRegistrationId;
    }

    /**
     * Promote waitlist user to confirmed when spot opens
     *
     * @param int $registrationId Registration ID of waitlist entry
     * @return true|WP_Error
     */
    public function promoteFromWaitlist(int $registrationId): true|WP_Error
    {
        $registration = $this->getRegistrationRepository()->get($registrationId);
        if (!$registration) {
            return new WP_Error('not_found', __('Registratie niet gevonden.', 'stride'));
        }

        if ($registration['status'] !== RegistrationRepository::STATUS_WAITLIST) {
            return new WP_Error('not_waitlisted', __('Registratie staat niet op wachtlijst.', 'stride'));
        }

        // Get course ID for LearnDash access
        $courseId = $this->getEditionService()->getCourseId($registration['edition_id']);
        if (!$courseId) {
            return new WP_Error('no_course', __('Editie heeft geen gekoppelde cursus.', 'stride'));
        }

        // Confirm registration
        $result = $this->getRegistrationRepository()->confirm($registrationId);
        if (is_wp_error($result)) {
            return $result;
        }

        // Grant LearnDash access
        $accessResult = $this->getCourseService()->grantAccess($registration['user_id'], $courseId);
        if (is_wp_error($accessResult)) {
            // Revert to waitlist on failure
            $this->getRegistrationRepository()->waitlist($registrationId);
            return $accessResult;
        }

        // Create CRM note
        $edition = $this->getEditionService()->getEdition($registration['edition_id']);
        $editionTitle = $edition['title'] ?? __('Onbekende editie', 'stride');
        $this->getSubscriberService()->createNote($registration['user_id'], sprintf(
            __('Gepromoveerd van wachtlijst: %s', 'stride'),
            $editionTitle
        ));

        do_action('stride/enrollment/promoted_from_waitlist', $registration['user_id'], $registration['edition_id'], $registrationId);

        return true;
    }

    // ========================================
    // QUERY METHODS
    // ========================================

    /**
     * Get registration for user and edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return array|null Registration data or null
     */
    public function getRegistration(int $userId, int $editionId): ?array
    {
        return $this->getRegistrationRepository()->findByUserAndEdition($userId, $editionId);
    }

    /**
     * Check if user is enrolled in an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return bool
     */
    public function isEnrolled(int $userId, int $editionId): bool
    {
        $registration = $this->getRegistrationRepository()->findByUserAndEdition($userId, $editionId);
        return $registration && $registration['status'] === RegistrationRepository::STATUS_CONFIRMED;
    }

    /**
     * Check if user is on waitlist for an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return bool
     */
    public function isOnWaitlist(int $userId, int $editionId): bool
    {
        $registration = $this->getRegistrationRepository()->findByUserAndEdition($userId, $editionId);
        return $registration && $registration['status'] === RegistrationRepository::STATUS_WAITLIST;
    }

    /**
     * Get all registrations for an edition
     *
     * @param int $editionId Edition post ID
     * @param string|null $status Filter by status
     * @return array Array of registrations
     */
    public function getEditionRegistrations(int $editionId, ?string $status = null): array
    {
        return $this->getRegistrationRepository()->getByEdition($editionId, $status);
    }

    /**
     * Get all registrations for a user
     *
     * @param int $userId WordPress user ID
     * @param string|null $status Filter by status
     * @return array Array of registrations
     */
    public function getUserRegistrations(int $userId, ?string $status = null): array
    {
        return $this->getRegistrationRepository()->getByUser($userId, $status);
    }

    /**
     * Get who enrolled a user (manager tracking)
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return int|null Manager user ID or null
     */
    public function getEnrollingManager(int $userId, int $editionId): ?int
    {
        $registration = $this->getRegistrationRepository()->findByUserAndEdition($userId, $editionId);
        return $registration['enrolled_by'] ?? null;
    }

    /**
     * Check if user was enrolled by someone else (managed enrollment)
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return bool
     */
    public function isManaged(int $userId, int $editionId): bool
    {
        return $this->getEnrollingManager($userId, $editionId) !== null;
    }

    // ========================================
    // PROFILE & ORGANIZATION SYNC
    // ========================================

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
            $this->getSubscriberService()->linkToCompany($userId, (int) $data['company_id']);
        } elseif (!empty($data['invoice_org_name'])) {
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
     * Create CRM audit note for enrollment
     */
    private function createEnrollmentNote(int $userId, int $editionId, array $data): void
    {
        $edition = $this->getEditionService()->getEdition($editionId);
        $editionTitle = $edition['title'] ?? __('Onbekende editie', 'stride');
        $note = sprintf(__('Ingeschreven voor: %s', 'stride'), $editionTitle);

        // Add manager info if enrolled by someone else
        $enrolledByUserId = $data['enrolled_by_user_id'] ?? null;
        if ($enrolledByUserId && $enrolledByUserId !== $userId) {
            $managerEmail = $this->getCourseService()->getUserDisplayInfo($enrolledByUserId);
            $note .= sprintf(' (door %s)', $managerEmail ?? 'onbekend');
        }

        // Add enrollment path for audit trail
        $path = $data['enrollment_path'] ?? 'individual';
        $note .= sprintf(' [%s]', $path);

        $this->getSubscriberService()->createNote($userId, $note);
    }

    // ========================================
    // DEPRECATED METHODS
    // Legacy support for courseId-based enrollment
    // ========================================

    /**
     * @deprecated Use enrollInEdition() instead
     */
    public function enrollUser(int $userId, int $courseId, array $data = []): true|WP_Error
    {
        _doing_it_wrong(__METHOD__, 'Use EnrollmentService::enrollInEdition() with edition_id instead.', '4.0.0');

        // Find edition for this course (backwards compat - gets first open edition)
        $editions = $this->getEditionService()->getEditionsForCourse($courseId);
        $openEdition = null;

        foreach ($editions as $edition) {
            if ($this->getEditionService()->isEnrollmentOpen($edition['id'])) {
                $openEdition = $edition;
                break;
            }
        }

        if (!$openEdition) {
            return new WP_Error('no_edition', __('Geen open editie gevonden voor deze cursus.', 'stride'));
        }

        $result = $this->enrollInEdition($userId, $openEdition['id'], $data);
        return is_wp_error($result) ? $result : true;
    }

    /**
     * @deprecated Use cancelRegistration() instead
     */
    public function unenrollUser(int $userId, int $courseId): true|WP_Error
    {
        _doing_it_wrong(__METHOD__, 'Use EnrollmentService::cancelRegistration() with registration_id instead.', '4.0.0');

        // Find registration for this user+course combo
        $editions = $this->getEditionService()->getEditionsForCourse($courseId);

        foreach ($editions as $edition) {
            $registration = $this->getRegistrationRepository()->findByUserAndEdition($userId, $edition['id']);
            if ($registration) {
                return $this->cancelRegistration($registration['id']);
            }
        }

        // Fallback: just revoke LearnDash access
        return $this->getCourseService()->revokeAccess($userId, $courseId);
    }

    /**
     * @deprecated Group enrollment will be handled differently
     */
    public function enrollUserInGroup(int $userId, int $groupId, array $data = []): true|WP_Error
    {
        _doing_it_wrong(__METHOD__, 'Group enrollment will be handled via trajectory editions.', '4.0.0');

        if (!function_exists('ld_update_group_access')) {
            return new WP_Error('learndash_unavailable', __('LearnDash is niet beschikbaar.', 'stride'));
        }

        ld_update_group_access($userId, $groupId);
        return true;
    }

    /**
     * @deprecated Group enrollment will be handled differently
     */
    public function unenrollUserFromGroup(int $userId, int $groupId): true|WP_Error
    {
        _doing_it_wrong(__METHOD__, 'Group unenrollment will be handled via trajectory editions.', '4.0.0');

        if (!function_exists('ld_update_group_access')) {
            return new WP_Error('learndash_unavailable', __('LearnDash is niet beschikbaar.', 'stride'));
        }

        ld_update_group_access($userId, $groupId, true);
        return true;
    }
}
