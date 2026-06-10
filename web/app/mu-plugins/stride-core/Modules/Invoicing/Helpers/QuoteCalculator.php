<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Helpers;

use Stride\Domain\Money;

/**
 * Pure calculation helper for quotes.
 *
 * All methods are stateless functions operating on Money values.
 */
final class QuoteCalculator
{
    private const TAX_RATE = 21.0; // Belgian BTW rate

    /**
     * Calculate totals from items.
     *
     * Money-based wrapper over deriveTotalsFromCents() — the single
     * subtotal -> discount -> tax -> total derivation (CR-C2). The returned
     * discount is the clamped value ([0, subtotal]), identical to what the
     * cents-level write paths persist.
     *
     * @param array<array{title: string, quantity: int, unit_price: Money}> $items
     * @return array{subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    public static function calculateTotals(array $items, ?Money $discount = null): array
    {
        $subtotalCents = 0;

        foreach ($items as $item) {
            $subtotalCents += $item['unit_price']->multiply($item['quantity'])->inCents();
        }

        $totals = self::deriveTotalsFromCents($subtotalCents, $discount?->inCents() ?? 0);

        return [
            'subtotal' => Money::cents($totals['subtotal']),
            'discount' => Money::cents($totals['discount']),
            'tax' => Money::cents($totals['tax']),
            'total' => Money::cents($totals['total']),
        ];
    }

    /**
     * Calculate tax amount.
     */
    public static function calculateTax(Money $amount): Money
    {
        return $amount->multiply(self::TAX_RATE / 100);
    }

    /**
     * Derive the full subtotal -> discount -> tax -> total chain in cents.
     *
     * Single source for the 21% BTW derivation used by every quote write
     * path (storage representation is int cents; see audit H-5). The
     * discount is clamped to [0, subtotal] and tax applies to the
     * discounted subtotal; the taxable base never goes below zero. The
     * input subtotal is passed through unchanged — callers decide whether
     * to persist the clamped discount or keep their stored value.
     *
     * @return array{subtotal: int, discount: int, tax: int, total: int}
     */
    public static function deriveTotalsFromCents(int $subtotalCents, int $discountCents = 0): array
    {
        $discountCents = min(max(0, $discountCents), max(0, $subtotalCents));
        $taxableCents = max(0, $subtotalCents - $discountCents);
        $taxCents = self::calculateTax(Money::cents($taxableCents))->inCents();

        return [
            'subtotal' => $subtotalCents,
            'discount' => $discountCents,
            'tax' => $taxCents,
            'total' => $taxableCents + $taxCents,
        ];
    }

    /**
     * Calculate discount from voucher percentage.
     */
    public static function calculatePercentageDiscount(Money $subtotal, float $percentage): Money
    {
        if ($percentage <= 0 || $percentage > 100) {
            return Money::zero();
        }

        return $subtotal->multiply($percentage / 100);
    }

    /**
     * Format items for storage.
     *
     * @param array<array{title: string, quantity: int, unit_price: Money, type?: string}> $items
     * @return array<array{title: string, quantity: int, unit_price: int, total: int, type: string}>
     */
    public static function formatItemsForStorage(array $items): array
    {
        $formatted = [];

        foreach ($items as $item) {
            $unitPrice = $item['unit_price'];
            $quantity = $item['quantity'];
            $total = $unitPrice->multiply($quantity);

            $formatted[] = [
                'title' => $item['title'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice->inCents(),
                'total' => $total->inCents(),
                'type' => $item['type'] ?? 'edition',
            ];
        }

        return $formatted;
    }
}
