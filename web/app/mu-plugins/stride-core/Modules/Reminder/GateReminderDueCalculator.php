<?php

declare(strict_types=1);

namespace Stride\Modules\Reminder;

use DateTimeImmutable;

/**
 * Pure date-math: "which mail is due today for this gate phase?"
 *
 * No WordPress calls, no `time()`/`date()` inside — `$today` is the only
 * source of "now", injected by the caller (GateReminderService, Task 4.3).
 * This makes the class fully deterministic and unit-testable without a WP
 * bootstrap.
 *
 * Rules:
 *   1. No deadline (null/empty) -> null. There is no cadence without a
 *      deadline to count down to.
 *   2. Day-before-deadline takes PRECEDENCE over the reminder. Compute
 *      deadlineMinusOne = deadline - 1 day. If today >= deadlineMinusOne
 *      and the 'deadline' mail has not been marked sent, it is due.
 *      Interpretation note (ambiguity resolved per task instructions): this
 *      also covers catch-up — if the exact day-before was missed (e.g. the
 *      site/cron was down) and today is now anywhere on/after that date,
 *      including past the deadline itself, the day-before mail still fires
 *      ONCE as a final notice. It never fires twice because after it is
 *      sent, phaseState['deadline'] is marked and this branch stops firing.
 *   3. Otherwise, reminder: reminderDate = registeredAt + reminderDays. If
 *      reminderDate >= deadline, the reminder window has collapsed into the
 *      day-before mail and 'reminder' is never returned (the day-before
 *      covers it). Otherwise, if today >= reminderDate and the 'reminder'
 *      mail has not been marked sent, it is due (same catch-up semantics:
 *      fires once, however late).
 *   4. If nothing above is due -> null.
 */
final class GateReminderDueCalculator
{
    /**
     * @param array{reminder?: string|null, deadline?: string|null} $phaseState
     */
    public function dueMailFor(
        string $registeredAt,
        ?string $deadline,
        int $reminderDays,
        array $phaseState,
        string $today,
    ): ?string {
        if ($deadline === null || $deadline === '') {
            return null;
        }

        $deadlineDate = $this->normalizeToDate($deadline);
        $todayDate = $this->normalizeToDate($today);
        $registeredDate = $this->normalizeToDate($registeredAt);

        if ($deadlineDate === null || $todayDate === null || $registeredDate === null) {
            return null;
        }

        $deadlineMinusOne = $deadlineDate->modify('-1 day');
        $deadlineAlreadySent = ($phaseState['deadline'] ?? null) !== null;

        if ($todayDate >= $deadlineMinusOne && !$deadlineAlreadySent) {
            return 'deadline';
        }

        $reminderDate = $registeredDate->modify(sprintf('+%d day', $reminderDays));

        if ($reminderDate >= $deadlineDate) {
            // Reminder window has collapsed into the day-before mail.
            return null;
        }

        $reminderAlreadySent = ($phaseState['reminder'] ?? null) !== null;

        if ($todayDate >= $reminderDate && !$reminderAlreadySent) {
            return 'reminder';
        }

        return null;
    }

    /**
     * Normalize a date or datetime string to midnight of its calendar date,
     * so time-of-day components never affect comparisons.
     *
     * Deliberately does NOT round-trip through a `@timestamp` (Unix epoch,
     * always UTC) — doing so and then calling setTime(0, 0) re-interprets
     * the instant in the current default timezone, which can shift the
     * calendar date by a day whenever the local offset isn't UTC (e.g.
     * Europe/Brussels, UTC+1/+2). Parsing the string directly into a
     * DateTimeImmutable in the default timezone keeps the calendar date the
     * caller wrote.
     */
    private function normalizeToDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }

        return $date->setTime(0, 0);
    }
}
