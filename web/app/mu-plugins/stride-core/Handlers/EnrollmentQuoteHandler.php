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

        if (!$registrationId || !$userId || !$editionId) {
            return;
        }

        // For colleague enrollments, quote goes to the enrolling user (the one who pays)
        $quoteUserId = $enrolledBy ?: $userId;

        $quotes = ntdst_get(QuoteService::class);

        // Check if quote already exists
        $existing = $quotes->getQuoteByRegistration($registrationId);
        if ($existing) {
            return;
        }

        // Get edition details
        $edition = get_post($editionId);
        if (!$edition) {
            return;
        }

        // Get price (check if member - simplified for now)
        // Price is based on the attendee's membership status, not the enrolling user
        $price = $this->getEditionPrice($editionId, $userId);

        // Skip free editions
        if ($price->isZero()) {
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
        $pendingBilling = $this->consumePendingBilling($quoteUserId, $editionId);
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
        $quotes->createQuote(
            userId: $quoteUserId,
            registrationId: $registrationId,
            editionId: $editionId,
            items: $items,
            billing: $billing,
            voucherCode: $voucherCode,
            discount: $discount,
        );
    }

    /**
     * Consume pending billing data from enrollment form.
     *
     * @return array<string, mixed>|null
     */
    private function consumePendingBilling(int $userId, int $editionId): ?array
    {
        $key = 'stride_pending_billing_' . $userId . '_' . $editionId;
        $billing = get_transient($key);
        delete_transient($key);
        return $billing ?: null;
    }

    /**
     * Get edition price for user.
     */
    private function getEditionPrice(int $editionId, int $userId): Money
    {
        // Check if user is member (simplified - check user meta)
        $isMember = (bool) get_user_meta($userId, 'is_member', true);

        $field = $isMember ? 'price' : 'price_non_member';
        $amount = (float) get_post_meta($editionId, $field, true);

        // Fall back to member price if non-member price not set
        if (!$isMember && $amount === 0.0) {
            $amount = (float) get_post_meta($editionId, 'price', true);
        }

        return Money::eur($amount);
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
            'email' => $user->user_email,
            'company' => get_user_meta($userId, 'company', true) ?: '',
            'address' => get_user_meta($userId, 'billing_address', true) ?: '',
            'vat_number' => get_user_meta($userId, 'vat_number', true) ?: '',
        ];
    }
}
