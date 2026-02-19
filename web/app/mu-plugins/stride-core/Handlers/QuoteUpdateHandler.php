<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteService;
use WP_Error;

/**
 * Handles quote update API requests.
 *
 * Thin handler - validates input, delegates to QuoteService.
 */
final class QuoteUpdateHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Register API handlers using NTDST filter pattern
        add_filter('ntdst/api_data/stride_update_quote', [$this, 'handleUpdateQuote'], 10, 2);
        add_filter('ntdst/api_data/stride_apply_quote_voucher', [$this, 'handleApplyVoucher'], 10, 2);
        add_filter('ntdst/api_data/stride_cancel_quote', [$this, 'handleCancelQuote'], 10, 2);

        // Fallback: Also register as wp_ajax for compatibility
        add_action('wp_ajax_stride_update_quote', [$this, 'ajaxUpdateQuote']);
        add_action('wp_ajax_stride_apply_quote_voucher', [$this, 'ajaxApplyVoucher']);
        add_action('wp_ajax_stride_cancel_quote', [$this, 'ajaxCancelQuote']);
    }

    /**
     * Handle quote update via NTDST API.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdateQuote(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $quoteId = absint($params['quote_id'] ?? 0);
        if (!$quoteId) {
            return new WP_Error('invalid_input', __('Geen offerte opgegeven.', 'stride'));
        }

        $validation = $this->validateQuoteAccess($quoteId, $userId);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $billing = $this->sanitizeBilling($params['billing'] ?? []);
        if (!empty($billing)) {
            $quoteRepo = ntdst_get(QuoteRepository::class);
            $quoteRepo->updateMeta($quoteId, ['billing' => $billing]);
        }

        return [
            'success' => true,
            'message' => __('Offerte bijgewerkt.', 'stride'),
            'redirect_url' => home_url('/mijn-account/mijn-offertes/'),
        ];
    }

    /**
     * Handle voucher application via NTDST API.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleApplyVoucher(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $quoteId = absint($params['quote_id'] ?? 0);
        $voucherCode = sanitize_text_field($params['voucher_code'] ?? '');

        if (!$quoteId || !$voucherCode) {
            return new WP_Error('invalid_input', __('Ongeldige invoer.', 'stride'));
        }

        $validation = $this->validateQuoteAccess($quoteId, $userId);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $quotes = ntdst_get(QuoteService::class);
        $result = $quotes->applyVoucher($quoteId, $voucherCode);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Voucher toegepast!', 'stride'),
        ];
    }

    /**
     * Handle quote cancellation via NTDST API.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleCancelQuote(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $quoteId = absint($params['quote_id'] ?? 0);
        if (!$quoteId) {
            return new WP_Error('invalid_input', __('Geen offerte opgegeven.', 'stride'));
        }

        $validation = $this->validateQuoteOwnership($quoteId, $userId);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $quoteService = ntdst_get(QuoteService::class);
        $result = $quoteService->cancel($quoteId);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Inschrijving geannuleerd.', 'stride'),
            'redirect_url' => home_url('/mijn-account/mijn-offertes/'),
        ];
    }

    /**
     * Validate user has access to quote and quote is editable.
     */
    private function validateQuoteAccess(int $quoteId, int $userId): true|WP_Error
    {
        $quotes = ntdst_get(QuoteService::class);
        $quote = $quotes->getQuote($quoteId);
        if (is_wp_error($quote)) {
            return new WP_Error('not_found', __('Offerte niet gevonden.', 'stride'));
        }

        if ((int) ($quote['user_id'] ?? 0) !== $userId) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        $status = $quote['status'] ?? '';
        if ($status !== 'draft') {
            return new WP_Error('not_editable', __('Deze offerte kan niet meer worden bijgewerkt.', 'stride'));
        }

        return true;
    }

    /**
     * Validate user owns the quote (without editability check).
     *
     * Used for cancellation, where draft status is not required.
     */
    private function validateQuoteOwnership(int $quoteId, int $userId): true|WP_Error
    {
        $quotes = ntdst_get(QuoteService::class);
        $quote = $quotes->getQuote($quoteId);
        if (is_wp_error($quote)) {
            return new WP_Error('not_found', __('Offerte niet gevonden.', 'stride'));
        }

        if ((int) ($quote['user_id'] ?? 0) !== $userId) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        return true;
    }

    /**
     * Sanitize billing data from request.
     *
     * @param array<string, mixed>|mixed $billing Raw billing data
     * @return array<string, string>
     */
    private function sanitizeBilling(mixed $billing): array
    {
        if (!is_array($billing)) {
            return [];
        }

        $allowed = ['organisation', 'email', 'address', 'postal_code', 'city', 'vat_number', 'gln_number'];
        $sanitized = [];

        foreach ($allowed as $field) {
            if (isset($billing[$field])) {
                $sanitized[$field] = sanitize_text_field($billing[$field]);
            }
        }

        return $sanitized;
    }

    /**
     * AJAX fallback: Update quote.
     */
    public function ajaxUpdateQuote(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_quote_update')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleUpdateQuote(null, $_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX fallback: Apply voucher.
     */
    public function ajaxApplyVoucher(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_quote_update')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleApplyVoucher(null, $_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX fallback: Cancel quote.
     */
    public function ajaxCancelQuote(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_quote_update')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleCancelQuote(null, $_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }
}
