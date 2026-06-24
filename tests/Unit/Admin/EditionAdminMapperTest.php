<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stride\Admin\Mappers\EditionAdminMapper;
use Stride\Domain\OfferingStatus;

/**
 * Characterization test for the shared edition->item shaping extracted from
 * AdminAPIController::getEditions (LIST view) and ::getEditionsAgendaView
 * (AGENDA view).
 *
 * The mapper produces the COMMON subset both views emit identically given the
 * same edition + course + reg-count inputs. The key risk this test pins: the
 * two views emit subtly different item shapes (agenda adds sessionId/date/times;
 * list adds startDate/endDate/course.tags), so a naive shared mapper that
 * flattens that difference must be caught. We therefore assert:
 *
 *   1. toItem() emits EXACTLY the common field set (no view-specific keys leak in).
 *   2. The common keys carry the verbatim values both views produce.
 *   3. INV-7 (C1): `status` is the EFFECTIVE status from the batched
 *      $context['effectiveStatuses'] map (editionId => OfferingStatus), NOT the
 *      raw stored meta. The raw stored status is only a defensive fallback when
 *      the id is absent from the map.
 */
class EditionAdminMapperTest extends TestCase
{
    /**
     * Build a representative $context the way the controller batches it.
     *
     * @return array{
     *   editionId:int, title:string, courseId:int, courseTitle:string,
     *   capacity:int, registeredCount:int, status:string
     * }
     */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'editionId'       => 555,
            'courseId'        => 42,
            'courseTitle'     => 'Excel Basis',
            'capacity'        => 20,
            'registeredCount' => 7,
            'status'          => 'open',
        ], $overrides);
    }

    public function test_toItem_emits_exactly_the_common_field_set(): void
    {
        $item = EditionAdminMapper::toItem($this->context());

        // The common subset both views share — and ONLY these keys. View-specific
        // keys (sessionId, date, startDate, course.tags, ...) must NOT appear here;
        // each view merges those itself.
        $this->assertSame(
            ['id', 'course', 'capacity', 'registeredCount', 'status', 'editUrl'],
            array_keys($item),
            'Mapper must emit only the common keys; view-specific keys leaking in would corrupt one grid.',
        );

        $this->assertSame(
            ['id', 'title'],
            array_keys($item['course']),
            'Common course shape is {id,title}; course.tags is LIST-view-specific and must stay out of the mapper.',
        );
    }

    public function test_toItem_carries_verbatim_common_values(): void
    {
        $item = EditionAdminMapper::toItem($this->context([
            'editionId'         => 555,
            'courseId'          => 42,
            'courseTitle'       => 'Excel Basis',
            'capacity'          => 20,
            'registeredCount'   => 7,
            'status'            => 'open',
            'effectiveStatuses' => [555 => OfferingStatus::Open],
        ]));

        $this->assertSame(555, $item['id']);
        $this->assertSame(42, $item['course']['id']);
        $this->assertSame('Excel Basis', $item['course']['title']);
        $this->assertSame(20, $item['capacity']);
        $this->assertSame(7, $item['registeredCount']);
        $this->assertSame('open', $item['status']);
        $this->assertSame('http://example.test/wp-admin/post.php?post=555&action=edit', $item['editUrl']);
    }

    public function test_status_is_effective_not_raw_stored(): void
    {
        // C1 (INV-7): the mapper emits the EFFECTIVE status from the batched map,
        // NOT the raw stored meta. Here stored is 'open' but effective is
        // Completed (e.g. past end_date derived in the controller) — the mapper
        // must emit 'completed', proving raw no longer wins.
        $item = EditionAdminMapper::toItem($this->context([
            'editionId'         => 555,
            'status'            => 'open',
            'effectiveStatuses' => [555 => OfferingStatus::Completed],
        ]));

        $this->assertSame('completed', $item['status']);
    }

    public function test_status_falls_back_to_stored_when_id_absent_from_map(): void
    {
        // Defensive degradation: if the effective-status map is missing this id
        // (should not happen — the controller batches every visible id), the
        // mapper falls back to the raw stored status (`?: 'open'`).
        $item = EditionAdminMapper::toItem($this->context([
            'editionId'         => 555,
            'status'            => 'cancelled',
            'effectiveStatuses' => [], // id 555 absent
        ]));

        $this->assertSame('cancelled', $item['status']);
    }

    public function test_status_falls_back_to_open_when_empty_and_id_absent(): void
    {
        // Raw fallback path with an empty stored status: `'' ?: 'open'` => 'open'.
        $item = EditionAdminMapper::toItem($this->context([
            'status'            => '',
            'effectiveStatuses' => [],
        ]));

        $this->assertSame('open', $item['status']);
    }

    public function test_no_course_yields_zero_id_empty_title(): void
    {
        // List + agenda both leave courseTitle '' and courseId 0 when no course
        // is linked — the mapper must preserve that, not null it out.
        $item = EditionAdminMapper::toItem($this->context([
            'courseId'    => 0,
            'courseTitle' => '',
        ]));

        $this->assertSame(0, $item['course']['id']);
        $this->assertSame('', $item['course']['title']);
    }
}
