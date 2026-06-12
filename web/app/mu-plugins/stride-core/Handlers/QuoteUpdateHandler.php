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
        add_filter('ntdst/api_data/stride_update_quote', [$this, 'handleUpdateQuote'], 10, 2);
        add_filter('ntdst/api_data/stride_apply_quote_voucher', [$this, 'handleApplyVoucher'], 10, 2);
        add_filter('ntdst/api_data/stride_cancel_quote', [$this, 'handleCancelQuote'], 10, 2);
    }

    /**
     * Handle quote update.
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
            ntdst_log('invoicing')->warning('Quote update rejected: access denied', [
                'quote_id' => $quoteId,
                'user_id' => $userId,
                'error' => $validation->get_error_message(),
            ]);
            return $validation;
        }

        // Accept billing as nested object OR flat fields (inlineEditSection sends flat)
        $billing = $this->sanitizeBilling($params['billing'] ?? $params);
        if (!empty($billing)) {
            $quoteRepo = ntdst_get(QuoteRepository::class);
            $quoteRepo->updateMeta($quoteId, ['billing' => $billing]);
        }

        ntdst_log('invoicing')->info('Quote billing updated', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
        ]);

        return [
            'success' => true,
            'message' => __('Offerte bijgewerkt.', 'stride'),
            'redirect_url' => home_url('/mijn-account/?tab=offertes'),
        ];
    }

    /**
     * Handle voucher application.
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
            ntdst_log('invoicing')->warning('Voucher application rejected', [
                'quote_id' => $quoteId,
                'user_id' => $userId,
                'error' => $validation->get_error_message(),
            ]);
            return $validation;
        }

        $quotes = ntdst_get(QuoteService::class);
        $result = $quotes->applyVoucher($quoteId, $voucherCode);
        if (is_wp_error($result)) {
            return $result;
        }

        ntdst_log('invoicing')->info('Voucher applied via handler', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'voucher_code' => $voucherCode,
        ]);

        return [
            'success' => true,
            'message' => __('Voucher toegepast!', 'stride'),
        ];
    }

    /**
     * Handle quote cancellation.
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

        ntdst_log('invoicing')->info('Quote cancellation requested', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
        ]);

        $validation = $this->validateQuoteOwnership($quoteId, $userId);
        if (is_wp_error($validation)) {
            ntdst_log('invoicing')->warning('Quote cancellation rejected', [
                'quote_id' => $quoteId,
                'user_id' => $userId,
                'error' => $validation->get_error_message(),
            ]);
            return $validation;
        }

        $quoteService = ntdst_get(QuoteService::class);
        $result = $quoteService->cancel($quoteId);

        if (is_wp_error($result)) {
            ntdst_log('invoicing')->error('Quote cancellation failed', [
                'quote_id' => $quoteId,
                'user_id' => $userId,
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Inschrijving geannuleerd.', 'stride'),
            'redirect_url' => home_url('/mijn-account/?tab=offertes'),
        ];
    }

    /**
     * Validate user has access to quote and quote is editable.
     *
     * A quote is editable when:
     *   - user owns it
     *   - status is still 'draft' (not sent / exported / cancelled)
     *   - `locked` flag is false (admin hasn't locked it via the edition bulk action)
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

        if (!empty($quote['locked'])) {
            return new WP_Error(
                'locked',
                __('Deze offerte is vergrendeld door de beheerder en kan niet meer worden bijgewerkt.', 'stride'),
            );
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

        $allowed = ['company', 'email', 'address', 'postal_code', 'city', 'vat_number', 'gln_number'];
        $sanitized = [];

        foreach ($allowed as $field) {
            if (isset($billing[$field])) {
                $sanitized[$field] = $field === 'email'
                    ? sanitize_email($billing[$field])
                    : sanitize_text_field($billing[$field]);
            }
        }

        return $sanitized;
    }
}
