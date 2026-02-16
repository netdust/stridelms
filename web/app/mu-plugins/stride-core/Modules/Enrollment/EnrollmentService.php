<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
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
        // No hooks needed yet
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
            return new WP_Error('enrollment_closed', 'Enrollment is not open for this edition');
        }

        // Check capacity
        if (!$this->editions->hasAvailableSpots($editionId)) {
            return new WP_Error('edition_full', 'This edition is full');
        }

        // Check not already enrolled
        if ($this->isEnrolled($userId, $editionId)) {
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
}
