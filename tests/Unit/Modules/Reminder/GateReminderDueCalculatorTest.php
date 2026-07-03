<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Reminder;

use Stride\Modules\Reminder\GateReminderDueCalculator;
use Stride\Tests\TestCase;

/**
 * Contract tests for GateReminderDueCalculator::dueMailFor() — the pure
 * "which mail is due today for this phase?" date-math (Task 4.2).
 *
 * Rules pinned here (see class docblock for full rationale):
 *   1. No deadline -> always null (no cadence without a deadline).
 *   2. day-before-deadline (deadline - 1 day) takes PRECEDENCE over reminder.
 *      Catch-up: if today is on/after deadline-1 and not yet marked sent,
 *      it fires ONCE even if the exact day-before was missed (e.g. site down).
 *   3. reminder = registeredAt + reminderDays. If reminderDate >= deadline,
 *      the reminder window has collapsed into the day-before mail and
 *      'reminder' is never returned. Otherwise it fires (with catch-up,
 *      once) once today >= reminderDate and not yet marked sent.
 *   4. Anything already marked sent in $phaseState is never re-fired.
 */
final class GateReminderDueCalculatorTest extends TestCase
{
    private GateReminderDueCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new GateReminderDueCalculator();
    }

    public function testReturnsReminderWhenTodayIsReminderDateAndNothingSent(): void
    {
        // registered 2026-06-01 + 7 days = 2026-06-08 reminder date.
        // deadline 2026-07-01 -> deadline-1 = 2026-06-30, far in the future.
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '2026-07-01',
            7,
            ['reminder' => null, 'deadline' => null],
            '2026-06-08',
        );

        $this->assertSame('reminder', $result);
    }

    public function testReturnsDeadlineWhenTodayIsDayBeforeDeadlineAndNothingSent(): void
    {
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '2026-07-01',
            7,
            ['reminder' => null, 'deadline' => null],
            '2026-06-30', // deadline - 1
        );

        $this->assertSame('deadline', $result);
    }

    public function testDayBeforeWinsOnCollisionWithReminderDate(): void
    {
        // registered 2026-06-01 + 29 days = 2026-06-30 == deadline (2026-07-01) - 1.
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '2026-07-01',
            29,
            ['reminder' => null, 'deadline' => null],
            '2026-06-30',
        );

        $this->assertSame('deadline', $result);
    }

    public function testReminderAlreadySentAndDayBeforeNotYetDueReturnsNull(): void
    {
        // today == reminderDate, reminder already marked sent, deadline far away.
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '2026-07-01',
            7,
            ['reminder' => '2026-06-08', 'deadline' => null],
            '2026-06-08',
        );

        $this->assertNull($result);
    }

    public function testReminderNeverFiresWhenReminderDateCollapsesIntoDeadlineWindow(): void
    {
        // registered 2026-06-25 + 7 days = 2026-07-02, but deadline is 2026-07-01
        // so reminderDate (07-02) >= deadline (07-01) -> reminder window collapsed.
        // Before deadline-1 (2026-06-30), nothing should be due at all.
        $result = $this->calculator->dueMailFor(
            '2026-06-25',
            '2026-07-01',
            7,
            ['reminder' => null, 'deadline' => null],
            '2026-06-29',
        );

        $this->assertNull($result);
    }

    public function testNoDeadlineReturnsNull(): void
    {
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            null,
            7,
            ['reminder' => null, 'deadline' => null],
            '2026-06-08',
        );

        $this->assertNull($result);
    }

    public function testCatchUpFiresReminderOnceWhenTodayIsAfterReminderDate(): void
    {
        // reminder date was 2026-06-08 (missed — site down), today is 2026-06-12,
        // still well before deadline-1 (2026-06-30). Reminder not yet sent.
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '2026-07-01',
            7,
            ['reminder' => null, 'deadline' => null],
            '2026-06-12',
        );

        $this->assertSame('reminder', $result);
    }

    public function testDayBeforeAlreadySentReturnsNullOnDeadlineMinusOne(): void
    {
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '2026-07-01',
            7,
            ['reminder' => '2026-06-08', 'deadline' => '2026-06-30'],
            '2026-06-30',
        );

        $this->assertNull($result);
    }

    public function testCatchUpFiresDeadlineMailOnceWhenTodayIsPastDeadlineEntirely(): void
    {
        // Missed the day-before entirely (site down through the deadline).
        // A reasonable "fire once" rule: today far past deadline, not yet
        // marked sent -> still fires the day-before mail once, as a final
        // notice, rather than never notifying at all.
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '2026-07-01',
            7,
            ['reminder' => '2026-06-08', 'deadline' => null],
            '2026-07-05', // 4 days after the deadline itself
        );

        $this->assertSame('deadline', $result);
    }

    public function testEmptyStringDeadlineTreatedAsNoDeadline(): void
    {
        $result = $this->calculator->dueMailFor(
            '2026-06-01',
            '',
            7,
            ['reminder' => null, 'deadline' => null],
            '2026-06-08',
        );

        $this->assertNull($result);
    }

    public function testDatetimeRegisteredAtIsNormalizedToDate(): void
    {
        // registeredAt carries a time component (as it does in the DB) — the
        // calculator must normalize to the date before adding reminderDays.
        $result = $this->calculator->dueMailFor(
            '2026-06-01 14:32:07',
            '2026-07-01',
            7,
            ['reminder' => null, 'deadline' => null],
            '2026-06-08',
        );

        $this->assertSame('reminder', $result);
    }
}
