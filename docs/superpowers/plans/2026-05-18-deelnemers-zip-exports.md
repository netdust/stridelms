# Deelnemers ZIP Exports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two new export types to the deelnemers metabox menu — a flat ZIP of uploaded files (`type=files`) and a bundle ZIP combining the three existing exports + the uploaded files (`type=bundle`).

**Architecture:** Two new exporter classes (`EditionFilesZipExporter`, `EditionBundleZipExporter`) wired into the existing `ajaxExportRegistrations` dispatcher. The bundle exporter composes the three existing exporters via DI; each gets a non-breaking `buildToFile(int $editionId, string $path): void` companion to its existing `export(int $editionId): void` so the bundle can write them to disk before zipping. Temp files live under `wp-content/uploads/stride-export-tmp/<random>/`. Skip-silently for anonymised users, orphans, and missing-from-disk attachments.

**Tech Stack:** PHP 8.2, WordPress + Bedrock, PHP `ZipArchive`, Box Spout (already in vendor), PhpOffice PhpWord (already in vendor), PHPUnit (Unit + Integration). No new Composer deps.

**Spec:** `docs/superpowers/specs/2026-05-18-deelnemers-zip-exports.md`

---

## File Structure

### New files

| Path | Responsibility |
|------|----------------|
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php` | Stream a flat ZIP of uploaded files. Owns the filename helper (`buildFileName`, `dutchTaskKey`, `resolveCollision`) and a public `enumerate(int): iterable` consumed by the bundle. |
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php` | Compose 3 existing exports + uploads/ into one ZIP. Uses `buildToFile()` on each, then `EditionFilesZipExporter::enumerate()` for uploads. |
| `tests/Unit/Edition/EditionFilesZipExporterTest.php` | Unit tests for filename builder, task-key mapping, collision resolver, enumerator skip rules. |
| `tests/Integration/Edition/EditionZipExportsIntegrationTest.php` | Integration test seeding a real edition + attachments + verifying ZIP contents end-to-end. |

### Modified files

| Path | Change |
|------|--------|
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php` | Extract output dispatch from `export()`. Add `buildToFile(int, string)` mode. |
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionNamecardExporter.php` | Same refactor. |
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceExporter.php` | Same refactor. |
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php` | Add `case 'files'` and `case 'bundle'` to `ajaxExportRegistrations` switch (around line 832). |
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php` | Add 2 menu entries + divider after the existing 3 (around lines 86–107). |
| `web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css` | Style for `.stride-export-divider`. |

No changes to: services, repositories, DB schema, container bindings, `plugin-config.php`.

### Refactor pattern for the three existing exporters

Each currently looks like:

```php
public function export(int $editionId): void {
    // 1. gather data + build writer  (KEEP IN buildToFile)
    // 2. while (ob_get_level()) ob_end_clean();
    // 3. header(...) ; openToBrowser($filename) OR ->save('php://output');
}
```

Goal: extract step 1 into `buildToFile(int $editionId, string $path): void`, leave `export()` as a thin caller that opens a temp file, runs `buildToFile()`, then streams the temp file with the right headers + cleans up. This guarantees old callers see no behaviour change.

---

## Task 1: Filename helper for files exporter

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`
- Test: `tests/Unit/Edition/EditionFilesZipExporterTest.php`

- [ ] **Step 1: Write failing tests for the filename helper**

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

        $result = $this->exporter->buildFileName($user, 'questionnaire', 'attest-leerkracht.pdf');

        self::assertSame('janssens-marie-vragenlijst-attest-leerkracht.pdf', $result);
    }

    public function testBuildFileNameFallsBackToUserIdWhenNamesEmpty(): void
    {
        $user = $this->makeUser(['ID' => 42, 'first_name' => '', 'last_name' => '']);

        $result = $this->exporter->buildFileName($user, 'documents', 'id.pdf');

        self::assertSame('user-42-documenten-id.pdf', $result);
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

        $result = $this->exporter->resolveCollision($used, 'janssens-marie-documenten-readme');

        self::assertSame('janssens-marie-documenten-readme-1', $result);
    }

    private function makeUser(array $fields): WP_User
    {
        $user = new WP_User();
        $user->ID = $fields['ID'] ?? 1;
        $user->first_name = $fields['first_name'] ?? '';
        $user->last_name = $fields['last_name'] ?? '';
        return $user;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: FAIL with "Class … EditionFilesZipExporter not found"

- [ ] **Step 3: Create the exporter with helper methods**

Create `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

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
final class EditionFilesZipExporter
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
        private readonly RegistrationRepository $registrations,
    ) {}

    /**
     * Build the per-file zip entry name.
     */
    public function buildFileName(WP_User $user, string $taskKey, string $originalBasename): string
    {
        $last = sanitize_title((string) ($user->last_name ?? ''));
        $first = sanitize_title((string) ($user->first_name ?? ''));
        $who = trim($last . '-' . $first, '-');
        if ($who === '') {
            $who = 'user-' . (int) $user->ID;
        }
        $task = $this->dutchTaskKey($taskKey);
        $base = sanitize_file_name($originalBasename);

        return "{$who}-{$task}-{$base}";
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
     * If $name already exists in $used, append -1, -2, ... before the extension.
     *
     * @param array<string,bool> $used map of taken names
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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php tests/Unit/Edition/EditionFilesZipExporterTest.php
git commit -m "feat(edition-export): filename helper for ZIP exporter"
```

---

## Task 2: Enumerate uploads — happy path

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`
- Modify: `tests/Unit/Edition/EditionFilesZipExporterTest.php`

- [ ] **Step 1: Add the failing test**

Append to `EditionFilesZipExporterTest` class:

```php
public function testEnumerateYieldsFilesForOneRegistration(): void
{
    global $_test_users, $_test_attached_files;
    $_test_users[7] = $this->makeUser(['ID' => 7, 'first_name' => 'Marie', 'last_name' => 'Janssens']);
    $_test_attached_files[101] = '/tmp/stride-test-attest.pdf';
    file_put_contents('/tmp/stride-test-attest.pdf', 'pdf-bytes');

    $reg = (object) [
        'id' => 1,
        'user_id' => 7,
        'edition_id' => 99,
        'completion_tasks' => wp_json_encode([
            'questionnaire' => ['status' => 'completed', 'data' => ['files' => [101]]],
        ]),
    ];

    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('findByEdition')->with(99)->willReturn([$reg]);

    $exporter = new EditionFilesZipExporter(
        $this->createMock(EditionService::class),
        $this->createMock(EditionRepository::class),
        $registrations,
    );

    $rows = iterator_to_array($exporter->enumerate(99));

    self::assertCount(1, $rows);
    self::assertSame('/tmp/stride-test-attest.pdf', $rows[0]['path']);
    self::assertSame('janssens-marie-vragenlijst-stride-test-attest.pdf', $rows[0]['name']);

    unset($_test_users[7], $_test_attached_files[101]);
    @unlink('/tmp/stride-test-attest.pdf');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: FAIL — `enumerate()` not defined OR `findByEdition` not defined on `RegistrationRepository`.

If `findByEdition` does not exist on `RegistrationRepository`, look at `RegistrationRepository` for the available method. The most likely names are `getByEdition`, `findForEdition`, or just direct `$wpdb` access. **Read `RegistrationRepository` first.** If there is no per-edition method, use `$this->getRegistrations(int $editionId): array` as a private method on the exporter that queries `$wpdb` directly the way `EditionRegistrationMetabox::getAllRegistrations` does (which is `SELECT * FROM {$wpdb->prefix}vad_registrations WHERE edition_id = %d`). Mirror the metabox helper. Update the test mock accordingly.

- [ ] **Step 3: Wire up enumerate + registration loading**

Append inside `EditionFilesZipExporter` class:

```php
/**
 * Yields one row per uploadable file across the edition's registrations.
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
    $users = \Stride\Infrastructure\BatchQueryHelper::batchGetUsers(array_unique($userIds));

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

/**
 * @return array<int, array<string, mixed>>
 */
private function getRegistrations(int $editionId): array
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
```

The test mocks `findByEdition` on the repository. To keep the unit test isolated from `$wpdb` reads, change the implementation: factor `getRegistrations()` so it can be replaced by tests. Two options:

- (Preferred) Make `getRegistrations()` `protected`, and have the test use a subclass / `getMockBuilder()` to override it.
- (Alternative) Inject a `Closure` registration-fetcher.

For this plan, go with the preferred path. Update the test to use the mock-builder override:

```php
$exporter = $this->getMockBuilder(EditionFilesZipExporter::class)
    ->setConstructorArgs([
        $this->createMock(EditionService::class),
        $this->createMock(EditionRepository::class),
        $this->createMock(RegistrationRepository::class),
    ])
    ->onlyMethods(['getRegistrations'])
    ->getMock();
$exporter->method('getRegistrations')->with(99)->willReturn([(array) $reg]);
```

Remove the `findByEdition` mock and the `(object) $reg` casts — pass an associative array directly. The `enumerate()` body already reads via `$reg['user_id']` style.

Also: the unit test relies on `wp_json_encode`, `get_attached_file`, `get_user_meta`, `BatchQueryHelper::batchGetUsers`. The first three are stubbed (`tests/Stubs/wordpress-stubs.php` confirms this). `BatchQueryHelper::batchGetUsers` reads from `$_test_users` global per its stub usage in the view-info pass — should work the same here.

Update the test to:

```php
public function testEnumerateYieldsFilesForOneRegistration(): void
{
    global $_test_users, $_test_attached_files;
    $_test_users[7] = $this->makeUser(['ID' => 7, 'first_name' => 'Marie', 'last_name' => 'Janssens']);
    $_test_attached_files[101] = '/tmp/stride-test-attest.pdf';
    file_put_contents('/tmp/stride-test-attest.pdf', 'pdf-bytes');

    $reg = [
        'id' => 1,
        'user_id' => 7,
        'edition_id' => 99,
        'completion_tasks' => wp_json_encode([
            'questionnaire' => ['status' => 'completed', 'data' => ['files' => [101]]],
        ]),
    ];

    $exporter = $this->getMockBuilder(EditionFilesZipExporter::class)
        ->setConstructorArgs([
            $this->createMock(EditionService::class),
            $this->createMock(EditionRepository::class),
            $this->createMock(RegistrationRepository::class),
        ])
        ->onlyMethods(['getRegistrations'])
        ->getMock();
    $exporter->method('getRegistrations')->with(99)->willReturn([$reg]);

    $rows = iterator_to_array($exporter->enumerate(99));

    self::assertCount(1, $rows);
    self::assertSame('/tmp/stride-test-attest.pdf', $rows[0]['path']);
    self::assertSame('janssens-marie-vragenlijst-stride-test-attest.pdf', $rows[0]['name']);

    unset($_test_users[7], $_test_attached_files[101]);
    @unlink('/tmp/stride-test-attest.pdf');
}
```

Note: `iterator_to_array` on a generator that yields without explicit keys reuses 0 for every yield, so subsequent yields would overwrite. Use `iterator_to_array($exporter->enumerate(99), false)` (false = re-key sequentially).

Update the assertion calls to use `iterator_to_array(..., false)`:

```php
$rows = iterator_to_array($exporter->enumerate(99), false);
```

Then change `getRegistrations` visibility from `private` to `protected` in the exporter so the mock can override it. Update the `final class` modifier — if `final` blocks PHPUnit mock subclassing, drop it (the class isn't designed for extension; we lose only the `final` keyword, not the design intent).

Actually, the codebase pattern (`EditionDuplicatorTest` works on a `final` class) shows PHPUnit can mock `final` classes through reflection. Try it without dropping `final`. If `setMethods`/`onlyMethods` errors, fall back to removing `final`.

- [ ] **Step 4: Verify stubs support `_test_attached_files`**

Check `tests/Stubs/wordpress-stubs.php` for `get_attached_file`. If it returns `$_test_attached_files[$id] ?? false`, you're done. If not:

```php
function get_attached_file($id) {
    global $_test_attached_files;
    return $_test_attached_files[$id] ?? false;
}
```

Add this minimal stub if missing. (The Task 9 implementation of the view-info pass already needed it — it should be there.)

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: PASS (6 tests)

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php tests/Unit/Edition/EditionFilesZipExporterTest.php
git commit -m "feat(edition-export): enumerate uploaded files for ZIP"
```

If `tests/Stubs/wordpress-stubs.php` was touched, include it:

```bash
git add tests/Stubs/wordpress-stubs.php
```

---

## Task 3: Enumerate — skip rules

**Files:**
- Modify: `tests/Unit/Edition/EditionFilesZipExporterTest.php`

(Implementation already in place from Task 2 — this task pins the skip rules with regression tests.)

- [ ] **Step 1: Add failing tests**

Append to `EditionFilesZipExporterTest`:

```php
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

    $exporter = $this->makeExporterWithRegistrations([$reg]);
    $rows = iterator_to_array($exporter->enumerate(99), false);

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
    // Do NOT create the file on disk.

    $reg = [
        'id' => 3, 'user_id' => 9, 'edition_id' => 99,
        'completion_tasks' => wp_json_encode([
            'documents' => ['status' => 'completed', 'data' => ['files' => [301]]],
        ]),
    ];

    $exporter = $this->makeExporterWithRegistrations([$reg]);
    $rows = iterator_to_array($exporter->enumerate(99), false);

    self::assertCount(0, $rows);

    unset($_test_users[9], $_test_attached_files[301]);
}

public function testEnumerateSkipsOrphanRegistration(): void
{
    global $_test_users, $_test_attached_files;
    // No user 99999 in $_test_users.
    $_test_attached_files[401] = '/tmp/stride-orphan.pdf';
    file_put_contents('/tmp/stride-orphan.pdf', 'x');

    $reg = [
        'id' => 4, 'user_id' => 99999, 'edition_id' => 99,
        'completion_tasks' => wp_json_encode([
            'documents' => ['status' => 'completed', 'data' => ['files' => [401]]],
        ]),
    ];

    $exporter = $this->makeExporterWithRegistrations([$reg]);
    $rows = iterator_to_array($exporter->enumerate(99), false);

    self::assertCount(0, $rows);

    unset($_test_attached_files[401]);
    @unlink('/tmp/stride-orphan.pdf');
}

private function makeExporterWithRegistrations(array $regs): EditionFilesZipExporter
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
```

- [ ] **Step 2: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: PASS (9 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Edition/EditionFilesZipExporterTest.php
git commit -m "test(edition-export): lock skip rules for ZIP enumeration"
```

---

## Task 4: Filename collision handling

**Files:**
- Modify: `tests/Unit/Edition/EditionFilesZipExporterTest.php`

- [ ] **Step 1: Add failing test**

Append to `EditionFilesZipExporterTest`:

```php
public function testEnumerateAppendsCounterOnCollision(): void
{
    global $_test_users, $_test_attached_files;
    $_test_users[10] = $this->makeUser(['ID' => 10, 'first_name' => 'Same', 'last_name' => 'Person']);
    $_test_attached_files[501] = '/tmp/stride-a.pdf';
    $_test_attached_files[502] = '/tmp/stride-b.pdf';
    file_put_contents('/tmp/stride-a.pdf', 'a');
    file_put_contents('/tmp/stride-b.pdf', 'b');

    // Same task + same user × 2 different file IDs whose basenames collide
    // after sanitization. We force the collision by giving both the same path basename.
    $_test_attached_files[502] = '/tmp/stride-a.pdf'; // shares basename "stride-a.pdf" via path

    $reg = [
        'id' => 5, 'user_id' => 10, 'edition_id' => 99,
        'completion_tasks' => wp_json_encode([
            'documents' => ['status' => 'completed', 'data' => ['files' => [501, 502]]],
        ]),
    ];

    $exporter = $this->makeExporterWithRegistrations([$reg]);
    $rows = iterator_to_array($exporter->enumerate(99), false);

    self::assertCount(2, $rows);
    self::assertSame('person-same-documenten-stride-a.pdf', $rows[0]['name']);
    self::assertSame('person-same-documenten-stride-a-1.pdf', $rows[1]['name']);

    unset($_test_users[10], $_test_attached_files[501], $_test_attached_files[502]);
    @unlink('/tmp/stride-a.pdf');
    @unlink('/tmp/stride-b.pdf');
}
```

- [ ] **Step 2: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: PASS (10 tests) — the implementation from Task 2 already calls `resolveCollision` so this should pass without code changes.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Edition/EditionFilesZipExporterTest.php
git commit -m "test(edition-export): lock collision-counter behaviour"
```

---

## Task 5: Temp file helper for streaming

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`

(Pure infrastructure; tested indirectly via Task 6 integration test.)

- [ ] **Step 1: Add the helpers**

Append inside `EditionFilesZipExporter` class:

```php
/**
 * Build a unique tmp file path under wp-content/uploads/stride-export-tmp/.
 * Creates the parent dir on first use with a Deny-All .htaccess and an empty index.php.
 */
protected function makeTempZipPath(string $prefix): string
{
    $uploads = wp_get_upload_dir();
    $base = trailingslashit($uploads['basedir']) . 'stride-export-tmp';

    if (!is_dir($base)) {
        wp_mkdir_p($base);
        $htaccess = $base . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
        }
        $index = $base . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // silence is golden\n");
        }
    }

    $unique = $prefix . '-' . wp_generate_password(8, false, false) . '-' . time() . '.zip';
    return $base . '/' . $unique;
}

/**
 * Stream a file to the browser as an attachment, in 8KB chunks.
 */
protected function streamZipToBrowser(string $path, string $downloadName): void
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
```

- [ ] **Step 2: Syntax check**

Run: `ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Run tests as regression check**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Expected: PASS (10 tests, unchanged)

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php
git commit -m "feat(edition-export): temp-file + browser-stream helpers"
```

---

## Task 6: `EditionFilesZipExporter::export()` — top-level entry point

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php`
- Create: `tests/Integration/Edition/EditionZipExportsIntegrationTest.php`

- [ ] **Step 1: Add the `export()` method**

Append inside `EditionFilesZipExporter`:

```php
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

private function editionSlug(int $editionId): string
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
```

- [ ] **Step 2: Write the integration test**

Create `tests/Integration/Edition/EditionZipExportsIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use Stride\Modules\Edition\Admin\EditionFilesZipExporter;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
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

        // Seed user with first/last name
        $this->userId = wp_create_user('zip_test_user', 'pw', 'zip_test@example.test');
        wp_update_user([
            'ID' => $this->userId,
            'first_name' => 'Marie',
            'last_name' => 'Janssens',
        ]);

        // Seed edition
        $this->editionId = $this->createTestEdition([
            'post_title' => 'Test ZIP Editie',
        ]);

        // Seed attachment with a real file on disk
        $uploads = wp_get_upload_dir();
        $this->attachmentPath = trailingslashit($uploads['path']) . 'stride-test-attest.pdf';
        file_put_contents($this->attachmentPath, 'pdf-bytes');
        $this->attachmentId = wp_insert_attachment([
            'post_mime_type' => 'application/pdf',
            'post_title' => 'stride-test-attest',
            'post_status' => 'inherit',
        ], $this->attachmentPath);

        // Seed registration with the attachment
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => $this->userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => [
                    'status' => 'completed',
                    'data' => ['files' => [$this->attachmentId]],
                ],
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

    public function testEnumerateReturnsSeededAttachment(): void
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
}
```

- [ ] **Step 3: Run the integration test**

Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: PASS (1 test). If `createTestEdition` is unavailable, copy how `RegistrationModalIntegrationTest::setUp` (committed in the prior view-info pass) seeded an edition.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php tests/Integration/Edition/EditionZipExportsIntegrationTest.php
git commit -m "feat(edition-export): files ZIP exporter end-to-end"
```

---

## Task 7: Wire `type=files` into the dispatcher

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php`

- [ ] **Step 1: Add the case**

In `EditionAdminController::ajaxExportRegistrations` (around line 832), inside the `switch ($type)` block, immediately after the existing `case 'attendance':` block and BEFORE the `default:` block, add:

```php
case 'files':
    $exporter = new EditionFilesZipExporter(
        $this->editionService,
        $this->editionRepository,
        ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
    );
    $exporter->export($editionId);
    break;
```

- [ ] **Step 2: Smoke test via the admin URL**

Pick a real edition ID from `ddev exec wp eval "echo (int) \$wpdb->get_var('SELECT ID FROM stride_posts WHERE post_type=\"vad_edition\" AND post_status=\"publish\" LIMIT 1');"`.

In a browser, log in as admin, then visit:
`https://stride.ddev.site/wp/wp-admin/admin-ajax.php?action=stride_export_registrations&type=files&edition_id=<id>&nonce=<get-nonce-from-page>`

You should get a `.zip` download. Don't fight to automate this — the integration test in Task 6 verifies the data path; this manual probe confirms the dispatcher wiring.

- [ ] **Step 3: Run all unit + integration tests**

Run: `ddev exec vendor/bin/phpunit --filter EditionFilesZipExporterTest --testsuite Unit`
Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: both green.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php
git commit -m "feat(edition-export): wire type=files into ajax dispatcher"
```

---

## Task 8: Refactor `EditionRegistrationExporter` to expose `buildToFile()`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php`

The current `export()` mixes data-gathering, writer-building, and HTTP streaming. We split it so the bundle can write to a path.

- [ ] **Step 1: Read the current `export()` method**

Read `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php:51-108` to understand the current flow:

- Gather data (line ~54: `$data = $this->gatherData($editionId);`)
- Compute filename + slug (~57)
- Clean output buffers (~62)
- Create writer + `openToBrowser($filename)` (~70)
- Write all 5 sheets

- [ ] **Step 2: Add `buildToFile()` next to `export()`**

Add a new public method directly after `export()`:

```php
/**
 * Build the XLSX to a path on disk without sending HTTP headers.
 * Used by the bundle exporter.
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
 * Refactored common writer flow used by both export() and buildToFile().
 */
private function writeAllSheets(Writer $writer, array $data): void
{
    // Sheet 1
    $sheet = $writer->getCurrentSheet();
    $sheet->setName('Overzicht');
    $this->writeOverviewSheet($writer, $data);

    // Sheets 2–5: open new sheets in order, copying the current export() body
    // exactly. (See Step 3 for the exact code to lift.)
}
```

- [ ] **Step 3: Lift the sheet-writing logic from `export()` into `writeAllSheets()`**

In the current `export()`, every line between `$writer->openToBrowser($filename);` and end of method is sheet writing. Copy those lines verbatim into `writeAllSheets()` (replace `// Sheets 2-5: ...` placeholder with the actual code). The body currently in `export()` between those markers, in order, is something like:

```php
// Sheet 1: Overzicht (default sheet)
$sheet = $writer->getCurrentSheet();
$sheet->setName('Overzicht');
$this->writeOverviewSheet($writer, $data);

// Sheet 2: Deelnemers
$sheet = $writer->addNewSheetAndMakeItCurrent();
$sheet->setName('Deelnemers');
$this->writeRegistrationsSheet($writer, $sheet, $data);

// Sheet 3: Facturatie
$sheet = $writer->addNewSheetAndMakeItCurrent();
$sheet->setName('Facturatie');
$this->writeInvoicingSheet($writer, $sheet, $data);

// Sheet 4: Aanwezigheid
$sheet = $writer->addNewSheetAndMakeItCurrent();
$sheet->setName('Aanwezigheid');
$this->writeAttendanceSheet($writer, $sheet, $data);

// Sheet 5: Taken
$sheet = $writer->addNewSheetAndMakeItCurrent();
$sheet->setName('Taken');
$this->writeTasksSheet($writer, $sheet, $data);

$writer->close();
```

Make `writeAllSheets()`'s body exactly match what's currently between `openToBrowser` and the bottom of `export()`, EXCEPT the final `$writer->close()` — that stays inside `buildToFile()` already, and for `export()` we'll add `$writer->close()` after the call.

- [ ] **Step 4: Slim `export()` to use `writeAllSheets()`**

Replace the body of `export()` with:

```php
public function export(int $editionId): void
{
    $data = $this->gatherData($editionId);

    // Build filename — decode HTML entities in title
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
```

- [ ] **Step 5: Confirm Spout supports `openToFile`**

Run: `ddev exec grep -rn "openToFile" vendor/box/spout/src/ | head -3`
Expected: at least one match showing the method exists on the Writer class.

If the project uses `openspout/openspout` instead of `box/spout`, check that vendor path. Both expose `openToFile`. If your codebase uses a fork that lacks it, fall back to:

```php
$writer->openToFile($path); // confirm by check
```

If absent, use the alternative idiom: `$writer->openToFile($path);` is the canonical PhpSpout API — if your version uses `setShouldUseInlineStrings`/`->openToFile`, the call site stays the same. **If genuinely unavailable**, fall back to writing via output buffering: `ob_start(); $writer->openToBrowser('x'); $writer->close(); file_put_contents($path, ob_get_clean());` — clunky but workable.

- [ ] **Step 6: Regression-run the existing export**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: still green (857 tests).

Manually trigger the existing Excel export from the menu (admin → edit edition → export → Volledig Excel) to confirm the user-facing flow is unchanged.

- [ ] **Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php
git commit -m "refactor(edition-export): extract buildToFile() from Excel exporter"
```

---

## Task 9: Refactor `EditionNamecardExporter` and `EditionAttendanceExporter` similarly

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionNamecardExporter.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceExporter.php`

Both use PhpWord's `Writer::save($target)`. The current dispatch is `$writer->save('php://output');` after header output. The split is even cleaner here because PhpWord doesn't have a "browser" mode — same call accepts either `'php://output'` or a real path.

- [ ] **Step 1: Refactor `EditionNamecardExporter`**

In `EditionNamecardExporter::export()` (around line 28), find the section that builds `$writer` and ends with `$writer->save('php://output');`. Extract everything BEFORE the `header(...)` calls (data gathering + writer construction + `addSection` / `addText` calls — basically the whole body up to the comment "Output to browser" or equivalent) into a private method `buildWriter(int $editionId): PhpWord\Writer\WriterInterface` that returns the configured writer.

Then add:

```php
public function buildToFile(int $editionId, string $path): void
{
    $writer = $this->buildWriter($editionId);
    $writer->save($path);
}
```

And reshape `export()`:

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
```

Read the current file to find where `$slug` is computed today — extract it into a private `slugForEdition(int): string` helper if it's inline, or call the existing helper if there is one.

- [ ] **Step 2: Same refactor for `EditionAttendanceExporter`**

Same pattern. Extract everything that builds `$writer` into `buildWriter(int): WriterInterface`, add `buildToFile(int, string)`.

- [ ] **Step 3: PHP syntax check**

Run:
```
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionNamecardExporter.php
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceExporter.php
```
Expected: no syntax errors.

- [ ] **Step 4: Regression check**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: green.

Manually trigger Naamkaartjes + Presentielijst exports from the menu to confirm behaviour unchanged.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionNamecardExporter.php web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAttendanceExporter.php
git commit -m "refactor(edition-export): extract buildToFile() from Word exporters"
```

---

## Task 10: `EditionBundleZipExporter` skeleton + integration test

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php`
- Modify: `tests/Integration/Edition/EditionZipExportsIntegrationTest.php`

- [ ] **Step 1: Add the failing integration test**

Append inside `EditionZipExportsIntegrationTest`:

```php
public function testBundleExporterProducesZipWithAllArtefacts(): void
{
    $exporter = new \Stride\Modules\Edition\Admin\EditionBundleZipExporter(
        ntdst_get(\Stride\Modules\Edition\Admin\EditionRegistrationExporter::class)
            ?? new \Stride\Modules\Edition\Admin\EditionRegistrationExporter(
                ntdst_get(\Stride\Modules\Edition\EditionService::class),
                ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
                ntdst_get(\Stride\Modules\Edition\SessionService::class),
                ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class),
            ),
        new \Stride\Modules\Edition\Admin\EditionNamecardExporter(
            ntdst_get(\Stride\Modules\Edition\EditionService::class),
            ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
        ),
        new \Stride\Modules\Edition\Admin\EditionAttendanceExporter(
            ntdst_get(\Stride\Modules\Edition\EditionService::class),
            ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
            ntdst_get(\Stride\Modules\Edition\SessionService::class),
        ),
        new EditionFilesZipExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(RegistrationRepository::class),
        ),
    );

    $path = $exporter->buildToFile($this->editionId);

    self::assertFileExists($path);

    $zip = new ZipArchive();
    self::assertTrue($zip->open($path) === true);

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    self::assertContains('Volledig.xlsx', $names);
    self::assertContains('Naamkaartjes.docx', $names);
    self::assertContains('Presentielijst.docx', $names);
    self::assertNotEmpty(array_filter($names, fn($n) => str_starts_with($n, 'uploads/')));

    @unlink($path);
}
```

Note: this test calls `buildToFile(int $editionId): string` returning the path. We expose a build-to-file mode on the bundle for testability; the dispatcher uses `export()` which builds then streams + cleans up.

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: FAIL — `Class … EditionBundleZipExporter not found`.

- [ ] **Step 3: Create the bundle exporter**

Create `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use ZipArchive;

/**
 * Composes the three existing exports (Excel, Naamkaartjes, Presentielijst)
 * with a flat uploads/ folder into a single ZIP. The three exporters are
 * called in their non-HTTP buildToFile() mode and written to a temp dir,
 * then bundled.
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
     * Stream the bundle ZIP to the browser.
     */
    public function export(int $editionId): void
    {
        $zipPath = $this->buildToFile($editionId);
        $slug = $this->filesExporter->editionSlugPublic($editionId);
        $downloadName = 'export-' . $slug . '-' . date('Y-m-d') . '.zip';
        $this->filesExporter->streamZipToBrowserPublic($zipPath, $downloadName);
        $this->cleanupNear($zipPath);
    }

    /**
     * Build the bundle ZIP to a temp path and return that path. Used by tests
     * and by export().
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

    /**
     * Remove the tmp dir that contains the given zip path (the dir we created
     * in makeTempDir).
     */
    private function cleanupNear(string $zipPath): void
    {
        $dir = dirname($zipPath);
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

The `streamZipToBrowserPublic` and `editionSlugPublic` references mean we need public wrappers on `EditionFilesZipExporter` — they're already public-ish in design but currently `protected`/`private`. Adjust them.

- [ ] **Step 4: Expose the needed helpers on `EditionFilesZipExporter`**

In `EditionFilesZipExporter`, change visibility:
- `protected function makeTempZipPath` → unchanged (only its own `export` uses it).
- `protected function streamZipToBrowser` → keep the same body, add a public delegator:

```php
public function streamZipToBrowserPublic(string $path, string $downloadName): void
{
    $this->streamZipToBrowser($path, $downloadName);
}
```

- `private function editionSlug` → add a public delegator:

```php
public function editionSlugPublic(int $editionId): string
{
    return $this->editionSlug($editionId);
}
```

(We're not making the originals public so we keep the constructor pattern stable; the suffixed helpers signal "this is shared infra reused by the bundle".)

- [ ] **Step 5: Run the integration test**

Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionBundleZipExporter.php web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionFilesZipExporter.php tests/Integration/Edition/EditionZipExportsIntegrationTest.php
git commit -m "feat(edition-export): bundle ZIP exporter end-to-end"
```

---

## Task 11: Wire `type=bundle` into the dispatcher

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php`

- [ ] **Step 1: Add the case**

In `EditionAdminController::ajaxExportRegistrations`, immediately after the `case 'files':` block from Task 7, add:

```php
case 'bundle':
    $filesExporter = new EditionFilesZipExporter(
        $this->editionService,
        $this->editionRepository,
        ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
    );
    $excelExporter = new EditionRegistrationExporter(
        $this->editionService,
        $this->editionRepository,
        $this->sessionService,
        $this->attendanceRepository,
    );
    $namecardExporter = new EditionNamecardExporter(
        $this->editionService,
        $this->editionRepository,
    );
    $attendanceExporter = new EditionAttendanceExporter(
        $this->editionService,
        $this->editionRepository,
        $this->sessionService,
    );
    $bundleExporter = new EditionBundleZipExporter(
        $excelExporter,
        $namecardExporter,
        $attendanceExporter,
        $filesExporter,
    );
    $bundleExporter->export($editionId);
    break;
```

- [ ] **Step 2: Smoke test in admin**

Same as Task 7 step 2 but with `type=bundle`. Confirm a `.zip` downloads and contains `Volledig.xlsx`, `Naamkaartjes.docx`, `Presentielijst.docx`, and one or more files under `uploads/`.

- [ ] **Step 3: Run integration test as regression check**

Run: `ddev exec vendor/bin/phpunit --filter EditionZipExportsIntegrationTest --testsuite Integration`
Expected: PASS (2 tests).

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php
git commit -m "feat(edition-export): wire type=bundle into ajax dispatcher"
```

---

## Task 12: Menu UI

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php`

- [ ] **Step 1: Add the divider + two menu items**

In `EditionRegistrationMetabox::render()`, find the `<div class="stride-export-menu">` block (around lines 92–105). Currently it contains three `<a>` entries. Append, immediately before the closing `</div>`:

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

The dashicons (`portfolio` and `archive`) are arbitrary but read naturally; pick different ones if they don't render in your dashicons version.

- [ ] **Step 2: PHP syntax check**

Run: `ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php`
Expected: no errors.

- [ ] **Step 3: Manual visual check**

Open the deelnemers metabox in an edition. The export dropdown should show 5 items now, with a horizontal rule between the original 3 and the 2 new ones.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php
git commit -m "feat(edition-admin): add Uploads/Bundle entries to export menu"
```

---

## Task 13: CSS for divider

**Files:**
- Modify: `web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css`

- [ ] **Step 1: Append divider style**

Append at the end of `edition-admin.css`:

```css
/* Export menu divider — separates Excel/Word from ZIP exports */
.stride-export-menu .stride-export-divider {
    height: 1px;
    background: #dcdcde;
    margin: 4px 0;
}
```

The selector is scoped under `.stride-export-menu` to avoid clashing with `.stride-action-divider` introduced in the view-info pass.

- [ ] **Step 2: Manual visual check**

Reload the admin page (hard refresh to bust the asset cache). Verify the divider renders as a thin line between the existing items and the new ones.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css
git commit -m "style(edition-admin): divider for export menu"
```

---

## Task 14: Full-suite sanity check

- [ ] **Step 1: Run all suites**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --filter "EditionZipExportsIntegrationTest|RegistrationModalIntegrationTest|EditionCascadeDeleteTest" --testsuite Integration
```

Expected: all green. The wider integration suite has 28 unrelated pre-existing LTI errors — ignore those.

- [ ] **Step 2: Manual end-to-end sweep**

Pick an edition with at least one registration that has uploaded files (use `tests/_output/probe.php`-style WP-CLI eval if needed to find one). For each of the 5 menu items:

1. **Volledig Excel** — opens an `.xlsx`. The "Taken" sheet still shows answers + filenames. (Unchanged behaviour — regression check on the refactor.)
2. **Naamkaartjes (Word)** — opens a `.docx`.
3. **Presentielijst (Word)** — opens a `.docx`.
4. **Uploads (ZIP)** — opens a `.zip` with files named `{lastname}-{firstname}-{task}-{original}.ext` at the top level.
5. **Volledig pakket (ZIP)** — opens a `.zip` with `Volledig.xlsx` + `Naamkaartjes.docx` + `Presentielijst.docx` + an `uploads/` folder.

- [ ] **Step 3: Cleanup probe**

Verify `wp-content/uploads/stride-export-tmp/` is empty after each download (the exporters call `unlink` / `cleanupNear` after streaming). If files linger, that's a bug in the cleanup path — investigate before shipping.

- [ ] **Step 4: Final summary commit (only if any post-merge fixes accumulated)**

If individual commits already covered everything, skip. Otherwise:

```bash
git commit -m "chore(edition-export): finalize ZIP exports"
```

---

## Out of scope (reminder)

- Per-task filter (e.g. "only export questionnaire files").
- Streaming directly without temp file.
- Background-job export for huge editions.
- Cron cleanup of stale temp files.
- Anonymised-user manifest.
- Re-using bundled exporters for the partner API or user-side download flow.
