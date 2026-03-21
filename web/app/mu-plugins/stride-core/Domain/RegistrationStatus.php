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
    case Pending = 'pending';
    case Withdrawn = 'withdrawn';

    /**
     * Check if registration counts toward capacity.
     */
    public function countsTowardCapacity(): bool
    {
        // Pending registrations reserve a spot, completed registrations still count
        return in_array($this, [self::Confirmed, self::Completed, self::Pending], true);
    }

    /**
     * Check if user has active access.
     */
    public function hasAccess(): bool
    {
        // Both confirmed and completed users have access to course content
        // Pending does NOT have access until admin confirms
        return in_array($this, [self::Confirmed, self::Completed], true);
    }

    /**
     * Check if registration blocks duplicate submissions.
     */
    public function blocksDuplicate(): bool
    {
        return in_array($this, [self::Confirmed, self::Completed, self::Pending, self::Interest], true);
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
            self::Pending => 'In afwachting',
            self::Withdrawn => 'Uitgetrokken',
        };
    }
}
