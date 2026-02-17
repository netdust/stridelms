<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Trajectory enrollment modes.
 */
enum TrajectoryMode: string
{
    case Cohort = 'cohort';
    case SelfPaced = 'self_paced';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cohort => 'Cohort (vaste editie-reeks)',
            self::SelfPaced => 'Zelfgestuurd (eigen edities kiezen)',
        };
    }

    /**
     * Check if user must pick editions.
     */
    public function requiresEditionChoice(): bool
    {
        return $this === self::SelfPaced;
    }
}
