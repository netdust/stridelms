<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use ZipArchive;

/**
 * Composes the three existing exports (Excel, Naamkaartjes, Presentielijst)
 * with a flat uploads/ folder into a single ZIP. Calls each exporter's
 * buildToFile() to write into a temp dir, then bundles.
 */
final class EditionBundleZipExporter
{
    public function __construct(
        private readonly EditionRegistrationExporter $excelExporter,
        private readonly EditionNamecardExporter $namecardExporter,
        private readonly EditionAttendanceExporter $attendanceExporter,
        private readonly EditionFilesZipExporter $filesExporter,
    ) {}

    /**
     * Build then stream the bundle ZIP to the browser; cleans up temp dir after.
     */
    public function export(int $editionId): void
    {
        $zipPath = $this->buildToFile($editionId);
        $slug = $this->filesExporter->editionSlug($editionId);
        $downloadName = 'export-' . $slug . '-' . date('Y-m-d') . '.zip';

        // F-A5: abort-proof cleanup — a cancelled download terminates PHP
        // inside the streaming loop, skipping everything below; the shutdown
        // hook still runs and removes the full-PII temp dir (see the same
        // pattern in EditionFilesZipExporter::export()). cleanupTempDir is
        // idempotent, so the normal path below double-running it is harmless.
        register_shutdown_function(function () use ($zipPath): void {
            $this->cleanupTempDir(dirname($zipPath));
        });
        $this->filesExporter->streamZipToBrowser($zipPath, $downloadName);
        $this->cleanupTempDir(dirname($zipPath));
        // F-A5: terminal, like the other three exporters — see
        // EditionFilesZipExporter::export(). Cleanup runs BEFORE the exit.
        exit;
    }

    /**
     * Build the bundle ZIP to a temp path and return that path.
     * Used by tests and by export().
     */
    public function buildToFile(int $editionId): string
    {
        $tmpDir = $this->makeTempDir($editionId);

        $excel = $tmpDir . '/Volledig.xlsx';
        $cards = $tmpDir . '/Naamkaartjes.docx';
        $attend = $tmpDir . '/Presentielijst.docx';

        $this->excelExporter->buildToFile($editionId, $excel);
        $this->namecardExporter->buildToFile($editionId, $cards);
        $this->attendanceExporter->buildToFile($editionId, $attend);

        $zipPath = $tmpDir . '/bundle.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(esc_html__('Kan export niet aanmaken.', 'stride'), '', ['response' => 500]);
        }

        $zip->addFile($excel, 'Volledig.xlsx');
        $zip->addFile($cards, 'Naamkaartjes.docx');
        $zip->addFile($attend, 'Presentielijst.docx');

        foreach ($this->filesExporter->enumerate($editionId) as $row) {
            $zip->addFile($row['path'], 'uploads/' . $row['name']);
        }

        $zip->close();
        return $zipPath;
    }

    private function makeTempDir(int $editionId): string
    {
        $uploads = wp_get_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'stride-export-tmp';
        if (!is_dir($base)) {
            wp_mkdir_p($base);
            file_put_contents($base . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
            file_put_contents($base . '/index.php', "<?php // silence is golden\n");
        }
        $dir = $base . '/bundle-' . $editionId . '-' . wp_generate_password(8, false, false);
        wp_mkdir_p($dir);
        return $dir;
    }

    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
