<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Registration status values.
 */
enum RegistrationStatus: string
{
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Waitlist = 'waitlist';
    case Interest = 'interest';

    /**
     * Check if registration counts toward capacity.
     */
    public function countsTowardCapacity(): bool
    {
        // Completed registrations still count - the spot was used
        return in_array($this, [self::Confirmed, self::Completed], true);
    }

    /**
     * Check if user has active access.
     */
    public function hasAccess(): bool
    {
        // Both confirmed and completed users have access to course content
        return in_array($this, [self::Confirmed, self::Completed], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Bevestigd',
            self::Completed => 'Afgerond',
            self::Cancelled => 'Geannuleerd',
            self::Waitlist => 'Wachtlijst',
            self::Interest => 'Interesse',
        };
    }
}
