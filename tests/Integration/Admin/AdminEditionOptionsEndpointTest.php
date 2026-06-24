<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;

/**
 * Integration tests for GET /stride/v1/admin/editions/options
 *
 * Task 1.4a — lightweight searchable edition typeahead for the admin grid
 * filter + group-by source + queue scoping. NOT the heavy getEditions payload.
 *
 * Tier A. This task:
 *  - registers a new REST route (wiring) → Seam test required
 *  - the permission_callback canViewAdmin is a load-bearing security guard
 *    → M1 denial must be RED-first
 *  - scope=active applies an effective-status (INV-7) + date predicate with a
 *    sessionless (§10.7) carve-out → branching logic to assert
 *  - q applies a server-side, $wpdb->prepare-bound LIKE → adversarial-safe search
 *
 * Run: ddev exec bash -c "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionOptionsEndpoint"
 */
final class AdminEditionOptionsEndpointTest extends IntegrationTestCase
{
    private static ?int $coordinatorUserId = null;
    private static ?int $plainUserId = null;

    private static ?int $pastEditionId = null;
    private static ?int $futureEditionId = null;
    private static ?int $datelessEditionId = null;
    private static ?int $searchAlphaId = null;
    private static ?int $searchBetaId = null;
    private static string $searchToken = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        // Coordinator with stride_view capability
        $coordinatorUsername = 'coord_t14a_' . uniqid();
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
        $plainUsername = 'plain_t14a_' . uniqid();
        self::$plainUserId = wp_create_user(
            $plainUsername,
            'testpass123',
            $plainUsername . '@test.local',
        );
        if (is_wp_error(self::$plainUserId)) {
            throw new \RuntimeException('Could not create plain user: ' . self::$plainUserId->get_error_message());
        }

        // Clearly-past edition (start + end both in the past)
        $past = wp_insert_post([
            'post_title'  => 'Edition Past T14a',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $future = wp_insert_post([
            'post_title'  => 'Edition Future T14a',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $dateless = wp_insert_post([
            'post_title'  => 'Edition Dateless T14a',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($past) || is_wp_error($future) || is_wp_error($dateless)) {
            throw new \RuntimeException('Could not create editions');
        }
        self::$pastEditionId     = (int) $past;
        self::$futureEditionId   = (int) $future;
        self::$datelessEditionId = (int) $dateless;

        $pastDate   = wp_date('Y-m-d', strtotime('-30 days'));
        $futureDate = wp_date('Y-m-d', strtotime('+30 days'));

        update_post_meta(self::$pastEditionId, '_ntdst_start_date', $pastDate);
        update_post_meta(self::$pastEditionId, '_ntdst_end_date', $pastDate);

        update_post_meta(self::$futureEditionId, '_ntdst_start_date', $futureDate);
        update_post_meta(self::$futureEditionId, '_ntdst_end_date', $futureDate);
        // Dateless: deliberately NO start/end meta (sessionless §10.7 carve-out)

        // Two editions for the q (title search) test — unique token in only one.
        self::$searchToken = 'Zynapse' . substr(uniqid(), -6);
        $alpha = wp_insert_post([
            'post_title'  => self::$searchToken . ' Workshop',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $beta = wp_insert_post([
            'post_title'  => 'Unrelated Title T14a',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($alpha) || is_wp_error($beta)) {
            throw new \RuntimeException('Could not create search editions');
        }
        self::$searchAlphaId = (int) $alpha;
        self::$searchBetaId  = (int) $beta;
        // Give them future dates so they appear in default active scope too.
        update_post_meta(self::$searchAlphaId, '_ntdst_start_date', $futureDate);
        update_post_meta(self::$searchBetaId, '_ntdst_start_date', $futureDate);

        self::$testPosts[] = self::$pastEditionId;
        self::$testPosts[] = self::$futureEditionId;
        self::$testPosts[] = self::$datelessEditionId;
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

        $response = $this->dispatch('GET', '/stride/v1/admin/editions/options');

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

        $response = $this->dispatch('GET', '/stride/v1/admin/editions/options');

        $this->assertEquals(403, $response->get_status(), 'User without stride_view must be denied');
    }

    // =========================================================================
    // LIGHTWEIGHT SHAPE: {id, title, effective_status} only — heavy keys absent
    // =========================================================================

    /**
     * @test
     * Returns a lightweight envelope of {id, title, effective_status} items for
     * a canViewAdmin user, with NONE of the heavy getEditions fields.
     */
    public function returnsLightweightOptionsForAdmin(): void
    {
        $response = $this->dispatch('GET', '/stride/v1/admin/editions/options', [
            'scope' => 'all',
        ]);

        $this->assertEquals(200, $response->get_status(), 'Coordinator must receive 200');

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('perPage', $data);

        $item = $this->findItem($data['items'], self::$futureEditionId);
        $this->assertNotNull($item, 'Future edition must be present in scope=all');

        // Lightweight keys present
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('effective_status', $item);
        $this->assertIsString($item['effective_status'], 'effective_status must be the enum ->value string');

        // Heavy getEditions keys MUST be absent
        $this->assertArrayNotHasKey('sessions', $item);
        $this->assertArrayNotHasKey('registrationCount', $item);
        $this->assertArrayNotHasKey('registeredCount', $item);
        $this->assertArrayNotHasKey('course', $item);
        $this->assertArrayNotHasKey('venue', $item);
        $this->assertArrayNotHasKey('capacity', $item);
    }

    // =========================================================================
    // SCOPE: active (default) excludes past, includes future AND dateless;
    //        all includes past too.
    // =========================================================================

    /**
     * @test
     * scope=active (default) EXCLUDES a clearly-past edition, INCLUDES a future
     * edition AND a dateless edition (sessionless §10.7). scope=all includes past.
     */
    public function scopeActiveExcludesPastButKeepsFutureAndDateless(): void
    {
        // Default (no scope param) == active. per_page=100 (the endpoint cap) so
        // this assertion is robust against a polluted shared integration DB: the
        // dateless edition sorts NULL-LAST, so with >50 (the default page size)
        // active editions accumulated by sibling fixtures it would fall onto page
        // 2 and be a false negative. The PRODUCT keeps it in active scope (proven:
        // SQL NULL-permitting predicate + non-terminal effective status); this
        // page-size only stops fixture accumulation from hiding it on page 1.
        $active = $this->dispatch('GET', '/stride/v1/admin/editions/options', ['per_page' => 100]);
        $this->assertEquals(200, $active->get_status());
        $activeItems = $active->get_data()['items'];

        $this->assertNull(
            $this->findItem($activeItems, self::$pastEditionId),
            'Past edition must be EXCLUDED from default (active) scope',
        );
        $this->assertNotNull(
            $this->findItem($activeItems, self::$futureEditionId),
            'Future edition must be INCLUDED in active scope',
        );
        $this->assertNotNull(
            $this->findItem($activeItems, self::$datelessEditionId),
            'Dateless edition must REMAIN in active scope (sessionless §10.7)',
        );

        // scope=all includes the past one (per_page=100 for the same shared-DB
        // isolation reason — the past edition must not be hidden on page 2).
        $all = $this->dispatch('GET', '/stride/v1/admin/editions/options', ['scope' => 'all', 'per_page' => 100]);
        $this->assertEquals(200, $all->get_status());
        $allItems = $all->get_data()['items'];

        $this->assertNotNull(
            $this->findItem($allItems, self::$pastEditionId),
            'Past edition must be INCLUDED in scope=all',
        );
    }

    // =========================================================================
    // Q: server-side title search
    // =========================================================================

    /**
     * @test
     * q does a server-side title search: searching a substring of one edition's
     * title returns that edition and not the unrelated one.
     */
    public function qPerformsServerSideTitleSearch(): void
    {
        $response = $this->dispatch('GET', '/stride/v1/admin/editions/options', [
            'scope' => 'all',
            'q'     => self::$searchToken,
        ]);

        $this->assertEquals(200, $response->get_status());
        $items = $response->get_data()['items'];

        $this->assertNotNull(
            $this->findItem($items, self::$searchAlphaId),
            'Edition matching q must be returned',
        );
        $this->assertNull(
            $this->findItem($items, self::$searchBetaId),
            'Edition NOT matching q must be absent',
        );
    }

    // =========================================================================
    // CAP / PAGING
    // =========================================================================

    // =========================================================================
    // CR-2: active-scope pagination must be CONSISTENT.
    //
    // OLD bug: scope=active dropped editions whose EFFECTIVE status is terminal
    // in a PHP loop AFTER the SQL LIMIT, while `total` was the PRE-filter SQL
    // COUNT. Net: an active page could return FEWER than perPage items, and
    // total / perPage / items.length disagreed → broken typeahead paging.
    //
    // A Cancelled-stored edition with a FUTURE start_date survives the SQL date
    // pre-filter (meta_value >= twoDaysAgo) but is terminal under
    // getEffectiveStatusFromPrefetched → it WAS counted in `total` yet dropped
    // from `items`. Sorting it first (earliest start_date) put it on page 1
    // under the old behavior, shortening that page.
    // =========================================================================

    /**
     * @test
     * Build a q-scoped corpus of 3 active (non-terminal, future) editions + 1
     * Cancelled (terminal, future, earliest-dated) edition. Under scope=active:
     *  (a) total == 3 (the NON-terminal count), NOT 4 (the pre-filter count);
     *  (b) a full page returns exactly min(total, perPage) items, with the
     *      terminal edition NEVER present;
     *  (c) page 1 + page 2 (perPage=2) together return all 3 active editions
     *      with no gap and no overlap.
     */
    public function activeScopePaginationIsConsistentWithEffectiveStatusFilter(): void
    {
        $token   = 'CR2Pag' . substr(uniqid(), -6);
        $base    = strtotime('+10 days');
        $created = [];

        // 3 active (Open) editions, future-dated.
        $activeIds = [];
        for ($i = 0; $i < 3; $i++) {
            $id = wp_insert_post([
                'post_title'  => "{$token} Active {$i}",
                'post_type'   => 'vad_edition',
                'post_status' => 'publish',
            ]);
            $this->assertIsInt($id);
            // Stagger dates AFTER the terminal one so the terminal sorts first.
            update_post_meta($id, '_ntdst_start_date', wp_date('Y-m-d', $base + ($i + 1) * 86400));
            update_post_meta($id, '_ntdst_status', \Stride\Domain\OfferingStatus::Open->value);
            $activeIds[]       = $id;
            $created[]         = $id;
            self::$testPosts[] = $id;
        }

        // 1 Cancelled (terminal) edition, future-dated, EARLIEST → sorts first
        // → lands on page 1 under the old (buggy) post-LIMIT PHP drop.
        $terminalId = wp_insert_post([
            'post_title'  => "{$token} Cancelled",
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $this->assertIsInt($terminalId);
        update_post_meta($terminalId, '_ntdst_start_date', wp_date('Y-m-d', $base));
        update_post_meta($terminalId, '_ntdst_status', \Stride\Domain\OfferingStatus::Cancelled->value);
        $created[]         = $terminalId;
        self::$testPosts[] = $terminalId;

        // (a) total must be the post-effective-status-filter count: 3, not 4.
        $full = $this->dispatch('GET', '/stride/v1/admin/editions/options', [
            'scope'    => 'active',
            'q'        => $token,
            'per_page' => 50,
        ]);
        $this->assertEquals(200, $full->get_status());
        $fullData = $full->get_data();

        $this->assertSame(
            3,
            (int) $fullData['total'],
            'total must equal the 3 NON-terminal editions, not the pre-filter count of 4',
        );

        // (b) a full page returns exactly min(total, perPage) = 3 items, and the
        //     terminal edition is never present.
        $this->assertCount(
            3,
            $fullData['items'],
            'A full page must return exactly min(total, perPage) items — no short page',
        );
        $this->assertNull(
            $this->findItem($fullData['items'], $terminalId),
            'Terminal (Cancelled) edition must NOT appear in active scope',
        );
        foreach ($activeIds as $aid) {
            $this->assertNotNull(
                $this->findItem($fullData['items'], $aid),
                "Active edition {$aid} must be present in the full active page",
            );
        }

        // (c) page 1 + page 2 (perPage=2) cover all 3 active editions, no gap/overlap.
        $p1 = $this->dispatch('GET', '/stride/v1/admin/editions/options', [
            'scope' => 'active', 'q' => $token, 'per_page' => 2, 'page' => 1,
        ]);
        $p2 = $this->dispatch('GET', '/stride/v1/admin/editions/options', [
            'scope' => 'active', 'q' => $token, 'per_page' => 2, 'page' => 2,
        ]);
        $this->assertEquals(200, $p1->get_status());
        $this->assertEquals(200, $p2->get_status());

        $p1Ids = array_map('intval', array_column($p1->get_data()['items'], 'id'));
        $p2Ids = array_map('intval', array_column($p2->get_data()['items'], 'id'));

        // Page 1 is a FULL page of 2 (the terminal must not steal a slot).
        $this->assertCount(2, $p1Ids, 'Page 1 must be a full page of 2 active editions');
        $this->assertCount(1, $p2Ids, 'Page 2 must hold the remaining 1 active edition');

        // No overlap.
        $this->assertEmpty(array_intersect($p1Ids, $p2Ids), 'Pages must not overlap');

        // Union == all 3 active editions, no gap, terminal absent.
        $union = array_merge($p1Ids, $p2Ids);
        sort($union);
        $expected = $activeIds;
        sort($expected);
        $this->assertSame($expected, $union, 'page1 ∪ page2 must equal all active editions (no gap)');
        $this->assertNotContains($terminalId, $union, 'Terminal edition must never appear across pages');

        // Cleanup
        foreach ($created as $id) {
            wp_delete_post($id, true);
        }
    }

    /**
     * @test
     * per_page is capped: requesting an absurd per_page clamps to the cap, and
     * the envelope reports the (clamped) perPage and page.
     */
    public function perPageIsCappedAndPaged(): void
    {
        $response = $this->dispatch('GET', '/stride/v1/admin/editions/options', [
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
        // Returned rows must never exceed the clamped page size.
        $this->assertLessThanOrEqual((int) $data['perPage'], count($data['items']));
    }
}
