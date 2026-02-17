<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Voucher status values.
 */
enum VoucherStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Disabled = 'disabled';

    /**
     * Check if voucher can be used.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actief',
            self::Exhausted => 'Uitgeput',
            self::Expired => 'Verlopen',
            self::Disabled => 'Uitgeschakeld',
        };
    }
}
