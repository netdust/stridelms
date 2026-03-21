<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Attendance status values.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Excused = 'excused';

    /**
     * Check if status counts as attended.
     */
    public function countsAsAttended(): bool
    {
        return $this === self::Present;
    }

    /**
     * Check if status indicates user was scheduled but not present.
     */
    public function wasMissed(): bool
    {
        return $this === self::Absent;
    }

    /**
     * Get SQL-safe quoted string of all statuses that count as attended.
     */
    public static function attendedValues(): string
    {
        $values = array_filter(self::cases(), fn(self $s) => $s->countsAsAttended());
        return implode(',', array_map(fn(self $s) => "'" . $s->value . "'", $values));
    }

    /**
     * Get human-readable label (Dutch).
     */
    public function label(): string
    {
        return match ($this) {
            self::Present => 'Aanwezig',
            self::Absent => 'Afwezig',
            self::Excused => 'Verontschuldigd',
        };
    }
}
