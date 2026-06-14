<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Dateless editions list in the catalog (klassikaal AND online); the
 * publish/active/published-course guards (INF-1 + the plan's threat note)
 * still hold.
 *
 * Plan: docs/plans/2026-06-14-dateless-editions-catalog.md (Tasks 1 + 2).
 *
 * Inclusion is SYMMETRIC across klassikaal and online — both share the one
 * date-window meta_query builder; the start_date orderby that forced the
 * EXISTS join (and excluded fully-dateless editions) is removed from both
 * item builders. Card TREATMENT differs by effective status (later tasks),
 * but query INCLUSION does not.
 *
 * The catalog helpers are loaded by the integration bootstrap (the
 * stridence theme functions run on wp-load); we assert function_exists as a
 * fixture-sanity guard rather than re-require the file.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CatalogDatelessInclusionTest
 */
final class CatalogDatelessInclusionTest extends IntegrationTestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(
            function_exists('stridence_catalog_klassikaal_items')
                && function_exists('stridence_catalog_online_items'),
            'stridence catalog helpers must be loaded by the integration bootstrap',
        );

        $this->prefix = ntdst_get(\Stride\Modules\Edition\EditionRepository::class)->getMetaPrefix();
    }

    /**
     * Resolve (creating if needed) a stride_format term and assign it to a course.
     */
    private function assignFormat(int $courseId, string $slug): void
    {
        $term = term_exists($slug, 'stride_format') ?: wp_insert_term($slug, 'stride_format');
        $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;
        wp_set_object_terms($courseId, [$termId], 'stride_format');
    }

    /**
     * @return list<int> edition ids present in a built catalog item list
     */
    private function editionIdsIn(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (($item['kind'] ?? 'edition') === 'edition') {
                $ids[] = (int) ($item['edition']['id'] ?? 0);
            }
        }

        return $ids;
    }

    public function test_publish_dateless_klassikaal_edition_with_publish_course_is_included(): void
    {
        $course = $this->createTestCourse(['post_status' => 'publish']);
        $this->assignFormat($course, 'klassikaal');

        // Dateless: no start_date / end_date meta written at all.
        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_status'    => 'announcement',
            '_ntdst_course_id' => $course,
        ]]);

        $this->assertContains(
            $edition,
            $this->editionIdsIn(stridence_catalog_klassikaal_items()),
            'Dateless publish klassikaal edition must list',
        );
    }

    public function test_publish_dateless_online_edition_with_publish_course_is_included(): void
    {
        // An always-on online edition with no dates must ALSO list — it is a
        // normal enrollable, just dateless. Card treatment differs (status
        // stays Open → enroll card, not interest), inclusion does not.
        $course = $this->createTestCourse(['post_status' => 'publish']);
        $this->assignFormat($course, 'online');

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_status'    => 'open',
            '_ntdst_course_id' => $course,
        ]]);

        $this->assertContains(
            $edition,
            $this->editionIdsIn(stridence_catalog_online_items()),
            'Dateless always-on online edition must list',
        );
    }

    public function test_draft_dateless_edition_is_excluded(): void
    {
        $course = $this->createTestCourse(['post_status' => 'publish']);
        $this->assignFormat($course, 'klassikaal');

        $edition = $this->createTestEdition(['post_status' => 'draft', 'meta' => [
            '_ntdst_status'    => 'announcement',
            '_ntdst_course_id' => $course,
        ]]);

        $this->assertNotContains(
            $edition,
            $this->editionIdsIn(stridence_catalog_klassikaal_items()),
            'A draft (unpublished) dateless edition must never list — post_status guard holds',
        );
    }

    public function test_dateless_edition_with_draft_course_does_not_leak(): void
    {
        // INF-1 denial path / threat-model edge: a published dateless edition
        // whose linked course is a DRAFT must not list and must not leak its
        // course title.
        $course = $this->createTestCourse(['post_status' => 'draft']);
        $this->assignFormat($course, 'klassikaal');

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_status'    => 'announcement',
            '_ntdst_course_id' => $course,
        ]]);

        $this->assertNotContains(
            $edition,
            $this->editionIdsIn(stridence_catalog_klassikaal_items()),
            'INF-1: a dateless edition whose course is draft must not list',
        );
    }

    public function test_dated_edition_before_grace_cutoff_is_excluded(): void
    {
        // The widening permits NULL start/end; it must NOT resurrect a dated
        // edition that ended before the -2-day grace cutoff.
        $course = $this->createTestCourse(['post_status' => 'publish']);
        $this->assignFormat($course, 'klassikaal');

        $old = date('Y-m-d', strtotime('-30 days'));
        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'announcement',
            '_ntdst_course_id'  => $course,
            '_ntdst_start_date' => $old,
            '_ntdst_end_date'   => $old,
        ]]);

        $this->assertNotContains(
            $edition,
            $this->editionIdsIn(stridence_catalog_klassikaal_items()),
            'A dated edition before the grace cutoff stays excluded',
        );
    }
}
