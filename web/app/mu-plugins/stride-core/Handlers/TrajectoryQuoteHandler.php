<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\Money;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\User\ProfileTypePolicy;

/**
 * Creates quotes when users enroll in trajectories (the event-driven path).
 *
 * Thin handler — listens for the dedicated `stride/trajectory/registration/created`
 * event (dispatched from TrajectorySelection::enroll()) and builds the trajectory
 * quote + attendee-keyed auto-voucher. Mirrors EnrollmentQuoteHandler (the edition
 * path) with trajectory-specific safety fixes from the adversarial review
 * (plan §4 A1-A5): namespaced pending-billing transient, editionScoped:false on
 * applyVoucher, and a body that can NEVER throw out of do_action (A3).
 */
final class TrajectoryQuoteHandler
{
    public function __construct()
    {
        add_action('stride/trajectory/registration/created', [$this, 'onTrajectoryRegistrationCreated']);
    }

    /**
     * Build the trajectory quote (+ auto-voucher) for a trajectory registration.
     *
     * The ENTIRE body is wrapped in try/catch(\Throwable): the event fires inside
     * the non-transactional TrajectorySelection::enroll(), and WordPress do_action
     * does NOT catch exceptions. A throwing handler would orphan the committed
     * registration + cascade rows and 500 the caller (A3). Nothing may escape.
     *
     * @param array{registration_id: int, user_id: int, trajectory_id: int, enrolled_by?: int|null} $data
     */
    public function onTrajectoryRegistrationCreated(array $data): void
    {
        try {
            $registrationId = (int) ($data['registration_id'] ?? 0);
            $userId = (int) ($data['user_id'] ?? 0);
            $trajectoryId = (int) ($data['trajectory_id'] ?? 0);

            if (!$registrationId || !$userId || !$trajectoryId) {
                return;
            }

            // Idempotency (A1): the event may fire more than once for a single
            // registration (retry, double-dispatch). If a quote already exists
            // for this registration, early-return — never build a second.
            $quotes = ntdst_get(QuoteService::class);
            if ($quotes->getQuoteByRegistration($registrationId)) {
                ntdst_log('invoicing')->warning('Trajectory quote already exists for registration', [
                    'registration_id' => $registrationId,
                    'trajectory_id' => $trajectoryId,
                ]);
                return;
            }

            // Load the trajectory. A missing trajectory or a zero/free price is a
            // LEGITIMATE skip (not a failure): free trajectories carry no quote.
            $trajectory = ntdst_get(TrajectoryService::class)->getTrajectory($trajectoryId);
            $priceCents = $trajectory ? (int) $trajectory['price'] : 0;

            if (!$trajectory || $priceCents <= 0) {
                ntdst_log('invoicing')->info('Skipping trajectory quote: not found or free', [
                    'registration_id' => $registrationId,
                    'trajectory_id' => $trajectoryId,
                    'price_cents' => $priceCents,
                ]);
                return;
            }

            // Build items. price is a FLOAT from getField (canonical CENTS) — cast
            // (int) for Money::cents; do NOT ×100.
            $items = [
                [
                    'title' => $trajectory['title'],
                    'quantity' => 1,
                    'unit_price' => Money::cents($priceCents),
                ],
            ];

            // Billing: read the NAMESPACED trajectory pending-billing transient
            // (plan §4 finding #3 — `_traj_` key, distinct from the edition shape).
            // Fall back to the attendee's stored billing when absent.
            $pendingBilling = $this->getPendingBilling($userId, $trajectoryId);
            $billing = $pendingBilling ?: $this->getUserBilling($userId);

            // Manual voucher from the pending-billing payload. null scope for a
            // trajectory (its edition_id field holds the trajectoryId, not an edition).
            $voucherCode = $pendingBilling['voucher_code'] ?? null;
            $discount = null;

            if (!empty($voucherCode)) {
                $voucherService = ntdst_get(VoucherService::class);
                $voucher = $voucherService->validateVoucher($voucherCode, null);
                if (!is_wp_error($voucher)) {
                    $discount = $voucherService->calculateDiscount($voucher, Money::cents($priceCents));
                }
            }

            // Create the trajectory quote. trajectoryId travels in the edition_id
            // slot (item reference only). On WP_Error the enrollment STANDS without
            // a quote — log and return; Task-3 rollback detects the missing quote_id.
            $quoteId = $quotes->createQuote(
                userId: $userId,
                registrationId: $registrationId,
                editionId: $trajectoryId,
                items: $items,
                billing: $billing,
                voucherCode: $voucherCode,
                discount: $discount,
            );

            if (is_wp_error($quoteId)) {
                ntdst_log('invoicing')->error('Trajectory quote creation failed', [
                    'registration_id' => $registrationId,
                    'trajectory_id' => $trajectoryId,
                    'error' => $quoteId->get_error_message(),
                ]);
                return;
            }

            // Auto-voucher (A5) — ONLY when no manual voucher was supplied, so the
            // auto path never stacks a second redemption (manual takes precedence).
            // Resolved server-side from the ATTENDEE's ($userId) STORED profile type
            // (never client/request input). applyVoucher validates + REDEEMS (moves
            // used_count), closing the createQuote-doesn't-redeem gap.
            if (empty($voucherCode)) {
                $policy = ntdst_get(ProfileTypePolicy::class);
                $autoCode = $policy->autoVoucherCode($userId, $trajectoryId, 'vad_trajectory');
                if ($autoCode !== null) {
                    // redeemAsUserId: $userId — redeem against the ATTENDEE (money
                    // identity), the entitlement holder. editionScoped: false — the
                    // trajectory quote's edition_id slot holds the trajectoryId, not
                    // a real edition; an edition-scoped voucher must NOT be rejected
                    // by comparing its allowed edition against the trajectoryId.
                    $applied = $quotes->applyVoucher(
                        $quoteId,
                        $autoCode,
                        redeemAsUserId: $userId,
                        editionScoped: false,
                    );
                    if (is_wp_error($applied)) {
                        // Resolved code invalid/expired/over-cap: the enrollment +
                        // quote STAND, just without the discount. Log, do not fail.
                        ntdst_log('invoicing')->info('Auto-voucher not applied to trajectory (invalid/exhausted)', [
                            'quote_id' => $quoteId,
                            'code' => $autoCode,
                            'reason' => $applied->get_error_message(),
                        ]);
                    }
                }
            }

            // Link the quote back to the registration, then clear the namespaced
            // billing transient (only after a successful quote build).
            ntdst_get(RegistrationRepository::class)->update($registrationId, ['quote_id' => $quoteId]);
            $this->clearPendingBilling($userId, $trajectoryId);

            ntdst_log('invoicing')->info('Trajectory quote created for registration', [
                'registration_id' => $registrationId,
                'quote_id' => $quoteId,
                'user_id' => $userId,
                'trajectory_id' => $trajectoryId,
                'amount' => $priceCents,
            ]);
        } catch (\Throwable $e) {
            ntdst_log('invoicing')->error('Trajectory quote handler failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Read the NAMESPACED trajectory pending-billing transient (non-destructive).
     *
     * @return array<string, mixed>|null
     */
    private function getPendingBilling(int $userId, int $trajectoryId): ?array
    {
        $key = 'stride_pending_billing_traj_' . $userId . '_' . $trajectoryId;
        $billing = get_transient($key);
        return $billing ?: null;
    }

    /**
     * Clear the namespaced trajectory pending-billing transient after a successful
     * quote build.
     */
    private function clearPendingBilling(int $userId, int $trajectoryId): void
    {
        delete_transient('stride_pending_billing_traj_' . $userId . '_' . $trajectoryId);
    }

    /**
     * Get the attendee's stored billing information (fallback when no pending
     * billing transient is present). Mirrors EnrollmentQuoteHandler::getUserBilling.
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
