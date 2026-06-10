<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Task G1 (audit 2.2 / CR-3) — the N-independence query budget for the
 * catalog render mechanism.
 *
 * Contract: building the eligible-items list AND rendering a full page
 * slice (24 cards) stays within the query budget at 28 seeded editions
 * AND at 28+20 — the per-card cost MUST NOT scale with catalog size.
 * RED against the pre-batch implementation (every card issued its own
 * getEffectiveStatus / get_post / isEnrolled / registered-count lookups).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CatalogRenderQueryBudget"
 */
final class CatalogRenderQueryBudgetTest extends IntegrationTestCase
{
    /**
     * Query budget for items-list + 24-card render (confirmed by Stefan).
     */
    private const QUERY_BUDGET = 40;

    /**
     * Tolerance between the two measurements. The mechanism is batched, so
     * adding editions must not add per-card queries; a small slack absorbs
     * incidental one-off lookups (e.g. a term cached during run 1).
     */
    private const N_INDEPENDENCE_TOLERANCE = 3;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        ntdst_get(RegistrationRepository::class)->clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];
        ntdst_get(RegistrationRepository::class)->clearCache();

        parent::tearDown();
    }

    private function seedKlassikaalEditions(int $count): void
    {
        $term = term_exists('klassikaal', 'stride_format') ?: wp_insert_term('klassikaal', 'stride_format');
        $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;

        $themeTerm = term_exists('budget-test-theme', 'stride_theme') ?: wp_insert_term('budget-test-theme', 'stride_theme');
        $themeTermId = is_array($themeTerm) ? (int) $themeTerm['term_id'] : (int) $themeTerm;

        $future = date('Y-m-d', strtotime('+30 days'));

        for ($i = 0; $i < $count; $i++) {
            // One course per two editions — realistic shape.
            if ($i % 2 === 0) {
                $courseId = $this->createTestCourse();
                wp_set_object_terms($courseId, [$termId], 'stride_format');
                wp_set_object_terms($courseId, [$themeTermId], 'stride_theme');
            }

            $editionId = $this->createTestEdition(['meta' => [
                '_ntdst_status'     => 'open',
                '_ntdst_course_id'  => $courseId,
                '_ntdst_start_date' => $future,
                '_ntdst_end_date'   => $future,
                '_ntdst_venue'      => 'Testzaal ' . $i,
            ]]);

            // Half the editions get a published session (Open), half stay
            // sessionless (effective Announcement) — both branches render.
            if ($i % 2 === 0) {
                $sessionId = wp_insert_post([
                    'post_title' => 'Budget Session ' . $i,
                    'post_type' => 'vad_session',
                    'post_status' => 'publish',
                ]);
                self::$testPosts[] = $sessionId;
                update_post_meta($sessionId, '_ntdst_edition_id', $editionId);
            }
        }
    }

    /**
     * Measure queries for the full catalog mechanism: eligible-items list +
     * a 24-card slice render. Cold object cache for a fair, comparable run.
     */
    private function measureCatalogRender(): array
    {
        wp_cache_flush();
        ntdst_get(RegistrationRepository::class)->clearCache();

        global $wpdb;
        $before = (int) $wpdb->num_queries;

        $items = stridence_catalog_items('klassikaal');
        $html = stridence_catalog_render_cards(
            array_slice($items, 0, STRIDENCE_CATALOG_PER_PAGE),
            get_current_user_id() ?: null,
        );

        return [(int) $wpdb->num_queries - $before, $items, $html];
    }

    /** @test */
    public function catalogRenderQueryCountIsIndependentOfCatalogSize(): void
    {
        $this->assertTrue(
            function_exists('stridence_catalog_items'),
            'stridence theme catalog helpers must be loaded',
        );

        // Logged-in fixture with one confirmed registration: the enrolled
        // branch must be part of the measured render (AF-4 logged-in case).
        $this->actingAs(self::$testUserId);

        $this->seedKlassikaalEditions(28);

        $enrolledEdition = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_start_date' => date('Y-m-d', strtotime('+5 days')),
            '_ntdst_end_date'   => date('Y-m-d', strtotime('+5 days')),
        ]]);
        $regId = ntdst_get(RegistrationRepository::class)->create([
            'user_id'    => self::$testUserId,
            'edition_id' => $enrolledEdition,
            'status'     => 'confirmed',
        ]);
        $this->createdRegistrationIds[] = $regId;

        [$queriesAt28, $itemsAt28, $htmlAt28] = $this->measureCatalogRender();

        $this->assertGreaterThanOrEqual(28, count($itemsAt28), 'seeded editions must be eligible');
        $this->assertNotSame('', trim($htmlAt28), 'catalog slice must render non-empty HTML');
        $this->assertLessThanOrEqual(
            self::QUERY_BUDGET,
            $queriesAt28,
            "catalog render at 28 editions used {$queriesAt28} queries; budget is " . self::QUERY_BUDGET,
        );

        // Grow the catalog — the slice cost must not grow with it.
        $this->seedKlassikaalEditions(20);

        [$queriesAt48, $itemsAt48, $htmlAt48] = $this->measureCatalogRender();

        $this->assertGreaterThanOrEqual(48, count($itemsAt48));
        $this->assertNotSame('', trim($htmlAt48));
        $this->assertLessThanOrEqual(
            self::QUERY_BUDGET,
            $queriesAt48,
            "catalog render at 48 editions used {$queriesAt48} queries; budget is " . self::QUERY_BUDGET,
        );

        // THE contract: N-independence. Per-card lookups would add
        // ~5 queries per extra rendered/eligible edition.
        $this->assertLessThanOrEqual(
            $queriesAt28 + self::N_INDEPENDENCE_TOLERANCE,
            $queriesAt48,
            "query count grew with catalog size ({$queriesAt28} -> {$queriesAt48}) — per-card N+1 reintroduced",
        );
    }

    /**
     * @test
     *
     * AF-4 empty/zero edge: with zero eligible editions the helper chain
     * returns cleanly and stays trivially within budget.
     */
    public function emptyCatalogRendersCleanlyWithinBudget(): void
    {
        wp_cache_flush();

        global $wpdb;
        $before = (int) $wpdb->num_queries;

        $items = stridence_catalog_items('klassikaal');
        $eligible = array_filter(
            $items,
            static fn(array $i): bool => str_starts_with((string) ($i['edition']['title'] ?? ''), 'NOPE-'),
        );
        $html = stridence_catalog_render_cards(array_slice($eligible, 0, STRIDENCE_CATALOG_PER_PAGE), null);

        $delta = (int) $wpdb->num_queries - $before;

        $this->assertSame('', $html, 'empty slice renders empty string, no fatal');
        $this->assertLessThanOrEqual(self::QUERY_BUDGET, $delta);
    }
}
