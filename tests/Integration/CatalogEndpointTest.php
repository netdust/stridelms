<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Task G1 (audit 2.2) — seam test for the `stride_catalog_page` endpoint.
 *
 * Exercises the REAL `ntdst/api_data/*` filter chain (the boundary being
 * wired — no mocks): the theme's CatalogEndpoint must be registered and
 * produce server-rendered card slices. Includes the adversarial cases
 * (unknown catalog, absurd page) and the AF-4 pagination boundary: at
 * exactly the server cap and one past it, no card is dropped or doubled
 * across consecutive pages.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CatalogEndpoint"
 */
final class CatalogEndpointTest extends IntegrationTestCase
{
    private function callEndpoint(array $params): mixed
    {
        return apply_filters('ntdst/api_data/stride_catalog_page', [], $params);
    }

    /** @test */
    public function endpointIsRegisteredOnTheApiDataChain(): void
    {
        $this->assertTrue(
            (bool) has_filter('ntdst/api_data/stride_catalog_page'),
            'CatalogEndpoint must be registered via ntdst/api_data (INV-2 — framework owns the nonce)',
        );
    }

    /**
     * @test
     *
     * AF-4 boundary: seed past the server cap; every seeded card appears on
     * exactly ONE page — pagination boundary card neither dropped nor doubled.
     */
    public function paginationBoundaryCardIsNeitherDroppedNorDoubled(): void
    {
        $future = date('Y-m-d', strtotime('+30 days'));
        $seeded = [];
        // Seed enough to guarantee at least two pages regardless of live data.
        for ($i = 0; $i < STRIDENCE_CATALOG_PER_PAGE + 4; $i++) {
            $seeded[] = $this->createTestEdition(['meta' => [
                '_ntdst_status'     => 'open',
                '_ntdst_start_date' => $future,
                '_ntdst_end_date'   => $future,
            ]]);
        }

        // Walk every page through the real filter chain.
        $pages = [];
        $page = 1;
        do {
            $result = $this->callEndpoint(['catalog' => 'klassikaal', 'page' => $page]);
            $this->assertIsArray($result, "page {$page} must return a result array");
            $this->assertSame($page, $result['page']);
            $this->assertLessThanOrEqual(STRIDENCE_CATALOG_PER_PAGE, $result['count']);
            $pages[$page] = (string) $result['html'];
            $page++;
        } while (!empty($result['has_more']) && $page <= 25);

        $this->assertGreaterThanOrEqual(2, count($pages), 'seed must span at least two pages');

        foreach ($seeded as $editionId) {
            $needle = 'href="' . esc_url(get_permalink($editionId)) . '"';
            $appearances = 0;
            foreach ($pages as $html) {
                if (str_contains($html, $needle)) {
                    $appearances++;
                }
            }
            $this->assertSame(
                1,
                $appearances,
                "edition #{$editionId} appears on {$appearances} pages — boundary card dropped or doubled",
            );
        }
    }

    /** @test */
    public function unknownCatalogIsRejected(): void
    {
        $result = $this->callEndpoint(['catalog' => 'bogus', 'page' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_catalog', $result->get_error_code());
    }

    /** @test */
    public function absurdPageReturnsEmptySliceWithoutFatal(): void
    {
        $result = $this->callEndpoint(['catalog' => 'klassikaal', 'page' => 9999]);

        $this->assertIsArray($result);
        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['count']);
        $this->assertFalse($result['has_more']);
    }

    /** @test */
    public function themeFilterScopesTheSlice(): void
    {
        $term = term_exists('endpoint-test-theme', 'stride_theme') ?: wp_insert_term('endpoint-test-theme', 'stride_theme');
        $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;

        $courseId = $this->createTestCourse();
        wp_set_object_terms($courseId, [$termId], 'stride_theme');

        $future = date('Y-m-d', strtotime('+30 days'));
        $themedEdition = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => $courseId,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);
        $plainEdition = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);

        $result = $this->callEndpoint(['catalog' => 'klassikaal', 'page' => 1, 'theme' => 'endpoint-test-theme']);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['total'], 'only the themed edition matches the filter');
        $this->assertStringContainsString('href="' . esc_url(get_permalink($themedEdition)) . '"', $result['html']);
        $this->assertStringNotContainsString('href="' . esc_url(get_permalink($plainEdition)) . '"', $result['html']);
    }
}
