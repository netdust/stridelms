<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionRepository;

/**
 * Pins the SINGLE catalog-eligibility predicate now owned by
 * EditionRepository::findCatalogEligibleIds() (Cluster 3 / Task 3.1). This is
 * the rule the audit found forking between helpers/catalog.php and
 * archive-sfwd-courses.php — it lives in exactly one home and is pinned here.
 *
 * Eligibility = published edition + active status (announcement/open/full/
 * in_progress) + the 3-branch date window:
 *   (1) end_date within a 2-day grace past today,
 *   (2) end_date missing AND start_date within the grace, or
 *   (3) fully dateless (neither start_date nor end_date set).
 *
 * INV-3: meta keys derive from EditionRepository::getMetaPrefix(); the query
 * shape lives in the repo, not the theme.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionRepositoryCatalogEligibilityTest
 */
final class EditionRepositoryCatalogEligibilityTest extends IntegrationTestCase
{
    private function repo(): EditionRepository
    {
        return ntdst_get(EditionRepository::class);
    }

    private function dated(string $end, string $start = null, string $status = 'open'): int
    {
        $meta = [
            '_ntdst_status'   => $status,
            '_ntdst_end_date' => $end,
        ];
        if ($start !== null) {
            $meta['_ntdst_start_date'] = $start;
        }

        return $this->createTestEdition(['meta' => $meta]);
    }

    /** No start_date / end_date meta at all (fully dateless interest anchor). */
    private function dateless(string $status = 'announcement'): int
    {
        return $this->createTestEdition(['meta' => ['_ntdst_status' => $status]]);
    }

    public function test_dated_edition_inside_two_day_grace_is_included(): void
    {
        $end = date('Y-m-d', strtotime('-1 day')); // ended yesterday, inside grace
        $id  = $this->dated($end, $end);

        $this->assertContains($id, $this->repo()->findCatalogEligibleIds());
    }

    public function test_dated_edition_past_the_grace_is_excluded(): void
    {
        $end = date('Y-m-d', strtotime('-5 days')); // well past the 2-day grace
        $id  = $this->dated($end, $end);

        $this->assertNotContains($id, $this->repo()->findCatalogEligibleIds());
    }

    public function test_end_date_missing_with_start_in_grace_is_included(): void
    {
        // No end_date written; start_date inside the grace -> branch (2).
        $start = date('Y-m-d', strtotime('-1 day'));
        $id    = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_start_date' => $start,
        ]]);

        $this->assertContains($id, $this->repo()->findCatalogEligibleIds());
    }

    public function test_fully_dateless_edition_is_included(): void
    {
        $id = $this->dateless();

        $this->assertContains($id, $this->repo()->findCatalogEligibleIds());
    }

    public function test_draft_post_status_edition_is_excluded(): void
    {
        $future = date('Y-m-d', strtotime('+10 days'));
        $id     = $this->createTestEdition([
            'post_status' => 'draft',
            'meta'        => [
                '_ntdst_status'     => 'open',
                '_ntdst_start_date' => $future,
                '_ntdst_end_date'   => $future,
            ],
        ]);

        $this->assertNotContains($id, $this->repo()->findCatalogEligibleIds());
    }

    public function test_non_active_status_edition_is_excluded(): void
    {
        // Cancelled is terminal -> never catalog-eligible, even when dated soon.
        $future = date('Y-m-d', strtotime('+10 days'));
        $id     = $this->dated($future, $future, 'cancelled');

        $this->assertNotContains($id, $this->repo()->findCatalogEligibleIds());
    }

    public function test_course_id_filter_restricts_to_supplied_courses(): void
    {
        $courseA = $this->createTestCourse();
        $courseB = $this->createTestCourse();

        $future = date('Y-m-d', strtotime('+10 days'));
        $idA = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => $courseA,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);
        $idB = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => $courseB,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);

        $ids = $this->repo()->findCatalogEligibleIds([$courseA]);

        $this->assertContains($idA, $ids);
        $this->assertNotContains($idB, $ids);
    }

    public function test_result_is_capped_at_the_limit_param(): void
    {
        $future = date('Y-m-d', strtotime('+10 days'));
        for ($i = 0; $i < 4; $i++) {
            $this->createTestEdition(['meta' => [
                '_ntdst_status'     => 'open',
                '_ntdst_start_date' => $future,
                '_ntdst_end_date'   => $future,
            ]]);
        }

        $ids = $this->repo()->findCatalogEligibleIds(null, 2);

        $this->assertCount(2, $ids, 'The limit param caps the enumeration');
    }
}
