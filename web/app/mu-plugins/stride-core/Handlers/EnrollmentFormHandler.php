<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelectionService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Invoicing\VoucherService;
use WP_Error;

/**
 * Handles enrollment form API requests.
 *
 * Thin handler - validates input, delegates to EnrollmentService.
 */
final class EnrollmentFormHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // AJAX handlers
        add_action('wp_ajax_stride_submit_enrollment', [$this, 'ajaxSubmitEnrollment']);
        add_action('wp_ajax_stride_validate_voucher', [$this, 'ajaxValidateVoucher']);
        add_action('wp_ajax_stride_save_session_selection', [$this, 'ajaxSaveSessionSelection']);
    }

    /**
     * AJAX: Submit enrollment form.
     */
    public function ajaxSubmitEnrollment(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_enrollment')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleSubmitEnrollment($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Handle enrollment submission.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleSubmitEnrollment(array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn om in te schrijven.', 'stride'));
        }

        $editionId = absint($params['edition_id'] ?? 0);
        $enrollmentType = sanitize_text_field($params['enrollment_type'] ?? 'self');

        ntdst_log('enrollment')->info('Enrollment form submitted', [
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
            ntdst_log('enrollment')->error('Enrollment submission failed', [
                'user_id' => $userId,
                'edition_id' => $editionId,
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Je inschrijving is succesvol verwerkt!', 'stride'),
            'registration_id' => $result['registration_id'] ?? null,
            'quote_id' => $result['quote_id'] ?? null,
            'redirect_url' => home_url('/mijn-account/mijn-cursussen/'),
        ];
    }

    /**
     * AJAX: Validate voucher code.
     */
    public function ajaxValidateVoucher(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_enrollment')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleValidateVoucher($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Handle voucher validation.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleValidateVoucher(array $params): array|WP_Error
    {
        $code = sanitize_text_field($params['code'] ?? '');
        $editionId = absint($params['edition_id'] ?? 0);

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'));
        }

        $vouchers = ntdst_get(VoucherService::class);
        $editions = ntdst_get(EditionService::class);

        $validation = $vouchers->validateVoucher($code, $editionId, 0, 'edition');
        if (is_wp_error($validation)) {
            ntdst_log('enrollment')->warning('Voucher validation failed', [
                'edition_id' => $editionId,
                'code' => $code,
            ]);
            return new WP_Error('invalid_voucher', __('Vouchercode ongeldig of verlopen.', 'stride'));
        }

        $price = $editions->getPrice($editionId);
        $discount = $vouchers->calculateDiscount($validation, 'edition', $editionId, $price);

        ntdst_log('enrollment')->info('Voucher validated', [
            'edition_id' => $editionId,
            'code' => $code,
            'discount' => $discount,
        ]);

        return [
            'valid' => true,
            'discount' => $discount,
            'discount_formatted' => '€ ' . number_format($discount, 2, ',', '.'),
            'discount_type' => $validation['discount_type'],
            'message' => sprintf(__('Korting toegepast: -€ %s', 'stride'), number_format($discount, 2, ',', '.')),
        ];
    }

    /**
     * AJAX: Save session selection.
     */
    public function ajaxSaveSessionSelection(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_session_selection')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleSaveSessionSelection($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Handle session selection save.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleSaveSessionSelection(array $params): array|WP_Error
    {
        $registrationId = absint($params['registration_id'] ?? 0);
        $sessionsJson = $params['sessions'] ?? '[]';
        $sessionIds = json_decode($sessionsJson, true) ?: [];

        if (!$registrationId) {
            return new WP_Error('invalid_input', __('Geen registratie opgegeven.', 'stride'));
        }

        $sessionSelection = ntdst_get(SessionSelectionService::class);
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
     * Sanitize enrollment form data.
     *
     * @return array<string, mixed>
     */
    private function sanitizeEnrollmentData(array $params, int $userId, int $editionId): array
    {
        return [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'enrollment_type' => sanitize_text_field($params['enrollment_type'] ?? 'self'),
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
            'voucher_code' => sanitize_text_field($params['voucher_code'] ?? ''),
            'selected_sessions' => array_map('intval', $params['selected_sessions'] ?? []),
            'terms_accepted' => (bool) ($params['terms_accepted'] ?? false),
        ];
    }

    /**
     * Validate enrollment data.
     */
    private function validateEnrollmentData(array $data): true|WP_Error
    {
        if (empty($data['first_name']) || empty($data['last_name'])) {
            return new WP_Error('validation_error', __('Voornaam en achternaam zijn vereist.', 'stride'));
        }

        if (empty($data['email'])) {
            return new WP_Error('validation_error', __('E-mailadres is vereist.', 'stride'));
        }

        if (!$data['terms_accepted']) {
            return new WP_Error('validation_error', __('Je moet akkoord gaan met de voorwaarden.', 'stride'));
        }

        return true;
    }
}
