<?php
// tests/Unit/AnnualReportSectionTest.php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Reporting\AnnualReportSection;

final class AnnualReportSectionTest extends TestCase
{
    public function test_creates_section_with_headers_and_rows(): void
    {
        $section = new AnnualReportSection(
            id: 'enrollments_by_course',
            title: 'Inschrijvingen per cursus',
            headers: ['Cursus', 'Huidig jaar', 'Vorig jaar'],
            rows: [
                ['Vorming A', 12, 8],
                ['Vorming B', 5, null],
            ],
        );

        $this->assertSame('enrollments_by_course', $section->id);
        $this->assertSame('Inschrijvingen per cursus', $section->title);
        $this->assertCount(2, $section->rows);
        $this->assertNull($section->rows[1][2]);
    }

    public function test_to_array_round_trips(): void
    {
        $section = new AnnualReportSection('a', 'A', ['h'], [['r']]);
        $arr = $section->toArray();
        $this->assertSame(['id' => 'a', 'title' => 'A', 'headers' => ['h'], 'rows' => [['r']]], $arr);
    }
}
