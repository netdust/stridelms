<?php

namespace ntdst\Stride\invoicing\Helpers;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\Support\QuoteConfig;

/**
 * Quote Calculator
 *
 * Pure calculation helper for quotes - no external dependencies.
 * Handles all price, tax, and discount calculations.
 *
 * This is a stateless helper class with no WordPress hooks.
 * All methods are pure functions operating on input data.
 *
 * @package stride\services\invoicing\Helpers
 */
class QuoteCalculator
{
    /**
     * Calculate totals from items array
     *
     * @param array $items Quote items
     * @param float|null $taxRate Tax rate percentage (null = use config)
     * @return array{subtotal: float, discount: float, tax: float, total: float}
     */
    public static function calculateTotals(array $items, ?float $taxRate = null): array
    {
        $taxRate = $taxRate ?? QuoteConfig::getTaxRate();

        $subtotal = 0.0;
        $discount = 0.0;

        foreach ($items as $item) {
            $itemTotal = (float) ($item['total'] ?? 0);

            if (($item['type'] ?? '') === 'discount') {
                $discount += abs($itemTotal);
            } else {
                $subtotal += $itemTotal;
            }
        }

        $discountedSubtotal = max(0, $subtotal - $discount);
        $tax = round($discountedSubtotal * ($taxRate / 100), 2);
        $total = $discountedSubtotal + $tax;

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    /**
     * Calculate totals from raw item input (admin form)
     *
     * @param array $itemsData Raw item data from form
     * @param float|null $taxRate Tax rate percentage (null = use config)
     * @return array{items: array, subtotal: float, discount: float, tax: float, total: float}
     */
    public static function processItemsInput(array $itemsData, ?float $taxRate = null): array
    {
        $taxRate = $taxRate ?? QuoteConfig::getTaxRate();

        $items = [];
        $subtotal = 0.0;

        foreach ($itemsData as $itemData) {
            // Skip empty rows
            if (empty($itemData['title']) && empty($itemData['unit_price'])) {
                continue;
            }

            $quantity = max(1, (int) ($itemData['quantity'] ?? 1));
            $unitPrice = (float) ($itemData['unit_price'] ?? 0);
            $itemTotal = $quantity * $unitPrice;
            $type = sanitize_text_field($itemData['type'] ?? 'course');

            $items[] = [
                'id' => (int) ($itemData['id'] ?? 0),
                'type' => $type,
                'title' => sanitize_text_field($itemData['title'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $itemTotal,
            ];

            $subtotal += $itemTotal;
        }

        // Calculate discount from items
        $discount = 0.0;
        foreach ($items as $item) {
            if ($item['type'] === 'discount') {
                $discount += abs($item['total']);
            }
        }

        // Calculate tax and total
        $subtotalBeforeDiscount = $subtotal + $discount;
        $discountedSubtotal = max(0, $subtotal);
        $tax = round($discountedSubtotal * ($taxRate / 100), 2);
        $total = $discountedSubtotal + $tax;

        return [
            'items' => $items,
            'subtotal' => $subtotalBeforeDiscount,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    /**
     * Recalculate totals after applying discount
     *
     * @param float $subtotal Original subtotal (before discount)
     * @param float $discount Discount amount
     * @param float|null $taxRate Tax rate percentage (null = use config)
     * @return array{tax: float, total: float}
     */
    public static function recalculateWithDiscount(float $subtotal, float $discount, ?float $taxRate = null): array
    {
        $taxRate = $taxRate ?? QuoteConfig::getTaxRate();

        $discountedSubtotal = max(0, $subtotal - $discount);
        $tax = round($discountedSubtotal * ($taxRate / 100), 2);
        $total = $discountedSubtotal + $tax;

        return [
            'tax' => $tax,
            'total' => $total,
        ];
    }

    /**
     * Add discount item to items array and recalculate
     *
     * @param array $items Current items
     * @param float $subtotal Subtotal before discount
     * @param float $discountAmount Discount amount
     * @param string $label Discount label
     * @param float|null $taxRate Tax rate percentage (null = use config)
     * @return array{items: array, discount: float, tax: float, total: float}
     */
    public static function applyDiscount(
        array $items,
        float $subtotal,
        float $discountAmount,
        string $label,
        ?float $taxRate = null
    ): array {
        // Remove existing discount items
        $items = array_filter($items, fn($item) => ($item['type'] ?? '') !== 'discount');
        $items = array_values($items);

        // Cap discount at subtotal
        $discountAmount = min($discountAmount, $subtotal);

        // Add new discount item
        $items[] = QuoteItemFactory::createDiscount($discountAmount, $label);

        // Recalculate
        $recalc = self::recalculateWithDiscount($subtotal, $discountAmount, $taxRate);

        return [
            'items' => $items,
            'discount' => $discountAmount,
            'tax' => $recalc['tax'],
            'total' => $recalc['total'],
        ];
    }

    /**
     * Remove all discounts and recalculate
     *
     * @param array $items Current items
     * @param float $subtotal Subtotal before discount
     * @param float|null $taxRate Tax rate percentage (null = use config)
     * @return array{items: array, discount: float, tax: float, total: float}
     */
    public static function removeDiscounts(array $items, float $subtotal, ?float $taxRate = null): array
    {
        // Remove discount items
        $items = array_filter($items, fn($item) => ($item['type'] ?? '') !== 'discount');
        $items = array_values($items);

        // Recalculate without discount
        $recalc = self::recalculateWithDiscount($subtotal, 0, $taxRate);

        return [
            'items' => $items,
            'discount' => 0.0,
            'tax' => $recalc['tax'],
            'total' => $recalc['total'],
        ];
    }

    /**
     * Validate voucher and return discount details via filter
     *
     * Uses the stride/quote/calculate_discount filter for actual calculation.
     *
     * @param string $voucherCode Voucher code
     * @param string $itemType Item type
     * @param int $itemId Item ID
     * @param float $subtotal Quote subtotal
     * @return array{valid: bool, discount: float, error?: string}
     */
    public static function validateAndCalculateVoucher(
        string $voucherCode,
        string $itemType,
        int $itemId,
        float $subtotal
    ): array {
        // Get price via filter
        $itemPrice = (float) apply_filters('stride/quote/resolve_price', $subtotal, $itemType, $itemId);

        // Calculate discount via filter
        $discount = (float) apply_filters(
            'stride/quote/calculate_discount',
            0.0,
            $voucherCode,
            $itemType,
            $itemId,
            $itemPrice
        );

        if ($discount <= 0) {
            return [
                'valid' => false,
                'discount' => 0.0,
                'error' => __('Vouchercode ongeldig of verlopen.', 'stride'),
            ];
        }

        return [
            'valid' => true,
            'discount' => min($discount, $subtotal),
        ];
    }

    /**
     * Get tax rate from config
     *
     * @return float Tax rate percentage
     */
    public static function getTaxRate(): float
    {
        return QuoteConfig::getTaxRate();
    }
}
