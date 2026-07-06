<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\Money;
use Stride\Modules\Edition\EditionRepository;
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
        $edition = ntdst_get(EditionRepository::class)->find($editionId);
        if (is_wp_error($edition)) {
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
                $discount = $voucherService->calculateDiscount($voucher, $price, $editionId);
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
            // Auto-apply the profile-type voucher (M3). Resolved server-side from
            // the ATTENDEE's STORED profile type ($userId, never the enrolling
            // $quoteUserId and never client/request input); applied via
            // applyVoucher which validates + REDEEMS (moves used_count) — closing
            // the createQuote-doesn't-redeem gap. Skip when a manual voucher was
            // already supplied on this quote so the auto path never stacks a
            // second redemption (manual takes precedence).
            if (empty($voucherCode)) {
                $policy = ntdst_get(\Stride\Modules\User\ProfileTypePolicy::class);
                $autoCode = $policy->autoVoucherCode($userId, $editionId, 'vad_edition');
                if ($autoCode !== null) {
                    // Redeem against the ATTENDEE ($userId), NOT the quote owner
                    // ($quoteUserId = the payer). For a bulk enroll of N colleagues
                    // by one admin the voucher is each attendee's own entitlement:
                    // keying redemption on the payer would collide on redeemVoucher's
                    // per-user cap and silently drop attendees 2..N's discount.
                    $applied = $quotes->applyVoucher($quoteId, $autoCode, redeemAsUserId: $userId);
                    if (is_wp_error($applied)) {
                        // Resolved code invalid/expired/over-cap: enrollment + quote
                        // STAND, just without the discount. Log, do not fail the enroll.
                        ntdst_log('invoicing')->info('Auto-voucher not applied (invalid/exhausted)', [
                            'quote_id' => $quoteId,
                            'code' => $autoCode,
                            'reason' => $applied->get_error_message(),
                        ]);
                    }
                }
            }

            // Link quote back to registration
            $registrationRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
            $registrationRepo->update($registrationId, ['quote_id' => $quoteId]);

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
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);

        return $editionService->getPrice($editionId, $userId);
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
