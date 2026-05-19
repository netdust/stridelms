# Questionnaire Builder Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the questionnaire admin builder UI with a canvas + right inspector layout aligned to `admin-dashboard.css` tokens. Data layer untouched.

**Architecture:** Single admin page rendered server-side, hydrated by one Alpine.js component that owns all state. Three regions: sticky toolbar (top), group pill tabs (top), canvas + 320px inspector (two-column body). Drag-drop via the already-loaded jQuery UI Sortable. Hard-save on submit (no AJAX). Public-side rendering, validator, repository, service classes all unchanged.

**Tech Stack:** PHP 8.3, WordPress mu-plugin (`stride-core`), Alpine.js 3.x (already in admin-dashboard.js), jQuery UI Sortable (already enqueued), CSS variables from `admin-dashboard.css` (`--sd-*` tokens).

**Spec:** `docs/superpowers/specs/2026-05-19-questionnaire-builder-redesign-design.md`

---

## File Structure

### New files

| File | Responsibility |
|---|---|
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/builder.php` | Top-level wrapper. Outputs Alpine root + container. Includes all partials. |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/_toolbar.php` | Page title, group count badge, Annuleren + Opslaan buttons. |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/_group-tabs.php` | Pill-tab switcher, "+ Nieuwe groep" inline, current stage label. |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/_group-header.php` | Selected group: inline-editable label, scope hint, "..." menu. |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/_field-list.php` | Drag-sortable container wrapping field rows + add-field button. |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/_field-row.php` | Single field row: grab handle, label, meta hint. Selection state. |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/_inspector.php` | Field property editor: type, vraag, hulptekst, vereist, options list, dup/delete. |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/_empty-state.php` | "Voeg je eerste veld toe" centered when group has no fields. |
| `web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder-v2.css` | All styles. Uses `--sd-*` tokens only. |
| `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder-v2.js` | Single Alpine.data component `questionnaireBuilder()`. Includes jQuery UI Sortable wiring. |
| `tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php` | New unit tests guarding the POST payload shape + state-JSON shape. |

### Modified files

| File | Change |
|---|---|
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php` | `renderPage()` now `include`s `templates/builder.php` instead of inline HTML (drops lines 167–500). `enqueueAssets()` swaps CSS/JS handles to v2. New `getStateJson()` method serializes current groups for Alpine hydration. `getFieldTypes()` returns plain `[key => label]` (no `color` key — removes the rainbow palette). `handleSave()` + `sanitizeGroups()` unchanged; reuses existing `NONCE_ACTION`/`NONCE_FIELD` constants. |

### Deleted files

(Deleted in the final task after verification)

- `web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder.css`
- `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder.js`

---

## Stride conventions to follow

- **Tests:** PHPUnit 10, run via `ddev exec vendor/bin/phpunit --testsuite Unit`. Stubs live in `tests/Stubs/`. Base class: `tests/TestCase.php`.
- **PHP style:** strict types declared, `final` classes, constructor promotion, `private const`. See `Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php` for the canonical shape.
- **Templates:** plain PHP, no Blade/Twig. Variables prefixed `$` in scope, `esc_html()` / `esc_attr()` / `esc_url()` on every output. Templates `include`d, not classed.
- **Alpine.js:** `Alpine.data('name', () => ({...}))` then `x-data="name()"` on the root. Reads state from `wp_localize_script` JSON. See `assets/js/admin-dashboard.js` for the pattern.
- **CSS tokens:** all values come from `--sd-*` variables defined in `assets/css/admin-dashboard.css` lines 17–80. Never hardcode colors/sizes — reference the token.
- **Commits:** Conventional Commits format: `feat:`, `refactor:`, `test:`, `style:`. Sign-offs: not required.

---

## Task 1: Verify baseline + add test scaffolding

**Files:**
- Create: `tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php`

- [ ] **Step 1: Run the existing unit test suite to confirm green starting point**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: `OK (894 tests, 2261 assertions)` (current baseline)

- [ ] **Step 2: Create the new test file with a failing smoke test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Questionnaire;

use Tests\TestCase;
use Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage;

final class QuestionnaireSettingsPageTest extends TestCase
{
    public function testGetFieldTypesReturnsPlainArrayWithoutColorKey(): void
    {
        $page = new QuestionnaireSettingsPage();

        $reflection = new \ReflectionMethod($page, 'getFieldTypes');
        $reflection->setAccessible(true);
        $types = $reflection->invoke($page);

        $this->assertIsArray($types);
        $this->assertArrayHasKey('text', $types);
        $this->assertArrayHasKey('label', $types['text']);
        $this->assertArrayNotHasKey(
            'color',
            $types['text'],
            'Field types should not carry per-type colors anymore — colors were removed to drop the rainbow chip palette.'
        );
    }
}
```

- [ ] **Step 3: Run the new test, expect it to fail**

Run: `ddev exec vendor/bin/phpunit --filter testGetFieldTypesReturnsPlainArrayWithoutColorKey`
Expected: `FAIL` — current `getFieldTypes()` at line 625 returns arrays containing `'color'` keys (see lines 628–634).

- [ ] **Step 4: Remove the color key from `getFieldTypes()` and promote nonce constants to public**

Edit `Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`.

First, change the nonce constants at lines 22–23 from `private` to `public` so the new template file can reference them:

```php
public const NONCE_ACTION = 'stride_save_questionnaire';
public const NONCE_FIELD  = 'stride_questionnaire_nonce';
```

Then at the `getFieldTypes()` method (around line 625), replace the return array so each type only has `label`:

```php
private function getFieldTypes(): array
{
    return [
        'text'        => ['label' => __('Tekst', 'stride')],
        'textarea'    => ['label' => __('Tekstveld', 'stride')],
        'select'      => ['label' => __('Selectie', 'stride')],
        'radio'       => ['label' => __('Keuze', 'stride')],
        'scale'       => ['label' => __('Schaal', 'stride')],
        'checkbox'    => ['label' => __('Vinkje', 'stride')],
        'description' => ['label' => __('Beschrijving', 'stride')],
    ];
}
```

- [ ] **Step 5: Run the test, expect pass**

Run: `ddev exec vendor/bin/phpunit --filter testGetFieldTypesReturnsPlainArrayWithoutColorKey`
Expected: `OK (1 test, 3 assertions)`

- [ ] **Step 6: Run the full unit suite, expect no regression**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: `OK (895 tests, 2264 assertions)` (one new test, +3 assertions)

- [ ] **Step 7: Commit**

```bash
git add tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php \
        web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php
git commit -m "refactor(questionnaire): prep QuestionnaireSettingsPage for v2 builder

- Drop per-field-type 'color' key from getFieldTypes(). The rainbow
  chip palette is gone; inspector renders type as plain text in a
  dropdown.
- Promote NONCE_ACTION + NONCE_FIELD from private to public so the
  v2 template (templates/builder.php) can reference them.

Part of: docs/superpowers/specs/2026-05-19-questionnaire-builder-redesign-design.md"
```

---

## Task 2: Add `getStateJson()` method for Alpine hydration

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`
- Modify: `tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php`

- [ ] **Step 1: Add a failing test for `getStateJson()` shape**

Append to `tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php`:

```php
public function testGetStateJsonHasGroupsFieldTypesStagesAndAssignmentsKeys(): void
{
    $page = new QuestionnaireSettingsPage();

    $reflection = new \ReflectionMethod($page, 'getStateJson');
    $reflection->setAccessible(true);
    $state = $reflection->invoke($page);

    $this->assertIsArray($state);
    $this->assertArrayHasKey('groups', $state);
    $this->assertArrayHasKey('fieldTypes', $state);
    $this->assertArrayHasKey('stages', $state);
    $this->assertArrayHasKey('assignments', $state);

    $this->assertIsArray($state['groups']);
    $this->assertIsArray($state['fieldTypes']);
    $this->assertIsArray($state['stages']);
    $this->assertIsArray($state['assignments']);
}
```

- [ ] **Step 2: Run the test, expect fail with "method not defined"**

Run: `ddev exec vendor/bin/phpunit --filter testGetStateJsonHasGroupsFieldTypesStagesAndAssignmentsKeys`
Expected: `FAIL` — `getStateJson()` does not exist yet.

- [ ] **Step 3: Add `getStateJson()` to `QuestionnaireSettingsPage`**

Add this method below `getFieldTypes()` in `QuestionnaireSettingsPage.php`:

```php
/**
 * Serialize all admin state for Alpine.js hydration.
 *
 * Returned as plain array; caller wraps in wp_localize_script() or
 * `<script type="application/json">` for the JSON-only path.
 *
 * @return array{
 *     groups: list<array>,
 *     fieldTypes: array<string, array{label: string}>,
 *     stages: array<string, string>,
 *     assignments: list<array{value: string, label: string}>,
 * }
 */
private function getStateJson(): array
{
    $repo  = $this->repository();
    $stages = $this->getStages();

    return [
        'groups'      => $repo->getGroups(),
        'fieldTypes'  => $this->getFieldTypes(),
        'stages'      => $stages,
        'assignments' => $this->getAssignmentOptions(),
    ];
}
```

(`repository()`, `getStages()`, `getAssignmentOptions()` already exist in the file — reuse them. If method names differ in your branch, check lines 159, 280, 320 of the current file and adapt.)

- [ ] **Step 4: Run the test, expect pass**

Run: `ddev exec vendor/bin/phpunit --filter testGetStateJsonHasGroupsFieldTypesStagesAndAssignmentsKeys`
Expected: `OK (1 test, 8 assertions)`

- [ ] **Step 5: Run full suite, expect no regression**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: `OK (896 tests, 2272 assertions)`

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php \
        web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php
git commit -m "feat(questionnaire): add getStateJson() for Alpine hydration

Serializes groups, field types, stages, and assignment options into a
single array. Used by the v2 template to seed Alpine.js state on page
load. Replaces the per-row server rendering of the old template.

Part of: docs/superpowers/specs/2026-05-19-questionnaire-builder-redesign-design.md"
```

---

## Task 3: Create the empty CSS file with `--sd-*` token aliases

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder-v2.css`

- [ ] **Step 1: Create the CSS file with the token aliases the rest of the work will use**

```css
/*
 * Questionnaire Builder v2 — admin styles
 *
 * All values come from --sd-* tokens defined in assets/css/admin-dashboard.css.
 * If a value is hardcoded here, it's a bug.
 *
 * Spec: docs/superpowers/specs/2026-05-19-questionnaire-builder-redesign-design.md
 */

.qb-app {
    font-family: var(--sd-font);
    font-size: var(--sd-font-size);
    color: var(--sd-text-primary);
    background: var(--sd-primary-bg);
    min-height: 100vh;
}

/* Toolbar (sticky at top of the page body) */
.qb-toolbar {
    position: sticky;
    top: 32px; /* WP admin bar */
    z-index: 5;
    background: var(--sd-surface);
    border-bottom: 1px solid var(--sd-border);
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.qb-toolbar__title { font-size: 14px; font-weight: 600; }
.qb-toolbar__count {
    background: var(--sd-primary-light);
    color: var(--sd-primary-hover);
    padding: 2px 8px;
    border-radius: 99px;
    font-size: var(--sd-font-size-sm);
    font-weight: 600;
}
.qb-toolbar__actions { display: flex; gap: 8px; }

/* Group tabs */
.qb-tabs {
    background: var(--sd-surface);
    border-bottom: 1px solid var(--sd-border);
    padding: 10px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.qb-tabs__list { display: flex; gap: 6px; flex-wrap: wrap; }
.qb-tab {
    background: var(--sd-surface);
    color: var(--sd-text-secondary);
    border: 1px solid var(--sd-border-strong);
    border-radius: var(--sd-radius);
    padding: 5px 10px;
    font-size: var(--sd-font-size-sm);
    cursor: pointer;
    height: var(--sd-control-height-sm);
}
.qb-tab--active {
    background: var(--sd-primary-subtle);
    color: var(--sd-primary);
    border-color: var(--sd-primary);
}
.qb-tab--add {
    background: var(--sd-surface);
    color: var(--sd-text-secondary);
}

/* Two-column body */
.qb-body { display: grid; grid-template-columns: 1fr 320px; }
.qb-canvas {
    padding: 16px 20px;
    border-right: 1px solid var(--sd-border);
}
.qb-inspector {
    background: var(--sd-surface);
    padding: 16px 20px;
}

/* Group header (in canvas) */
.qb-group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 10px;
    margin-bottom: 12px;
    border-bottom: 1px solid var(--sd-border);
}

/* Field rows */
.qb-field-list { list-style: none; margin: 0; padding: 0; }
.qb-field-row {
    background: var(--sd-surface);
    border: 1px solid var(--sd-border);
    border-radius: var(--sd-radius);
    padding: 10px 12px;
    margin-bottom: 6px;
    display: flex;
    gap: 10px;
    align-items: center;
    cursor: pointer;
}
.qb-field-row--selected {
    background: var(--sd-primary-subtle);
    border-color: var(--sd-primary);
}
.qb-field-row__grab { color: var(--sd-text-muted); cursor: grab; }
.qb-field-row__label { font-size: var(--sd-font-size); font-weight: 500; }
.qb-field-row__meta { font-size: 11px; color: var(--sd-text-muted); margin-top: 2px; }
.qb-field-row--selected .qb-field-row__meta { color: var(--sd-primary); font-weight: 500; }

/* Add-field dashed button */
.qb-add-field {
    background: var(--sd-surface);
    border: 1px dashed var(--sd-border-strong);
    border-radius: var(--sd-radius);
    padding: 10px;
    width: 100%;
    color: var(--sd-text-secondary);
    font-size: var(--sd-font-size);
    cursor: pointer;
    margin-top: 6px;
}

/* Inspector */
.qb-inspector__title {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--sd-text-muted);
    margin-bottom: 14px;
}
.qb-inspector__field { margin-bottom: 14px; }
.qb-inspector__label {
    display: block;
    font-size: var(--sd-font-size-sm);
    font-weight: 600;
    color: var(--sd-text-secondary);
    margin-bottom: 4px;
}
.qb-inspector__input,
.qb-inspector__select {
    width: 100%;
    border: 1px solid var(--sd-border-strong);
    border-radius: var(--sd-radius);
    padding: 6px 10px;
    font-size: var(--sd-font-size);
    height: var(--sd-control-height);
    background: var(--sd-surface);
    color: var(--sd-text-primary);
}
.qb-inspector__actions {
    border-top: 1px solid var(--sd-border);
    padding-top: 12px;
    display: flex;
    gap: 8px;
    margin-top: 18px;
}

/* Empty state */
.qb-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--sd-text-muted);
    font-size: var(--sd-font-size);
}

/* Buttons (reuse --sd- tokens) */
.qb-btn {
    background: var(--sd-surface);
    border: 1px solid var(--sd-border-strong);
    border-radius: var(--sd-radius);
    padding: 6px 12px;
    font-size: var(--sd-font-size);
    color: var(--sd-text-primary);
    cursor: pointer;
    height: var(--sd-control-height);
}
.qb-btn--primary {
    background: var(--sd-primary);
    color: #fff;
    border-color: var(--sd-primary);
    font-weight: 500;
}
.qb-btn--primary:hover { background: var(--sd-primary-hover); }
.qb-btn--danger {
    color: var(--sd-danger);
    border-color: var(--sd-danger);
    flex: 1;
}
.qb-btn--ghost { background: none; border: none; color: var(--sd-text-muted); padding: 4px; }
.qb-btn--sm { height: var(--sd-control-height-sm); font-size: var(--sd-font-size-sm); padding: 5px 10px; }

/* Sortable.js placeholder styling */
.qb-field-row.ui-sortable-helper { box-shadow: var(--sd-shadow-md); }
.qb-field-row.ui-sortable-placeholder {
    background: var(--sd-primary-light);
    border: 1px dashed var(--sd-primary);
    visibility: visible !important;
}
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder-v2.css
git commit -m "feat(questionnaire): add v2 CSS using --sd-* tokens

New stylesheet for the redesigned builder. Every value sourced from
admin-dashboard.css tokens. No hardcoded colors or sizes. Replaces
questionnaire-builder.css in a later task."
```

---

## Task 4: Create the empty Alpine.js component

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder-v2.js`

- [ ] **Step 1: Write the skeleton with state + selectors only (no actions yet)**

```javascript
/**
 * Questionnaire Builder v2 — Alpine.js controller
 *
 * Single component owning all admin state. Hydrated from
 * window.strideQuestionnaireState seeded by wp_localize_script.
 *
 * Spec: docs/superpowers/specs/2026-05-19-questionnaire-builder-redesign-design.md
 */
(function () {
    'use strict';

    function questionnaireBuilder() {
        return {
            // ── State ─────────────────────────────────────────────
            groups: [],
            selectedGroupId: null,
            selectedFieldId: null,
            fieldTypes: {},
            stages: {},
            assignments: [],
            isDirty: false,

            // ── Lifecycle ─────────────────────────────────────────
            init() {
                const seed = window.strideQuestionnaireState || {};
                this.groups = seed.groups || [];
                this.fieldTypes = seed.fieldTypes || {};
                this.stages = seed.stages || {};
                this.assignments = seed.assignments || [];

                if (this.groups.length > 0) {
                    this.selectedGroupId = this.groups[0].id;
                }
            },

            // ── Computed ──────────────────────────────────────────
            get selectedGroup() {
                return this.groups.find(g => g.id === this.selectedGroupId) || null;
            },

            get selectedField() {
                if (!this.selectedGroup) return null;
                return this.selectedGroup.fields.find(f => f.id === this.selectedFieldId) || null;
            },

            // ── Selection ─────────────────────────────────────────
            selectGroup(id) {
                this.selectedGroupId = id;
                this.selectedFieldId = null;
            },

            selectField(id) {
                this.selectedFieldId = id;
            },
        };
    }

    document.addEventListener('alpine:init', () => {
        window.Alpine.data('questionnaireBuilder', questionnaireBuilder);
    });
})();
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder-v2.js
git commit -m "feat(questionnaire): add v2 Alpine.js controller skeleton

State + selection only. Actions (add/delete/duplicate/reorder) come in
later tasks. Hydrates from window.strideQuestionnaireState seeded by
wp_localize_script."
```

---

## Task 5: Add CRUD actions to the Alpine component

**Files:**
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder-v2.js`

- [ ] **Step 1: Add the action methods inside the returned object, after `selectField`**

Add this block before the closing `}` of the returned object:

```javascript
            // ── ID generation ─────────────────────────────────────
            // New rows get a client-side `tmp_<random>` id. Server
            // assigns the real id on save; the existing sanitizeGroups()
            // accepts any string id, so this is safe.
            _newId(prefix) {
                return prefix + '_' + Math.random().toString(36).slice(2, 9);
            },

            // ── Group CRUD ────────────────────────────────────────
            addGroup() {
                const stageKeys = Object.keys(this.stages);
                const id = this._newId('tmp_g');
                this.groups.push({
                    id,
                    label: '',
                    stage: stageKeys[0] || '',
                    assigned: [],
                    fields: [],
                });
                this.selectedGroupId = id;
                this.selectedFieldId = null;
                this.isDirty = true;
            },

            deleteGroup(id) {
                const idx = this.groups.findIndex(g => g.id === id);
                if (idx === -1) return;
                this.groups.splice(idx, 1);
                if (this.selectedGroupId === id) {
                    this.selectedGroupId = this.groups[0]?.id || null;
                    this.selectedFieldId = null;
                }
                this.isDirty = true;
            },

            // ── Field CRUD ────────────────────────────────────────
            addField() {
                if (!this.selectedGroup) return;
                const id = this._newId('tmp_f');
                this.selectedGroup.fields.push({
                    id,
                    name: '',
                    label: '',
                    help: '',
                    type: 'text',
                    required: false,
                    options: '',
                    min: 1,
                    max: 5,
                });
                this.selectedFieldId = id;
                this.isDirty = true;
            },

            duplicateField(id) {
                if (!this.selectedGroup) return;
                const src = this.selectedGroup.fields.find(f => f.id === id);
                if (!src) return;
                const copy = { ...src, id: this._newId('tmp_f'), label: src.label + ' (kopie)' };
                const idx = this.selectedGroup.fields.findIndex(f => f.id === id);
                this.selectedGroup.fields.splice(idx + 1, 0, copy);
                this.selectedFieldId = copy.id;
                this.isDirty = true;
            },

            deleteField(id) {
                if (!this.selectedGroup) return;
                const idx = this.selectedGroup.fields.findIndex(f => f.id === id);
                if (idx === -1) return;
                this.selectedGroup.fields.splice(idx, 1);
                if (this.selectedFieldId === id) {
                    this.selectedFieldId = null;
                }
                this.isDirty = true;
            },

            // ── Field-row meta hint ───────────────────────────────
            fieldMeta(field) {
                const typeLabel = this.fieldTypes[field.type]?.label || field.type;
                const reqLabel = field.required ? 'vereist' : 'optioneel';
                if (field.type === 'select' || field.type === 'radio') {
                    const count = (field.options || '').split(/\r?\n/).filter(Boolean).length;
                    return typeLabel + ' · ' + count + ' opties · ' + reqLabel;
                }
                if (field.type === 'description') {
                    return typeLabel;
                }
                return typeLabel + ' · ' + reqLabel;
            },
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder-v2.js
git commit -m "feat(questionnaire): add CRUD actions to v2 controller

addGroup, deleteGroup, addField, duplicateField, deleteField + fieldMeta
helper. All operate on this.groups in place; isDirty flips on every
mutation. Server-side ids stay strings, so tmp_<random> ids are safe."
```

---

## Task 6: Create the template partials

**Files:**
- Create: `templates/builder.php`, `_toolbar.php`, `_group-tabs.php`, `_group-header.php`, `_field-list.php`, `_field-row.php`, `_inspector.php`, `_empty-state.php`

All under `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/`.

- [ ] **Step 1: Create `builder.php` (top-level wrapper)**

```php
<?php
/**
 * Questionnaire Builder v2 — top-level template.
 *
 * Loaded from QuestionnaireSettingsPage::renderPage().
 * State is hydrated by Alpine via window.strideQuestionnaireState
 * (set in enqueueAssets()).
 *
 * Form posts to the same admin URL — handleSave() is wired on
 * admin_init and reads $_POST directly. NONCE_ACTION + NONCE_FIELD
 * are the existing constants on QuestionnaireSettingsPage.
 */
defined('ABSPATH') || exit;
?>
<div class="qb-app" x-data="questionnaireBuilder()" x-cloak>
    <form method="post">
        <?php wp_nonce_field(\Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage::NONCE_ACTION, \Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage::NONCE_FIELD); ?>
        <input type="hidden" name="stride_questionnaire_groups_json" :value="JSON.stringify(groups)">

        <?php include __DIR__ . '/_toolbar.php'; ?>
        <?php include __DIR__ . '/_group-tabs.php'; ?>

        <div class="qb-body">
            <div class="qb-canvas">
                <template x-if="selectedGroup">
                    <div>
                        <?php include __DIR__ . '/_group-header.php'; ?>
                        <?php include __DIR__ . '/_field-list.php'; ?>
                    </div>
                </template>
                <template x-if="!selectedGroup">
                    <?php include __DIR__ . '/_empty-state.php'; ?>
                </template>
            </div>
            <?php include __DIR__ . '/_inspector.php'; ?>
        </div>
    </form>
</div>
```

- [ ] **Step 2: Create `_toolbar.php`**

```php
<?php defined('ABSPATH') || exit; ?>
<div class="qb-toolbar">
    <div style="display:flex;align-items:center;gap:14px">
        <div class="qb-toolbar__title"><?php esc_html_e('Vragenlijst groepen', 'stride'); ?></div>
        <span class="qb-toolbar__count" x-text="groups.length + ' <?php echo esc_js(__('groepen', 'stride')); ?>'"></span>
    </div>
    <div class="qb-toolbar__actions">
        <a href="<?php echo esc_url(admin_url('admin.php?page=stride-questionnaire')); ?>" class="qb-btn">
            <?php esc_html_e('Annuleren', 'stride'); ?>
        </a>
        <button type="submit" class="qb-btn qb-btn--primary">
            <?php esc_html_e('Wijzigingen opslaan', 'stride'); ?>
        </button>
    </div>
</div>
```

- [ ] **Step 3: Create `_group-tabs.php`**

```php
<?php defined('ABSPATH') || exit; ?>
<div class="qb-tabs">
    <div class="qb-tabs__list">
        <template x-for="group in groups" :key="group.id">
            <button type="button"
                    class="qb-tab"
                    :class="{ 'qb-tab--active': group.id === selectedGroupId }"
                    @click="selectGroup(group.id)"
                    x-text="group.label || '<?php echo esc_js(__('Nieuwe groep', 'stride')); ?>'"></button>
        </template>
        <button type="button" class="qb-tab qb-tab--add" @click="addGroup()">
            + <?php esc_html_e('Nieuwe groep', 'stride'); ?>
        </button>
    </div>
    <div style="font-size:var(--sd-font-size-sm);color:var(--sd-text-secondary)" x-show="selectedGroup">
        <?php esc_html_e('Fase:', 'stride'); ?>
        <strong x-text="stages[selectedGroup?.stage] || ''" style="color:var(--sd-text-primary)"></strong>
    </div>
</div>
```

- [ ] **Step 4: Create `_group-header.php`**

```php
<?php defined('ABSPATH') || exit; ?>
<div class="qb-group-header">
    <div style="flex:1">
        <input type="text"
               class="qb-inspector__input"
               style="border-color:transparent;background:transparent;padding-left:0;font-weight:600"
               x-model="selectedGroup.label"
               @input="isDirty = true"
               placeholder="<?php esc_attr_e('Groepsnaam (bv. Medische gegevens)', 'stride'); ?>">
        <div style="font-size:var(--sd-font-size-sm);color:var(--sd-text-muted);margin-top:2px">
            <?php esc_html_e('Toon aan:', 'stride'); ?>
            <span x-text="selectedGroup.assigned.length === 0 ? '<?php echo esc_js(__('alle deelnemers', 'stride')); ?>' : selectedGroup.assigned.length + ' <?php echo esc_js(__('toewijzingen', 'stride')); ?>'"></span>
        </div>
    </div>
    <button type="button" class="qb-btn qb-btn--ghost"
            @click="if (confirm('<?php echo esc_js(__('Deze groep verwijderen?', 'stride')); ?>')) deleteGroup(selectedGroup.id)">
        <?php esc_html_e('Verwijder groep', 'stride'); ?>
    </button>
</div>
```

- [ ] **Step 5: Create `_field-list.php`**

```php
<?php defined('ABSPATH') || exit; ?>
<ul class="qb-field-list" x-ref="fieldList" data-group-id="" :data-group-id="selectedGroup?.id">
    <template x-for="field in selectedGroup.fields" :key="field.id">
        <?php include __DIR__ . '/_field-row.php'; ?>
    </template>
</ul>

<template x-if="selectedGroup.fields.length === 0">
    <div class="qb-empty">
        <?php esc_html_e('Geen velden in deze groep.', 'stride'); ?>
        <button type="button" class="qb-btn" style="margin-top:12px" @click="addField()">
            + <?php esc_html_e('Eerste veld toevoegen', 'stride'); ?>
        </button>
    </div>
</template>

<button type="button" class="qb-add-field" @click="addField()"
        x-show="selectedGroup.fields.length > 0">
    + <?php esc_html_e('Veld toevoegen', 'stride'); ?>
</button>
```

- [ ] **Step 6: Create `_field-row.php`**

```php
<?php defined('ABSPATH') || exit; ?>
<li class="qb-field-row"
    :class="{ 'qb-field-row--selected': field.id === selectedFieldId }"
    :data-field-id="field.id"
    @click="selectField(field.id)">
    <div class="qb-field-row__grab">⋮⋮</div>
    <div style="flex:1">
        <div class="qb-field-row__label" x-text="field.label || '<?php echo esc_js(__('Naamloos veld', 'stride')); ?>'"></div>
        <div class="qb-field-row__meta" x-text="fieldMeta(field)"></div>
    </div>
    <span style="font-size:11px;color:var(--sd-text-muted)" x-show="field.id !== selectedFieldId">✎</span>
</li>
```

- [ ] **Step 7: Create `_inspector.php`**

```php
<?php defined('ABSPATH') || exit; ?>
<aside class="qb-inspector">
    <div class="qb-inspector__title">
        <span x-show="selectedField"><?php esc_html_e('Veld bewerken', 'stride'); ?></span>
        <span x-show="!selectedField"><?php esc_html_e('Selecteer een veld', 'stride'); ?></span>
    </div>

    <template x-if="selectedField">
        <div>
            <div class="qb-inspector__field">
                <label class="qb-inspector__label"><?php esc_html_e('Type', 'stride'); ?></label>
                <select class="qb-inspector__select"
                        x-model="selectedField.type"
                        @change="isDirty = true">
                    <template x-for="(typeDef, typeKey) in fieldTypes" :key="typeKey">
                        <option :value="typeKey" x-text="typeDef.label"></option>
                    </template>
                </select>
            </div>

            <div class="qb-inspector__field" x-show="selectedField.type !== 'description'">
                <label class="qb-inspector__label"><?php esc_html_e('Vraag', 'stride'); ?></label>
                <input type="text"
                       class="qb-inspector__input"
                       x-model="selectedField.label"
                       @input="isDirty = true">
            </div>

            <div class="qb-inspector__field" x-show="selectedField.type === 'description'">
                <label class="qb-inspector__label"><?php esc_html_e('Tekst', 'stride'); ?></label>
                <textarea class="qb-inspector__input"
                          style="min-height:80px;padding:8px 10px"
                          x-model="selectedField.label"
                          @input="isDirty = true"></textarea>
            </div>

            <div class="qb-inspector__field" x-show="selectedField.type !== 'description'">
                <label class="qb-inspector__label">
                    <?php esc_html_e('Hulptekst', 'stride'); ?>
                    <span style="font-weight:400;color:var(--sd-text-muted)">
                        (<?php esc_html_e('optioneel', 'stride'); ?>)
                    </span>
                </label>
                <input type="text"
                       class="qb-inspector__input"
                       x-model="selectedField.help"
                       @input="isDirty = true">
            </div>

            <div class="qb-inspector__field" x-show="selectedField.type === 'select' || selectedField.type === 'radio'">
                <label class="qb-inspector__label">
                    <?php esc_html_e('Opties', 'stride'); ?>
                    <span style="font-weight:400;color:var(--sd-text-muted)">
                        (<?php esc_html_e('één per regel', 'stride'); ?>)
                    </span>
                </label>
                <textarea class="qb-inspector__input"
                          style="min-height:80px;padding:8px 10px"
                          x-model="selectedField.options"
                          @input="isDirty = true"></textarea>
            </div>

            <div class="qb-inspector__field" x-show="selectedField.type === 'scale'">
                <label class="qb-inspector__label"><?php esc_html_e('Schaal', 'stride'); ?></label>
                <div style="display:flex;gap:8px">
                    <input type="number" class="qb-inspector__input" x-model.number="selectedField.min" min="1" @input="isDirty = true">
                    <input type="number" class="qb-inspector__input" x-model.number="selectedField.max" min="2" @input="isDirty = true">
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;font-size:var(--sd-font-size);color:var(--sd-text-primary);margin-bottom:18px;cursor:pointer"
                   x-show="selectedField.type !== 'description'">
                <input type="checkbox" x-model="selectedField.required" @change="isDirty = true">
                <?php esc_html_e('Verplicht in te vullen', 'stride'); ?>
            </label>

            <div class="qb-inspector__actions">
                <button type="button" class="qb-btn qb-btn--sm" style="flex:1"
                        @click="duplicateField(selectedField.id)">
                    <?php esc_html_e('Dupliceren', 'stride'); ?>
                </button>
                <button type="button" class="qb-btn qb-btn--sm qb-btn--danger"
                        @click="if (confirm('<?php echo esc_js(__('Dit veld verwijderen?', 'stride')); ?>')) deleteField(selectedField.id)">
                    <?php esc_html_e('Verwijderen', 'stride'); ?>
                </button>
            </div>
        </div>
    </template>
</aside>
```

- [ ] **Step 8: Create `_empty-state.php`**

```php
<?php defined('ABSPATH') || exit; ?>
<div class="qb-empty">
    <?php esc_html_e('Geen groepen aangemaakt.', 'stride'); ?>
    <button type="button" class="qb-btn qb-btn--primary" style="margin-top:12px" @click="addGroup()">
        + <?php esc_html_e('Eerste groep toevoegen', 'stride'); ?>
    </button>
</div>
```

- [ ] **Step 9: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/
git commit -m "feat(questionnaire): add v2 template partials

8 new partials: builder, toolbar, group-tabs, group-header, field-list,
field-row, inspector, empty-state. Server-rendered HTML hydrated by
Alpine.js. Replaces inline render methods in QuestionnaireSettingsPage."
```

---

## Task 7: Wire `renderPage()` and `enqueueAssets()` to use v2

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`

- [ ] **Step 1: Read the current `renderPage()` body (around lines 167–195)**

You need to find the method, currently ~30 lines, that opens the WP admin page wrap, prints headings, and renders the form inline.

- [ ] **Step 2: Replace `renderPage()` body**

Locate the method `public function renderPage(): void` and replace its body with:

```php
public function renderPage(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Geen toegang.', 'stride'));
    }

    ?>
    <div class="wrap">
        <h1 style="margin-bottom:16px"><?php esc_html_e('Vragenlijst', 'stride'); ?></h1>
        <?php
        settings_errors('stride_questionnaire');
        include __DIR__ . '/templates/builder.php';
        ?>
    </div>
    <?php
}
```

- [ ] **Step 3: Update `enqueueAssets()` to register the v2 assets and seed state**

Locate `public function enqueueAssets(string $hook): void` (around line 70). Replace its body with:

```php
public function enqueueAssets(string $hook): void
{
    if (!str_contains($hook, self::PAGE_SLUG)) {
        return;
    }

    // Matches the existing enqueue pattern in this file: from
    // Modules/Questionnaire/Admin, go up 3 levels to stride-core root.
    $basePath = dirname(__DIR__, 3);
    $cssFile  = $basePath . '/assets/css/admin/questionnaire-builder-v2.css';
    $jsFile   = $basePath . '/assets/js/admin/questionnaire-builder-v2.js';

    // The admin-dashboard.css stylesheet defines all --sd-* tokens.
    // AdminDashboardService registers it at handle `stride-admin-dashboard`.
    if (wp_style_is('stride-admin-dashboard', 'registered')) {
        wp_enqueue_style('stride-admin-dashboard');
    }

    // Alpine.js — AdminDashboardService registers it at handle `alpinejs`
    // (3.14.9, CDN, defer). Register here if we're outside its scope so the
    // call is idempotent.
    if (!wp_script_is('alpinejs', 'registered')) {
        wp_register_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
            [],
            '3.14.9',
            true
        );
        wp_script_add_data('alpinejs', 'defer', true);
    }
    wp_enqueue_script('alpinejs');

    wp_enqueue_script('jquery-ui-sortable');

    if (file_exists($cssFile)) {
        wp_enqueue_style(
            'stride-questionnaire-builder-v2',
            plugins_url('assets/css/admin/questionnaire-builder-v2.css', $basePath . '/stride-core.php'),
            [],
            (string) filemtime($cssFile)
        );
    }

    if (file_exists($jsFile)) {
        wp_enqueue_script(
            'stride-questionnaire-builder-v2',
            plugins_url('assets/js/admin/questionnaire-builder-v2.js', $basePath . '/stride-core.php'),
            ['jquery', 'jquery-ui-sortable'],
            (string) filemtime($jsFile),
            true
        );

        wp_localize_script(
            'stride-questionnaire-builder-v2',
            'strideQuestionnaireState',
            $this->getStateJson()
        );
    }
}
```

(If the project already enqueues Alpine globally, drop the `alpinejs` block — check `assets/js/admin-dashboard.js` enqueue.)

- [ ] **Step 4: Walk through the builder page manually**

```bash
ddev exec wp cache flush --path=web/wp
```

Then in a browser, visit: `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-questionnaire` (the submenu is "Formuliervelden" under the Stride dashboard menu)

Verify visually:
1. Page renders with the new layout (toolbar top, group tabs, two-column body)
2. Clicking a group tab highlights it and updates the canvas
3. Clicking a field highlights it (blue ring + bg) and populates the inspector
4. "+ Veld toevoegen" adds a new row and selects it
5. Inspector "Verwijderen" + confirm removes the field
6. "Wijzigingen opslaan" submits and reloads with the new content persisted

- [ ] **Step 5: Run the full unit suite**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: `OK (896 tests, 2272 assertions)` (no regression — `renderPage` has no test coverage; we relied on manual walkthrough)

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php
git commit -m "feat(questionnaire): wire renderPage/enqueueAssets to v2 templates

renderPage now includes templates/builder.php instead of inline HTML.
enqueueAssets switches to the v2 CSS/JS handles and seeds Alpine state
via wp_localize_script.

The legacy inline render methods (renderGroup, renderFieldCard, etc.)
remain for now — removed in a later task after the new flow is verified."
```

---

## Task 8: Adapt `handleSave()` to accept the JSON payload

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`
- Modify: `tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php`

- [ ] **Step 1: Add a failing test for the JSON-shaped POST payload**

Append to `tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php`:

```php
public function testParseSubmittedGroupsDecodesJsonPayloadFromV2Builder(): void
{
    $payload = [
        [
            'id'       => 'tmp_g1',
            'label'    => 'Medische gegevens',
            'stage'    => 'enrollment_personal',
            'assigned' => [],
            'fields'   => [
                [
                    'id'       => 'tmp_f1',
                    'name'     => '',
                    'label'    => 'Allergieën?',
                    'type'     => 'textarea',
                    'required' => true,
                ],
            ],
        ],
    ];

    $page = new QuestionnaireSettingsPage();
    $reflection = new \ReflectionMethod($page, 'parseSubmittedGroups');
    $reflection->setAccessible(true);

    $parsed = $reflection->invoke($page, json_encode($payload));

    $this->assertIsArray($parsed);
    $this->assertCount(1, $parsed);
    $this->assertSame('Medische gegevens', $parsed[0]['label']);
    $this->assertCount(1, $parsed[0]['fields']);
    $this->assertSame('Allergieën?', $parsed[0]['fields'][0]['label']);
}
```

- [ ] **Step 2: Run, expect fail (method doesn't exist)**

Run: `ddev exec vendor/bin/phpunit --filter testParseSubmittedGroupsDecodesJsonPayloadFromV2Builder`
Expected: `FAIL`

- [ ] **Step 3: Add `parseSubmittedGroups()` method**

Add to `QuestionnaireSettingsPage.php`, near `sanitizeGroups`:

```php
/**
 * Decode the v2 builder's JSON payload back into the array shape
 * sanitizeGroups() expects. Returns [] on malformed input — the
 * existing sanitizer treats [] as "no groups", which is safe.
 */
private function parseSubmittedGroups(string $json): array
{
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    return $decoded;
}
```

- [ ] **Step 4: Update `handleSave()` to prefer JSON payload, fall back to legacy**

Find the existing code in `handleSave()` that pulls `$_POST['stride_questionnaire_groups']` (around line 150). Replace that line with:

```php
// v2 builder posts as JSON; legacy (pre-v2) posted as nested form arrays
$rawGroups = isset($_POST['stride_questionnaire_groups_json'])
    ? $this->parseSubmittedGroups((string) wp_unslash($_POST['stride_questionnaire_groups_json']))
    : (array) ($_POST['stride_questionnaire_groups'] ?? []);
```

- [ ] **Step 5: Run the new test, expect pass**

Run: `ddev exec vendor/bin/phpunit --filter testParseSubmittedGroupsDecodesJsonPayloadFromV2Builder`
Expected: `OK (1 test, 4 assertions)`

- [ ] **Step 6: Run the full suite**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: `OK (897 tests, 2276 assertions)`

- [ ] **Step 7: Manual save-and-reload smoke test**

In browser, modify a group label and a field label. Click "Wijzigingen opslaan". Reload. Verify:
1. WP shows an "Updated" notice
2. The new labels persist
3. No PHP warnings in `wp_debug_log` or DDEV logs

- [ ] **Step 8: Commit**

```bash
git add tests/Unit/Questionnaire/QuestionnaireSettingsPageTest.php \
        web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php
git commit -m "feat(questionnaire): accept JSON payload from v2 builder

handleSave now reads stride_questionnaire_groups_json (the v2 shape)
in addition to the legacy nested array shape. Falls back gracefully
when JSON is missing or malformed."
```

---

## Task 9: Wire jQuery UI Sortable for field reordering

**Files:**
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder-v2.js`

- [ ] **Step 1: Add an effect/watcher that initializes Sortable when the group changes**

Add `initSortable()` method inside the returned object, and call it from `init()` + after `selectGroup()`:

```javascript
            // ── Drag-drop ─────────────────────────────────────────
            initSortable() {
                if (typeof jQuery === 'undefined' || !jQuery.ui || !jQuery.ui.sortable) {
                    return; // graceful fallback — drag disabled, no JS error
                }
                const $list = jQuery(this.$refs.fieldList);
                if (!$list.length) return;
                if ($list.data('uiSortable')) {
                    $list.sortable('destroy');
                }
                const self = this;
                $list.sortable({
                    items: '> li',
                    handle: '.qb-field-row__grab',
                    cursor: 'grabbing',
                    placeholder: 'qb-field-row qb-field-row--placeholder ui-sortable-placeholder',
                    forcePlaceholderSize: true,
                    update: function () {
                        // Read new order from DOM, reassign this.selectedGroup.fields
                        if (!self.selectedGroup) return;
                        const newOrder = [];
                        $list.find('> li').each(function () {
                            const id = jQuery(this).data('fieldId');
                            const field = self.selectedGroup.fields.find(f => f.id === id);
                            if (field) newOrder.push(field);
                        });
                        self.selectedGroup.fields = newOrder;
                        self.isDirty = true;
                    },
                });
            },
```

Modify the existing `init()` to call `initSortable` after a tick:

```javascript
            init() {
                const seed = window.strideQuestionnaireState || {};
                this.groups = seed.groups || [];
                this.fieldTypes = seed.fieldTypes || {};
                this.stages = seed.stages || {};
                this.assignments = seed.assignments || [];

                if (this.groups.length > 0) {
                    this.selectedGroupId = this.groups[0].id;
                }

                this.$nextTick(() => this.initSortable());
            },
```

Modify `selectGroup()` to re-init Sortable on the new group's list:

```javascript
            selectGroup(id) {
                this.selectedGroupId = id;
                this.selectedFieldId = null;
                this.$nextTick(() => this.initSortable());
            },
```

- [ ] **Step 2: Manual drag-drop test**

Reload the admin page. With a group containing 3+ fields:
1. Hover the ⋮⋮ handle — cursor should change to grab
2. Drag a field to a new position — placeholder shows blue
3. Drop — order updates visually
4. Click "Wijzigingen opslaan", reload
5. Verify the new order persists

- [ ] **Step 3: Sortable-disabled fallback test**

Open browser devtools, block `jquery-ui.js` (or rename the asset URL in Network → Block request URL). Reload.
Expected: no JS errors, drag handles still visible but non-functional, save still works.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder-v2.js
git commit -m "feat(questionnaire): wire jQuery UI Sortable for field reorder

initSortable() runs after init() and after every selectGroup(), keeping
the bound list pointing at the current group's <ul>. Graceful no-op
when jQuery UI Sortable is not loaded — drag disabled, save still
works, no JS errors thrown."
```

---

## Task 10: Remove the legacy renderers and CSS/JS files

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`
- Delete: `web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder.css`
- Delete: `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder.js`

- [ ] **Step 1: In `QuestionnaireSettingsPage.php`, delete legacy methods**

Remove these private methods (all currently used only by the old inline `renderPage()`, replaced by templates):
- `renderGroup()` (around line 225)
- `renderFieldCard()` (around line 370)
- The inline `<script type="text/template">` blocks (lines 213–219 area — should have been removed via Task 7 but verify)

Use your editor to find each method and delete from `private function` through the matching closing `}`.

- [ ] **Step 2: Run the full unit suite**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: `OK (897 tests, 2276 assertions)` — no new failures.

- [ ] **Step 3: Manual smoke test of the builder page**

Reload `admin.php?page=stride-questionnaire`. Verify:
1. Page still renders (legacy methods unused)
2. Add/delete/edit/drag/save all still work
3. Browser console: no 404s for the old `questionnaire-builder.css` or `.js` (Task 7 already swapped the enqueue handles)

- [ ] **Step 4: Delete the legacy asset files**

```bash
rm web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder.css
rm web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder.js
```

- [ ] **Step 5: Reload the admin page once more**

Verify no 404s in the browser network tab — confirms nothing else referenced the deleted files.

- [ ] **Step 6: Commit**

```bash
git rm web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder.css \
       web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder.js
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php
git commit -m "refactor(questionnaire): drop legacy v1 renderers and assets

Removes renderGroup, renderFieldCard, inline JS templates, and the
v1 CSS/JS files. v2 (templates + Alpine + jQuery UI Sortable) is now
the sole admin builder."
```

---

## Task 11: Final QA pass

- [ ] **Step 1: Run the full unit suite**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: `OK (897 tests, 2276 assertions)`

- [ ] **Step 2: Walk through the 5 manual scenarios from the spec**

1. Add a new group, add 3 fields of different types (text, select, scale), set required, save, reload, verify persistence.
2. Drag-reorder 3 fields, save, reload, verify order.
3. Click a select field, edit its options (newline-separated), save, reload, verify options preserved.
4. Click a description field type — verify the inspector switches to a textarea for the body and hides required/help.
5. Delete a group with confirm dialog — verify it's gone after save.

For each: take a screenshot for the PR description.

- [ ] **Step 3: Code linting**

Run: `ddev exec php -l web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`
Expected: `No syntax errors detected`

For each new template:
```bash
for f in web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/templates/*.php; do
  ddev exec php -l "/var/www/html/$f" 2>&1 | grep -v "^No syntax"
done
```
Expected: empty output (all clean).

- [ ] **Step 4: Verify no `--sd-*` token misses**

Run:
```bash
grep -E "color: #|background: #|border:.+#" web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder-v2.css | grep -v "/\*"
```
Expected: empty output. Any match is a hardcoded color that should reference a `--sd-*` token. Fix and recommit.

- [ ] **Step 5: Final commit (only if cleanup needed from step 4)**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder-v2.css
git commit -m "style(questionnaire): replace hardcoded colors with --sd-* tokens"
```

---

## Follow-up (not blocking this redesign)

The drift review (2026-05-19) flagged one **inherited** drift item:

- `getAssignmentOptions()` calls `ntdst_data()->get('vad_edition')` directly to build the edition dropdown. Per `pattern_repositories_only.md`, this should go through `EditionRepository::findFields(['course_id', 'start_date'])` (or whichever shape the dropdown needs).

Keep it out of this PR to maintain UI-only scope. Create a follow-up task once this lands:

> **Follow-up: `getAssignmentOptions()` repository refactor**
> Replace the `ntdst_data()->get('vad_edition')` loop in `QuestionnaireSettingsPage::getAssignmentOptions()` with `ntdst_get(EditionRepository::class)` calls. One-method change; covered by visual regression of the assignment dropdown only (no business logic).

Call this out in the PR description so it doesn't ossify.

---

## Summary

After all tasks:

- **New:** 11 files (8 templates + CSS + JS + test file)
- **Modified:** `QuestionnaireSettingsPage.php` — net `-~300 lines` (legacy renderers removed, lean template includes added). `NONCE_ACTION`/`NONCE_FIELD` constants promoted to `public` so the new template can reference them.
- **Deleted:** 2 legacy asset files
- **Test count:** +3 new unit tests guarding `getFieldTypes` shape, `getStateJson` shape, JSON payload decoding

Public-side rendering, validator, repository, service: **unchanged**.
