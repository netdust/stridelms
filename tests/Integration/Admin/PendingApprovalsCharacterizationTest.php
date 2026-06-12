<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * PERMANENT regression pin for the pending-approvals bucketing (audit H-8 /
 * Task E2). Originally the pre-rewrite characterization harness; the
 * bounded/column-trimmed rewrite (and the INV-3 move of the scan SQL into
 * RegistrationRepository) are done — this suite now guards against any
 * re-fork or drift of the bucket semantics. Every row in the matrix pins a
 * branch of the bucketing logic, including the edges the SQL pre-filter
 * must keep preserving (SQL may only ever OVER-fetch; PHP re-checks are
 * authoritative):
 *
 *  - approval present but status key missing  → defaults to 'pending' (R9)
 *  - user tasks done, NO approval key, stale-aged → emits NOTHING (R10 — the
 *    row the rewritten WHERE disjunction may legitimately stop fetching)
 *  - confirmed row whose JSON merely CONTAINS the substring 'post_approval'
 *    in a value, not as a task key → emits NOTHING (R12 — pins the
 *    LIKE '%post_approval%' → JSON_EXTRACT equivalence on emitted output)
 *  - post-course tasks absent count as done (R11)
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PendingApprovalsCharacterizationTest
 */
final class PendingApprovalsCharacterizationTest extends IntegrationTestCase
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

    private function insertRegistration(string $status, int $daysAgo, array|string $tasks): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => self::$testUserId,
            'edition_id' => $this->editionId,
            'status' => $status,
            'enrollment_path' => 'individual',
            'registered_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * DAY_IN_SECONDS),
            'completion_tasks' => is_string($tasks) ? $tasks : wp_json_encode($tasks),
        ]);

        $regId = (int) $wpdb->insert_id;
        $this->regIds[] = $regId;

        return $regId;
    }

    /**
     * Fetch ALL items the endpoint reports, following pagination if the
     * response advertises it (pre-rewrite: single unpaginated response).
     *
     * @return array{items: array<int, array>, counts: array, data: array}
     */
    private function fetchAll(int $staleDays = 7): array
    {
        $itemsById = [];
        $page = 1;
        $data = [];

        do {
            $req = new WP_REST_Request('GET', '/stride/v1/admin/pending-approvals');
            $req->set_param('stale_days', $staleDays);
            $req->set_param('page', $page);
            $req->set_param('per_page', 100);

            $data = $this->controller->getPendingApprovals($req)->get_data();
            foreach ($data['items'] as $item) {
                $itemsById[(int) $item['id']] = $item;
            }

            $totalPages = (int) ($data['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return ['items' => $itemsById, 'counts' => $data['counts'], 'data' => $data];
    }

    /**
     * @test
     */
    public function bucketMatrixIsStable(): void
    {
        // -- Bucket 1: approval (pending phase, user done, admin owes action)
        $r1 = $this->insertRegistration('pending', 1, [
            'documents' => ['status' => 'completed'],
            'approval' => ['status' => 'pending'],
        ]);
        // approval present but status key MISSING → treated as pending
        $r9 = $this->insertRegistration('pending', 1, [
            'documents' => ['status' => 'completed'],
            'approval' => ['note' => 'no status key'],
        ]);

        // -- Bucket 3: stale_user (pending, user NOT done, idle ≥ threshold)
        $r3 = $this->insertRegistration('pending', 10, [
            'session_selection' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ]);

        // -- Bucket 2: post_approval (confirmed, post user tasks done)
        $r5 = $this->insertRegistration('confirmed', 10, [
            'post_evaluation' => ['status' => 'completed'],
            'post_documents' => ['status' => 'completed'],
            'post_approval' => ['status' => 'pending'],
        ]);
        // absent post-course user tasks count as done
        $r11 = $this->insertRegistration('confirmed', 10, [
            'post_approval' => ['status' => 'pending'],
        ]);

        // -- Must NOT appear, each pinning one exclusion branch
        $r2 = $this->insertRegistration('pending', 10, [
            'documents' => ['status' => 'completed'],
            'approval' => ['status' => 'completed'],
        ]); // user done + approval completed → nothing (and never stale)
        $r4 = $this->insertRegistration('pending', 2, [
            'session_selection' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ]); // user not done but too recent → nothing
        $r6 = $this->insertRegistration('confirmed', 10, [
            'post_evaluation' => ['status' => 'pending'],
            'post_approval' => ['status' => 'pending'],
        ]); // post user task open → nothing
        $r7 = $this->insertRegistration('confirmed', 10, [
            'post_evaluation' => ['status' => 'completed'],
            'post_approval' => ['status' => 'completed'],
        ]); // post_approval already signed off → nothing
        $r8 = $this->insertRegistration('confirmed', 10, [
            'documents' => ['status' => 'completed'],
        ]); // no post_approval task at all → nothing
        $r10 = $this->insertRegistration('pending', 10, [
            'documents' => ['status' => 'completed'],
        ]); // user done, NO approval key, stale-aged → nothing
        $r12 = $this->insertRegistration('confirmed', 10, [
            'documents' => ['status' => 'completed', 'data' => ['note' => 'post_approval']],
        ]); // 'post_approval' as substring in a VALUE, not a key → nothing

        $result = $this->fetchAll(7);
        $items = $result['items'];

        // Included rows, with their exact bucket type
        foreach ([$r1 => 'approval', $r9 => 'approval', $r3 => 'stale_user', $r5 => 'post_approval', $r11 => 'post_approval'] as $regId => $expectedType) {
            $this->assertArrayHasKey($regId, $items, "Registration {$regId} (expected bucket '{$expectedType}') must be returned");
            $this->assertSame($expectedType, $items[$regId]['type'], "Registration {$regId} must land in bucket '{$expectedType}'");
        }

        // Stale item carries the open-task fields
        $this->assertSame('session_selection', $items[$r3]['open_task']);
        $this->assertNotEmpty($items[$r3]['open_task_label']);
        $this->assertGreaterThanOrEqual(10, $items[$r3]['days_idle']);

        // Item shape consumed by admin-dashboard.js / dashboard.php
        foreach (['id', 'type', 'user_id', 'user_name', 'user_email', 'edition_id', 'edition_title', 'registered_at', 'tasks'] as $key) {
            $this->assertArrayHasKey($key, $items[$r1], "Approval item must expose '{$key}'");
        }
        $this->assertSame(self::$testUserId, $items[$r1]['user_id']);
        $this->assertSame($this->editionId, $items[$r1]['edition_id']);

        // Excluded rows
        foreach ([$r2, $r4, $r6, $r7, $r8, $r10, $r12] as $regId) {
            $this->assertArrayNotHasKey($regId, $items, "Registration {$regId} must NOT appear in any bucket");
        }

        // Counts cover (at least) the seeded rows; dev DB may hold more.
        $counts = $result['counts'];
        $this->assertGreaterThanOrEqual(2, $counts['approval']);
        $this->assertGreaterThanOrEqual(1, $counts['stale_user']);
        $this->assertGreaterThanOrEqual(1, $counts['post_approval']);
        $this->assertSame(7, $result['data']['stale_threshold_days']);
    }

    /**
     * @test
     */
    public function staleDaysParamMovesTheStaleBoundary(): void
    {
        $regId = $this->insertRegistration('pending', 3, [
            'documents' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ]);

        $this->assertArrayNotHasKey($regId, $this->fetchAll(7)['items'], '3-day-old pending must not be stale at threshold 7');

        $items = $this->fetchAll(1)['items'];
        $this->assertArrayHasKey($regId, $items, '3-day-old pending must be stale at threshold 1');
        $this->assertSame('stale_user', $items[$regId]['type']);
    }
}
