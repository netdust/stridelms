<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stride\Admin\Mappers\EditionAdminMapper;

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
 *   2. The common keys carry the verbatim values both views produced before
 *      the dedup (incl. the stored-raw `status ?: 'open'` — C1 changes this
 *      single source NEXT cluster; here it stays raw).
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
            'editionId'       => 555,
            'courseId'        => 42,
            'courseTitle'     => 'Excel Basis',
            'capacity'        => 20,
            'registeredCount' => 7,
            'status'          => 'cancelled',
        ]));

        $this->assertSame(555, $item['id']);
        $this->assertSame(42, $item['course']['id']);
        $this->assertSame('Excel Basis', $item['course']['title']);
        $this->assertSame(20, $item['capacity']);
        $this->assertSame(7, $item['registeredCount']);
        // status passes through RAW (stored), NOT effective — C1 is next cluster.
        $this->assertSame('cancelled', $item['status']);
        $this->assertSame('http://example.test/wp-admin/post.php?post=555&action=edit', $item['editUrl']);
    }

    public function test_status_falls_back_to_open_when_empty_verbatim(): void
    {
        // Both views emit `$editionStatus ?: 'open'` — the mapper must reproduce
        // that exact fallback (this is the single source C1 will change).
        $item = EditionAdminMapper::toItem($this->context(['status' => '']));

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
