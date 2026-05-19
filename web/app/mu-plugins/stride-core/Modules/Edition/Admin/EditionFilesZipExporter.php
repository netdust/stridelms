<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_User;
use ZipArchive;

/**
 * Streams a flat ZIP of every uploaded file across an edition's registrations.
 *
 * Filename scheme: {lastname}-{firstname}-{task-key}-{original-basename}
 * Anonymised users, orphan registrations, and missing-from-disk attachments
 * are silently skipped.
 *
 * Not declared `final` because the unit test suite subclasses this via
 * PHPUnit's getMockBuilder()->onlyMethods(['getRegistrations']) to stub
 * the DB read.
 */
class EditionFilesZipExporter
{
    public function __construct(
        private readonly EditionRepository $editionRepository,
        private readonly RegistrationRepository $registrations,
    ) {}

    /**
     * Stream a flat ZIP of all uploaded files for the edition to the browser.
     */
    public function export(int $editionId): void
    {
        $slug = $this->editionSlug($editionId);
        $zipPath = $this->makeTempZipPath('uploads-' . $slug);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(esc_html__('Kan export niet aanmaken.', 'stride'), '', ['response' => 500]);
        }

        foreach ($this->enumerate($editionId) as $row) {
            $zip->addFile($row['path'], $row['name']);
        }
        $zip->close();

        $downloadName = 'uploads-' . $slug . '-' . date('Y-m-d') . '.zip';
        $this->streamZipToBrowser($zipPath, $downloadName);
        @unlink($zipPath);
    }

    /**
     * Yield one row per uploadable file across the edition's registrations.
     *
     * @return \Generator<int, array{path: string, name: string}>
     */
    public function enumerate(int $editionId): \Generator
    {
        $regs = $this->getRegistrations($editionId);
        if (empty($regs)) {
            return;
        }

        $userIds = array_map(static fn($r) => (int) $r['user_id'], $regs);
        $users = BatchQueryHelper::batchGetUsers(array_unique($userIds));

        $used = [];
        foreach ($regs as $reg) {
            $userId = (int) $reg['user_id'];
            $user = $users[$userId] ?? null;
            if (!$user) {
                continue;
            }
            if ((int) get_user_meta($userId, '_stride_anonymised_at', true) > 0) {
                continue;
            }

            $tasks = $this->decodeTasks($reg['completion_tasks'] ?? '');
            foreach (['questionnaire', 'documents', 'post_documents'] as $taskKey) {
                $files = $tasks[$taskKey]['data']['files'] ?? null;
                if (!is_array($files)) {
                    continue;
                }
                foreach ($files as $fileId) {
                    $path = get_attached_file((int) $fileId);
                    if (!$path || !file_exists($path)) {
                        continue;
                    }
                    $name = $this->buildFileName($user, $taskKey, basename($path));
                    $name = $this->resolveCollision($used, $name);
                    $used[$name] = true;
                    yield ['path' => $path, 'name' => $name];
                }
            }
        }
    }

    public function buildFileName(WP_User $user, string $taskKey, string $originalBasename): string
    {
        $last = sanitize_title((string) ($user->last_name ?? ''));
        $first = sanitize_title((string) ($user->first_name ?? ''));
        $who = trim($last . '-' . $first, '-');
        if ($who === '') {
            $who = 'user-' . (int) $user->ID;
        }
        return "{$who}-{$this->dutchTaskKey($taskKey)}-" . sanitize_file_name($originalBasename);
    }

    public function dutchTaskKey(string $key): string
    {
        // Fallback explicitly converts _ to - because real WP sanitize_title
        // preserves underscores (the test stub does not). Without this, an
        // unmapped task key like "foo_bar" would slug to "foo_bar" in prod
        // and "foo-bar" in tests.
        return [
            'questionnaire'  => 'vragenlijst',
            'documents'      => 'documenten',
            'post_documents' => 'post-documenten',
        ][$key] ?? str_replace('_', '-', sanitize_title($key));
    }

    /**
     * @param array<string,bool> $used
     */
    public function resolveCollision(array $used, string $name): string
    {
        if (!isset($used[$name])) {
            return $name;
        }
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $ext = $dot === false ? '' : substr($name, $dot);
        $i = 1;
        while (isset($used[$stem . '-' . $i . $ext])) {
            $i++;
        }
        return $stem . '-' . $i . $ext;
    }

    /**
     * Public so the bundle exporter can produce the same slug.
     */
    public function editionSlug(int $editionId): string
    {
        $edition = $this->editionRepository->find($editionId);
        if ($edition instanceof \WP_Post) {
            $title = html_entity_decode($edition->post_title, ENT_QUOTES, 'UTF-8');
            $slug = sanitize_title($title);
            if ($slug !== '') {
                return $slug;
            }
        }
        return 'editie-' . $editionId;
    }

    /**
     * Stream a file to the browser as a ZIP attachment.
     * Public so the bundle exporter can reuse it.
     */
    public function streamZipToBrowser(string $path, string $downloadName): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getRegistrations(int $editionId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE edition_id = %d ORDER BY registered_at DESC",
            $editionId
        ), ARRAY_A);
        return $rows ?: [];
    }

    private function decodeTasks(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function makeTempZipPath(string $prefix): string
    {
        $uploads = wp_get_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'stride-export-tmp';

        if (!is_dir($base)) {
            wp_mkdir_p($base);
            file_put_contents($base . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
            file_put_contents($base . '/index.php', "<?php // silence is golden\n");
        }

        return $base . '/' . $prefix . '-' . wp_generate_password(8, false, false) . '-' . time() . '.zip';
    }
}
