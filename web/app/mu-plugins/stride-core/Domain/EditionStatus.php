<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Edition status values.
 */
enum EditionStatus: string
{
    case Open = 'open';
    case Full = 'full';
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';
    case Announcement = 'announcement';
    case Completed = 'completed';

    /**
     * Check if enrollment is allowed.
     */
    public function allowsEnrollment(): bool
    {
        return $this === self::Open;
    }

    /**
     * Check if edition is active (not cancelled/completed).
     */
    public function isActive(): bool
    {
        return !in_array($this, [self::Cancelled, self::Completed], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open voor inschrijving',
            self::Full => 'Volzet',
            self::Cancelled => 'Geannuleerd',
            self::Postponed => 'Uitgesteld',
            self::Announcement => 'Aankondiging',
            self::Completed => 'Afgelopen',
        };
    }
}
