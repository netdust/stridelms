<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\Money;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;

/**
 * Creates quotes when users enroll in editions.
 *
 * Thin handler - listens for stride/registration/created event
 * and delegates to QuoteService.
 */
final class EnrollmentQuoteHandler
{
    public function __construct()
    {
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/confirmed', [$this, 'onRegistrationConfirmed']);
    }

    /**
     * Handle registration created event.
     *
     * @param array{registration_id: int, user_id: int, edition_id: int, enrolled_by?: int|null} $data
     */
    public function onRegistrationCreated(array $data): void
    {
        $registrationId = $data['registration_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;
        $editionId = $data['edition_id'] ?? 0;
        $enrolledBy = $data['enrolled_by'] ?? null;
        $status = $data['status'] ?? 'confirmed';

        if (!$registrationId || !$userId || !$editionId) {
            return;
        }

        // Skip quote for interest registrations
        if ($status === 'interest') {
            ntdst_log('invoicing')->info('Skipping quote: interest registration', [
                'registration_id' => $registrationId,
            ]);
            return;
        }

        // Skip quote for pending registrations with completion tasks
        // Quote will be created when registration is confirmed
        if ($status === 'pending') {
            ntdst_log('invoicing')->info('Deferring quote: pending registration with completion tasks', [
                'registration_id' => $registrationId,
            ]);
            return;
        }

        // For colleague enrollments, quote goes to the enrolling user (the one who pays)
        $quoteUserId = $enrolledBy ?: $userId;

        $quotes = ntdst_get(QuoteService::class);

        // Check if quote already exists
        $existing = $quotes->getQuoteByRegistration($registrationId);
        if ($existing) {
            ntdst_log('invoicing')->warning('Quote already exists for registration', [
                'registration_id' => $registrationId,
                'quote_id' => $existing['id'] ?? $existing['ID'] ?? null,
            ]);
            return;
        }

        // Get edition details
        $edition = get_post($editionId);
        if (!$edition) {
            ntdst_log('invoicing')->warning('Skipping quote: edition not found', [
                'registration_id' => $registrationId,
                'edition_id' => $editionId,
            ]);
            return;
        }

        // Get price (check if member - simplified for now)
        // Price is based on the attendee's membership status, not the enrolling user
        $price = $this->getEditionPrice($editionId, $userId);

        // Skip free editions
        if ($price->isZero()) {
            ntdst_log('invoicing')->info('Skipping quote: free edition', [
                'registration_id' => $registrationId,
                'edition_id' => $editionId,
                'user_id' => $userId,
            ]);
            return;
        }

        // Build items array
        $items = [
            [
                'title' => $edition->post_title,
                'quantity' => 1,
                'unit_price' => $price,
                'type' => 'edition',
            ],
        ];

        // Check for pending billing from enrollment form
        $pendingBilling = $this->getPendingBilling($quoteUserId, $editionId);
        $billing = $pendingBilling ?: $this->getUserBilling($quoteUserId);

        // Get voucher code and calculate discount if provided
        $voucherCode = $pendingBilling['voucher_code'] ?? null;
        $discount = null;

        if ($voucherCode) {
            $voucherService = ntdst_get(VoucherService::class);
            $voucher = $voucherService->validateVoucher($voucherCode, $editionId);
            if (!is_wp_error($voucher)) {
                $discount = $voucherService->calculateDiscount($voucher, $price);
            }
        }

        // Create quote for the billing user
        $quoteId = $quotes->createQuote(
            userId: $quoteUserId,
            registrationId: $registrationId,
            editionId: $editionId,
            items: $items,
            billing: $billing,
            voucherCode: $voucherCode,
            discount: $discount,
        );

        if (!is_wp_error($quoteId)) {
            // Clear billing transient only after successful quote creation
            $this->clearPendingBilling($quoteUserId, $editionId);

            ntdst_log('invoicing')->info('Quote created for registration', [
                'registration_id' => $registrationId,
                'quote_id' => $quoteId,
                'user_id' => $quoteUserId,
                'edition_id' => $editionId,
                'amount' => $price->inCents(),
            ]);
        }
    }

    /**
     * Create quote when a pending registration is confirmed.
     */
    public function onRegistrationConfirmed(array $data): void
    {
        $registrationId = $data['registration_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;
        $editionId = $data['edition_id'] ?? 0;

        if (!$registrationId || !$userId || !$editionId) {
            return;
        }

        // Delegate to the same creation logic
        $this->onRegistrationCreated([
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
        ]);
    }

    /**
     * Get pending billing data from enrollment form (non-destructive read).
     *
     * @return array<string, mixed>|null
     */
    private function getPendingBilling(int $userId, int $editionId): ?array
    {
        $key = 'stride_pending_billing_' . $userId . '_' . $editionId;
        $billing = get_transient($key);
        return $billing ?: null;
    }

    /**
     * Clear pending billing transient after successful quote creation.
     */
    private function clearPendingBilling(int $userId, int $editionId): void
    {
        delete_transient('stride_pending_billing_' . $userId . '_' . $editionId);
    }

    /**
     * Get edition price for user.
     */
    private function getEditionPrice(int $editionId, int $userId): Money
    {
        // Check if user is member (simplified - check user meta)
        $isMember = (bool) get_user_meta($userId, 'is_member', true);

        // Use EditionService for proper meta access with prefix handling
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);

        return $editionService->getPrice($editionId, $isMember);
    }

    /**
     * Get user billing information.
     *
     * @return array<string, string>
     */
    private function getUserBilling(int $userId): array
    {
        $user = get_userdata($userId);

        if (!$user) {
            return [];
        }

        return [
            'name' => $user->display_name,
            'email' => get_user_meta($userId, 'invoice_email', true) ?: '',
            'company' => get_user_meta($userId, 'billing_company', true) ?: '',
            'address' => get_user_meta($userId, 'billing_address_1', true) ?: '',
            'postal_code' => get_user_meta($userId, 'billing_postcode', true) ?: '',
            'city' => get_user_meta($userId, 'billing_city', true) ?: '',
            'vat_number' => get_user_meta($userId, 'billing_vat', true) ?: '',
            'gln_number' => get_user_meta($userId, 'gln_number', true) ?: '',
        ];
    }
}
