<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;

/**
 * Integration tests for GET /stride/v1/admin/trajectories/options
 *
 * Task 1.4b — lightweight searchable trajectory typeahead for the admin grid
 * trajectory filter. NOT the heavy getTrajectories payload.
 *
 * Tier A. This task:
 *  - registers a new REST route (wiring) → Seam test required
 *  - permission_callback canViewAdmin is a load-bearing security guard
 *    → M1 denial must be RED-first
 *  - scope=active restricts to non-terminal statuses (status-only, no dates) →
 *    branching logic to assert
 *  - q applies a server-side, $wpdb->prepare-bound LIKE → adversarial-safe search
 *
 * Run: ddev exec bash -c "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminTrajectoryOptionsEndpoint"
 */
final class AdminTrajectoryOptionsEndpointTest extends IntegrationTestCase
{
    private static ?int $coordinatorUserId = null;
    private static ?int $plainUserId = null;

    private static ?int $openTrajId = null;       // active (open) — in active scope
    private static ?int $terminalTrajId = null;   // archived — only in scope=all
    private static ?int $searchAlphaId = null;
    private static ?int $searchBetaId = null;
    private static string $searchToken = '';
    // Shared unique token on the open+terminal scope fixtures, so scope tests can
    // find them via q= instead of relying on page-1 presence. The endpoint pages
    // (per_page=50, ORDER BY post_title ASC); a shared dev DB can hold >50 other
    // trajectories that push these fixtures off page 1 — searching the token is
    // paging-proof and mirrors the already-robust qPerformsServerSideTitleSearch.
    private static string $scopeToken = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        $coordinatorUsername = 'coord_t14bt_' . uniqid();
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

        $plainUsername = 'plain_t14bt_' . uniqid();
        self::$plainUserId = wp_create_user(
            $plainUsername,
            'testpass123',
            $plainUsername . '@test.local',
        );
        if (is_wp_error(self::$plainUserId)) {
            throw new \RuntimeException('Could not create plain user: ' . self::$plainUserId->get_error_message());
        }

        // Shared unique token on the scope fixtures — lets scope tests locate them
        // by q= (paging-proof) rather than assuming page-1 presence.
        self::$scopeToken = 'Scopemark' . substr(uniqid(), -6);

        // Open (active) trajectory.
        $open = wp_insert_post([
            'post_title'  => self::$scopeToken . ' Open T14b',
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);
        // Terminal (archived) trajectory.
        $terminal = wp_insert_post([
            'post_title'  => self::$scopeToken . ' Archived T14b',
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($open) || is_wp_error($terminal)) {
            throw new \RuntimeException('Could not create trajectories');
        }
        self::$openTrajId     = (int) $open;
        self::$terminalTrajId = (int) $terminal;
        update_post_meta(self::$openTrajId, '_ntdst_status', 'open');
        update_post_meta(self::$terminalTrajId, '_ntdst_status', 'archived');

        // Two trajectories for the q (title search) test.
        self::$searchToken = 'Zynapse' . substr(uniqid(), -6);
        $alpha = wp_insert_post([
            'post_title'  => self::$searchToken . ' Pad',
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);
        $beta = wp_insert_post([
            'post_title'  => 'Unrelated Trajectory T14b',
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($alpha) || is_wp_error($beta)) {
            throw new \RuntimeException('Could not create search trajectories');
        }
        self::$searchAlphaId = (int) $alpha;
        self::$searchBetaId  = (int) $beta;
        update_post_meta(self::$searchAlphaId, '_ntdst_status', 'open');
        update_post_meta(self::$searchBetaId, '_ntdst_status', 'open');

        self::$testPosts[] = self::$openTrajId;
        self::$testPosts[] = self::$terminalTrajId;
        self::$testPosts[] = self::$searchAlphaId;
        self::$testPosts[] = self::$searchBetaId;
    }

    public static function tearDownAfterClass(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (self::$coordinatorUserId) {
            wp_delete_user(self::$coordinatorUserId);
        }
        if (self::$plainUserId) {
            wp_delete_user(self::$plainUserId);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$coordinatorUserId);
    }

    private function dispatch(string $method, string $path, array $params = []): \WP_REST_Response|\WP_Error
    {
        $request = new \WP_REST_Request($method, $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_do_request($request);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function findItem(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    // =========================================================================
    // M1 SECURITY: ANONYMOUS DENIAL (load-bearing — RED proof target)
    // =========================================================================

    /**
     * @test
     * Unauthenticated request → 401/403 (M1 permission_callback must deny).
     */
    public function unauthenticatedRequestIsDenied(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch('GET', '/stride/v1/admin/trajectories/options');

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

        $response = $this->dispatch('GET', '/stride/v1/admin/trajectories/options');

        $this->assertEquals(403, $response->get_status(), 'User without stride_view must be denied');
    }

    // =========================================================================
    // LIGHTWEIGHT SHAPE: {id, title, status} only — heavy keys absent
    // =========================================================================

    /**
     * @test
     */
    public function returnsLightweightOptionsForAdmin(): void
    {
        // Search the scope token so paging on a populated shared DB can't hide the
        // fixture (the endpoint returns page 1 of ORDER BY post_title ASC).
        $response = $this->dispatch('GET', '/stride/v1/admin/trajectories/options', [
            'scope' => 'all',
            'q'     => self::$scopeToken,
        ]);

        $this->assertEquals(200, $response->get_status(), 'Coordinator must receive 200');

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('perPage', $data);

        $item = $this->findItem($data['items'], self::$openTrajId);
        $this->assertNotNull($item, 'Open trajectory must be present in scope=all');

        // Lightweight keys present.
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertSame('open', $item['status'], 'status must be the _ntdst_status value');

        // Heavy getTrajectories keys MUST be absent.
        $this->assertArrayNotHasKey('mode', $item);
        $this->assertArrayNotHasKey('capacity', $item);
        $this->assertArrayNotHasKey('enrollmentCount', $item);
        $this->assertArrayNotHasKey('courses', $item);
        $this->assertArrayNotHasKey('enrollment_deadline', $item);
    }

    // =========================================================================
    // SCOPE: active (default) excludes terminal status; all includes it.
    // =========================================================================

    /**
     * @test
     * scope=active (default) EXCLUDES an archived (terminal) trajectory and
     * INCLUDES an open one. scope=all includes the archived one.
     */
    public function scopeActiveExcludesTerminalStatus(): void
    {
        // q= the shared scope token in BOTH scopes so the fixtures are findable
        // regardless of how many other trajectories the shared DB holds (paging).
        $active = $this->dispatch('GET', '/stride/v1/admin/trajectories/options', [
            'q' => self::$scopeToken,
        ]);
        $this->assertEquals(200, $active->get_status());
        $activeItems = $active->get_data()['items'];

        $this->assertNotNull(
            $this->findItem($activeItems, self::$openTrajId),
            'Open trajectory must be INCLUDED in active scope',
        );
        $this->assertNull(
            $this->findItem($activeItems, self::$terminalTrajId),
            'Archived (terminal) trajectory must be EXCLUDED from default active scope',
        );

        $all = $this->dispatch('GET', '/stride/v1/admin/trajectories/options', [
            'scope' => 'all',
            'q'     => self::$scopeToken,
        ]);
        $this->assertEquals(200, $all->get_status());
        $allItems = $all->get_data()['items'];

        $this->assertNotNull(
            $this->findItem($allItems, self::$terminalTrajId),
            'Archived trajectory must be INCLUDED in scope=all',
        );
    }

    // =========================================================================
    // Q: server-side title search
    // =========================================================================

    /**
     * @test
     */
    public function qPerformsServerSideTitleSearch(): void
    {
        $response = $this->dispatch('GET', '/stride/v1/admin/trajectories/options', [
            'scope' => 'all',
            'q'     => self::$searchToken,
        ]);

        $this->assertEquals(200, $response->get_status());
        $items = $response->get_data()['items'];

        $this->assertNotNull(
            $this->findItem($items, self::$searchAlphaId),
            'Trajectory matching q must be returned',
        );
        $this->assertNull(
            $this->findItem($items, self::$searchBetaId),
            'Trajectory NOT matching q must be absent',
        );
    }

    // =========================================================================
    // CAP / PAGING
    // =========================================================================

    /**
     * @test
     */
    public function perPageIsCappedAndPaged(): void
    {
        $response = $this->dispatch('GET', '/stride/v1/admin/trajectories/options', [
            'scope'    => 'all',
            'per_page' => 9999,
        ]);

        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();

        $this->assertArrayHasKey('perPage', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertLessThanOrEqual(
            100,
            (int) $data['perPage'],
            'per_page must clamp to the cap (<= 100), never honor a 9999 dump',
        );
        $this->assertGreaterThanOrEqual(1, (int) $data['page']);
        $this->assertLessThanOrEqual((int) $data['perPage'], count($data['items']));
    }
}
