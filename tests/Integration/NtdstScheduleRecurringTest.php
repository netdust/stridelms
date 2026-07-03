<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Pins the ntdst_schedule_recurring() / ntdst_clear_recurring() seam (INV-10,
 * Task 4.1). This is the reusable recurring-cron primitive every future cron
 * job (gate reminders, mail-broadcast) must register through instead of
 * hand-rolling wp_schedule_event.
 *
 * Mitigation 7 note: the seam passes NO request data to the callback — it is
 * invoked by WP-Cron with no superglobals in scope. There is nothing to
 * assert in code for this (no $_GET/$_POST/$_REQUEST is ever threaded
 * through add_action($hook, $cb) here); it is a structural property of how
 * WP-Cron invokes hooks, verified by inspection of the two functions below.
 */
final class NtdstScheduleRecurringTest extends IntegrationTestCase
{
    private const HOOK = 'stride_test_recurring_hook';

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        remove_all_actions(self::HOOK);

        parent::tearDown();
    }

    public function test_schedule_recurring_sets_next_run_and_binds_callback(): void
    {
        $called = false;
        $cb = function () use (&$called): void {
            $called = true;
        };

        ntdst_schedule_recurring(self::HOOK, 'daily', $cb);

        $this->assertNotFalse(
            wp_next_scheduled(self::HOOK),
            'Expected wp_next_scheduled() to report a scheduled run after ntdst_schedule_recurring().',
        );
        $this->assertTrue(
            (bool) has_action(self::HOOK),
            'Expected the callback to be bound to the hook via add_action().',
        );

        // Prove the bound callback is actually the one we passed in.
        do_action(self::HOOK);
        $this->assertTrue($called, 'Expected the registered callback to fire when the hook runs.');
    }

    public function test_schedule_recurring_called_twice_does_not_double_schedule(): void
    {
        $cb = static function (): void {};

        ntdst_schedule_recurring(self::HOOK, 'daily', $cb);
        $firstRun = wp_next_scheduled(self::HOOK);

        ntdst_schedule_recurring(self::HOOK, 'daily', $cb);
        $secondRun = wp_next_scheduled(self::HOOK);

        $this->assertNotFalse($firstRun, 'Expected a scheduled run after the first call.');
        $this->assertSame(
            $firstRun,
            $secondRun,
            'Expected the self-healing guard to skip re-scheduling on a second call (still exactly one event).',
        );
    }

    public function test_clear_recurring_removes_the_scheduled_event(): void
    {
        $cb = static function (): void {};

        ntdst_schedule_recurring(self::HOOK, 'daily', $cb);
        $this->assertNotFalse(wp_next_scheduled(self::HOOK), 'Precondition: hook should be scheduled.');

        ntdst_clear_recurring(self::HOOK);

        $this->assertFalse(
            wp_next_scheduled(self::HOOK),
            'Expected wp_next_scheduled() to report false after ntdst_clear_recurring().',
        );
    }
}
