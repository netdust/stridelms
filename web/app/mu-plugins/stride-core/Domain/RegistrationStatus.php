<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Registration status values.
 */
enum RegistrationStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Waitlist = 'waitlist';
    case Interest = 'interest';

    /**
     * Check if registration counts toward capacity.
     */
    public function countsTowardCapacity(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Check if user has active access.
     */
    public function hasAccess(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Bevestigd',
            self::Cancelled => 'Geannuleerd',
            self::Waitlist => 'Wachtlijst',
            self::Interest => 'Interesse',
        };
    }
}
