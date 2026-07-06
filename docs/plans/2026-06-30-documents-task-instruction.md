# Documents-Task Instruction Field — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The implementer MUST re-read each cited file before editing — the file:line refs below are from a 2026-06-30 investigation and may have drifted.

**Goal:** Give the "Documenten uploaden" completion task (and its post-course twin) an admin-authored, per-edition / per-trajectory free-text instruction that replaces the generic "Upload de gevraagde documenten." line on the completion page, with a sensible pre-filled default and a graceful fallback to the generic string when cleared.

**Architecture:** Two new `textarea` meta fields (`documents_instruction`, `post_documents_instruction`) on both the `vad_edition` and `vad_trajectory` schemas. The admin textareas render in the shared `OfferingSidebarPartial`, shown only when their matching checkbox is checked (via the file's existing **jQuery toggle** pattern — NOT Alpine). The instruction is read through the per-domain repository in `EnrollmentCompletion` and threaded to the completion page through the **existing `getTaskSummary()` → `$task_summary` data channel** (no new plugin→theme call). The theme prefers the admin instruction, falls back to its existing generic description.

**Tech Stack:** PHP 8.3, NTDST Core (ntdst_data Data API, AbstractRepository), WordPress, Alpine.js (theme), jQuery (admin metabox), PHPUnit (unit).

## Global Constraints

- All user-facing UI text in **Dutch (nl_BE)**; code/identifiers in English.
- **Repositories-only** (project rule, memory `pattern_repositories_only`): CPT meta access goes through the per-domain repository (`repoFor($postType)->getField(...)`), never `ntdst_data()` directly or `get_post_meta()`. (Note: `OfferingSidebarPartial` is an admin render partial that already uses `ntdst_data()->get($modelKey)->getMeta()` for the existing checkboxes — match the file's established sibling pattern there for the admin-input read; the authoritative business read in `EnrollmentCompletion` uses the repository.)
- **mu-plugin must NEVER call theme helpers** (project rule, memory `gotcha_mu_plugin_no_theme_calls`). The instruction flows plugin→theme only through the existing `ntdst_response()->with(...)` payload that already feeds `completion.php` (`$task_summary`). The theme must NOT reach into a stride-core repository.
- **Schema-registered fields never return `getField()` defaults** (memory `gotcha_formatted_read_no_defaults`): an empty/unset instruction returns `''`, not the default. Therefore the default string is applied (a) as the admin textarea's pre-filled value when the stored value is empty, and (b) is NOT what the read accessor returns — the accessor returns the stored value or `''`, and the theme/accessor decides the fallback. See Task 4 for the exact fallback ladder.
- **No new AJAX/REST handler.** Both fields save through the existing `ntdst_fields[...]` CPT metabox save path, inheriting its nonce + capability check.

---

## Golden path: form-data-flow (admin-settings sub-shape) — deviations named below

- [x] Built to `ntdst-patterns/golden-paths/form-data-flow.md` (admin-save sub-shape) — read before task breakdown.
- [ ] Deviations from the slice (each named + justified):
  - **No new handler / no `ntdst/api_data` action** — the field rides the existing CPT metabox `ntdst_fields[...]` save (already nonce- + capability-guarded by the edition/trajectory edit screen). Justified: adding a handler would duplicate an existing, audited save path for one textarea.
  - **Sanitize is automatic, not hand-written** — declaring the schema field `type => 'textarea'` makes the Data API apply `sanitize_textarea_field` on save (per `ntdst-data` field-type table). Justified: the framework's typed sanitizer is the single source; a hand-rolled sanitize would drift.
  - **Read path is a Service accessor, not a Repository pass-through** — the new method in `EnrollmentCompletion` adds the default-fallback transformation, so it is NOT a banned pure pass-through (memory `lesson_pure_passthrough_is_drift`); it earns its layer.

## WP security requirements (per data-flow)

This feature adds exactly **one** new attack surface: admin-entered free text echoed on a **public** completion page (the enrolling user sees it). The four pillars (defined in `netdust-wp:wp-security` — referenced, not restated):

- [ ] **Admin save `ntdst_fields[documents_instruction]` / `[post_documents_instruction]`** (CPT metabox save, existing path):
  - **Validate:** n/a — free text, any string accepted (length is not constrained by product; WP/DB column limits suffice).
  - **Sanitize:** automatic via schema `type => 'textarea'` → `sanitize_textarea_field` on the Data API write. The implementer MUST verify the Data API applies the textarea sanitizer on this save path (Task 1 acceptance) and not silently store raw.
  - **Authorize:** inherited — the existing edition/trajectory edit-screen save already runs the metabox nonce + the post-type `edit_post` capability. The new field adds NO new save entry point. Confirm in the diff that no new save hook is introduced.
  - **Escape:** **on output** at `completion.php:170` — currently `esc_html($task_descriptions[$taskType] ?? '')`. The admin instruction flows through this SAME echo. Default to `esc_html()` (no line breaks). **If line breaks are desired**, use `nl2br(esc_html($x))` (escape THEN nl2br — never the reverse). Decision recorded in Task 5: ship plain `esc_html()` for v1 unless the default string needs a second line.
  - This is the **security acceptance flow** — see `## Acceptance flows` row F6 (an instruction containing `<script>`/HTML must render escaped, not execute).

## ntdst-core layering requirements

Only the rows that apply to what this feature builds:

- [ ] **Data access through a Repository** — the read accessor uses `repoFor($postType)->getField($postId, 'documents_instruction')`; no `ntdst_data()->get()` or `get_post_meta()` in the business read.
- [ ] **No pure pass-through Service method** — the new `EnrollmentCompletion` accessor adds the default-fallback transform, so it is a real layer (see golden-path deviation note).
- [ ] **No raw `wp_ajax_*` handler** — none added.
- [ ] **No `ob_start()+include` rendering** — none added; admin uses the file's existing inline-render partial style; theme uses `stridence_template_part`.
- [ ] **No swallowed `WP_Error`** — `getField()` returns scalar/`''`, not `WP_Error`, on this path; no error to swallow. If a repo lookup can fail, default to `''` and let the fallback ladder handle it.
- [ ] **No hardcoded meta prefix** — use the schema field key (`documents_instruction`); the Data API applies any prefix.
- [ ] **Correct module layering** — schema in `Modules/{Edition,Trajectory}/*CPT.php`; admin render in `Modules/Edition/Admin/OfferingSidebarPartial.php` (shared); business read in `Modules/Enrollment/EnrollmentCompletion.php`; presentation in `themes/stridence/forms/completion.php`.

> **Convergence contract:** These three blocks (golden-path slice + the four-pillar flow line + the layering rows) are the convergence target for `/code-review` and `netdust-wp:drift-reviewer` at shake-out. Reviewers verify the diff against the named items above, not free-form — a gap is a one-line finding keyed to a named item (or an unjustified deviation), not a re-discovery.

---

## Threat model (scoped — one new surface)

| Asset | Actor / attack | Mitigation | Status |
|---|---|---|---|
| The public completion page DOM | A user who can edit editions/trajectories stores `<script>`/HTML in the instruction → stored XSS against every enrolling user who views the page | Output escaped at `completion.php:170` via `esc_html()` (or `nl2br(esc_html())`); input sanitized via `sanitize_textarea_field` (schema `textarea` type) | Mitigated — Task 5 + Acceptance F6 |
| The instruction save path | CSRF / unauthorized write of the instruction | Rides the existing edition/trajectory metabox save (nonce + `edit_post` cap) — no new entry point | Mitigated — inherited; confirm no new hook in diff |
| **Out of scope / deferred:** | | | |
| Length / DoS via huge instruction | Not constrained — same exposure as every other existing free-text edition meta field; no new risk class introduced | — | Deferred (no product requirement) |

---

## Pre-existing inconsistency to FLAG (do NOT fix in this feature)

`CompletionTaskHandler::ALLOWED_MIME_TYPES` (`Handlers/CompletionTaskHandler.php:22-25`) accepts **`application/pdf`, `image/jpeg`, `image/png` only** — `.doc/.docx` (Word) are deliberately rejected. The frontend dropzone copy in `templates/forms/completion/task-documents.php` reportedly says "PDF, Word, afbeeldingen". This is a **pre-existing copy/behavior mismatch unrelated to this feature**. The default instruction string in this plan therefore promises only PDF/JPG/PNG. **Flag for the product owner; do NOT change the dropzone copy or the MIME list as part of this task** unless the owner approves a trivial copy fix separately.

---

## Default instruction string

```
Upload de gevraagde bewijsstukken (bv. diploma of attest). Toegestane formaten: PDF, JPG, PNG — max. 10 MB.
```

(Single line — keeps v1 output on plain `esc_html()`. Verify the "max. 10 MB" against the handler's actual size cap during Task 1; if the handler enforces a different limit, match it or drop the size clause rather than state a wrong number.) Same default used for both `documents_instruction` and `post_documents_instruction`.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php` | Edition schema | Add `documents_instruction`, `post_documents_instruction` (`textarea`) after the matching booleans (~L185, ~L205) |
| `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryCPT.php` | Trajectory schema | Add the same two `textarea` fields (~L174, ~L184) |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php` | Business read + default-fallback accessor; thread into `getTaskSummary()` | Add `DEFAULT_DOCUMENTS_INSTRUCTION` const + `documentsInstruction()` accessor + a `descriptions` (or `task_descriptions`) map in `getTaskSummary()` return |
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/OfferingSidebarPartial.php` | Admin textareas + checkbox-gated visibility | Render a textarea under the `requires_documents` and `post_requires_documents` rows; extend the existing jQuery to toggle it |
| `web/app/themes/stridence/forms/completion.php` | Frontend echo | Replace the hardcoded `documents` / `post_documents` description with the threaded admin instruction, falling back to the generic string |
| `web/app/themes/stridence/templates/forms/completion/task-documents.php` | (Optional) surface instruction above dropzone | Only if Task 5 review decides the card-body line is insufficient — keep minimal |

---

## Task 1: Register the two instruction fields on the Edition schema

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php` (after `requires_documents` ~L185 and `post_requires_documents` ~L205)
- Test: `tests/Unit/Modules/Edition/EditionCPTSchemaTest.php` (new or existing schema test)

**Interfaces:**
- Produces: schema keys `documents_instruction` and `post_documents_instruction`, both `type => 'textarea'`, read later via `repoFor('vad_edition')->getField($id, 'documents_instruction')` returning `string` (`''` when unset).

- [ ] **Step 1: Write the failing test** — assert the Edition schema declares both fields as `textarea`.

```php
public function test_edition_schema_declares_documents_instruction_textareas(): void
{
    $fields = \Stride\Modules\Edition\EditionCPT::getFields(); // match the real accessor name in the file
    $this->assertArrayHasKey('documents_instruction', $fields);
    $this->assertSame('textarea', $fields['documents_instruction']['type']);
    $this->assertArrayHasKey('post_documents_instruction', $fields);
    $this->assertSame('textarea', $fields['post_documents_instruction']['type']);
}
```

- [ ] **Step 2: Run test, verify it fails** — `ddev exec vendor/bin/phpunit --filter test_edition_schema_declares_documents_instruction_textareas` → FAIL (key missing). If `getFields()` is private/static-named differently, first read the file and adjust the accessor in the test.

- [ ] **Step 3: Implement** — add to `getFields()`:

```php
'documents_instruction' => [
    'type'        => 'textarea',
    'label'       => __('Instructie documenten', 'stride'),
    'description' => __('Toelichting die de deelnemer ziet bij de taak "Documenten uploaden". Leeg = standaardtekst.', 'stride'),
],
// ... and after post_requires_documents:
'post_documents_instruction' => [
    'type'        => 'textarea',
    'label'       => __('Instructie documenten na afloop', 'stride'),
    'description' => __('Toelichting bij de upload-taak na afloop. Leeg = standaardtekst.', 'stride'),
],
```

- [ ] **Step 4: Run test, verify it passes**, then the full unit suite: `ddev exec vendor/bin/phpunit --testsuite Unit`.

- [ ] **Step 5: Confirm the textarea sanitizer** — read the Data API field-type mapping (or write a tiny integration probe) to confirm `type => 'textarea'` routes saves through `sanitize_textarea_field`. Record the finding in the commit body. (This is the input-pillar evidence the security block requires.)

- [ ] **Step 6: Commit** — `git add … && git commit -m "feat(edition): register documents_instruction textarea fields"`

**Test tier: A** — schema is the contract the read accessor and admin UI depend on; a missing/misnamed key or wrong type silently breaks sanitize + read. RED-first asserts the contract.
**Acceptance:** drift pre-check clean on `EditionCPT.php`; the per-flow sanitize pillar (textarea→`sanitize_textarea_field`) is verified in Step 5.

---

## Task 2: Register the two instruction fields on the Trajectory schema

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryCPT.php` (after `requires_documents` ~L174 and `post_requires_documents` ~L184)
- Test: `tests/Unit/Modules/Trajectory/TrajectoryCPTSchemaTest.php`

**Interfaces:**
- Produces: identical schema keys on `vad_trajectory`, read via `repoFor('vad_trajectory')->getField(...)`.

- [ ] **Step 1: Write the failing test** (mirror Task 1 against the Trajectory schema accessor):

```php
public function test_trajectory_schema_declares_documents_instruction_textareas(): void
{
    $fields = \Stride\Modules\Trajectory\TrajectoryCPT::getFields();
    $this->assertSame('textarea', $fields['documents_instruction']['type'] ?? null);
    $this->assertSame('textarea', $fields['post_documents_instruction']['type'] ?? null);
}
```

- [ ] **Step 2: Run, verify FAIL.**
- [ ] **Step 3: Implement** — add the same two `textarea` field definitions (Dutch labels as Task 1) to the Trajectory `getFields()`.
- [ ] **Step 4: Run test + full unit suite, verify PASS.**
- [ ] **Step 5: Commit** — `git commit -m "feat(trajectory): register documents_instruction textarea fields"`

**Test tier: A** — same contract reasoning as Task 1, on the second host CPT.
**Acceptance:** drift pre-check clean on `TrajectoryCPT.php`.

---

## Task 3: Add the default-instruction accessor with fallback ladder to EnrollmentCompletion

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php` (add const + accessor near `repoFor()` ~L34 / the requirements block ~L60-77)
- Test: `tests/Unit/Modules/Enrollment/EnrollmentCompletionTest.php`

**Interfaces:**
- Consumes: `repoFor(string $postType): AbstractRepository` (existing, L34-39), `getField($postId, $metaKey)` (AbstractRepository).
- Produces:
  - `public const DEFAULT_DOCUMENTS_INSTRUCTION = '…';`
  - `public function documentsInstruction(int $postId, string $postType, bool $postCourse = false): string` — returns the stored instruction if non-empty, else `DEFAULT_DOCUMENTS_INSTRUCTION`. Reads `post_documents_instruction` when `$postCourse`, else `documents_instruction`.

**Fallback ladder (single source of truth — the theme MUST NOT re-implement it):**
`stored value (trimmed, non-empty)  →  else DEFAULT_DOCUMENTS_INSTRUCTION`.
(The generic theme string at `completion.php:47/50` becomes the LAST-resort fallback only if the accessor's value is somehow absent in the payload — but the accessor never returns empty, so in practice the admin value or the default always wins. This keeps the default in ONE place: the const.)

- [ ] **Step 1: Write the failing tests** (three behaviors: admin value wins, empty→default, post-course reads the post key). Mock the repository.

```php
public function test_returns_admin_instruction_when_set(): void
{
    $repo = $this->createMock(\Stride\Infrastructure\AbstractRepository::class);
    $repo->method('getField')->with(42, 'documents_instruction')->willReturn('Breng je diploma mee.');
    $svc = $this->makeCompletionWithEditionRepo($repo); // inject so repoFor('vad_edition') returns $repo
    $this->assertSame('Breng je diploma mee.', $svc->documentsInstruction(42, 'vad_edition'));
}

public function test_returns_default_when_instruction_empty(): void
{
    $repo = $this->createMock(\Stride\Infrastructure\AbstractRepository::class);
    $repo->method('getField')->willReturn('');   // schema fields never return defaults
    $svc = $this->makeCompletionWithEditionRepo($repo);
    $this->assertSame(
        \Stride\Modules\Enrollment\EnrollmentCompletion::DEFAULT_DOCUMENTS_INSTRUCTION,
        $svc->documentsInstruction(42, 'vad_edition')
    );
}

public function test_post_course_reads_post_documents_instruction_key(): void
{
    $repo = $this->createMock(\Stride\Infrastructure\AbstractRepository::class);
    $repo->expects($this->once())->method('getField')
         ->with(42, 'post_documents_instruction')->willReturn('Upload je attest.');
    $svc = $this->makeCompletionWithEditionRepo($repo);
    $this->assertSame('Upload je attest.', $svc->documentsInstruction(42, 'vad_edition', true));
}
```

- [ ] **Step 2: Run, verify all three FAIL** (method undefined).

- [ ] **Step 3: Implement** the const + accessor:

```php
public const DEFAULT_DOCUMENTS_INSTRUCTION =
    'Upload de gevraagde bewijsstukken (bv. diploma of attest). Toegestane formaten: PDF, JPG, PNG — max. 10 MB.';

public function documentsInstruction(int $postId, string $postType, bool $postCourse = false): string
{
    $key   = $postCourse ? 'post_documents_instruction' : 'documents_instruction';
    $value = trim((string) $this->repoFor($postType)->getField($postId, $key));

    return $value !== '' ? $value : self::DEFAULT_DOCUMENTS_INSTRUCTION;
}
```

- [ ] **Step 4: Run the three tests + full unit suite, verify PASS.**
- [ ] **Step 5: Commit** — `git commit -m "feat(enrollment): documentsInstruction accessor with default fallback"`

**Test tier: A** — branching logic (admin-vs-default fallback) + key selection (enrollment vs post-course) + the trim guard. This is exactly the logic the whole feature hinges on; RED-first on all three branches incl. the empty→default path.
**Acceptance:** drift pre-check clean; accessor is a real layer (transform), not a pass-through.

---

## Task 4: Thread the instruction into the completion-page payload via getTaskSummary()

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php` — `getTaskSummary()` (~L532-563)
- Test: `tests/Integration/EnrollmentCompletionSummaryTest.php` (integration — needs a real registration row resolving edition/trajectory)

**Interfaces:**
- Consumes: `documentsInstruction()` from Task 3; the registration's resolved post id + post type.
- Produces: `getTaskSummary()` return array gains a `descriptions` key:
  `['descriptions' => ['documents' => <instruction>, 'post_documents' => <post instruction>], …]`
  Only present for the document task types; other task types are NOT included here (the theme keeps its generic descriptions for those). This is the **single plugin→theme channel** — the theme reads `$task_summary['descriptions']`, never a repo.

**Resolution edge (named):** `getTaskSummary()` currently reads `edition_id` (L542). A registration may be a **trajectory** registration. The implementer MUST resolve the correct `(postId, postType)` pair the way the rest of the module does (trajectory registrations carry the trajectory id; confirm the registration column/shape — read `RegistrationRepository` and how other methods pick edition vs trajectory). Do NOT assume `edition_id` is always the offering. If the registration is a trajectory, pass `'vad_trajectory'`; else `'vad_edition'`.

- [ ] **Step 1: Write the failing integration test** — create an edition with a `documents_instruction`, a registration with a `documents` task, assert `getTaskSummary($regId)['descriptions']['documents']` equals the stored instruction; create a second edition WITHOUT the field, assert it equals `DEFAULT_DOCUMENTS_INSTRUCTION`.

```php
public function test_summary_includes_admin_documents_instruction(): void
{
    // GIVEN an edition with documents_instruction set + a registration with a documents task
    // WHEN  getTaskSummary($regId)
    // THEN  ['descriptions']['documents'] === the stored instruction
}
public function test_summary_falls_back_to_default_instruction(): void
{
    // GIVEN an edition with documents required but instruction empty
    // THEN  ['descriptions']['documents'] === EnrollmentCompletion::DEFAULT_DOCUMENTS_INSTRUCTION
}
```

- [ ] **Step 2: Run, verify FAIL** (`descriptions` key absent).

- [ ] **Step 3: Implement** — in `getTaskSummary()`, after resolving `(postId, postType)`, build the descriptions only for document tasks present in `$tasks`:

```php
$descriptions = [];
if (isset($tasks['documents'])) {
    $descriptions['documents'] = $this->documentsInstruction($postId, $postType, false);
}
if (isset($tasks['post_documents'])) {
    $descriptions['post_documents'] = $this->documentsInstruction($postId, $postType, true);
}
// add 'descriptions' => $descriptions to the returned array
```

- [ ] **Step 4: Run the integration tests + full suite, verify PASS.**
- [ ] **Step 5: Commit** — `git commit -m "feat(enrollment): expose documents instructions via getTaskSummary"`

**Test tier: A** — this is the seam that joins the accessor (Task 3) to the real registration→offering resolution, including the trajectory-vs-edition branch. Integration test crosses the **un-mocked** repo/DB seam (per testing-workflow seam rule): ≥1 real-chain assertion (real registration row) + ≥1 negative/edge (the empty→default + the trajectory path). Run ≥1 trajectory case too.
**Acceptance:** drift pre-check clean; the resolution edge (edition vs trajectory) is exercised, not assumed.

---

## Task 5: Render the admin textareas with checkbox-gated visibility (jQuery, shared partial)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/OfferingSidebarPartial.php` (the `requires_documents` row in the "Tijdens" foreach ~L87-95; the `post_requires_documents` row in the "Na afloop" foreach ~L108-116; the jQuery block ~L119-125)

**Interfaces:**
- Consumes: existing `$model->getMeta($post->ID, $key)` admin-read pattern already in the file; the field keys from Tasks 1-2.
- Produces: two `<textarea name="ntdst_fields[documents_instruction]">` / `[post_documents_instruction]` inputs, each pre-filled with the stored value OR the default when empty, each shown only when its sibling checkbox is checked.

**Design notes (ground-truthed):**
- This partial is **SHARED by edition AND trajectory** — rendering here covers both CPTs at once (the file comment L13-16 lists the required schema fields; Tasks 1-2 added them to both).
- The visibility toggle in this file is **jQuery** (existing `<script>` L119-125 toggling `#stride-approval-controls`), **NOT Alpine**. Match it: give each textarea a wrapper id, and on each documents checkbox `change`, toggle its wrapper. Initial state mirrors the checkbox's `checked`.
- **Default pre-fill** (per the "schema fields never return defaults" rule): `$val = $model->getMeta($post->ID, $key); if ($val === '' ) $val = EnrollmentCompletion::DEFAULT_DOCUMENTS_INSTRUCTION;` — reference the const from `EnrollmentCompletion` so the default lives in exactly one place. Output the value with `esc_textarea()`.
- Because the two document rows live inside generic `foreach` loops over `$duringRequirements` / `$postCourseRequirements`, render the textarea **conditionally inside the loop** when `$key === 'requires_documents'` / `'post_requires_documents'`, OR pull those two rows out of the loop — implementer's choice; keep it readable and keep the other requirement rows untouched.

- [ ] **Step 1: Implement** — after the `requires_documents` checkbox `<label>`, emit:

```php
<?php if ($key === 'requires_documents'):
    $docVal = (string) $model->getMeta($post->ID, 'documents_instruction');
    if ($docVal === '') { $docVal = \Stride\Modules\Enrollment\EnrollmentCompletion::DEFAULT_DOCUMENTS_INSTRUCTION; }
?>
    <div id="stride-documents-instruction" style="margin: 4px 0 8px 24px; <?= $checked ? '' : 'display:none;' ?>">
        <textarea name="ntdst_fields[documents_instruction]" rows="3"
                  style="width:100%; font-size:12px;"
                  placeholder="<?php esc_attr_e('Instructie voor de deelnemer…', 'stride'); ?>"><?= esc_textarea($docVal) ?></textarea>
        <p class="description" style="font-size:11px;"><?php esc_html_e('Leeg laten = standaardtekst tonen.', 'stride'); ?></p>
    </div>
<?php endif; ?>
```

Mirror the same block for `post_requires_documents` → `#stride-post-documents-instruction` reading `post_documents_instruction`.

- [ ] **Step 2: Extend the jQuery** (existing block ~L119-125) to toggle each wrapper off its checkbox:

```javascript
$('input[name="ntdst_fields[requires_documents]"][type=checkbox]')
    .on('change', function () { $('#stride-documents-instruction').toggle(this.checked); });
$('input[name="ntdst_fields[post_requires_documents]"][type=checkbox]')
    .on('change', function () { $('#stride-post-documents-instruction').toggle(this.checked); });
```

- [ ] **Step 3: Manual admin smoke** (no jsdom — this is presentational; verify in a real browser at Task-7 acceptance): load an edition edit screen, toggle the checkbox, confirm the textarea shows/hides and the default is pre-filled.

- [ ] **Step 4: Commit** — `git commit -m "feat(admin): checkbox-gated documents instruction textareas in offering sidebar"`

**Test tier: B** — `no unit test: Tier B, presentational admin markup + jQuery toggle; behavior verified via real-browser feature-acceptance (F1/F2/F4), not jsdom`. (The default-fallback LOGIC it surfaces is already Tier-A-tested in Task 3 via the shared const.)
**Acceptance:** drift pre-check clean on `OfferingSidebarPartial.php`; no new save hook introduced (authorize pillar inherited); output uses `esc_textarea()`.

---

## Task 6: Use the admin instruction on the completion page (frontend echo)

**Files:**
- Modify: `web/app/themes/stridence/forms/completion.php` (`$task_descriptions` ~L44-52; the echo ~L170)

**Interfaces:**
- Consumes: `$task_summary['descriptions']` from Task 4 (the ONLY plugin→theme channel).
- Produces: the rendered `documents` / `post_documents` description now prefers the admin instruction.

- [ ] **Step 1: Implement** — after `$task_descriptions` is defined (L52), overlay the threaded admin descriptions:

```php
$admin_descriptions = $task_summary['descriptions'] ?? [];
// admin instruction wins for document tasks; generic stays for everything else
$task_descriptions = array_merge($task_descriptions, array_filter($admin_descriptions, 'strlen'));
```

The echo at L170 stays `esc_html($task_descriptions[$taskType] ?? '')`. **Escape decision for v1: plain `esc_html()`** (default string is single-line). If the product later wants multi-line instructions, change L170 to `nl2br(esc_html($task_descriptions[$taskType] ?? ''))` — escape THEN nl2br — and note it; do not switch to `wp_kses_post` (would re-introduce HTML/XSS surface the default doesn't need).

- [ ] **Step 2: Manual frontend smoke** (real browser, Task 7): visit a completion page for an edition with a custom instruction → the custom text shows; clear it in admin → the default shows.

- [ ] **Step 3: Commit** — `git commit -m "feat(theme): show admin documents instruction on completion page"`

**Test tier: B** — `no unit test: Tier B, theme echo/array-merge glue; the merge precedence + fallback is Tier-A-covered in Tasks 3-4, and rendered output is verified through the browser in feature-acceptance F1/F3/F6`.
**Acceptance:** mu-plugin-no-theme-calls upheld — the theme reads only `$task_summary['descriptions']`, never a repo or a stride-core helper; escape pillar satisfied at L170.

---

## ── REVIEW GATE ── (tier: FULL — admin free text rendered on a PUBLIC page = stored-XSS surface; the threat-model + the four-pillar escape/sanitize line are the convergence targets)

**Cluster: Tasks 1–6 (the whole feature — 6 small tasks, one cohesive slice).** Per review-group sizing this is one cluster (6 tasks but a single narrow vertical: 2 schema, 1 accessor, 1 seam, 2 presentational). It touches a **1a threat-modeling surface** (untrusted-ish admin input echoed on a public page → stored XSS), so the provisional tier is **FULL**: all finders + `security-sentinel` + the feature-acceptance browser pass at spec-close. The controller may not downgrade below FULL while the public-echo surface is in the diff (tier escalation is one-way).

Hold this ONE cluster at the gate — not a flat phase. Reviewer checklist keyed to named items:
- Input sanitized via schema `textarea` → `sanitize_textarea_field` (Task 1 Step 5 evidence present).
- Output escaped at `completion.php:170` (`esc_html`, or `nl2br(esc_html())` if multi-line shipped).
- No new save hook / no new AJAX-REST route (authorize inherited).
- Repositories-only on the read; no `ntdst_data()`/`get_post_meta()` in the business path.
- mu-plugin-no-theme-calls: theme reads only `$task_summary['descriptions']`.
- Edition AND trajectory both covered (shared partial + both schemas).

---

## Acceptance flows

Driven through the **real browser** (admin edit screen + public completion page) at shake-out per `netdust-agent:feature-acceptance` — these are mostly Tier-B tasks, so this matrix is the load-bearing verification, not jsdom.

| # | Flow (intended use) | Steps | Expected | Edges (mandatory) |
|---|---|---|---|---|
| **F1** | Admin sets instruction → user sees it | Admin checks "Documenten uploaden", types a custom instruction, saves edition → user opens that edition's completion page, expands the documents task | The custom instruction text renders in the task card body (replacing the generic line) | **Empty/zero:** instruction left as the pre-filled default → user sees the default string (F3). **Boundary:** very long instruction renders without breaking layout. |
| **F2** | Admin checkbox unchecked → no textarea, no task | Admin unchecks "Documenten uploaden", saves | Admin: textarea hides (jQuery) and isn't saved/used; user: no documents task appears on completion page at all | **Wrong-order/re-entry:** check → type → uncheck → re-check: textarea reappears with prior value (or default); the stored value is not echoed when the task is absent. |
| **F3** | Admin clears instruction → user sees default fallback | Admin selects all textarea text, deletes it, saves → user opens completion page | User sees `DEFAULT_DOCUMENTS_INSTRUCTION`, NOT a blank line | **Empty:** stored `''` → accessor returns the const (Task 3 covers this at unit level; F3 proves it end-to-end). |
| **F4** | Post-course documents variant | Admin sets a `post_documents_instruction` on an edition with post-course documents required → user in post_course phase opens completion page | The post-course documents task shows the post-course instruction (independent of the enrollment-phase one) | **Wrong-order:** enrollment-phase instruction set but post-phase empty → post task shows default, not the enrollment text (proves key separation from Task 3 test 3). |
| **F5** | Trajectory parity | Repeat F1 on a **trajectory** (not an edition): shared partial renders the textarea; a trajectory registration's completion page shows the trajectory's instruction | Trajectory instruction renders; resolution picks `vad_trajectory` not `vad_edition` | **Boundary:** a trajectory registration whose offering id is NOT in `edition_id` — confirm Task 4's resolution edge handles it (the named edge). |
| **F6** | **Security:** HTML/script in instruction renders escaped | Admin enters `<script>alert(1)</script>` and `<b>x</b>` as the instruction, saves → user opens completion page | The text renders **literally and escaped** (no script executes, no bold) — confirm via DevTools that the DOM contains the escaped entities, not live tags | **Denied actor:** a user without `edit_post` on the offering cannot reach the save path (inherited nonce/cap — confirm the field has no independent entry point). |

A flow is `pass` only if driven through the real browser with both the visible result AND (for F1/F3/F4/F5) the persisted meta confirmed. F6 is `pass` only when DevTools shows escaped entities in the live DOM.

---

## Integration gate (phase = the whole feature)

- [ ] Full unit suite green (`ddev exec vendor/bin/phpunit --testsuite Unit`) — Tasks 1,2,3 RED-first tests included.
- [ ] Integration suite green (`ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist`) — Task 4 summary test, incl. ≥1 trajectory case.
- [ ] PHPStan clean on touched files (`ddev exec composer lint:stan`).
- [ ] `netdust-wp:drift-reviewer` clean on all five touched PHP files (the nine categories).
- [ ] Feature-acceptance F1–F6 driven in a real browser, manifest emitted (pass/fail/not-reachable). F6 is blocking.
- [ ] Smoke checklist handed to user: admin edit screen toggle + pre-fill; completion page custom vs default; post-course variant; trajectory variant; XSS-escape check.

---

## Self-review (run against this plan)

- **Spec coverage:** per-edition field ✓(T1) · per-trajectory field ✓(T2) · textarea visible only when checkbox checked ✓(T5) · pre-filled default ✓(T3 const + T5 pre-fill) · replaces generic line ✓(T4+T6) · clears→generic fallback ✓(T3+F3) · post-course twin ✓(T1/T2 keys, T3 `$postCourse`, T4, F4) · sanitize-on-input ✓(T1 textarea) · escape-on-output ✓(T6 L170) · capability inherited ✓(security block) · no new handler ✓ · default promises only PDF/JPG/PNG + Word inconsistency flagged ✓.
- **Placeholder scan:** all code steps carry real code; the only intentional "verify in file" notes are the accessor-name checks (`getFields()`) and the registration-resolution shape — both REQUIRE a read because the investigation didn't capture the exact private accessor name / registration column. These are flagged, not hand-waved.
- **Type consistency:** `documentsInstruction(int,string,bool):string`, const `DEFAULT_DOCUMENTS_INSTRUCTION`, summary key `descriptions['documents'|'post_documents']`, field keys `documents_instruction`/`post_documents_instruction` — used identically across T3/T4/T5/T6.
