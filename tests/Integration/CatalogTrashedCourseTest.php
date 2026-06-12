<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Shake-out F2 (AF-4 wrong-order edge): an ACTIVE edition whose course was
 * TRASHED must not produce a catalog card.
 *
 * Root cause: get_post()/get_post_status() return trashed posts non-null, so
 * the eligible-items builder (stridence_catalog_edition_items_from_ids) and
 * the card partial's INF-1 publish guard only suppressed the course TITLE —
 * the card itself still rendered from the edition-title fallback.
 *
 * Contract: trash the course → the edition disappears from the eligible
 * items AND a stale pre-built item renders to nothing (no fatal); restore
 * the course → the card returns. The /online pure-LD course path must keep
 * excluding trashed courses (post_status=publish query — regression pin).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CatalogTrashedCourse"
 */
final class CatalogTrashedCourseTest extends IntegrationTestCase
{
    /**
     * @return list<int> edition ids present in a catalog items list
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

    /**
     * @return list<int> pure-LD course ids present in a catalog items list
     */
    private function courseIdsIn(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'course') {
                $ids[] = (int) ($item['course_id'] ?? 0);
            }
        }

        return $ids;
    }

    /** @test */
    public function trashedCourseEditionIsSkippedOnKlassikaalAndReturnsOnRestore(): void
    {
        $this->assertTrue(
            function_exists('stridence_catalog_items'),
            'stridence theme catalog helpers must be loaded',
        );

        $future = date('Y-m-d', strtotime('+30 days'));
        $courseId = $this->createTestCourse(['post_title' => 'TrashedCourseF2 ' . wp_generate_password(4, false)]);
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => $courseId,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);

        // Baseline: published course → the edition is eligible and renders.
        $items = stridence_catalog_items('klassikaal');
        $this->assertContains($editionId, $this->editionIdsIn($items), 'Fixture sanity: active edition must list while its course is published');

        $staleItem = null;
        foreach ($items as $item) {
            if ((int) ($item['edition']['id'] ?? 0) === $editionId) {
                $staleItem = $item;
                break;
            }
        }
        $this->assertNotNull($staleItem);

        $html = stridence_catalog_render_cards([$staleItem], null);
        $this->assertStringContainsString(
            (string) get_permalink($editionId),
            $html,
            'Fixture sanity: the edition card must render while the course is published',
        );

        // Trash the course — the AF-4 wrong-order edge. The active edition
        // still exists and matches every meta predicate.
        wp_trash_post($courseId);

        // 1. Builder layer: the eligible-items pre-pass must skip the edition.
        $this->assertNotContains(
            $editionId,
            $this->editionIdsIn(stridence_catalog_items('klassikaal')),
            'F2: an edition whose course is trashed must be filtered out of the eligible items',
        );

        // 2. Partial layer (defensive skip): a STALE item built before the
        // trash must render to no card — and no fatal.
        $staleHtml = stridence_catalog_render_cards([$staleItem], null);
        $this->assertStringNotContainsString(
            (string) get_permalink($editionId),
            $staleHtml,
            'F2: the card partial must skip an edition whose course is no longer published',
        );

        // Restore: untrash + republish (wp_untrash_post restores to draft
        // since WP 5.6) → the card returns.
        wp_untrash_post($courseId);
        wp_update_post(['ID' => $courseId, 'post_status' => 'publish']);

        $this->assertContains(
            $editionId,
            $this->editionIdsIn(stridence_catalog_items('klassikaal')),
            'Restoring the course must bring the edition card back',
        );
    }

    /** @test */
    public function draftCourseEditionIsAlsoSkipped(): void
    {
        // Same INF-1 family as the trashed case: a draft (unpublished) course
        // must not leak an enrollable card either.
        $future = date('Y-m-d', strtotime('+30 days'));
        $courseId = $this->createTestCourse(['post_status' => 'draft']);
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => $courseId,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);

        $this->assertNotContains(
            $editionId,
            $this->editionIdsIn(stridence_catalog_items('klassikaal')),
            'An edition whose course is a draft must be filtered out of the eligible items',
        );
    }

    /** @test */
    public function editionWithoutCourseStillLists(): void
    {
        // Negative guard on the fix itself: a course-less edition (course_id
        // 0 — dateless/standalone shape) must NOT be swallowed by the
        // course-status filter.
        $future = date('Y-m-d', strtotime('+30 days'));
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => 0,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);

        $this->assertContains(
            $editionId,
            $this->editionIdsIn(stridence_catalog_items('klassikaal')),
            'An edition with no course must keep listing — the course filter only applies when course_id is set',
        );
    }

    /** @test */
    public function trashedPureLdCourseIsExcludedFromOnlineCatalog(): void
    {
        // Regression pin for /online's course cards: the pure-LD enumeration
        // queries post_status=publish, so a trashed pure-LD course must not
        // produce a card.
        $term = term_exists('online', 'stride_format') ?: wp_insert_term('online', 'stride_format');
        $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;

        $courseId = $this->createTestCourse();
        wp_set_object_terms($courseId, [$termId], 'stride_format');

        $this->assertContains(
            $courseId,
            $this->courseIdsIn(stridence_catalog_items('online')),
            'Fixture sanity: a published pure-LD online course must list',
        );

        wp_trash_post($courseId);

        $this->assertNotContains(
            $courseId,
            $this->courseIdsIn(stridence_catalog_items('online')),
            'A trashed pure-LD course must not produce an /online card',
        );
    }
}
