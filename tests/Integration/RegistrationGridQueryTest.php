<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminRegistrationQueryService;
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

    // --- Task 1.4b: trajectory grid-filter fixtures ---
    private static ?int $trajT1Id = null;          // Trajectory T1 (id value)
    private static ?int $trajT2Id = null;          // Trajectory T2 (the leak-check foil)
    private static ?int $trajChildEditionA = null; // edition for a T1 cascade child
    private static ?int $trajChildEditionB = null; // edition for another T1 cascade child
    private static ?int $trajLegacyEdition = null; // edition for the T1 legacy (pre-cascade) child
    private static ?int $trajT2ChildEdition = null;// edition for a T2 cascade child
    private static ?int $t1ParentRegId = null;
    private static ?int $t1ChildARegId = null;     // confirmed cascade child (via parent link)
    private static ?int $t1ChildBRegId = null;     // waitlist cascade child (via parent link)
    private static ?int $t1LegacyChildRegId = null;// legacy child: trajectory_id=T1, no parent link
    private static ?int $t2ParentRegId = null;
    private static ?int $t2ChildRegId = null;      // T2's cascade child (must never leak into T1)
    private static ?int $trajPlainRegId = null;    // plain non-trajectory edition reg

    // Distinct users for the trajectory fixtures (avoid user+edition dedup).
    private static ?int $trajUser1Id = null;
    private static ?int $trajUser2Id = null;
    private static ?int $trajUser3Id = null;

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

        // --- Task 1.4b: trajectory grid-filter fixtures ---
        self::seedTrajectoryFixtures($repo);
    }

    /**
     * Seed the trajectory parent/child corpus for the grid trajectory_id filter:
     *  - T1 parent (edition_id NULL) + 2 cascade children (parent-linked) + 1
     *    legacy pre-cascade child (trajectory_id=T1, no parent link).
     *  - T2 parent + 1 cascade child (the leak-check foil — must NEVER appear in
     *    a T1-filtered result).
     *  - 1 plain non-trajectory edition reg (must also never appear under T1).
     *
     * Editions are dated active (future) so they survive even the default scope;
     * the assertions still pass edition_scope=all so the join is what's under test.
     * Distinct users per child avoid create()'s user+edition dedup.
     */
    private static function seedTrajectoryFixtures(RegistrationRepository $repo): void
    {
        $futureStart = date('Y-m-d', strtotime('+30 days'));
        $futureEnd   = date('Y-m-d', strtotime('+31 days'));

        // Trajectory "ids" — we only need integer values distinct from each other
        // and from the child editions; they need not be real CPT posts for the
        // structured-FK join under test.
        self::$trajT1Id = 770001;
        self::$trajT2Id = 770002;

        self::$trajChildEditionA  = self::createEditionWithDates('T14b ChildA ' . time(), $futureStart, $futureEnd, 'open');
        self::$trajChildEditionB  = self::createEditionWithDates('T14b ChildB ' . time(), $futureStart, $futureEnd, 'open');
        self::$trajLegacyEdition  = self::createEditionWithDates('T14b Legacy ' . time(), $futureStart, $futureEnd, 'open');
        self::$trajT2ChildEdition = self::createEditionWithDates('T14b T2Child ' . time(), $futureStart, $futureEnd, 'open');

        self::$trajUser1Id = (int) wp_create_user('grid_traj_u1_' . uniqid(), 'pass123', 'gtj1_' . uniqid() . '@test.local');
        self::$trajUser2Id = (int) wp_create_user('grid_traj_u2_' . uniqid(), 'pass123', 'gtj2_' . uniqid() . '@test.local');
        self::$trajUser3Id = (int) wp_create_user('grid_traj_u3_' . uniqid(), 'pass123', 'gtj3_' . uniqid() . '@test.local');

        // --- T1 parent (edition_id NULL) ---
        $t1Parent = $repo->create([
            'user_id'         => self::$trajUser1Id,
            'trajectory_id'   => self::$trajT1Id,
            'company_id'      => self::$companyId,
            'status'          => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($t1Parent, 'T1-parent');
        self::$t1ParentRegId = (int) $t1Parent;
        self::$regIds[]      = (int) $t1Parent;

        // --- T1 cascade child A (parent-linked, trajectory_id NULL, confirmed) ---
        $t1ChildA = $repo->create([
            'user_id'                => self::$trajUser1Id,
            'edition_id'             => self::$trajChildEditionA,
            'parent_registration_id' => self::$t1ParentRegId,
            'company_id'             => self::$companyId,
            'status'                 => RegistrationStatus::Confirmed->value,
            'enrollment_path'        => 'trajectory',
        ]);
        self::assertValidRegId($t1ChildA, 'T1-childA');
        self::$t1ChildARegId = (int) $t1ChildA;
        self::$regIds[]      = (int) $t1ChildA;

        // --- T1 cascade child B (parent-linked, trajectory_id NULL, waitlist) ---
        $t1ChildB = $repo->create([
            'user_id'                => self::$trajUser2Id,
            'edition_id'             => self::$trajChildEditionB,
            'parent_registration_id' => self::$t1ParentRegId,
            'company_id'             => self::$companyId,
            'status'                 => RegistrationStatus::Waitlist->value,
            'enrollment_path'        => 'trajectory',
        ]);
        self::assertValidRegId($t1ChildB, 'T1-childB');
        self::$t1ChildBRegId = (int) $t1ChildB;
        self::$regIds[]      = (int) $t1ChildB;

        // --- T1 legacy pre-cascade child (trajectory_id=T1, NO parent link) ---
        $t1Legacy = $repo->create([
            'user_id'         => self::$trajUser3Id,
            'edition_id'      => self::$trajLegacyEdition,
            'trajectory_id'   => self::$trajT1Id,
            'company_id'      => self::$companyId,
            'status'          => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($t1Legacy, 'T1-legacy');
        self::$t1LegacyChildRegId = (int) $t1Legacy;
        self::$regIds[]           = (int) $t1Legacy;

        // --- T2 parent (the foil) ---
        $t2Parent = $repo->create([
            'user_id'         => self::$trajUser2Id,
            'trajectory_id'   => self::$trajT2Id,
            'company_id'      => self::$companyId,
            'status'          => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($t2Parent, 'T2-parent');
        self::$t2ParentRegId = (int) $t2Parent;
        self::$regIds[]      = (int) $t2Parent;

        // --- T2 cascade child — MUST NEVER leak into a T1-filtered result ---
        $t2Child = $repo->create([
            'user_id'                => self::$trajUser3Id,
            'edition_id'             => self::$trajT2ChildEdition,
            'parent_registration_id' => self::$t2ParentRegId,
            'company_id'             => self::$companyId,
            'status'                 => RegistrationStatus::Confirmed->value,
            'enrollment_path'        => 'trajectory',
        ]);
        self::assertValidRegId($t2Child, 'T2-child');
        self::$t2ChildRegId = (int) $t2Child;
        self::$regIds[]     = (int) $t2Child;

        // --- Plain non-trajectory edition reg (also must never appear under T1) ---
        $plain = $repo->create([
            'user_id'    => self::$trajUser1Id,
            'edition_id' => self::$activeEditionId,
            'company_id' => self::$companyId,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        self::assertValidRegId($plain, 'traj-plain');
        self::$trajPlainRegId = (int) $plain;
        self::$regIds[]       = (int) $plain;
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

        foreach ([
            self::$user1Id, self::$user2Id, self::$user3Id,
            self::$trajUser1Id, self::$trajUser2Id, self::$trajUser3Id,
        ] as $uid) {
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

    /**
     * UI↔server group-by invariant (shakeout Bug 1).
     *
     * The grid UI offers a fixed set of group-by axes. EVERY axis the UI can
     * offer MUST be accepted by the SERVICE (getGroupedPage) and return a
     * grouped envelope; any value OUTSIDE the server allowlist MUST return the
     * `invalid_group_by` WP_Error (HTTP 400) — never a silent 200.
     *
     * Driven through the SERVICE, not the repo: queryForGrid SILENTLY IGNORES
     * an out-of-allowlist group_by (M4 sort-style fallback), which is exactly
     * why the unsupported `trajectory_id` UI option shipped green — the only
     * existing assertion (m4OutOfWhitelistGroupByRejected) hit the repo. This
     * pins the affordance↔allowlist contract at the service boundary that the
     * controller actually calls.
     *
     * @test
     */
    public function groupByServiceAcceptsExactlyTheAllowlist(): void
    {
        $service = ntdst_get(AdminRegistrationQueryService::class);

        // Every value the server allowlist accepts must produce a grouped
        // envelope (items array + total), NOT an error.
        foreach (RegistrationRepository::GROUP_BY_ALLOWLIST as $axis) {
            $result = $service->getGridPage([
                'group_by'      => $axis,
                'company_id'    => self::$companyId,
                'edition_scope' => 'all',
            ]);

            $this->assertNotInstanceOf(
                \WP_Error::class,
                $result,
                "Allowlisted group_by axis '{$axis}' must return a grouped envelope, not an error",
            );
            $this->assertArrayHasKey('items', $result, "Axis '{$axis}' envelope must have items");
            $this->assertArrayHasKey('total', $result, "Axis '{$axis}' envelope must have total");
        }

        // The UI-removed value: trajectory_id is NOT in the allowlist and MUST
        // be a hard invalid_group_by 400 — the server defends the affordance the
        // UI no longer offers.
        $rejected = $service->getGridPage([
            'group_by'      => 'trajectory_id',
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);

        $this->assertInstanceOf(
            \WP_Error::class,
            $rejected,
            'Out-of-allowlist group_by (trajectory_id) must return WP_Error, not a silent 200',
        );
        $this->assertSame(
            'invalid_group_by',
            $rejected->get_error_code(),
            'Rejected group_by must carry the invalid_group_by error code',
        );
        $this->assertSame(
            400,
            $rejected->get_error_data()['status'] ?? null,
            'invalid_group_by must carry HTTP 400 status',
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
    // FIX 1: trajectory-PARENT rows (edition_id IS NULL) must NEVER appear in
    // the edition-grained grid corpus — any scope.
    // =========================================================================

    /**
     * @test
     */
    public function trajectoryParentRowExcludedFromGridCorpusEveryScope(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // A trajectory PARENT row: trajectory_id SET, edition_id NULL,
        // parent_registration_id NULL, status=completed, enrollment_path='trajectory'.
        $trajId = self::$datelessEditionId; // any int — used only as the trajectory_id value
        $parentReg = $repo->create([
            'user_id'         => self::$user3Id,
            'trajectory_id'   => $trajId,
            'company_id'      => self::$companyId,
            'status'          => RegistrationStatus::Completed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($parentReg, 'trajectory-parent');
        self::$regIds[] = (int) $parentReg;

        // Sanity: it really is a parent (edition_id NULL).
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT edition_id, trajectory_id FROM {$wpdb->prefix}vad_registrations WHERE id = %d",
            (int) $parentReg,
        ));
        $this->assertNull($row->edition_id, 'fixture must be a trajectory parent (edition_id NULL)');

        // Default (active) scope — parent must NOT leak in.
        $active = $repo->queryForGrid(['company_id' => self::$companyId]);
        $activeIds = array_map(fn($r) => (int) $r->id, $active['rows']);
        $this->assertNotContains(
            (int) $parentReg,
            $activeIds,
            'Trajectory parent (edition_id NULL) must NOT appear in the default active scope',
        );

        // edition_scope=all — parent must STILL NOT appear (never a grid row).
        $all = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
        ]);
        $allIds = array_map(fn($r) => (int) $r->id, $all['rows']);
        $this->assertNotContains(
            (int) $parentReg,
            $allIds,
            'Trajectory parent (edition_id NULL) must NOT appear even with edition_scope=all',
        );
    }

    // =========================================================================
    // FIX 5: grouped path forms no spurious NULL/'' group from trajectory parents.
    // =========================================================================

    /**
     * @test
     */
    public function groupedByEditionFormsNoNullGroupFromTrajectoryParent(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // A trajectory PARENT row (edition_id NULL).
        $parentReg = $repo->create([
            'user_id'         => self::$user1Id,
            'trajectory_id'   => self::$activeEditionId,
            'company_id'      => self::$companyId,
            'status'          => RegistrationStatus::Completed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($parentReg, 'trajectory-parent-grouped');
        self::$regIds[] = (int) $parentReg;

        $result = $repo->queryForGridGrouped(
            ['company_id' => self::$companyId, 'edition_scope' => 'all'],
            'edition_id',
        );

        // No group_value may be null or '' (the NULL-edition trajectory parent).
        foreach ($result['agg_rows'] as $agg) {
            $this->assertNotNull($agg->group_value, 'No NULL edition_id group may form');
            $this->assertNotSame('', (string) $agg->group_value, 'No empty edition_id group may form');
        }

        // Every group must have a consistent reg-id set (count matches resolved ids,
        // proving no IN('') miss silently dropped the group's ids).
        foreach ($result['agg_rows'] as $agg) {
            $gv  = $agg->group_value;
            $ids = $result['group_reg_ids'][$gv] ?? [];
            $this->assertSame(
                (int) $agg->cnt,
                count($ids),
                "Group {$gv}: resolved reg-id count must equal the aggregate count (no IN('') miss)",
            );
        }
    }

    // =========================================================================
    // FIX 2: grouped pagination must count GROUPS, not ROWS.
    // =========================================================================

    /**
     * @test
     */
    public function groupedTotalCountsGroupsNotRows(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // Our company has rows across distinct statuses: confirmed (R1,R2),
        // waitlist (R3), completed (R4), interest (R5) = 4 distinct statuses,
        // 5 rows (with edition_scope=all). Grouping by status → 4 groups.
        $perPage = 50; // >= number of groups

        $result = $repo->queryForGridGrouped(
            ['company_id' => self::$companyId, 'edition_scope' => 'all', 'per_page' => $perPage],
            'status',
        );

        $distinctGroups = count($result['agg_rows']);
        $this->assertGreaterThanOrEqual(4, $distinctGroups, 'expected >=4 distinct status groups');

        // total must equal the number of GROUPS, not the number of ROWS.
        $this->assertSame(
            $distinctGroups,
            (int) $result['total'],
            'Grouped total must be the GROUP count, not the row count (no phantom pages)',
        );

        // Cross-check: the flat row total is strictly larger than the group count
        // (5 rows > 4 groups), so this assertion would fail on the old row-count code.
        $flat = $repo->queryForGrid(['company_id' => self::$companyId, 'edition_scope' => 'all']);
        $this->assertGreaterThan(
            (int) $result['total'],
            (int) $flat['total'],
            'flat row total must exceed grouped group total for this fixture',
        );
    }

    // =========================================================================
    // FIX 4: flat queryForGrid must NOT collapse rows by group_by.
    // =========================================================================

    /**
     * @test
     */
    public function flatQueryDoesNotCollapseRowsByGroupBy(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // group_by=status is IN the allowlist. The flat row path must still
        // return ONE ROW PER REGISTRATION, not one arbitrary row per status.
        $flatGrouped = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'group_by'      => 'status',
            'per_page'      => 100,
        ]);

        $baseline = $repo->queryForGrid([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        // Same number of rows as without group_by (one row per registration).
        $this->assertSame(
            count($baseline['rows']),
            count($flatGrouped['rows']),
            'Flat path with in-allowlist group_by must NOT collapse rows',
        );

        // All ids distinct + real (no arbitrary one-row-per-status collapse).
        $ids = array_map(fn($r) => (int) $r->id, $flatGrouped['rows']);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Flat rows must be distinct real ids');

        // total must remain the ROW total, not a group total.
        $this->assertSame(
            (int) $baseline['total'],
            (int) $flatGrouped['total'],
            'Flat path total must remain the row total even when group_by is passed',
        );
    }

    // =========================================================================
    // Task 1.4b: trajectory_id grid filter (parent→child join, scoped to T1)
    // =========================================================================

    /**
     * @test
     * trajectory_id=T1 returns ONLY T1's child edition-rows: every returned row
     * has a non-null edition_id and is one of T1's three children (childA, childB,
     * legacy). The PARENT row (edition_id NULL) is never among them.
     */
    public function trajectoryFilterReturnsOnlyT1ChildEditionRows(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $result = $repo->queryForGrid([
            'trajectory_id' => self::$trajT1Id,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        $ids = array_map(fn($r) => (int) $r->id, $result['rows']);

        // T1's three child edition-rows are all present.
        $this->assertContains(self::$t1ChildARegId, $ids, 'T1 cascade child A must be returned');
        $this->assertContains(self::$t1ChildBRegId, $ids, 'T1 cascade child B must be returned');
        $this->assertContains(self::$t1LegacyChildRegId, $ids, 'T1 legacy child must be returned');

        // Every returned row is edition-grained (parent excluded) and belongs to T1.
        $allowed = [self::$t1ChildARegId, self::$t1ChildBRegId, self::$t1LegacyChildRegId];
        foreach ($result['rows'] as $row) {
            $this->assertNotNull($row->edition_id, 'Trajectory-filtered grid row must have an edition_id (no parent rows)');
            $this->assertContains(
                (int) $row->id,
                $allowed,
                'Only T1 child edition-rows may appear under trajectory_id=T1',
            );
        }
    }

    /**
     * @test
     * The T1 PARENT row (edition_id NULL) is NOT in a trajectory_id=T1 result —
     * the base r.edition_id IS NOT NULL corpus predicate keeps it out.
     */
    public function trajectoryFilterExcludesTheParentRow(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $result = $repo->queryForGrid([
            'trajectory_id' => self::$trajT1Id,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        $ids = array_map(fn($r) => (int) $r->id, $result['rows']);
        $this->assertNotContains(
            self::$t1ParentRegId,
            $ids,
            'T1 trajectory parent (edition_id NULL) must NOT appear in the grid',
        );
    }

    /**
     * @test
     * LEAK-CHECK (threat-model A1, load-bearing): a trajectory_id=T1 result
     * contains NONE of T2's child rows and NOT the plain non-trajectory reg.
     * This is both a correctness AND a confidentiality assertion.
     */
    public function trajectoryFilterDoesNotLeakOtherTrajectoryOrPlainRows(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $result = $repo->queryForGrid([
            'trajectory_id' => self::$trajT1Id,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        $ids = array_map(fn($r) => (int) $r->id, $result['rows']);

        $this->assertNotContains(self::$t2ChildRegId, $ids, 'T2 child must NOT leak into a T1-filtered result');
        $this->assertNotContains(self::$t2ParentRegId, $ids, 'T2 parent must NOT leak into a T1-filtered result');
        $this->assertNotContains(self::$trajPlainRegId, $ids, 'Plain non-trajectory reg must NOT appear under trajectory_id=T1');

        // PREPARE-ORDER under DEFAULT (active) scope — this path adds the
        // active-scope %s WHERE param ALONGSIDE the trajectory JOIN %d, the
        // highest-risk binding combo. Children are dated future/active, so they
        // survive the active scope; the same leak guarantees must hold.
        $activeScoped = $repo->queryForGrid([
            'trajectory_id' => self::$trajT1Id,
            'company_id'    => self::$companyId,
            'per_page'      => 100,
        ]);
        $activeIds = array_map(fn($r) => (int) $r->id, $activeScoped['rows']);

        $this->assertContains(self::$t1ChildARegId, $activeIds, 'T1 child A must survive default active scope');
        $this->assertNotContains(self::$t2ChildRegId, $activeIds, 'T2 child must NOT leak under default active scope (prepare-order)');
        $this->assertNotContains(self::$trajPlainRegId, $activeIds, 'Plain reg must NOT appear under T1 + active scope');
        foreach ($activeScoped['rows'] as $row) {
            $this->assertNotNull($row->edition_id, 'No parent rows under active scope either');
        }
    }

    /**
     * @test
     * The legacy pre-cascade child (trajectory_id=T1, edition_id SET, no parent
     * link) IS returned — covers the `child.trajectory_id = T OR parent IS NOT
     * NULL` disjunct, not just the parent-link branch.
     */
    public function trajectoryFilterIncludesLegacyPreCascadeChild(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $result = $repo->queryForGrid([
            'trajectory_id' => self::$trajT1Id,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        $ids = array_map(fn($r) => (int) $r->id, $result['rows']);
        $this->assertContains(
            self::$t1LegacyChildRegId,
            $ids,
            'Legacy pre-cascade child (trajectory_id=T1, no parent link) must be returned',
        );
    }

    /**
     * @test
     * PREPARE-ORDER PROOF: trajectory_id combined with status (a WHERE param)
     * binds correctly. trajectory_id=T1 + status=confirmed returns ONLY T1's
     * confirmed children (childA + legacy), and NOT the waitlist child (childB).
     * A misordered $wpdb->prepare would corrupt this disjoint binding.
     */
    public function trajectoryFilterCombinedWithStatusBindsCorrectly(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $result = $repo->queryForGrid([
            'trajectory_id' => self::$trajT1Id,
            'status'        => RegistrationStatus::Confirmed->value,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        $ids = array_map(fn($r) => (int) $r->id, $result['rows']);

        // Confirmed T1 children present.
        $this->assertContains(self::$t1ChildARegId, $ids, 'Confirmed cascade child A must be present');
        $this->assertContains(self::$t1LegacyChildRegId, $ids, 'Confirmed legacy child must be present');

        // Waitlist child B must NOT be present (status filter), nor anything else.
        $this->assertNotContains(self::$t1ChildBRegId, $ids, 'Waitlist child B must be filtered out by status=confirmed');

        // And every row is genuinely confirmed + a T1 child (no cross-binding corruption).
        $allowedConfirmed = [self::$t1ChildARegId, self::$t1LegacyChildRegId];
        foreach ($result['rows'] as $row) {
            $this->assertSame('confirmed', $row->status, 'status param must bind — only confirmed rows');
            $this->assertContains((int) $row->id, $allowedConfirmed, 'Only T1 confirmed children may appear');
        }
    }

    // =========================================================================
    // Task 3.3 Part B: per-status funnel counts (statusCounts) in the grid DTO
    // =========================================================================

    /**
     * @test
     * The grid page DTO carries a `statusCounts` map reflecting the CURRENT
     * filter set MINUS the status filter itself (so the pipeline funnel can show
     * "how many of each status match the OTHER active filters"). With a company
     * filter applied (edition_scope=all), the per-status counts must respect it:
     * our company's fixture rows are confirmed×2 (R1,R2) + waitlist×1 (R3) +
     * completed×1 (R4) + interest×1 (R5), PLUS the trajectory child rows under
     * the same company. F4 acceptance ("funnel shows per-stage live counts").
     */
    public function gridDtoCarriesStatusCountsRespectingActiveFilters(): void
    {
        $service = ntdst_get(AdminRegistrationQueryService::class);

        $dto = $service->getGridPage([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);

        $this->assertIsArray($dto);
        $this->assertArrayHasKey('statusCounts', $dto, 'Grid DTO must expose a statusCounts map for the funnel');

        $counts = $dto['statusCounts'];

        // Zero-filled across every lifecycle status so the funnel chips always
        // have a number to render (never undefined).
        foreach (RegistrationStatus::cases() as $case) {
            $this->assertArrayHasKey($case->value, $counts, "statusCounts must include '{$case->value}' (zero-filled)");
            $this->assertIsInt($counts[$case->value]);
        }

        // Our company's non-trajectory fixtures: confirmed R1+R2, waitlist R3,
        // completed R4, interest R5. Trajectory children add MORE confirmed +
        // waitlist rows for the same company — so assert the floor, not equality
        // (other suites' fixtures share the test DB, but never our company id).
        $this->assertGreaterThanOrEqual(2, $counts[RegistrationStatus::Confirmed->value], 'expected >=2 confirmed for our company');
        $this->assertGreaterThanOrEqual(1, $counts[RegistrationStatus::Waitlist->value], 'expected >=1 waitlist for our company');
        $this->assertGreaterThanOrEqual(1, $counts[RegistrationStatus::Completed->value], 'expected >=1 completed for our company');
        $this->assertGreaterThanOrEqual(1, $counts[RegistrationStatus::Interest->value], 'expected >=1 interest for our company');
    }

    /**
     * @test
     * statusCounts IGNORES the status filter in the request (the funnel shows the
     * full distribution under the OTHER filters, regardless of which chip is
     * active) — but RESPECTS a company filter that scopes a foreign company out.
     * A different company id returns all-zero counts for our fixtures.
     */
    public function statusCountsIgnoreStatusFilterButRespectCompanyScope(): void
    {
        $service = ntdst_get(AdminRegistrationQueryService::class);

        // Same company, but with an active status chip selected: statusCounts
        // must be the SAME distribution as with no status filter — the count is
        // "of each status, under the other filters", not "rows matching the chip".
        $noStatus = $service->getGridPage([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);
        $withStatus = $service->getGridPage([
            'company_id'    => self::$companyId,
            'edition_scope' => 'all',
            'status'        => RegistrationStatus::Confirmed->value,
            'per_page'      => 100,
        ]);

        $this->assertSame(
            $noStatus['statusCounts'],
            $withStatus['statusCounts'],
            'statusCounts must drop the status filter — same distribution regardless of the active chip',
        );

        // A foreign company id → our fixtures excluded → all-zero counts.
        $foreign = $service->getGridPage([
            'company_id'    => self::$companyId + 999999,
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);
        foreach ($foreign['statusCounts'] as $status => $n) {
            $this->assertSame(0, $n, "Foreign company must yield 0 for status '{$status}'");
        }

        // Existing envelope keys remain present + unchanged in shape (additive-only).
        foreach (['items', 'total', 'page', 'perPage', 'totalPages'] as $key) {
            $this->assertArrayHasKey($key, $noStatus, "Existing envelope key '{$key}' must remain");
        }
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
