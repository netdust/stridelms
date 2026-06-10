<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * Bounding contract for audit H-8 (Task E2).
 *
 * GET /admin/pending-approvals must be paginated like the controller's other
 * list endpoints (page / per_page params; total / page / perPage / totalPages
 * response keys) while keeping the pre-existing top-level shape
 * (items / counts / stale_threshold_days) that admin-dashboard.js consumes.
 * `counts` stays GLOBAL (the dashboard tab pills), only `items` is paged.
 *
 * RED against the pre-rewrite implementation: no LIMIT, no pagination keys,
 * items unbounded.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PendingApprovalsBoundedTest
 */
final class PendingApprovalsBoundedTest extends IntegrationTestCase
{
    private \Stride\Admin\AdminAPIController $controller;

    private int $editionId;

    /** @var list<int> */
    private array $regIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = ntdst_get(\Stride\Admin\AdminAPIController::class);
        $this->editionId = $this->createTestEdition();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->regIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        $this->regIds = [];
        parent::tearDown();
    }

    private function insertApprovalRegistration(int $daysAgo): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => self::$testUserId,
            'edition_id' => $this->editionId,
            'status' => 'pending',
            'enrollment_path' => 'individual',
            'registered_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * DAY_IN_SECONDS),
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed'],
                'approval' => ['status' => 'pending'],
            ]),
        ]);

        $regId = (int) $wpdb->insert_id;
        $this->regIds[] = $regId;

        return $regId;
    }

    private function request(int $page, int $perPage): array
    {
        $req = new WP_REST_Request('GET', '/stride/v1/admin/pending-approvals');
        $req->set_param('stale_days', 7);
        $req->set_param('page', $page);
        $req->set_param('per_page', $perPage);

        return $this->controller->getPendingApprovals($req)->get_data();
    }

    /**
     * @test
     */
    public function itemsAreBoundedByPerPageWithSiblingShapedPagination(): void
    {
        // Over-seed: 7 qualifying approval rows against per_page=3.
        $seeded = [];
        for ($i = 0; $i < 7; $i++) {
            $seeded[] = $this->insertApprovalRegistration($i + 1);
        }

        $data = $this->request(1, 3);

        // Bounded: exactly per_page items on a > per_page queue.
        $this->assertCount(3, $data['items'], 'Page 1 must return exactly per_page items');

        // Sibling-shaped pagination keys (getEditions / getQuotes convention).
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('perPage', $data);
        $this->assertArrayHasKey('totalPages', $data);
        $this->assertGreaterThanOrEqual(7, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(3, $data['perPage']);
        $this->assertSame((int) ceil($data['total'] / 3), $data['totalPages']);

        // Pre-existing consumer shape preserved, counts stay GLOBAL.
        $this->assertArrayHasKey('counts', $data);
        $this->assertArrayHasKey('stale_threshold_days', $data);
        $this->assertGreaterThanOrEqual(7, $data['counts']['approval'], 'counts must reflect the whole queue, not the page');

        // Walking all pages yields every seeded row exactly once.
        $seen = [];
        for ($page = 1; $page <= $data['totalPages']; $page++) {
            foreach ($this->request($page, 3)['items'] as $item) {
                $this->assertArrayNotHasKey($item['id'], $seen, 'No item may appear on two pages');
                $seen[$item['id']] = true;
            }
        }
        foreach ($seeded as $regId) {
            $this->assertArrayHasKey($regId, $seen, "Seeded registration {$regId} must be reachable via pagination");
        }
    }

    /**
     * @test
     */
    public function outOfRangePageReturnsEmptyItemsButKeepsTotals(): void
    {
        $this->insertApprovalRegistration(1);

        $data = $this->request(9999, 3);

        $this->assertSame([], $data['items'], 'Out-of-range page must return no items');
        $this->assertGreaterThanOrEqual(1, $data['total']);
        $this->assertGreaterThanOrEqual(1, $data['counts']['approval']);
    }
}
