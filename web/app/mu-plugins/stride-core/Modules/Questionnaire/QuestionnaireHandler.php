<?php

declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\CompanyAffiliation;
use WP_Error;

/**
 * API action handlers for questionnaire submissions.
 *
 * Thin handler — validates input, delegates to repository.
 */
final class QuestionnaireHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_submit_interest', [$this, 'handleSubmitInterest'], 10, 2);
        add_filter('ntdst/api_data/stride_submit_waitlist', [$this, 'handleSubmitWaitlist'], 10, 2);
        add_filter('ntdst/api_data/stride_submit_intake', [$this, 'handleSubmitStage'], 10, 2);
        add_filter('ntdst/api_data/stride_submit_evaluation', [$this, 'handleSubmitStage'], 10, 2);

        // Interest and waitlist are public (anonymous allowed)
        add_filter('ntdst/api/public_actions', function (array $actions): array {
            $actions[] = 'stride_submit_interest';
            $actions[] = 'stride_submit_waitlist';
            return $actions;
        });
    }

    public function handleSubmitInterest(mixed $data, array $params): array|WP_Error
    {
        $editionId = absint($params['edition_id'] ?? 0);
        $name = sanitize_text_field($params['name'] ?? '');
        $email = sanitize_email($params['email'] ?? '');

        if (!$editionId || empty($name) || empty($email)) {
            return new WP_Error('validation_error', __('Naam, e-mailadres en editie zijn vereist.', 'stride'));
        }

        // Validate extra fields
        $extraFields = $this->sanitizeExtraFields($params['extra_fields'] ?? []);
        $validator = ntdst_get(QuestionnaireValidator::class);
        $validationResult = $validator->validate($extraFields, $editionId, 'interest');
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        $stageData = array_merge(['name' => $name, 'email' => $email], $extraFields);
        $wrapped = RegistrationRepository::wrapStage($stageData, get_current_user_id() ?: null);

        $result = $this->upsertStageSubmission($editionId, 'interest', $email, $wrapped);
        if (is_wp_error($result)) {
            return $result;
        }
        [$registrationId, $boundUserId] = $result;

        // Same semantic event the logged-in EnrollmentService path dispatches —
        // mail (StrideMailBridge), audit (AuditBridge) and any future consumer
        // hang off this one emission point instead of inline ndmail_send calls.
        do_action('stride/registration/interest_registered', [
            'registration_id' => $registrationId,
            'user_id'         => $boundUserId ?: null,
            'edition_id'      => $editionId,
            'name'            => $name,
            'email'           => $email,
        ]);

        return [
            'success' => true,
            'message' => __('Je interesse is geregistreerd. We houden je op de hoogte!', 'stride'),
        ];
    }

    public function handleSubmitWaitlist(mixed $data, array $params): array|WP_Error
    {
        $editionId = absint($params['edition_id'] ?? 0);
        $name = sanitize_text_field($params['name'] ?? '');
        $email = sanitize_email($params['email'] ?? '');

        if (!$editionId || empty($name) || empty($email)) {
            return new WP_Error('validation_error', __('Naam, e-mailadres en editie zijn vereist.', 'stride'));
        }

        $extraFields = $this->sanitizeExtraFields($params['extra_fields'] ?? []);
        $validator = ntdst_get(QuestionnaireValidator::class);
        $validationResult = $validator->validate($extraFields, $editionId, 'waitlist');
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        // The native offer/invoice fields rendered by the waitlist template are
        // NOT declared in any questionnaire group, so QuestionnaireValidator does
        // not enforce them. Enforce the offer essentials here. Field names are the
        // EnrollmentService::getUserMetaMapping() input-keys so they map cleanly to
        // billing_* usermeta on promote.
        $nativeRequired = [
            'company' => __('Bedrijf of organisatie op factuur is verplicht.', 'stride'),
            'vat_number' => __('BTW-nummer is verplicht.', 'stride'),
            'invoice_email' => __('Facturatie e-mailadres is verplicht.', 'stride'),
        ];
        foreach ($nativeRequired as $field => $message) {
            if (empty($extraFields[$field])) {
                return new WP_Error('validation_error', $message);
            }
        }
        if (!is_email($extraFields['invoice_email'])) {
            return new WP_Error('validation_error', __('Facturatie e-mailadres is ongeldig.', 'stride'));
        }

        $stageData = array_merge(['name' => $name, 'email' => $email], $extraFields);
        $wrapped = RegistrationRepository::wrapStage($stageData, get_current_user_id() ?: null);

        $result = $this->upsertStageSubmission($editionId, 'waitlist', $email, $wrapped);
        if (is_wp_error($result)) {
            return $result;
        }
        [$registrationId, $boundUserId] = $result;

        // Same semantic event the logged-in EnrollmentService path dispatches —
        // see handleSubmitInterest for the rationale.
        do_action('stride/registration/waitlisted', [
            'registration_id' => $registrationId,
            'user_id'         => $boundUserId ?: null,
            'edition_id'      => $editionId,
            'name'            => $name,
            'email'           => $email,
        ]);

        return [
            'success' => true,
            'message' => __('Je staat op de wachtlijst. We nemen contact op als er een plaats vrijkomt.', 'stride'),
        ];
    }

    public function handleSubmitStage(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $editionId = absint($params['edition_id'] ?? 0);
        if (!$editionId) {
            return new WP_Error('invalid_input', __('Geen editie opgegeven.', 'stride'));
        }

        // Determine stage from the current filter
        $stage = str_contains(current_filter(), 'intake') ? 'intake' : 'evaluation';

        // Find existing registration
        $registrations = ntdst_get(RegistrationRepository::class);
        $registration = $registrations->findByUserAndEdition($userId, $editionId);
        if (!$registration) {
            return new WP_Error('no_registration', __('Geen inschrijving gevonden.', 'stride'));
        }

        // Check registration status matches expected state
        $expectedStatus = $stage === 'intake' ? RegistrationStatus::Confirmed : RegistrationStatus::Completed;
        if ($registration->status !== $expectedStatus->value) {
            return new WP_Error('invalid_status', __('Je inschrijving heeft niet de juiste status voor dit formulier.', 'stride'));
        }

        // Validate
        $extraFields = $this->sanitizeExtraFields($params['extra_fields'] ?? []);
        $validator = ntdst_get(QuestionnaireValidator::class);
        $validationResult = $validator->validate($extraFields, $editionId, $stage);
        if (is_wp_error($validationResult)) {
            return $validationResult;
        }

        // Merge stage data into enrollment_data (already decoded to array by the repository)
        $existingData = is_array($registration->enrollment_data ?? null) ? $registration->enrollment_data : [];
        $existingData[$stage] = RegistrationRepository::wrapStage(
            $extraFields,
            get_current_user_id() ?: null,
        );

        $updated = $registrations->update((int) $registration->id, [
            'enrollment_data' => $existingData,
        ]);
        if (!$updated) {
            ntdst_log('enrollment')->error('Stage submission update failed', [
                'registration_id' => (int) $registration->id,
                'edition_id' => $editionId,
                'stage' => $stage,
            ]);
            return new WP_Error('update_failed', __('Je antwoorden konden niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
        }

        return [
            'success' => true,
            'message' => __('Bedankt voor het invullen!', 'stride'),
        ];
    }

    /**
     * E-mail→account resolution, SAFER VARIANT (form-identity plan
     * 2026-07-14, Stefan's decision): bind at submission time ONLY when the
     * submitter is logged in AND the submitted e-mail is their own account
     * e-mail. Every other submission — a visitor, or a logged-in user
     * submitting for someone else — stays a lead and is adopted
     * collision-safely at promotion/enrollment (INV-9).
     *
     * Deliberately NOT a get_user_by() lookup on arbitrary e-mails: a
     * visitor typing a member's address must never write into that member's
     * account (plan threat 2), and the form response is identical in every
     * branch so nothing leaks whether an e-mail has an account (threat 1).
     *
     * @return int The submitter's user id when self-bound, 0 otherwise.
     */
    private function resolveSelfBoundUser(string $email): int
    {
        $current = wp_get_current_user();
        if (!$current || !$current->ID) {
            return 0;
        }

        return strcasecmp($email, (string) $current->user_email) === 0 ? (int) $current->ID : 0;
    }

    /**
     * The ONE upsert both public pre-enrollment forms (interest, waitlist)
     * run: resolve the participant (self-bound account or lead), dedupe to
     * one row per participant per edition, append the stage envelope.
     *
     * Row resolution order for a self-bound submission:
     *  1. an existing row on the ACCOUNT (any status) — pre-enrollment
     *     statuses get the stage appended; an active enrollment is a
     *     friendly "al ingeschreven" error (own state, no info leak);
     *  2. an existing LEAD row for this e-mail+edition (submitted earlier
     *     while logged out) — bound to the account first
     *     (bindLeadToUser), so one person never accumulates two rows;
     *  3. none → a new account-bound row.
     * A lead submission (visitor / on-behalf) keeps today's behavior:
     * one lead row per e-mail per edition, stage appended on repeat. It
     * deliberately does NOT dedupe against BOUND rows — that would require
     * resolving arbitrary e-mails to accounts (threat 2) or leaking whether
     * an e-mail has a registration (threat 1). The transient duplicate is
     * visible in the admin grid (account-match badge) and promotion refuses
     * to bind a lead onto an account that already holds a row for the
     * edition (EnrollmentService::promoteFromWaitlist duplicate guard).
     *
     * @param  string $stage 'interest'|'waitlist' (also the target status).
     * @return array{0:int,1:int}|WP_Error  [registrationId, boundUserId (0 = lead)]
     */
    private function upsertStageSubmission(int $editionId, string $stage, string $email, array $wrapped): array|WP_Error
    {
        $registrations = ntdst_get(RegistrationRepository::class);
        $status = $stage === 'waitlist' ? RegistrationStatus::Waitlist : RegistrationStatus::Interest;

        // Server-side availability gate (INV-7): the render-time CTA gating
        // is bypassable with a crafted POST — enforce the same rule here.
        // Interest is an Announcement affordance, waitlist a Full one
        // (identical to the edition template's CTA conditions). The check is
        // identity-independent, so the response leaks nothing about accounts.
        $editions = ntdst_get(EditionService::class);
        if (!$editions->exists($editionId)) {
            return new WP_Error('invalid_edition', __('Deze editie bestaat niet.', 'stride'));
        }
        $offering = $editions->getEffectiveStatus($editionId);
        $stageAllowed = $status === RegistrationStatus::Waitlist
            ? $offering->allowsWaitlist()
            : $offering->allowsInterest();
        if (!$stageAllowed) {
            return new WP_Error('not_available', __('Aanmelden is momenteel niet mogelijk voor deze editie.', 'stride'));
        }

        $boundUserId = $this->resolveSelfBoundUser($email);
        $companyId = $boundUserId ? (CompanyAffiliation::getCompanyId($boundUserId) ?: null) : null;
        $row = null;

        if ($boundUserId) {
            $row = $registrations->findByUserAndEdition($boundUserId, $editionId);

            if (!$row) {
                $lead = $registrations->findAnonymousForEmailAndEdition($email, $editionId);
                if ($lead) {
                    // Partner scoping travels IN the bind (guarded COALESCE) —
                    // no separate stamp write, no submission-order dependence.
                    if (!$registrations->bindLeadToUser((int) $lead->id, $boundUserId, $companyId)) {
                        // INV-4: a failed bind (SQL error, or a concurrent bind
                        // won the row) must never fall through to create() —
                        // that would mint the second row the adopt exists to
                        // prevent, or downgrade a waitlist row via create()'s
                        // reactivate branch.
                        ntdst_log('enrollment')->error('Self-bind lead adoption failed', [
                            'registration_id' => (int) $lead->id,
                            'user_id' => $boundUserId,
                            'edition_id' => $editionId,
                        ]);
                        return new WP_Error('update_failed', __('Je aanmelding kon niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
                    }
                    // Only user_id/company changed, nothing below reads them
                    // stale — reflect in memory instead of an unguarded
                    // re-find whose null return would fall through to create().
                    $row = $lead;
                    $row->user_id = $boundUserId;
                    $row->company_id = $row->company_id ?: $companyId;
                }
            }
        } else {
            $row = $registrations->findAnonymousForEmailAndEdition($email, $editionId);
        }

        if ($row) {
            $rowStatus = RegistrationStatus::tryFrom((string) $row->status);

            if ($boundUserId && (!$rowStatus || !$rowStatus->isReactivatable())) {
                // Active enrollment on the OWN account — friendly error (own
                // state, no info leak about other accounts).
                return new WP_Error(
                    'already_registered',
                    __('Je bent al ingeschreven voor deze editie.', 'stride'),
                );
            }

            // A cancelled ACCOUNT row reactivates through create(): its
            // reactivate branch runs under the enroll lock and does the FULL
            // reset — cancelled_at, stale quote_id/selections/completion_tasks/
            // completed_at, and a fresh registered_at (waitlist seniority is
            // registered_at ASC; keeping the old stamp would let a returning
            // user queue-jump everyone who joined since). Lead rows never land
            // here: findAnonymousForEmailAndEdition filters interest/waitlist.
            if ($boundUserId && $rowStatus === RegistrationStatus::Cancelled) {
                $existingData = is_array($row->enrollment_data ?? null) ? $row->enrollment_data : [];
                $existingData[$stage] = $wrapped;

                $reactivated = $registrations->create([
                    'user_id' => $boundUserId,
                    'edition_id' => $editionId,
                    'status' => $status->value,
                    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                    'enrollment_data' => $existingData,
                    'company_id' => $companyId,
                ]);
                if (is_wp_error($reactivated)) {
                    return $reactivated;
                }

                return [(int) $reactivated, $boundUserId];
            }

            // Live interest/waitlist row (bound or lead): append the stage
            // envelope. The read-modify-write runs under the selection lock
            // (DATA-MODEL §5 — interleaved submissions diffing against the
            // same pre-state was a shipped bug), and the row is re-read
            // INSIDE the lock so the no-downgrade decision and the data merge
            // see fresh state.
            $rowId = (int) $row->id;
            if (!$registrations->acquireSelectionLock($rowId)) {
                return new WP_Error('update_failed', __('Je aanmelding kon niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
            }

            try {
                $fresh = $registrations->find($rowId);
                if (!$fresh) {
                    return new WP_Error('update_failed', __('Je aanmelding kon niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
                }
                $row = $fresh;
                $rowStatus = RegistrationStatus::tryFrom((string) $row->status);

                // Never DOWNGRADE waitlist → interest: a waitlist row is
                // promotion-eligible (a stronger claim than interest), so an
                // interest submission on top of it appends its data but keeps
                // the waitlist status. The reverse (interest → waitlist) is a
                // normal upgrade. Applies to bound AND lead rows alike.
                $targetStatus = ($rowStatus === RegistrationStatus::Waitlist && $status === RegistrationStatus::Interest)
                    ? RegistrationStatus::Waitlist
                    : $status;

                $existingData = is_array($row->enrollment_data ?? null) ? $row->enrollment_data : [];
                $existingData[$stage] = $wrapped;

                $update = [
                    'status' => $targetStatus->value,
                    'enrollment_data' => $existingData,
                ];

                // The row may have been cancelled between the pre-lock read and
                // this fresh read — clear the stamp like create()'s reactivate
                // branch does (updateStatus() only stamps cancelled_at when
                // empty, so a stale value would freeze forever).
                if ($rowStatus === RegistrationStatus::Cancelled) {
                    $update['cancelled_at'] = null;
                }

                // Partner scoping parity for a PRE-EXISTING account row that
                // never got a company (adopted rows are stamped in the bind).
                if ($boundUserId && $companyId && empty($row->company_id)) {
                    $update['company_id'] = $companyId;
                }

                $updated = $registrations->update($rowId, $update);
                if (!$updated) {
                    ntdst_log('enrollment')->error('Stage submission update failed', [
                        'registration_id' => $rowId,
                        'edition_id' => $editionId,
                        'stage' => $stage,
                    ]);
                    return new WP_Error('update_failed', __('Je aanmelding kon niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
                }
            } finally {
                $registrations->releaseSelectionLock($rowId);
            }

            return [$rowId, $boundUserId];
        }

        $payload = [
            'user_id' => $boundUserId ?: null,
            'edition_id' => $editionId,
            'status' => $status->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
            'enrollment_data' => [$stage => $wrapped],
        ];

        // Account-bound rows carry the partner affiliation like every other
        // account path (mirrors EnrollmentService::registerInterest).
        if ($boundUserId && $companyId) {
            $payload['company_id'] = $companyId;
        }

        $created = $registrations->create($payload);
        if (is_wp_error($created)) {
            return $created;
        }

        return [(int) $created, $boundUserId];
    }

    private function sanitizeExtraFields(array|string $fields): array
    {
        if (is_string($fields)) {
            $fields = json_decode($fields, true) ?: [];
        }
        $sanitized = [];
        foreach ($fields as $key => $value) {
            $sanitized[sanitize_key($key)] = is_string($value) ? sanitize_text_field($value) : $value;
        }
        return $sanitized;
    }
}
