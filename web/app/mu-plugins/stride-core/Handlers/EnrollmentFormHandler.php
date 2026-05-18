<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\Money;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Questionnaire\QuestionnaireValidator;
use Stride\Modules\Trajectory\TrajectorySelection;
use Stride\Modules\Trajectory\TrajectoryService;
use WP_Error;

/**
 * Handles enrollment form API requests.
 *
 * Thin handler - validates input, delegates to EnrollmentService/TrajectorySelection.
 * Supports both edition and trajectory enrollments via item_type parameter.
 */
final class EnrollmentFormHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Register API action handlers
        add_filter('ntdst/api_data/stride_submit_enrollment', [$this, 'handleSubmitEnrollment'], 10, 2);
        add_filter('ntdst/api_data/stride_validate_voucher', [$this, 'handleValidateVoucher'], 10, 2);
        add_filter('ntdst/api_data/stride_save_session_selection', [$this, 'handleSaveSessionSelection'], 10, 2);
    }

    /**
     * Handle enrollment submission.
     *
     * Routes to edition or trajectory enrollment based on item_type parameter.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleSubmitEnrollment(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn om in te schrijven.', 'stride'));
        }

        $itemType = sanitize_text_field($params['item_type'] ?? 'edition');

        ntdst_log('enrollment')->info('Enrollment form submitted', [
            'user_id' => $userId,
            'item_type' => $itemType,
        ]);

        return match ($itemType) {
            'trajectory' => $this->processTrajectoryEnrollment($params, $userId),
            default => $this->processEditionEnrollment($params, $userId),
        };
    }

    /**
     * Process edition enrollment.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function processEditionEnrollment(array $params, int $userId): array|WP_Error
    {
        $editionId = absint($params['edition_id'] ?? 0);
        $enrollmentType = sanitize_text_field($params['enrollment_type'] ?? 'self');

        ntdst_log('enrollment')->info('Processing edition enrollment', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'enrollment_type' => $enrollmentType,
        ]);

        if (!$editionId) {
            return new WP_Error('invalid_input', __('Geen editie opgegeven.', 'stride'));
        }

        $editions = ntdst_get(EditionService::class);
        if (!$editions->isEnrollmentOpen($editionId)) {
            return new WP_Error('enrollment_closed', __('Inschrijving is niet meer mogelijk voor deze editie.', 'stride'));
        }

        $enrollmentData = $this->sanitizeEnrollmentData($params, $userId, $editionId);

        $validation = $this->validateEnrollmentData($enrollmentData);
        if (is_wp_error($validation)) {
            ntdst_log('enrollment')->warning('Enrollment validation failed', [
                'user_id' => $userId,
                'edition_id' => $editionId,
                'error' => $validation->get_error_message(),
            ]);
            return $validation;
        }

        // Split extra fields into stage-keyed structure and validate each stage
        $stageData = $this->splitExtraFieldsByStage(
            $enrollmentData['extra_fields'] ?? [],
            $editionId,
            'vad_edition'
        );

        $validator = ntdst_get(QuestionnaireValidator::class);

        $personalResult = $validator->validate(
            $stageData['enrollment_personal'] ?? [],
            $editionId,
            'enrollment_personal'
        );
        if (is_wp_error($personalResult)) {
            return $personalResult;
        }

        $billingResult = $validator->validate(
            $stageData['enrollment_billing'] ?? [],
            $editionId,
            'enrollment_billing'
        );
        if (is_wp_error($billingResult)) {
            return $billingResult;
        }

        // Replace flat extra_fields with stage-keyed enrollment_data
        unset($enrollmentData['extra_fields']);
        $enrollmentData['enrollment_data'] = $stageData;

        $enrollment = ntdst_get(EnrollmentService::class);
        $result = $enrollment->processEnrollment($enrollmentData);
        if (is_wp_error($result)) {
            ntdst_log('enrollment')->error('Edition enrollment failed', [
                'user_id' => $userId,
                'edition_id' => $editionId,
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        // Determine response message based on resulting registration status
        $registrationId = $result['registration_id'] ?? null;
        $isPending = false;
        $hasTasks = false;
        if ($registrationId) {
            $reg = $enrollment->getRegistration($registrationId);
            if (!is_wp_error($reg) && $reg->status === 'pending') {
                $isPending = true;
                $hasTasks = !empty($reg->completion_tasks);
            }
        }

        if ($hasTasks) {
            $message = __('Je inschrijving is ontvangen. Er zijn nog een aantal stappen nodig om je inschrijving te voltooien.', 'stride');
            $redirectUrl = home_url('/vormingen/' . get_post_field('post_name', $editionId) . '/voltooien/');
        } elseif ($isPending) {
            $message = __('Je inschrijving is ontvangen en wacht op goedkeuring.', 'stride');
            $redirectUrl = home_url('/mijn-account/?tab=inschrijvingen');
        } else {
            $message = __('Je inschrijving is succesvol verwerkt!', 'stride');
            $redirectUrl = home_url('/mijn-account/?tab=inschrijvingen');
        }

        return [
            'success' => true,
            'message' => $message,
            'registration_id' => $registrationId,
            'quote_id' => $result['quote_id'] ?? null,
            'status' => $isPending ? 'pending' : 'confirmed',
            'redirect_url' => $redirectUrl,
        ];
    }

    /**
     * Process trajectory enrollment.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function processTrajectoryEnrollment(array $params, int $userId): array|WP_Error
    {
        $trajectoryId = absint($params['trajectory_id'] ?? 0);

        ntdst_log('enrollment')->info('Processing trajectory enrollment', [
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
        ]);

        if (!$trajectoryId) {
            return new WP_Error('invalid_input', __('Geen traject opgegeven.', 'stride'));
        }

        // Check enrollment is open
        $trajectoryService = ntdst_get(TrajectoryService::class);
        if (!$trajectoryService->isEnrollmentOpen($trajectoryId)) {
            return new WP_Error('enrollment_closed', __('Inschrijving is niet meer mogelijk voor dit traject.', 'stride'));
        }

        // Check user not already enrolled
        if ($trajectoryService->isUserEnrolled($userId, $trajectoryId)) {
            return new WP_Error('already_enrolled', __('Je bent al ingeschreven voor dit traject.', 'stride'));
        }

        // Sanitize billing data
        $billingData = $this->sanitizeTrajectoryBillingData($params);

        // Validate required fields
        $validation = $this->validateTrajectoryEnrollmentData($billingData, $params);
        if (is_wp_error($validation)) {
            ntdst_log('enrollment')->warning('Trajectory enrollment validation failed', [
                'user_id' => $userId,
                'trajectory_id' => $trajectoryId,
                'error' => $validation->get_error_message(),
            ]);
            return $validation;
        }

        // Split extra fields into stage-keyed structure and validate each stage
        $stageData = $this->splitExtraFieldsByStage(
            $billingData['extra_fields'] ?? [],
            $trajectoryId,
            'vad_trajectory'
        );

        $validator = ntdst_get(QuestionnaireValidator::class);

        $personalResult = $validator->validate(
            $stageData['enrollment_personal'] ?? [],
            $trajectoryId,
            'enrollment_personal',
            'vad_trajectory'
        );
        if (is_wp_error($personalResult)) {
            return $personalResult;
        }

        $billingResult = $validator->validate(
            $stageData['enrollment_billing'] ?? [],
            $trajectoryId,
            'enrollment_billing',
            'vad_trajectory'
        );
        if (is_wp_error($billingResult)) {
            return $billingResult;
        }

        // Replace flat extra_fields with stage-keyed enrollment_data
        unset($billingData['extra_fields']);
        $billingData['enrollment_data'] = $stageData;

        // Create enrollment via TrajectorySelection
        $selectionService = ntdst_get(TrajectorySelection::class);
        $enrollmentId = $selectionService->enroll($userId, $trajectoryId);

        if (is_wp_error($enrollmentId)) {
            ntdst_log('enrollment')->error('Trajectory enrollment failed', [
                'user_id' => $userId,
                'trajectory_id' => $trajectoryId,
                'error' => $enrollmentId->get_error_message(),
            ]);
            return $enrollmentId;
        }

        // Update user billing info
        $this->updateUserBillingInfo($userId, $billingData);

        // Create quote via QuoteService
        $quoteId = $this->createTrajectoryQuote(
            $userId,
            $enrollmentId,
            $trajectoryId,
            $billingData,
            $params['voucher_code'] ?? ''
        );

        if (is_wp_error($quoteId)) {
            ntdst_log('enrollment')->error('Trajectory quote creation failed', [
                'user_id' => $userId,
                'trajectory_id' => $trajectoryId,
                'enrollment_id' => $enrollmentId,
                'error' => $quoteId->get_error_message(),
            ]);

            // Roll back the enrollment so a missing quote can never let the
            // user walk past payment. The user can retry; an admin sees the
            // cancellation in the audit trail.
            $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
            $enrollmentService->cancel($enrollmentId);

            return new WP_Error(
                'quote_creation_failed',
                __('De inschrijving kon niet worden afgerond omdat de offerte niet aangemaakt werd. Probeer opnieuw of contacteer ons.', 'stride')
            );
        }

        ntdst_log('enrollment')->info('Trajectory enrollment completed', [
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'enrollment_id' => $enrollmentId,
            'quote_id' => is_wp_error($quoteId) ? null : $quoteId,
        ]);

        // Check if trajectory requires approval
        $requiresApproval = $trajectoryService->requiresApproval($trajectoryId);
        $message = $requiresApproval
            ? __('Je inschrijving is ontvangen en wacht op goedkeuring.', 'stride')
            : __('Je inschrijving voor het traject is succesvol verwerkt!', 'stride');

        return [
            'success' => true,
            'message' => $message,
            'enrollment_id' => $enrollmentId,
            'quote_id' => is_wp_error($quoteId) ? null : $quoteId,
            'status' => $requiresApproval ? 'pending' : 'confirmed',
            'redirect_url' => home_url('/mijn-account/?tab=inschrijvingen'),
        ];
    }

    /**
     * Create quote for trajectory enrollment.
     *
     * @param array<string, string> $billingData
     */
    private function createTrajectoryQuote(
        int $userId,
        int $enrollmentId,
        int $trajectoryId,
        array $billingData,
        string $voucherCode
    ): int|WP_Error {
        $trajectoryService = ntdst_get(TrajectoryService::class);
        $trajectory = $trajectoryService->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return new WP_Error('trajectory_not_found', 'Trajectory not found');
        }

        // Get price (could be member vs non-member in future)
        $price = (float) $trajectory['price'];
        $priceCents = (int) round($price * 100);

        // Build quote items
        $items = [
            [
                'title' => $trajectory['title'],
                'quantity' => 1,
                'unit_price' => Money::cents($priceCents),
            ],
        ];

        // Handle voucher discount
        $discount = null;
        $appliedVoucherCode = null;

        if (!empty($voucherCode)) {
            $voucherService = ntdst_get(VoucherService::class);
            $voucher = $voucherService->validateVoucher($voucherCode, null);

            if (!is_wp_error($voucher)) {
                $subtotal = Money::cents($priceCents);
                $discount = $voucherService->calculateDiscount($voucher, $subtotal);
                $appliedVoucherCode = $voucherCode;

                ntdst_log('enrollment')->info('Voucher applied to trajectory enrollment', [
                    'trajectory_id' => $trajectoryId,
                    'voucher_code' => $voucherCode,
                    'discount_cents' => $discount->inCents(),
                ]);
            }
        }

        // Format billing for quote
        $billing = [
            'company' => $billingData['company'] ?? '',
            'email' => $billingData['invoice_email'] ?? $billingData['email'] ?? '',
            'address' => $billingData['address'] ?? '',
            'postal_code' => $billingData['postal_code'] ?? '',
            'city' => $billingData['city'] ?? '',
            'vat_number' => $billingData['vat_number'] ?? '',
            'gln_number' => $billingData['gln_number'] ?? '',
        ];

        // Use QuoteService to create quote
        // Note: QuoteService.createQuote expects registration_id and edition_id,
        // but for trajectories we pass enrollment_id in place of registration_id
        // and trajectoryId (which is stored as edition_id field for now - can be refactored later)
        $quoteService = ntdst_get(QuoteService::class);

        return $quoteService->createQuote(
            $userId,
            $enrollmentId,      // Using enrollment_id as registration_id
            $trajectoryId,      // Using trajectory_id as edition_id (item reference)
            $items,
            $billing,
            $appliedVoucherCode,
            $discount
        );
    }

    /**
     * Handle voucher validation.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleValidateVoucher(mixed $data, array $params): array|WP_Error
    {
        if (!get_current_user_id()) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $code = sanitize_text_field($params['code'] ?? '');
        $itemType = sanitize_text_field($params['item_type'] ?? 'edition');
        $itemId = absint($params['item_id'] ?? $params['edition_id'] ?? $params['trajectory_id'] ?? 0);

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'));
        }

        $vouchers = ntdst_get(VoucherService::class);

        // For editions, validate against edition_id restriction
        // For trajectories, pass null (no edition restriction)
        $editionIdForValidation = ($itemType === 'edition') ? $itemId : null;

        $validation = $vouchers->validateVoucher($code, $editionIdForValidation);
        if (is_wp_error($validation)) {
            ntdst_log('enrollment')->warning('Voucher validation failed', [
                'item_type' => $itemType,
                'item_id' => $itemId,
                'code' => $code,
            ]);
            return new WP_Error('invalid_voucher', __('Vouchercode ongeldig of verlopen.', 'stride'));
        }

        // Get price based on item type
        $price = $this->getItemPrice($itemType, $itemId);
        if ($price === null) {
            return new WP_Error('invalid_item', __('Item niet gevonden.', 'stride'));
        }

        $discount = $vouchers->calculateDiscount($validation, $price, $editionIdForValidation);

        ntdst_log('enrollment')->info('Voucher validated', [
            'item_type' => $itemType,
            'item_id' => $itemId,
            'code' => $code,
            'discount_cents' => $discount->inCents(),
        ]);

        return [
            'valid' => true,
            'discount' => $discount->inCents() / 100,
            'discount_formatted' => '€ ' . number_format($discount->inCents() / 100, 2, ',', '.'),
            'message' => sprintf(__('Korting toegepast: -€ %s', 'stride'), number_format($discount->inCents() / 100, 2, ',', '.')),
        ];
    }

    /**
     * Get price for an item (edition or trajectory).
     */
    private function getItemPrice(string $itemType, int $itemId): ?Money
    {
        if ($itemType === 'trajectory') {
            $trajectoryService = ntdst_get(TrajectoryService::class);
            $trajectory = $trajectoryService->getTrajectory($itemId);
            return $trajectory ? Money::eur((float) $trajectory['price']) : null;
        }

        // Default: edition — pass current user for member pricing
        $editions = ntdst_get(EditionService::class);
        $userId = get_current_user_id() ?: null;

        return $editions->getPrice($itemId, $userId);
    }

    /**
     * Handle session selection save.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleSaveSessionSelection(mixed $data, array $params): array|WP_Error
    {
        $registrationId = absint($params['registration_id'] ?? 0);
        $sessionsJson = $params['sessions'] ?? '[]';
        $sessionIds = json_decode($sessionsJson, true) ?: [];

        if (!$registrationId) {
            return new WP_Error('invalid_input', __('Geen registratie opgegeven.', 'stride'));
        }

        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $repo = ntdst_get(RegistrationRepository::class);
        $reg = $repo->find($registrationId);
        if (!$reg || (int) $reg->user_id !== $userId) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        $sessionSelection = ntdst_get(SessionSelection::class);
        if (!$sessionSelection) {
            return new WP_Error('service_unavailable', __('Service niet beschikbaar.', 'stride'));
        }

        $result = $sessionSelection->setSelections($registrationId, array_map('intval', $sessionIds));
        if (is_wp_error($result)) {
            ntdst_log('enrollment')->error('Session selection failed', [
                'registration_id' => $registrationId,
                'session_ids' => $sessionIds,
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        ntdst_log('enrollment')->info('Session selection saved', [
            'registration_id' => $registrationId,
            'session_ids' => $sessionIds,
        ]);

        return [
            'success' => true,
            'message' => __('Je sessiekeuze is opgeslagen.', 'stride'),
            'reload' => true,
        ];
    }

    /**
     * Sanitize common billing fields.
     *
     * @return array<string, string>
     */
    private function sanitizeBillingFields(array $params): array
    {
        return [
            'first_name' => sanitize_text_field($params['first_name'] ?? ''),
            'last_name' => sanitize_text_field($params['last_name'] ?? ''),
            'email' => sanitize_email($params['email'] ?? ''),
            'phone' => sanitize_text_field($params['phone'] ?? ''),
            'organisation' => sanitize_text_field($params['organisation'] ?? ''),
            'department' => sanitize_text_field($params['department'] ?? ''),
            'message' => sanitize_textarea_field($params['message'] ?? ''),
            'vat_number' => sanitize_text_field($params['vat_number'] ?? ''),
            'address' => sanitize_text_field($params['address'] ?? ''),
            'postal_code' => sanitize_text_field($params['postal_code'] ?? ''),
            'city' => sanitize_text_field($params['city'] ?? ''),
            'company' => sanitize_text_field($params['company'] ?? ''),
            'invoice_email' => sanitize_email($params['invoice_email'] ?? ''),
            'gln_number' => sanitize_text_field($params['gln_number'] ?? ''),
            'po_number' => sanitize_text_field($params['po_number'] ?? ''),
            'extra_fields' => $this->sanitizeExtraFields($params['extra_fields'] ?? []),
        ];
    }

    /**
     * Sanitize dynamic extra fields from field groups.
     *
     * @return array<string, string|bool>
     */
    private function sanitizeExtraFields(array|string $fields): array
    {
        if (is_string($fields)) {
            if (strlen($fields) > 10000) {
                return [];
            }
            $fields = json_decode($fields, true) ?: [];
        }

        $sanitized = [];
        foreach ($fields as $key => $value) {
            $safeKey = sanitize_key($key);
            $sanitized[$safeKey] = is_bool($value) ? $value : sanitize_text_field((string) $value);
        }

        return $sanitized;
    }

    /**
     * Split flat extra fields into stage-keyed structure.
     *
     * Fields belonging to 'enrollment_billing' are placed under that key;
     * everything else defaults to 'enrollment_personal'.
     *
     * @param array<string, string|bool> $extraFields Sanitized extra field values
     * @param int    $postId   Edition or trajectory post ID
     * @param string $postType Post type — 'vad_edition' or 'vad_trajectory'
     * @return array{enrollment_personal: array<string, mixed>, enrollment_billing: array<string, mixed>}
     */
    private function splitExtraFieldsByStage(array $extraFields, int $postId, string $postType): array
    {
        $questionnaireRepo = ntdst_get(QuestionnaireRepository::class);

        $billingFieldNames = array_column(
            $questionnaireRepo->getFlatFieldsForStage($postId, 'enrollment_billing', $postType),
            'name'
        );

        $stageData = [
            'enrollment_personal' => [],
            'enrollment_billing'  => [],
        ];

        foreach ($extraFields as $key => $value) {
            if (in_array($key, $billingFieldNames, true)) {
                $stageData['enrollment_billing'][$key] = $value;
            } else {
                $stageData['enrollment_personal'][$key] = $value;
            }
        }

        return $stageData;
    }

    /**
     * Sanitize edition enrollment form data.
     *
     * @return array<string, mixed>
     */
    private function sanitizeEnrollmentData(array $params, int $userId, int $editionId): array
    {
        $billing = $this->sanitizeBillingFields($params);

        return array_merge($billing, [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'enrollment_type' => sanitize_text_field($params['enrollment_type'] ?? 'self'),
            'voucher_code' => sanitize_text_field($params['voucher_code'] ?? ''),
            'selected_sessions' => array_map('intval', $params['selected_sessions'] ?? []),
            'terms_accepted' => (bool) ($params['terms_accepted'] ?? false),
        ]);
    }

    /**
     * Sanitize trajectory billing data.
     *
     * @return array<string, string>
     */
    private function sanitizeTrajectoryBillingData(array $params): array
    {
        return $this->sanitizeBillingFields($params);
    }

    /**
     * Validate required billing fields.
     */
    private function validateRequiredBillingFields(array $data, bool $termsAccepted): true|WP_Error
    {
        if (empty($data['first_name']) || empty($data['last_name'])) {
            return new WP_Error('validation_error', __('Voornaam en achternaam zijn vereist.', 'stride'));
        }

        if (empty($data['email'])) {
            return new WP_Error('validation_error', __('E-mailadres is vereist.', 'stride'));
        }

        if (!$termsAccepted) {
            return new WP_Error('validation_error', __('Je moet akkoord gaan met de voorwaarden.', 'stride'));
        }

        return true;
    }

    /**
     * Validate edition enrollment data.
     */
    private function validateEnrollmentData(array $data): true|WP_Error
    {
        return $this->validateRequiredBillingFields($data, $data['terms_accepted'] ?? false);
    }

    /**
     * Validate trajectory enrollment data.
     *
     * @param array<string, string> $billingData
     * @param array<string, mixed> $params Original params for terms check
     */
    private function validateTrajectoryEnrollmentData(array $billingData, array $params): true|WP_Error
    {
        return $this->validateRequiredBillingFields($billingData, (bool) ($params['terms_accepted'] ?? false));
    }

    /**
     * Update user billing info from enrollment form.
     *
     * Delegates to EnrollmentService to keep meta key mappings consistent.
     *
     * @param array<string, string> $billingData
     */
    private function updateUserBillingInfo(int $userId, array $billingData): void
    {
        $enrollment = ntdst_get(EnrollmentService::class);
        $enrollment->updateUserProfile($userId, $billingData);
    }
}
