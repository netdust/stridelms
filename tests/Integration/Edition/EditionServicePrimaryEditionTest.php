<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionService;

/**
 * Pins EditionService::getPrimaryEdition(array $activeEditionIds): ?int — the
 * primary-edition CTA pick extracted out of the theme (Cluster 3 / Task 3.4 / B4).
 *
 * THE RULE (one canonical home; previously duplicated in
 * single-sfwd-courses.php and stridence_prefetch_course_cards):
 *   among the given active edition ids, return the first whose EFFECTIVE status
 *   (INV-7, via getEffectiveStatuses) allowsEnrollment(); if none is enrollable,
 *   return the first active id; if the array is empty, return null.
 *
 * B4 is the bug this fixes: a course can have a RUNNING cohort (active, not
 * enrollable) AND an OPEN cohort (enrollable). The enrollable cohort must drive
 * the CTA even when it is not first in the list — otherwise the sidebar says
 * "Niet beschikbaar" while an open cohort exists.
 *
 * Fixtures use ONLINE-format courses + future dates so getEffectiveStatus does
 * not flip status (no terminal stored status, no past-date → Completed flip, no
 * classroom-zero-sessions → Announcement flip): 'open' stays Open (enrollable),
 * 'in_progress' stays InProgress (active, not enrollable).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionServicePrimaryEditionTest
 */
final class EditionServicePrimaryEditionTest extends IntegrationTestCase
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

    private function editionFor(int $courseId, string $status): int
    {
        $future = date('Y-m-d', strtotime('+10 days'));

        return $this->createTestEdition(['meta' => [
            '_ntdst_status'     => $status,
            '_ntdst_course_id'  => $courseId,
            '_ntdst_start_date' => $future,
            '_ntdst_end_date'   => $future,
        ]]);
    }

    public function test_enrollable_cohort_wins_even_when_not_first(): void
    {
        $course    = $this->createTestCourse();
        $this->setFormats($course, ['online']);
        $runningId = $this->editionFor($course, 'in_progress'); // active, NOT enrollable
        $openId    = $this->editionFor($course, 'open');        // enrollable

        // running listed first — the open cohort must still be the primary (B4).
        $this->assertSame(
            $openId,
            $this->service()->getPrimaryEdition([$runningId, $openId]),
            'the enrollable (open) cohort drives the CTA even when not first',
        );
    }

    public function test_order_independence(): void
    {
        $course    = $this->createTestCourse();
        $this->setFormats($course, ['online']);
        $runningId = $this->editionFor($course, 'in_progress');
        $openId    = $this->editionFor($course, 'open');

        $this->assertSame(
            $openId,
            $this->service()->getPrimaryEdition([$openId, $runningId]),
            'open cohort wins regardless of position in the array',
        );
    }

    public function test_only_running_returns_the_running_edition(): void
    {
        $course    = $this->createTestCourse();
        $this->setFormats($course, ['online']);
        $runningId = $this->editionFor($course, 'in_progress'); // active, not enrollable

        $this->assertSame(
            $runningId,
            $this->service()->getPrimaryEdition([$runningId]),
            'with no enrollable cohort, the first active edition is the primary',
        );
    }

    public function test_empty_array_returns_null(): void
    {
        $this->assertNull(
            $this->service()->getPrimaryEdition([]),
            'no active editions → no primary',
        );
    }
}
