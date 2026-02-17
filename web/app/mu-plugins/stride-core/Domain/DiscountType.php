<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Discount type values.
 */
enum DiscountType: string
{
    case Full = 'full';           // 100% discount
    case Fixed = 'fixed';         // Fixed amount (e.g., €50 off)
    case Percentage = 'percentage'; // Percentage (e.g., 20% off)

    public function label(): string
    {
        return match ($this) {
            self::Full => '100% korting',
            self::Fixed => 'Vast bedrag',
            self::Percentage => 'Percentage',
        };
    }
}
