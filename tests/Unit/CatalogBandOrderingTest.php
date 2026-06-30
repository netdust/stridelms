<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pure band-ordering for the KLASSIKAAL catalog list (Task 3 of the
 * dateless-editions-catalog plan).
 *
 * Bands: A = dated-soon (start_date >= today) ASC + course items; B = dateless
 * ("Binnenkort — toon interesse"); C = dated-grace (start_date < today) ASC.
 * Output A ++ B ++ C, with a page-1 guard that hoists B inside the first
 * STRIDENCE_CATALOG_PER_PAGE. /online never calls this — online is flat.
 */
final class CatalogBandOrderingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/web/app/themes/stridence/helpers/catalog.php';
    }

    /** @return array<string,mixed> */
    private function ed(int $id, ?string $start, ?string $sortDate = null, string $title = ''): array
    {
        return ['kind' => 'edition', 'edition' => [
            'id'         => $id,
            'start_date' => $start,
            // sort_date is the next-upcoming-session date the caller computes;
            // defaults to start_date so existing tests keep their meaning.
            'sort_date'  => $sortDate ?? $start,
            'title'      => $title,
        ], 'themes' => []];
    }

    /** @return array<string,mixed> */
    private function course(int $id): array
    {
        return ['kind' => 'course', 'course_id' => $id, 'themes' => []];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<int>
     */
    private function ids(array $items): array
    {
        return array_map(static fn(array $i): int => (int) ($i['edition']['id'] ?? $i['course_id']), $items);
    }

    public function test_orders_dated_soon_then_dateless_then_grace(): void
    {
        $soon = date('Y-m-d', strtotime('+5 days'));
        $past = date('Y-m-d', strtotime('-1 day')); // inside grace, < today
        $items = [
            $this->ed(3, $past),
            $this->ed(1, $soon),
            $this->ed(2, null),
        ];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([1, 2, 3], $out, 'A(soon) ++ B(dateless) ++ C(grace)');
    }

    public function test_dateless_band_sits_after_first_row_of_dated_soon(): void
    {
        // The "Binnenkort — toon interesse" band must surface high on page 1:
        // after the first row of dated-soon editions (STRIDENCE_CATALOG_BAND_LEAD),
        // not dead-last behind the whole dated list. With 6 dated-soon + 1
        // dateless, the dateless lands at index 3 (after the soonest 3), then
        // the remaining dated-soon, then grace.
        $items = [];
        for ($i = 1; $i <= 6; $i++) {
            $items[] = $this->ed($i, date('Y-m-d', strtotime("+{$i} days")));
        }
        $items[] = $this->ed(999, null); // one dateless
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $lead = STRIDENCE_CATALOG_BAND_LEAD;
        $this->assertSame(
            [1, 2, 3, 999, 4, 5, 6],
            $out,
            'dateless band sits after the first ' . $lead . ' dated-soon editions',
        );
        $this->assertSame(999, $out[$lead], 'dateless lands immediately after the lead row');
    }

    public function test_dateless_is_hoisted_onto_page_one_when_band_a_overflows(): void
    {
        $soon = date('Y-m-d', strtotime('+1 day'));
        $items = [];
        for ($i = 1; $i <= 30; $i++) { // 30 dated-soon (> PER_PAGE)
            $items[] = $this->ed($i, $soon);
        }
        $items[] = $this->ed(999, null); // one dateless
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $page1 = array_slice($out, 0, STRIDENCE_CATALOG_PER_PAGE);
        $this->assertContains(999, $page1, 'dateless must be on page 1');
    }

    public function test_degenerate_band_b_fills_page_one(): void
    {
        // count(B) >= PER_PAGE: page 1 is all dateless, B fully ahead.
        $soon = date('Y-m-d', strtotime('+1 day'));
        $items = [];
        for ($i = 1; $i <= STRIDENCE_CATALOG_PER_PAGE + 5; $i++) {
            $items[] = $this->ed(1000 + $i, null); // dateless
        }
        $items[] = $this->ed(1, $soon); // one dated-soon
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $page1 = array_slice($out, 0, STRIDENCE_CATALOG_PER_PAGE);
        // Every page-1 slot is a dateless item; the dated edition is not on page 1.
        $this->assertNotContains(1, $page1, 'with B >= page size, dated edition lands after B');
        $this->assertSame(1001, $out[0], 'first item is the first dateless item');
    }

    public function test_dateless_keeps_stable_enumeration_order(): void
    {
        $items = [$this->ed(7, null), $this->ed(3, null), $this->ed(9, null)];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([7, 3, 9], $out, 'Band B preserves enumeration order');
    }

    public function test_course_items_stay_in_band_a_not_dateless(): void
    {
        $items = [$this->course(50), $this->ed(2, null)];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([50, 2], $out, 'course before dateless');
    }

    public function test_start_without_end_is_dated_not_dateless(): void
    {
        $soon = date('Y-m-d', strtotime('+3 days'));
        $items = [$this->ed(2, null), $this->ed(1, $soon)];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([1, 2], $out, 'dated-soon first, dateless after');
    }

    // ── next-session ordering + stable tiebreak (refresh-shuffle fix) ──

    public function test_dated_band_sorts_by_sort_date_not_start_date(): void
    {
        // Edition 1 starts earliest but its first session passed; its next
        // upcoming session (sort_date) is later than edition 2's. Order must
        // follow sort_date, so edition 2 comes first.
        $items = [
            $this->ed(1, date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('+20 days')), 'Alpha'),
            $this->ed(2, date('Y-m-d', strtotime('+5 days')), date('Y-m-d', strtotime('+5 days')), 'Beta'),
        ];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([2, 1], $out, 'sort by next-session date, not start_date');
    }

    public function test_equal_sort_dates_break_tie_alphabetically_by_title(): void
    {
        $d = date('Y-m-d', strtotime('+7 days'));
        // Deliberately out of alphabetical input order.
        $items = [
            $this->ed(10, $d, $d, 'Charlie'),
            $this->ed(11, $d, $d, 'Alpha'),
            $this->ed(12, $d, $d, 'Bravo'),
        ];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([11, 12, 10], $out, 'same date → A→Z by title (Alpha, Bravo, Charlie)');
    }

    public function test_tiebreak_is_deterministic_regardless_of_input_order(): void
    {
        // The refresh-shuffle bug: same input, different enumeration order from
        // the DB must produce the SAME output. Two permutations → identical result.
        $d = date('Y-m-d', strtotime('+7 days'));
        $perm1 = [
            $this->ed(10, $d, $d, 'Charlie'),
            $this->ed(11, $d, $d, 'Alpha'),
            $this->ed(12, $d, $d, 'Bravo'),
        ];
        $perm2 = [
            $this->ed(12, $d, $d, 'Bravo'),
            $this->ed(10, $d, $d, 'Charlie'),
            $this->ed(11, $d, $d, 'Alpha'),
        ];
        $this->assertSame(
            $this->ids(stridence_catalog_order_into_bands($perm1)),
            $this->ids(stridence_catalog_order_into_bands($perm2)),
            'tied dates must order identically no matter the DB enumeration order',
        );
    }

    public function test_grace_band_also_sorts_by_sort_date_with_title_tiebreak(): void
    {
        // Two grace editions (next session already passed) sharing a sort_date
        // must still order deterministically by title.
        $past = date('Y-m-d', strtotime('-2 days'));
        $items = [
            $this->ed(20, $past, $past, 'Zulu'),
            $this->ed(21, $past, $past, 'Mike'),
        ];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([21, 20], $out, 'grace band: same date → A→Z by title');
    }
}
