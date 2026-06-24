<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminQuoteService;
use Stride\Domain\QuoteStatus;

/**
 * Characterization pin for the getQuotes -> AdminQuoteService strangle (Task D1).
 *
 * Task D1 relocates the ~6 inline quote SELECTs + read-model assembly out of
 * AdminAPIController::getQuotes into QuoteRepository::countAdminList /
 * findAdminListRows (the $wpdb execution, INV-3) and AdminQuoteService::getQuoteList
 * (the WHERE-assembly + BatchQueryHelper enrichment + formatting). The move MUST be
 * behavior-preserving: AdminQuoteService::getQuoteList() returns the SAME rows /
 * shape / order as the pre-extraction controller produced.
 *
 * This is the safety net that proves the relocation was byte-identical. It pins the
 * full read-model SHAPE for two fixture cases:
 *   1. empty-filter (all quotes for the seeded user)
 *   2. filtered-by-status (only the matching status, others excluded)
 * plus the load-bearing envelope edge — the search short-circuit, whose envelope
 * (data/total/page/per_page) is DELIBERATELY different from the main envelope
 * (items/total/page/perPage/totalPages) and must be preserved verbatim.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminQuoteService
 */
final class AdminQuoteServiceTest extends IntegrationTestCase
{
    private AdminQuoteService $service;
    private int $editionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);
        $this->editionId = $this->createTestEdition(['post_title' => 'D1 Edition']);
        $this->service = ntdst_get(AdminQuoteService::class);
    }

    /**
     * Build the filters array exactly as the controller's getQuotes param parsing
     * produces it (page/per_page/search/status/edition_id), so the service is driven
     * through the same contract the REST callback feeds it.
     *
     * @return array<string,mixed>
     */
    private function filters(array $overrides = []): array
    {
        return array_merge([
            'page'       => 1,
            'per_page'   => 20,
            'search'     => '',
            'status'     => '',
            'edition_id' => 0,
        ], $overrides);
    }

    /** @test */
    public function emptyFilterReturnsAllSeededQuotesInTheMainEnvelopeShape(): void
    {
        // Two quotes for the seeded user — different statuses, both must appear.
        $sentId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'status'       => QuoteStatus::Sent->value,
                'quote_number' => 'OFF-D1-0001',
                'subtotal'     => 50000,
                'tax'          => 10500,
                'total'        => 60500,
                'items'        => [
                    ['title' => 'Lijn', 'quantity' => 1, 'unit_price' => 50000, 'total' => 50000],
                ],
                'billing'      => ['company' => 'Acme'],
            ],
        ]);
        $draftId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => [
                'status'       => QuoteStatus::Draft->value,
                'quote_number' => 'OFF-D1-0002',
                'subtotal'     => 10000,
                'tax'          => 2100,
                'total'        => 12100,
            ],
        ]);

        $result = $this->service->getQuoteList($this->filters());

        // Main envelope shape (items/total/page/perPage/totalPages).
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('perPage', $result);
        $this->assertArrayHasKey('totalPages', $result);
        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['perPage']);

        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertArrayHasKey($sentId, $byId, 'sent quote present');
        $this->assertArrayHasKey($draftId, $byId, 'draft quote present');

        // Full read-model SHAPE pinned for the Sent quote — every key getQuotes emitted.
        $sent = $byId[$sentId];
        $this->assertSame($sentId, $sent['id']);
        $this->assertSame('OFF-D1-0001', $sent['number']);
        $this->assertSame(QuoteStatus::Sent->value, $sent['status']);
        $this->assertSame(QuoteStatus::Sent->label(), $sent['statusLabel']);
        $this->assertSame(500.0, $sent['subtotal']);   // cents -> euros
        $this->assertSame(105.0, $sent['tax']);
        $this->assertSame(605.0, $sent['total']);
        $this->assertSame(number_format(605.0, 2, ',', '.'), $sent['totalFormatted']);
        $this->assertArrayHasKey('date', $sent);
        $this->assertSame(self::$testUserId, $sent['user']['id']);
        $this->assertSame($this->editionId, $sent['edition']['id']);
        $this->assertSame('D1 Edition', $sent['edition']['title']);
        // lineItems money fields converted cents -> euros via the (relocated) mapper.
        $this->assertSame(500.0, $sent['lineItems'][0]['unit_price']);
        $this->assertSame(500.0, $sent['lineItems'][0]['total']);
        $this->assertSame(['company' => 'Acme'], $sent['billing']);
        $this->assertArrayHasKey('editUrl', $sent);
        $this->assertArrayHasKey('sentAt', $sent);
        $this->assertArrayHasKey('validUntil', $sent);
    }

    /** @test */
    public function statusFilterReturnsOnlyMatchingQuotes(): void
    {
        $sentId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => ['status' => QuoteStatus::Sent->value, 'quote_number' => 'OFF-D1-1001'],
        ]);
        $draftId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => ['status' => QuoteStatus::Draft->value, 'quote_number' => 'OFF-D1-1002'],
        ]);

        $result = $this->service->getQuoteList($this->filters([
            'status' => QuoteStatus::Sent->value,
        ]));

        $ids = array_map(fn($i) => $i['id'], $result['items']);
        $this->assertContains($sentId, $ids, 'sent quote included by status filter');
        $this->assertNotContains($draftId, $ids, 'draft quote excluded by status filter');
    }

    /**
     * Spec-close test-effectiveness blind-path #1: the `edition_id` EXISTS
     * predicate was relocated verbatim but no test exercised it — a dropped or
     * renamed `edition_id` filter would have shipped green. This drives it: a
     * quote on a SECOND edition must be excluded when filtering by the first.
     *
     * @test
     */
    public function editionFilterReturnsOnlyQuotesForThatEdition(): void
    {
        $thisEditionId = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => ['status' => QuoteStatus::Sent->value, 'quote_number' => 'OFF-D1-2001'],
        ]);
        $otherEdition = $this->createTestEdition(['post_title' => 'D1 Other Edition']);
        $otherEditionId = $this->createTestQuote(self::$testUserId, $otherEdition, [
            'meta' => ['status' => QuoteStatus::Sent->value, 'quote_number' => 'OFF-D1-2002'],
        ]);

        $result = $this->service->getQuoteList($this->filters([
            'edition_id' => $this->editionId,
        ]));

        $ids = array_map(fn($i) => $i['id'], $result['items']);
        $this->assertContains($thisEditionId, $ids, 'quote for the filtered edition is included');
        $this->assertNotContains($otherEditionId, $ids, 'quote for a DIFFERENT edition must be excluded (edition_id EXISTS predicate)');
    }

    /**
     * Spec-close test-effectiveness blind-path #1 (cont.): the positive
     * user-search branch (search resolves to >=1 user) was never run — only the
     * zero-user short-circuit was. This drives a search that DOES match a user
     * and asserts only that user's quotes return.
     *
     * @test
     */
    public function searchMatchingAUserReturnsOnlyThatUsersQuotes(): void
    {
        $token = 'qsrch' . wp_generate_password(6, false);
        $matchUser = wp_create_user('quser_' . $token, 'testpass123', 'quser_' . $token . '@test.local');
        wp_update_user(['ID' => $matchUser, 'display_name' => 'Zoekbaar ' . $token]);
        $mine = $this->createTestQuote($matchUser, $this->editionId, [
            'meta' => ['status' => QuoteStatus::Sent->value, 'quote_number' => 'OFF-D1-3001'],
        ]);
        $theirs = $this->createTestQuote(self::$testUserId, $this->editionId, [
            'meta' => ['status' => QuoteStatus::Sent->value, 'quote_number' => 'OFF-D1-3002'],
        ]);

        $result = $this->service->getQuoteList($this->filters(['search' => $token]));

        // Positive match → the MAIN envelope (not the zero-user short-circuit).
        $this->assertArrayHasKey('items', $result, 'a matching search uses the main items envelope');
        $ids = array_map(fn($i) => $i['id'], $result['items']);
        $this->assertContains($mine, $ids, 'the matched user\'s quote is returned');
        $this->assertNotContains($theirs, $ids, 'another user\'s quote is excluded by the user-search filter');
    }

    /** @test */
    public function searchWithNoMatchingUsersShortCircuitsWithTheDataEnvelope(): void
    {
        // The deliberate envelope divergence: when the user-search resolves to zero
        // users, getQuotes short-circuits with data/total/page/per_page (NOT the main
        // items/.../totalPages envelope). This must be preserved verbatim.
        $result = $this->service->getQuoteList($this->filters([
            'search' => 'zzz_no_such_user_' . wp_generate_password(8, false),
        ]));

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertArrayNotHasKey('items', $result);
        $this->assertArrayNotHasKey('totalPages', $result);
    }
}
