<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Trajectory status values.
 */
enum TrajectoryStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Archived = 'archived';

    /**
     * Check if enrollment is allowed.
     */
    public function allowsEnrollment(): bool
    {
        return $this === self::Open;
    }

    /**
     * Check if trajectory is active.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::InProgress], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Open => 'Open voor inschrijving',
            self::InProgress => 'Lopend',
            self::Closed => 'Gesloten',
            self::Archived => 'Gearchiveerd',
        };
    }
}
