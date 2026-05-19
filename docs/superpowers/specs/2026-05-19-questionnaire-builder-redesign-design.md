# Questionnaire Builder Redesign — Design Spec

**Status:** Draft for review
**Date:** 2026-05-19
**Author:** brainstormed with Stefan
**Predecessor:** `2026-03-22-questionnaire-system-design.md` (this redesign keeps the data layer untouched and replaces the admin UI only)

## Problem

The current questionnaire admin (`Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php` + `assets/js/admin/questionnaire-builder.js`) works functionally but reads as a debug palette:

- Field-type buttons (Tekst, Tekstveld, Selectie, Keuze, Schaal, Vinkje, Beschrijving) are rendered as a flat row of rainbow-bordered chips below "+ Veld toevoegen". Looks like a developer tool, not a polished admin panel.
- Two separate "+ toevoegen" buttons with different visual weight (thin outline + filled). Hierarchy is accidental.
- Group panel has a header bar with status pills, hamburger icon, X — treats inline content like a modal/drawer.
- No visual selection state — clicking a field doesn't communicate "this is the one you're editing".
- Doesn't share visual language with the rest of the Stride admin dashboard (the Alpine pages under `templates/admin/`).

The data layer is sound. Groups, fields, stages, assignments, field types, validation — all working. **This redesign is admin UI only.**

## Goals

- The admin feels like a part of the Stride admin dashboard, not a separate tool.
- Editor (non-technical, occasional user, small forms of 3–8 fields) understands what to do without training.
- Selection state is obvious. Editing is contextual.
- Zero data-layer changes — no migration, no schema change.

## Non-goals

- Drag-drop between *groups* (only within a group). To move a field across groups, editor opens the inspector menu and uses "Verplaats naar..." — this is rare with small forms.
- Conditional field logic ("if X then show Y"). Out of scope; current schema can't express it and editors don't need it for small forms.
- Real-time collaboration, autosave, version history.
- Touch-optimized mobile UX. Builder is a desktop admin tool.
- Replacing the questionnaire renderer or the data API. Public-side forms keep rendering as today.

## Decisions locked during brainstorming

| Question | Answer |
|---|---|
| Primary user | Non-technical course editor |
| Usage frequency | Occasional — a few times per month |
| Typical form complexity | Small — 1 group, 3–8 fields |
| Direction (A inline vs B canvas+inspector) | **B** — canvas + right inspector |
| Drag-drop reordering | Yes, with Sortable.js |
| Live preview fidelity | Schematic (boxes with labels) — not full WYSIWYG |
| Scope right now | **Plan only, build later** (not blocking migration) |
| Visual tone | Matches admin-dashboard.css tokens exactly |

## Architecture

### Layout (three regions)

```
┌──────────────────────────────────────────────────────────────────┐
│ Toolbar: page title · group count · [Annuleren] [Opslaan]       │  44px sticky
├──────────────────────────────────────────────────────────────────┤
│ Group tabs: [Medische gegevens] [Verwachtingen] [+ Nieuwe groep]│  44px
│                                              Fase: Persoonlijk  │
├────────────────────────────────────────┬─────────────────────────┤
│ Canvas                                 │ Inspector               │
│                                        │                         │
│ Group header (name, scope, … menu)     │ VELD BEWERKEN           │
│ ────────────────────────────────────   │                         │
│ [⋮⋮] Field row, plain                  │ Type      [select   ▾] │
│ [⋮⋮] Field row, plain                  │ Vraag     [input    ]  │
│ [⋮⋮] Field row, SELECTED               │ Hulptekst [input    ]  │
│ [⋮⋮] Field row, plain                  │ ☐ Verplicht            │
│                                        │ ───────────────────    │
│ [+ Veld toevoegen]                     │ [Dup] [Verwijder]      │
└────────────────────────────────────────┴─────────────────────────┘
   flex 1                                  fixed 320px
```

### Components

| Component | Purpose | Where it lives |
|---|---|---|
| `Toolbar` | Page title, group count, save/cancel | new template partial |
| `GroupTabs` | Pill-tab switcher; click a group to load it into the canvas | new partial |
| `GroupHeader` | Selected group's name (inline-editable), scope, "..." menu | new partial |
| `FieldList` | Drag-sortable list of field rows; click row to select | new partial, Sortable.js attached |
| `FieldRow` | A field's label + type + meta hint (e.g. "Tekstveld · vereist") | new partial |
| `AddFieldButton` | Dashed button at the bottom of the field list | new partial |
| `Inspector` | Field-property editor: type, label, help, required, options (for select/radio/scale), duplicate, delete | new partial |
| `EmptyState` (group has no fields) | "Voeg je eerste veld toe" with centered + button | new partial |

Each component is a server-rendered PHP template that emits HTML readable by an Alpine.js controller bound at the top level. Same pattern as the existing dashboard pages (`templates/admin/dashboard.php`).

### State management

One Alpine.js component owns the entire page state:

```js
Alpine.data('questionnaireBuilder', () => ({
    groups: [],            // [{id, label, stage, assigned, fields: [...]}]
    selectedGroupId: null,
    selectedFieldId: null,
    fieldTypes: [],        // from PHP via wp_localize_script
    isDirty: false,

    init() { /* hydrate from server-rendered JSON */ },
    selectGroup(id) { ... },
    selectField(id) { ... },
    addGroup() { ... },
    addField() { ... },
    duplicateField(id) { ... },
    deleteField(id) { ... },
    saveAll() { /* POST to existing handler, no API change */ },
}))
```

Reuses the existing POST flow in `QuestionnaireSettingsPage::handleSubmit()` — server-side validation + save is unchanged. Client just sends a slightly more structured payload that maps 1:1 to the existing form-encoded shape.

### Drag-drop

The current builder already enqueues **jQuery UI Sortable** (line 110 of `QuestionnaireSettingsPage.php`, dependency `jquery-ui-sortable`). Reuse it — no new dependency, smaller patch. Attached to the `FieldList` ul of the currently-selected group:

```js
$('.qb-field-list').sortable({
    items: '> li',
    handle: '.qb-field-grab',
    update: (e, ui) => {
        const fieldId = ui.item.data('field-id');
        const newIndex = ui.item.index();
        this.reorderField(fieldId, newIndex);
        this.isDirty = true;
    }
});
```

(Originally Sortable.js was suggested as zero-dep. jQuery UI Sortable is ~140kb but it's already loaded — net change ≈ 0kb.)

### Data flow

```
Page load → server renders initial HTML + emits state JSON via wp_localize_script
            ↓
Alpine init() reads state JSON, takes over rendering
            ↓
User interacts (click field, edit input, drag) → Alpine mutates state, isDirty=true
            ↓
User clicks "Opslaan" → Alpine serializes state to form-encoded → POST to handleSubmit()
            ↓
Server validates + saves → redirect with success notice
```

No AJAX. Hard save on submit. Matches the existing pattern; keeps server validation as the single source of truth.

### Visual tokens (all from `admin-dashboard.css`)

| Token | Value | Use |
|---|---|---|
| `--sd-primary` | `#2563eb` | Selected field border, active tab, primary button |
| `--sd-primary-subtle` | `#eff6ff` | Selected field row bg |
| `--sd-primary-light` | `#dbeafe` | Badge bg ("3 groepen") |
| `--sd-text-primary` | `#0f172a` | Field labels |
| `--sd-text-secondary` | `#475569` | Inspector labels |
| `--sd-text-muted` | `#94a3b8` | Field-type meta hints, drag handles |
| `--sd-surface` | `#ffffff` | Cards, inspector |
| `--sd-surface-alt` | `#f8fafc` | Canvas bg |
| `--sd-border` | `#e2e8f0` | Field rows, dividers |
| `--sd-border-strong` | `#cbd5e1` | Inputs |
| `--sd-radius` | `6px` | Everything |
| `--sd-font` | `-apple-system, ...` | Everything |
| `--sd-font-size` | `13px` | Body |
| `--sd-font-size-sm` | `12px` | Meta hints, labels |
| `--sd-control-height` | `32px` | Inputs, primary buttons |
| `--sd-control-height-sm` | `28px` | Group pill tabs |
| `--sd-shadow-sm` | `0 1px 2px rgba(15,23,42,0.04)` | Resting field rows |
| `--sd-danger` | `#dc2626` | Delete button outline |

**Critical:** all chip/button colors that were hardcoded per field type in PHP (lines 625–632 of QuestionnaireSettingsPage) go away. Field type is just text in the inspector dropdown.

## File touch list

### New

- `Modules/Questionnaire/Admin/templates/builder.php` — main wrapper, three regions
- `Modules/Questionnaire/Admin/templates/_toolbar.php`
- `Modules/Questionnaire/Admin/templates/_group-tabs.php`
- `Modules/Questionnaire/Admin/templates/_group-header.php`
- `Modules/Questionnaire/Admin/templates/_field-list.php`
- `Modules/Questionnaire/Admin/templates/_field-row.php`
- `Modules/Questionnaire/Admin/templates/_inspector.php`
- `Modules/Questionnaire/Admin/templates/_empty-state.php`
- `assets/css/admin/questionnaire-builder-v2.css` — new file, replaces `questionnaire-builder.css`
- `assets/js/admin/questionnaire-builder-v2.js` — new file, replaces `questionnaire-builder.js`

### Modified

- `Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`:
  - Render new templates instead of inline HTML (drops ~500 lines)
  - Localize state as JSON for Alpine (replaces existing per-field server rendering)
  - Remove field-type color map (lines 625–632)
  - Keep `handleSubmit`, `getFieldTypes`, validation logic, sanitization — all unchanged
- No new dependencies. jQuery UI Sortable is already enqueued by the existing page (line 110 of `QuestionnaireSettingsPage.php`).

### Removed (after redesign ships)

- `assets/css/admin/questionnaire-builder.css` (replaced by v2)
- `assets/js/admin/questionnaire-builder.js` (replaced by v2)
- Inline `<script type="text/template">` blocks at lines 213–219 of QuestionnaireSettingsPage (Alpine handles templating)

## Field-type inventory (kept identical to today)

| Internal key | Label (Dutch) | Inspector reveals |
|---|---|---|
| `text` | Tekst | label, help, required |
| `textarea` | Tekstveld | label, help, required |
| `select` | Selectie (dropdown) | label, help, required, options list |
| `radio` | Keuze | label, help, required, options list |
| `scale` | Schaal | label, help, required, min, max, labels |
| `checkbox` | Vinkje | label, help, required |
| `description` | Beschrijving (info, geen veld) | text only |

Rendering of `options list` for select/radio uses the same nested-input pattern as today's UI (line-by-line input list with "+ Optie" button). No drag-drop on options — they're typically 2–5 and order is set once.

## Error handling

- **Validation errors on save**: server returns `WP_Error`, renders inline error banner above the toolbar (existing WP admin pattern, `admin_notices` hook).
- **Empty group on save**: pre-flight client check warns "Groep X heeft geen velden — wil je doorgaan?" (existing behavior is to silently save; tighten it).
- **Duplicate field labels within a group**: existing validator catches this server-side; client mirrors check on blur and shows a `--sd-warning` inline hint.
- **Sortable.js fails to load** (CDN down): drag handles disabled gracefully, up/down buttons appear in each field row as fallback. Detect `typeof Sortable === 'undefined'` after enqueue.

## Testing

- **Unit (PHPUnit, in `tests/Unit/Modules/Questionnaire/`)**:
  - `QuestionnaireSettingsPageTest::testHandleSubmitAcceptsRefactoredPayload()` — guard the POST shape if it changes
  - Existing `QuestionnaireValidator` tests stay green (no validator changes)
- **Manual walk-through** (no automated browser tests for this admin page):
  1. Add a new group, add 3 fields of different types, save, reload, verify persistence
  2. Drag-reorder fields, save, verify order
  3. Delete a field, undo via browser back, save
  4. Switch between groups via pill tabs, verify state preserved
  5. jQuery UI Sortable fails to init (broken handle selector, race condition) → drag disabled, up/down arrows visible as fallback

## Migration

None. Data shape is unchanged. Editors notice a different UI on next page load; their existing data renders correctly in the new layout. No content downtime, no data migration.

## Out of scope (future iterations)

- Conditional fields ("show field X only if field Y = value")
- Field templates / "save group as template"
- Bulk operations (delete multiple fields at once)
- Schema export/import (JSON download of a questionnaire)
- Form-preview tab inside the builder (currently goes to the public page to preview)

## When this gets built

**Deferred post-launch.** Listed in `tasks/vad-to-stride-migration.md` as Phase 12+ deferred work. Current admin UI is functional; this redesign is polish. Launch the migration first; ship the builder redesign after.

---

## Appendix: visual mockup

Final approved mockup pushed to brainstorming server at `.superpowers/brainstorm/953929-1779224820/content/builder-v2-aligned.html` — preserved for reference.
