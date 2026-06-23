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
 *   Pending    → Confirmed, Cancelled
 *   Waitlist   → Confirmed, Cancelled
 *   Interest   → Pending, Cancelled
 *   Confirmed  → Cancelled
 *   Completed  → (terminal)
 *   Cancelled  → (terminal; re-enrol is a NEW registration)
 *
 * Cancellation is available from EVERY non-terminal state — the map mirrors the
 * actual server authority (EnrollmentService::cancel() rejects ONLY Completed
 * and Cancelled). The map previously omitted Pending→Cancelled and
 * Waitlist→Cancelled, so the client bulk-bar (which derives from this map)
 * disagreed with what the server would actually do (CR-5). The map is now the
 * single truthful source the JS bootstrap (StrideConfig.transitions) validates
 * against.
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
            RegistrationStatus::Pending->value   => [RegistrationStatus::Confirmed, RegistrationStatus::Cancelled],
            RegistrationStatus::Waitlist->value  => [RegistrationStatus::Confirmed, RegistrationStatus::Cancelled],
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

    /**
     * Serializable view of the authoritative map (status value => list of
     * permitted target status values), for the admin JS bootstrap
     * (StrideConfig.transitions). The client validates its bulk-bar lifecycle
     * actions against THIS instead of silently embedding a hand-copied map
     * (CR-5) — drift between the PHP source and the JS surfaces as a console
     * warning at load instead of a wrong button weeks later.
     *
     * @return array<string, list<string>>
     */
    public static function toArray(): array
    {
        $out = [];
        foreach (self::map() as $from => $targets) {
            $out[$from] = array_map(static fn(RegistrationStatus $s): string => $s->value, $targets);
        }

        return $out;
    }
}
