<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for RegistrationRepository::queryForGrid.
 *
 * Covers the 6 assertions from the task brief:
 *  1. Structured filter (status + company_id) — only matching rows returned.
 *  2. Pagination — per_page/page correctly slices results + total is accurate.
 *  3. M4 — out-of-whitelist sort/group_by falls back to the default, not honored.
 *  4. M5 — JSON-column keys (enrollment_data/selections) in filters are ignored.
 *  5. Active-scope — terminal/past edition rows excluded by default scope.
 *  6. Sessionless carve-out (§10.7) — dateless edition interest row included in
 *     default active scope.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationGridQuery
 */
class RegistrationGridQueryTest extends IntegrationTestCase
{
    // Unique company ID so our fixtures never collide with seed data.
    private static int $companyId = 88881;

    // User IDs for different scenarios
    private static ?int $user1Id = null;
    private static ?int $user2Id = null;
    private static ?int $user3Id = null;

    // Edition IDs
    private static ?int $activeEditionId = null;   // Future/active dated edition
    private static ?int $pastEditionId = null;     // Past / terminal edition (> 2 days ago)
    private static ?int $datelessEditionId = null; // No start_date — interest-list anchor

    // Registration IDs to clean up
    private static array $regIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // --- Users ---
        $u1 = wp_create_user('grid_test_u1_' . time(), 'pass123', 'gtu1_' . time() . '@test.local');
        $u2 = wp_create_user('grid_test_u2_' . time(), 'pass123', 'gtu2_' . time() . '@test.local');
        $u3 = wp_create_user('grid_test_u3_' . time(), 'pass123', 'gtu3_' . time() . '@test.local');

        if (is_wp_error($u1) || is_wp_error($u2) || is_wp_error($u3)) {
            throw new \RuntimeException('Failed to create test users for RegistrationGridQueryTest');
        }

        self::$user1Id = (int) $u1;
        self::$user2Id = (int) $u2;
        self::$user3Id = (int) $u3;

        // --- Editions ---

        // Active dated edition: start_date = 30 days from now
        self::$activeEditionId = self::createEditionWithDates(
            'GridTest Active ' . time(),
            date('Y-m-d', strtotime('+30 days')),
            date('Y-m-d', strtotime('+31 days')),
            'open',
        );

        // Past/terminal edition: start_date = 60 days ago (well past 2-day grace)
        self::$pastEditionId = self::createEditionWithDates(
            'GridTest Past ' . time(),
            date('Y-m-d', strtotime('-60 days')),
            date('Y-m-d', strtotime('-59 days')),
            'completed',
        );

        // Dateless edition: NO start/end date meta → sessionless interest-list anchor
        self::$datelessEditionId = self::createEditionWithDates(
            'GridTest Dateless ' . time(),
            null,
            null,
            'open',
        );

        // --- Registrations ---
        $repo = ntdst_get(RegistrationRepository::class);

        // R1: user1, active edition, company=companyId, status=confirmed
        $r1 = $repo->create([
            'user_id'   => self::$user1Id,
            'edition_id' => self::$activeEditionId,
            'company_id' => self::$companyId,
            'status'    => RegistrationStatus::Confirmed->value,
        ]);
        self::assertValidRegId($r1, 'R1');
        self::$regIds[] = (int) $r1;

        // R2: user2, active edition, company=companyId, status=confirmed
        $r2 = $repo->create([
            'user_id'   => self::$user2Id,
            'edition_id' => self::$activeEditionId,
            'company_id' => self::$companyId,
            'status'    => RegistrationStatus::Confirmed->value,
        ]);
        self::assertValidRegId($r2, 'R2');
        self::$regIds[] = (int) $r2;

        // R3: user3, active edition, company=companyId, status=waitlist
        $r3 = $repo->create([
            'user_id'   => self::$user3Id,
            'edition_id' => self::$activeEditionId,
            'company_id' => self::$companyId,
            'status'    => RegistrationStatus::Waitlist->value,
        ]);
        self::assertValidRegId($r3, 'R3');
        self::$regIds[] = (int) $r3;

        // R4: user1, PAST edition, company=companyId, status=completed
        // This should be EXCLUDED from the default 'active' scope.
        $r4 = $repo->create([
            'user_id'   => self::$user1Id,
            'edition_id' => self::$pastEditionId,
            'company_id' => self::$companyId,
            'status'    => RegistrationStatus::Completed->value,
        ]);
        self::assertValidRegId($r4, 'R4');
        self::$regIds[] = (int) $r4;

        // R5: user2, DATELESS edition, company=companyId, status=interest
        // This must APPEAR in the default active scope (§10.7 sessionless carve-out).
        $r5 = $repo->create([
            'user_id'   => self::$user2Id,
            'edition_id' => self::$datelessEditionId,
            'company_id' => self::$companyId,
            'status'    => RegistrationStatus::Interest->value,
        ]);
        self::assertValidRegId($r5, 'R5');
        self::$regIds[] = (int) $r5;
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        foreach (self::$regIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }

        foreach ([self::$activeEditionId, self::$pastEditionId, self::$datelessEditionId] as $postId) {
            if ($postId) {
                wp_delete_post($postId, true);
            }
        }

        foreach ([self::$user1Id, self::$user2Id, self::$user3Id] as $uid) {
            if ($uid) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($uid);
            }
        }

        parent::tearDownAfterClass();
    }

    // =========================================================================
    // Assertion 1: Structured filter — status + company_id
    // =========================================================================

    /**
     * @test
     */
    public function structuredFilterByStatusAndCompany(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $result = $repo->queryForGrid([
            'status'     => RegistrationStatus::Confirmed->value,
            'company_id' => self::$companyId,
            'edition_scope' => 'all',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('total', $result);

        // Must only return confirmed rows for our company
        foreach ($result['rows'] as $row) {
            $this->assertEquals('confirmed', $row->status, 'Non-confirmed row leaked through status filter');
            $this->assertEquals(self::$companyId, (int) $row->company_id, 'Non-company row leaked through company filter');
        }

        // We created 2 confirmed rows for this company (R1, R2)
        $ourRows = array_filter($result['rows'], fn($r) => in_array((int) $r->edition_id, [self::$activeEditionId, self::$pastEditionId], true));
        $this->assertGreaterThanOrEqual(2, count($ourRows), 'Expected at least 2 confirmed rows for our company');
    }

    // =========================================================================
    // Assertion 2: Pagination
    // =========================================================================

    /**
     * @test
     */
    public function paginationSlicesResults(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // Fetch all for our company to know the true total
        $all = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        $total = (int) $all['total'];
        $this->assertGreaterThanOrEqual(5, $total, 'Expected at least 5 rows (R1–R5)');

        // Now fetch page 1 with per_page=2
        $page1 = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'per_page'      => 2,
            'page'          => 1,
        ]);

        $this->assertCount(2, $page1['rows'], 'Page 1 should return exactly 2 rows');
        $this->assertEquals($total, (int) $page1['total'], 'Total must remain the same regardless of page');
        $this->assertEquals(1, (int) $page1['page']);
        $this->assertEquals(2, (int) $page1['per_page']);

        // Page 2
        $page2 = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'per_page'      => 2,
            'page'          => 2,
        ]);

        $this->assertCount(2, $page2['rows'], 'Page 2 should return exactly 2 rows');

        // The two pages must contain distinct rows
        $p1Ids = array_map(fn($r) => (int) $r->id, $page1['rows']);
        $p2Ids = array_map(fn($r) => (int) $r->id, $page2['rows']);
        $this->assertEmpty(array_intersect($p1Ids, $p2Ids), 'Page 1 and page 2 must not share rows');
    }

    // =========================================================================
    // Assertion 3: M4 — out-of-whitelist sort / group_by rejected
    // =========================================================================

    /**
     * @test
     */
    public function m4OutOfWhitelistSortFallsToDefault(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // An out-of-whitelist sort value must NOT be honored — the query must
        // succeed (no DB error, no exception) and fall back to the default
        // sort (registered_at DESC). We verify it doesn't throw and returns rows.
        $resultBad = $repo->queryForGrid([
            'sort'          => '1;DROP TABLE wp_vad_registrations--',
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);

        $this->assertIsArray($resultBad);
        $this->assertArrayHasKey('rows', $resultBad);
        // The query must not blow up and must return our company rows
        $this->assertGreaterThanOrEqual(1, count($resultBad['rows']));

        // Also assert the result is the SAME as the default-sorted result —
        // both should return the same rows, proving the bad value was ignored.
        $resultDefault = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);

        $badIds = array_map(fn($r) => (int) $r->id, $resultBad['rows']);
        $defaultIds = array_map(fn($r) => (int) $r->id, $resultDefault['rows']);
        $this->assertEquals($defaultIds, $badIds, 'Bad sort should produce same result as default sort');
    }

    /**
     * @test
     */
    public function m4OutOfWhitelistGroupByRejected(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // Baseline: no group_by applied
        $baseline = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);

        // 'enrollment_data' is NOT in the group_by allowlist — must be rejected/ignored.
        // The result must be IDENTICAL to the no-group_by baseline (same row IDs,
        // same total), proving the bad value never reached SQL.
        $resultBad = $repo->queryForGrid([
            'group_by'      => 'enrollment_data',
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);

        $this->assertIsArray($resultBad);
        $this->assertArrayHasKey('rows', $resultBad);

        $baselineIds = array_map(fn($r) => (int) $r->id, $baseline['rows']);
        $badGroupIds = array_map(fn($r) => (int) $r->id, $resultBad['rows']);

        $this->assertEquals(
            $baselineIds,
            $badGroupIds,
            'Out-of-whitelist group_by must be silently ignored; result must match no-group_by baseline',
        );
        $this->assertEquals(
            (int) $baseline['total'],
            (int) $resultBad['total'],
            'Total count must be unchanged when group_by is rejected',
        );
    }

    // =========================================================================
    // Assertion 4: M5 — JSON-column keys in filters are ignored
    // =========================================================================

    /**
     * @test
     */
    public function m5JsonColumnFiltersAreIgnored(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // Passing enrollment_data / selections as filter keys must be silently
        // ignored — the result must be identical to the baseline without them.
        $baseline = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);

        $withJsonKeys = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'enrollment_data' => '{"interest":{"data":{"email":"x@x.com"}}}',
            'selections'      => '[1,2,3]',
            'completion_tasks' => '{"approval":{"status":"pending"}}',
        ]);

        $baselineIds = array_map(fn($r) => (int) $r->id, $baseline['rows']);
        $withJsonIds = array_map(fn($r) => (int) $r->id, $withJsonKeys['rows']);

        $this->assertEquals($baselineIds, $withJsonIds, 'JSON-column filter keys must not affect the query result');
        $this->assertEquals((int) $baseline['total'], (int) $withJsonKeys['total']);
    }

    // =========================================================================
    // Assertion 5: Active-scope — past edition excluded by default, included with 'all'
    // =========================================================================

    /**
     * @test
     */
    public function activeScopeExcludesPastEditionByDefault(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // Default scope='active' — R4 (past edition) must be EXCLUDED
        $activeScope = $repo->queryForGrid([
            'company_id' => self::$companyId,
        ]);

        $activeIds = array_map(fn($r) => (int) $r->id, $activeScope['rows']);
        $pastRegId = self::$regIds[3]; // R4 is index 3

        $this->assertNotContains(
            $pastRegId,
            $activeIds,
            'R4 (past edition, 60 days ago) must be excluded from the default active scope',
        );

        // With edition_scope='all', R4 MUST be present
        $allScope = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);

        $allIds = array_map(fn($r) => (int) $r->id, $allScope['rows']);
        $this->assertContains(
            $pastRegId,
            $allIds,
            'R4 (past edition) must be included when edition_scope=all',
        );
    }

    /**
     * @test
     */
    public function activeScopeBypassedWithExplicitEditionId(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // Passing an explicit edition_id bypasses the active-scope filter,
        // even when edition_scope is not 'all'.
        $result = $repo->queryForGrid([
            'company_id' => self::$companyId,
            'edition_id' => self::$pastEditionId,
        ]);

        $ids = array_map(fn($r) => (int) $r->id, $result['rows']);
        $pastRegId = self::$regIds[3]; // R4

        $this->assertContains(
            $pastRegId,
            $ids,
            'Explicit edition_id must bypass active scope and include the past edition row',
        );
    }

    // =========================================================================
    // Assertion 6: Sessionless carve-out (§10.7)
    // =========================================================================

    /**
     * @test
     */
    public function sessionlessDatelessEditionAppearsInDefaultActiveScope(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // Default scope (active) — R5 on the dateless edition MUST be present.
        $result = $repo->queryForGrid([
            'company_id' => self::$companyId,
        ]);

        $ids = array_map(fn($r) => (int) $r->id, $result['rows']);
        $datelessRegId = self::$regIds[4]; // R5

        $this->assertContains(
            $datelessRegId,
            $ids,
            'R5 (dateless edition, interest row) must appear in the default active scope (§10.7 sessionless carve-out)',
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a vad_edition post with optional start/end date postmeta.
     */
    private static function createEditionWithDates(
        string $title,
        ?string $startDate,
        ?string $endDate,
        string $status = 'open',
    ): int {
        $postId = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create edition: {$title}");
        }

        self::$testPosts[] = $postId;

        update_post_meta($postId, '_ntdst_status', $status);
        update_post_meta($postId, '_ntdst_capacity', 20);

        if ($startDate !== null) {
            update_post_meta($postId, '_ntdst_start_date', $startDate);
        }
        if ($endDate !== null) {
            update_post_meta($postId, '_ntdst_end_date', $endDate);
        }

        return (int) $postId;
    }

    /**
     * Assert a registration create result is a valid (non-error) int ID.
     */
    private static function assertValidRegId(mixed $result, string $label): void
    {
        if (is_wp_error($result)) {
            throw new \RuntimeException("Failed to create registration {$label}: " . $result->get_error_message());
        }
        if (!is_int($result) || $result <= 0) {
            throw new \RuntimeException("Invalid registration ID for {$label}: " . var_export($result, true));
        }
    }
}
