<?php
declare(strict_types=1);

namespace NtdstAssistant;

class ExportService
{
    private const EXPORT_DIR = 'stride-exports';
    private const URL_EXPIRY = 3600; // 1 hour
    private const MAX_ROWS = 5000;

    public function getMaxRows(): int
    {
        return self::MAX_ROWS;
    }

    /**
     * Generate a CSV file in the exports directory.
     *
     * @param string $prefix  Filename prefix (e.g., 'edities')
     * @param array  $headers Column headers
     * @param array  $rows    Array of row arrays
     * @return array{filepath: string, filename: string, row_count: int, truncated: bool}
     */
    public function generateCsv(string $prefix, array $headers, array $rows): array
    {
        $dir = $this->ensureExportDir();
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $filename = sprintf('%s_%s_%s.csv', $prefix, gmdate('Y-m-d'), uniqid());
        $filepath = $dir . '/' . $filename;

        $handle = fopen($filepath, 'w');
        // UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);

        return [
            'filepath' => $filepath,
            'filename' => $filename,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * Generate a signed download URL for a file.
     */
    public function getSignedUrl(string $filename, int $userId): string
    {
        $expires = time() + self::URL_EXPIRY;
        $token = $this->computeToken($filename, $userId, $expires);

        return rest_url('ntdst-assistant/v1/download') . '?' . http_build_query([
            'file' => $filename,
            'token' => $token,
            'expires' => $expires,
            'uid' => $userId,
        ]);
    }

    /**
     * Verify a signed download URL.
     */
    public function verifySignedUrl(string $file, string $token, int $expires, int $userId): bool
    {
        if (time() > $expires) {
            return false;
        }

        $expected = $this->computeToken($file, $userId, $expires);
        return hash_equals($expected, $token);
    }

    /**
     * Get the full filesystem path for an export file (with path traversal prevention).
     *
     * @return string|false  Full path or false if invalid
     */
    public function resolveFilePath(string $file): string|false
    {
        $dir = $this->getExportDir();
        $safe = $dir . '/' . basename($file);

        $real = realpath($safe);
        if ($real === false || !str_starts_with($real, realpath($dir))) {
            return false;
        }

        return $real;
    }

    /**
     * Delete export files older than 1 hour.
     */
    public function cleanup(): void
    {
        $dir = $this->getExportDir();
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - self::URL_EXPIRY;
        foreach (glob($dir . '/*.csv') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    private function computeToken(string $filename, int $userId, int $expires): string
    {
        $payload = json_encode([$filename, $userId, $expires]);
        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    private function getExportDir(): string
    {
        $uploadDir = wp_upload_dir();
        return $uploadDir['basedir'] . '/' . self::EXPORT_DIR;
    }

    private function ensureExportDir(): string
    {
        $dir = $this->getExportDir();

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);

            // Protect with .htaccess
            file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }

        return $dir;
    }
}
