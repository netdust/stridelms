<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\SessionSelectionService;
use WP_Error;

/**
 * Enrollment orchestration service.
 *
 * Handles registration creation and LMS access management.
 */
final class EnrollmentService extends AbstractService
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionQueryInterface $editions,
        private readonly LMSAdapterInterface $lms,
        private readonly ?SessionSelectionService $sessionSelection = null,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Service',
            'description' => 'Handles user enrollment in editions',
            'priority' => 15,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'enrollment';
    }

    protected function init(): void
    {
        // Instantiate enrollment form handler
        ntdst_get(\Stride\Handlers\EnrollmentFormHandler::class);
    }

    /**
     * Enroll user in an edition.
     *
     * @param array<string, mixed> $options Additional options (voucher_code, enrolled_by, notes)
     * @return int|WP_Error Registration ID or error
     */
    public function enroll(int $userId, int $editionId, array $options = []): int|WP_Error
    {
        // Validate edition exists
        if (!$this->editions->exists($editionId)) {
            return new WP_Error('invalid_edition', 'Edition does not exist');
        }

        // Check enrollment allowed
        $status = $this->editions->getStatus($editionId);
        if (!$status->allowsEnrollment()) {
            // Distinguish between "full" and other closed reasons
            if ($status === \Stride\Domain\EditionStatus::Full) {
                ntdst_log('enrollment')->warning('Enrollment rejected: edition full', [
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                ]);
                return new WP_Error('edition_full', 'This edition is full');
            }

            ntdst_log('enrollment')->warning('Enrollment rejected: enrollment closed', [
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => $status->value,
            ]);
            return new WP_Error('enrollment_closed', 'Enrollment is not open for this edition');
        }

        // Check capacity (redundant when status is correctly maintained, but defensive)
        if (!$this->editions->hasAvailableSpots($editionId)) {
            ntdst_log('enrollment')->warning('Enrollment rejected: edition full', [
                'user_id' => $userId,
                'edition_id' => $editionId,
            ]);
            return new WP_Error('edition_full', 'This edition is full');
        }

        // Check not already enrolled
        if ($this->isEnrolled($userId, $editionId)) {
            ntdst_log('enrollment')->warning('Enrollment rejected: already enrolled', [
                'user_id' => $userId,
                'edition_id' => $editionId,
            ]);
            return new WP_Error('already_enrolled', 'User is already enrolled in this edition');
        }

        // Create registration
        $registrationId = $this->registrations->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
            'enrolled_by' => $options['enrolled_by'] ?? null,
            'voucher_code' => $options['voucher_code'] ?? null,
            'notes' => $options['notes'] ?? null,
        ]);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Grant LMS access
        $courseId = $this->editions->getCourseId($editionId);
        if ($courseId) {
            $this->lms->grantAccess($userId, $courseId);
        }

        // Fire event
        $this->dispatch('registration/created', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
            'enrolled_by' => $options['enrolled_by'] ?? null,
        ]);

        ntdst_log('enrollment')->info('Enrollment created', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'registration_id' => $registrationId,
            'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        return $registrationId;
    }

    /**
     * Cancel enrollment.
     */
    public function cancel(int $registrationId): bool|WP_Error
    {
        $registration = $this->registrations->find($registrationId);

        if (is_wp_error($registration)) {
            return $registration;
        }

        // Update status
        $result = $this->registrations->cancel($registrationId);

        if (!$result) {
            ntdst_log('enrollment')->error('Enrollment cancellation failed', [
                'registration_id' => $registrationId,
                'user_id' => (int) $registration->user_id,
                'edition_id' => (int) $registration->edition_id,
            ]);
            return new WP_Error('cancel_failed', 'Failed to cancel registration');
        }

        // Revoke LMS access
        $courseId = $this->editions->getCourseId((int) $registration->edition_id);
        if ($courseId) {
            $this->lms->revokeAccess((int) $registration->user_id, $courseId);
        }

        // Fire event
        $this->dispatch('registration/cancelled', [
            'registration_id' => $registrationId,
            'user_id' => (int) $registration->user_id,
            'edition_id' => (int) $registration->edition_id,
        ]);

        ntdst_log('enrollment')->info('Enrollment cancelled', [
            'registration_id' => $registrationId,
            'user_id' => (int) $registration->user_id,
            'edition_id' => (int) $registration->edition_id,
        ]);

        return true;
    }

    /**
     * Check if user is enrolled in edition.
     */
    public function isEnrolled(int $userId, int $editionId): bool
    {
        $registration = $this->registrations->findByUserAndEdition($userId, $editionId);

        if (!$registration) {
            return false;
        }

        return $registration->status === RegistrationStatus::Confirmed->value;
    }

    /**
     * Get user's enrollments.
     *
     * @return array<object>
     */
    public function getUserEnrollments(int $userId): array
    {
        return $this->registrations->findByUser($userId, RegistrationStatus::Confirmed->value);
    }

    /**
     * Get registration by ID.
     */
    public function getRegistration(int $registrationId): \stdClass|WP_Error
    {
        return $this->registrations->find($registrationId);
    }

    /**
     * Process enrollment from frontend form.
     *
     * @param array{
     *   edition_id: int,
     *   user_id: int,
     *   enrollment_type: string,
     *   first_name: string,
     *   last_name: string,
     *   email: string,
     *   phone?: string,
     *   company?: string,
     *   vat_number?: string,
     *   address?: string,
     *   postal_code?: string,
     *   city?: string,
     *   gln_peppol?: string,
     *   invoice_email?: string,
     *   po_number?: string,
     *   voucher_code?: string,
     *   selected_sessions?: array<int>,
     *   terms_accepted: bool,
     * } $data
     * @return array{registration_id: int, quote_id?: int, participant_id: int}|WP_Error
     */
    public function processEnrollment(array $data): array|WP_Error
    {
        $editionId = (int) ($data['edition_id'] ?? 0);
        $currentUserId = (int) ($data['user_id'] ?? 0);
        $enrollmentType = $data['enrollment_type'] ?? 'self';

        ntdst_log('enrollment')->info('Processing enrollment', [
            'user_id' => $currentUserId,
            'edition_id' => $editionId,
            'enrollment_type' => $enrollmentType,
        ]);

        // Determine participant and enrollment path
        if ($enrollmentType === 'colleague') {
            // Check if user exists before resolving (to detect new user creation)
            $existingUser = get_user_by('email', $data['email']);

            // Colleague enrollment: find or create user by email
            $participantId = $this->resolveParticipant(
                $data['email'],
                $data['first_name'],
                $data['last_name']
            );

            if (is_wp_error($participantId)) {
                return $participantId;
            }

            // Log if a new user was created
            if (!$existingUser) {
                ntdst_log('enrollment')->info('Colleague user created', [
                    'participant_id' => $participantId,
                    'email' => $data['email'],
                    'enrolled_by' => $currentUserId,
                ]);
            }

            $enrollmentPath = RegistrationRepository::PATH_COLLEAGUE;
            $enrolledBy = $currentUserId;
        } else {
            // Self enrollment
            $participantId = $currentUserId;
            $enrollmentPath = RegistrationRepository::PATH_INDIVIDUAL;
            $enrolledBy = null;

            // Update current user's profile with form data
            $this->updateUserProfile($currentUserId, $data);
        }

        // Store billing data for quote handler to use
        $this->storePendingBilling($data, $enrolledBy ?: $participantId);

        // Perform enrollment
        $registrationId = $this->enroll($participantId, $editionId, [
            'enrollment_path' => $enrollmentPath,
            'enrolled_by' => $enrolledBy,
            'voucher_code' => $data['voucher_code'] ?? null,
        ]);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Handle session selection if provided
        $selectedSessions = $data['selected_sessions'] ?? [];
        if (!empty($selectedSessions) && $this->sessionSelection) {
            foreach ($selectedSessions as $sessionId) {
                $this->sessionSelection->registerForSession(
                    $registrationId,
                    (int) $sessionId,
                    $participantId
                );
            }
        }

        // Get quote ID from registration (created by handler)
        $quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
        $quote = $quoteService->getQuoteByRegistration($registrationId);

        return [
            'registration_id' => $registrationId,
            'quote_id' => $quote['id'] ?? null,
            'participant_id' => $participantId,
        ];
    }

    /**
     * Resolve participant user (find or create).
     */
    private function resolveParticipant(string $email, string $firstName, string $lastName): int|WP_Error
    {
        $user = get_user_by('email', $email);
        if ($user) {
            return $user->ID;
        }

        // Create new user
        $username = sanitize_user(explode('@', $email)[0], true);
        $counter = 1;
        while (username_exists($username)) {
            $username = sanitize_user(explode('@', $email)[0], true) . $counter;
            $counter++;
        }

        $password = wp_generate_password(16, true, true);
        $userId = wp_create_user($username, $password, $email);

        if (is_wp_error($userId)) {
            return $userId;
        }

        wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName),
        ]);

        wp_new_user_notification($userId, null, 'both');

        return $userId;
    }

    /**
     * Update user profile with enrollment form data.
     */
    private function updateUserProfile(int $userId, array $data): void
    {
        $metaFields = [
            'phone' => 'phone',
            'company' => 'company',
            'vat_number' => 'vat_number',
            'address' => 'billing_address',
            'postal_code' => 'billing_postal_code',
            'city' => 'billing_city',
            'gln_peppol' => 'gln_number',
            'invoice_email' => 'invoice_email',
        ];

        foreach ($metaFields as $inputKey => $metaKey) {
            if (!empty($data[$inputKey])) {
                update_user_meta($userId, $metaKey, sanitize_text_field($data[$inputKey]));
            }
        }

        // Update core user fields if provided
        if (!empty($data['first_name']) || !empty($data['last_name'])) {
            wp_update_user([
                'ID' => $userId,
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
            ]);
        }
    }

    /**
     * Store pending billing data for quote handler.
     */
    private function storePendingBilling(array $data, int $billingUserId): void
    {
        $billing = [
            'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
            'email' => ($data['invoice_email'] ?? '') ?: ($data['email'] ?? ''),
            'company' => $data['company'] ?? '',
            'address' => $data['address'] ?? '',
            'postal_code' => $data['postal_code'] ?? '',
            'city' => $data['city'] ?? '',
            'vat_number' => $data['vat_number'] ?? '',
            'gln_number' => $data['gln_peppol'] ?? '',
            'po_number' => $data['po_number'] ?? '',
            'voucher_code' => $data['voucher_code'] ?? '',
        ];

        // Store in transient keyed by billing user ID + edition
        $key = 'stride_pending_billing_' . $billingUserId . '_' . $data['edition_id'];
        set_transient($key, $billing, HOUR_IN_SECONDS);
    }
}
