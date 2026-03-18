# Excel Export "We found a problem with some content" — Investigation

**Status:** Unresolved
**File:** `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationExporter.php`
**Library:** OpenSpout v4.32.0

---

## Symptom

When opening the exported XLSX in Excel, it shows:

> "We found a problem with some content in [filename]. Do you want us to try to recover as much as we can?"

Clicking "Yes" recovers successfully — the data is intact.

---

## What was investigated and ruled out

### 1. Stray output before binary stream
WordPress/plugins can echo debug notices before the XLSX binary stream, corrupting the ZIP header.

**Fix applied:** `ob_end_clean()` before `openToBrowser()` — did NOT fix the warning.

**Verified:** Raw binary output starts with `PK` (valid ZIP signature). The file is a valid ZIP archive containing proper XLSX internal structure.

### 2. HTML entities in text content
`get_the_title()` returns `&#8211;` instead of `–`.

**Fix applied:** `html_entity_decode()` on edition and course titles — did NOT fix the warning.

### 3. Background color ARGB format (OpenSpout bug)
XLSX spec requires 8-character ARGB colors (`FF2271B1`). OpenSpout's `setFontColor()` calls `Color::toARGB()` internally during XML serialization, but `setBackgroundColor()` does NOT — it writes the raw value.

`Color::rgb(r, g, b)` returns 6-char hex (`2271B1`), so background fills were written as:
```xml
<fgColor rgb="2271B1"/>   <!-- BAD: 6 chars -->
```
instead of:
```xml
<fgColor rgb="FF2271B1"/> <!-- GOOD: 8 chars -->
```

**Fix applied:** Wrap all `setBackgroundColor()` calls with `Color::toARGB()`:
```php
->setBackgroundColor(Color::toARGB(Color::rgb(34, 113, 177)))
```

**Verified:** All 12 color values in the generated `xl/styles.xml` are now valid 8-char ARGB — did NOT fix the warning.

### 4. FluentForm class collision (separate issue, resolved)
FluentForm bundles OpenSpout v3 which has incompatible API. Deactivated FluentForm as workaround. Needs permanent fix eventually (see below).

---

## What to investigate next

### Empty comment/VML files
OpenSpout generates empty `comments*.xml` and `vmlDrawing*.vml` files for every sheet, even when no comments are used. These stub files have empty `<commentList>` elements and bare VML markup. Excel may flag these as problems.

**Test:** Manually remove the comment/VML files from the ZIP and re-test, or check if OpenSpout has a config to disable comment generation.

### DEFAULT_ROW_HEIGHT on Options
```php
$options->DEFAULT_ROW_HEIGHT = 20;
```
This sets a public property directly. Verify this is the correct API — may need a setter or different property name in v4.

### Sheet name length / characters
Sheet name "Taken & Vragenlijst" contains `&`. While OpenSpout should escape this, verify in `xl/workbook.xml`.

### Minimal reproduction
Generate progressively:
1. Single sheet, single row, no styles → test
2. Add styles → test
3. Add second sheet → test
4. Add column widths → test
5. Add mixed cell/row styles → test

Find the exact pattern that triggers the warning.

### Alternative: write to temp file instead of php://output
The `openToBrowser()` method sends headers + streams to `php://output`. WordPress admin-ajax.php may add content after `exit`. Try writing to a temp file first, then `readfile()`:

```php
$tmpFile = tempnam(sys_get_temp_dir(), 'stride_export_');
$writer->openToFile($tmpFile);
// ... write sheets ...
$writer->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
unlink($tmpFile);
exit;
```

This eliminates any chance of stray bytes from `openToBrowser()` header handling.

---

## FluentForm conflict (future)

FluentForm bundles OpenSpout v3 at `web/app/plugins/fluentform/vendor/openspout/openspout/`. When both are active, PHP autoloads v3 classes instead of our Composer v4.

Options:
1. **Scoped package** — use `humbug/php-scoper` to prefix our OpenSpout classes
2. **Feature flag** — only load our export when FluentForm is inactive
3. **Write to temp + shell out** — generate XLSX in a subprocess that loads only our autoloader
4. **Wait for FluentForm update** — they may upgrade to v4 eventually
