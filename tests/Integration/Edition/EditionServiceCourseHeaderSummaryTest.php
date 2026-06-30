<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Domain\Money;
use Stride\Modules\Edition\EditionService;

/**
 * Pins EditionService::getCourseHeaderSummary(int $courseId) — the next-edition +
 * upcoming-count + price-range aggregation extracted out of the course-header
 * template (templates/course/header.php:39-74, Cluster 3 / Task 3.5 / B5).
 *
 * The returned struct:
 *   array{
 *     next_edition_date: ?string,
 *     upcoming_count: int,
 *     price_min_cents: ?int,
 *     price_max_cents: ?int,
 *   }
 *
 * INV-3: editions read via the repo (findByCourse). The OPTIONAL-PRICE semantics
 * are intentional flow, not error-swallowing: a getPrice() throw on one edition
 * excludes ONLY that edition from the range (the method still returns the rest),
 * and a 0/absent price is excluded from min/max via the `> 0` guard.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionServiceCourseHeaderSummaryTest
 */
final class EditionServiceCourseHeaderSummaryTest extends IntegrationTestCase
{
    private function service(): EditionService
    {
        return ntdst_get(EditionService::class);
    }

    /**
     * Create a published edition tied to $courseId.
     *
     * @param string $start         Y-m-d start date ('' for none)
     * @param ?float $nonMemberPrice EUR amount for the non-member price field
     *                               (the one getPrice() reads with no user), or
     *                               null to leave it unset (→ 0 → excluded)
     */
    private function editionFor(int $courseId, string $start, ?float $nonMemberPrice = null): int
    {
        $meta = [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => $courseId,
            '_ntdst_start_date' => $start,
            '_ntdst_end_date'   => $start,
        ];
        if ($nonMemberPrice !== null) {
            $meta['_ntdst_price_non_member'] = $nonMemberPrice;
        }

        return $this->createTestEdition(['meta' => $meta]);
    }

    public function test_all_past_editions_yield_empty_summary(): void
    {
        $course = $this->createTestCourse();
        $this->editionFor($course, date('Y-m-d', strtotime('-30 days')), 100.0);
        $this->editionFor($course, date('Y-m-d', strtotime('-5 days')), 200.0);

        $summary = $this->service()->getCourseHeaderSummary($course);

        $this->assertSame(0, $summary['upcoming_count'], 'no future editions → count 0');
        $this->assertNull($summary['next_edition_date'], 'no future editions → null next date');
        $this->assertNull($summary['price_min_cents'], 'no future editions → null min');
        $this->assertNull($summary['price_max_cents'], 'no future editions → null max');
    }

    public function test_mixed_editions_pick_soonest_future_and_count_only_future(): void
    {
        $course = $this->createTestCourse();
        $this->editionFor($course, date('Y-m-d', strtotime('-10 days')), 100.0); // past
        $soonest = date('Y-m-d', strtotime('+5 days'));
        $later   = date('Y-m-d', strtotime('+40 days'));
        $this->editionFor($course, $later, 100.0);   // future, not soonest
        $this->editionFor($course, $soonest, 100.0); // future, soonest

        $summary = $this->service()->getCourseHeaderSummary($course);

        $this->assertSame(2, $summary['upcoming_count'], 'only the two future editions counted');
        $this->assertSame($soonest, $summary['next_edition_date'], 'next date is the soonest future start');
    }

    public function test_zero_priced_edition_excluded_from_range_priced_ones_aggregate(): void
    {
        $course = $this->createTestCourse();
        // priced future editions: 100 and 250 EUR
        $this->editionFor($course, date('Y-m-d', strtotime('+5 days')), 100.0);
        $this->editionFor($course, date('Y-m-d', strtotime('+6 days')), 250.0);
        // no-price future edition — must NOT drag the range down to 0
        $this->editionFor($course, date('Y-m-d', strtotime('+7 days')), null);

        $summary = $this->service()->getCourseHeaderSummary($course);

        $this->assertSame(3, $summary['upcoming_count'], 'all three future editions counted');
        $this->assertSame(10000, $summary['price_min_cents'], 'min over the PRICED subset (100 EUR)');
        $this->assertSame(25000, $summary['price_max_cents'], 'max over the PRICED subset (250 EUR)');
    }

    public function test_getprice_throw_on_one_edition_omits_it_and_returns_the_rest(): void
    {
        $course = $this->createTestCourse();
        $good   = $this->editionFor($course, date('Y-m-d', strtotime('+5 days')), 150.0);
        $bad    = $this->editionFor($course, date('Y-m-d', strtotime('+6 days')), 999.0);

        // Force getPrice() to throw for the $bad edition only.
        $filter = static function (Money $price, int $editionId) use ($bad): Money {
            if ($editionId === $bad) {
                throw new \RuntimeException('boom — simulated price lookup failure');
            }

            return $price;
        };
        add_filter('stride/membership/price', $filter, 10, 2);

        try {
            $summary = $this->service()->getCourseHeaderSummary($course);
        } finally {
            remove_filter('stride/membership/price', $filter, 10);
        }

        // No fatal; both editions are upcoming, but only the good one's price aggregates.
        $this->assertSame(2, $summary['upcoming_count'], 'both editions still counted as upcoming');
        $this->assertSame(15000, $summary['price_min_cents'], 'only the non-throwing edition priced in');
        $this->assertSame(15000, $summary['price_max_cents'], 'the throwing edition is omitted from the range');
    }
}
