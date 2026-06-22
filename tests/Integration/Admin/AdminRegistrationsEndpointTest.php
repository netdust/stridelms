<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use Stride\Domain\QuoteStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for GET /stride/v1/admin/registrations
 *
 * Task 1.3 — AdminRegistrationQueryService + thin REST route.
 *
 * Tier A. This task:
 *  - registers a new REST route (wiring) → Seam test required
 *  - the permission_callback canViewAdmin is a load-bearing security guard → M1 denial must be RED-first
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminRegistrationsEndpoint
 */
final class AdminRegistrationsEndpointTest extends IntegrationTestCase
{
    private static ?int $coordinatorUserId = null;
    private static ?int $plainUserId = null;
    private static ?int $testEditionId = null;
    private static ?int $testEditionId2 = null;
    private static ?int $testStudentUserId = null;
    private static ?int $testStudentUserId2 = null;
    private static ?int $testQuoteId = null;

    /** @var list<int> */
    private array $testRegistrationIds = [];

    // =========================================================================
    // SUITE SETUP / TEARDOWN
    // =========================================================================

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        // Coordinator with stride_view capability
        $coordinatorUsername = 'coord_t13_' . uniqid();
        self::$coordinatorUserId = wp_create_user(
            $coordinatorUsername,
            'testpass123',
            $coordinatorUsername . '@test.local',
        );
        if (is_wp_error(self::$coordinatorUserId)) {
            throw new \RuntimeException('Could not create coordinator: ' . self::$coordinatorUserId->get_error_message());
        }
        $coord = get_user_by('ID', self::$coordinatorUserId);
        $coord->set_role('stride_coordinator');

        // Plain user WITHOUT stride_view
        $plainUsername = 'plain_t13_' . uniqid();
        self::$plainUserId = wp_create_user(
            $plainUsername,
            'testpass123',
            $plainUsername . '@test.local',
        );
        if (is_wp_error(self::$plainUserId)) {
            throw new \RuntimeException('Could not create plain user: ' . self::$plainUserId->get_error_message());
        }

        // Students to enroll
        $s1 = wp_create_user('student_t13a_' . uniqid(), 'testpass123', 'student_t13a_' . uniqid() . '@test.local');
        $s2 = wp_create_user('student_t13b_' . uniqid(), 'testpass123', 'student_t13b_' . uniqid() . '@test.local');
        if (is_wp_error($s1) || is_wp_error($s2)) {
            throw new \RuntimeException('Could not create student users');
        }
        self::$testStudentUserId  = (int) $s1;
        self::$testStudentUserId2 = (int) $s2;

        update_user_meta(self::$testStudentUserId, 'billing_company', 'Acme Corp');

        // Two editions
        $e1 = wp_insert_post([
            'post_title'  => 'Edition Alpha T13',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $e2 = wp_insert_post([
            'post_title'  => 'Edition Beta T13',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($e1) || is_wp_error($e2)) {
            throw new \RuntimeException('Could not create editions');
        }
        self::$testEditionId  = (int) $e1;
        self::$testEditionId2 = (int) $e2;
        self::$testPosts[]    = self::$testEditionId;
        self::$testPosts[]    = self::$testEditionId2;

        // Quote (exported) linked to registration — quote_id set later via update after reg is created
        $qId = wp_insert_post([
            'post_title'  => 'Quote T13',
            'post_type'   => 'vad_quote',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($qId)) {
            throw new \RuntimeException('Could not create quote: ' . $qId->get_error_message());
        }
        self::$testQuoteId  = (int) $qId;
        self::$testPosts[]  = self::$testQuoteId;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$coordinatorUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$coordinatorUserId);
        }
        if (self::$plainUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$plainUserId);
        }
        if (self::$testStudentUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$testStudentUserId);
        }
        if (self::$testStudentUserId2) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$testStudentUserId2);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Default: act as coordinator
        $this->actingAs(self::$coordinatorUserId);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->testRegistrationIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        $this->testRegistrationIds = [];
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getRepo(): RegistrationRepository
    {
        return ntdst_get(RegistrationRepository::class);
    }

    private function createReg(array $overrides = []): int
    {
        $defaults = [
            'user_id'         => self::$testStudentUserId,
            'edition_id'      => self::$testEditionId,
            'status'          => 'confirmed',
            'enrollment_path' => 'individual',
        ];
        $id = $this->getRepo()->create(array_merge($defaults, $overrides));
        $this->assertIsInt($id, 'Failed to create registration');
        $this->testRegistrationIds[] = $id;
        return $id;
    }

    private function dispatch(string $method, string $path, array $params = []): \WP_REST_Response|\WP_Error
    {
        $request = new \WP_REST_Request($method, $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_do_request($request);
    }

    // =========================================================================
    // M1 SECURITY: ANONYMOUS DENIAL (load-bearing — RED proof target)
    // =========================================================================

    /**
     * @test
     * Unauthenticated request → 401/403 (M1 permission_callback must deny).
     * This is the load-bearing security assertion for this task.
     */
    public function unauthenticatedRequestIsDenied(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations');

        $this->assertContains(
            $response->get_status(),
            [401, 403],
            'Unauthenticated request must be denied (401 or 403)',
        );
    }

    /**
     * @test
     * User without stride_view capability → 403.
     */
    public function unprivilegedUserIsDenied(): void
    {
        $this->actingAs(self::$plainUserId);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations');

        $this->assertEquals(403, $response->get_status(), 'User without stride_view must be denied');
    }

    // =========================================================================
    // HAPPY PATH: §3.1 composite DTO
    // =========================================================================

    /**
     * @test
     * As coordinator, GET /admin/registrations returns 200 with the composite
     * page DTO — each item carries: id, user, edition, status, offerteStatus,
     * attendancePct, company keys.
     */
    public function coordinatorReceivesCompositeDto(): void
    {
        $regId = $this->createReg();

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
        ]);

        $this->assertEquals(200, $response->get_status(), 'Coordinator must receive 200');

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('perPage', $data);
        $this->assertArrayHasKey('totalPages', $data);

        // Find our registration in the items
        $found = null;
        foreach ($data['items'] as $item) {
            if ((int) $item['id'] === $regId) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, "Registration {$regId} not found in items");

        // §3.1 — every required key must be present
        $this->assertArrayHasKey('id', $found);
        $this->assertArrayHasKey('user', $found);
        $this->assertArrayHasKey('edition', $found);
        $this->assertArrayHasKey('status', $found);
        $this->assertArrayHasKey('offerteStatus', $found);
        $this->assertArrayHasKey('attendancePct', $found);
        $this->assertArrayHasKey('company', $found);

        // User sub-keys
        $this->assertArrayHasKey('id', $found['user']);
        $this->assertArrayHasKey('name', $found['user']);
        $this->assertArrayHasKey('email', $found['user']);

        // Edition sub-keys
        $this->assertArrayHasKey('id', $found['edition']);
        $this->assertArrayHasKey('title', $found['edition']);

        // Company sub-keys
        $this->assertArrayHasKey('id', $found['company']);
        $this->assertArrayHasKey('name', $found['company']);

        // Status label must be a string (RegistrationStatus::label())
        $this->assertArrayHasKey('label', $found['status']);
        $this->assertIsString($found['status']['label']);
    }

    // =========================================================================
    // TWO-STEP OFFERTE RESOLVER
    // =========================================================================

    /**
     * @test
     * A registration with an exported quote → offerteStatus is "Verwerkt" (exported label).
     * A registration with no quote → offerteStatus is "Geen offerte".
     * This proves the two-step resolver is running; NOT a paid flag.
     */
    public function offerteStatusReflectsQuoteWorkflowNotPaymentFlag(): void
    {
        // Registration WITH quote (exported)
        $regWithQuote = $this->createReg([
            'user_id'    => self::$testStudentUserId,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        // Attach quote to registration via postmeta
        update_post_meta(self::$testQuoteId, 'registration_id', $regWithQuote);
        update_post_meta(self::$testQuoteId, 'status', QuoteStatus::Exported->value);

        // Registration WITHOUT any quote
        $regNoQuote = $this->createReg([
            'user_id'    => self::$testStudentUserId2,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
        ]);

        $this->assertEquals(200, $response->get_status());

        $data  = $response->get_data();
        $items = $data['items'];

        $withQuoteItem = null;
        $noQuoteItem   = null;
        foreach ($items as $item) {
            if ((int) $item['id'] === $regWithQuote) {
                $withQuoteItem = $item;
            }
            if ((int) $item['id'] === $regNoQuote) {
                $noQuoteItem = $item;
            }
        }

        $this->assertNotNull($withQuoteItem, "Registration with quote not found in items");
        $this->assertNotNull($noQuoteItem, "Registration without quote not found in items");

        // Exported → "Verwerkt"
        $this->assertEquals(
            QuoteStatus::Exported->label(),
            $withQuoteItem['offerteStatus'],
            'Row with exported quote must show Verwerkt, not a paid flag',
        );

        // No quote → "Geen offerte"
        $this->assertEquals(
            'Geen offerte',
            $noQuoteItem['offerteStatus'],
            'Row with no quote must show "Geen offerte"',
        );

        // Clean up quote link
        delete_post_meta(self::$testQuoteId, 'registration_id');
    }

    // =========================================================================
    // STATUS PARAM PASSES THROUGH TO queryForGrid
    // =========================================================================

    /**
     * @test
     * Passing status=confirmed narrows results to confirmed rows only
     * (proves the param flows through the service to queryForGrid).
     */
    public function statusParamNarrowsResults(): void
    {
        // Confirmed registration
        $confirmedId = $this->createReg([
            'user_id'    => self::$testStudentUserId,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        // Cancelled registration (same edition)
        $cancelledId = $this->createReg([
            'user_id'    => self::$testStudentUserId2,
            'edition_id' => self::$testEditionId,
            'status'     => 'cancelled',
        ]);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
            'status'        => 'confirmed',
        ]);

        $this->assertEquals(200, $response->get_status());

        $data    = $response->get_data();
        $itemIds = array_column($data['items'], 'id');

        // confirmed row is present
        $this->assertContains((string) $confirmedId, array_map('strval', $itemIds));

        // cancelled row is absent when filtering by confirmed
        $this->assertNotContains((string) $cancelledId, array_map('strval', $itemIds));
    }

    // =========================================================================
    // GROUP_BY: returns aggregates, not arbitrary rows
    // =========================================================================

    /**
     * @test
     * When group_by=status is passed, the response items are GROUP AGGREGATES
     * (each item has group_value + count + pct_afgerond), NOT arbitrary flat rows.
     */
    public function groupByReturnsAggregatesNotArbitraryRows(): void
    {
        // Two confirmed registrations for edition 1
        $this->createReg([
            'user_id'    => self::$testStudentUserId,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);
        $this->createReg([
            'user_id'    => self::$testStudentUserId2,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
            'group_by'      => 'status',
        ]);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('items', $data);

        // Items must be aggregate shape, not flat registration rows
        foreach ($data['items'] as $item) {
            $this->assertArrayHasKey('group_value', $item, 'Grouped items must have group_value key');
            $this->assertArrayHasKey('count', $item, 'Grouped items must have count key');
            $this->assertArrayHasKey('pct_afgerond', $item, 'Grouped items must have pct_afgerond key');
            // Flat-row keys must NOT be present (they would indicate an arbitrary-row response)
            $this->assertArrayNotHasKey('user', $item, 'Grouped items must not carry individual user data');
            $this->assertArrayNotHasKey('offerteStatus', $item, 'Grouped items must not carry offerteStatus per row');
        }

        // Find confirmed group — must aggregate both registrations
        $confirmedGroup = null;
        foreach ($data['items'] as $item) {
            if ($item['group_value'] === 'confirmed') {
                $confirmedGroup = $item;
                break;
            }
        }
        $this->assertNotNull($confirmedGroup, 'confirmed group must appear in grouped response');
        $this->assertGreaterThanOrEqual(2, $confirmedGroup['count'], 'confirmed group must count both registrations');
    }

    // =========================================================================
    // BUG FIX: q name-filter must apply identically on grouped and flat paths
    // =========================================================================

    /**
     * @test
     * Regression: grouped path (group_by=status) MUST apply the q name filter.
     *
     * Before the fix, getGroupedPage() built its own WHERE clause that omitted
     * the q predicate — so the aggregate counts reflected ALL rows while `total`
     * was taken from queryForGrid (which DID apply q). This caused total to
     * disagree with the summed group counts.
     *
     * Contract asserted here:
     *  1. With q=<unique display_name> + group_by=status, the summed group
     *     counts must equal exactly what the flat q-only query returns.
     *  2. `total` must agree with the summed group counts (not a phantom larger
     *     number from the unfiltered universe).
     */
    public function groupedPathAppliesQNameFilterIdenticallyToFlatPath(): void
    {
        global $wpdb;

        // Create two users with distinct, non-colliding display names.
        // Only user A's display_name will match the q filter.
        $suffix = uniqid('qgrp_');

        $userAId = wp_create_user("useraA_{$suffix}", 'pw', "userA_{$suffix}@test.local");
        $userBId = wp_create_user("userBB_{$suffix}", 'pw', "userB_{$suffix}@test.local");

        $this->assertIsInt($userAId);
        $this->assertIsInt($userBId);

        // Give user A a fully unique display name so the q search is unambiguous.
        $uniqueDisplayName = "QueryTarget_{$suffix}";
        wp_update_user(['ID' => $userAId, 'display_name' => $uniqueDisplayName]);

        // Edition: no start_date so it shows in the default active scope.
        $editionId = wp_insert_post([
            'post_title'  => "QGrpEdition_{$suffix}",
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $this->assertIsInt($editionId);
        self::$testPosts[] = $editionId;

        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);

        // R-A: user A, confirmed  — matches q
        $rA = $repo->create([
            'user_id'    => $userAId,
            'edition_id' => $editionId,
            'status'     => 'confirmed',
        ]);
        $this->assertIsInt($rA);
        $this->testRegistrationIds[] = $rA;

        // R-B: user B, confirmed — does NOT match q
        $rB = $repo->create([
            'user_id'    => $userBId,
            'edition_id' => $editionId,
            'status'     => 'confirmed',
        ]);
        $this->assertIsInt($rB);
        $this->testRegistrationIds[] = $rB;

        // Flat path with q: must find exactly 1 row (user A only).
        $flatResponse = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'q'             => $uniqueDisplayName,
            'edition_scope' => 'all',
        ]);
        $this->assertEquals(200, $flatResponse->get_status());
        $flatData  = $flatResponse->get_data();
        $flatTotal = (int) $flatData['total'];

        // Flat total must be exactly 1 (only user A matches).
        $this->assertSame(
            1,
            $flatTotal,
            "Flat path: expected exactly 1 row matching q={$uniqueDisplayName}, got {$flatTotal}",
        );

        // Grouped path with same q + group_by=status.
        $groupedResponse = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'q'             => $uniqueDisplayName,
            'group_by'      => 'status',
            'edition_scope' => 'all',
        ]);
        $this->assertEquals(200, $groupedResponse->get_status());
        $groupedData = $groupedResponse->get_data();

        // The summed group counts must equal 1 (only user A).
        $summedGroupCount = array_sum(array_column($groupedData['items'], 'count'));
        $this->assertSame(
            1,
            $summedGroupCount,
            "Grouped path: summed group counts must be 1 (q filter must apply), got {$summedGroupCount}",
        );

        // total must agree with the summed group counts (not a phantom larger value).
        $groupedTotal = (int) $groupedData['total'];
        $this->assertSame(
            $groupedTotal,
            $summedGroupCount,
            "total ({$groupedTotal}) must agree with summed group counts ({$summedGroupCount})",
        );

        // Cleanup users (tearDown handles registrations, setUpBeforeClass handles posts).
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userAId);
        wp_delete_user($userBId);
    }

    // =========================================================================
    // CR-1: trajectory_id must be wired THROUGH the real endpoint.
    //
    // The existing RegistrationGridQueryTest proves queryForGrid honours
    // trajectory_id, but it bypasses the controller. This test drives the REAL
    // endpoint (rest_do_request) — the only place the param-extraction wiring
    // is exercised. Before the fix, getRegistrations() omitted trajectory_id
    // from $params, so the filter was UNREACHABLE: the response came back
    // unscoped (all editions), proving the param was silently dropped.
    //
    // Fixture: a trajectory parent (edition_id NULL, enrollment_path=trajectory,
    // trajectory_id=T) + 2 child edition-rows (parent-linked, trajectory_id NULL)
    // + an unrelated plain edition reg. Mirrors RegistrationGridQueryTest's
    // parent/child corpus.
    // =========================================================================

    /**
     * @test
     * Seam (un-mocked chain): GET /stride/v1/admin/registrations?trajectory_id=T
     * returns ONLY T's child edition-rows — every returned id is one of T's
     * children, and the unrelated plain reg is absent. WITHOUT the param the
     * same request returns the unrelated reg too (the param actually changes
     * the result). This is the assertion the controller wiring must satisfy.
     */
    public function trajectoryIdParamScopesEndpointToChildEditionRows(): void
    {
        $repo = $this->getRepo();

        // A distinct trajectory id value for this test (need not be a real CPT —
        // the grid join is on the structured trajectory_id/parent_registration_id
        // columns, matching the RegistrationGridQueryTest fixture convention).
        $trajId = 880011;

        // Distinct users to avoid create()'s user+edition dedup.
        $u1 = (int) wp_create_user('cr1_u1_' . uniqid(), 'pw', 'cr1u1_' . uniqid() . '@test.local');
        $u2 = (int) wp_create_user('cr1_u2_' . uniqid(), 'pw', 'cr1u2_' . uniqid() . '@test.local');

        // Trajectory PARENT (edition_id NULL).
        $parentId = $repo->create([
            'user_id'         => $u1,
            'trajectory_id'   => $trajId,
            'status'          => 'confirmed',
            'enrollment_path' => 'trajectory',
        ]);
        $this->assertIsInt($parentId);
        $this->testRegistrationIds[] = $parentId;

        // Two child edition-rows (parent-linked, trajectory_id NULL).
        $childA = $repo->create([
            'user_id'                => $u1,
            'edition_id'             => self::$testEditionId,
            'parent_registration_id' => $parentId,
            'status'                 => 'confirmed',
            'enrollment_path'        => 'trajectory',
        ]);
        $this->assertIsInt($childA);
        $this->testRegistrationIds[] = $childA;

        $childB = $repo->create([
            'user_id'                => $u2,
            'edition_id'             => self::$testEditionId2,
            'parent_registration_id' => $parentId,
            'status'                 => 'confirmed',
            'enrollment_path'        => 'trajectory',
        ]);
        $this->assertIsInt($childB);
        $this->testRegistrationIds[] = $childB;

        // Unrelated plain (non-trajectory) edition reg — must NEVER appear under T.
        $plainId = $repo->create([
            'user_id'    => $u2,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);
        $this->assertIsInt($plainId);
        $this->testRegistrationIds[] = $plainId;

        // --- WITHOUT the param: the unrelated plain reg IS reachable (control). ---
        $unscoped = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
            'per_page'      => 100,
        ]);
        $this->assertEquals(200, $unscoped->get_status());
        $unscopedIds = array_map('intval', array_column($unscoped->get_data()['items'], 'id'));
        $this->assertContains(
            $plainId,
            $unscopedIds,
            'Control: without trajectory_id the plain reg must be in the unscoped result',
        );

        // --- WITH the param: result is scoped to T's child edition-rows ONLY. ---
        $scoped = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
            'trajectory_id' => $trajId,
            'per_page'      => 100,
        ]);
        $this->assertEquals(200, $scoped->get_status());
        $scopedIds = array_map('intval', array_column($scoped->get_data()['items'], 'id'));

        // T's two children are present.
        $this->assertContains($childA, $scopedIds, 'T child A must appear under trajectory_id=T');
        $this->assertContains($childB, $scopedIds, 'T child B must appear under trajectory_id=T');

        // The parent row (edition_id NULL) is NOT present.
        $this->assertNotContains($parentId, $scopedIds, 'Trajectory parent (edition_id NULL) must not appear');

        // NEGATIVE / leak case: the unrelated plain reg must NOT appear under T.
        // This is the assertion that goes RED when the param is dropped — the
        // unscoped corpus (incl. $plainId) comes back instead of the T-scoped one.
        $this->assertNotContains(
            $plainId,
            $scopedIds,
            'Plain non-trajectory reg must NOT appear under trajectory_id=T (param was dropped if it does)',
        );

        // Every returned row belongs to T's child set (no leakage).
        foreach ($scopedIds as $id) {
            $this->assertContains(
                $id,
                [$childA, $childB],
                'Only T child edition-rows may appear under trajectory_id=T',
            );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($u1);
        wp_delete_user($u2);
    }

    // =========================================================================
    // FIX 8: out-of-allowlist group_by must be a 400, not a silent empty 200.
    // =========================================================================

    /**
     * @test
     * group_by=enrollment_data (NOT in GROUP_BY_ALLOWLIST) must return HTTP 400,
     * not a 200 with an empty envelope (which is indistinguishable from no-data
     * and silently changes the response SHAPE aggregates↔rows).
     */
    public function outOfAllowlistGroupByReturns400(): void
    {
        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
            'group_by'      => 'enrollment_data',
        ]);

        $this->assertEquals(
            400,
            $response->get_status(),
            'Out-of-allowlist group_by must be rejected with 400, not a silent empty 200',
        );
    }
}
