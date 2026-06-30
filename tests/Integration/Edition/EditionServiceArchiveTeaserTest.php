<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionService;

/**
 * Pins EditionService::getArchiveTeaserItems(string $strip) — the homepage SEO
 * teaser strips (archive-sfwd-courses.php) moved out of the theme (Cluster 3 /
 * Task 3.3). PURE DE-DUPLICATION: the teaser's DISTINCT behavior is preserved,
 * NOT converged to the canonical catalog rule (getCatalogItems):
 *
 *   - classroom strip: ACTIVE-status-ONLY (NO date window — a past-end active
 *     classroom edition still shows), editions of online-format courses
 *     EXCLUDED, dateless editions EXCLUDED (the start_date EXISTS-join drops
 *     them), capped at the limit.
 *   - online strip: the CANONICAL date-window applies (a past-grace online
 *     edition is dropped), online-format-course-scoped, dateless EXCLUDED for
 *     the teaser, capped, with pure-LD online courses topping up the remainder.
 *
 * Stefan ruled (2026-06-30): KEEP the teaser behavior exactly as-is — it is a
 * 6-item homepage strip, not the full catalog. So this test asserts PARITY WITH
 * CURRENT BEHAVIOR (the distinguishing active-only / dateless-excluded shape),
 * NOT convergence with the /klassikaal + /online catalog.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionServiceArchiveTeaserTest
 */
final class EditionServiceArchiveTeaserTest extends IntegrationTestCase
{
    private function service(): EditionService
    {
        return ntdst_get(EditionService::class);
    }

    /** Tag a course with stride_format slugs (term-created on demand). */
    private function setFormats(int $courseId, array $slugs): void
    {
        $termIds = [];
        foreach ($slugs as $slug) {
            $existing = term_exists($slug, 'stride_format');
            $termIds[] = (int) ($existing['term_id'] ?? (wp_insert_term($slug, 'stride_format')['term_id'] ?? 0));
        }
        wp_set_object_terms($courseId, array_filter($termIds), 'stride_format');
    }

    /**
     * Edition with explicit dates (null start = dateless: no start/end meta).
     */
    private function editionFor(int $courseId, string $status = 'open', ?string $start = null, ?string $end = null): int
    {
        $meta = [
            '_ntdst_status'    => $status,
            '_ntdst_course_id' => $courseId,
        ];
        if ($start !== null) {
            $meta['_ntdst_start_date'] = $start;
        }
        if ($end !== null) {
            $meta['_ntdst_end_date'] = $end;
        }

        return $this->createTestEdition(['meta' => $meta]);
    }

    /** @return list<int> edition ids in the teaser strip (course-kind items omitted). */
    private function editionIdsOf(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'edition') {
                $out[] = (int) ($item['edition']['id'] ?? 0);
            }
        }

        return $out;
    }

    /** @return list<int> course ids referenced by the teaser strip items. */
    private function courseIdsOf(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'course') {
                $out[] = (int) $item['course_id'];
            } elseif (($item['kind'] ?? '') === 'edition') {
                $out[] = (int) ($item['edition']['course_id'] ?? 0);
            }
        }

        return $out;
    }

    public function test_classroom_teaser_includes_active_edition_with_past_end_date(): void
    {
        // The DISTINGUISHING teaser behavior: active-only, NO date window. A
        // classroom edition that already ended (well outside any grace) but is
        // still status=open STAYS in the teaser. The canonical date-window
        // catalog would drop it — the teaser deliberately does not.
        $course = $this->createTestCourse();
        $this->setFormats($course, ['klassikaal']);
        $past = date('Y-m-d', strtotime('-60 days'));
        $editionId = $this->editionFor($course, 'open', $past, $past);

        $ids = $this->editionIdsOf($this->service()->getArchiveTeaserItems('classroom'));

        $this->assertContains($editionId, $ids, 'active past-end classroom edition stays in the teaser (active-only, no date window)');
    }

    public function test_classroom_teaser_excludes_dateless_edition(): void
    {
        // The start_date EXISTS-join in the teaser query drops dateless editions.
        $course = $this->createTestCourse();
        $this->setFormats($course, ['klassikaal']);
        $datelessId = $this->editionFor($course, 'open', null, null);

        $ids = $this->editionIdsOf($this->service()->getArchiveTeaserItems('classroom'));

        $this->assertNotContains($datelessId, $ids, 'dateless classroom edition excluded from teaser');
    }

    public function test_classroom_teaser_excludes_online_course_edition(): void
    {
        $online = $this->createTestCourse();
        $this->setFormats($online, ['online']);
        $future = date('Y-m-d', strtotime('+10 days'));
        $onlineEditionId = $this->editionFor($online, 'open', $future, $future);

        $ids = $this->editionIdsOf($this->service()->getArchiveTeaserItems('classroom'));

        $this->assertNotContains($onlineEditionId, $ids, 'edition of an online-format course excluded from classroom strip');
    }

    public function test_online_teaser_applies_date_window(): void
    {
        // Unlike the classroom strip, the online strip IS date-windowed:
        // a past-grace online edition is dropped.
        $course = $this->createTestCourse();
        $this->setFormats($course, ['online']);
        $past = date('Y-m-d', strtotime('-60 days'));
        $pastEditionId = $this->editionFor($course, 'open', $past, $past);

        $ids = $this->editionIdsOf($this->service()->getArchiveTeaserItems('online'));

        $this->assertNotContains($pastEditionId, $ids, 'past-grace online edition dropped by the date window');
    }

    public function test_online_teaser_tops_up_with_pure_ld_courses(): void
    {
        $course = $this->createTestCourse();
        $this->setFormats($course, ['online']);
        // No edition — pure-LD online course tops up the remainder.

        $items = $this->service()->getArchiveTeaserItems('online');

        $courseKindIds = [];
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'course') {
                $courseKindIds[] = (int) $item['course_id'];
            }
        }

        $this->assertContains($course, $courseKindIds, 'pure-LD online course tops up the online teaser as a course-kind item');
    }

    public function test_classroom_teaser_caps_at_the_limit(): void
    {
        $course = $this->createTestCourse();
        $this->setFormats($course, ['klassikaal']);
        $future = date('Y-m-d', strtotime('+10 days'));
        for ($i = 0; $i < 9; $i++) {
            $this->editionFor($course, 'open', $future, $future);
        }

        $items = $this->service()->getArchiveTeaserItems('classroom', 6);

        $this->assertLessThanOrEqual(6, count($items), 'classroom teaser capped at the limit');
    }
}
