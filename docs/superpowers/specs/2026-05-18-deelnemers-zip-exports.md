# Deelnemers ZIP exports

**Date:** 2026-05-18
**Scope:** Stride admin — export menu on `vad_edition` edit screen (Deelnemers metabox)
**Status:** Spec / brainstorm-approved
**Prior work:** Builds directly on the view-info pass (`2026-05-18-deelnemers-panel-view-info.md`) — same metabox surface, no controller reuse.

## Why

Today's export menu offers three exports: full Excel (`type=excel`), name cards (`type=namecards`), and attendance sheet (`type=attendance`). The Excel sheet "Taken" already lists questionnaire answers + uploaded *filenames*, but the file *contents* themselves cannot be exported — admins have to dig into Media Library row by row.

Admins also want a single archive they can hand off to colleagues / file in compliance storage, containing every exportable artefact for one edition.

## What we build

Two new export types in the existing dispatcher:

1. **`type=files`** — ZIP of every uploaded file across all confirmed/completed registrations, flat naming.
2. **`type=bundle`** — ZIP containing the three existing exports + the uploaded files.

## Filename conventions

### Uploaded files (in `type=files` and inside `type=bundle`'s `uploads/`)

Flat directory, one filename per file:

```
{lastname}-{firstname}-{task-key}-{original-basename}.{ext}
```

- `lastname` / `firstname`: sanitised via `sanitize_title()` to keep filename-safe ASCII; fallback to `user-{id}` when both empty.
- `task-key`: the `completion_tasks` array key (e.g. `vragenlijst` for `questionnaire`, `documenten` for `documents`, `post-documenten` for `post_documents`). Use a Dutch-label map so the zip is end-user-readable.
- `original-basename`: `basename(get_attached_file($id))`, decoded if necessary, sanitised.
- Collisions: if two files would produce the same name, append `-{N}` to subsequent ones.
- Anonymised users, orphan registrations, files missing from disk: silently skipped.

### Bundle ZIP

```
export-{edition-slug}-{YYYY-MM-DD}.zip
├── Volledig.xlsx
├── Naamkaartjes.docx
├── Presentielijst.docx
└── uploads/
    ├── Janssens-Marie-vragenlijst-attest.pdf
    └── Peeters-Jan-documenten-id.pdf
```

No nested top-level folder. The three exports keep simple filenames (`Volledig.xlsx`, etc.) inside the zip — the descriptive metadata lives in the outer zip filename.

### Files-only ZIP

```
uploads-{edition-slug}-{YYYY-MM-DD}.zip
├── Janssens-Marie-vragenlijst-attest.pdf
└── Peeters-Jan-documenten-id.pdf
```

Flat — no `uploads/` wrapper folder when there's nothing else in the zip.

## UI changes

In `EditionRegistrationMetabox::render()`, the export menu (`.stride-export-menu`) currently has three `<a>` entries. Append a divider and two new entries:

```
[Volledig Excel]
[Naamkaartjes (Word)]
[Presentielijst (Word)]
────────────────
[Uploads (ZIP)]
[Volledig pakket (ZIP)]
```

The divider is a `<div class="stride-export-divider">` (or `<hr>` — match existing style).

## Components

### New files

| Path | Responsibility |
|------|----------------|
| `Modules/Edition/Admin/EditionFilesZipExporter.php` | Stream a ZIP of uploaded files |
| `Modules/Edition/Admin/EditionBundleZipExporter.php` | Build a temp ZIP combining the 3 existing exports + uploads, stream to browser |

### Modified files

| Path | Change |
|------|--------|
| `Modules/Edition/Admin/EditionAdminController.php` | Add `case 'files'` and `case 'bundle'` to the `ajaxExportRegistrations` switch |
| `Modules/Edition/Admin/EditionRegistrationMetabox.php` | Add the two new menu items |
| `assets/css/admin/edition-admin.css` | Style for `.stride-export-divider` if needed |

No changes to: services, repositories, DB schema, the three existing exporters.

## Design

### Naming helper (shared)

A small private helper on each new exporter (or one shared trait — see "Decomposition" below):

```php
private function buildFileName(WP_User $user, string $taskKey, string $originalBasename): string
{
    $last  = sanitize_title($user->last_name  ?: '');
    $first = sanitize_title($user->first_name ?: '');
    $who   = trim($last . '-' . $first, '-') ?: ('user-' . $user->ID);
    $task  = $this->dutchTaskKey($taskKey);
    $base  = sanitize_file_name($originalBasename);

    return "{$who}-{$task}-{$base}";
}

private function dutchTaskKey(string $key): string
{
    return [
        'questionnaire'  => 'vragenlijst',
        'documents'      => 'documenten',
        'post_documents' => 'post-documenten',
    ][$key] ?? sanitize_title($key);
}
```

### Files exporter

```php
public function export(int $editionId): void
{
    $registrations = $this->getRegistrations($editionId);
    $users         = BatchQueryHelper::batchGetUsers(array_column($registrations, 'user_id'));
    $slug          = sanitize_title($this->editionTitle($editionId) ?: "editie-{$editionId}");
    $tmpPath       = $this->makeTempZipPath("uploads-{$slug}");

    $zip = new \ZipArchive();
    $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    $used = []; // collision tracking
    foreach ($registrations as $reg) {
        $user = $users[(int) $reg['user_id']] ?? null;
        if (!$user || (int) get_user_meta($user->ID, '_stride_anonymised_at', true) > 0) {
            continue;
        }
        $tasks = $this->decodeTasks($reg['completion_tasks'] ?? '');
        foreach (['questionnaire', 'documents', 'post_documents'] as $key) {
            $files = $tasks[$key]['data']['files'] ?? null;
            if (!is_array($files)) continue;
            foreach ($files as $fileId) {
                $path = get_attached_file((int) $fileId);
                if (!$path || !file_exists($path)) continue;
                $name = $this->buildFileName($user, $key, basename($path));
                $name = $this->resolveCollision($used, $name);
                $zip->addFile($path, $name);
                $used[$name] = true;
            }
        }
    }
    $zip->close();

    $this->streamToBrowser($tmpPath, "uploads-{$slug}-" . date('Y-m-d') . '.zip');
    @unlink($tmpPath);
}
```

`resolveCollision()` appends `-1`, `-2`, … to the basename portion (before extension) if a name is already in `$used`.

### Bundle exporter

The trickiest part: we need to *capture* the three existing exports' bytes without sending HTTP responses. Each existing exporter calls `$writer->openToBrowser($filename)` or equivalent — they assume they own the response.

Approach: refactor each existing exporter to support **two modes** without behaviour change for the existing callers:

- `export(int $editionId): void` — current behaviour, streams to browser. Stays the default for the existing menu items.
- `exportToFile(int $editionId, string $path): void` — write to a given absolute path; do NOT touch headers or output.

Implementation: extract the file-building logic into a private `build(int $editionId, $sink)` where `$sink` is either `$writer->openToBrowser($filename)` or `$writer->openToFile($path)`. (Spout's `Writer::openToFile()` exists; same for PhpWord's `Writer::save($path)`.)

Then:

```php
public function export(int $editionId): void
{
    $slug    = sanitize_title($this->editionTitle($editionId));
    $tmpDir  = $this->makeTempDir("bundle-{$editionId}");
    $excel   = "{$tmpDir}/Volledig.xlsx";
    $cards   = "{$tmpDir}/Naamkaartjes.docx";
    $attend  = "{$tmpDir}/Presentielijst.docx";

    $this->excelExporter->exportToFile($editionId, $excel);
    $this->namecardExporter->exportToFile($editionId, $cards);
    $this->attendanceExporter->exportToFile($editionId, $attend);

    $zipPath = "{$tmpDir}/bundle.zip";
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFile($excel,  'Volledig.xlsx');
    $zip->addFile($cards,  'Naamkaartjes.docx');
    $zip->addFile($attend, 'Presentielijst.docx');

    // Re-use the files-exporter's enumeration logic to add uploads/
    foreach ($this->filesExporter->enumerate($editionId) as $row) {
        $zip->addFile($row['path'], 'uploads/' . $row['name']);
    }
    $zip->close();

    $this->streamToBrowser($zipPath, "export-{$slug}-" . date('Y-m-d') . '.zip');
    $this->cleanupTempDir($tmpDir);
}
```

The `EditionFilesZipExporter` exposes a public generator-style `enumerate(int $editionId): iterable` returning `[path => ..., name => ...]` rows so the bundle exporter can reuse the naming/collision logic without duplicating it.

### Temp file storage

Use WordPress's `wp_get_upload_dir()['basedir'] . '/stride-export-tmp/'`:

- Directory created on first use, `.htaccess` with `Deny from all` and `index.php` empty file written once.
- Random subdirectory per export to avoid races between concurrent admin tabs.
- `cleanupTempDir()` removes the subdirectory after streaming.
- A separate cron-style cleanup is **not** in scope — bundle exports are short-lived; if an admin abandons mid-stream, the temp file stays until the next manual cleanup. We can add a sweep cron later if it becomes a problem.

### Streaming

`streamToBrowser(string $path, string $downloadName)` reads the file in 8KB chunks, sends `Content-Type: application/zip`, `Content-Disposition: attachment; filename="..."`, `Content-Length`. Match how the existing Excel exporter handles output-buffer cleanup.

### Errors

- ZIP creation fails (disk full, permissions): `wp_die('Kan export niet aanmaken: ' . $error, 500)`.
- No registrations or no uploaded files: still produce the zip — empty zip is preferable to a confusing error. Admin can see at a glance it was empty.
- Capability/nonce already enforced by the controller dispatcher before reaching the exporter.

## Decomposition

`EditionBundleZipExporter` constructor takes the three existing exporters + the new files exporter via DI:

```php
public function __construct(
    private readonly EditionRegistrationExporter $excelExporter,
    private readonly EditionNamecardExporter $namecardExporter,
    private readonly EditionAttendanceExporter $attendanceExporter,
    private readonly EditionFilesZipExporter $filesExporter,
) {}
```

This keeps the controller wiring honest — Bundle is *composed* of the others, not a re-implementation.

The shared naming helper (`buildFileName`, `dutchTaskKey`, `resolveCollision`) lives on `EditionFilesZipExporter` as private methods; the bundle exporter uses `filesExporter->enumerate()` and never names files itself.

## Tests

- **Unit (`tests/Unit/Edition/EditionFilesZipExporterTest.php`)**
  - Filename builder: lastname-first, sanitization, missing names → `user-{id}`, task-key mapping (`questionnaire` → `vragenlijst`).
  - Collision resolver: `name.pdf`, `name.pdf` → `name.pdf`, `name-1.pdf`.
  - Skip rules: anonymised user → omitted; missing attachment path → omitted; missing user → omitted.

- **Integration (`tests/Integration/Edition/EditionZipExportsIntegrationTest.php`)**
  - Seed an edition with 2 registrations, one with a fake uploaded file (write a small file to uploads dir, register attachment).
  - Run `EditionFilesZipExporter::export()` against a captured response (use `ob_start()` and pull the bytes), open the zip in-memory, assert: correct entries, correct names, correct content.
  - Run `EditionBundleZipExporter::export()`, assert: 3 exports + `uploads/...` entries present.

- **Acceptance** (optional, defer): a Cest that clicks the new menu items and confirms a zip download starts. Codeception's WPDriver can't easily inspect downloads, so this is low-value — skip unless needed.

## Out of scope

- Per-task filter (e.g. "only export questionnaire files"). One menu, one zip.
- Streaming directly (no temp file). PHP's ZipArchive doesn't stream well; tempfile is the simple right thing.
- Background-job export for huge editions. If we ever hit timeouts, add an async path.
- Cron cleanup of stale temp files (see above).
- Re-using bundled exporters for the partner API or the user-side download flow.

## References

- Current dispatcher: `Modules/Edition/Admin/EditionAdminController.php:816-862`
- Current menu UI: `Modules/Edition/Admin/EditionRegistrationMetabox.php:86-107`
- Current full Excel: `Modules/Edition/Admin/EditionRegistrationExporter.php`
- Existing `BatchQueryHelper::batchGetUsers` in `Infrastructure/`
- `completion_tasks` shape: keyed by task type, `data.files` is `array<int>` of attachment IDs
