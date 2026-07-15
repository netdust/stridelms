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

    /**
     * Check if registration counts toward capacity.
     */
    public function countsTowardCapacity(): bool
    {
        // Pending registrations reserve a spot, completed registrations still count
        return in_array($this, [self::Confirmed, self::Completed, self::Pending], true);
    }

    /**
     * SQL-ready list of the status values that hold a seat — derived from
     * countsTowardCapacity() so every occupancy counter (capacity melding,
     * EditionService::getRegisteredCount / hasAvailableSpots) reasons over
     * the SAME set. Two hand-rolled lists disagreed here once (F-V6:
     * confirmed-only vs confirmed+completed+pending).
     *
     * @return list<string>
     */
    public static function capacityValues(): array
    {
        return array_values(array_map(
            static fn(self $status): string => $status->value,
            array_filter(self::cases(), static fn(self $status): bool => $status->countsTowardCapacity()),
        ));
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
     * Pre-enrollment / reopenable states: a submission or enrollment for the
     * same (user, edition) APPENDS TO or REACTIVATES this row instead of
     * blocking as a duplicate. Single source of the vocabulary — the repo's
     * reactivate branch (create()), EnrollmentService::enroll()'s pre-check
     * and the public form upsert all ask this instead of hand-rolling the
     * list (DATA-MODEL-REGISTRATIONS.md §3: three hand-rolled lists is how
     * the pre-slice capacity bugs happened).
     */
    public function isReactivatable(): bool
    {
        return in_array($this, [self::Cancelled, self::Interest, self::Waitlist], true);
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
        };
    }
}
