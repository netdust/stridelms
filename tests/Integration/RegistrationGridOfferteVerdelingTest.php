<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminRegistrationQueryService;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * FIX-10 (Task 3A.1): the group-by "offerte verdeling" tally is computed in SQL,
 * NOT by pulling every registration id of every visible group into PHP.
 *
 * This test PINS the tally output (per-group counts by offerte label) for a
 * fixture that deliberately exercises every semantic the old PHP path relied on:
 *
 *  - multiple groups (grouped by company_id) with multiple quote statuses
 *  - a registration with TWO published quotes → MIN(post ID) wins, counted ONCE
 *  - a registration with NO quote → the 'Geen offerte' bucket
 *  - a group whose group_value is NULL (company_id NULL) → routed via IS NULL,
 *    never IN ('')
 *  - a registration whose quote has an UNKNOWN raw status → the raw value is
 *    kept verbatim (QuoteStatus::tryFrom miss → (string) $rawStatus), matching
 *    resolveOfferteStatuses().
 *
 * PARITY: the assertions pin the known-correct expected map. The tally is
 * asserted at BOTH layers — the new repository SQL method
 * (offerteVerdelingByGroup) and the service consumer (getGroupedPage's
 * offerte_verdeling) — so a divergence in either goes RED.
 *
 * SCALE: the repository method is asserted to run in a BOUNDED number of
 * queries (constant, not O(groups) and not an unbounded per-group id pull).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationGridOfferteVerdeling
 */
class RegistrationGridOfferteVerdelingTest extends IntegrationTestCase
{
    // Two real companies + a NULL-company group.
    private static int $companyA = 91001;
    private static int $companyB = 91002;

    private static ?int $editionId = null;

    /** @var array<int> */
    private static array $userIds = [];
    /** @var array<int> */
    private static array $regIds = [];
    /** @var array<int> */
    private static array $quoteIds = [];

    // Expected tally, pinned. Keyed by group_value (string company_id or '' for
    // the NULL group via the aggregate's group_value === null), then by label.
    // Built to match resolveOfferteStatuses + tallyOfferteStatuses exactly.
    private const RAW_UNKNOWN = 'weird_legacy_status';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $repo = ntdst_get(RegistrationRepository::class);

        self::$editionId = self::createEdition('OfferteVerdeling Edition ' . time());

        // --- Company A group: 4 regs ---
        // A1: quote status = sent   → 'Verzonden'
        // A2: quote status = sent   → 'Verzonden'
        // A3: quote status = exported→ 'Verwerkt'
        // A4: NO quote               → 'Geen offerte'
        $a1 = self::seedReg($repo, self::$companyA, RegistrationStatus::Confirmed);
        self::seedQuote($a1, QuoteStatus::Sent->value);

        $a2 = self::seedReg($repo, self::$companyA, RegistrationStatus::Confirmed);
        self::seedQuote($a2, QuoteStatus::Sent->value);

        $a3 = self::seedReg($repo, self::$companyA, RegistrationStatus::Confirmed);
        self::seedQuote($a3, QuoteStatus::Exported->value);

        self::seedReg($repo, self::$companyA, RegistrationStatus::Confirmed); // A4 no quote

        // --- Company B group: 2 regs ---
        // B1: TWO quotes — a lower-ID 'draft' and a higher-ID 'exported'.
        //     MIN(post ID) wins → 'draft' → 'In behandeling'. Counted ONCE.
        // B2: quote with an UNKNOWN raw status → kept verbatim.
        $b1 = self::seedReg($repo, self::$companyB, RegistrationStatus::Confirmed);
        self::seedQuote($b1, QuoteStatus::Draft->value);     // lower post ID (seeded first)
        self::seedQuote($b1, QuoteStatus::Exported->value);  // higher post ID — must be ignored (MIN wins)

        $b2 = self::seedReg($repo, self::$companyB, RegistrationStatus::Confirmed);
        self::seedQuote($b2, self::RAW_UNKNOWN);

        // --- NULL company group: 2 regs ---
        // N1: quote status = sent → 'Verzonden'
        // N2: NO quote            → 'Geen offerte'
        $n1 = self::seedReg($repo, null, RegistrationStatus::Confirmed);
        self::seedQuote($n1, QuoteStatus::Sent->value);

        self::seedReg($repo, null, RegistrationStatus::Confirmed); // N2 no quote
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        foreach (self::$regIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        foreach (self::$quoteIds as $qid) {
            wp_delete_post($qid, true);
        }
        if (self::$editionId) {
            wp_delete_post(self::$editionId, true);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach (self::$userIds as $uid) {
            wp_delete_user($uid);
        }

        parent::tearDownAfterClass();
    }

    // =========================================================================
    // The pinned expectation — the golden tally the OLD PHP path produced and
    // the NEW SQL path must reproduce byte-for-byte.
    // =========================================================================

    /**
     * @return array<string,array<string,int>>  group_value => label => count
     */
    private function expectedTally(): array
    {
        return [
            (string) self::$companyA => [
                QuoteStatus::Sent->label()     => 2, // A1, A2
                QuoteStatus::Exported->label() => 1, // A3
                'Geen offerte'                 => 1, // A4
            ],
            (string) self::$companyB => [
                QuoteStatus::Draft->label() => 1, // B1 (MIN quote = draft, counted once)
                self::RAW_UNKNOWN           => 1, // B2 (unknown raw status kept verbatim)
            ],
            // NULL group_value: the repo returns it under the '' key (group_value
            // === null → we map to '' in the assertion below).
            '' => [
                QuoteStatus::Sent->label() => 1, // N1
                'Geen offerte'             => 1, // N2
            ],
        ];
    }

    // =========================================================================
    // Repository-layer parity: the new SQL method returns the pinned tally.
    // =========================================================================

    /**
     * @test
     */
    public function offerteVerdelingByGroupReturnsPinnedTallyInSql(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $raw = $repo->offerteVerdelingByGroup(self::scopedFilters(), 'company_id');

        // Reduce the raw [group => [rawStatusOrNull => count]] to the label map
        // the SERVICE produces, so we compare at the same altitude as the pinned
        // expectation. (The service owns the label mapping.)
        $actual = $this->mapRawToLabels($raw);

        foreach ($this->expectedTally() as $group => $expectedLabels) {
            $this->assertArrayHasKey(
                $group,
                $actual,
                "group '{$group}' must be present in the SQL tally",
            );
            // The tally is a label=>count MAP; equality is order-independent.
            ksort($expectedLabels);
            $actualLabels = $actual[$group];
            ksort($actualLabels);
            $this->assertSame(
                $expectedLabels,
                $actualLabels,
                "SQL tally for group '{$group}' must match the pinned PHP-path counts",
            );
        }
    }

    /**
     * @test
     * The NULL-group registrations must be tallied under the IS NULL branch,
     * NOT dropped and NOT collapsed into an empty-string company_id.
     */
    public function nullGroupIsTalliedViaIsNull(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $raw    = $repo->offerteVerdelingByGroup(self::scopedFilters(), 'company_id');
        $actual = $this->mapRawToLabels($raw);

        $this->assertArrayHasKey('', $actual, 'NULL company_id group must be present (routed via IS NULL)');
        $expected = [
            QuoteStatus::Sent->label() => 1,
            'Geen offerte'             => 1,
        ];
        ksort($expected);
        $actualNull = $actual[''];
        ksort($actualNull);
        $this->assertSame(
            $expected,
            $actualNull,
            'NULL group tally must count both the quoted and the no-quote reg',
        );
    }

    /**
     * @test
     * A registration with two quotes must be counted ONCE, using the MIN(post ID)
     * quote's status — identical to findQuoteIdsByRegistrations semantics.
     */
    public function registrationWithTwoQuotesCountedOnceViaMinId(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $raw    = $repo->offerteVerdelingByGroup(self::scopedFilters(), 'company_id');
        $actual = $this->mapRawToLabels($raw);

        $bTally = $actual[(string) self::$companyB] ?? [];

        // Company B has exactly 2 registrations. If B1's two quotes double-counted,
        // the sum would be 3.
        $this->assertSame(
            2,
            array_sum($bTally),
            'company B group must total 2 (the 2-quote reg counted once — MIN wins)',
        );
        $this->assertSame(
            1,
            $bTally[QuoteStatus::Draft->label()] ?? 0,
            'the 2-quote reg must resolve to the MIN-post-ID quote status (draft/In behandeling)',
        );
        $this->assertArrayNotHasKey(
            QuoteStatus::Exported->label(),
            $bTally,
            'the higher-ID exported quote of the 2-quote reg must NOT appear',
        );
    }

    // =========================================================================
    // Service-layer parity: getGroupedPage's offerte_verdeling equals the tally.
    // =========================================================================

    /**
     * @test
     * The consumer (getGroupedPage) must surface the SAME per-group tally the
     * repository computes — this is the end-to-end parity the whole fix protects.
     */
    public function getGroupedPageOfferteVerdelingMatchesPinnedTally(): void
    {
        $service = ntdst_get(AdminRegistrationQueryService::class);

        // Scope to the fixture edition so seed rows (esp. NULL-company seed regs)
        // never pollute the '' group. group_by=company_id within one edition
        // isolates exactly the A/B/NULL groups this fixture seeds.
        $dto = $service->getGridPage([
            'group_by'   => 'company_id',
            'edition_id' => self::$editionId,
            'per_page'   => 50,
        ]);

        $this->assertNotInstanceOf(\WP_Error::class, $dto, 'grouped company_id page must not error');

        $byGroup = [];
        foreach ($dto['items'] as $item) {
            $key = $item['group_value'] === null ? '' : (string) $item['group_value'];
            $byGroup[$key] = $item['offerte_verdeling'];
        }

        foreach ($this->expectedTally() as $group => $expectedLabels) {
            $this->assertArrayHasKey(
                $group,
                $byGroup,
                "group '{$group}' must appear in the grouped service response",
            );
            // Order-independent equality: the tally is a label=>count map.
            ksort($expectedLabels);
            $actualLabels = $byGroup[$group];
            ksort($actualLabels);
            $this->assertSame(
                $expectedLabels,
                $actualLabels,
                "service offerte_verdeling for group '{$group}' must match the pinned tally",
            );
        }
    }

    // =========================================================================
    // Scale: the tally runs in a BOUNDED query count — no per-group id pull.
    // =========================================================================

    /**
     * @test
     * The whole point of FIX-10: computing the tally must NOT scale its query
     * count with the number of groups, and must NOT materialise every reg id.
     * We assert the repository method issues a small CONSTANT number of queries
     * (a COUNT/aggregate is fine; an O(groups) or O(rows) fan-out is the bug).
     */
    public function offerteVerdelingIssuesBoundedQueryCount(): void
    {
        global $wpdb;

        $repo = ntdst_get(RegistrationRepository::class);

        $before = $wpdb->num_queries;
        $repo->offerteVerdelingByGroup(self::scopedFilters(), 'company_id');
        $delta = $wpdb->num_queries - $before;

        // 3 groups in the fixture; a per-group approach would issue ≥3 queries plus
        // the id pull. The SQL tally must be a small constant regardless.
        $this->assertLessThanOrEqual(
            2,
            $delta,
            "offerteVerdelingByGroup must run a bounded query count (was {$delta}); it must not fan out per group or pull every id",
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Map the repo's raw [group => [rawStatusOrNull => count]] into the label
     * map the service produces — the SAME translation resolveOfferteStatuses uses:
     * null/'' → 'Geen offerte'; valid enum → label(); unknown raw → verbatim.
     *
     * @param  array<string,array<string,int>> $raw
     * @return array<string,array<string,int>>
     */
    private function mapRawToLabels(array $raw): array
    {
        $out = [];
        foreach ($raw as $group => $statusCounts) {
            $labelled = [];
            foreach ($statusCounts as $rawStatus => $count) {
                // Repo returns the NULL/no-quote bucket under the '' key.
                if ($rawStatus === '' || $rawStatus === '__none__') {
                    $label = 'Geen offerte';
                } else {
                    $enum  = QuoteStatus::tryFrom((string) $rawStatus);
                    $label = $enum !== null ? $enum->label() : (string) $rawStatus;
                }
                $labelled[$label] = ($labelled[$label] ?? 0) + $count;
            }
            $out[$group] = $labelled;
        }
        return $out;
    }

    /**
     * Filter set scoped to the fixture edition so the tally corpus is EXACTLY
     * this fixture's regs (A/B/NULL groups) — no seed-data pollution. An explicit
     * edition_id bypasses active-scope in buildGridFilters, so terminal/dateless
     * scoping never changes the corpus under test.
     *
     * @return array<string,mixed>
     */
    private static function scopedFilters(): array
    {
        return [
            'edition_id' => self::$editionId,
            'per_page'   => 50,
        ];
    }

    private static function createEdition(string $title): int
    {
        $postId = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create edition: {$title}");
        }
        update_post_meta($postId, '_ntdst_status', 'open');
        update_post_meta($postId, '_ntdst_capacity', 50);
        update_post_meta($postId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($postId, '_ntdst_end_date', date('Y-m-d', strtotime('+31 days')));

        return (int) $postId;
    }

    private static function seedReg(
        RegistrationRepository $repo,
        ?int $companyId,
        RegistrationStatus $status,
    ): int {
        $uid = wp_create_user('ov_u_' . uniqid(), 'pass123', 'ov_' . uniqid() . '@test.local');
        if (is_wp_error($uid)) {
            throw new \RuntimeException('Failed to create user for offerte-verdeling fixture');
        }
        self::$userIds[] = (int) $uid;

        $data = [
            'user_id'    => (int) $uid,
            'edition_id' => self::$editionId,
            'status'     => $status->value,
        ];
        if ($companyId !== null) {
            $data['company_id'] = $companyId;
        }

        $reg = $repo->create($data);
        if (is_wp_error($reg) || !is_int($reg) || $reg <= 0) {
            throw new \RuntimeException('Failed to create registration for offerte-verdeling fixture');
        }
        self::$regIds[] = (int) $reg;

        return (int) $reg;
    }

    /**
     * Seed a published vad_quote linked to a registration, with a raw `status`
     * meta — mirroring exactly what findQuoteIdsByRegistrations + the resolver
     * read (bare `registration_id` string meta + bare `status` meta).
     */
    private static function seedQuote(int $regId, string $status): int
    {
        $qid = wp_insert_post([
            'post_title'  => 'OV Quote for reg ' . $regId,
            'post_type'   => 'vad_quote',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($qid) || !$qid) {
            throw new \RuntimeException('Failed to create vad_quote fixture');
        }
        // Bare meta keys — the resolver reads meta_key='registration_id' (string)
        // and meta_key='status' directly from wp_postmeta (no _ntdst_ prefix).
        update_post_meta($qid, 'registration_id', (string) $regId);
        update_post_meta($qid, 'status', $status);
        self::$quoteIds[] = (int) $qid;

        return (int) $qid;
    }
}
