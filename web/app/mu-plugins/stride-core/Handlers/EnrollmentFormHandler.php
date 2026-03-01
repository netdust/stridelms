<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\Money;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
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
        add_filter('ntdst/api_data/stride_register_interest', [$this, 'handleRegisterInterest'], 10, 2);
        add_filter('ntdst/api_data/stride_validate_voucher', [$this, 'handleValidateVoucher'], 10, 2);
        add_filter('ntdst/api_data/stride_save_session_selection', [$this, 'handleSaveSessionSelection'], 10, 2);

        // Register voucher validation as public action (can validate before login)
        add_filter('ntdst/api/public_actions', [$this, 'registerPublicActions']);
    }

    /**
     * Register public API actions that don't require authentication.
     *
     * @param array<string> $actions Existing public actions
     * @return array<string>
     */
    public function registerPublicActions(array $actions): array
    {
        $actions[] = 'stride_validate_voucher';
        return $actions;
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
     * Handle interest registration.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleRegisterInterest(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn om je interesse te melden.', 'stride'));
        }

        $itemType = sanitize_text_field($params['item_type'] ?? 'edition');
        $editionId = absint($params['edition_id'] ?? 0);
        $trajectoryId = absint($params['trajectory_id'] ?? 0);

        // Update user profile with provided info
        $firstName = sanitize_text_field($params['first_name'] ?? '');
        $lastName = sanitize_text_field($params['last_name'] ?? '');
        $phone = sanitize_text_field($params['phone'] ?? '');
        $organisation = sanitize_text_field($params['company'] ?? '');
        $message = sanitize_textarea_field($params['message'] ?? '');

        if (empty($firstName) || empty($lastName)) {
            return new WP_Error('validation_error', __('Voornaam en achternaam zijn vereist.', 'stride'));
        }

        // Update user meta
        wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        if ($phone) {
            update_user_meta($userId, 'phone', $phone);
        }
        if ($organisation) {
            update_user_meta($userId, 'company', $organisation);
        }

        // Register interest
        $enrollment = ntdst_get(EnrollmentService::class);
        $result = $enrollment->registerInterest($userId, [
            'edition_id' => $editionId ?: null,
            'trajectory_id' => $trajectoryId ?: null,
            'notes' => $message,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Je interesse is geregistreerd. We houden je op de hoogte!', 'stride'),
            'registration_id' => $result,
            'redirect_url' => home_url('/mijn-account/'),
        ];
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
        if ($registrationId) {
            $reg = $enrollment->getRegistration($registrationId);
            if (!is_wp_error($reg) && $reg->status === 'pending') {
                $isPending = true;
            }
        }

        $message = $isPending
            ? __('Je inschrijving is ontvangen en wacht op goedkeuring.', 'stride')
            : __('Je inschrijving is succesvol verwerkt!', 'stride');

        return [
            'success' => true,
            'message' => $message,
            'registration_id' => $registrationId,
            'quote_id' => $result['quote_id'] ?? null,
            'status' => $isPending ? 'pending' : 'confirmed',
            'redirect_url' => home_url('/mijn-account/mijn-cursussen/'),
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
            // Don't fail enrollment if quote creation fails, but log it
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
            'redirect_url' => home_url('/mijn-account/mijn-trajecten/'),
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
            'organisation' => $billingData['company'] ?? '',
            'email' => $billingData['invoice_email'] ?? $billingData['email'] ?? '',
            'address' => $billingData['address'] ?? '',
            'postal_code' => $billingData['postal_code'] ?? '',
            'city' => $billingData['city'] ?? '',
            'vat_number' => $billingData['vat_number'] ?? '',
            'gln_number' => $billingData['gln_peppol'] ?? '',
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
        $code = sanitize_text_field($params['code'] ?? '');
        $itemType = sanitize_text_field($params['item_type'] ?? 'edition');
        $itemId = absint($params['edition_id'] ?? $params['trajectory_id'] ?? 0);

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

        $subtotal = Money::cents((int) round($price * 100));
        $discount = $vouchers->calculateDiscount($validation, $subtotal);

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
            'discount_type' => $validation['discount_type'],
            'message' => sprintf(__('Korting toegepast: -€ %s', 'stride'), number_format($discount->inCents() / 100, 2, ',', '.')),
        ];
    }

    /**
     * Get price for an item (edition or trajectory).
     */
    private function getItemPrice(string $itemType, int $itemId): ?float
    {
        if ($itemType === 'trajectory') {
            $trajectoryService = ntdst_get(TrajectoryService::class);
            $trajectory = $trajectoryService->getTrajectory($itemId);
            return $trajectory ? (float) $trajectory['price'] : null;
        }

        // Default: edition
        $editions = ntdst_get(EditionService::class);
        return $editions->getPrice($itemId);
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

        $sessionSelection = ntdst_get(SessionSelection::class);
        if (!$sessionSelection) {
            return new WP_Error('service_unavailable', __('Service niet beschikbaar.', 'stride'));
        }

        $result = $sessionSelection->selectSessions($registrationId, array_map('intval', $sessionIds));
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
            'company' => sanitize_text_field($params['company'] ?? ''),
            'vat_number' => sanitize_text_field($params['vat_number'] ?? ''),
            'address' => sanitize_text_field($params['address'] ?? ''),
            'postal_code' => sanitize_text_field($params['postal_code'] ?? ''),
            'city' => sanitize_text_field($params['city'] ?? ''),
            'gln_peppol' => sanitize_text_field($params['gln_peppol'] ?? ''),
            'invoice_email' => sanitize_email($params['invoice_email'] ?? ''),
            'po_number' => sanitize_text_field($params['po_number'] ?? ''),
        ];
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
     * @param array<string, string> $billingData
     */
    private function updateUserBillingInfo(int $userId, array $billingData): void
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
            if (!empty($billingData[$inputKey])) {
                update_user_meta($userId, $metaKey, $billingData[$inputKey]);
            }
        }

        // Update core user fields if provided
        if (!empty($billingData['first_name']) || !empty($billingData['last_name'])) {
            wp_update_user([
                'ID' => $userId,
                'first_name' => $billingData['first_name'] ?? '',
                'last_name' => $billingData['last_name'] ?? '',
            ]);
        }
    }
}
