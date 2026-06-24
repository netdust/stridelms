<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminStatsService;

/**
 * Cache-correctness tests for Task S6 (admin-backend-cleanup CLUSTER F):
 * AdminStatsService::getStats() is wrapped in a transient (key
 * `stride_admin_stats`, TTL 120s) with write-invalidation, mirroring the
 * existing getActionQueueItems / stride_action_queue pattern. The dashboard
 * poll re-fired ~15 queries every call; none of the stats are real-time
 * critical, and the Phase 2 frontend polls this more.
 *
 * The risk is cache CORRECTNESS, so the load-bearing assertions are:
 *   1. CACHE SET — after the first getStats() the `stride_admin_stats` transient
 *      holds the computed value.
 *   2. CACHE HIT — a second call within the TTL returns the cached value
 *      verbatim, even when the transient has been mutated underneath (proving
 *      the second call READS the cache rather than recomputing).
 *   3. STALE PATH (the denial path) — deleting the transient (the invalidation
 *      event) forces a fresh compute on the next call; the recomputed value is
 *      the real, current data, not the stale cached payload.
 *
 * Run: ddev exec bash -c "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminStatsCache"
 */
final class AdminStatsCacheTest extends IntegrationTestCase
{
    private const TRANSIENT_KEY = 'stride_admin_stats';

    private AdminStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(AdminStatsService::class);
        delete_transient(self::TRANSIENT_KEY);
    }

    protected function tearDown(): void
    {
        delete_transient(self::TRANSIENT_KEY);
        parent::tearDown();
    }

    public function testFirstCallPopulatesTheTransient(): void
    {
        $this->assertFalse(
            get_transient(self::TRANSIENT_KEY),
            'precondition: transient is empty before the first call',
        );

        $stats = $this->service->getStats();

        $cached = get_transient(self::TRANSIENT_KEY);
        $this->assertIsArray($cached, 'getStats() must populate the stride_admin_stats transient');
        $this->assertSame($stats, $cached, 'the cached payload equals the returned payload');
        $this->assertArrayHasKey('upcomingEditions', $cached, 'the cached payload is the full stats shape');
    }

    public function testSecondCallReturnsTheCachedValueWithoutRecomputing(): void
    {
        // First call warms the cache.
        $this->service->getStats();

        // Mutate the cached payload under the service. If the second call
        // recomputed it would NOT contain this sentinel; if it reads the cache
        // (the intended behavior) the sentinel survives — proving cache-hit.
        $sentinel = get_transient(self::TRANSIENT_KEY);
        $this->assertIsArray($sentinel);
        $sentinel['__cache_probe__'] = 'hit';
        set_transient(self::TRANSIENT_KEY, $sentinel, 120);

        $second = $this->service->getStats();

        $this->assertArrayHasKey(
            '__cache_probe__',
            $second,
            'a second getStats() within the TTL must return the CACHED value (no recompute)',
        );
        $this->assertSame('hit', $second['__cache_probe__']);
    }

    public function testInvalidationForcesAFreshComputeOnTheNextCall(): void
    {
        // Warm + poison the cache with a value the real compute would never
        // produce, then bust it (the registration/quote-write invalidation).
        $this->service->getStats();
        set_transient(self::TRANSIENT_KEY, ['__stale__' => true], 120);

        // The invalidation event (mirrors delete_transient('stride_admin_stats')
        // wired to the registration/quote write hooks).
        delete_transient(self::TRANSIENT_KEY);

        $fresh = $this->service->getStats();

        $this->assertArrayNotHasKey(
            '__stale__',
            $fresh,
            'after invalidation getStats() must recompute fresh data, not serve the stale payload',
        );
        $this->assertArrayHasKey('upcomingEditions', $fresh, 'the fresh value is a real, recomputed stats payload');
    }
}
