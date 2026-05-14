<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Domain\Money;
use Stride\Domain\VoucherStatus;
use Stride\Modules\Invoicing\VoucherService;

/**
 * Integration tests for VoucherService
 *
 * Tests voucher validation and discount calculation.
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter VoucherService
 */
class VoucherServiceIntegrationTest extends IntegrationTestCase
{
    private VoucherService $voucherService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->actingAs(self::$testUserId);
    }

    // =========================================================================
    // VOUCHER CREATION
    // =========================================================================

    /**
     * @test
     */
    public function canCreateVoucher(): void
    {
        $code = 'TESTCREATE' . time();
        $result = $this->voucherService->createVoucher([
            'code' => $code,
            'discount_type' => DiscountType::Full->value,
            'usage_limit' => 5,
        ]);

        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);

        self::$testPosts[] = $result;
    }

    /**
     * @test
     */
    public function canCreateVoucherWithPercentageDiscount(): void
    {
        $code = 'PERCENT' . time();
        $voucherId = $this->voucherService->createVoucher([
            'code' => $code,
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 25,
            'usage_limit' => 10,
        ]);

        $this->assertIsInt($voucherId);
        self::$testPosts[] = $voucherId;
    }

    /**
     * @test
     */
    public function canCreateVoucherWithFixedDiscount(): void
    {
        $code = 'FIXED' . time();
        $voucherId = $this->voucherService->createVoucher([
            'code' => $code,
            'discount_type' => DiscountType::Fixed->value,
            'discount_value' => 2500,
            'usage_limit' => 1,
        ]);

        $this->assertIsInt($voucherId);
        self::$testPosts[] = $voucherId;
    }

    // =========================================================================
    // DISCOUNT CALCULATION (These don't require DB queries)
    // =========================================================================

    /**
     * @test
     */
    public function calculatesFullDiscount(): void
    {
        $voucher = [
            'discount_type' => DiscountType::Full->value,
            'discount_value' => 0,
        ];
        $subtotal = Money::cents(10000);

        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

        $this->assertEquals(10000, $discount->inCents());
    }

    /**
     * @test
     */
    public function calculatesPercentageDiscount(): void
    {
        $voucher = [
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 25,
        ];
        $subtotal = Money::cents(10000);

        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

        $this->assertEquals(2500, $discount->inCents());
    }

    /**
     * @test
     */
    public function calculatesFixedDiscount(): void
    {
        $voucher = [
            'discount_type' => DiscountType::Fixed->value,
            'discount_value' => 1500,
        ];
        $subtotal = Money::cents(10000);

        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

        $this->assertEquals(1500, $discount->inCents());
    }

    /**
     * @test
     */
    public function fixedDiscountCappedAtSubtotal(): void
    {
        $voucher = [
            'discount_type' => DiscountType::Fixed->value,
            'discount_value' => 15000,
        ];
        $subtotal = Money::cents(10000);

        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

        $this->assertEquals(10000, $discount->inCents());
    }

    /**
     * @test
     */
    public function calculatesZeroPercentageDiscount(): void
    {
        $voucher = [
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 0,
        ];
        $subtotal = Money::cents(10000);

        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

        $this->assertEquals(0, $discount->inCents());
    }

    /**
     * @test
     */
    public function calculatesHundredPercentDiscount(): void
    {
        $voucher = [
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 100,
        ];
        $subtotal = Money::cents(10000);

        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

        $this->assertEquals(10000, $discount->inCents());
    }

    // =========================================================================
    // VOUCHER VALIDATION (Using test helper for setup)
    // =========================================================================

    /**
     * @test
     */
    public function validationRejectsNonExistentVoucher(): void
    {
        $result = $this->voucherService->validateVoucher('NONEXISTENT_' . time());

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_found', $result->get_error_code());
    }

    /**
     * @test
     */
    public function validatesActiveVoucherFromHelper(): void
    {
        $code = 'ACTIVE' . time();
        $voucherId = $this->createTestVoucher(['code' => $code]);

        $result = $this->voucherService->validateVoucher($code);

        $this->assertIsArray($result, 'Validation should return voucher array');
        $this->assertEquals($code, $result['code']);
    }

    /**
     * @test
     */
    public function validationRejectsExhaustedVoucherFromHelper(): void
    {
        $code = 'EXHAUSTED' . time();
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_usage_limit' => 1,
                '_ntdst_used_count' => 1,
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('exhausted', $result->get_error_code());
    }

    /**
     * @test
     */
    public function validationRejectsExpiredVoucherFromHelper(): void
    {
        $code = 'EXPIRED' . time();
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_valid_until' => date('Y-m-d', strtotime('-1 day')),
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('expired', $result->get_error_code());
    }

    /**
     * @test
     */
    public function validationRejectsNotYetValidVoucherFromHelper(): void
    {
        $code = 'FUTURE' . time();
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_valid_from' => date('Y-m-d', strtotime('+1 day')),
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_yet_valid', $result->get_error_code());
    }

    /**
     * @test
     */
    public function validationRejectsWrongEdition(): void
    {
        $edition1 = $this->createTestEdition();
        $edition2 = $this->createTestEdition();

        $code = 'EDITION' . time();
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_edition_id' => $edition1,
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code, $edition2);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('wrong_edition', $result->get_error_code());
    }

    /**
     * @test
     */
    public function validationAcceptsCorrectEdition(): void
    {
        $editionId = $this->createTestEdition();

        $code = 'RIGHTEDITION' . time();
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_edition_id' => $editionId,
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code, $editionId);

        $this->assertIsArray($result);
        $this->assertEquals($code, $result['code']);
    }

    /**
     * @test
     */
    public function validationAcceptsUnrestrictedVoucherForAnyEdition(): void
    {
        $editionId = $this->createTestEdition();

        $code = 'UNRESTRICTED' . time();
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_edition_id' => 0,
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code, $editionId);

        $this->assertIsArray($result);
        $this->assertEquals($code, $result['code']);
    }

    // =========================================================================
    // VOUCHER RETRIEVAL
    // =========================================================================

    /**
     * @test
     */
    public function canGetVoucherByCode(): void
    {
        $code = 'GETBYCODE' . time();
        $voucherId = $this->createTestVoucher(['code' => $code]);

        $voucher = $this->voucherService->getVoucherByCode($code);

        $this->assertNotNull($voucher);
        $this->assertEquals($code, $voucher['code']);
    }

    /**
     * @test
     */
    public function getVoucherByCodeReturnsNullForNonExistent(): void
    {
        $voucher = $this->voucherService->getVoucherByCode('DOESNOTEXIST_' . time());

        $this->assertNull($voucher);
    }

    /**
     * @test
     */
    public function canGetVoucherById(): void
    {
        $code = 'GETBYID' . time();
        $voucherId = $this->createTestVoucher(['code' => $code]);

        $voucher = $this->voucherService->getVoucher($voucherId);

        $this->assertNotNull($voucher);
        $this->assertEquals($code, $voucher['code']);
    }

    // =========================================================================
    // SCOPE — alle / alleen / behalve
    // =========================================================================

    /**
     * @test
     */
    public function validationRejectsExcludedEdition(): void
    {
        $excluded = $this->createTestEdition();
        $allowed = $this->createTestEdition();

        $code = 'EXCEPT' . time();
        $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_scope_mode' => 'except',
                '_ntdst_excluded_edition_ids' => [$excluded],
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code, $excluded);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('wrong_edition', $result->get_error_code());
    }

    /**
     * @test
     */
    public function validationAcceptsNonExcludedEdition(): void
    {
        $excluded = $this->createTestEdition();
        $allowed = $this->createTestEdition();

        $code = 'EXCEPTOK' . time();
        $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_scope_mode' => 'except',
                '_ntdst_excluded_edition_ids' => [$excluded],
            ],
        ]);

        $result = $this->voucherService->validateVoucher($code, $allowed);

        $this->assertIsArray($result);
        $this->assertEquals($code, $result['code']);
    }

    /**
     * @test
     *
     * Back-compat: legacy vouchers stored before scope_mode existed
     * (only edition_id > 0) must continue to behave as "only" mode.
     */
    public function validationLegacyEditionIdActsAsOnlyMode(): void
    {
        $allowed = $this->createTestEdition();
        $other = $this->createTestEdition();

        $code = 'LEGACY' . time();
        $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                // Deliberately no scope_mode — simulates legacy data
                '_ntdst_edition_id' => $allowed,
            ],
        ]);

        $reject = $this->voucherService->validateVoucher($code, $other);
        $this->assertTrue(is_wp_error($reject));
        $this->assertEquals('wrong_edition', $reject->get_error_code());

        $accept = $this->voucherService->validateVoucher($code, $allowed);
        $this->assertIsArray($accept);
    }

    // =========================================================================
    // APPLY MODE — single_session prorating
    // =========================================================================

    /**
     * @test
     */
    public function calculatesProratedDiscountForSingleSessionMode(): void
    {
        $editionId = $this->createTestEdition();
        $this->createTestSessions($editionId, 4);

        $voucher = [
            'discount_type' => DiscountType::Full->value,
            'discount_value' => 0,
            'apply_mode' => 'single_session',
        ];

        $subtotal = Money::cents(10000); // €100
        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal, $editionId);

        // 100% Full discount on prorated €25 = €25 off
        $this->assertEquals(2500, $discount->inCents());
    }

    /**
     * @test
     */
    public function calculatesProratedPercentageDiscount(): void
    {
        $editionId = $this->createTestEdition();
        $this->createTestSessions($editionId, 4);

        $voucher = [
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 50,
            'apply_mode' => 'single_session',
        ];

        $subtotal = Money::cents(10000); // €100
        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal, $editionId);

        // 50% of (€100 / 4) = 50% of €25 = €12.50
        $this->assertEquals(1250, $discount->inCents());
    }

    /**
     * @test
     */
    public function prorateFallsBackForZeroSessionEdition(): void
    {
        $editionId = $this->createTestEdition(); // No sessions = e-learning

        $voucher = [
            'discount_type' => DiscountType::Full->value,
            'discount_value' => 0,
            'apply_mode' => 'single_session',
        ];

        $subtotal = Money::cents(10000);
        $discount = $this->voucherService->calculateDiscount($voucher, $subtotal, $editionId);

        // 0 sessions collapses to divisor 1, so full subtotal discounts
        $this->assertEquals(10000, $discount->inCents());
    }

    /**
     * Create N sessions for an edition. Cleanup handled via self::$testPosts.
     */
    private function createTestSessions(int $editionId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $sessionId = wp_insert_post([
                'post_title' => "Test Session {$i}",
                'post_type' => 'vad_session',
                'post_status' => 'publish',
            ]);

            if (is_wp_error($sessionId)) {
                throw new \RuntimeException('Failed to create test session: ' . $sessionId->get_error_message());
            }

            update_post_meta($sessionId, '_ntdst_edition_id', $editionId);
            update_post_meta($sessionId, '_ntdst_date', date('Y-m-d', strtotime("+{$i} days")));
            update_post_meta($sessionId, '_ntdst_start_time', '09:00');

            self::$testPosts[] = $sessionId;
        }

        // SessionService caches results; clear to ensure tests see fresh data
        ntdst_invalidate_post_type('vad_session');
    }
}
