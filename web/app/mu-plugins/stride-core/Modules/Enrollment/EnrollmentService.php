<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\SessionSelection;
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
        private readonly ?SessionSelection $sessionSelection = null,
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
        // Register enrollment/completion URL routes
        add_action('init', function () {
            $completion = ntdst_get(EnrollmentCompletion::class);
            (new EnrollmentRouter($this, $this->registrations, $completion))->register();
        }, 20);

        // Register plain classes as singletons (no service lifecycle)
        ntdst_set(EnrollmentCompletion::class, fn() => new EnrollmentCompletion(
            $this->registrations,
            ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
            ntdst_get(\Stride\Modules\Trajectory\TrajectoryRepository::class),
        ));

        // Register completion task handler (AJAX + auto-confirm hook)
        new \Stride\Handlers\CompletionTaskHandler();

        // Auto-enroll users when they access an open course lesson
        add_action('learndash-lesson-before', [$this, 'maybeEnrollOnLessonAccess'], 10, 3);
        add_action('learndash-topic-before', [$this, 'maybeEnrollOnLessonAccess'], 10, 3);

        // Cancel registration when quote is cancelled (revokes course access)
        add_action('stride/quote/cancelled', [$this, 'onQuoteCancelled']);
    }

    /**
     * Handle quote cancellation - cancel associated registration.
     *
     * @param array{quote_id: int} $data Event data
     */
    public function onQuoteCancelled(array $data): void
    {
        $quoteId = (int) ($data['quote_id'] ?? 0);
        if (!$quoteId) {
            return;
        }

        // Get registration_id from quote
        $quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
        $quote = $quoteService->getQuote($quoteId);

        if (!$quote || empty($quote['registration_id'])) {
            ntdst_log('enrollment')->warning('Quote cancelled but no registration found', [
                'quote_id' => $quoteId,
            ]);
            return;
        }

        $registrationId = (int) $quote['registration_id'];

        // Cancel registration (this revokes course access)
        $result = $this->cancel($registrationId);

        if (is_wp_error($result)) {
            ntdst_log('enrollment')->error('Failed to cancel registration on quote cancellation', [
                'quote_id' => $quoteId,
                'registration_id' => $registrationId,
                'error' => $result->get_error_message(),
            ]);
            return;
        }

        ntdst_log('enrollment')->info('Registration cancelled due to quote cancellation', [
            'quote_id' => $quoteId,
            'registration_id' => $registrationId,
        ]);
    }

    /**
     * Enroll user when they access an open course lesson.
     *
     * LearnDash "open" courses grant access to everyone but don't track enrollment.
     * This hook ensures users get enrolled when they start an open course,
     * so the course appears in their dashboard.
     *
     * @param int $postId The lesson/topic post ID
     * @param int $courseId The course ID
     * @param int $userId The user ID
     */
    public function maybeEnrollOnLessonAccess(int $postId, int $courseId, int $userId): void
    {
        if (!$userId || !$courseId) {
            return;
        }

        // Check if user is already enrolled
        if (function_exists('learndash_user_get_enrolled_courses')) {
            $enrolledCourses = learndash_user_get_enrolled_courses($userId);
            if (in_array($courseId, $enrolledCourses, true)) {
                return; // Already enrolled
            }
        }

        // Check if this is an open course (grant access without enrollment)
        if (!$this->lms->isOpenCourse($courseId)) {
            return;
        }

        // Enroll the user via LearnDash
        $this->lms->grantAccess($userId, $courseId);

        ntdst_log('enrollment')->info('Auto-enrolled user in open course on lesson access', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'lesson_id' => $postId,
            'course_title' => get_the_title($courseId),
        ]);
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

        // Reject when the edition is in the past. OfferingStatus is admin-managed
        // and doesn't auto-transition when a date passes, so without this guard
        // an admin who forgot to update the status would let users enroll in
        // something that already happened.
        if ($this->editions->isPast($editionId)) {
            ntdst_log('enrollment')->warning('Enrollment rejected: edition is in the past', [
                'user_id' => $userId,
                'edition_id' => $editionId,
            ]);
            return new WP_Error('edition_past', 'Deze editie is voorbij');
        }

        // Check enrollment allowed — effective status folds in past-date,
        // missing-sessions, and other display overrides so the server can't
        // accept what the frontend won't offer.
        $status = $this->editions->getEffectiveStatus($editionId);
        if (!$status->allowsEnrollment()) {
            // Distinguish between "full" and other closed reasons
            if ($status === \Stride\Domain\OfferingStatus::Full) {
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

        // Begin atomic enrollment — lock capacity rows to prevent race conditions
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            // Lock capacity check with FOR UPDATE
            $confirmedCount = $this->registrations->countConfirmedForUpdate($editionId);
            $capacity = $this->editions->getCapacity($editionId);
            if ($capacity > 0 && $confirmedCount >= $capacity) {
                $wpdb->query('ROLLBACK');
                ntdst_log('enrollment')->warning('Enrollment rejected: edition full', [
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                ]);
                return new WP_Error('edition_full', 'This edition is full');
            }

            // Determine initial status based on completion requirements + approval setting
            $completionService = ntdst_get(EnrollmentCompletion::class);
            $hasCompletionRequirements = $completionService->hasRequirements($editionId, 'vad_edition');

            $initialStatus = ($hasCompletionRequirements || $this->editions->requiresApproval($editionId))
                ? RegistrationStatus::Pending
                : RegistrationStatus::Confirmed;

            // Check for existing interest registration to upgrade (before duplicate check).
            // Only allowed when the enrolling user is acting on their own account
            // (self-enrollment): any other path lets an attacker pre-seed an
            // interest row with a victim's email and silently merge their data
            // into the victim's eventual enrollment.
            $upgradedRegistrationId = null;
            $callerId = get_current_user_id();
            $isSelfEnrolment = ($callerId > 0 && $callerId === $userId);
            $user = get_userdata($userId);
            $userEmail = $user ? $user->user_email : '';
            if ($isSelfEnrolment && $userEmail) {
                $existingInterest = $this->registrations->findByEmailAndEdition($userEmail, $editionId);
                $interestHasNoUser = $existingInterest && (int) ($existingInterest->user_id ?? 0) === 0;
                if ($existingInterest
                    && $interestHasNoUser
                    && $existingInterest->status === RegistrationStatus::Interest->value
                ) {
                    // Upgrade: set user_id, merge enrollment data
                    $existingData = is_array($existingInterest->enrollment_data)
                        ? $existingInterest->enrollment_data
                        : (json_decode($existingInterest->enrollment_data ?? '{}', true) ?: []);
                    $newData = is_array($options['enrollment_data'] ?? null)
                        ? $options['enrollment_data']
                        : [];
                    $mergedData = array_merge($existingData, $newData);

                    $this->registrations->upgradeFromInterest(
                        (int) $existingInterest->id,
                        $userId,
                        $initialStatus->value,
                        $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
                        $mergedData
                    );

                    $upgradedRegistrationId = (int) $existingInterest->id;
                }
            }

            if ($upgradedRegistrationId !== null) {
                $registrationId = $upgradedRegistrationId;
                $wpdb->query('COMMIT');
            } else {
                // Check not already registered (within transaction).
                // Interest + Waitlist rows are NOT considered blocking here — they
                // represent prior intent and get reactivated by RegistrationRepository::create().
                $existing = $this->registrations->findByUserAndEdition($userId, $editionId);
                $existingStatus = $existing ? RegistrationStatus::tryFrom($existing->status) : null;
                $isReactivatable = in_array($existingStatus, [
                    RegistrationStatus::Cancelled,
                    RegistrationStatus::Interest,
                    RegistrationStatus::Waitlist,
                ], true);

                if ($existing && !$isReactivatable && $existingStatus && $existingStatus->blocksDuplicate()) {
                    $wpdb->query('ROLLBACK');
                    ntdst_log('enrollment')->warning('Enrollment rejected: already registered', [
                        'user_id' => $userId,
                        'edition_id' => $editionId,
                    ]);
                    return new WP_Error('already_enrolled', 'User is already enrolled in this edition');
                }

                // Build registration data
                $registrationData = [
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                    'status' => $initialStatus->value,
                    'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
                    'enrolled_by' => $options['enrolled_by'] ?? null,
                    'voucher_code' => $options['voucher_code'] ?? null,
                    'notes' => $options['notes'] ?? null,
                    'enrollment_data' => $options['enrollment_data'] ?? null,
                ];

                // Propagate company_id from user meta if not explicitly provided
                if (!isset($options['company_id'])) {
                    $companyId = (int) get_user_meta($userId, '_stride_company_id', true);
                    if ($companyId) {
                        $registrationData['company_id'] = $companyId;
                    }
                } elseif ($options['company_id']) {
                    $registrationData['company_id'] = $options['company_id'];
                }

                // Create registration
                $registrationId = $this->registrations->create($registrationData);

                if (is_wp_error($registrationId)) {
                    $wpdb->query('ROLLBACK');
                    return $registrationId;
                }

                $wpdb->query('COMMIT');
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        // Grant LMS access only for confirmed registrations
        if ($initialStatus === RegistrationStatus::Confirmed) {
            $courseId = $this->editions->getCourseId($editionId);
            if ($courseId) {
                $this->lms->grantAccess($userId, $courseId);
            }
        }

        // Fire event
        $this->dispatch('registration/created', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
            'enrolled_by' => $options['enrolled_by'] ?? null,
            'status' => $initialStatus->value,
        ]);

        ntdst_log('enrollment')->info('Enrollment created', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'registration_id' => $registrationId,
            'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        // Initialize completion tasks for pending registrations with requirements
        if ($hasCompletionRequirements) {
            $completionService->initializeForRegistration($registrationId, $editionId, 'vad_edition');
        }

        return $registrationId;
    }

    /**
     * Register interest in an offering (announcement status).
     *
     * Lightweight registration: no capacity checks, no LMS access, no quote.
     *
     * @param array{edition_id?: int, trajectory_id?: int, notes?: string} $options
     * @return int|WP_Error Registration ID or error
     */
    public function registerInterest(int $userId, array $options = []): int|WP_Error
    {
        $editionId = $options['edition_id'] ?? null;
        $trajectoryId = $options['trajectory_id'] ?? null;

        if (!$editionId && !$trajectoryId) {
            return new WP_Error('invalid_input', 'Edition or trajectory ID is required');
        }

        // Validate offering exists and status is announcement
        if ($editionId) {
            if (!$this->editions->exists($editionId)) {
                return new WP_Error('invalid_edition', 'Edition does not exist');
            }

            // Effective status — accepts announcement-by-policy AND
            // klassikaal-no-sessions editions that fall back to interest.
            $status = $this->editions->getEffectiveStatus($editionId);
            if (!$status->allowsInterest()) {
                return new WP_Error('interest_closed', 'Interest registration is not available for this edition');
            }

            // Check not already registered (any active status)
            if ($this->hasActiveRegistration($userId, editionId: $editionId)) {
                return new WP_Error('already_registered', 'Je hebt al interesse gemeld voor deze editie');
            }
        }

        if ($trajectoryId) {
            $trajectoryService = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);
            $trajectory = $trajectoryService->getTrajectory($trajectoryId);
            if (!$trajectory) {
                return new WP_Error('invalid_trajectory', 'Trajectory does not exist');
            }

            $status = $trajectory['status_enum'] ?? null;
            if (!$status || !$status->allowsInterest()) {
                return new WP_Error('interest_closed', 'Interest registration is not available for this trajectory');
            }

            if ($this->hasActiveRegistration($userId, trajectoryId: $trajectoryId)) {
                return new WP_Error('already_registered', 'Je hebt al interesse gemeld voor dit traject');
            }
        }

        // Build registration data
        $registrationData = [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'status' => RegistrationStatus::Interest->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
            'notes' => $options['notes'] ?? null,
        ];

        // Propagate company_id from user meta
        $companyId = (int) get_user_meta($userId, '_stride_company_id', true);
        if ($companyId) {
            $registrationData['company_id'] = $companyId;
        }

        $registrationId = $this->registrations->create($registrationData);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Fire event (no LMS access, no quote)
        $this->dispatch('registration/interest_registered', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
        ]);

        ntdst_log('enrollment')->info('Interest registered', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'registration_id' => $registrationId,
        ]);

        return $registrationId;
    }

    /**
     * Register on the waitlist for a full edition.
     *
     * Lightweight registration: no capacity checks, no LMS access, no quote.
     * Mirrors registerInterest() but gated on OfferingStatus::Full instead of Announcement.
     *
     * @param array{edition_id?: int, trajectory_id?: int, notes?: string} $options
     * @return int|WP_Error Registration ID or error
     */
    public function registerWaitlist(int $userId, array $options = []): int|WP_Error
    {
        $editionId = $options['edition_id'] ?? null;
        $trajectoryId = $options['trajectory_id'] ?? null;

        if (!$editionId && !$trajectoryId) {
            return new WP_Error('invalid_input', 'Edition or trajectory ID is required');
        }

        if ($editionId) {
            if (!$this->editions->exists($editionId)) {
                return new WP_Error('invalid_edition', 'Edition does not exist');
            }

            $status = $this->editions->getStatus($editionId);
            if (!$status->allowsWaitlist()) {
                return new WP_Error('waitlist_closed', 'Waitlist registration is not available for this edition');
            }

            if ($this->hasActiveRegistration($userId, editionId: $editionId)) {
                return new WP_Error('already_registered', 'Je staat al op de wachtlijst of bent al ingeschreven voor deze editie');
            }
        }

        if ($trajectoryId) {
            $trajectoryService = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);
            $trajectory = $trajectoryService->getTrajectory($trajectoryId);
            if (!$trajectory) {
                return new WP_Error('invalid_trajectory', 'Trajectory does not exist');
            }

            $status = $trajectory['status_enum'] ?? null;
            if (!$status || !$status->allowsWaitlist()) {
                return new WP_Error('waitlist_closed', 'Waitlist registration is not available for this trajectory');
            }

            if ($this->hasActiveRegistration($userId, trajectoryId: $trajectoryId)) {
                return new WP_Error('already_registered', 'Je staat al op de wachtlijst of bent al ingeschreven voor dit traject');
            }
        }

        $registrationData = [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'status' => RegistrationStatus::Waitlist->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
            'notes' => $options['notes'] ?? null,
        ];

        $companyId = (int) get_user_meta($userId, '_stride_company_id', true);
        if ($companyId) {
            $registrationData['company_id'] = $companyId;
        }

        $registrationId = $this->registrations->create($registrationData);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        $this->dispatch('registration/waitlisted', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
        ]);

        ntdst_log('enrollment')->info('Waitlist registered', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'registration_id' => $registrationId,
        ]);

        return $registrationId;
    }

    /**
     * Confirm a pending registration (admin approval).
     *
     * @return true|WP_Error
     */
    public function confirmRegistration(int $registrationId): true|WP_Error
    {
        $registration = $this->registrations->find($registrationId);

        if (is_wp_error($registration)) {
            return $registration;
        }

        if ($registration === null) {
            return new WP_Error('not_found', 'Registration not found');
        }

        if ($registration->status !== RegistrationStatus::Pending->value) {
            return new WP_Error('invalid_status', 'Registration is not pending approval');
        }

        // Update status to confirmed
        $result = $this->registrations->updateStatus($registrationId, RegistrationStatus::Confirmed);

        if (!$result) {
            return new WP_Error('update_failed', 'Failed to confirm registration');
        }

        // Grant LMS access
        $editionId = (int) $registration->edition_id;
        if ($editionId) {
            $courseId = $this->editions->getCourseId($editionId);
            if ($courseId) {
                $this->lms->grantAccess((int) $registration->user_id, $courseId);
            }
        }

        // Fire event
        $this->dispatch('registration/confirmed', [
            'registration_id' => $registrationId,
            'user_id' => (int) $registration->user_id,
            'edition_id' => $editionId,
        ]);

        ntdst_log('enrollment')->info('Registration confirmed by admin', [
            'registration_id' => $registrationId,
            'user_id' => (int) $registration->user_id,
            'edition_id' => $editionId,
        ]);

        return true;
    }

    /**
     * Cancel enrollment.
     *
     * Cannot cancel a registration that is already in a terminal state.
     * Completed registrations are immutable — they represent course completion
     * and may have an attached certificate. Already-cancelled is a no-op error.
     */
    public function cancel(int $registrationId): bool|WP_Error
    {
        $registration = $this->registrations->find($registrationId);

        if (is_wp_error($registration)) {
            return $registration;
        }

        if ($registration === null) {
            return new WP_Error('not_found', 'Registration not found');
        }

        $status = RegistrationStatus::tryFrom($registration->status);
        if ($status === RegistrationStatus::Completed) {
            return new WP_Error('already_completed', 'Cannot cancel a completed registration');
        }
        if ($status === RegistrationStatus::Cancelled) {
            return new WP_Error('already_cancelled', 'Registration is already cancelled');
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
     * Check if user is enrolled in edition (confirmed status).
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
     * Check if user has any active registration (blocks duplicate submissions).
     *
     * Active = anything except cancelled (uses RegistrationStatus::blocksDuplicate).
     */
    public function hasActiveRegistration(int $userId, ?int $editionId = null, ?int $trajectoryId = null): bool
    {
        if ($editionId) {
            $registration = $this->registrations->findByUserAndEdition($userId, $editionId);
        } elseif ($trajectoryId) {
            return $this->registrations->existsForTrajectory($userId, $trajectoryId);
        } else {
            return false;
        }

        if (!$registration) {
            return false;
        }

        $status = RegistrationStatus::tryFrom($registration->status);
        return $status && $status->blocksDuplicate();
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
     *   gln_number?: string,
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
        $isExistingColleague = false;
        if (in_array($enrollmentType, ['colleague', 'collega'], true)) {
            // Check if user exists before resolving (to detect new user creation)
            $existingUser = get_user_by('email', $data['email']);
            $isExistingColleague = ($existingUser instanceof \WP_User);

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

        // Build enrollment options
        $enrollOptions = [
            'enrollment_path' => $enrollmentPath,
            'enrolled_by' => $enrolledBy,
            'voucher_code' => $data['voucher_code'] ?? null,
        ];

        // Split extra_fields: known user meta fields get saved to user profile,
        // remaining course-specific fields go to enrollment_data
        if (!empty($data['extra_fields'])) {
            $userMetaKeys = array_keys($this->getUserMetaMapping());
            $profileFields = [];
            $courseFields = [];

            foreach ($data['extra_fields'] as $key => $value) {
                if (in_array($key, $userMetaKeys, true)) {
                    $profileFields[$key] = $value;
                } else {
                    $courseFields[$key] = $value;
                }
            }

            // Never overwrite a pre-existing user's profile from a colleague
            // enrolment — the enroller does not own that account. Persist the
            // values per-registration only so the data still reaches the quote
            // handler without mutating the victim's wp_users / user_meta rows.
            if ($isExistingColleague && !empty($profileFields)) {
                $courseFields = array_merge($profileFields, $courseFields);
                $profileFields = [];
            }

            if (!empty($profileFields)) {
                $this->updateUserProfile($participantId, $profileFields);
            }
            if (!empty($courseFields)) {
                $enrollOptions['enrollment_data'] = $courseFields;
            }
        }

        // Perform enrollment
        $registrationId = $this->enroll($participantId, $editionId, $enrollOptions);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Handle session selection if provided
        $selectedSessions = $data['selected_sessions'] ?? [];
        if (!empty($selectedSessions) && $this->sessionSelection) {
            $sessionIds = array_map('intval', $selectedSessions);
            $result = $this->sessionSelection->setSelections($registrationId, $sessionIds);
            if (is_wp_error($result)) {
                ntdst_log('enrollment')->warning('Session selection persistence failed', [
                    'registration_id' => $registrationId,
                    'session_ids' => $sessionIds,
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ]);
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
    /**
     * Map of enrollment form field names → user meta keys.
     *
     * Single source of truth shared by:
     * - {@see EnrollmentService::updateUserProfile()} (persistence)
     * - {@see \Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage} (admin "reserved name" warning)
     *
     * When a Questionnaire field name (4-stage form builder under
     * "Formuliervelden") matches a key here, its value is automatically
     * persisted to the corresponding user meta in addition to the
     * per-enrollment `enrollment_data` JSON snapshot.
     *
     * Keys are also used by {@see \Stride\Modules\User\UserLifecycleService} to
     * strip PII on anonymisation.
     *
     * @return array<string, string> inputKey => metaKey
     */
    public static function getUserMetaMapping(): array
    {
        return [
            // Personal identity
            'phone' => 'phone',
            'organisation' => 'organisation',
            'department' => 'department',
            'national_id' => 'national_id',
            'date_of_birth' => 'date_of_birth',
            'professional_license_number' => 'professional_license_number',

            // Billing
            'vat_number' => 'billing_vat',
            'address' => 'billing_address_1',
            'postal_code' => 'billing_postcode',
            'city' => 'billing_city',
            'invoice_email' => 'invoice_email',
            'gln_number' => 'gln_number',
            'company' => 'billing_company',
        ];
    }

    public function updateUserProfile(int $userId, array $data): void
    {
        $metaFields = self::getUserMetaMapping();

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
            'gln_number' => $data['gln_number'] ?? '',
            'po_number' => $data['po_number'] ?? '',
            'voucher_code' => $data['voucher_code'] ?? '',
        ];

        // Store in transient keyed by billing user ID + edition
        $key = 'stride_pending_billing_' . $billingUserId . '_' . $data['edition_id'];
        set_transient($key, $billing, HOUR_IN_SECONDS);
    }
}
