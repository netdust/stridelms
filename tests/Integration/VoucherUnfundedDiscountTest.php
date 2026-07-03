<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Domain\QuoteStatus;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;

/**
 * DATA-1 regression (audit-remediation-nine, Task 2A.1).
 *
 * Bug: QuoteService::applyVoucher() persisted the discount to the quote
 * (updateMeta) BEFORE redeeming the voucher. When redeemVoucher returned a
 * WP_Error the discount was already written with no matching redemption — an
 * "unfunded discount" flowing to Exact Online.
 *
 * Fix (mitigation 4): redeem-then-write. redeemVoucher must succeed BEFORE the
 * discount is persisted; on a redeem WP_Error the quote's discount/tax/total
 * stay untouched and the error is surfaced (INV-4).
 *
 * Totals still derive through QuoteCalculator::deriveTotalsFromCents (INV-8) —
 * the reorder does not touch the math.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter VoucherUnfundedDiscount
 */
final class VoucherUnfundedDiscountTest extends IntegrationTestCase
{
    private QuoteService $quoteService;
    private VoucherService $voucherService;
    private int $editionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);
        $this->quoteService = ntdst_get(QuoteService::class);
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->editionId = $this->createTestEdition(['post_title' => 'DATA-1 Edition']);
    }

    /**
     * Read the current discount/tax/total off a quote as stored cents.
     *
     * @return array{discount:int,tax:int,total:int,voucher_code:string}
     */
    private function quoteFinancials(int $quoteId): array
    {
        return [
            'discount'     => (int) get_post_meta($quoteId, 'discount', true),
            'tax'          => (int) get_post_meta($quoteId, 'tax', true),
            'total'        => (int) get_post_meta($quoteId, 'total', true),
            'voucher_code' => (string) get_post_meta($quoteId, 'voucher_code', true),
        ];
    }

    /**
     * RED-first: when redeemVoucher fails, the discount must NOT be persisted.
     *
     * The redeem is forced to fail deterministically by pre-redeeming the SAME
     * voucher for the SAME user — VoucherService::redeemVoucher returns
     * WP_Error('already_redeemed') on the second attempt. Against the pre-reorder
     * code the discount was written to the quote BEFORE that failing redeem, so
     * this test FAILS (discount != 0). After the redeem-then-write reorder it
     * PASSES (discount stays 0, error surfaced).
     *
     * @test
     */
    public function failedRedeemLeavesNoUnfundedDiscountOnTheQuote(): void
    {
        $code = 'DATA1' . strtoupper(wp_generate_password(6, false, false));
        $voucherId = $this->createTestVoucher([
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,
                '_ntdst_usage_limit'    => 5,
            ],
        ]);

        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'status'   => QuoteStatus::Draft->value,
                'subtotal' => 50000,
                'discount' => 0,
                'tax'      => 10500,
                'total'    => 60500,
            ],
        ]);

        // Pre-redeem for the same user so the redeem INSIDE applyVoucher fails.
        $pre = $this->voucherService->redeemVoucher($code, self::$testUserId, 999999);
        self::assertIsArray($pre, 'pre-redeem should succeed to arm the already_redeemed failure');

        $before = $this->quoteFinancials($quoteId);

        $result = $this->quoteService->applyVoucher($quoteId, $code);

        // The error is surfaced, not swallowed (INV-4).
        self::assertInstanceOf(\WP_Error::class, $result, 'a failed redeem must surface the error');
        self::assertSame('already_redeemed', $result->get_error_code());

        // No unfunded discount: quote financials are byte-for-byte unchanged.
        $after = $this->quoteFinancials($quoteId);
        self::assertSame(0, $after['discount'], 'discount must NOT be written when the redeem fails');
        self::assertSame($before['tax'], $after['tax'], 'tax must be unchanged on a failed redeem');
        self::assertSame($before['total'], $after['total'], 'total must be unchanged on a failed redeem');
        self::assertSame('', $after['voucher_code'], 'voucher_code must not be persisted on a failed redeem');
    }

    /**
     * GREEN happy path: a successful apply persists the discount AND records the
     * redemption. Both sides of the ledger move together.
     *
     * @test
     */
    public function successfulApplyPersistsDiscountAndRecordsRedemption(): void
    {
        $code = 'DATA1OK' . strtoupper(wp_generate_password(5, false, false));
        $voucherId = $this->createTestVoucher([
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,   // 10% of 500.00 = 50.00
                '_ntdst_usage_limit'    => 5,
            ],
        ]);

        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'status'   => QuoteStatus::Draft->value,
                'subtotal' => 50000,
                'discount' => 0,
                'tax'      => 10500,
                'total'    => 60500,
            ],
        ]);

        $result = $this->quoteService->applyVoucher($quoteId, $code);

        self::assertTrue($result, 'a valid voucher applies cleanly');

        // Discount persisted on the quote.
        $after = $this->quoteFinancials($quoteId);
        self::assertSame(5000, $after['discount'], '10% of 50000 = 5000 cents discount');
        self::assertSame($code, $after['voucher_code']);
        self::assertGreaterThanOrEqual(0, $after['tax']);
        self::assertGreaterThanOrEqual(0, $after['total']);

        // Matching redemption recorded — the funding side of the ledger.
        self::assertSame(1, (int) get_post_meta($voucherId, '_ntdst_used_count', true));
        $redemptions = get_post_meta($voucherId, '_ntdst_redemptions', true) ?: [];
        self::assertCount(1, $redemptions);
        self::assertSame(self::$testUserId, (int) ($redemptions[0]['user_id'] ?? 0));
    }

    /**
     * Boundary (INV-8): a >100% percentage voucher clamps the discount to the
     * subtotal; tax and total stay >= 0. The clamp lives in
     * QuoteCalculator::deriveTotalsFromCents and must survive the reorder.
     *
     * @test
     */
    public function overHundredPercentVoucherClampsToSubtotalWithNonNegativeTotals(): void
    {
        $code = 'DATA1CLAMP' . strtoupper(wp_generate_password(4, false, false));
        $voucherId = $this->createTestVoucher([
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 150,  // misconfigured 150%
                '_ntdst_usage_limit'    => 5,
            ],
        ]);

        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'status'   => QuoteStatus::Draft->value,
                'subtotal' => 50000,
                'discount' => 0,
                'tax'      => 10500,
                'total'    => 60500,
            ],
        ]);

        $result = $this->quoteService->applyVoucher($quoteId, $code);
        self::assertTrue($result);

        $after = $this->quoteFinancials($quoteId);
        // Discount clamped to the subtotal, never above it.
        self::assertSame(50000, $after['discount'], 'a 150% voucher clamps to the 50000 subtotal');
        self::assertGreaterThanOrEqual(0, $after['tax'], 'tax must not go negative');
        self::assertGreaterThanOrEqual(0, $after['total'], 'total must not go negative');
    }

    /**
     * Re-apply edge: applying a DIFFERENT code releases the previous, redeems the
     * new, and recomputes the discount once. And if the NEW redeem then fails,
     * the quote is left in its prior FUNDED state (the old code's discount stays)
     * — never an unfunded discount.
     *
     * @test
     */
    public function reApplyReleasesPreviousThenRedeemsNew(): void
    {
        $codeA = 'DATA1A' . strtoupper(wp_generate_password(4, false, false));
        $codeB = 'DATA1B' . strtoupper(wp_generate_password(4, false, false));

        $voucherA = $this->createTestVoucher([
            'meta' => [
                '_ntdst_code'           => $codeA,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,   // 10% -> 5000
                '_ntdst_usage_limit'    => 5,
            ],
        ]);
        $voucherB = $this->createTestVoucher([
            'meta' => [
                '_ntdst_code'           => $codeB,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 20,   // 20% -> 10000
                '_ntdst_usage_limit'    => 5,
            ],
        ]);

        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'status'   => QuoteStatus::Draft->value,
                'subtotal' => 50000,
                'discount' => 0,
                'tax'      => 10500,
                'total'    => 60500,
            ],
        ]);

        // First apply A.
        self::assertTrue($this->quoteService->applyVoucher($quoteId, $codeA));
        self::assertSame(5000, $this->quoteFinancials($quoteId)['discount']);
        self::assertSame(1, (int) get_post_meta($voucherA, '_ntdst_used_count', true));

        // Re-apply B: A released, B redeemed, discount recomputed to 20%.
        self::assertTrue($this->quoteService->applyVoucher($quoteId, $codeB));
        $after = $this->quoteFinancials($quoteId);
        self::assertSame(10000, $after['discount'], 'discount recomputed for the new code');
        self::assertSame($codeB, $after['voucher_code']);
        self::assertSame(0, (int) get_post_meta($voucherA, '_ntdst_used_count', true), 'A released (used_count reversed)');
        self::assertSame(1, (int) get_post_meta($voucherB, '_ntdst_used_count', true), 'B redeemed once');
    }

    /**
     * Re-apply failure edge: when the new code's redeem fails, the quote keeps
     * its PRIOR funded state (old code + old discount), never an unfunded new
     * discount. The previous code was released at step 1 but — because the
     * discount write is now step 4 (after redeem) — the quote's meta still
     * carries the OLD code and OLD discount when the new redeem fails.
     *
     * @test
     */
    public function reApplyWithFailingNewRedeemLeavesPriorFundedState(): void
    {
        $codeA = 'DATA1FA' . strtoupper(wp_generate_password(4, false, false));
        $codeB = 'DATA1FB' . strtoupper(wp_generate_password(4, false, false));

        $voucherA = $this->createTestVoucher([
            'meta' => [
                '_ntdst_code'           => $codeA,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,   // 10% -> 5000
                '_ntdst_usage_limit'    => 5,
            ],
        ]);
        $voucherB = $this->createTestVoucher([
            'meta' => [
                '_ntdst_code'           => $codeB,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 20,
                '_ntdst_usage_limit'    => 5,
            ],
        ]);

        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'status'   => QuoteStatus::Draft->value,
                'subtotal' => 50000,
                'discount' => 0,
                'tax'      => 10500,
                'total'    => 60500,
            ],
        ]);

        // Apply A cleanly.
        self::assertTrue($this->quoteService->applyVoucher($quoteId, $codeA));
        self::assertSame(5000, $this->quoteFinancials($quoteId)['discount']);

        // Arm B's redeem to fail for this user.
        self::assertIsArray($this->voucherService->redeemVoucher($codeB, self::$testUserId, 888888));

        // Re-apply B — its redeem fails with already_redeemed.
        $result = $this->quoteService->applyVoucher($quoteId, $codeB);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('already_redeemed', $result->get_error_code());

        // Quote still carries the OLD funded state — no unfunded B discount.
        $after = $this->quoteFinancials($quoteId);
        self::assertSame($codeA, $after['voucher_code'], 'quote keeps the old funded code');
        self::assertSame(5000, $after['discount'], 'quote keeps the old funded discount, not an unfunded B discount');
    }
}
