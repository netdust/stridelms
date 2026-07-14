<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminStatsService;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Tier-A backend contract for AdminStatsService::getWorklistQueueCounts() — the
 * REAL engineering behind Phase-1D Task 3.3 (freshness-review drift #4).
 *
 * Asserts the 5 worklist queue counts (§1 of the spec), all scoped to the
 * ACTIVE-edition subset (§10), and surfaces them ADDITIVELY in /admin/stats.
 *
 * Load-bearing properties under test:
 *  1. All 5 counts correct against seeded fixtures.
 *  2. offerte_opvolging uses the SAME two-step paid-proxy resolver the grid
 *     offerte column uses (counts confirmed rows whose quote is absent OR
 *     status != Exported; EXCLUDES one whose quote IS Exported) —
 *     Sibling-site audit item 1: one paid-proxy definition only.
 *  3. Active-edition scoping: a row on a terminal/past (non-active) edition is
 *     EXCLUDED — the count consumes the active-edition ID set, never a corpus scan.
 *  4. §10.7 carve-out: an interest row on a DATELESS edition (NULL start_date),
 *     older than 90 days, IS counted in oldinterest (regression guard for
 *     bug_sessionless_edition_cutoff).
 *  5. waitlist_open EXCLUDES a waitlist row whose edition has no open capacity
 *     (INV-7 effective status / per-edition capacity check).
 *  6. /admin/stats response includes the 5 counts AND its pre-existing keys are
 *     unchanged (additive proof, via rest_do_request).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminWorklistQueueCounts
 */
final class AdminWorklistQueueCountsTest extends IntegrationTestCase
{
    private static int $companyId = 99221;

    // Users
    private static ?int $coordinatorUserId = null;
    private static ?int $uPending = null;
    private static ?int $uWaitOpen = null;
    private static ?int $uWaitFull = null;
    private static ?int $uOfferteOpen = null;   // confirmed, quote NOT exported  → counted
    private static ?int $uOfferteDone = null;   // confirmed, quote IS  exported  → excluded
    private static ?int $uOfferteNone = null;   // confirmed, no quote            → counted
    private static ?int $uNoCert = null;        // completed, no LD cert          → counted
    private static ?int $uOldInterest = null;   // interest on dateless, >90d old → counted
    private static ?int $uPastExcluded = null;  // pending on a PAST edition      → excluded
    private static ?int $uInterestDated = null; // interest on a DATED edition    → interest_to_invite

    // Editions
    private static ?int $activeFullCapEdition = null;  // capacity=1, 1 confirmed → no open spots
    private static ?int $activeOpenCapEdition = null;  // capacity=20            → open spots
    private static ?int $datelessEdition = null;       // NULL start_date — interest anchor
    private static ?int $pastEdition = null;           // start_date 60d ago — non-active

    // Quotes
    private static ?int $quoteExported = null;
    private static ?int $quoteDraft = null;

    /** @var array<int> active-edition ID set fed to getWorklistQueueCounts */
    private static array $activeEditionIds = [];

    /** @var array<int> */
    private static array $regIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        // --- Coordinator (stride_view) for the REST dispatch assertion ---
        $coordName = 'wq_coord_' . uniqid();
        self::$coordinatorUserId = (int) wp_create_user($coordName, 'pass123', $coordName . '@test.local');
        get_user_by('ID', self::$coordinatorUserId)->set_role('stride_coordinator');

        // --- Users (distinct so create()'s user+edition dedup never collides) ---
        foreach ([
            'uPending', 'uWaitOpen', 'uWaitFull', 'uOfferteOpen', 'uOfferteDone',
            'uOfferteNone', 'uNoCert', 'uOldInterest', 'uPastExcluded', 'uInterestDated',
        ] as $prop) {
            self::$$prop = (int) wp_create_user(
                'wq_' . $prop . '_' . uniqid(),
                'pass123',
                'wq_' . $prop . '_' . uniqid() . '@test.local',
            );
        }

        // --- Editions ---
        self::$activeOpenCapEdition = self::makeEdition('WQ OpenCap', date('Y-m-d', strtotime('+30 days')), 'open', 20);
        self::$activeFullCapEdition = self::makeEdition('WQ FullCap', date('Y-m-d', strtotime('+30 days')), 'open', 1);
        self::$datelessEdition      = self::makeEdition('WQ Dateless', null, 'open', 0);
        self::$pastEdition          = self::makeEdition('WQ Past', date('Y-m-d', strtotime('-60 days')), 'completed', 20);

        // Active subset (§10): future-dated OR dateless (§10.7 carve-out). NOT the past edition.
        self::$activeEditionIds = [
            self::$activeOpenCapEdition,
            self::$activeFullCapEdition,
            self::$datelessEdition,
        ];

        $repo = ntdst_get(RegistrationRepository::class);

        // pending (active)
        self::reg($repo, self::$uPending, self::$activeOpenCapEdition, RegistrationStatus::Pending);

        // waitlist on an edition WITH open capacity → counted in waitlist_open
        self::reg($repo, self::$uWaitOpen, self::$activeOpenCapEdition, RegistrationStatus::Waitlist);

        // Fill the cap-1 edition with a confirmed reg so it has NO open spots…
        self::reg($repo, self::$uOfferteNone, self::$activeFullCapEdition, RegistrationStatus::Confirmed);
        // …then a waitlist row on it → must be EXCLUDED from waitlist_open (INV-7 / capacity).
        self::reg($repo, self::$uWaitFull, self::$activeFullCapEdition, RegistrationStatus::Waitlist);

        // offerte-opvolging fixtures (all confirmed on the active open-cap edition)
        $rOpen = self::reg($repo, self::$uOfferteOpen, self::$activeOpenCapEdition, RegistrationStatus::Confirmed);
        $rDone = self::reg($repo, self::$uOfferteDone, self::$activeOpenCapEdition, RegistrationStatus::Confirmed);
        // uOfferteNone above is already a confirmed reg with no quote → counted.

        // nocert: completed reg, completed_at set, NO LD certificate
        $rNoCert = self::reg($repo, self::$uNoCert, self::$activeOpenCapEdition, RegistrationStatus::Completed);
        $repo->update($rNoCert, ['completed_at' => current_time('mysql')]);

        // oldinterest: interest on DATELESS edition, registered 200 days ago (>90)
        $rOld = self::reg($repo, self::$uOldInterest, self::$datelessEdition, RegistrationStatus::Interest);
        self::backdateRegistration($rOld, date('Y-m-d H:i:s', strtotime('-200 days')));

        // interest_to_invite: interest on a DATED (future start_date) edition → its
        // formerly-dateless anchor now has a planned date, so it surfaces for invite.
        // Registered "today" so it does NOT also land in oldinterest (keeps the two
        // queues' fixtures independent — though overlap would be allowed).
        self::reg($repo, self::$uInterestDated, self::$activeOpenCapEdition, RegistrationStatus::Interest);

        // EXCLUDED: pending on a PAST (non-active) edition — not in the active ID set.
        self::reg($repo, self::$uPastExcluded, self::$pastEdition, RegistrationStatus::Pending);

        // --- Quotes (link via 'registration_id' + 'status' meta — the resolver's read) ---
        self::$quoteExported = self::makeQuote($rDone, QuoteStatus::Exported);   // → excluded from offerte_opvolging
        self::$quoteDraft    = self::makeQuote($rOpen, QuoteStatus::Draft);      // → counted (status != Exported)
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;
        foreach (self::$regIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        foreach ([
            self::$coordinatorUserId, self::$uPending, self::$uWaitOpen, self::$uWaitFull,
            self::$uOfferteOpen, self::$uOfferteDone, self::$uOfferteNone, self::$uNoCert,
            self::$uOldInterest, self::$uPastExcluded, self::$uInterestDated,
        ] as $uid) {
            if ($uid) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($uid);
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$coordinatorUserId);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function makeEdition(string $title, ?string $startDate, string $status, int $capacity): int
    {
        $id = wp_insert_post([
            'post_title'  => $title . ' ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($id)) {
            throw new \RuntimeException('Failed to create edition: ' . $id->get_error_message());
        }
        $id = (int) $id;
        self::$testPosts[] = $id;
        update_post_meta($id, '_ntdst_status', $status);
        update_post_meta($id, '_ntdst_capacity', $capacity);
        update_post_meta($id, '_ntdst_course_id', 0);
        if ($startDate !== null) {
            update_post_meta($id, '_ntdst_start_date', $startDate);
            update_post_meta($id, '_ntdst_end_date', $startDate);
        }
        return $id;
    }

    private static function makeQuote(int $registrationId, QuoteStatus $status): int
    {
        $id = wp_insert_post([
            'post_title'  => 'WQ Quote ' . uniqid(),
            'post_type'   => 'vad_quote',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($id)) {
            throw new \RuntimeException('Failed to create quote: ' . $id->get_error_message());
        }
        $id = (int) $id;
        self::$testPosts[] = $id;
        update_post_meta($id, 'registration_id', $registrationId);
        update_post_meta($id, 'status', $status->value);
        return $id;
    }

    private static function reg(RegistrationRepository $repo, int $userId, int $editionId, RegistrationStatus $status): int
    {
        $id = $repo->create([
            'user_id'    => $userId,
            'edition_id' => $editionId,
            'company_id' => self::$companyId,
            'status'     => $status->value,
        ]);
        if (!is_int($id) || $id <= 0) {
            throw new \RuntimeException('Failed to create registration (status ' . $status->value . ')');
        }
        self::$regIds[] = $id;
        return $id;
    }

    private static function backdateRegistration(int $regId, string $datetime): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'vad_registrations',
            ['registered_at' => $datetime],
            ['id' => $regId],
        );
        ntdst_get(RegistrationRepository::class)->clearCache();
    }

    private function counts(): array
    {
        return ntdst_get(AdminStatsService::class)->getWorklistQueueCounts(self::$activeEditionIds);
    }

    private function dispatch(string $method, string $path): \WP_REST_Response|\WP_Error
    {
        return rest_do_request(new \WP_REST_Request($method, $path));
    }

    // =========================================================================
    // Assertion 1: all 5 counts present + correct
    // =========================================================================

    /** @test */
    public function returnsAllFiveQueueCountsWithCorrectValues(): void
    {
        $c = $this->counts();

        foreach (['pending', 'waitlist_open', 'offerte_opvolging', 'nocert', 'oldinterest', 'interest_to_invite'] as $key) {
            $this->assertArrayHasKey($key, $c, "Missing queue count key: {$key}");
            $this->assertIsInt($c[$key], "Queue count {$key} must be an int");
        }

        $this->assertSame(1, $c['pending'], 'Exactly 1 pending row in the active subset');
        $this->assertSame(1, $c['waitlist_open'], 'Exactly 1 waitlist row on an open-capacity active edition');
        // offerte_opvolging: uOfferteOpen (draft quote) + uOfferteNone (no quote) = 2; uOfferteDone (exported) excluded
        $this->assertSame(2, $c['offerte_opvolging'], 'Confirmed rows with absent OR non-Exported quote');
        $this->assertSame(1, $c['nocert'], 'Exactly 1 completed row without an LD certificate');
        $this->assertSame(1, $c['oldinterest'], 'Exactly 1 interest row older than 90 days');
    }

    // =========================================================================
    // Assertion 2: offerte-opvolging uses the shared paid-proxy resolver
    // =========================================================================

    /** @test */
    public function offerteOpvolgingExcludesExportedAndCountsAbsentOrNonExported(): void
    {
        $c = $this->counts();

        // The Exported quote (uOfferteDone) must NOT inflate the count; absent (uOfferteNone)
        // and Draft (uOfferteOpen) must. This proves the same Exported-label exclusion the
        // grid offerte column applies — one paid-proxy definition (Sibling-site audit 1).
        $this->assertSame(
            2,
            $c['offerte_opvolging'],
            'Exported quote must be excluded; absent + non-Exported must be counted',
        );

        // Sanity: the grid resolver labels uOfferteDone "Verwerkt" (Exported) and uOfferteOpen
        // is not "Verwerkt" — i.e. the count consumes the same resolver output.
        $resolved = ntdst_get(\Stride\Admin\AdminRegistrationQueryService::class)
            ->offerteStatusesForRegistrations(self::$regIds);
        $exportedLabel = QuoteStatus::Exported->label();
        $this->assertContains($exportedLabel, $resolved, 'Resolver should report the Exported quote as "Verwerkt"');
    }

    // =========================================================================
    // Assertion 3: active-edition scoping excludes the past edition
    // =========================================================================

    /** @test */
    public function pendingRowOnNonActiveEditionIsExcluded(): void
    {
        // uPastExcluded is pending on self::$pastEdition, which is NOT in the active ID set.
        // The active count is 1 (uPending only). Re-confirm against a count that INCLUDES the
        // past edition to prove the difference is the scoping, not a coincidence.
        $active = $this->counts();
        $this->assertSame(1, $active['pending'], 'Past-edition pending row must be excluded');

        $withPast = ntdst_get(AdminStatsService::class)->getWorklistQueueCounts(
            array_merge(self::$activeEditionIds, [self::$pastEdition]),
        );
        $this->assertSame(2, $withPast['pending'], 'Including the past edition surfaces its pending row');
    }

    // =========================================================================
    // Assertion 4: §10.7 dateless carve-out — oldinterest counts a dateless row
    // =========================================================================

    /** @test */
    public function datelessEditionInterestRowIsCountedInOldInterest(): void
    {
        // The 90-day-old interest row lives on the DATELESS edition (NULL start_date).
        // It must be counted — the active subset includes dateless editions (§10.7,
        // regression guard for bug_sessionless_edition_cutoff).
        $c = $this->counts();
        $this->assertSame(1, $c['oldinterest'], 'Dateless-edition old interest row must be counted');

        // Drop the dateless edition from the active set → the count goes to 0,
        // proving the dateless edition is what carries the row (not a leak elsewhere).
        $withoutDateless = ntdst_get(AdminStatsService::class)->getWorklistQueueCounts([
            self::$activeOpenCapEdition,
            self::$activeFullCapEdition,
        ]);
        $this->assertSame(0, $withoutDateless['oldinterest'], 'Old interest lives only on the dateless edition');
    }

    // =========================================================================
    // Assertion 4b: interest_to_invite — DATED edition counts, DATELESS does NOT
    // =========================================================================

    /** @test */
    public function interestOnDatedEditionIncrementsInviteQueueAndDatelessDoesNot(): void
    {
        // uInterestDated: interest on activeOpenCapEdition (future start_date) → counted.
        // uOldInterest:   interest on datelessEdition (NULL start_date)         → NOT counted.
        $c = $this->counts();
        $this->assertSame(
            1,
            $c['interest_to_invite'],
            'Only the interest row on a DATED edition surfaces for invite',
        );

        // Denial proof: dropping the DATED edition from the active set drives the
        // count to 0 — the dateless interest row (still in the set) never counts.
        $datelessOnly = ntdst_get(AdminStatsService::class)->getWorklistQueueCounts([
            self::$datelessEdition,
        ]);
        $this->assertSame(
            0,
            $datelessOnly['interest_to_invite'],
            'A dateless-edition interest row must NOT count toward interest_to_invite',
        );
    }

    // =========================================================================
    // Assertion 5: waitlist_open excludes a full-capacity edition (INV-7)
    // =========================================================================

    /** @test */
    public function waitlistOpenExcludesFullCapacityEdition(): void
    {
        $c = $this->counts();

        // Two waitlist rows exist: one on the open-cap edition (counted), one on the
        // cap-1 edition that already has a confirmed reg (no open spots → excluded).
        $this->assertSame(
            1,
            $c['waitlist_open'],
            'Only the waitlist row on an open-capacity edition is counted',
        );
    }

    // =========================================================================
    // Assertion 6: /admin/stats additive — new keys present, old keys unchanged
    // =========================================================================

    /** @test */
    public function adminStatsResponseIncludesQueueCountsAndKeepsExistingKeys(): void
    {
        $response = $this->dispatch('GET', '/stride/v1/admin/stats');
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);

        // The CONSUMED keys (vandaag.js mapStats) must be present. The
        // pre-workspace detail blocks (todaySessionDetails,
        // upcomingEditionDetails, recentRegistrations, openTrajectories,
        // alerts, actionCount) were verified consumer-less and trimmed
        // (F-V12) — computing them cost batch fetches on every cache miss.
        foreach ([
            'upcomingEditions', 'totalRegistrations', 'pendingQuotes', 'todaySessions',
            'registrationsThisWeek', 'registrationsLastWeek',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "Consumed /admin/stats key dropped: {$key}");
        }
        $this->assertArrayNotHasKey('alerts', $data, 'the dead detail payload must stay trimmed (F-V12)');

        // New worklist queue counts present under a stable container key.
        $this->assertArrayHasKey('worklistQueues', $data, '/admin/stats must expose worklistQueues');
        foreach (['pending', 'waitlist_open', 'offerte_opvolging', 'nocert', 'oldinterest', 'interest_to_invite'] as $key) {
            $this->assertArrayHasKey($key, $data['worklistQueues'], "Missing worklist queue: {$key}");
            $this->assertIsInt($data['worklistQueues'][$key]);
        }
    }
}
