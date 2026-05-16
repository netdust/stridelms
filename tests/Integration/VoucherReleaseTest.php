<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Modules\Invoicing\VoucherService;

/**
 * Regression for B2-003:
 *   - Applying voucher B to a quote that already had voucher A left A's
 *     redemption in place. `_ntdst_used_count` for A was permanently
 *     incremented even though the discount no longer used it.
 *   - Cancelling a quote with a voucher left the redemption in place.
 *
 * VoucherService::releaseVoucher reverses redeemVoucher.
 * QuoteService::applyVoucher releases the prior voucher before redeeming.
 * QuoteService::cancel releases the active voucher.
 */
final class VoucherReleaseTest extends IntegrationTestCase
{
    private VoucherService $voucherService;
    private int $voucherId;
    private string $voucherCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->actingAs(self::$testUserId);

        $this->voucherCode = 'REL' . time() . random_int(100, 999);
        $this->voucherId = $this->voucherService->createVoucher([
            'code' => $this->voucherCode,
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 10,
            'usage_limit' => 5,
        ]);
        self::$testPosts[] = $this->voucherId;
    }

    public function testReleaseVoucherRemovesRedemptionAndDecrementsCount(): void
    {
        $userId = self::$testUserId;
        $quoteId = 99001;

        $r = $this->voucherService->redeemVoucher($this->voucherCode, $userId, $quoteId);
        self::assertIsArray($r);
        self::assertSame(1, (int) get_post_meta($this->voucherId, '_ntdst_used_count', true));

        $released = $this->voucherService->releaseVoucher($this->voucherCode, $userId, $quoteId);
        self::assertTrue($released);

        self::assertSame(0, (int) get_post_meta($this->voucherId, '_ntdst_used_count', true));
        $redemptions = get_post_meta($this->voucherId, '_ntdst_redemptions', true) ?: [];
        self::assertCount(0, $redemptions);
    }

    public function testReleaseVoucherIsNoOpWhenRedemptionAbsent(): void
    {
        $released = $this->voucherService->releaseVoucher($this->voucherCode, self::$testUserId, 99002);
        self::assertFalse($released);
    }

    public function testReleaseVoucherOnlyRemovesMatchingRedemption(): void
    {
        $u1 = self::$testUserId;
        $u2 = self::$testUserId + 1;

        $this->voucherService->redeemVoucher($this->voucherCode, $u1, 99010);
        $this->voucherService->redeemVoucher($this->voucherCode, $u2, 99011);

        self::assertSame(2, (int) get_post_meta($this->voucherId, '_ntdst_used_count', true));

        $released = $this->voucherService->releaseVoucher($this->voucherCode, $u1, 99010);
        self::assertTrue($released);

        self::assertSame(1, (int) get_post_meta($this->voucherId, '_ntdst_used_count', true));
        $redemptions = get_post_meta($this->voucherId, '_ntdst_redemptions', true) ?: [];
        self::assertCount(1, $redemptions);
        self::assertSame($u2, (int) ($redemptions[0]['user_id'] ?? 0));
    }
}
