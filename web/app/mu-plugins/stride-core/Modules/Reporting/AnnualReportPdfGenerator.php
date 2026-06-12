<?php

declare(strict_types=1);

namespace Stride\Modules\Reporting;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders an AnnualReport as a tables-only PDF using DOMPDF.
 *
 * v1: text/table output only — no logo, no remote assets, no charts.
 */
class AnnualReportPdfGenerator implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Annual Report PDF Generator',
            'description' => 'Renders the Jaarrapport as a tables-only PDF',
            'priority'    => 60,
        ];
    }

    /**
     * Generate the annual report PDF and return its binary contents.
     *
     * @return string Raw PDF bytes (starts with '%PDF-').
     */
    public function generate(AnnualReport $report): string
    {
        $html = $this->renderHtml($report);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function renderHtml(AnnualReport $report): string
    {
        $templatesDir = dirname(__DIR__, 2) . '/templates';

        return ntdst_response()
            ->addPath($templatesDir)
            ->withData(['report' => $report])
            ->html('pdf/annual-report');
    }
}
