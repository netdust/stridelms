<?php
declare(strict_types=1);

namespace Stride\Modules\Reporting;

final class AnnualReport
{
    /**
     * @param array<string, array{current: int|float|null, previous: int|float|null}> $kpis
     * @param list<AnnualReportSection> $sections
     */
    public function __construct(
        public readonly int $year,
        public readonly int $previousYear,
        public readonly string $generatedAt,
        public readonly array $kpis,
        public readonly array $sections,
    ) {
    }

    public function kpiChangePercent(string $key): ?float
    {
        $kpi = $this->kpis[$key] ?? null;
        if ($kpi === null) {
            return null;
        }
        $current = $kpi['current'];
        $previous = $kpi['previous'];
        if ($current === null || $previous === null || $previous == 0) {
            return null;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }
}
