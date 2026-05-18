# Deelnemers ZIP Exports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two new export types to the deelnemers metabox menu — a flat ZIP of uploaded files (`type=files`) and a bundle ZIP combining the three existing exports + the uploaded files (`type=bundle`).

**Architecture:** Two new exporter classes. `EditionFilesZipExporter` enumerates uploads with a flat `{lastname}-{firstname}-{task}-{original}.ext` naming scheme and ships them as a ZIP. `EditionBundleZipExporter` composes the three existing exporters via DI; each gets a `buildToFile(int, string): void` companion so the bundle can write them to disk before zipping. Temp files live under `wp-content/uploads/stride-export-tmp/<random>/`. Skip silently for anonymised users, orphans, and missing-from-disk attachments.

**Tech Stack:** PHP 8.2, WordPress + Bedrock, PHP `ZipArchive`, Box Spout (already in vendor), PhpOffice PhpWord (already in vendor), PHPUnit (Unit + Integration). No new Composer deps.

**Spec:** `docs/superpowers/specs/2026-05-18-deelnemers-zip-exports.md`

---

## File Structure

### New files

| Path | Responsibility |
|------|----------------|
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php` | Flat ZIP of uploaded files. Filename helper, enumeration generator, temp-file + browser-stream helpers, top-level `export()`. |
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php` | Composes 3 existing exports + uploads/ into one ZIP. Uses `buildToFile()` on each, then `EditionFilesZipExporter::enumerate()` for uploads. |
| `tests/Unit/Edition/EditionFilesZipExporterTest.php` | Filename helper, task-key map, collision resolver, enumeration skip rules. |
| `tests/Integration/Edition/EditionZipExportsIntegrationTest.php` | Seeded edition + real attachment; verifies both ZIPs end-to-end. |

### Modified files

| Path | Change |
|------|--------|
| `Modules/Edition/Admin/EditionRegistrationExporter.php` | Extract `buildToFile(int, string): void` next to `export()`. |
| `Modules/Edition/Admin/EditionNamecardExporter.php` | Same. |
| `Modules/Edition/Admin/EditionAttendanceExporter.php` | Same. |
| `Modules/Edition/Admin/EditionAdminController.php` | Two cases in `ajaxExportRegistrations` switch (~line 832). |
| `Modules/Edition/Admin/EditionRegistrationMetabox.php` | Divider + 2 menu entries (~lines 92–105). |
| `assets/css/admin/edition-admin.css` | `.stride-export-divider` style. |

No changes to: services, repositories, DB schema, container bindings, `plugin-config.php`.

### Refactor shape (Tasks 2 + 3)

Each existing exporter today:

```php
public function export(int $editionId): void {
    // 1. gather data + build writer
    // 2. while (ob_get_level()) ob_end_clean();
    // 3. header(...) ; openToBrowser($filename) OR ->save('php://output');
}
```

After:

```php
public function buildToFile(int $editionId, string $path): void {
    // 1. gather data + build writer
    // 3'. openToFile($path) / save($path)
}

public function export(int $editionId): void {
    // (could call buildToFile() into a tmp path, then stream — but to keep
    // the existing user-facing behaviour byte-identical we keep the streaming
    // path as-is. Each existing exporter keeps its current export() body and
    // gains a sibling buildToFile() that writes to a path.)
}
```

---

## Task 1: `EditionFilesZipExporter` — full implementation

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`
- Create: `tests/Unit/Edition/EditionFilesZipExporterTest.php`

This task builds the entire `EditionFilesZipExporter` class with all unit tests, in one TDD cycle: helper methods first, then enumeration with skip rules + collision handling, then the `export()` entry point.

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/Edition/EditionFilesZipExporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\Admin\EditionFilesZipExporter;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;
use WP_User;

class EditionFilesZipExporterTest extends TestCase
{
    private EditionFilesZipExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new EditionFilesZipExporter(
            $this->createMock(EditionService::class),
            $this->createMock(EditionRepository::class),
            $this->createMock(RegistrationRepository::class),
        );
    }

    public function testBuildFileNamePrefixesLastnameFirstname(): void
    {
        $user = $this->makeUser(['first_name' => 'Marie', 'last_name' => 'Janssens']);
        self::assertSame(
            'janssens-marie-vragenlijst-attest-leerkracht.pdf',
            $this->exporter->buildFileName($user, 'questionnaire', 'attest-leerkracht.pdf'),
        );
    }

    public function testBuildFileNameFallsBackToUserIdWhenNamesEmpty(): void
    {
        $user = $this->makeUser(['ID' => 42, 'first_name' => '', 'last_name' => '']);
        self::assertSame(
            'user-42-documenten-id.pdf',
            $this->exporter->buildFileName($user, 'documents', 'id.pdf'),
        );
    }

    public function testDutchTaskKeyMaps(): void
    {
        self::assertSame('vragenlijst', $this->exporter->dutchTaskKey('questionnaire'));
        self::assertSame('documenten', $this->exporter->dutchTaskKey('documents'));
        self::assertSame('post-documenten', $this->exporter->dutchTaskKey('post_documents'));
        self::assertSame('unknown-key', $this->exporter->dutchTaskKey('unknown_key'));
    }

    public function testResolveCollisionAppendsCounter(): void
    {
        $used = [];
        $a = $this->exporter->resolveCollision($used, 'janssens-marie-vragenlijst-attest.pdf');
        $used[$a] = true;
        $b = $this->exporter->resolveCollision($used, 'janssens-marie-vragenlijst-attest.pdf');
        $used[$b] = true;
        $c = $this->exporter->resolveCollision($used, 'janssens-marie-vragenlijst-attest.pdf');

        self::assertSame('janssens-marie-vragenlijst-attest.pdf', $a);
        self::assertSame('janssens-marie-vragenlijst-attest-1.pdf', $b);
        self::assertSame('janssens-marie-vragenlijst-attest-2.pdf', $c);
    }

    public function testResolveCollisionHandlesFilesWithNoExtension(): void
    {
        $used = ['janssens-marie-documenten-readme' => true];
        self::assertSame(
            'janssens-marie-documenten-readme-1',
            $this->exporter->resolveCollision($used, 'janssens-marie-documenten-readme'),
        );
    }

    public function testEnumerateYieldsFilesForOneRegistration(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[7] = $this->makeUser(['ID' => 7, 'first_name' => 'Marie', 'last_name' => 'Janssens']);
        $_test_attached_files[101] = '/tmp/stride-test-attest.pdf';
        file_put_contents('/tmp/stride-test-attest.pdf', 'pdf-bytes');

        $reg = [
            'id' => 1, 'user_id' => 7, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'data' => ['files' => [101]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);

        self::assertCount(1, $rows);
        self::assertSame('/tmp/stride-test-attest.pdf', $rows[0]['path']);
        self::assertSame('janssens-marie-vragenlijst-stride-test-attest.pdf', $rows[0]['name']);

        unset($_test_users[7], $_test_attached_files[101]);
        @unlink('/tmp/stride-test-attest.pdf');
    }

    public function testEnumerateSkipsAnonymisedUser(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[8] = $this->makeUser(['ID' => 8, 'first_name' => 'X', 'last_name' => 'Y']);
        $_test_attached_files[201] = '/tmp/stride-test-x.pdf';
        file_put_contents('/tmp/stride-test-x.pdf', 'x');
        update_user_meta(8, '_stride_anonymised_at', time());

        $reg = [
            'id' => 2, 'user_id' => 8, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [201]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);
        self::assertCount(0, $rows);

        delete_user_meta(8, '_stride_anonymised_at');
        unset($_test_users[8], $_test_attached_files[201]);
        @unlink('/tmp/stride-test-x.pdf');
    }

    public function testEnumerateSkipsMissingFileOnDisk(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[9] = $this->makeUser(['ID' => 9, 'first_name' => 'A', 'last_name' => 'B']);
        $_test_attached_files[301] = '/tmp/stride-does-not-exist.pdf';

        $reg = [
            'id' => 3, 'user_id' => 9, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [301]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);
        self::assertCount(0, $rows);

        unset($_test_users[9], $_test_attached_files[301]);
    }

    public function testEnumerateSkipsOrphanRegistration(): void
    {
        global $_test_attached_files;
        $_test_attached_files[401] = '/tmp/stride-orphan.pdf';
        file_put_contents('/tmp/stride-orphan.pdf', 'x');

        $reg = [
            'id' => 4, 'user_id' => 99999, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [401]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);
        self::assertCount(0, $rows);

        unset($_test_attached_files[401]);
        @unlink('/tmp/stride-orphan.pdf');
    }

    public function testEnumerateAppendsCounterOnCollision(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[10] = $this->makeUser(['ID' => 10, 'first_name' => 'Same', 'last_name' => 'Person']);
        // Two attachments pointing at the same basename — forces a collision.
        $_test_attached_files[501] = '/tmp/stride-a.pdf';
        $_test_attached_files[502] = '/tmp/stride-a.pdf';
        file_put_contents('/tmp/stride-a.pdf', 'a');

        $reg = [
            'id' => 5, 'user_id' => 10, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [501, 502]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);

        self::assertCount(2, $rows);
        self::assertSame('person-same-documenten-stride-a.pdf', $rows[0]['name']);
        self::assertSame('person-same-documenten-stride-a-1.pdf', $rows[1]['name']);

        unset($_test_users[10], $_test_attached_files[501], $_test_attached_files[502]);
        @unlink('/tmp/stride-a.pdf');
    }

    private function makeUser(array $fields): WP_User
    {
        $user = new WP_User();
        $user->ID = $fields['ID'] ?? 1;
        $user->first_name = $fields['first_name'] ?? '';
        $user->last_name = $fields['last_name'] ?? '';
        return $user;
    }

    private function makeExporter(array $regs): EditionFilesZipExporter
    {
        $exporter = $this->getMockBuilder(EditionFilesZipExporter::class)
            ->setConstructorArgs([
                $this->createMock(EditionService::class),
                $this->createMock(EditionRepository::class),
                $this->createMock(RegistrationRepository::class),
            ])
            ->onlyMethods(['getRegistrations'])
            ->getMock();
        $exporter->method('getRegistrations')->willReturn($regs);
        return $exporter;
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: FAIL with `Class … EditionFilesZipExporter not found`.

- [ ] **Step 3: Confirm `get_attached_file` stub supports `$_test_attached_files`**

Check `tests/Stubs/wordpress-stubs.php` — search for `function get_attached_file`. If it doesn't already read from `$_test_attached_files`, add the minimal version:

```php
if (!function_exists('get_attached_file')) {
    function get_attached_file($id) {
        global $_test_attached_files;
        return $_test_attached_files[$id] ?? false;
    }
}
```

(The view-info pass likely already added this — verify before adding.)

- [ ] **Step 4: Create the full exporter**

Create `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_User;
use ZipArchive;

/**
 * Streams a flat ZIP of every uploaded file across an edition's registrations.
 *
 * Filename scheme: {lastname}-{firstname}-{task-key}-{original-basename}
 * Anonymised users, orphan registrations, and missing-from-disk attachments
 * are silently skipped.
 */
class EditionFilesZipExporter
{
    public function __construct(
        private readonly EditionService $editionService,
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
        return [
            'questionnaire'  => 'vragenlijst',
            'documents'      => 'documenten',
            'post_documents' => 'post-documenten',
        ][$key] ?? sanitize_title($key);
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
        $edition = $this->editionService->getEdition($editionId);
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
```

Note on `class` not `final class` — PHPUnit's `getMockBuilder()->onlyMethods()` needs to subclass `getRegistrations`. Stride's `EditionDuplicatorTest` mocks a `final` class successfully, but it uses `->onlyMethods([])` (no override). Our test overrides one method, which requires removing `final`. Acceptable tradeoff.

- [ ] **Step 5: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: PASS (9 tests).

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php tests/Unit/Edition/EditionFilesZipExporterTest.php
git commit -m "feat(edition-export): EditionFilesZipExporter for uploads ZIP"
```

If you added the `get_attached_file` stub:

```bash
git add tests/Stubs/wordpress-stubs.php
git commit --amend --no-edit
```

---

## Task 2: Refactor 3 existing exporters to expose `buildToFile()`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionNamecardExporter.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceExporter.php`

Each exporter today bundles "build the writer" + "send to browser" in `export()`. We extract the build into `buildToFile(int, string): void`. The streaming `export()` body stays byte-identical for users.

### 2a — `EditionRegistrationExporter` (Spout / XLSX)

Read `EditionRegistrationExporter.php:51-108` first to see the current `export()` body.

- [ ] **Step 1: Add `buildToFile()` and refactor `export()` to share sheet logic**

Replace the current `export()` method with:

```php
public function export(int $editionId): void
{
    $data = $this->gatherData($editionId);
    $editionTitle = html_entity_decode($data['editionTitle'], ENT_QUOTES, 'UTF-8');
    $slug = sanitize_title($editionTitle ?: 'editie-' . $editionId);
    $filename = 'export-' . $slug . '-' . date('Y-m-d') . '.xlsx';

    while (ob_get_level()) {
        ob_end_clean();
    }

    $options = new Options();
    $options->DEFAULT_ROW_HEIGHT = 20;

    $writer = new Writer($options);
    $writer->openToBrowser($filename);
    $this->writeAllSheets($writer, $data);
    $writer->close();
}

/**
 * Build the XLSX to a path on disk, without sending HTTP headers.
 */
public function buildToFile(int $editionId, string $path): void
{
    $data = $this->gatherData($editionId);

    $options = new Options();
    $options->DEFAULT_ROW_HEIGHT = 20;

    $writer = new Writer($options);
    $writer->openToFile($path);
    $this->writeAllSheets($writer, $data);
    $writer->close();
}

/**
 * Write all 5 sheets — shared by export() and buildToFile().
 */
private function writeAllSheets(Writer $writer, array $data): void
{
    $sheet = $writer->getCurrentSheet();
    $sheet->setName('Overzicht');
    $this->writeOverviewSheet($writer, $data);

    $sheet = $writer->addNewSheetAndMakeItCurrent();
    $sheet->setName('Deelnemers');
    $this->writeRegistrationsSheet($writer, $sheet, $data);

    $sheet = $writer->addNewSheetAndMakeItCurrent();
    $sheet->setName('Facturatie');
    $this->writeInvoicingSheet($writer, $sheet, $data);

    $sheet = $writer->addNewSheetAndMakeItCurrent();
    $sheet->setName('Aanwezigheid');
    $this->writeAttendanceSheet($writer, $sheet, $data);

    $sheet = $writer->addNewSheetAndMakeItCurrent();
    $sheet->setName('Taken');
    $this->writeTasksSheet($writer, $sheet, $data);
}
```

If the actual current `export()` body has different sheet names or ordering, mirror what's there — the goal is "current behaviour byte-identical, plus a new `buildToFile()` that does the same thing without HTTP".

- [ ] **Step 2: Confirm Spout exposes `openToFile`**

Run: `ddev exec grep -rn "openToFile\|public function openTo" vendor/box/spout/src/ vendor/openspout/openspout/src/ 2>/dev/null | head -5`
Expected: at least one match. Both Box Spout and OpenSpout expose `openToFile`. If genuinely missing, fall back to: `ob_start(); $writer->openToBrowser('x'); $writer->close(); file_put_contents($path, ob_get_clean());`.

### 2b — `EditionNamecardExporter` (PhpWord)

- [ ] **Step 3: Extract writer construction**

Read the current `export()` (around line 28–140). The body builds `$writer` via a helper or inline, then ends with:

```php
header('Content-Type: ...');
header('Content-Disposition: attachment; filename="..."');
$writer->save('php://output');
```

Extract everything *before* the header calls into a private method `buildWriter(int $editionId): \PhpOffice\PhpWord\Writer\WriterInterface` (or whatever the project's PhpWord namespace alias is). Then:

```php
public function export(int $editionId): void
{
    $writer = $this->buildWriter($editionId);
    $slug = $this->slugForEdition($editionId);
    $filename = 'naamkaartjes-' . $slug . '-' . date('Y-m-d') . '.docx';

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer->save('php://output');
}

public function buildToFile(int $editionId, string $path): void
{
    $this->buildWriter($editionId)->save($path);
}
```

If `slugForEdition` doesn't already exist in the file, extract the slug logic inline rather than introducing a new helper.

### 2c — `EditionAttendanceExporter` (PhpWord)

- [ ] **Step 4: Same refactor pattern**

Identical shape to 2b. Extract `buildWriter(int): WriterInterface`, add `buildToFile(int, string): void`, leave `export()` to drive headers + `save('php://output')`.

### Validation

- [ ] **Step 5: Syntax check**

```bash
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionNamecardExporter.php
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceExporter.php
```
Expected: no syntax errors on all three.

- [ ] **Step 6: Regression — run unit suite**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: green (no test changes; this is pure refactor).

- [ ] **Step 7: Manual regression in admin**

Visit an edition with registrations. Trigger all three existing exports from the dropdown: Volledig Excel, Naamkaartjes (Word), Presentielijst (Word). Open each downloaded file — confirm content is identical to pre-refactor (same sheets / same names).

- [ ] **Step 8: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionNamecardExporter.php web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceExporter.php
git commit -m "refactor(edition-export): extract buildToFile() from 3 existing exporters"
```

---

## Task 3: `EditionBundleZipExporter`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php`
- Create: `tests/Integration/Edition/EditionZipExportsIntegrationTest.php`

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/Edition/EditionZipExportsIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAttendanceExporter;
use Stride\Modules\Edition\Admin\EditionBundleZipExporter;
use Stride\Modules\Edition\Admin\EditionFilesZipExporter;
use Stride\Modules\Edition\Admin\EditionNamecardExporter;
use Stride\Modules\Edition\Admin\EditionRegistrationExporter;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\Integration\IntegrationTestCase;
use ZipArchive;

class EditionZipExportsIntegrationTest extends IntegrationTestCase
{
    private int $userId = 0;
    private int $editionId = 0;
    private int $registrationId = 0;
    private int $attachmentId = 0;
    private string $attachmentPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = wp_create_user('zip_test_user', 'pw', 'zip_test@example.test');
        wp_update_user(['ID' => $this->userId, 'first_name' => 'Marie', 'last_name' => 'Janssens']);

        $this->editionId = $this->createTestEdition(['post_title' => 'Test ZIP Editie']);

        $uploads = wp_get_upload_dir();
        $this->attachmentPath = trailingslashit($uploads['path']) . 'stride-test-attest.pdf';
        file_put_contents($this->attachmentPath, 'pdf-bytes');
        $this->attachmentId = wp_insert_attachment([
            'post_mime_type' => 'application/pdf',
            'post_title' => 'stride-test-attest',
            'post_status' => 'inherit',
        ], $this->attachmentPath);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => $this->userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'data' => ['files' => [$this->attachmentId]]],
            ]),
            'registered_at' => current_time('mysql'),
        ]);
        $this->registrationId = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if ($this->registrationId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $this->registrationId]);
        }
        if ($this->attachmentId) {
            wp_delete_attachment($this->attachmentId, true);
        }
        if ($this->userId) {
            wp_delete_user($this->userId);
        }
        if ($this->attachmentPath && file_exists($this->attachmentPath)) {
            @unlink($this->attachmentPath);
        }
        parent::tearDown();
    }

    public function testFilesEnumerateReturnsSeededAttachment(): void
    {
        $exporter = new EditionFilesZipExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(RegistrationRepository::class),
        );

        $rows = iterator_to_array($exporter->enumerate($this->editionId), false);

        self::assertCount(1, $rows);
        self::assertStringContainsString('janssens-marie-vragenlijst-', $rows[0]['name']);
        self::assertSame($this->attachmentPath, $rows[0]['path']);
    }

    public function testBundleProducesZipWithAllArtefacts(): void
    {
        $filesExporter = new EditionFilesZipExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(RegistrationRepository::class),
        );

        $bundle = new EditionBundleZipExporter(
            new EditionRegistrationExporter(
                ntdst_get(EditionService::class),
                ntdst_get(EditionRepository::class),
                ntdst_get(SessionService::class),
                ntdst_get(AttendanceRepository::class),
            ),
            new EditionNamecardExporter(
                ntdst_get(EditionService::class),
                ntdst_get(EditionRepository::class),
            ),
            new EditionAttendanceExporter(
                ntdst_get(EditionService::class),
                ntdst_get(EditionRepository::class),
                ntdst_get(SessionService::class),
            ),
            $filesExporter,
        );

        $zipPath = $bundle->buildToFile($this->editionId);
        self::assertFileExists($zipPath);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        self::assertContains('Volledig.xlsx', $names);
        self::assertContains('Naamkaartjes.docx', $names);
        self::assertContains('Presentielijst.docx', $names);
        self::assertNotEmpty(array_filter($names, fn($n) => str_starts_with($n, 'uploads/')));

        @unlink($zipPath);
        $tmpDir = dirname($zipPath);
        if (is_dir($tmpDir)) {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }
}
```

If `createTestEdition` is unavailable, mirror how the prior `RegistrationModalIntegrationTest::setUp` seeded an edition (committed in the view-info pass).

- [ ] **Step 2: Run test to see it fail**

Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: FAIL — first test passes (built in Task 1), second fails because `EditionBundleZipExporter` does not exist.

- [ ] **Step 3: Create the bundle exporter**

Create `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php`:

```php
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

        $this->filesExporter->streamZipToBrowser($zipPath, $downloadName);
        $this->cleanupTempDir(dirname($zipPath));
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
```

- [ ] **Step 4: Run integration test**

Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php tests/Integration/Edition/EditionZipExportsIntegrationTest.php
git commit -m "feat(edition-export): EditionBundleZipExporter for combined ZIP"
```

---

## Task 4: Dispatcher cases — `type=files` and `type=bundle`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php`

- [ ] **Step 1: Add the two cases**

In `EditionAdminController::ajaxExportRegistrations` (around line 832), inside the `switch ($type)` block, insert these two cases between the existing `case 'attendance':` block and the `default:`:

```php
case 'files':
    $filesExporter = new EditionFilesZipExporter(
        $this->editionService,
        $this->editionRepository,
        ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
    );
    $filesExporter->export($editionId);
    break;

case 'bundle':
    $filesExporter = new EditionFilesZipExporter(
        $this->editionService,
        $this->editionRepository,
        ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
    );
    $bundleExporter = new EditionBundleZipExporter(
        new EditionRegistrationExporter(
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
            $this->attendanceRepository,
        ),
        new EditionNamecardExporter(
            $this->editionService,
            $this->editionRepository,
        ),
        new EditionAttendanceExporter(
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
        ),
        $filesExporter,
    );
    $bundleExporter->export($editionId);
    break;
```

- [ ] **Step 2: PHP syntax check**

Run: `ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php`
Expected: no errors.

- [ ] **Step 3: Run integration tests as a regression check**

Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: PASS (2 tests).

- [ ] **Step 4: Manual smoke test**

Visit (after admin login) — substitute a real edition id:

```
https://stride.ddev.site/wp/wp-admin/admin-ajax.php?action=stride_export_registrations&type=files&edition_id=<id>&nonce=<nonce-from-page>
https://stride.ddev.site/wp/wp-admin/admin-ajax.php?action=stride_export_registrations&type=bundle&edition_id=<id>&nonce=<nonce-from-page>
```

Both should download `.zip` files. Don't fight to automate; the integration test in Task 3 covers correctness.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php
git commit -m "feat(edition-export): wire type=files and type=bundle into dispatcher"
```

---

## Task 5: Menu UI + CSS

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php`
- Modify: `web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css`

- [ ] **Step 1: Add divider + two menu entries**

In `EditionRegistrationMetabox::render()`, find the `<div class="stride-export-menu">` block (around lines 92–105). Append, immediately before its closing `</div>`:

```php
<div class="stride-export-divider" role="separator" aria-hidden="true"></div>
<a href="<?php echo esc_url(admin_url('admin-ajax.php?action=stride_export_registrations&type=files&edition_id=' . $this->currentEditionId . '&nonce=' . $exportNonce)); ?>">
    <span class="dashicons dashicons-portfolio"></span>
    <?php esc_html_e('Uploads (ZIP)', 'stride'); ?>
</a>
<a href="<?php echo esc_url(admin_url('admin-ajax.php?action=stride_export_registrations&type=bundle&edition_id=' . $this->currentEditionId . '&nonce=' . $exportNonce)); ?>">
    <span class="dashicons dashicons-archive"></span>
    <?php esc_html_e('Volledig pakket (ZIP)', 'stride'); ?>
</a>
```

- [ ] **Step 2: Add divider CSS**

Append to `web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css`:

```css
/* Export menu divider — separates Excel/Word from ZIP exports */
.stride-export-menu .stride-export-divider {
    height: 1px;
    background: #dcdcde;
    margin: 4px 0;
}
```

- [ ] **Step 3: PHP syntax check**

Run: `ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php`
Expected: no errors.

- [ ] **Step 4: Manual visual check**

Hard-refresh an edition edit page (bust the asset cache). Click the "Exporteer" button — the menu should show 5 items with a thin grey line between the original 3 and the 2 new ones.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css
git commit -m "feat(edition-admin): menu entries + divider for ZIP exports"
```

---

## Task 6: Full-suite sweep

- [ ] **Step 1: Run all suites**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --filter "EditionZipExportsIntegrationTest|RegistrationModalIntegrationTest|EditionCascadeDeleteTest" --testsuite Integration
```

Expected: all green. (The wider Integration suite has 28 unrelated pre-existing LTI errors — ignore.)

- [ ] **Step 2: Manual end-to-end sweep**

Find an edition with at least one registration that has uploaded files. For each of the 5 menu items:

1. **Volledig Excel** — `.xlsx` opens. "Taken" sheet still lists answers + filenames. (Regression check on Task 2.)
2. **Naamkaartjes (Word)** — `.docx` opens.
3. **Presentielijst (Word)** — `.docx` opens.
4. **Uploads (ZIP)** — `.zip` opens, files named `{lastname}-{firstname}-{task}-{original}.ext` at the top level.
5. **Volledig pakket (ZIP)** — `.zip` opens, contains `Volledig.xlsx` + `Naamkaartjes.docx` + `Presentielijst.docx` + `uploads/`.

- [ ] **Step 3: Cleanup check**

After the 5 downloads:

```bash
ddev exec ls -la /var/www/html/web/app/uploads/stride-export-tmp/
```

Expected: only the `.htaccess` and `index.php` files (no leftover zip files or `bundle-*` dirs). If files linger, the `unlink` / `cleanupTempDir` paths have a bug — investigate before shipping.

- [ ] **Step 4: Commit only if anything changed during the sweep**

Skip this step unless follow-up fixes accumulated. Otherwise:

```bash
git commit -m "chore(edition-export): finalize ZIP exports"
```

---

## Out of scope (reminder)

- Per-task filter (e.g. "only export questionnaire files").
- Direct streaming without a temp file.
- Background-job export for huge editions.
- Cron cleanup of stale temp files.
- Anonymised-user manifest.
- Re-using bundled exporters for the partner API or user-side download flow.
