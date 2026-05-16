<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Reporting\AnnualReportPdfGenerator;
use Stride\Modules\Reporting\AnnualReportService;

final class AnnualReportPdfGeneratorIntegrationTest extends IntegrationTestCase
{
    public function test_generate_returns_non_empty_pdf_binary(): void
    {
        $service = ntdst_get(AnnualReportService::class);
        $gen = ntdst_get(AnnualReportPdfGenerator::class);

        $report = $service->buildReport((int) current_time('Y'));
        $bytes = $gen->generate($report);

        $this->assertNotSame('', $bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }
}
