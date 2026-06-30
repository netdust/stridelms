<?php

declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;
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

        // Check for existing anonymous row (any pre-enrollment stage). One row per
        // email/edition: edition status determines current stage, we just append data.
        $registrations = ntdst_get(RegistrationRepository::class);
        $existing = $registrations->findAnonymousForEmailAndEdition($email, $editionId);

        $stageData = array_merge(['name' => $name, 'email' => $email], $extraFields);
        $wrapped = RegistrationRepository::wrapStage($stageData, get_current_user_id() ?: null);

        $registrationId = $existing ? (int) $existing->id : 0;

        if ($existing) {
            $existingData = is_array($existing->enrollment_data ?? null) ? $existing->enrollment_data : [];
            $existingData['interest'] = $wrapped;
            $updated = $registrations->update((int) $existing->id, [
                'status' => RegistrationStatus::Interest->value,
                'enrollment_data' => $existingData,
            ]);
            if (!$updated) {
                ntdst_log('enrollment')->error('Interest registration update failed', [
                    'registration_id' => (int) $existing->id,
                    'edition_id' => $editionId,
                ]);
                return new WP_Error('update_failed', __('Je interesse kon niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
            }
        } else {
            // Create new interest registration
            $created = $registrations->create([
                'user_id' => null,
                'edition_id' => $editionId,
                'status' => RegistrationStatus::Interest->value,
                'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                'enrollment_data' => ['interest' => $wrapped],
            ]);

            if (is_wp_error($created)) {
                return $created;
            }
            $registrationId = (int) $created;
        }

        // Same semantic event the logged-in EnrollmentService path dispatches —
        // mail (StrideMailBridge), audit (AuditBridge) and any future consumer
        // hang off this one emission point instead of inline ndmail_send calls.
        do_action('stride/registration/interest_registered', [
            'registration_id' => $registrationId,
            'user_id'         => null,
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

        $registrations = ntdst_get(RegistrationRepository::class);
        $existing = $registrations->findAnonymousForEmailAndEdition($email, $editionId);

        $stageData = array_merge(['name' => $name, 'email' => $email], $extraFields);
        $wrapped = RegistrationRepository::wrapStage($stageData, get_current_user_id() ?: null);

        $registrationId = $existing ? (int) $existing->id : 0;

        if ($existing) {
            $existingData = is_array($existing->enrollment_data ?? null) ? $existing->enrollment_data : [];
            $existingData['waitlist'] = $wrapped;
            $updated = $registrations->update((int) $existing->id, [
                'status' => RegistrationStatus::Waitlist->value,
                'enrollment_data' => $existingData,
            ]);
            if (!$updated) {
                ntdst_log('enrollment')->error('Waitlist registration update failed', [
                    'registration_id' => (int) $existing->id,
                    'edition_id' => $editionId,
                ]);
                return new WP_Error('update_failed', __('Je aanvraag kon niet worden opgeslagen. Probeer het later opnieuw.', 'stride'));
            }
        } else {
            $created = $registrations->create([
                'user_id' => null,
                'edition_id' => $editionId,
                'status' => RegistrationStatus::Waitlist->value,
                'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                'enrollment_data' => ['waitlist' => $wrapped],
            ]);

            if (is_wp_error($created)) {
                return $created;
            }
            $registrationId = (int) $created;
        }

        // Same semantic event the logged-in EnrollmentService path dispatches —
        // see handleSubmitInterest for the rationale.
        do_action('stride/registration/waitlisted', [
            'registration_id' => $registrationId,
            'user_id'         => null,
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
