<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;

/**
 * RED-first contract for T5 of docs/plans/2026-07-05-profiletype-visibility-filter.md
 * (concept A — the `exclude_from_catalog` listing flag).
 *
 * CONTRACT (plan §1.A, §6.1 CORRECTED, §8 flow E):
 *   `exclude_from_catalog=true` HIDES an enrollable from every catalog
 *   ENUMERATION surface, but is NOT access control — the flagged enrollable is
 *   still returned by direct single-item lookup (`find($id)`) and stays
 *   enrollable by direct URL. Listing-only.
 *
 * The flag persists as `_ntdst_exclude_from_catalog` (both CPTs register with
 * meta_prefix `_ntdst_`; the field is `exclude_from_catalog`).
 *
 * Surfaces pinned here (the three REPO must-haves per §6.1):
 *   1. Primary list  — EditionRepository::findCatalogEligibleIds()
 *      (covers /klassikaal + /online + the AJAX stride_catalog_page slice).
 *   2. Teasers       — EditionRepository::findArchiveClassroomTeaserIds().
 *   3. Trajectories  — TrajectoryRepository::findActive().
 *
 * Surface 4 (theme `stridence_prefetch_course_cards()` get_posts, catalog.php:435)
 * is NOT asserted here: it requires theme-function bootstrapping (the theme file
 * is not loaded in the integration harness) and its own raw `_ntdst_` meta_query.
 * Deferred to feature-acceptance / browser shake-out (plan §8 flow E drives it),
 * per the plan's "use your judgment — the 3 repo surfaces are the must-haves"
 * instruction. The implementer must still add the flag clause to that theme
 * query (§6.1 bullet 3) — it is verified downstream, not in this RED.
 *
 * Denial/negative paths asserted (this is what makes it a real contract, not a
 * happy-path mirror):
 *   - flag OFF  -> the unflagged sibling IS present (we did not over-filter).
 *   - still reachable -> the FLAGGED edition -> find($id) still returns the
 *     WP_Post (listing-only, not access control).
 *
 * MANDATORY teardown (lesson_integration_test_registration_cleanup): every post
 * this test creates — including raw-inserted trajectories — is deleted in
 * tearDown() so it cannot pollute other suites in a shared-DB full run.
 *
 * Run: ddev exec bash -c 'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ExcludeFromCatalog'
 */
final class ExcludeFromCatalogTest extends IntegrationTestCase
{
    /** @var list<int> Posts created by THIS test, deleted in tearDown(). */
    private array $createdPosts = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPosts as $postId) {
            wp_delete_post($postId, true);
        }
        $this->createdPosts = [];

        parent::tearDown();
    }

    private function editions(): EditionRepository
    {
        return ntdst_get(EditionRepository::class);
    }

    private function trajectories(): TrajectoryRepository
    {
        return ntdst_get(TrajectoryRepository::class);
    }

    /**
     * Catalog-eligible edition: active status + a dated window inside the 2-day
     * grace (matches EditionRepositoryCatalogEligibilityTest's included-fixture
     * shape). $flag toggles ONLY the exclude_from_catalog flag, so the flag is
     * the sole differentiator between the two editions.
     */
    private function catalogEligibleEdition(bool $flag): int
    {
        $end  = date('Y-m-d', strtotime('-1 day')); // ended yesterday, inside grace
        $meta = [
            '_ntdst_status'     => 'open',
            '_ntdst_start_date' => $end,
            '_ntdst_end_date'   => $end,
        ];
        if ($flag) {
            $meta['_ntdst_exclude_from_catalog'] = true;
        }

        $id = $this->createTestEdition(['meta' => $meta]);
        $this->createdPosts[] = $id;

        return $id;
    }

    /**
     * Catalog-eligible edition with exclude_from_catalog EXPLICITLY set false
     * THROUGH THE FIELD (not raw meta), so the `_ntdst_exclude_from_catalog`
     * meta row EXISTS carrying the bool-false representation. This exercises the
     * `!= '1'` OR-branch of excludeFromCatalogMetaQuery() — the "flag present but
     * false" case the NOT-EXISTS-only fixtures never reach. A `bool` field's
     * false persists as '' (empty string, meta present), verified below.
     */
    private function catalogEligibleEditionFieldFalse(): int
    {
        $id = $this->catalogEligibleEdition(false); // no exclude meta yet

        // Write through the CPT field so it goes through the bool sanitizer and
        // the model's meta-write path exactly as an admin save would.
        ntdst_data()->get('vad_edition')->update($id, ['exclude_from_catalog' => false]);

        return $id;
    }

    /**
     * Teaser-eligible edition: the teaser query filters on ACTIVE STATUS (no
     * date WINDOW — a past-end active classroom edition still shows) but orders
     * by `_ntdst_start_date` with an EXISTS join, so a fully-dateless edition is
     * dropped from the teaser. The fixture therefore carries a start_date to
     * qualify. An EARLY start_date is used so the row sorts to the front of the
     * start_date-ASC order and survives the limit-6 cap deterministically even
     * on a shared DB with other active teaser editions present. $flag remains
     * the sole differentiator between the two teaser fixtures.
     */
    private function teaserEligibleEdition(bool $flag): int
    {
        $meta = [
            '_ntdst_status'     => 'open',
            '_ntdst_start_date' => '2000-01-01', // sorts first under start_date ASC
        ];
        if ($flag) {
            $meta['_ntdst_exclude_from_catalog'] = true;
        }

        $id = $this->createTestEdition(['meta' => $meta]);
        $this->createdPosts[] = $id;

        return $id;
    }

    /**
     * Active trajectory (status 'open' ∈ ACTIVE_STATUSES). No createTest helper
     * exists for trajectories, so insert raw + set meta the same way
     * createTestEdition does, and track for teardown. $flag toggles only the
     * exclude flag.
     */
    private function activeTrajectory(bool $flag): int
    {
        $id = wp_insert_post([
            'post_title'  => 'Test Trajectory ' . wp_generate_password(4, false),
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($id)) {
            $this->fail('Failed to create test trajectory: ' . $id->get_error_message());
        }

        $this->createdPosts[] = $id;

        update_post_meta($id, '_ntdst_status', 'open');
        if ($flag) {
            update_post_meta($id, '_ntdst_exclude_from_catalog', true);
        }

        return $id;
    }

    /** Robustly read a trajectory id out of a findActive() hydrated row. */
    private function rowId(array $row): int
    {
        return (int) ($row['id'] ?? $row['ID'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Surface 1: primary catalog list — findCatalogEligibleIds()
    // ------------------------------------------------------------------

    public function test_flagged_edition_is_absent_from_the_primary_catalog_list(): void
    {
        $flagged   = $this->catalogEligibleEdition(true);
        $unflagged = $this->catalogEligibleEdition(false);

        $ids = $this->editions()->findCatalogEligibleIds();

        // Denial path: the flagged edition must be filtered out of the listing.
        $this->assertNotContains(
            $flagged,
            $ids,
            'exclude_from_catalog=true must hide the edition from the primary catalog list',
        );

        // Negative control: the unflagged sibling — identical but for the flag —
        // MUST remain, proving we filtered on the flag and did not over-filter.
        $this->assertContains(
            $unflagged,
            $ids,
            'An unflagged, otherwise-identical edition must stay in the catalog list',
        );
    }

    /**
     * Pins the `!= '1'` OR-branch of excludeFromCatalogMetaQuery(): an edition
     * whose exclude flag is PRESENT-but-false (meta row exists, value '') must
     * stay in the catalog. The other tests only ever write NO exclude meta, so
     * they cover the NOT-EXISTS branch alone — if someone deleted the `!= '1'`
     * clause, a flagged-then-unflagged edition (meta present, '') would be
     * wrongly EXCLUDED and every other test would stay green. This one goes RED.
     */
    public function test_edition_with_flag_present_but_false_stays_in_the_primary_catalog_list(): void
    {
        $presentFalse = $this->catalogEligibleEditionFieldFalse();

        // Confirm the representation the branch depends on: the meta row EXISTS
        // (so NOT EXISTS does NOT match it) and carries the bool-false value ''.
        $this->assertTrue(
            metadata_exists('post', $presentFalse, '_ntdst_exclude_from_catalog'),
            'field=false must leave the meta row PRESENT (not delete it) so the != 1 branch is what keeps it',
        );
        $this->assertSame(
            '',
            get_post_meta($presentFalse, '_ntdst_exclude_from_catalog', true),
            'A bool field false persists as an empty string; the != 1 branch must match it',
        );

        $ids = $this->editions()->findCatalogEligibleIds();

        // The present-but-false edition must be KEPT — only value === '1' hides.
        $this->assertContains(
            $presentFalse,
            $ids,
            'An edition with exclude_from_catalog PRESENT but false (!= 1) must stay in the catalog list',
        );
    }

    // ------------------------------------------------------------------
    // Boundary: flag is listing-only, NOT access control
    // ------------------------------------------------------------------

    public function test_flagged_edition_is_still_reachable_by_direct_find(): void
    {
        $flagged = $this->catalogEligibleEdition(true);

        $post = $this->editions()->find($flagged);

        // The flag hides from listings only — the single-item lookup that backs
        // the direct-URL enrollable page must STILL return the post.
        $this->assertInstanceOf(
            \WP_Post::class,
            $post,
            'exclude_from_catalog is listing-only; find($id) must still return the edition',
        );
        $this->assertSame($flagged, $post->ID);
    }

    // ------------------------------------------------------------------
    // Surface 2: homepage teasers — findArchiveClassroomTeaserIds()
    // ------------------------------------------------------------------

    public function test_flagged_edition_is_absent_from_the_teaser_strip(): void
    {
        $flagged   = $this->teaserEligibleEdition(true);
        $unflagged = $this->teaserEligibleEdition(false);

        $ids = $this->editions()->findArchiveClassroomTeaserIds();

        $this->assertNotContains(
            $flagged,
            $ids,
            'exclude_from_catalog=true must hide the edition from the homepage teaser strip',
        );
        $this->assertContains(
            $unflagged,
            $ids,
            'An unflagged, otherwise teaser-eligible edition must stay in the teaser strip',
        );
    }

    // ------------------------------------------------------------------
    // Surface 3: trajectory cards — findActive()
    // ------------------------------------------------------------------

    public function test_flagged_trajectory_is_absent_from_the_active_trajectory_cards(): void
    {
        $flagged   = $this->activeTrajectory(true);
        $unflagged = $this->activeTrajectory(false);

        $activeIds = array_map([$this, 'rowId'], $this->trajectories()->findActive());

        $this->assertNotContains(
            $flagged,
            $activeIds,
            'exclude_from_catalog=true must hide the trajectory from the active-trajectory cards',
        );
        $this->assertContains(
            $unflagged,
            $activeIds,
            'An unflagged, active trajectory must stay in the trajectory cards',
        );
    }
}
