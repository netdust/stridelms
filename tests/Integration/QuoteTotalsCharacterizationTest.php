<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use ReflectionMethod;
use Stride\Domain\DiscountType;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\Admin\QuoteAdminController;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;

/**
 * Characterization tests for Task C1 (audit finding H-5).
 *
 * Pins the CURRENT subtotal -> discount -> tax -> total derivation of the
 * two code paths that each carry their own `0.21` literal:
 *
 *   - QuoteAdminController::applyManualDiscount / removeDiscount /
 *     processItemsData (admin path)
 *   - QuoteService::onSessionSelectionCompleted / applyVoucher (service path)
 *
 * over a shared fixture matrix (normal discount, discount > subtotal,
 * EUR 0 quote, half-cent rounding edge), BEFORE the literals are
 * consolidated into QuoteCalculator. Every assertion here must stay green
 * across the refactor — totals identical pre/post.
 *
 * The matrix test also asserts the two paths AGREE with each other on tax
 * and total for every fixture; a failure there is a live financial bug,
 * not a test bug (plan says: stop, do not refactor).
 *
 * All amounts are int euro-cents (the real storage representation).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter QuoteTotalsCharacterization
 */
final class QuoteTotalsCharacterizationTest extends IntegrationTestCase
{
    private QuoteService $quoteService;
    private VoucherService $voucherService;
    private int $editionId;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->quoteService = ntdst_get(QuoteService::class);
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->actingAs(self::$testUserId);
        $this->editionId = $this->createTestEdition();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    // =========================================================================
    // Fixture matrix — shared by admin path and service path
    // =========================================================================

    /**
     * @return array<string, array{subtotal: int, discount: int, expectedStoredDiscount: int, expectedTax: int, expectedTotal: int}>
     */
    public static function discountMatrix(): array
    {
        return [
            // taxable 40000 -> 21% = 8400 exactly
            'normal discount' => [
                'subtotal' => 50000,
                'discount' => 10000,
                'expectedStoredDiscount' => 10000,
                'expectedTax' => 8400,
                'expectedTotal' => 48400,
            ],
            // discount exceeds subtotal: admin path clamps stored discount to
            // the subtotal; both paths clamp the TAXABLE base to zero
            'discount exceeds subtotal' => [
                'subtotal' => 5000,
                'discount' => 10000,
                'expectedStoredDiscount' => 5000,
                'expectedTax' => 0,
                'expectedTotal' => 0,
            ],
            // EUR 0 quote
            'zero subtotal' => [
                'subtotal' => 0,
                'discount' => 10000,
                'expectedStoredDiscount' => 0,
                'expectedTax' => 0,
                'expectedTotal' => 0,
            ],
            // taxable 50 cents -> 21% = 10.5 cents (EUR 0,105 — a half cent):
            // PHP round() half-away-from-zero -> 11
            'half-cent tax rounds up' => [
                'subtotal' => 10050,
                'discount' => 10000,
                'expectedStoredDiscount' => 10000,
                'expectedTax' => 11,
                'expectedTotal' => 61,
            ],
        ];
    }

    /**
     * Admin path (applyManualDiscount) pinned + compared against the
     * service recompute path (onSessionSelectionCompleted) on the same
     * subtotal/discount fixture.
     *
     * @test
     * @dataProvider discountMatrix
     */
    public function adminAndServiceDiscountPathsAgreeOnTaxAndTotal(
        int $subtotal,
        int $discount,
        int $expectedStoredDiscount,
        int $expectedTax,
        int $expectedTotal,
    ): void {
        $admin = $this->runAdminManualDiscount($subtotal, $discount);
        $service = $this->runServiceModifierRecompute($subtotal, $discount);

        // Pin current admin-path behavior
        $this->assertSame($expectedStoredDiscount, $admin['discount'], 'admin: stored discount');
        $this->assertSame($expectedTax, $admin['tax'], 'admin: tax');
        $this->assertSame($expectedTotal, $admin['total'], 'admin: total');

        // Pin current service-path behavior (service path never rewrites the
        // stored discount — it only re-derives tax/total from it)
        $this->assertSame($discount, $service['discount'], 'service: stored discount untouched');
        $this->assertSame($expectedTax, $service['tax'], 'service: tax');
        $this->assertSame($expectedTotal, $service['total'], 'service: total');

        // The divergence gate: both paths must agree on the money the
        // customer owes. A failure here is a live financial bug — STOP.
        $this->assertSame($admin['tax'], $service['tax'], 'paths disagree on tax');
        $this->assertSame($admin['total'], $service['total'], 'paths disagree on total');
    }

    // =========================================================================
    // removeDiscount (admin path)
    // =========================================================================

    /** @test */
    public function removeDiscountRestoresFullTaxOnSubtotal(): void
    {
        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'subtotal' => 10000,
                'discount' => 2000,
                'tax' => 1680,
                'total' => 9680,
                'voucher_code' => 'SOMECODE',
            ],
        ]);

        $this->invokeAdmin('removeDiscount', $quoteId);

        $totals = $this->readTotals($quoteId);
        $this->assertSame(0, $totals['discount']);
        $this->assertSame(2100, $totals['tax']);
        $this->assertSame(12100, $totals['total']);
        $this->assertSame('', get_post_meta($quoteId, 'voucher_code', true));
    }

    /** @test */
    public function removeDiscountRoundsHalfCentTaxUp(): void
    {
        // 50 cents * 0.21 = 10.5 cents -> 11
        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => ['subtotal' => 50, 'discount' => 10, 'tax' => 8, 'total' => 48],
        ]);

        $this->invokeAdmin('removeDiscount', $quoteId);

        $totals = $this->readTotals($quoteId);
        $this->assertSame(11, $totals['tax']);
        $this->assertSame(61, $totals['total']);
    }

    // =========================================================================
    // processItemsData (admin path — discount-free derivation, pure)
    // =========================================================================

    /** @test */
    public function processItemsDataDerivesTaxFromItemSubtotal(): void
    {
        // EUR 25,50 -> 2550 cents; 2550 * 0.21 = 535.5 (half cent) -> 536
        $result = $this->invokeAdmin('processItemsData', [
            ['title' => 'Lijn', 'quantity' => 1, 'unit_price' => '25.50'],
        ]);

        $this->assertSame(2550, $result['subtotal']);
        $this->assertSame(536, $result['tax']);
        $this->assertSame(3086, $result['total']);
    }

    /** @test */
    public function processItemsDataOnEmptyItemsYieldsZeroTotals(): void
    {
        $result = $this->invokeAdmin('processItemsData', []);

        $this->assertSame(0, $result['subtotal']);
        $this->assertSame(0, $result['tax']);
        $this->assertSame(0, $result['total']);
    }

    // =========================================================================
    // applyVoucher (service path)
    // =========================================================================

    /** @test */
    public function applyVoucherDerivesTaxOnDiscountedSubtotalWithHalfCentRounding(): void
    {
        // Fixed voucher of 10000 cents on a 10050-cent quote:
        // taxable 50 -> tax 10.5 -> 11, total 61
        $code = 'CHAR' . time() . random_int(100, 999);
        $voucherId = $this->voucherService->createVoucher([
            'code' => $code,
            'discount_type' => DiscountType::Fixed->value,
            'discount_value' => 10000,
            'usage_limit' => 5,
        ]);
        $this->assertIsInt($voucherId);
        self::$testPosts[] = $voucherId;

        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => ['subtotal' => 10050, 'tax' => 2111, 'total' => 12161],
        ]);

        $result = $this->quoteService->applyVoucher($quoteId, $code);
        $this->assertTrue($result, 'applyVoucher should succeed');

        $totals = $this->readTotals($quoteId);
        $this->assertSame(10000, $totals['discount']);
        $this->assertSame(11, $totals['tax']);
        $this->assertSame(61, $totals['total']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Drive QuoteAdminController::applyManualDiscount over a real quote.
     *
     * @return array{discount: int, tax: int, total: int}
     */
    private function runAdminManualDiscount(int $subtotalCents, int $discountCents): array
    {
        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            // Sentinel tax/total (valid but obviously wrong) prove the write happened
            'meta' => ['subtotal' => $subtotalCents, 'discount' => 0, 'tax' => 1, 'total' => 1],
        ]);

        $this->invokeAdmin('applyManualDiscount', $quoteId, $discountCents / 100);

        return $this->readTotals($quoteId);
    }

    /**
     * Drive QuoteService::onSessionSelectionCompleted over a real quote +
     * registration. The quote starts with one edition item (= the subtotal
     * under test) plus one stale session_modifier item; submitting an empty
     * selection strips the modifier and forces the recompute branch.
     *
     * @return array{discount: int, tax: int, total: int}
     */
    private function runServiceModifierRecompute(int $subtotalCents, int $discountCents): array
    {
        $regId = ntdst_get(RegistrationRepository::class)->create([
            'user_id' => self::$testUserId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        $items = [
            [
                'id' => $this->editionId,
                'type' => 'edition',
                'title' => 'Basis',
                'quantity' => 1,
                'unit_price' => $subtotalCents,
                'total' => $subtotalCents,
            ],
            [
                'id' => 99999,
                'type' => 'session_modifier',
                'title' => 'Sessie: Oud',
                'quantity' => 1,
                'unit_price' => 999,
                'total' => 999,
            ],
        ];

        $quoteId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'registration_id' => $regId,
                'items' => $items,
                'subtotal' => $subtotalCents + 999,
                'discount' => $discountCents,
                'tax' => 1,
                'total' => 1,
            ],
        ]);

        $this->quoteService->onSessionSelectionCompleted([
            'task_type' => 'session_selection',
            'registration_id' => $regId,
            'tasks' => ['session_selection' => ['data' => ['session_ids' => []]]],
        ]);

        $this->assertSame(
            $subtotalCents,
            (int) get_post_meta($quoteId, 'subtotal', true),
            'service path should re-derive subtotal from remaining items',
        );

        return $this->readTotals($quoteId);
    }

    /**
     * Invoke a private QuoteAdminController method via reflection.
     */
    private function invokeAdmin(string $method, mixed ...$args): mixed
    {
        $controller = new QuoteAdminController(
            $this->quoteService,
            ntdst_get(QuoteRepository::class),
            $this->voucherService,
            ntdst_get(EditionRepository::class),
        );

        $ref = new ReflectionMethod($controller, $method);

        return $ref->invoke($controller, ...$args);
    }

    /**
     * @return array{discount: int, tax: int, total: int}
     */
    private function readTotals(int $quoteId): array
    {
        return [
            'discount' => (int) get_post_meta($quoteId, 'discount', true),
            'tax' => (int) get_post_meta($quoteId, 'tax', true),
            'total' => (int) get_post_meta($quoteId, 'total', true),
        ];
    }
}
