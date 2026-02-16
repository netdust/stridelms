<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Session type values.
 *
 * Determines how completion is tracked.
 */
enum SessionType: string
{
    case InPerson = 'in_person';
    case Webinar = 'webinar';
    case Online = 'online';
    case Assignment = 'assignment';

    /**
     * Check if session requires admin attendance marking.
     */
    public function requiresAttendanceMarking(): bool
    {
        return in_array($this, [self::InPerson, self::Webinar], true);
    }

    /**
     * Check if completion is tracked by LearnDash.
     */
    public function trackedByLMS(): bool
    {
        return in_array($this, [self::Online, self::Assignment], true);
    }

    /**
     * Check if session has a scheduled date/time.
     */
    public function isScheduled(): bool
    {
        return in_array($this, [self::InPerson, self::Webinar], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'Fysieke sessie',
            self::Webinar => 'Webinar',
            self::Online => 'Online module',
            self::Assignment => 'Opdracht',
        };
    }
}
