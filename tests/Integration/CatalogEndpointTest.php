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
     * CR-G1 (CRITICAL): the catalog is a PUBLIC surface. The framework's
     * check_nonce_permission() only issues anonymous nonces for actions on
     * the `ntdst/api/public_actions` filter — without this registration,
     * every guest "Toon meer" / theme-filter fetch dies with a 401.
     */
    public function catalogPageIsRegisteredAsAPublicAction(): void
    {
        $this->assertContains(
            'stride_catalog_page',
            apply_filters('ntdst/api/public_actions', []),
            'stride_catalog_page must be on ntdst/api/public_actions — guests cannot page/filter the catalog otherwise (CR-G1)',
        );
    }

    /**
     * @test
     *
     * CR-G1 seam — the GUEST wire path over real HTTP, no mocks: an
     * unauthenticated client fetches an anonymous nonce for
     * stride_catalog_page and then a server-rendered page-1 slice through
     * the real /get_nonce → /action chain. Mirrors how ntdstAPI drives it
     * from the browser. The negative case lives in
     * privateActionStillRequiresAuthForAnonymousNonce() below.
     */
    public function guestCanFetchNonceAndPageThroughRealHttp(): void
    {
        $future = date('Y-m-d', strtotime('+30 days'));
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);

        // 1. Anonymous nonce fetch (no auth cookies — wp_remote_post sends none).
        $nonceResponse = wp_remote_post(rest_url('ntdst/v1/get_nonce'), [
            'sslverify' => false,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode(['action' => 'stride_catalog_page']),
        ]);
        $this->assertFalse(is_wp_error($nonceResponse), 'HTTP to own site must work for this assertion to mean anything');
        $this->assertSame(
            200,
            wp_remote_retrieve_response_code($nonceResponse),
            'anonymous get_nonce for stride_catalog_page must return 200, got '
                . wp_remote_retrieve_response_code($nonceResponse) . ' — guests are locked out of the catalog (CR-G1)',
        );
        $nonceBody = json_decode((string) wp_remote_retrieve_body($nonceResponse), true);
        $nonce = (string) ($nonceBody['data']['nonce'] ?? '');
        $this->assertNotSame('', $nonce, 'nonce payload must contain a nonce');

        // 2. Anonymous paged fetch through the real handle_action chain.
        $actionResponse = wp_remote_post(rest_url('ntdst/v1/action'), [
            'sslverify' => false,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode([
                'action'  => 'stride_catalog_page',
                'nonce'   => $nonce,
                'catalog' => 'klassikaal',
                'page'    => 1,
            ]),
        ]);
        $this->assertFalse(is_wp_error($actionResponse));
        $this->assertSame(200, wp_remote_retrieve_response_code($actionResponse));

        $body = json_decode((string) wp_remote_retrieve_body($actionResponse), true);
        $this->assertTrue((bool) ($body['success'] ?? false), 'anonymous catalog page fetch must succeed');
        $this->assertStringContainsString(
            'href="' . esc_url(get_permalink($editionId)) . '"',
            (string) ($body['data']['html'] ?? ''),
            'the guest slice must contain server-rendered cards',
        );
    }

    /**
     * @test
     *
     * Adversarial counterpart of the guest wire path: making the catalog
     * public must NOT loosen the gate for everything else — an anonymous
     * nonce fetch for a non-public action stays 401.
     */
    public function privateActionStillRequiresAuthForAnonymousNonce(): void
    {
        $response = wp_remote_post(rest_url('ntdst/v1/get_nonce'), [
            'sslverify' => false,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode(['action' => 'stride_submit_intake']),
        ]);
        $this->assertFalse(is_wp_error($response));
        $this->assertSame(
            401,
            wp_remote_retrieve_response_code($response),
            'non-public actions must keep requiring authentication for nonce issuance',
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
