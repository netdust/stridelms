<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Handlers\BulkRegistrationHandler;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration test for Task 4.1 — select-all-across-pages as a server-side
 * filter→ids expansion (capped).
 *
 * The bulk payload gains a {select_all:true, filter:{…}} shape. A shared
 * param-normalisation step (BulkRegistrationHandler::resolveBulkIds) expands
 * `filter` → the filtered registration-id set via the NEW repo method
 * RegistrationRepository::idsForGridFilter, which REUSES buildGridFilters (the
 * single WHERE source), capped at MAX_BATCH+1 so an over-cap expansion trips the
 * EXISTING runBulk `too_many` guard rather than truncating.
 *
 * Six-assertion contract (plan §133-139):
 *   1. select_all + filter expands ACROSS PAGES and applies to ALL (count > one page).
 *   2. a filter matching > MAX_BATCH (500) rows → `too_many` 400, NO row mutated.
 *   3. expansion RESPECTS the filter — a non-matching row is untouched.
 *   4. trajectory filter expands to CHILD edition-rows only (parent edition_id=NULL excluded).
 *   5. empty `filter` is BOUNDED (buildGridFilters base predicate + active scope).
 *   6. denial — without stride_manage → 403 BEFORE expansion (no row mutated).
 *
 * The deterministic mutation vehicle is `stride_bulk_set_field` field=notes:
 * it mutates the `notes` column on every resolved row regardless of status,
 * with no LD/completion/seat side effects — isolating the EXPANSION logic
 * (what id-set reaches the handler) from per-row domain semantics (covered by
 * the cluster-B/C bulk suites). The quote pre-resolver path (DRIFT #2) is
 * additionally exercised via handleBulkQuoteSent to prove setQuoteStatusForRows'
 * independent $params['ids'] read at :307 sees the expanded set.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkSelectAll
 */
final class BulkSelectAllTest extends IntegrationTestCase
{
    // Unique company ids so fixtures never collide with seed/sibling data.
    private static int $companyA = 95001;
    private static int $companyB = 95002;

    private static ?int $adminId = null;
    private static ?int $viewerId = null;

    // Editions
    private static ?int $editionId = null;        // active dated edition, the main page corpus
    private static ?int $overCapEditionId = null; // active dated edition with > MAX_BATCH rows

    // The "many pending rows on $editionId" fixture (company A), > one page (>50).
    /** @var array<int> */
    private static array $pendingRegIds = [];
    // A confirmed row (company A) on $editionId — must be untouched by a status=pending select-all.
    private static ?int $confirmedRegId = null;
    // A pending row of company B — must be untouched by a {status:pending, company_id:A} select-all.
    private static ?int $pendingCompanyBRegId = null;

    // Trajectory fixtures (mirror RegistrationGridQueryTest's parent→child shape).
    private static int $trajT1 = 950011;
    private static int $trajT2 = 950012;
    private static ?int $t1ParentRegId = null;     // edition_id NULL — must NEVER expand
    private static ?int $t1ChildARegId = null;     // parent-linked child
    private static ?int $t1ChildBRegId = null;     // parent-linked child
    private static ?int $t2ChildRegId = null;      // foil — must never leak into T1
    private static ?int $trajChildEditionA = null;
    private static ?int $trajChildEditionB = null;
    private static ?int $trajT2ChildEdition = null;

    // Over-cap fixture: MAX_BATCH + 1 lightweight rows on a dedicated edition,
    // inserted directly (bypassing create()'s per-row dedup/events) so the seed
    // is fast. company A, status=pending.
    private const OVER_CAP = 501; // MAX_BATCH(500) + 1
    /** @var array<int> */
    private static array $overCapRegIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $admin = wp_create_user('bsa_admin_' . uniqid(), 'pass123', 'bsaadmin_' . uniqid() . '@test.local');
        $viewer = wp_create_user('bsa_viewer_' . uniqid(), 'pass123', 'bsaviewer_' . uniqid() . '@test.local');
        if (is_wp_error($admin) || is_wp_error($viewer)) {
            throw new \RuntimeException('Failed to create users for BulkSelectAllTest');
        }
        self::$adminId = (int) $admin;
        self::$viewerId = (int) $viewer;
        wp_update_user(['ID' => self::$adminId, 'role' => 'administrator']);
        wp_update_user(['ID' => self::$viewerId, 'role' => 'subscriber']);
        get_role('administrator')?->add_cap('stride_manage');

        $futureStart = date('Y-m-d', strtotime('+30 days'));
        $futureEnd = date('Y-m-d', strtotime('+31 days'));

        self::$editionId = self::createEditionWithDates('BSA Main ' . time(), $futureStart, $futureEnd);
        self::$overCapEditionId = self::createEditionWithDates('BSA OverCap ' . time(), $futureStart, $futureEnd);
        self::$trajChildEditionA = self::createEditionWithDates('BSA T1ChildA ' . time(), $futureStart, $futureEnd);
        self::$trajChildEditionB = self::createEditionWithDates('BSA T1ChildB ' . time(), $futureStart, $futureEnd);
        self::$trajT2ChildEdition = self::createEditionWithDates('BSA T2Child ' . time(), $futureStart, $futureEnd);

        $repo = ntdst_get(RegistrationRepository::class);

        // 60 pending rows on $editionId, company A — > one page (per_page caps at 50/100).
        // Distinct users avoid create()'s user+edition dedup.
        for ($i = 0; $i < 60; $i++) {
            $uid = self::makeUser("bsa_pend_{$i}_");
            $rid = $repo->create([
                'user_id' => $uid,
                'edition_id' => self::$editionId,
                'company_id' => self::$companyA,
                'status' => RegistrationStatus::Pending->value,
            ]);
            self::assertValidRegId($rid, "pending#{$i}");
            self::$pendingRegIds[] = (int) $rid;
        }

        // A confirmed row (company A) on $editionId — non-matching for status=pending.
        $cUid = self::makeUser('bsa_confirmed_');
        $confirmed = $repo->create([
            'user_id' => $cUid,
            'edition_id' => self::$editionId,
            'company_id' => self::$companyA,
            'status' => RegistrationStatus::Confirmed->value,
        ]);
        self::assertValidRegId($confirmed, 'confirmed');
        self::$confirmedRegId = (int) $confirmed;

        // A pending row of company B — non-matching for company_id=A.
        $bUid = self::makeUser('bsa_companyB_');
        $pendB = $repo->create([
            'user_id' => $bUid,
            'edition_id' => self::$editionId,
            'company_id' => self::$companyB,
            'status' => RegistrationStatus::Pending->value,
        ]);
        self::assertValidRegId($pendB, 'pendingCompanyB');
        self::$pendingCompanyBRegId = (int) $pendB;

        // Trajectory parent + children (company A).
        self::seedTrajectoryFixtures($repo);

        // Over-cap fixture: insert OVER_CAP rows directly on $overCapEditionId.
        self::seedOverCapFixture();
    }

    private static function seedTrajectoryFixtures(RegistrationRepository $repo): void
    {
        $u1 = self::makeUser('bsa_tj1_');
        $u2 = self::makeUser('bsa_tj2_');
        $u3 = self::makeUser('bsa_tj3_');

        $parent = $repo->create([
            'user_id' => $u1,
            'trajectory_id' => self::$trajT1,
            'company_id' => self::$companyA,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($parent, 'T1-parent');
        self::$t1ParentRegId = (int) $parent;

        $childA = $repo->create([
            'user_id' => $u1,
            'edition_id' => self::$trajChildEditionA,
            'parent_registration_id' => self::$t1ParentRegId,
            'company_id' => self::$companyA,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($childA, 'T1-childA');
        self::$t1ChildARegId = (int) $childA;

        $childB = $repo->create([
            'user_id' => $u2,
            'edition_id' => self::$trajChildEditionB,
            'parent_registration_id' => self::$t1ParentRegId,
            'company_id' => self::$companyA,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($childB, 'T1-childB');
        self::$t1ChildBRegId = (int) $childB;

        // T2 foil: parent + child — must never leak into a T1-filtered expansion.
        $t2Parent = $repo->create([
            'user_id' => $u2,
            'trajectory_id' => self::$trajT2,
            'company_id' => self::$companyA,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($t2Parent, 'T2-parent');

        $t2Child = $repo->create([
            'user_id' => $u3,
            'edition_id' => self::$trajT2ChildEdition,
            'parent_registration_id' => (int) $t2Parent,
            'company_id' => self::$companyA,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);
        self::assertValidRegId($t2Child, 'T2-child');
        self::$t2ChildRegId = (int) $t2Child;
    }

    private static function seedOverCapFixture(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $now = current_time('mysql');

        // Direct inserts (no create() dedup/events) for speed. Each row needs a
        // distinct user_id to avoid any unique(user,edition) index; synthetic ids
        // far above any real user are fine — the grid query LEFT JOINs users and
        // does not require the user row to exist (no q filter in this fixture).
        for ($i = 0; $i < self::OVER_CAP; $i++) {
            $wpdb->insert($table, [
                'user_id' => 9000000 + $i,
                'edition_id' => self::$overCapEditionId,
                'company_id' => self::$companyA,
                'status' => RegistrationStatus::Pending->value,
                'enrollment_path' => 'individual',
                'registered_at' => $now,
                'notes' => 'overcap-untouched',
            ]);
            self::$overCapRegIds[] = (int) $wpdb->insert_id;
        }
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';

        $allRegs = array_merge(
            self::$pendingRegIds,
            self::$overCapRegIds,
            array_filter([
                self::$confirmedRegId,
                self::$pendingCompanyBRegId,
                self::$t1ParentRegId,
                self::$t1ChildARegId,
                self::$t1ChildBRegId,
                self::$t2ChildRegId,
            ]),
        );
        foreach ($allRegs as $id) {
            $wpdb->delete($table, ['id' => $id]);
        }
        // Trajectory T2 parent (id not retained) — clean by trajectory_id.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE trajectory_id IN (%d, %d) AND edition_id IS NULL",
            self::$trajT1,
            self::$trajT2,
        ));

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([self::$adminId, self::$viewerId] as $uid) {
            if ($uid) {
                wp_delete_user($uid);
            }
        }

        set_current_screen('front');
        wp_set_current_user(0);

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Reset every fixture row's notes to a known baseline before each test so
        // "untouched" / "mutated" assertions are unambiguous.
        wp_set_current_user(self::$adminId);
    }

    private function actingAsManager(): void
    {
        get_role('administrator')?->add_cap('stride_manage');
        wp_set_current_user(self::$adminId);
    }

    private function actingAsViewer(): void
    {
        wp_set_current_user(self::$viewerId);
    }

    private function notesOf(int $regId): ?string
    {
        $repo = ntdst_get(RegistrationRepository::class);
        $row = $repo->find($regId);
        return $row?->notes;
    }

    private function setNotes(int $regId, string $value): void
    {
        ntdst_get(RegistrationRepository::class)->update($regId, ['notes' => $value]);
    }

    // =========================================================================
    // Assertion 1: select_all + filter expands ACROSS PAGES and applies to ALL.
    // =========================================================================

    /** @test */
    public function selectAllExpandsAcrossPagesAndAppliesToEntireFilteredSet(): void
    {
        $this->actingAsManager();
        $handler = new BulkRegistrationHandler();

        $marker = 'bsa_a1_' . uniqid();

        // No `ids` in the payload — the server must expand the filter itself.
        $report = $handler->handleBulkSetField([], [
            'select_all' => true,
            'filter' => [
                'status' => RegistrationStatus::Pending->value,
                'company_id' => self::$companyA,
                'edition_id' => self::$editionId,
                'edition_scope' => 'all',
            ],
            'field' => 'notes',
            'value' => $marker,
        ]);

        $this->assertIsArray($report, 'manager select_all must not be denied: ' . self::errText($report));

        // count(pendingRegIds) is 60 — well over a single page (50/100 cap).
        $this->assertGreaterThan(50, $report['total'], 'expansion must cross pages (> one page of rows)');
        $this->assertSame(count(self::$pendingRegIds), $report['summary']['ok'], 'every pending row of company A must be mutated');

        // Every pending row carries the marker now (not just the first page).
        foreach (self::$pendingRegIds as $rid) {
            $this->assertSame($marker, $this->notesOf($rid), "pending row {$rid} must be mutated by the expanded select-all");
        }
    }

    // =========================================================================
    // Assertion 2: over-cap → too_many 400, NO row mutated (no truncation).
    // =========================================================================

    /** @test */
    public function overCapFilterReturnsTooManyAndMutatesNothing(): void
    {
        $this->actingAsManager();
        $handler = new BulkRegistrationHandler();

        // Baseline notes for a sample of the over-cap rows.
        $sample = array_slice(self::$overCapRegIds, 0, 5);
        foreach ($sample as $rid) {
            $this->setNotes($rid, 'overcap-baseline');
        }

        $result = $handler->handleBulkSetField([], [
            'select_all' => true,
            'filter' => [
                'edition_id' => self::$overCapEditionId,
                'edition_scope' => 'all',
            ],
            'field' => 'notes',
            'value' => 'should-never-apply',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result, 'over-cap expansion must return a WP_Error, not a partial report');
        $this->assertSame('too_many', $result->get_error_code());
        $this->assertSame(
            'Te veel inschrijvingen geselecteerd (max 500).',
            $result->get_error_message(),
        );

        // NOT a truncated mutation — every sampled row keeps its baseline.
        foreach ($sample as $rid) {
            $this->assertSame('overcap-baseline', $this->notesOf($rid), "over-cap row {$rid} must NOT be mutated (no truncation)");
        }
    }

    /** @test */
    public function idsForGridFilterRespectsItsLimit(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        $ids = $repo->idsForGridFilter([
            'edition_id' => self::$overCapEditionId,
            'edition_scope' => 'all',
        ], BulkRegistrationHandler::EXPANSION_FETCH_LIMIT);

        $this->assertCount(
            BulkRegistrationHandler::EXPANSION_FETCH_LIMIT,
            $ids,
            'idsForGridFilter must honor LIMIT = MAX_BATCH+1 so runBulk distinguishes >500 from ==500',
        );
    }

    // =========================================================================
    // Assertion 3: expansion RESPECTS the filter — non-matching rows untouched.
    // =========================================================================

    /** @test */
    public function expansionRespectsFilterLeavingNonMatchingRowsUntouched(): void
    {
        $this->actingAsManager();
        $handler = new BulkRegistrationHandler();

        $this->setNotes(self::$confirmedRegId, 'confirmed-untouched');
        $this->setNotes(self::$pendingCompanyBRegId, 'companyB-untouched');

        $marker = 'bsa_a3_' . uniqid();
        $report = $handler->handleBulkSetField([], [
            'select_all' => true,
            'filter' => [
                'status' => RegistrationStatus::Pending->value,
                'company_id' => self::$companyA,
                'edition_id' => self::$editionId,
                'edition_scope' => 'all',
            ],
            'field' => 'notes',
            'value' => $marker,
        ]);
        $this->assertIsArray($report, self::errText($report));

        // A confirmed row (wrong status) is untouched.
        $this->assertSame('confirmed-untouched', $this->notesOf(self::$confirmedRegId), 'confirmed row must not match status=pending');
        // A pending row of company B (wrong company) is untouched.
        $this->assertSame('companyB-untouched', $this->notesOf(self::$pendingCompanyBRegId), 'company B row must not match company_id=A');
    }

    // =========================================================================
    // Assertion 4: trajectory filter expands to CHILD rows only (parent excluded).
    // =========================================================================

    /** @test */
    public function trajectoryFilterExpandsToChildRowsOnlyNeverParentOrOtherTrajectory(): void
    {
        $this->actingAsManager();

        $repo = ntdst_get(RegistrationRepository::class);
        $ids = $repo->idsForGridFilter([
            'trajectory_id' => self::$trajT1,
            'edition_scope' => 'all',
        ], BulkRegistrationHandler::EXPANSION_FETCH_LIMIT);

        $this->assertContains(self::$t1ChildARegId, $ids, 'T1 child A must be in the expansion');
        $this->assertContains(self::$t1ChildBRegId, $ids, 'T1 child B must be in the expansion');

        // The parent (edition_id NULL) must NEVER expand.
        $this->assertNotContains(self::$t1ParentRegId, $ids, 'trajectory PARENT (edition_id NULL) must NOT be in the expansion');
        // Another trajectory's child must never leak.
        $this->assertNotContains(self::$t2ChildRegId, $ids, "another trajectory's child must NOT leak into a T1 expansion");

        // Every expanded row is a genuine T1 child edition-row.
        $allowed = [self::$t1ChildARegId, self::$t1ChildBRegId];
        foreach ($ids as $id) {
            $this->assertContains($id, $allowed, "only T1 child edition-rows may expand under trajectory_id=T1 (saw {$id})");
        }
    }

    // =========================================================================
    // Assertion 5: empty filter is BOUNDED (base predicate + active scope).
    // =========================================================================

    /** @test */
    public function emptyFilterIsBoundedToActiveEditionGrainedRowsNotTheWholeCorpus(): void
    {
        $repo = ntdst_get(RegistrationRepository::class);

        // An EMPTY filter must reuse buildGridFilters' base predicate
        // (r.edition_id IS NOT NULL) + default active scope — so trajectory
        // parents (edition_id NULL) can never appear, and only active edition
        // rows are in the corpus.
        $ids = $repo->idsForGridFilter([], BulkRegistrationHandler::EXPANSION_FETCH_LIMIT);

        $this->assertNotContains(self::$t1ParentRegId, $ids, 'empty filter must NOT expand to trajectory parents (edition_id NULL)');

        // It is also CAPPED: with the over-cap edition's 501 active rows in the
        // corpus, an empty-filter expansion can never be unbounded.
        $this->assertLessThanOrEqual(
            BulkRegistrationHandler::EXPANSION_FETCH_LIMIT,
            count($ids),
            'empty-filter expansion must be bounded by the LIMIT (cap backstop)',
        );

        // And the handler converts an over-the-cap empty expansion into too_many,
        // never an unbounded mutation. (The over-cap edition alone already exceeds
        // MAX_BATCH within the active corpus.)
        $this->actingAsManager();
        $handler = new BulkRegistrationHandler();
        $result = $handler->handleBulkSetField([], [
            'select_all' => true,
            'filter' => [],
            'field' => 'notes',
            'value' => 'empty-filter-should-not-apply',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result, 'an over-cap empty-filter expansion must be rejected, never mutated wholesale');
        $this->assertSame('too_many', $result->get_error_code());
    }

    // =========================================================================
    // Assertion 6: denial — without stride_manage → 403 BEFORE any expansion.
    // =========================================================================

    /** @test */
    public function deniedActorIsRejectedBeforeAnyExpansionOrMutation(): void
    {
        $this->actingAsViewer();
        $handler = new BulkRegistrationHandler();

        // Baseline a pending row so we can prove NOTHING mutated.
        $probe = self::$pendingRegIds[0];
        // Set via a manager first, then switch back to viewer for the call.
        $this->actingAsManager();
        $this->setNotes($probe, 'denied-baseline');
        $this->actingAsViewer();

        $result = $handler->handleBulkSetField([], [
            'select_all' => true,
            'filter' => [
                'status' => RegistrationStatus::Pending->value,
                'company_id' => self::$companyA,
                'edition_id' => self::$editionId,
                'edition_scope' => 'all',
            ],
            'field' => 'notes',
            'value' => 'denied-should-not-apply',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result, 'view-only actor must be denied');
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);

        // No expansion ran → the probe row is unchanged.
        $this->actingAsManager();
        $this->assertSame('denied-baseline', $this->notesOf($probe), 'a denied call must not expand or mutate any row');
    }

    // =========================================================================
    // DRIFT #2: the quote pre-resolver (setQuoteStatusForRows :307) must see the
    // expanded ids — else quote select-all silently no-ops every row.
    // =========================================================================

    /** @test */
    public function quoteHandlerPreResolverSeesExpandedIdsUnderSelectAll(): void
    {
        $this->actingAsManager();

        // Give a pending row a real linked quote so the quote path has something
        // to flip. The reg→quote map (V11) is read INDEPENDENTLY at :307 from the
        // resolved $params['ids'] — the regression DRIFT #2 guards against.
        $repo = ntdst_get(RegistrationRepository::class);
        $targetReg = self::$pendingRegIds[1];
        $userId = (int) $repo->find($targetReg)->user_id;

        $quoteId = $this->createTestQuote($userId, self::$editionId, [
            'meta' => ['registration_id' => $targetReg, 'status' => QuoteStatus::Draft->value],
        ]);
        $repo->update($targetReg, ['quote_id' => $quoteId]);

        $handler = new BulkRegistrationHandler();
        $report = $handler->handleBulkQuoteSent([], [
            'select_all' => true,
            'filter' => [
                'status' => RegistrationStatus::Pending->value,
                'company_id' => self::$companyA,
                'edition_id' => self::$editionId,
                'edition_scope' => 'all',
            ],
        ]);

        $this->assertIsArray($report, 'quote select_all must not be denied/error: ' . self::errText($report));
        // At least our quote-bearing row succeeded (the rest fail no_quote, which
        // is fine) — proving the expanded id set reached the :307 pre-resolver.
        $this->assertGreaterThanOrEqual(1, $report['summary']['ok'], 'the quote-bearing expanded row must flip to Sent');
        $this->assertSame(
            QuoteStatus::Sent->value,
            get_post_meta($quoteId, 'status', true),
            'the linked quote must be Sent — the quote pre-resolver saw the expanded id',
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function makeUser(string $prefix): int
    {
        $id = wp_create_user($prefix . uniqid(), 'pass123', $prefix . uniqid() . '@test.local');
        if (is_wp_error($id)) {
            throw new \RuntimeException('Failed to create user ' . $prefix);
        }
        return (int) $id;
    }

    private static function createEditionWithDates(string $title, string $startDate, string $endDate): int
    {
        $postId = wp_insert_post([
            'post_title' => $title,
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create edition: {$title}");
        }
        self::$testPosts[] = $postId;
        update_post_meta($postId, '_ntdst_status', 'open');
        update_post_meta($postId, '_ntdst_capacity', 9999);
        update_post_meta($postId, '_ntdst_start_date', $startDate);
        update_post_meta($postId, '_ntdst_end_date', $endDate);
        return (int) $postId;
    }

    private static function assertValidRegId(mixed $result, string $label): void
    {
        if (is_wp_error($result)) {
            throw new \RuntimeException("Failed to create registration {$label}: " . $result->get_error_message());
        }
        if (!is_int($result) || $result <= 0) {
            throw new \RuntimeException("Invalid registration ID for {$label}: " . var_export($result, true));
        }
    }

    private static function errText(mixed $maybeError): string
    {
        return $maybeError instanceof \WP_Error
            ? $maybeError->get_error_code() . ': ' . $maybeError->get_error_message()
            : 'not-an-error';
    }
}
