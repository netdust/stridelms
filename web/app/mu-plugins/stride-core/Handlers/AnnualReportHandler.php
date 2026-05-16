<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Reporting\AnnualReport;
use Stride\Modules\Reporting\AnnualReportPdfGenerator;
use Stride\Modules\Reporting\AnnualReportSection;
use Stride\Modules\Reporting\AnnualReportService;

/**
 * Handles PDF and CSV download requests for the Annual Report admin page.
 *
 * Thin handler — guards (nonce + capability), resolves params, delegates to
 * AnnualReportService + AnnualReportPdfGenerator, streams the response.
 *
 * CSV cells are passed through {@see self::sanitizeCsvCell()} to neutralise
 * formula-injection (leading =, +, -, @, tab, CR). See audit finding C1.
 */
final class AnnualReportHandler
{
    private const NONCE_ACTION = 'stride_annual_report';
    private const CAPABILITY = 'stride_view';

    public function __construct()
    {
        add_action('wp_ajax_stride_annual_report_pdf', [$this, 'downloadPdf']);
        add_action('wp_ajax_stride_annual_report_csv', [$this, 'downloadCsv']);
    }

    public function downloadPdf(): void
    {
        $this->guard();
        $year = $this->resolveYear();

        $service = ntdst_get(AnnualReportService::class);
        $gen = ntdst_get(AnnualReportPdfGenerator::class);
        $report = $service->buildReport($year);
        $bytes = $gen->generate($report);

        $filename = sprintf('jaarrapport-%d.pdf', $year);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public function downloadCsv(): void
    {
        $this->guard();
        $year = $this->resolveYear();
        $sectionId = isset($_GET['section']) ? sanitize_key((string) $_GET['section']) : 'all';

        $service = ntdst_get(AnnualReportService::class);
        $report = $service->buildReport($year);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        $filename = $sectionId === 'all'
            ? sprintf('jaarrapport-%d.csv', $year)
            : sprintf('jaarrapport-%d-%s.csv', $year, $sectionId);
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens it correctly
        fwrite($out, "\xEF\xBB\xBF");

        if ($sectionId === 'all') {
            $this->writeKpisCsv($out, $report);
            foreach ($report->sections as $section) {
                fputcsv($out, []);
                $this->writeSectionCsv($out, $section);
            }
        } else {
            $section = $this->findSection($report, $sectionId);
            if ($section === null) {
                fputcsv($out, [self::sanitizeCsvCell('Sectie niet gevonden: ' . $sectionId)]);
            } else {
                $this->writeSectionCsv($out, $section);
            }
        }

        fclose($out);
        exit;
    }

    private function guard(): void
    {
        $nonce = isset($_REQUEST['_wpnonce']) ? (string) $_REQUEST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(__('Ongeldige aanvraag.', 'stride'), 403);
        }
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Geen toegang.', 'stride'), 403);
        }
    }

    private function resolveYear(): int
    {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) current_time('Y');
        if ($year < 2000 || $year > 2100) {
            $year = (int) current_time('Y');
        }
        return $year;
    }

    /**
     * @param resource $out
     */
    private function writeKpisCsv($out, AnnualReport $report): void
    {
        fputcsv($out, [self::sanitizeCsvCell('Kerncijfers')]);
        fputcsv($out, [
            self::sanitizeCsvCell('Metriek'),
            self::sanitizeCsvCell((string) $report->year),
            self::sanitizeCsvCell((string) $report->previousYear),
        ]);
        foreach ($report->kpis as $key => $kpi) {
            fputcsv($out, [
                self::sanitizeCsvCell($key),
                self::sanitizeCsvCell($kpi['current'] ?? ''),
                self::sanitizeCsvCell($kpi['previous'] ?? ''),
            ]);
        }
    }

    /**
     * @param resource $out
     */
    private function writeSectionCsv($out, AnnualReportSection $section): void
    {
        fputcsv($out, [self::sanitizeCsvCell($section->title)]);
        fputcsv($out, array_map([self::class, 'sanitizeCsvCell'], $section->headers));
        foreach ($section->rows as $row) {
            fputcsv($out, array_map([self::class, 'sanitizeCsvCell'], $row));
        }
    }

    private function findSection(AnnualReport $report, string $id): ?AnnualReportSection
    {
        foreach ($report->sections as $s) {
            if ($s->id === $id) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Neutralise CSV formula-injection.
     *
     * Excel/LibreOffice/Numbers treat any cell that starts with =, +, -, @,
     * tab, or CR as a formula. User-controlled strings (org names, course
     * titles) could contain attacker-supplied content like "=cmd|'/c calc'!A1"
     * which would execute when an admin opens the export. Prefixing with a
     * single quote forces literal-string interpretation.
     *
     * Mirrors the mitigation required for AdminAPIController::exportRegistrations
     * (audit finding C1, 2026-05-14).
     */
    private static function sanitizeCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $str = (string) $value;
        if ($str !== '' && preg_match('/^[=+\-@\t\r]/', $str) === 1) {
            return "'" . $str;
        }
        return $str;
    }
}
