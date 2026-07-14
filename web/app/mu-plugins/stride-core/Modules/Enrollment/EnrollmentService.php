<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\User\CompanyAffiliation;
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
        if (!$this->lms->grantAccess($userId, $courseId)) {
            ntdst_log('enrollment')->warning('Open-course auto-enroll: grantAccess returned false', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'lesson_id' => $postId,
            ]);
        }

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

        // Profile-type enroll gate (INV-12 M1). A profile type marked block:true
        // for this edition genuinely cannot self-enroll. Fail-open when no rule
        // applies. Resolved via the container to match the on-demand collaborator
        // pattern (matches the ntdst_get(TrajectoryService::class) resolution below).
        $policy = ntdst_get(\Stride\Modules\User\ProfileTypePolicy::class);
        if ($policy->blocksEnrollment($userId, $editionId, 'vad_edition')) {
            return new WP_Error('profiletype_blocked', __('Niet beschikbaar voor jouw profieltype', 'stride'));
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

        // DATA-2 / mitigation 2: acquire the (user,edition) tuple advisory lock
        // BEFORE the capacity FOR UPDATE. The capacity lock covers the CAPACITY
        // predicate; the duplicate check at findByUserAndEdition below is a
        // DIFFERENT predicate the capacity lock does not cover. Serializing on
        // the tuple closes the two-concurrent-enrolls-for-the-same-user window.
        //
        // Lock ordering is load-bearing: tuple lock → THEN capacity FOR UPDATE,
        // never the reverse, to avoid deadlock. The same lock is re-acquired
        // (reentrant, same connection) inside RegistrationRepository::create().
        global $wpdb;
        if (!$this->registrations->acquireEnrollLock($userId, $editionId)) {
            ntdst_log('enrollment')->warning('Enrollment rejected: could not acquire tuple lock', [
                'user_id' => $userId,
                'edition_id' => $editionId,
            ]);
            return new WP_Error('lock_timeout', 'Kon de inschrijving niet vergrendelen, probeer het opnieuw.');
        }

        // Begin atomic enrollment — lock capacity rows to prevent race conditions
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

            // Resolve the registration's partner scoping once — used by the
            // lead-upgrade path AND the create path below (parity: whichever
            // branch runs, the row ends up with the same company_id).
            $companyId = null;
            if (!isset($options['company_id'])) {
                $companyId = CompanyAffiliation::getCompanyId($userId) ?: null;
            } elseif ($options['company_id']) {
                $companyId = (int) $options['company_id'];
            }

            // Check for an existing LEAD row (interest OR waitlist) to adopt
            // (before duplicate check). A lead waitlist row is adopted the same
            // way as interest — skipping it minted a second row for the same
            // person+edition that a later promotion re-homed onto the account
            // (review round 2). Only allowed when the enrolling user is acting
            // on their own account (self-enrollment): any other path lets an
            // attacker pre-seed a lead row with a victim's email and silently
            // merge their data into the victim's eventual enrollment.
            $upgradedRegistrationId = null;
            $callerId = get_current_user_id();
            $isSelfEnrolment = ($callerId > 0 && $callerId === $userId);
            $user = get_userdata($userId);
            $userEmail = $user ? $user->user_email : '';
            if ($isSelfEnrolment && $userEmail) {
                $existingLead = $this->registrations->findAnonymousForEmailAndEdition($userEmail, $editionId);
                if ($existingLead) {
                    // Upgrade: set user_id, merge enrollment data
                    $existingData = is_array($existingLead->enrollment_data)
                        ? $existingLead->enrollment_data
                        : (json_decode($existingLead->enrollment_data ?? '{}', true) ?: []);
                    $newData = is_array($options['enrollment_data'] ?? null)
                        ? $options['enrollment_data']
                        : [];
                    $mergedData = array_merge($existingData, $newData);

                    $upgraded = $this->registrations->upgradeFromInterest(
                        (int) $existingLead->id,
                        $userId,
                        $initialStatus->value,
                        $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
                        $mergedData,
                        $companyId,
                    );

                    if ($upgraded) {
                        $upgradedRegistrationId = (int) $existingLead->id;
                    }
                    // On false (concurrent bind won the row) fall through to the
                    // normal duplicate-check + create() path, which resolves the
                    // now-bound row under the enroll lock — never assume the
                    // upgrade happened.
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
                $isReactivatable = $existingStatus && $existingStatus->isReactivatable();

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

                // Partner scoping resolved once above the upgrade branch —
                // options['company_id'] wins, user-meta affiliation otherwise.
                if ($companyId) {
                    $registrationData['company_id'] = $companyId;
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
        } finally {
            // Release the tuple lock on EVERY exit from the transaction span
            // (edition_full / already_enrolled / db_error early returns, the
            // success fall-through, and any rethrown exception). The duplicate
            // window is closed once create() has committed, so releasing here —
            // before the grant/dispatch tail — is correct.
            $this->registrations->releaseEnrollLock($userId, $editionId);
        }

        // Grant LMS access only for confirmed registrations
        if ($initialStatus === RegistrationStatus::Confirmed) {
            $courseId = $this->editions->getCourseId($editionId);
            if ($courseId && !$this->lms->grantAccess($userId, $courseId)) {
                ntdst_log('enrollment')->warning('Enrollment created but grantAccess returned false', [
                    'registration_id' => $registrationId,
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                    'course_id' => $courseId,
                ]);
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
        $companyId = CompanyAffiliation::getCompanyId($userId);
        if ($companyId) {
            $registrationData['company_id'] = $companyId;
        }

        $registrationId = $this->registrations->create($registrationData);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Fire event (no LMS access, no quote).
        // Include name/email so the confirmation mail can reach ANONYMOUS
        // registrants (user_id = 0): StrideMailBridge::sendUserStageMail reads
        // these from the payload for the anonymous branch and otherwise returns
        // early with no send. Omitting them silently dropped every anonymous
        // interest confirmation email (bug ML-01).
        $this->dispatch('registration/interest_registered', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'name' => (string) ($options['name'] ?? ''),
            'email' => (string) ($options['email'] ?? ''),
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

            // Profile-type enroll gate (INV-12 M1): the block applies to the
            // user-initiated waitlist self-registration too.
            $policy = ntdst_get(\Stride\Modules\User\ProfileTypePolicy::class);
            if ($policy->blocksEnrollment($userId, $editionId, 'vad_edition')) {
                return new WP_Error('profiletype_blocked', __('Niet beschikbaar voor jouw profieltype', 'stride'));
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

            // Profile-type enroll gate (INV-12 M1): trajectory waitlist too.
            $policy = ntdst_get(\Stride\Modules\User\ProfileTypePolicy::class);
            if ($policy->blocksEnrollment($userId, $trajectoryId, 'vad_trajectory')) {
                return new WP_Error('profiletype_blocked', __('Niet beschikbaar voor jouw profieltype', 'stride'));
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

        $companyId = CompanyAffiliation::getCompanyId($userId);
        if ($companyId) {
            $registrationData['company_id'] = $companyId;
        }

        $registrationId = $this->registrations->create($registrationData);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Include name/email for anonymous registrants — see ML-01 note on the
        // interest dispatch above; the same mail-bridge early-return applies.
        $this->dispatch('registration/waitlisted', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'name' => (string) ($options['name'] ?? ''),
            'email' => (string) ($options['email'] ?? ''),
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

        return $this->confirmCore($registration, 'Registration confirmed by admin');
    }

    /**
     * Promote a waitlisted registration to confirmed (admin / bulk action).
     *
     * Shares the SAME single grant + event path as confirmRegistration() via
     * confirmCore() — there is no second confirm code path. The seat re-check is
     * race-safe: it locks confirmed rows with FOR UPDATE inside a transaction,
     * mirroring the enroll() capacity guard, so two concurrent promotes cannot
     * both slip into the last seat.
     *
     * @return true|WP_Error
     */
    public function promoteFromWaitlist(int $registrationId): true|WP_Error
    {
        $registration = $this->registrations->find($registrationId);

        if (is_wp_error($registration)) {
            return $registration;
        }

        if ($registration === null) {
            return new WP_Error('not_found', 'Registration not found');
        }

        if ($registration->status !== RegistrationStatus::Waitlist->value) {
            return new WP_Error('invalid_status', 'Registration is not on the waitlist');
        }

        $editionId = (int) $registration->edition_id;

        // INV-7: a terminal edition (cancelled/completed/archived) cannot accept
        // new confirmed seats — gate on effective status, not stored status.
        if ($editionId && $this->editions->getEffectiveStatus($editionId)->isTerminal()) {
            return new WP_Error('edition_closed', 'Edition is closed');
        }

        // INV-9 / M-SEQUENCE: an anonymous (public-form) waitlist lead carries no
        // WP account. Resolve (find-or-create) a real account from the upfront-
        // captured name/email, map the captured billing/personal fields onto a
        // NEW account only (M-NO-OVERWRITE: never onto a pre-existing account),
        // and re-link the row — all BEFORE the capacity transaction, which only
        // flips status. A later transaction rollback leaves the row on the
        // waitlist carrying a real user_id (benign-idempotent: this block is
        // gated on user_id===0, so a retry resolves nothing new).
        //
        // $resolvedToExistingAnon tracks the COLLISION case specifically: an
        // anonymous lead (entered the branch below) whose email matched a
        // pre-existing account. That is the ONLY case whose confirmation mail is
        // suppressed (M-NEW-USER-MAIL-ONLY). A normal accounted promote never
        // enters the branch, so it keeps the default false and still mails.
        $resolvedToExistingAnon = false;
        $createdNewAnonAccount = false;
        if ((int) $registration->user_id === 0) {
            $captured = $registration->enrollment_data['waitlist']['data'] ?? [];

            $resolved = $this->resolveLeadAccount(
                (string) ($captured['email'] ?? ''),
                (string) ($captured['name'] ?? ''),
            );
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $resolvedToExistingAnon = $resolved['was_existing'];
            $createdNewAnonAccount = !$resolved['was_existing'];

            // Duplicate guard (review round 2): the resolved EXISTING account
            // may already hold a registration for this edition — the person
            // submitted the waitlist form logged-out AND has (or had) an
            // account-bound row. Binding + confirming would mint the
            // user+edition duplicate shape the enroll lock exists to prevent
            // (double capacity count, double grant/mail/quote). Refuse and
            // hand it to the admin with the existing row named — exactly the
            // triage semantics of scripts/adopt-leads.php.
            if ($resolvedToExistingAnon) {
                $accountRow = $this->registrations->findByUserAndEdition($resolved['user_id'], $editionId);
                if ($accountRow && (int) $accountRow->id !== $registrationId) {
                    $accountRowStatus = RegistrationStatus::tryFrom((string) $accountRow->status);

                    return new WP_Error('duplicate_registration', sprintf(
                        /* translators: 1: status label, 2: registration id */
                        __('Dit e-mailadres hoort bij een account dat al een inschrijving voor deze editie heeft (%1$s, #%2$d). Los eerst dat dubbel op.', 'stride'),
                        $accountRowStatus ? $accountRowStatus->label() : (string) $accountRow->status,
                        (int) $accountRow->id,
                    ));
                }
            }

            // M-META-MAP — new account only: map the captured reserved-name fields
            // onto the new user via the existing convergence (getUserMetaMapping /
            // updateUserProfile, which re-sanitizes). M-NO-OVERWRITE — an existing
            // account is left entirely untouched (no meta write at all).
            if (!$resolvedToExistingAnon) {
                $this->updateUserProfile($resolved['user_id'], $captured);
            }

            // M-PER-ROW: the relink is committed standalone BEFORE the capacity
            // transaction. Guard its outcome — a failed re-link must become a
            // per-row WP_Error, never a confirm against the stale user_id=0 row
            // (which would orphan-grant access to user 0). bindLeadToUser is
            // guarded on the row still being account-less; 0 affected rows now
            // counts as a failed bind too (a concurrent bind means THIS confirm
            // must not proceed against assumptions — M-IDEMPOTENT gates re-entry
            // on user_id===0, so the idempotent retry never reaches this branch).
            // Partner scoping parity travels IN the bind statement: the bound
            // account's company affiliation is stamped once, guarded so an
            // admin-set company on the row is never overwritten — or the
            // promoted registration stays invisible to the Partner API purely
            // because it started as a lead.
            $leadCompanyId = \Stride\Modules\User\CompanyAffiliation::getCompanyId($resolved['user_id']);
            if ($this->registrations->bindLeadToUser($registrationId, $resolved['user_id'], $leadCompanyId ?: null) === false) {
                ntdst_log('enrollment')->error('Failed to relink waitlist row to resolved account', [
                    'registration_id' => $registrationId,
                    'user_id' => $resolved['user_id'],
                ]);

                return new WP_Error(
                    'relink_failed',
                    __('De wachtlijst-aanmelding kon niet aan het account gekoppeld worden.', 'stride'),
                );
            }

            // Only user_id (+ company) changed; reflect it on the in-memory row
            // (avoids an unguarded re-find + an extra query that could
            // null-deref on a concurrently-deleted row).
            $registration->user_id = $resolved['user_id'];
            if ($leadCompanyId && empty($registration->company_id)) {
                $registration->company_id = $leadCompanyId;
            }
        }

        // Race-safe per-row capacity re-check + status transition under one
        // transaction, mirroring enroll(): lock confirmed rows FOR UPDATE so a
        // concurrent promote/enroll cannot both consume the final seat.
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            if ($editionId) {
                $confirmedCount = $this->registrations->countConfirmedForUpdate($editionId);
                $capacity = $this->editions->getCapacity($editionId);
                if ($capacity > 0 && $confirmedCount >= $capacity) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('capacity_full', 'Edition is full');
                }
            }

            $result = $this->registrations->updateStatus($registrationId, RegistrationStatus::Confirmed);
            if (!$result) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('update_failed', 'Failed to promote registration');
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            ntdst_log('enrollment')->error('Waitlist promote failed', [
                'registration_id' => $registrationId,
                'edition_id' => $editionId,
                'error' => $e->getMessage(),
            ]);

            return new WP_Error('promote_failed', 'Failed to promote registration');
        }

        // Status already transitioned to Confirmed inside the transaction; run the
        // shared grant + event tail without re-writing the status. Thread the
        // account-resolution outcome through so StrideMailBridge can gate the
        // confirmation mail (M-NEW-USER-MAIL-ONLY): a brand-new account is the
        // intended welcome recipient (mail allowed), but the COLLISION case — an
        // anonymous lead resolved to a PRE-EXISTING account — must NOT receive an
        // unsolicited confirmation mail (suppressConfirmMail). A normal accounted
        // promote never entered the anon branch, so both flags stay false and its
        // confirmation mail is sent as before.
        return $this->confirmCore(
            $registration,
            'Registration promoted from waitlist',
            false,
            $createdNewAnonAccount,
            $resolvedToExistingAnon,
        );
    }

    /**
     * Shared confirm tail: transition to Confirmed (unless already done), grant
     * LMS access, fire stride/registration/confirmed exactly once.
     *
     * This is the ONE grant/event code path — both confirmRegistration() and
     * promoteFromWaitlist() route through here (lesson_pure_passthrough_is_drift).
     *
     * @param object $registration   the (pre-transition) registration row.
     * @param bool   $writeStatus    write status=Confirmed here; false when the
     *                               caller already transitioned it under a lock.
     * @param bool   $wasNewAccount  true when this confirm resolved a brand-new
     *                               account for an anonymous waitlist lead.
     *                               Additive on the event payload for listeners.
     * @param bool   $suppressConfirmMail true ONLY for the anonymous-promote
     *                               COLLISION case (an anon lead whose email
     *                               matched a PRE-EXISTING account). The seeded
     *                               stride-enrollment-confirmed trigger would
     *                               otherwise send an unsolicited confirmation
     *                               mail to that stranger's account
     *                               (M-NEW-USER-MAIL-ONLY / attack 6). The grant,
     *                               audit, quote and cache listeners still fire —
     *                               only the confirmation mail is suppressed.
     * @return true|WP_Error
     */
    private function confirmCore(
        object $registration,
        string $logMessage,
        bool $writeStatus = true,
        bool $wasNewAccount = false,
        bool $suppressConfirmMail = false,
    ): true|WP_Error {
        $registrationId = (int) $registration->id;
        $editionId = (int) $registration->edition_id;

        if ($writeStatus) {
            $result = $this->registrations->updateStatus($registrationId, RegistrationStatus::Confirmed);
            if (!$result) {
                return new WP_Error('update_failed', 'Failed to confirm registration');
            }
        }

        // Grant LMS access
        if ($editionId) {
            $courseId = $this->editions->getCourseId($editionId);
            if ($courseId && !$this->lms->grantAccess((int) $registration->user_id, $courseId)) {
                ntdst_log('enrollment')->warning('Registration confirmed but grantAccess returned false', [
                    'registration_id' => $registrationId,
                    'user_id' => (int) $registration->user_id,
                    'edition_id' => $editionId,
                    'course_id' => $courseId,
                ]);
            }
        }

        // Fire event. `was_new_account` is additive (existing listeners ignore
        // unknown keys) and lets StrideMailBridge suppress the seeded
        // confirmation-mail trigger for the anonymous-promote COLLISION case —
        // a confirm against a PRE-EXISTING account whose email a stranger typed
        // into the public waitlist form must not receive an unsolicited
        // confirmation/welcome mail (M-NEW-USER-MAIL-ONLY / attack 6). A normal
        // accounted confirm (where no account was resolved on this path) carries
        // was_new_account=false too, but is NOT suppressed — the bridge keys
        // suppression on the anon-promote collision, not on the flag alone.
        $this->dispatch('registration/confirmed', [
            'registration_id' => $registrationId,
            'user_id' => (int) $registration->user_id,
            'edition_id' => $editionId,
            'was_new_account' => $wasNewAccount,
            'suppress_confirm_mail' => $suppressConfirmMail,
        ]);

        ntdst_log('enrollment')->info($logMessage, [
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
     * Edition ids where the user has a confirmed registration.
     *
     * The batch equivalent of isEnrolled() for catalog surfaces (Task G1 /
     * audit 2.2): one per-request-cached query via
     * RegistrationRepository::findByUser() instead of a lookup per card.
     * Same contract as isEnrolled(): confirmed status only.
     *
     * @return list<int>
     */
    public function getEnrolledEditionIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $out = [];
        foreach ($this->registrations->findByUser($userId) as $row) {
            $editionId = (int) ($row->edition_id ?? 0);
            if ($editionId > 0 && ($row->status ?? '') === RegistrationStatus::Confirmed->value) {
                $out[$editionId] = $editionId;
            }
        }

        return array_values($out);
    }

    /**
     * Trajectory ids where the user has an active enrollment.
     *
     * The trajectory analogue of getEnrolledEditionIds() for catalog surfaces:
     * one per-request-cached findByUser() pass instead of an existsForTrajectory()
     * lookup per card. Trajectory enrollment is a PARENT row (trajectory_id set,
     * edition_id NULL); "active" follows the duplicate-guard semantics
     * (RegistrationStatus::blocksDuplicate) so pending trajectory enrollments
     * also count as enrolled — matching hasActiveRegistration()/existsForTrajectory()
     * used on the detail page.
     *
     * @return list<int>
     */
    public function getEnrolledTrajectoryIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $out = [];
        foreach ($this->registrations->findByUser($userId) as $row) {
            $trajectoryId = (int) ($row->trajectory_id ?? 0);
            $editionId    = (int) ($row->edition_id ?? 0);
            if ($trajectoryId <= 0 || $editionId > 0) {
                continue; // not a trajectory parent (skip edition rows + cascade children)
            }
            $status = RegistrationStatus::tryFrom($row->status ?? '');
            if ($status && $status->blocksDuplicate()) {
                $out[$trajectoryId] = $trajectoryId;
            }
        }

        return array_values($out);
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
                $data['last_name'],
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
                // Merge into any pre-wrapped envelope from the caller (the
                // form handler pre-wraps the custom answers) instead of
                // clobbering it — clobbering dropped the custom answers for
                // existing-colleague enrollments where profileFields fold
                // back into courseFields.
                $actorId = get_current_user_id() ?: null;
                $preWrapped = is_array($data['enrollment_data'] ?? null) ? $data['enrollment_data'] : [];
                $existingPersonal = $preWrapped['enrollment_personal']['data'] ?? [];
                $preWrapped['enrollment_personal'] = RegistrationRepository::wrapStage(
                    array_merge(is_array($existingPersonal) ? $existingPersonal : [], $courseFields),
                    $actorId,
                );
                $enrollOptions['enrollment_data'] = $preWrapped;
            }
        }

        // Honor pre-wrapped enrollment_data set by the caller (e.g. EnrollmentFormHandler)
        // when no extra_fields processing overrode it.
        if (!isset($enrollOptions['enrollment_data']) && is_array($data['enrollment_data'] ?? null)) {
            $enrollOptions['enrollment_data'] = $data['enrollment_data'];
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

        // Snapshot the original selection into enrollment_data (append-only).
        // `none` type when no selection step ran; `edition` with session IDs otherwise.
        // This records intent at enrollment time — distinct from the live `selections` column.
        $hasSessions = !empty($selectedSessions) && $this->sessionSelection;
        if ($hasSessions) {
            $this->registrations->appendInitialSelectionPhase(
                $registrationId,
                [
                    'phase'       => 'enrollment',
                    'session_ids' => array_map('intval', $selectedSessions),
                ],
                'edition',
            );
        } else {
            $this->registrations->appendInitialSelectionPhase(
                $registrationId,
                ['phase' => 'enrollment'],
                'none',
            );
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
     * Collision-safe find-or-create for an anonymous lead account (INV-9).
     *
     * The single convergence point that turns an anonymous pre-account
     * registration (the public interest/waitlist forms) into a real WP account.
     * Unlike {@see resolveParticipant()} (the colleague-enroll sibling, a tracked
     * INV-9 bypass) this method sends NO credential/welcome notification: a
     * matched existing account is returned untouched (M-COLLISION-SAFE / attack 6),
     * and this method writes NO billing/personal meta — meta-mapping happens in
     * the promote branch where {@see $wasExisting} is consumed (M-NO-OVERWRITE /
     * M-META-MAP). The welcome mail is sent later, only for new accounts, via the
     * enriched `stride/registration/confirmed` event.
     *
     * @return array{user_id:int, was_existing:bool}|WP_Error
     */
    private function resolveLeadAccount(string $email, string $name): array|WP_Error
    {
        // M-EMAIL-VALIDATE — re-validate the stored (public-form) email BEFORE
        // any user creation.
        $email = sanitize_email((string) $email);
        if (!is_email($email)) {
            return new WP_Error(
                'lead_no_email',
                __('De wachtlijst-aanmelding heeft geen geldig e-mailadres.', 'stride'),
            );
        }

        // M-COLLISION-SAFE — found existing: return the ID, send NO credentials,
        // write NO meta, return BEFORE any notification.
        $existing = get_user_by('email', $email);
        if ($existing) {
            return ['user_id' => (int) $existing->ID, 'was_existing' => true];
        }

        // Create a new, active account. Derive a unique username from the email
        // local-part (mirrors resolveParticipant's uniqueness idiom).
        $username = sanitize_user(explode('@', $email)[0], true);
        $counter = 1;
        while (username_exists($username)) {
            $username = sanitize_user(explode('@', $email)[0], true) . $counter;
            $counter++;
        }

        $password = wp_generate_password(16, true, true); // never logged/returned
        $userId = wp_create_user($username, $password, $email);

        if (is_wp_error($userId)) {
            ntdst_log('enrollment')->error('Failed to create lead account on waitlist promote', [
                'email' => $email,
                'error' => $userId->get_error_message(),
            ]);

            return new WP_Error(
                'account_create_failed',
                __('Het account voor de wachtlijst-aanmelding kon niet worden aangemaakt.', 'stride'),
            );
        }

        $name = sanitize_text_field($name);
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? '';

        wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $name !== '' ? $name : $username,
        ]);

        return ['user_id' => (int) $userId, 'was_existing' => false];
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
