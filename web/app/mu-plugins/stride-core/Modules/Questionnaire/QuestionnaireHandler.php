<?php

declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

use Stride\Domain\RegistrationStatus;
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
     * one lead row per e-mail per edition, stage appended on repeat.
     *
     * @param  string $stage 'interest'|'waitlist' (also the target status).
     * @return array{0:int,1:int}|WP_Error  [registrationId, boundUserId (0 = lead)]
     */
    private function upsertStageSubmission(int $editionId, string $stage, string $email, array $wrapped): array|WP_Error
    {
        $registrations = ntdst_get(RegistrationRepository::class);
        $status = $stage === 'waitlist' ? RegistrationStatus::Waitlist : RegistrationStatus::Interest;

        $boundUserId = $this->resolveSelfBoundUser($email);
        $row = null;

        if ($boundUserId) {
            $row = $registrations->findByUserAndEdition($boundUserId, $editionId);

            if (!$row) {
                $lead = $registrations->findAnonymousForEmailAndEdition($email, $editionId);
                if ($lead && $registrations->bindLeadToUser((int) $lead->id, $boundUserId)) {
                    $row = $registrations->find((int) $lead->id);
                }
            }

            if ($row) {
                $rowStatus = RegistrationStatus::tryFrom((string) $row->status);
                $appendable = [RegistrationStatus::Interest, RegistrationStatus::Waitlist, RegistrationStatus::Cancelled];
                if (!in_array($rowStatus, $appendable, true)) {
                    return new WP_Error(
                        'already_registered',
                        __('Je bent al ingeschreven voor deze editie.', 'stride'),
                    );
                }
            }
        } else {
            $row = $registrations->findAnonymousForEmailAndEdition($email, $editionId);
        }

        if ($row) {
            $existingData = is_array($row->enrollment_data ?? null) ? $row->enrollment_data : [];
            $existingData[$stage] = $wrapped;
            $updated = $registrations->update((int) $row->id, [
                'status' => $status->value,
                'enrollment_data' => $existingData,
            ]);
            if (!$updated) {
                ntdst_log('enrollment')->error('Stage submission update failed', [
                    'registration_id' => (int) $row->id,
                    'edition_id' => $editionId,
                    'stage' => $stage,
                ]);
                return new WP_Error('update_failed', __('Je aanmelding kon niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
            }

            return [(int) $row->id, $boundUserId];
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
        if ($boundUserId) {
            $companyId = CompanyAffiliation::getCompanyId($boundUserId);
            if ($companyId) {
                $payload['company_id'] = $companyId;
            }
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
