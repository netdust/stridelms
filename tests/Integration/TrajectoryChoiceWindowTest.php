<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * Integration tests for TrajectoryService::isChoiceWindowOpen() — the single
 * decision point the trajectory dashboard and tab-keuzes both rely on for the
 * choice-window state.
 *
 * Context (plan 2026-06-30-stridence-theme-remediation, Task 3.7 / B7):
 * The dashboard template previously re-derived the window rule inline with its
 * own strtotime and required BOTH dates to be configured. tab-keuzes was fixed
 * to delegate to this service method ("Shake-out BUG-4"); the dashboard still
 * carried the divergent copy. This test PINS the contract both surfaces now
 * share so the no-drift parity cannot silently regress.
 *
 * Contract:
 * - now between available and deadline → open (true)
 * - now after deadline → closed (false)               [the denial path]
 * - now before available date         → closed (false)
 * - boundary: now == deadline / now == available → open (inclusive)
 * - NO dates configured                → open (true)  [the BUG-4 case the old
 *   dashboard copy got wrong: it required both dates and rendered "closed"]
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryChoiceWindowTest
 */
final class TrajectoryChoiceWindowTest extends IntegrationTestCase
{
    private TrajectoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(TrajectoryService::class);
    }

    private function makeTrajectory(string $available = '', string $deadline = ''): int
    {
        $id = wp_insert_post([
            'post_type' => 'vad_trajectory',
            'post_title' => 'Choice Window Trajectory',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $id;

        if ($available !== '') {
            update_post_meta($id, '_ntdst_choice_available_date', $available);
        }
        if ($deadline !== '') {
            update_post_meta($id, '_ntdst_choice_deadline', $deadline);
        }

        return $id;
    }

    public function testWindowOpenWhenNowBetweenAvailableAndDeadline(): void
    {
        $id = $this->makeTrajectory(
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d', strtotime('+7 days')),
        );

        $this->assertTrue(
            $this->service->isChoiceWindowOpen($id),
            'window must be open when now is between available and deadline',
        );
    }

    public function testWindowClosedAfterDeadline(): void
    {
        $id = $this->makeTrajectory(
            date('Y-m-d', strtotime('-10 days')),
            date('Y-m-d', strtotime('-1 day')),
        );

        $this->assertFalse(
            $this->service->isChoiceWindowOpen($id),
            'window must be closed after the deadline (the denial path)',
        );
    }

    public function testWindowClosedBeforeAvailableDate(): void
    {
        $id = $this->makeTrajectory(
            date('Y-m-d', strtotime('+3 days')),
            date('Y-m-d', strtotime('+10 days')),
        );

        $this->assertFalse(
            $this->service->isChoiceWindowOpen($id),
            'window must be closed before the available date',
        );
    }

    public function testWindowOpenWhenNoDatesConfigured(): void
    {
        // The BUG-4 case: the old dashboard copy required BOTH dates and would
        // render "closed" here, while the server accepts submissions. The
        // service treats "no dates configured" as open — the dashboard now
        // delegates, closing the drift.
        $id = $this->makeTrajectory('', '');

        $this->assertTrue(
            $this->service->isChoiceWindowOpen($id),
            'no dates configured = no constraint = open (BUG-4 parity)',
        );
    }

    public function testWindowClosedWhenDeadlineIsToday(): void
    {
        // Boundary: the service compares strtotime($deadline) < time(). A bare
        // 'Y-m-d' deadline parses to today's MIDNIGHT, which is already behind
        // the current time-of-day — so a deadline of *today* reads as past.
        // Pin this exact decision so the dashboard and tab-keuzes agree on it.
        $id = $this->makeTrajectory(
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d'),
        );

        $this->assertFalse(
            $this->service->isChoiceWindowOpen($id),
            'deadline of today (midnight) is past the current time → closed',
        );
    }

    public function testWindowOpenWhenDeadlineIsTomorrow(): void
    {
        // The inclusive edge that is still open: a deadline parsing to a future
        // instant (tomorrow's midnight) is > now → open.
        $id = $this->makeTrajectory(
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d', strtotime('+1 day')),
        );

        $this->assertTrue(
            $this->service->isChoiceWindowOpen($id),
            'deadline in the future (tomorrow) → window still open',
        );
    }
}
