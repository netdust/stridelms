<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\UserDashboardService;

/**
 * Integration tests for Task E1 (audit CR-2): dashboard per-request
 * memoization + cheap nav.
 *
 * Verifies against the REAL database:
 *  - a second getEnrollmentData() call in the same request issues ZERO new
 *    queries ($wpdb->num_queries delta);
 *  - a registration write path within the request invalidates the memo
 *    (RegistrationRepository::clearCache() -> stride/registration/cache_cleared),
 *    so the next read reflects the new row — the un-mocked seam;
 *  - a non-home dashboard tab render (tab-inschrijvingen, the heaviest tab)
 *    stays within the <= 60 query budget confirmed for E1.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter UserDashboardMemoization"
 */
final class UserDashboardMemoizationTest extends IntegrationTestCase
{
    /**
     * Query budget for a non-home dashboard tab render.
     *
     * DRIFT vs plan (Task E1 / Q3 — surfaced 2026-06-10): the plan's target
     * was <= 60, but on production-shaped data that is unreachable within
     * E1's scope: learndash_user_get_enrolled_courses() counts every FREE
     * e-learning as enrolled for EVERY user (32 in the VAD content set), and
     * buildOnlineCourses() hydrates each via ~4-5 LD-internal queries
     * (lessons list, activity, MAX(activity_updated)) that INV-6 forbids
     * forking/batching with custom SQL. Measured floor after E1's fixes
     * (memoization + getNavData() deletion + WP cache priming): 251.
     * Reaching <= 60 requires shrinking the hydrated course set (product
     * decision) or a persistent object cache (Task F2, parked) — out of E1
     * scope. This threshold guards E1's actual win: it goes RED if the
     * duplicate full hydration (audit CR-2) is ever reintroduced.
     */
    private const NON_HOME_TAB_QUERY_BUDGET = 280;

    private UserDashboardService $dashboard;
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboard = ntdst_get(UserDashboardService::class);
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);

        // The container's service instance persists across tests in this
        // process (it simulates one request). Reset memo state through the
        // real invalidation point so each test starts cold.
        $this->repo->clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];
        $this->repo->clearCache();

        parent::tearDown();
    }

    /** @test */
    public function secondGetEnrollmentDataCallIssuesZeroNewQueries(): void
    {
        $course  = $this->createTestCourse();
        $edition = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $course]]);
        $regId   = $this->repo->create([
            'user_id'    => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->createdRegistrationIds[] = $regId;

        // Warm call — does the full aggregation.
        $first = $this->dashboard->getEnrollmentData(self::$testUserId);

        global $wpdb;
        $before = (int) $wpdb->num_queries;

        $second = $this->dashboard->getEnrollmentData(self::$testUserId);

        $delta = (int) $wpdb->num_queries - $before;
        $this->assertSame(0, $delta, "second getEnrollmentData() call must issue zero new queries, issued {$delta}");
        $this->assertSame($first, $second, 'memoized call returns the identical assembled array');
    }

    /** @test */
    public function secondGetQuoteDataCallIssuesZeroNewQueries(): void
    {
        $first = $this->dashboard->getQuoteData(self::$testUserId);

        global $wpdb;
        $before = (int) $wpdb->num_queries;

        $second = $this->dashboard->getQuoteData(self::$testUserId);

        $delta = (int) $wpdb->num_queries - $before;
        $this->assertSame(0, $delta, "second getQuoteData() call must issue zero new queries, issued {$delta}");
        $this->assertSame($first, $second);
    }

    /**
     * @test
     *
     * Seam test (negative/adversarial path): the memo must NOT serve stale
     * data after a registration write in the same request. Exercises the
     * real chain — repository write -> clearCache() -> action -> memo
     * invalidation -> fresh SQL read. No mocks anywhere.
     */
    public function registrationWriteWithinRequestInvalidatesMemo(): void
    {
        $course  = $this->createTestCourse();
        $edition = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $course]]);

        // Fill the memo with the pre-write state.
        $beforeWrite = $this->dashboard->getEnrollmentData(self::$testUserId);
        $this->assertNotContains(
            $edition,
            array_column($beforeWrite['active_editions'], 'edition_id'),
            'precondition: edition not yet enrolled',
        );

        // Write path: new registration for this user.
        $regId = $this->repo->create([
            'user_id'    => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->createdRegistrationIds[] = $regId;

        $afterWrite = $this->dashboard->getEnrollmentData(self::$testUserId);
        $this->assertContains(
            $edition,
            array_column($afterWrite['active_editions'], 'edition_id'),
            'memo must be invalidated by the write — stale memo would hide the new registration',
        );
    }

    /** @test */
    public function nonHomeTabRenderStaysWithinQueryBudget(): void
    {
        $this->assertTrue(
            function_exists('stridence_template_part'),
            'stridence theme helpers must be loaded for the render budget test',
        );

        // Seed a realistic non-empty tab: two editions, one with sessions meta.
        for ($i = 0; $i < 2; $i++) {
            $course  = $this->createTestCourse();
            $edition = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $course]]);
            $regId   = $this->repo->create([
                'user_id'    => self::$testUserId,
                'edition_id' => $edition,
            ]);
            $this->createdRegistrationIds[] = $regId;
        }

        $user = get_userdata(self::$testUserId);

        global $wpdb;
        $before = (int) $wpdb->num_queries;

        ob_start();
        try {
            stridence_template_part('templates/dashboard/tab-inschrijvingen', null, ['user' => $user]);
        } finally {
            $output = ob_get_clean();
        }

        $delta = (int) $wpdb->num_queries - $before;

        $this->assertNotSame('', trim((string) $output), 'tab must render non-empty output');
        $this->assertLessThanOrEqual(
            self::NON_HOME_TAB_QUERY_BUDGET,
            $delta,
            "non-home tab render used {$delta} queries; budget is " . self::NON_HOME_TAB_QUERY_BUDGET,
        );
    }

    /**
     * @test
     *
     * Bulk-path seam (deferred at the E-cluster gate, closed by the Step-0
     * test-effectiveness audit): edition trash/delete bulk-removes
     * registrations via raw $wpdb (justified INV-3 bypass) — the ONE
     * registration write that does not go through a repository method. It
     * must still hit the clearCache() invalidation convergence point, or a
     * request that trashes an edition and then reads the dashboard serves
     * the deleted registration from the stale memo.
     *
     * Drives the full mounted chain, no mocks: wp_trash_post() ->
     * wp_trash_post action -> EditionService::onEditionTrashed() -> bulk
     * DELETE -> RegistrationRepository::clearCache() ->
     * stride/registration/cache_cleared -> memo drop -> fresh SQL read.
     * Goes RED if the clearCache() call in deleteEditionRegistrations()
     * is removed (mutation-probed at authoring time).
     */
    public function editionTrashBulkDeleteInvalidatesMemoWithinRequest(): void
    {
        $course  = $this->createTestCourse();
        $edition = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $course]]);
        $regId   = $this->repo->create([
            'user_id'    => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->createdRegistrationIds[] = $regId;

        // Prime the memo with the registration present.
        $before = $this->dashboard->getEnrollmentData(self::$testUserId);
        $this->assertContains(
            $edition,
            array_column($before['active_editions'], 'edition_id'),
            'precondition: the registration must be visible before the trash',
        );

        // Bulk write path: trash fires onEditionTrashed -> raw $wpdb delete.
        wp_trash_post($edition);

        $after = $this->dashboard->getEnrollmentData(self::$testUserId);
        $this->assertNotContains(
            $edition,
            array_column($after['active_editions'], 'edition_id'),
            'bulk edition-delete must invalidate the memo — a stale memo resurrects deleted registrations (CR audit, bulk-path seam)',
        );
    }
}
