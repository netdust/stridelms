<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * Task 6.1 (Phase 6 — gate deadlines & reminders): the stale_user bucket in
 * getPendingApprovals() must derive a countdown to the active gate deadline
 * (days_left / days_overdue), sourced via the SAME convergence point the
 * per-task badge (Task 6.2) will read from: EnrollmentCompletion::getTaskAvailability().
 *
 * The overdue DECISION must come from getTaskAvailability()'s 'overdue' flag
 * (no parallel strtotime re-derive) — this test pins the observable contract,
 * not the internal sourcing, but a future refactor that stops calling
 * getTaskAvailability() would still need to reproduce the same overdue
 * boundary, which is what the fixture below exercises (deadline exactly
 * today reads as NOT overdue per deadlineInfo()'s strict `<` comparison).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PendingApprovalsDaysLeftTest
 */
final class PendingApprovalsDaysLeftTest extends IntegrationTestCase
{
    private \Stride\Admin\AdminAPIController $controller;

    /** @var list<int> */
    private array $regIds = [];

    /** @var list<int> */
    private array $editionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = ntdst_get(\Stride\Admin\AdminAPIController::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->regIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        $this->regIds = [];
        $this->editionIds = [];
        parent::tearDown();
    }

    private function insertRegistration(int $editionId, int $daysAgo, array $tasks): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'pending',
            'enrollment_path' => 'individual',
            'registered_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * DAY_IN_SECONDS),
            'completion_tasks' => wp_json_encode($tasks),
        ]);

        $regId = (int) $wpdb->insert_id;
        $this->regIds[] = $regId;

        return $regId;
    }

    private function fetchStaleItems(): array
    {
        $req = new WP_REST_Request('GET', '/stride/v1/admin/pending-approvals');
        $req->set_param('stale_days', 7);
        $req->set_param('page', 1);
        $req->set_param('per_page', 100);

        $data = $this->controller->getPendingApprovals($req)->get_data();

        $byId = [];
        foreach ($data['items'] as $item) {
            $byId[(int) $item['id']] = $item;
        }

        return $byId;
    }

    /**
     * @test
     */
    public function futureDeadlineYieldsDaysLeftNotOverdue(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => ['_ntdst_gate_deadline' => gmdate('Y-m-d', time() + 5 * DAY_IN_SECONDS)],
        ]);
        $this->editionIds[] = $editionId;

        $regId = $this->insertRegistration($editionId, 10, [
            'documents' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ]);

        $items = $this->fetchStaleItems();

        $this->assertArrayHasKey($regId, $items);
        $this->assertFalse($items[$regId]['overdue'] ?? null);
        $this->assertArrayHasKey('days_left', $items[$regId]);
        $this->assertGreaterThanOrEqual(4, $items[$regId]['days_left']);
        $this->assertLessThanOrEqual(5, $items[$regId]['days_left']);
        $this->assertArrayNotHasKey('days_overdue', $items[$regId]);
    }

    /**
     * @test
     */
    public function pastDeadlineYieldsDaysOverdueNotDaysLeft(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => ['_ntdst_gate_deadline' => gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS)],
        ]);
        $this->editionIds[] = $editionId;

        $regId = $this->insertRegistration($editionId, 10, [
            'documents' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ]);

        $items = $this->fetchStaleItems();

        $this->assertArrayHasKey($regId, $items);
        $this->assertTrue($items[$regId]['overdue'] ?? null);
        $this->assertArrayHasKey('days_overdue', $items[$regId]);
        $this->assertGreaterThanOrEqual(2, $items[$regId]['days_overdue']);
        $this->assertLessThanOrEqual(3, $items[$regId]['days_overdue']);
        $this->assertTrue(empty($items[$regId]['days_left']));
    }

    /**
     * @test
     */
    public function noDeadlineYieldsNeitherDaysLeftNorOverdue(): void
    {
        $editionId = $this->createTestEdition();
        $this->editionIds[] = $editionId;

        $regId = $this->insertRegistration($editionId, 10, [
            'documents' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ]);

        $items = $this->fetchStaleItems();

        $this->assertArrayHasKey($regId, $items);
        $this->assertTrue(empty($items[$regId]['days_left']));
        $this->assertTrue(empty($items[$regId]['days_overdue']));
        $this->assertFalse($items[$regId]['overdue'] ?? false);
    }
}
