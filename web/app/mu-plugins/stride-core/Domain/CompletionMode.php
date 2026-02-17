<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Edition completion mode values.
 *
 * Determines how attendance is evaluated for completion.
 */
enum CompletionMode: string
{
    case AttendAll = 'attend_all';
    case Percentage = 'percentage';
    case Count = 'count';

    /**
     * Get human-readable label (Dutch).
     */
    public function label(): string
    {
        return match ($this) {
            self::AttendAll => 'Alle sessies bijwonen',
            self::Percentage => 'Percentage sessies bijwonen',
            self::Count => 'Minimum aantal sessies bijwonen',
        };
    }

    /**
     * Get description (Dutch).
     */
    public function description(): string
    {
        return match ($this) {
            self::AttendAll => 'Deelnemer moet alle sessies bijwonen',
            self::Percentage => 'Deelnemer moet een percentage van de sessies bijwonen',
            self::Count => 'Deelnemer moet een minimum aantal sessies bijwonen',
        };
    }
}
