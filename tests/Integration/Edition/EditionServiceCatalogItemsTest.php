<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionService;

/**
 * Pins EditionService::getCatalogItems(string $catalog) — the catalog POLICY
 * moved out of the theme's helpers/catalog.php (Cluster 3 / Task 3.2). The repo
 * (Task 3.1) owns the raw eligibility QUERY; this method composes it into the
 * fully-shaped item list the theme prepass consumes:
 *   - klassikaal: editions only, format-excluded (online-only courses dropped),
 *     NO pure-LD courses, returned UNORDERED (theme band-orders).
 *   - online: editions of online-format courses + pure-LD online courses
 *     (kind 'course'), flat.
 *
 * The returned shape MUST match the theme's previous output verbatim so the
 * INV-7 prepass + pure-renderer partials are untouched:
 *   list<
 *     array{kind:'edition', edition:array{id,title,course_id,start_date,
 *       end_date,venue,price,capacity,status,spots_remaining}, themes:list<string>}
 *     | array{kind:'course', course_id:int, themes:list<string>}
 *   >
 *
 * INV-3: data via the repo. INV-5: no stridence_* call inside the service.
 * INV-7: the item's `status` is the raw stored status handed to the prepass —
 * the service does NOT pre-apply effective-status (preserve existing wiring).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionServiceCatalogItemsTest
 */
final class EditionServiceCatalogItemsTest extends IntegrationTestCase
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

    private function editionFor(int $courseId, string $status = 'open', ?string $start = null): int
    {
        $future = $start ?? date('Y-m-d', strtotime('+10 days'));

        return $this->createTestEdition(['meta' => [
            '_ntdst_status'     => $status,
            '_ntdst_course_id'  => $courseId,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);
    }

    /** @return list<int> course_ids referenced by the returned items */
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

    public function test_online_only_course_edition_excluded_from_klassikaal_present_in_online(): void
    {
        $course = $this->createTestCourse();
        $this->setFormats($course, ['online']); // online-only format
        $this->editionFor($course);

        $klassikaal = $this->courseIdsOf($this->service()->getCatalogItems('klassikaal'));
        $online     = $this->courseIdsOf($this->service()->getCatalogItems('online'));

        $this->assertNotContains($course, $klassikaal, 'online-only course excluded from klassikaal');
        $this->assertContains($course, $online, 'online-only course present in online');
    }

    public function test_pure_ld_online_course_appears_as_course_kind_in_online_only(): void
    {
        $course = $this->createTestCourse();
        $this->setFormats($course, ['online']);
        // No edition created — pure-LD online course.

        $online = $this->service()->getCatalogItems('online');
        $kinds  = [];
        foreach ($online as $item) {
            if ((int) ($item['course_id'] ?? 0) === $course) {
                $kinds[] = $item['kind'];
            }
        }

        $this->assertSame(['course'], $kinds, 'pure-LD online course appears once as a course kind');
        $this->assertNotContains(
            $course,
            $this->courseIdsOf($this->service()->getCatalogItems('klassikaal')),
            'pure-LD online course must not appear in klassikaal',
        );
    }

    public function test_classroom_edition_item_has_the_documented_shape(): void
    {
        $course = $this->createTestCourse();
        $this->setFormats($course, ['klassikaal']);
        $editionId = $this->editionFor($course);

        $items = $this->service()->getCatalogItems('klassikaal');

        $found = null;
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'edition' && (int) $item['edition']['id'] === $editionId) {
                $found = $item;
                break;
            }
        }

        $this->assertNotNull($found, 'the classroom edition is present in klassikaal');
        $this->assertSame(['kind', 'edition', 'themes'], array_keys($found));
        $this->assertSame(
            ['id', 'title', 'course_id', 'start_date', 'end_date', 'venue', 'price', 'capacity', 'status', 'spots_remaining'],
            array_keys($found['edition']),
            'edition struct keys match the prepass contract verbatim',
        );
        $this->assertSame($editionId, $found['edition']['id']);
        $this->assertSame($course, $found['edition']['course_id']);
        $this->assertIsArray($found['themes']);
    }

    public function test_edition_of_draft_course_is_excluded_inf1_guard(): void
    {
        $course = $this->createTestCourse(['post_status' => 'draft']);
        $this->setFormats($course, ['klassikaal']);
        $editionId = $this->editionFor($course);

        $klassikaalEditionIds = array_map(
            static fn(array $i): int => (int) ($i['edition']['id'] ?? 0),
            array_filter(
                $this->service()->getCatalogItems('klassikaal'),
                static fn(array $i): bool => ($i['kind'] ?? '') === 'edition',
            ),
        );

        $this->assertNotContains($editionId, $klassikaalEditionIds, 'INF-1: edition of a draft course is suppressed');
    }
}
