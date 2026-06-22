<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;

/**
 * Single source of truth for valid registration lifecycle state transitions.
 *
 * Later consumers (grid bulk-bar, case-view action buttons, Phase-2 cohort roster)
 * derive their allowed actions from these methods — never from their own maps.
 *
 * The transition map (spec §2.1):
 *   Pending    → Confirmed
 *   Waitlist   → Confirmed
 *   Interest   → Pending, Cancelled
 *   Confirmed  → Cancelled
 *   Completed  → (terminal)
 *   Cancelled  → (terminal; re-enrol is a NEW registration)
 */
final class RegistrationTransitions
{
    /**
     * The one authoritative transition map. All public methods read from here.
     *
     * @return array<string, RegistrationStatus[]>
     */
    private static function map(): array
    {
        return [
            RegistrationStatus::Pending->value   => [RegistrationStatus::Confirmed],
            RegistrationStatus::Waitlist->value  => [RegistrationStatus::Confirmed],
            RegistrationStatus::Interest->value  => [RegistrationStatus::Pending, RegistrationStatus::Cancelled],
            RegistrationStatus::Confirmed->value => [RegistrationStatus::Cancelled],
            RegistrationStatus::Completed->value => [],
            RegistrationStatus::Cancelled->value => [],
        ];
    }

    /**
     * Returns the states $from may transition to. Empty array for terminal states.
     *
     * @return RegistrationStatus[]
     */
    public static function validFor(RegistrationStatus $from): array
    {
        return self::map()[$from->value];
    }

    /**
     * True iff $from → $to is a permitted transition.
     */
    public static function isAllowed(RegistrationStatus $from, RegistrationStatus $to): bool
    {
        return in_array($to, self::validFor($from), true);
    }

    /**
     * True iff $state has no outbound transitions (Completed, Cancelled).
     */
    public static function isTerminal(RegistrationStatus $state): bool
    {
        return self::validFor($state) === [];
    }
}
