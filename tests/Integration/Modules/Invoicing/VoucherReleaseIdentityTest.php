<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Invoicing;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Domain\Money;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;

/**
 * Pre-merge review findings 2+3 (PR #7) — MONEY BOUNDARY (Tier A).
 *
 * RED-first repro of the redeem/release IDENTITY ASYMMETRY introduced by this
 * branch's T8 fix. applyVoucher can now redeem a voucher against an ATTENDEE
 * (colleague/bulk enroll, payer != attendee) via $redeemAsUserId. But the RELEASE
 * paths keyed on the PAYER:
 *   - cancel()        released against $meta['user_id'] (the payer)
 *   - applyVoucher's  release-of-previous step recomputed the id from the payer
 * Redeemed-as-attendee + released-as-payer → removeRedemption() finds no matching
 * (voucherId, userId, quoteId) row → releaseVoucher rolls back → used_count never
 * reverses (quota drained) and the attendee stays capped (can't redeem again).
 *
 * The fix persists WHO the voucher was redeemed against on the quote as new meta
 * `voucher_redeemed_user_id`, and both release paths read it (falling back to the
 * payer for legacy quotes). This test asserts the symmetry holds across the
 * redeem -> cancel and redeem -> replace gaps.
 *
 * Modeled on AutoVoucherEditionTest (bulk-enroll + attendee redemption). Reuses
 * CleansUpLeakedQuotesTrait for the registration-id-reuse teardown leak.
 *
 * This is a MONEY boundary — run it 3x for determinism (redeemVoucher SELECT FOR
 * UPDATE). Immutable contract: green it without weakening.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter VoucherReleaseIdentity'
 */
final class VoucherReleaseIdentityTest extends IntegrationTestCase
{
    use CleansUpLeakedQuotesTrait;

    private QuoteService $quotes;
    private VoucherService $vouchers;
    private RegistrationRepository $registrations;

    /** @var array<int> registration ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];
    /** @var array<int> user ids to delete in tearDown */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes        = ntdst_get(QuoteService::class);
        $this->vouchers      = ntdst_get(VoucherService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        $this->deleteQuotesForRegistrations($this->createdRegistrationIds);

        foreach ($this->createdRegistrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrationIds = [];

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        wp_set_current_user(0);

        parent::tearDown();
    }

    // === Finding 2 — CANCEL reverses the ATTENDEE's redemption ===============

    /**
     * A payer (admin) bulk-enrolls a colleague; the auto-voucher is redeemed
     * against the ATTENDEE (payer != attendee). Cancelling the quote must reverse
     * used_count to 0 AND free the attendee to redeem again.
     *
     * Pre-fix: cancel() releases against the payer id → removeRedemption finds no
     * row → rollback → used_count stays 1, attendee stays capped.
     *
     * @test
     */
    public function cancellingABulkQuoteReversesTheAttendeeRedemption(): void
    {
        $code      = $this->uniqueCode('CXL');
        $voucherId = $this->createTenPercentVoucher($code);

        $payerId    = $this->createUser();   // admin/payer — owns the quote
        $attendeeId = $this->createUser();   // the colleague the discount belongs to

        $quoteId = $this->createBulkDraftQuote($payerId, $attendeeId);

        // Redeem the auto-voucher against the ATTENDEE (the T8 path).
        $applied = $this->quotes->applyVoucher($quoteId, $code, redeemAsUserId: $attendeeId);
        self::assertTrue($applied === true, 'the attendee auto-voucher must apply: ' . $this->err($applied));

        self::assertSame(
            1,
            $this->usedCount($voucherId),
            'redeeming against the attendee must move used_count to 1',
        );

        // Cancel the quote → the redemption keyed on the ATTENDEE must reverse.
        $cancelled = $this->quotes->cancel($quoteId);
        self::assertTrue($cancelled === true, 'cancel must succeed: ' . $this->err($cancelled));

        self::assertSame(
            0,
            $this->usedCount($voucherId),
            'cancelling the quote must reverse used_count to 0 — the release must key on the '
            . 'ATTENDEE the voucher was redeemed against, not the payer',
        );

        // ...and the attendee is no longer capped: they can redeem the same code
        // again on a fresh quote (removeRedemption removed their row).
        $secondQuoteId = $this->createBulkDraftQuote($payerId, $attendeeId);
        $reapplied = $this->quotes->applyVoucher($secondQuoteId, $code, redeemAsUserId: $attendeeId);
        self::assertTrue(
            $reapplied === true,
            'the attendee must be free to redeem again after cancel released their redemption: ' . $this->err($reapplied),
        );
    }

    // === Finding 3 — ADMIN REPLACE reverses the OLD voucher's redemption =====

    /**
     * A bulk quote has auto-voucher A redeemed against the ATTENDEE. Admin applies
     * voucher B via the default-null replace path (exactly what handleVoucherActions
     * calls: applyVoucher($postId, $codeB) with no $redeemAsUserId). A's redemption
     * must reverse to 0 and B must redeem.
     *
     * Pre-fix: applyVoucher's release-of-previous step keys on the recomputed payer
     * id → A's attendee row is never found → A strands at used_count 1.
     *
     * @test
     */
    public function adminReplacingAVoucherReversesTheOldAttendeeRedemption(): void
    {
        $codeA = $this->uniqueCode('OLDA');
        $codeB = $this->uniqueCode('NEWB');
        $voucherA = $this->createTenPercentVoucher($codeA);
        $voucherB = $this->createTenPercentVoucher($codeB);

        $payerId    = $this->createUser();
        $attendeeId = $this->createUser();

        $quoteId = $this->createBulkDraftQuote($payerId, $attendeeId);

        // Auto-voucher A redeemed against the ATTENDEE.
        $appliedA = $this->quotes->applyVoucher($quoteId, $codeA, redeemAsUserId: $attendeeId);
        self::assertTrue($appliedA === true, 'voucher A must apply: ' . $this->err($appliedA));
        self::assertSame(1, $this->usedCount($voucherA), 'A must redeem against the attendee (used_count 1)');

        // Admin replaces with B via the DEFAULT-NULL path (the handleVoucherActions
        // signature: applyVoucher($postId, $code) — no redeemAsUserId). This is the
        // exact replace path under test.
        $appliedB = $this->quotes->applyVoucher($quoteId, $codeB);
        self::assertTrue($appliedB === true, 'voucher B must apply on replace: ' . $this->err($appliedB));

        self::assertSame(
            0,
            $this->usedCount($voucherA),
            "replacing A with B must reverse A's used_count to 0 — the release of the PREVIOUS "
            . 'voucher must key on the id A was redeemed against (the attendee), not the payer',
        );
        self::assertSame(
            1,
            $this->usedCount($voucherB),
            'voucher B must be redeemed after the replace',
        );
    }

    // === Fixtures ===========================================================

    private function uniqueCode(string $prefix): string
    {
        return $prefix . strtoupper(wp_generate_password(6, false, false));
    }

    private function createTenPercentVoucher(string $code): int
    {
        return $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,
                '_ntdst_usage_limit'    => 5,
                '_ntdst_used_count'     => 0,
            ],
        ]);
    }

    private function createUser(): int
    {
        $username = 'relid_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'fixture: failed to create user');
        $this->createdUserIds[] = $userId;
        return $userId;
    }

    /**
     * A DRAFT quote for a bulk enroll: owned by the PAYER, attendee = colleague.
     * Mirrors the shape createQuote produces so applyVoucher's draft-status guard
     * and subtotal math run. edition_id 0 keeps the voucher edition-unscoped so
     * the 10% code validates for any edition.
     */
    private function createBulkDraftQuote(int $payerId, int $attendeeId): int
    {
        $editionId = $this->createTestEdition();

        $regId = $this->registrations->create([
            'user_id'         => $attendeeId,
            'edition_id'      => $editionId,
            'status'          => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
            'enrolled_by'     => $payerId,
        ]);
        self::assertIsInt($regId, 'fixture: could not seed registration');
        $this->createdRegistrationIds[] = $regId;

        // The quote is owned by the PAYER (user_id = payer) — the asymmetry the
        // finding is about. subtotal non-zero so a discount can be computed.
        $quote = $this->quotes->createQuote(
            userId: $payerId,
            editionId: $editionId,
            items: [[
                'title'      => 'Bulk enroll seat',
                'quantity'   => 1,
                'unit_price' => Money::cents(10000),
            ]],
            registrationId: $regId,
        );
        self::assertIsInt($quote, 'fixture: createQuote must return a quote id: ' . $this->err($quote));

        return $quote;
    }

    private function usedCount(int $voucherId): int
    {
        return (int) get_post_meta($voucherId, '_ntdst_used_count', true);
    }

    private function err(mixed $result): string
    {
        return is_wp_error($result) ? $result->get_error_code() . ': ' . $result->get_error_message() : var_export($result, true);
    }
}
