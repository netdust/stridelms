<?php
// web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportSection.php
declare(strict_types=1);

namespace Stride\Modules\Reporting;

final class AnnualReportSection
{
    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $headers,
        public readonly array $rows,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'headers' => $this->headers,
            'rows' => $this->rows,
        ];
    }
}
