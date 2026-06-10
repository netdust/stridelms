<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Invoicing;

use Stride\Modules\Invoicing\Helpers\QuoteCalculator;
use Stride\Tests\TestCase;

/**
 * Contract tests for QuoteCalculator::deriveTotalsFromCents() — the single
 * cents-level subtotal -> discount -> tax -> total derivation that Task C1
 * (audit H-5) consolidates the six scattered `0.21` literals into.
 *
 * Matches the behavior pinned by QuoteTotalsCharacterizationTest:
 *   - tax = 21% BTW on the DISCOUNTED subtotal, round() half-away-from-zero
 *   - taxable base never goes below zero (discount clamped to subtotal)
 *   - the input subtotal passes through unchanged
 */
final class QuoteCalculatorTest extends TestCase
{
    public function testDerivesTaxOnDiscountedSubtotal(): void
    {
        $totals = QuoteCalculator::deriveTotalsFromCents(50000, 10000);

        $this->assertSame(50000, $totals['subtotal']);
        $this->assertSame(10000, $totals['discount']);
        $this->assertSame(8400, $totals['tax']); // 40000 * 21%
        $this->assertSame(48400, $totals['total']);
    }

    public function testDiscountDefaultsToZero(): void
    {
        $totals = QuoteCalculator::deriveTotalsFromCents(10000);

        $this->assertSame(10000, $totals['subtotal']);
        $this->assertSame(0, $totals['discount']);
        $this->assertSame(2100, $totals['tax']);
        $this->assertSame(12100, $totals['total']);
    }

    public function testDiscountLargerThanSubtotalIsClampedToSubtotal(): void
    {
        $totals = QuoteCalculator::deriveTotalsFromCents(5000, 10000);

        $this->assertSame(5000, $totals['subtotal']);
        $this->assertSame(5000, $totals['discount']);
        $this->assertSame(0, $totals['tax']);
        $this->assertSame(0, $totals['total']);
    }

    public function testNegativeDiscountIsClampedToZero(): void
    {
        $totals = QuoteCalculator::deriveTotalsFromCents(10000, -500);

        $this->assertSame(0, $totals['discount']);
        $this->assertSame(2100, $totals['tax']);
        $this->assertSame(12100, $totals['total']);
    }

    public function testZeroSubtotalYieldsZeroTotalsEvenWithDiscount(): void
    {
        $totals = QuoteCalculator::deriveTotalsFromCents(0, 10000);

        $this->assertSame(0, $totals['subtotal']);
        $this->assertSame(0, $totals['discount']);
        $this->assertSame(0, $totals['tax']);
        $this->assertSame(0, $totals['total']);
    }

    public function testHalfCentTaxRoundsAwayFromZero(): void
    {
        // taxable 50 cents -> 21% = 10.5 cents -> 11
        $totals = QuoteCalculator::deriveTotalsFromCents(10050, 10000);

        $this->assertSame(11, $totals['tax']);
        $this->assertSame(61, $totals['total']);
    }

    public function testNegativeSubtotalClampsTaxableBaseToZero(): void
    {
        // Defensive: item lists with negative session modifiers could in
        // theory sum below zero — the taxable base must never go negative.
        // Matches the pre-consolidation max(0, ...) in the modifier path.
        $totals = QuoteCalculator::deriveTotalsFromCents(-100, 0);

        $this->assertSame(-100, $totals['subtotal']); // passthrough, caller persists raw subtotal
        $this->assertSame(0, $totals['discount']);
        $this->assertSame(0, $totals['tax']);
        $this->assertSame(0, $totals['total']);
    }
}
