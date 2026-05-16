<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Reporting\AnnualReport;
use Stride\Modules\Reporting\AnnualReportSection;

final class AnnualReportTest extends TestCase
{
    public function test_holds_year_kpis_and_sections(): void
    {
        $report = new AnnualReport(
            year: 2026,
            previousYear: 2025,
            generatedAt: '2026-05-16 12:00:00',
            kpis: [
                'enrollments' => ['current' => 120, 'previous' => null],
                'completions' => ['current' => 80, 'previous' => null],
            ],
            sections: [
                new AnnualReportSection('s1', 'Sectie 1', ['col'], [['v']]),
            ],
        );

        $this->assertSame(2026, $report->year);
        $this->assertSame(2025, $report->previousYear);
        $this->assertSame(120, $report->kpis['enrollments']['current']);
        $this->assertNull($report->kpis['enrollments']['previous']);
        $this->assertCount(1, $report->sections);
    }

    public function test_kpi_change_percentage_returns_null_when_previous_missing(): void
    {
        $report = new AnnualReport(2026, 2025, '2026-05-16 12:00:00', [
            'enrollments' => ['current' => 120, 'previous' => null],
        ], []);

        $this->assertNull($report->kpiChangePercent('enrollments'));
    }

    public function test_kpi_change_percentage_calculates_when_both_present(): void
    {
        $report = new AnnualReport(2026, 2025, '2026-05-16 12:00:00', [
            'enrollments' => ['current' => 150, 'previous' => 100],
        ], []);

        $this->assertSame(50.0, $report->kpiChangePercent('enrollments'));
    }

    public function test_kpi_change_percentage_handles_zero_previous(): void
    {
        $report = new AnnualReport(2026, 2025, '2026-05-16 12:00:00', [
            'enrollments' => ['current' => 10, 'previous' => 0],
        ], []);

        $this->assertNull($report->kpiChangePercent('enrollments'));
    }
}
